<?php
$host = 'localhost';
$dbname = 'ecams';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $teachers = [
        ['uid' => 5,  'name' => 'lindaluvhorses'],
        ['uid' => 6,  'name' => 'bRonny'],
        ['uid' => 7,  'name' => 'JapandudeAA'],
        ['uid' => 8,  'name' => 'leonardo'],
        ['uid' => 9,  'name' => 'Gayhu'],
        ['uid' => 10, 'name' => 'Carleung'],
        ['uid' => 11, 'name' => 'GwongZai'],
        ['uid' => 12, 'name' => 'NEUER1'],
        ['uid' => 13, 'name' => 'PROstrangler'],
        ['uid' => 14, 'name' => 'iwanttojoinPLA'],
        ['uid' => 15, 'name' => 'japansFENG'],
        ['uid' => 16, 'name' => 'T-RYANgiggs'],
        ['uid' => 17, 'name' => 'shelynornathan']
    ];

    $stmt = $pdo->prepare("
        INSERT INTO users (uid, name, class, cid, email, password, role) 
        VALUES (:uid, :name, :class, :cid, :email, :password, :role)
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            class = VALUES(class),
            cid = VALUES(cid),
            email = VALUES(email),
            password = VALUES(password),
            role = VALUES(role)
    ");

    $pdo->beginTransaction();
    $cidCounter = 1;

    foreach ($teachers as $t) {
        $uid = $t['uid'];
        $name = $t['name'];
        $class = 'TE';
        $cid = $cidCounter++;
        $email = $name . '@ecams.local';
        $hashedPassword = password_hash($name, PASSWORD_DEFAULT);
        $role = 'teacher';

        $stmt->execute([
            ':uid' => $uid,
            ':name' => $name,
            ':class' => $class,
            ':cid' => $cid,
            ':email' => $email,
            ':password' => $hashedPassword,
            ':role' => $role
        ]);
    }

    $pdo->commit();
    echo "Successfully inserted/updated all teachers into the database!";

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>