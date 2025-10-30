<?php
declare(strict_types=1);

/**
 * Finds the ID of a location given its components.
 *
 * @param mysqli $conn The database connection.
 * @param string $row The row code.
 * @param string $bay The bay number.
 * @param string $lvl The level code.
 * @param string $side The side (A or B).
 * @return int|null The location ID or null if not found.
 */
function find_loc_id(mysqli $conn, string $row, string $bay, string $lvl, string $side): ?int {
    $st = $conn->prepare("
        SELECT id
        FROM location
        WHERE row_code=? AND CAST(bay_num AS UNSIGNED)=CAST(? AS UNSIGNED)
          AND level_code=? AND side=? LIMIT 1
    ");
    $st->bind_param('ssss', $row, $bay, $lvl, $side);
    $st->execute();
    return $st->get_result()->fetch_column() ?: null;
}

/**
 * Ensures a location exists in the database. Inserts it if not found.
 *
 * @param mysqli $conn The database connection.
 * @param string $row The row code.
 * @param string $bay The bay number.
 * @param string $lvl The level code.
 * @param string $side The side (A or B).
 * @return int The location ID. Returns 0 on failure (should not happen with find/insert logic).
 */
function ensure_location(mysqli $conn, string $row, string $bay, string $lvl, string $side): int {
    $id = find_loc_id($conn, $row, $bay, $lvl, $side);
    if ($id) return $id;
    $ins = $conn->prepare("INSERT IGNORE INTO location (row_code, bay_num, level_code, side) VALUES (?,?,?,?)");
    $ins->bind_param('ssss', $row, $bay, $lvl, $side);
    $ins->execute();
    return (int) (find_loc_id($conn, $row, $bay, $lvl, $side) ?: 0);
}

/**
 * Fetches the live on-hand stock for a specific location (hiding 0/NULL quantities).
 * Used by AJAX and post-transfer display.
 *
 * @param mysqli $conn The database connection.
 * @param string $row The row code.
 * @param string $bay The bay number.
 * @param string $lvl The level code.
 * @param string $side The side (A or B).
 * @return array An array of associative arrays: [{'sku_num': 'ABC', 'quantity': 10}]. Empty array on error or no stock.
 */
function fetch_stock(mysqli $conn, $row, $bay, $lvl, $side): array {
    if (!$row || !$bay || !$lvl || !$side) return [];
    $sql = "
        SELECT S.sku_num, SUM(COALESCE(I.quantity,0)) AS quantity
        FROM location L
        JOIN inventory I ON I.loc_id = L.id
        JOIN sku S       ON S.id      = I.sku_id
        WHERE L.row_code = ?
          AND CAST(L.bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
          AND L.level_code = ?
          AND L.side = ?
        GROUP BY S.sku_num
        HAVING SUM(COALESCE(I.quantity,0)) > 0
        ORDER BY S.sku_num
    ";
    try {
        $st = $conn->prepare($sql);
        $st->bind_param('ssss', $row, $bay, $lvl, $side);
        $st->execute();
        return $st->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (\Throwable $e) { error_log('[fetch_stock] ' . $e->getMessage()); return []; }
}
function fetch_all($conn, $sql) {
  $res = mysqli_query($conn, $sql);
  if (!$res) return [];
  $rows = [];
  while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  return $rows;
}
function table_exists($conn, $table){
  $t = mysqli_real_escape_string($conn, $table);
  $res = mysqli_query($conn, "SHOW TABLES LIKE '$t'");
  return $res && mysqli_num_rows($res) > 0;
}