<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Create feedback table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idnumber VARCHAR(20),
    rating INT NOT NULL,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$page     = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$total_feedback = $conn->query("SELECT COUNT(*) as count FROM feedback")->fetch_assoc()['count'];
$total_pages    = ceil($total_feedback / $per_page);

$feedback = $conn->query("SELECT f.*, u.firstname, u.lastname, u.course
    FROM feedback f
    LEFT JOIN users u ON f.idnumber = u.idnumber
    ORDER BY f.created_at DESC
    LIMIT $per_page OFFSET $offset");

$avg_rating = $conn->query("SELECT AVG(rating) as avg_rating FROM feedback")->fetch_assoc()['avg_rating'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Feedback Reports</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .feedback-container { padding: 20px; }
    .feedback-content { max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .no-feedback { text-align: center; padding: 40px; color: #666; }
    .pagination a { padding: 7px 12px; margin: 0 2px; background: #1a5276; color: white; text-decoration: none; border-radius: 4px; }
    .pagination a:hover { background: #0a3d62; }
  </style>
</head>
<body>
  <header>
    <img src="uclogo.png" alt="UC Logo" class="logo"/>
    <h1>College of Computer Studies Admin</h1>
    <img src="ucmainccslogo.png" alt="CCS Logo" class="logo"/>
  </header>
  <nav>
    <a href="admin_dashboard.php">Home</a>
    <a href="admin_search.php">Search</a>
    <a href="admin_students.php">Students</a>
    <a href="admin_sitin.php">Sit-in</a>
    <a href="admin_sitin_records.php">Sit-in Records</a>
    <a href="admin_reports.php">Reports</a>
    <a href="admin_feedback.php" class="active">Feedback</a>
    <a href="admin_reservation.php">Reservation</a>
    <a href="admin_logout.php" class="logout-btn">Log out</a>
  </nav>
  <main>
    <div class="feedback-container">
      <h2 style="color:#1a3a6b; margin-bottom:16px;">📊 Feedback Reports</h2>

      <div class="stats-row">
        <div class="stat-box">
          <h3><?= $total_feedback ?></h3>
          <p>Total Feedbacks</p>
        </div>
        <div class="stat-box" style="background:#f39c12;">
          <h3><?= number_format($avg_rating, 1) ?>/5</h3>
          <p>Average Rating</p>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Student</th>
              <th>Course</th>
              <th>Rating</th>
              <th>Comment</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($feedback && $feedback->num_rows > 0):
              while ($row = $feedback->fetch_assoc()): ?>
              <tr>
                <td><?= date('M d, Y h:i A', strtotime($row['created_at'])) ?></td>
                <td><?= htmlspecialchars(($row['firstname'] ?? 'N/A') . ' ' . ($row['lastname'] ?? '')) ?></td>
                <td><?= htmlspecialchars($row['course'] ?? 'N/A') ?></td>
                <td>
                  <span class="rating-badge rating-<?= $row['rating'] ?>">
                    <?= $row['rating'] ?> ★
                  </span>
                </td>
                <td class="feedback-content" title="<?= htmlspecialchars($row['comment'] ?? '') ?>">
                  <?= htmlspecialchars($row['comment'] ?? 'No comment') ?>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr>
                <td colspan="5">
                  <div class="no-feedback">No feedback submitted yet.</div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_pages > 1): ?>
        <div class="pagination" style="margin-top:16px; display:flex; gap:4px; flex-wrap:wrap;">
          <?php if ($page > 1): ?>
            <a href="admin_feedback.php?page=<?= $page - 1 ?>">&laquo; Prev</a>
          <?php endif; ?>
          <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $page): ?>
              <span style="padding:7px 12px; background:#e0e7ef; border-radius:4px;"><?= $i ?></span>
            <?php else: ?>
              <a href="admin_feedback.php?page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $total_pages): ?>
            <a href="admin_feedback.php?page=<?= $page + 1 ?>">Next &raquo;</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
