<?php
$host = 'localhost';$dbname = 'ecams';
$username = 'root';$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username,$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $aid = 13;
    $oid = 1;

    $pdo->beginTransaction();

    // 1. Fetch all member student IDs (sid) for the specified organisation (oid)
    $memStmt =$pdo->prepare("SELECT sid FROM membership WHERE oid = ?");
    $memStmt->execute([$oid]);
    $members =$memStmt->fetchAll();

    if (empty($members)) {
        echo "No members found in organization ID $oid.";
        exit();
    }

    // 2. Prepare statement to insert attendance records
    // Using INSERT IGNORE or ON DUPLICATE KEY UPDATE to prevent duplicate entry errors if some records already exist
    $attStmt =$pdo->prepare("
        INSERT INTO attendance (aid, sid, attend, rate, `s.rate`) 
        VALUES (?, ?, 0, 10, 10)
        ON DUPLICATE KEY UPDATE attend = VALUES(attend)
    ");

    $count = 0;
    foreach ($members as$member) {
        $attStmt->execute([$aid, $member['sid']]);$count++;
    }

    $pdo->commit();
    echo "Successfully initialized/inserted $count attendance records for Activity ID $aid (Organization ID$oid).";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {$pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>