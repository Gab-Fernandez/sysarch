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

// Mark all as read
$conn->prepare("UPDATE notifications SET is_read = 1 WHERE idnumber = ?")
     ->bind_param("s", $student['idnumber']);

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
  <title>CCS | Notifications</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .notif-container { max-width: 700px; margin: 30px auto; padding: 0 20px; }
    .notif-item { background: #fff; border: 1px solid #dde4f0; border-radius: 6px;
                  padding: 14px 18px; margin-bottom: 10px; }
    .notif-item.unread { border-left: 4px solid #2563c0; }
    .notif-date { font-size: 11px; color: #999; margin-top: 6px; }
    .notif-msg  { font-size: 14px; color: #222; }
    .empty      { text-align:center; color:#999; padding:40px; }
  </style>
</head>
<body>
  <header>
    <img src="uclogo.png" alt="UC Logo" class="logo"/>
    <h1>College of Computer Studies Sit-in Monitoring System</h1>
    <img src="ucmainccslogo.png" alt="CCS Logo" class="logo"/>
  </header>
  <nav class="top-nav" style="background:#1d3f7a;display:flex;padding:0 16px;border-bottom:3px solid #0f2a55;">
    <a href="student_notifications.php" style="color:#fff;padding:11px 14px;font-weight:600;text-decoration:none;">🔔 Notification</a>
    <a href="student_dashboard.php"     style="color:#c8d8f5;padding:11px 14px;font-weight:600;text-decoration:none;">Home</a>
    <a href="student_edit_profile.php"  style="color:#c8d8f5;padding:11px 14px;font-weight:600;text-decoration:none;">Edit Profile</a>
    <a href="student_history.php"       style="color:#c8d8f5;padding:11px 14px;font-weight:600;text-decoration:none;">History</a>
    <a href="student_reservation.php"   style="color:#c8d8f5;padding:11px 14px;font-weight:600;text-decoration:none;">Reservation</a>
    <span style="flex:1"></span>
    <a href="student_logout.php" style="background:#c0392b;color:#fff;padding:11px 18px;font-weight:700;text-decoration:none;">Log out</a>
  </nav>
  <main>
    <div class="notif-container">
      <h2 style="margin-bottom:20px; color:#1a3a6b;">🔔 Notifications</h2>
      <?php if ($notifs->num_rows > 0):
        while ($n = $notifs->fetch_assoc()): ?>
        <div class="notif-item">
          <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
          <div class="notif-date"><?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></div>
        </div>
      <?php endwhile; else: ?>
        <div class="empty">No notifications yet.</div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
