<?php
session_start();
if (!isset($_SESSION['student_id'])) { header("Location: index.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Fetch student
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['student_id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) { session_destroy(); header("Location: index.php"); exit(); }

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
                $upd->execute(); $upd->close();
                $stmt2 = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt2->bind_param("i", $_SESSION['student_id']);
                $stmt2->execute();
                $student = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();
            }
        }
    }
    header("Location: student_dashboard.php"); exit();
}

// Announcements
$announcements = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 15");

// Unread notifications
$nq = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE idnumber = ? AND is_read = 0");
$nq->bind_param("s", $student['idnumber']); $nq->execute();
$unread_count = $nq->get_result()->fetch_assoc()['cnt'] ?? 0;
$nq->close();

// Sit-in history count
$hq = $conn->prepare("SELECT COUNT(*) as cnt FROM sit_in WHERE idnumber = ?");
$hq->bind_param("s", $student['idnumber']); $hq->execute();
$history_count = $hq->get_result()->fetch_assoc()['cnt'] ?? 0;
$hq->close();

// Active sit-in
$aq = $conn->prepare("SELECT * FROM sit_in WHERE idnumber = ? AND status = 'active' LIMIT 1");
$aq->bind_param("s", $student['idnumber']); $aq->execute();
$active_sitin = $aq->get_result()->fetch_assoc();
$aq->close();

$conn->close();

$sess_left   = (int)$student['remaining_session'];
$sess_color  = $sess_left <= 5 ? '#e74c3c' : ($sess_left <= 10 ? '#f39c12' : '#27ae60');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Student Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
    :root {
      --navy:    #0f2a55;
      --blue:    #1a5276;
      --mid:     #2563c0;
      --light:   #3b82f6;
      --bg:      #eef2f7;
      --card:    #ffffff;
      --border:  #d0daea;
      --text:    #1e293b;
      --muted:   #64748b;
      --sidebar: #162d55;
    }
    html, body { height:100%; }
    body {
      font-family:'Inter',sans-serif;
      background:var(--bg);
      color:var(--text);
      display:flex;
      flex-direction:column;
      min-height:100vh;
    }

    /* ── HEADER ── */
    .site-header {
      background:linear-gradient(90deg, #0a2744, #1a4fa0);
      display:flex; align-items:center;
      padding:10px 20px; gap:14px;
      box-shadow:0 2px 12px rgba(0,0,0,0.35);
      flex-shrink:0;
    }
    .site-header img   { height:48px; width:auto; flex-shrink:0; }
    .site-header h1    { flex:1; text-align:center; color:#fff; font-size:clamp(12px,2vw,18px); font-weight:700; line-height:1.3; }

    /* ── TOP NAV ── */
    .top-nav {
      background:var(--navy);
      display:flex; align-items:stretch;
      padding:0 12px;
      border-bottom:3px solid #071d3a;
      flex-shrink:0; flex-wrap:wrap;
    }
    .top-nav a {
      color:#a8c4e8; text-decoration:none;
      font-size:12.5px; font-weight:600;
      padding:10px 12px; white-space:nowrap;
      display:flex; align-items:center; gap:5px;
      border-bottom:3px solid transparent;
      margin-bottom:-3px;
      transition:color 0.15s, border-color 0.15s, background 0.15s;
    }
    .top-nav a:hover  { color:#fff; background:rgba(255,255,255,0.06); border-bottom-color:var(--light); }
    .top-nav a.active { color:#fff; border-bottom-color:#60a5fa; font-weight:700; }
    .top-nav .spacer  { flex:1; }
    .top-nav .logout  { background:#b91c1c; color:#fff !important; padding:10px 18px; border-bottom-color:transparent; font-weight:700; margin-left:4px; border-radius:0; }
    .top-nav .logout:hover { background:#991b1b; }
    .notif-wrap { position:relative; display:inline-flex; }
    .notif-badge {
      position:absolute; right:2px; top:5px;
      background:#ef4444; color:#fff;
      font-size:10px; font-weight:800;
      border-radius:50%; width:16px; height:16px;
      display:flex; align-items:center; justify-content:center;
      border:2px solid var(--navy);
    }

    /* ── MAIN 3-COLUMN ── */
    .page-body {
      display:grid;
      grid-template-columns:270px 1fr 280px;
      flex:1;
      min-height:0;
      overflow:hidden;
    }

    /* ══ LEFT PANEL — Student Profile ══ */
    .left-panel {
      background:var(--sidebar);
      display:flex; flex-direction:column;
      border-right:2px solid #071d3a;
      overflow-y:auto;
    }
    .panel-title {
      background:linear-gradient(135deg,#1234a0,var(--mid));
      color:#fff; padding:11px 16px;
      font-size:13px; font-weight:700;
      letter-spacing:0.04em;
      border-bottom:2px solid #071d3a;
      flex-shrink:0;
    }

    /* Avatar */
    .avatar-section {
      display:flex; flex-direction:column;
      align-items:center; padding:24px 16px 14px;
      gap:10px;
    }
    .avatar-ring {
      width:112px; height:112px; border-radius:50%;
      border:3px solid rgba(255,255,255,0.18);
      background:#1e3f7a;
      display:flex; align-items:center; justify-content:center;
      font-size:46px; overflow:hidden; flex-shrink:0;
      box-shadow:0 4px 20px rgba(0,0,0,0.3);
    }
    .avatar-ring img { width:100%; height:100%; object-fit:cover; }
    .student-fullname {
      color:#fff; font-weight:700; font-size:14px;
      text-align:center; line-height:1.3;
    }
    .student-course-tag {
      background:rgba(255,255,255,0.12);
      color:#b8d0f5; font-size:11px; font-weight:600;
      padding:3px 12px; border-radius:20px;
      text-align:center;
    }

    /* Upload form */
    .photo-row {
      display:flex; gap:6px; align-items:center;
      justify-content:center; flex-wrap:wrap;
    }
    .btn-choose {
      background:rgba(37,99,192,0.7);
      color:#fff; padding:5px 12px;
      border-radius:5px; cursor:pointer;
      font-size:11px; white-space:nowrap;
      border:1px solid rgba(255,255,255,0.2);
      transition:background 0.15s;
    }
    .btn-choose:hover { background:rgba(37,99,192,1); }
    .btn-upload {
      background:rgba(39,174,96,0.8);
      color:#fff; border:none;
      padding:5px 12px; border-radius:5px;
      cursor:pointer; font-size:11px;
      transition:background 0.15s;
    }
    .btn-upload:hover { background:#27ae60; }

    /* Info rows */
    .divider { height:1px; background:rgba(255,255,255,0.07); margin:0 16px; }
    .info-section { padding:12px 16px 20px; }
    .info-row {
      display:flex; align-items:flex-start;
      gap:10px; padding:9px 0;
      border-bottom:1px solid rgba(255,255,255,0.06);
    }
    .info-row:last-child { border-bottom:none; }
    .info-icon { font-size:15px; flex-shrink:0; margin-top:1px; }
    .info-label { font-size:10px; font-weight:600; color:rgba(255,255,255,0.45); display:block; margin-bottom:2px; text-transform:uppercase; letter-spacing:0.05em; }
    .info-value { color:#e8f0fe; font-weight:500; font-size:12.5px; line-height:1.35; word-break:break-word; }

    /* Session badge */
    .session-display {
      display:inline-flex; align-items:center; gap:8px; margin-top:3px;
    }
    .session-num {
      font-size:22px; font-weight:800; line-height:1;
      color: <?= $sess_color ?>;
      text-shadow:0 0 12px <?= $sess_color ?>44;
    }
    .session-label { font-size:10.5px; color:rgba(255,255,255,0.45); font-weight:600; }

    /* Quick-link nav buttons inside sidebar */
    .sidebar-nav { padding:0 14px 16px; display:flex; flex-direction:column; gap:6px; }
    .sidebar-nav-title { font-size:10px; font-weight:700; color:rgba(255,255,255,0.35); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:4px; }
    .snav-btn {
      display:flex; align-items:center; gap:9px;
      background:rgba(255,255,255,0.06);
      color:#b8d0f5; text-decoration:none;
      padding:9px 12px; border-radius:7px;
      font-size:12.5px; font-weight:600;
      transition:background 0.15s, color 0.15s;
      position:relative;
    }
    .snav-btn:hover { background:rgba(255,255,255,0.13); color:#fff; }
    .snav-btn .ico  { font-size:15px; flex-shrink:0; }
    .snav-badge {
      margin-left:auto;
      background:#ef4444; color:#fff;
      font-size:10px; font-weight:800;
      border-radius:10px; padding:1px 7px;
      min-width:20px; text-align:center;
    }

    /* ══ MIDDLE PANEL — Announcements ══ */
    .mid-panel {
      background:var(--card);
      display:flex; flex-direction:column;
      border-right:1px solid var(--border);
      overflow:hidden;
    }
    .panel-header {
      background:linear-gradient(135deg,#1234a0,var(--mid));
      color:#fff; padding:11px 18px;
      font-size:13px; font-weight:700;
      border-bottom:2px solid #071d3a;
      flex-shrink:0;
      display:flex; align-items:center; gap:7px;
    }

    /* Active sit-in banner */
    .active-banner {
      background:linear-gradient(135deg,#065f46,#059669);
      color:#fff; padding:10px 18px;
      font-size:12.5px; font-weight:600;
      display:flex; align-items:center; gap:8px;
      flex-shrink:0;
    }
    .active-dot { width:9px; height:9px; border-radius:50%; background:#86efac; animation:pulse 1.4s infinite; flex-shrink:0; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

    .ann-list { overflow-y:auto; flex:1; }
    .ann-item { padding:14px 18px; border-bottom:1px solid #f1f5f9; }
    .ann-item:last-child { border-bottom:none; }
    .ann-meta { font-size:11px; font-weight:700; color:var(--mid); margin-bottom:6px; display:flex; align-items:center; gap:6px; }
    .ann-dot  { width:6px; height:6px; border-radius:50%; background:var(--light); flex-shrink:0; }
    .ann-body { font-size:13px; color:#334155; line-height:1.65; }
    .no-ann   { padding:50px 18px; text-align:center; color:#94a3b8; font-size:13px; }

    /* ══ RIGHT PANEL — Rules ══ */
    .right-panel {
      background:var(--card);
      display:flex; flex-direction:column;
      overflow:hidden;
    }
    .rules-body { padding:14px 16px; overflow-y:auto; flex:1; }
    .rules-school { text-align:center; margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid var(--border); }
    .school-name  { font-size:13px; font-weight:700; color:var(--navy); }
    .college-name { font-size:11px; font-weight:600; color:var(--blue); margin-top:2px; }
    .rules-title  { font-size:11.5px; font-weight:700; color:var(--navy); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:8px; }
    .rules-intro  { font-size:11.5px; color:#555; line-height:1.6; margin-bottom:10px; }
    .rule-item    { display:flex; gap:7px; margin-bottom:9px; font-size:11.5px; color:#334155; line-height:1.6; }
    .rule-num     { font-weight:700; color:var(--mid); flex-shrink:0; min-width:17px; }

    /* ══ RESPONSIVE ══ */
    @media (max-width:1024px) {
      .page-body { grid-template-columns:240px 1fr 240px; }
    }
    @media (max-width:768px) {
      .page-body { grid-template-columns:1fr; overflow:visible; height:auto; }
      .left-panel,.mid-panel,.right-panel { overflow:visible; border:none; border-bottom:1px solid var(--border); min-height:unset; }
      body { overflow:auto; }
      .page-body { display:block; }
    }
    @media (max-width:480px) {
      .site-header { padding:8px 12px; gap:8px; }
      .site-header img { height:36px; }
      .site-header h1  { font-size:12px; }
      .top-nav a { font-size:11.5px; padding:9px 9px; }
    }
  </style>
</head>
<body>

<!-- ── HEADER ── -->
<div class="site-header">
  <img src="uclogo.png" alt="UC Logo"/>
  <h1>College of Computer Studies Sit-in Monitoring System</h1>
  <img src="ucmainccslogo.png" alt="CCS Logo"/>
</div>

<!-- ── TOP NAV (all 7 items from whiteboard) ── -->
<nav class="top-nav">
  <a href="student_dashboard.php" class="active">🏠 Home</a>
  <a href="student_edit_profile.php">✏️ Edit Profile</a>
  <a href="student_history.php">📋 History &amp; Feedback</a>
  <div class="notif-wrap">
    <a href="student_notifications.php">🔔 Notification</a>
    <?php if ($unread_count > 0): ?>
      <span class="notif-badge"><?= $unread_count ?></span>
    <?php endif; ?>
  </div>
  <a href="student_reservation.php">🔖 Reservation</a>
  <span class="spacer"></span>
  <a href="student_logout.php" class="logout">Log out</a>
</nav>

<!-- ── 3-COLUMN BODY ── -->
<div class="page-body">

  <!-- ══ LEFT: Student Profile Panel ══ -->
  <aside class="left-panel">
    <div class="panel-title">👤 Student Information</div>

    <div class="avatar-section">
      <!-- Profile photo -->
      <div class="avatar-ring">
        <?php if (!empty($student['profile_photo']) && file_exists($student['profile_photo'])): ?>
          <img src="<?= htmlspecialchars($student['profile_photo']) ?>" alt="Profile"/>
        <?php else: ?>
          🎓
        <?php endif; ?>
      </div>
      <div class="student-fullname">
        <?= htmlspecialchars($student['firstname'] . ' ' . $student['lastname']) ?>
      </div>
      <div class="student-course-tag">
        <?= htmlspecialchars($student['course']) ?> · Year <?= $student['courselevel'] ?>
      </div>

      <!-- Photo upload -->
      <form method="POST" enctype="multipart/form-data">
        <input type="file" id="photoFile" name="photo" accept="image/*" style="display:none"
               onchange="document.getElementById('photoLbl').textContent = this.files[0]?.name?.substring(0,13)+'…' || '📷 Choose Photo'"/>
        <input type="hidden" name="upload_photo" value="1"/>
        <div class="photo-row">
          <label for="photoFile" id="photoLbl" class="btn-choose">📷 Choose Photo</label>
          <button type="submit" class="btn-upload">Upload</button>
        </div>
      </form>
    </div>

    <div class="divider"></div>

    <!-- Info rows -->
    <div class="info-section">
      <div class="info-row">
        <span class="info-icon">🪪</span>
        <div><span class="info-label">ID Number</span><span class="info-value"><?= htmlspecialchars($student['idnumber']) ?></span></div>
      </div>
      <div class="info-row">
        <span class="info-icon">✉️</span>
        <div><span class="info-label">Email</span><span class="info-value"><?= htmlspecialchars($student['email']) ?></span></div>
      </div>
      <div class="info-row">
        <span class="info-icon">📍</span>
        <div><span class="info-label">Address</span><span class="info-value"><?= htmlspecialchars($student['address'] ?: '—') ?></span></div>
      </div>

      <!-- Remaining Session — prominent display -->
      <div class="info-row">
        <span class="info-icon">⏱️</span>
        <div>
          <span class="info-label">Remaining Sessions</span>
          <div class="session-display">
            <span class="session-num"><?= $sess_left ?></span>
            <span class="session-label">session<?= $sess_left !== 1 ? 's' : '' ?> left</span>
          </div>
        </div>
      </div>

      <!-- Sit-in history count -->
      <div class="info-row">
        <span class="info-icon">📊</span>
        <div><span class="info-label">Total Sit-ins</span><span class="info-value"><?= $history_count ?></span></div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Quick sidebar nav links -->
    <div class="sidebar-nav" style="margin-top:12px;">
      <div class="sidebar-nav-title">Quick Access</div>
      <a href="student_edit_profile.php" class="snav-btn"><span class="ico">✏️</span> Edit Profile</a>
      <a href="student_dashboard.php#announcements" class="snav-btn"><span class="ico">📢</span> View Announcement</a>
      <a href="student_dashboard.php#rules" class="snav-btn"><span class="ico">📋</span> Lab Rules &amp; Regulation</a>
      <a href="student_history.php" class="snav-btn">
        <span class="ico">📁</span> History &amp; Feedbacks
      </a>
      <a href="student_notifications.php" class="snav-btn">
        <span class="ico">🔔</span> Notification
        <?php if ($unread_count > 0): ?>
          <span class="snav-badge"><?= $unread_count ?></span>
        <?php endif; ?>
      </a>
      <a href="student_reservation.php" class="snav-btn"><span class="ico">🔖</span> Reservation</a>
    </div>

  </aside>

  <!-- ══ MIDDLE: Announcements ══ -->
  <section class="mid-panel">
    <div class="panel-header" id="announcements">📢 Announcements</div>

    <?php if ($active_sitin): ?>
    <div class="active-banner">
      <span class="active-dot"></span>
      Active sit-in: <strong><?= htmlspecialchars($active_sitin['lab']) ?></strong>
      &nbsp;·&nbsp; <?= htmlspecialchars($active_sitin['purpose']) ?>
      &nbsp;·&nbsp; Started <?= date('h:i A', strtotime($active_sitin['session_date'])) ?>
    </div>
    <?php endif; ?>

    <div class="ann-list">
      <?php if ($announcements && $announcements->num_rows > 0):
        while ($ann = $announcements->fetch_assoc()): ?>
        <div class="ann-item">
          <div class="ann-meta">
            <span class="ann-dot"></span>
            CCS <?= htmlspecialchars($ann['posted_by']) ?>
            &nbsp;·&nbsp; <?= date('M d, Y', strtotime($ann['created_at'])) ?>
          </div>
          <div class="ann-body"><?= nl2br(htmlspecialchars($ann['content'])) ?></div>
        </div>
      <?php endwhile; else: ?>
        <div class="no-ann">
          <div style="font-size:36px;margin-bottom:10px;">📭</div>
          No announcements at this time.
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ══ RIGHT: Lab Rules & Regulations ══ -->
  <aside class="right-panel">
    <div class="panel-header" id="rules">📋 Lab Rules &amp; Regulations</div>
    <div class="rules-body">
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
      <div class="rule-item"><span class="rule-num">8.</span><span>Strictly no piracy. Installation of unauthorized software is prohibited.</span></div>
      <div class="rule-item"><span class="rule-num">9.</span><span>Observe clean-as-you-go policy. Return chairs and equipment to their proper places after use.</span></div>
      <div class="rule-item"><span class="rule-num">10.</span><span>Violation of any of these rules will be subject to disciplinary action per university policy.</span></div>
    </div>
  </aside>

</div><!-- /page-body -->
</body>
</html>