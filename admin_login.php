<?php
session_start();

// Already logged in as admin?
if (isset($_SESSION['admin_id'])) {
    header("Location: admin_dashboard.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM admin WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin  = $result->fetch_assoc();
    $stmt->close();

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id']       = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        header("Location: admin_dashboard.php");
        exit();
    } else {
        $error = "Invalid username or password.";
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Admin Login</title>
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
    <a href="Register.php">Register</a>
    <a href="admin_login.php" class="active">Admin Login</a>
  </nav>
  <main>
    <section class="form-container" style="max-width:380px;">
      <h2>🔐 Admin Login</h2>
      <?php if ($error): ?>
        <p style="color:#e74c3c; text-align:center; font-weight:600; margin-bottom:10px;">
          <?= htmlspecialchars($error) ?>
        </p>
      <?php endif; ?>
      <form action="admin_login.php" method="POST">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required
               autocomplete="username" placeholder="Admin username"/>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required
               autocomplete="current-password" placeholder="••••••••"/>

        <button type="submit">Login</button>
      </form>
      <p style="text-align:center; margin-top:14px; font-size:0.88rem;">
        <a href="index.php" style="color:#1a5276; font-weight:600;">← Back to Student Login</a>
      </p>
    </section>
  </main>
</body>
</html>
