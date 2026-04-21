<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// ── Date filter ───────────────────────────────────────────────
$date_from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']))
    ? $_GET['from'] : date('Y-m-01');
$date_to = (isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))
    ? $_GET['to'] : date('Y-m-d');
$df = $conn->real_escape_string($date_from);
$dt = $conn->real_escape_string($date_to);
$dWhere = "WHERE session_date BETWEEN '$df 00:00:00' AND '$dt 23:59:59'";

// ── KPI cards ─────────────────────────────────────────────────
$totalSessions = $conn->query("SELECT COUNT(*) as c FROM sit_in $dWhere")->fetch_assoc()['c'];
$activeSessions= $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE status='active'")->fetch_assoc()['c'];

$avgMinsRow = $conn->query("
    SELECT AVG(TIMESTAMPDIFF(MINUTE,session_date,time_out)) as m
    FROM sit_in $dWhere AND status='done' AND time_out IS NOT NULL
")->fetch_assoc();
$avgMins = round($avgMinsRow['m'] ?? 0);
$avgHrsDisplay = $avgMins >= 60
    ? floor($avgMins/60).'h '.($avgMins%60).'m'
    : $avgMins.'m';

$uniqueStudents = $conn->query("SELECT COUNT(DISTINCT idnumber) as c FROM sit_in $dWhere")->fetch_assoc()['c'];

// ── Most visited lab ─────────────────────────────────────────
$labResult = $conn->query("SELECT lab, COUNT(*) as cnt FROM sit_in $dWhere GROUP BY lab ORDER BY cnt DESC");
$labLabels = []; $labCounts = [];
while ($r = $labResult->fetch_assoc()) { $labLabels[] = $r['lab']; $labCounts[] = (int)$r['cnt']; }
$topLab = count($labLabels) > 0 ? $labLabels[0] : 'N/A';
$topLabCount = count($labCounts) > 0 ? $labCounts[0] : 0;

// ── Purpose breakdown ─────────────────────────────────────────
$purposeResult = $conn->query("SELECT purpose, COUNT(*) as cnt FROM sit_in $dWhere AND purpose IS NOT NULL GROUP BY purpose ORDER BY cnt DESC");
$purposeLabels = []; $purposeCounts = [];
while ($r = $purposeResult->fetch_assoc()) { $purposeLabels[] = $r['purpose']; $purposeCounts[] = (int)$r['cnt']; }

// ── Daily trend (last 30 days within range) ───────────────────
$dailyResult = $conn->query("
    SELECT DATE(session_date) as d, COUNT(*) as cnt
    FROM sit_in $dWhere
    GROUP BY DATE(session_date)
    ORDER BY d ASC
    LIMIT 60
");
$dailyLabels = []; $dailyCounts = [];
while ($r = $dailyResult->fetch_assoc()) { $dailyLabels[] = date('M d', strtotime($r['d'])); $dailyCounts[] = (int)$r['cnt']; }

// ── Peak hours (0–23) ─────────────────────────────────────────
$hourResult = $conn->query("
    SELECT HOUR(session_date) as hr, COUNT(*) as cnt
    FROM sit_in $dWhere
    GROUP BY HOUR(session_date)
    ORDER BY hr
");
$hourCounts = array_fill(0, 24, 0);
while ($r = $hourResult->fetch_assoc()) { $hourCounts[(int)$r['hr']] = (int)$r['cnt']; }
$hourLabels = [];
for ($h = 0; $h < 24; $h++) {
    $hourLabels[] = date('g A', mktime($h, 0, 0));
}

// ── Day of week ───────────────────────────────────────────────
$dowResult = $conn->query("
    SELECT DAYOFWEEK(session_date) as dow, COUNT(*) as cnt
    FROM sit_in $dWhere
    GROUP BY DAYOFWEEK(session_date)
    ORDER BY dow
");
$dowCounts = array_fill(1, 7, 0);
while ($r = $dowResult->fetch_assoc()) { $dowCounts[(int)$r['dow']] = (int)$r['cnt']; }
$dowLabels = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$dowData   = array_values($dowCounts);

// ── Course breakdown ─────────────────────────────────────────
$courseResult = $conn->query("
    SELECT u.course, COUNT(*) as cnt
    FROM sit_in s JOIN users u ON s.idnumber=u.idnumber
    $dWhere
    GROUP BY u.course ORDER BY cnt DESC LIMIT 8
");
$courseLabels = []; $courseCounts = [];
while ($r = $courseResult->fetch_assoc()) {
    // shorten label
    $courseLabels[] = preg_replace('/^BS /', '', $r['course']);
    $courseCounts[] = (int)$r['cnt'];
}

// ── Lab hours (avg session duration per lab) ─────────────────
$labHoursResult = $conn->query("
    SELECT lab,
           AVG(TIMESTAMPDIFF(MINUTE,session_date,time_out)) as avg_m,
           COUNT(*) as cnt
    FROM sit_in $dWhere AND status='done' AND time_out IS NOT NULL
    GROUP BY lab ORDER BY avg_m DESC
");
$labHoursLabels = []; $labHoursData = [];
while ($r = $labHoursResult->fetch_assoc()) {
    $labHoursLabels[] = $r['lab'];
    $labHoursData[]   = round((float)$r['avg_m'] / 60, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Analytics</title>
  <link rel="stylesheet" href="style.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .analytics-wrap { padding: 24px; }

    /* ── Filter bar ── */
    .filter-bar {
      display: flex; gap: 14px; align-items: flex-end;
      background: #fff; padding: 14px 18px;
      border-radius: 10px; margin-bottom: 22px;
      box-shadow: 0 1px 6px rgba(0,0,0,0.06);
      flex-wrap: wrap;
    }
    .filter-bar label { font-weight: 600; font-size: 0.85rem; display: block; margin-bottom: 4px; }
    .filter-bar input  { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 5px; font-size: 0.85rem; }
    .filter-bar button { padding: 8px 22px; background: #1a5276; color: #fff; border: none; border-radius: 5px; cursor: pointer; font-weight: 700; font-size: 0.85rem; align-self: flex-end; }
    .filter-bar button:hover { background: #0f3460; }

    /* ── KPI row ── */
    .kpi-row { display: flex; gap: 16px; margin-bottom: 22px; flex-wrap: wrap; }
    .kpi {
      flex: 1; min-width: 140px;
      background: #fff; border-radius: 10px;
      padding: 16px 18px;
      box-shadow: 0 1px 6px rgba(0,0,0,0.07);
      border-left: 5px solid var(--c, #1a5276);
    }
    .kpi .kpi-num { font-size: 1.9rem; font-weight: 800; color: var(--c, #1a5276); line-height: 1; }
    .kpi .kpi-lbl { font-size: 0.78rem; color: #64748b; margin-top: 5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
    .kpi .kpi-sub { font-size: 0.75rem; color: #94a3b8; margin-top: 3px; }

    /* ── Top Lab highlight ── */
    .top-lab-banner {
      background: linear-gradient(135deg, #1a3a6b, #2563c0);
      color: #fff; border-radius: 10px;
      padding: 16px 22px; margin-bottom: 22px;
      display: flex; align-items: center; gap: 16px;
      flex-wrap: wrap;
    }
    .top-lab-banner .tl-icon { font-size: 2.5rem; }
    .top-lab-banner .tl-title { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.7; }
    .top-lab-banner .tl-name  { font-size: 1.6rem; font-weight: 800; }
    .top-lab-banner .tl-count { font-size: 0.9rem; opacity: 0.85; }
    .top-lab-banner .tl-right { margin-left: auto; }
    .lab-rank-list { display: flex; flex-direction: column; gap: 4px; }
    .lab-rank-item { display: flex; align-items: center; gap: 8px; font-size: 0.82rem; }
    .lab-rank-bar  { flex: 1; height: 6px; background: rgba(255,255,255,0.2); border-radius: 3px; overflow: hidden; }
    .lab-rank-fill { height: 100%; background: rgba(255,255,255,0.75); border-radius: 3px; }

    /* ── Chart grid ── */
    .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 22px; }
    .chart-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 22px; }
    .chart-card {
      background: #fff; border-radius: 10px;
      padding: 18px 20px;
      box-shadow: 0 1px 6px rgba(0,0,0,0.07);
    }
    .chart-card.full { grid-column: 1 / -1; }
    .chart-card h3 {
      font-size: 0.92rem; font-weight: 700;
      color: #1a3a6b; margin-bottom: 14px;
      display: flex; align-items: center; gap: 7px;
    }
    .chart-card canvas { max-height: 240px; }
    .chart-card.tall canvas { max-height: 320px; }

    @media(max-width: 900px) {
      .chart-grid   { grid-template-columns: 1fr; }
      .chart-grid-3 { grid-template-columns: 1fr; }
    }
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
  <a href="admin_reports.php">Reports</a>
  <a href="admin_feedback.php">Feedback</a>
  <a href="admin_reservation.php">Reservation</a>
  <a href="admin_leaderboard.php">Leaderboard</a>
  <a href="admin_analytics.php" class="active">Analytics</a>
  <a href="admin_logout.php" class="logout-btn">Log out</a>
</nav>

<main>
<div class="analytics-wrap">
  <h2 style="color:#1a3a6b; margin-bottom:18px; font-size:1.35rem;">📈 Analytics Dashboard</h2>

  <!-- ── Date filter ── -->
  <form method="GET" class="filter-bar">
    <div>
      <label>From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>"/>
    </div>
    <div>
      <label>To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>"/>
    </div>
    <button type="submit">Apply</button>
    <a href="admin_analytics.php" style="align-self:flex-end; padding:8px 14px; background:#f1f5f9; color:#475569; border-radius:5px; text-decoration:none; font-weight:600; font-size:0.85rem;">Reset</a>
  </form>

  <!-- ── KPI row ── -->
  <div class="kpi-row">
    <div class="kpi" style="--c:#1a5276;">
      <div class="kpi-num"><?= $totalSessions ?></div>
      <div class="kpi-lbl">Total Sessions</div>
      <div class="kpi-sub"><?= date('M d', strtotime($date_from)) ?> – <?= date('M d, Y', strtotime($date_to)) ?></div>
    </div>
    <div class="kpi" style="--c:#27ae60;">
      <div class="kpi-num"><?= $activeSessions ?></div>
      <div class="kpi-lbl">Active Right Now</div>
    </div>
    <div class="kpi" style="--c:#f59e0b;">
      <div class="kpi-num"><?= $avgHrsDisplay ?></div>
      <div class="kpi-lbl">Avg Session Duration</div>
    </div>
    <div class="kpi" style="--c:#8b5cf6;">
      <div class="kpi-num"><?= $uniqueStudents ?></div>
      <div class="kpi-lbl">Unique Students</div>
    </div>
  </div>

  <!-- ── Most Visited Lab Banner ── -->
  <?php if ($topLab !== 'N/A'): ?>
  <div class="top-lab-banner">
    <div class="tl-icon">🏫</div>
    <div>
      <div class="tl-title">Most Visited Laboratory</div>
      <div class="tl-name"><?= htmlspecialchars($topLab) ?></div>
      <div class="tl-count"><?= $topLabCount ?> sit-in sessions</div>
    </div>
    <div class="tl-right">
      <div class="lab-rank-list">
        <?php
        $maxLab = $labCounts[0] ?? 1;
        $show = array_slice(array_keys($labLabels), 0, min(5, count($labLabels)));
        foreach ($show as $i):
          $pct = $maxLab > 0 ? round(($labCounts[$i]/$maxLab)*100) : 0;
        ?>
        <div class="lab-rank-item">
          <span style="min-width:50px; font-weight:600;"><?= htmlspecialchars($labLabels[$i]) ?></span>
          <div class="lab-rank-bar"><div class="lab-rank-fill" style="width:<?= $pct ?>%"></div></div>
          <span><?= $labCounts[$i] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Row 1: Daily trend + Peak hours ── -->
  <div class="chart-grid">
    <div class="chart-card tall">
      <h3>📅 Daily Sit-in Trend</h3>
      <canvas id="dailyChart"></canvas>
    </div>
    <div class="chart-card tall">
      <h3>🕐 Peak Hours (by time of day)</h3>
      <canvas id="hourChart"></canvas>
    </div>
  </div>

  <!-- ── Row 2: Lab usage + Purpose ── -->
  <div class="chart-grid">
    <div class="chart-card">
      <h3>🏫 Lab Visit Count</h3>
      <canvas id="labChart"></canvas>
    </div>
    <div class="chart-card">
      <h3>🎯 Purpose Breakdown</h3>
      <canvas id="purposeChart"></canvas>
    </div>
  </div>

  <!-- ── Row 3: Day of week + Course + Avg hours per lab ── -->
  <div class="chart-grid-3">
    <div class="chart-card">
      <h3>📆 Busiest Days</h3>
      <canvas id="dowChart"></canvas>
    </div>
    <div class="chart-card">
      <h3>🎓 Sessions by Course</h3>
      <canvas id="courseChart"></canvas>
    </div>
    <div class="chart-card">
      <h3>⏱️ Avg Hours per Lab</h3>
      <canvas id="labHoursChart"></canvas>
    </div>
  </div>

</div>
</main>

<script>
const COLORS = ['#1a5276','#2563c0','#3b82f6','#60a5fa','#93c5fd','#1e8449','#27ae60','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f97316'];

function makeChart(id, type, labels, data, opts={}) {
  const ctx = document.getElementById(id);
  if (!ctx) return;
  new Chart(ctx, {
    type,
    data: {
      labels: labels.length ? labels : ['No Data'],
      datasets:[{
        data: data.length ? data : [1],
        backgroundColor: type==='line' ? 'rgba(37,99,192,0.12)' : COLORS,
        borderColor:     type==='line' ? '#2563c0' : COLORS,
        borderWidth:     type==='line' ? 2 : 1,
        fill:            type==='line',
        tension:         0.4,
        pointRadius:     type==='line' ? 3 : undefined,
        ...opts.dataset
      }]
    },
    options: {
      responsive:true,
      plugins:{
        legend:{ display: type==='pie'||type==='doughnut', position:'bottom', labels:{font:{size:11}} },
        ...opts.plugins
      },
      scales: (type==='bar'||type==='line') ? {
        y:{ beginAtZero:true, ticks:{font:{size:11}}, grid:{color:'#f1f5f9'} },
        x:{ ticks:{font:{size:10}, maxRotation:45}, grid:{display:false} }
      } : {},
      ...opts.chart
    }
  });
}

// Daily trend
makeChart('dailyChart','line',
  <?= json_encode($dailyLabels) ?>,
  <?= json_encode($dailyCounts) ?>
);

// Peak hours — bar
makeChart('hourChart','bar',
  <?= json_encode($hourLabels) ?>,
  <?= json_encode($hourCounts) ?>,
  { dataset:{ backgroundColor: Array(24).fill(0).map((_,i)=>{
      const v = <?= json_encode($hourCounts) ?>[i];
      const max = Math.max(...<?= json_encode($hourCounts) ?>);
      const ratio = max>0 ? v/max : 0;
      return `rgba(37,99,${Math.round(150+106*ratio)},${0.4+0.6*ratio})`;
    })
  }}
);

// Lab visits
makeChart('labChart','bar',
  <?= json_encode($labLabels) ?>,
  <?= json_encode($labCounts) ?>
);

// Purpose doughnut
makeChart('purposeChart','doughnut',
  <?= json_encode($purposeLabels) ?>,
  <?= json_encode($purposeCounts) ?>
);

// Day of week
makeChart('dowChart','bar',
  <?= json_encode($dowLabels) ?>,
  <?= json_encode($dowData) ?>
);

// Course
makeChart('courseChart','bar',
  <?= json_encode($courseLabels) ?>,
  <?= json_encode($courseCounts) ?>
);

// Avg hours per lab
makeChart('labHoursChart','bar',
  <?= json_encode($labHoursLabels) ?>,
  <?= json_encode($labHoursData) ?>,
  { dataset:{ backgroundColor:'#1e8449' } }
);
</script>
</body>
</html>