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

// Unread notifications count
$nq = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE idnumber = ? AND is_read = 0");
$nq->bind_param("s", $student['idnumber']);
$nq->execute();
$unread_count = $nq->get_result()->fetch_assoc()['cnt'] ?? 0;
$nq->close();

$message = ""; $message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idnumber     = trim($_POST['idnumber']);
    $firstname    = trim($_POST['firstname']);
    $middlename   = trim($_POST['middlename'] ?? '');
    $lastname     = trim($_POST['lastname']);
    $address      = trim($_POST['address']);
    $email        = trim($_POST['email']);
    $new_password = $_POST['new_password'];
    $confirm_pw   = $_POST['confirm_password'];

    if (empty($idnumber) || empty($firstname) || empty($lastname)) {
        $message = "ID Number, First Name, and Last Name are required.";
        $message_type = "error";
    } elseif (!empty($new_password) && $new_password !== $confirm_pw) {
        $message = "New passwords do not match.";
        $message_type = "error";
    } elseif (!empty($new_password) && strlen($new_password) < 6) {
        $message = "Password must be at least 6 characters.";
        $message_type = "error";
    } else {
        $ic = $conn->prepare("SELECT id FROM users WHERE idnumber = ? AND id != ?");
        $ic->bind_param("si", $idnumber, $_SESSION['student_id']);
        $ic->execute(); $ic->store_result();
        if ($ic->num_rows > 0) {
            $message = "That ID Number is already used by another account.";
            $message_type = "error";
            $ic->close();
        } else {
            $ic->close();
            $ec = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $ec->bind_param("si", $email, $_SESSION['student_id']);
            $ec->execute(); $ec->store_result();
            if ($ec->num_rows > 0) {
                $message = "That email is already used by another account.";
                $message_type = "error";
                $ec->close();
            } else {
                $ec->close();
                if (!empty($new_password)) {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $upd = $conn->prepare("UPDATE users SET idnumber=?,firstname=?,middlename=?,lastname=?,address=?,email=?,password=? WHERE id=?");
                    $upd->bind_param("sssssssi", $idnumber,$firstname,$middlename,$lastname,$address,$email,$hashed,$_SESSION['student_id']);
                } else {
                    $upd = $conn->prepare("UPDATE users SET idnumber=?,firstname=?,middlename=?,lastname=?,address=?,email=? WHERE id=?");
                    $upd->bind_param("ssssssi", $idnumber,$firstname,$middlename,$lastname,$address,$email,$_SESSION['student_id']);
                }
                if ($upd->execute()) {
                    $message = "Profile updated successfully!";
                    $message_type = "success";
                    $stmt2 = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt2->bind_param("i", $_SESSION['student_id']);
                    $stmt2->execute();
                    $student = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();
                } else {
                    $message = "Update failed. Please try again.";
                    $message_type = "error";
                }
                $upd->close();
            }
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Edit Profile</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .readonly-field {
      background: #f4f6f9; color: #555;
      padding: 9px 11px; border: 1px solid #ddd;
      border-radius: 5px; font-size: 0.9rem;
      margin-top: 4px;
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
      <a href="student_notifications.php">🔔 Notifications</a>
      <?php if ($unread_count > 0): ?>
        <span class="notif-badge"><?= $unread_count ?></span>
      <?php endif; ?>
    </div>
    <a href="student_dashboard.php">Home</a>
    <a href="student_edit_profile.php" class="active">Edit Profile</a>
    <a href="student_history.php">History</a>
    <a href="student_reservation.php">Reservation</a>
    <span class="spacer"></span>
    <a href="student_logout.php" class="logout">Log out</a>
  </nav>
  <main>
    <section class="form-container" style="max-width:480px;">
      <h2>✏️ Edit Profile</h2>

      <?php if ($message): ?>
        <div class="msg-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <label>Course</label>
      <div class="readonly-field"><?= htmlspecialchars($student['course']) ?></div>

      <label>Year Level</label>
      <div class="readonly-field">Year <?= htmlspecialchars($student['courselevel']) ?></div>

      <hr style="margin:18px 0; border:none; border-top:1px solid #e0e7ef;"/>

      <form action="student_edit_profile.php" method="POST">
        <label for="idnumber">ID Number <span style="color:#e74c3c;">*</span></label>
        <input type="text" id="idnumber" name="idnumber" required
               value="<?= htmlspecialchars($student['idnumber']) ?>"/>

        <label for="firstname">First Name <span style="color:#e74c3c;">*</span></label>
        <input type="text" id="firstname" name="firstname" required
               value="<?= htmlspecialchars($student['firstname']) ?>"/>

        <label for="middlename">Middle Name</label>
        <input type="text" id="middlename" name="middlename"
               value="<?= htmlspecialchars($student['middlename'] ?? '') ?>"/>

        <label for="lastname">Last Name <span style="color:#e74c3c;">*</span></label>
        <input type="text" id="lastname" name="lastname" required
               value="<?= htmlspecialchars($student['lastname']) ?>"/>

        <label for="address">Address</label>
        <input type="text" id="address" name="address"
               value="<?= htmlspecialchars($student['address'] ?? '') ?>"/>

        <label for="email">Email <span style="color:#e74c3c;">*</span></label>
        <input type="email" id="email" name="email" required
               value="<?= htmlspecialchars($student['email']) ?>"/>

        <hr style="margin:18px 0; border:none; border-top:1px solid #e0e7ef;"/>
        <p style="font-size:0.85rem; color:#666; margin-bottom:4px;">Leave blank to keep your current password.</p>

        <label for="new_password">New Password <small style="font-weight:400;">(min. 6 characters)</small></label>
        <input type="password" id="new_password" name="new_password" minlength="6" placeholder="New password"/>

        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat new password"/>

        <button type="submit">Save Changes</button>
      </form>
    </section>
  </main>
</body>
</html>
