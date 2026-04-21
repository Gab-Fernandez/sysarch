<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Ensure announcements table exists
$conn->query("CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content TEXT NOT NULL,
    posted_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// Stats
$totalStudents = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$currentSitin  = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE status='active'")->fetch_assoc()['c'];
$totalSitin    = $conn->query("SELECT COUNT(*) as c FROM sit_in")->fetch_assoc()['c'];

// Pie chart data — sit-in by purpose
$purposeResult = $conn->query("SELECT purpose, COUNT(*) as count FROM sit_in GROUP BY purpose ORDER BY count DESC");
$purposes = []; $counts = [];
while ($row = $purposeResult->fetch_assoc()) {
    $purposes[] = $row['purpose'];
    $counts[]   = $row['count'];
}

// Handle announcement submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['announcement'])) {
    $content = trim($_POST['announcement']);
    if (!empty($content)) {
        $stmt = $conn->prepare("INSERT INTO announcements (content, posted_by) VALUES (?, ?)");
        $stmt->bind_param("ss", $content, $_SESSION['admin_username']);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin_dashboard.php");
    exit();
}

// Handle delete announcement
if (isset($_GET['delete_ann'])) {
    $aid = intval($_GET['delete_ann']);
    $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt->bind_param("i", $aid);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_dashboard.php");
    exit();
}

$announcements = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Admin Dashboard</title>
  <link rel="stylesheet" href="style.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header>
  <img src="uclogo.png" alt="UC Logo" class="logo"/>
  <h1>College of Computer Studies Admin</h1>
  <img src="ucmainccslogo.png" alt="CCS Logo" class="logo"/>
</header>
<nav>
  <a href="admin_dashboard.php" class="active">Home</a>
  <a href="admin_search.php">Search</a>
  <a href="admin_students.php">Students</a>
  <a href="admin_sitin.php">Sit-in</a>
  <a href="admin_sitin_records.php">Sit-in Records</a>
  <a href="admin_reports.php">Reports</a>
  <a href="admin_feedback.php">Feedback</a>
  <a href="admin_reservation.php">Reservation</a>
  <a href="admin_leaderboard.php">Leaderboard</a>
  <a href="admin_analytics.php">Analytics</a>
  <a href="admin_logout.php" class="logout-btn">Log out</a>
</nav>
<main>
  <div class="dash-wrap">

    <!-- LEFT: Stats + Chart -->
    <div class="dash-card">
      <div class="dash-card-header">📊 Statistics</div>
      <div class="dash-stats">
        <p>👥 <strong>Students Registered:</strong> <?= $totalStudents ?></p>
        <p>🟢 <strong>Currently Sit-in:</strong> <?= $currentSitin ?></p>
        <p>📋 <strong>Total Sit-in:</strong> <?= $totalSitin ?></p>
      </div>
      <div style="padding:0 16px 16px;">
        <canvas id="sitinChart" style="max-height:300px;"></canvas>
      </div>
    </div>

    <!-- RIGHT: Announcements -->
    <div class="dash-card">
      <div class="dash-card-header">📢 Announcements</div>
      <form method="POST" action="admin_dashboard.php" style="padding:14px 16px 0;">
        <textarea name="announcement"
                  placeholder="Type a new announcement..."
                  style="width:100%;height:80px;padding:8px;border:1px solid #ccc;
                         border-radius:4px;resize:vertical;font-size:0.9rem;"></textarea>
        <button type="submit"
                style="margin-top:8px;background:#28a745;color:#fff;border:none;
                       padding:8px 20px;border-radius:4px;cursor:pointer;font-weight:600;">
          Post Announcement
        </button>
      </form>

      <div style="padding:14px 16px;">
        <h4 style="margin-bottom:10px;color:#1a5276;">Posted Announcements</h4>
        <?php if ($announcements && $announcements->num_rows > 0):
          while ($ann = $announcements->fetch_assoc()): ?>
          <div class="ann-item">
            <div class="ann-meta">
              CCS <?= htmlspecialchars($ann['posted_by']) ?> | <?= date('Y-M-d', strtotime($ann['created_at'])) ?>
              <a href="admin_dashboard.php?delete_ann=<?= $ann['id'] ?>"
                 onclick="return confirm('Delete this announcement?')"
                 style="float:right;color:#e74c3c;font-size:11px;text-decoration:none;">✕ Delete</a>
            </div>
            <div class="ann-body"><?= nl2br(htmlspecialchars($ann['content'])) ?></div>
          </div>
        <?php endwhile; else: ?>
          <p style="color:#999;font-size:0.9rem;">No announcements yet.</p>
        <?php endif; ?>
      </div>
    </div>

  </div>
</main>

<script>
const purposes = <?= json_encode($purposes) ?>;
const counts   = <?= json_encode($counts) ?>;
new Chart(document.getElementById('sitinChart'), {
  type: 'pie',
  data: {
    labels: purposes.length ? purposes : ['No Data'],
    datasets: [{
      data: counts.length ? counts : [1],
      backgroundColor: ['#3498db','#e74c3c','#f39c12','#2ecc71','#9b59b6','#1abc9c','#e67e22','#34495e']
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { position: 'top' } }
  }
});
</script>
</body>
</html>