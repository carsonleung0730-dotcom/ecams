<?php
// 1. Trust DigitalOcean load balancer proxy headers for HTTPS
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// 2. Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 3. Database Credentials & Connection
$host = 'ecams-do-user-46407401-0.m.db.ondigitalocean.com';
$username = 'doadmin';
$password = 'AVNS_U3ocRtBD80-OAEuGdHs';
$dbname = 'ecams'; // Fixed variable name matching
$port = 25060;

$conn = mysqli_init();

// Use $dbname here instead of $database
if (!@mysqli_real_connect($conn, $host, $username, $password, $dbname, $port, NULL, MYSQLI_CLIENT_SSL)) {
    die("Database Connection Failed: " . mysqli_connect_error());
}

$conn->set_charset("utf8mb4");

// 4. Robust Session Configuration (Prevents random session drops)
ini_set('session.gc_maxlifetime', 86400);
ini_set('session.cookie_lifetime', 86400);
ini_set('session.use_strict_mode', 1);

session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);
?>
