<?php
$host = 'ecams-do-user-46407401-0.m.db.ondigitalocean.com';
$username = 'doadmin';
$password = 'AVNS_U3ocRtBD80-OAEuGdHs';
$database = 'ecams';
$port = 25060;

$conn = mysqli_init();
// Optional: Enable SSL mode if supported by the container runtime
// mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);

if (!@mysqli_real_connect($conn, $host, $username, $password, $database, $port, NULL, MYSQLI_CLIENT_SSL)) {
    die("Connect Error: " . mysqli_connect_error());
}
echo "Connected successfully!";
?>
