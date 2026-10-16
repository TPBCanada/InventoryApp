<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/security.php';

file_put_contents(
    __DIR__ . '/entry.log',
    json_encode([
        'time' => date('c'),
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'session_name' => session_name(),
        'session_hash' => session_id() !== ''
            ? substr(hash('sha256', session_id()), 0, 12)
            : 'NONE',
        'username' => $_SESSION['username'] ?? 'MISSING',
        'user_id' => $_SESSION['user_id'] ?? 'MISSING',
        'role_id' => $_SESSION['role_id'] ?? 'MISSING',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

?>
<!doctype html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory Entry Test</title>
</head>
<body>

<h2>Inventory Entry OK</h2>

<p>
Session:
<?= htmlspecialchars(
    session_id() !== ''
        ? substr(hash('sha256', session_id()), 0, 12)
        : 'NONE'
) ?>
</p>

<p>
User:
<?= htmlspecialchars($_SESSION['username'] ?? 'MISSING') ?>
</p>

<p>
<a href="/staging/inventory/dashboard.php">
    Continue to Inventory Dashboard
</a>
</p>

</body>
</html>

