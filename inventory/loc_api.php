<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
include __DIR__ . '/../dbinv.php';


// Define the endpoint
const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';


header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($conn) || !$conn || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DBinv failed to connect.']);
    exit;
}

try {
    $conn->set_charset('utf8mb4');

    $raw_code = $_GET['code'] ?? $_POST['code'] ?? '';
    $code = trim(trim($raw_code), " \t\n\r\0\x0B'\"");

    if ($code === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing location code']);
        exit;
    }

    // --- Location Lookup ---
    // Assuming your location codes are stored in the 'location' table.
    // The location label we want to match is ROW-BAY-LEVEL-SIDE (e.g., FLOOR-01-11-F)
    $locSql = "
    SELECT 
        id AS loc_id,
        row_code,
        bay_num,
        level_code,
        side,
        CONCAT(row_code, '-', bay_num, '-', level_code, '-', side) AS location_label
    FROM location
    WHERE CONCAT(row_code, '-', bay_num, '-', level_code, '-', side) = ?
    LIMIT 1
";

    $locStmt = $conn->prepare($locSql);
    $locStmt->bind_param('s', $code);
    $locStmt->execute();
    $location = $locStmt->get_result()->fetch_assoc();
    $locStmt->close();

    if (!$location) {
        echo json_encode(['ok' => false, 'error' => "Location '{$code}' not found."]);
        exit;
    }

    // Success response includes the verified location details
    echo json_encode([
        'ok' => true,
        'location' => [
            'loc_id' => (int) $location['loc_id'],
            'row_code' => $location['row_code'],
            'bay_num' => $location['bay_num'],
            'level_code' => $location['level_code'],
            'side' => $location['side'],
            'label' => $location['location_label'],
            'scanned_code' => $code,
        ]
    ]);

} catch (\mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SQL error', 'detail' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
}