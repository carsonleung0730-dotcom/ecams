<?php
session_start();

require_once 'db.php';

if (!isset($conn) \vert{}\vert{} !($conn instanceof mysqli)) {
    die("Database connection error.");
}

$userId =$_SESSION['uid'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 2;

$userInfo = [
    'uid' => $userId,
    'name' => 'User',
    'email' => 'user@ecams.local',
    'role' => 'student',
    'class' => '1A',
    'cid' => '1'
];

$userStmt =$conn->prepare("SELECT uid, name, email, role, class, cid FROM users WHERE uid = ?");
if ($userStmt) {$userStmt->bind_param("s", $userId);$userStmt->execute();
    $userRes =$userStmt->get_result();
    if ($row =$userRes->fetch_assoc()) {
        $userInfo =$row;
    }
    $userStmt->close();
}

$aid =$_GET['aid'] ?? 1;
$activity = null;

$actStmt =$conn->prepare("
    SELECT a.aid, a.aname, a.date, a.room, a.timeslot, o.oname, o.oid
    FROM activity a
    JOIN organisations o ON a.oid = o.oid
    WHERE a.aid = ?
");
if ($actStmt) {$actStmt->bind_param("i", $aid);$actStmt->execute();
    $actRes =$actStmt->get_result();
    if ($row =$actRes->fetch_assoc()) {
        $activity =$row;
    }
    $actStmt->close();
}

if (!$activity) {
    die("Activity not found. Requested Activity ID (aid): " . htmlspecialchars($aid));
}

$successMsg = '';$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {$attendanceData = $_POST['attendance'] ?? [];$conn->begin_transaction();
    try {
        $updateStmt =$conn->prepare("
            UPDATE attendance 
            SET attend = ?, rate = ?, `s.rate` = ? 
            WHERE aid = ? AND sid = ?
        ");

        foreach ($attendanceData as $sid =>$data) {
            $attend = isset($data['attend']) ? 1 : 0;
            $rate = max(0, min(10, intval($data['rate'] ?? 10)));
            $sRate = max(0, min(10, intval($data['s_rate'] ?? 10)));

            $updateStmt->bind_param("iiisi", $attend,$rate, $sRate,$aid, $sid);$updateStmt->execute();
        }
        $updateStmt->close();

        $conn->commit();
        
        $redirectPage = (strtolower($userInfo['role']) === 'teacher') ? 'teacher.php' : 'student.php';
        header("Location: " . $redirectPage);
        exit();
    } catch (Exception $e) {
        $conn->rollback();$errorMsg = "Failed to update attendance: " . $e->getMessage();
    }
}

$members = [];
$memStmt =$conn->prepare("
    SELECT u.uid, u.name, at.attend, at.rate, at.`s.rate` AS s_rate
    FROM membership m
    JOIN users u ON m.sid = u.uid
    LEFT JOIN attendance at ON at.aid = ? AND at.sid = u.uid
    WHERE m.oid = ?
");
if ($memStmt) {
    $memStmt->bind_param("ii", $aid, $activity['oid']);$memStmt->execute();
    $memRes =$memStmt->get_result();
    while ($row =$memRes->fetch_assoc()) {
        $members[] =$row;
    }
    $memStmt->close();
}

$returnDashboard = (strtolower($userInfo['role']) === 'teacher') ? 'teacher.php' : 'student.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - Activity Attendance</title>
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

        .app-body { flex: 1; display: flex; height: calc(100vh - 110px); position: relative; overflow: hidden; }
        .side-panel {
            width: 280px; background-color: #ffffff; border-right: 4px double #1037e572;
            display: flex; flex-direction: column; flex-shrink: 0; height: 100%;
        }
        .nav-btn-single {
            width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
            text-decoration: none; background-color: #2563eb; transition: all 0.2s ease-in-out; border: none;
        }
        .side-panel .nav-btn-single:hover { background-color: #1d4ed8; }
        .btn-icon-return { width: 350px; height: 350px; object-fit: contain; }

        main {
            flex: 1; padding: 2rem; background-color: #d6e6f9; display: flex;
            flex-direction: column; align-items: center; justify-content: flex-start;
            height: 100%; overflow-y: auto; gap: 1.5rem;
        }

        .content-card {
            background-color: #ffffff;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.1);
            border: 1px solid #dbeafe;
            max-width: 900px;
            width: 100%;
        }
        .card-title {
            font-size: 1.25rem; font-weight: 700; color: #2563eb;
            margin-bottom: 1rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 0.5rem;
        }
        .activity-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        .info-item span { font-weight: 600; color: #64748b; }

        .members-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .members-table th, .members-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            font-size: 0.9rem;
        }
        .members-table th {
            background-color: #f8fafc;
            color: #475569;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.04em;
        }

        .switch {
            position: relative; display: inline-block; width: 48px; height: 24px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1; transition: .3s; border-radius: 24px;
        }
        .slider:before {
            position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px;
            background-color: white; transition: .3s; border-radius: 50%;
        }
        input:checked + .slider { background-color: #2563eb; }
        input:checked + .slider:before { transform: translateX(24px); }

        .link-all {
            color: #2563eb;
            text-decoration: underline;
            cursor: pointer;
            font-weight: 700;
            margin-left: 8px;
            font-size: 0.75rem;
        }
        .link-all:hover { color: #1d4ed8; }

        .stepper-container {
            display: flex; align-items: center; gap: 4px; max-width: 120px;
        }
        .stepper-btn {
            background-color: #f1f5f9; border: 1px solid #cbd5e1; width: 28px; height: 28px;
            font-weight: bold; cursor: pointer; border-radius: 4px; display: flex; align-items: center; justify-content: center; user-select: none;
        }
        .stepper-btn:hover { background-color: #e2e8f0; }
        .stepper-input {
            width: 40px; height: 28px; text-align: center; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.9rem;
        }
        .stepper-input::-webkit-inner-spin-button, .stepper-input::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }

        .btn-submit {
            background-color: #16a34a; color: #ffffff; border: none; padding: 0.75rem 1.5rem;
            font-size: 1rem; font-weight: 700; border-radius: 8px; cursor: pointer; transition: background-color 0.2s;
            margin-top: 1.5rem; display: block; width: 100%; text-align: center;
        }
        .btn-submit:hover { background-color: #15803d; }

        .alert-success { background-color: #dcfce7; color: #166534; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; font-weight: 600; }
        .alert-error { background-color: #fee2e2; color: #991b1b; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; font-weight: 600; }
        
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background-color: rgba(15, 23, 42, 0.5);
            z-index: 2000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s ease;
        }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
        .modal-box { background-color: #ffffff; width: 90%; max-width: 400px; border-radius: 12px; padding: 1.5rem; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); text-align: center; }
        .modal-box h3 { font-size: 1.15rem; color: #0f172a; margin-bottom: 0.5rem; font-weight: 700; }
        .modal-box p { font-size: 0.9rem; color: #64748b; margin-bottom: 1.5rem; }
        .modal-actions { display: flex; gap: 0.75rem; justify-content: center; }
        .btn-modal-cancel { flex: 1; padding: 0.65rem; background-color: #64748b; color: #ffffff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-modal-confirm { flex: 1; padding: 0.65rem; background-color: #2563eb; color: #ffffff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
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
                        <p>Activity Attendance Management</p>
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
            <a href="<?= htmlspecialchars($returnDashboard) ?>" class="nav-btn-single">
                <img src="return.png" alt="Return" class="btn-icon-return">
            </a>
        </aside>

        <main>
            <div class="content-card">
                <div class="card-title">Activity Details</div>
                <?php if (!empty($successMsg)): ?>
                    <div class="alert-success"><?= htmlspecialchars($successMsg) ?></div>
                <?php endif; ?>
                <?php if (!empty($errorMsg)): ?>
                    <div class="alert-error"><?= htmlspecialchars($errorMsg) ?></div>
                <?php endif; ?>

                <div class="activity-info-grid">
                    <div class="info-item"><span>Activity Name:</span> <?= htmlspecialchars($activity['aname']) ?></div>
                    <div class="info-item"><span>Organisation:</span> <?= htmlspecialchars($activity['oname']) ?></div>
                    <div class="info-item"><span>Date:</span> <?= htmlspecialchars($activity['date']) ?></div>
                    <div class="info-item"><span>Room:</span> <?= htmlspecialchars($activity['room']) ?></div>
                    <div class="info-item"><span>Time Slot:</span> <?= htmlspecialchars($activity['timeslot']) ?></div>
                </div>

                <div class="card-title" style="margin-top: 1.5rem;">Organisation Members Attendance</div>

                <form id="attendanceForm" method="POST">
                    <input type="hidden" name="submit_attendance" value="1">

                    <table class="members-table">
                        <thead>
                            <tr>
                                <th>UID</th>
                                <th>Name</th>
                                <th>
                                    Attendance <span class="link-all" id="selectAllLink">ALL</span>
                                </th>
                                <th>Rate (0-10)</th>
                                <th>S.Rate (0-10)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($members)): ?>
                                <tr><td colspan="5" style="text-align: center; color: #64748b;">No members found in this organisation.</td></tr>
                            <?php else: ?>
                                <?php foreach ($members as$m): ?>
                                    <?php 
                                        $uid =$m['uid'];
                                        $attendVal =$m['attend'] ?? 0;
                                        $rateVal = $m['rate'] !== null ? $m['rate'] : 10;
                                        $sRateVal = $m['s_rate'] !== null ? $m['s_rate'] : 10;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($uid) ?></td>
                                        <td><?= htmlspecialchars($m['name']) ?></td>
                                        <td>
                                            <label class="switch">
                                                <input type="checkbox" name="attendance[<?= $uid ?>][attend]" value="1" class="attend-checkbox" <?= $attendVal == 1 ? 'checked' : '' ?>>
                                                <span class="slider"></span>
                                            </label>
                                        </td>
                                        <td>
                                            <div class="stepper-container">
                                                <button type="button" class="stepper-btn" data-dir="-1">&#8249;</button>
                                                <input type="number" name="attendance[<?= $uid ?>][rate]" class="stepper-input" value="<?= $rateVal ?>" min="0" max="10">
                                                <button type="button" class="stepper-btn" data-dir="1">&#8250;</button>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="stepper-container">
                                                <button type="button" class="stepper-btn" data-dir="-1">&#8249;</button>
                                                <input type="number" name="attendance[<?= $uid ?>][s_rate]" class="stepper-input" value="<?= $sRateVal ?>" min="0" max="10">
                                                <button type="button" class="stepper-btn" data-dir="1">&#8250;</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <button type="button" class="btn-submit" id="openModalBtn">Submit Attendance</button>
                </form>
            </div>
        </main>
    </div>

    <div class="modal-overlay" id="confirmModal">
        <div class="modal-box">
            <h3>Confirm Submission</h3>
            <p>Are you sure you want to submit? This action cannot be reversed.</p>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" id="cancelModalBtn">Cancel</button>
                <button type="button" class="btn-modal-confirm" id="confirmModalBtn">Confirm</button>
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

        const selectAllLink = document.getElementById('selectAllLink');
        let allSelected = false;
        selectAllLink.addEventListener('click', () => {
            allSelected = !allSelected;
            const checkboxes = document.querySelectorAll('.attend-checkbox');
            checkboxes.forEach(cb => cb.checked = allSelected);
            selectAllLink.textContent = allSelected ? 'NONE' : 'ALL';
        });

        function stepValue(input, direction) {
            let val = parseInt(input.value) || 0;
            val = Math.max(0, Math.min(10, val + direction));
            input.value = val;
        }

        document.querySelectorAll('.stepper-btn').forEach(btn => {
            const direction = parseInt(btn.getAttribute('data-dir'));
            const input = btn.parentElement.querySelector('.stepper-input');

            let holdTimer = null;
            let repeatInterval = null;

            function handlePress(e) {
                e.preventDefault();
                stepValue(input, direction);

                holdTimer = setTimeout(() => {
                    repeatInterval = setInterval(() => {
                        stepValue(input, direction);
                    }, 333);
                }, 670);
            }

            function clearPress() {
                clearTimeout(holdTimer);
                clearInterval(repeatInterval);
            }

            btn.addEventListener('mousedown', handlePress);
            btn.addEventListener('touchstart', handlePress);

            ['mouseup', 'mouseleave', 'touchend', 'touchcancel'].forEach(evt => {
                btn.addEventListener(evt, clearPress);
            });
        });

        const modal = document.getElementById('confirmModal');
        document.getElementById('openModalBtn').addEventListener('click', () => {
            modal.classList.add('active');
        });
        document.getElementById('cancelModalBtn').addEventListener('click', () => {
            modal.classList.remove('active');
        });
        document.getElementById('confirmModalBtn').addEventListener('click', () => {
            document.getElementById('attendanceForm').submit();
        });
    </script>
</body>
</html>
