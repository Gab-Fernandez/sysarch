<?php
session_start();

if (isset($_SESSION['student_id'])) {
    header("Location: student_dashboard.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idnumber = trim($_POST['idnumber']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE idnumber = ?");
    $stmt->bind_param("s", $idnumber);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($student && password_verify($password, $student['password'])) {
        $_SESSION['student_id']       = $student['id'];
        $_SESSION['student_idnumber'] = $student['idnumber'];
        $_SESSION['student_name']     = $student['firstname'] . ' ' . $student['lastname'];
        header("Location: student_dashboard.php");
        exit();
    } else {
        $error = "Invalid ID number or password.";
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Student Login</title>
  <link rel="stylesheet" href="style.css"/>
</head>
<body>
  <header>
    <img src="uclogo.png" alt="UC Logo" class="logo"/>
    <h1>College of Computer Studies Sit-in Monitoring System</h1>
    <img src="ucmainccslogo.png" alt="CCS Logo" class="logo"/>
  </header>
  <nav>
    <a href="index.php">Home</a>
    <a href="Register.html">Register</a>
    <a href="admin_login.php">Admin</a>
  </nav>
  <main>
    <section class="form-container">
      <h2>Student Login</h2>
      <?php if ($error): ?>
        <p style="color:red; text-align:center; margin-top:10px;"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>
      <?php if (isset($_GET['registered'])): ?>
        <p style="color:green; text-align:center; margin-top:10px;">Registration successful! Please log in.</p>
      <?php endif; ?>
      <form action="index.php" method="POST">
        <label for="idnumber">ID Number</label>
        <input type="text" id="idnumber" name="idnumber" required placeholder="e.g., 2024-00001"/>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required/>

        <button type="submit">Login</button>
      </form>
      <p style="text-align:center; margin-top:12px; font-size:0.9rem;">
        No account yet? <a href="Register.html">Register here</a>
      </p>
    </section>
  </main>
</body>
</html>
