<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$message = ""; $message_type = "";

// ── Approve ───────────────────────────────────────────────────
if (isset($_GET['approve'])) {
    $res_id = intval($_GET['approve']);
    $conn->query("UPDATE reservations SET status='approved' WHERE res_id=$res_id");
    // Send notification to student
    $res = $conn->query("SELECT idnumber, lab, reservation_date FROM reservations WHERE res_id=$res_id");
    if ($res && $r = $res->fetch_assoc()) {
        $msg = $conn->real_escape_string("Your reservation for Lab {$r['lab']} on ".date('M d, Y', strtotime($r['reservation_date']))." has been APPROVED.");
        $conn->query("INSERT INTO notifications (idnumber, message) VALUES ('{$r['idnumber']}', '$msg')");
    }
    $message = "Reservation approved."; $message_type = "success";
}

// ── Reject ────────────────────────────────────────────────────
if (isset($_GET['reject'])) {
    $res_id = intval($_GET['reject']);
    $conn->query("UPDATE reservations SET status='rejected' WHERE res_id=$res_id");
    $res = $conn->query("SELECT idnumber, lab, reservation_date FROM reservations WHERE res_id=$res_id");
    if ($res && $r = $res->fetch_assoc()) {
        $msg = $conn->real_escape_string("Your reservation for Lab {$r['lab']} on ".date('M d, Y', strtotime($r['reservation_date']))." has been REJECTED.");
        $conn->query("INSERT INTO notifications (idnumber, message) VALUES ('{$r['idnumber']}', '$msg')");
    }
    $message = "Reservation rejected."; $message_type = "error";
}

// ── Start Sit-in from approved reservation ────────────────────
if (isset($_GET['start_sitin'])) {
    $res_id = intval($_GET['start_sitin']);
    $res_stmt = $conn->prepare("SELECT r.*, u.firstname, u.lastname, u.remaining_session
                                FROM reservations r
                                LEFT JOIN users u ON r.idnumber = u.idnumber
                                WHERE r.res_id = ?");
    $res_stmt->bind_param("i", $res_id);
    $res_stmt->execute();
    $reservation = $res_stmt->get_result()->fetch_assoc();
    $res_stmt->close();

    if ($reservation) {
        if ($reservation['remaining_session'] <= 0) {
            $message = "Student has no remaining sessions."; $message_type = "error";
        } else {
            $chk = $conn->prepare("SELECT sit_id FROM sit_in WHERE idnumber = ? AND status = 'active'");
            $chk->bind_param("s", $reservation['idnumber']);
            $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) {
                $message = "Student already has an active sit-in session."; $message_type = "error";
            } else {
                $now = date('Y-m-d H:i:s');
                // Check if pc_number column exists
                $hasPc = $conn->query("SHOW COLUMNS FROM sit_in LIKE 'pc_number'");
                if ($hasPc && $hasPc->num_rows > 0) {
                    $pc = $reservation['pc_number'] ?? '';
                    $ins = $conn->prepare("INSERT INTO sit_in (idnumber, lab, purpose, session_date, status, pc_number) VALUES (?, ?, ?, ?, 'active', ?)");
                    $ins->bind_param("sssss", $reservation['idnumber'], $reservation['lab'], $reservation['purpose'], $now, $pc);
                } else {
                    $ins = $conn->prepare("INSERT INTO sit_in (idnumber, lab, purpose, session_date, status) VALUES (?, ?, ?, ?, 'active')");
                    $ins->bind_param("ssss", $reservation['idnumber'], $reservation['lab'], $reservation['purpose'], $now);
                }
                if ($ins->execute()) {
                    $new_sess = $reservation['remaining_session'] - 1;
                    $upd = $conn->prepare("UPDATE users SET remaining_session = ? WHERE idnumber = ?");
                    $upd->bind_param("is", $new_sess, $reservation['idnumber']);
                    $upd->execute(); $upd->close();
                    $message = "Sit-in started for ".htmlspecialchars($reservation['firstname'].' '.$reservation['lastname']).".";
                    $message_type = "success";
                } else {
                    $message = "Failed to start sit-in."; $message_type = "error";
                }
                $ins->close();
            }
            $chk->close();
        }
    }
}

// ── Fetch reservations ────────────────────────────────────────
$pending = $conn->query("
    SELECT r.*, u.firstname, u.lastname, u.course
    FROM reservations r
    LEFT JOIN users u ON r.idnumber = u.idnumber
    WHERE r.status = 'pending'
    ORDER BY r.reservation_date ASC, r.start_time ASC
");

$approved = $conn->query("
    SELECT r.*, u.firstname, u.lastname, u.course, u.remaining_session
    FROM reservations r
    LEFT JOIN users u ON r.idnumber = u.idnumber
    WHERE r.status = 'approved'
    ORDER BY r.reservation_date ASC, r.start_time ASC
");

$rejected = $conn->query("
    SELECT r.*, u.firstname, u.lastname
    FROM reservations r
    LEFT JOIN users u ON r.idnumber = u.idnumber
    WHERE r.status = 'rejected'
    ORDER BY r.created_at DESC
    LIMIT 20
");

// Stats
$totalPending  = $conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='pending'")->fetch_assoc()['c'];
$totalApproved = $conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='approved'")->fetch_assoc()['c'];
$totalRejected = $conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='rejected'")->fetch_assoc()['c'];

// Check pc_number col
$hasPcCol = $conn->query("SHOW COLUMNS FROM reservations LIKE 'pc_number'");
$showPc   = ($hasPcCol && $hasPcCol->num_rows > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Reservation</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .res-wrap { padding: 22px; }

    /* Stats */
    .stats-row { display:flex; gap:14px; margin-bottom:22px; flex-wrap:wrap; }
    .stat-card { flex:1; min-width:120px; background:#fff; border-radius:10px;
                 padding:16px 18px; box-shadow:0 1px 6px rgba(0,0,0,0.07);
                 border-left:4px solid var(--c,#1a5276); text-align:center; }
    .stat-card .num { font-size:1.8rem; font-weight:800; color:var(--c,#1a5276); line-height:1; }
    .stat-card .lbl { font-size:0.75rem; color:#64748b; font-weight:600;
                      text-transform:uppercase; margin-top:4px; }

    /* Section cards */
    .section-card { background:#fff; border-radius:10px;
                    box-shadow:0 1px 8px rgba(0,0,0,0.07); margin-bottom:22px; overflow:hidden; }
    .section-head {
      display:flex; align-items:center; justify-content:space-between;
      padding:13px 20px;
      font-weight:700; font-size:0.95rem; color:#fff;
    }
    .section-head.pending  { background:linear-gradient(135deg,#b45309,#d97706); }
    .section-head.approved { background:linear-gradient(135deg,#166534,#16a34a); }
    .section-head.rejected { background:linear-gradient(135deg,#7f1d1d,#dc2626); }
    .section-head .badge {
      background:rgba(255,255,255,0.25); padding:2px 10px;
      border-radius:12px; font-size:0.8rem; font-weight:700;
    }

    /* Table */
    table { width:100%; border-collapse:collapse; font-size:0.86rem; }
    th { background:#f8faff; color:#475569; padding:10px 14px; text-align:left;
         font-weight:700; font-size:0.75rem; text-transform:uppercase;
         letter-spacing:0.05em; border-bottom:1px solid #e2e8f0; }
    td { padding:11px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    tr:last-child td { border-bottom:none; }
    tr:hover td { background:#f8faff; }

    .empty-row { text-align:center; padding:30px; color:#94a3b8; font-size:0.88rem; }

    /* Student name cell */
    .name-cell .name { font-weight:700; font-size:0.88rem; color:#1e293b; }
    .name-cell .id   { font-size:0.75rem; color:#94a3b8; margin-top:1px; }

    /* Status pill */
    .pill { display:inline-block; padding:3px 10px; border-radius:20px;
            font-size:0.76rem; font-weight:700; }
    .pill-pending  { background:#fef9c3; color:#854d0e; }
    .pill-approved { background:#dcfce7; color:#166534; }
    .pill-rejected { background:#fee2e2; color:#991b1b; }

    /* Action buttons */
    .btn-approve {
      background:#16a34a; color:#fff; padding:5px 12px;
      border:none; border-radius:6px; cursor:pointer;
      font-size:0.8rem; font-weight:700; text-decoration:none;
      display:inline-block; transition:opacity 0.15s;
    }
    .btn-approve:hover { opacity:0.85; }
    .btn-reject {
      background:#dc2626; color:#fff; padding:5px 12px;
      border:none; border-radius:6px; cursor:pointer;
      font-size:0.8rem; font-weight:700; text-decoration:none;
      display:inline-block; margin-left:5px; transition:opacity 0.15s;
    }
    .btn-reject:hover { opacity:0.85; }
    .btn-start {
      background:#2563c0; color:#fff; padding:5px 12px;
      border:none; border-radius:6px; cursor:pointer;
      font-size:0.8rem; font-weight:700; text-decoration:none;
      display:inline-block; transition:opacity 0.15s;
    }
    .btn-start:hover { opacity:0.85; }

    .sess-low  { color:#dc2626; font-weight:700; }
    .sess-ok   { color:#16a34a; font-weight:700; }
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
<div class="res-wrap">
  <h2 style="color:#1a3a6b; margin-bottom:18px; font-size:1.25rem;">🔖 Lab Reservation Management</h2>

  <?php if ($message): ?>
    <div class="msg-<?= $message_type ?>" style="margin-bottom:16px;"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card" style="--c:#d97706;">
      <div class="num"><?= $totalPending ?></div>
      <div class="lbl">Pending</div>
    </div>
    <div class="stat-card" style="--c:#16a34a;">
      <div class="num"><?= $totalApproved ?></div>
      <div class="lbl">Approved</div>
    </div>
    <div class="stat-card" style="--c:#dc2626;">
      <div class="num"><?= $totalRejected ?></div>
      <div class="lbl">Rejected</div>
    </div>
    <div class="stat-card" style="--c:#1a5276;">
      <div class="num"><?= $totalPending + $totalApproved + $totalRejected ?></div>
      <div class="lbl">Total</div>
    </div>
  </div>

  <!-- ── Pending ── -->
  <div class="section-card">
    <div class="section-head pending">
      <span>⏳ Pending Approvals</span>
      <span class="badge"><?= $totalPending ?></span>
    </div>
    <table>
      <thead>
        <tr>
          <th>Student</th>
          <th>Lab</th>
          <?php if ($showPc): ?><th>PC</th><?php endif; ?>
          <th>Date</th>
          <th>Time</th>
          <th>Purpose</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($pending && $pending->num_rows > 0):
          while ($row = $pending->fetch_assoc()): ?>
          <tr>
            <td class="name-cell">
              <div class="name"><?= htmlspecialchars($row['firstname'].' '.$row['lastname']) ?></div>
              <div class="id"><?= htmlspecialchars($row['idnumber']) ?></div>
            </td>
            <td><strong><?= htmlspecialchars($row['lab']) ?></strong></td>
            <?php if ($showPc): ?><td><?= htmlspecialchars($row['pc_number'] ?? '—') ?></td><?php endif; ?>
            <td><?= date('M d, Y', strtotime($row['reservation_date'])) ?></td>
            <td><?= date('h:i A', strtotime($row['start_time'])) ?> – <?= date('h:i A', strtotime($row['end_time'])) ?></td>
            <td><?= htmlspecialchars($row['purpose']) ?></td>
            <td>
              <a class="btn-approve"
                 href="admin_reservation.php?approve=<?= $row['res_id'] ?>"
                 onclick="return confirm('Approve this reservation?')">✓ Approve</a>
              <a class="btn-reject"
                 href="admin_reservation.php?reject=<?= $row['res_id'] ?>"
                 onclick="return confirm('Reject this reservation?')">✗ Reject</a>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="<?= $showPc?7:6 ?>" class="empty-row">No pending reservations.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ── Approved ── -->
  <div class="section-card">
    <div class="section-head approved">
      <span>✅ Approved Reservations</span>
      <span class="badge"><?= $totalApproved ?></span>
    </div>
    <table>
      <thead>
        <tr>
          <th>Student</th>
          <th>Lab</th>
          <?php if ($showPc): ?><th>PC</th><?php endif; ?>
          <th>Date</th>
          <th>Time</th>
          <th>Purpose</th>
          <th>Sessions Left</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($approved && $approved->num_rows > 0):
          while ($row = $approved->fetch_assoc()): ?>
          <tr>
            <td class="name-cell">
              <div class="name"><?= htmlspecialchars($row['firstname'].' '.$row['lastname']) ?></div>
              <div class="id"><?= htmlspecialchars($row['idnumber']) ?></div>
            </td>
            <td><strong><?= htmlspecialchars($row['lab']) ?></strong></td>
            <?php if ($showPc): ?><td><?= htmlspecialchars($row['pc_number'] ?? '—') ?></td><?php endif; ?>
            <td><?= date('M d, Y', strtotime($row['reservation_date'])) ?></td>
            <td><?= date('h:i A', strtotime($row['start_time'])) ?> – <?= date('h:i A', strtotime($row['end_time'])) ?></td>
            <td><?= htmlspecialchars($row['purpose']) ?></td>
            <td class="<?= $row['remaining_session'] <= 5 ? 'sess-low' : 'sess-ok' ?>">
              <?= $row['remaining_session'] ?>
            </td>
            <td>
              <a class="btn-start"
                 href="admin_reservation.php?start_sitin=<?= $row['res_id'] ?>"
                 onclick="return confirm('Start sit-in for this student?')">▶ Start Sit-in</a>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="<?= $showPc?8:7 ?>" class="empty-row">No approved reservations.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ── Rejected ── -->
  <div class="section-card">
    <div class="section-head rejected">
      <span>✗ Rejected Reservations <span style="font-size:0.8rem;opacity:0.75;">(last 20)</span></span>
      <span class="badge"><?= $totalRejected ?></span>
    </div>
    <table>
      <thead>
        <tr>
          <th>Student</th>
          <th>Lab</th>
          <?php if ($showPc): ?><th>PC</th><?php endif; ?>
          <th>Date</th>
          <th>Time</th>
          <th>Purpose</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rejected && $rejected->num_rows > 0):
          while ($row = $rejected->fetch_assoc()): ?>
          <tr>
            <td class="name-cell">
              <div class="name"><?= htmlspecialchars($row['firstname'].' '.$row['lastname']) ?></div>
              <div class="id"><?= htmlspecialchars($row['idnumber']) ?></div>
            </td>
            <td><?= htmlspecialchars($row['lab']) ?></td>
            <?php if ($showPc): ?><td><?= htmlspecialchars($row['pc_number'] ?? '—') ?></td><?php endif; ?>
            <td><?= date('M d, Y', strtotime($row['reservation_date'])) ?></td>
            <td><?= date('h:i A', strtotime($row['start_time'])) ?> – <?= date('h:i A', strtotime($row['end_time'])) ?></td>
            <td><?= htmlspecialchars($row['purpose']) ?></td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="<?= $showPc?6:5 ?>" class="empty-row">No rejected reservations.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
</main>
</body>
</html>