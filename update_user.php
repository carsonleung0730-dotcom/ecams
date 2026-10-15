<?php
session_start();
header('Content-Type: application/json');

// Database configuration
$host = 'localhost';
$dbname = 'ecams';
$username = 'root'; // Replace with your MySQL username
$password = '';     // Replace with your MySQL password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Get JSON input from fetch
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['uid'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload. UID is required.']);
    exit;
}

$uid      = $data['uid'];
$name     = $data['name'] ?? '';
$class    = $data['class'] ?? '';
$cid      = $data['cid'] ?? '';
$email    = $data['email'] ?? '';
$password = $data['password'] ?? '';
$role     = $data['role'] ?? '';
$orgs     = $data['orgs'] ?? []; // Array of selected organisation OIDs

try {
    $pdo->beginTransaction();

    // 1. Update User Details (including password)
    $updateUserStmt = $pdo->prepare("
        UPDATE users 
        SET name = :name, class = :class, cid = :cid, email = :email, password = :password, role = :role 
        WHERE uid = :uid
    ");
    $updateUserStmt->execute([
        ':name'     => $name,
        ':class'    => $class,
        ':cid'      => $cid,
        ':email'    => $email,
        ':password' => $password,
        ':role'     => $role,
        ':uid'      => $uid
    ]);

    // 2. Update Organisation Memberships
    // Delete existing memberships
    $deleteMemStmt = $pdo->prepare("DELETE FROM membership WHERE sid = :uid");
    $deleteMemStmt->execute([':uid' => $uid]);

    // Insert newly checked memberships
    if (!empty($orgs)) {
        $insertMemStmt = $pdo->prepare("INSERT INTO membership (sid, oid) VALUES (:uid, :oid)");
        foreach ($orgs as $oid) {
            $insertMemStmt->execute([
                ':uid' => $uid,
                ':oid' => $oid
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database update error: ' . $e->getMessage()]);
}