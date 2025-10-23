<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/dbinv.php';

header('Content-Type: application/json; charset=utf-8');

// Make mysqli throw exceptions so we return JSON instead of a blank 500
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (\Throwable $_) {}

try {
  // --- helpers --------------------------------------------------------------
  $dbNameRes = $conn->query('SELECT DATABASE()'); // current db name
  $dbName = $dbNameRes->fetch_row()[0] ?? '';

  $hasCol = function(mysqli $conn, string $db, string $table, string $col): bool {
    $stmt = $conn->prepare("
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
      LIMIT 1
    ");
    $stmt->bind_param('sss', $db, $table, $col);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
  };

  // --- input ----------------------------------------------------------------
  $raw  = $_GET['code'] ?? $_POST['code'] ?? '';
  $code = trim(trim($raw), " \t\n\r\0\x0B'\""); // trim spaces + surrounding quotes
  if ($code === '') { echo json_encode(['ok'=>false,'error'=>'Missing code']); exit; }

  // --- find SKU -------------------------------------------------------------
  $skuSql = "
    SELECT id, sku_num, `desc`, `status`, COALESCE(quantity,0) AS sku_quantity
    FROM sku
    WHERE sku_num = ?
    LIMIT 1
  ";
  $skuStmt = $conn->prepare($skuSql);
  $skuStmt->bind_param('s', $code);
  $skuStmt->execute();
  $sku = $skuStmt->get_result()->fetch_assoc();
  $skuStmt->close();

  if (!$sku) {
    echo json_encode(['ok'=>false,'error'=>"SKU not found for code: {$code}"]); exit;
  }

  // --- figure out the PK column of `location` -------------------------------
  $locPk = $hasCol($conn, $dbName, 'location', 'id') ? 'id'
         : ($hasCol($conn, $dbName, 'location', 'loc_id') ? 'loc_id' : null);

  if ($locPk === null) {
    // We can still return SKU details even if we can’t resolve locations
    echo json_encode([
      'ok'=>true,
      'sku'=>[
        'id'=>(int)$sku['id'],
        'sku_num'=>$sku['sku_num'],
        'desc'=>$sku['desc'],
        'status'=>$sku['status'],
        'sku_quantity'=>(int)$sku['sku_quantity'],
        'scanned_code'=>$code,
      ],
      'total_on_hand'=>0,
      'locations'=>[],
      '_warning'=>'location table missing id/loc_id; update scan_api.php join if your schema differs',
    ]);
    exit;
  }

  // --- locations breakdown (use m.loc_id as the stable key) -----------------
  $locSql = "
    SELECT
      m.loc_id                                   AS loc_id,
      l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code,'-',l.bay_num,'-',l.level_code,'-',l.side) AS location_label,
      SUM(m.quantity_change)                     AS on_hand,
      MAX(m.created_at)                          AS last_movement
    FROM inventory_movements m
    JOIN location l ON l.`$locPk` = m.loc_id
    WHERE m.sku_id = ?
    GROUP BY m.loc_id, l.row_code, l.bay_num, l.level_code, l.side
    HAVING on_hand > 0
    ORDER BY l.row_code, l.bay_num, l.level_code, l.side
  ";
  $locStmt = $conn->prepare($locSql);
  $sku_id = (int)$sku['id'];
  $locStmt->bind_param('i', $sku_id);
  $locStmt->execute();
  $res = $locStmt->get_result();

  $locations = [];
  $total_on_hand = 0;
  while ($row = $res->fetch_assoc()) {
    $qty = (int)$row['on_hand'];
    $row['on_hand'] = $qty;
    $total_on_hand += $qty;
    $locations[] = $row;
  }
  $locStmt->close();

  // --- response -------------------------------------------------------------
  echo json_encode([
    'ok' => true,
    'sku' => [
      'id'           => (int)$sku['id'],
      'sku_num'      => $sku['sku_num'],
      'desc'         => $sku['desc'],
      'status'       => $sku['status'],
      'sku_quantity' => (int)$sku['sku_quantity'],
      'scanned_code' => $code,
    ],
    'total_on_hand' => (int)$total_on_hand,
    'locations'     => $locations,
  ]);

} catch (\mysqli_sql_exception $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'SQL error','detail'=>$e->getMessage()]);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
}
