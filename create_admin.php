<?php
require_once 'config.php';

$username = 'admin';
$password = 'admin123';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Check if admin already exists
$stmt = $conn->prepare("SELECT * FROM admin WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $stmt->close();
    $insert = $conn->prepare("INSERT INTO admin (username, password) VALUES (?, ?)");
    $insert->bind_param("ss", $username, $hashed_password);
    if ($insert->execute()) {
        echo "<h3>Admin account created successfully!</h3>";
        echo "Username: <b>admin</b><br>";
        echo "Password: <b>admin123</b><br><br>";
        echo "<i>You can now log in at <a href='admin_login.php'>admin_login.php</a>. Please delete this file (create_admin.php) for security.</i>";
    } else {
        echo "Error creating admin account: " . $insert->error;
    }
    $insert->close();
} else {
    echo "<h3>Admin account already exists!</h3>";
    echo "You can log in at <a href='admin_login.php'>admin_login.php</a> using the username <b>admin</b>.";
}
$conn->close();
?>
