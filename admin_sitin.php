<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$message = ""; $message_type = "";

// ── End sit-in ────────────────────────────────────────────────
if (isset($_GET['end'])) {
    $sit_id  = intval($_GET['end']);
    $timeout = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE sit_in SET status = 'done', time_out = ? WHERE sit_id = ?");
    $stmt->bind_param("si", $timeout, $sit_id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_sitin.php");
    exit();
}

// ── Pagination & search ───────────────────────────────────────
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
if (!in_array($per_page, [10,25,50,100])) $per_page = 10;
$page   = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

$where = "WHERE s.status = 'active'";
if ($search) $where .= " AND (s.idnumber LIKE '%$search%' OR u.firstname LIKE '%$search%' OR u.lastname LIKE '%$search%' OR s.purpose LIKE '%$search%' OR s.lab LIKE '%$search%')";

$total       = $conn->query("SELECT COUNT(*) as c FROM sit_in s JOIN users u ON s.idnumber = u.idnumber $where")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total / $per_page));

$records = $conn->query("
    SELECT s.*, u.firstname, u.lastname, u.remaining_session
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Sit-in</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .table-section h2 { color:#1a3a6b; text-align:center; margin-bottom:16px; font-size:1.2rem; }
    .table-section { padding:20px; }
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
  <a href="admin_sitin.php" class="active">Sit-in</a>
  <a href="admin_sitin_records.php">Sit-in Records</a>
  <a href="admin_reports.php">Reports</a>
  <a href="admin_feedback.php">Feedback</a>
  <a href="admin_reservation.php">Reservation</a>
  <a href="admin_logout.php" class="logout-btn">Log out</a>
</nav>
<main>
  <?php if ($message): ?>
    <div style="padding:0 20px; padding-top:14px;">
      <div class="msg-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
    </div>
  <?php endif; ?>

  <div class="table-section">
      <h2>Current Sit-in Sessions</h2>
      <div class="table-controls">
        <label>
          Show
          <select onchange="changePerPage(this.value)">
            <?php foreach ([10,25,50,100] as $n): ?>
              <option value="<?= $n ?>" <?= $per_page == $n ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
          entries
        </label>
        <span style="flex:1"></span>
        <label>Search:
          <input type="text" id="searchInput" value="<?= htmlspecialchars($search) ?>"
                 placeholder="Name, lab, purpose..." onkeyup="doSearch(this.value)"/>
        </label>
      </div>

      <div class="table-wrap">
        <table id="sitinTable">
          <thead>
            <tr>
              <th onclick="sortTable(0)">Sit ID ▲</th>
              <th onclick="sortTable(1)">ID Number</th>
              <th onclick="sortTable(2)">Name</th>
              <th onclick="sortTable(3)">Purpose</th>
              <th onclick="sortTable(4)">Lab</th>
              <th onclick="sortTable(5)">Sessions Left</th>
              <th onclick="sortTable(6)">Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($records && $records->num_rows > 0):
              while ($row = $records->fetch_assoc()): ?>
              <tr>
                <td><?= $row['sit_id'] ?></td>
                <td><?= htmlspecialchars($row['idnumber']) ?></td>
                <td><?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) ?></td>
                <td><?= htmlspecialchars($row['purpose']) ?></td>
                <td><?= htmlspecialchars($row['lab']) ?></td>
                <td><?= $row['remaining_session'] ?></td>
                <td class="status-active">Active</td>
                <td>
                  <a class="btn-end"
                     href="admin_sitin.php?end=<?= $row['sit_id'] ?>"
                     onclick="return confirm('End this sit-in session?')">End Session</a>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="8" style="text-align:center;padding:20px;color:#999;">No active sit-in sessions.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination-info">
        Showing <?= $total ? ($offset+1) : 0 ?> to <?= min($offset+$per_page, $total) ?> of <?= $total ?> entries
      </div>
      <div class="pagination">
        <a href="?page=1&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">«</a>
        <?php if ($page > 1): ?>
          <a href="?page=<?= $page-1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">‹</a>
        <?php endif; ?>
        <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
          <?php if ($i == $page): ?><span class="current"><?= $i ?></span>
          <?php else: ?><a href="?page=<?= $i ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
          <a href="?page=<?= $page+1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">›</a>
        <?php endif; ?>
        <a href="?page=<?= $total_pages ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">»</a>
      </div>
    </div>
</main>

<script>
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
  const table = document.getElementById('sitinTable');
  const tbody = table.tBodies[0];
  const rows  = Array.from(tbody.rows);
  const asc   = table.dataset.sortCol == col && table.dataset.sortDir === 'asc' ? false : true;
  table.dataset.sortCol = col;
  table.dataset.sortDir = asc ? 'asc' : 'desc';
  rows.sort((a,b) => {
    const va = a.cells[col].innerText.trim().toLowerCase();
    const vb = b.cells[col].innerText.trim().toLowerCase();
    const na = parseFloat(va), nb = parseFloat(vb);
    if (!isNaN(na) && !isNaN(nb)) return asc ? na-nb : nb-na;
    return asc ? va.localeCompare(vb) : vb.localeCompare(va);
  });
  rows.forEach(r => tbody.appendChild(r));
}
</script>
</body>
</html>
