<?php
$$host = 'ecams-do-user-46407401-0.m.db.ondigitalocean.com';
$port = '25060';
$dbname = 'ecams';
$username = 'doadmin';
$password = 'AVNS_U3ocRtBD80-OAEuGdHs';// Default Laragon MySQL password is empty

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
?>
