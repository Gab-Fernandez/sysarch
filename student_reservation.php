<?php
session_start();
if (!isset($_SESSION['student_id'])) { header("Location: index.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['student_id']); $stmt->execute();
$student = $stmt->get_result()->fetch_assoc(); $stmt->close();

// Unread notifications
$nq = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE idnumber = ? AND is_read = 0");
$nq->bind_param("s", $student['idnumber']); $nq->execute();
$unread_count = $nq->get_result()->fetch_assoc()['cnt'] ?? 0; $nq->close();

// Check reservation enabled
$resEnabled = true;
$chkTbl = $conn->query("SHOW TABLES LIKE 'system_settings'");
if ($chkTbl && $chkTbl->num_rows > 0) {
    $resRow = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='reservation_enabled' LIMIT 1");
    if ($resRow && $resRow->num_rows > 0)
        $resEnabled = ($resRow->fetch_assoc()['setting_value'] === '1');
}

$labs = ['524','526','528','530','542','Mac Lab','Lab 1','Lab 2','Lab 3','Lab 4','Lab 5','Lab 6'];
$purposes = ['Programming','Research','Online Class','Project','Assignment','Printing','Internet','C#','C','Java','ASP.Net','PHP','Other'];

// PC counts per lab
$lab_pc_counts = [
    '524'=>50,'526'=>50,'528'=>50,'530'=>50,'542'=>50,'Mac Lab'=>20,
    'Lab 1'=>30,'Lab 2'=>30,'Lab 3'=>30,'Lab 4'=>30,'Lab 5'=>30,'Lab 6'=>30,
];

$message = ""; $message_type = "";

// Submit reservation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resEnabled) {
    $purpose          = trim($_POST['purpose']);
    $lab              = trim($_POST['lab']);
    $pc_number        = trim($_POST['pc_number'] ?? '');
    $reservation_date = $_POST['reservation_date'];
    $start_time       = $_POST['start_time'];
    $end_time         = $_POST['end_time'];

    if (empty($purpose) || empty($lab) || empty($reservation_date) || empty($start_time) || empty($end_time)) {
        $message = "Please fill in all required fields."; $message_type = "error";
    } elseif ($end_time <= $start_time) {
        $message = "End time must be after start time."; $message_type = "error";
    } else {
        // Check time slot conflict (same lab + pc)
        $chk = $conn->prepare(
            "SELECT res_id FROM reservations
             WHERE lab = ? AND reservation_date = ?
             AND status != 'rejected'
             AND ((start_time <= ? AND end_time > ?) OR (start_time < ? AND end_time >= ?))"
        );
        $chk->bind_param("ssssss", $lab, $reservation_date, $start_time, $start_time, $end_time, $end_time);
        $chk->execute(); $chk->store_result();

        if ($chk->num_rows > 0) {
            $message = "That time slot is already booked for this lab. Please choose another.";
            $message_type = "error";
        } else {
            $ins = $conn->prepare(
                "INSERT INTO reservations (idnumber, lab, reservation_date, start_time, end_time, purpose, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            );
            // Try with pc_number if column exists
            $hasPc = $conn->query("SHOW COLUMNS FROM reservations LIKE 'pc_number'");
            if ($hasPc && $hasPc->num_rows > 0) {
                $ins->close();
                $ins = $conn->prepare(
                    "INSERT INTO reservations (idnumber, lab, reservation_date, start_time, end_time, purpose, pc_number, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
                );
                $ins->bind_param("sssssss", $student['idnumber'], $lab, $reservation_date, $start_time, $end_time, $purpose, $pc_number);
            } else {
                $ins->bind_param("ssssss", $student['idnumber'], $lab, $reservation_date, $start_time, $end_time, $purpose);
            }

            if ($ins->execute()) {
                $message = "Reservation submitted successfully! Waiting for admin approval.";
                $message_type = "success";
            } else {
                $message = "Failed to submit. Please try again."; $message_type = "error";
            }
            $ins->close();
        }
        $chk->close();
    }
}

// Fetch reservations — check if pc_number col exists
$hasPcCol = $conn->query("SHOW COLUMNS FROM reservations LIKE 'pc_number'");
$selectPc = ($hasPcCol && $hasPcCol->num_rows > 0) ? ", pc_number" : ", NULL as pc_number";

$rq = $conn->prepare(
    "SELECT res_id, lab, reservation_date, start_time, end_time, purpose, status $selectPc
     FROM reservations WHERE idnumber = ? ORDER BY reservation_date DESC, start_time DESC LIMIT 20"
);
$rq->bind_param("s", $student['idnumber']); $rq->execute();
$reservations = $rq->get_result(); $rq->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Reservation</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="style.css"/>
  <style>
    body { font-family:'Inter',sans-serif; background:#eef2f7; }

    .page-wrap { display:grid; grid-template-columns:1fr 1fr; gap:24px; padding:28px; max-width:1200px; margin:0 auto; }

    /* ── Form card ── */
    .res-card {
      background:#fff; border-radius:12px;
      overflow:hidden;
      box-shadow:0 1px 8px rgba(0,0,0,0.08);
    }
    .res-card-head {
      background:#1a3a6b; color:#fff;
      padding:14px 20px;
      display:flex; align-items:center; gap:10px;
      font-weight:700; font-size:0.95rem;
      letter-spacing:0.04em;
    }
    .res-card-head .icon { font-size:1rem; }
    .res-card-body { padding:22px 22px; }

    /* form fields */
    .fg { margin-bottom:16px; }
    .fg label {
      display:block; font-weight:600;
      font-size:0.85rem; color:#334155;
      margin-bottom:6px;
    }
    .fg select, .fg input {
      width:100%; padding:10px 13px;
      border:1.5px solid #d0daea;
      border-radius:8px; font-size:0.9rem;
      background:#f7f9fc; color:#1e293b;
      transition:border-color 0.2s, background 0.2s;
      appearance:none; -webkit-appearance:none;
    }
    .fg select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:32px; }
    .fg select:focus, .fg input:focus {
      border-color:#2563c0; background:#fff;
      outline:none; box-shadow:0 0 0 3px rgba(37,99,192,0.1);
    }
    .btn-submit {
      width:100%; padding:12px;
      background:#2563c0; color:#fff;
      border:none; border-radius:8px;
      font-size:0.95rem; font-weight:700;
      cursor:pointer; margin-top:4px;
      transition:background 0.2s;
    }
    .btn-submit:hover { background:#1a4fa0; }
    .btn-submit:disabled { background:#94a3b8; cursor:not-allowed; }

    /* disabled overlay */
    .disabled-notice {
      background:#fef9ec; border:1px solid #fde68a;
      border-radius:8px; padding:14px 16px;
      font-size:0.88rem; color:#92400e; font-weight:600;
      margin-bottom:16px; display:flex; align-items:center; gap:8px;
    }

    /* ── My Reservations table ── */
    .res-table-wrap { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:0.84rem; }
    thead th {
      background:#f1f5f9; color:#64748b;
      font-weight:700; font-size:0.75rem;
      text-transform:uppercase; letter-spacing:0.06em;
      padding:10px 12px; text-align:left;
      border-bottom:1px solid #e2e8f0;
    }
    tbody td { padding:11px 12px; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
    tbody tr:last-child td { border-bottom:none; }
    tbody tr:hover td { background:#f8faff; }

    .empty-row { text-align:center; padding:40px; color:#94a3b8; font-size:0.88rem; }

    /* status pills */
    .pill {
      display:inline-block; padding:3px 10px;
      border-radius:20px; font-size:0.76rem; font-weight:700;
      white-space:nowrap;
    }
    .pill-pending  { background:#fef9c3; color:#854d0e; }
    .pill-approved { background:#dcfce7; color:#166534; }
    .pill-rejected { background:#fee2e2; color:#991b1b; }

    /* alert */
    .alert { padding:11px 14px; border-radius:8px; margin-bottom:16px; font-size:0.88rem; font-weight:600; }
    .alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
    .alert-error   { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

    @media(max-width:800px){ .page-wrap{grid-template-columns:1fr;padding:16px;} }
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

  <!-- LEFT: Form -->
  <div class="res-card">
    <div class="res-card-head">
      <span class="icon">🔖</span> NEW RESERVATION
    </div>
    <div class="res-card-body">
      <?php if (!$resEnabled): ?>
        <div class="disabled-notice">⚠️ Reservations are currently disabled by the admin.</div>
      <?php endif; ?>
      <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <form method="POST" id="resForm">
        <div class="fg">
          <label>Purpose</label>
          <select name="purpose" required <?= !$resEnabled?'disabled':'' ?>>
            <option value="">Select Purpose</option>
            <?php foreach($purposes as $p): ?>
              <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="fg">
          <label>Lab</label>
          <select name="lab" id="labSelect" required <?= !$resEnabled?'disabled':'' ?> onchange="buildPcDropdown()">
            <option value="">Select Lab</option>
            <?php foreach($labs as $l): ?>
              <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="fg">
          <label>PC Number</label>
          <select name="pc_number" id="pcSelect" <?= !$resEnabled?'disabled':'' ?>>
            <option value="">Select PC Number</option>
          </select>
        </div>

        <div class="fg">
          <label>Date</label>
          <input type="date" name="reservation_date" required
                 min="<?= date('Y-m-d') ?>"
                 <?= !$resEnabled?'disabled':'' ?>/>
        </div>

        <div class="fg">
          <label>Start Time</label>
          <input type="time" name="start_time" required <?= !$resEnabled?'disabled':'' ?>/>
        </div>

        <div class="fg">
          <label>End Time</label>
          <input type="time" name="end_time" required <?= !$resEnabled?'disabled':'' ?>/>
        </div>

        <button type="submit" class="btn-submit" <?= !$resEnabled?'disabled':'' ?>>
          Submit Reservation
        </button>
      </form>
    </div>
  </div>

  <!-- RIGHT: My Reservations -->
  <div class="res-card">
    <div class="res-card-head">
      <span class="icon">🗓️</span> MY RESERVATIONS
    </div>
    <div class="res-card-body" style="padding:0;">
      <div class="res-table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Start Time</th>
              <th>End Time</th>
              <th>Purpose</th>
              <th>Lab</th>
              <th>PC Number</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($reservations && $reservations->num_rows > 0):
              while ($r = $reservations->fetch_assoc()): ?>
              <tr>
                <td><?= date('M d, Y', strtotime($r['reservation_date'])) ?></td>
                <td><?= date('h:i A', strtotime($r['start_time'])) ?></td>
                <td><?= date('h:i A', strtotime($r['end_time'])) ?></td>
                <td><?= htmlspecialchars($r['purpose']) ?></td>
                <td><?= htmlspecialchars($r['lab']) ?></td>
                <td><?= htmlspecialchars($r['pc_number'] ?? '—') ?></td>
                <td>
                  <span class="pill pill-<?= $r['status'] ?>">
                    <?= ucfirst($r['status']) ?>
                  </span>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="7" class="empty-row">No reservations found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</main>

<script>
const LAB_PCS = <?= json_encode($lab_pc_counts) ?>;

function buildPcDropdown() {
  const lab = document.getElementById('labSelect').value;
  const sel = document.getElementById('pcSelect');
  sel.innerHTML = '<option value="">Select PC Number</option>';
  if (lab && LAB_PCS[lab]) {
    for (let i = 1; i <= LAB_PCS[lab]; i++) {
      const n = 'PC-' + String(i).padStart(2,'0');
      sel.innerHTML += `<option value="${n}">${n}</option>`;
    }
  }
}
</script>
</body>
</html>