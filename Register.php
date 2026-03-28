<?php
$conn = new mysqli("localhost", "root", "", "sit_in_system");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idnumber       = trim($_POST['idnumber']);
    $lastname       = trim($_POST['lastname']);
    $firstname      = trim($_POST['firstname']);
    $middlename     = trim($_POST['middlename']);
    $courselevel    = intval($_POST['courselevel']);   // cast to int
    $course         = trim($_POST['course']);
    $address        = trim($_POST['address']);
    $email          = trim($_POST['email']);
    $password       = $_POST['password'];
    $repeatpassword = $_POST['repeatpassword'];

    if ($password !== $repeatpassword) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        // Check duplicate ID or email
        $check = $conn->prepare("SELECT id FROM users WHERE idnumber = ? OR email = ?");
        $check->bind_param("ss", $idnumber, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "ID Number or Email is already registered.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            // bind_param: s s s s i s s s s  (courselevel is int → i)
            $stmt = $conn->prepare(
                "INSERT INTO users (idnumber, lastname, firstname, middlename, courselevel, course, address, email, password, remaining_session)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 30)"
            );
            $stmt->bind_param("ssssissss",
                $idnumber, $lastname, $firstname, $middlename,
                $courselevel, $course, $address, $email, $hashed
            );

            if ($stmt->execute()) {
                header("Location: index.php?registered=1");
                exit();
            } else {
                $error = "Registration failed: " . $stmt->error;
            }
            $stmt->close();
        }
        $check->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Register</title>
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
    <a href="admin_login.php">Admin</a>
  </nav>
  <main>
    <section class="form-container" style="max-width:500px;">
      <h2>Sign Up</h2>
      <?php if ($error): ?>
        <p style="color:red; text-align:center; margin-bottom:10px;"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>
      <form action="Register.php" method="POST">
        <label for="idnumber">ID Number</label>
        <input type="text" id="idnumber" name="idnumber" required
               value="<?= htmlspecialchars($_POST['idnumber'] ?? '') ?>"/>

        <label for="lastname">Last Name</label>
        <input type="text" id="lastname" name="lastname" required
               value="<?= htmlspecialchars($_POST['lastname'] ?? '') ?>"/>

        <label for="firstname">First Name</label>
        <input type="text" id="firstname" name="firstname" required
               value="<?= htmlspecialchars($_POST['firstname'] ?? '') ?>"/>

        <label for="middlename">Middle Name</label>
        <input type="text" id="middlename" name="middlename"
               value="<?= htmlspecialchars($_POST['middlename'] ?? '') ?>"/>

        <label for="courselevel">Year Level</label>
        <select id="courselevel" name="courselevel" required>
          <?php for ($y = 1; $y <= 5; $y++): ?>
            <option value="<?= $y ?>" <?= (($_POST['courselevel'] ?? 1) == $y) ? 'selected' : '' ?>>
              Year <?= $y ?>
            </option>
          <?php endfor; ?>
        </select>

        <label for="course">Course</label>
        <select id="course" name="course" required>
          <option value="">-- Select Course --</option>
          <?php foreach ([
            'BS Computer Science','BS Information Technology','BS Computer Engineering',
            'BS Accountancy','BS Business Administration','BS Criminology',
            'BS Civil Engineering','BS Electrical Engineering','BS Mechanical Engineering',
            'BS Industrial Engineering','BS Commerce','BS Hotel & Restaurant Management',
            'BS Tourism Management','BS Elementary Education','BS Secondary Education',
            'BS Customs Administration','BS Industrial Psychology',
            'BS Real Estate Management','BS Office Administration'
          ] as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"
              <?= (($_POST['course'] ?? '') === $c) ? 'selected' : '' ?>>
              <?= htmlspecialchars($c) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="address">Address</label>
        <input type="text" id="address" name="address" required
               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>"/>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"/>

        <label for="password">Password <small>(min. 6 characters)</small></label>
        <input type="password" id="password" name="password" required minlength="6"/>

        <label for="repeatpassword">Repeat Password</label>
        <input type="password" id="repeatpassword" name="repeatpassword" required/>

        <button type="submit">Register</button>
      </form>
      <p style="text-align:center; margin-top:12px; font-size:0.9rem;">
        Already have an account? <a href="index.php">Login here</a>
      </p>
    </section>
  </main>
</body>
</html>
