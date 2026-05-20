<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

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

$labs       = ['524','526','528','530','542','Mac Lab'];
$categories = ['Programming','Database','Design','Productivity','Utilities','Office','Internet','Security','General'];
$message = ""; $message_type = "";

// ── Add ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_software'])) {
    $lab     = trim($_POST['lab']);
    $name    = trim($_POST['software_name']);
    $version = trim($_POST['version'] ?? '');
    $cat     = trim($_POST['category']);
    $status  = $_POST['status'] ?? 'available';
    if ($name && $lab) {
        $ins = $conn->prepare("INSERT INTO lab_software (lab,software_name,version,category,status,added_by) VALUES (?,?,?,?,?,?)");
        $ins->bind_param("ssssss",$lab,$name,$version,$cat,$status,$_SESSION['admin_username']);
        $ins->execute() ? ($message="Software added successfully.") && ($message_type="success")
                       : ($message="Add failed.") && ($message_type="error");
        $ins->close();
    }
}

// ── Toggle ────────────────────────────────────────────────────
if (isset($_GET['toggle'])) {
    $id  = intval($_GET['toggle']);
    $cur = $conn->query("SELECT status FROM lab_software WHERE id=$id")->fetch_assoc()['status'];
    $new = $cur==='available' ? 'unavailable' : 'available';
    $conn->query("UPDATE lab_software SET status='$new' WHERE id=$id");
    $back = isset($_GET['lab']) ? '?tab='.urlencode($_GET['lab']) : '';
    header("Location: admin_software.php$back"); exit();
}

// ── Delete ────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM lab_software WHERE id=$id");
    $back = isset($_GET['lab']) ? '?tab='.urlencode($_GET['lab']) : '';
    header("Location: admin_software.php$back"); exit();
}

// ── CSV Import ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['import_csv']) && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error']===0 && pathinfo($file['name'],PATHINFO_EXTENSION)==='csv') {
        $handle = fopen($file['tmp_name'],'r');
        fgetcsv($handle);
        $imported = 0;
        while(($row=fgetcsv($handle))!==false) {
            if (count($row)<2) continue;
            $r_lab    = $conn->real_escape_string(trim($row[0]??''));
            $r_name   = $conn->real_escape_string(trim($row[1]??''));
            $r_ver    = $conn->real_escape_string(trim($row[2]??''));
            $r_cat    = $conn->real_escape_string(trim($row[3]??'General'));
            $r_status = in_array(trim($row[4]??''),['available','unavailable'])?trim($row[4]):'available';
            if ($r_lab && $r_name) {
                $conn->query("INSERT INTO lab_software (lab,software_name,version,category,status,added_by)
                              VALUES ('$r_lab','$r_name','$r_ver','$r_cat','$r_status','".$_SESSION['admin_username']."')");
                $imported++;
            }
        }
        fclose($handle);
        $message="Imported $imported entries."; $message_type="success";
    } else {
        $message="Please upload a valid .csv file."; $message_type="error";
    }
}

// ── Active tab ────────────────────────────────────────────────
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : $labs[0];
if (!in_array($active_tab, $labs)) $active_tab = $labs[0];

// ── Fetch all software grouped by lab ─────────────────────────
$all_software = [];
$res = $conn->query("SELECT * FROM lab_software ORDER BY lab, category, software_name");
while ($r = $res->fetch_assoc()) {
    $all_software[$r['lab']][$r['category']][] = $r;
}

// ── Stats ─────────────────────────────────────────────────────
$totalSW   = $conn->query("SELECT COUNT(*) as c FROM lab_software")->fetch_assoc()['c'];
$availSW   = $conn->query("SELECT COUNT(*) as c FROM lab_software WHERE status='available'")->fetch_assoc()['c'];
$totalLabs = $conn->query("SELECT COUNT(DISTINCT lab) as c FROM lab_software")->fetch_assoc()['c'];

// Per-lab counts
$labCounts = [];
$lc = $conn->query("SELECT lab, COUNT(*) as c FROM lab_software GROUP BY lab");
while ($r=$lc->fetch_assoc()) $labCounts[$r['lab']] = (int)$r['c'];
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

    /* ── Stats row ── */
    .stats-row { display:flex; gap:14px; margin-bottom:22px; flex-wrap:wrap; }
    .mini-stat { flex:1; min-width:110px; background:#fff; border-radius:10px; padding:14px 16px;
                 box-shadow:0 1px 6px rgba(0,0,0,0.07); border-left:4px solid var(--c,#1a5276); text-align:center; }
    .mini-stat .mn { font-size:1.6rem; font-weight:800; color:var(--c,#1a5276); line-height:1; }
    .mini-stat .ml { font-size:0.75rem; color:#64748b; font-weight:600; margin-top:4px; text-transform:uppercase; }

    /* ── Two-col layout ── */
    .main-grid { display:grid; grid-template-columns:320px 1fr; gap:20px; align-items:start; }

    /* ── Left: Add form ── */
    .form-card { background:#fff; border-radius:10px; padding:20px; box-shadow:0 1px 8px rgba(0,0,0,0.07); }
    .form-card h3 { color:#1a5276; font-size:0.95rem; font-weight:700; margin-bottom:14px;
                    border-bottom:2px solid #1a5276; padding-bottom:8px; }
    .fg { margin-bottom:12px; }
    .fg label { display:block; font-weight:600; font-size:0.85rem; margin-bottom:4px; color:#334155; }
    .fg input,.fg select { width:100%; padding:9px 11px; border:1.5px solid #d0daea;
                           border-radius:7px; font-size:0.88rem; background:#f7f9fc; }
    .fg input:focus,.fg select:focus { border-color:#2563c0; outline:none; background:#fff; }
    .btn-add { background:#27ae60; color:#fff; border:none; padding:10px; width:100%;
               border-radius:7px; font-weight:700; font-size:0.92rem; cursor:pointer; margin-top:4px; }
    .btn-add:hover { background:#219150; }

    .csv-card { background:#fff; border-radius:10px; padding:18px 20px;
                box-shadow:0 1px 8px rgba(0,0,0,0.07); margin-top:16px; }
    .csv-card h3 { color:#1a5276; font-size:0.92rem; font-weight:700; margin-bottom:10px; }
    .csv-note { font-size:0.78rem; color:#64748b; margin-bottom:10px; line-height:1.5; }
    .btn-import { background:#1a5276; color:#fff; border:none; padding:9px 18px;
                  border-radius:6px; font-weight:700; font-size:0.88rem; cursor:pointer; }
    .btn-dl { display:inline-block; margin-top:8px; font-size:0.8rem; color:#2563c0; text-decoration:none; }

    /* ── Right: Lab tabs + grid ── */
    .lab-panel { background:#fff; border-radius:10px; box-shadow:0 1px 8px rgba(0,0,0,0.07); overflow:hidden; }

    /* Lab tabs */
    .lab-tabs { display:flex; flex-wrap:wrap; gap:0; border-bottom:2px solid #e2e8f0;
                background:#f8faff; padding:10px 14px 0; }
    .lab-tab {
      padding:8px 14px; font-size:0.82rem; font-weight:600;
      color:#64748b; cursor:pointer; border:none; background:none;
      border-bottom:3px solid transparent; margin-bottom:-2px;
      text-decoration:none; display:inline-flex; align-items:center; gap:5px;
      transition:color 0.15s, border-color 0.15s;
      white-space:nowrap;
    }
    .lab-tab:hover { color:#1a5276; }
    .lab-tab.active { color:#1a3a6b; border-bottom-color:#1a3a6b; font-weight:700; }
    .lab-tab .tab-count {
      background:#e2e8f0; color:#64748b; font-size:10px; font-weight:700;
      padding:1px 6px; border-radius:10px;
    }
    .lab-tab.active .tab-count { background:#1a3a6b; color:#fff; }

    /* Lab panel header */
    .lab-panel-head {
      background:linear-gradient(135deg,#1a3a6b,#1a5276);
      color:#fff; padding:14px 20px;
      display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;
    }
    .lab-panel-head h3 { font-size:1rem; font-weight:700; margin:0; }
    .lab-panel-head .head-stats { display:flex; gap:16px; font-size:0.8rem; opacity:0.85; }

    /* Category section */
    .sw-body { padding:0; }
    .sw-empty { padding:50px; text-align:center; color:#94a3b8; }
    .sw-empty .ico { font-size:2.5rem; margin-bottom:10px; }

    .cat-section { border-bottom:1px solid #f1f5f9; }
    .cat-section:last-child { border-bottom:none; }
    .cat-head {
      background:#f8faff; padding:9px 20px;
      font-size:0.75rem; font-weight:700; color:#1a5276;
      text-transform:uppercase; letter-spacing:0.06em;
      border-bottom:1px solid #e2e8f0;
      display:flex; align-items:center; gap:6px;
    }
    .cat-count { background:#dbeafe; color:#1d4ed8; font-size:10px;
                 font-weight:700; padding:1px 7px; border-radius:10px; }

    /* Software cards grid */
    .sw-grid {
      display:grid;
      grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));
      gap:12px;
      padding:14px 16px;
    }
    .sw-card {
      border:1.5px solid #e2e8f0; border-radius:10px;
      padding:12px 14px; background:#fff;
      transition:box-shadow 0.15s, transform 0.12s;
      position:relative; overflow:hidden;
    }
    .sw-card:hover { box-shadow:0 4px 14px rgba(0,0,0,0.1); transform:translateY(-2px); }
    .sw-card.unavailable { background:#fafafa; border-color:#fecaca; opacity:0.75; }

    /* coloured left accent by category */
    .sw-card::before {
      content:''; position:absolute; left:0; top:0; bottom:0;
      width:4px; border-radius:10px 0 0 10px;
      background: var(--cat-color, #1a5276);
    }
    .sw-card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:6px; margin-bottom:6px; }
    .sw-icon { font-size:1.3rem; flex-shrink:0; }
    .sw-name { font-weight:700; font-size:0.88rem; color:#1e293b; line-height:1.3; flex:1; }
    .sw-version { font-size:0.75rem; color:#94a3b8; margin-top:2px; }

    .sw-badge {
      font-size:0.7rem; font-weight:700; padding:2px 8px;
      border-radius:10px; white-space:nowrap; flex-shrink:0;
    }
    .sw-badge.available   { background:#dcfce7; color:#166534; }
    .sw-badge.unavailable { background:#fee2e2; color:#991b1b; }

    .sw-actions { display:flex; gap:6px; margin-top:10px; }
    .sw-btn {
      flex:1; padding:5px 0; border:none; border-radius:6px;
      font-size:0.75rem; font-weight:700; cursor:pointer;
      text-align:center; text-decoration:none; display:inline-block;
      transition:opacity 0.15s;
    }
    .sw-btn:hover { opacity:0.85; }
    .sw-btn-toggle { background:#f59e0b; color:#fff; }
    .sw-btn-del    { background:#ef4444; color:#fff; }

    @media(max-width:1024px){ .main-grid{grid-template-columns:1fr;} }
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
  <h2 style="color:#1a3a6b; margin-bottom:18px; font-size:1.25rem;">💻 Lab Software Management</h2>

  <?php if ($message): ?>
    <div class="msg-<?= $message_type ?>" style="margin-bottom:16px;"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <div class="mini-stat" style="--c:#1a5276;"><div class="mn"><?= $totalSW ?></div><div class="ml">Total Listed</div></div>
    <div class="mini-stat" style="--c:#27ae60;"><div class="mn"><?= $availSW ?></div><div class="ml">Available</div></div>
    <div class="mini-stat" style="--c:#ef4444;"><div class="mn"><?= $totalSW - $availSW ?></div><div class="ml">Unavailable</div></div>
    <div class="mini-stat" style="--c:#8b5cf6;"><div class="mn"><?= $totalLabs ?></div><div class="ml">Labs with Software</div></div>
  </div>

  <div class="main-grid">

    <!-- ── LEFT: Add form + CSV ── -->
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
          <button type="submit" name="add_software" class="btn-add">➕ Add Software</button>
        </form>
      </div>

      <div class="csv-card">
        <h3>📂 Import from CSV</h3>
        <div class="csv-note">Format: <code>lab, software_name, version, category, status</code></div>
        <form method="POST" enctype="multipart/form-data">
          <div class="fg"><input type="file" name="csv_file" accept=".csv" required/></div>
          <button type="submit" name="import_csv" class="btn-import">📤 Import CSV</button>
        </form>
        <a href="data:text/csv;charset=utf-8,lab,software_name,version,category,status%0ALab%201,Visual%20Studio%20Code,1.85.0,Programming,available"
           download="software_template.csv" class="btn-dl">⬇️ Download CSV Template</a>
      </div>
    </div>

    <!-- ── RIGHT: Lab tabs + software grid ── -->
    <div class="lab-panel">

      <!-- Lab tabs -->
      <div class="lab-tabs">
        <?php foreach($labs as $lab):
          $cnt = $labCounts[$lab] ?? 0;
        ?>
          <a href="admin_software.php?tab=<?= urlencode($lab) ?>"
             class="lab-tab <?= $active_tab===$lab?'active':'' ?>">
            <?= htmlspecialchars($lab) ?>
            <span class="tab-count"><?= $cnt ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Active lab header -->
      <?php
        $lab_sw     = $all_software[$active_tab] ?? [];
        $lab_total  = $labCounts[$active_tab] ?? 0;
        $lab_avail  = 0;
        foreach($lab_sw as $cat => $items)
            foreach($items as $sw)
                if($sw['status']==='available') $lab_avail++;
      ?>
      <div class="lab-panel-head">
        <h3>🏫 Laboratory <?= htmlspecialchars($active_tab) ?></h3>
        <div class="head-stats">
          <span>📦 <?= $lab_total ?> software</span>
          <span>✅ <?= $lab_avail ?> available</span>
          <span>🚫 <?= $lab_total - $lab_avail ?> unavailable</span>
        </div>
      </div>

      <!-- Software cards -->
      <div class="sw-body">
        <?php if (empty($lab_sw)): ?>
          <div class="sw-empty">
            <div class="ico">💾</div>
            <p style="font-weight:600; color:#475569;">No software listed for <?= htmlspecialchars($active_tab) ?> yet.</p>
            <p style="font-size:0.83rem; margin-top:4px;">Use the form on the left to add software to this lab.</p>
          </div>
        <?php else:
          // Category accent colors
          $catColors = [
            'Programming' => '#3b82f6',
            'Database'    => '#8b5cf6',
            'Design'      => '#ec4899',
            'Productivity'=> '#f59e0b',
            'Utilities'   => '#14b8a6',
            'Office'      => '#f97316',
            'Internet'    => '#06b6d4',
            'Security'    => '#ef4444',
            'General'     => '#64748b',
          ];
          // Category icons
          $catIcons = [
            'Programming' => '🖥️',
            'Database'    => '🗄️',
            'Design'      => '🎨',
            'Productivity'=> '📋',
            'Utilities'   => '🔧',
            'Office'      => '📄',
            'Internet'    => '🌐',
            'Security'    => '🔒',
            'General'     => '📦',
          ];
          foreach($lab_sw as $cat => $items):
            $color = $catColors[$cat] ?? '#64748b';
            $icon  = $catIcons[$cat]  ?? '📦';
        ?>
          <div class="cat-section">
            <div class="cat-head">
              <?= $icon ?> <?= htmlspecialchars($cat) ?>
              <span class="cat-count"><?= count($items) ?></span>
            </div>
            <div class="sw-grid">
              <?php foreach($items as $sw): ?>
              <div class="sw-card <?= $sw['status']==='unavailable'?'unavailable':'' ?>"
                   style="--cat-color:<?= $color ?>">
                <div class="sw-card-top">
                  <span class="sw-icon">💾</span>
                  <div style="flex:1; min-width:0;">
                    <div class="sw-name"><?= htmlspecialchars($sw['software_name']) ?></div>
                    <?php if($sw['version']): ?>
                      <div class="sw-version">v<?= htmlspecialchars($sw['version']) ?></div>
                    <?php endif; ?>
                  </div>
                  <span class="sw-badge <?= $sw['status'] ?>">
                    <?= $sw['status']==='available'?'✓':'✗' ?>
                  </span>
                </div>
                <div class="sw-actions">
                  <a href="admin_software.php?toggle=<?= $sw['id'] ?>&lab=<?= urlencode($active_tab) ?>&tab=<?= urlencode($active_tab) ?>"
                     class="sw-btn sw-btn-toggle">
                    <?= $sw['status']==='available'?'Mark Unavailable':'Mark Available' ?>
                  </a>
                  <a href="admin_software.php?delete=<?= $sw['id'] ?>&tab=<?= urlencode($active_tab) ?>"
                     class="sw-btn sw-btn-del"
                     onclick="return confirm('Delete <?= htmlspecialchars(addslashes($sw['software_name'])) ?>?')">
                    Delete
                  </a>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

    </div><!-- /lab-panel -->
  </div><!-- /main-grid -->
</div>
</main>
</body>
</html>