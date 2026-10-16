<?php
session_start();

require_once 'db.php';
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'student') {
    // Redirect unauthorized users to an error page or their respective dashboard
    header('Location: unauthorized.php');
    exit();
}
if (!isset($_SESSION['uid']) && !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if (!isset($_SESSION['uid']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

$studentUid = $_SESSION['uid'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

$orgsData = [];
try {
    // Fetch organizations where the logged-in student is a member
    $orgStmt = $pdo->prepare("
        SELECT o.oid, o.oname, o.tid, 
               (SELECT uid FROM membership WHERE oid = o.oid LIMIT 1) AS monitor_uid, 
               u.name AS teacher_name
        FROM membership m
        JOIN organisations o ON m.oid = o.oid
        LEFT JOIN users u ON o.tid = u.uid
        WHERE m.sid = ?
        ORDER BY o.oid ASC
    ");
    $orgStmt->execute([$studentUid]);
    $orgsRaw = $orgStmt->fetchAll();

    foreach ($orgsRaw as $org) {
        $monitorUid = $org['monitor_uid'] ?? '';

        // Fetch members and place monitor/top user first
        $memStmt = $pdo->prepare("
            SELECT u.uid, u.name 
            FROM membership m
            JOIN users u ON m.sid = u.uid
            WHERE m.oid = ?
            ORDER BY CASE WHEN u.uid = ? THEN 0 ELSE 1 END, CAST(u.uid AS UNSIGNED) ASC, u.uid ASC
        ");
        $memStmt->execute([$org['oid'], $monitorUid]);
        $org['members'] = $memStmt->fetchAll();

        $safeOid = preg_replace('/[^a-zA-Z0-9_-]/', '_', $org['oid']);
        $existingImg = "uploads/org_{$safeOid}.png";
        $org['image_path'] = file_exists($existingImg) ? $existingImg : '';
        
        $orgsData[] = $org;
    }
} catch (PDOException $e) {
    $orgsData = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ECAMS - My Organisations</title>
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
        .nav-btn-activity { background-color: #dc2626; }
        
        main { flex: 1; padding: 1rem 2rem; background-color: #d6e6f9; overflow-x: auto; overflow-y: hidden; padding-top: 1rem; height: 100%; position: relative; }
        
        .orgs-horizontal-container { display: flex; gap: 1.5rem; height: 100%; align-items: flex-start; width: max-content; padding-bottom: 1rem; margin-top: 1.267rem; }
        
        .org-vertical-box {
            border-radius: 12px; border: 1px solid rgba(219, 234, 254, 0.6);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08); width: 320px; min-width: 320px;
            height: calc(100vh - 165px); display: flex; flex-direction: column; padding: 1.25rem; flex-shrink: 0;
            position: relative; backdrop-filter: blur(4px); overflow: hidden;
            background-color: rgba(52, 102, 235, 0.35);
        }
        
        .org-header-section { border-bottom: 2px solid #e2e8f0; padding-bottom: 0.75rem; margin-bottom: 0.85rem; flex-shrink: 0; }
        .org-title-row { display: flex; align-items: center; justify-content: space-between; gap: 4px; margin-bottom: 0.6rem; }
        .org-title { font-size: 0.95rem; font-weight: 800; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 100%; text-align: center; }
        
        .image-preview-wrapper { position: relative; width: 100%; margin-bottom: 0.6rem; margin-top: 0.4rem; display: none; }
        .image-preview-wrapper.has-image { display: block; }
        .org-image-preview { width: 100%; aspect-ratio: 1 / 1; background-color: #f8fafc; border-radius: 6px; object-fit: cover; border: 1px solid #cbd5e1; display: block; }

        .teacher-info { font-size: 0.85rem; color: #475569; text-align: left; margin-top: 0.4rem; position: relative; }
        .teacher-name-bold { font-weight: 700; color: #1e293b; font-size: 1.1rem; }
        .teacher-display { text-align: center; }

        .members-section-title { font-size: 0.8rem; font-weight: 710; color: #5d5c5c; text-transform: uppercase; margin-bottom: 0.5rem; flex-shrink: 0; }
        
        .members-table-wrapper { flex: 1; overflow-y: auto; border: 1px solid #f1f5f9; border-radius: 8px; background-color: #f8fafc; min-height: 0; }
        
        .members-table { width: 100%; border-collapse: collapse; text-align: left; }
        .members-table th { position: sticky; top: 0; background-color: #f1f5f9; font-size: 0.72rem; font-weight: 700; color: #475569; padding: 4px 8px; border-bottom: 1px solid #cbd5e1; z-index: 5; }
        .members-table td { padding: 4px 8px; font-size: 0.85rem; color: #1e293b; border-bottom: 1px solid #f1f5f9; }
        
        /* Monitor styling matching a-org.php */
        .monitor-row td { font-weight: bold; color: #0f172a }
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
            <a href="student.php" class="nav-btn nav-btn-org is-active" id="navBtnOrg">
                <img src="org.png" alt="Organization" class="btn-icon">
            </a>
            <a href="s-activity.php" class="nav-btn nav-btn-activity">
                <img src="act.png" alt="Activity" class="btn-icon">
            </a>
        </aside>

        <main id="mainContainer">
            <div class="orgs-horizontal-container">
                <?php if (!empty($orgsData)): ?>
                    <?php foreach ($orgsData as $org): 
                        $hasImage = !empty($org['image_path']);
                        $monitorUid = $org['monitor_uid'] ?? '';
                    ?>
                        <div class="org-vertical-box" data-oid="<?= htmlspecialchars($org['oid']) ?>">
                            <div class="org-header-section">
                                <div class="org-title-row">
                                    <div class="org-title" title="<?= htmlspecialchars($org['oname']) ?>"><?= htmlspecialchars($org['oid']) ?> - <?= htmlspecialchars($org['oname']) ?></div>
                                </div>
                                
                                <div class="image-preview-wrapper <?= $hasImage ? 'has-image' : '' ?>">
                                    <img src="<?= htmlspecialchars($org['image_path']) ?>" alt="Preview" class="org-image-preview">
                                </div>

                                <div class="teacher-info">
                                    <div class="teacher-display">
                                        Teacher: <span class="teacher-name-bold"><?= htmlspecialchars($org['teacher_name'] ?? 'Unassigned') ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="members-section-title"><?= count($org['members']) ?> Members</div>
                            
                            <div class="members-table-wrapper">
                                <?php if (!empty($org['members'])): ?>
                                    <table class="members-table">
                                        <thead>
                                            <tr>
                                                <th>UID</th>
                                                <th>Name</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($org['members'] as $index => $member): 
                                                $isMonitor = ($index === 0); // Top member treated as monitor if matched, or adjust condition as needed
                                            ?>
                                                <tr class="<?= $isMonitor ? 'monitor-row' : '' ?>">
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
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding: 2rem; font-size: 1rem; color: #1e293b;">You are not currently enrolled in any organisations.</div>
                <?php endif; ?>
            </div>
        </main>
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
            const boxes = mainContainer.querySelectorAll('.org-vertical-box');
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
    </script>
</body>
</html>
