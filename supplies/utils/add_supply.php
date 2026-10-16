<?php
declare(strict_types=1);
session_start();

// Basic auth guard
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../dbinv.php';

if (!$conn instanceof mysqli) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$input = file_get_contents('php://input');
$data = [];
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ctype, 'application/json') !== false && $input !== '') {
    $data = json_decode($input, true) ?: [];
} else {
    $data = $_POST;
}

$item_name = trim((string)($data['item_name'] ?? ''));
$model_no = trim((string)($data['model_no'] ?? ''));
$dimensions = trim((string)($data['dimensions'] ?? ''));
$bundle_qty = (int)($data['bundle_qty'] ?? 0);
$current_stock = max(0, (int)($data['current_stock'] ?? 0));
$min_stock = max(0, (int)($data['min_stock'] ?? 0));

if ($item_name === '' || $model_no === '' || $dimensions === '' || $bundle_qty <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

$item_type = 'SUPPLY';
$ref_note = $dimensions;
$uline = $model_no;
$last_count = null;
$total_units = null;

$stmt = $conn->prepare("
    INSERT INTO Warehouse_Current_Stock
      (item_name, item_type, qty_current_stock, qty_per_bundle, total_units, min_stock_level, reorder_flag, last_count_date, reference_note, uline_link_text, model_no)
    VALUES (?, ?, ?, ?, ?, ?, 'NO', ?, ?, ?, ?)
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to prepare statement']);
    exit;
}

$stmt->bind_param(
    'ssiiiissss',
    $item_name,
    $item_type,
    $current_stock,
    $bundle_qty,
    $total_units,
    $min_stock,
    $last_count,
    $ref_note,
    $uline,
    $model_no
);

$stmt->execute();
$newId = $stmt->insert_id;
$stmt->close();

echo json_encode(['ok' => true, 'id' => $newId]);
