<?php
// reorder_preview.php — Stash reorder selection into session and redirect to export_csv.php?mode=preview
declare(strict_types=1);
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$raw = $_POST['reorder_json'] ?? '';
if ($raw === '') {
    http_response_code(400);
    echo 'Missing reorder list.';
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo 'Invalid reorder list.';
    exit;
}

// Build the preview structure your export_csv.php expects
$columns = ['stock_id','item_name','item_type','qty_on_hand','min_stock','qty_to_order'];
$rows = [];
foreach ($data as $row) {
    $rows[] = [
        'stock_id'     => (string)($row['stock_id'] ?? ''),
        'item_name'    => (string)($row['item_name'] ?? ''),
        'item_type'    => (string)($row['item_type'] ?? ''),
        'qty_on_hand'  => (string)($row['qty_on_hand'] ?? '0'),
        'min_stock'    => (string)($row['min_stock'] ?? '0'),
        'qty_to_order' => (string)($row['qty_to_order'] ?? '0'),
    ];
}

// NOTE: This assumes your export_csv.php consumes the $_SESSION['ai_preview'] key.
$_SESSION['ai_preview'] = [
    'columns' => $columns,
    'rows'    => $rows,
    'sql'     => '/* reorder selection preview */'
];

// Redirect to the existing CSV exporter in preview mode
header('Location: export_csv.php?mode=preview');
exit;