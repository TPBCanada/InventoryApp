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


$lot_id = (int) ($_POST['lot_id'] ?? 0);
$expiry_date = trim((string) ($_POST['expiry_date'] ?? ''));
$adjustment_qty = (int) ($_POST['adjustment_qty'] ?? 0);

if ($lot_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid lot record.']);
    exit;
}

if ($expiry_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid expiry date.']);
    exit;
}

// try {
//     $stmt = $conn->prepare('UPDATE lot SET expiry_date = ? WHERE id = ?');
//     $expiry_for_db = $expiry_date === '' ? null : $expiry_date;
//     $stmt->bind_param('si', $expiry_for_db, $lot_id);
//     $stmt->execute();
//     $stmt->close();

//     echo json_encode(['ok' => true, 'message' => 'Lot updated successfully.']);
//     exit;
// } catch (Throwable $e) {
//     http_response_code(500);
//     echo json_encode(['ok' => false, 'message' => 'Server error']);
//     exit;
// }

try {
    /** * 1. Update the Lot 
     * We use "quantity = quantity + ?" to allow the DB to handle the math atomically.
     */
    $stmt = $conn->prepare('UPDATE lot SET expiry_date = ?, quantity = quantity + ? WHERE id = ?');
    $expiry_for_db = $expiry_date === '' ? null : $expiry_date;
    $stmt->bind_param('sii', $expiry_for_db, $adjustment_qty, $lot_id);
    $stmt->execute();
    $stmt->close();

    /** * 2. Obtain the new quantity to update the UI
     * This establishes the current "On Hand" for this specific lot.
     */
    $res = $conn->prepare('SELECT quantity, sku_id FROM lot WHERE id = ?');
    $res->bind_param('i', $lot_id);
    $res->execute();
    $lot_data = $res->get_result()->fetch_assoc();
    $res->close();

    $new_lot_total = (int)($lot_data['quantity'] ?? 0);
    $sku_id = (int)($lot_data['sku_id'] ?? 0);

    /**
     * 3. (Optional but Recommended) Sync the Global Inventory
     * If you have a main inventory table, you can now sum all lots for this SKU.
     */
    // $global_sql = "UPDATE inventory SET total_on_hand = (SELECT SUM(quantity) FROM lot WHERE sku_id = $sku_id) WHERE id = $sku_id";
    // mysqli_query($conn, $global_sql);

    echo json_encode([
        'ok' => true, 
        'message' => 'Lot updated successfully.',
        'new_total' => $new_lot_total // This matches your JS `data.new_total` logic
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}