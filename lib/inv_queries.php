<?php
// dev/lib/inv_queries.php
declare(strict_types=1);

if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
  http_response_code(404);
  exit;
}

function getSkuWhereabouts(mysqli $conn, string $skuQuery, int $page = 1, int $limit = 50): array {
  $page  = max(1, $page);
  $limit = max(1, min(200, $limit));
  $offset = ($page - 1) * $limit;
  $like = '%' . $skuQuery . '%';

  $sql = "
    SELECT
      s.sku_num AS sku,
      l.loc_id, l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS location_code,
      COALESCE(SUM(CASE
        WHEN im.movement_type = 'IN'         THEN  im.quantity_change
        WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
        WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
        ELSE 0
      END), 0) AS on_hand,
      MAX(im.created_at) AS last_movement
    FROM inventory_movements im
    INNER JOIN sku s      ON s.id = im.sku_id
    INNER JOIN location l ON l.loc_id = im.loc_id
    WHERE s.sku_num LIKE ?
    GROUP BY s.sku_num, l.loc_id, l.row_code, l.bay_num, l.level_code, l.side
    HAVING on_hand <> 0
    ORDER BY l.row_code, CAST(l.bay_num AS UNSIGNED), l.level_code, l.side
    LIMIT ? OFFSET ?
  ";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return ['_error' => 'Database error (rows): ' . $conn->error, 'rows'=>[], 'grand_total'=>0, 'total_rows'=>0, 'page'=>1, 'pages'=>1];
  $stmt->bind_param('sii', $like, $limit, $offset);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = []; $pageTotal = 0;
  while ($r = $res->fetch_assoc()) {
    $r['on_hand'] = (int)$r['on_hand'];
    $r['last_movement'] = $r['last_movement'] ? date('Y-m-d H:i:s', strtotime($r['last_movement'])) : null;
    $rows[] = $r;
    $pageTotal += (int)$r['on_hand'];
  }
  $stmt->close();

  $countSql = "
    SELECT COUNT(*) AS c FROM (
      SELECT 1
      FROM inventory_movements im
      INNER JOIN sku s      ON s.id = im.sku_id
      INNER JOIN location l ON l.loc_id = im.loc_id
      WHERE s.sku_num LIKE ?
      GROUP BY s.sku_num, l.loc_id, l.row_code, l.bay_num, l.level_code, l.side
      HAVING COALESCE(SUM(CASE
        WHEN im.movement_type = 'IN'         THEN  im.quantity_change
        WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
        WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
        ELSE 0
      END), 0) <> 0
    ) x
  ";
  $cstmt = $conn->prepare($countSql);
  if (!$cstmt) return ['_error' => 'Database error (count): ' . $conn->error, 'rows'=>$rows, 'grand_total'=>0, 'total_rows'=>0, 'page'=>$page, 'pages'=>1];
  $cstmt->bind_param('s', $like);
  $cstmt->execute();
  $cres = $cstmt->get_result();
  $total_rows = (int)($cres->fetch_assoc()['c'] ?? 0);
  $cstmt->close();

  $gtSql = "
    SELECT COALESCE(SUM(on_hand),0) AS grand_total
    FROM (
      SELECT
        COALESCE(SUM(CASE
          WHEN im.movement_type = 'IN'         THEN  im.quantity_change
          WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
          WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
          ELSE 0
        END), 0) AS on_hand
      FROM inventory_movements im
      INNER JOIN sku s      ON s.id = im.sku_id
      INNER JOIN location l ON l.loc_id = im.loc_id
      WHERE s.sku_num LIKE ?
      GROUP BY s.sku_num, l.loc_id, l.row_code, l.bay_num, l.level_code, l.side
      HAVING on_hand <> 0
    ) t
  ";
  $gtstmt = $conn->prepare($gtSql);
  if (!$gtstmt) return ['_error' => 'Database error (grand): ' . $conn->error, 'rows'=>$rows, 'grand_total'=>0, 'total_rows'=>$total_rows, 'page'=>$page, 'pages'=>max(1, (int)ceil($total_rows / $limit))];
  $gtstmt->bind_param('s', $like);
  $gtstmt->execute();
  $gtres = $gtstmt->get_result();
  $grandTotal = (int)($gtres->fetch_assoc()['grand_total'] ?? 0);
  $gtstmt->close();

  return [
    'rows'        => $rows,
    'grand_total' => $grandTotal,
    'total_rows'  => $total_rows,
    'page'        => $page,
    'pages'       => max(1, (int)ceil($total_rows / $limit)),
  ];
}

/**
 * Detailed movements for a SKU with running balance per location (no users join).
 * NOTE: orders globally newest→oldest so the table is DESC by time/id.
 */
/**
 * Detailed movements for a SKU with running balance per location (no users join).
 * NOTE: orders globally newest→oldest so the table is DESC by time/id.
 */
function getSkuMovementDetails(mysqli $conn, string $skuQuery): array {
  $like = '%' . $skuQuery . '%';
  
  // SQL using Window Functions (Preferred)
  $sqlWin = "... [omitted for brevity, assume original contents] ...";

  if ($stmt = $conn->prepare($sqlWin)) {
    // ... [omitted for brevity, assume original execution logic] ...
    try {
      // ... execution logic ...
      $stmt->close();
      return ['rows' => $rows, 'used_window' => true];
    } catch (\Throwable $e) {
      $stmt->close();
      // fall through to fallback
    }
  }

  // Fallback (no window functions)
  $sql = "
    SELECT
      im.id, s.sku_num AS sku, im.sku_id, im.loc_id,
      l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS location_code,
      im.movement_type, im.quantity_change, im.reference, im.user_id,
      CAST(im.user_id AS CHAR) AS user_name, im.created_at
    FROM inventory_movements im
    INNER JOIN sku s       ON s.id = im.sku_id
    INNER JOIN location l ON l.loc_id = im.loc_id
    WHERE s.sku_num LIKE ?
    ORDER BY im.created_at DESC, im.id DESC -- NOTE: This ORDER BY must be replaced for running balance calc
  ";

  // --- Start Running Balance Calculation Fallback ---
  
  // 1. Modify the SQL to order correctly for running balance calculation: by loc_id ASC, then chronologically ASC.
  $modified_sql = str_replace(
    'ORDER BY im.created_at DESC, im.id DESC',
    'ORDER BY l.loc_id ASC, im.created_at ASC, im.id ASC',
    $sql
  );
  
  $stmt2 = $conn->prepare($modified_sql);
  
  if (!$stmt2) {
    return ['_error' => 'Database error (details): ' . $conn->error, 'rows'=>[], 'used_window'=>false];
  }
  
  $stmt2->bind_param('s', $like);
  $stmt2->execute();
  $res2 = $stmt2->get_result();

  // 2. Compute running balance in PHP
  $rows = [];
  $acc = []; // Accumulator array for running balance per location
  while ($r = $res2->fetch_assoc()) {
    $loc = (string)$r['loc_id'];
    if (!isset($acc[$loc])) $acc[$loc] = 0;
    
    $delta = 0;
    $qty_change = (int)$r['quantity_change'];
    
    if ($r['movement_type'] === 'IN')           $delta = $qty_change;
    elseif ($r['movement_type'] === 'OUT')      $delta = -$qty_change;
    elseif ($r['movement_type'] === 'ADJUSTMENT') $delta = $qty_change;
    
    $acc[$loc] += $delta;
    $r['running_balance'] = $acc[$loc];
    $r['quantity_change'] = $qty_change; // already cast to int above
    $r['created_at'] = $r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : null;
    $rows[] = $r;
  }
  $stmt2->close();

  // 3. Re-sort the final array to DESC order for display
  usort($rows, function($a,$b){
    if ($a['created_at'] === $b['created_at']) return $b['id'] <=> $a['id'];
    return strcmp($b['created_at'], $a['created_at']);
  });

  return ['rows' => $rows, 'used_window' => false];
}

/**
 * Extra helper: current SKUs at a location (used by Show SKUs).
 */
function getSkusAtLocation(mysqli $conn, int $loc_id): array {
  $sql = "
    SELECT
      s.sku_num AS sku,
      COALESCE(s.`desc`, '') AS description,
      COALESCE(SUM(CASE
        WHEN im.movement_type = 'IN'         THEN  im.quantity_change
        WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
        WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
        ELSE 0
      END), 0) AS on_hand,
      MAX(im.created_at) AS last_movement
    FROM inventory_movements im
    JOIN sku s ON s.id = im.sku_id
    WHERE im.loc_id = ?
    GROUP BY s.sku_num, s.`desc`
    HAVING on_hand <> 0
    ORDER BY on_hand DESC, s.sku_num ASC
  ";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return [];
  $stmt->bind_param("i", $loc_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $r['on_hand'] = (int)$r['on_hand'];
    $r['last_movement'] = $r['last_movement'] ? date('Y-m-d H:i:s', strtotime($r['last_movement'])) : '—';
    $rows[] = $r;
  }
  $stmt->close();
  return $rows;
}

// Add to receiving queue
if (isset($_POST['action']) && $_POST['action'] === 'queue_receive') {
  $stmt = $conn->prepare("
    INSERT INTO receiving_queue (sku_id, quantity, supplier_name, po_number, reference_note, received_by)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param('iisssi', $_POST['sku_id'], $_POST['quantity'], $_POST['supplier_name'], $_POST['po_number'], $_POST['reference_note'], $_SESSION['user_id']);
  $stmt->execute();
  header('Location: receive.php?msg=queued');
  exit;
}

// Approve — moves to inventory + log
if (isset($_POST['action']) && $_POST['action'] === 'approve_receive') {
    $id = (int)$_POST['id'];
    $conn->begin_transaction();

    // 1. Fetch record (FIX: Use prepared statement)
    $stmt_fetch = $conn->prepare("SELECT sku_id, quantity FROM receiving_queue WHERE id=? AND status='PENDING'");
    $stmt_fetch->bind_param('i', $id);
    $stmt_fetch->execute();
    $r = $stmt_fetch->get_result()->fetch_assoc();
    $stmt_fetch->close();

    if ($r) {
        $sku_id = $r['sku_id'];
        $qty = $r['quantity'];
        $user_id = $_SESSION['user_id'];
        $loc_id = 1; // Default Receiving location

        // 2. Update inventory (FIX: Use prepared statement)
        $stmt_inv = $conn->prepare("
            INSERT INTO inventory (sku_id, loc_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ");
        // Bind parameters: (sku_id, loc_id, quantity) AND (quantity for update)
        $stmt_inv->bind_param('iiii', $sku_id, $loc_id, $qty, $qty); 
        $stmt_inv->execute();
        $stmt_inv->close();
        
        // 3. Log movement (FIX: Use prepared statement)
        $ref = 'Receiving Approved';
        $mov_type = 'IN';
        $stmt_log = $conn->prepare("
            INSERT INTO inventory_movements (sku_id, loc_id, quantity_change, movement_type, reference, user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt_log->bind_param('iissis', $sku_id, $loc_id, $qty, $mov_type, $ref, $user_id);
        $stmt_log->execute();
        $stmt_log->close();

        // 4. Mark approved (FIX: Use prepared statement)
        $stmt_approve = $conn->prepare("UPDATE receiving_queue SET status='APPROVED', approved_at=NOW() WHERE id=?");
        $stmt_approve->bind_param('i', $id);
        $stmt_approve->execute();
        $stmt_approve->close();
    }
    $conn->commit();
    header('Location: receive.php?msg=approved');
    exit;
}

// Reject
if (isset($_POST['action']) && $_POST['action'] === 'reject_receive') {
    $id = (int)$_POST['id'];
    
    // FIX: Use prepared statement
    $stmt = $conn->prepare("UPDATE receiving_queue SET status='REJECTED' WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    
    header('Location: receive.php?msg=rejected');
    exit;
}

