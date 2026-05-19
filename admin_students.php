<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$message = ""; $message_type = "";

// ── Reset ALL sessions ────────────────────────────────────────
if (isset($_GET['reset_all'])) {
    $conn->query("UPDATE users SET remaining_session = 30");
    $message = "All student sessions have been reset to 30.";
    $message_type = "success";
}

// ── Reset ONE student's sessions ──────────────────────────────
if (isset($_GET['reset_sessions'])) {
    $idnumber = $conn->real_escape_string($_GET['reset_sessions']);
    $conn->query("UPDATE users SET remaining_session = 30 WHERE idnumber = '$idnumber'");
    $message = "Sessions reset for ID: " . htmlspecialchars($idnumber);
    $message_type = "success";
}

// ── Delete student ────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $idnumber = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE idnumber = ?");
    $stmt->bind_param("s", $idnumber);
    $stmt->execute() ? ($message = "Student deleted.") && ($message_type = "success")
                     : ($message = "Delete failed.") && ($message_type = "error");
    $stmt->close();
}

// ── Add student (modal form POST) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $idnumber    = trim($_POST['idnumber']);
    $lastname    = trim($_POST['lastname']);
    $firstname   = trim($_POST['firstname']);
    $middlename  = trim($_POST['middlename']);
    $courselevel = intval($_POST['courselevel']);
    $course      = trim($_POST['course']);
    $address     = trim($_POST['address']);
    $email       = trim($_POST['email']);
    $password    = $_POST['password'];

    $chk = $conn->prepare("SELECT id FROM users WHERE idnumber=? OR email=?");
    $chk->bind_param("ss", $idnumber, $email);
    $chk->execute(); $chk->store_result();
    if ($chk->num_rows > 0) {
        $message = "ID or Email already exists."; $message_type = "error";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $ins = $conn->prepare(
            "INSERT INTO users (idnumber,lastname,firstname,middlename,courselevel,course,address,email,password,remaining_session)
             VALUES (?,?,?,?,?,?,?,?,?,30)"
        );
        $ins->bind_param("ssssissss", $idnumber,$lastname,$firstname,$middlename,$courselevel,$course,$address,$email,$hashed);
        $ins->execute() ? ($message="Student added successfully.") && ($message_type="success")
                       : ($message="Add failed: ".$ins->error) && ($message_type="error");
        $ins->close();
    }
    $chk->close();
}

// ── Edit student (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    $idnumber    = trim($_POST['idnumber']);
    $lastname    = trim($_POST['lastname']);
    $firstname   = trim($_POST['firstname']);
    $middlename  = trim($_POST['middlename']);
    $courselevel = intval($_POST['courselevel']);
    $course      = trim($_POST['course']);
    $address     = trim($_POST['address']);
    $email       = trim($_POST['email']);
    $sessions    = intval($_POST['remaining_session']);

    $upd = $conn->prepare(
        "UPDATE users SET lastname=?,firstname=?,middlename=?,courselevel=?,course=?,address=?,email=?,remaining_session=? WHERE idnumber=?"
    );
    $upd->bind_param("sssississs",$lastname,$firstname,$middlename,$courselevel,$course,$address,$email,$sessions,$idnumber);
    $upd->execute() ? ($message="Student updated.") && ($message_type="success")
                   : ($message="Update failed.") && ($message_type="error");
    $upd->close();
}

// ── Pagination ────────────────────────────────────────────────
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
if (!in_array($per_page, [10,25,50,100])) $per_page = 10;
$page     = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset   = ($page - 1) * $per_page;
$search   = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

$where = $search ? "WHERE idnumber LIKE '%$search%' OR lastname LIKE '%$search%' OR firstname LIKE '%$search%'" : '';
$total_students = $conn->query("SELECT COUNT(*) as c FROM users $where")->fetch_assoc()['c'];
$total_pages    = max(1, ceil($total_students / $per_page));
$students       = $conn->query("SELECT * FROM users $where ORDER BY lastname, firstname LIMIT $per_page OFFSET $offset");

// Fetch edit target
$edit_student = null;
if (isset($_GET['edit'])) {
    $eid = $conn->real_escape_string($_GET['edit']);
    $edit_student = $conn->query("SELECT * FROM users WHERE idnumber='$eid'")->fetch_assoc();
}

$courses = ['BS Computer Science','BS Information Technology','BS Computer Engineering',
            'BS Accountancy','BS Business Administration','BS Criminology',
            'BS Civil Engineering','BS Electrical Engineering','BS Mechanical Engineering',
            'BS Industrial Engineering','BS Commerce','BS Hotel & Restaurant Management',
            'BS Tourism Management','BS Elementary Education','BS Secondary Education',
            'BS Customs Administration','BS Industrial Psychology','BS Real Estate Management','BS Office Administration'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>CCS | Students</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .page-top { display:flex; align-items:center; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
    .page-top h2 { flex:1; color:#1a3a6b; }
    .btn-add   { background:#1a5276; color:#fff; padding:8px 18px; border:none; border-radius:5px; cursor:pointer; font-weight:700; font-size:0.9rem; }
    .btn-reset-all { background:#e74c3c; color:#fff; padding:8px 18px; border:none; border-radius:5px; cursor:pointer; font-weight:700; font-size:0.9rem; }
    .btn-add:hover { background:#0a3d62; }
    .btn-reset-all:hover { background:#c0392b; }
    .table-controls { display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap; }
    .table-controls label { font-weight:600; font-size:0.88rem; }
    .table-controls select, .table-controls input { padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:0.88rem; }
    .table-wrap { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:0.88rem; }
    th { background:#1a5276; color:#fff; padding:10px 12px; text-align:left; cursor:pointer; user-select:none; white-space:nowrap; }
    th:hover { background:#0f3460; }
    td { padding:9px 12px; border-bottom:1px solid #e5e9f0; }
    tr:hover td { background:#f0f6ff; }
    .btn-edit   { background:#1a5276; color:#fff; padding:4px 12px; border:none; border-radius:4px; cursor:pointer; font-size:0.82rem; text-decoration:none; }
    .btn-delete { background:#e74c3c; color:#fff; padding:4px 12px; border:none; border-radius:4px; cursor:pointer; font-size:0.82rem; text-decoration:none; margin-left:4px; }
    .btn-reset  { background:#f39c12; color:#fff; padding:4px 10px; border:none; border-radius:4px; cursor:pointer; font-size:0.82rem; text-decoration:none; margin-left:4px; }
    .pagination-info { font-size:0.85rem; color:#666; margin-top:10px; }
    .pagination { display:flex; gap:4px; margin-top:8px; flex-wrap:wrap; }
    .pagination a, .pagination span { padding:5px 11px; border:1px solid #ccc; border-radius:4px; text-decoration:none; font-size:0.85rem; color:#1a5276; }
    .pagination span.current { background:#1a5276; color:#fff; border-color:#1a5276; }
    .pagination a:hover { background:#eef2f7; }

    /* Modal */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; }
    .modal-overlay.open { display:flex; }
    .modal { background:#fff; border-radius:10px; padding:28px 28px 20px; width:500px; max-width:95vw; max-height:90vh; overflow-y:auto; }
    .modal h3 { color:#1a5276; margin-bottom:18px; }
    .modal label { display:block; font-weight:600; font-size:0.88rem; margin-top:12px; }
    .modal input, .modal select { width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:4px; margin-top:3px; font-size:0.9rem; }
    .modal-actions { display:flex; gap:10px; margin-top:18px; }
    .modal-actions button { flex:1; padding:10px; border:none; border-radius:5px; font-weight:700; cursor:pointer; font-size:0.95rem; }
    .btn-save   { background:#28a745; color:#fff; }
    .btn-cancel { background:#6c757d; color:#fff; }
    .msg-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:10px 14px; border-radius:5px; margin-bottom:14px; }
    .msg-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; padding:10px 14px; border-radius:5px; margin-bottom:14px; }
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
  <a href="admin_students.php" class="active">Students</a>
  <a href="admin_sitin.php">Sit-in</a>
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

  <div class="page-top">
    <h2>Students Information</h2>
    <button class="btn-add" onclick="openModal('addModal')">Add Students</button>
    <a href="admin_students.php?reset_all=1" class="btn-reset-all"
       onclick="return confirm('Reset ALL student sessions to 30?')">Reset All Session</a>
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
             placeholder="ID, name..." onkeyup="doSearch(this.value)"/>
    </label>
  </div>

  <div class="table-wrap">
    <table id="studentsTable">
      <thead>
        <tr>
          <th onclick="sortTable(0)">ID Number ▲</th>
          <th onclick="sortTable(1)">Name</th>
          <th onclick="sortTable(2)">Year Level</th>
          <th onclick="sortTable(3)">Course</th>
          <th onclick="sortTable(4)">Remaining Session</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($students && $students->num_rows > 0):
          while ($row = $students->fetch_assoc()): ?>
          <tr>
            <td><?= htmlspecialchars($row['idnumber']) ?></td>
            <td><?= htmlspecialchars($row['lastname'].', '.$row['firstname'].' '.substr($row['middlename'],0,1).'.') ?></td>
            <td><?= htmlspecialchars($row['courselevel']) ?></td>
            <td><?= htmlspecialchars($row['course']) ?></td>
            <td><?= htmlspecialchars($row['remaining_session']) ?></td>
            <td>
              <a class="btn-edit"
                 href="admin_students.php?edit=<?= urlencode($row['idnumber']) ?>">Edit</a>
              <a class="btn-delete"
                 href="admin_students.php?delete=<?= urlencode($row['idnumber']) ?>"
                 onclick="return confirm('Delete this student?')">Delete</a>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="6" style="text-align:center;padding:20px;color:#999;">No students found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <div class="pagination-info">
    Showing <?= $total_students ? ($offset+1) : 0 ?> to <?= min($offset+$per_page,$total_students) ?> of <?= $total_students ?> entries
  </div>
  <div class="pagination">
    <a href="?page=1&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">«</a>
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page-1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">‹</a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
      <?php if ($i==$page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?page=<?= $i ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?>
      <a href="?page=<?= $page+1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">›</a>
    <?php endif; ?>
    <a href="?page=<?= $total_pages ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">»</a>
  </div>
</main>

<!-- ADD STUDENT MODAL -->
<div class="modal-overlay <?= (!$edit_student && isset($_POST['add_student']) && $message_type==='error') ? 'open' : '' ?>" id="addModal">
  <div class="modal">
    <h3>➕ Add Student</h3>
    <form method="POST" action="admin_students.php">
      <label>ID Number</label>
      <input type="text" name="idnumber" required placeholder="e.g. 2024-00001"/>
      <label>Last Name</label>
      <input type="text" name="lastname" required/>
      <label>First Name</label>
      <input type="text" name="firstname" required/>
      <label>Middle Name</label>
      <input type="text" name="middlename"/>
      <label>Year Level</label>
      <select name="courselevel" required>
        <?php for ($y=1;$y<=5;$y++): ?>
          <option value="<?= $y ?>">Year <?= $y ?></option>
        <?php endfor; ?>
      </select>
      <label>Course</label>
      <select name="course" required>
        <option value="">-- Select --</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Address</label>
      <input type="text" name="address"/>
      <label>Email</label>
      <input type="email" name="email" required/>
      <label>Password</label>
      <input type="password" name="password" required minlength="6" placeholder="Min. 6 characters"/>
      <div class="modal-actions">
        <button type="submit" name="add_student" class="btn-save">Add Student</button>
        <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT STUDENT MODAL -->
<?php if ($edit_student): ?>
<div class="modal-overlay open" id="editModal">
  <div class="modal">
    <h3>✏️ Edit Student</h3>
    <form method="POST" action="admin_students.php">
      <input type="hidden" name="idnumber" value="<?= htmlspecialchars($edit_student['idnumber']) ?>"/>
      <label>ID Number (read-only)</label>
      <input type="text" value="<?= htmlspecialchars($edit_student['idnumber']) ?>" readonly style="background:#f4f6f9;"/>
      <label>Last Name</label>
      <input type="text" name="lastname" required value="<?= htmlspecialchars($edit_student['lastname']) ?>"/>
      <label>First Name</label>
      <input type="text" name="firstname" required value="<?= htmlspecialchars($edit_student['firstname']) ?>"/>
      <label>Middle Name</label>
      <input type="text" name="middlename" value="<?= htmlspecialchars($edit_student['middlename']) ?>"/>
      <label>Year Level</label>
      <select name="courselevel" required>
        <?php for ($y=1;$y<=5;$y++): ?>
          <option value="<?= $y ?>" <?= $edit_student['courselevel']==$y?'selected':'' ?>>Year <?= $y ?></option>
        <?php endfor; ?>
      </select>
      <label>Course</label>
      <select name="course" required>
        <?php foreach ($courses as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>" <?= $edit_student['course']===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Address</label>
      <input type="text" name="address" value="<?= htmlspecialchars($edit_student['address']) ?>"/>
      <label>Email</label>
      <input type="email" name="email" required value="<?= htmlspecialchars($edit_student['email']) ?>"/>
      <label>Remaining Sessions</label>
      <input type="number" name="remaining_session" min="0" max="30" value="<?= $edit_student['remaining_session'] ?>" required/>
      <div class="modal-actions">
        <button type="submit" name="edit_student" class="btn-save">Save Changes</button>
        <a href="admin_students.php" class="btn-cancel" style="text-align:center;padding:10px;border-radius:5px;text-decoration:none;font-weight:700;color:#fff;">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close modal clicking outside
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

// Per page change
function changePerPage(val) {
  const url = new URL(window.location);
  url.searchParams.set('per_page', val);
  url.searchParams.set('page', 1);
  window.location = url;
}

// Search (debounced)
let searchTimer;
function doSearch(val) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    const url = new URL(window.location);
    url.searchParams.set('search', val);
    url.searchParams.set('page', 1);
    window.location = url;
  }, 400);
}

// Client-side column sort
function sortTable(col) {
  const table = document.getElementById('studentsTable');
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