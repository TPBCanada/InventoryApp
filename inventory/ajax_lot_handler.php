<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

// session_start();
while (ob_get_level() > 0) {
  ob_end_clean();
}

ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);


session_start();


require_once __DIR__ . '/dbinv.php';


$unexpectedOutput = ob_get_contents();

if ($unexpectedOutput !== false && $unexpectedOutput !== '') {
    error_log(
        '[ajax_lot_handler] Unexpected output after dbinv.php: ' .
        json_encode($unexpectedOutput)
    );
}

ob_clean();

header('Content-Type: application/json; charset=utf-8');


require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/utils/config_init.php';

if (!isset($_SESSION['username'])) {
  http_response_code(401);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
  exit;
}
$user_id = (int) ($_SESSION['user_id'] ?? 0);

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


if (headers_sent($sourceFile, $sourceLine)) {
    error_log(
        "Headers already sent by {$sourceFile} on line {$sourceLine}"
    );
}

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$debug = ($_GET['debug'] ?? $_POST['debug'] ?? '') === '1';
if (!$debug) {
  $req_uri = $_SERVER['REQUEST_URI'] ?? '';
  if ($req_uri && strpos($req_uri, '/dev/') !== false) {
    $debug = true;
  }
}

if ($action === 'fetch') {
  $sku_id = (int) ($_GET['sku_id'] ?? 0);
  $loc_id = (int) ($_GET['loc_id'] ?? 0);
  $lot_mode = trim((string) ($_GET['lot_mode'] ?? ''));
  if ($sku_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing SKU.']);
    exit;
  }

  try {
    $all_expiry_dates_any = [];
    $all_expiry_locations_any = [];
    $stmt_oldest = $conn->prepare('
      SELECT DISTINCT l.expiry_date
      FROM lot l
      WHERE l.sku_id = ?
        AND l.expiry_date IS NOT NULL
        AND COALESCE(l.quantity, 0) > 0
      ORDER BY l.expiry_date ASC
    ');
    $stmt_oldest->bind_param('i', $sku_id);
    $stmt_oldest->execute();
    $res_oldest = stmt_get_result_safe($stmt_oldest);
    if ($res_oldest instanceof mysqli_result) {
      while ($oldest_row = $res_oldest->fetch_assoc()) {
        $expiry = (string) ($oldest_row['expiry_date'] ?? '');
        if ($expiry !== '') {
          $all_expiry_dates_any[] = $expiry;
        }
      }
      $res_oldest->free();
    }
    $stmt_oldest->close();
    $oldest_any_expiry = $all_expiry_dates_any[0] ?? null;

    $stmt_locs = $conn->prepare('
      SELECT DISTINCT
        l.expiry_date,
        loc.id AS loc_id,
        loc.row_code,
        loc.bay_num,
        loc.level_code,
        loc.side
      FROM lot l
      JOIN inventory i ON i.lot_id = l.id
      JOIN pallet p ON p.id = i.pallet_id
      JOIN location loc ON loc.id = p.loc_id
      WHERE l.sku_id = ?
        AND l.expiry_date IS NOT NULL
        AND i.quantity > 0
        AND loc.is_disabled = 0
      ORDER BY l.expiry_date ASC, loc.row_code ASC, CAST(loc.bay_num AS UNSIGNED) ASC, loc.level_code ASC, loc.side ASC
    ');
    $stmt_locs->bind_param('i', $sku_id);
    $stmt_locs->execute();
    $res_locs = stmt_get_result_safe($stmt_locs);
    if ($res_locs instanceof mysqli_result) {
      while ($loc_row = $res_locs->fetch_assoc()) {
        $expiry = (string) ($loc_row['expiry_date'] ?? '');
        if ($expiry === '') {
          continue;
        }
        $all_expiry_locations_any[] = [
          'expiry_date' => $expiry,
          'loc_id' => (int) ($loc_row['loc_id'] ?? 0),
          'location' => (function (array $row): string {
            $rowCode = (string) ($row['row_code'] ?? '');
            if (defined('SPECIAL_ROWS') && in_array(strtoupper($rowCode), SPECIAL_ROWS, true)) {
              return $rowCode;
            }
            $bay = (string) ($row['bay_num'] ?? '');
            $level = (string) ($row['level_code'] ?? '');
            $side = (string) ($row['side'] ?? '');
            return "{$rowCode}-{$bay}-{$level}-{$side}";
          })($loc_row),
        ];
      }
      $res_locs->free();
    }
    $stmt_locs->close();

    if ($lot_mode === 'remove' && $loc_id > 0) {
      $stmt = $conn->prepare('
        SELECT
          lot.id,
          lot.lot_code,
          lot.expiry_date,
          COALESCE(lot.quantity, 0) AS on_hand
        FROM lot
        JOIN inventory i ON i.lot_id = lot.id
        JOIN pallet p ON p.id = i.pallet_id
        WHERE lot.sku_id = ?
          AND p.loc_id = ?
          AND i.quantity > 0
        GROUP BY lot.id, lot.lot_code, lot.expiry_date, lot.quantity
        ORDER BY lot.expiry_date IS NULL, lot.expiry_date ASC, lot.lot_code ASC
      ');
      $stmt->bind_param('ii', $sku_id, $loc_id);
    } elseif ($lot_mode === 'view') {
      $stmt = $conn->prepare('
        SELECT
          lot.id,
          lot.lot_code,
          lot.expiry_date,
          COALESCE(lot.quantity, 0) AS on_hand
        FROM lot
        WHERE lot.sku_id = ?
          AND COALESCE(lot.quantity, 0) > 0
        ORDER BY lot.expiry_date IS NULL, lot.expiry_date ASC, lot.lot_code ASC
      ');
      $stmt->bind_param('i', $sku_id);
    } else {
      $stmt = $conn->prepare('
        SELECT
          lot.id,
          lot.lot_code,
          lot.expiry_date,
          COALESCE(lot.quantity, 0) AS on_hand
        FROM lot
        WHERE lot.sku_id = ?
        ORDER BY lot.expiry_date IS NULL, lot.expiry_date ASC, lot.lot_code ASC
      ');
      $stmt->bind_param('i', $sku_id);
    }
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    $stmt->close();

    echo json_encode([
      'ok' => true,
      'items' => $rows,
      'oldest_expiry_any' => $oldest_any_expiry,
      'expiry_dates_any' => $all_expiry_dates_any,
      'expiry_locations_any' => $all_expiry_locations_any,
    ]);
    exit;
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
    exit;
  }
}

if ($action === 'create') {
  $sku_id = (int) ($_POST['sku_id'] ?? 0);
  $lot_code = trim((string) ($_POST['lot_code'] ?? ''));
  $expiry_date = trim((string) ($_POST['expiry_date'] ?? ''));
  $adjustment_raw = trim((string) ($_POST['adjustment_qty'] ?? ''));
  $quantity_raw = trim((string) ($_POST['quantity'] ?? ''));
  $loc_id = (int) ($_POST['loc_id'] ?? 0);

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
    if ($lot_code === '') {
      echo json_encode(['ok' => false, 'message' => 'Lot code is required.']);
      exit;
    }
    if ($expiry_date === '') {
      echo json_encode(['ok' => false, 'message' => 'Expiry date is required.']);
      exit;
    }

    if ($expiry_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
      echo json_encode(['ok' => false, 'message' => 'Invalid expiry date.']);
      exit;
    }

    $incoming_raw = $adjustment_raw !== '' ? $adjustment_raw : $quantity_raw;
    if ($incoming_raw === '' || !is_numeric($incoming_raw)) {
      echo json_encode(['ok' => false, 'message' => 'Add quantity is required.']);
      exit;
    }
    $adjustment_qty = (int) $incoming_raw;

    if ($expiry_date !== '') {
      $today = (new DateTime('today'))->format('Y-m-d');
      if ($expiry_date < $today) {
        echo json_encode(['ok' => false, 'message' => 'Expiry date must be today or later.']);
        exit;
      }
    }

    $stmt = $conn->prepare('INSERT INTO lot (sku_id, lot_code, expiry_date, quantity) VALUES (?,?,?,?)');
    $expiry_for_db = $expiry_date;
    $stmt->bind_param('issi', $sku_id, $lot_code, $expiry_for_db, $adjustment_qty);
    $stmt->execute();
    $lot_id = (int) $stmt->insert_id;
    $stmt->close();

    if ($loc_id > 0 && $adjustment_qty > 0) {
      $stmt = $conn->prepare('SELECT id FROM pallet WHERE loc_id = ? AND status = ? ORDER BY id DESC LIMIT 1');
      $status = 'ACTIVE';
      $stmt->bind_param('is', $loc_id, $status);
      $stmt->execute();
      $res = stmt_get_result_safe($stmt);
      $pallet_row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
      if ($res instanceof mysqli_result) {
        $res->free();
      }
      $stmt->close();

      if ($pallet_row) {
        $pallet_id = (int) $pallet_row['id'];
        $stmt = $conn->prepare('
          INSERT INTO inventory (sku_id, pallet_id, lot_id, quantity)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ');
        $stmt->bind_param('iiii', $sku_id, $pallet_id, $lot_id, $adjustment_qty);
        $stmt->execute();
        $stmt->close();

        $movement_qty = abs($adjustment_qty);
        if ($movement_qty > 0) {
          $movement_type = $adjustment_qty >= 0 ? 'IN' : 'OUT';
          $reference = sprintf(
            'Lot %s %s %d units at location %d',
            $lot_code,
            $movement_type === 'IN' ? 'added' : 'removed',
            $movement_qty,
            $loc_id
          );
          log_inventory_movement($conn, $sku_id, $loc_id, $movement_qty, $movement_type, $reference, $user_id);
        }
      }
    }

    echo json_encode(['ok' => true, 'lot_id' => $lot_id, 'message' => 'Lot created successfully.']);
    exit;
  } catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
      echo json_encode(['ok' => false, 'message' => 'Lot code already exists for this SKU.']);
      exit;
    }
    http_response_code(500);
    if ($debug) {
      echo json_encode([
        'ok' => false,
        'message' => 'Server error',
        'debug' => $e->getMessage(),
        'code' => $e->getCode(),
      ]);
    } else {
      echo json_encode(['ok' => false, 'message' => 'Server error']);
    }
    exit;
  } catch (Throwable $e) {
    http_response_code(500);
    if ($debug) {
      echo json_encode(['ok' => false, 'message' => 'Server error', 'debug' => $e->getMessage()]);
    } else {
      echo json_encode(['ok' => false, 'message' => 'Server error']);
    }
    exit;
  }
}

if ($action === 'update') {
  $lot_id = (int) ($_POST['lot_id'] ?? 0);
  $expiry_date = trim((string) ($_POST['expiry_date'] ?? ''));
  $adjustment_raw = trim((string) ($_POST['adjustment_qty'] ?? ''));
  $quantity_raw = trim((string) ($_POST['quantity'] ?? ''));
  $loc_id = (int) ($_POST['loc_id'] ?? 0);

  if ($lot_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid lot record.']);
    exit;
  }

  if ($expiry_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid expiry date.']);
    exit;
  }

  $txn_started = false;
  try {
    $incoming_raw = $adjustment_raw !== '' ? $adjustment_raw : $quantity_raw;
    if ($incoming_raw === '' || !is_numeric($incoming_raw)) {
      echo json_encode(['ok' => false, 'message' => 'Adjustment quantity is required.']);
      exit;
    }
    $adjustment_qty = (int) $incoming_raw;

    $conn->begin_transaction();
    $txn_started = true;

    $stmt = $conn->prepare('SELECT sku_id, lot_code, expiry_date, quantity FROM lot WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $lot_id);
    $stmt->execute();
    $res = stmt_get_result_safe($stmt);
    $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    if ($res instanceof mysqli_result) {
      $res->free();
    }
    $stmt->close();
    if (!$row) {
      echo json_encode(['ok' => false, 'message' => 'Lot not found.']);
      exit;
    }
    $sku_id = (int) ($row['sku_id'] ?? 0);
    $lot_code = (string) ($row['lot_code'] ?? '');
    $current_qty = (int) ($row['quantity'] ?? 0);
    if (($current_qty + $adjustment_qty) < 0) {
      echo json_encode(['ok' => false, 'message' => 'Remove quantity exceeds available lot quantity.']);
      exit;
    }

    if ($expiry_date === '') {
      $expiry_date = (string) ($row['expiry_date'] ?? '');
      if ($expiry_date === '') {
        echo json_encode(['ok' => false, 'message' => 'Expiry date is required.']);
        exit;
      }
    }

    $stmt = $conn->prepare('UPDATE lot SET expiry_date = ?, quantity = quantity + ? WHERE id = ?');
    $expiry_for_db = $expiry_date;
    $stmt->bind_param('sii', $expiry_for_db, $adjustment_qty, $lot_id);
    $stmt->execute();
    $stmt->close();

    if ($loc_id > 0 && $adjustment_qty !== 0 && $sku_id > 0) {
      if ($adjustment_qty > 0) {
        $pallet = ensure_location_pallet($conn, $loc_id);
        $pallet_id = (int) ($pallet['id'] ?? 0);
        if ($pallet_id <= 0) {
          throw new RuntimeException('Unable to resolve a pallet for this location.');
        }

        $stmt = $conn->prepare('
          INSERT INTO inventory (sku_id, pallet_id, lot_id, quantity)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ');
        $stmt->bind_param('iiii', $sku_id, $pallet_id, $lot_id, $adjustment_qty);
        $stmt->execute();
        $stmt->close();
      } else {
        $remove_qty = abs($adjustment_qty);
        $stmt_rows = $conn->prepare('
          SELECT i.pallet_id, i.quantity
          FROM inventory i
          JOIN pallet p ON p.id = i.pallet_id
          WHERE p.loc_id = ? AND i.sku_id = ? AND i.lot_id = ?
          ORDER BY i.quantity DESC, i.pallet_id ASC
          FOR UPDATE
        ');
        $stmt_rows->bind_param('iii', $loc_id, $sku_id, $lot_id);
        $stmt_rows->execute();
        $res_rows = stmt_get_result_safe($stmt_rows);

        $pallet_rows = [];
        $total_on_hand = 0;
        if ($res_rows instanceof mysqli_result) {
          while ($inv_row = $res_rows->fetch_assoc()) {
            $qty = (int) ($inv_row['quantity'] ?? 0);
            $pallet_rows[] = [
              'pallet_id' => (int) ($inv_row['pallet_id'] ?? 0),
              'quantity' => $qty,
            ];
            $total_on_hand += $qty;
          }
          $res_rows->free();
        }
        $stmt_rows->close();

        if ($remove_qty > $total_on_hand) {
          throw new RuntimeException('Remove quantity exceeds available inventory for this lot at the location.');
        }

        $update_stmt = $conn->prepare('
          UPDATE inventory
          SET quantity = quantity - ?
          WHERE pallet_id = ? AND sku_id = ? AND lot_id = ? AND quantity >= ?
        ');
        $delete_stmt = $conn->prepare('
          DELETE FROM inventory
          WHERE pallet_id = ? AND sku_id = ? AND lot_id = ? AND quantity <= 0
        ');

        $update_qty = 0;
        $update_pallet = 0;
        $update_threshold = 0;
        $update_stmt->bind_param('iiiii', $update_qty, $update_pallet, $sku_id, $lot_id, $update_threshold);

        $delete_pallet = 0;
        $delete_stmt->bind_param('iii', $delete_pallet, $sku_id, $lot_id);

        $remaining = $remove_qty;
        foreach ($pallet_rows as $inv_row) {
          if ($remaining <= 0) {
            break;
          }
          $deduct = min($remaining, (int) $inv_row['quantity']);
          if ($deduct <= 0) {
            continue;
          }
          $update_qty = $deduct;
          $update_pallet = (int) $inv_row['pallet_id'];
          $update_threshold = $deduct;
          $update_stmt->execute();
          if ($update_stmt->affected_rows === 0) {
            throw new RuntimeException('Removal failed while updating inventory entry.');
          }
          if ((int) $inv_row['quantity'] === $deduct) {
            $delete_pallet = (int) $inv_row['pallet_id'];
            $delete_stmt->execute();
          }
          $remaining -= $deduct;
        }

        $update_stmt->close();
        $delete_stmt->close();

        if ($remaining > 0) {
          throw new RuntimeException('Removal halted due to concurrent updates. Please retry.');
        }
      }

      $movement_qty = abs($adjustment_qty);
      if ($movement_qty > 0) {
        $movement_type = $adjustment_qty >= 0 ? 'IN' : 'OUT';
        $reference = sprintf(
          'Lot %s %s %d units at location %d',
          $lot_code !== '' ? $lot_code : ('#' . $lot_id),
          $movement_type === 'IN' ? 'added' : 'removed',
          $movement_qty,
          $loc_id
        );
        log_inventory_movement($conn, $sku_id, $loc_id, $movement_qty, $movement_type, $reference, $user_id);
      }
    }

    $stmt = $conn->prepare('SELECT quantity FROM lot WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $lot_id);
    $stmt->execute();
    $res = stmt_get_result_safe($stmt);
    $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
    if ($res instanceof mysqli_result) {
      $res->free();
    }
    $stmt->close();
    $new_total = (int) ($row['quantity'] ?? 0);

    $conn->commit();
    $txn_started = false;

    echo json_encode(['ok' => true, 'message' => 'Lot updated successfully.', 'new_total' => $new_total]);
    exit;
  } catch (Throwable $e) {
    if ($txn_started) {
      $conn->rollback();
    }
    http_response_code(500);
    if ($debug) {
      echo json_encode(['ok' => false, 'message' => 'Server error', 'debug' => $e->getMessage()]);
    } else {
      echo json_encode(['ok' => false, 'message' => 'Server error']);
    }
    exit;
  }
}

echo json_encode(['ok' => false, 'message' => 'Invalid action.']);
