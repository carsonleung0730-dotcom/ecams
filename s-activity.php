<?php
// Temporarily force error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
session_start();

// Test basic output to verify file execution
echo "Database and session loaded successfully! If you see this, the file is working.";
exit();
?>
