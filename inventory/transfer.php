<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/utils/helpers.php';



mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}
$username = $_SESSION['username'];
$user_id = (int) ($_SESSION['user_id'] ?? 0);
date_default_timezone_set('America/Toronto');
$title = 'Transfer Inventory';
$css = is_array($css ?? null) ? $css : [];
$css[] = '/refactor_css/transfer.css';

// Define rows that do not require Bay, Level, or Side (e.g., floor locations)
$special_rows_config = defined('SPECIAL_ROWS') ? SPECIAL_ROWS : ($dynamic_special_rows ?? ['RECEIVING', 'TUNNEL']);
$SPECIAL_ROWS_RAW = array_values(array_filter(array_map('trim', (array) $special_rows_config)));
$SPECIAL_ROWS = array_values(array_filter(array_map('strtoupper', $SPECIAL_ROWS_RAW)));
$SPECIAL_DEFAULT_BAY = '01';
$SPECIAL_DEFAULT_LEVEL = '11';
$SPECIAL_DEFAULT_SIDE = 'F';

$isSpecialRow = static function (string $row) use ($SPECIAL_ROWS): bool {
    return $row !== '' && in_array(strtoupper($row), $SPECIAL_ROWS, true);
};

if (!function_exists('generate_next_pallet_tag')) {
    function generate_next_pallet_tag(mysqli $conn): string
    {
        $prefix = 'P';
        $res = $conn->query("SELECT MAX(CAST(SUBSTRING(pallet_tag, 2) AS UNSIGNED)) AS max_seq FROM pallet WHERE pallet_tag LIKE 'P%'");
        $row = $res ? $res->fetch_assoc() : null;
        $next = (int) ($row['max_seq'] ?? 0) + 1;
        return sprintf('%s%03d', $prefix, $next);
    }
}

if (!function_exists('ensure_location_pallet')) {
    function ensure_location_pallet(mysqli $conn, int $loc_id): ?array
    {
        $stmt = $conn->prepare("SELECT id, pallet_tag FROM pallet WHERE loc_id = ? AND status = 'ACTIVE' ORDER BY id ASC LIMIT 1");
        $stmt->bind_param('i', $loc_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res->fetch_assoc() ?: null;
        $stmt->close();

        if ($existing) {
            return ['id' => (int) $existing['id'], 'tag' => (string) $existing['pallet_tag']];
        }

        $attempts = 0;
        do {
            $attempts++;
            $pallet_tag = generate_next_pallet_tag($conn);
            try {
                $stmtInsert = $conn->prepare("INSERT INTO pallet (pallet_tag, loc_id, status) VALUES (?, ?, 'ACTIVE')");
                $stmtInsert->bind_param('si', $pallet_tag, $loc_id);
                $stmtInsert->execute();
                $stmtInsert->close();
                return ['id' => (int) $conn->insert_id, 'tag' => $pallet_tag];
            } catch (\mysqli_sql_exception $e) {
                if ((int) $e->getCode() !== 1062) {
                    throw $e;
                }
            }
        } while ($attempts < 5);

        error_log('[transfer] Unable to prepare destination storage.');
        return null;
    }
}

if (!function_exists('get_pallet_info')) {
    function get_pallet_info(mysqli $conn, int $pallet_id): ?array
    {
        $stmt = $conn->prepare("SELECT id, pallet_tag, loc_id FROM pallet WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $pallet_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $info = $res->fetch_assoc() ?: null;
        $stmt->close();
        return $info ? [
            'id' => (int) $info['id'],
            'pallet_tag' => (string) $info['pallet_tag'],
            'loc_id' => (int) $info['loc_id'],
        ] : null;
    }
}


/* ---------- Inline AJAX: items by location (qty > 0) ---------- */
$ajaxAction = $_GET['ajax'] ?? null;
if ($ajaxAction === 'location_inventory') {
    $ajaxAction = 'inv_by_loc';
}
if ($ajaxAction === 'inv_by_loc') {
    // Kill any output buffers
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');

    // Basic auth guard
    if (!isset($_SESSION['username'])) {
        error_log('[transfer][inv_by_loc] auth_required ? no username in session');
        echo json_encode(['ok' => false, 'error' => 'auth_required']);
        exit;
    }

    // Log raw inputs
    $row = trim($_GET['row'] ?? '');
    $bay = trim($_GET['bay'] ?? '');
    $lvl = trim($_GET['lvl'] ?? '');
    $side = trim($_GET['side'] ?? '');

    $is_special_row = $isSpecialRow($row);

    if ($is_special_row) {
        // Apply special defaults and log them
        if ($bay === '')
            $bay = $SPECIAL_DEFAULT_BAY;
        if ($lvl === '')
            $lvl = $SPECIAL_DEFAULT_LEVEL;
        if ($side === '')
            $side = $SPECIAL_DEFAULT_SIDE;
    } else {
        if ($bay === '' || $lvl === '' || $side === '') {
            error_log('[transfer][inv_by_loc] ERROR: non-special row missing bay/level/side');
            echo json_encode(['ok' => false, 'error' => 'Location not fully specified.']);
            exit;
        }
    }

    $norm_row = strtoupper($row);
    $norm_bay = $bay === '' ? '' : ltrim($bay, '0');
    if ($norm_bay === '' && $bay !== '')
        $norm_bay = '0';
    $norm_lvl = strtoupper($lvl);
    $norm_side = strtoupper($side);

    try {
        // 1) Find location
        $locStmt = $conn->prepare("
            SELECT id, row_code, bay_num, level_code, side
            FROM location
            WHERE UPPER(row_code) = ?
              AND CAST(bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
              AND UPPER(level_code) = ?
              AND LEFT(UPPER(side), 1) = LEFT(?, 1)
              AND is_disabled = 0
            LIMIT 1
        ");
        $locStmt->bind_param('ssss', $norm_row, $norm_bay, $norm_lvl, $norm_side);
        $locStmt->execute();
        $locInfo = $locStmt->get_result()->fetch_assoc();
        $locStmt->close();

        

        if (!$locInfo) {
            error_log('[transfer][inv_by_loc] ERROR: location not found');
            echo json_encode(['ok' => false, 'error' => 'Location not found.']);
            exit;
        }

        $loc_id = (int) $locInfo['id'];
        $loc_label = format_location_string(
            (string) $locInfo['row_code'],
            (string) $locInfo['bay_num'],
            (string) $locInfo['level_code'],
            (string) $locInfo['side'],
            $SPECIAL_ROWS
        );


        // 2) Load pallet + inventory
        $sql = "
            SELECT
              p.id AS pallet_id,
              '' AS pallet_tag,
              s.id AS sku_id,
              s.sku_num,
              s.`desc` AS sku_desc,
              i.lot_id,
              lt.lot_code,
              i.quantity,
              lt.expiry_date
            FROM pallet p
            JOIN inventory i ON i.pallet_id = p.id
            JOIN sku s ON s.id = i.sku_id
            LEFT JOIN lot lt ON lt.id = i.lot_id
            WHERE p.loc_id = ?
              AND i.quantity > 0
            ORDER BY p.id ASC, s.sku_num ASC, lt.lot_code ASC, lt.expiry_date ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $loc_id);
        $stmt->execute();
        $res = $stmt->get_result();

        $pallets = [];
        $flatItems = [];
        $row_count = 0;

        while ($rowInv = $res->fetch_assoc()) {
            $row_count++;
            $rowInv['quantity'] = (int) $rowInv['quantity'];
            $pid = (int) $rowInv['pallet_id'];

            if (!isset($pallets[$pid])) {
                $pallets[$pid] = [
                    'pallet_id' => $pid,
                    'pallet_tag' => $rowInv['pallet_tag'],
                    'skus' => [],
                ];
            }

            $pallets[$pid]['skus'][] = [
                'sku_id' => (int) $rowInv['sku_id'],
                'sku_num' => $rowInv['sku_num'],
                'sku_desc' => $rowInv['sku_desc'],
                'lot_id' => isset($rowInv['lot_id']) ? (int) $rowInv['lot_id'] : null,
                'lot_code' => $rowInv['lot_code'] ?? '',
                'quantity' => $rowInv['quantity'],
                'expiry_date' => $rowInv['expiry_date'] ?? null,
            ];

            $flatItems[] = [
                'pallet_id' => $pid,
                'pallet_tag' => $rowInv['pallet_tag'],
                'sku_id' => (int) $rowInv['sku_id'],
                'sku_num' => $rowInv['sku_num'],
                'sku_desc' => $rowInv['sku_desc'],
                'lot_id' => isset($rowInv['lot_id']) ? (int) $rowInv['lot_id'] : null,
                'lot_code' => $rowInv['lot_code'] ?? '',
                'quantity' => $rowInv['quantity'],
                'expiry_date' => $rowInv['expiry_date'] ?? null,
            ];
        }
        $stmt->close();



        echo json_encode([
            'ok' => true,
            'pallets' => array_values($pallets),
            'items' => array_values($flatItems),
            'location_label' => $loc_label,
        ]);
    } catch (Throwable $e) {
        error_log('[transfer][inv_by_loc] EXCEPTION: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'server_error']);
    }

    exit;
}
/* ---------- Load selectors for JS ---------- */
$rows = [];
$baysByRow = [];
$levelsByRow = [];
$sidesByRow = [];
$res = $conn->query("
 SELECT row_code, bay_num, level_code, side
 FROM location
 WHERE is_disabled = 0
 ORDER BY row_code, CAST(bay_num AS UNSIGNED), bay_num, level_code, side
");
while ($r = $res->fetch_assoc()) {
    $row = $r['row_code'];
    $bay = $r['bay_num'];
    $lvl = $r['level_code'];
    $side = $r['side'];
    if (!in_array($row, $rows, true))
        $rows[] = $row;
    $baysByRow[$row] = $baysByRow[$row] ?? [];
    $levelsByRow[$row] = $levelsByRow[$row] ?? [];
    $levelsByRow[$row][$bay] = $levelsByRow[$row][$bay] ?? [];
    $sidesByRow[$row] = $sidesByRow[$row] ?? [];
    $sidesByRow[$row][$bay] = $sidesByRow[$row][$bay] ?? [];
    $sidesByRow[$row][$bay][$lvl] = $sidesByRow[$row][$bay][$lvl] ?? [];

    // Only populate B/L/S arrays for non-special rows (prevents empty dropdowns for special rows)
    if (!$isSpecialRow($row)) {
        if (!in_array($bay, $baysByRow[$row], true))
            $baysByRow[$row][] = $bay;
        if (!in_array($lvl, $levelsByRow[$row][$bay], true))
            $levelsByRow[$row][$bay][] = $lvl;
        if (!in_array($side, $sidesByRow[$row][$bay][$lvl], true))
            $sidesByRow[$row][$bay][$lvl][] = $side;
    }
}

/* ---------- PRG state and helper function for location strings ---------- */

// Helper function to format the location string
function format_location_string(string $row, string $bay, string $lvl, string $side, array $special_rows): string
{
    $lookup = array_map(static fn($val) => strtoupper((string) $val), $special_rows);
    if ($row && in_array(strtoupper($row), $lookup, true)) {
        return $row;
    }
    // Only return the full string if all racked components are present
    if ($row && $bay && $lvl && $side) {
        return "$row-$bay-$lvl-$side";
    }
    return ''; // Or return just $row if needed, but for transfer history, we want the final string
}

function fetch_stock_lot_rows(mysqli $conn, string $row, string $bay, string $lvl, string $side): array
{
    $row = strtoupper(trim($row));
    $bay = trim($bay);
    $lvl = trim($lvl);
    $side = trim($side);

    if ($row === '') {
        return [];
    }

    $is_special = in_array($row, SPECIAL_ROWS, true);
    if ($is_special) {
        if ($bay === '') {
            $bay = '01';
        }
        if ($lvl === '') {
            $lvl = '11';
        }
        if ($side === '') {
            $side = 'F';
        }
    } elseif ($bay === '' || $lvl === '' || $side === '') {
        return [];
    }

    $sql = "
        SELECT
          p.id AS pallet_id,
          p.pallet_tag,
          s.id AS sku_id,
          s.sku_num,
          s.`desc` AS sku_desc,
          i.lot_id,
          lt.lot_code,
          lt.expiry_date,
          i.quantity
        FROM location l
        JOIN pallet p ON p.loc_id = l.id
        JOIN inventory i ON i.pallet_id = p.id
        JOIN sku s ON s.id = i.sku_id
        LEFT JOIN lot lt ON lt.id = i.lot_id
        WHERE UPPER(l.row_code) = ?
          AND CAST(l.bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
          AND UPPER(l.level_code) = ?
          AND LEFT(UPPER(l.side), 1) = LEFT(?, 1)
          AND i.quantity > 0
          AND l.is_disabled = 0
        ORDER BY p.id ASC, s.sku_num ASC, lt.lot_code ASC, lt.expiry_date ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss', $row, $bay, $lvl, $side);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    $stmt->close();

    return $rows ?: [];
}

$toast_msg = '';
$toast_type = 'info';
$toast_details = [];
$transfer_details = [];
$show_history = false;
$s_loc_str = '';
$d_loc_str = '';
$inv_src = [];
$inv_dst = [];
$historySku = null;
$transferred_skus = [];

if (isset($_SESSION['toast'])) {
    $toast_msg = $_SESSION['toast']['msg'] ?? '';
    $toast_type = $_SESSION['toast']['type'] ?? 'info';
    $toast_details = is_array($_SESSION['toast']['details'] ?? null) ? $_SESSION['toast']['details'] : [];
    unset($_SESSION['toast']);
}
if (isset($_SESSION['last_transfer'])) {
    $lt = $_SESSION['last_transfer'];
    $s_loc_str = $lt['s_loc_str'] ?? '';
    $d_loc_str = $lt['d_loc_str'] ?? '';
    $inv_src = $lt['inv_src'] ?? [];
    $inv_dst = $lt['inv_dst'] ?? [];
    $transferred_skus = is_array($lt['transfer_skus'] ?? null) ? $lt['transfer_skus'] : [];
    $historySku = (count($transferred_skus) === 1) ? $transferred_skus[0] : null;
    $show_history = true;
    unset($_SESSION['last_transfer']);
}

/* ---------- Transfer handler ---------- */
if (!isset($_SESSION['transfer_token']))
    $_SESSION['transfer_token'] = bin2hex(random_bytes(32));
$transfer_token = $_SESSION['transfer_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn) {
    $error_message = null;
    $success_message = null;
    $processed_skus = [];

    $posted_items = $_POST['transfer_items'] ?? $_POST['selected_items'] ?? [];
    $selected_items = array_values(array_unique(array_filter(array_map('trim', (array) $posted_items))));
    $qty_map = $_POST['transfer_qty'] ?? $_POST['selected_qty'] ?? [];
    $lot_map = $_POST['selected_lot_id'] ?? [];

    $s_row = trim((string) ($_POST['s_row'] ?? ''));
    $s_bay = trim((string) ($_POST['s_bay'] ?? ''));
    $s_lvl = trim((string) ($_POST['s_lvl'] ?? ''));
    $s_side = trim((string) ($_POST['s_side'] ?? ''));

    $d_row = trim((string) ($_POST['d_row'] ?? ''));
    $d_bay = trim((string) ($_POST['d_bay'] ?? ''));
    $d_lvl = trim((string) ($_POST['d_lvl'] ?? ''));
    $d_side = trim((string) ($_POST['d_side'] ?? ''));

    $s_is_special = $isSpecialRow($s_row);
    $d_is_special = $isSpecialRow($d_row);

    if ($s_is_special) {
        if ($s_bay === '') {
            $s_bay = $SPECIAL_DEFAULT_BAY;
        }
        if ($s_lvl === '') {
            $s_lvl = $SPECIAL_DEFAULT_LEVEL;
        }
        if ($s_side === '') {
            $s_side = $SPECIAL_DEFAULT_SIDE;
        }
    }
    if ($d_is_special) {
        if ($d_bay === '') {
            $d_bay = $SPECIAL_DEFAULT_BAY;
        }
        if ($d_lvl === '') {
            $d_lvl = $SPECIAL_DEFAULT_LEVEL;
        }
        if ($d_side === '') {
            $d_side = $SPECIAL_DEFAULT_SIDE;
        }
    }


    if (empty($selected_items)) {
        $error_message = 'Select at least one SKU to transfer.';
    } elseif (!$s_row || (!$s_is_special && (!$s_bay || !$s_lvl || !$s_side))) {
        $error_message = 'Missing source details.';
    } elseif (!$d_row || (!$d_is_special && (!$d_bay || !$d_lvl || !$d_side))) {
        $error_message = 'Missing destination details.';
    } else {
        $srcLoc = ensure_location($conn, $s_row, $s_bay, $s_lvl, $s_side);
        $dstLoc = ensure_location($conn, $d_row, $d_bay, $d_lvl, $d_side);

        if (!$srcLoc || !$dstLoc) {
            $error_message = 'Invalid source or destination location.';
        } elseif ($srcLoc === $dstLoc) {
            $error_message = 'Source and destination cannot be the same.';
        } else {
            $items = [];
            $skuLookup = $conn->prepare("SELECT sku_num, `desc` AS sku_desc FROM sku WHERE id = ? LIMIT 1");
            foreach ($selected_items as $token) {
                $parts = explode(':', $token);
                if (count($parts) < 2) {
                    continue;
                }
                $pallet_id = (int) $parts[0];
                $sku_id = (int) $parts[1];
                $lot_id = isset($parts[2]) ? (int) $parts[2] : 0;
                if ($lot_id <= 0 && isset($lot_map[$pallet_id][$sku_id])) {
                    $lot_id = (int) $lot_map[$pallet_id][$sku_id];
                }
                $qty = (int) ($qty_map[$pallet_id][$sku_id][$lot_id] ?? 0);
                if ($pallet_id <= 0 || $sku_id <= 0) {
                    continue;
                }
                if ($qty <= 0) {
                    $error_message = 'Each selected SKU must have a quantity greater than zero.';
                    break;
                }
                $skuLookup->bind_param('i', $sku_id);
                $skuLookup->execute();
                $skuRow = $skuLookup->get_result()->fetch_assoc();
                if (!$skuRow || !($skuRow['sku_num'] ?? null)) {
                    $error_message = 'One or more SKUs could not be resolved.';
                    break;
                }
                $items[] = [
                    'pallet_id' => $pallet_id,
                    'sku_id' => $sku_id,
                    'lot_id' => $lot_id > 0 ? $lot_id : null,
                    'sku_num' => (string) $skuRow['sku_num'],
                    'sku_desc' => (string) ($skuRow['sku_desc'] ?? ''),
                    'qty' => $qty
                ];
            }
            $skuLookup->close();

            if (!$error_message && empty($items)) {
                $error_message = 'Select at least one valid SKU to transfer.';
            }

            if (!$error_message) {
                $conn->begin_transaction();
                try {
                    $s_loc_str = format_location_string($s_row, $s_bay, $s_lvl, $s_side, $SPECIAL_ROWS);
                    $d_loc_str = format_location_string($d_row, $d_bay, $d_lvl, $d_side, $SPECIAL_ROWS);
                    $dest_pallet = ensure_location_pallet($conn, $dstLoc);
                    $validation_error = null;

                    if (!$dest_pallet) {
                        $validation_error = 'Unable to prepare destination storage.';
                    } else {
                        foreach ($items as $item) {
                            $pallet_info = get_pallet_info($conn, $item['pallet_id']);
                            if (!$pallet_info || $pallet_info['loc_id'] !== $srcLoc) {
                                $validation_error = 'Invalid source selection.';
                                break;
                            }

                            $lot_key = $item['lot_id'] ?? 0;

                            $selectQtyStmt = $conn->prepare('SELECT quantity FROM inventory WHERE pallet_id = ? AND sku_id = ? AND COALESCE(lot_id, 0) = ? FOR UPDATE');
                            $selectQtyStmt->bind_param('iii', $item['pallet_id'], $item['sku_id'], $lot_key);
                            $selectQtyStmt->execute();
                            $row = $selectQtyStmt->get_result()->fetch_assoc();
                            $selectQtyStmt->free_result();
                            if (!$row) {
                                $selectQtyStmt->close();
                                $validation_error = 'SKU not found at the source location.';
                                break;
                            }
                            $available = (int) $row['quantity'];
                            if ($item['qty'] > $available) {
                                $selectQtyStmt->close();
                                $validation_error = 'Transfer quantity exceeds available stock.';
                                break;
                            }
                            $selectQtyStmt->close();

                            $updateSrcStmt = $conn->prepare('UPDATE inventory SET quantity = quantity - ? WHERE pallet_id = ? AND sku_id = ? AND COALESCE(lot_id, 0) = ?');
                            $updateSrcStmt->bind_param('iiii', $item['qty'], $item['pallet_id'], $item['sku_id'], $lot_key);
                            $updateSrcStmt->execute();
                            $updateSrcStmt->close();

                            $cleanupStmt = $conn->prepare('DELETE FROM inventory WHERE pallet_id = ? AND sku_id = ? AND COALESCE(lot_id, 0) = ? AND quantity <= 0');
                            $cleanupStmt->bind_param('iii', $item['pallet_id'], $item['sku_id'], $lot_key);
                            $cleanupStmt->execute();
                            $cleanupStmt->close();

                            $selectDestQtyStmt = $conn->prepare('SELECT quantity FROM inventory WHERE pallet_id = ? AND sku_id = ? AND COALESCE(lot_id, 0) = ? FOR UPDATE');
                            $selectDestQtyStmt->bind_param('iii', $dest_pallet['id'], $item['sku_id'], $lot_key);
                            $selectDestQtyStmt->execute();
                            $destRow = $selectDestQtyStmt->get_result()->fetch_assoc();
                            $selectDestQtyStmt->free_result();
                            $selectDestQtyStmt->close();

                            if ($destRow) {
                                $updateDestStmt = $conn->prepare('UPDATE inventory SET quantity = quantity + ?, updated_at = CURRENT_TIMESTAMP WHERE pallet_id = ? AND sku_id = ? AND COALESCE(lot_id, 0) = ?');
                                $updateDestStmt->bind_param('iiii', $item['qty'], $dest_pallet['id'], $item['sku_id'], $lot_key);
                                $updateDestStmt->execute();
                                $updateDestStmt->close();
                            } else {
                                if ($item['lot_id'] === null) {
                                    $insertDestStmt = $conn->prepare('INSERT INTO inventory (sku_id, pallet_id, quantity) VALUES (?, ?, ?)');
                                    $insertDestStmt->bind_param('iii', $item['sku_id'], $dest_pallet['id'], $item['qty']);
                                } else {
                                    $insertDestStmt = $conn->prepare('INSERT INTO inventory (sku_id, pallet_id, lot_id, quantity) VALUES (?, ?, ?, ?)');
                                    $insertDestStmt->bind_param('iiii', $item['sku_id'], $dest_pallet['id'], $lot_key, $item['qty']);
                                }
                                $insertDestStmt->execute();
                                $insertDestStmt->close();
                            }

                            $lot_label = '';
                            if ($item['lot_id'] !== null) {
                                $lotStmt = $conn->prepare('SELECT lot_code, expiry_date FROM lot WHERE id = ? LIMIT 1');
                                $lotStmt->bind_param('i', $item['lot_id']);
                                $lotStmt->execute();
                                $lotInfo = $lotStmt->get_result()->fetch_assoc();
                                $lotStmt->close();
                                if ($lotInfo) {
                                    $lot_label = trim((string) ($lotInfo['lot_code'] ?? ''));
                                    if (!empty($lotInfo['expiry_date'])) {
                                        $lot_label .= $lot_label !== '' ? ' / ' . $lotInfo['expiry_date'] : (string) $lotInfo['expiry_date'];
                                    }
                                }
                            }
                            $reference_out = sprintf(
                                'Transfer OUT %d units from %s%s',
                                $item['qty'],
                                $s_loc_str,
                                $lot_label !== '' ? ' [LOT ' . $lot_label . ']' : ''
                            );
                            $reference_in = sprintf(
                                'Transfer IN %d units to %s%s',
                                $item['qty'],
                                $d_loc_str,
                                $lot_label !== '' ? ' [LOT ' . $lot_label . ']' : ''
                            );
                            log_inventory_movement($conn, $item['sku_id'], $pallet_info['loc_id'], -$item['qty'], 'TRANSFER', $reference_out, $user_id);
                            log_inventory_movement($conn, $item['sku_id'], $dstLoc, $item['qty'], 'TRANSFER', $reference_in, $user_id);
                            $processed_skus[] = $item['sku_num'];
                        }
                    }

                    if ($validation_error !== null) {
                        $conn->rollback();
                        $error_message = $validation_error;
                    } else {
                        $conn->commit();

                        $success_message = sprintf('Transferred %d item(s).', count($items));
                        $transfer_details = array_map(static function ($it) {
                            return [
                                'sku' => (string) ($it['sku_num'] ?? ''),
                                'desc' => (string) ($it['sku_desc'] ?? ''),
                                'lot_id' => isset($it['lot_id']) ? (int) $it['lot_id'] : null,
                                'qty' => (int) ($it['qty'] ?? 0),
                            ];
                        }, $items);
                        $processed_skus = array_values(array_unique($processed_skus));

                        list($s_row_h, $s_bay_h, $s_lvl_h, $s_side_h) = array_pad(explode('-', $s_loc_str), 4, '');
                        list($d_row_h, $d_bay_h, $d_lvl_h, $d_side_h) = array_pad(explode('-', $d_loc_str), 4, '');

                        $inv_src = fetch_stock_lot_rows($conn, $s_row_h, $s_bay_h, $s_lvl_h, $s_side_h);
                        $inv_dst = fetch_stock_lot_rows($conn, $d_row_h, $d_bay_h, $d_lvl_h, $d_side_h);
                    }

                } catch (\Throwable $e) {
                    $conn->rollback();
                    $error_message = 'Transfer failed: ' . $e->getMessage();
                    error_log('[transfer] ' . $e->getMessage());
                }
            }
        }
    }

    // PRG
    if ($error_message !== null || $success_message !== null) {
        $_SESSION['toast'] = [
            'msg' => $error_message ?? $success_message,
            'type' => $error_message ? 'error' : 'success',
            'details' => $success_message ? $transfer_details : [],
        ];
        if ($success_message) {
            $_SESSION['last_transfer'] = [
                's_loc_str' => $s_loc_str,
                'd_loc_str' => $d_loc_str,
                'inv_src' => $inv_src,
                'inv_dst' => $inv_dst,
                'transfer_skus' => $processed_skus,
                'transfer_details' => $transfer_details,
            ];
        }
        $_SESSION['transfer_token'] = bin2hex(random_bytes(32));
        header('Location: transfer.php');
        exit;
    }
}

/* ---------- View ---------- */
ob_start();
?>
<div id="transferShell" class="transfer-shell<?= $toast_type === 'success'
    ? ' transfer-shell--success'
    : ($toast_type === 'error' ? ' transfer-shell--error' : '') ?>">
    <h2>Transfer Inventory</h2>

    <?php if ($toast_msg): ?>
        <div class="toast toast--banner <?= htmlspecialchars($toast_type) ?>" <?= $toast_type === 'success' && !empty($toast_details) ? ' data-toast-details="' . htmlspecialchars(json_encode($toast_details), ENT_QUOTES) . '"' : '' ?>>
            <?= htmlspecialchars($toast_msg) ?>
        </div>
    <?php else: ?>
        <div class="toast toast--banner info">
            Stage 1: search the source location. <br />Stage 2: search the destination. <br />Stage 3: check the SKUs to
            move, adjust the quantities, then approve.
        </div>
    <?php endif; ?>

    <form id="transferForm" method="post" autocomplete="off">
        <input type="hidden" name="do_transfer" value="1">

        <input type="hidden" name="s_row" id="s_row_val">
        <input type="hidden" name="s_bay" id="s_bay_val">
        <input type="hidden" name="s_lvl" id="s_lvl_val">
        <input type="hidden" name="s_side" id="s_side_val">
        <input type="hidden" name="d_row" id="d_row_val">
        <input type="hidden" name="d_bay" id="d_bay_val">
        <input type="hidden" name="d_lvl" id="d_lvl_val">
        <input type="hidden" name="d_side" id="d_side_val">

        <div class="grid-2col summary">
            <fieldset>
                <legend>1) Source Location</legend>
                <div class="grid grid-4">
                    <div><label>Row</label><select id="s_row"></select></div>
                    <div><label>Bay</label><select id="s_bay" disabled></select></div>
                    <div><label>Level</label><select id="s_lvl" disabled></select></div>
                    <div><label>Side</label><select id="s_side" disabled></select></div>
                </div>
                <div class="results" id="sourceInventory">
                    <!-- <div class="mb-1">
                        <button class="btn secondary small" type="button" id="selectAllSourceBtn" disabled>
                            Select all SKUs
                        </button>
                    </div> -->
                    <table id="srcTable" class="table" aria-label="Source inventory table">
                        <thead>
                        <tr>
                            <th style="width:40px;text-align:center;">
                                <input type="checkbox" id="selectAllSkus">
                            </th>
                            <th>SKU</th>
                            <th>Description</th>
                            <th>Lot</th>
                            <th>On Hand</th>
                            <th style="width:140px;">Transfer Qty</th>
                        </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="muted" colspan="6">Pick a full location to load items.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </fieldset>

            <fieldset>
                <legend>2) Destination Location</legend>
                <div class="grid grid-4">
                    <div><label>Row</label><select id="d_row"></select></div>
                    <div><label>Bay</label><select id="d_bay" disabled></select></div>
                    <div><label>Level</label><select id="d_lvl" disabled></select></div>
                    <div><label>Side</label><select id="d_side" disabled></select></div>
                    <input type="hidden" name="transfer_token" value="<?= htmlspecialchars($transfer_token) ?>">
                </div>
                <div class="results" id="destinationInventory">
                    <!-- <div class="muted mb-2" id="destinationLocationLabel"></div> -->
                    <table id="dstTable" class="table" aria-label="Destination inventory table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Description</th>
                                <th>Lot</th>
                                <th>Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="muted" colspan="4">Pick a full location to load items.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </fieldset>
        </div>

        <div class="actions">
            <button class="btn" type="submit" id="approveBtn" disabled>Approve Transfer</button>
            <button class="btn secondary" type="reset" id="resetBtn">Reset</button>
        </div>
    </form>

    <?php if ($show_history): ?>
        <h2 style="margin-top:18px;">Inventory After Transfer</h2>
        <?php
        // The loc strings already contain only what is necessary, so explode() should work fine.
        // If the string is "STAGING", explode will give: ["STAGING"]. array_pad will give: ["STAGING", "", "", ""].
        list($s_row_h, $s_bay_h, $s_lvl_h, $s_side_h) = array_pad(explode('-', $s_loc_str), 4, '');
        list($d_row_h, $d_bay_h, $d_lvl_h, $d_side_h) = array_pad(explode('-', $d_loc_str), 4, '');
        ?>
        <div class="grid-2col">
            <div>
                <h3>
                    Source (<?= htmlspecialchars($s_loc_str) ?>)
                    <?php if ($historySku): ?>
                        <a class="btn secondary small" style="margin-left: 8px;"
                            href="history.php?sku=<?= urlencode($historySku) ?>&row=<?= urlencode($s_row_h) ?>&bay=<?= urlencode($s_bay_h) ?>&lvl=<?= urlencode($s_lvl_h) ?>&side=<?= urlencode($s_side_h) ?>">
                            History
                        </a>
                    <?php endif; ?>
                </h3>
                <table>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Description</th>
                            <th>Lot</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$inv_src): ?>
                            <tr>
                                <td colspan="4" class="muted">No items at this location.</td>
                            </tr>
                        <?php else:
                            foreach ($inv_src as $it): ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['sku_num']) ?></td>
                                    <td><?= htmlspecialchars((string) ($it['sku_desc'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($it['lot_code'] ?? '')) ?></td>
                                    <td><?= (int) $it['quantity'] ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div>
                <h3>
                    Destination (<?= htmlspecialchars($d_loc_str) ?>)
                    <?php if ($historySku): ?>
                        <a class="btn secondary small" style="margin-left: 8px;"
                            href="history.php?sku=<?= urlencode($historySku) ?>&row=<?= urlencode($d_row_h) ?>&bay=<?= urlencode($d_bay_h) ?>&lvl=<?= urlencode($d_lvl_h) ?>&side=<?= urlencode($d_side_h) ?>">
                            History
                        </a>
                    <?php endif; ?>
                </h3>
                <table>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Description</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$inv_dst): ?>
                            <tr>
                                <td colspan="4" class="muted">No items at this location.</td>
                            </tr>
                        <?php else:
                            foreach ($inv_dst as $it): ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['sku_num']) ?></td>
                                    <td><?= htmlspecialchars((string) ($it['sku_desc'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($it['lot_code'] ?? '')) ?></td>
                                    <td><?= (int) $it['quantity'] ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div> <!-- /#transferShell -->

<!-- Transfer confirmation modal (outside shell to avoid positioning issues) -->
<div id="transferConfirmModal" class="modal" hidden aria-hidden="true">
    <div class="modal__backdrop"></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="transferConfirmTitle">
        <h3 id="transferConfirmTitle">Confirm Transfer</h3>
        <p id="transferConfirmMessage" class="modal__body">
            Are you sure you want to transfer the selected items?
        </p>
        <div class="modal__actions">
            <button type="button" class="btn" id="transferConfirmYes">Yes, transfer</button>
            <button type="button" class="btn secondary" id="transferConfirmNo">Cancel</button>
        </div>
    </div>
</div>

<?php

$content = ob_get_clean();

$script_path_from_root = '/transfer.php';

// Boot for transfer.js
$boot = [
    'rows' => $rows,
    'baysByRow' => $baysByRow,
    'levelsByRow' => $levelsByRow,
    'sidesByRow' => $sidesByRow,
    'ajaxPath' => (defined('APP_ROOT_URL') ? APP_ROOT_URL : '') . $script_path_from_root,
    'specialRows' => $SPECIAL_ROWS_RAW,
    'specialDefaults' => [
        'bay' => $SPECIAL_DEFAULT_BAY,
        'level' => $SPECIAL_DEFAULT_LEVEL,
        'side' => $SPECIAL_DEFAULT_SIDE,
    ],
];
$footer_js = '<style>
/* Modal fallback (ensures center overlay even if base CSS misses it) */
.modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 1000; }
.modal.is-open { display: flex; }
.modal__backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.45); }
.modal__dialog { position: relative; background: #fff; padding: 20px 24px; border-radius: 8px; max-width: 420px; width: 90%; box-shadow: 0 12px 25px rgba(0, 0, 0, 0.25); z-index: 1001; }
.modal__body { margin: 8px 0 16px; }
.modal__actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
</style>';
$footer_js .= '<script id="transfer-boot" type="application/json">'
    . json_encode($boot, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
    . '</script>';

$js = isset($js) && is_array($js) ? $js : [];
$js[] = '/js/transfer.js';
$footer_js .= '<script>(function(){const tags=[...document.scripts].filter(s=>s.src&&s.src.indexOf("transfer.js")!==-1);console.log("[transfer] script tags found",tags.map(t=>t.src));tags.forEach(t=>{t.addEventListener("load",()=>console.log("[transfer] script loaded",t.src));t.addEventListener("error",ev=>console.error("[transfer] script load failed",t.src,ev));});if(!tags.length){console.error("[transfer] no transfer.js script tags detected");}})();</script>';


require __DIR__ . '/templates/layout.php';
