<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/security.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$timeout_duration = 600;
$now = time();
$_SESSION['LAST_ACTIVITY'] = $now;

echo json_encode([
    'ok' => true,
    'lastActivity' => (int) $_SESSION['LAST_ACTIVITY'],
    'serverNow' => (int) $now,
    'expiresAt' => (int) ($now + $timeout_duration)
]);