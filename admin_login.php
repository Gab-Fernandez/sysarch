<?php
session_start();

$conn = new mysqli("localhost", "root", "", "sit_in_system");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM admin WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        header("Location: admin_dashboard.php");
        exit();
    } else {
        $error = "Invalid username or password.";
    }

    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CCS | Admin Login</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <header>
    <img src="../uclogo.png" alt="UC Logo" class="logo" />
    <h1>College of Computer Studies Sit-in Monitoring System</h1>
    <img src="../ucmainccslogo.png" alt="CCS Logo" class="logo" />
  </header>
  <nav>
    <a href="index.html">Home</a>
    <a href="#">Community ▼</a>
    <a href="#">About</a>
    <a href="Register.html">Register</a>
  </nav>

  <main>
    <section class="form-container">
      <h2>Admin Login</h2>
      <?php if (isset($error)): ?>
        <p style="color:red; text-align:center;"><?= $error ?></p>
      <?php endif; ?>
      <form action="admin_login.php" method="POST">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required />

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required />

        <button type="submit">Login</button>
      </form>
      <p style="text-align:center; margin-top:10px;">
        <a href="index.html">← Back to Student Login</a>
      </p>
    </section>
  </main>
</body>
</html>