<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit(); }

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$message = ""; $message_type = "";
$labs     = ['Lab 1','Lab 2','Lab 3','Lab 4','Lab 5','Lab 6','524','526','528','530','542','Mac Lab'];
$purposes = ['C#','C','Java','ASP.Net','PHP','Programming','Research','Online Class','Project','Assignment','Printing','Internet','Other'];

// PC counts per lab — adjust to match your actual setup
$lab_pc_counts = [
    'Lab 1'=>30,'Lab 2'=>30,'Lab 3'=>30,'Lab 4'=>30,'Lab 5'=>30,'Lab 6'=>30,
    '524'=>40,'526'=>40,'528'=>40,'530'=>40,'542'=>40,'Mac Lab'=>20,
];

// ── AJAX: Student lookup ───────────────────────────────────────
if (isset($_GET['lookup'])) {
    header('Content-Type: application/json');
    $id   = trim($_GET['idnumber'] ?? '');
    $stmt = $conn->prepare("SELECT idnumber,firstname,middlename,lastname,remaining_session FROM users WHERE idnumber=?");
    $stmt->bind_param("s",$id); $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) {
        $chk = $conn->prepare("SELECT sit_id FROM sit_in WHERE idnumber=? AND status='active'");
        $chk->bind_param("s",$id); $chk->execute(); $chk->store_result();
        $active = $chk->num_rows > 0; $chk->close();
        echo json_encode([
            'found'             => true,
            'name'              => trim($row['firstname'].' '.($row['middlename']?' '.$row['middlename'].' ':'').$row['lastname']),
            'remaining_session' => (int)$row['remaining_session'],
            'has_active'        => $active,
        ]);
    } else {
        echo json_encode(['found'=>false]);
    }
    $conn->close(); exit();
}

// ── Start sit-in ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['start_sitin'])) {
    $idnumber  = trim($_POST['idnumber']);
    $lab       = trim($_POST['lab']);
    $purpose   = trim($_POST['purpose']);
    $pc_number = trim($_POST['pc_number'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM users WHERE idnumber=?");
    $stmt->bind_param("s",$idnumber); $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if (!$student) {
        $message="Student not found."; $message_type="error";
    } elseif ($student['remaining_session']<=0) {
        $message="Student has no remaining sessions."; $message_type="error";
    } else {
        $chk = $conn->prepare("SELECT sit_id FROM sit_in WHERE idnumber=? AND status='active'");
        $chk->bind_param("s",$idnumber); $chk->execute(); $chk->store_result();
        if ($chk->num_rows>0) {
            $message="Student already has an active sit-in session."; $message_type="error";
        } else {
            $now = date('Y-m-d H:i:s');
            // Try with pc_number column; fallback gracefully
            $ins = $conn->prepare("INSERT INTO sit_in (idnumber,lab,purpose,session_date,status,pc_number) VALUES (?,?,?,?,'active',?)");
            if (!$ins) {
                $ins = $conn->prepare("INSERT INTO sit_in (idnumber,lab,purpose,session_date,status) VALUES (?,?,?,?,'active')");
                $ins->bind_param("ssss",$idnumber,$lab,$purpose,$now);
            } else {
                $ins->bind_param("sssss",$idnumber,$lab,$purpose,$now,$pc_number);
            }
            if ($ins->execute()) {
                $new_sess = $student['remaining_session']-1;
                $upd = $conn->prepare("UPDATE users SET remaining_session=? WHERE idnumber=?");
                $upd->bind_param("is",$new_sess,$idnumber); $upd->execute(); $upd->close();
                $message = "Sit-in started for ".htmlspecialchars($student['firstname'].' '.$student['lastname']).". Sessions remaining: $new_sess.";
                $message_type="success";
            } else {
                $message="Failed to start sit-in."; $message_type="error";
            }
            $ins->close();
        }
        $chk->close();
    }
}

// ── End sit-in ────────────────────────────────────────────────
if (isset($_GET['end'])) {
    $sit_id=$intval($_GET['end'] ?? 0);
    $sit_id=intval($_GET['end']);
    $timeout=date('Y-m-d H:i:s');
    $stmt=$conn->prepare("UPDATE sit_in SET status='done',time_out=? WHERE sit_id=?");
    $stmt->bind_param("si",$timeout,$sit_id); $stmt->execute(); $stmt->close();
    header("Location: admin_sitin.php"); exit();
}

// ── Pagination & search ───────────────────────────────────────
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
if (!in_array($per_page,[10,25,50,100])) $per_page=10;
$page   = isset($_GET['page']) ? max(1,intval($_GET['page'])) : 1;
$offset = ($page-1)*$per_page;
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

$where = "WHERE s.status='active'";
if ($search) $where.=" AND (s.idnumber LIKE '%$search%' OR u.firstname LIKE '%$search%' OR u.lastname LIKE '%$search%' OR s.purpose LIKE '%$search%' OR s.lab LIKE '%$search%')";

$total       = $conn->query("SELECT COUNT(*) as c FROM sit_in s JOIN users u ON s.idnumber=u.idnumber $where")->fetch_assoc()['c'];
$total_pages = max(1,ceil($total/$per_page));
$records     = $conn->query("SELECT s.*,u.firstname,u.lastname,u.remaining_session FROM sit_in s JOIN users u ON s.idnumber=u.idnumber $where ORDER BY s.session_date DESC LIMIT $per_page OFFSET $offset");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Sit-in</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    /* ── Page header ── */
    .page-header {
      display:flex; align-items:center; justify-content:space-between;
      padding:18px 24px 4px; flex-wrap:wrap; gap:12px;
    }
    .page-header h2 { color:#1a3a6b; font-size:1.25rem; }
    .btn-open-modal {
      background:linear-gradient(135deg,#1a3a8f,#2563c0);
      color:#fff; border:none; padding:10px 22px;
      border-radius:7px; font-size:0.95rem; font-weight:700;
      cursor:pointer; display:flex; align-items:center; gap:8px;
      box-shadow:0 2px 10px rgba(26,82,118,0.3);
      transition:opacity 0.2s,transform 0.15s;
    }
    .btn-open-modal:hover { opacity:0.9; transform:translateY(-1px); }

    .table-section { padding:12px 24px 28px; }

    /* ── Modal overlay ── */
    .modal-overlay {
      display:none; position:fixed; inset:0;
      background:rgba(10,25,55,0.6); z-index:900;
      align-items:center; justify-content:center;
      padding:16px; backdrop-filter:blur(3px);
    }
    .modal-overlay.open { display:flex; }

    /* ── Modal card ── */
    .sitin-modal {
      background:#fff; border-radius:18px;
      width:490px; max-width:100%; max-height:92vh;
      overflow-y:auto;
      box-shadow:0 24px 64px rgba(0,0,0,0.28);
      animation:mIn 0.22s cubic-bezier(.34,1.4,.64,1);
    }
    @keyframes mIn {
      from { opacity:0; transform:scale(0.92) translateY(-16px); }
      to   { opacity:1; transform:scale(1)    translateY(0); }
    }

    /* Modal header */
    .modal-head {
      background:linear-gradient(135deg,#1234a0,#2563c0);
      color:#fff; padding:16px 22px;
      border-radius:18px 18px 0 0;
      display:flex; align-items:center; justify-content:space-between;
    }
    .modal-head h3 { font-size:1.08rem; font-weight:700; margin:0; letter-spacing:0.01em; }
    .modal-close {
      background:rgba(255,255,255,0.15); border:none; color:#fff;
      width:30px; height:30px; border-radius:50%;
      font-size:1rem; cursor:pointer; display:flex;
      align-items:center; justify-content:center;
      transition:background 0.15s;
    }
    .modal-close:hover { background:rgba(255,255,255,0.3); }

    /* Status bars inside modal */
    .info-bar {
      display:none; border-radius:8px;
      padding:10px 14px; margin:0 22px 4px;
      font-size:0.87rem; font-weight:600;
    }
    .info-bar.show { display:flex; align-items:center; gap:8px; }
    .info-bar.success { background:#eafaf1; border:1px solid #a9dfbf; color:#1e8449; }
    .info-bar.error   { background:#fdf0f0; border:1px solid #f5b7b1; color:#c0392b; }

    /* Form rows */
    .modal-body { padding:18px 22px 10px; }
    .mf-row {
      display:grid; grid-template-columns:148px 1fr;
      align-items:center; gap:10px; margin-bottom:14px;
    }
    .mf-row label { font-weight:600; font-size:0.88rem; color:#333; }
    .mf-row input, .mf-row select {
      padding:10px 12px; border:1.5px solid #d0daea;
      border-radius:8px; font-size:0.9rem;
      background:#f7f9fc; width:100%;
      transition:border-color 0.2s,background 0.2s;
    }
    .mf-row input:focus, .mf-row select:focus {
      border-color:#2563c0; background:#fff; outline:none;
      box-shadow:0 0 0 3px rgba(37,99,192,0.12);
    }
    .mf-row input[readonly] {
      background:#eef2f7; color:#444; cursor:default; font-weight:600;
    }
    /* Session input color coding */
    .sess-ok  { background:#eafaf1 !important; border-color:#27ae60 !important; color:#1a7a40 !important; }
    .sess-low { background:#fdf0f0 !important; border-color:#e74c3c !important; color:#c0392b !important; }

    /* Footer */
    .modal-footer {
      display:flex; justify-content:flex-end;
      gap:12px; padding:10px 22px 20px;
    }
    .btn-cancel-modal {
      background:#f0f4f8; color:#555;
      border:1.5px solid #d0daea; padding:10px 26px;
      border-radius:8px; font-weight:600; font-size:0.92rem;
      cursor:pointer; transition:background 0.15s;
    }
    .btn-cancel-modal:hover { background:#dce4ef; }
    .btn-sitin {
      background:linear-gradient(135deg,#1234a0,#2563c0);
      color:#fff; border:none; padding:10px 32px;
      border-radius:8px; font-weight:700; font-size:0.95rem;
      cursor:pointer; box-shadow:0 2px 10px rgba(37,99,192,0.3);
      transition:opacity 0.2s;
    }
    .btn-sitin:hover { opacity:0.9; }
    .btn-sitin:disabled { opacity:0.45; cursor:not-allowed; }

    /* Table */
    .table-controls { display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap; }
    .table-controls label { font-weight:600;font-size:0.88rem; }
    .table-controls select,.table-controls input { padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:0.88rem; }

    @media(max-width:540px){
      .mf-row { grid-template-columns:1fr; gap:4px; }
      .modal-footer { flex-direction:column-reverse; }
      .btn-cancel-modal,.btn-sitin { width:100%; text-align:center; }
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
  <a href="admin_sitin.php" class="active">Sit-in</a>
  <a href="admin_sitin_records.php">Sit-in Records</a>
  <a href="admin_reports.php">Reports</a>
  <a href="admin_feedback.php">Feedback</a>
  <a href="admin_reservation.php">Reservation</a>
  <a href="admin_logout.php" class="logout-btn">Log out</a>
</nav>

<main>
  <?php if ($message): ?>
    <div style="padding:14px 24px 0;">
      <div class="msg-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
    </div>
  <?php endif; ?>

  <div class="page-header">
    <h2>📋 Current Sit-in Sessions</h2>
    <button class="btn-open-modal" onclick="openModal()">
      ➕ Sit-in Form
    </button>
  </div>

  <div class="table-section">
    <div class="table-controls">
      <label>
        Show
        <select onchange="changePerPage(this.value)">
          <?php foreach ([10,25,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $per_page==$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
        entries
      </label>
      <span style="flex:1"></span>
      <label>Search:
        <input type="text" id="searchInput" value="<?= htmlspecialchars($search) ?>"
               placeholder="Name, lab, purpose..." onkeyup="doSearch(this.value)"/>
      </label>
    </div>

    <div class="table-wrap">
      <table id="sitinTable">
        <thead>
          <tr>
            <th onclick="sortTable(0)">Sit ID ▲</th>
            <th onclick="sortTable(1)">ID Number</th>
            <th onclick="sortTable(2)">Name</th>
            <th onclick="sortTable(3)">Purpose</th>
            <th onclick="sortTable(4)">Lab</th>
            <th onclick="sortTable(5)">PC #</th>
            <th onclick="sortTable(6)">Sessions Left</th>
            <th onclick="sortTable(7)">Time In</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($records && $records->num_rows > 0):
            while ($row = $records->fetch_assoc()): ?>
            <tr>
              <td><?= $row['sit_id'] ?></td>
              <td><?= htmlspecialchars($row['idnumber']) ?></td>
              <td><?= htmlspecialchars($row['firstname'].' '.$row['lastname']) ?></td>
              <td><?= htmlspecialchars($row['purpose']) ?></td>
              <td><?= htmlspecialchars($row['lab']) ?></td>
              <td><?= htmlspecialchars($row['pc_number'] ?? '—') ?></td>
              <td><?= $row['remaining_session'] ?></td>
              <td><?= date('h:i A', strtotime($row['session_date'])) ?></td>
              <td class="status-active">● Active</td>
              <td>
                <a class="btn-end"
                   href="admin_sitin.php?end=<?= $row['sit_id'] ?>"
                   onclick="return confirm('End this sit-in session?')">End Session</a>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="10" style="text-align:center;padding:28px;color:#999;">No active sit-in sessions.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination-info">
      Showing <?= $total?($offset+1):0 ?> to <?= min($offset+$per_page,$total) ?> of <?= $total ?> entries
    </div>
    <div class="pagination">
      <a href="?page=1&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">«</a>
      <?php if ($page>1): ?>
        <a href="?page=<?= $page-1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">‹</a>
      <?php endif; ?>
      <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
        <?php if($i==$page): ?><span class="current"><?= $i ?></span>
        <?php else: ?><a href="?page=<?= $i ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if($page<$total_pages): ?>
        <a href="?page=<?= $page+1 ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">›</a>
      <?php endif; ?>
      <a href="?page=<?= $total_pages ?>&per_page=<?= $per_page ?>&search=<?= urlencode($search) ?>">»</a>
    </div>
  </div>
</main>

<!-- ══════════ SIT-IN MODAL ══════════ -->
<div class="modal-overlay" id="sitinModal">
  <div class="sitin-modal">

    <div class="modal-head">
      <h3>📋 Sit-In Form</h3>
      <button class="modal-close" onclick="closeModal()" title="Close">✕</button>
    </div>

    <!-- Status bars -->
    <div class="info-bar success" id="foundBar">
      ✅ <span id="foundMsg"></span>
    </div>
    <div class="info-bar error" id="errorBar">
      <span id="errorMsg"></span>
    </div>

    <form method="POST" action="admin_sitin.php" id="sitinForm">
      <div class="modal-body">

        <!-- ID Number -->
        <div class="mf-row">
          <label for="m_id">ID Number</label>
          <input type="text" id="m_id" name="idnumber"
                 placeholder="e.g., 2024-00001" required
                 oninput="schedLookup(this.value)" autocomplete="off"/>
        </div>

        <!-- Student Name (readonly, auto-filled) -->
        <div class="mf-row">
          <label>Student Name</label>
          <input type="text" id="m_name" placeholder="Auto-filled on lookup" readonly/>
        </div>

        <!-- Purpose -->
        <div class="mf-row">
          <label for="m_purpose">Purpose</label>
          <select id="m_purpose" name="purpose" required>
            <option value="">— Select Purpose —</option>
            <?php foreach ($purposes as $p): ?>
              <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Lab -->
        <div class="mf-row">
          <label for="m_lab">Lab</label>
          <select id="m_lab" name="lab" required onchange="buildPcList()">
            <option value="">— Select Lab —</option>
            <?php foreach ($labs as $l): ?>
              <option value="<?= htmlspecialchars($l) ?>"><?= htmlspecialchars($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- PC Number -->
        <div class="mf-row">
          <label for="m_pc">PC Number</label>
          <select id="m_pc" name="pc_number">
            <option value="">— Select PC —</option>
          </select>
        </div>

        <!-- Remaining Session -->
        <div class="mf-row">
          <label>Remaining Session</label>
          <input type="text" id="m_sess" placeholder="—" readonly/>
        </div>

      </div><!-- /modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn-cancel-modal" onclick="closeModal()">Cancel</button>
        <button type="submit" name="start_sitin" class="btn-sitin" id="m_submit" disabled>
          Sit-In
        </button>
      </div>
    </form>

  </div>
</div>

<script>
const LAB_PCS = <?= json_encode($lab_pc_counts) ?>;

/* ── Open / Close ── */
function openModal() {
  document.getElementById('sitinModal').classList.add('open');
  document.getElementById('m_id').focus();
}
function closeModal() {
  document.getElementById('sitinModal').classList.remove('open');
  resetModal();
}
document.getElementById('sitinModal').addEventListener('click', e => {
  if (e.target === document.getElementById('sitinModal')) closeModal();
});
document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal(); });

/* ── Reset ── */
function resetModal() {
  document.getElementById('sitinForm').reset();
  document.getElementById('m_name').value  = '';
  document.getElementById('m_sess').value  = '';
  document.getElementById('m_sess').className = '';
  setBar('found', false);
  setBar('error', false);
  document.getElementById('m_submit').disabled = true;
  buildPcList();
}

/* ── Info bars ── */
function setBar(type, show, msg='') {
  const bar = document.getElementById(type==='found'?'foundBar':'errorBar');
  const txt = document.getElementById(type==='found'?'foundMsg':'errorMsg');
  if (show) { txt.textContent=msg; bar.classList.add('show'); }
  else       { bar.classList.remove('show'); }
}

/* ── Debounced lookup ── */
let timer;
function schedLookup(val) {
  clearTimeout(timer);
  document.getElementById('m_name').value  = '';
  document.getElementById('m_sess').value  = '';
  document.getElementById('m_sess').className = '';
  setBar('found',false); setBar('error',false);
  document.getElementById('m_submit').disabled = true;
  if (val.trim().length < 3) return;
  timer = setTimeout(() => doLookup(val.trim()), 420);
}

async function doLookup(id) {
  try {
    const r    = await fetch(`admin_sitin.php?lookup=1&idnumber=${encodeURIComponent(id)}`);
    const data = await r.json();

    if (!data.found) {
      setBar('error', true, '❌ Student not found. Please verify the ID number.');
      return;
    }
    if (data.has_active) {
      setBar('error', true, '⚠️ This student already has an active sit-in session.');
      return;
    }
    if (data.remaining_session <= 0) {
      setBar('error', true, '⚠️ This student has no remaining sessions left.');
      return;
    }

    // Fill fields
    document.getElementById('m_name').value = data.name;
    const sess = document.getElementById('m_sess');
    sess.value = data.remaining_session;
    sess.className = data.remaining_session <= 5 ? 'sess-low' : 'sess-ok';

    setBar('found', true, `Found: ${data.name}`);
    document.getElementById('m_submit').disabled = false;

  } catch(e) {
    setBar('error', true, '⚠️ Lookup failed. Please try again.');
  }
}

/* ── PC dropdown ── */
function buildPcList() {
  const lab = document.getElementById('m_lab').value;
  const sel = document.getElementById('m_pc');
  sel.innerHTML = '<option value="">— Select PC —</option>';
  if (lab && LAB_PCS[lab]) {
    for (let i=1; i<=LAB_PCS[lab]; i++) {
      const n = 'PC-'+String(i).padStart(2,'0');
      sel.innerHTML += `<option value="${n}">${n}</option>`;
    }
  }
}

/* ── Auto-reopen on POST error ── */
<?php if ($_SERVER['REQUEST_METHOD']==='POST' && $message_type==='error'): ?>
openModal();
document.getElementById('m_id').value = <?= json_encode($_POST['idnumber']??'') ?>;
<?php endif; ?>

/* ── Table helpers ── */
function changePerPage(val) {
  const u=new URL(window.location); u.searchParams.set('per_page',val); u.searchParams.set('page',1); window.location=u;
}
let st;
function doSearch(val) {
  clearTimeout(st);
  st=setTimeout(()=>{ const u=new URL(window.location); u.searchParams.set('search',val); u.searchParams.set('page',1); window.location=u; },400);
}
function sortTable(col) {
  const t=document.getElementById('sitinTable'), b=t.tBodies[0], rows=Array.from(b.rows);
  const asc=t.dataset.sortCol==col&&t.dataset.sortDir==='asc'?false:true;
  t.dataset.sortCol=col; t.dataset.sortDir=asc?'asc':'desc';
  rows.sort((a,b2)=>{
    const va=a.cells[col].innerText.trim().toLowerCase(), vb=b2.cells[col].innerText.trim().toLowerCase();
    const na=parseFloat(va), nb=parseFloat(vb);
    if(!isNaN(na)&&!isNaN(nb)) return asc?na-nb:nb-na;
    return asc?va.localeCompare(vb):vb.localeCompare(va);
  });
  rows.forEach(r=>b.appendChild(r));
}
</script>
</body>
</html>