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

// Mark all as read — FIXED: was missing execute()
$upd = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE idnumber = ?");
$upd->bind_param("s", $student['idnumber']);
$upd->execute();
$upd->close();

// Fetch notifications
$nq = $conn->prepare("SELECT * FROM notifications WHERE idnumber = ? ORDER BY created_at DESC LIMIT 30");
$nq->bind_param("s", $student['idnumber']);
$nq->execute();
$notifs = $nq->get_result();
$nq->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Notifications</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .notif-container { max-width: 700px; margin: 28px auto; padding: 0 20px; }
    .notif-item {
      background: #fff;
      border: 1px solid #dde4f0;
      border-radius: 6px;
      padding: 14px 18px;
      margin-bottom: 10px;
      transition: box-shadow 0.15s;
    }
    .notif-item:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .notif-item.unread { border-left: 4px solid #2563c0; }
    .notif-msg  { font-size: 14px; color: #222; }
    .notif-date { font-size: 11px; color: #999; margin-top: 6px; }
    .empty { text-align:center; color:#999; padding:50px; font-size:14px; }
  </style>
</head>
<body>
  <header>
    <img src="uclogo.png" alt="UC Logo" class="logo"/>
    <h1>College of Computer Studies Sit-in Monitoring System</h1>
    <img src="ucmainccslogo.png" alt="CCS Logo" class="logo"/>
  </header>
  <nav class="top-nav">
    <a href="student_notifications.php" class="active">🔔 Notifications</a>
    <a href="student_dashboard.php">Home</a>
    <a href="student_edit_profile.php">Edit Profile</a>
    <a href="student_history.php">History</a>
    <a href="student_reservation.php">Reservation</a>
    <span class="spacer"></span>
    <a href="student_logout.php" class="logout">Log out</a>
  </nav>
  <main>
    <div class="notif-container">
      <h2 style="margin-bottom:20px; color:#1a3a6b;">🔔 Notifications</h2>
      <?php if ($notifs && $notifs->num_rows > 0):
        while ($n = $notifs->fetch_assoc()): ?>
        <div class="notif-item">
          <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
          <div class="notif-date"><?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></div>
        </div>
      <?php endwhile; else: ?>
        <div class="empty">📭 No notifications yet.</div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
