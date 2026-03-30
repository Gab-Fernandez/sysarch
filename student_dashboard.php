<?php
session_start();
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Fetch student
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['student_id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Handle Photo Upload
if (isset($_POST['upload_photo']) && isset($_FILES['photo'])) {
    $file = $_FILES['photo'];
    if ($file['error'] === 0 && $file['size'] < 5000000) {
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (in_array($mime, $allowed)) {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $student['idnumber'] . '_' . time() . '.' . $ext;
            $web_path = 'uploads/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                $upd = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                $upd->bind_param("si", $web_path, $student['id']);
                $upd->execute();
                $upd->close();
                // Refresh
                $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->bind_param("i", $_SESSION['student_id']);
                $stmt->execute();
                $student = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }
    }
    header("Location: student_dashboard.php");
    exit();
}

// Fetch announcements
$announcements = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 10");

// Unread notification count — FIXED: use get_result or bind_result+fetch properly
$nq = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE idnumber = ? AND is_read = 0");
$nq->bind_param("s", $student['idnumber']);
$nq->execute();
$unread_count = $nq->get_result()->fetch_assoc()['cnt'] ?? 0;
$nq->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Student Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --blue-dark:  #1a3a6b;
      --blue-mid:   #1e4fa0;
      --blue-light: #2563c0;
      --blue-nav:   #1d3f7a;
      --white:      #ffffff;
      --bg:         #eef2f7;
      --left-bg:    #1e3a6e;
      --border:     #d0daea;
    }
    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg);
      color: #1a1a2e;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    /* HEADER */
    .site-header {
      background: var(--blue-dark);
      display: flex;
      align-items: center;
      padding: 10px 20px;
      gap: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.3);
      flex-shrink: 0;
    }
    .site-header img { height: 48px; width: auto; flex-shrink: 0; }
    .site-header h1 {
      flex: 1;
      text-align: center;
      color: #fff;
      font-size: clamp(12px, 2.2vw, 18px);
      font-weight: 700;
      line-height: 1.3;
    }
    /* TOP NAV */
    .top-nav {
      background: var(--blue-nav);
      display: flex;
      align-items: stretch;
      padding: 0 14px;
      border-bottom: 3px solid #0f2a55;
      flex-wrap: wrap;
      flex-shrink: 0;
    }
    .top-nav a {
      color: #c8d8f5;
      text-decoration: none;
      font-size: 12.5px;
      font-weight: 600;
      padding: 10px 13px;
      transition: background 0.15s;
      white-space: nowrap;
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .top-nav a:hover, .top-nav a.active { background: rgba(255,255,255,0.13); color: #fff; }
    .top-nav .spacer { flex: 1; min-width: 10px; }
    .top-nav .logout { background: #c0392b; color: #fff !important; padding: 10px 16px; font-weight: 700; }
    .top-nav .logout:hover { background: #a93226; }
    .notif-wrap { position: relative; display: inline-flex; }
    .notif-badge {
      position: absolute; right: 3px; top: 5px;
      background: #e74c3c; color: #fff;
      font-size: 10px; font-weight: 700;
      border-radius: 50%; width: 15px; height: 15px;
      display: flex; align-items: center; justify-content: center;
    }
    /* SUB-NAV */
    .sub-nav {
      background: #f0f4fb;
      border-bottom: 1px solid var(--border);
      display: flex;
      padding: 0 14px;
      flex-shrink: 0;
    }
    .sub-nav a {
      color: var(--blue-mid);
      text-decoration: none;
      font-size: 12px;
      font-weight: 600;
      padding: 8px 14px;
      border-bottom: 2px solid transparent;
    }
    .sub-nav a:hover, .sub-nav a.active { border-bottom-color: var(--blue-mid); color: var(--blue-dark); }
    /* 3-COLUMN LAYOUT */
    .page-body {
      display: grid;
      grid-template-columns: 260px 1fr 295px;
      flex: 1;
      overflow: hidden;
    }
    /* LEFT PANEL */
    .left-panel {
      background: var(--left-bg);
      color: #fff;
      display: flex;
      flex-direction: column;
      border-right: 2px solid #0f2a55;
      overflow-y: auto;
    }
    .panel-title {
      background: var(--blue-light);
      padding: 10px 14px;
      font-size: 13px;
      font-weight: 700;
      border-bottom: 2px solid #0f2a55;
      flex-shrink: 0;
    }
    .avatar-wrap {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 22px 16px 14px;
      gap: 10px;
    }
    .avatar-circle {
      width: 110px; height: 110px;
      border-radius: 50%;
      border: 3px solid rgba(255,255,255,0.2);
      background: #2a4f8a;
      display: flex; align-items: center; justify-content: center;
      font-size: 44px;
      overflow: hidden;
      flex-shrink: 0;
    }
    .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
    .photo-upload-row { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; justify-content: center; }
    .student-info { padding: 4px 16px 16px; }
    .info-row {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      padding: 8px 0;
      border-bottom: 1px solid rgba(255,255,255,0.07);
      font-size: 12.5px;
    }
    .info-row:last-child { border-bottom: none; }
    .info-icon { font-size: 14px; flex-shrink: 0; margin-top: 2px; }
    .info-label { font-size: 10px; font-weight: 600; color: rgba(255,255,255,0.5); display: block; margin-bottom: 1px; }
    .info-value { color: #fff; font-weight: 500; line-height: 1.3; word-break: break-word; }
    .session-badge {
      display: inline-block;
      background: <?php echo ($student['remaining_session'] <= 5) ? '#e74c3c' : '#27ae60'; ?>;
      color: #fff;
      font-size: 18px;
      font-weight: 700;
      padding: 2px 14px;
      border-radius: 5px;
      margin-top: 2px;
    }
    /* MIDDLE: Announcements */
    .mid-panel {
      background: #fff;
      display: flex;
      flex-direction: column;
      border-right: 1px solid var(--border);
      overflow: hidden;
    }
    .panel-header {
      background: var(--blue-light);
      color: #fff;
      padding: 10px 16px;
      font-size: 13px;
      font-weight: 700;
      border-bottom: 2px solid #0f2a55;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .ann-list { overflow-y: auto; flex: 1; }
    .ann-item { padding: 14px 18px; border-bottom: 1px solid #e8eef6; }
    .ann-item:last-child { border-bottom: none; }
    .ann-meta { font-size: 11px; font-weight: 700; color: var(--blue-mid); margin-bottom: 6px; }
    .ann-body { font-size: 13px; color: #333; line-height: 1.65; }
    .no-ann { padding: 40px 18px; text-align: center; color: #999; font-size: 13px; }
    /* RIGHT: Rules */
    .right-panel {
      background: #fff;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .rules-body { padding: 14px; overflow-y: auto; flex: 1; }
    .rules-school { text-align: center; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
    .school-name  { font-size: 13px; font-weight: 700; color: var(--blue-dark); }
    .college-name { font-size: 11px; font-weight: 700; color: var(--blue-mid); margin-top: 2px; }
    .rules-title  { font-size: 11.5px; font-weight: 700; color: var(--blue-dark); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
    .rules-intro  { font-size: 11.5px; color: #555; line-height: 1.6; margin-bottom: 10px; }
    .rule-item    { display: flex; gap: 7px; margin-bottom: 8px; font-size: 11.5px; color: #333; line-height: 1.6; }
    .rule-num     { font-weight: 700; color: var(--blue-mid); flex-shrink: 0; min-width: 16px; }

    /* RESPONSIVE */
    @media (max-width: 900px) {
      .page-body { grid-template-columns: 1fr; overflow: visible; }
      .left-panel, .mid-panel, .right-panel { overflow: visible; border: none; border-bottom: 1px solid var(--border); }
      .left-panel { display: block; }
      body { overflow: auto; }
    }
    @media (max-width: 480px) {
      .site-header { padding: 8px 10px; gap: 6px; }
      .site-header img { height: 34px; }
      .top-nav a { font-size: 11.5px; padding: 9px 9px; }
    }
  </style>
</head>
<body>

<div class="site-header">
  <img src="uclogo.png" alt="UC Logo"/>
  <h1>College of Computer Studies Sit-in Monitoring System</h1>
  <img src="ucmainccslogo.png" alt="CCS Logo"/>
</div>

<nav class="top-nav">
  <div class="notif-wrap">
    <a href="student_notifications.php">🔔 Notifications</a>
    <?php if ($unread_count > 0): ?>
      <span class="notif-badge"><?= $unread_count ?></span>
    <?php endif; ?>
  </div>
  <a href="student_dashboard.php" class="active">Home</a>
  <a href="student_edit_profile.php">Edit Profile</a>
  <a href="student_history.php">History</a>
  <a href="student_reservation.php">Reservation</a>
  <span class="spacer"></span>
  <a href="student_logout.php" class="logout">Log out</a>
</nav>

<div class="sub-nav">
  <a href="#rules" class="active">Rules &amp; Regulations</a>
</div>

<div class="page-body">
  <!-- LEFT: Student Info -->
  <aside class="left-panel">
    <div class="panel-title">👤 Student Information</div>
    <div class="avatar-wrap">
      <div class="avatar-circle">
        <?php if (!empty($student['profile_photo']) && file_exists($student['profile_photo'])): ?>
          <img src="<?= htmlspecialchars($student['profile_photo']) ?>" alt="Profile Photo"/>
        <?php else: ?>
          🎓
        <?php endif; ?>
      </div>
      <form method="POST" enctype="multipart/form-data" id="photoForm" class="photo-upload-row">
        <input type="file" id="photo" name="photo" accept="image/*" style="display:none;"
               onchange="document.getElementById('photoLabel').textContent = this.files[0]?.name?.substring(0,14) || '📷 Choose Photo'"/>
        <label for="photo" id="photoLabel"
               style="background:#1e4fa0;color:#fff;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:11.5px;white-space:nowrap;">
          📷 Choose Photo
        </label>
        <input type="hidden" name="upload_photo" value="1"/>
        <button type="submit"
                style="background:#27ae60;color:#fff;border:none;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:11.5px;">
          Upload
        </button>
      </form>
    </div>
    <div class="student-info">
      <div class="info-row">
        <span class="info-icon">🪪</span>
        <div><span class="info-label">ID Number</span><span class="info-value"><?= htmlspecialchars($student['idnumber']) ?></span></div>
      </div>
      <div class="info-row">
        <span class="info-icon">👤</span>
        <div><span class="info-label">Name</span><span class="info-value"><?= htmlspecialchars($student['firstname'] . ' ' . $student['middlename'] . ' ' . $student['lastname']) ?></span></div>
      </div>
      <div class="info-row">
        <span class="info-icon">🎓</span>
        <div><span class="info-label">Course</span><span class="info-value"><?= htmlspecialchars($student['course']) ?></span></div>
      </div>
      <div class="info-row">
        <span class="info-icon">📅</span>
        <div><span class="info-label">Year Level</span><span class="info-value"><?= htmlspecialchars($student['courselevel']) ?></span></div>
      </div>
      <div class="info-row">
        <span class="info-icon">✉️</span>
        <div><span class="info-label">Email</span><span class="info-value"><?= htmlspecialchars($student['email']) ?></span></div>
      </div>
      <div class="info-row">
        <span class="info-icon">📍</span>
        <div><span class="info-label">Address</span><span class="info-value"><?= htmlspecialchars($student['address'] ?: '—') ?></span></div>
      </div>
      <div class="info-row">
        <span class="info-icon">⏱️</span>
        <div>
          <span class="info-label">Remaining Sessions</span>
          <div class="session-badge"><?= $student['remaining_session'] ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MIDDLE: Announcements -->
  <section class="mid-panel">
    <div class="panel-header">📢 Announcements</div>
    <div class="ann-list">
      <?php if ($announcements && $announcements->num_rows > 0):
        while ($ann = $announcements->fetch_assoc()): ?>
        <div class="ann-item">
          <div class="ann-meta">
            CCS <?= htmlspecialchars($ann['posted_by']) ?> | <?= date('Y-M-d', strtotime($ann['created_at'])) ?>
          </div>
          <div class="ann-body"><?= nl2br(htmlspecialchars($ann['content'])) ?></div>
        </div>
      <?php endwhile; else: ?>
        <div class="no-ann">No announcements at this time.</div>
      <?php endif; ?>
    </div>
  </section>

  <!-- RIGHT: Lab Rules -->
  <aside class="right-panel">
    <div class="panel-header">📋 Rules &amp; Regulations</div>
    <div class="rules-body" id="rules">
      <div class="rules-school">
        <div class="school-name">University of Cebu</div>
        <div class="college-name">COLLEGE OF INFORMATION &amp; COMPUTER STUDIES</div>
      </div>
      <div class="rules-title">Laboratory Rules and Regulations</div>
      <p class="rules-intro">To avoid embarrassment and maintain camaraderie inside the laboratories, please observe the following:</p>
      <div class="rule-item"><span class="rule-num">1.</span><span>Maintain silence, proper decorum, and discipline. Mobile phones and personal devices must be switched off.</span></div>
      <div class="rule-item"><span class="rule-num">2.</span><span>Games are not allowed inside the lab — computer-related or otherwise.</span></div>
      <div class="rule-item"><span class="rule-num">3.</span><span>Internet surfing is allowed only with the instructor's permission. Downloading and installing software are strictly prohibited.</span></div>
      <div class="rule-item"><span class="rule-num">4.</span><span>Food and drinks are not allowed inside the laboratory.</span></div>
      <div class="rule-item"><span class="rule-num">5.</span><span>Students are not allowed to transfer from one laboratory to another without permission.</span></div>
      <div class="rule-item"><span class="rule-num">6.</span><span>Personal belongings must be kept in the designated area. The lab is not responsible for lost items.</span></div>
      <div class="rule-item"><span class="rule-num">7.</span><span>Any damage to equipment must be reported immediately to the lab-in-charge.</span></div>
      <div class="rule-item"><span class="rule-num">8.</span><span>Strictly no piracy. Installation of any unauthorized software is prohibited.</span></div>
      <div class="rule-item"><span class="rule-num">9.</span><span>Observe clean-as-you-go policy. Return chairs and equipment to their proper places after use.</span></div>
      <div class="rule-item"><span class="rule-num">10.</span><span>Violation of any of these rules will be subject to disciplinary action per university policy.</span></div>
    </div>
  </aside>
</div>
</body>
</html>
