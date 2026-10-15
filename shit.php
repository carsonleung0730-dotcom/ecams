<?php
session_start();
require_once 'db.php';

// 1. ACTIVE SESSION GUARD
if (isset($_SESSION['uid']) && !empty($_SESSION['role'])) {
    $role = strtolower(trim($_SESSION['role']));
    if (in_array($role, ['admin', 'teacher', 'monitor', 'student'])) {
        header("Location: {$role}.php");
        exit();
    }
}

// 2. PROCESS FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Redirect if either field is empty
    if (empty($username) || empty($password)) {
        header("Location: shit.php?error=empty_fields");
        exit();
    }

    // Query database strictly by username using 1 positional placeholder
    $stmt = $pdo->prepare("SELECT * FROM users WHERE name = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Validate credentials
    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);

        $_SESSION['uid']   = $user['uid'];
        $_SESSION['uname'] = $user['name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['class'] = $user['class'];
        $_SESSION['cid']   = $user['cid'];

        $role = strtolower(trim($user['role']));
        $_SESSION['role']  = $role;

        switch ($role) {
            case 'admin':
                header("Location: admin.php");
                break;
            case 'teacher':
                header("Location: teacher.php");
                break;
            case 'monitor':
                header("Location: monitor.php");
                break;
            case 'student':
            default:
                header("Location: student.php");
                break;
        }
        exit();
    } else {
        // Redirect on wrong username or password
        header("Location: shit.php?error=invalid_credentials");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECAMS - Log In</title>
  <link rel="icon" type="image/x-icon" href="sECAMS.png">
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: system-ui, -apple-system, sans-serif;
      background-color: #f1f5f9;
      color: #1e293b;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    header {
      background-color: #ffffff;
      border-bottom: 4px double #1037e572;
      padding: 1rem 2.5rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
      position: relative;
      z-index: 20;
    }
    .header-content {
      max-width: 1100px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between; /* Spreads branding left and clock right */
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

    /* Top Right Clock Widget Styling */
    .clock-widget {
      text-align: right;
      background-color: #f8fafc;
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

    .highlight-marker {
      background-color: rgba(230, 240, 250, 0.6); 
      padding: 2px 4px;
      border-radius: 3px;
    }
    main {
      flex: 1;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 2.5rem 1.5rem;
      position: relative;
      z-index: 10;
    }
    .container {
      width: 100%;
      max-width: 950px;
      display: grid;
      grid-template-columns: 1fr 480px;
      gap: 2.5rem;
      align-items: center;
    }
    .info-section {
      padding-right: 1rem;
      position: relative;
      z-index: 10;
    }

    .info-section h2 {
      font-size: 2rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.25;
      margin-bottom: 1rem;
    }
    mark.highlight {
      background-color: #fc2e00;
      color: #fbfaf9;
      padding: 2px 6px;
      border-radius: 4px;
    }
    .info-section p {
      font-size: 1rem;
      color: #000307;
      line-height: 1.6;
    }
    .login-card-circle {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 50%;
      width: 520px;
      height: 520px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 3.5rem;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
      position: relative;
      z-index: 30; 
    }
    .login-card-circle h3 {
      font-size: 1.25rem;
      font-weight: 700;
      color: #0f172a;
      margin-bottom: 0.15rem;
      text-align: center;
    }
    .login-card-circle .subtitle {
      font-size: 0.8rem;
      color: #64748b;
      margin-bottom: 1.25rem;
      text-align: center;
    }
    form {
      width: 100%;
      max-width: 280px;
    }
    .formg {
      margin-bottom: 0.9rem;
    }
    label {
      display: block;
      font-size: 0.8rem;
      font-weight: 600;
      margin-bottom: 0.3rem;
      color: #334155;
    }

    input, select {
      width: 100%;
      padding: 0.6rem 0.75rem;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      font-size: 0.875rem;
      background-color: #ffffff;
      color: #0f172a;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    input:focus, select:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    button {
      width: 100%;
      padding: 0.7rem;
      background-color: #2563eb;
      color: #ffffff;
      border: none;
      border-radius: 6px;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      margin-top: 0.4rem;
      transition: background-color 0.15s ease;
    }

    button:hover {
      background-color: #1d4ed8;
    }
    .bg-overlay {
      position: fixed;
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      z-index: -1;
      background-image: url('rbg.png');
      background-size: cover;
      background-position: top center;
      background-repeat: no-repeat;
      background-attachment: fixed;
      opacity: 0.35;
    }
    .side-overlay-image {
      opacity: 0.35;
      flex: 0 0 auto;
      max-width: 320px;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    @media (max-width: 850px) {
      .container {
        grid-template-columns: 1fr;
        gap: 2rem;
      }
      .info-section {
        text-align: center;
        padding-right: 0;
      }
      .login-card-circle {
        margin: 0 auto;
        width: 380px;
        height: 380px;
        padding: 2rem;
      }
      header {
        padding: 1rem;
      }
      .header-content {
        flex-direction: column;
        gap: 0.75rem;
      }
      .clock-widget {
        text-align: center;
      }
      .bg-overlay {
        top: 90px;
        height: calc(100vh - 90px);
      }
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
          <p>Efficient, Exquisite, Expertly-crafted ECA managing platform</p>
        </div>
      </div>
      <!-- Top Right Clock Widget -->
      <div class="clock-widget">
        <div class="clock-time" id="live-time">00:00:00 AM</div>
        <div class="clock-date" id="live-date">Loading date...</div>
      </div>
    </div>
  </header>

  <div class="bg-overlay"></div>

  <main>
    <div class="container">
      <div class="info-section">
        <h2></h2>
      </div>
      <div class="login-card-circle">
        <h1>Sign In</h1>
        <p class="subtitle">Enter your username and password.</p>
        <?php if (isset($_GET['error'])): ?>
            <?php if ($_GET['error'] === 'empty_fields'): ?>
              <p><mark class="highlight">Please enter both username and password.</mark></p>
            <?php elseif ($_GET['error'] === 'invalid_credentials'): ?>
              <p><mark class="highlight">Invalid username or password</mark></p>
            <?php endif; ?>
        <?php endif; ?>
        <form action="shit.php" method="POST">
          <div class="formg">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required placeholder="Chris Wong">
          </div>
          <div class="formg">
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required placeholder="********">
          </div>
          <button type="submit">Log In</button>
        </form>
      </div>
    </div>
  </main>

  <script>
    function updateClock() {
      const now = new Date();

      // Format time (12-hour format with AM/PM)
      const timeString = now.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
      });

      // Format date (e.g., Mon, Oct 5, 2026)
      const dateString = now.toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric'
      });

      document.getElementById('live-time').textContent = timeString;
      document.getElementById('live-date').textContent = dateString;
    }

    // Run clock on DOM load and refresh every second
    updateClock();
    setInterval(updateClock, 1000);
  </script>
</body>
</html>