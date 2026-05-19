<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "sit_in_system");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create reservations table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS reservations (
    res_id INT AUTO_INCREMENT PRIMARY KEY,
    idnumber VARCHAR(20) NOT NULL,
    lab VARCHAR(20) NOT NULL,
    reservation_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    purpose VARCHAR(50),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$message = "";
$message_type = "";

// Handle status updates
if (isset($_GET['approve'])) {
    $res_id = intval($_GET['approve']);
    $conn->query("UPDATE reservations SET status = 'approved' WHERE res_id = $res_id");
    $message = "Reservation approved.";
    $message_type = "success";
}

if (isset($_GET['reject'])) {
    $res_id = intval($_GET['reject']);
    $conn->query("UPDATE reservations SET status = 'rejected' WHERE res_id = $res_id");
    $message = "Reservation rejected.";
    $message_type = "error";
}

// Handle start sit-in from approved reservation
if (isset($_GET['start_sitin'])) {
    $res_id = intval($_GET['start_sitin']);
    
    // Get the reservation details
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
            $message = "Student has no remaining sessions.";
            $message_type = "error";
        } else {
            // Check if student already has an active sit-in
            $chk = $conn->prepare("SELECT sit_id FROM sit_in WHERE idnumber = ? AND status = 'active'");
            $chk->bind_param("s", $reservation['idnumber']);
            $chk->execute();
            $chk->store_result();
            
            if ($chk->num_rows > 0) {
                $message = "Student already has an active sit-in session.";
                $message_type = "error";
            } else {
                // Create sit-in record
                $now = date('Y-m-d H:i:s');
                $ins = $conn->prepare("INSERT INTO sit_in (idnumber, lab, purpose, session_date, status) VALUES (?, ?, ?, ?, 'active')");
                $ins->bind_param("ssss", $reservation['idnumber'], $reservation['lab'], $reservation['purpose'], $now);
                
                if ($ins->execute()) {
                    // Decrement remaining sessions
                    $new_sess = $reservation['remaining_session'] - 1;
                    $upd = $conn->prepare("UPDATE users SET remaining_session = ? WHERE idnumber = ?");
                    $upd->bind_param("is", $new_sess, $reservation['idnumber']);
                    $upd->execute();
                    $upd->close();
                    
                    $message = "Sit-in session started for " . htmlspecialchars($reservation['firstname'] . ' ' . $reservation['lastname']) . ".";
                    $message_type = "success";
                } else {
                    $message = "Failed to start sit-in.";
                    $message_type = "error";
                }
                $ins->close();
            }
            $chk->close();
        }
    }
}

// Get pending reservations
$pending = $conn->query("SELECT r.*, u.firstname, u.lastname, u.course 
    FROM reservations r 
    LEFT JOIN users u ON r.idnumber = u.idnumber 
    WHERE r.status = 'pending' 
    ORDER BY r.reservation_date, r.start_time");

// Get approved reservations
$approved = $conn->query("SELECT r.*, u.firstname, u.lastname, u.course, u.remaining_session 
    FROM reservations r 
    LEFT JOIN users u ON r.idnumber = u.idnumber 
    WHERE r.status = 'approved' 
    ORDER BY r.reservation_date, r.start_time");

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>CCS | Reservation</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    .reservation-container { padding: 20px; }
    .message { padding: 12px; border-radius: 4px; margin-bottom: 15px; }
    .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .two-col { display: grid; grid-template-columns: 1fr; gap: 20px; }
    .form-section, .list-section { background: #f8f9fa; padding: 20px; border-radius: 8px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    .btn-submit { background: #28a745; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
    .btn-submit:hover { background: #218838; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 10px; border: 1px solid #ddd; text-align: left; font-size: 0.9em; }
    th { background: #1a5276; color: white; }
    tr:nth-child(even) { background: white; }
    .status-pending { background: #fff3cd; color: #856404; padding: 3px 8px; border-radius: 4px; }
    .status-approved { background: #d4edda; color: #155724; padding: 3px 8px; border-radius: 4px; }
    .status-rejected { background: #f8d7da; color: #721c24; padding: 3px 8px; border-radius: 4px; }
    .btn-approve { background: #28a745; color: white; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em; }
    .btn-reject { background: #e74c3c; color: white; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em; }
    .btn-startsitin { background: #007bff; color: white; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em; text-decoration: none; }
    .btn-startsitin:hover { background: #0056b3; }
    .section-title { margin-top: 0; color: #1a5276; border-bottom: 2px solid #1a5276; padding-bottom: 10px; }
    h3.section-title { margin-bottom: 15px; }
  </style>
</head>
<body>
  <header>
    <img src="uclogo.png" alt="UC Logo" class="logo" />
    <h1>College of Computer Studies Admin</h1>
    <img src="ucmainccslogo.png" alt="CCS Logo" class="logo" />
  </header>
  <nav>
    <a href="admin_dashboard.php">Home</a>
    <a href="admin_search.php">Search</a>
    <a href="admin_students.php">Students</a>
    <a href="admin_sitin.php">Sit-in</a>
    <a href="admin_sitin_records.php">View Sit-in Records</a>
    <a href="admin_reports.php">Sit-in Reports</a>
    <a href="admin_feedback.php">Feedback Reports</a>
    <a href="admin_reservation.php">Reservation</a>
    <a href="admin_logout.php" class="logout-btn">Log out</a>
  </nav>
  <main>
    <div class="reservation-container">
      <h2>🔖 Lab Reservation System</h2>
      
      <?php if ($message): ?>
        <div class="message <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      
      <div class="two-col">
        <div class="list-section" style="grid-column: 1 / -1;">
          <h3 class="section-title">Pending Approvals</h3>
          <?php if ($pending->num_rows > 0): ?>
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Student</th>
                  <th>Lab</th>
                  <th>Time</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($row = $pending->fetch_assoc()): ?>
                  <tr>
                    <td><?= date('M d, Y', strtotime($row['reservation_date'])) ?></td>
                    <td><?= htmlspecialchars(($row['firstname'] ?? 'N/A') . ' ' . ($row['lastname'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($row['lab']) ?></td>
                    <td><?= date('h:i A', strtotime($row['start_time'])) ?> - <?= date('h:i A', strtotime($row['end_time'])) ?></td>
                    <td>
                      <a class="btn-approve" href="admin_reservation.php?approve=<?= $row['res_id'] ?>" onclick="return confirm('Approve this reservation?')">✓</a>
                      <a class="btn-reject" href="admin_reservation.php?reject=<?= $row['res_id'] ?>" onclick="return confirm('Reject this reservation?')">✗</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p style="text-align: center; color: #666; padding: 20px;">No pending reservations.</p>
          <?php endif; ?>
          
          <h3 class="section-title" style="margin-top: 25px;">Approved Reservations</h3>
          <?php if ($approved->num_rows > 0): ?>
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Student</th>
                  <th>Lab</th>
                  <th>Time</th>
                  <th>Sessions</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($row = $approved->fetch_assoc()): ?>
                  <tr>
                    <td><?= date('M d, Y', strtotime($row['reservation_date'])) ?></td>
                    <td><?= htmlspecialchars(($row['firstname'] ?? 'N/A') . ' ' . ($row['lastname'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($row['lab']) ?></td>
                    <td><?= date('h:i A', strtotime($row['start_time'])) ?> - <?= date('h:i A', strtotime($row['end_time'])) ?></td>
                    <td><?= $row['remaining_session'] ?></td>
                    <td>
                      <a class="btn-startsitin" href="admin_reservation.php?start_sitin=<?= $row['res_id'] ?>" onclick="return confirm('Start sit-in session for this reservation?')">▶ Start Sit-in</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p style="text-align: center; color: #666; padding: 20px;">No approved reservations.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
