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
$nq = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE idnumber = ? AND is_read = 0");
$nq->bind_param("s", $student['idnumber']);
$nq->execute();
$unread_count = $nq->get_result()->fetch_assoc()['cnt'] ?? 0;
$nq->close();

// Ensure feedback table exists
$conn->query("CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idnumber VARCHAR(20),
    rating INT NOT NULL,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$feedback_msg = "";
$feedback_type = "";

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $rating  = intval($_POST['rating']);
    $comment = trim($_POST['comment']);

    if ($rating < 1 || $rating > 5) {
        $feedback_msg  = "Please select a valid rating (1–5).";
        $feedback_type = "error";
    } else {
        // Check if student already submitted feedback today
        $fc = $conn->prepare("SELECT id FROM feedback WHERE idnumber = ? AND DATE(created_at) = CURDATE()");
        $fc->bind_param("s", $student['idnumber']);
        $fc->execute();
        $fc->store_result();

        if ($fc->num_rows > 0) {
            $feedback_msg  = "You have already submitted feedback today.";
            $feedback_type = "error";
        } else {
            $fi = $conn->prepare("INSERT INTO feedback (idnumber, rating, comment) VALUES (?, ?, ?)");
            $fi->bind_param("sis", $student['idnumber'], $rating, $comment);
            if ($fi->execute()) {
                $feedback_msg  = "Thank you for your feedback!";
                $feedback_type = "success";
            }
            $fi->close();
        }
        $fc->close();
    }
}

// Fetch sit-in history
$hq = $conn->prepare("SELECT * FROM sit_in WHERE idnumber = ? ORDER BY session_date DESC");
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
    .page-wrap { padding: 24px; max-width: 1000px; margin: 0 auto; }
    .history-table-wrap { overflow-x: auto; margin-bottom: 30px; }
    .feedback-card {
      background: #fff;
      border-radius: 8px;
      padding: 22px 24px;
      box-shadow: 0 1px 6px rgba(0,0,0,0.07);
      max-width: 480px;
    }
    .feedback-card h3 { color: #1a5276; margin-bottom: 16px; border-bottom: 2px solid #1a5276; padding-bottom: 8px; }
    .stars { display: flex; gap: 6px; margin-bottom: 14px; }
    .stars input[type="radio"] { display: none; }
    .stars label {
      font-size: 28px;
      cursor: pointer;
      color: #ccc;
      transition: color 0.15s;
    }
    .stars input[type="radio"]:checked ~ label,
    .stars label:hover,
    .stars label:hover ~ label { color: #f1c40f; }
    .stars { flex-direction: row-reverse; justify-content: flex-end; }
    .stars input[type="radio"]:checked ~ label { color: #f1c40f; }
    .stars label:hover, .stars label:hover ~ label { color: #f1c40f; }
    .btn-feedback {
      background: #1a5276; color: #fff; border: none;
      padding: 10px 28px; border-radius: 5px; cursor: pointer;
      font-weight: 700; font-size: 0.95rem; margin-top: 10px;
      transition: background 0.2s;
    }
    .btn-feedback:hover { background: #0a3d62; }
    .empty { text-align:center; color:#999; padding:40px; }
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
      <a href="student_notifications.php">🔔 Notifications</a>
      <?php if ($unread_count > 0): ?>
        <span class="notif-badge"><?= $unread_count ?></span>
      <?php endif; ?>
    </div>
    <a href="student_dashboard.php">Home</a>
    <a href="student_edit_profile.php">Edit Profile</a>
    <a href="student_history.php" class="active">History</a>
    <a href="student_reservation.php">Reservation</a>
    <span class="spacer"></span>
    <a href="student_logout.php" class="logout">Log out</a>
  </nav>
  <main>
    <div class="page-wrap">
      <h2 style="color:#1a3a6b; margin-bottom:20px;">📋 My Sit-in History</h2>

      <div class="history-table-wrap">
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

      <!-- Feedback Section -->
      <div class="feedback-card">
        <h3>⭐ Leave a Feedback</h3>
        <?php if ($feedback_msg): ?>
          <div class="msg-<?= $feedback_type ?>" style="margin-bottom:12px;"><?= htmlspecialchars($feedback_msg) ?></div>
        <?php endif; ?>
        <form method="POST" action="student_history.php">
          <label style="font-weight:600; font-size:0.9rem; display:block; margin-bottom:6px;">
            Rate your experience:
          </label>
          <div class="stars">
            <?php for ($s = 5; $s >= 1; $s--): ?>
              <input type="radio" name="rating" id="star<?= $s ?>" value="<?= $s ?>" required/>
              <label for="star<?= $s ?>">★</label>
            <?php endfor; ?>
          </div>
          <label for="comment" style="font-weight:600; font-size:0.9rem; display:block; margin-bottom:4px;">Comment (optional):</label>
          <textarea name="comment" id="comment" rows="3"
                    style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:5px; font-size:0.9rem; resize:vertical;"
                    placeholder="Share your experience..."></textarea>
          <button type="submit" name="submit_feedback" class="btn-feedback">Submit Feedback</button>
        </form>
      </div>
    </div>
  </main>
</body>
</html>
