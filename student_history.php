<?php
session_start();
if (!isset($_SESSION['student_id'])) { header("Location: index.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['student_id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Unread notifications count
$nq = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE idnumber = ? AND is_read = 0");
$nq->bind_param("s", $student['idnumber']);
$nq->execute();
$nq->bind_result($unread_count);
$nq->fetch();
$nq->close();

// Fetch sit-in history
$hq = $conn->prepare(
    "SELECT * FROM sit_in WHERE idnumber = ? ORDER BY session_date DESC"
);
$hq->bind_param("s", $student['idnumber']);
$hq->execute();
$history = $hq->get_result();
$hq->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | My Sit-in History</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .history-container { padding: 24px; max-width: 900px; margin: 0 auto; }
    table { width:100%; border-collapse:collapse; background:#fff;
            border-radius:6px; overflow:hidden;
            box-shadow:0 1px 4px rgba(0,0,0,0.06); }
    th,td { padding:11px 14px; border-bottom:1px solid #e5e9f0; text-align:left; font-size:0.9rem; }
    th    { background:#1a5276; color:#fff; font-weight:600; }
    tr:hover td { background:#f0f6ff; }
    .status-active { color:#27ae60; font-weight:700; }
    .status-done   { color:#7f8c8d; }
    .empty { text-align:center; padding:40px; color:#999; }
    .top-nav { background:#1d3f7a; display:flex; padding:0 16px; border-bottom:3px solid #0f2a55; }
    .top-nav a { color:#c8d8f5; padding:11px 14px; font-weight:600; text-decoration:none; font-size:13px; }
    .top-nav a.active, .top-nav a:hover { background:rgba(255,255,255,0.13); color:#fff; }
    .top-nav .logout { margin-left:auto; background:#c0392b; color:#fff !important; padding:11px 18px; }
    .notif-wrap { position:relative; display:inline-flex; }
    .notif-badge { position:absolute; right:4px; top:6px; background:#e74c3c; color:#fff;
                   font-size:10px; font-weight:700; border-radius:50%; width:16px; height:16px;
                   display:flex; align-items:center; justify-content:center; }
  </style>
</head>
<body>
  <header>
    <img src="uclogo.png" alt="UC Logo" class="logo"/>
    <h1>College of Computer Studies Sit-in Monitoring System</h1>
    <img src="ucmainccslogo.png" alt="CCS Logo" class="logo"/>
  </header>
  <nav class="top-nav">
    <div class="notif-wrap">
      <a href="student_notifications.php">🔔 Notification</a>
      <?php if ($unread_count > 0): ?>
        <span class="notif-badge"><?= $unread_count ?></span>
      <?php endif; ?>
    </div>
    <a href="student_dashboard.php">Home</a>
    <a href="student_edit_profile.php">Edit Profile</a>
    <a href="student_history.php" class="active">History</a>
    <a href="student_reservation.php">Reservation</a>
    <a href="student_logout.php" class="logout">Log out</a>
  </nav>
  <main>
    <div class="history-container">
      <h2 style="color:#1a3a6b; margin-bottom:20px;">📋 My Sit-in History</h2>
      <?php if ($history->num_rows > 0): ?>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Date &amp; Time</th>
              <th>Laboratory</th>
              <th>Purpose</th>
              <th>Time Out</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 1; while ($row = $history->fetch_assoc()): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= date('M d, Y h:i A', strtotime($row['session_date'])) ?></td>
                <td><?= htmlspecialchars($row['lab']) ?></td>
                <td><?= htmlspecialchars($row['purpose']) ?></td>
                <td><?= $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '—' ?></td>
                <td class="status-<?= $row['status'] ?>"><?= ucfirst($row['status']) ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="empty">No sit-in history yet.</div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
