<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

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

// 1. Fetch organizations where the student is a member
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

// 2. Retrieve all activities for those organizations (Past & Future)
$dbActivities = [];
if (isset($conn) && $conn instanceof mysqli && !empty($managedOrgIds)) {
    $idsString = implode(',', $managedOrgIds);
    $result = $conn->query("
        SELECT a.aid, a.oid, a.aname, a.date, a.room, a.timeslot, o.oname 
        FROM activity a 
        LEFT JOIN organisations o ON a.oid = o.oid 
        WHERE a.oid IN ($idsString)
        ORDER BY a.date DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dbActivities[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - Student Activities Calendar</title>
    <link rel="icon" type="image/x-icon" href="sECAMS.png">

    <!-- FullCalendar CDN -->
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
        .nav-btn-activity { background-color: #dc2626; }

        main { flex: 1; padding: 1.5rem; background-color: #d6e6f9; height: 100%; overflow-y: auto; display: flex; flex-direction: column; gap: 1rem; }

        .debug-container {
            background: #ffffff;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 0.9rem;
        }
        .debug-container h3 { margin-bottom: 0.5rem; color: #2563eb; }

        .calendar-container {
            max-width: 1000px;
            width: 100%;
            height: 600px;
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
                        <p>Student Activity View Dashboard</p>
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
            <a href="s-org.php" class="nav-btn nav-btn-org">
                <img src="org.png" alt="Organization" class="btn-icon">
            </a>
            <a href="s-activity.php" class="nav-btn nav-btn-activity is-active" id="navBtnActivity">
                <img src="act.png" alt="Activity" class="btn-icon">
            </a>
        </aside>

        <main>
            <!-- Temporary Verification Box to check fetched activities -->
            <div class="debug-container">
                <h3>Retrieved Activities Debug View (Not on Calendar yet):</h3>
                <pre><?php print_r($dbActivities); ?></pre>
            </div>

            <div class="calendar-container">
                <div id="ecams-calendar"></div>
            </div>
        </main>
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

            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                displayEventTime: false,
                headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listYear' },
                buttonText: { today: 'Today', dayGridMonth: 'Month', listYear: 'List All' },
                events: [], // Kept empty as requested
                handleWindowResize: true,
                stickyHeaderDates: true
            });

            calendar.render();
        });
    </script>
</body>
</html>
