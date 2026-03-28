<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// End session
if (isset($_GET['end'])) {
    $sit_id  = intval($_GET['end']);
    $timeout = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE sit_in SET status='done', time_out=? WHERE sit_id=?");
    $stmt->bind_param("si", $timeout, $sit_id);
    $stmt->execute(); $stmt->close();
    header("Location: admin_sitin_records.php"); exit();
}

// Chart data
$purpose_chart = $conn->query("SELECT purpose, COUNT(*) as c FROM sit_in GROUP BY purpose");
$pLabels=[]; $pData=[];
while ($r=$purpose_chart->fetch_assoc()) { $pLabels[]=$r['purpose']; $pData[]=$r['c']; }

$lab_chart = $conn->query("SELECT lab, COUNT(*) as c FROM sit_in GROUP BY lab");
$lLabels=[]; $lData=[];
while ($r=$lab_chart->fetch_assoc()) { $lLabels[]=$r['lab']; $lData[]=$r['c']; }

// Pagination
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
if (!in_array($per_page,[10,25,50,100])) $per_page=10;
$page   = isset($_GET['page']) ? max(1,intval($_GET['page'])) : 1;
$offset = ($page-1)*$per_page;
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

$where  = $search
    ? "WHERE (s.idnumber LIKE '%$search%' OR u.firstname LIKE '%$search%' OR u.lastname LIKE '%$search%' OR s.purpose LIKE '%$search%' OR s.lab LIKE '%$search%')"
    : '';

$total = $conn->query("SELECT COUNT(*) as c FROM sit_in s JOIN users u ON s.idnumber=u.idnumber $where")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total/$per_page));

$records = $conn->query("
    SELECT s.*, u.firstname, u.lastname
    FROM sit_in s
    JOIN users u ON s.idnumber = u.idnumber
    $where
    ORDER BY s.session_date DESC
    LIMIT $per_page OFFSET $offset
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>CCS | Sit-in Records</title>
  <link rel="stylesheet" href="style.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .charts-row { display:flex; gap:20px; margin-bottom:24px; flex-wrap:wrap; }
    .chart-box  { flex:1; min-width:260px; background:#fff; border:1px solid #dde4f0;
                  border-radius:8px; padding:16px; }
    .chart-box h3 { color:#1a5276; margin-bottom:10px; font-size:0.95rem; }
    .table-controls { display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap; }
    .table-controls label { font-weight:600; font-size:0.88rem; }
    .table-controls select, .table-controls input { padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:0.88rem; }
    .table-wrap { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:0.88rem; }
    th { background:#1a5276; color:#fff; padding:10px 12px; text-align:left;
         cursor:pointer; user-select:none; white-space:nowrap; }
    th:hover { background:#0f3460; }
    td { padding:9px 12px; border-bottom:1px solid #e5e9f0; white-space:nowrap; }
    tr:hover td { background:#f0f6ff; }
    .status-active { color:#27ae60; font-weight:700; }
    .status-done   { color:#7f8c8d; }
    .btn-end { background:#e74c3c; color:#fff; padding:4px 12px; border:none;
               border-radius:4px; cursor:pointer; font-size:0.82rem; text-decoration:none; }
    .btn-end:hover { background:#c0392b; }
    .pagination-info { font-size:0.85rem; color:#666; margin-top:10px; }
    .pagination { display:flex; gap:4px; margin-top:8px; flex-wrap:wrap; }
    .pagination a, .pagination span { padding:5px 11px; border:1px solid #ccc; border-radius:4px;
                                      text-decoration:none; font-size:0.85rem; color:#1a5276; }
    .pagination span.current { background:#1a5276; color:#fff; border-color:#1a5276; }
    .pagination a:hover { background:#eef2f7; }
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
  <a href="admin_sitin_records.php" class="active">View Sit-in Records</a>
  <a href="admin_reports.php">Sit-in Reports</a>
  <a href="admin_feedback.php">Feedback Reports</a>
  <a href="admin_reservation.php">Reservation</a>
  <a href="admin_logout.php" class="logout-btn">Log out</a>
</nav>
<main style="padding:24px;">

  <h2 style="color:#1a3a6b; margin-bottom:20px; text-align:center;">Current Sit-in Records</h2>

  <!-- Two pie charts matching photo -->
  <div class="charts-row">
    <div class="chart-box">
      <h3>Purpose Breakdown</h3>
      <canvas id="purposeChart" height="220"></canvas>
    </div>
    <div class="chart-box">
      <h3>Lab Usage</h3>
      <canvas id="labChart" height="220"></canvas>
    </div>
  </div>

  <!-- Table controls -->
  <div class="table-controls">
    <label>
      <select onchange="changePerPage(this.value)">
        <?php foreach ([10,25,50,100] as $n): ?>
          <option value="<?= $n ?>" <?= $per_page==$n?'selected':'' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
      entries per page
    </label>
    <span style="flex:1"></span>
    <label>Search:
      <input type="text" id="searchInput" value="<?= htmlspecialchars($search) ?>"
             placeholder="Name, purpose, lab..." onkeyup="doSearch(this.value)"/>
    </label>
  </div>

  <div class="table-wrap">
    <table id="recordsTable">
      <thead>
        <tr>
          <th onclick="sortTable(0)">Sit-in Number ▲</th>
          <th onclick="sortTable(1)">ID Number</th>
          <th onclick="sortTable(2)">Name</th>
          <th onclick="sortTable(3)">Purpose</th>
          <th onclick="sortTable(4)">Lab</th>
          <th onclick="sortTable(5)">Login</th>
          <th onclick="sortTable(6)">Logout</th>
          <th onclick="sortTable(7)">Date</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($records && $records->num_rows > 0):
          while ($row = $records->fetch_assoc()):
            $timein  = strtotime($row['session_date']);
            $timeout = $row['time_out'] ? strtotime($row['time_out']) : null;
        ?>
          <tr>
            <td><?= $row['sit_id'] ?></td>
            <td><?= htmlspecialchars($row['idnumber']) ?></td>
            <td><?= htmlspecialchars($row['firstname'].' '.$row['lastname']) ?></td>
            <td><?= htmlspecialchars($row['purpose']) ?></td>
            <td><?= htmlspecialchars($row['lab']) ?></td>
            <td><?= date('h:i:sa', $timein) ?></td>
            <td><?= $timeout ? date('h:i:sa', $timeout) : '—' ?></td>
            <td><?= date('Y-m-d', $timein) ?></td>
            <td class="status-<?= $row['status'] ?>"><?= ucfirst($row['status']) ?></td>
            <td>
              <?php if ($row['status'] === 'active'): ?>
                <a class="btn-end"
                   href="admin_sitin_records.php?end=<?= $row['sit_id'] ?>"
                   onclick="return confirm('End this sit-in session?')">Logout</a>
              <?php else: ?>
                <span style="color:#aaa;font-size:0.82rem;">Done</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="10" style="text-align:center;padding:20px;color:#999;">No records found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="pagination-info">
    Showing <?= $total ? ($offset+1) : 0 ?> to <?= min($offset+$per_page,$total) ?> of <?= $total ?> entries
  </div>
  <div class="pagination">
    <a href="?page=1&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">«</a>
    <?php if ($page>1): ?>
      <a href="?page=<?= $page-1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">‹</a>
    <?php endif; ?>
    <?php for ($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
      <?php if ($i==$page): ?><span class="current"><?= $i ?></span>
      <?php else: ?><a href="?page=<?= $i ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page<$total_pages): ?>
      <a href="?page=<?= $page+1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">›</a>
    <?php endif; ?>
    <a href="?page=<?= $total_pages ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">»</a>
  </div>
</main>

<script>
// Charts
new Chart(document.getElementById('purposeChart'), {
  type: 'pie',
  data: {
    labels: <?= json_encode($pLabels ?: ['No Data']) ?>,
    datasets: [{ data: <?= json_encode($pData ?: [1]) ?>,
      backgroundColor:['#3498db','#e74c3c','#f39c12','#2ecc71','#9b59b6','#1abc9c','#e67e22','#34495e'] }]
  },
  options: { responsive:true, plugins:{ legend:{ position:'top' } } }
});
new Chart(document.getElementById('labChart'), {
  type: 'pie',
  data: {
    labels: <?= json_encode($lLabels ?: ['No Data']) ?>,
    datasets: [{ data: <?= json_encode($lData ?: [1]) ?>,
      backgroundColor:['#2980b9','#e74c3c','#f39c12','#2ecc71','#8e44ad','#16a085'] }]
  },
  options: { responsive:true, plugins:{ legend:{ position:'top' } } }
});

function changePerPage(val) {
  const url = new URL(window.location);
  url.searchParams.set('per_page', val);
  url.searchParams.set('page', 1);
  window.location = url;
}
let st;
function doSearch(val) {
  clearTimeout(st);
  st = setTimeout(() => {
    const url = new URL(window.location);
    url.searchParams.set('search', val);
    url.searchParams.set('page', 1);
    window.location = url;
  }, 400);
}
function sortTable(col) {
  const table = document.getElementById('recordsTable');
  const tbody = table.tBodies[0];
  const rows  = Array.from(tbody.rows);
  const asc   = table.dataset.sortCol==col && table.dataset.sortDir==='asc' ? false : true;
  table.dataset.sortCol=col; table.dataset.sortDir=asc?'asc':'desc';
  rows.sort((a,b)=>{
    const va=a.cells[col].innerText.trim().toLowerCase();
    const vb=b.cells[col].innerText.trim().toLowerCase();
    const na=parseFloat(va), nb=parseFloat(vb);
    if(!isNaN(na)&&!isNaN(nb)) return asc?na-nb:nb-na;
    return asc?va.localeCompare(vb):vb.localeCompare(va);
  });
  rows.forEach(r=>tbody.appendChild(r));
}
</script>
</body>
</html>