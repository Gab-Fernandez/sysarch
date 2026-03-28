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

$message = "";
$message_type = "";
$labs     = ['Lab 1','Lab 2','Lab 3','Lab 4','Lab 5','Lab 6'];
$purposes = ['Programming','Research','Online Class','Project','Assignment','Printing','Internet','Other'];

// Submit reservation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lab              = trim($_POST['lab']);
    $reservation_date = $_POST['reservation_date'];
    $start_time       = $_POST['start_time'];
    $end_time         = $_POST['end_time'];
    $purpose          = trim($_POST['purpose']);

    if ($end_time <= $start_time) {
        $message = "End time must be after start time.";
        $message_type = "error";
    } else {
        // Check conflict
        $chk = $conn->prepare(
            "SELECT res_id FROM reservations
             WHERE lab = ? AND reservation_date = ?
             AND status != 'rejected'
             AND ((start_time <= ? AND end_time > ?) OR (start_time < ? AND end_time >= ?))"
        );
        $chk->bind_param("ssssss", $lab, $reservation_date, $start_time, $start_time, $end_time, $end_time);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $message = "That time slot is already booked. Please choose another.";
            $message_type = "error";
        } else {
            $ins = $conn->prepare(
                "INSERT INTO reservations (idnumber, lab, reservation_date, start_time, end_time, purpose, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            );
            $ins->bind_param("ssssss", $student['idnumber'], $lab, $reservation_date, $start_time, $end_time, $purpose);
            if ($ins->execute()) {
                $message = "Reservation submitted! Waiting for admin approval.";
                $message_type = "success";
            } else {
                $message = "Failed to submit reservation. Please try again.";
                $message_type = "error";
            }
            $ins->close();
        }
        $chk->close();
    }
}

// Fetch this student's reservations
$rq = $conn->prepare(
    "SELECT * FROM reservations WHERE idnumber = ? ORDER BY reservation_date DESC, start_time DESC LIMIT 20"
);
$rq->bind_param("s", $student['idnumber']);
$rq->execute();
$reservations = $rq->get_result();
$rq->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Reservation</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .top-nav { background:#1d3f7a; display:flex; padding:0 16px; border-bottom:3px solid #0f2a55; }
    .top-nav a { color:#c8d8f5; padding:11px 14px; font-weight:600; text-decoration:none; font-size:13px; }
    .top-nav a.active, .top-nav a:hover { background:rgba(255,255,255,0.13); color:#fff; }
    .top-nav .logout { margin-left:auto; background:#c0392b; color:#fff !important; padding:11px 18px; }
    .notif-wrap { position:relative; display:inline-flex; }
    .notif-badge { position:absolute; right:4px; top:6px; background:#e74c3c; color:#fff;
                   font-size:10px; font-weight:700; border-radius:50%; width:16px; height:16px;
                   display:flex; align-items:center; justify-content:center; }
    .page-wrap { display:grid; grid-template-columns:1fr 1fr; gap:24px; padding:24px; }
    .card { background:#fff; border-radius:8px; padding:20px; box-shadow:0 1px 6px rgba(0,0,0,0.07); }
    .card h3 { color:#1a5276; border-bottom:2px solid #1a5276; padding-bottom:8px; margin-bottom:16px; }
    .form-group { margin-bottom:14px; }
    .form-group label { display:block; font-weight:600; margin-bottom:4px; font-size:0.9rem; }
    .form-group input, .form-group select { width:100%; padding:9px 11px; border:1px solid #ccc; border-radius:5px; }
    .btn-submit { background:#28a745; color:#fff; border:none; padding:11px; width:100%; border-radius:5px; font-size:1rem; font-weight:600; cursor:pointer; }
    .btn-submit:hover { background:#218838; }
    .msg-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:10px 14px; border-radius:5px; margin-bottom:14px; }
    .msg-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; padding:10px 14px; border-radius:5px; margin-bottom:14px; }
    table { width:100%; border-collapse:collapse; font-size:0.88rem; }
    th,td { padding:9px 10px; border-bottom:1px solid #e5e9f0; text-align:left; }
    th { background:#1a5276; color:#fff; }
    .status-pending  { background:#fff3cd; color:#856404; padding:2px 8px; border-radius:4px; font-size:0.8rem; font-weight:700; }
    .status-approved { background:#d4edda; color:#155724; padding:2px 8px; border-radius:4px; font-size:0.8rem; font-weight:700; }
    .status-rejected { background:#f8d7da; color:#721c24; padding:2px 8px; border-radius:4px; font-size:0.8rem; font-weight:700; }
    @media(max-width:700px){ .page-wrap{grid-template-columns:1fr;} }
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
    <a href="student_history.php">History</a>
    <a href="student_reservation.php" class="active">Reservation</a>
    <a href="student_logout.php" class="logout">Log out</a>
  </nav>
  <main>
    <div class="page-wrap">
      <!-- Form -->
      <div class="card">
        <h3>🔖 New Reservation</h3>
        <?php if ($message): ?>
          <div class="msg-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="POST">
          <div class="form-group">
            <label>Laboratory</label>
            <select name="lab" required>
              <option value="">Select Lab</option>
              <?php foreach ($labs as $l): ?>
                <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Date</label>
            <input type="date" name="reservation_date" required min="<?= date('Y-m-d') ?>"/>
          </div>
          <div class="form-group">
            <label>Start Time</label>
            <input type="time" name="start_time" required/>
          </div>
          <div class="form-group">
            <label>End Time</label>
            <input type="time" name="end_time" required/>
          </div>
          <div class="form-group">
            <label>Purpose</label>
            <select name="purpose" required>
              <option value="">Select Purpose</option>
              <?php foreach ($purposes as $p): ?>
                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn-submit">Submit Reservation</button>
        </form>
      </div>

      <!-- My Reservations -->
      <div class="card">
        <h3>📋 My Reservations</h3>
        <?php if ($reservations->num_rows > 0): ?>
          <table>
            <thead>
              <tr><th>Date</th><th>Lab</th><th>Time</th><th>Purpose</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php while ($r = $reservations->fetch_assoc()): ?>
                <tr>
                  <td><?= date('M d, Y', strtotime($r['reservation_date'])) ?></td>
                  <td><?= htmlspecialchars($r['lab']) ?></td>
                  <td><?= date('h:i A', strtotime($r['start_time'])) ?>–<?= date('h:i A', strtotime($r['end_time'])) ?></td>
                  <td><?= htmlspecialchars($r['purpose']) ?></td>
                  <td><span class="status-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p style="color:#999; text-align:center; padding:30px;">No reservations yet.</p>
        <?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>
