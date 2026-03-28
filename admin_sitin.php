<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$message = ""; $message_type = "";
$labs     = ['Lab 1','Lab 2','Lab 3','Lab 4','Lab 5','Lab 6','524','526','528','530','542','Mac Lab'];
$purposes = ['C#','C','Java','ASP.Net','PHP','Programming','Research','Online Class','Project','Assignment','Printing','Internet','Other'];

// Start sit-in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_sitin'])) {
    $idnumber = trim($_POST['idnumber']);
    $lab      = trim($_POST['lab']);
    $purpose  = trim($_POST['purpose']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE idnumber = ?");
    $stmt->bind_param("s", $idnumber);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        $message = "Student not found."; $message_type = "error";
    } elseif ($student['remaining_session'] <= 0) {
        $message = "Student has no remaining sessions."; $message_type = "error";
    } else {
        $chk = $conn->prepare("SELECT sit_id FROM sit_in WHERE idnumber=? AND status='active'");
        $chk->bind_param("s",$idnumber); $chk->execute(); $chk->store_result();
        if ($chk->num_rows > 0) {
            $message = "Student already has an active sit-in."; $message_type = "error";
        } else {
            $now = date('Y-m-d H:i:s');
            $ins = $conn->prepare("INSERT INTO sit_in (idnumber,lab,purpose,session_date,status) VALUES (?,?,?,?,'active')");
            $ins->bind_param("ssss",$idnumber,$lab,$purpose,$now);
            if ($ins->execute()) {
                $new_sess = $student['remaining_session'] - 1;
                $conn->prepare("UPDATE users SET remaining_session=? WHERE idnumber=?")
                     ->bind_param("is",$new_sess,$idnumber);
                $upd2 = $conn->prepare("UPDATE users SET remaining_session=? WHERE idnumber=?");
                $upd2->bind_param("is",$new_sess,$idnumber);
                $upd2->execute();
                $message = "Sit-in session started for ".$student['firstname']." ".$student['lastname'].".";
                $message_type = "success";
            }
            $ins->close();
        }
        $chk->close();
    }
}

// End sit-in
if (isset($_GET['end'])) {
    $sit_id  = intval($_GET['end']);
    $timeout = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE sit_in SET status='done', time_out=? WHERE sit_id=?");
    $stmt->bind_param("si",$timeout,$sit_id);
    $stmt->execute(); $stmt->close();
    header("Location: admin_sitin.php"); exit();
}

// Pagination & search for the active table
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
if (!in_array($per_page,[10,25,50,100])) $per_page=10;
$page   = isset($_GET['page']) ? max(1,intval($_GET['page'])) : 1;
$offset = ($page-1)*$per_page;
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

$where = "WHERE s.status='active'";
if ($search) $where .= " AND (s.idnumber LIKE '%$search%' OR u.firstname LIKE '%$search%' OR u.lastname LIKE '%$search%' OR s.purpose LIKE '%$search%' OR s.lab LIKE '%$search%')";

$total = $conn->query("SELECT COUNT(*) as c FROM sit_in s JOIN users u ON s.idnumber=u.idnumber $where")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total/$per_page));

$records = $conn->query("
    SELECT s.*, u.firstname, u.lastname, u.remaining_session
    FROM sit_in s JOIN users u ON s.idnumber=u.idnumber
    $where ORDER BY s.session_date DESC
    LIMIT $per_page OFFSET $offset
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>CCS | Sit-in</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .two-col { display:grid; grid-template-columns:340px 1fr; gap:20px; }
    .sitin-form-card { background:#fff; border-radius:8px; padding:20px;
                       box-shadow:0 1px 6px rgba(0,0,0,0.07); align-self:start; }
    .sitin-form-card h3 { color:#1a5276; margin-bottom:14px; border-bottom:2px solid #1a5276; padding-bottom:8px; }
    .form-group { margin-bottom:13px; }
    .form-group label { display:block; font-weight:600; font-size:0.88rem; margin-bottom:4px; }
    .form-group input, .form-group select { width:100%; padding:9px 11px; border:1px solid #ccc; border-radius:5px; }
    .btn-start { background:#28a745; color:#fff; border:none; padding:11px; width:100%;
                 border-radius:5px; font-size:1rem; font-weight:700; cursor:pointer; }
    .btn-start:hover { background:#218838; }
    .table-section h2 { color:#1a3a6b; text-align:center; margin-bottom:16px; }
    .table-controls { display:flex; align-items:center; gap:12px; margin-bottom:10px; flex-wrap:wrap; }
    .table-controls label { font-weight:600; font-size:0.88rem; }
    .table-controls select, .table-controls input { padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:0.88rem; }
    table { width:100%; border-collapse:collapse; font-size:0.88rem; background:#fff; }
    th { background:#1a5276; color:#fff; padding:10px 12px; text-align:left;
         cursor:pointer; user-select:none; white-space:nowrap; }
    th:hover { background:#0f3460; }
    td { padding:9px 12px; border-bottom:1px solid #e5e9f0; white-space:nowrap; }
    tr:hover td { background:#f0f6ff; }
    .btn-end { background:#e74c3c; color:#fff; padding:4px 12px; border:none;
               border-radius:4px; cursor:pointer; font-size:0.82rem; text-decoration:none; }
    .status-active { color:#27ae60; font-weight:700; }
    .pagination-info { font-size:0.85rem; color:#666; margin-top:10px; }
    .pagination { display:flex; gap:4px; margin-top:8px; }
    .pagination a, .pagination span { padding:5px 11px; border:1px solid #ccc; border-radius:4px; text-decoration:none; font-size:0.85rem; color:#1a5276; }
    .pagination span.current { background:#1a5276; color:#fff; border-color:#1a5276; }
    .pagination a:hover { background:#eef2f7; }
    .msg-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:10px 14px; border-radius:5px; margin-bottom:14px; }
    .msg-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; padding:10px 14px; border-radius:5px; margin-bottom:14px; }
    @media(max-width:900px){ .two-col{grid-template-columns:1fr;} }
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
  <a href="admin_sitin_records.php">View Sit-in Records</a>
  <a href="admin_reports.php">Sit-in Reports</a>
  <a href="admin_feedback.php">Feedback Reports</a>
  <a href="admin_reservation.php">Reservation</a>
  <a href="admin_logout.php" class="logout-btn">Log out</a>
</nav>
<main style="padding:24px;">

  <?php if ($message): ?>
    <div class="msg-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="two-col">

    <!-- Start Sit-in Form -->
    <div class="sitin-form-card">
      <h3>➕ Start Sit-in Session</h3>
      <form method="POST" action="admin_sitin.php">
        <div class="form-group">
          <label>Student ID Number</label>
          <input type="text" name="idnumber" required placeholder="e.g., 2024-00001"/>
        </div>
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
          <label>Purpose</label>
          <select name="purpose" required>
            <option value="">Select Purpose</option>
            <?php foreach ($purposes as $p): ?>
              <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" name="start_sitin" class="btn-start">Start Sit-in</button>
      </form>
    </div>

    <!-- Current Sit-in Table -->
    <div class="table-section">
      <h2>Current Sit in</h2>

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
                 placeholder="Name, lab, purpose..." onkeyup="doSearch(this.value)"/>
        </label>
      </div>

      <div style="overflow-x:auto;">
        <table id="sitinTable">
          <thead>
            <tr>
              <th onclick="sortTable(0)">Sit ID Number ▲</th>
              <th onclick="sortTable(1)">ID Number</th>
              <th onclick="sortTable(2)">Name</th>
              <th onclick="sortTable(3)">Purpose</th>
              <th onclick="sortTable(4)">Sit Lab</th>
              <th onclick="sortTable(5)">Session</th>
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
                <td><?= htmlspecialchars($row['firstname'].' '.$row['lastname']) ?></td>
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
        Showing <?= $total ? ($offset+1) : 0 ?> to <?= min($offset+$per_page,$total) ?> of <?= $total ?> entries
      </div>
      <div class="pagination">
        <a href="?page=1&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">«</a>
        <?php if ($page>1): ?>
          <a href="?page=<?= $page-1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">‹</a>
        <?php endif; ?>
        <?php for ($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
          <?php if ($i==$page): ?><span class="current"><?= $i ?></span>
          <?php else: ?><a href="?page=<?= $i ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page<$total_pages): ?>
          <a href="?page=<?= $page+1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">›</a>
        <?php endif; ?>
        <a href="?page=<?= $total_pages ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">»</a>
      </div>
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