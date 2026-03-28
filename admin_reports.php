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

// Detect date column name dynamically
$date_col = 'session_date'; // default
$cols = $conn->query("SHOW COLUMNS FROM sit_in");
while ($col = $cols->fetch_assoc()) {
    $col_name = strtolower($col['Field']);
    if (strpos($col_name, 'date') !== false || strpos($col_name, 'time') !== false) {
        $date_col = $col['Field'];
        break;
    }
}

// Get filter parameters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$purpose_filter = isset($_GET['purpose']) ? $_GET['purpose'] : '';

// Build query with filters (without table alias for COUNT queries)
$date_filter = " $date_col BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";
$purpose_where = !empty($purpose_filter) ? " AND purpose = '" . $conn->real_escape_string($purpose_filter) . "'" : "";

// Get sit-in data (with JOIN query)
$report_query = $conn->query("
    SELECT s.*, u.firstname, u.lastname, u.course 
    FROM sit_in s 
    LEFT JOIN users u ON s.idnumber = u.idnumber 
    WHERE s.$date_filter $purpose_where
    ORDER BY s.$date_col DESC
");

// Statistics (without JOIN)
$total_sessions = $conn->query("SELECT COUNT(*) as count FROM sit_in WHERE $date_filter $purpose_where")->fetch_assoc()['count'];
$active_sessions = $conn->query("SELECT COUNT(*) as count FROM sit_in WHERE status = 'active'")->fetch_assoc()['count'];

// Purpose breakdown
$purpose_stats = $conn->query("
    SELECT purpose, COUNT(*) as count 
    FROM sit_in WHERE $date_filter $purpose_where
    GROUP BY purpose 
    ORDER BY count DESC
");

// Lab breakdown
$lab_stats = $conn->query("
    SELECT lab, COUNT(*) as count 
    FROM sit_in WHERE $date_filter $purpose_where
    GROUP BY lab 
    ORDER BY count DESC
");

// Get unique purposes for filter dropdown
$purposes = $conn->query("SELECT DISTINCT purpose FROM sit_in WHERE purpose IS NOT NULL AND purpose != ''");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>CCS | Sit-in Reports</title>
  <link rel="stylesheet" href="style.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .reports-container { padding: 20px; }
    .filter-box { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    .filter-box form { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
    .filter-box label { font-weight: bold; display: block; margin-bottom: 5px; }
    .filter-box input, .filter-box select { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
    .filter-box button { padding: 8px 20px; background: #1a5276; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .filter-box button:hover { background: #154360; }
    .stats-row { display: flex; gap: 20px; margin-bottom: 20px; }
    .stat-box { flex: 1; background: #1a5276; color: white; padding: 20px; border-radius: 8px; text-align: center; }
    .stat-box h3 { margin: 0; font-size: 2.5em; }
    .stat-box p { margin: 5px 0 0 0; opacity: 0.9; }
    .charts-row { display: flex; gap: 20px; margin-bottom: 20px; }
    .chart-box { flex: 1; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 15px; }
    .chart-box h3 { margin-top: 0; color: #1a5276; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
    th { background: #1a5276; color: white; }
    tr:nth-child(even) { background: #f9f9f9; }
    .export-btn { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin-bottom: 15px; }
    .export-btn:hover { background: #218838; }
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
    <div class="reports-container">
      <h2>📊 Sit-in Reports</h2>
      
      <div class="filter-box">
        <form method="GET" action="admin_reports.php">
          <div>
            <label for="date_from">From:</label>
            <input type="date" id="date_from" name="date_from" value="<?= $date_from ?>">
          </div>
          <div>
            <label for="date_to">To:</label>
            <input type="date" id="date_to" name="date_to" value="<?= $date_to ?>">
          </div>
          <div>
            <label for="purpose">Purpose:</label>
            <select id="purpose" name="purpose">
              <option value="">All Purposes</option>
              <?php while ($p = $purposes->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($p['purpose']) ?>" <?= $purpose_filter == $p['purpose'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($p['purpose']) ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <button type="submit">Filter</button>
        </form>
      </div>
      
      <div class="stats-row">
        <div class="stat-box">
          <h3><?= $total_sessions ?></h3>
          <p>Total Sessions (<?= date('M d', strtotime($date_from)) ?> - <?= date('M d, Y', strtotime($date_to)) ?>)</p>
        </div>
        <div class="stat-box">
          <h3><?= $active_sessions ?></h3>
          <p>Currently Active</p>
        </div>
      </div>
      
      <div class="charts-row">
        <div class="chart-box">
          <h3>Purpose Breakdown</h3>
          <canvas id="purposeChart"></canvas>
        </div>
        <div class="chart-box">
          <h3>Lab Usage</h3>
          <canvas id="labChart"></canvas>
        </div>
      </div>
      
      <h3>Detailed Records</h3>
      <table>
        <thead>
          <tr>
            <th>Date/Time</th>
            <th>ID Number</th>
            <th>Name</th>
            <th>Course</th>
            <th>Lab</th>
            <th>Purpose</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($report_query->num_rows > 0): ?>
            <?php while ($row = $report_query->fetch_assoc()): ?>
              <tr>
                <td><?= date('M d, Y h:i A', strtotime($row[$date_col] ?? $row['session_date'] ?? $row['date'] ?? $row['time'] ?? 'now')) ?></td>
                <td><?= htmlspecialchars($row['idnumber']) ?></td>
                <td><?= htmlspecialchars(($row['firstname'] ?? 'N/A') . ' ' . ($row['lastname'] ?? '')) ?></td>
                <td><?= htmlspecialchars($row['course'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($row['lab']) ?></td>
                <td><?= htmlspecialchars($row['purpose']) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="7">No records found for the selected period.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    
    <script>
      // Purpose Chart
      const purposeData = <?php 
        $purpose_stats->data_seek(0);
        $pLabels = []; $pCounts = [];
        while ($p = $purpose_stats->fetch_assoc()) { 
          $pLabels[] = $p['purpose']; 
          $pCounts[] = $p['count']; 
        }
        echo json_encode(['labels' => $pLabels, 'counts' => $pCounts]);
      ?>;
      
      new Chart(document.getElementById('purposeChart'), {
        type: 'doughnut',
        data: {
          labels: purposeData.labels.length ? purposeData.labels : ['No Data'],
          datasets: [{
            data: purposeData.counts.length ? purposeData.counts : [1],
            backgroundColor: ['#3498db', '#e74c3c', '#f39c12', '#2ecc71', '#9b59b6', '#1abc9c', '#e67e22', '#34495e']
          }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
      });
      
      // Lab Chart
      const labData = <?php 
        $lab_stats->data_seek(0);
        $lLabels = []; $lCounts = [];
        while ($l = $lab_stats->fetch_assoc()) { 
          $lLabels[] = $l['lab']; 
          $lCounts[] = $l['count']; 
        }
        echo json_encode(['labels' => $lLabels, 'counts' => $lCounts]);
      ?>;
      
      new Chart(document.getElementById('labChart'), {
        type: 'bar',
        data: {
          labels: labData.labels.length ? labData.labels : ['No Data'],
          datasets: [{
            label: 'Sessions',
            data: labData.counts.length ? labData.counts : [0],
            backgroundColor: '#1a5276'
          }]
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
      });
    </script>
  </main>
</body>
</html>
