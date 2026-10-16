<?php
declare(strict_types=1);

http_response_code(403);

$logoutUrl = '/staging/logout.php';


// Resolve the app root from the page that was denied. The guarded pages live
// one directory below the app root, so this also works for module pages.
$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/dashboard.php');
$devPosition = strpos($scriptPath, '/dev/');

if ($devPosition !== false) {
    $appRoot = substr($scriptPath, 0, $devPosition + 4);
} else {
    $lastSlash = strrpos($scriptPath, '/');
    $scriptDir = $lastSlash === false ? '' : substr($scriptPath, 0, $lastSlash);
    $parentSlash = strrpos($scriptDir, '/');
    $appRoot = $parentSlash === false ? '' : substr($scriptDir, 0, $parentSlash);
}

// $logoutUrl = rtrim($appRoot, '/') . '/logout.php';
// if ($logoutUrl === '//logout.php') {
//     $logoutUrl = './logout.php';
// }

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
            color: #263238;
            background: #f7f7f7;
        }

        * { box-sizing: border-box; }

        html, body { min-height: 100%; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: start center;
            padding: 0 3px;
            background: #f7f7f7;
        }

        .access-denied {
            width: min(100%, 540px);
            min-height: 300px;
            margin-top: 0;
            padding: 34px 44px 36px;
            text-align: center;
            background: #fff;
            border: 1px solid #d5d5d5;
            border-radius: 4px;
        }

        h1 {
            margin: 3px 0 8px;
            font-size: clamp(2rem, 5vw, 2.2rem);
            font-weight: 400;
            line-height: 1.2;
            letter-spacing: -0.025em;
        }

        .rule {
            height: 1px;
            margin: 0 0 19px;
            background: #d6d6d6;
            border: 0;
        }

        p {
            margin: 0;
            color: #66717a;
            font-size: 14px;
            line-height: 1.55;
        }

        p + p { margin-top: 0; }

        .access-denied__reason {
            display: block;
            max-width: 410px;
            margin: 17px auto 0;
            padding: 11px 14px;
            border: 1px solid #cfe2f3;
            border-radius: 4px;
            background: #f4f9fd;
            color: #4f5b63;
            font-size: 14px;
            line-height: 1.55;
        }

        a {
            display: inline-block;
            color: #337ab7;
            font-size: 14px;
            text-decoration: none;
        }

        .access-denied__actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 25px;
        }

        .access-denied__button {
            min-width: 92px;
            padding: 9px 16px;
            border: 1px solid #337ab7;
            border-radius: 4px;
            background: #337ab7;
            color: #fff;
            cursor: pointer;
            font: inherit;
            font-size: 14px;
            text-align: center;
        }

        a:hover, a:focus-visible { text-decoration: underline; }

        @media (max-width: 480px) {
            .access-denied { padding: 30px 22px 34px; }
        }
    </style>
</head>
<body>
    <main class="access-denied" aria-labelledby="access-denied-title">
        <h1 id="access-denied-title">Access denied</h1>
        <hr class="rule">
        <p>You do not have permission to access this page.</p>
        <span class="access-denied__reason">
            Your account is signed in, but its assigned role does not include access to the dashboard.
            Please contact your Site Administrator(s) to request access.
        </span>
        <div class="access-denied__actions">
            <a class="access-denied__button" href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>">
                Log out
            </a>
        </div>
    </main>
</body>
</html>
