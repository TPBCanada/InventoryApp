<?php
declare(strict_types=1);

file_put_contents(
    __DIR__ . '/entry_test.log',
    date('c')
    . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '')
    . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '')
    . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo '<h1>Inventory Entry Test</h1>';
echo '<p>The server received this request.</p>';
echo '<p><a href="/inventory/dashboard.php?v=20260831-1">Continue to Inventory</a></p>';