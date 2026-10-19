<?php
// Database configuration and session parameters
require_once 'db.php';   
session_start();

if (!isset($_SESSION['role']) \vert{}\vert{} strtolower($_SESSION['role']) !== 'student') {
    header('Location: unauthorized.php');
    exit();
}
if (!isset($_SESSION['uid']) && !isset($_SESSION['user_id'])) {
    header('Location: shit.php');
    exit();
}

$studentUid =$_SESSION['uid'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 2;

// 1. Fetch organizations where the student is a member
$managedOrgIds = [];
if (isset($conn) &&$conn instanceof mysqli) {
    $mOrgStmt =$conn->prepare("
        SELECT o.oid, o.oname 
        FROM membership m 
        JOIN organisations o ON m.oid = o.oid 
        WHERE m.sid = ? 
        ORDER BY o.oname ASC
    ");
    if ($mOrgStmt) {$mOrgStmt->bind_param("s", $studentUid);$mOrgStmt->execute();
        $mOrgRes =$mOrgStmt->get_result();
        while ($row =$mOrgRes->fetch_assoc()) {
            $managedOrgIds[] = intval($row['oid']);
        }
        $mOrgStmt->close();
    }
}

// 2. Fetch activities ONLY for the organizations this student belongs to
$dbActivities = [];
if (isset($conn) && $conn instanceof mysqli && !empty($managedOrgIds)) {
    $idsString = implode(',',$managedOrgIds);
    $result =$conn->query("
        SELECT a.aid, a.oid, a.aname, a.date, a.room, a.timeslot, o.oname 
        FROM activity a 
        LEFT JOIN organisations o ON a.oid = o.oid 
        WHERE a.oid IN ($idsString)
    ");
    if ($result) {
        while ($row =$result->fetch_assoc()) {
            $dbActivities[] =$row;
        }
    }
}

$events = array_map(function($act) {
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

        /* Past dates greyed out and isolated from hover effects[cite: 1] */
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

        /* Heatmap spread colors restricted strictly to non-past dates[cite: 1] */
        .calendar-active-mode-red .fc-daygrid-day:not(.fc-day-past) { background-color: #fff1f2 !important; }

        .calendar-active-mode-red .fc-daygrid-day.clicked-0:not(.fc-day-past) { background-color: #ea580c !important; }
        .calendar-active-mode-red .fc-daygrid-day.clicked-0:not(.fc-day-past) .fc-daygrid-day-number { color: #ffffff !important; font-weight: 700; }
        .calendar-active-mode-red .fc-daygrid-day.clicked-1:not(.fc-day-past) { background-color: #f2b88e !important; }
        .calendar-active-mode-red .fc-daygrid-day.clicked-1:not(.fc-day-past) .fc-daygrid-day-number { color: #ffffff !important; font-weight: 600; }
        .calendar-active-mode-red .fc-daygrid-day.clicked-2:not(.fc-day-past) { background-color: #f7e4b9 !important; }
        .calendar-active-mode-red .fc-daygrid-day.clicked-2:not(.fc-day-past) .fc-daygrid-day-number { color: #7c2d12 !important; font-weight: 600; }
        .calendar-active-mode-red .fc-daygrid-day.clicked-3:not(.fc-day-past) { background-color: #fffbe4 !important; }
        .calendar-active-mode-red .fc-daygrid-day.clicked-3:not(.fc-day-past) .fc-daygrid-day-number { color: #9f1239 !important; font-weight: 500; }

        .calendar-active-mode-red .fc-daygrid-day.has-event:not(.clicked-0):not(.fc-day-past),
        .calendar-active-mode-red .fc-daygrid-day.has-event:not(.clicked-0)[class*="clicked-"]:not(.fc-day-past) {
            background-color: #fecdd3 !important;
            z-index: 4 !important;
        }

        .calendar-active-mode-red .fc-daygrid-day.has-event:not(.clicked-0):not(.fc-day-past) .fc-daygrid-day-number,
        .calendar-active-mode-red .fc-daygrid-day.has-event:not(.clicked-0)[class*="clicked-"]:not(.fc-day-past) .fc-daygrid-day-number {
            color: #9f1239 !important;
            font-weight: 700 !important;
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
        .nav-btn-activity { background-color: #dc2626; }

        main { flex: 1; padding: 1.5rem; background-color: #d6e6f9; height: 100%; overflow: hidden; display: flex; flex-direction: column; }

        .calendar-container {
            max-width: 1000px; width: 100%; height: 100%; margin: 0; padding: 16px;
            background: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
            border: 1px solid #dbeafe; display: flex; flex-direction: column; box-sizing: border-box; overflow: hidden;
        }

        #ecams-calendar { flex: 1; min-height: 0; }

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
            var dbEvents = <?php echo json_encode($events); ?>;

            var currentSelectedDate = null;

            function unselectCalendarDate() {
                document.querySelectorAll('.fc-daygrid-day').forEach(function(dayEl) {
                    dayEl.classList.remove('clicked-0', 'clicked-1', 'clicked-2', 'clicked-3');
                });
                calendarEl.classList.remove('calendar-active-mode-red');
                currentSelectedDate = null;
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
                },

                dateClick: function(info) {
                    var clickedCell = info.dayEl;
                    var clickedDateStr = info.dateStr;
                    var todayStr = getTodayDateStr();
                    var isPast = clickedDateStr < todayStr;

                    var dayActivities = calendar.getEvents().filter(function(e) {
                        return e.startStr.split('T')[0] === clickedDateStr;
                    });

                    if (dayActivities.length === 0) {
                        unselectCalendarDate();
                        return;
                    }

                    var isAlreadySelected = clickedCell.classList.contains('clicked-0');
                    unselectCalendarDate();

                    if (isAlreadySelected) {
                        return;
                    }

                    currentSelectedDate = clickedDateStr;

                    if (!isPast) {
                        calendarEl.classList.add('calendar-active-mode-red');
                        applyHeatmapSpread(clickedDateStr);
                    }
                },

                eventClick: function(info) {
                    var eventDateStr = info.event.startStr.split('T')[0];
                    var todayStr = getTodayDateStr();
                    var isPast = eventDateStr < todayStr;

                    unselectCalendarDate();
                    currentSelectedDate = eventDateStr;

                    if (!isPast) {
                        calendarEl.classList.add('calendar-active-mode-red');
                        applyHeatmapSpread(currentSelectedDate);
                    }
                }
            });

            calendar.render();

            function applyHeatmapSpread(clickedDateStr) {
                var baseDate = new Date(clickedDateStr + 'T00:00:00');
                var baseDayOfWeek = baseDate.getDay();

                for (var rowOffset = -3; rowOffset <= 3; rowOffset++) {
                    for (var colOffset = -3; colOffset <= 3; colOffset++) {
                        var targetCol = baseDayOfWeek + colOffset;
                        if (targetCol < 0 || targetCol > 6) continue;

                        var totalDaysOffset = (rowOffset * 7) + colOffset;
                        var targetDate = new Date(baseDate);
                        targetDate.setDate(targetDate.getDate() + totalDaysOffset);

                        var year = targetDate.getFullYear();
                        var month = String(targetDate.getMonth() + 1).padStart(2, '0');
                        var day = String(targetDate.getDate()).padStart(2, '0');
                        var dateStr = year + '-' + month + '-' + day;

                        var targetEl = document.querySelector('.fc-daygrid-day[data-date="' + dateStr + '"]');
                        // Exclude past days from heatmap color injection[cite: 1]
                        if (targetEl && !targetEl.classList.contains('fc-day-past')) {
                            var distance = Math.max(Math.abs(rowOffset), Math.abs(colOffset));
                            if (distance <= 3) {
                                targetEl.classList.add('clicked-' + distance);
                            }
                        }
                    }
                }
            }

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
        });
    </script>
</body>
</html>
