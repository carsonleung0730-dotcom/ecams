<?php
// Database configuration and session parameters loaded FIRST to avoid warnings
require_once 'db.php';   

session_start();

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
    header('Location: unauthorized.php');
    exit();
}
if (!isset($_SESSION['uid']) && !isset($_SESSION['user_id'])) {
    header('Location: shit.php');
    exit();
}

// Retrieve logged-in teacher identifier from session
$teacherUid = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 1;

// Handle AJAX actions for approving or rejecting join requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'save_image') {
        $oid = preg_replace('/[^a-zA-Z0-9_-]/', '_', $_POST['oid']);
        $imageData = $_POST['image'];

        $chk = $conn->prepare("SELECT tid FROM organisations WHERE oid = ?");
        $chk->bind_param("s", $_POST['oid']);
        $chk->execute();
        $chk->bind_result($orgTid);
        $chk->fetch();
        $chk->close();

        if ($orgTid != $teacherUid) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only modify organisations you manage.']);
            exit;
        }

        if (!is_dir('uploads')) {
            mkdir('uploads', 0777, true);
        }

        list($type, $imageData) = explode(';', $imageData);
        list(, $imageData) = explode(',', $imageData);
        $decodedData = base64_decode($imageData);

        $filePath = "uploads/org_{$oid}.png";
        if (file_put_contents($filePath, $decodedData)) {
            echo json_encode(['success' => true, 'path' => $filePath]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save image file.']);
        }
        exit;
    }

    if ($action === 'delete_image') {
        $oid = preg_replace('/[^a-zA-Z0-9_-]/', '_', $_POST['oid'] ?? '');
        
        $chk = $conn->prepare("SELECT tid FROM organisations WHERE oid = ?");
        $chk->bind_param("s", $oid);
        $chk->execute();
        $chk->bind_result($orgTid);
        $chk->fetch();
        $chk->close();

        if ($orgTid != $teacherUid) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only modify organisations you manage.']);
            exit;
        }

        $filePath = "uploads/org_{$oid}.png";
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_org') {
        $oid = trim($_POST['oid'] ?? '');
        
        $chk = $conn->prepare("SELECT tid FROM organisations WHERE oid = ?");
        $chk->bind_param("s", $oid);
        $chk->execute();
        $chk->bind_result($orgTid);
        $chk->fetch();
        $chk->close();

        if ($orgTid != $teacherUid) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only delete organisations you manage.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $delMon = $conn->prepare("DELETE FROM monitor WHERE oid = ?");
            $delMon->bind_param("s", $oid);
            $delMon->execute();
            $delMon->close();

            $delMem = $conn->prepare("DELETE FROM membership WHERE oid = ?");
            $delMem->bind_param("s", $oid);
            $delMem->execute();
            $delMem->close();

            $delOrg = $conn->prepare("DELETE FROM organisations WHERE oid = ?");
            $delOrg->bind_param("s", $oid);
            $delOrg->execute();
            $delOrg->close();

            $safeOid = preg_replace('/[^a-zA-Z0-9_-]/', '_', $oid);
            $filePath = "uploads/org_{$safeOid}.png";
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'update_org') {
        $oldOid = trim($_POST['old_oid'] ?? '');
        $newOid = trim($_POST['new_oid'] ?? '');
        $oname = trim($_POST['oname'] ?? '');
        $tid = trim($_POST['tid'] ?? null);
        $members = $_POST['members'] ?? [];
        $monitors = $_POST['monitors'] ?? [];

        $chk = $conn->prepare("SELECT tid FROM organisations WHERE oid = ?");
        $chk->bind_param("s", $oldOid);
        $chk->execute();
        $chk->bind_result($orgTid);
        $chk->fetch();
        $chk->close();

        if ($orgTid != $teacherUid) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only modify organisations you manage.']);
            exit;
        }

        if (empty($newOid) || empty($oname)) {
            echo json_encode(['success' => false, 'message' => 'Organisation ID and Name are required.']);
            exit;
        }

        if ($oldOid !== $newOid) {
            $chkDup = $conn->prepare("SELECT COUNT(*) FROM organisations WHERE oid = ?");
            $chkDup->bind_param("s", $newOid);
            $chkDup->execute();
            $chkDup->bind_result($dupCount);
            $chkDup->fetch();
            $chkDup->close();

            if ($dupCount > 0) {
                echo json_encode(['success' => false, 'message' => 'Organisation ID already exists.']);
                exit;
            }
        }

        $conn->begin_transaction();
        try {
            $finalTid = empty($tid) ? null : $tid;
            if ($oldOid !== $newOid) {
                $updMonOid = $conn->prepare("UPDATE monitor SET oid = ? WHERE oid = ?");
                $updMonOid->bind_param("ss", $newOid, $oldOid);
                $updMonOid->execute();
                $updMonOid->close();

                $updMemOid = $conn->prepare("UPDATE membership SET oid = ? WHERE oid = ?");
                $updMemOid->bind_param("ss", $newOid, $oldOid);
                $updMemOid->execute();
                $updMemOid->close();

                $updOrg = $conn->prepare("UPDATE organisations SET oid = ?, oname = ?, tid = ? WHERE oid = ?");
                $updOrg->bind_param("ssss", $newOid, $oname, $finalTid, $oldOid);
                $updOrg->execute();
                $updOrg->close();

                $oldImg = "uploads/org_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $oldOid) . ".png";
                $newImg = "uploads/org_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $newOid) . ".png";
                if (file_exists($oldImg)) {
                    rename($oldImg, $newImg);
                }
            } else {
                $stmt = $conn->prepare("UPDATE organisations SET oname = ?, tid = ? WHERE oid = ?");
                $stmt->bind_param("sss", $oname, $finalTid, $oldOid);
                $stmt->execute();
                $stmt->close();
            }

            $delMon = $conn->prepare("DELETE FROM monitor WHERE oid = ?");
            $delMon->bind_param("s", $newOid);
            $delMon->execute();
            $delMon->close();

            $delMem = $conn->prepare("DELETE FROM membership WHERE oid = ?");
            $delMem->bind_param("s", $newOid);
            $delMem->execute();
            $delMem->close();

            if (!empty($members)) {
                $memStmt = $conn->prepare("INSERT INTO membership (oid, sid) VALUES (?, ?)");
                foreach ($members as $sid) {
                    $memStmt->bind_param("ss", $newOid, $sid);
                    $memStmt->execute();
                }
                $memStmt->close();
            }

            if (!empty($monitors)) {
                $monStmt = $conn->prepare("INSERT INTO monitor (oid, mid) VALUES (?, ?)");
                foreach ($monitors as $mid) {
                    $monStmt->bind_param("ss", $newOid, $mid);
                    $monStmt->execute();
                }
                $monStmt->close();
            }

            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'approve_request' || $action === 'reject_request') {
        $reqId = $_POST['req_id'] ?? '';
        $jsonFile = 'join_requests.json';
        
        if (file_exists($jsonFile)) {
            $requests = json_decode(file_get_contents($jsonFile), true) ?? [];
            $targetReq = null;
            
            foreach ($requests as $i => $req) {
                if ($req['id'] === $reqId) {
                    $targetReq = $req;
                    if ($action === 'approve_request') {
                        // Add membership in database
                        $ins = $conn->prepare("INSERT IGNORE INTO membership (oid, sid) VALUES (?, ?)");
                        $ins->bind_param("ss", $req['oid'], $req['sid']);
                        $ins->execute();
                        $ins->close();
                    }
                    // Remove request from JSON
                    array_splice($requests, $i, 1);
                    break;
                }
            }
            
            file_put_contents($jsonFile, json_encode($requests, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Request not found.']);
        exit;
    }
}

// Load pending join requests for organisations managed by this teacher
$jsonFile = 'join_requests.json';
$allRequests = [];
if (file_exists($jsonFile)) {
    $allRequests = json_decode(file_get_contents($jsonFile), true) ?? [];
}

// Get managed organization IDs for this teacher
$managedOids = [];
$mOrgRes = $conn->prepare("SELECT oid FROM organisations WHERE tid = ?");
$mOrgRes->bind_param("s", $teacherUid);
$mOrgRes->execute();
$mRes = $mOrgRes->get_result();
while ($row = $mRes->fetch_assoc()) {
    $managedOids[] = $row['oid'];
}
$mOrgRes->close();

$teacherRequests = [];
foreach ($allRequests as $req) {
    if ($req['status'] === 'pending' && in_array($req['oid'], $managedOids)) {
        // Fetch student class + cid
        $sInfo = $conn->prepare("SELECT class, cid, name FROM users WHERE uid = ?");
        $sInfo->bind_param("s", $req['sid']);
        $sInfo->execute();
        $sInfo->bind_result($sClass, $sCid, $sName);
        if ($sInfo->fetch()) {
            $req['class_str'] = trim($sClass . $sCid);
            $req['student_name'] = $sName;
        }
        $sInfo->close();
        $teacherRequests[] = $req;
    }
}

$teachersList = [];
$tRes = $conn->query("SELECT uid, name FROM users WHERE role IN ('teacher', 'admin') ORDER BY CAST(uid AS UNSIGNED) ASC, uid ASC");
if ($tRes) {
    $teachersList = $tRes->fetch_all(MYSQLI_ASSOC);
}

$studentsList = [];
$sRes = $conn->query("SELECT uid, name, class, cid FROM users WHERE role = 'student' ORDER BY CAST(uid AS UNSIGNED) ASC, uid ASC");
if ($sRes) {
    $studentsList = $sRes->fetch_all(MYSQLI_ASSOC);
}

$orgsData = [];
$orgRes = $conn->query("
    SELECT o.oid, o.oname, o.tid, u.name AS teacher_name
    FROM organisations o
    LEFT JOIN users u ON o.tid = u.uid
    ORDER BY o.oid ASC
");

if ($orgRes) {
    $orgsRaw = $orgRes->fetch_all(MYSQLI_ASSOC);

    foreach ($orgsRaw as $org) {
        $monStmt = $conn->prepare("SELECT mid FROM monitor WHERE oid = ?");
        $monStmt->bind_param("s", $org['oid']);
        $monStmt->execute();
        $monRes = $monStmt->get_result();
        $org['monitors'] = array_column($monRes->fetch_all(MYSQLI_ASSOC), 'mid');
        $monStmt->close();

        $memStmt = $conn->prepare("
            SELECT u.uid, u.name 
            FROM membership m
            JOIN users u ON m.sid = u.uid
            WHERE m.oid = ?
            ORDER BY CAST(u.uid AS UNSIGNED) ASC, u.uid ASC
        ");
        $memStmt->bind_param("s", $org['oid']);
        $memStmt->execute();
        $memRes = $memStmt->get_result();
        $org['members'] = $memRes->fetch_all(MYSQLI_ASSOC);
        $memStmt->close();
        
        usort($org['members'], function($a, $b) use ($org) {
            $aIsMon = in_array($a['uid'], $org['monitors']);
            $bIsMon = in_array($b['uid'], $org['monitors']);
            if ($aIsMon && !$bIsMon) return -1;
            if (!$aIsMon && $bIsMon) return 1;
            return strnatcmp($a['uid'], $b['uid']);
        });

        $safeOid = preg_replace('/[^a-zA-Z0-9_-]/', '_', $org['oid']);
        $existingImg = "uploads/org_{$safeOid}.png";
        $org['image_path'] = file_exists($existingImg) ? $existingImg . '?t=' . filemtime($existingImg) : '';
        
        $orgsData[] = $org;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - Teacher Organisations Overview</title>
    <link rel="icon" type="image/x-icon" href="sECAMS.png">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; margin: 0; overflow: hidden; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: #e0f2fe;
            color: #1e293b;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        header {
            background-color: #ffffff;
            border-bottom: 4px double #1037e572;
            padding: 1rem 2.5rem;
            position: relative;
            z-index: 20;
            height: 110px;
            flex-shrink: 0;
        }
        .header-content { display: flex; align-items: center; justify-content: space-between; }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .logo { height: 85px; width: auto; display: block; }
        .header-titles h1 { font-size: 1.35rem; font-weight: 700; color: #2e8ccb; }
        .sub-header-row { display: flex; align-items: center; gap: 12px; margin-top: 2px; }
        .sub-header-row p { font-size: 0.85rem; color: #64748b; }
        .btn-logout {
            background-color: #eb4174; color: #ffffff; font-size: 0.75rem;
            font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 4px; text-decoration: none;
            transition: background-color 0.2s;
            display: inline-block;
        }
        .btn-logout:hover { background-color: #9f1239; }
        .clock-widget { text-align: right; padding: 0.5rem 0.85rem; }
        .clock-time { font-size: 1.15rem; font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; }
        .clock-date { font-size: 0.75rem; font-weight: 500; color: #64748b; text-transform: uppercase; }

        .app-body { flex: 1; display: flex; height: calc(100vh - 110px); position: relative; overflow: hidden; }
        .side-panel { width: 280px; background-color: #ffffff; border-right: 4px double #1037e572; display: flex; flex-direction: column; flex-shrink: 0; }
        .nav-btn { flex: 1; display: flex; align-items: center; justify-content: center; text-decoration: none; position: relative; border: none; cursor: pointer; transition: all 0.25s ease-in-out; }
        .nav-btn.is-active::before {
            content: ''; position: absolute; top: 12px; left: 12px; width: 14px; height: 14px;
            background-color: #2563eb; border-radius: 50%; border: 2px solid #ffffff; 
            box-shadow: 0 0 8px rgba(37, 99, 235, 0.6); z-index: 10;
        }
        .side-panel .nav-btn:hover { border: 8px double #ffffff !important; z-index: 2; }
        .btn-icon { width: 280px; height: 200px; object-fit: contain; }
        .nav-btn-org { background-color: #fea768; border-bottom: 2px solid #ea580c; }
        .nav-btn-activity { background-color: #dc2626; border-bottom: 2px solid #991b1b; }
        .nav-btn-user { background-color: #881337; }
        
        main { flex: 1; padding: 1rem 2rem; background-color: #d6e6f9; overflow-x: auto; overflow-y: hidden; padding-top: 1rem; height: 100%; position: relative; }
        
        .floating-controls {
            position: fixed; top: 115px; left: 280px; right: 0; z-index: 1000;
            display: flex; gap: 6px; align-items: center; justify-content: center; pointer-events: none;
        }
        .control-box {
            background: #ffffff; border: 1px solid #cbd5e1; padding: 4px 10px; border-radius: 4px;
            font-size: 0.75rem; font-weight: 700; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            user-select: none; pointer-events: auto;
        }
        .control-box.grey { background-color: #f1f5f9; color: #94a3b8; border-color: #cbd5e1; cursor: not-allowed; }
        .control-box.blue { background-color: #2563eb; color: #ffffff; border-color: #1d4ed8; cursor: pointer; }
        
        /* Red to White Pulse Animation for View Incoming Requests Button (1 cycle per 3 seconds) */
        @keyframes redWhitePulse {
            0% { background-color: #dc2626; color: #ffffff; border-color: #991b1b; }
            50% { background-color: #ffffff; color: #dc2626; border-color: #dc2626; }
            100% { background-color: #dc2626; color: #ffffff; border-color: #991b1b; }
        }
        .requests-btn.has-requests {
            animation: redWhitePulse 3s infinite;
            cursor: pointer;
            pointer-events: auto;
        }
        .requests-btn.no-requests {
            background-color: #f1f5f9;
            color: #94a3b8;
            border-color: #cbd5e1;
            cursor: not-allowed;
            pointer-events: none;
        }

        .filter-managed-btn {
            background: #ffffff; border: 1px solid #cbd5e1; padding: 4px 10px; border-radius: 4px;
            font-size: 0.75rem; font-weight: 700; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            user-select: none; pointer-events: auto; transition: background-color 0.2s ease;
        }
        .filter-managed-btn.filter-active {
            background-color: #0284c7;
            color: #ffffff;
            border-color: #0369a1;
        }

        .orgs-horizontal-container { display: flex; gap: 1.5rem; height: 100%; align-items: flex-start; width: max-content; padding-bottom: 1rem; margin-top: 1.267rem; }
        
        .org-vertical-box {
            border-radius: 12px; border: 1px solid rgba(219, 234, 254, 0.6);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08); width: 320px; min-width: 320px;
            height: calc(100vh - 165px); display: flex; flex-direction: column; padding: 1.25rem; flex-shrink: 0;
            position: relative; backdrop-filter: blur(4px); overflow: hidden;
            background-color: rgba(52, 102, 235, 0.35);
        }
        
        .delete-arrow-btn { position: absolute; top: 6px; left: 6px; width: 20px; height: 20px; cursor: pointer; z-index: 50; display: none; }
        .org-header-section { border-bottom: 2px solid #e2e8f0; padding-bottom: 0.75rem; margin-bottom: 0.85rem; flex-shrink: 0; }
        .org-title-row { display: flex; align-items: center; justify-content: space-between; gap: 4px; margin-bottom: 0.6rem; }
        .org-title { font-size: 0.95rem; font-weight: 800; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .org-title-input { padding: 4px 6px; font-size: 0.85rem; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 4px; background-color: #ffffff; color: #1e293b; margin-bottom: 0; }
        .org-title-input:hover, .org-title-input:focus { border-color: #2563eb; outline: none; }
        .btn-upload-file {
            background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; border-radius: 4px;
            padding: 0.3rem 0.4rem; font-size: 0.65rem; font-weight: 700; cursor: pointer; text-align: center; white-space: nowrap; display: none;
        }
        .btn-upload-file:hover { background-color: #e2e8f0; color: #0f172a; border-color: #2563eb; }
        
        .image-preview-wrapper { position: relative; width: 100%; margin-bottom: 0.6rem; margin-top: 0.4rem; }
        .org-image-preview { width: 100%; aspect-ratio: 1 / 1; background-color: #f8fafc; border-radius: 6px; object-fit: cover; border: 1px solid #cbd5e1; display: block; }
        .delete-photo-btn {
            position: absolute; top: 6px; left: 6px; width: 22px; height: 22px; cursor: pointer; z-index: 10;
            display: none; background: rgba(255, 255, 255, 0.8); border-radius: 4px; padding: 2px;
        }
        .delete-photo-btn:hover { background: rgba(255, 255, 255, 1); }

        .teacher-info { font-size: 0.85rem; color: #475569; text-align: left; margin-top: 0.4rem; position: relative; }
        .teacher-name-bold { font-weight: 700; color: #1e293b; font-size: 1.1rem; }
        .teacher-input { width: 100%; padding: 6px 8px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 4px; margin-top: 4px; background-color: #ffffff; color: #1e293b; display: none; }
        .teacher-input:hover, .teacher-input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15); }
        
        .custom-dropdown-list {
            position: absolute; top: 100%; left: 0; width: 100%; background: #ffffff;
            border: 1px solid #cbd5e1; border-radius: 4px; max-height: 150px; overflow-y: auto;
            z-index: 100; display: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-top: 2px;
        }
        .custom-dropdown-item { padding: 6px 10px; font-size: 0.85rem; color: #1e293b; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .custom-dropdown-item:hover { background-color: #eff6ff; color: #2563eb; border-left: 3px solid #2563eb; }

        .members-section-title { font-size: 0.8rem; font-weight: 710; color: #5d5c5c; text-transform: uppercase; margin-bottom: 0.5rem; flex-shrink: 0; }
        .member-search-input { width: 100%; padding: 4px 8px; font-size: 0.8rem; border: 1px solid #cbd5e1; border-radius: 4px; margin-bottom: 6px; background-color: #ffffff; color: #1e293b; flex-shrink: 0; display: none; }
        .member-search-input:hover, .member-search-input:focus { border-color: #2563eb; outline: none; }
        
        .members-table-wrapper { flex: 1; overflow-y: auto; border: 1px solid #f1f5f9; border-radius: 8px; background-color: #f8fafc; min-height: 0; }
        .checklist-items-scroll { overflow-y: auto; max-height: 100%; }
        
        .members-table { width: 100%; border-collapse: collapse; text-align: left; }
        .members-table th { position: sticky; top: 0; background-color: #f1f5f9; font-size: 0.72rem; font-weight: 700; color: #475569; padding: 4px 8px; border-bottom: 1px solid #cbd5e1; z-index: 5; }
        .members-table td { padding: 4px 8px; font-size: 0.85rem; color: #1e293b; border-bottom: 1px solid #f1f5f9; }
        
        .member-row-monitor td { font-weight: bold; }
        
        .checklist-container { padding: 4px; display: flex; flex-direction: column; }
        .checklist-item { display: flex; align-items: center; gap: 8px; padding: 4px 6px; font-size: 0.85rem; color: #1e293b; border-bottom: 1px solid #f1f5f9; background-color: #ffffff; cursor: pointer; user-select: none; }
        .checklist-item:hover { background-color: #eff6ff; }
        .state-box {
            width: 16px; height: 16px; border: 1px solid #cbd5e1; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; background-color: #ffffff;
        }
        .checklist-item.state-member .state-box { background-color: #2563eb; border-color: #1d4ed8; }
        .checklist-item.state-monitor .state-box { background-color: #dc2626; border-color: #991b1b; }
        
        .crop-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background-color: rgba(15, 23, 42, 0.7); z-index: 2000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s ease;
        }
        .crop-modal-overlay.active { opacity: 1; pointer-events: auto; }
        .crop-modal-box { background-color: #ffffff; width: 90%; max-width: 360px; border-radius: 10px; padding: 1.25rem; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); text-align: center; }
        .crop-viewport { width: 260px; height: 260px; margin: 0 auto 1rem auto; background-color: #0f172a; position: relative; overflow: hidden; cursor: grab; border-radius: 4px; }
        #cropImage { position: absolute; transform-origin: top left; user-select: none; pointer-events: none; }
        .zoom-slider-con { margin-bottom: 1rem; display: flex; align-items: center; gap: 8px; font-size: 0.75rem; color: #64748b; }
        .zoom-slider-con input { flex: 1; }
        .crop-modal-actions { display: flex; gap: 0.75rem; justify-content: center; }
        .btn-modal-cancel, .btn-modal-confirm { flex: 1; padding: 0.5rem; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; color: #fff; }
        .btn-modal-cancel { background-color: #64748b; }
        .btn-modal-confirm { background-color: #2563eb; }

        /* Custom Popup Modals for Requests */
        .custom-popup-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background-color: rgba(15, 23, 42, 0.6); z-index: 3000; display: none; align-items: center; justify-content: center;
        }
        .custom-popup-overlay.active { display: flex; }
        .custom-popup-box {
            background-color: #ffffff; width: 90%; max-width: 420px; border-radius: 12px; padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); position: relative; max-height: 80vh; display: flex; flex-direction: column;
        }
        .popup-close-btn {
            position: absolute; top: 12px; right: 12px; width: 22px; height: 22px; cursor: pointer; opacity: 0.7;
        }
        .popup-close-btn:hover { opacity: 1; }
        .popup-title { font-size: 1.05rem; font-weight: 800; color: #0f172a; margin-bottom: 1rem; padding-right: 24px; }
        .requests-list-scroll { flex: 1; overflow-y: auto; max-height: 300px; display: flex; flex-direction: column; gap: 8px; }
        .request-item-card {
            background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; cursor: pointer; transition: background 0.2s;
        }
        .request-item-card:hover { background: #eff6ff; border-color: #2563eb; }
        .req-card-name { font-weight: 700; font-size: 0.9rem; color: #1e293b; }
        .req-card-sub { font-size: 0.75rem; color: #64748b; margin-top: 2px; }

        .request-reason-text { font-size: 0.9rem; color: #334155; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #cbd5e1; margin-bottom: 1rem; min-height: 80px; }
        .popup-actions { display: flex; gap: 10px; }
        .btn-approve { flex: 1; background-color: #16a34a; color: #ffffff; border: none; padding: 8px; border-radius: 6px; font-weight: 700; cursor: pointer; }
        .btn-approve:hover { background-color: #15803d; }
        .btn-reject { flex: 1; background-color: #dc2626; color: #ffffff; border: none; padding: 8px; border-radius: 6px; font-weight: 700; cursor: pointer; }
        .btn-reject:hover { background-color: #b91c1c; }

        /* Editing Mode Scoping Switchers for Managed Orgs */
        body.editing-mode .org-vertical-box[data-is-managed="true"] .delete-arrow-btn,
        body.editing-mode .org-vertical-box[data-is-managed="true"] .btn-upload-file,
        body.editing-mode .org-vertical-box[data-is-managed="true"] .org-title-edit,
        body.editing-mode .org-vertical-box[data-is-managed="true"] .teacher-input,
        body.editing-mode .org-vertical-box[data-is-managed="true"] .member-search-input,
        body.editing-mode .org-vertical-box[data-is-managed="true"] .members-view-edit,
        body.editing-mode .org-vertical-box[data-is-managed="true"].has-image .delete-photo-btn {
            display: flex !important;
        }
        body.editing-mode .org-vertical-box[data-is-managed="true"] .org-title-display,
        body.editing-mode .org-vertical-box[data-is-managed="true"] .teacher-display,
        body.editing-mode .org-vertical-box[data-is-managed="true"] .members-view-display {
            display: none !important;
        }
        body.editing-mode .org-vertical-box[data-is-managed="true"] .org-title-edit {
            display: flex !important;
            gap: 4px;
            align-items: center;
            width: 100%;
        }
    </style>
</head>
<body>

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
            <a href="teacher.php" class="nav-btn nav-btn-org is-active" id="navBtnOrg">
                <img src="org.png" alt="Organisation" class="btn-icon">
            </a>
            <a href="t-activity.php" class="nav-btn nav-btn-activity">
                <img src="act.png" alt="Activity" class="btn-icon">
            </a>
            <a href="t-user.php" class="nav-btn nav-btn-user">
                <img src="use.png" alt="User" class="btn-icon">
            </a>
        </aside>

        <main id="mainContainer">
            <div class="floating-controls">
                <div class="control-box filter-managed-btn filter-active" id="filterManagedBtn">Show All Organisations</div>
                <div class="control-box requests-btn <?= count($teacherRequests) > 0 ? 'has-requests' : 'no-requests' ?>" id="viewRequestsBtn">
                    View Incoming Requests (<?= count($teacherRequests) ?>)
                </div>
                <div class="control-box" id="editingModeBtn">Editing Mode</div>
                <div class="control-box grey" id="modifyBtn" style="display: none;">Modify</div>
            </div>

            <div class="orgs-horizontal-container">
                <?php if (!empty($orgsData)): ?>
                    <?php foreach ($orgsData as $org): 
                        $hasImage = !empty($org['image_path']);
                        $isManagedByMe = ($org['tid'] == $teacherUid);
                    ?>
                        <div class="org-vertical-box existing-org-box <?= $hasImage ? 'has-image' : '' ?>" data-oid="<?= htmlspecialchars($org['oid']) ?>" data-tid="<?= htmlspecialchars($org['tid'] ?? '') ?>" data-is-managed="<?= $isManagedByMe ? 'true' : 'false' ?>" data-original-name="<?= htmlspecialchars($org['oname']) ?>" data-original-tid="<?= htmlspecialchars($org['tid'] ?? '') ?>" style="<?= $isManagedByMe ? '' : 'display: none;' ?>">
                            <img src="arrow.png" alt="Delete" class="delete-arrow-btn delete-org-trigger" title="Delete Organisation">

                            <div class="org-header-section">
                                <div class="org-title-row">
                                    <div class="org-title-display" style="display: flex; align-items: center; width: 100%; justify-content: space-between;">
                                        <div class="org-title" title="<?= htmlspecialchars($org['oname']) ?>"><?= htmlspecialchars($org['oid']) ?> - <?= htmlspecialchars($org['oname']) ?></div>
                                    </div>
                                    <div class="org-title-edit" style="display: none; width: 100%; gap: 4px; align-items: center;">
                                        <input type="text" class="org-title-input edit-oid" value="<?= htmlspecialchars($org['oid']) ?>" placeholder="ID" style="width: 30px; flex-shrink: 0;">
                                        <input type="text" class="org-title-input edit-oname" value="<?= htmlspecialchars($org['oname']) ?>" placeholder="Name" style="width: 160px; flex-shrink: 0;">
                                    </div>
                                    <label for="file-input-<?= htmlspecialchars($org['oid']) ?>" class="btn-upload-file photo-upload-btn" style="flex-shrink: 0;">
                                        <?= $hasImage ? 'Change photo' : 'Choose photo' ?>
                                    </label>
                                </div>
                                
                                <input type="file" id="file-input-<?= htmlspecialchars($org['oid']) ?>" class="device-file-input" accept="image/*" style="display: none;">
                                
                                <div class="image-preview-wrapper" style="display: <?= $hasImage ? 'block' : 'none' ?>;">
                                    <img src="<?= htmlspecialchars($org['image_path']) ?>" alt="Preview" class="org-image-preview">
                                    <img src="arrow.png" alt="Delete Photo" class="delete-photo-btn delete-photo-trigger" title="Delete Photo">
                                </div>

                                <div class="teacher-info">
                                    <div class="teacher-display" style="text-align: center;">
                                        Teacher: <span class="teacher-name-bold"><?= htmlspecialchars($org['teacher_name'] ?? 'Unassigned') ?></span>
                                    </div>
                                    <div class="teacher-edit" style="margin-top: 4px; position: relative; display: none;">
                                        <div style="font-weight: 700; color: #475569; font-size: 0.75rem; margin-bottom: 2px;">Teacher Managing:</div>
                                        <input type="text" class="teacher-input edit-tid" value="<?= htmlspecialchars($org['tid'] ?? '') ?>" placeholder="Select or type teacher UID/name" autocomplete="off">
                                        <div class="custom-dropdown-list edit-teacher-dropdown">
                                            <?php foreach ($teachersList as $t): ?>
                                                <div class="custom-dropdown-item" data-uid="<?= htmlspecialchars($t['uid']) ?>" data-name="<?= htmlspecialchars($t['name']) ?>">
                                                    [<?= htmlspecialchars($t['uid']) ?>] <?= htmlspecialchars($t['name']) ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="members-section-title"><?= count($org['members']) ?> Members</div>
                            
                            <div class="members-table-wrapper members-view-display">
                                <?php if (!empty($org['members'])): ?>
                                    <table class="members-table">
                                        <thead>
                                            <tr>
                                                <th>UID</th>
                                                <th>Name</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($org['members'] as $member): 
                                                $isMonitor = in_array($member['uid'], $org['monitors']);
                                            ?>
                                                <tr class="<?= $isMonitor ? 'member-row-monitor' : '' ?>">
                                                    <td><?= htmlspecialchars($member['uid']) ?></td>
                                                    <td><?= htmlspecialchars($member['name']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="no-members" style="padding: 10px; font-size: 0.85rem; color: #64748b;">No members enrolled</div>
                                <?php endif; ?>
                            </div>

                            <div class="members-table-wrapper checklist-container members-view-edit" style="display: none;">
                                <input type="text" class="member-search-input" placeholder="Search student (ID, Name, Class)...">
                                <div class="checklist-items-scroll">
                                    <?php 
                                    $memberUids = array_column($org['members'], 'uid');
                                    foreach ($studentsList as $s): 
                                        $isMem = in_array($s['uid'], $memberUids);
                                        $isMon = in_array($s['uid'], $org['monitors']);
                                        $stateClass = '';
                                        if ($isMon) $stateClass = 'state-monitor';
                                        else if ($isMem) $stateClass = 'state-member';

                                        $sClass = $s['class'] ?? '';
                                        $sNum = $s['cid'] ?? '';
                                        $sCombined = $sClass . $sNum;
                                        $searchString = strtolower(trim("{$s['uid']} {$s['name']} {$sClass} {$sNum} {$sCombined}"));
                                    ?>
                                        <div class="checklist-item <?= $stateClass ?>" data-search="<?= htmlspecialchars($searchString) ?>" data-uid="<?= htmlspecialchars($s['uid']) ?>">
                                            <span class="state-box"></span>
                                            <span>[<?= htmlspecialchars($s['uid']) ?>] <?= htmlspecialchars($s['name']) ?><?= $sClass ? " ($sClass$sNum)" : "" ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- First Popup Modal: Incoming Requests List -->
    <div class="custom-popup-overlay" id="requestsModal">
        <div class="custom-popup-box">
            <img src="arrow.png" alt="Close" class="popup-close-btn" id="closeRequestsModal">
            <div class="popup-title">Incoming Join Requests</div>
            <div class="requests-list-scroll" id="requestsListContainer">
                <?php if (!empty($teacherRequests)): ?>
                    <?php foreach ($teacherRequests as $req): ?>
                        <div class="request-item-card" data-req-id="<?= htmlspecialchars($req['id']) ?>" data-uid="<?= htmlspecialchars($req['sid']) ?>" data-name="<?= htmlspecialchars($req['student_name']) ?>" data-class="<?= htmlspecialchars($req['class_str']) ?>" data-oid="<?= htmlspecialchars($req['oid']) ?>" data-reason="<?= htmlspecialchars($req['reason']) ?>">
                            <div class="req-card-name"><?= htmlspecialchars($req['student_name']) ?> [<?= htmlspecialchars($req['sid']) ?>]</div>
                            <div class="req-card-sub">Class: <?= htmlspecialchars($req['class_str']) ?> | Org: <?= htmlspecialchars($req['oid']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; color: #64748b; padding: 2rem; font-size: 0.85rem;">No incoming requests.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Second Popup Modal: Request Details & Approve/Reject -->
    <div class="custom-popup-overlay" id="requestDetailModal">
        <div class="custom-popup-box">
            <img src="arrow.png" alt="Close" class="popup-close-btn" id="closeDetailModal">
            <div class="popup-title" id="detailModalTitle">Student Request</div>
            <div style="font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 4px;">Reason for Joining:</div>
            <div class="request-reason-text" id="detailReasonText"></div>
            <div class="popup-actions">
                <button type="button" class="btn-approve" id="approveReqBtn">Approve</button>
                <button type="button" class="btn-reject" id="rejectReqBtn">Reject</button>
            </div>
        </div>
    </div>

    <div class="crop-modal-overlay" id="cropModal">
        <div class="crop-modal-box">
            <h3>Crop Photo to Square</h3>
            <div class="crop-viewport" id="cropViewport">
                <img id="cropImage" src="" alt="Source">
            </div>
            <div class="zoom-slider-con">
                <span>Zoom:</span>
                <input type="range" id="zoomSlider" min="0.5" max="3" step="0.05" value="1">
            </div>
            <div class="crop-modal-actions">
                <button type="button" class="btn-modal-cancel" id="cancelCropBtn">Cancel</button>
                <button type="button" class="btn-modal-confirm" id="confirmCropBtn">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const timeEl = document.getElementById('live-time');
            const dateEl = document.getElementById('live-date');
            if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        }
        updateClock();
        setInterval(updateClock, 1000);

        const mainContainer = document.getElementById('mainContainer');
        if (mainContainer) {
            mainContainer.addEventListener('wheel', function(e) {
                if (!e.target.closest('.org-vertical-box')) {
                    if (e.deltaY !== 0) {
                        mainContainer.scrollLeft += e.deltaY;
                        e.preventDefault();
                    }
                }
            }, { passive: false });
        }

        const shadePalette = [
            [52, 102, 235],  
            [85, 82, 222],   
            [122, 54, 186],  
            [184, 52, 139],  
            [224, 48, 105],  
            [255, 30, 30],   
            [250, 95, 62],   
            [250, 128, 52],  
            [252, 172, 63],  
            [254, 223, 115]  
        ];

        function updateBoxColorsOnScroll() {
            if (!mainContainer) return;
            const boxes = Array.from(mainContainer.querySelectorAll('.org-vertical-box')).filter(b => b.style.display !== 'none');
            const containerWidth = mainContainer.clientWidth;

            boxes.forEach((box) => {
                const boxRect = box.getBoundingClientRect();
                const boxCenter = boxRect.left + boxRect.width / 2;
                
                let ratio = boxCenter / containerWidth;
                ratio = Math.max(0, Math.min(1, ratio));

                const scaled = ratio * (shadePalette.length - 1);
                const index = Math.floor(scaled);
                const remainder = scaled - index;

                let color1 = shadePalette[index];
                let color2 = shadePalette[Math.min(index + 1, shadePalette.length - 1)];

                const r = Math.round(color1[0] + (color2[0] - color1[0]) * remainder);
                const g = Math.round(color1[1] + (color2[1] - color1[1]) * remainder);
                const b = Math.round(color1[2] + (color2[2] - color1[2]) * remainder);

                box.style.backgroundColor = 'rgba(' + r + ', ' + g + ', ' + b + ', 0.35)';
            });
        }

        if (mainContainer) {
            mainContainer.addEventListener('scroll', updateBoxColorsOnScroll);
            window.addEventListener('resize', updateBoxColorsOnScroll);
            window.addEventListener('DOMContentLoaded', updateBoxColorsOnScroll);
        }

        let isEditingMode = false;
        let showAllOrgs = false;
        let hasChanges = false;
        const editingModeBtn = document.getElementById('editingModeBtn');
        const filterManagedBtn = document.getElementById('filterManagedBtn');
        const modifyBtn = document.getElementById('modifyBtn');
        const viewRequestsBtn = document.getElementById('viewRequestsBtn');
        const requestsModal = document.getElementById('requestsModal');
        const closeRequestsModal = document.getElementById('closeRequestsModal');
        const requestDetailModal = document.getElementById('requestDetailModal');
        const closeDetailModal = document.getElementById('closeDetailModal');

        let activeReqId = null;

        viewRequestsBtn.addEventListener('click', function() {
            if (viewRequestsBtn.classList.contains('has-requests')) {
                requestsModal.classList.add('active');
            }
        });

        closeRequestsModal.addEventListener('click', function() {
            requestsModal.classList.remove('active');
        });

        closeDetailModal.addEventListener('click', function() {
            requestDetailModal.classList.remove('active');
        });

        document.addEventListener('click', function(e) {
            const card = e.target.closest('.request-item-card');
            if (card) {
                activeReqId = card.getAttribute('data-req-id');
                const name = card.getAttribute('data-name');
                const uid = card.getAttribute('data-uid');
                const cls = card.getAttribute('data-class');
                const oid = card.getAttribute('data-oid');
                const reason = card.getAttribute('data-reason');

                document.getElementById('detailModalTitle').textContent = `${name} [${uid}] (${cls}) - ${oid}`;
                document.getElementById('detailReasonText').textContent = reason;

                requestsModal.classList.remove('active');
                requestDetailModal.classList.add('active');
            }
        });

        document.getElementById('approveReqBtn').addEventListener('click', function() {
            processRequest('approve_request');
        });

        document.getElementById('rejectReqBtn').addEventListener('click', function() {
            processRequest('reject_request');
        });

        function processRequest(actionType) {
            if (!activeReqId) return;

            const formData = new FormData();
            formData.append('action', actionType);
            formData.append('req_id', activeReqId);

            fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Action failed'));
                }
            })
            .catch(err => {
                alert('Request failed.');
            });
        }

        editingModeBtn.addEventListener('click', function() {
            isEditingMode = !isEditingMode;
            if (isEditingMode) {
                document.body.classList.add('editing-mode');
                editingModeBtn.textContent = 'Close Editing Mode';
                modifyBtn.style.display = 'block';
                modifyBtn.className = 'control-box grey';
                updateBoxColorsOnScroll();
            } else {
                window.location.href = window.location.pathname + (showAllOrgs ? '?filter=all' : '');
            }
        });

        filterManagedBtn.addEventListener('click', function() {
            showAllOrgs = !showAllOrgs;
            if (showAllOrgs) {
                filterManagedBtn.classList.remove('filter-active');
                filterManagedBtn.textContent = 'Only Show Organisations I Manage';
            } else {
                filterManagedBtn.classList.add('filter-active');
                filterManagedBtn.textContent = 'Show All Organisations';
            }

            document.querySelectorAll('.existing-org-box').forEach(box => {
                const isManaged = box.getAttribute('data-is-managed') === 'true';
                if (!showAllOrgs && !isManaged) {
                    box.style.display = 'none';
                } else {
                    box.style.display = 'flex';
                }
            });
            updateBoxColorsOnScroll();
        });

        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('editing') === 'true') {
                editingModeBtn.click();
            }
            if (urlParams.get('filter') === 'all') {
                filterManagedBtn.click();
            }
            if (urlParams.get('scroll') === 'right') {
                if (mainContainer) {
                    mainContainer.scrollLeft = mainContainer.scrollWidth;
                }
            }
            updateBoxColorsOnScroll();
        });

        document.addEventListener('input', function(e) {
            if (isEditingMode && e.target.closest('.org-vertical-box[data-is-managed="true"]') && (e.target.classList.contains('edit-oid') || e.target.classList.contains('edit-oname') || e.target.classList.contains('edit-tid'))) {
                hasChanges = true;
                modifyBtn.className = 'control-box blue';
            }
        });

        document.addEventListener('click', function(e) {
            const checklistItem = e.target.closest('.checklist-item');
            if (checklistItem && isEditingMode && checklistItem.closest('.org-vertical-box[data-is-managed="true"]')) {
                if (checklistItem.classList.contains('state-member')) {
                    checklistItem.classList.remove('state-member');
                    checklistItem.classList.add('state-monitor');
                } else if (checklistItem.classList.contains('state-monitor')) {
                    checklistItem.classList.remove('state-monitor');
                } else {
                    checklistItem.classList.add('state-member');
                }
                hasChanges = true;
                modifyBtn.className = 'control-box blue';
            }
        });

        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('member-search-input') && e.target.closest('.org-vertical-box')) {
                const term = e.target.value.toLowerCase();
                e.target.closest('.org-vertical-box').querySelectorAll('.checklist-item').forEach(item => {
                    const text = item.getAttribute('data-search');
                    item.style.display = text.includes(term) ? 'flex' : 'none';
                });
            }
        });

        document.addEventListener('focus', function(e) {
            if (e.target.classList.contains('teacher-input')) {
                const dropdown = e.target.nextElementSibling;
                if (dropdown && dropdown.classList.contains('custom-dropdown-list')) {
                    dropdown.style.display = 'block';
                }
            }
        }, true);

        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('teacher-input')) {
                const term = e.target.value.toLowerCase();
                const dropdown = e.target.nextElementSibling;
                if (dropdown && dropdown.classList.contains('custom-dropdown-list')) {
                    dropdown.style.display = 'block';
                    dropdown.querySelectorAll('.custom-dropdown-item').forEach(item => {
                        const text = item.textContent.toLowerCase();
                        item.style.display = text.includes(term) ? 'block' : 'none';
                    });
                }
            }
        });

        document.addEventListener('click', function(e) {
            const dropdownItem = e.target.closest('.custom-dropdown-item');
            if (dropdownItem) {
                const dropdown = dropdownItem.closest('.custom-dropdown-list');
                const input = dropdown.previousElementSibling;
                input.value = dropdownItem.getAttribute('data-uid');
                dropdown.style.display = 'none';
                hasChanges = true;
                modifyBtn.className = 'control-box blue';
                return;
            }

            if (!e.target.closest('.teacher-info')) {
                document.querySelectorAll('.custom-dropdown-list').forEach(d => d.style.display = 'none');
            }
        });

        modifyBtn.addEventListener('click', function() {
            if (!hasChanges || modifyBtn.classList.contains('grey')) return;

            if (confirm('Are you sure you want to modify your organisations?')) {
                const promises = [];
                const allOids = [];
                let hasDuplicate = false;

                document.querySelectorAll('.existing-org-box[data-is-managed="true"]').forEach(box => {
                    const newOid = box.querySelector('.edit-oid').value.trim();
                    if (allOids.includes(newOid)) {
                        hasDuplicate = true;
                    }
                    allOids.push(newOid);
                });

                if (hasDuplicate) {
                    alert('Duplicate Organisation IDs are not allowed.');
                    return;
                }

                document.querySelectorAll('.existing-org-box[data-is-managed="true"]').forEach(box => {
                    const oldOid = box.getAttribute('data-oid');
                    const newOid = box.querySelector('.edit-oid').value.trim();
                    const oname = box.querySelector('.edit-oname').value.trim();
                    const tid = box.querySelector('.edit-tid').value.trim();
                    
                    const members = [];
                    const monitors = [];
                    
                    box.querySelectorAll('.members-view-edit .checklist-item').forEach(item => {
                        const uid = item.getAttribute('data-uid');
                        if (item.classList.contains('state-monitor')) {
                            members.push(uid);
                            monitors.push(uid);
                        } else if (item.classList.contains('state-member')) {
                            members.push(uid);
                        }
                    });

                    const formData = new FormData();
                    formData.append('action', 'update_org');
                    formData.append('old_oid', oldOid);
                    formData.append('new_oid', newOid);
                    formData.append('oname', oname);
                    formData.append('tid', tid);
                    members.forEach(m => formData.append('members[]', m));
                    monitors.forEach(m => formData.append('monitors[]', m));

                    promises.push(fetch('', { method: 'POST', body: formData }).then(res => res.json()));
                });

                Promise.all(promises).then(results => {
                    for (let r of results) {
                        if (!r.success) {
                            alert('Error: ' + r.message);
                            return;
                        }
                    }
                    window.location.reload();
                }).catch(err => {
                    alert('Error updating organisations.');
                });
            }
        });

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('delete-org-trigger')) {
                const box = e.target.closest('.existing-org-box');
                if (box.getAttribute('data-is-managed') !== 'true') return;
                
                const oid = box.getAttribute('data-oid');

                if (confirm('Are you sure you want to delete this organisation?')) {
                    const formData = new FormData();
                    formData.append('action', 'delete_org');
                    formData.append('oid', oid);

                    fetch('', { method: 'POST', body: oid ? formData : null })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            box.remove();
                            updateBoxColorsOnScroll();
                        } else {
                            alert('Failed to delete: ' + data.message);
                        }
                    });
                }
            }

            if (e.target.classList.contains('delete-photo-trigger')) {
                const box = e.target.closest('.org-vertical-box');
                if (box.classList.contains('existing-org-box') && box.getAttribute('data-is-managed') !== 'true') return;

                const oid = box.getAttribute('data-oid');

                if (confirm('Are you sure you want to delete this photo?')) {
                    const formData = new FormData();
                    formData.append('action', 'delete_image');
                    formData.append('oid', oid);

                    fetch('', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            box.classList.remove('has-image');
                            const wrapper = box.querySelector('.image-preview-wrapper');
                            wrapper.style.display = 'none';
                            wrapper.querySelector('.org-image-preview').src = '';
                            const uploadBtn = box.querySelector('.btn-upload-file');
                            if (uploadBtn) uploadBtn.textContent = 'Choose photo';
                        } else {
                            alert('Failed to delete photo: ' + data.message);
                        }
                    });
                }
            }
        });

        const cropModal = document.getElementById('cropModal');
        const cropViewport = document.getElementById('cropViewport');
        const cropImage = document.getElementById('cropImage');
        const zoomSlider = document.getElementById('zoomSlider');
        const cancelCropBtn = document.getElementById('cancelCropBtn');
        const confirmCropBtn = document.getElementById('confirmCropBtn');

        let activeBox = null;
        let imgX = 0, imgY = 0, scale = 1;
        let isDragging = false;
        let startX = 0, startY = 0;

        document.querySelectorAll('.org-vertical-box').forEach(box => {
            const fileInput = box.querySelector('.device-file-input');
            if (!fileInput) return;

            fileInput.addEventListener('change', function(e) {
                if (box.classList.contains('existing-org-box') && box.getAttribute('data-is-managed') !== 'true') return;
                const file = e.target.files[0];
                if (!file) return;

                const reader = new FileReader();
                reader.onload = function(event) {
                    cropImage.onload = function() {
                        activeBox = box;
                        initCropper();
                        cropModal.classList.add('active');
                    };
                    cropImage.src = event.target.result;
                };
                reader.readAsDataURL(file);
                fileInput.value = '';
            });
        });

        function initCropper() {
            scale = 1;
            zoomSlider.value = 1;
            const vSize = 260;
            
            if (cropImage.naturalWidth > cropImage.naturalHeight) {
                cropImage.style.height = vSize + 'px';
                cropImage.style.width = 'auto';
            } else {
                cropImage.style.width = vSize + 'px';
                cropImage.style.height = 'auto';
            }
            
            setTimeout(() => {
                imgX = (vSize - cropImage.offsetWidth) / 2;
                imgY = (vSize - cropImage.offsetHeight) / 2;
                updateImageTransform();
            }, 50);
        }

        function updateImageTransform() {
            cropImage.style.transform = 'translate(' + imgX + 'px, ' + imgY + 'px) scale(' + scale + ')';
        }

        cropViewport.addEventListener('mousedown', (e) => {
            isDragging = true;
            startX = e.clientX - imgX;
            startY = e.clientY - imgY;
        });

        window.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            imgX = e.clientX - startX;
            imgY = e.clientY - startY;
            updateImageTransform();
        });

        window.addEventListener('mouseup', () => { isDragging = false; });

        zoomSlider.addEventListener('input', (e) => {
            scale = parseFloat(e.target.value);
            updateImageTransform();
        });

        cancelCropBtn.addEventListener('click', () => {
            cropModal.classList.remove('active');
            activeBox = null;
        });

        confirmCropBtn.addEventListener('click', () => {
            if (!activeBox) return;

            const preview = activeBox.querySelector('.org-image-preview');
            const wrapper = activeBox.querySelector('.image-preview-wrapper');
            const uploadBtn = activeBox.querySelector('.btn-upload-file');
            const oid = activeBox.getAttribute('data-oid');

            const canvas = document.createElement('canvas');
            const outputSize = 300;
            canvas.width = outputSize;
            canvas.height = outputSize;
            const ctx = canvas.getContext('2d');

            const vSize = 260;
            const imgRect = cropImage.getBoundingClientRect();
            const viewportRect = cropViewport.getBoundingClientRect();

            const naturalScale = cropImage.naturalWidth / imgRect.width;

            const sourceX = (viewportRect.left - imgRect.left) * naturalScale;
            const sourceY = (viewportRect.top - imgRect.top) * naturalScale;
            const sourceSize = vSize * naturalScale;

            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, outputSize, outputSize);

            ctx.drawImage(
                cropImage,
                sourceX,
                sourceY,
                sourceSize,
                sourceSize,
                0, 0, outputSize, outputSize
            );

            const base64Image = canvas.toDataURL('image/png', 0.9);
            
            const formData = new FormData();
            formData.append('action', 'save_image');
            formData.append('oid', oid);
            formData.append('image', base64Image);

            fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    preview.src = data.path + '?t=' + new Date().getTime();
                    wrapper.style.display = 'block';
                    activeBox.classList.add('has-image');
                    uploadBtn.textContent = 'Change photo';
                } else {
                    alert('Error saving image: ' + data.message);
                }
            })
            .catch(err => { console.error('Upload failed:', err); });

            cropModal.classList.remove('active');
            activeBox = null;
        });
    </script>
</body>
</html>
