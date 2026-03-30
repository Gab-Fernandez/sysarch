<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// ── Detect date column dynamically ────────────────────────────
$date_col = 'session_date';
$cols = $conn->query("SHOW COLUMNS FROM sit_in");
while ($col = $cols->fetch_assoc()) {
    $name = strtolower($col['Field']);
    if (strpos($name, 'date') !== false || strpos($name, 'time') !== false) {
        $date_col = $col['Field'];
        break;
    }
}

// ── Filters (sanitized) ───────────────────────────────────────
$date_from = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])
    ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])
    ? $_GET['date_to'] : date('Y-m-d');
$purpose_filter = isset($_GET['purpose']) ? $conn->real_escape_string(trim($_GET['purpose'])) : '';

$date_filter = " s.`$date_col` BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";
$purpose_where = $purpose_filter ? " AND s.purpose = '$purpose_filter'" : "";

// ── Data queries ──────────────────────────────────────────────
$report_query = $conn->query("
    SELECT s.*, u.firstname, u.lastname, u.course
    FROM sit_in s
    LEFT JOIN users u ON s.idnumber = u.idnumber
    WHERE $date_filter $purpose_where
    ORDER BY s.`$date_col` DESC
");

$total_sessions  = $conn->query("SELECT COUNT(*) as count FROM sit_in s WHERE $date_filter $purpose_where")->fetch_assoc()['count'];
$active_sessions = $conn->query("SELECT COUNT(*) as count FROM sit_in WHERE status = 'active'")->fetch_assoc()['count'];

$purpose_stats = $conn->query("SELECT purpose, COUNT(*) as count FROM sit_in s WHERE $date_filter $purpose_where GROUP BY purpose ORDER BY count DESC");
$lab_stats     = $conn->query("SELECT lab, COUNT(*) as count FROM sit_in s WHERE $date_filter $purpose_where GROUP BY lab ORDER BY count DESC");
$purposes_list = $conn->query("SELECT DISTINCT purpose FROM sit_in WHERE purpose IS NOT NULL AND purpose != '' ORDER BY purpose");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Sit-in Reports</title>
  <link rel="stylesheet" href="style.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .reports-container { padding: 20px; }
    .filter-box { background: #f8f9fa; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #dde4f0; }
    .filter-form { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
    .filter-group label { font-weight: 600; display: block; margin-bottom: 4px; font-size: 0.88rem; }
    .filter-group input, .filter-group select { padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.88rem; }
    .filter-group button { padding: 9px 22px; background: #1a5276; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 700; }
    .filter-group button:hover { background: #0a3d62; }
    .charts-row { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
    .chart-box { flex: 1; min-width: 260px; background: white; border: 1px solid #dde4f0; border-radius: 8px; padding: 16px; }
    .chart-box h3 { margin-top: 0; color: #1a5276; font-size: 0.95rem; margin-bottom: 10px; }
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
  <a href="admin_reports.php" class="active">Reports</a>
  <a href="admin_feedback.php">Feedback</a>
  <a href="admin_reservation.php">Reservation</a>
  <a href="admin_logout.php" class="logout-btn">Log out</a>
</nav>
<main>
  <div class="reports-container">
    <h2 style="color:#1a3a6b; margin-bottom:16px;">📊 Sit-in Reports</h2>

    <div class="filter-box">
      <form method="GET" action="admin_reports.php" class="filter-form">
        <div class="filter-group">
          <label>From</label>
          <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        </div>
        <div class="filter-group">
          <label>To</label>
          <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <div class="filter-group">
          <label>Purpose</label>
          <select name="purpose">
            <option value="">All Purposes</option>
            <?php while ($p = $purposes_list->fetch_assoc()): ?>
              <option value="<?= htmlspecialchars($p['purpose']) ?>" <?= $purpose_filter == $p['purpose'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['purpose']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>&nbsp;</label>
          <button type="submit">Filter</button>
        </div>
      </form>
    </div>

    <div class="stats-row">
      <div class="stat-box">
        <h3><?= $total_sessions ?></h3>
        <p>Sessions (<?= date('M d', strtotime($date_from)) ?> – <?= date('M d, Y', strtotime($date_to)) ?>)</p>
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

    <h3 style="color:#1a3a6b; margin-bottom:10px;">Detailed Records</h3>
    <div class="table-wrap">
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
          <?php if ($report_query && $report_query->num_rows > 0):
            while ($row = $report_query->fetch_assoc()):
              $ts = strtotime($row[$date_col] ?? 'now');
          ?>
            <tr>
              <td><?= date('M d, Y h:i A', $ts) ?></td>
              <td><?= htmlspecialchars($row['idnumber']) ?></td>
              <td><?= htmlspecialchars(($row['firstname'] ?? 'N/A') . ' ' . ($row['lastname'] ?? '')) ?></td>
              <td><?= htmlspecialchars($row['course'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($row['lab']) ?></td>
              <td><?= htmlspecialchars($row['purpose']) ?></td>
              <td><?= htmlspecialchars($row['status']) ?></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="7" style="text-align:center;padding:20px;color:#999;">No records found for the selected period.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <script>
    const purposeData = <?php
      $purpose_stats->data_seek(0);
      $pL = []; $pC = [];
      while ($p = $purpose_stats->fetch_assoc()) { $pL[] = $p['purpose']; $pC[] = $p['count']; }
      echo json_encode(['labels' => $pL, 'counts' => $pC]);
    ?>;
    new Chart(document.getElementById('purposeChart'), {
      type: 'doughnut',
      data: {
        labels: purposeData.labels.length ? purposeData.labels : ['No Data'],
        datasets: [{ data: purposeData.counts.length ? purposeData.counts : [1],
          backgroundColor: ['#3498db','#e74c3c','#f39c12','#2ecc71','#9b59b6','#1abc9c','#e67e22','#34495e'] }]
      },
      options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    const labData = <?php
      $lab_stats->data_seek(0);
      $lL = []; $lC = [];
      while ($l = $lab_stats->fetch_assoc()) { $lL[] = $l['lab']; $lC[] = $l['count']; }
      echo json_encode(['labels' => $lL, 'counts' => $lC]);
    ?>;
    new Chart(document.getElementById('labChart'), {
      type: 'bar',
      data: {
        labels: labData.labels.length ? labData.labels : ['No Data'],
        datasets: [{ label: 'Sessions', data: labData.counts.length ? labData.counts : [0], backgroundColor: '#1a5276' }]
      },
      options: { responsive: true, plugins: { legend: { display: false } } }
    });
  </script>
</main>
</body>
</html>
