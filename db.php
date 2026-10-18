<?php
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$host = 'ecams-do-user-46407401-0.m.db.ondigitalocean.com';
$username = 'doadmin';
$password = 'AVNS_U3ocRtBD80-OAEuGdHs';
$dbname = 'ecams';
$port = 25060;

$conn = mysqli_init();

if (!@mysqli_real_connect($conn, $host, $username, $password, $database, $port, NULL, MYSQLI_CLIENT_SSL)) {
    die("Database Connection Failed: " . mysqli_connect_error());
}
    mysqli_select_db($conn, $dbname);
?>
