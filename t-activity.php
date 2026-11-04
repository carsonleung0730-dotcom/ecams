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

$teacherUid = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 1;

// Fetch organisations managed by this teacher
$managedOrgIds = [];
$managedOrgsList = [];
if (isset($conn) && $conn instanceof mysqli) {
    $mOrgStmt = $conn->prepare("SELECT oid, oname FROM organisations WHERE tid = ? ORDER BY oname ASC");
    $mOrgStmt->bind_param("s", $teacherUid);
    $mOrgStmt->execute();
    $mOrgRes = $mOrgStmt->get_result();
    while ($row = $mOrgRes->fetch_assoc()) {
        $managedOrgsList[] = $row;
        $managedOrgIds[] = intval($row['oid']);
    }
    $mOrgStmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'modify') {
        $aid = intval($_POST['aid']);
        $oid = intval($_POST['oid']);
        $aname = trim($_POST['aname']);
        $date = trim($_POST['date']);
        $room = intval($_POST['room']);
        $timeslot = trim($_POST['timeslot']);

        // Security check: ensure activity belongs to teacher and new oid is managed by this teacher
        $chk = $conn->prepare("
            SELECT a.aid FROM activity a 
            JOIN organisations o ON a.oid = o.oid 
            WHERE a.aid = ? AND o.tid = ?
        ");
        $chk->bind_param("is", $aid, $teacherUid);
        $chk->execute();
        $chkRes = $chk->get_result();
        $hasAccess = $chkRes->num_rows > 0;
        $chk->close();

        // Database-level verification for target organization
        $orgChk = $conn->prepare("SELECT oid FROM organisations WHERE oid = ? AND tid = ?");
        $orgChk->bind_param("is", $oid, $teacherUid);
        $orgChk->execute();
        $orgValid = $orgChk->get_result()->num_rows > 0;
        $orgChk->close();

        if (!$hasAccess || !$orgValid) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized: You can only modify activities for your own organisations.']);
            $output = ob_get_clean();
            echo $output;
            exit();
        }

        $stmt = $conn->prepare("UPDATE activity SET oid = ?, aname = ?, date = ?, room = ?, timeslot = ? WHERE aid = ?");
        $stmt->bind_param("issisi", $oid, $aname, $date, $room, $timeslot, $aid);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $stmt->error]);
        }
        $stmt->close();

        $output = ob_get_clean();
        echo $output;
        exit();
    }

    if ($action === 'create') {
        $oid = intval($_POST['oid']);
        $aname = trim($_POST['aname']);
        $datesInput = trim($_POST['date']);
        $room = intval($_POST['room']);
        $timeslot = trim($_POST['timeslot']);

        // Support multiple comma-separated dates for regular training sessions
        $dates = array_filter(array_map('trim', explode(',', $datesInput)));
        if (empty($dates)) {
            echo json_encode(['status' => 'error', 'message' => 'At least one date is required.']);
            $output = ob_get_clean();
            echo $output;
            exit();
        }

        // Bulletproof Database-level Security check: verify ownership directly from DB
        $orgChk = $conn->prepare("SELECT oid FROM organisations WHERE oid = ? AND tid = ?");
        $orgChk->bind_param("is", $oid, $teacherUid);
        $orgChk->execute();
        $orgValid = $orgChk->get_result()->num_rows > 0;
        $orgChk->close();

        if (!$orgValid) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized: You can only create activities for organisations you manage.']);
            $output = ob_get_clean();
            echo $output;
            exit();
        }

        $conn->begin_transaction();
        try {
            $createdAids = [];
            $stmt = $conn->prepare("INSERT INTO activity (oid, aname, date, room, timeslot) VALUES (?, ?, ?, ?, ?)");
            
            $memStmt = $conn->prepare("SELECT sid FROM membership WHERE oid = ?");
            $memStmt->bind_param("i", $oid);
            $memStmt->execute();
            $memRes = $memStmt->get_result();
            $members = $memRes->fetch_all(MYSQLI_ASSOC);
            $memStmt->close();

            $attStmt = $conn->prepare("INSERT INTO attendance (aid, sid, attend, rate, `s.rate`) VALUES (?, ?, 0, 10, 10)");

            foreach ($dates as $date) {
                $stmt->bind_param("issis", $oid, $aname, $date, $room, $timeslot);
                $stmt->execute();
                $newAid = $stmt->insert_id;
                $createdAids[] = [
                    'aid' => $newAid,
                    'date' => $date
                ];

                foreach ($members as $member) {
                    $attStmt->bind_param("is", $newAid, $member['sid']);
                    $attStmt->execute();
                }
            }
            $stmt->close();
            $attStmt->close();

            $conn->commit();
            echo json_encode(['status' => 'success', 'aids' => $createdAids]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        $output = ob_get_clean();
        echo $output;
        exit();
    }

    if ($action === 'delete') {
        $aid = intval($_POST['aid']);

        // Security check: ensure activity belongs to teacher's managed organisation
        $chk = $conn->prepare("
            SELECT a.aid FROM activity a 
            JOIN organisations o ON a.oid = o.oid 
            WHERE a.aid = ? AND o.tid = ?
        ");
        $chk->bind_param("is", $aid, $teacherUid);
        $chk->execute();
        $chkRes = $chk->get_result();
        $hasAccess = $chkRes->num_rows > 0;
        $chk->close();

        if (!$hasAccess) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized: You can only delete activities for your own organisations.']);
            $output = ob_get_clean();
            echo $output;
            exit();
        }

        $conn->begin_transaction();
        try {
            $delAttStmt = $conn->prepare("DELETE FROM attendance WHERE aid = ?");
            $delAttStmt->bind_param("i", $aid);
            $delAttStmt->execute();
            $delAttStmt->close();

            $delActStmt = $conn->prepare("DELETE FROM activity WHERE aid = ?");
            $delActStmt->bind_param("i", $aid);
            $delActStmt->execute();
            $delActStmt->close();

            $conn->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        $output = ob_get_clean();
        echo $output;
        exit();
    }
}

// Fetch ONLY organisations managed by this teacher for the dropdown list
$orgStmt = $conn->prepare("SELECT oid, oname, tid FROM organisations WHERE tid = ? ORDER BY oid ASC");
$orgStmt->bind_param("s", $teacherUid);
$orgStmt->execute();
$orgRes = $orgStmt->get_result();
$organizations = $orgRes->fetch_all(MYSQLI_ASSOC);
$orgStmt->close();

// Fetch all activities joined with organisations to get the organisation names (`oname`) for all activities
$stmt = $conn->query("
    SELECT a.aid, a.oid, a.aname, a.date, a.room, a.timeslot, o.oname 
    FROM activity a 
    LEFT JOIN organisations o ON a.oid = o.oid
");
$dbActivities = $stmt ? $stmt->fetch_all(MYSQLI_ASSOC) : [];

$events = array_map(function($act) use ($managedOrgIds) {
    $isManaged = in_array(intval($act['oid']), $managedOrgIds, true);
    return [
        'id' => $act['aid'],
        'title' => $act['aname'],
        'start' => $act['date'],
        'extendedProps' => [
            'aid' => $act['aid'],
            'oid' => $act['oid'],
            'oname' => $act['oname'] ?? 'Unknown Organisation',
            'aname' => $act['aname'],
            'date' => $act['date'],
            'room' => $act['room'],
            'timeslot' => $act['timeslot'] ?? 'Lunch Recess',
            'isManaged' => $isManaged
        ]
    ];
}, $dbActivities);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - Teacher Activities Calendar</title>
    <link rel="icon" type="image/x-icon" href="sECAMS.png">

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        html, body {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: #e0f2fe;
            color: #1e293b;
            height: 100vh;
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
            flex-shrink: 0;
        }

        .header-content { max-width: 100%; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .logo { height: 85px; width: auto; display: block; }
        .header-titles h1 { font-size: 1.35rem; font-weight: 700; color: #2e8ccb; letter-spacing: -0.02em; }
        .sub-header-row { display: flex; align-items: center; gap: 12px; margin-top: 2px; }
        .sub-header-row p { font-size: 0.85rem; color: #64748b; }
        .btn-logout {
            background-color: #eb4174; color: #ffffff; font-size: 0.75rem; font-weight: 700;
            padding: 0.25rem 0.65rem; border-radius: 4px; text-decoration: none; transition: background-color 0.2s; display: inline-block;
        }
        .btn-logout:hover { background-color: #9f1239; }
        .clock-widget { text-align: right; padding: 0.5rem 0.85rem; }
        .clock-time { font-size: 1.15rem; font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; }
        .clock-date { font-size: 0.75rem; font-weight: 500; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }

        .fc-daygrid-day {
            transition: outline 0.15s ease, background-color 1.25s ease, color 1.25s ease !important;
            cursor: pointer;
            position: relative;
        }

        .fc-daygrid-day-number { transition: color 1.25s ease !important; }

        .fc-daygrid-day.fc-day-past { background-color: #cbd5e1 !important; }
        .fc-daygrid-day.fc-day-past .fc-daygrid-day-number { color: #64748b !important; }
        .fc-daygrid-day.fc-day-past:hover { outline: none !important; background-color: #cbd5e1 !important; }
        .fc-daygrid-day.fc-day-past.has-event { cursor: pointer !important; }
        .fc-daygrid-day.fc-day-past.has-event:hover { outline: 4px double #8fa7ea !important; outline-offset: -4px; }

        .fc-daygrid-day:hover {
            outline: 4px double #8fa7ea !important;
            outline-offset: -4px;
            z-index: 5 !important;
            background-color: transparent;
        }

        .fc-daygrid-day.has-event:hover { outline: 4px double #e99090 !important; outline-offset: -4px; }

        /* Modify mode: Red highlighting */
        .calendar-active-mode-red .fc-daygrid-day.multi-selected:not(.fc-day-past) { background-color: #ea580c !important; }
        .calendar-active-mode-red .fc-daygrid-day.multi-selected:not(.fc-day-past) .fc-daygrid-day-number { color: #ffffff !important; font-weight: 700; }

        /* Create mode: Blue highlighting */
        .calendar-active-mode-blue .fc-daygrid-day.multi-selected:not(.fc-day-past) { background-color: #0284c7 !important; }
        .calendar-active-mode-blue .fc-daygrid-day.multi-selected:not(.fc-day-past) .fc-daygrid-day-number { color: #ffffff !important; font-weight: 700; }

        .fc-daygrid-day.has-event.multi-selected:not(.fc-day-past) {
            z-index: 4 !important;
        }

        .app-body { flex: 1; display: flex; height: calc(100vh - 110px); position: relative; overflow: hidden; }

        .side-panel { width: 280px; background-color: #ffffff; border-right: 4px double #1037e572; display: flex; flex-direction: column; flex-shrink: 0; height: 100%; }
        .nav-btn {
            flex: 1; display: flex; align-items: center; justify-content: center; text-decoration: none;
            font-size: 1.35rem; font-weight: 700; color: #ffffff; transition: all 0.2s ease-in-out;
            box-sizing: border-box; position: relative; border: none; cursor: pointer;
        }
        .nav-btn.is-active::before {
            content: ''; position: absolute; top: 12px; left: 12px; width: 14px; height: 14px;
            background-color: #2563eb; border-radius: 50%; border: 2px solid #ffffff; box-shadow: 0 0 8px rgba(37, 99, 235, 0.6); z-index: 10;
        }
        .side-panel .nav-btn:hover { border: 8px double #ffffff !important; z-index: 2; }
        .btn-icon { width: 280px; height: 200px; object-fit: contain; }
        .nav-btn-org { background-color: #fea768; border-bottom: 2px solid #ea580c; }
        .nav-btn-activity { background-color: #dc2626; border-bottom: 2px solid #991b1b; }
        .nav-btn-user { background-color: #881337; }

        main { flex: 1; padding: 1.5rem; background-color: #d6e6f9; height: 100%; overflow: hidden; display: flex; flex-direction: column; }

        .calendar-container {
            max-width: 1000px;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 16px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
            border: 1px solid #dbeafe;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            overflow: hidden;
        }

        #ecams-calendar {
            flex: 1;
            min-height: 0;
        }

        :root {
            --fc-border-color: #e2e8f0; --fc-button-bg-color: #2563eb; --fc-button-border-color: #2563eb;
            --fc-button-hover-bg-color: #1d4ed8; --fc-button-active-bg-color: #1e40af; --fc-today-bg-color: #eff6ff;
            --fc-page-bg-color: #ffffff; --fc-neutral-bg-color: #f8fafc;
        }

        .fc .fc-toolbar-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; }
        .fc .fc-toolbar { margin-bottom: 0.5rem !important; }

        .activity-details-panel {
            position: fixed; top: 130px; right: 20px; width: clamp(220px, 25vw, 380px); min-width: 220px;
            max-width: calc(100vw - 30px); height: auto; min-height: 320px; max-height: calc(100vh - 160px);
            background-color: #ffffff; border: 1px solid #cbd5e1; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            border-radius: 8px; z-index: 1000; display: none; flex-direction: column; resize: both; overflow: hidden; box-sizing: border-box;
        }
        .activity-details-panel.active { display: flex; }

        .resize-handle-bl { position: absolute; left: 0; bottom: 0; width: 16px; height: 16px; cursor: nesw-resize; z-index: 1100; }
        .resize-handle-bl::after {
            content: ''; position: absolute; left: 3px; bottom: 3px; width: 6px; height: 6px;
            border-left: 2px solid #94a3b8; border-bottom: 2px solid #94a3b8;
        }

        .panel-header { padding: 0.75rem 1rem; background-color: #f8fafc; border-bottom: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; cursor: move; user-select: none; }
        .header-tabs { display: flex; align-items: center; gap: 8px; font-size: 0.95rem; font-weight: 700; }
        .tab-btn { background: none; border: none; font-size: 0.9rem; font-weight: 700; color: #64748b; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: all 0.2s; }
        .tab-btn.active { color: #2563eb; background-color: #eff6ff; }
        .tab-btn:hover:not(.active) { color: #1e293b; }
        .tab-separator { color: #cbd5e1; font-weight: 400; }
        .close-btn { background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer; line-height: 1; }
        .close-btn:hover { color: #0f172a; }

        .panel-body-content { padding: 1.25rem; flex: 1; overflow-y: auto; display: flex; flex-direction: column; position: relative; }

        .btn-return-back {
            display: inline-flex; align-items: center; gap: 4px; background-color: #f1f5f9; color: #334155;
            border: 1px solid #cbd5e1; border-radius: 4px; padding: 0.35rem 0.65rem; font-size: 0.8rem;
            font-weight: 700; cursor: pointer; margin-bottom: 0.85rem; align-self: flex-start; transition: background-color 0.15s ease;
        }
        .btn-return-back:hover { background-color: #e2e8f0; color: #0f172a; }

        .activity-cards-list { display: flex; flex-direction: column; gap: 0.75rem; margin-top: 0.25rem; }
        .fc-v-event, .fc-h-event, .fc-daygrid-event { background-color: #76787e !important; border-radius: 4px; }

        .activity-card {
            background-color: #ffffff; border: 1.5px solid #e2e8f0; border-left: 5px solid #888c96;
            border-radius: 6px; padding: 0.85rem 1rem; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }
        .activity-card:hover {
            border-color: #93c5fd; border-left-color: #1d4ed8; background-color: #f8fafc;
            transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.08);
        }
        .activity-card-title { font-size: 0.95rem; font-weight: 700; color: #1e293b; }
        .activity-card-sub { font-size: 0.75rem; color: #64748b; margin-top: 4px; }

        .field-group { margin-bottom: 0.85rem; display: flex; flex-direction: column; gap: 0.35rem; }
        .field-group label { font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; }
        .field-group input, .field-group select {
            width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px;
            font-size: 0.95rem; color: #0f172a; outline: none; transition: border-color 0.2s; background-color: #ffffff;
        }
        .field-group input:focus, .field-group select:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15); }
        .field-group input[readonly], .field-group input:disabled, .field-group select:disabled { background-color: #f1f5f9; color: #64748b; cursor: not-allowed; }

        .date-chips-container {
            max-height: 90px;
            overflow-y: auto;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 6px;
            background: #f8fafc;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            box-sizing: border-box;
        }
        .date-chip {
            background-color: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #bae6fd;
        }
        .date-chip .remove-date {
            cursor: pointer;
            color: #0369a1;
            font-weight: bold;
        }
        .date-chip .remove-date:hover {
            color: #9f1239;
        }

        .panel-footer { margin-top: auto; padding-top: 1rem; display: flex; flex-direction: column; gap: 0.6rem; }

        .btn-action {
            width: 100%; padding: 0.75rem; background-color: #2563eb; color: #ffffff;
            font-size: 1rem; font-weight: 700; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s ease;
        }
        .btn-action:hover:not(:disabled) { background-color: #1d4ed8; }
        .btn-action:disabled { background-color: #cbd5e1 !important; color: #94a3b8 !important; cursor: not-allowed !important; opacity: 0.8; }

        .btn-create-mode { background-color: #16a34a !important; }
        .btn-create-mode:hover:not(:disabled) { background-color: #15803d !important; }

        .btn-delete {
            width: 100%; padding: 0.75rem; background-color: #dc2626; color: #ffffff;
            font-size: 1rem; font-weight: 700; border: none; border-radius: 6px; cursor: pointer; display: none; transition: background-color 0.2s;
        }
        .btn-delete:hover { background-color: #b91c1c; }

        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background-color: rgba(15, 23, 42, 0.5);
            z-index: 2000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s ease;
        }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
        .modal-box { background-color: #ffffff; width: 90%; max-width: 420px; border-radius: 10px; padding: 1.5rem; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); text-align: center; }
        .modal-box h3 { font-size: 1.15rem; color: #0f172a; margin-bottom: 0.5rem; font-weight: 700; }
        .modal-box p { font-size: 0.9rem; color: #64748b; margin-bottom: 1.5rem; }
        .modal-actions { display: flex; gap: 0.75rem; justify-content: center; }
        .btn-modal-cancel { flex: 1; padding: 0.65rem; background-color: #808080; color: #ffffff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-modal-confirm { flex: 1; padding: 0.65rem; background-color: #2563eb; color: #ffffff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-modal-confirm.delete-mode { background-color: #dc2626 !important; }
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
            <a href="t-org.php" class="nav-btn nav-btn-org">
                <img src="org.png" alt="Organization" class="btn-icon">
            </a>
            <a href="teacher.php" class="nav-btn nav-btn-activity is-active" id="navBtnActivity">
                <img src="act.png" alt="Activity" class="btn-icon">
            </a>
            <a href="t-user.php" class="nav-btn nav-btn-user">
                <img src="use.png" alt="User" class="btn-icon">
            </a>
        </aside>

        <main>
            <div class="calendar-container">
                <div id="ecams-calendar"></div>
            </div>
        </main>

        <div class="activity-details-panel" id="activityPanel">
            <div class="resize-handle-bl" id="resizeHandleBL"></div>

            <div class="panel-header" id="panelHeader">
                <div class="header-tabs">
                    <button type="button" class="tab-btn" id="tabExisting">Existing Activities</button>
                    <span class="tab-separator" id="tabSeparator">|</span>
                    <button type="button" class="tab-btn" id="tabCreate">Create new</button>
                </div>
                <button class="close-btn" id="closePanelBtn">×</button>
            </div>
            
            <div class="panel-body-content">
                <div id="activitiesListView" style="display: none;">
                    <div class="activity-cards-list" id="activityCardsContainer"></div>
                </div>

                <div id="activityFormView" style="display: none;">
                    <button type="button" class="btn-return-back" id="returnBackBtn" style="display: none;">← Back</button>

                    <form id="activityForm">
                        <div class="field-group">
                            <label for="field_aid">Activity ID (aid)</label>
                            <input type="text" id="field_aid" name="aid" placeholder="Auto-assigned" readonly>
                        </div>

                        <div class="field-group">
                            <label for="field_oid">Organisation</label>
                            <select id="field_oid" name="oid">
                                <option value="">Select Organisation</option>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?= htmlspecialchars($org['oid']) ?>">
                                        <?= htmlspecialchars($org['oid']) ?>. <?= htmlspecialchars($org['oname']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-group">
                            <label for="field_aname">Activity Name (aname)</label>
                            <input type="text" id="field_aname" name="aname" placeholder="e.g. Science Fair">
                        </div>

                        <div class="field-group">
                            <label for="field_date">Date(s) (date)</label>
                            <div class="date-chips-container" id="dateChipsContainer"></div>
                            <div style="display: flex; gap: 6px; margin-top: 6px;" id="datePickerRow">
                                <input type="date" id="field_date_picker" style="flex: 1;">
                                <button type="button" id="addDateBtn" style="padding: 0.4rem 0.75rem; background: #2563eb; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">Add</button>
                            </div>
                            <input type="hidden" id="field_date" name="date">
                        </div>

                        <div class="field-group">
                            <label for="field_room">Room (room)</label>
                            <input type="number" id="field_room" name="room" placeholder="e.g. 302">
                        </div>

                        <div class="field-group">
                            <label for="field_timeslot">Time Slot</label>
                            <select id="field_timeslot" name="timeslot">
                                <option value="Lunch Recess">Lunch Recess</option>
                                <option value="After School">After School</option>
                            </select>
                        </div>

                        <div class="panel-footer">
                            <button type="button" class="btn-action" id="actionBtn">Modify</button>
                            <button type="button" class="btn-delete" id="deleteBtn">Delete Activity</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="confirmModal">
        <div class="modal-box">
            <h3 id="modalHeading">Confirm Action</h3>
            <p id="modalDescription">Are you sure you want to proceed?</p>
            <div class="modal-actions">
                <button class="btn-modal-cancel" id="cancelModalBtn">Cancel</button>
                <button class="btn-modal-confirm" id="confirmModalBtn">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const dateString = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
            document.getElementById('live-time').textContent = timeString;
            document.getElementById('live-date').textContent = dateString;
        }
        updateClock();
        setInterval(updateClock, 1000);

        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('ecams-calendar');
            var dbEvents = <?php echo json_encode($events); ?>;

            var currentMode = 'modify';
            var initialActivityData = {};
            var activeEventId = null;
            var currentSelectedDate = null;
            var currentDayActivities = [];
            var selectedDates = [];

            var panel = document.getElementById('activityPanel');
            var tabExisting = document.getElementById('tabExisting');
            var tabCreate = document.getElementById('tabCreate');
            var tabSeparator = document.getElementById('tabSeparator');

            var activitiesListView = document.getElementById('activitiesListView');
            var activityCardsContainer = document.getElementById('activityCardsContainer');
            var activityFormView = document.getElementById('activityFormView');
            var returnBackBtn = document.getElementById('returnBackBtn');

            var actionBtn = document.getElementById('actionBtn');
            var deleteBtn = document.getElementById('deleteBtn');
            var formInputs = document.querySelectorAll('#activityForm input:not([readonly], [type="hidden"]), #activityForm select');
            var navBtnActivity = document.getElementById('navBtnActivity');

            navBtnActivity.addEventListener('click', function(e) {
                e.preventDefault();
                closeRightPanel();
                window.location.href = 'teacher.php';
            });

            function resetPanelPosition() {
                panel.style.top = '';
                panel.style.left = '';
                panel.style.right = '';
                panel.style.width = '';
                panel.style.height = '';
            }

            function unselectCalendarDate() {
                document.querySelectorAll('.fc-daygrid-day').forEach(function(dayEl) {
                    dayEl.classList.remove('multi-selected');
                });
                calendarEl.classList.remove('calendar-active-mode-blue', 'calendar-active-mode-red');
                selectedDates = [];
                currentSelectedDate = null;
                renderDateChips();
            }

            function updateSelectionHighlight() {
                document.querySelectorAll('.fc-daygrid-day').forEach(function(dayEl) {
                    var dStr = dayEl.getAttribute('data-date');
                    if (selectedDates.includes(dStr)) {
                        dayEl.classList.add('multi-selected');
                    } else {
                        dayEl.classList.remove('multi-selected');
                    }
                });
            }

            function getTodayDateStr() {
                const now = new Date();
                const y = now.getFullYear();
                const m = String(now.getMonth() + 1).padStart(2, '0');
                const d = String(now.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }

            function markPastDays() {
                const todayStr = getTodayDateStr();
                document.querySelectorAll('.fc-daygrid-day').forEach(function(cell) {
                    const dateStr = cell.getAttribute('data-date');
                    if (dateStr && dateStr < todayStr) {
                        cell.classList.add('fc-day-past');
                    } else if (dateStr) {
                        cell.classList.remove('fc-day-past');
                    }
                });
            }

            function renderDateChips() {
                var container = document.getElementById('dateChipsContainer');
                if (!container) return;
                container.innerHTML = '';

                selectedDates.forEach(function(dStr, index) {
                    var chip = document.createElement('div');
                    chip.className = 'date-chip';
                    chip.textContent = dStr;

                    if (currentMode === 'create') {
                        var removeBtn = document.createElement('span');
                        removeBtn.className = 'remove-date';
                        removeBtn.textContent = '×';
                        removeBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            // Rule: if the first (primary) date is deselected/removed, close right panel and clear all
                            if (index === 0) {
                                closeRightPanel();
                            } else {
                                selectedDates = selectedDates.filter(d => d !== dStr);
                                if (selectedDates.length === 0) {
                                    closeRightPanel();
                                } else {
                                    renderDateChips();
                                    updateSelectionHighlight();
                                    checkFormModifications();
                                }
                            }
                        });
                        chip.appendChild(removeBtn);
                    }

                    container.appendChild(chip);
                });

                document.getElementById('field_date').value = selectedDates.join(', ');
                checkFormModifications();
            }

            // Add date via right panel date picker button during creation
            document.getElementById('addDateBtn').addEventListener('click', function() {
                var picker = document.getElementById('field_date_picker');
                var val = picker.value;
                if (!val) return;

                var todayStr = getTodayDateStr();
                if (val < todayStr) {
                    alert('Cannot select past dates.');
                    return;
                }

                if (!selectedDates.includes(val)) {
                    selectedDates.push(val);
                }

                picker.value = '';

                if (!panel.classList.contains('active') || currentMode !== 'create') {
                    openActivityPanelForCreate(val);
                } else {
                    renderDateChips();
                    calendarEl.classList.remove('calendar-active-mode-red');
                    calendarEl.classList.add('calendar-active-mode-blue');
                    updateSelectionHighlight();
                }
            });

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                displayEventTime: false,
                headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listYear' },
                buttonText: { today: 'Today', dayGridMonth: 'Month', listYear: 'List All' },
                events: dbEvents,
                handleWindowResize: true,
                stickyHeaderDates: true,

                datesSet: function() {
                    markCellsWithEvents();
                    markPastDays();
                    updateSelectionHighlight();
                },

                dateClick: function(info) {
                    var clickedDateStr = info.dateStr;
                    var todayStr = getTodayDateStr();
                    var isPast = clickedDateStr < todayStr;

                    currentDayActivities = calendar.getEvents().filter(function(e) {
                        return e.startStr.split('T')[0] === clickedDateStr;
                    });

                    // 1. If clicking exact same date in existing/modify mode -> toggle close panel
                    if (panel.classList.contains('active') && currentSelectedDate === clickedDateStr && currentMode !== 'create' && selectedDates.length === 1) {
                        closeRightPanel();
                        return;
                    }

                    // 2. If in Create New mode, clicking any date toggles it blue and adds/removes it from date chips
                    if (panel.classList.contains('active') && currentMode === 'create') {
                        var idx = selectedDates.indexOf(clickedDateStr);
                        if (idx > -1) {
                            // Rule: if clicking the first (primary) date to deselect it, close panel entirely
                            if (idx === 0) {
                                closeRightPanel();
                                return;
                            }
                            selectedDates.splice(idx, 1);
                        } else {
                            if (!isPast) {
                                selectedDates.push(clickedDateStr);
                            }
                        }

                        if (selectedDates.length === 0) {
                            closeRightPanel();
                            return;
                        }

                        calendarEl.classList.remove('calendar-active-mode-red');
                        calendarEl.classList.add('calendar-active-mode-blue');
                        updateSelectionHighlight();
                        renderDateChips();
                        return;
                    }

                    // 3. ALWAYS DESELECT CURRENT & SELECT NEW DATE OUTSIDE CREATE NEW MODE
                    currentSelectedDate = clickedDateStr;

                    if (currentDayActivities.length > 0) {
                        calendarEl.classList.remove('calendar-active-mode-blue');
                        calendarEl.classList.add('calendar-active-mode-red');
                        selectedDates = [clickedDateStr]; // Strictly single date
                        updateSelectionHighlight();
                        renderDateChips();
                        openPanelWithActivityList(currentDayActivities);
                    } else {
                        if (isPast) return;
                        calendarEl.classList.remove('calendar-active-mode-red');
                        calendarEl.classList.add('calendar-active-mode-blue');
                        selectedDates = [clickedDateStr]; // Strictly single date
                        updateSelectionHighlight();
                        openActivityPanelForCreate(clickedDateStr);
                    }
                },

                eventClick: function(info) {
                    var eventDateStr = info.event.startStr.split('T')[0];
                    var todayStr = getTodayDateStr();
                    var isPast = eventDateStr < todayStr;

                    if (panel.classList.contains('active') && currentSelectedDate === eventDateStr && currentMode !== 'create' && selectedDates.length === 1) {
                        closeRightPanel();
                        return;
                    }

                    calendarEl.classList.remove('calendar-active-mode-blue');
                    calendarEl.classList.add('calendar-active-mode-red');
                    selectedDates = [eventDateStr]; // Strictly single date
                    currentSelectedDate = eventDateStr;
                    updateSelectionHighlight();
                    renderDateChips();

                    currentDayActivities = calendar.getEvents().filter(function(e) {
                        return e.startStr.split('T')[0] === currentSelectedDate;
                    });
                    
                    openPanelWithActivityList(currentDayActivities);
                    var clickedEvt = currentDayActivities.find(e => e.id == info.event.id);
                    if (clickedEvt) {
                        showActivityDetails(clickedEvt);
                    }
                }
            });

            calendar.render();

            function markCellsWithEvents() {
                document.querySelectorAll('.fc-daygrid-day').forEach(function(el) {
                    el.classList.remove('has-event');
                });
                calendar.getEvents().forEach(function(evt) {
                    if (evt.start) {
                        var dStr = evt.startStr.split('T')[0];
                        var cell = document.querySelector('.fc-daygrid-day[data-date="' + dStr + '"]');
                        if (cell) {
                            cell.classList.add('has-event');
                        }
                    }
                });
            }

            markCellsWithEvents();
            markPastDays();

            function openPanelWithActivityList(activitiesList) {
                resetPanelPosition();

                // Keep calendar highlighted in red for existing activities
                calendarEl.classList.remove('calendar-active-mode-blue');
                calendarEl.classList.add('calendar-active-mode-red');

                const todayStr = getTodayDateStr();
                const isPast = currentSelectedDate < todayStr;

                if (isPast) {
                    tabCreate.style.display = 'none';
                    tabSeparator.style.display = 'none';
                } else {
                    tabCreate.style.display = 'inline-block';
                    tabSeparator.style.display = 'inline-block';
                }

                tabExisting.style.display = 'inline-block';
                tabExisting.classList.add('active');
                tabCreate.classList.remove('active');

                activityCardsContainer.innerHTML = '';
                
                activitiesList.forEach(function(evt) {
                    var card = document.createElement('div');
                    card.className = 'activity-card';
                    
                    var titleEl = document.createElement('div');
                    titleEl.className = 'activity-card-title';
                    titleEl.textContent = evt.title;

                    var subEl = document.createElement('div');
                    subEl.className = 'activity-card-sub';
                    var orgName = evt.extendedProps.oname || 'Unknown Organisation';
                    var mgmtStatus = evt.extendedProps.isManaged ? '' : ' (Read-Only)';
                    subEl.textContent = 'Org: ' + orgName + ' | ' + (evt.extendedProps.timeslot || 'Lunch Recess') + mgmtStatus;

                    card.appendChild(titleEl);
                    card.appendChild(subEl);

                    card.addEventListener('click', function() {
                        showActivityDetails(evt);
                    });

                    activityCardsContainer.appendChild(card);
                });

                activitiesListView.style.display = 'block';
                activityFormView.style.display = 'none';
                panel.classList.add('active');
            }

            function showActivityDetails(eventObj) {
                activitiesListView.style.display = 'none';
                activityFormView.style.display = 'block';
                returnBackBtn.style.display = 'inline-flex';

                loadActivityIntoForm(eventObj);
            }

            function loadActivityIntoForm(eventObj) {
                currentMode = 'modify';
                var props = eventObj.extendedProps;
                activeEventId = eventObj.id;
                var isManaged = props.isManaged;

                calendarEl.classList.remove('calendar-active-mode-blue');
                calendarEl.classList.add('calendar-active-mode-red');

                actionBtn.textContent = 'Modify';
                actionBtn.classList.remove('btn-create-mode');

                initialActivityData = {
                    aid: props.aid || eventObj.id,
                    oid: props.oid || '',
                    oname: props.oname || '',
                    aname: props.aname || eventObj.title,
                    date: props.date || eventObj.startStr.split('T')[0],
                    room: props.room || '',
                    timeslot: props.timeslot || 'Lunch Recess'
                };

                document.getElementById('field_aid').value = initialActivityData.aid;
                
                var oidSelect = document.getElementById('field_oid');
                Array.from(oidSelect.options).forEach(function(opt) {
                    if (opt.getAttribute('data-dynamic') === 'true') {
                        opt.remove();
                    }
                });

                if (!isManaged && initialActivityData.oid) {
                    let exists = false;
                    for (let i = 0; i < oidSelect.options.length; i++) {
                        if (oidSelect.options[i].value == initialActivityData.oid) {
                            exists = true;
                            break;
                        }
                    }
                    if (!exists) {
                        let opt = document.createElement('option');
                        opt.value = initialActivityData.oid;
                        opt.textContent = initialActivityData.oid + '. ' + initialActivityData.oname;
                        opt.setAttribute('data-dynamic', 'true');
                        oidSelect.appendChild(opt);
                    }
                }
                oidSelect.value = initialActivityData.oid;

                document.getElementById('field_aname').value = initialActivityData.aname;
                selectedDates = [initialActivityData.date];
                renderDateChips();
                document.getElementById('field_room').value = initialActivityData.room;
                document.getElementById('field_timeslot').value = initialActivityData.timeslot;

                const todayStr = getTodayDateStr();
                const isPast = initialActivityData.date < todayStr;

                document.getElementById('datePickerRow').style.display = 'none';

                if (!isManaged) {
                    formInputs.forEach(inp => inp.disabled = true);
                    actionBtn.style.display = 'none';
                    deleteBtn.style.display = 'none';
                    tabCreate.style.display = isPast ? 'none' : 'inline-block';
                } else {
                    formInputs.forEach(inp => inp.disabled = false);
                    document.getElementById('field_aid').disabled = true;
                    actionBtn.style.display = 'block';
                    deleteBtn.style.display = isPast ? 'none' : 'block';
                    tabCreate.style.display = isPast ? 'none' : 'inline-block';
                    if (isPast) tabSeparator.style.display = 'none';
                    actionBtn.disabled = true;
                }
            }

            function openActivityPanelForCreate(dateVal) {
                resetPanelPosition();

                currentMode = 'create';
                activeEventId = null;

                formInputs.forEach(inp => inp.disabled = false);
                document.getElementById('field_aid').disabled = true;
                document.getElementById('datePickerRow').style.display = 'flex';

                var oidSelect = document.getElementById('field_oid');
                Array.from(oidSelect.options).forEach(function(opt) {
                    if (opt.getAttribute('data-dynamic') === 'true') {
                        opt.remove();
                    }
                });

                tabCreate.style.display = 'inline-block';
                tabSeparator.style.display = 'inline-block';

                if (currentDayActivities.length === 0) {
                    tabExisting.style.display = 'none';
                    tabSeparator.style.display = 'none';
                } else {
                    tabExisting.style.display = 'inline-block';
                    tabSeparator.style.display = 'inline-block';
                }

                tabCreate.classList.add('active');
                tabExisting.classList.remove('active');

                activitiesListView.style.display = 'none';
                activityFormView.style.display = 'block';
                returnBackBtn.style.display = 'none';

                actionBtn.textContent = 'Create Activity';
                actionBtn.classList.add('btn-create-mode');

                document.getElementById('field_aid').value = '';
                document.getElementById('field_oid').value = '';
                document.getElementById('field_aname').value = '';

                if (dateVal) {
                    var parsedDates = dateVal.split(',').map(s => s.trim()).filter(Boolean);
                    parsedDates.forEach(d => {
                        if (!selectedDates.includes(d)) selectedDates.push(d);
                    });
                } else if (selectedDates.length === 0 && currentSelectedDate) {
                    selectedDates = [currentSelectedDate];
                }

                calendarEl.classList.remove('calendar-active-mode-red');
                calendarEl.classList.add('calendar-active-mode-blue');
                renderDateChips();
                updateSelectionHighlight();

                document.getElementById('field_room').value = '';
                document.getElementById('field_timeslot').value = 'Lunch Recess';

                actionBtn.style.display = 'block';
                actionBtn.disabled = true;
                deleteBtn.style.display = 'none';
                panel.classList.add('active');
            }

            returnBackBtn.addEventListener('click', function() {
                activityFormView.style.display = 'none';
                activitiesListView.style.display = 'block';
                calendarEl.classList.remove('calendar-active-mode-blue');
                calendarEl.classList.add('calendar-active-mode-red');
                if (selectedDates.length > 1) {
                    selectedDates = [selectedDates[0]];
                    renderDateChips();
                    updateSelectionHighlight();
                }
            });

            tabExisting.addEventListener('click', function() {
                if (currentDayActivities.length > 0) {
                    calendarEl.classList.remove('calendar-active-mode-blue');
                    calendarEl.classList.add('calendar-active-mode-red');
                    selectedDates = [currentSelectedDate || selectedDates[0]];
                    renderDateChips();
                    updateSelectionHighlight();
                    openPanelWithActivityList(currentDayActivities);
                }
            });

            tabCreate.addEventListener('click', function() {
                if (selectedDates.length > 0 && selectedDates[0] < getTodayDateStr()) return;
                openActivityPanelForCreate(selectedDates.join(', ') || currentSelectedDate);
            });

            function closeRightPanel() {
                panel.classList.remove('active');
                deleteBtn.style.display = 'none';
                resetPanelPosition();
                unselectCalendarDate();
            }

            document.getElementById('closePanelBtn').addEventListener('click', closeRightPanel);

            formInputs.forEach(function(input) {
                input.addEventListener('input', checkFormModifications);
                input.addEventListener('change', checkFormModifications);
            });

            function checkFormModifications() {
                if (currentMode === 'create') {
                    var hasContent = document.getElementById('field_aname').value.trim() !== '' &&
                                     document.getElementById('field_oid').value !== '' &&
                                     document.getElementById('field_room').value.trim() !== '' &&
                                     selectedDates.length > 0;
                    actionBtn.disabled = !hasContent;
                } else {
                    var isModified = 
                        document.getElementById('field_oid').value != initialActivityData.oid ||
                        document.getElementById('field_aname').value != initialActivityData.aname ||
                        document.getElementById('field_date').value != initialActivityData.date ||
                        document.getElementById('field_room').value != initialActivityData.room ||
                        document.getElementById('field_timeslot').value != initialActivityData.timeslot;

                    actionBtn.disabled = !isModified;
                }
            }

            var confirmModal = document.getElementById('confirmModal');
            var confirmModalBtn = document.getElementById('confirmModalBtn');

            actionBtn.addEventListener('click', function() {
                if (actionBtn.disabled) return;

                confirmModalBtn.classList.remove('delete-mode');
                if (currentMode === 'create') {
                    document.getElementById('modalHeading').textContent = 'Confirm Creation';
                    document.getElementById('modalDescription').textContent = 'Are you sure you want to add this new activity to the selected date(s)?';
                } else {
                    document.getElementById('modalHeading').textContent = 'Confirm Modifications';
                    document.getElementById('modalDescription').textContent = 'Are you sure you want to save changes to this activity?';
                }
                confirmModal.classList.add('active');
            });

            deleteBtn.addEventListener('click', function() {
                currentMode = 'delete';
                confirmModalBtn.classList.add('delete-mode');
                document.getElementById('modalHeading').textContent = 'Confirm Deletion';
                document.getElementById('modalDescription').textContent = 'Are you sure you want to delete this activity? This action cannot be undone.';
                confirmModal.classList.add('active');
            });

            document.getElementById('cancelModalBtn').addEventListener('click', function() {
                confirmModal.classList.remove('active');
            });

            confirmModalBtn.addEventListener('click', function() {
                confirmModal.classList.remove('active');

                var formData = new FormData();
                formData.append('action', currentMode);
                formData.append('aid', document.getElementById('field_aid').value);

                if (currentMode !== 'delete') {
                    formData.append('oid', document.getElementById('field_oid').value);
                    formData.append('aname', document.getElementById('field_aname').value);
                    formData.append('date', document.getElementById('field_date').value);
                    formData.append('room', document.getElementById('field_room').value);
                    formData.append('timeslot', document.getElementById('field_timeslot').value);
                }

                fetch('teacher.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (currentMode === 'delete') {
                            var activeEvt = calendar.getEventById(activeEventId);
                            if (activeEvt) activeEvt.remove();
                            alert('Activity deleted successfully!');
                            closeRightPanel();
                        } else if (currentMode === 'modify') {
                            var anameVal = document.getElementById('field_aname').value;
                            var dateVal = document.getElementById('field_date').value;
                            var oidVal = document.getElementById('field_oid').value;
                            var roomVal = document.getElementById('field_room').value;
                            var timeslotVal = document.getElementById('field_timeslot').value;
                            
                            var oidSelect = document.getElementById('field_oid');
                            var selectedOrgText = oidSelect.options[oidSelect.selectedIndex] ? oidSelect.options[oidSelect.selectedIndex].text : '';
                            var onameVal = selectedOrgText.includes('.') ? selectedOrgText.split('.')[1].trim() : selectedOrgText;

                            var activeEvt = calendar.getEventById(activeEventId);
                            if (activeEvt) {
                                activeEvt.setProp('title', anameVal);
                                activeEvt.setStart(dateVal);
                                activeEvt.setExtendedProp('aname', anameVal);
                                activeEvt.setExtendedProp('date', dateVal);
                                activeEvt.setExtendedProp('oid', oidVal);
                                activeEvt.setExtendedProp('oname', onameVal);
                                activeEvt.setExtendedProp('room', roomVal);
                                activeEvt.setExtendedProp('timeslot', timeslotVal);
                            }

                            initialActivityData = { aid: document.getElementById('field_aid').value, oid: oidVal, oname: onameVal, aname: anameVal, date: dateVal, room: roomVal, timeslot: timeslotVal };
                            alert('Activity updated successfully!');
                            actionBtn.disabled = true;
                        } else if (currentMode === 'create') {
                            var anameVal = document.getElementById('field_aname').value;
                            var oidVal = document.getElementById('field_oid').value;
                            var roomVal = document.getElementById('field_room').value;
                            var timeslotVal = document.getElementById('field_timeslot').value;

                            var oidSelect = document.getElementById('field_oid');
                            var selectedOrgText = oidSelect.options[oidSelect.selectedIndex] ? oidSelect.options[oidSelect.selectedIndex].text : '';
                            var onameVal = selectedOrgText.includes('.') ? selectedOrgText.split('.')[1].trim() : selectedOrgText;

                            if (data.aids && Array.isArray(data.aids)) {
                                data.aids.forEach(function(item) {
                                    calendar.addEvent({
                                        id: item.aid,
                                        title: anameVal,
                                        start: item.date,
                                        extendedProps: { aid: item.aid, oid: oidVal, oname: onameVal, aname: anameVal, date: item.date, room: roomVal, timeslot: timeslotVal, isManaged: true }
                                    });
                                });
                            }

                            alert('New activity/activities created successfully!');
                            closeRightPanel();
                        }

                        markCellsWithEvents();
                        markPastDays();
                        updateSelectionHighlight();
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error occurred'));
                    }
                })
                .catch(err => {
                    alert('Request failed: ' + err);
                });
            });
        });
    </script>
</body>
</html>
