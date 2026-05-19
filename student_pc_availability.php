<?php
session_start();
if (!isset($_SESSION['student_id'])) { header("Location: index.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['student_id']); $stmt->execute();
$student = $stmt->get_result()->fetch_assoc(); $stmt->close();

$nq = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE idnumber = ? AND is_read = 0");
$nq->bind_param("s", $student['idnumber']); $nq->execute();
$unread_count = $nq->get_result()->fetch_assoc()['cnt'] ?? 0; $nq->close();

// Labs and their PC counts
$labs = [
    '524' => 50, '526' => 50, '528' => 50,
    '530' => 50, '542' => 50, 'Mac Lab' => 20,
];

$selected_lab = isset($_GET['lab']) ? $_GET['lab'] : array_key_first($labs);
if (!array_key_exists($selected_lab, $labs)) $selected_lab = array_key_first($labs);
$pc_count = $labs[$selected_lab];

// Get active sit-ins for selected lab (with pc_number)
$sitin_pcs = [];
$sq = $conn->query("SELECT pc_number FROM sit_in WHERE lab = '$selected_lab' AND status = 'active' AND pc_number IS NOT NULL AND pc_number != ''");
if ($sq) while ($r = $sq->fetch_assoc()) $sitin_pcs[] = $r['pc_number'];

// Get approved/pending reservations for today for selected lab
$reserved_pcs = [];
$today = date('Y-m-d');
$hasPcCol = $conn->query("SHOW COLUMNS FROM reservations LIKE 'pc_number'");
if ($hasPcCol && $hasPcCol->num_rows > 0) {
    $rq = $conn->query("SELECT pc_number FROM reservations WHERE lab = '$selected_lab' AND reservation_date = '$today' AND status IN ('approved','pending') AND pc_number IS NOT NULL AND pc_number != ''");
    if ($rq) while ($r = $rq->fetch_assoc()) $reserved_pcs[] = $r['pc_number'];
}

// Get manually marked unavailable PCs (from lab_software table repurposed, or system_settings)
// We'll use a simple pc_status table if it exists, otherwise treat none as unavailable
$unavailable_pcs = [];
$hasPcStatus = $conn->query("SHOW TABLES LIKE 'pc_status'");
if ($hasPcStatus && $hasPcStatus->num_rows > 0) {
    $uq = $conn->query("SELECT pc_number FROM pc_status WHERE lab = '$selected_lab' AND status = 'unavailable'");
    if ($uq) while ($r = $uq->fetch_assoc()) $unavailable_pcs[] = $r['pc_number'];
}

// Count stats
$in_use = count($sitin_pcs) + count($reserved_pcs);
$unavail = count($unavailable_pcs);
$available = $pc_count - $in_use - $unavail;
if ($available < 0) $available = 0;

$conn->close();

// Build status map
function getPcStatus($pcLabel, $sitin, $reserved, $unavailable) {
    if (in_array($pcLabel, $unavailable)) return 'unavailable';
    if (in_array($pcLabel, $sitin))       return 'sitin';
    if (in_array($pcLabel, $reserved))    return 'reserved';
    return 'available';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | PC Availability</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="style.css"/>
  <style>
    body { font-family:'Inter',sans-serif; background:#f1f5f9; }
    .page-wrap { padding: 28px; max-width: 1280px; margin: 0 auto; }

    /* Page title */
    .page-title    { font-size: 1.5rem; font-weight: 800; color: #1e293b; margin-bottom: 4px; }
    .page-subtitle { font-size: 0.88rem; color: #64748b; margin-bottom: 24px; }

    /* ── Lab tabs ── */
    .lab-tabs {
      display: flex; gap: 8px; flex-wrap: wrap;
      margin-bottom: 22px;
    }
    .lab-tab {
      padding: 8px 20px;
      border-radius: 30px;
      border: 1.5px solid #d0daea;
      background: #fff;
      font-size: 0.88rem; font-weight: 600;
      color: #475569; cursor: pointer;
      text-decoration: none;
      transition: all 0.15s;
    }
    .lab-tab:hover  { border-color: #1a5276; color: #1a5276; }
    .lab-tab.active {
      background: #1a3a6b; color: #fff;
      border-color: #1a3a6b;
      box-shadow: 0 2px 8px rgba(26,58,107,0.3);
    }

    /* ── Legend ── */
    .legend {
      display: flex; gap: 18px; flex-wrap: wrap;
      margin-bottom: 20px; align-items: center;
    }
    .legend-item { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; font-weight: 600; color: #475569; }
    .legend-dot  { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; }
    .dot-available   { background: #bbf7d0; border: 1.5px solid #4ade80; }
    .dot-sitin       { background: #fed7aa; border: 1.5px solid #fb923c; }
    .dot-reserved    { background: #bfdbfe; border: 1.5px solid #60a5fa; }
    .dot-unavailable { background: #e9d5ff; border: 1.5px solid #c084fc; }

    /* ── Stats row ── */
    .stats-row {
      display: flex; gap: 32px; margin-bottom: 20px; flex-wrap: wrap;
    }
    .stat-item .stat-icon { font-size: 1.1rem; }
    .stat-item .stat-num  { font-size: 1.6rem; font-weight: 800; color: #1e293b; line-height: 1; margin-top: 2px; }
    .stat-item .stat-lbl  { font-size: 0.75rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

    /* ── Lab header bar ── */
    .lab-header {
      background: #1a3a6b; color: #fff;
      padding: 12px 20px;
      border-radius: 8px 8px 0 0;
      font-size: 0.88rem; font-weight: 700;
      display: flex; align-items: center; gap: 8px;
      letter-spacing: 0.04em;
    }

    /* ── PC Grid ── */
    .pc-grid-wrap {
      background: #fff;
      border-radius: 0 0 12px 12px;
      padding: 24px 20px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.07);
    }
    .pc-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
      gap: 10px;
    }

    /* ── PC Card ── */
    .pc-card {
      border-radius: 8px;
      border: 1.5px solid;
      padding: 12px 8px 10px;
      text-align: center;
      cursor: default;
      transition: transform 0.12s, box-shadow 0.12s;
      position: relative;
    }
    .pc-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.12); }

    .pc-card.available {
      background: #f0fdf4; border-color: #4ade80;
    }
    .pc-card.sitin {
      background: #fff7ed; border-color: #fb923c;
    }
    .pc-card.reserved {
      background: #eff6ff; border-color: #60a5fa;
    }
    .pc-card.unavailable {
      background: #faf5ff; border-color: #c084fc;
    }

    .pc-icon { font-size: 1.3rem; margin-bottom: 4px; display: block; }
    .pc-label { font-size: 0.75rem; font-weight: 700; color: #1e293b; display: block; }
    .pc-status-label {
      font-size: 0.6rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.04em;
      margin-top: 2px; display: block;
    }
    .available   .pc-status-label { color: #16a34a; }
    .sitin       .pc-status-label { color: #ea580c; }
    .reserved    .pc-status-label { color: #2563c0; }
    .unavailable .pc-status-label { color: #9333ea; }

    /* status icon overlay */
    .pc-status-icon {
      position: absolute; top: 5px; right: 6px;
      font-size: 0.7rem;
    }

    /* auto-refresh badge */
    .refresh-badge {
      display: inline-flex; align-items: center; gap: 5px;
      background: #f1f5f9; border: 1px solid #e2e8f0;
      border-radius: 20px; padding: 4px 12px;
      font-size: 0.76rem; color: #64748b; font-weight: 600;
      margin-bottom: 20px;
    }
    .refresh-dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; animation: blink 1.4s infinite; }
    @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }

    @media(max-width:600px){
      .pc-grid { grid-template-columns: repeat(auto-fill, minmax(72px,1fr)); gap:7px; }
      .pc-card { padding:9px 6px 8px; }
      .pc-icon { font-size:1.1rem; }
    }
  </style>
</head>
<body>
<header>
  <img src="uclogo.png" alt="UC Logo" class="logo"/>
  <h1>College of Computer Studies Sit-in Monitoring System</h1>
  <img src="ucmainccslogo.png" alt="CCS Logo" class="logo"/>
</header>
<?php include 'nav_student.php'; ?>

<main>
<div class="page-wrap">

  <div class="page-title">PC Availability</div>
  <div class="page-subtitle">Check which computers are available across all laboratories</div>

  <!-- Lab tabs -->
  <div class="lab-tabs">
    <?php foreach ($labs as $lab => $count): ?>
      <a href="student_pc_availability.php?lab=<?= urlencode($lab) ?>"
         class="lab-tab <?= $selected_lab === $lab ? 'active' : '' ?>">
        <?= htmlspecialchars($lab) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Legend -->
  <div class="legend">
    <div class="legend-item"><span class="legend-dot dot-available"></span> Available</div>
    <div class="legend-item"><span class="legend-dot dot-sitin"></span> Sit-in</div>
    <div class="legend-item"><span class="legend-dot dot-reserved"></span> Reserved</div>
    <div class="legend-item"><span class="legend-dot dot-unavailable"></span> Not Available</div>
  </div>

  <!-- Live indicator -->
  <div class="refresh-badge">
    <span class="refresh-dot"></span>
    Live · auto-refreshes every 30s
  </div>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-item">
      <div class="stat-icon">✅</div>
      <div class="stat-num"><?= $available ?></div>
      <div class="stat-lbl">Available</div>
    </div>
    <div class="stat-item">
      <div class="stat-icon">👤</div>
      <div class="stat-num"><?= $in_use ?></div>
      <div class="stat-lbl">In Use</div>
    </div>
    <div class="stat-item">
      <div class="stat-icon">🚫</div>
      <div class="stat-num"><?= $unavail ?></div>
      <div class="stat-lbl">Unavailable</div>
    </div>
    <div class="stat-item">
      <div class="stat-icon">🖥️</div>
      <div class="stat-num"><?= $pc_count ?></div>
      <div class="stat-lbl">Total Units</div>
    </div>
  </div>

  <!-- Lab header + grid -->
  <div class="lab-header">
    🖥️ LAB <?= htmlspecialchars($selected_lab) ?> — <?= $pc_count ?> UNITS
  </div>
  <div class="pc-grid-wrap">
    <div class="pc-grid">
      <?php for ($i = 1; $i <= $pc_count; $i++):
        $pcLabel = 'PC-' . str_pad($i, 2, '0', STR_PAD_LEFT);
        $status  = getPcStatus($pcLabel, $sitin_pcs, $reserved_pcs, $unavailable_pcs);
        $statusText = [
          'available'   => 'Available',
          'sitin'       => 'Sit-in',
          'reserved'    => 'Reserved',
          'unavailable' => 'Not Available',
        ][$status];
        $statusIcon = [
          'available'   => '',
          'sitin'       => '👤',
          'reserved'    => '📅',
          'unavailable' => '🚫',
        ][$status];
      ?>
      <div class="pc-card <?= $status ?>" title="<?= $pcLabel ?> — <?= $statusText ?>">
        <?php if ($statusIcon): ?>
          <span class="pc-status-icon"><?= $statusIcon ?></span>
        <?php endif; ?>
        <span class="pc-icon">🖥️</span>
        <span class="pc-label">PC <?= $i ?></span>
        <span class="pc-status-label"><?= $statusText ?></span>
      </div>
      <?php endfor; ?>
    </div>
  </div>

</div>
</main>

<script>
// Auto-refresh every 30 seconds
setTimeout(() => window.location.reload(), 30000);
</script>
</body>
</html>