<?php
session_start();
if (!isset($_SESSION['student_id'])) { header("Location: index.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['student_id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Unread notifications
$nq = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE idnumber = ? AND is_read = 0");
$nq->bind_param("s", $student['idnumber']); $nq->execute();
$unread_count = $nq->get_result()->fetch_assoc()['cnt'] ?? 0;
$nq->close();

// Ensure feedback table
$conn->query("CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idnumber VARCHAR(20),
    rating INT NOT NULL,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── Sit-in Summary Stats ──────────────────────────────────────
// Total completed sessions
$statsQ = $conn->prepare("
    SELECT
        COUNT(*) as total_sessions,
        COALESCE(SUM(TIMESTAMPDIFF(MINUTE, session_date, time_out)), 0) as total_minutes,
        COALESCE(AVG(TIMESTAMPDIFF(MINUTE, session_date, time_out)), 0) as avg_minutes,
        COALESCE(MAX(TIMESTAMPDIFF(MINUTE, session_date, time_out)), 0) as max_minutes
    FROM sit_in
    WHERE idnumber = ? AND status = 'done' AND time_out IS NOT NULL
");
$statsQ->bind_param("s", $student['idnumber']); $statsQ->execute();
$stats = $statsQ->get_result()->fetch_assoc(); $statsQ->close();

$totalSessions = (int)$stats['total_sessions'];
$totalMins     = (int)$stats['total_minutes'];
$avgMins       = round($stats['avg_minutes']);
$maxMins       = (int)$stats['max_minutes'];

function fmtMins($m) {
    if ($m <= 0) return '0m';
    $h = floor($m / 60); $rm = $m % 60;
    return $h > 0 ? "{$h}h {$rm}m" : "{$rm}m";
}
$totalHrsDisplay = fmtMins($totalMins);
$avgDisplay      = fmtMins($avgMins);
$longestDisplay  = fmtMins($maxMins);

// ── Feedback handling ─────────────────────────────────────────
$feedback_msg = ""; $feedback_type = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $rating  = intval($_POST['rating']);
    $comment = trim($_POST['comment'] ?? '');
    if ($rating < 1 || $rating > 5) {
        $feedback_msg = "Please select a rating."; $feedback_type = "error";
    } else {
        $fc = $conn->prepare("SELECT id FROM feedback WHERE idnumber = ? AND DATE(created_at) = CURDATE()");
        $fc->bind_param("s", $student['idnumber']); $fc->execute(); $fc->store_result();
        if ($fc->num_rows > 0) {
            $feedback_msg = "You have already submitted feedback today."; $feedback_type = "error";
        } else {
            $fi = $conn->prepare("INSERT INTO feedback (idnumber, rating, comment) VALUES (?, ?, ?)");
            $fi->bind_param("sis", $student['idnumber'], $rating, $comment);
            if ($fi->execute()) { $feedback_msg = "Thank you for your feedback! ⭐"; $feedback_type = "success"; }
            $fi->close();
        }
        $fc->close();
    }
}

// ── Sessions (full table with duration + PC No.) ──────────────
$hq = $conn->prepare("
    SELECT *,
        CASE
            WHEN time_out IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, session_date, time_out)
            ELSE NULL
        END AS duration_mins
    FROM sit_in WHERE idnumber = ? ORDER BY session_date DESC
");
$hq->bind_param("s", $student['idnumber']); $hq->execute();
$history = $hq->get_result(); $hq->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | History &amp; Feedback</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="style.css"/>
  <style>
    body { font-family:'Inter',sans-serif; background:#eef2f7; }
    .page-wrap { padding: 24px; max-width: 1100px; margin: 0 auto; }

    /* ── Summary cards ── */
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
      gap: 16px; margin-bottom: 28px;
    }
    .sum-card {
      background: #fff; border-radius: 12px;
      padding: 18px 20px;
      box-shadow: 0 1px 8px rgba(0,0,0,0.07);
      border-top: 4px solid var(--c, #1a5276);
      text-align: center;
    }
    .sum-card .sum-icon { font-size: 1.8rem; margin-bottom: 6px; }
    .sum-card .sum-val  { font-size: 1.7rem; font-weight: 800; color: var(--c,#1a5276); line-height: 1; }
    .sum-card .sum-lbl  { font-size: 0.78rem; color: #64748b; margin-top: 5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }

    /* ── Section headings ── */
    .section-head {
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 14px;
    }
    .section-head h3 { color: #1a3a6b; font-size: 1.05rem; font-weight: 700; }

    /* ── Sessions table ── */
    .table-card {
      background: #fff; border-radius: 12px;
      box-shadow: 0 1px 8px rgba(0,0,0,0.07);
      overflow: hidden; margin-bottom: 28px;
    }
    .table-card-head {
      background: linear-gradient(135deg,#1a3a6b,#1a5276);
      color: #fff; padding: 13px 18px;
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 8px;
    }
    .table-card-head h3 { font-size: 0.95rem; font-weight: 700; margin: 0; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
    th { background: #1a5276; color: #fff; padding: 10px 12px; text-align: left; font-weight: 600; white-space: nowrap; }
    td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #f8faff; }

    .status-done   { color: #27ae60; font-weight: 700; }
    .status-active { color: #f59e0b; font-weight: 700; }
    .duration-pill {
      background: #eef2f7; color: #1a5276;
      font-weight: 700; font-size: 0.82rem;
      padding: 2px 10px; border-radius: 10px;
      white-space: nowrap;
    }
    .empty-row { text-align: center; padding: 40px; color: #94a3b8; }

    /* ── Two-col bottom ── */
    .bottom-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

    /* ── Feedback card ── */
    .feedback-card {
      background: #fff; border-radius: 12px;
      padding: 22px 22px;
      box-shadow: 0 1px 8px rgba(0,0,0,0.07);
    }
    .feedback-card h3 { color: #1a3a6b; font-size: 1rem; font-weight: 700; margin-bottom: 14px; border-bottom: 2px solid #1a5276; padding-bottom: 8px; }
    .stars { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; margin-bottom: 14px; }
    .stars input[type="radio"] { display: none; }
    .stars label { font-size: 2rem; cursor: pointer; color: #d1d5db; transition: color 0.12s; }
    .stars input[type="radio"]:checked ~ label,
    .stars label:hover,
    .stars label:hover ~ label { color: #f59e0b; }
    .btn-feedback {
      background: #1a5276; color: #fff; border: none;
      padding: 10px 26px; border-radius: 7px; cursor: pointer;
      font-weight: 700; font-size: 0.92rem; margin-top: 10px;
      transition: background 0.2s;
    }
    .btn-feedback:hover { background: #0f3460; }

    /* ── Quick stats side card ── */
    .stats-side-card {
      background: linear-gradient(160deg, #0f2a55, #1a4fa0);
      border-radius: 12px; padding: 22px;
      color: #fff;
      box-shadow: 0 1px 8px rgba(0,0,0,0.12);
    }
    .stats-side-card h3 { font-size: 1rem; font-weight: 700; margin-bottom: 18px; opacity: 0.9; }
    .stat-line { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .stat-line:last-child { border-bottom: none; }
    .stat-line .sl-label { font-size: 0.85rem; opacity: 0.75; }
    .stat-line .sl-val   { font-size: 1rem; font-weight: 700; color: #93c5fd; }

    @media(max-width:700px) {
      .bottom-grid { grid-template-columns: 1fr; }
      .summary-grid { grid-template-columns: 1fr 1fr; }
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
  <a href="student_history.php" class="active">History &amp; Feedback</a>
  <a href="student_reservation.php">Reservation</a>
  <span class="spacer"></span>
  <a href="student_logout.php" class="logout">Log out</a>
</nav>

<main>
<div class="page-wrap">

  <h2 style="color:#1a3a6b; margin-bottom:20px; font-size:1.3rem;">📋 My Sit-in History &amp; Feedback</h2>

  <!-- ── Summary Stats ── -->
  <div class="summary-grid">
    <div class="sum-card" style="--c:#1a5276;">
      <div class="sum-icon">⏱️</div>
      <div class="sum-val"><?= $totalHrsDisplay ?></div>
      <div class="sum-lbl">Total Sit-in Hours</div>
    </div>
    <div class="sum-card" style="--c:#2563c0;">
      <div class="sum-icon">📅</div>
      <div class="sum-val"><?= $totalSessions ?></div>
      <div class="sum-lbl">Number of Sessions</div>
    </div>
    <div class="sum-card" style="--c:#f59e0b;">
      <div class="sum-icon">📊</div>
      <div class="sum-val"><?= $avgDisplay ?></div>
      <div class="sum-lbl">Average Session Duration</div>
    </div>
    <div class="sum-card" style="--c:#27ae60;">
      <div class="sum-icon">🏆</div>
      <div class="sum-val"><?= $longestDisplay ?></div>
      <div class="sum-lbl">Longest Session</div>
    </div>
  </div>

  <!-- ── Sessions Table ── -->
  <div class="table-card">
    <div class="table-card-head">
      <h3>📅 Sessions Table</h3>
      <span style="font-size:0.8rem; opacity:0.8;">All sit-in records</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Time In</th>
            <th>Time Out</th>
            <th>Duration</th>
            <th>Laboratory</th>
            <th>PC No.</th>
            <th>Purpose</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($history->num_rows > 0):
            $i = 1;
            while ($row = $history->fetch_assoc()):
              $durMins = $row['duration_mins'];
              $durStr  = ($durMins !== null) ? fmtMins((int)$durMins) : '—';
          ?>
            <tr>
              <td style="color:#94a3b8; font-size:0.8rem;"><?= $i++ ?></td>
              <td><?= date('M d, Y', strtotime($row['session_date'])) ?></td>
              <td><?= date('h:i A', strtotime($row['session_date'])) ?></td>
              <td><?= $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '<span style="color:#94a3b8;">—</span>' ?></td>
              <td>
                <?php if ($durMins !== null): ?>
                  <span class="duration-pill"><?= $durStr ?></span>
                <?php else: ?>
                  <span style="color:#f59e0b; font-weight:600; font-size:0.82rem;">● Ongoing</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($row['lab']) ?></td>
              <td style="font-weight:600;"><?= htmlspecialchars($row['pc_number'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['purpose']) ?></td>
              <td class="status-<?= $row['status'] ?>"><?= ucfirst($row['status']) ?></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="9" class="empty-row">No sit-in sessions recorded yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Bottom: Stats side + Feedback ── -->
  <div class="bottom-grid">

    <!-- Quick summary repeat -->
    <div class="stats-side-card">
      <h3>📈 Your Sit-in Summary</h3>
      <div class="stat-line">
        <span class="sl-label">Total Sit-in Hours</span>
        <span class="sl-val"><?= $totalHrsDisplay ?></span>
      </div>
      <div class="stat-line">
        <span class="sl-label">Number of Sessions</span>
        <span class="sl-val"><?= $totalSessions ?></span>
      </div>
      <div class="stat-line">
        <span class="sl-label">Average Session Duration</span>
        <span class="sl-val"><?= $avgDisplay ?></span>
      </div>
      <div class="stat-line">
        <span class="sl-label">Longest Session</span>
        <span class="sl-val"><?= $longestDisplay ?></span>
      </div>
      <div class="stat-line">
        <span class="sl-label">Remaining Sessions</span>
        <span class="sl-val" style="color:<?= $student['remaining_session'] <= 5 ? '#fca5a5' : '#86efac' ?>">
          <?= $student['remaining_session'] ?>
        </span>
      </div>
    </div>

    <!-- Feedback -->
    <div class="feedback-card">
      <h3>⭐ Leave a Feedback</h3>
      <?php if ($feedback_msg): ?>
        <div class="msg-<?= $feedback_type ?>" style="margin-bottom:12px;"><?= htmlspecialchars($feedback_msg) ?></div>
      <?php endif; ?>
      <form method="POST">
        <label style="font-weight:600; font-size:0.88rem; display:block; margin-bottom:6px;">Rate your experience:</label>
        <div class="stars">
          <?php for ($s = 5; $s >= 1; $s--): ?>
            <input type="radio" name="rating" id="star<?= $s ?>" value="<?= $s ?>" required/>
            <label for="star<?= $s ?>">★</label>
          <?php endfor; ?>
        </div>
        <label for="comment" style="font-weight:600; font-size:0.88rem; display:block; margin-bottom:4px;">Comment <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
        <textarea name="comment" id="comment" rows="3"
                  style="width:100%;padding:9px 11px;border:1.5px solid #d0daea;border-radius:7px;font-size:0.9rem;resize:vertical;font-family:inherit;"
                  placeholder="Share your experience in the lab..."></textarea>
        <button type="submit" name="submit_feedback" class="btn-feedback">Submit Feedback</button>
      </form>
    </div>

  </div>
</div>
</main>
</body>
</html>