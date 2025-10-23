<?php
// transfer.php — single-file version (view + inline AJAX)
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (Throwable $__) {}

// (Optional) UI helper; we won't rely on it for DB comparisons anymore.
function norm_bay(string $b): string {
  $b = trim($b);
  $b = ltrim($b, '0');
  return $b === '' ? '0' : $b;
}

/** Ensure a location row exists, return its id (numeric-bay compare to avoid dupes). */
function ensure_location(mysqli $conn, string $row, string $bay, string $lvl, string $side): int {
  // Try find by numeric compare so '01' == '1'
  $sel = $conn->prepare("
    SELECT id
    FROM location
    WHERE row_code=?
      AND CAST(bay_num AS UNSIGNED)=CAST(? AS UNSIGNED)
      AND level_code=?
      AND side=?
    LIMIT 1
  ");
  $sel->bind_param('ssss', $row, $bay, $lvl, $side);
  $sel->execute();
  $id = (int)($sel->get_result()->fetch_column() ?: 0);
  if ($id) return $id;

  // Insert a canonical row using the incoming values; UNIQUE(row,bay,level,side) keeps it safe
  $ins = $conn->prepare("INSERT IGNORE INTO location (row_code, bay_num, level_code, side) VALUES (?,?,?,?)");
  $ins->bind_param('ssss', $row, $bay, $lvl, $side);
  $ins->execute();

  // Re-read with numeric compare (covers '01' vs '1')
  $sel->execute();
  return (int)($sel->get_result()->fetch_column() ?: 0);
}

// transfer.php (Add this function near the top with your other DB helpers)

/** Fetch current inventory (SKU/Qty) for a specific location. */
function fetch_current_inventory(mysqli $conn, $row, $bay, $lvl, $side): array {
    if (!$row || !$bay || !$lvl || !$side) return [];
    
    // Only items with qty > 0 at the exact location (numeric bay compare)
    $sql = "
      SELECT s.sku_num, i.quantity AS quantity
      FROM location L
      JOIN inventory i ON i.loc_id = L.id
      JOIN sku s ON s.id = i.sku_id
      WHERE L.row_code = ?
        AND CAST(L.bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
        AND L.level_code = ?
        AND L.side = ?
        AND i.quantity > 0
      ORDER BY s.sku_num
    ";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssss', $row, $bay, $lvl, $side);
        $stmt->execute();

        $items = [];
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $items[] = [
                'sku_num' => (string)$r['sku_num'],
                'quantity' => (int)$r['quantity'],
            ];
        }
        return $items;
    } catch (\Throwable $e) {
        error_log('[transfer.php fetch_current_inventory] ' . $e->getMessage());
        return [];
    }
}

/** Resolve a location id using numeric bay compare (read-only). */
function find_loc_id(mysqli $conn, string $row, string $bay, string $lvl, string $side) {
  $st = $conn->prepare("
    SELECT id
    FROM location
    WHERE row_code=?
      AND CAST(bay_num AS UNSIGNED)=CAST(? AS UNSIGNED)
      AND level_code=?
      AND side=?
    LIMIT 1
  ");
  $st->bind_param('ssss', $row, $bay, $lvl, $side);
  $st->execute();
  return $st->get_result()->fetch_column() ?: null;
}

/** Fetch recent history for a specific location (numeric bay compare). */
function fetch_history(mysqli $conn, $row, $bay, $lvl, $side) {
  if (!$row || !$bay || !$lvl || !$side) return [];
  $sql = "
    SELECT M.created_at,
           S.sku_num,
           M.quantity_change AS change_qty,
           M.movement_type   AS action,
           M.reference       AS note
    FROM location L
    JOIN inventory_movements M ON M.loc_id = L.id
    JOIN sku S                 ON S.id     = M.sku_id
    WHERE L.row_code = ?
      AND CAST(L.bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
      AND L.level_code = ?
      AND L.side = ?
    ORDER BY M.created_at DESC
    LIMIT 10
  ";
  $st = $conn->prepare($sql);
  $st->bind_param('ssss', $row, $bay, $lvl, $side);
  $st->execute();
  return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ----------------- INLINE AJAX: fetch items for a location -----------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'inv_by_loc') {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=UTF-8');
  ini_set('display_errors', '0');
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  if (!isset($_SESSION['username'])) {
    echo json_encode(['ok' => false, 'error' => 'auth_required'], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }

  $row  = trim($_GET['row']  ?? '');
  $bay  = trim($_GET['bay']  ?? '');
  $lvl  = trim($_GET['lvl']  ?? '');
  $side = trim($_GET['side'] ?? '');

  if ($row === '' || $bay === '' || $lvl === '' || $side === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_params'], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }

  try {
    // Only items with qty > 0 at the exact location (numeric bay compare)
    $sql = "
      SELECT s.id AS sku_id, s.sku_num, i.quantity AS quantity
      FROM location L
      JOIN inventory i ON i.loc_id = L.id
      JOIN sku s       ON s.id     = i.sku_id
      WHERE L.row_code = ?
        AND CAST(L.bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
        AND L.level_code = ?
        AND L.side = ?
        AND i.quantity > 0
      ORDER BY s.sku_num
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss', $row, $bay, $lvl, $side);
    $stmt->execute();

    $items = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $r['quantity'] = (int)$r['quantity'];
      $items[] = $r;
    }

    echo json_encode(['ok' => true, 'items' => $items], JSON_INVALID_UTF8_SUBSTITUTE);
  } catch (Throwable $e) {
    error_log('[transfer.php ajax=inv_by_loc] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_INVALID_UTF8_SUBSTITUTE);
  }
  exit;
}

// ---------------------------------------------
// Auth
// ---------------------------------------------
if (!isset($_SESSION['username'])) {
  header('Location: login.php'); exit;
}

$username = $_SESSION['username'];
$user_id  = (int)($_SESSION['user_id'] ?? 0);
$role_id  = (int)($_SESSION['role_id'] ?? 0);

date_default_timezone_set('America/Toronto');
$title = 'Transfer Inventory';

// ----------------- Load selector data for JS boot (cascading) -----------------
$rows = [];
$baysByRow   = [];  // row => [bay,...]
$levelsByRow = [];  // row:bay => [level,...]
$sidesByRow  = [];  // row:bay:level => [side,...]

$sql = "
  SELECT row_code, bay_num, level_code, side
  FROM location
  ORDER BY row_code,
           CAST(bay_num AS UNSIGNED), bay_num,
           level_code, side
";
$res = $conn->query($sql);
while ($r = $res->fetch_assoc()) {
  $row  = $r['row_code'];
  $bay  = $r['bay_num'];
  $lvl  = $r['level_code'];
  $side = $r['side'];

  if (!in_array($row, $rows, true)) $rows[] = $row;

  $baysByRow[$row]               = $baysByRow[$row]               ?? [];
  $levelsByRow[$row]             = $levelsByRow[$row]             ?? [];
  $levelsByRow[$row][$bay]       = $levelsByRow[$row][$bay]       ?? [];
  $sidesByRow[$row]              = $sidesByRow[$row]              ?? [];
  $sidesByRow[$row][$bay]        = $sidesByRow[$row][$bay]        ?? [];
  $sidesByRow[$row][$bay][$lvl]  = $sidesByRow[$row][$bay][$lvl]  ?? [];

  if (!in_array($bay,  $baysByRow[$row], true))                    $baysByRow[$row][] = $bay;
  if (!in_array($lvl,  $levelsByRow[$row][$bay], true))            $levelsByRow[$row][$bay][] = $lvl;
  if (!in_array($side, $sidesByRow[$row][$bay][$lvl], true))       $sidesByRow[$row][$bay][$lvl][] = $side;
}


// ----------------- Transfer handler (Approve) -----------------
$toast_msg = '';
$toast_type = 'info';
$show_history = false;
$history_src = $history_dst = [];
$inv_src = $inv_dst = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_transfer'])) {
    $sku_id = (int)($_POST['sku_id'] ?? 0);
    $qty = filter_input(INPUT_POST, 'qty', FILTER_VALIDATE_INT, [
      'options' => ['min_range' => 1]
    ]);
    $qty = $qty !== false && $qty !== null ? (int)$qty : 0;
    
    // Use the raw select values for display; numeric-compare in SQL handles 01/1
    $s_row  = trim($_POST['s_row']  ?? '');
    $s_bay  = trim($_POST['s_bay']  ?? '');
    $s_lvl  = trim($_POST['s_lvl']  ?? '');
    $s_side = trim($_POST['s_side'] ?? '');

    $d_row  = trim($_POST['d_row']  ?? '');
    $d_bay  = trim($_POST['d_bay']  ?? '');
    $d_lvl  = trim($_POST['d_lvl']  ?? '');
    $d_side = trim($_POST['d_side'] ?? '');
    
    if ($sku_id <= 0 || $qty <= 0) {
        $pos = abs($qty);
        $neg = -$pos;
        $toast_msg  = 'Select a SKU and enter a positive quantity.';
        $toast_type = 'error';
    } elseif (!$s_row || !$s_bay || !$s_lvl || !$s_side || !$d_row || !$d_bay || !$d_lvl || !$d_side) {
        $toast_msg  = 'Missing source or destination details.';
        $toast_type = 'error';
    } else {
        try {
            // Resolve/ensure locations (destination may be new)
            $srcLoc = find_loc_id($conn, $s_row, $s_bay, $s_lvl, $s_side);
            if (!$srcLoc) $srcLoc = ensure_location($conn, $s_row, $s_bay, $s_lvl, $s_side);

            $dstLoc = find_loc_id($conn, $d_row, $d_bay, $d_lvl, $d_side);
            if (!$dstLoc) $dstLoc = ensure_location($conn, $d_row, $d_bay, $d_lvl, $d_side);

            if (!$srcLoc || !$dstLoc) {
                $toast_msg  = "Invalid source or destination. SRC($s_row-$s_bay-$s_lvl-$s_side)=>$srcLoc, DST($d_row-$d_bay-$d_lvl-$d_side)=>$dstLoc";
                $toast_type = 'error';
            } elseif ($srcLoc == $dstLoc) {
                $toast_msg  = 'Source and destination cannot be the same.';
                $toast_type = 'error';
            } else {
                // 🔒 START TRANSACTION & LOCK ROW (New Block Placement) 🔒
                $conn->begin_transaction();

                // 1. Check and LOCK available stock at source
                $chk = $conn->prepare("
                    SELECT COALESCE(quantity,0) 
                    FROM inventory 
                    WHERE sku_id=? AND loc_id=? 
                    FOR UPDATE
                ");
                $chk->bind_param('ii', $sku_id, $srcLoc);
                $chk->execute();
                $avail = (int)$chk->get_result()->fetch_column();

                // 2. CHECK AVAILABILITY
                if ($qty > $avail) {
                    $conn->rollback(); // 👈 Must rollback to release the lock!
                    $toast_msg  = "Insufficient stock at source. Available: $avail. The stock may have been moved just before you approved.";
                    $toast_type = 'error';
                } else {
                    // All your original successful transfer logic goes here (steps 3 & 4)

                    $pos = $qty; // Ensure $pos/$neg are set for the movement inserts
                    $neg = -$qty;

                    // 3. EXECUTE TRANSFER (The row is now safely locked)

                   // Original (Guarded and now redundant logic due to lock)
                $dec = $conn->prepare("
                    UPDATE inventory
                        SET quantity = COALESCE(quantity,0) - ?
                      WHERE sku_id = ? AND loc_id = ? AND COALESCE(quantity,0) >= ?
                ");
                
                // Optimized (Relies on the lock and previous check)
                    $dec = $conn->prepare("
                        UPDATE inventory
                            SET quantity = quantity - ?
                          WHERE sku_id = ? AND loc_id = ?
                    ");
                    $dec->bind_param('iii', $pos, $sku_id, $srcLoc); // Only 3 parameters needed
                    $dec->execute();
                    
                    // The check on $dec->affected_rows !== 1 is still valuable to ensure the row was updated.
                    if ($dec->affected_rows !== 1) {
                        throw new RuntimeException("insufficient stock at source (DB Guard)");
                    }
                    
                    // 2) Destination: positive upsert
                    $inc = $conn->prepare("
                        INSERT INTO inventory (sku_id, loc_id, quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = COALESCE(quantity,0) + VALUES(quantity)
                    ");
                    $inc->bind_param('iii', $sku_id, $dstLoc, $pos);
                    $inc->execute();

                    // Movement refs
                    $ref = "Transfer $pos from $s_row-$s_bay-$s_lvl-$s_side to $d_row-$d_bay-$d_lvl-$d_side";
                    $uid = (int)($_SESSION['user_id'] ?? 0);
                    
                    // OUT
                    $mv1 = $conn->prepare("
                        INSERT INTO inventory_movements
                            (sku_id, loc_id, quantity_change, movement_type, reference, user_id, created_at)
                        VALUES (?, ?, ?, 'OUT', ?, ?, NOW())
                    ");
                    $mv1->bind_param('iiisi', $sku_id, $srcLoc, $neg, $ref, $uid);
                    $mv1->execute();
                    
                    // IN
                    $mv2 = $conn->prepare("
                        INSERT INTO inventory_movements
                            (sku_id, loc_id, quantity_change, movement_type, reference, user_id, created_at)
                        VALUES (?, ?, ?, 'IN', ?, ?, NOW())
                    ");
                    $mv2->bind_param('iiisi', $sku_id, $dstLoc, $pos, $ref, $uid);
                    $mv2->execute();

                    // 4. COMMIT
                    $conn->commit(); // 🔓 Lock is released here.

                    $toast_msg  = 'Transfer completed.';
                    $toast_type = 'success';
                    $show_history = true;

                    // Hydrate history lists immediately
                    // $history_src = fetch_history($conn, $s_row, $s_bay, $s_lvl, $s_side);
                    // $history_dst = fetch_history($conn, $d_row, $d_bay, $d_lvl, $d_side);
                    
                    $inv_src = fetch_current_inventory($conn, $s_row, $s_bay, $s_lvl, $s_side);
                    $inv_dst = fetch_current_inventory($conn, $d_row, $d_bay, $d_lvl, $d_side);
                }
                
                
            }
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $__) {} // Rollback if any error occurs
            $toast_msg  = 'Transfer failed: '.$e->getMessage();
            $toast_type = 'error';
            error_log('[transfer] exception: '.$e->getMessage());
        }
    }
}


// ----------------- View -----------------
ob_start();
?>
<h2>Transfer Inventory</h2>

<?php if ($toast_msg): ?>
  <div class="toast <?= htmlspecialchars($toast_type) ?>"><?= htmlspecialchars($toast_msg) ?></div>
<?php else: ?>
  <div class="toast info">Stage 1: search the source location. Stage 2: search the destination. Then choose SKU &amp; qty and approve.</div>
<?php endif; ?>

<form id="transferForm" method="post" autocomplete="off">
  <input type="hidden" name="do_transfer" value="1">
  <input type="hidden" name="sku_id" id="sku_id">
  <input type="hidden" name="s_row" id="s_row_val">
  <input type="hidden" name="s_bay" id="s_bay_val">
  <input type="hidden" name="s_lvl" id="s_lvl_val">
  <input type="hidden" name="s_side" id="s_side_val">
  <input type="hidden" name="d_row" id="d_row_val">
  <input type="hidden" name="d_bay" id="d_bay_val">
  <input type="hidden" name="d_lvl" id="d_lvl_val">
  <input type="hidden" name="d_side" id="d_side_val">

  <div class="grid-2col">
    <fieldset>
      <legend>1) Source Location</legend>
      <div class="grid grid-4">
        <div><label>Row</label><select id="s_row"></select></div>
        <div><label>Bay</label><select id="s_bay" disabled></select></div>
        <div><label>Level</label><select id="s_lvl" disabled></select></div>
        <div><label>Side</label><select id="s_side" disabled></select></div>
      </div>
      <div id="srcResultsWrap" class="results">
        <table id="srcTable" aria-label="Source inventory table">
          <thead><tr><th>SKU</th><th>Qty</th></tr></thead>
          <tbody><tr><td class="muted" colspan="2">Pick a full location to load items.</td></tr></tbody>
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
      </div>
      <div id="dstResultsWrap" class="results">
        <table id="dstTable" aria-label="Destination inventory table">
          <thead><tr><th>SKU</th><th>Qty</th></tr></thead>
          <tbody><tr><td class="muted" colspan="2">Pick a full location to load items.</td></tr></tbody>
        </table>
      </div>
    </fieldset>
  </div>

  <fieldset style="margin-top:12px;">
    <legend>3) Select SKU</legend>
    <div class="form-row-2">
      <div class="form-col">
        <label for="chosenSkuNum">Chosen SKU (from source results)</label>
        <input id="chosenSkuNum" type="text" placeholder="Click a row in Source results" readonly>
      </div>
      <div class="form-col">
        <label for="qty">Quantity</label>
        <input class="input" type="number" name="qty" id="qty" min="1" step="1" disabled>
      </div>
      <div class="form-note">Available at source: <span id="avail">—</span></div>
    </div>
  </fieldset>

  <div class="actions">
    <button class="btn" type="submit" id="approveBtn" disabled>Approve Transfer</button>
    <button class="btn secondary" type="reset" id="resetBtn">Reset</button>
  </div>
</form>

<?php if ($show_history): ?>
  <h2 style="margin-top:18px;">Recent History (Source &amp; Destination)</h2>
  <div class="grid-2col">
    <div id="sourceInventoryAfter">
      <h3>Source (<?= htmlspecialchars("$s_row-$s_bay-$s_lvl-$s_side") ?>)</h3>
      <table id="srcTableAfter" aria-label="Source inventory after transfer">
        <thead><tr><th>SKU</th><th>Qty</th></tr></thead>
        <tbody>
        <?php if (empty($inv_src)): ?>
          <tr><td colspan="2" class="muted">No items at this location.</td></tr>
        <?php else: ?>
          <?php foreach ($inv_src as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['sku_num']) ?></td>
              <td><?= htmlspecialchars((string)$item['quantity']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div id="destinationInventoryAfter">
      <h3>Destination (<?= htmlspecialchars("$d_row-$d_bay-$d_lvl-$d_side") ?>)</h3>
      <table id="dstTableAfter" aria-label="Destination inventory after transfer">
        <thead><tr><th>SKU</th><th>Qty</th></tr></thead>
        <tbody>
        <?php if (empty($inv_dst)): ?>
          <tr><td colspan="2" class="muted">No items at this location.</td></tr>
        <?php else: ?>
          <?php foreach ($inv_dst as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['sku_num']) ?></td>
              <td><?= htmlspecialchars((string)$item['quantity']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php
// Boot config for js/transfer.js
try {
  $boot = [
    'rows'        => $rows,
    'baysByRow'   => $baysByRow,
    'levelsByRow' => $levelsByRow,
    'sidesByRow'  => $sidesByRow,
    'ajaxPath'    => $_SERVER['PHP_SELF'],
  ];
  $boot_json = json_encode($boot, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
  $footer_js = '<script id="transfer-boot" type="application/json">'.$boot_json.'</script>';
} catch (Throwable $e) {
  error_log('[transfer.php] boot json encode failed: '.$e->getMessage());
  $footer_js = '<script id="transfer-boot" type="application/json">{"rows":[]}</script>';
}

$js = isset($js) && is_array($js) ? $js : [];
$js[] = '/js/transfer.js';

$content = ob_get_clean();
require __DIR__ . '/templates/layout.php';
