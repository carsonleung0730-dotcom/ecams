<?php
// Database configuration and session parameters
require_once 'db.php';   
session_start();

if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'student') {
    header('Location: unauthorized.php');
    exit();
}
if (!isset($_SESSION['uid']) && !isset($_SESSION['user_id'])) {
    header('Location: shit.php');
    exit();
}

$studentUid = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 2;

// 1. Fetch ALL organizations where the student is a member (multi-club support)
$managedOrgIds = [];
if (isset($conn) && $conn instanceof mysqli) {
    $mOrgStmt = $conn->prepare("
        SELECT o.oid, o.oname 
        FROM membership m 
        JOIN organisations o ON m.oid = o.oid 
        WHERE m.sid = ? 
        ORDER BY o.oname ASC
    ");
    if ($mOrgStmt) {
        $mOrgStmt->bind_param("s", $studentUid);
        $mOrgStmt->execute();
        $mOrgRes = $mOrgStmt->get_result();
        while ($row = $mOrgRes->fetch_assoc()) {
            $managedOrgIds[] = intval($row['oid']);
        }
        $mOrgStmt->close();
    }
}

// 2. Fetch activities for all of the student's clubs
$dbActivities = [];
if (isset($conn) && $conn instanceof mysqli && !empty($managedOrgIds)) {
    $placeholders = implode(',', array_fill(0, count($managedOrgIds), '?'));
    $types = str_repeat('i', count($managedOrgIds));
    
    $actStmt = $conn->prepare("
        SELECT a.aid, a.oid, a.aname, a.date, a.room, a.timeslot, o.oname 
        FROM activity a 
        LEFT JOIN organisations o ON a.oid = o.oid 
        WHERE a.oid IN ($placeholders)
    ");
    
    if ($actStmt) {
        $actStmt->bind_param($types, ...$managedOrgIds);
        $actStmt->execute();
        $result = $actStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dbActivities[] = $row;
        }
        $actStmt->close();
    }
}

$events = array_map(function($act) {
    return [
        'id' => $act['aid'],
        'title' => $act['aname'],
        'start' => $act['date'],
        'extendedProps' => [
            'aid' => $act['aid'],
            'oname' => $act['oname'] ?? 'Unknown Organisation',
            'room' => $act['room'] ?? 'TBD',
            'timeslot' => $act['timeslot'] ?? 'Lunch Recess'
        ]
    ];
}, $dbActivities);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - Student Activities Calendar</title>
    <link rel="icon" type="image/x-icon" href="sECAMS.png">

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
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
            height: 110px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .logo { height: 85px; width: auto; }
        .header-titles h1 { font-size: 1.35rem; font-weight: 700; color: #2e8ccb; }
        .sub-header-row { display: flex; align-items: center; gap: 12px; margin-top: 2px; }
        .sub-header-row p { font-size: 0.85rem; color: #64748b; }
        .btn-logout {
            background-color: #eb4174; color: #ffffff; font-size: 0.75rem; font-weight: 700;
            padding: 0.25rem 0.65rem; border-radius: 4px; text-decoration: none;
        }
        .btn-logout:hover { background-color: #9f1239; }

        .app-body { flex: 1; display: flex; height: calc(100vh - 110px); overflow: hidden; }
        .side-panel { width: 280px; background-color: #ffffff; border-right: 4px double #1037e572; display: flex; flex-direction: column; }
        .nav-btn {
            flex: 1; display: flex; align-items: center; justify-content: center; text-decoration: none;
            background-color: #dc2626; position: relative; border: none; cursor: pointer;
        }
        .nav-btn.is-active::before {
            content: ''; position: absolute; top: 12px; left: 12px; width: 14px; height: 14px;
            background-color: #2563eb; border-radius: 50%; border: 2px solid #ffffff; z-index: 10;
        }
        .nav-btn-org { background-color: #fea768; border-bottom: 2px solid #ea580c; }
        .btn-icon { width: 280px; height: 200px; object-fit: contain; }

        main { flex: 1; padding: 1.5rem; background-color: #d6e6f9; display: flex; flex-direction: column; }
        .calendar-container {
            max-width: 1000px; width: 100%; height: 100%; padding: 16px;
            background: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
            border: 1px solid #dbeafe; display: flex; flex-direction: column; overflow: hidden;
        }
        #ecams-calendar { flex: 1; min-height: 0; }

        .fc-daygrid-day.fc-day-past { background-color: #cbd5e1 !important; }
        .fc-daygrid-day.fc-day-past .fc-daygrid-day-number { color: #64748b !important; }
        .fc-v-event, .fc-h-event, .fc-daygrid-event { background-color: #2563eb !important; border-radius: 4px; }
    </style>
</head>
<body>

    <header>
        <div class="header-left">
            <img src="logo.png" alt="ECAMS Logo" class="logo">
            <div class="header-titles">
                <h1>Extracurricular Activity Management System</h1>
                <div class="sub-header-row">
                    <p>Student Activity View Dashboard</p>
                    <a href="logout.php" class="btn-logout">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <div class="app-body">
        <aside class="side-panel">
            <a href="s-org.php" class="nav-btn nav-btn-org">
                <img src="org.png" alt="Organization" class="btn-icon">
            </a>
            <a href="s-activity.php" class="nav-btn nav-btn-activity is-active">
                <img src="act.png" alt="Activity" class="btn-icon">
            </a>
        </aside>

        <main>
            <div class="calendar-container">
                <div id="ecams-calendar"></div>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('ecams-calendar');
            var dbEvents = <?php echo json_encode($events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                displayEventTime: false,
                headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listYear' },
                buttonText: { today: 'Today', dayGridMonth: 'Month', listYear: 'List All' },
                events: dbEvents,
                eventClick: function(info) {
                    var p = info.event.extendedProps;
                    alert('Activity: ' + info.event.title + '\nOrganisation: ' + p.oname + '\nRoom: ' + p.room + '\nTime: ' + p.timeslot);
                }
            });

            calendar.render();
        });
    </script>
</body>
</html>
