<?php
// Database configuration and session parameters loaded FIRST to avoid warnings
require_once 'db.php';   

session_start();

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
    // Redirect unauthorized users to an error page or their respective dashboard
    header('Location: unauthorized.php');
    exit();
}
if (!isset($_SESSION['uid']) && !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$teacherUid = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 1;

// Fetch organisations managed by this teacher for report option filters
$managedOrgIds = [];
$managedOrgsList = [];
$mOrgStmt = $conn->prepare("SELECT oid, oname FROM organisations WHERE tid = ? ORDER BY oname ASC");
$mOrgStmt->bind_param("s", $teacherUid);
$mOrgStmt->execute();
$mOrgRes = $mOrgStmt->get_result();
while ($row = $mOrgRes->fetch_assoc()) {
    $managedOrgsList[] = $row;
    $managedOrgIds[] = $row['oid'];
}
$mOrgStmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        $chkTeachOrg = $conn->prepare("
            SELECT COUNT(*) FROM membership m 
            JOIN organisations o ON m.oid = o.oid 
            WHERE m.sid = ? AND o.tid = ?
        ");
        $chkTeachOrg->bind_param("ss", $uid, $teacherUid);
        $chkTeachOrg->execute();
        $chkTeachOrg->bind_result($teachOrgCount);
        $chkTeachOrg->fetch();
        $chkTeachOrg->close();

        if ($teachOrgCount == 0) {
            die("Unauthorized: You can only export reports for students in your managed organisations.");
        }

        $stmt = $conn->prepare("SELECT * FROM users WHERE uid = ? AND LOWER(role) = 'student'");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
        $studentRes = $stmt->get_result();
        $student = $studentRes->fetch_assoc();
        $stmt->close();

        if (!$student) {
            die("Error: PDF report generation is strictly restricted to users with the 'student' role.");
        }

        $orgStmt = $conn->prepare("
            SELECT o.oid, o.oname 
            FROM membership m 
            JOIN organisations o ON m.oid = o.oid 
            WHERE m.sid = ?
        ");
        $orgStmt->bind_param("s", $uid);
        $orgStmt->execute();
        $enrolledOrgsRes = $orgStmt->get_result();
        $enrolledOrgsRaw = $enrolledOrgsRes->fetch_all(MYSQLI_ASSOC);
        $orgStmt->close();

        $orgMap = [];
        foreach ($enrolledOrgsRaw as $o) {
            $orgMap[$o['oid']] = $o['oname'];
        }
        $allOrgIds = array_keys($orgMap);

        $getOrgActivities = function($oid) use ($conn, $start, $end) {
            $actQuery = "
                SELECT a.aid, a.oid, a.aname, a.date, a.room 
                FROM activity a 
                WHERE a.oid = ? AND a.date BETWEEN ? AND ?
                ORDER BY a.date ASC
            ";
            $actStmt = $conn->prepare($actQuery);
            $actStmt->bind_param("sss", $oid, $start, $end);
            $actStmt->execute();
            $res = $actStmt->get_result();
            $data = $res->fetch_all(MYSQLI_ASSOC);
            $actStmt->close();
            return $data;
        };

        $getAllActivities = function($targetOids) use ($conn, $start, $end) {
            if (empty($targetOids)) return [];
            $placeholders = implode(',', array_fill(0, count($targetOids), '?'));
            $actQuery = "
                SELECT a.aid, a.oid, a.aname, a.date, a.room 
                FROM activity a 
                WHERE a.oid IN ($placeholders) AND a.date BETWEEN ? AND ?
                ORDER BY a.date ASC
            ";
            $types = str_repeat('s', count($targetOids)) . 'ss';
            $params = array_merge($targetOids, [$start, $end]);
            
            $actStmt = $conn->prepare($actQuery);
            $actStmt->bind_param($types, ...$params);
            $actStmt->execute();
            $res = $actStmt->get_result();
            $data = $res->fetch_all(MYSQLI_ASSOC);
            $actStmt->close();
            return $data;
        };

        $toRoman = function($num) {
            $map = [
                1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V',
                6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X'
            ];
            return $map[$num] ?? $num;
        };

        $attendanceMap = [];
        if (!empty($allOrgIds)) {
            $attStmt = $conn->prepare("
                SELECT aid, attend, rate, `s.rate` 
                FROM attendance 
                WHERE sid = ?
            ");
            $attStmt->bind_param("s", $uid);
            $attStmt->execute();
            $attRes = $attStmt->get_result();
            while ($row = $attRes->fetch_assoc()) {
                $attendanceMap[$row['aid']] = $row;
            }
            $attStmt->close();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Student_Report_' . $uid . '.pdf"');

        $pdfCommands = [];
        $pdfCommands[] = ["font" => "F1", "size" => 16, "x" => 80, "y" => 730, "text" => "ECAMS STUDENT REPORT"];
        
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

        if ($att) {
            $pdfCommands[] = ["font" => "F1", "size" => 12, "x" => 40, "y" => $y, "text" => $toRoman($sectionCounter) . ". Attendance Record:"];
            $y -= 18;

            $hasAll = in_array('ALL', $attOrgs);
            $specificOrgs = array_diff($attOrgs, ['ALL']);
            if (empty($attOrgs)) { $hasAll = true; }

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

        if ($part && $y > 60) {
            $pdfCommands[] = ["font" => "F1", "size" => 12, "x" => 40, "y" => $y, "text" => $toRoman($sectionCounter) . ". Average Participation Rating:"];
            $y -= 18;

            $hasAll = in_array('ALL', $partOrgs);
            $specificOrgs = array_diff($partOrgs, ['ALL']);
            if (empty($partOrgs)) { $hasAll = true; }

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

        if ($sat && $y > 60) {
            $pdfCommands[] = ["font" => "F1", "size" => 12, "x" => 40, "y" => $y, "text" => $toRoman($sectionCounter) . ". Average Satisfactory Rating:"];
            $y -= 18;

            $hasAll = in_array('ALL', $satOrgs);
            $specificOrgs = array_diff($satOrgs, ['ALL']);
            if (empty($satOrgs)) { $hasAll = true; }

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
        echo "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >> endobj\n";
        echo "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Courier >> endobj\n";
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
}

$classes = [];
$classRes = $conn->query("SELECT DISTINCT class FROM users WHERE class IS NOT NULL AND class != '' ORDER BY class ASC");
if ($classRes) {
    while ($row = $classRes->fetch_assoc()) {
        $classes[] = $row['class'];
    }
}

$users = [];
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
$userRes = $conn->query($query);
if ($userRes) {
    $rawUsers = $userRes->fetch_all(MYSQLI_ASSOC);

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
        
        $isStudentInMyOrgs = false;
        if (strtolower($row['role']) === 'student') {
            foreach ($orgList as $o) {
                if (in_array($o['oid'], $managedOrgIds)) {
                    $isStudentInMyOrgs = true;
                    break;
                }
            }
        }
        $row['is_in_my_orgs'] = $isStudentInMyOrgs;

        unset($row['org_data']);
        $users[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - Teacher User Profile</title>
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
        .filter-controls { 
            display: flex; 
            gap: 1rem; 
            margin-bottom: 1.25rem; 
            align-items: center; 
            flex-shrink: 0; 
            width: 100%; 
        }
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
        
        .filter-managed-btn {
            background-color: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
            transition: all 0.2s ease;
            user-select: none;
        }
        .filter-managed-btn:hover { background-color: #f8fafc; border-color: #94a3b8; }
        .filter-managed-btn.filter-active {
            background-color: #0284c7;
            color: #ffffff;
            border-color: #0369a1;
        }

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

        .panel-upper-half { display: flex; flex-direction: column; gap: 1rem; }
        .panel-lower-half { display: flex; flex-direction: column; gap: 1rem; background-color: #f8fafc; padding: 1.25rem; border-radius: 10px; border: 1px solid #e2e8f0; }

        .form-row-1 { display: grid; grid-template-columns: 0.8fr 2.2fr 1.2fr 0.8fr; gap: 1rem; align-items: center; }
        .form-row-2 { display: grid; grid-template-columns: 1fr 1.3fr 1fr; gap: 1rem; align-items: flex-start; }

        .field-group { display: flex; flex-direction: column; gap: 0.25rem; }
        .field-label { font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; }

        .input-box {
            padding: 0.45rem 0.65rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.88rem;
            outline: none;
            background-color: #f1f5f9;
            color: #64748b;
            width: 100%;
            pointer-events: none;
        }
        .input-box-large { font-size: 1.15rem; font-weight: 800; }

        .dropdown-checklist-container { position: relative; display: inline-block; width: 60%; max-width: 350px; }
        .checklist-anchor {
            padding: 0.35rem 0.55rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.8rem;
            background: #f1f5f9;
            color: #64748b;
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
        .checklist-item { display: flex; align-items: center; gap: 8px; padding: 3px 6px; font-size: 0.78rem; color: #1e293b; pointer-events: none; }
        .checklist-item input[type="checkbox"] { pointer-events: none; }

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
            <a href="t-org.php" class="nav-btn nav-btn-org">
                <img src="org.png" alt="Organization" class="btn-icon">
            </a>
            <a href="t-activity.php" class="nav-btn nav-btn-activity">
                <img src="act.png" alt="Activity" class="btn-icon">
            </a>
            <a href="teacher.php" class="nav-btn nav-btn-user is-active">
                <img src="use.png" alt="User" class="btn-icon">
            </a>
        </aside>

        <main>
            <div class="content-wrapper">
                <div class="filter-controls">
                    <input type="text" id="searchInput" class="search-box" placeholder="Search by name or UID...">
                    <button type="button" class="filter-managed-btn" id="filterManagedBtn">Only show students in my organisations</button>
                    
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
                                     data-is-managed="<?= $u['is_in_my_orgs'] ? 'true' : 'false' ?>"
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
                    <div class="panel-upper-half">
                        <div class="section-title">View User Profile</div>
                        
                        <div class="form-row-1">
                            <div class="field-group">
                                <label class="field-label">uId</label>
                                <input type="text" id="panelUid" class="input-box" readonly>
                            </div>
                            <div class="field-group">
                                <label class="field-label">Name</label>
                                <input type="text" id="panelName" class="input-box input-box-large" readonly>
                            </div>
                            <div class="field-group">
                                <label class="field-label">Class</label>
                                <input type="text" id="panelClass" class="input-box input-box-large" readonly>
                            </div>
                            <div class="field-group">
                                <label class="field-label">cid</label>
                                <input type="text" id="panelCid" class="input-box" readonly>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="field-group">
                                <label class="field-label">Email</label>
                                <input type="email" id="panelEmail" class="input-box" readonly>
                            </div>

                            <div class="field-group">
                                <label class="field-label">Role</label>
                                <input type="text" id="panelRole" class="input-box" readonly>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label" id="panelOrgLabel">Organisations Enrolled:</label>
                            <div class="dropdown-checklist-container">
                                <div class="checklist-anchor" id="checklistAnchor">
                                    <span id="checklistLabel">Select Organisations...</span>
                                    <span>▼</span>
                                </div>
                                <div class="dropdown-checklist-container">
                                <div class="checklist-dropdown" id="checklistDropdown"></div>
                            </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel-lower-half" id="exportSectionContainer" style="display:none;">
                        <div class="section-title">Export Reports</div>
                        
                        <form id="exportForm" method="POST" action="">
                            <input type="hidden" name="action" value="export_pdf">
                            <input type="hidden" name="export_uid" id="formExportUid">

                            <div class="report-checkbox-group">
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

    <script>
        const managedTeacherOrgs = <?php echo json_encode($managedOrgsList); ?>;

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
        const filterManagedBtn = document.getElementById('filterManagedBtn');
        let isFilterManagedActive = false;
        
        const detailPanel = document.getElementById('detailPanel');
        const closePanelBtn = document.getElementById('closePanelBtn');

        const panelUid = document.getElementById('panelUid');
        const panelName = document.getElementById('panelName');
        const panelClass = document.getElementById('panelClass');
        const panelCid = document.getElementById('panelCid');
        const panelEmail = document.getElementById('panelEmail');
        const panelRole = document.getElementById('panelRole');
        const panelOrgLabel = document.getElementById('panelOrgLabel');
        const exportSectionContainer = document.getElementById('exportSectionContainer');

        const checklistAnchor = document.getElementById('checklistAnchor');
        const checklistDropdown = document.getElementById('checklistDropdown');
        const checklistLabel = document.getElementById('checklistLabel');

        const confirmModal = document.getElementById('confirmModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalMessage = document.getElementById('modalMessage');
        const btnModalCancel = document.getElementById('btnModalCancel');
        const btnModalConfirm = document.getElementById('btnModalConfirm');

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

        sortTable('uid', true);

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

        function setupSubChecklist(anchor, dropdown, labelElem, userOrgs, prefix) {
            dropdown.innerHTML = `
                <label class="checklist-item">
                    <input type="checkbox" class="${prefix}-org-cb" value="ALL" checked>
                    <span>All Managed Organisations</span>
                </label>
            `;
            managedTeacherOrgs.forEach(o => {
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
                labelElem.textContent = "All Managed Organisations";
            } else if (allCb.checked && checked.length > 1) {
                labelElem.textContent = `All Managed Orgs + ${checked.length - 1} Org(s)`;
            } else if (allCb.checked) {
                labelElem.textContent = "All Managed Organisations";
            } else {
                labelElem.textContent = `${checked.length} Selected`;
            }
        }

        document.addEventListener('click', (e) => {
            if (!attChecklistAnchor.contains(e.target) && !attChecklistDropdown.contains(e.target)) attChecklistDropdown.classList.remove('visible');
            if (!partChecklistAnchor.contains(e.target) && !partChecklistDropdown.contains(e.target)) partChecklistDropdown.classList.remove('visible');
            if (!satChecklistAnchor.contains(e.target) && !satChecklistDropdown.contains(e.target)) satChecklistDropdown.classList.remove('visible');
            if (!checklistAnchor.contains(e.target) && !checklistDropdown.contains(e.target)) checklistDropdown.classList.remove('visible');
        });
        
        let onConfirmCallback = null;
        let onCancelCallback = null;

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
                    tooltip.style.display = 'none';

                    const uid = row.getAttribute('data-uid');
                    const roleVal = (row.getAttribute('data-role') || 'student').toLowerCase();
                    const isManaged = row.getAttribute('data-is-managed') === 'true';

                    panelUid.value = uid;
                    formExportUid.value = uid;
                    panelName.value = row.getAttribute('data-name');
                    panelClass.value = row.getAttribute('data-class');
                    panelCid.value = row.getAttribute('data-cid');
                    panelEmail.value = row.getAttribute('data-email');
                    panelRole.value = row.getAttribute('data-role') || 'student';
                    
                    if (roleVal === 'student' && isManaged) {
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
                    
                    if (roleVal === 'student') {
                        panelOrgLabel.textContent = 'Organisations Enrolled:';
                    } else {
                        panelOrgLabel.textContent = 'Organisations Managed:';
                    }

                    let userOrgs = [];
                    try { userOrgs = JSON.parse(row.getAttribute('data-orgs') || "[]"); } catch (e) { userOrgs = []; }

                    // Populate the profile organisation dropdown with exactly the user's enrolled/managed orgs (non-clickable view)
                    checklistDropdown.innerHTML = '';
                    if (userOrgs.length > 0) {
                        userOrgs.forEach((o, idx) => {
                            checklistDropdown.innerHTML += `
                                <div class="checklist-item">
                                    <span>${idx + 1}. [${o.oid}] ${o.oname}</span>
                                </div>
                            `;
                        });
                        checklistLabel.textContent = `${userOrgs.length} Organisation(s)`;
                    } else {
                        checklistDropdown.innerHTML = `
                            <div class="checklist-item">
                                <span>No organisations assigned.</span>
                            </div>
                        `;
                        checklistLabel.textContent = 'None Assigned';
                    }

                    checklistAnchor.onclick = (e) => {
                        e.stopPropagation();
                        checklistDropdown.classList.toggle('visible');
                    };

                    setupSubChecklist(attChecklistAnchor, attChecklistDropdown, attChecklistLabel, userOrgs, 'att');
                    setupSubChecklist(partChecklistAnchor, partChecklistDropdown, partChecklistLabel, userOrgs, 'part');
                    setupSubChecklist(satChecklistAnchor, satChecklistDropdown, satChecklistLabel, userOrgs, 'sat');

                    detailPanel.classList.add('is-open');
                };

                row.addEventListener('mousemove', row._mouseMoveHandler);
                row.addEventListener('mouseleave', row._mouseLeaveHandler);
                row.addEventListener('click', row._clickHandler);
            });
        }
        attachRowListeners();

        function filterUsers() {
            const query = searchInput.value.toLowerCase().trim();
            const selectedClass = classFilter.value;

            userRows.forEach(row => {
                const uid = (row.getAttribute('data-uid') || '').toLowerCase();
                const name = (row.getAttribute('data-name') || '').toLowerCase();
                const cls = (row.getAttribute('data-class') || '');
                const isManaged = row.getAttribute('data-is-managed') === 'true';

                const matchesSearch = uid.includes(query) || name.includes(query);
                const matchesClass = selectedClass === 'ALL' || cls === selectedClass;
                const matchesManaged = !isFilterManagedActive || isManaged;

                if (matchesSearch && matchesClass && matchesManaged) {
                    row.style.display = 'grid';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        searchInput.addEventListener('input', filterUsers);
        classFilter.addEventListener('change', filterUsers);

        filterManagedBtn.addEventListener('click', () => {
            isFilterManagedActive = !isFilterManagedActive;
            if (isFilterManagedActive) {
                filterManagedBtn.classList.add('filter-active');
                filterManagedBtn.textContent = 'Show All Users';
            } else {
                filterManagedBtn.classList.remove('filter-active');
                filterManagedBtn.textContent = 'Only show students in my organisations';
            }
            filterUsers();
        });

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
    </script>
</body>
</html>
