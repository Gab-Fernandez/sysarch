<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "sit_in_system");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create feedback table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idnumber VARCHAR(20),
    rating INT NOT NULL,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Get feedback with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$total_result = $conn->query("SELECT COUNT(*) as count FROM feedback");
$total_feedback = $total_result->fetch_assoc()['count'];
$total_pages = ceil($total_feedback / $per_page);

$feedback = $conn->query("SELECT f.*, u.firstname, u.lastname, u.course 
    FROM feedback f 
    LEFT JOIN users u ON f.idnumber = u.idnumber 
    ORDER BY f.created_at DESC 
    LIMIT $per_page OFFSET $offset");

// Get average rating
$avg_result = $conn->query("SELECT AVG(rating) as avg_rating FROM feedback");
$avg_rating = $avg_result->fetch_assoc()['avg_rating'] ?? 0;

// Get rating distribution
$rating_dist = $conn->query("SELECT rating, COUNT(*) as count FROM feedback GROUP BY rating ORDER BY rating DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>CCS | Feedback Reports</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    .feedback-container { padding: 20px; }
    .stats-row { display: flex; gap: 20px; margin-bottom: 20px; }
    .stat-box { flex: 1; background: #1a5276; color: white; padding: 20px; border-radius: 8px; text-align: center; }
    .stat-box h3 { margin: 0; font-size: 2.5em; }
    .stat-box p { margin: 5px 0 0 0; opacity: 0.9; }
    .rating-box { background: #f39c12; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 10px; border: 1px solid #ddd; text-align: left; }
    th { background: #1a5276; color: white; }
    tr:nth-child(even) { background: #f9f9f9; }
    .rating-badge { 
      display: inline-block; 
      padding: 4px 10px; 
      border-radius: 12px; 
      font-weight: bold;
      color: white;
    }
    .rating-5 { background: #27ae60; }
    .rating-4 { background: #2ecc71; }
    .rating-3 { background: #f1c40f; color: #333; }
    .rating-2 { background: #e67e22; }
    .rating-1 { background: #e74c3c; }
    .pagination { margin-top: 20px; text-align: center; }
    .pagination a { padding: 8px 12px; margin: 0 3px; background: #1a5276; color: white; text-decoration: none; border-radius: 4px; }
    .pagination a:hover { background: #154360; }
    .no-feedback { text-align: center; padding: 40px; color: #666; }
    .feedback-content { max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  </style>
</head>
<body>
  <header>
    <img src="uclogo.png" alt="UC Logo" class="logo" />
    <h1>College of Computer Studies Admin</h1>
    <img src="ucmainccslogo.png" alt="CCS Logo" class="logo" />
  </header>
  <nav>
    <a href="admin_dashboard.php">Home</a>
    <a href="admin_search.php">Search</a>
    <a href="admin_students.php">Students</a>
    <a href="admin_sitin.php">Sit-in</a>
    <a href="admin_sitin_records.php">View Sit-in Records</a>
    <a href="admin_reports.php">Sit-in Reports</a>
    <a href="admin_feedback.php">Feedback Reports</a>
    <a href="admin_reservation.php">Reservation</a>
    <a href="admin_logout.php" class="logout-btn">Log out</a>
  </nav>
  <main>
    <div class="feedback-container">
      <h2>📊 Feedback Reports</h2>
      
      <div class="stats-row">
        <div class="stat-box">
          <h3><?= $total_feedback ?></h3>
          <p>Total Feedbacks</p>
        </div>
        <div class="stat-box rating-box">
          <h3><?= number_format($avg_rating, 1) ?>/5</h3>
          <p>Average Rating</p>
        </div>
      </div>
      
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
          <?php if ($feedback->num_rows > 0): ?>
            <?php while ($row = $feedback->fetch_assoc()): ?>
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
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="5">
                <div class="no-feedback">
                  <p>No feedback submitted yet.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      
      <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="admin_feedback.php?page=<?= $page - 1 ?>">&laquo; Prev</a>
          <?php endif; ?>
          
          <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $page): ?>
              <span><?= $i ?></span>
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
