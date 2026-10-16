<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../dbusers.php';
$birthdaysConnection = $conn;
require_once __DIR__ . '/helpers.php';

try {
    $data = get_all_birthdays($birthdaysConnection);
    echo json_encode($data);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
