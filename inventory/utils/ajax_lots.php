<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../dbinv.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Database connection error.']);
    exit;
}

try {
    $conn->set_charset('utf8mb4');
} catch (Throwable $_) {
}

header('Content-Type: application/json; charset=utf-8');

$sku_id = (int) ($_GET['sku_id'] ?? 0);
if ($sku_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing SKU.']);
    exit;
}

try {
    $stmt = $conn->prepare('
        SELECT
            lot.id,
            lot.lot_code,
            lot.expiry_date,
            COALESCE(SUM(inv.quantity), 0) AS on_hand
        FROM lot
        LEFT JOIN inventory inv ON inv.lot_id = lot.id
        WHERE lot.sku_id = ?
        GROUP BY lot.id, lot.lot_code, lot.expiry_date
        ORDER BY lot.expiry_date IS NULL, lot.expiry_date ASC, lot.lot_code ASC
    ');
    $stmt->bind_param('i', $sku_id);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    $stmt->close();

    echo json_encode(['ok' => true, 'items' => $rows]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
    exit;
}
