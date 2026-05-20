<?php
$self = basename($_SERVER['PHP_SELF']);
$links = [
  'admin_dashboard.php'     => 'Home',
  'admin_search.php'        => 'Search',
  'admin_students.php'      => 'Students',
  'admin_sitin.php'         => 'Sit-in',
  'admin_sitin_records.php' => 'Sit-in Records',
  'admin_reports.php'       => 'Reports',
  'admin_feedback.php'      => 'Feedback',
  'admin_reservation.php'   => 'Reservation',
  'admin_leaderboard.php'   => 'Leaderboard',
  'admin_analytics.php'     => 'Analytics',
  'admin_software.php'      => 'Software',
  'admin_logout.php'        => 'Log out',
];
echo "<nav>\n";
foreach ($links as $href => $label) {
    $isActive  = ($self === $href) ? 'active' : '';
    $extra     = ($href === 'admin_logout.php') ? ' logout-btn' : '';
    $classAttr = trim($isActive . ' ' . $extra);
    $classAttr = $classAttr ? ' class="' . trim($classAttr) . '"' : '';
    echo "  <a href=\"$href\"$classAttr>$label</a>\n";
}
echo "</nav>\n";