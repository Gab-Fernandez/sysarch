<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$conn = new mysqli("localhost", "root", "", "sit_in_system");

$searchResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $search = trim($_POST['search']);
    $stmt = $conn->prepare("SELECT * FROM users WHERE idnumber LIKE ? OR lastname LIKE ? OR firstname LIKE ?");
    $like = "%$search%";
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $searchResult = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>CCS | Search</title>
  <link rel="stylesheet" href="style.css" />
  <style>
    .search-container { padding: 20px; }
    .search-box { display: flex; gap: 10px; margin-bottom: 20px; }
    .search-box input { padding: 8px; width: 300px; border: 1px solid #ccc; border-radius: 4px; }
    .search-box button { padding: 8px 16px; background: #1a5276; color: white; border: none; border-radius: 4px; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
    th { background: #1a5276; color: white; }
    tr:nth-child(even) { background: #f2f2f2; }
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
    <div class="search-container">
      <h2>Search Student</h2>
      <form method="POST">
        <div class="search-box">
          <input type="text" name="search" placeholder="Search by ID, first or last name..." />
          <button type="submit">Search</button>
        </div>
      </form>
      <?php if ($searchResult): ?>
        <?php if ($searchResult->num_rows > 0): ?>
          <table>
            <thead>
              <tr>
                <th>ID Number</th>
                <th>Name</th>
                <th>Course</th>
                <th>Year Level</th>
                <th>Email</th>
                <th>Remaining Session</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($row = $searchResult->fetch_assoc()): ?>
                <tr>
                  <td><?= htmlspecialchars($row['idnumber']) ?></td>
                  <td><?= htmlspecialchars($row['lastname'] . ', ' . $row['firstname'] . ' ' . $row['middlename']) ?></td>
                  <td><?= htmlspecialchars($row['course']) ?></td>
                  <td><?= htmlspecialchars($row['courselevel']) ?></td>
                  <td><?= htmlspecialchars($row['email']) ?></td>
                  <td><?= htmlspecialchars($row['remaining_session']) ?></td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p>No students found.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>