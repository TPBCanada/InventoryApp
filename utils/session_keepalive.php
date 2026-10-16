<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/security.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'error' => 'unauthenticated',
    ]);
    exit;
}

$timeout_duration = 600;
$now = time();
$_SESSION['LAST_ACTIVITY'] = $now;

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'ok' => true,
    'lastActivity' => $now,
    'serverNow' => $now,
    'expiresAt' => $now + $timeout_duration,
]);
