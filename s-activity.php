<?php
require_once 'db.php';   
session_start();

echo "<h3>Session Debugger:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Database Connection Check:</h3>";
if (isset($conn) && $conn instanceof mysqli) {
    echo "MySQLi connection (\$conn) is active and working!";
} else {
    echo "ERROR: \$conn is not set or is not a valid MySQLi connection. Check your db.php file.";
}
exit();
?>
