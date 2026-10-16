<?php
declare(strict_types=1);

// 1. USE THE WORKING CONFIG
// This matches your working db_structure_dump.php logic
require_once __DIR__ . '/../dbinv.php'; 

// 2. SET UP THE HANDSHAKE
$secret_key = "test1234"; 

// Capture incoming data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// 3. SECURITY & CONTENT CHECK
if (!isset($data['secret_key']) || $data['secret_key'] !== $secret_key) {
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Invalid Secret Key"]);
    exit;
}

if (!isset($data['items']) || !is_array($data['items'])) {
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "No items received"]);
    exit;
}

// 4. DATABASE CONNECTION (Using working variables from dbinv.php)
try {
    $conn = new mysqli($servername, $username, $password, $database);
    
    if ($conn->connect_error) {
        throw new Exception("DB Connection failed");
    }

    $whseId = $data['warehouse_id'] ?? 0;
    $rows_affected = 0;

    foreach ($data['items'] as $item) {
        $sku = $conn->real_escape_string((string)$item['sku']);
        $desc = $conn->real_escape_string((string)$item['description']);
        $qty = (int)$item['total_qty'];
        $pallet = $conn->real_escape_string((string)$item['pallet_number']);

        $sql = "INSERT INTO outwhse_inventory 
                (warehouse_location_id, sku, description, total_qty, pallet_number, status) 
                VALUES ('$whseId', '$sku', '$desc', $qty, '$pallet', 'In Stock')
                ON DUPLICATE KEY UPDATE 
                description = VALUES(description),
                total_qty = VALUES(total_qty),
                updated_at = NOW()";

        if ($conn->query($sql)) {
            $rows_affected++;
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        "status" => "success", 
        "rows_processed" => $rows_affected,
        "warehouse" => $whseId
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
} finally {
    if (isset($conn)) { $conn->close(); }
}