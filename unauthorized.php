<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized Access</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: #ffffff;
            color: #991b1b;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            text-align: center;
        }
        .container {
            max-width: 1000px;
            display: flex;
            flex-direction: column;
            gap: 2rem;
            align-items: center;
        }
        h1 {
            font-size: 6rem;
            font-weight: 900;
            color: #dc2626;
            line-height: 1;
            text-transform: uppercase;
        }
        .btn-redirect {
            background-color: #991b1b;
            color: #ffffff;
            font-size: 1rem;
            font-weight: 700;
            padding: 1rem 2rem;
            border-radius: 8px;
            text-decoration: none;
            transition: background-color 0.2s, transform 0.1s;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .btn-redirect:hover {
            background-color: #7f1d1d;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>DONT DREAM OF SNEAKING INTO THIS CONFIDENTIAL SITE WHEN YOU ARE NOT AUTHORIZED U SNEAKY LITTLE PRICLK</h1>
        <a href="logout.php" class="btn-redirect">i know my mistake now and i am so ugly</a>
    </div>
</body>
</html>