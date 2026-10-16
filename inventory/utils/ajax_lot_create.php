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

$sku_id = (int) ($_POST['sku_id'] ?? 0);
$lot_code = trim((string) ($_POST['lot_code'] ?? ''));
$expiry_date = trim((string) ($_POST['expiry_date'] ?? ''));

if ($sku_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing SKU.']);
    exit;
}

try {
    $stmt = $conn->prepare('SELECT id, lot_required FROM sku WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $sku_id);
    $stmt->execute();
    $res = stmt_get_result_safe($stmt);
    $sku_row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    $stmt->close();

    if (!$sku_row) {
        echo json_encode(['ok' => false, 'message' => 'SKU not found.']);
        exit;
    }

    $lot_required = (int) ($sku_row['lot_required'] ?? 0) === 1;
    if ($lot_code === '' || ($lot_required && $expiry_date === '')) {
        echo json_encode(['ok' => false, 'message' => 'Lot code and expiry date are required for this SKU.']);
        exit;
    }

    if ($expiry_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid expiry date.']);
        exit;
    }

    if ($expiry_date !== '') {
        $today = (new DateTime('today'))->format('Y-m-d');
        if ($expiry_date < $today) {
            echo json_encode(['ok' => false, 'message' => 'Expiry date must be today or later.']);
            exit;
        }
    }

    $stmt = $conn->prepare('INSERT INTO lot (sku_id, lot_code, expiry_date) VALUES (?,?,?)');
    $expiry_for_db = $expiry_date === '' ? null : $expiry_date;
    $stmt->bind_param('iss', $sku_id, $lot_code, $expiry_for_db);
    $stmt->execute();
    $lot_id = (int) $stmt->insert_id;
    $stmt->close();

    echo json_encode(['ok' => true, 'lot_id' => $lot_id, 'message' => 'Lot created successfully.']);
    exit;
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        echo json_encode(['ok' => false, 'message' => 'Lot code already exists for this SKU.']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
    exit;
}
