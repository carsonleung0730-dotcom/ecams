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

// 1. Fetch organizations where the student is a monitor
$monitorOrgIds = [];
if (isset($conn) && $conn instanceof mysqli) {
    $monStmt = $conn->prepare("SELECT oid FROM monitor WHERE mid = ?");
    if ($monStmt) {
        $monStmt->bind_param("s", $studentUid);
        $monStmt->execute();
        $monRes = $monStmt->get_result();
        while ($row = $monRes->fetch_assoc()) {
            $monitorOrgIds[] = intval($row['oid']);
        }
        $monStmt->close();
    }
}

// 2. Fetch organizations where the student is a member
$orgIds = [];
if (isset($conn) && $conn instanceof mysqli) {
    $mOrgStmt = $conn->prepare("SELECT oid FROM membership WHERE sid = ?");
    if ($mOrgStmt) {
        $mOrgStmt->bind_param("s", $studentUid);
        $mOrgStmt->execute();
        $mRes = $mOrgStmt->get_result();
        while ($row = $mRes->fetch_assoc()) {
            $orgIds[] = intval($row['oid']);
        }
        $mOrgStmt->close();
    }
}

// Combine membership and monitor organization IDs
$allOrgIds = array_unique(array_merge($orgIds, $monitorOrgIds));

// 3. Fetch activities for these organizations
$dbActivities = [];
if (isset($conn) && $conn instanceof mysqli && !empty($allOrgIds)) {
    $placeholders = implode(',', array_fill(0, count($allOrgIds), '?'));
    $types = str_repeat('i', count($allOrgIds));
    
    $actStmt = $conn->prepare("
        SELECT a.aid, a.oid, a.aname, a.date, a.room, a.timeslot, o.oname 
        FROM activity a 
        LEFT JOIN organisations o ON a.oid = o.oid 
        WHERE a.oid IN ($placeholders)
    ");
    
    if ($actStmt) {
        $actStmt->bind_param($types, ...$allOrgIds);
        $actStmt->execute();
        $result = $actStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dbActivities[] = $row;
        }
        $actStmt->close();
    }
}

$events = array_map(function($act) use ($monitorOrgIds) {
    $isMonitor = in_array(intval($act['oid']), $monitorOrgIds, true);
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
            'room' => $act['room'] ?? 'TBD',
            'timeslot' => $act['timeslot'] ?? 'Lunch Recess',
            'isMonitor' => $isMonitor
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

        .calendar-active-mode-red .fc-daygrid-day.clicked-0:not(.fc-day-past) { background-color: #ea580c !important; }
        .calendar-active-mode-red .fc-daygrid-day.clicked-0:not(.fc-day-past) .fc-daygrid-day-number { color: #ffffff !important; font-weight: 700; }

        .fc-daygrid-day.has-event.clicked-0:not(.fc-day-past) { z-index: 4 !important; }

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
        .fc-v-event, .fc-h-event, .fc-daygrid-event { background-color: #76787e !important; border-radius: 4px; }

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
        .header-tabs { display: flex; align-items: center; gap: 8px; font-size: 0.95rem; font-weight: 700; color: #2563eb; }
        .close-btn { background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer; line-height: 1; }
        .close-btn:hover { color: #0f172a; }

        .panel-body-content { padding: 1.25rem; flex: 1; overflow-y: auto; display: flex; flex-direction: column; position: relative; }

        .form-top-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 0.85rem; }

        .btn-return-back {
            display: inline-flex; align-items: center; gap: 4px; background-color: #f1f5f9; color: #334155;
            border: 1px solid #cbd5e1; border-radius: 4px; padding: 0.35rem 0.65rem; font-size: 0.8rem;
            font-weight: 700; cursor: pointer; transition: background-color 0.15s ease;
        }
        .btn-return-back:hover { background-color: #e2e8f0; color: #0f172a; }

        .btn-attendance {
            display: inline-flex; align-items: center; justify-content: center; background-color: #dc2626; color: #ffffff;
            font-size: 0.8rem; font-weight: 700; border: none; border-radius: 4px; padding: 0.35rem 0.65rem; 
            cursor: pointer; text-decoration: none; transition: background-color 0.2s;
        }
        .btn-attendance:hover { background-color: #b91c1c; }

        .activity-cards-list { display: flex; flex-direction: column; gap: 0.75rem; margin-top: 0.25rem; }

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
            font-size: 0.95rem; color: #0f172a; outline: none; background-color: #f1f5f9; color: #64748b; cursor: not-allowed;
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
            <a href="s-org.php" class="nav-btn nav-btn-org">
                <img src="org.png" alt="Organization" class="btn-icon">
            </a>
            <a href="student.php" class="nav-btn nav-btn-activity is-active" id="navBtnActivity">
                <img src="act.png" alt="Activity" class="btn-icon">
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
                <div class="header-tabs">Activity Details</div>
                <button class="close-btn" id="closePanelBtn">×</button>
            </div>
            
            <div class="panel-body-content">
                <div id="activitiesListView" style="display: none;">
                    <div class="activity-cards-list" id="activityCardsContainer"></div>
                </div>

                <div id="activityFormView" style="display: none;">
                    <div class="form-top-row">
                        <button type="button" class="btn-return-back" id="returnBackBtn">← Back</button>
                        <a href="#" id="attendanceBtn" class="btn-attendance" style="display: none;">Attendance</a>
                    </div>

                    <form id="activityForm">
                        <div class="field-group">
                            <label for="field_aid">Activity ID (aid)</label>
                            <input type="text" id="field_aid" name="aid" readonly>
                        </div>

                        <div class="field-group">
                            <label for="field_oname">Organisation</label>
                            <input type="text" id="field_oname" name="oname" readonly>
                        </div>

                        <div class="field-group">
                            <label for="field_aname">Activity Name (aname)</label>
                            <input type="text" id="field_aname" name="aname" readonly>
                        </div>

                        <div class="field-group">
                            <label for="field_date">Date (date)</label>
                            <input type="text" id="field_date" name="date" readonly>
                        </div>

                        <div class="field-group">
                            <label for="field_room">Room (room)</label>
                            <input type="text" id="field_room" name="room" readonly>
                        </div>

                        <div class="field-group">
                            <label for="field_timeslot">Time Slot</label>
                            <input type="text" id="field_timeslot" name="timeslot" readonly>
                        </div>
                    </form>
                </div>
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
            var dbEvents = <?php echo json_encode($events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            var panel = document.getElementById('activityPanel');
            var activitiesListView = document.getElementById('activitiesListView');
            var activityCardsContainer = document.getElementById('activityCardsContainer');
            var activityFormView = document.getElementById('activityFormView');
            var returnBackBtn = document.getElementById('returnBackBtn');
            var attendanceBtn = document.getElementById('attendanceBtn');
            var navBtnActivity = document.getElementById('navBtnActivity');

            navBtnActivity.addEventListener('click', function(e) {
                e.preventDefault();
                closeRightPanel();
                window.location.href = 'student.php';
            });

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
                    markPastDays();
                },

                dateClick: function(info) {
                    var clickedDateStr = info.dateStr;
                    var dayActivities = calendar.getEvents().filter(function(e) {
                        return e.startStr.split('T')[0] === clickedDateStr;
                    });

                    if (dayActivities.length === 0) {
                        closeRightPanel();
                        return;
                    }

                    if (dayActivities.length === 1) {
                        showActivityDetails(dayActivities[0]);
                    } else {
                        openPanelWithActivityList(dayActivities);
                    }
                },

                eventClick: function(info) {
                    showActivityDetails(info.event);
                }
            });

            calendar.render();
            markPastDays();

            function openPanelWithActivityList(activitiesList) {
                activitiesListView.style.display = 'block';
                activityFormView.style.display = 'none';
                attendanceBtn.style.display = 'none';

                activityCardsContainer.innerHTML = '';
                activitiesList.forEach(function(evt) {
                    var card = document.createElement('div');
                    card.className = 'activity-card';
                    
                    var titleEl = document.createElement('div');
                    titleEl.className = 'activity-card-title';
                    titleEl.textContent = evt.title;

                    var subEl = document.createElement('div');
                    subEl.className = 'activity-card-sub';
                    subEl.textContent = 'Org: ' + evt.extendedProps.oname + ' | ' + evt.extendedProps.timeslot;

                    card.appendChild(titleEl);
                    card.appendChild(subEl);

                    card.addEventListener('click', function() {
                        showActivityDetails(evt);
                    });

                    activityCardsContainer.appendChild(card);
                });

                panel.classList.add('active');
            }

            function showActivityDetails(eventObj) {
                activitiesListView.style.display = 'none';
                activityFormView.style.display = 'block';

                var props = eventObj.extendedProps;
                document.getElementById('field_aid').value = props.aid || eventObj.id;
                document.getElementById('field_oname').value = props.oname;
                document.getElementById('field_aname').value = props.aname || eventObj.title;
                document.getElementById('field_date').value = props.date || eventObj.startStr.split('T')[0];
                document.getElementById('field_room').value = props.room;
                document.getElementById('field_timeslot').value = props.timeslot;

                const todayStr = getTodayDateStr();
                const actDate = props.date || eventObj.startStr.split('T')[0];
                const isPast = actDate < todayStr;
                const isMonitor = props.isMonitor === true || props.isMonitor === 1 || props.isMonitor === '1';

                // ONLY show attendance button for past activities AND if student is monitor of that organization
                if (isPast && isMonitor) {
                    attendanceBtn.style.display = 'inline-flex';
                    attendanceBtn.href = 'attendance.php?aid=' + (props.aid || eventObj.id);
                } else {
                    attendanceBtn.style.display = 'none';
                }

                panel.classList.add('active');
            }

            returnBackBtn.addEventListener('click', function() {
                activityFormView.style.display = 'none';
                activitiesListView.style.display = 'block';
                attendanceBtn.style.display = 'none';
            });

            function closeRightPanel() {
                panel.classList.remove('active');
                attendanceBtn.style.display = 'none';
            }

            document.getElementById('closePanelBtn').addEventListener('click', closeRightPanel);
        });
    </script>
</body>
</html>
