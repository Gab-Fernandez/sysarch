<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// ── Leaderboard: total sit-in hours per student ───────────────
// Uses TIMESTAMPDIFF on session_date vs time_out; excludes active/no-timeout rows
$leaderboard = $conn->query("
    SELECT
        u.idnumber,
        u.firstname,
        u.lastname,
        u.course,
        u.courselevel,
        COUNT(s.sit_id)                                               AS total_sessions,
        COALESCE(SUM(
            TIMESTAMPDIFF(MINUTE, s.session_date, s.time_out)
        ), 0)                                                         AS total_minutes,
        COALESCE(SUM(
            TIMESTAMPDIFF(MINUTE, s.session_date, s.time_out)
        ) / 60.0, 0)                                                  AS total_hours,
        MAX(s.session_date)                                           AS last_seen
    FROM users u
    LEFT JOIN sit_in s
        ON s.idnumber = u.idnumber
        AND s.status  = 'done'
        AND s.time_out IS NOT NULL
    GROUP BY u.idnumber, u.firstname, u.lastname, u.course, u.courselevel
    HAVING total_minutes > 0
    ORDER BY total_minutes DESC
    LIMIT 50
");

// ── Top stats ─────────────────────────────────────────────────
$totalStudentsRow = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc();
$totalStudents    = $totalStudentsRow['c'];

$totalHoursRow = $conn->query("
    SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, session_date, time_out)),0) / 60.0 AS h
    FROM sit_in WHERE status='done' AND time_out IS NOT NULL
")->fetch_assoc();
$totalHours = round($totalHoursRow['h'], 1);

$avgHoursRow = $conn->query("
    SELECT COALESCE(AVG(sub.m),0)/60.0 AS h FROM (
        SELECT SUM(TIMESTAMPDIFF(MINUTE,session_date,time_out)) AS m
        FROM sit_in WHERE status='done' AND time_out IS NOT NULL
        GROUP BY idnumber
    ) sub
")->fetch_assoc();
$avgHours = round($avgHoursRow['h'], 1);

// Collect rows into array so we can get max for progress bar
$rows = [];
while ($row = $leaderboard->fetch_assoc()) { $rows[] = $row; }
$maxMinutes = count($rows) > 0 ? (float)$rows[0]['total_minutes'] : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Leaderboard</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .lb-wrap { padding: 24px; max-width: 1100px; margin: 0 auto; }

    /* ── Stat cards ── */
    .lb-stats { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
    .lb-stat {
      flex: 1; min-width: 140px;
      background: #fff;
      border-radius: 10px;
      padding: 18px 20px;
      box-shadow: 0 1px 6px rgba(0,0,0,0.07);
      border-top: 4px solid var(--c, #1a5276);
      text-align: center;
    }
    .lb-stat .num   { font-size: 2rem; font-weight: 800; color: var(--c, #1a5276); line-height: 1; }
    .lb-stat .lbl   { font-size: 0.82rem; color: #64748b; margin-top: 5px; font-weight: 600; }

    /* ── Top 3 podium ── */
    .podium { display: flex; justify-content: center; align-items: flex-end; gap: 16px; margin-bottom: 32px; flex-wrap: wrap; }
    .podium-card {
      background: #fff;
      border-radius: 14px;
      padding: 20px 18px 16px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(0,0,0,0.10);
      position: relative;
      min-width: 160px;
    }
    .podium-card.rank-1 { border-top: 5px solid #f59e0b; order: 2; min-height: 210px; }
    .podium-card.rank-2 { border-top: 5px solid #94a3b8; order: 1; min-height: 185px; }
    .podium-card.rank-3 { border-top: 5px solid #cd7c2f; order: 3; min-height: 170px; }

    .medal { font-size: 2rem; display: block; margin-bottom: 6px; }
    .podium-name  { font-weight: 700; font-size: 14px; color: #1e293b; margin-bottom: 3px; }
    .podium-course{ font-size: 11px; color: #64748b; margin-bottom: 10px; }
    .podium-hours {
      font-size: 1.5rem; font-weight: 800;
      background: linear-gradient(135deg,#1a5276,#2563c0);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    }
    .podium-sessions { font-size: 11px; color: #94a3b8; margin-top: 3px; }

    /* ── Table ── */
    .lb-table-wrap { background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 1px 8px rgba(0,0,0,0.07); }
    .lb-table-head {
      background: linear-gradient(135deg,#1a3a6b,#1a5276);
      color:#fff; padding:12px 20px;
      display:flex; align-items:center; justify-content:space-between;
    }
    .lb-table-head h3 { font-size:1rem; font-weight:700; margin:0; }

    table { width:100%; border-collapse:collapse; }
    th { background:#1a5276; color:#fff; padding:10px 14px; text-align:left; font-size:0.85rem; font-weight:600; white-space:nowrap; }
    td { padding:11px 14px; border-bottom:1px solid #f1f5f9; font-size:0.88rem; vertical-align:middle; }
    tr:last-child td { border-bottom:none; }
    tr:hover td { background:#f8faff; }

    .rank-cell { font-weight:800; font-size:1rem; color:#1a5276; width:40px; text-align:center; }
    .rank-medal { font-size:1.2rem; }

    .hours-bar-wrap { display:flex; align-items:center; gap:10px; }
    .hours-bar-bg {
      flex:1; height:8px; background:#e2e8f0;
      border-radius:4px; overflow:hidden;
    }
    .hours-bar-fill {
      height:100%; border-radius:4px;
      background:linear-gradient(90deg,#1a5276,#3b82f6);
      transition:width 0.6s ease;
    }
    .hours-val { font-weight:700; font-size:0.9rem; color:#1a5276; white-space:nowrap; min-width:58px; }

    .course-tag {
      background:#eef2f7; color:#1a5276;
      font-size:10.5px; font-weight:600;
      padding:2px 9px; border-radius:10px;
      display:inline-block;
    }
    .sessions-num { font-weight:700; color:#475569; }

    .empty-lb { text-align:center; padding:60px; color:#94a3b8; }
    .empty-lb .ico { font-size:3rem; margin-bottom:12px; }

    /* filter bar */
    .lb-filter { display:flex; gap:12px; align-items:center; padding:12px 20px; background:#f8faff; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; }
    .lb-filter label { font-weight:600; font-size:0.85rem; }
    .lb-filter select, .lb-filter input { padding:6px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:0.85rem; }
    .lb-filter button { padding:6px 18px; background:#1a5276; color:#fff; border:none; border-radius:5px; cursor:pointer; font-weight:600; font-size:0.85rem; }
    .lb-filter button:hover { background:#0f3460; }
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
  <a href="admin_leaderboard.php" class="active">Leaderboard</a>
  <a href="admin_analytics.php">Analytics</a>
  <a href="admin_logout.php" class="logout-btn">Log out</a>
</nav>

<main>
<div class="lb-wrap">
  <h2 style="color:#1a3a6b; margin-bottom:20px; font-size:1.4rem;">🏆 Student Sit-in Leaderboard</h2>

  <!-- ── Stat cards ── -->
  <div class="lb-stats">
    <div class="lb-stat" style="--c:#1a5276;">
      <div class="num"><?= $totalStudents ?></div>
      <div class="lbl">Registered Students</div>
    </div>
    <div class="lb-stat" style="--c:#f59e0b;">
      <div class="num"><?= number_format($totalHours, 1) ?></div>
      <div class="lbl">Total Lab Hours</div>
    </div>
    <div class="lb-stat" style="--c:#27ae60;">
      <div class="num"><?= number_format($avgHours, 1) ?></div>
      <div class="lbl">Avg Hours / Student</div>
    </div>
    <div class="lb-stat" style="--c:#8b5cf6;">
      <div class="num"><?= count($rows) ?></div>
      <div class="lbl">Students on Board</div>
    </div>
  </div>

  <!-- ── Top 3 Podium ── -->
  <?php if (count($rows) >= 1): ?>
  <div class="podium">
    <?php
    $medals = ['🥇','🥈','🥉'];
    $show   = array_slice($rows, 0, 3);
    foreach ($show as $i => $r):
      $hrs  = number_format($r['total_hours'], 2);
      $name = htmlspecialchars($r['firstname'].' '.$r['lastname']);
    ?>
    <div class="podium-card rank-<?= $i+1 ?>">
      <span class="medal"><?= $medals[$i] ?></span>
      <div class="podium-name"><?= $name ?></div>
      <div class="podium-course"><?= htmlspecialchars($r['course']) ?></div>
      <div class="podium-hours"><?= $hrs ?> hrs</div>
      <div class="podium-sessions"><?= $r['total_sessions'] ?> session<?= $r['total_sessions']!=1?'s':'' ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Full Table ── -->
  <div class="lb-table-wrap">
    <div class="lb-table-head">
      <h3>📋 Full Rankings — Total Sit-in Hours</h3>
      <span style="font-size:0.82rem; opacity:0.8;">Top 50 students · completed sessions only</span>
    </div>

    <?php if (count($rows) > 0): ?>
    <table>
      <thead>
        <tr>
          <th style="width:50px;">Rank</th>
          <th>Student</th>
          <th>Course</th>
          <th>Year</th>
          <th>Sessions</th>
          <th style="min-width:200px;">Total Hours</th>
          <th>Last Seen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r):
          $pct  = $maxMinutes > 0 ? ($r['total_minutes'] / $maxMinutes) * 100 : 0;
          $hrs  = number_format($r['total_hours'], 2);
          $rank = $i + 1;
          $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : $rank));
        ?>
        <tr>
          <td class="rank-cell">
            <?php if ($rank <= 3): ?>
              <span class="rank-medal"><?= $medal ?></span>
            <?php else: ?>
              #<?= $rank ?>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-weight:700; font-size:0.9rem;"><?= htmlspecialchars($r['firstname'].' '.$r['lastname']) ?></div>
            <div style="font-size:11px; color:#94a3b8;"><?= htmlspecialchars($r['idnumber']) ?></div>
          </td>
          <td><span class="course-tag"><?= htmlspecialchars($r['course']) ?></span></td>
          <td style="text-align:center;">Yr <?= $r['courselevel'] ?></td>
          <td class="sessions-num" style="text-align:center;"><?= $r['total_sessions'] ?></td>
          <td>
            <div class="hours-bar-wrap">
              <div class="hours-bar-bg">
                <div class="hours-bar-fill" style="width:<?= round($pct) ?>%"></div>
              </div>
              <span class="hours-val"><?= $hrs ?> hrs</span>
            </div>
          </td>
          <td style="color:#64748b; font-size:0.83rem;"><?= date('M d, Y', strtotime($r['last_seen'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <div class="empty-lb">
        <div class="ico">🏆</div>
        <p>No completed sit-in sessions recorded yet.</p>
        <p style="font-size:0.85rem; margin-top:6px; color:#b0bec5;">Hours are calculated from sessions with a logged-out time.</p>
      </div>
    <?php endif; ?>
  </div>

</div>
</main>
</body>
</html>