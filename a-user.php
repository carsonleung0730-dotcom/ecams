<?php
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'ecams';
$username = 'root'; // Replace with your MySQL username
$password = '';     // Replace with your MySQL password
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    // Redirect unauthorized users to an error page or their respective dashboard
    header('Location: unauthorized.php');
    exit();
}
if (!isset($_SESSION['uid']) && !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// -------------------------------------------------------------------------
// 1. BACKEND HANDLERS (AJAX & PDF GENERATION)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle PDF Export Request (Restricted to Student role)
    if (isset($_POST['action']) && $_POST['action'] === 'export_pdf') {
        $uid      = $_POST['export_uid'] ?? '';
        $start    = $_POST['export_start'] ?? '2026-09-01';
        $end      = $_POST['export_end'] ?? date('Y-m-d');
        
        $att      = isset($_POST['opt_attendance']) ? true : false;
        $attOrgs  = $_POST['opt_attendance_orgs'] ?? [];

        $part     = isset($_POST['opt_participation']) ? true : false;
        $partOrgs = $_POST['opt_participation_orgs'] ?? [];

        $sat      = isset($_POST['opt_satisfactory']) ? true : false;
        $satOrgs  = $_POST['opt_satisfactory_orgs'] ?? [];

        // Verify user exists and is a student
        $stmt = $pdo->prepare("SELECT * FROM users WHERE uid = :uid AND LOWER(role) = 'student'");
        $stmt->execute([':uid' => $uid]);
        $student = $stmt->fetch();

        if (!$student) {
            die("Error: PDF report generation is strictly restricted to users with the 'student' role.");
        }

        // Fetch enrolled organisations key-value map [oid => oname]
        $orgStmt = $pdo->prepare("
            SELECT o.oid, o.oname 
            FROM membership m 
            JOIN organisations o ON m.oid = o.oid 
            WHERE m.sid = :uid
        ");
        $orgStmt->execute([':uid' => $uid]);
        $enrolledOrgsRaw = $orgStmt->fetchAll();
        $orgMap = [];
        foreach ($enrolledOrgsRaw as $o) {
            $orgMap[$o['oid']] = $o['oname'];
        }
        $allOrgIds = array_keys($orgMap);

        // Fetch activities for a single organisation
        $getOrgActivities = function($oid) use ($pdo, $start, $end) {
            $actQuery = "
                SELECT a.aid, a.oid, a.aname, a.date, a.room 
                FROM activity a 
                WHERE a.oid = ? AND a.date BETWEEN ? AND ?
                ORDER BY a.date ASC
            ";
            $actStmt = $pdo->prepare($actQuery);
            $actStmt->execute([$oid, $start, $end]);
            return $actStmt->fetchAll();
        };

        // Fetch activities across all target orgs combined
        $getAllActivities = function($targetOids) use ($pdo, $start, $end) {
            if (empty($targetOids)) return [];
            $placeholders = implode(',', array_fill(0, count($targetOids), '?'));
            $actQuery = "
                SELECT a.aid, a.oid, a.aname, a.date, a.room 
                FROM activity a 
                WHERE a.oid IN ($placeholders) AND a.date BETWEEN ? AND ?
                ORDER BY a.date ASC
            ";
            $params = array_merge($targetOids, [$start, $end]);
            $actStmt = $pdo->prepare($actQuery);
            $actStmt->execute($params);
            return $actStmt->fetchAll();
        };

        // Helper function to convert integer to Roman numerals
        $toRoman = function($num) {
            $map = [
                1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V',
                6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X'
            ];
            return $map[$num] ?? $num;
        };

        // Fetch attendance records for this student using sid
        $attendanceMap = [];
        if (!empty($allOrgIds)) {
            $attStmt = $pdo->prepare("
                SELECT aid, attend, rate, `s.rate` 
                FROM attendance 
                WHERE sid = :uid
            ");
            $attStmt->execute([':uid' => $uid]);
            while ($row = $attStmt->fetch()) {
                $attendanceMap[$row['aid']] = $row;
            }
        }

        // Output PDF Headers
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Student_Report_' . $uid . '.pdf"');

        // Build PDF text elements
        $pdfCommands = [];
        
        // Title (Larger Font, Bold)
        $pdfCommands[] = ["font" => "F1", "size" => 16, "x" => 80, "y" => 730, "text" => "ECAMS STUDENT REPORT"];
        
        // Listed user details (Labels bold, values regular)
        $details = [
            ["label" => "UID: ", "val" => ($student['uid'] ?? '')],
            ["label" => "Name: ", "val" => ($student['name'] ?? '')],
            ["label" => "Class: ", "val" => ($student['class'] ?? '')],
            ["label" => "CID: ", "val" => ($student['cid'] ?? '')],
            ["label" => "Email: ", "val" => ($student['email'] ?? '')],
            ["label" => "Role: ", "val" => ($student['role'] ?? '')],
            ["label" => "Date Range: ", "val" => $start . " to " . $end]
        ];

        $y = 690;
        foreach ($details as $d) {
            $pdfCommands[] = ["font" => "F1", "size" => 11, "x" => 40, "y" => $y, "text" => $d['label']];
            $pdfCommands[] = ["font" => "F2", "size" => 11, "x" => 120, "y" => $y, "text" => $d['val']];
            $y -= 18;
        }

        $y -= 5;
        $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 40, "y" => $y, "text" => "--------------------------------------------------------------------------------"];
        $y -= 20;

        $sectionCounter = 1;

        // 1. Attendance Record Section
        if ($att) {
            $pdfCommands[] = ["font" => "F1", "size" => 12, "x" => 40, "y" => $y, "text" => $toRoman($sectionCounter) . ". Attendance Record:"];
            $y -= 18;

            $hasAll = in_array('ALL', $attOrgs);
            $specificOrgs = array_diff($attOrgs, ['ALL']);
            if (empty($attOrgs)) {
                $hasAll = true; // Default fallback if nothing selected
            }

            // Render separate org records first if any specific ones are chosen
            foreach ($specificOrgs as $oid) {
                if ($y < 50) break;
                if (!isset($orgMap[$oid])) continue;
                $orgName = $orgMap[$oid];
                $pdfCommands[] = ["font" => "F1", "size" => 11, "x" => 50, "y" => $y, "text" => "[" . $orgName . "]"];
                $y -= 16;

                $activities = $getOrgActivities($oid);
                $attCount = 0;
                $attTotal = count($activities);
                $index = 1;

                foreach ($activities as $act) {
                    if ($y < 50) break;
                    $aid = $act['aid'];
                    $status = "NOT PARTICIPATED";
                    if (isset($attendanceMap[$aid]) && intval($attendanceMap[$aid]['attend']) === 1) {
                        $status = "PARTICIPATED";
                        $attCount++;
                    }
                    
                    $actLabel = $index . ". " . $act['aname'] . " (" . $act['date'] . ")";
                    $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => $actLabel];
                    $pdfCommands[] = ["font" => "F2", "size" => 10, "x" => 330, "y" => $y, "text" => $status];
                    $y -= 15;
                    $index++;
                }
                $avgAtt = $attTotal > 0 ? round(($attCount / $attTotal) * 100, 1) : 0;
                $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => "Average Attendance: " . $avgAtt . "% (" . $attCount . "/" . $attTotal . ")"];
                $y -= 20;
            }

            // Render combined section last if 'ALL' is checked
            if ($hasAll) {
                if ($y >= 50) {
                    $pdfCommands[] = ["font" => "F1", "size" => 11, "x" => 50, "y" => $y, "text" => "[All Organisations]"];
                    $y -= 16;

                    $activities = $getAllActivities($allOrgIds);
                    $attCount = 0;
                    $attTotal = count($activities);
                    $index = 1;

                    foreach ($activities as $act) {
                        if ($y < 50) break;
                        $aid = $act['aid'];
                        $status = "NOT PARTICIPATED";
                        if (isset($attendanceMap[$aid]) && intval($attendanceMap[$aid]['attend']) === 1) {
                            $status = "PARTICIPATED";
                            $attCount++;
                        }
                        
                        $orgBracket = isset($orgMap[$act['oid']]) ? " (" . $orgMap[$act['oid']] . ")" : "";
                        $actLabel = $index . ". " . $act['aname'] . $orgBracket . " (" . $act['date'] . ")";

                        $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => $actLabel];
                        $pdfCommands[] = ["font" => "F2", "size" => 10, "x" => 330, "y" => $y, "text" => $status];
                        $y -= 15;
                        $index++;
                    }
                    $avgAtt = $attTotal > 0 ? round(($attCount / $attTotal) * 100, 1) : 0;
                    $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => "Average Attendance: " . $avgAtt . "% (" . $attCount . "/" . $attTotal . ")"];
                    $y -= 20;
                }
            }

            $y -= 10;
            $sectionCounter++;
        }

        // 2. Average Participation Rating Section
        if ($part && $y > 60) {
            $pdfCommands[] = ["font" => "F1", "size" => 12, "x" => 40, "y" => $y, "text" => $toRoman($sectionCounter) . ". Average Participation Rating:"];
            $y -= 18;

            $hasAll = in_array('ALL', $partOrgs);
            $specificOrgs = array_diff($partOrgs, ['ALL']);
            if (empty($partOrgs)) {
                $hasAll = true;
            }

            foreach ($specificOrgs as $oid) {
                if ($y < 50) break;
                if (!isset($orgMap[$oid])) continue;
                $orgName = $orgMap[$oid];
                $pdfCommands[] = ["font" => "F1", "size" => 11, "x" => 50, "y" => $y, "text" => "[" . $orgName . "]"];
                $y -= 16;

                $activities = $getOrgActivities($oid);
                $partSum = 0;
                $partCount = 0;
                $index = 1;

                foreach ($activities as $act) {
                    if ($y < 50) break;
                    $aid = $act['aid'];
                    $rate = isset($attendanceMap[$aid]['rate']) ? $attendanceMap[$aid]['rate'] : 'N/A';
                    if (is_numeric($rate)) {
                        $partSum += floatval($rate);
                        $partCount++;
                    }

                    $actLabel = $index . ". " . $act['aname'];
                    $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => $actLabel];
                    $pdfCommands[] = ["font" => "F2", "size" => 10, "x" => 330, "y" => $y, "text" => "Rate: " . $rate];
                    $y -= 15;
                    $index++;
                }
                $avgPart = $partCount > 0 ? round($partSum / $partCount, 2) : 0;
                $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => "Average Rating: " . $avgPart];
                $y -= 20;
            }

            if ($hasAll) {
                if ($y >= 50) {
                    $pdfCommands[] = ["font" => "F1", "size" => 11, "x" => 50, "y" => $y, "text" => "[All Organisations]"];
                    $y -= 16;

                    $activities = $getAllActivities($allOrgIds);
                    $partSum = 0;
                    $partCount = 0;
                    $index = 1;

                    foreach ($activities as $act) {
                        if ($y < 50) break;
                        $aid = $act['aid'];
                        $rate = isset($attendanceMap[$aid]['rate']) ? $attendanceMap[$aid]['rate'] : 'N/A';
                        if (is_numeric($rate)) {
                            $partSum += floatval($rate);
                            $partCount++;
                        }

                        $orgBracket = isset($orgMap[$act['oid']]) ? " (" . $orgMap[$act['oid']] . ")" : "";
                        $actLabel = $index . ". " . $act['aname'] . $orgBracket;

                        $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => $actLabel];
                        $pdfCommands[] = ["font" => "F2", "size" => 10, "x" => 330, "y" => $y, "text" => "Rate: " . $rate];
                        $y -= 15;
                        $index++;
                    }
                    $avgPart = $partCount > 0 ? round($partSum / $partCount, 2) : 0;
                    $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => "Average Rating: " . $avgPart];
                    $y -= 20;
                }
            }

            $y -= 10;
            $sectionCounter++;
        }

        // 3. Average Satisfactory Rating Section
        if ($sat && $y > 60) {
            $pdfCommands[] = ["font" => "F1", "size" => 12, "x" => 40, "y" => $y, "text" => $toRoman($sectionCounter) . ". Average Satisfactory Rating:"];
            $y -= 18;

            $hasAll = in_array('ALL', $satOrgs);
            $specificOrgs = array_diff($satOrgs, ['ALL']);
            if (empty($satOrgs)) {
                $hasAll = true;
            }

            foreach ($specificOrgs as $oid) {
                if ($y < 50) break;
                if (!isset($orgMap[$oid])) continue;
                $orgName = $orgMap[$oid];
                $pdfCommands[] = ["font" => "F1", "size" => 11, "x" => 50, "y" => $y, "text" => "[" . $orgName . "]"];
                $y -= 16;

                $activities = $getOrgActivities($oid);
                $satSum = 0;
                $satCount = 0;
                $index = 1;

                foreach ($activities as $act) {
                    if ($y < 50) break;
                    $aid = $act['aid'];
                    $sRate = isset($attendanceMap[$aid]['s.rate']) ? $attendanceMap[$aid]['s.rate'] : 'N/A';
                    if (is_numeric($sRate)) {
                        $satSum += floatval($sRate);
                        $satCount++;
                    }

                    $actLabel = $index . ". " . $act['aname'];
                    $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => $actLabel];
                    $pdfCommands[] = ["font" => "F2", "size" => 10, "x" => 330, "y" => $y, "text" => "Satisfactory Rate: " . $sRate];
                    $y -= 15;
                    $index++;
                }
                $avgSat = $satCount > 0 ? round($satSum / $satCount, 2) : 0;
                $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => "Average Rating: " . $avgSat];
                $y -= 20;
            }

            if ($hasAll) {
                if ($y >= 50) {
                    $pdfCommands[] = ["font" => "F1", "size" => 11, "x" => 50, "y" => $y, "text" => "[All Organisations]"];
                    $y -= 16;

                    $activities = $getAllActivities($allOrgIds);
                    $satSum = 0;
                    $satCount = 0;
                    $index = 1;

                    foreach ($activities as $act) {
                        if ($y < 50) break;
                        $aid = $act['aid'];
                        $sRate = isset($attendanceMap[$aid]['s.rate']) ? $attendanceMap[$aid]['s.rate'] : 'N/A';
                        if (is_numeric($sRate)) {
                            $satSum += floatval($sRate);
                            $satCount++;
                        }

                        $orgBracket = isset($orgMap[$act['oid']]) ? " (" . $orgMap[$act['oid']] . ")" : "";
                        $actLabel = $index . ". " . $act['aname'] . $orgBracket;

                        $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => $actLabel];
                        $pdfCommands[] = ["font" => "F2", "size" => 10, "x" => 330, "y" => $y, "text" => "Satisfactory Rate: " . $sRate];
                        $y -= 15;
                        $index++;
                    }
                    $avgSat = $satCount > 0 ? round($satSum / $satCount, 2) : 0;
                    $pdfCommands[] = ["font" => "F1", "size" => 10, "x" => 60, "y" => $y, "text" => "Average Rating: " . $avgSat];
                    $y -= 20;
                }
            }
        }

        $contentStream = "";
        // Draw solid square at top left corner (X: 40, Y: 720, Width: 32, Height: 32)
        $contentStream .= "0 g 0 G 40 720 32 32 re f\n";

        foreach ($pdfCommands as $cmd) {
            $escapedText = addslashes($cmd['text']);
            $contentStream .= "BT /{$cmd['font']} {$cmd['size']} Tf {$cmd['x']} {$cmd['y']} Td ({$escapedText}) Tj ET\n";
        }

        $streamLength = strlen($contentStream);
        echo "%PDF-1.4\n";
        echo "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        echo "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
        echo "3 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /MediaBox [0 0 612 792] /Contents 6 0 R >> endobj\n";
        echo "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >> endobj\n"; // F1 = Bold
        echo "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Courier >> endobj\n";      // F2 = Regular
        echo "6 0 obj << /Length " . $streamLength . " >> stream\n";
        echo $contentStream;
        echo "endstream\nendobj\n";
        
        echo "xref\n0 7\n";
        echo "0000000000 65535 f \n";
        echo "0000000009 00000 n \n";
        echo "0000000058 00000 n \n";
        echo "0000000115 00000 n \n";
        echo "0000000260 00000 n \n";
        echo "0000000327 00000 n \n";
        echo "0000000408 00000 n \n";
        echo "trailer << /Size 7 /Root 1 0 R >>\nstartxref\n550\n%%EOF";
        exit;
    }

    // Handle AJAX JSON Requests
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'Invalid payload.']);
            exit;
        }

        if (isset($data['action']) && $data['action'] === 'hash_preview') {
            $plain = $data['password'] ?? '';
            if (empty($plain)) {
                echo json_encode(['success' => true, 'hash' => '']);
                exit;
            }
            $hashed = password_hash($plain, PASSWORD_DEFAULT);
            echo json_encode(['success' => true, 'hash' => $hashed]);
            exit;
        }

        if (isset($data['action']) && $data['action'] === 'delete') {
            $uid = $data['uid'] ?? '';
            if (empty($uid)) {
                echo json_encode(['success' => false, 'message' => 'UID required for deletion.']);
                exit;
            }
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM membership WHERE sid = :uid")->execute([':uid' => $uid]);
                $pdo->prepare("DELETE FROM users WHERE uid = :uid")->execute([':uid' => $uid]);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'User deleted successfully.']);
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
            }
            exit;
        }

        if (isset($data['action']) && $data['action'] === 'batch_add') {
            $newUsers = $data['users'] ?? [];
            if (empty($newUsers)) {
                echo json_encode(['success' => false, 'message' => 'No user entries provided.']);
                exit;
            }
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (uid, name, class, cid, email, password, role) VALUES (:uid, :name, :class, :cid, :email, :password, :role)");
                
                foreach ($newUsers as $u) {
                    $hashedPwd = !empty($u['password']) ? password_hash($u['password'], PASSWORD_DEFAULT) : '';
                    $stmt->execute([
                        ':uid'      => $u['uid'],
                        ':name'     => $u['name'],
                        ':class'    => $u['class'],
                        ':cid'      => $u['cid'],
                        ':email'    => $u['email'],
                        ':password' => $hashedPwd,
                        ':role'     => $u['role']
                    ]);
                }
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Users added successfully.']);
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Batch insert failed: ' . $e->getMessage()]);
            }
            exit;
        }

        $uid      = $data['uid'] ?? '';
        $name     = $data['name'] ?? '';
        $class    = $data['class'] ?? '';
        $cid      = $data['cid'] ?? '';
        $email    = $data['email'] ?? '';
        $plainPwd = $data['password'] ?? '';
        $role     = $data['role'] ?? '';
        $orgs     = $data['orgs'] ?? [];

        if (empty($uid)) {
            echo json_encode(['success' => false, 'message' => 'UID is required.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            if (!empty($plainPwd)) {
                $hashedPassword = password_hash($plainPwd, PASSWORD_DEFAULT);
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
                    ':password' => $hashedPassword,
                    ':role'     => $role,
                    ':uid'      => $uid
                ]);
            } else {
                $updateUserStmt = $pdo->prepare("
                    UPDATE users 
                    SET name = :name, class = :class, cid = :cid, email = :email, role = :role 
                    WHERE uid = :uid
                ");
                $updateUserStmt->execute([
                    ':name'     => $name,
                    ':class'    => $class,
                    ':cid'      => $cid,
                    ':email'    => $email,
                    ':role'     => $role,
                    ':uid'      => $uid
                ]);
            }

            $pdo->prepare("DELETE FROM membership WHERE sid = :uid")->execute([':uid' => $uid]);

            if (!empty($orgs)) {
                $insertMemStmt = $pdo->prepare("INSERT INTO membership (sid, oid) VALUES (:uid, :oid)");
                foreach ($orgs as $oid) {
                    $insertMemStmt->execute([':uid' => $uid, ':oid' => $oid]);
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// -------------------------------------------------------------------------
// 2. PAGE DATA FETCHING
// -------------------------------------------------------------------------
$allOrganisations = [];
try {
    $orgStmt = $pdo->query("SELECT oid, oname FROM organisations ORDER BY oname ASC");
    $allOrganisations = $orgStmt->fetchAll();
} catch (PDOException $e) {}

$existingUsersData = [];
try {
    $exStmt = $pdo->query("SELECT class, cid FROM users WHERE class IS NOT NULL AND cid IS NOT NULL");
    $existingUsersData = $exStmt->fetchAll();
} catch (PDOException $e) {}

$classes = [];
try {
    $classStmt = $pdo->query("SELECT DISTINCT class FROM users WHERE class IS NOT NULL AND class != '' ORDER BY class ASC");
    $classes = $classStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

$users = [];
try {
    $query = "
        SELECT 
            u.uid, 
            u.name, 
            u.class, 
            u.cid,
            u.email,
            u.role,
            CASE 
                WHEN LOWER(u.role) = 'student' THEN (
                    SELECT GROUP_CONCAT(CONCAT(o.oid, ':', o.oname) SEPARATOR '||')
                    FROM membership m
                    JOIN organisations o ON m.oid = o.oid
                    WHERE m.sid = u.uid
                )
                ELSE (
                    SELECT GROUP_CONCAT(CONCAT(o.oid, ':', o.oname) SEPARATOR '||')
                    FROM organisations o
                    WHERE o.tid = u.uid
                )
            END AS org_data
        FROM users u
        ORDER BY u.uid ASC
    ";
    $stmt = $pdo->query($query);
    $rawUsers = $stmt->fetchAll();

    foreach ($rawUsers as $row) {
        $orgList = [];
        if (!empty($row['org_data'])) {
            $pairs = explode('||', $row['org_data']);
            foreach ($pairs as $pair) {
                $parts = explode(':', $pair, 2);
                if (count($parts) === 2) {
                    $orgList[] = ['oid' => $parts[0], 'oname' => $parts[1]];
                }
            }
        }
        $row['organisations'] = $orgList;
        unset($row['org_data']);
        $users[] = $row;
    }
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - User Profile</title>
    <link rel="icon" type="image/x-icon" href="sECAMS.png">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: #e0f2fe;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        header {
            background-color: #ffffff;
            border-bottom: 4px double #1037e572;
            padding: 1rem 2.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            position: relative;
            z-index: 20;
            height: 110px;
            box-sizing: border-box;
        }

        .header-content {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-left { display: flex; align-items: center; gap: 1rem; }
        .logo { height: 85px; width: auto; display: block; }
        .header-titles h1 { font-size: 1.35rem; font-weight: 700; color: #2e8ccb; letter-spacing: -0.02em; }
        .sub-header-row { display: flex; align-items: center; gap: 12px; margin-top: 2px; }
        .sub-header-row p { font-size: 0.85rem; color: #64748b; }
        
        .btn-logout {
            background-color: #eb4174;
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.65rem;
            border-radius: 4px;
            text-decoration: none;
            transition: background-color 0.2s;
            display: inline-block;
        }
        .btn-logout:hover { background-color: #9f1239; }

        .clock-widget { text-align: right; padding: 0.5rem 0.85rem; }
        .clock-time { font-size: 1.15rem; font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; }
        .clock-date { font-size: 0.75rem; font-weight: 500; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }

        .app-body { flex: 1; display: flex; height: calc(100vh - 110px); position: relative; overflow: hidden; }

        .side-panel {
            width: 280px;
            background-color: #ffffff;
            border-right: 4px double #1037e572;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            z-index: 10;
        }

        .nav-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 1.35rem;
            font-weight: 700;
            color: #ffffff;
            transition: all 0.2s ease-in-out;
            box-sizing: border-box;
            position: relative;
            border: none;
            cursor: pointer;
        }

        .nav-btn.is-active::before {
            content: '';
            position: absolute;
            top: 16px;
            left: 16px;
            width: 16px;
            height: 16px;
            background-color: #2563eb;
            border-radius: 50%;
            border: 2px solid #ffffff;
            box-shadow: 0 0 10px rgba(37, 99, 235, 0.8);
            z-index: 20;
        }

        .side-panel .nav-btn:hover { border: 8px double #ffffff !important; z-index: 2; }
        .btn-icon { width: 280px; height: 200px; object-fit: contain; }
        .nav-btn-org { background-color: #fea768; border-bottom: 2px solid #ea580c; }
        .nav-btn-activity { background-color: #dc2626; border-bottom: 2px solid #991b1b; }
        .nav-btn-user { background-color: #881337; }

        main {
            flex: 1;
            padding: 2rem;
            background-color: #d6e6f9;
            height: 100%;
            overflow: hidden;
            position: relative;
        }

        .content-wrapper { max-width: 1000px; margin: 0 auto; height: 100%; display: flex; flex-direction: column; }
        .filter-controls { display: flex; gap: 1rem; margin-bottom: 1.25rem; align-items: center; flex-shrink: 0; }

        .search-box {
            flex: 1;
            padding: 0.75rem 1rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95rem;
            background-color: #ffffff;
            outline: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
            transition: border-color 0.2s;
        }
        .search-box:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }

        .class-select {
            padding: 0.75rem 1rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95rem;
            background-color: #ffffff;
            outline: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
            cursor: pointer;
            min-width: 180px;
        }

        .user-container {
            background: #ffffff;
            border-radius: 12px;
            padding: 0;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
            border: 1px solid #dbeafe;
            height: calc(100vh - 270px);
            overflow-y: auto;
            box-sizing: border-box;
            position: relative;
        }

        .table-header {
            position: sticky;
            top: 0;
            z-index: 10;
            display: grid;
            grid-template-columns: 1fr 2fr 1fr 1fr;
            gap: 1rem;
            padding: 0.75rem 1.25rem;
            background-color: #f1f5f9;
            border-bottom: 2px solid #cbd5e1;
            font-size: 0.75rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
        }

        .table-header div {
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: color 0.2s;
        }
        .table-header div:hover { color: #2563eb; }
        .table-header div.sorted-asc::after { content: " ▲"; font-size: 0.65rem; color: #2563eb; }
        .table-header div.sorted-desc::after { content: " ▼"; font-size: 0.65rem; color: #2563eb; }

        .user-row {
            display: grid;
            grid-template-columns: 1fr 2fr 1fr 1fr;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1.25rem;
            border: 1px solid transparent;
            border-bottom: 1px solid #f1f5f9;
            transition: background-color 0.15s ease;
            cursor: pointer;
        }
        .user-row:hover { background-color: #eff6ff; border: 1px solid #000000; }
        .user-field-value { font-size: 0.9rem; font-weight: 600; color: #0f172a; }

        /* Hover Tooltip */
        .hover-tooltip {
            position: fixed;
            display: none;
            pointer-events: none;
            background-color: #ffffff;
            border: 2px solid #2563eb;
            border-radius: 10px;
            padding: 16px 20px;
            font-size: 1rem;
            color: #0f172a;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            z-index: 100000000000000;
            min-width: 320px;
            line-height: 1.5;
        }

        .add-floating-btn {
            position: fixed;
            bottom: 0;
            right: 0;
            width: 280px;
            height: 280px;
            cursor: pointer;
            z-index: 1500;
            transition: transform 0.2s ease;
        }
        .add-floating-btn:hover { transform: scale(1.05); }

        .sliding-panel {
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            background-color: #ffffff;
            box-shadow: -4px 0 16px rgba(0, 0, 0, 0.15);
            transform: translateX(100%);
            transition: transform 0.4s ease-in-out;
            z-index: 2500;
            display: flex;
            flex-direction: column;
            padding: 1.5rem 2rem;
            box-sizing: border-box;
            overflow-y: auto;
        }
        .sliding-panel.is-open { transform: translateX(0); }

        .close-arrow-btn {
            position: absolute;
            top: 16px;
            left: 16px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s ease;
            z-index: 40;
        }
        .close-arrow-btn:hover { transform: scale(1.15); }
        .arrow-img { width: 32px; height: 32px; object-fit: contain; }

        .panel-content { width: 100%; display: flex; flex-direction: column; margin-top: 2rem; gap: 1.5rem; }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 4px;
        }

        .panel-upper-half {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .panel-lower-half {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            background-color: #f8fafc;
            padding: 1.25rem;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }

        .form-row-1 { display: grid; grid-template-columns: 0.8fr 2.2fr 1.2fr 0.8fr auto; gap: 1rem; align-items: center; }
        .form-row-2 { display: grid; grid-template-columns: 1fr 1.3fr 1.3fr 1fr; gap: 1rem; align-items: flex-start; }

        .field-group { display: flex; flex-direction: column; gap: 0.25rem; }
        .field-label { font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; }

        .input-box {
            padding: 0.45rem 0.65rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.88rem;
            outline: none;
            background-color: #ffffff;
            color: #0f172a;
            width: 100%;
        }
        .input-box:focus { border-color: #2563eb; }
        .input-box-large { font-size: 1.15rem; font-weight: 800; color: #0f172a; }

        .btn-delete-user {
            background-color: #ef4444;
            color: #ffffff;
            border: none;
            font-size: 0.85rem;
            font-weight: 700;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s ease;
            white-space: nowrap;
        }
        .btn-delete-user:hover { background-color: #dc2626; }

        .dropdown-checklist-container { position: relative; display: inline-block; width: 60%; max-width: 350px; }
        .checklist-anchor {
            padding: 0.35rem 0.55rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.8rem;
            background: #ffffff;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .checklist-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            max-height: 120px;
            overflow-y: auto;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 100;
            padding: 4px;
        }
        .checklist-dropdown.visible { display: block; }
        .checklist-item { display: flex; align-items: center; gap: 8px; padding: 3px 6px; font-size: 0.78rem; color: #1e293b; cursor: pointer; }
        .checklist-item:hover { background-color: #f1f5f9; }

        .panel-actions-row { display: flex; justify-content: center; align-items: center; gap: 1rem; margin-top: 0.5rem; }
        .btn-modify {
            background-color: #94a3b8;
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 700;
            padding: 0.5rem 2.5rem;
            border: none;
            border-radius: 6px;
            cursor: not-allowed;
            transition: all 0.2s ease;
        }
        .btn-modify.is-active { background-color: #2563eb; cursor: pointer; }
        .btn-modify.is-active:hover { background-color: #1d4ed8; }

        /* Report Export Controls */
        .report-checkbox-group { display: flex; flex-direction: column; gap: 0.85rem; }
        .report-item-block { display: flex; flex-direction: column; gap: 4px; }
        .report-option-row { display: flex; align-items: center; gap: 12px; font-size: 0.9rem; font-weight: 600; color: #1e293b; cursor: pointer; }
        .report-option-row input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: #0f172a; flex-shrink: 0; }
        
        .org-select-inline { display: none; position: relative; width: 220px; flex-shrink: 0; }
        .org-select-inline.visible { display: inline-block; }

        .date-range-container { display: none; align-items: center; gap: 8px; margin-left: 28px; margin-top: 4px; }
        .date-range-container.visible { display: flex; }
        .date-input { padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.85rem; background: #ffffff; }

        .btn-export-large {
            background-color: #0f172a;
            color: #ffffff;
            font-size: 1rem;
            font-weight: 800;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease;
            text-align: center;
            display: block;
            width: 100%;
            max-width: 320px;
            margin: 0.5rem auto 0 auto;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .btn-export-large:hover { background-color: #334155; transform: translateY(-1px); }

        /* Modals */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(2px);
            z-index: 3000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.is-visible { display: flex; }
        
        .confirm-modal {
            background: #ffffff;
            border-radius: 12px;
            width: 420px;
            max-width: 90%;
            padding: 1.5rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .add-modal {
            background: #ffffff;
            border-radius: 12px;
            width: 950px;
            height: 80vh;
            max-width: 95%;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid #cbd5e1;
        }
        .add-modal-body { flex: 1; overflow-y: auto; margin-top: 1rem; margin-bottom: 1rem; padding-right: 6px; }
        
        .counter-container { display: flex; gap: 1rem; align-items: center; margin-bottom: 1rem; justify-content: center; }
        .counter-box-wrapper { display: flex; flex-direction: column; align-items: center; }
        .spinner-btn { background: #f1f5f9; border: 1px solid #cbd5e1; cursor: pointer; width: 45px; height: 26px; font-weight: bold; font-size: 1rem; user-select: none; }
        .spinner-btn:hover { background: #e2e8f0; }
        .spinner-input { width: 45px; text-align: center; font-size: 1.4rem; font-weight: bold; border: 1px solid #cbd5e1; padding: 4px; margin: 2px 0; color: #000000; background: #ffffff; }

        .add-row-item { display: grid; grid-template-columns: 1fr 2fr 1fr 1fr 1.5fr 1.5fr 1fr; gap: 8px; align-items: center; margin-bottom: 8px; background: #f8fafc; padding: 8px; border-radius: 6px; border: 1px solid #e2e8f0; }
        .add-row-item input, .add-row-item select { width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.85rem; color: #000000; background: #ffffff; }

        .modal-title { font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-bottom: 0.5rem; }
        .modal-message { font-size: 0.95rem; color: #475569; margin-bottom: 1rem; line-height: 1.4; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 0.75rem; flex-shrink: 0; }
        .btn-modal-cancel { padding: 0.5rem 1.25rem; border-radius: 6px; border: 1px solid #cbd5e1; background-color: #ffffff; color: #475569; font-size: 0.88rem; font-weight: 600; cursor: pointer; }
        .btn-modal-cancel:hover { background-color: #f8fafc; }
        .btn-modal-confirm { padding: 0.5rem 1.25rem; border-radius: 6px; border: none; background-color: #2563eb; color: #ffffff; font-size: 0.88rem; font-weight: 700; cursor: pointer; }
        .btn-modal-confirm:hover { background-color: #1d4ed8; }
    </style>
</head>
<body>

    <div id="hoverTooltip" class="hover-tooltip"></div>
    
    <img src="add.png" alt="Add Users" class="add-floating-btn" id="btnAddUserOpen" title="Add New Users">

    <header>
        <div class="header-content">
            <div class="header-left">
                <img src="logo.png" alt="ECAMS Logo" class="logo">
                <div class="header-titles">
                    <h1>Extracurricular Activity Management System</h1>
                    <div class="sub-header-row">
                        <p>Efficient, Exquisite, Expertly-crafted ECA managing platform</p>
                        <a href="logout.php" class="btn-logout">Logout</a>
                    </div>
                </div>
            </div>
            <div class="clock-widget">
                <div class="clock-time" id="live-time">00:00:00 AM</div>
                <div class="clock-date" id="live-date">Loading date...</div>
            </div>
        </div>
    </header>

    <div class="app-body">
        <aside class="side-panel">
            <a href="a-org.php" class="nav-btn nav-btn-org">
                <img src="org.png" alt="Organization" class="btn-icon">
            </a>
            <a href="a-activity.php" class="nav-btn nav-btn-activity">
                <img src="act.png" alt="Activity" class="btn-icon">
            </a>
            <a href="admin.php" class="nav-btn nav-btn-user is-active">
                <img src="use.png" alt="User" class="btn-icon">
            </a>
        </aside>

        <main>
            <div class="content-wrapper">
                <div class="filter-controls">
                    <input type="text" id="searchInput" class="search-box" placeholder="Search by name or UID...">
                    
                    <select id="classFilter" class="class-select">
                        <option value="ALL">Display All Classes</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= htmlspecialchars($cls) ?>">Class <?= htmlspecialchars($cls) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="user-container" id="userContainer">
                    <div class="table-header">
                        <div data-sort="uid" class="sorted-asc">UID</div>
                        <div data-sort="name">NAME</div>
                        <div data-sort="class">CLASS</div>
                        <div data-sort="cid">CID</div>
                    </div>

                    <div id="rowsWrapper">
                        <?php if (!empty($users)): ?>
                            <?php foreach ($users as $u): ?>
                                <div class="user-row" 
                                     data-uid="<?= htmlspecialchars($u['uid'] ?? '') ?>" 
                                     data-name="<?= htmlspecialchars($u['name'] ?? '') ?>" 
                                     data-class="<?= htmlspecialchars($u['class'] ?? '') ?>"
                                     data-cid="<?= htmlspecialchars($u['cid'] ?? '') ?>"
                                     data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
                                     data-role="<?= htmlspecialchars($u['role'] ?? '') ?>"
                                     data-orgs='<?= htmlspecialchars(json_encode($u['organisations'] ?? []), ENT_QUOTES, 'UTF-8') ?>'>
                                    
                                    <div class="user-field-value col-uid"><?= htmlspecialchars($u['uid'] ?? 'N/A') ?></div>
                                    <div class="user-field-value col-name"><?= htmlspecialchars($u['name'] ?? 'N/A') ?></div>
                                    <div class="user-field-value col-class"><?= htmlspecialchars($u['class'] ?? 'N/A') ?></div>
                                    <div class="user-field-value col-cid"><?= htmlspecialchars($u['cid'] ?? 'N/A') ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sliding Panel -->
            <div class="sliding-panel" id="detailPanel">
                <button class="close-arrow-btn" id="closePanelBtn" title="Close Panel">
                    <img src="arrow.png" alt="Back Arrow" class="arrow-img">
                </button>

                <div class="panel-content">
                    <!-- UPPER HALF: MODIFY -->
                    <div class="panel-upper-half">
                        <div class="section-title">Modify</div>
                        
                        <!-- Line 1: Basic Info -->
                        <div class="form-row-1">
                            <div class="field-group">
                                <label class="field-label">uId</label>
                                <input type="text" id="panelUid" class="input-box" readonly>
                            </div>
                            <div class="field-group">
                                <label class="field-label">Name</label>
                                <input type="text" id="panelName" class="input-box input-box-large">
                            </div>
                            <div class="field-group">
                                <label class="field-label">Class</label>
                                <input type="text" id="panelClass" class="input-box input-box-large">
                            </div>
                            <div class="field-group">
                                <label class="field-label">cid</label>
                                <input type="text" id="panelCid" class="input-box">
                            </div>
                        </div>

                        <!-- Line 2: Email, Password, Live Hash, and Role Fields -->
                        <div class="form-row-2">
                            <div class="field-group">
                                <label class="field-label">Email</label>
                                <input type="email" id="panelEmail" class="input-box">
                            </div>
                            
                            <div class="field-group">
                                <label class="field-label">New Password (Plaintext)</label>
                                <input type="password" id="panelPassword" class="input-box" placeholder="Type new password...">
                            </div>

                            <div class="field-group">
                                <label class="field-label">Live Hashed Password (PHP Default)</label>
                                <input type="text" id="panelHashedPassword" class="input-box" readonly placeholder="Hashed output appears here...">
                            </div>

                            <div class="field-group">
                                <label class="field-label">Role</label>
                                <input type="text" id="panelRole" class="input-box">
                            </div>
                        </div>

                        <!-- Line 3: Compact Organisations Checklist -->
                        <div class="field-group">
                            <label class="field-label" id="panelOrgLabel">Organisations Enrolled:</label>
                            <div class="dropdown-checklist-container">
                                <div class="checklist-anchor" id="checklistAnchor">
                                    <span id="checklistLabel">Select Organisations...</span>
                                    <span>▼</span>
                                </div>
                                <div class="checklist-dropdown" id="checklistDropdown">
                                    <?php foreach ($allOrganisations as $org): ?>
                                        <label class="checklist-item">
                                            <input type="checkbox" class="org-checkbox" value="<?= htmlspecialchars($org['oid']) ?>">
                                            <span><?= htmlspecialchars($org['oid']) ?> - <?= htmlspecialchars($org['oname']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Line 4: Modify & Delete Buttons Grouped Together -->
                        <div class="panel-actions-row">
                            <button type="button" class="btn-modify" id="btnModify" disabled>Modify</button>
                            <button type="button" class="btn-delete-user" id="btnDeleteUser">Delete User</button>
                        </div>
                    </div>

                    <!-- LOWER HALF: EXPORT REPORTS -->
                    <div class="panel-lower-half" id="exportSectionContainer" style="display:none;">
                        <div class="section-title">Export Reports</div>
                        
                        <form id="exportForm" method="POST" action="">
                            <input type="hidden" name="action" value="export_pdf">
                            <input type="hidden" name="export_uid" id="formExportUid">

                            <div class="report-checkbox-group">
                                <!-- Option 1: Attendance -->
                                <div class="report-item-block">
                                    <div class="report-option-row">
                                        <input type="checkbox" id="chkAttendance" name="opt_attendance" value="1">
                                        <span>Attendance Record</span>
                                        <div class="org-select-inline" id="attOrgWrapper">
                                            <div class="checklist-anchor" id="attChecklistAnchor">
                                                <span id="attChecklistLabel">Select Orgs...</span>
                                                <span>▼</span>
                                            </div>
                                            <div class="checklist-dropdown" id="attChecklistDropdown"></div>
                                        </div>
                                    </div>
                                    <div class="date-range-container" id="dateRangeWrapper">
                                        <span class="field-label">From:</span>
                                        <input type="date" id="exportStartDate" name="export_start" class="date-input" value="2026-09-01">
                                        <span class="field-label">To:</span>
                                        <input type="date" id="exportEndDate" name="export_end" class="date-input">
                                    </div>
                                </div>

                                <!-- Option 2: Participation -->
                                <div class="report-item-block">
                                    <div class="report-option-row">
                                        <input type="checkbox" id="chkParticipation" name="opt_participation" value="1">
                                        <span>Average Participation Rating</span>
                                        <div class="org-select-inline" id="partOrgWrapper">
                                            <div class="checklist-anchor" id="partChecklistAnchor">
                                                <span id="partChecklistLabel">Select Orgs...</span>
                                                <span>▼</span>
                                            </div>
                                            <div class="checklist-dropdown" id="partChecklistDropdown"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Option 3: Satisfactory -->
                                <div class="report-item-block">
                                    <div class="report-option-row">
                                        <input type="checkbox" id="chkSatisfactory" name="opt_satisfactory" value="1">
                                        <span>Average Satisfactory Rating</span>
                                        <div class="org-select-inline" id="satOrgWrapper">
                                            <div class="checklist-anchor" id="satChecklistAnchor">
                                                <span id="satChecklistLabel">Select Orgs...</span>
                                                <span>▼</span>
                                            </div>
                                            <div class="checklist-dropdown" id="satChecklistDropdown"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Select All Option -->
                                <label class="report-option-row" style="margin-top: 6px; border-top: 1px dashed #cbd5e1; padding-top: 8px;">
                                    <input type="checkbox" id="chkSelectAll">
                                    <span style="font-weight: 800; color: #2563eb;">Select All Options</span>
                                </label>
                            </div>

                            <button type="button" class="btn-export-large" id="btnExportPdf">Export PDF</button>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Centered Modal Confirmation Overlay -->
    <div class="modal-overlay" id="confirmModal">
        <div class="confirm-modal">
            <div class="modal-title" id="modalTitle">Confirm Action</div>
            <div class="modal-message" id="modalMessage">Are you sure you want to proceed?</div>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" id="btnModalCancel">Cancel</button>
                <button type="button" class="btn-modal-confirm" id="btnModalConfirm">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Fixed Maximum Size Scrollable Add User Modal -->
    <div class="modal-overlay" id="addUserModal">
        <div class="add-modal">
            <div class="modal-title">Add New Users</div>
            <div class="modal-message">Select how many user entries you wish to add:</div>
            
            <div class="counter-container">
                <div class="counter-box-wrapper">
                    <button type="button" class="spinner-btn" id="tensUp">▲</button>
                    <input type="text" id="tensInput" class="spinner-input" value="0" readonly>
                    <button type="button" class="spinner-btn" id="tensDown">▼</button>
                </div>
                <div class="counter-box-wrapper">
                    <button type="button" class="spinner-btn" id="unitsUp">▲</button>
                    <input type="text" id="unitsInput" class="spinner-input" value="0" readonly>
                    <button type="button" class="spinner-btn" id="unitsDown">▼</button>
                </div>
            </div>

            <div id="addRowsContainer" class="add-modal-body">
                <!-- Dynamic input rows render here -->
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" id="btnAddCancel">Cancel</button>
                <button type="button" class="btn-modal-confirm" id="btnAddSave" style="display:none;">Save Users</button>
            </div>
        </div>
    </div>

    <script>
        const existingUsers = <?php echo json_encode($existingUsersData); ?>;

        function updateClock() {
            const now = new Date();
            document.getElementById('live-time').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            document.getElementById('live-date').textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        }
        updateClock();
        setInterval(updateClock, 1000);

        let userRows = Array.from(document.querySelectorAll('.user-row'));
        const rowsWrapper = document.getElementById('rowsWrapper');
        const tooltip = document.getElementById('hoverTooltip');
        const searchInput = document.getElementById('searchInput');
        const classFilter = document.getElementById('classFilter');
        
        const detailPanel = document.getElementById('detailPanel');
        const closePanelBtn = document.getElementById('closePanelBtn');
        const btnDeleteUser = document.getElementById('btnDeleteUser');
        const btnModify = document.getElementById('btnModify');

        const panelUid = document.getElementById('panelUid');
        const panelName = document.getElementById('panelName');
        const panelClass = document.getElementById('panelClass');
        const panelCid = document.getElementById('panelCid');
        const panelEmail = document.getElementById('panelEmail');
        const panelPassword = document.getElementById('panelPassword');
        const panelHashedPassword = document.getElementById('panelHashedPassword');
        const panelRole = document.getElementById('panelRole');
        const panelOrgLabel = document.getElementById('panelOrgLabel');
        const exportSectionContainer = document.getElementById('exportSectionContainer');

        const checklistAnchor = document.getElementById('checklistAnchor');
        const checklistDropdown = document.getElementById('checklistDropdown');
        const checklistLabel = document.getElementById('checklistLabel');
        const orgCheckboxes = document.querySelectorAll('.org-checkbox');

        const confirmModal = document.getElementById('confirmModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalMessage = document.getElementById('modalMessage');
        const btnModalCancel = document.getElementById('btnModalCancel');
        const btnModalConfirm = document.getElementById('btnModalConfirm');

        const addUserModal = document.getElementById('addUserModal');
        const btnAddUserOpen = document.getElementById('btnAddUserOpen');
        const btnAddCancel = document.getElementById('btnAddCancel');
        const tensInput = document.getElementById('tensInput');
        const unitsInput = document.getElementById('unitsInput');
        const tensUp = document.getElementById('tensUp');
        const tensDown = document.getElementById('tensDown');
        const unitsUp = document.getElementById('unitsUp');
        const unitsDown = document.getElementById('unitsDown');
        const addRowsContainer = document.getElementById('addRowsContainer');

        const chkAttendance = document.getElementById('chkAttendance');
        const chkParticipation = document.getElementById('chkParticipation');
        const chkSatisfactory = document.getElementById('chkSatisfactory');
        const chkSelectAll = document.getElementById('chkSelectAll');

        const attOrgWrapper = document.getElementById('attOrgWrapper');
        const partOrgWrapper = document.getElementById('partOrgWrapper');
        const satOrgWrapper = document.getElementById('satOrgWrapper');

        const attChecklistAnchor = document.getElementById('attChecklistAnchor');
        const attChecklistDropdown = document.getElementById('attChecklistDropdown');
        const attChecklistLabel = document.getElementById('attChecklistLabel');

        const partChecklistAnchor = document.getElementById('partChecklistAnchor');
        const partChecklistDropdown = document.getElementById('partChecklistDropdown');
        const partChecklistLabel = document.getElementById('partChecklistLabel');

        const satChecklistAnchor = document.getElementById('satChecklistAnchor');
        const satChecklistDropdown = document.getElementById('satChecklistDropdown');
        const satChecklistLabel = document.getElementById('satChecklistLabel');

        const dateRangeWrapper = document.getElementById('dateRangeWrapper');
        const btnExportPdf = document.getElementById('btnExportPdf');
        const exportStartDate = document.getElementById('exportStartDate');
        const exportEndDate = document.getElementById('exportEndDate');
        const exportForm = document.getElementById('exportForm');
        const formExportUid = document.getElementById('formExportUid');

        // Sorting Logic
        let currentSortField = 'uid';
        let currentSortAsc = true;

        function sortTable(field, asc = true) {
            currentSortField = field;
            currentSortAsc = asc;

            userRows.sort((a, b) => {
                let valA = (a.getAttribute('data-' + field) || '').toLowerCase();
                let valB = (b.getAttribute('data-' + field) || '').toLowerCase();

                if (field === 'uid' || field === 'cid') {
                    let numA = parseFloat(valA);
                    let numB = parseFloat(valB);
                    if (!isNaN(numA) && !isNaN(numB)) {
                        return asc ? numA - numB : numB - numA;
                    }
                }

                if (valA < valB) return asc ? -1 : 1;
                if (valA > valB) return asc ? 1 : -1;
                return 0;
            });

            userRows.forEach(row => rowsWrapper.appendChild(row));
        }

        document.querySelectorAll('.table-header div').forEach(header => {
            header.addEventListener('click', () => {
                const field = header.getAttribute('data-sort');
                if (currentSortField === field) {
                    currentSortAsc = !currentSortAsc;
                } else {
                    currentSortField = field;
                    currentSortAsc = true;
                }

                document.querySelectorAll('.table-header div').forEach(h => {
                    h.classList.remove('sorted-asc', 'sorted-desc');
                });
                header.classList.add(currentSortAsc ? 'sorted-asc' : 'sorted-desc');

                sortTable(currentSortField, currentSortAsc);
            });
        });

        // Initial default sort by UID ascending
        sortTable('uid', true);

        // Toggle drop-down visibility when individual checkboxes are clicked
        chkAttendance.addEventListener('change', () => {
            if (chkAttendance.checked) {
                attOrgWrapper.classList.add('visible');
                dateRangeWrapper.classList.add('visible');
            } else {
                attOrgWrapper.classList.remove('visible');
                dateRangeWrapper.classList.remove('visible');
            }
        });

        chkParticipation.addEventListener('change', () => {
            if (chkParticipation.checked) {
                partOrgWrapper.classList.add('visible');
            } else {
                partOrgWrapper.classList.remove('visible');
            }
        });

        chkSatisfactory.addEventListener('change', () => {
            if (chkSatisfactory.checked) {
                satOrgWrapper.classList.add('visible');
            } else {
                satOrgWrapper.classList.remove('visible');
            }
        });

        chkSelectAll.addEventListener('change', () => {
            const isChecked = chkSelectAll.checked;
            chkAttendance.checked = isChecked;
            chkParticipation.checked = isChecked;
            chkSatisfactory.checked = isChecked;

            if (isChecked) {
                attOrgWrapper.classList.add('visible');
                partOrgWrapper.classList.add('visible');
                satOrgWrapper.classList.add('visible');
                dateRangeWrapper.classList.add('visible');
            } else {
                attOrgWrapper.classList.remove('visible');
                partOrgWrapper.classList.remove('visible');
                satOrgWrapper.classList.remove('visible');
                dateRangeWrapper.classList.remove('visible');
            }
        });

        // Helper to setup multi-select checklists for inline sub-dropdowns allowing both ALL and specific orgs
        function setupSubChecklist(anchor, dropdown, labelElem, userOrgs, prefix) {
            dropdown.innerHTML = `
                <label class="checklist-item">
                    <input type="checkbox" class="${prefix}-org-cb" value="ALL" checked>
                    <span>All Organisations</span>
                </label>
            `;
            userOrgs.forEach(o => {
                dropdown.innerHTML += `
                    <label class="checklist-item">
                        <input type="checkbox" class="${prefix}-org-cb" value="${o.oid}">
                        <span>${o.oid} - ${o.oname}</span>
                    </label>
                `;
            });

            const checkboxes = dropdown.querySelectorAll(`.${prefix}-org-cb`);
            const allCb = dropdown.querySelector(`input[value="ALL"]`);

            checkboxes.forEach(cb => {
                cb.addEventListener('change', () => {
                    updateSubSummary(checkboxes, labelElem, allCb);
                });
            });

            anchor.onclick = (e) => {
                e.stopPropagation();
                dropdown.classList.toggle('visible');
            };

            updateSubSummary(checkboxes, labelElem, allCb);
        }

        function updateSubSummary(checkboxes, labelElem, allCb) {
            const checked = Array.from(checkboxes).filter(cb => cb.checked);
            if (checked.length === 0) {
                allCb.checked = true;
                labelElem.textContent = "All Organisations";
            } else if (allCb.checked && checked.length > 1) {
                labelElem.textContent = `All Organisations + ${checked.length - 1} Org(s)`;
            } else if (allCb.checked) {
                labelElem.textContent = "All Organisations";
            } else {
                labelElem.textContent = `${checked.length} Selected`;
            }
        }

        document.addEventListener('click', (e) => {
            if (!attChecklistAnchor.contains(e.target) && !attChecklistDropdown.contains(e.target)) attChecklistDropdown.classList.remove('visible');
            if (!partChecklistAnchor.contains(e.target) && !partChecklistDropdown.contains(e.target)) partChecklistDropdown.classList.remove('visible');
            if (!satChecklistAnchor.contains(e.target) && !satChecklistDropdown.contains(e.target)) satChecklistDropdown.classList.remove('visible');
        });
        
        let onConfirmCallback = null;
        let onCancelCallback = null;
        let activeRow = null;
        let initialData = {};

        panelPassword.addEventListener('input', async () => {
            const val = panelPassword.value;
            checkFormChanged();
            if (!val) {
                panelHashedPassword.value = '';
                return;
            }
            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'hash_preview', password: val })
                });
                const data = await res.json();
                if (data.success) {
                    panelHashedPassword.value = data.hash;
                }
            } catch (err) {
                console.error('Hash preview failed', err);
            }
        });

        function attachRowListeners() {
            userRows.forEach(row => {
                row.removeEventListener('mousemove', row._mouseMoveHandler);
                row.removeEventListener('mouseleave', row._mouseLeaveHandler);
                row.removeEventListener('click', row._clickHandler);

                row._mouseMoveHandler = (e) => {
                    const name = row.getAttribute('data-name') || 'N/A';
                    const cls = row.getAttribute('data-class') || 'N/A';
                    const cid = row.getAttribute('data-cid') || 'N/A';
                    const role = (row.getAttribute('data-role') || 'student').toLowerCase();
                    
                    let orgs = [];
                    try { orgs = JSON.parse(row.getAttribute('data-orgs') || "[]"); } catch (err) { orgs = []; }
                    
                    const orgTitle = role === 'student' ? 'Organisations Enrolled' : 'Organisations Managed';
                    
                    let orgListHTML = '';
                    if (orgs.length > 0) {
                        orgListHTML = orgs.map((o, index) => `${index + 1}. ${o.oname}`).join('<br>');
                    } else {
                        orgListHTML = 'None';
                    }

                    tooltip.innerHTML = `
                        <strong>${name}</strong> &nbsp; <strong>Class ${cls}</strong> &nbsp; <strong>CID: ${cid}</strong>
                        <hr style="border:0; border-top:1px solid #cbd5e1; margin:8px 0;">
                        <div style="font-weight:700; margin-bottom:4px; color:#475569;">${orgTitle}:</div>
                        <div style="padding-left:4px;">${orgListHTML}</div>
                    `;
                    
                    tooltip.style.display = 'block';
                    tooltip.style.left = (e.clientX + 15) + 'px';
                    tooltip.style.top = (e.clientY + 15) + 'px';
                };

                row._mouseLeaveHandler = () => {
                    tooltip.style.display = 'none';
                };

                row._clickHandler = () => {
                    activeRow = row;
                    tooltip.style.display = 'none';

                    const uid = row.getAttribute('data-uid');
                    const roleVal = (row.getAttribute('data-role') || 'student').toLowerCase();

                    panelUid.value = uid;
                    formExportUid.value = uid;
                    panelName.value = row.getAttribute('data-name');
                    panelClass.value = row.getAttribute('data-class');
                    panelCid.value = row.getAttribute('data-cid');
                    panelEmail.value = row.getAttribute('data-email');
                    
                    panelPassword.value = '';
                    panelHashedPassword.value = '';
                    
                    if (roleVal === 'student') {
                        exportSectionContainer.style.display = 'flex';
                    } else {
                        exportSectionContainer.style.display = 'none';
                    }

                    chkAttendance.checked = false;
                    chkParticipation.checked = false;
                    chkSatisfactory.checked = false;
                    chkSelectAll.checked = false;
                    
                    attOrgWrapper.classList.remove('visible');
                    partOrgWrapper.classList.remove('visible');
                    satOrgWrapper.classList.remove('visible');
                    dateRangeWrapper.classList.remove('visible');

                    exportStartDate.value = '2026-09-01';
                    exportEndDate.value = new Date().toISOString().split('T')[0];

                    panelRole.value = row.getAttribute('data-role') || 'student';
                    
                    if (roleVal === 'student') {
                        panelOrgLabel.textContent = 'Organisations Enrolled:';
                    } else {
                        panelOrgLabel.textContent = 'Organisations Managed:';
                    }

                    let userOrgs = [];
                    try { userOrgs = JSON.parse(row.getAttribute('data-orgs') || "[]"); } catch (e) { userOrgs = []; }
                    const enrolledOids = userOrgs.map(o => String(o.oid));

                    setupSubChecklist(attChecklistAnchor, attChecklistDropdown, attChecklistLabel, userOrgs, 'att');
                    setupSubChecklist(partChecklistAnchor, partChecklistDropdown, partChecklistLabel, userOrgs, 'part');
                    setupSubChecklist(satChecklistAnchor, satChecklistDropdown, satChecklistLabel, userOrgs, 'sat');

                    orgCheckboxes.forEach(cb => {
                        cb.checked = enrolledOids.includes(cb.value);
                    });

                    updateChecklistSummary();
                    initialData = getCurrentState();
                    resetModifyButton();

                    detailPanel.classList.add('is-open');
                };

                row.addEventListener('mousemove', row._mouseMoveHandler);
                row.addEventListener('mouseleave', row._mouseLeaveHandler);
                row.addEventListener('click', row._clickHandler);
            });
        }
        attachRowListeners();

        panelRole.addEventListener('input', () => {
            const roleVal = panelRole.value.trim().toLowerCase();
            if (roleVal === 'student') {
                panelOrgLabel.textContent = 'Organisations Enrolled:';
                exportSectionContainer.style.display = 'flex';
            } else {
                panelOrgLabel.textContent = 'Organisations Managed:';
                exportSectionContainer.style.display = 'none';
            }
            checkFormChanged();
        });

        function filterUsers() {
            const query = searchInput.value.toLowerCase().trim();
            const selectedClass = classFilter.value;

            userRows.forEach(row => {
                const uid = (row.getAttribute('data-uid') || '').toLowerCase();
                const name = (row.getAttribute('data-name') || '').toLowerCase();
                const cls = (row.getAttribute('data-class') || '');

                const matchesSearch = uid.includes(query) || name.includes(query);
                const matchesClass = selectedClass === 'ALL' || cls === selectedClass;

                if (matchesSearch && matchesClass) {
                    row.style.display = 'grid';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        searchInput.addEventListener('input', filterUsers);
        classFilter.addEventListener('change', filterUsers);

        function showConfirmModal(title, message, isDanger, confirmCb, cancelCb = null) {
            modalTitle.textContent = title;
            modalMessage.textContent = message;
            if (isDanger) {
                btnModalConfirm.classList.add('danger');
            } else {
                btnModalConfirm.classList.remove('danger');
            }
            onConfirmCallback = confirmCb;
            onCancelCallback = cancelCb;
            confirmModal.classList.add('is-visible');
        }

        btnModalCancel.addEventListener('click', () => {
            confirmModal.classList.remove('is-visible');
            if (onCancelCallback) onCancelCallback();
            onConfirmCallback = null;
            onCancelCallback = null;
        });

        btnModalConfirm.addEventListener('click', () => {
            confirmModal.classList.remove('is-visible');
            if (onConfirmCallback) onConfirmCallback();
            onConfirmCallback = null;
            onCancelCallback = null;
        });

        checklistAnchor.addEventListener('click', () => {
            checklistDropdown.classList.toggle('visible');
        });

        document.addEventListener('click', (e) => {
            if (!checklistAnchor.contains(e.target) && !checklistDropdown.contains(e.target)) {
                checklistDropdown.classList.remove('visible');
            }
        });

        function updateChecklistSummary() {
            const checkedCount = document.querySelectorAll('.org-checkbox:checked').length;
            checklistLabel.textContent = checkedCount > 0 ? `${checkedCount} Organisation(s) Selected` : 'Select Organisations...';
        }

        orgCheckboxes.forEach(cb => {
            cb.addEventListener('change', () => {
                updateChecklistSummary();
                checkFormChanged();
            });
        });

        function getCurrentState() {
            const checkedOrgs = Array.from(document.querySelectorAll('.org-checkbox:checked')).map(cb => cb.value).sort();
            return {
                name: panelName.value,
                class: panelClass.value,
                cid: panelCid.value,
                email: panelEmail.value,
                password: panelPassword.value,
                role: panelRole.value,
                orgs: checkedOrgs.join(',')
            };
        }

        function checkFormChanged() {
            const isChanged = JSON.stringify(getCurrentState()) !== JSON.stringify(initialData);
            if (isChanged) {
                btnModify.classList.add('is-active');
                btnModify.disabled = false;
            } else {
                resetModifyButton();
            }
        }

        function resetModifyButton() {
            btnModify.classList.remove('is-active');
            btnModify.disabled = true;
        }

        function resetFieldsToInitial() {
            panelName.value = initialData.name;
            panelClass.value = initialData.class;
            panelCid.value = initialData.cid;
            panelEmail.value = initialData.email;
            panelPassword.value = initialData.password;
            panelHashedPassword.value = '';
            panelRole.value = initialData.role;

            const roleVal = initialData.role.trim().toLowerCase();
            if (roleVal === 'student') {
                panelOrgLabel.textContent = 'Organisations Enrolled:';
            } else {
                panelOrgLabel.textContent = 'Organisations Managed:';
            }

            const initialOrgs = initialData.orgs ? initialData.orgs.split(',') : [];
            orgCheckboxes.forEach(cb => {
                cb.checked = initialOrgs.includes(cb.value);
            });

            updateChecklistSummary();
            resetModifyButton();
        }

        [panelName, panelClass, panelCid, panelEmail, panelRole].forEach(input => {
            input.addEventListener('input', checkFormChanged);
        });

        closePanelBtn.addEventListener('click', () => {
            detailPanel.classList.remove('is-open');
            checklistDropdown.classList.remove('visible');
        });

        btnExportPdf.addEventListener('click', () => {
            const uid = panelUid.value;
            const isAttendanceChecked = chkAttendance.checked;
            const isParticipationChecked = chkParticipation.checked;
            const isSatisfactoryChecked = chkSatisfactory.checked;

            if (!isAttendanceChecked && !isParticipationChecked && !isSatisfactoryChecked) {
                alert('Please select at least one report option to export.');
                return;
            }

            // Dynamically inject selected sub-checkbox values as hidden fields before submitting
            const form = exportForm;
            form.querySelectorAll('.dyn-hidden-org').forEach(el => el.remove());

            const appendOrgInputs = (dropdownId, fieldName) => {
                const cbs = document.querySelectorAll(`#${dropdownId} input[type="checkbox"]:checked`);
                cbs.forEach(cb => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = fieldName + '[]';
                    inp.value = cb.value;
                    inp.className = 'dyn-hidden-org';
                    form.appendChild(inp);
                });
            };

            if (isAttendanceChecked) appendOrgInputs('attChecklistDropdown', 'opt_attendance_orgs');
            if (isParticipationChecked) appendOrgInputs('partChecklistDropdown', 'opt_participation_orgs');
            if (isSatisfactoryChecked) appendOrgInputs('satChecklistDropdown', 'opt_satisfactory_orgs');

            showConfirmModal(
                'Export PDF Report Confirmation',
                `Are you sure you want to generate and export the selected report for student UID: ${uid}?`,
                false,
                () => {
                    exportForm.submit();
                }
            );
        });

        btnModify.addEventListener('click', () => {
            if (btnModify.disabled) return;
            const uid = panelUid.value;

            showConfirmModal(
                'Modify User Confirmation',
                `Are you sure you want to save modifications for user UID: ${uid}?`,
                false,
                async () => {
                    const selectedOrgs = Array.from(document.querySelectorAll('.org-checkbox:checked')).map(cb => cb.value);

                    const payload = {
                        uid: panelUid.value,
                        name: panelName.value,
                        class: panelClass.value,
                        cid: panelCid.value,
                        email: panelEmail.value,
                        password: panelPassword.value,
                        role: panelRole.value,
                        orgs: selectedOrgs
                    };

                    try {
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });

                        const result = await response.json();

                        if (result.success) {
                            alert(`User ${uid} updated successfully.`);
                            location.reload();
                        } else {
                            alert('Failed to update database: ' + result.message);
                        }
                    } catch (err) {
                        console.error('Save failed:', err);
                        alert('An error occurred while saving user details.');
                    }
                },
                () => {
                    resetFieldsToInitial();
                }
            );
        });

        btnDeleteUser.addEventListener('click', () => {
            const uid = panelUid.value;
            showConfirmModal(
                'Delete User Confirmation',
                `Are you sure you want to PERMANENTLY delete user UID: ${uid}?`,
                true,
                async () => {
                    try {
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete', uid: uid })
                        });
                        const result = await response.json();
                        if (result.success) {
                            alert('User deleted successfully.');
                            location.reload();
                        } else {
                            alert('Failed to delete user: ' + result.message);
                        }
                    } catch (err) {
                        console.error('Delete failed:', err);
                        alert('An error occurred while deleting the user.');
                    }
                }
            );
        });

        function getTotalQty() {
            const tens = parseInt(tensInput.value) || 0;
            const units = parseInt(unitsInput.value) || 0;
            return (tens * 10) + units;
        }

        function setTotalQty(val) {
            if (val < 0) val = 0;
            if (val > 99) val = 99;
            const tens = Math.floor(val / 10);
            const units = val % 10;
            tensInput.value = tens;
            unitsInput.value = units;
            renderAddRows(val);
        }

        btnAddUserOpen.addEventListener('click', () => {
            tensInput.value = '0';
            unitsInput.value = '0';
            addRowsContainer.innerHTML = '';
            document.getElementById('btnAddSave').style.display = 'none';
            addUserModal.classList.add('is-visible');
        });

        btnAddCancel.addEventListener('click', () => {
            addUserModal.classList.remove('is-visible');
        });

        function renderAddRows(qty) {
            addRowsContainer.innerHTML = '';
            const btnAddSave = document.getElementById('btnAddSave');
            if (qty <= 0) {
                btnAddSave.style.display = 'none';
                return;
            }
            btnAddSave.style.display = 'block';

            for (let i = 0; i < qty; i++) {
                const rowDiv = document.createElement('div');
                rowDiv.className = 'add-row-item';
                rowDiv.innerHTML = `
                    <input type="text" placeholder="UID" class="new-uid" required>
                    <input type="text" placeholder="Name" class="new-name" required>
                    <input type="text" placeholder="Class" class="new-class">
                    <input type="text" placeholder="CID" class="new-cid">
                    <div class="email-group">
                        <input type="text" placeholder="username" class="new-email-prefix">
                        <span class="email-suffix">@ecams.local</span>
                    </div>
                    <input type="password" placeholder="Password" class="new-password">
                    <select class="new-role">
                        <option value="student" selected>student</option>
                        <option value="monitor">monitor</option>
                        <option value="teacher">teacher</option>
                        <option value="admin">admin</option>
                    </select>
                `;
                addRowsContainer.appendChild(rowDiv);
            }
        }

        function bindPressAndHold(btn, delta) {
            let timer = null;
            let interval = null;

            const triggerChange = () => {
                setTotalQty(getTotalQty() + delta);
            };

            const startHolding = (e) => {
                e.preventDefault();
                triggerChange();
                timer = setTimeout(() => {
                    interval = setInterval(triggerChange, 200);
                }, 400);
            };

            const stopHolding = () => {
                clearTimeout(timer);
                clearInterval(interval);
            };

            btn.addEventListener('mousedown', startHolding);
            btn.addEventListener('touchstart', startHolding);

            window.addEventListener('mouseup', stopHolding);
            window.addEventListener('touchend', stopHolding);
        }

        bindPressAndHold(unitsUp, 1);
        bindPressAndHold(unitsDown, -1);
        bindPressAndHold(tensUp, 10);
        bindPressAndHold(tensDown, -10);

        document.addEventListener('click', async (e) => {
            if (e.target && e.target.id === 'btnAddSave') {
                const rowItems = addRowsContainer.querySelectorAll('.add-row-item');
                const usersPayload = [];
                const combinationTracker = new Set();

                for (let item of rowItems) {
                    const uid = item.querySelector('.new-uid').value.trim();
                    const name = item.querySelector('.new-name').value.trim();
                    const cls = item.querySelector('.new-class').value.trim();
                    const cid = item.querySelector('.new-cid').value.trim();
                    
                    const emailPrefix = item.querySelector('.new-email-prefix').value.trim();
                    const email = emailPrefix ? emailPrefix + '@ecams.local' : '';

                    const password = item.querySelector('.new-password').value.trim();
                    const role = item.querySelector('.new-role').value;

                    if (!uid || !name) {
                        alert('Every user row must have at least a UID and Name.');
                        return;
                    }

                    if (cls && cid) {
                        const comboKey = `${cls}_${cid}`.toLowerCase();
                        
                        if (combinationTracker.has(comboKey)) {
                            alert(`Duplicate constraint violation: Class "${cls}" cannot have multiple users with CID "${cid}" in this batch.`);
                            return;
                        }
                        combinationTracker.add(comboKey);

                        const existsInDb = existingUsers.some(u => 
                            (u.class ? String(u.class).trim().toLowerCase() : '') === cls.toLowerCase() && 
                            (u.cid ? String(u.cid).trim().toLowerCase() : '') === cid.toLowerCase()
                        );
                        if (existsInDb) {
                            alert(`Duplicate constraint violation: Class "${cls}" already has a user with CID "${cid}" in the database.`);
                            return;
                        }
                    }

                    usersPayload.push({ uid, name, class: cls, cid, email, password, role });
                }

                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'batch_add', users: usersPayload })
                    });
                    const result = await response.json();
                    if (result.success) {
                        alert('New users added successfully.');
                        location.reload();
                    } else {
                        alert('Failed to add users: ' + result.message);
                    }
                } catch (err) {
                    console.error('Batch add failed:', err);
                    alert('An error occurred while adding users.');
                }
            }
        });
    </script>
</body>
</html>