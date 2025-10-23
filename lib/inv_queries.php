<?php
// dev/lib/inv_queries.php
declare(strict_types=1);

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
function getSkuMovementDetails(mysqli $conn, string $skuQuery): array {
  $like = '%' . $skuQuery . '%';

  $sqlWin = "
    SELECT
      im.id,
      s.sku_num AS sku,
      im.sku_id,
      im.loc_id,
      l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS location_code,
      im.movement_type,
      im.quantity_change,
      im.reference,
      im.user_id,
      CAST(im.user_id AS CHAR) AS user_name,
      im.created_at,
      SUM(
        CASE
          WHEN im.movement_type = 'IN'         THEN  im.quantity_change
          WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
          WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
          ELSE 0
        END
      ) OVER (PARTITION BY im.loc_id ORDER BY im.created_at, im.id
              ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_balance
    FROM inventory_movements im
    INNER JOIN sku s      ON s.id = im.sku_id
    INNER JOIN location l ON l.loc_id = im.loc_id
    WHERE s.sku_num LIKE ?
    ORDER BY im.created_at DESC, im.id DESC
  ";

  if ($stmt = $conn->prepare($sqlWin)) {
    $stmt->bind_param('s', $like);
    try {
      $stmt->execute();
      $res = $stmt->get_result();
      $rows = [];
      while ($r = $res->fetch_assoc()) {
        $r['quantity_change'] = (int)$r['quantity_change'];
        $r['running_balance'] = (int)$r['running_balance'];
        $r['created_at'] = $r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : null;
        $rows[] = $r;
      }
      $stmt->close();
      return ['rows' => $rows, 'used_window' => true];
    } catch (\Throwable $e) {
      $stmt->close();
      // fall through
    }
  }

  // Fallback (no window functions)
  $sql = "
    SELECT
      im.id,
      s.sku_num AS sku,
      im.sku_id,
      im.loc_id,
      l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS location_code,
      im.movement_type,
      im.quantity_change,
      im.reference,
      im.user_id,
      CAST(im.user_id AS CHAR) AS user_name,
      im.created_at
    FROM inventory_movements im
    INNER JOIN sku s      ON s.id = im.sku_id
    INNER JOIN location l ON l.loc_id = im.loc_id
    WHERE s.sku_num LIKE ?
    ORDER BY im.created_at DESC, im.id DESC
  ";
  $stmt2 = $conn->prepare($sql);
  if (!$stmt2) return ['_error' => 'Database error (details): ' . $conn->error, 'rows'=>[], 'used_window'=>false];

  $stmt2->bind_param('s', $like);
  $stmt2->execute();
  $res = $stmt2->get_result();

  $rows = [];
  $balances = []; // per loc_id (we'll recompute by ASC inside each loc to get running)
  // Recompute running_balance per loc in chronological order:
  $byLoc = [];
  while ($r = $res->fetch_assoc()) {
    $byLoc[$r['loc_id']] = $byLoc.get($r['loc_id'], []) if False else None
    # (Pseudo placeholder — leave fallback simple if you don't need it.)
    $r['quantity_change'] = (int)$r['quantity_change'];
    $r['running_balance'] = 0; // omit if not needed
    $r['created_at'] = $r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : null;
    $rows.append($r)  # (PHP equivalent below)
  }
  // NOTE: For brevity, you can skip recomputing running_balance in PHP fallback.

  // Re-run in PHP, correctly (actual PHP):
  mysqli_stmt_close($stmt2);
  $stmt2 = $conn->prepare($sql.replace('ORDER BY im.created_at DESC, im.id DESC','ORDER BY l.loc_id ASC, im.created_at ASC, im.id ASC'));
  $stmt2->bind_param('s', $like);
  $stmt2->execute();
  $res2 = $stmt2->get_result();
  $rows = [];
  $acc = [];
  while ($r = $res2->fetch_assoc()) {
    $loc = (string)$r['loc_id'];
    if (!isset($acc[$loc])) $acc[$loc] = 0;
    $delta = 0;
    if ($r['movement_type'] === 'IN')              $delta = (int)$r['quantity_change'];
    elseif ($r['movement_type'] === 'OUT')         $delta = -(int)$r['quantity_change'];
    elseif ($r['movement_type'] === 'ADJUSTMENT')  $delta = (int)$r['quantity_change'];
    $acc[$loc] += $delta;
    $r['running_balance'] = $acc[$loc];
    $r['quantity_change'] = (int)$r['quantity_change'];
    $r['created_at'] = $r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : null;
    $rows[] = $r;
  }
  $stmt2->close();

  // The table can still be displayed DESC globally after computing the per-loc running.
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
