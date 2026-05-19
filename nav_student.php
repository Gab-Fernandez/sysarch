<?php
$self = basename($_SERVER['PHP_SELF']);
echo "<nav class=\"top-nav\">\n";
$students = [
  'student_dashboard.php' => '🏠 Home',
  'student_edit_profile.php' => '✏️ Edit Profile',
  'student_history.php' => '📋 History',
  'student_software.php' => '💻 Software',
  'student_pc_availability.php' => '🖥️ PC Availability',
];
foreach ($students as $href => $label) {
    $cls = ($self === $href) ? ' class="active"' : '';
    echo "  <a href=\"$href\"$cls>$label</a>\n";
}
// Notifications (expects $unread_count to be defined in including page)
echo "  <div class=\"notif-wrap\">\n";
echo "    <a href=\"student_notifications.php\">🔔 Notification</a>\n";
if (isset($unread_count) && $unread_count > 0) {
    echo "    <span class=\"notif-badge\">" . intval($unread_count) . "</span>\n";
}
echo "  </div>\n";
// Reservation link (expects $resEnabled to be defined)
if (!isset($resEnabled) || $resEnabled) {
    echo "  <a href=\"student_reservation.php\">🔖 Reservation</a>\n";
} else {
    echo "  <a href=\"#\" style=\"opacity:0.45;cursor:not-allowed;\" title=\"Reservations are currently disabled\">🔖 Reservation</a>\n";
}
echo "  <span class=\"spacer\"></span>\n";
echo "  <a href=\"student_logout.php\" class=\"logout\">Log out</a>\n";
echo "</nav>\n";
