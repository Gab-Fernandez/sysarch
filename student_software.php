<?php
session_start();
if (!isset($_SESSION['student_id'])) { header("Location: index.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Ensure software table exists
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

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['student_id']); $stmt->execute();
$student = $stmt->get_result()->fetch_assoc(); $stmt->close();

$nq = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE idnumber=? AND is_read=0");
$nq->bind_param("s",$student['idnumber']); $nq->execute();
$unread_count = $nq->get_result()->fetch_assoc()['cnt'] ?? 0; $nq->close();

// Get all labs that have software entries
$labs_with_sw = $conn->query("SELECT DISTINCT lab FROM lab_software ORDER BY lab");
$labs = []; while($r=$labs_with_sw->fetch_assoc()) $labs[]=$r['lab'];

// Get selected lab (default to first)
$selected_lab = isset($_GET['lab']) ? $conn->real_escape_string($_GET['lab']) : ($labs[0] ?? '');

// Get software for selected lab
$software = [];
if ($selected_lab) {
    $sq = $conn->query("SELECT * FROM lab_software WHERE lab='$selected_lab' ORDER BY category, software_name");
    while($r=$sq->fetch_assoc()) $software[]=$r;
}

// Stats
$totalSoftware = $conn->query("SELECT COUNT(*) as c FROM lab_software WHERE status='available'")->fetch_assoc()['c'];
$totalLabs     = $conn->query("SELECT COUNT(DISTINCT lab) as c FROM lab_software")->fetch_assoc()['c'];

// Pre-fetch software counts per lab (avoids opening a new DB connection per lab in the loop)
$labCountsRaw = $conn->query("SELECT lab, COUNT(*) as c FROM lab_software WHERE status='available' GROUP BY lab");
$labCounts = [];
while ($r = $labCountsRaw->fetch_assoc()) { $labCounts[$r['lab']] = (int)$r['c']; }

$conn->close();

// Group software by category
$grouped = [];
foreach($software as $sw) {
    $grouped[$sw['category']][] = $sw;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Software Availability</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="style.css"/>
  <style>
    body { font-family:'Inter',sans-serif; background:#eef2f7; }
    .page-wrap { padding: 24px; max-width: 1100px; margin: 0 auto; }

    /* KPI row */
    .kpi-row { display:flex; gap:16px; margin-bottom:24px; flex-wrap:wrap; }
    .kpi-card { flex:1; min-width:130px; background:#fff; border-radius:10px; padding:16px 18px; box-shadow:0 1px 6px rgba(0,0,0,0.07); border-left:5px solid var(--c,#1a5276); text-align:center; }
    .kpi-card .num { font-size:1.8rem; font-weight:800; color:var(--c,#1a5276); }
    .kpi-card .lbl { font-size:0.78rem; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; margin-top:4px; }

    /* Layout */
    .sw-layout { display:grid; grid-template-columns:200px 1fr; gap:20px; }

    /* Lab sidebar */
    .lab-sidebar { display:flex; flex-direction:column; gap:6px; }
    .lab-sidebar-title { font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:4px; }
    .lab-btn {
      display:block; padding:10px 14px;
      background:#fff; border-radius:8px;
      text-decoration:none; font-weight:600; font-size:0.9rem;
      color:#475569; border:1.5px solid #e2e8f0;
      transition:all 0.15s;
    }
    .lab-btn:hover { background:#eef2f7; border-color:#cbd5e1; color:#1a3a6b; }
    .lab-btn.active { background:linear-gradient(135deg,#1a3a6b,#1a5276); color:#fff; border-color:transparent; }
    .lab-btn .lab-count { font-size:0.75rem; opacity:0.7; margin-top:2px; display:block; }

    /* Software panel */
    .sw-panel {}
    .sw-panel-head {
      background:linear-gradient(135deg,#1a3a6b,#1a5276);
      color:#fff; border-radius:10px 10px 0 0;
      padding:14px 20px; display:flex; align-items:center; justify-content:space-between;
    }
    .sw-panel-head h3 { margin:0; font-size:1rem; font-weight:700; }
    .sw-panel-body { background:#fff; border-radius:0 0 10px 10px; overflow:hidden; box-shadow:0 2px 10px rgba(0,0,0,0.07); }

    /* Category group */
    .sw-category { border-bottom:1px solid #f1f5f9; }
    .sw-category:last-child { border-bottom:none; }
    .sw-cat-head {
      background:#f8faff; padding:10px 20px;
      font-size:0.78rem; font-weight:700;
      color:#1a5276; text-transform:uppercase; letter-spacing:0.06em;
      border-bottom:1px solid #e2e8f0;
    }
    .sw-row {
      display:grid; grid-template-columns:1fr auto auto;
      align-items:center; gap:12px;
      padding:11px 20px; border-bottom:1px solid #f1f5f9;
      transition:background 0.12s;
    }
    .sw-row:last-child { border-bottom:none; }
    .sw-row:hover { background:#f8faff; }
    .sw-name    { font-weight:600; font-size:0.9rem; color:#1e293b; }
    .sw-version { font-size:0.8rem; color:#94a3b8; margin-top:2px; }
    .sw-badge {
      font-size:0.75rem; font-weight:700;
      padding:3px 10px; border-radius:20px;
      white-space:nowrap;
    }
    .sw-badge.available   { background:#dcfce7; color:#166534; }
    .sw-badge.unavailable { background:#fee2e2; color:#991b1b; }

    .no-sw { padding:50px; text-align:center; color:#94a3b8; }
    .no-lab-selected { padding:50px; text-align:center; color:#94a3b8; background:#fff; border-radius:10px; }

    @media(max-width:700px) {
      .sw-layout { grid-template-columns:1fr; }
      .lab-sidebar { flex-direction:row; flex-wrap:wrap; }
      .lab-btn { flex:1; min-width:100px; text-align:center; }
    }
  </style>
</head>
<body>
<header>
  <img src="uclogo.png" alt="UC Logo" class="logo"/>
  <h1>College of Computer Studies Sit-in Monitoring System</h1>
  <img src="ucmainccslogo.png" alt="CCS Logo" class="logo"/>
</header>
<nav class="top-nav">
  <div class="notif-wrap">
    <a href="student_notifications.php">🔔 Notification</a>
    <?php if ($unread_count > 0): ?><span class="notif-badge"><?= $unread_count ?></span><?php endif; ?>
  </div>
  <a href="student_dashboard.php">Home</a>
  <a href="student_edit_profile.php">Edit Profile</a>
  <a href="student_history.php">History &amp; Feedback</a>
  <a href="student_software.php" class="active">Software</a>
  <a href="student_reservation.php">Reservation</a>
  <span class="spacer"></span>
  <a href="student_logout.php" class="logout">Log out</a>
</nav>

<main>
<div class="page-wrap">
  <h2 style="color:#1a3a6b; margin-bottom:18px; font-size:1.3rem;">💻 Software Availability / Lab</h2>

  <!-- KPIs -->
  <div class="kpi-row">
    <div class="kpi-card" style="--c:#1a5276;">
      <div class="num"><?= $totalLabs ?></div>
      <div class="lbl">Labs Listed</div>
    </div>
    <div class="kpi-card" style="--c:#27ae60;">
      <div class="num"><?= $totalSoftware ?></div>
      <div class="lbl">Available Software</div>
    </div>
  </div>

  <?php if (empty($labs)): ?>
    <div class="no-lab-selected">
      <div style="font-size:3rem;margin-bottom:12px;">🖥️</div>
      <p style="font-weight:600;color:#475569;">No software listed yet.</p>
      <p style="font-size:0.85rem;margin-top:6px;">The admin hasn't uploaded any software information yet.</p>
    </div>
  <?php else: ?>
  <div class="sw-layout">
    <!-- Lab list sidebar -->
    <div class="lab-sidebar">
      <div class="lab-sidebar-title">Select a Lab</div>
      <?php foreach($labs as $lab):
        $cnt_val = $labCounts[$lab] ?? 0;
      ?>
        <a href="student_software.php?lab=<?= urlencode($lab) ?>"
           class="lab-btn <?= $selected_lab===$lab?'active':'' ?>">
          🏫 <?= htmlspecialchars($lab) ?>
          <span class="lab-count"><?= $cnt_val ?> software</span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Software panel -->
    <div class="sw-panel">
      <?php if ($selected_lab): ?>
        <div class="sw-panel-head">
          <h3>🏫 <?= htmlspecialchars($selected_lab) ?></h3>
          <span style="font-size:0.82rem;opacity:0.8;"><?= count($software) ?> software listed</span>
        </div>
        <div class="sw-panel-body">
          <?php if (empty($grouped)): ?>
            <div class="no-sw">No software listed for this lab yet.</div>
          <?php else:
            foreach($grouped as $cat => $items): ?>
            <div class="sw-category">
              <div class="sw-cat-head">📁 <?= htmlspecialchars($cat) ?></div>
              <?php foreach($items as $sw): ?>
              <div class="sw-row">
                <div>
                  <div class="sw-name">💾 <?= htmlspecialchars($sw['software_name']) ?></div>
                  <?php if($sw['version']): ?>
                    <div class="sw-version">v<?= htmlspecialchars($sw['version']) ?></div>
                  <?php endif; ?>
                </div>
                <span class="sw-badge <?= $sw['status'] ?>">
                  <?= $sw['status']==='available' ? '✓ Available' : '✗ Unavailable' ?>
                </span>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      <?php else: ?>
        <div class="no-lab-selected">Select a lab from the left to view its software.</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
</main>
</body>
</html>