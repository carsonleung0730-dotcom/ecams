<?php
// generate_students.php
session_start();

$host = 'localhost';
$dbname = 'ecams';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Hong Kong Surnames (at least 18 different ones)
$hkSurnames = [
    'Chan', 'Wong', 'Cheung', 'Lee', 'Leung', 'Ho', 'Kwok', 'Lam', 'Ng', 'Tang',
    'Fung', 'Tse', 'Yeung', 'Chu', 'Hui', 'Chung', 'Tam', 'Tsang', 'Lo', 'Yuen'
];

// Your custom given names pool
$givenNamesPool = [
    'Amy', 'Brian', 'Chloe', 'David', 'Emily', 'Frank', 'Grace', 'Henry', 'Isabella', 'Jack',
    'Kelly', 'Leo', 'Mia', 'Nathan', 'Olivia', 'Peter', 'Herman', 'Queenie', 'Ryan', 'Sarah', 'Thomas',
    'Vivian', 'William', 'Xavier', 'Yan', 'Ollie', 'Anson', 'Belinda', 'Carson', 'Diana', 'Ethan',
    'Fiona', 'Gareth', 'Hannah', 'Ian', 'Maatson', 'Barkley', 'Bruno', 'Jasmine', 'Kevin', 'Lily', 'Mason', 'Nora', 'Oscar',
    'Priscilla', 'Quinton', 'Rachel', 'Samuel', 'Tiffany', 'Eugene', 'Novem', 'Vicky', 'Walter', 'Icy', 'Yuki',
    'Zoe', 'Cynthia', 'Derek', 'Eunice', 'Felix', 'Gloria', 'Horace', 'Iris', 'Jason', 'Karen',
    'Louis', 'Monica', 'Norman', 'Mario', 'Draculla', 'Frankenstein', 'Ophelia', 'Patrick', 'Queena', 'Raymond', 'Samantha', 'Terence', 'Una',
    'Victor', 'Karson', 'Katie', 'Hagi', 'Pirlo', 'Martinez', 'Emil', 'Rooney', 'Bobby', 'McArthur', 'Trump', 'Biden', 'Emma', 'Nicky', 'Tarantino', 'Brad', 'Pedri', 'Yamal', 'Ronaldo', 'Watson', 'Wendy', 'Zendaya', 'Dalton', 'Zack', 'Daisy', 'EDward', 'Flora', 'Gavin'
];

// Classes definition: Grades 1 to 6 with sections A, B, C
$grades = [1, 2, 3, 4, 5, 6];
$sections = ['A', 'B', 'C'];

$usedNames = [];
$studentsData = [];
$currentUid = 18; // Uid starts from 18

// Generate 25 students per class
foreach ($grades as $grade) {
    foreach ($sections as $section) {
        $className = "{$grade}{$section}";
        
        // Determine how many students for this class (25, but 24 for 1A because #10 is skipped)
        $totalStudentsInClass = ($className === '1A') ? 24 : 25;

        // Generate a randomized array of unique student numbers (cid) from 1 to 25 for this class
        $availableCids = range(1, 25);
        if ($className === '1A') {
            // Remove number 10 if it's 1A
            $availableCids = array_diff($availableCids, [10]);
        }
        shuffle($availableCids);

        for ($i = 0; $i < $totalStudentsInClass; $i++) {
            $assignedCid = array_pop($availableCids);

            // Generate unique given name + Hong Kong surname combination
            do {
                $given = $givenNamesPool[array_rand($givenNamesPool)];
                $sur = $hkSurnames[array_rand($hkSurnames)];
                $fullName = "{$given} {$sur}";
            } while (in_array($fullName, $usedNames));

            $usedNames[] = $fullName;

            // Email: name trimmed spacing + @ecams.local
            $trimmedName = str_replace(' ', '', $fullName);
            $email = strtolower($trimmedName) . '@ecams.local';

            // Password hashed using PHP DEFAULT HASHING (NOT SHA256)
            $hashedPassword = password_hash($fullName, PASSWORD_DEFAULT);

            $studentsData[] = [
                'uid' => $currentUid++,
                'name' => $fullName,
                'email' => $email,
                'password' => $hashedPassword,
                'role' => 'student',
                'class' => $className,
                'cid' => $assignedCid
            ];
        }
    }
}

try {
    $pdo->beginTransaction();

    // Insert students into users table including class and cid
    $userStmt = $pdo->prepare("INSERT INTO users (uid, name, email, password, role, class, cid) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($studentsData as $student) {
        $userStmt->execute([
            $student['uid'],
            $student['name'],
            $student['email'],
            $student['password'],
            $student['role'],
            $student['class'],
            $student['cid']
        ]);
    }

    // Randomly assign 0 to 3 organisations for every student (OIDs 1 to 15)
    $memStmt = $pdo->prepare("INSERT IGNORE INTO membership (sid, oid) VALUES (?, ?)");
    
    foreach ($studentsData as $student) {
        $sid = $student['uid'];
        
        $numOrgsRand = rand(1, 100);
        if ($numOrgsRand <= 10) {
            $numOrgs = 0;
        } elseif ($numOrgsRand <= 40) {
            $numOrgs = 1;
        } elseif ($numOrgsRand <= 80) {
            $numOrgs = 2;
        } else {
            $numOrgs = 3;
        }

        if ($numOrgs > 0) {
            $availableOids = range(1, 15);
            shuffle($availableOids);

            $assignedOrgs = [];
            for ($k = 0; $k < $numOrgs; $k++) {
                if (empty($availableOids)) break;
                $chosenOid = array_pop($availableOids);

                if ($chosenOid == 9) {
                    $cntStmt = $pdo->query("SELECT COUNT(*) FROM membership WHERE oid = 9");
                    $currentOid9Count = $cntStmt->fetchColumn();
                    if ($currentOid9Count >= 9) {
                        continue;
                    }
                }

                $assignedOrgs[] = $chosenOid;
            }

            foreach ($assignedOrgs as $oid) {
                $memStmt->execute([$sid, $oid]);
            }
        }
    }

    $pdo->commit();
    echo "<h3>Success! All students with custom names and random non-duplicate class IDs (cid) have been added, along with organization memberships.</h3>";
    echo "<p>Total students added: " . count($studentsData) . "</p>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h3>Error executing database transaction:</h3> " . $e->getMessage();
}
?>