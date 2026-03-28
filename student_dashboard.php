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

// Fetch announcements
$announcements = $conn->query(
    "SELECT * FROM announcements ORDER BY created_at DESC LIMIT 10"
);

// Unread notification count
$nq = $conn->prepare(
    "SELECT COUNT(*) FROM notifications WHERE idnumber = ? AND is_read = 0"
);
$nq->bind_param("s", $student['idnumber']);
$nq->execute();
$nq->bind_result($unread_count);
$nq->fetch();
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
      --text:       #1a1a2e;
      --border:     #d0daea;
      --left-bg:    #1e3a6e;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* HEADER */
    .site-header {
      background: var(--blue-dark);
      display: flex;
      align-items: center;
      padding: 10px 24px;
      gap: 14px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
    .site-header img { height: 50px; width: auto; flex-shrink: 0; }
    .site-header h1 {
      flex: 1;
      text-align: center;
      color: var(--white);
      font-size: 17px;
      font-weight: 700;
      line-height: 1.3;
    }

    /* TOP NAV */
    .top-nav {
      background: var(--blue-nav);
      display: flex;
      align-items: stretch;
      padding: 0 16px;
      gap: 0;
      border-bottom: 3px solid #0f2a55;
    }
    .top-nav a {
      color: #c8d8f5;
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      padding: 11px 14px;
      transition: background 0.15s, color 0.15s;
      white-space: nowrap;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .top-nav a:hover,
    .top-nav a.active {
      background: rgba(255,255,255,0.13);
      color: #fff;
    }

    /* Notification badge */
    .notif-wrap { position: relative; display: inline-flex; }
    .notif-badge {
      position: absolute;
      right: 4px; top: 6px;
      background: #e74c3c;
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      border-radius: 50%;
      width: 16px; height: 16px;
      display: flex; align-items: center; justify-content: center;
    }

    /* Logout to far right */
    .top-nav .spacer { flex: 1; }
    .top-nav .logout {
      background: #c0392b;
      color: #fff !important;
      padding: 10px 18px;
      font-weight: 700;
    }
    .top-nav .logout:hover { background: #a93226; }

    /* SUB-NAV */
    .sub-nav {
      background: #f0f4fb;
      border-bottom: 1px solid var(--border);
      display: flex;
      padding: 0 16px;
    }
    .sub-nav a {
      color: var(--blue-mid);
      text-decoration: none;
      font-size: 12.5px;
      font-weight: 600;
      padding: 8px 14px;
      border-bottom: 2px solid transparent;
      transition: border-color 0.15s, color 0.15s;
    }
    .sub-nav a:hover,
    .sub-nav a.active {
      border-bottom-color: var(--blue-mid);
      color: var(--blue-dark);
    }

    /* PAGE BODY: 3 columns */
    .page-body {
      display: grid;
      grid-template-columns: 275px 1fr 310px;
      flex: 1;
      min-height: 0;
      height: calc(100vh - 118px); /* fill remaining height */
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
      padding: 11px 16px;
      font-size: 13.5px;
      font-weight: 700;
      border-bottom: 2px solid #0f2a55;
      letter-spacing: 0.02em;
    }
    .avatar-wrap {
      display: flex;
      justify-content: center;
      padding: 26px 20px 14px;
    }
    .avatar-circle {
      width: 128px; height: 128px;
      border-radius: 50%;
      border: 4px solid rgba(255,255,255,0.22);
      background: #2a4f8a;
      display: flex; align-items: center; justify-content: center;
      font-size: 52px;
      overflow: hidden;
      flex-shrink: 0;
    }
    .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

    .student-info { padding: 6px 18px 20px; }
    .info-row {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 9px 0;
      border-bottom: 1px solid rgba(255,255,255,0.07);
      font-size: 13px;
    }
    .info-row:last-child { border-bottom: none; }
    .info-icon { font-size: 15px; flex-shrink: 0; margin-top: 2px; }
    .info-text {}
    .info-label { font-size: 10.5px; font-weight: 600; color: rgba(255,255,255,0.5); display: block; margin-bottom: 1px; }
    .info-value { color: #fff; font-weight: 500; line-height: 1.3; word-break: break-word; }

    .session-badge {
      display: inline-block;
      background: <?php echo ($student['remaining_session'] <= 5) ? '#e74c3c' : '#27ae60'; ?>;
      color: #fff;
      font-size: 20px;
      font-weight: 700;
      padding: 3px 18px;
      border-radius: 6px;
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
      padding: 11px 18px;
      font-size: 13.5px;
      font-weight: 700;
      border-bottom: 2px solid #0f2a55;
      display: flex;
      align-items: center;
      gap: 7px;
      flex-shrink: 0;
    }
    .ann-list { overflow-y: auto; flex: 1; }
    .ann-item {
      padding: 16px 20px;
      border-bottom: 1px solid #e8eef6;
    }
    .ann-item:last-child { border-bottom: none; }
    .ann-meta {
      font-size: 11.5px;
      font-weight: 700;
      color: var(--blue-mid);
      margin-bottom: 7px;
    }
    .ann-body {
      font-size: 13.5px;
      color: #333;
      line-height: 1.65;
    }
    .no-ann {
      padding: 48px 20px;
      text-align: center;
      color: #999;
      font-size: 13px;
    }

    /* RIGHT: Rules */
    .right-panel {
      background: #fff;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .rules-body {
      padding: 16px 16px;
      overflow-y: auto;
      flex: 1;
    }
    .rules-school {
      text-align: center;
      margin-bottom: 12px;
      padding-bottom: 10px;
      border-bottom: 1px solid var(--border);
    }
    .school-name  { font-size: 13.5px; font-weight: 700; color: var(--blue-dark); }
    .college-name { font-size: 11.5px; font-weight: 700; color: var(--blue-mid); margin-top: 2px; }
    .rules-title  {
      font-size: 12px; font-weight: 700;
      color: var(--blue-dark);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 10px;
    }
    .rules-intro  { font-size: 12px; color: #555; line-height: 1.6; margin-bottom: 12px; }
    .rule-item    { display: flex; gap: 8px; margin-bottom: 10px; font-size: 12px; color: #333; line-height: 1.6; }
    .rule-num     { font-weight: 700; color: var(--blue-mid); flex-shrink: 0; min-width: 18px; }
  </style>
</head>
<body>

<!-- HEADER -->
<div class="site-header">
  <img src="uclogo.png" alt="UC Logo"/>
  <h1>College of Computer Studies Sit-in Monitoring System</h1>
  <img src="ucmainccslogo.png" alt="CCS Logo"/>
</div>

<!-- TOP NAV -->
<nav class="top-nav">
  <div class="notif-wrap">
    <a href="student_notifications.php">🔔 Notification</a>
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

<!-- SUB NAV -->
<div class="sub-nav">
  <a href="#rules" class="active">Rules and Regulation</a>
</div>

<!-- 3-COLUMN LAYOUT -->
<div class="page-body">

  <!-- LEFT: Student Info -->
  <aside class="left-panel">
    <div class="panel-title">👤 Student Information</div>
    <div class="avatar-wrap">
      <div class="avatar-circle">
        <?php if (!empty($student['profile_photo'])): ?>
          <img src="<?= htmlspecialchars($student['profile_photo']) ?>" alt="Photo"/>
        <?php else: ?>
          🎓
        <?php endif; ?>
      </div>
    </div>
    <div class="student-info">
      <div class="info-row">
        <span class="info-icon">👤</span>
        <div class="info-text">
          <span class="info-label">Name</span>
          <span class="info-value">
            <?= htmlspecialchars($student['firstname'] . ' ' . $student['middlename'] . ' ' . $student['lastname']) ?>
          </span>
        </div>
      </div>
      <div class="info-row">
        <span class="info-icon">🎓</span>
        <div class="info-text">
          <span class="info-label">Course</span>
          <span class="info-value"><?= htmlspecialchars($student['course']) ?></span>
        </div>
      </div>
      <div class="info-row">
        <span class="info-icon">📅</span>
        <div class="info-text">
          <span class="info-label">Year</span>
          <span class="info-value"><?= htmlspecialchars($student['courselevel']) ?></span>
        </div>
      </div>
      <div class="info-row">
        <span class="info-icon">✉️</span>
        <div class="info-text">
          <span class="info-label">Email</span>
          <span class="info-value"><?= htmlspecialchars($student['email']) ?></span>
        </div>
      </div>
      <div class="info-row">
        <span class="info-icon">📍</span>
        <div class="info-text">
          <span class="info-label">Address</span>
          <span class="info-value"><?= htmlspecialchars($student['address'] ?: '—') ?></span>
        </div>
      </div>
      <div class="info-row">
        <span class="info-icon">⏱️</span>
        <div class="info-text">
          <span class="info-label">Session</span>
          <div class="session-badge"><?= $student['remaining_session'] ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MIDDLE: Announcements -->
  <section class="mid-panel">
    <div class="panel-header">📢 Announcement</div>
    <div class="ann-list">
      <?php if ($announcements->num_rows > 0):
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
      <p class="rules-intro">
        To avoid embarrassment and maintain camaraderie with your friends and
        superiors at our laboratories, please observe the following:
      </p>
      <div class="rule-item"><span class="rule-num">1.</span><span>Maintain silence, proper decorum, and discipline inside the laboratory. Mobile phones, walkmans, and other personal pieces of equipment must be switched off.</span></div>
      <div class="rule-item"><span class="rule-num">2.</span><span>Games are not allowed inside the lab. This includes computer-related games, card games, and other games that may disturb the operation of the lab.</span></div>
      <div class="rule-item"><span class="rule-num">3.</span><span>Surfing the Internet is allowed only with the permission of the instructor. Downloading and installing of software are strictly prohibited.</span></div>
      <div class="rule-item"><span class="rule-num">4.</span><span>Food and drinks are not allowed inside the laboratory.</span></div>
      <div class="rule-item"><span class="rule-num">5.</span><span>Students are not allowed to transfer from one laboratory to another without permission.</span></div>
      <div class="rule-item"><span class="rule-num">6.</span><span>Personal belongings must be kept in the designated area. The laboratory is not responsible for any lost items.</span></div>
      <div class="rule-item"><span class="rule-num">7.</span><span>Any damage to laboratory equipment must be reported immediately to the lab-in-charge.</span></div>
      <div class="rule-item"><span class="rule-num">8.</span><span>Strictly no piracy. Installation of any unauthorized software is prohibited.</span></div>
      <div class="rule-item"><span class="rule-num">9.</span><span>Observe clean-as-you-go policy. Return chairs and equipment to their proper places after use.</span></div>
      <div class="rule-item"><span class="rule-num">10.</span><span>Violation of any of these rules will be subject to disciplinary action in accordance with university policy.</span></div>
    </div>
  </aside>

</div>
</body>
</html>