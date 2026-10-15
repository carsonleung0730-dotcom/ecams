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
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Retrieve logged-in username from session
$loggedInUser = '';
if (!empty($_SESSION['username'])) {
    $loggedInUser = $_SESSION['username'];
} elseif (!empty($_SESSION['user'])) {
    $loggedInUser = $_SESSION['user'];
} elseif (!empty($_SESSION['name'])) {
    $loggedInUser = $_SESSION['name'];
} else {
    $loggedInUser = 'User';
}

$displayUsername = htmlspecialchars($loggedInUser);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - Admin Dashboard</title>
    <link rel="icon" type="image/x-icon" href="sECAMS.png">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: #e0f2fe;
            color: #1e293b;
            display: flex;
            flex-direction: column;
        }

        /* Header Layout */
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

        .header-content {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            height: 85px;
            width: auto;
            display: block;
        }

        .header-titles h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #2e8ccb;
            letter-spacing: -0.02em;
        }

        .sub-header-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 2px;
        }

        .sub-header-row p {
            font-size: 0.85rem;
            color: #64748b;
        }

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

        .btn-logout:hover {
            background-color: #9f1239;
        }

        .clock-widget {
            text-align: right;
            padding: 0.5rem 0.85rem;
        }

        .clock-time {
            font-size: 1.15rem;
            font-weight: 700;
            color: #0f172a;
            font-variant-numeric: tabular-nums;
        }

        .clock-date {
            font-size: 0.75rem;
            font-weight: 500;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .app-body {
            flex: 1;
            display: flex;
            height: calc(100vh - 110px);
            position: relative;
            overflow: hidden;
        }

        /* Left Side Navigation Panel */
        .side-panel {
            width: 280px;
            background-color: #ffffff;
            border-right: 4px double #1037e572;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            height: 100%;
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
        }

        .side-panel .nav-btn:hover {
            border: 8px double #ffffff !important;
            z-index: 2;
        }

        .btn-icon {
            width: 280px;
            height: 200px;
            object-fit: contain;
        }

        .nav-btn-org { background-color: #fea768; border-bottom: 2px solid #ea580c; }
        .nav-btn-activity { background-color: #dc2626; border-bottom: 2px solid #991b1b; }
        .nav-btn-user { background-color: #881337; }

        /* Main Welcome Container */
        main {
            flex: 1;
            padding: 2.5rem;
            background-color: #d6e6f9;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            overflow-y: auto;
        }

        .welcome-card {
            background-color: #ffffff;
            border-radius: 16px;
            padding: 3.5rem 3rem;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.1);
            border: 1px solid #dbeafe;
            text-align: center;
            max-width: 700px;
            width: 100%;
        }

        .welcome-title {
            font-size: 2.75rem;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 1rem;
            letter-spacing: -0.03em;
        }

        .welcome-subtitle {
            font-size: 1.25rem;
            font-weight: 500;
            color: #64748b;
            line-height: 1.6;
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
            <a href="a-org.php" class="nav-btn nav-btn-org">
                <img src="org.png" alt="Organization" class="btn-icon">
            </a>
            <a href="a-activity.php" class="nav-btn nav-btn-activity">
                <img src="act.png" alt="Activity" class="btn-icon">
            </a>
            <a href="a-user.php" class="nav-btn nav-btn-user">
                <img src="use.png" alt="User" class="btn-icon">
            </a>
        </aside>

        <main>
            <div class="welcome-card">
                <<h1>Welcome back, <?php echo htmlspecialchars($_SESSION['uname'] ?? 'Admin'); ?>!</h1>
                <p class="welcome-subtitle">Click the buttons on the left panel to perform different actions</p>
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
    </script>
</body>
</html>