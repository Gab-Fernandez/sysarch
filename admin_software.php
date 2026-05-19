<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Ensure table
$conn->query("CREATE TABLE IF NOT EXISTS lab_software (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab VARCHAR(30) NOT NULL,
    software_name VARCHAR(100) NOT NULL,
    version VARCHAR(50) DEFAULT '',
    category VARCHAR(50) DEFAULT 'General',
    status ENUM('available','unavailable') DEFAULT 'available',
    added_by VARCHAR(50) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$labs       = ['Lab 1','Lab 2','Lab 3','Lab 4','Lab 5','Lab 6','524','526','528','530','542','Mac Lab'];
$categories = ['Programming','Database','Design','Productivity','Utilities','Office','Internet','Security','General'];
$message = ""; $message_type = "";

// ── Add single software ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_software'])) {
    $lab     = trim($_POST['lab']);
    $name    = trim($_POST['software_name']);
    $version = trim($_POST['version'] ?? '');
    $cat     = trim($_POST['category']);
    $status  = $_POST['status'] ?? 'available';

    if ($name && $lab) {
        $ins = $conn->prepare("INSERT INTO lab_software (lab,software_name,version,category,status,added_by) VALUES (?,?,?,?,?,?)");
        $ins->bind_param("ssssss", $lab,$name,$version,$cat,$status,$_SESSION['admin_username']);
        $ins->execute() ? ($message="Software added.") && ($message_type="success")
                       : ($message="Add failed.") && ($message_type="error");
        $ins->close();
    }
}

// ── Toggle status ─────────────────────────────────────────────
if (isset($_GET['toggle'])) {
    $id  = intval($_GET['toggle']);
    $cur = $conn->query("SELECT status FROM lab_software WHERE id=$id")->fetch_assoc()['status'];
    $new = $cur==='available' ? 'unavailable' : 'available';
    $conn->query("UPDATE lab_software SET status='$new' WHERE id=$id");
    header("Location: admin_software.php"); exit();
}

// ── Delete ────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM lab_software WHERE id=$id");
    header("Location: admin_software.php"); exit();
}

// ── CSV Import ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['import_csv']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error']===0 && pathinfo($file['name'],PATHINFO_EXTENSION)==='csv') {
        $handle  = fopen($file['tmp_name'],'r');
        $header  = fgetcsv($handle); // skip header row
        $imported = 0;
        while(($row=fgetcsv($handle))!==false) {
            if (count($row)<2) continue;
            $r_lab    = $conn->real_escape_string(trim($row[0] ?? ''));
            $r_name   = $conn->real_escape_string(trim($row[1] ?? ''));
            $r_ver    = $conn->real_escape_string(trim($row[2] ?? ''));
            $r_cat    = $conn->real_escape_string(trim($row[3] ?? 'General'));
            $r_status = in_array(trim($row[4]??''),['available','unavailable']) ? trim($row[4]) : 'available';
            if ($r_lab && $r_name) {
                $conn->query("INSERT INTO lab_software (lab,software_name,version,category,status,added_by)
                              VALUES ('$r_lab','$r_name','$r_ver','$r_cat','$r_status','".$_SESSION['admin_username']."')");
                $imported++;
            }
        }
        fclose($handle);
        $message = "Imported $imported software entries."; $message_type = "success";
    } else {
        $message = "Please upload a valid .csv file."; $message_type = "error";
    }
}

// ── Filter / fetch ────────────────────────────────────────────
$filter_lab = isset($_GET['lab']) ? $conn->real_escape_string($_GET['lab']) : '';
$where = $filter_lab ? "WHERE lab='$filter_lab'" : '';
$software = $conn->query("SELECT * FROM lab_software $where ORDER BY lab, category, software_name");

// Stats
$totalSW  = $conn->query("SELECT COUNT(*) as c FROM lab_software")->fetch_assoc()['c'];
$availSW  = $conn->query("SELECT COUNT(*) as c FROM lab_software WHERE status='available'")->fetch_assoc()['c'];
$totalLabs= $conn->query("SELECT COUNT(DISTINCT lab) as c FROM lab_software")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Software Management</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .sw-wrap { padding: 20px; }
    .two-col { display: grid; grid-template-columns: 340px 1fr; gap: 20px; align-items: start; }

    /* form card */
    .form-card { background:#fff; border-radius:10px; padding:20px; box-shadow:0 1px 8px rgba(0,0,0,0.07); }
    .form-card h3 { color:#1a5276; font-size:0.95rem; font-weight:700; margin-bottom:14px; border-bottom:2px solid #1a5276; padding-bottom:8px; }
    .fg { margin-bottom:12px; }
    .fg label { display:block; font-weight:600; font-size:0.85rem; margin-bottom:4px; }
    .fg input,.fg select { width:100%; padding:9px 11px; border:1.5px solid #d0daea; border-radius:7px; font-size:0.88rem; }
    .fg input:focus,.fg select:focus { border-color:#2563c0; outline:none; }
    .btn-add { background:#27ae60; color:#fff; border:none; padding:10px; width:100%; border-radius:7px; font-weight:700; font-size:0.92rem; cursor:pointer; margin-top:4px; }
    .btn-add:hover { background:#219150; }

    /* CSV import */
    .csv-card { background:#fff; border-radius:10px; padding:18px 20px; box-shadow:0 1px 8px rgba(0,0,0,0.07); margin-top:16px; }
    .csv-card h3 { color:#1a5276; font-size:0.92rem; font-weight:700; margin-bottom:12px; }
    .csv-note { font-size:0.78rem; color:#64748b; margin-bottom:10px; line-height:1.5; }
    .btn-import { background:#1a5276; color:#fff; border:none; padding:9px 18px; border-radius:6px; font-weight:700; font-size:0.88rem; cursor:pointer; }
    .btn-import:hover { background:#0f3460; }
    .btn-dl { display:inline-block; margin-top:8px; font-size:0.8rem; color:#2563c0; text-decoration:none; }

    /* right panel */
    .right-panel {}
    .filter-bar { display:flex; gap:10px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
    .filter-bar select { padding:7px 10px; border:1.5px solid #d0daea; border-radius:6px; font-size:0.85rem; }
    .filter-bar a { padding:7px 14px; background:#f1f5f9; color:#475569; border-radius:6px; text-decoration:none; font-weight:600; font-size:0.85rem; }

    .stats-row { display:flex; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
    .mini-stat { flex:1; min-width:100px; background:#fff; border-radius:8px; padding:12px 14px; box-shadow:0 1px 5px rgba(0,0,0,0.06); border-left:4px solid var(--c,#1a5276); text-align:center; }
    .mini-stat .mn { font-size:1.4rem; font-weight:800; color:var(--c,#1a5276); }
    .mini-stat .ml { font-size:0.75rem; color:#64748b; font-weight:600; }

    table { width:100%; border-collapse:collapse; font-size:0.86rem; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 1px 8px rgba(0,0,0,0.07); }
    th { background:#1a5276; color:#fff; padding:10px 12px; text-align:left; font-weight:600; white-space:nowrap; }
    td { padding:9px 12px; border-bottom:1px solid #f1f5f9; }
    tr:last-child td { border-bottom:none; }
    tr:hover td { background:#f8faff; }

    .badge-av   { background:#dcfce7; color:#166534; padding:2px 9px; border-radius:10px; font-size:0.78rem; font-weight:700; }
    .badge-un   { background:#fee2e2; color:#991b1b; padding:2px 9px; border-radius:10px; font-size:0.78rem; font-weight:700; }
    .btn-toggle { background:#f59e0b; color:#fff; padding:4px 10px; border:none; border-radius:5px; cursor:pointer; font-size:0.78rem; font-weight:700; text-decoration:none; display:inline-block; }
    .btn-del    { background:#ef4444; color:#fff; padding:4px 10px; border:none; border-radius:5px; cursor:pointer; font-size:0.78rem; font-weight:700; text-decoration:none; display:inline-block; margin-left:4px; }

    @media(max-width:900px){ .two-col{grid-template-columns:1fr;} }
  </style>
</head>
<body>
<header>
  <img src="uclogo.png" alt="UC Logo" class="logo"/>
  <h1>College of Computer Studies Admin</h1>
  <img src="ucmainccslogo.png" alt="CCS Logo" class="logo"/>
</header>
<?php include 'nav_admin.php'; ?>

<main>
<div class="sw-wrap">
  <h2 style="color:#1a3a6b; margin-bottom:18px;">💻 Lab Software Management</h2>

  <?php if ($message): ?>
    <div class="msg-<?= $message_type ?>" style="margin-bottom:14px;"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="two-col">

    <!-- LEFT: Add form + CSV import -->
    <div>
      <div class="form-card">
        <h3>➕ Add Software</h3>
        <form method="POST">
          <div class="fg">
            <label>Laboratory</label>
            <select name="lab" required>
              <option value="">— Select Lab —</option>
              <?php foreach($labs as $l): ?>
                <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>Software Name</label>
            <input type="text" name="software_name" required placeholder="e.g., Visual Studio Code"/>
          </div>
          <div class="fg">
            <label>Version <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
            <input type="text" name="version" placeholder="e.g., 1.85.0"/>
          </div>
          <div class="fg">
            <label>Category</label>
            <select name="category">
              <?php foreach($categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>Status</label>
            <select name="status">
              <option value="available">✓ Available</option>
              <option value="unavailable">✗ Unavailable</option>
            </select>
          </div>
          <button type="submit" name="add_software" class="btn-add">Add Software</button>
        </form>
      </div>

      <!-- CSV Import -->
      <div class="csv-card">
        <h3>📂 Import from CSV</h3>
        <div class="csv-note">
          CSV format: <code>lab, software_name, version, category, status</code><br/>
          Status must be <code>available</code> or <code>unavailable</code>.
        </div>
        <form method="POST" enctype="multipart/form-data">
          <div class="fg">
            <input type="file" name="csv_file" accept=".csv" required/>
          </div>
          <button type="submit" name="import_csv" class="btn-import">📤 Import CSV</button>
        </form>
        <!-- Downloadable template -->
        <a href="data:text/csv;charset=utf-8,lab,software_name,version,category,status%0ALab%201,Visual%20Studio%20Code,1.85.0,Programming,available"
           download="software_template.csv" class="btn-dl">⬇️ Download CSV Template</a>
      </div>
    </div>

    <!-- RIGHT: Table -->
    <div class="right-panel">
      <div class="stats-row">
        <div class="mini-stat" style="--c:#1a5276;"><div class="mn"><?= $totalSW ?></div><div class="ml">Total Listed</div></div>
        <div class="mini-stat" style="--c:#27ae60;"><div class="mn"><?= $availSW ?></div><div class="ml">Available</div></div>
        <div class="mini-stat" style="--c:#ef4444;"><div class="mn"><?= $totalSW-$availSW ?></div><div class="ml">Unavailable</div></div>
        <div class="mini-stat" style="--c:#8b5cf6;"><div class="mn"><?= $totalLabs ?></div><div class="ml">Labs</div></div>
      </div>

      <div class="filter-bar">
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <label style="font-weight:600;font-size:0.85rem;">Filter by Lab:</label>
          <select name="lab" onchange="this.form.submit()">
            <option value="">All Labs</option>
            <?php foreach($labs as $l): ?>
              <option value="<?= htmlspecialchars($l) ?>" <?= $filter_lab===$l?'selected':'' ?>><?= htmlspecialchars($l) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php if($filter_lab): ?>
          <a href="admin_software.php">Clear Filter</a>
        <?php endif; ?>
      </div>

      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>Lab</th>
              <th>Software</th>
              <th>Version</th>
              <th>Category</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if($software && $software->num_rows > 0):
              while($sw=$software->fetch_assoc()): ?>
              <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($sw['lab']) ?></td>
                <td>💾 <?= htmlspecialchars($sw['software_name']) ?></td>
                <td style="color:#94a3b8;"><?= htmlspecialchars($sw['version'] ?: '—') ?></td>
                <td><?= htmlspecialchars($sw['category']) ?></td>
                <td>
                  <span class="badge-<?= $sw['status']==='available'?'av':'un' ?>">
                    <?= $sw['status']==='available'?'✓ Available':'✗ Unavailable' ?>
                  </span>
                </td>
                <td>
                  <a href="admin_software.php?toggle=<?= $sw['id'] ?>" class="btn-toggle">Toggle</a>
                  <a href="admin_software.php?delete=<?= $sw['id'] ?>"
                     class="btn-del"
                     onclick="return confirm('Delete <?= htmlspecialchars(addslashes($sw['software_name'])) ?>?')">Delete</a>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">No software listed yet. Add some above or import a CSV.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</main>
</body>
</html>