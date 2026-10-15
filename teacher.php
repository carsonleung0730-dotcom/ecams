<?php
session_start();

$host = 'localhost';
$dbname = 'ecams';
$username = 'root';
$password = '';
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
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

// Retrieve logged-in teacher identifier from session
$teacherUid = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 1;

// Fetch teacher account details
$stmt = $pdo->prepare("SELECT uid, name, email, role, class, cid FROM users WHERE uid = ?");
$stmt->execute([$teacherUid]);
$userInfo = $stmt->fetch() ?: [
    'uid' => $teacherUid,
    'name' => 'Teacher User',
    'email' => 'teacher@ecams.local',
    'role' => 'teacher',
    'class' => 'Staff',
    'cid' => 'N/A'
];

$monitorAlerts = [];
$debugActivitiesCount = 0;

try {
    // Teachers automatically manage organizations where tid matches their uid (or via monitor table)
    // Here we check organizations managed by this teacher (tid = teacherUid) and their activities
    $alertStmt = $pdo->prepare("
        SELECT a.aid, a.aname, a.date, a.timeslot, o.oname
        FROM organisations o
        JOIN activity a ON o.oid = a.oid
        WHERE o.tid = ?
        ORDER BY a.date ASC
    ");
    $alertStmt->execute([$teacherUid]);
    $managedActivities = $alertStmt->fetchAll();
    $debugActivitiesCount = count($managedActivities);

    $currentTime = new DateTime('now');

    foreach ($managedActivities as $act) {
        $actDateObj = new DateTime($act['date']);
        $todayObj = new DateTime($currentTime->format('Y-m-d'));
        
        $diffDays = (int)$todayObj->diff($actDateObj)->format('%r%a');
        $showAlert = false;

        // Show reminder box starting 24 hours before the activity (today or tomorrow)
        if ($diffDays === 0 || ($diffDays === 1 && $currentTime->format('H:i') >= '00:00')) {
            $showAlert = true;
        }

        if ($showAlert) {
            $monitorAlerts[] = [
                'aid' => $act['aid'],
                'act_name' => $act['aname'],
                'org_name' => $act['oname'],
                'timeslot' => $act['timeslot']
            ];
        }
    }
} catch (Exception $e) {
    // Handle error silently
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - Teacher Dashboard</title>
    <link rel="icon" type="image/x-icon" href="sECAMS.png">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: #e0f2fe;
            color: #1e293b;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        header {
            background-color: #ffffff;
            border-bottom: 4px double #1037e572;
            padding: 1rem 2.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            position: relative;
            z-index: 20;
            height: 110px;
            flex-shrink: 0;
        }
        .header-content { max-width: 100%; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .logo { height: 85px; width: auto; display: block; }
        .header-titles h1 { font-size: 1.35rem; font-weight: 700; color: #2e8ccb; letter-spacing: -0.02em; }
        .sub-header-row { display: flex; align-items: center; gap: 12px; margin-top: 2px; }
        .sub-header-row p { font-size: 0.85rem; color: #64748b; }
        .btn-logout {
            background-color: #eb4174; color: #ffffff; font-size: 0.75rem;
            font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 4px; text-decoration: none;
            transition: background-color 0.2s; display: inline-block;
        }
        .btn-logout:hover { background-color: #9f1239; }
        .clock-widget { text-align: right; padding: 0.5rem 0.85rem; }
        .clock-time { font-size: 1.15rem; font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; }
        .clock-date { font-size: 0.75rem; font-weight: 500; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }

        .monitor-alerts-container {
            position: fixed;
            top: 115px;
            right: 25px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .monitor-warning-box {
            background-color: #fef08a;
            border: 2px solid #ca8a04;
            color: #713f12;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: pulse-yellow 1.5s infinite;
        }
        .monitor-warning-box a { color: inherit; text-decoration: none; }
        .monitor-warning-box a:hover { text-decoration: underline; }
        .close-alert-btn {
            background: none; border: none; font-size: 1rem; font-weight: bold;
            cursor: pointer; color: inherit; opacity: 0.7;
        }
        .close-alert-btn:hover { opacity: 1; }

        @keyframes pulse-yellow {
            0% { box-shadow: 0 0 0 0 rgba(250, 204, 21, 0.4); }
            70% { box-shadow: 0 0 0 8px rgba(250, 204, 21, 0); }
            100% { box-shadow: 0 0 0 0 rgba(250, 204, 21, 0); }
        }

        .app-body { flex: 1; display: flex; height: calc(100vh - 110px); position: relative; overflow: hidden; }
        .side-panel {
            width: 280px; background-color: #ffffff; border-right: 4px double #1037e572;
            display: flex; flex-direction: column; flex-shrink: 0; height: 100%;
        }
        .nav-btn {
            flex: 1; display: flex; align-items: center; justify-content: center;
            text-decoration: none; font-size: 1.35rem; font-weight: 700; color: #ffffff;
            transition: all 0.2s ease-in-out; box-sizing: border-box; position: relative; border: none;
        }
        .side-panel .nav-btn:hover { border: 8px double #ffffff !important; z-index: 2; }
        .btn-icon { width: 280px; height: 200px; object-fit: contain; }
        .nav-btn-org { background-color: #fea768; border-bottom: 2px solid #ea580c; }
        .nav-btn-activity { background-color: #dc2626; border-bottom: 2px solid #991b1b; }
        .nav-btn-user { background-color: #881337; }

        main {
            flex: 1; padding: 2rem; background-color: #d6e6f9; display: flex;
            flex-direction: column; align-items: center; justify-content: flex-start;
            height: 100%; overflow-y: auto; gap: 1.5rem;
        }
        .welcome-card {
            background-color: #ffffff; border-radius: 16px; padding: 2rem 2.5rem;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.1); border: 1px solid #dbeafe;
            text-align: center; max-width: 650px; width: 100%; margin-top: 0.5rem;
        }
        .welcome-title { font-size: 2.2rem; font-weight: 800; color: #1e293b; margin-bottom: 0.5rem; letter-spacing: -0.03em; }
        .welcome-subtitle { font-size: 1.05rem; font-weight: 500; color: #64748b; }
        .pw { font-size: 0.75rem; color: #fd6601; }

        .user-details-card {
            background-color: #ffffff; border-radius: 16px; padding: 2rem 2.5rem;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.1); border: 1px solid #dbeafe;
            max-width: 650px; width: 100%;
        }
        .user-details-title { font-size: 1.25rem; font-weight: 700; color: #2563eb; margin-bottom: 1.25rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 0.5rem; }
        .detail-row { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid #f8fafc; font-size: 0.95rem; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: 600; color: #64748b; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; }
        .detail-value { font-weight: 700; color: #1e293b; }
    </style>
</head>
<body>

    <!-- Floating Monitor Warning Boxes linking to attendance.php?aid=... -->
    <?php if (!empty($monitorAlerts)): ?>
        <div class="monitor-alerts-container">
            <?php foreach ($monitorAlerts as $alert): ?>
                <div class="monitor-warning-box">
                    <a href="attendance.php?aid=<?= htmlspecialchars($alert['aid']) ?>">⚠️ Reminder: <?= htmlspecialchars($alert['act_name']) ?> (<?= htmlspecialchars($alert['org_name']) ?> - <?= htmlspecialchars($alert['timeslot']) ?>)</a>
                    <button type="button" class="close-alert-btn" onclick="this.parentElement.remove()">×</button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <header>
        <div class="header-content">
            <div class="header-left">
                <img src="logo.png" alt="ECAMS Logo" class="logo">
                <div class="header-titles">
                    <h1>Extracurricular Activity Management System</h1>
                    <div class="sub-header-row">
                        <p>Teacher Administration Platform</p>
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
                <img src="org.png" alt="Organisation" class="btn-icon">
            </a>
            <a href="t-activity.php" class="nav-btn nav-btn-activity">
                <img src="act.png" alt="Activity" class="btn-icon">
            </a>
            <a href="t-user.php" class="nav-btn nav-btn-user">
                <img src="use.png" alt="User" class="btn-icon">
            </a>
        </aside>

        <main>
            <div class="welcome-card">
                <h1 class="welcome-title">Welcome back, <?= htmlspecialchars($userInfo['name'] ?? 'Teacher') ?>!</h1>
                <p class="welcome-subtitle">Manage your organisation activities, users, and monitor attendance using the left menu.</p>
            </div>

            <div class="user-details-card">
                <div class="user-details-title">Teacher Account Details</div>
                <p class="pw">*Keep your login credentials secure.</p>
                <div class="detail-row">
                    <span class="detail-label">User ID (UID)</span>
                    <span class="detail-value"><?= htmlspecialchars($userInfo['uid']) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Full Name</span>
                    <span class="detail-value"><?= htmlspecialchars($userInfo['name']) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Email Address</span>
                    <span class="detail-value"><?= htmlspecialchars($userInfo['email']) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">User Role</span>
                    <span class="detail-value" style="text-transform: capitalize;"><?= htmlspecialchars($userInfo['role']) ?></span>
                </div>
            </div>
        </main>
    </div>

    <!-- DEBUGGER BANNER -->
    <div style="position:fixed; bottom:5px; left:5px; background:#111; color:#0f0; font-family:monospace; font-size:11px; padding:6px 10px; z-index:9999; border-radius:4px;">
        UID: <?= htmlspecialchars($teacherUid) ?> | Managed Activities Found: <?= $debugActivitiesCount ?> | Alerts Active: <?= count($monitorAlerts) ?>
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
    </script>
</body>
</html>