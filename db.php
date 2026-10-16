<?php
$host = 'ecams-do-user-46407401-0.m.db.ondigitalocean.com';
$username = 'doadmin';
$password = 'AVNS_U3ocRtBD80-OAEuGdHs';
$database = 'ecams';
$port = 25060;

$conn = mysqli_init();

if (!@mysqli_real_connect($conn, $host, $username, $password, $database, $port, NULL, MYSQLI_CLIENT_SSL)) {
    die("Database Connection Failed: " . mysqli_connect_error());
}
?>
