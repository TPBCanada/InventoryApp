<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';


if (!isset($_SESSION['username'])) {
  header('Location: ../login.php');
  exit;
}

$username = $_SESSION['username'];
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role_id = (int) ($_SESSION['role_id'] ?? 0);

if (!$conn) {
  die("Connection failed: " . mysqli_connect_error());
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn->set_charset('utf8mb4');
} catch (\Throwable $_) {
}

/* ========== Inline Suggest API (SKU only) ==========
   GET ?suggest=1&type=sku&q=term
   -> { ok:true, items:[{id:..., sku_num: "...", desc: "...", lot_required: 0/1}] }
===================================================== */
if (isset($_GET['suggest']) && $_GET['suggest'] === '1') {
  while (ob_get_level() > 0)
    ob_end_clean();

  header('Content-Type: application/json; charset=utf-8');

  $q = trim((string) ($_GET['q'] ?? ''));
  $items = [];

  try {
    $sql = "
      SELECT id, sku_num, `desc`, lot_required
      FROM sku
      WHERE status='ACTIVE'
        AND (sku_num LIKE CONCAT('%', ?, '%') OR `desc` LIKE CONCAT('%', ?, '%'))
      ORDER BY sku_num ASC
      LIMIT 30
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $q, $q);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
      $items[] = [
        'id' => (int) $r['id'],
        'sku_num' => (string) $r['sku_num'],
        'desc' => (string) ($r['desc'] ?? ''),
        'lot_required' => (int) ($r['lot_required'] ?? 0),
      ];
    }
    $stmt->close();
  } catch (\Throwable $e) {
    error_log('[take suggest] ' . $e->getMessage());
  }

  echo json_encode(['ok' => true, 'type' => 'sku', 'items' => $items], JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

if (!defined('SPECIAL_ROWS')) {
  define('SPECIAL_ROWS', $dynamic_special_rows);
}

$special_defaults = [
  'bay_num' => '01',
  'level_code' => '11',
  'side' => 'F',
];


$current_special_rows = SPECIAL_ROWS;

if (empty($current_special_rows)) {
  $structured_rows = ['R10', 'R11', 'R12'];
  $row_codes = [];

  $row_res = $conn->query("SELECT DISTINCT row_code FROM location");

  while ($row_res && ($rr = $row_res->fetch_assoc())) {
    $row_codes[] = strtoupper($rr['row_code']);
  }

  if ($row_res) {
    $row_res->close();
  }

  $current_special_rows = array_values(
    array_diff(
      array_unique($row_codes),
      $structured_rows
    )
  );

  error_log(
    "[place] SPECIAL_ROWS fallback derived: " .
    implode(", ", $current_special_rows)
  );
}



/* ---------- Pull all active locations (for buttons) ---------- */
$loc_list = [];
$res = $conn->query("SELECT id, row_code, bay_num, level_code, side 
 FROM location
 WHERE is_disabled = 0
 ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side");
while ($r = $res->fetch_assoc()) {
  $loc_list[] = [
    'id' => (int) $r['id'],
    'row_code' => (string) $r['row_code'],
    'bay_num' => (string) $r['bay_num'],
    'level_code' => (string) $r['level_code'],
    'side' => (string) $r['side'],
  ];
}

/* ---------- Pull all active SKUs once (sku_num + lot_required for client-side typeahead) ---------- */
$sku_all = [];
$skuRes = $conn->query("SELECT id, sku_num, `desc`, lot_required FROM sku WHERE status = 'ACTIVE' ORDER BY sku_num ASC");
while ($r = $skuRes->fetch_assoc()) {
  $sku_all[] = [
    'id' => (int) $r['id'],
    'sku_num' => (string) $r['sku_num'],
    'desc' => (string) $r['desc'],
    'lot_required' => (bool) ($r['lot_required'] ?? false),
  ];
}

/* ---------- Read selection from GET ---------- */
$row_code = trim($_GET['row_code'] ?? '');
$bay_num = trim($_GET['bay_num'] ?? '');
$level = trim($_GET['level_code'] ?? '');
$side = trim($_GET['side'] ?? '');

$sel = [
  'row_code' => $row_code,
  'bay_num' => $bay_num,
  'level_code' => $level,
  'side' => $side,
];

/* ---------- Resolve loc_id (tolerant) ---------- */
$loc_id = null;
$loc_label = '';

$norm_row = $row_code === '' ? '' : strtoupper($row_code);



// Use the constant SPECIAL_ROWS array
if ($norm_row && in_array($norm_row, $current_special_rows, true)) {
  $norm_bay = "01";
  $norm_level = "11";
  $norm_side = "F";

  // Update selection array/variables with forced defaults for subsequent processing/links
  $sel["bay_num"] = $norm_bay;
  $sel["level_code"] = $norm_level;
  $sel["side"] = $norm_side;

  $bay_num = $norm_bay;
  $level = $norm_level;
  $side = $norm_side;
} else {
  // Standard normalization logic for structured rows
  $norm_bay = $bay_num === "" ? "" : ltrim($bay_num, "0");
  if ($norm_bay === "" && $bay_num !== "") {
    $norm_bay = "0";
  }
  $norm_level = $level === "" ? "" : strtoupper($level);
  $norm_side = $side === "" ? "" : strtoupper($side);
}

if ($norm_row && $norm_bay !== '' && $norm_level && $norm_side) {
  $stmt = $conn->prepare(
    "SELECT id, row_code, bay_num, level_code, side
 FROM location
 WHERE UPPER(row_code) = ?
 AND CAST(bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
 AND UPPER(level_code) = ?
 AND LEFT(UPPER(side), 1) = LEFT(?, 1)
 AND is_disabled = 0
 LIMIT 1"
  );
  $stmt->bind_param('ssss', $norm_row, $norm_bay, $norm_level, $norm_side);
  $stmt->execute();
  $rs = $stmt->get_result();
  if ($rec = $rs->fetch_assoc()) {
    $loc_id = (int) $rec['id'];
    // FIX: Call getLocationDisplayLabel without the second array parameter
    $loc_label = getLocationDisplayLabel($rec);
  }
  $stmt->close();
}

/* ---------- Load current inventory at location (qty > 0) ---------- */
$rows_out = [];
$error_msg = '';
$success_msg = '';
$toast_msg = '';
$toast_type = '';
$toast_details = [];

if ($loc_id !== null) {
  $sql = "
    SELECT 
      s.id AS sku_id,
      s.sku_num AS sku,
      s.`desc` AS sku_description,
      s.status AS sku_status,
      s.lot_required AS lot_required,
      SUM(i.quantity) AS on_hand,
      MIN(lt.expiry_date) AS lot_expiry,
    (
      SELECT MAX(m.created_at)
      FROM inventory_movements m
      WHERE m.sku_id = s.id
      AND m.loc_id = p.loc_id
    ) AS last_moved_at,             
    (SELECT COALESCE(SUM(qty_requested - picked_qty), 0) 
      FROM back_requests 
      WHERE sku_id = s.id AND status = 'OPEN') as open_req_qty,
      (SELECT COALESCE(MAX(is_high_priority), 0) 
      FROM back_requests 
      WHERE sku_id = s.id AND status = 'OPEN') as has_priority
    FROM inventory i
    JOIN sku s ON s.id = i.sku_id
    JOIN pallet p ON p.id = i.pallet_id
    LEFT JOIN lot lt ON lt.id = i.lot_id
    WHERE p.loc_id = ?
    AND i.quantity > 0
    GROUP BY s.id, s.sku_num, s.`desc`, s.status, s.lot_required, p.loc_id
    ORDER BY s.sku_num ASC
  ";

  try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $loc_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $rows_out[] = [
        'sku_id' => (int) ($r['sku_id'] ?? 0),
        'sku' => (string) ($r['sku'] ?? ''),
        'desc' => (string) ($r['sku_description'] ?? ''),
        'sku_status' => (string) ($r['sku_status'] ?? 'ACTIVE'),
        'on_hand' => (int) ($r['on_hand'] ?? 0),
        'lot_required' => (int) ($r['lot_required'] ?? 0),
        'last_moved_at' => $r['last_moved_at'] ?? null,
        'lot_expiry' => $r['lot_expiry'] ?? null,
        'open_req_qty' => (int) ($r['open_req_qty'] ?? 0),
        'has_priority' => (int) ($r['has_priority'] ?? 0)
      ];
    }
    $stmt->close();
  } catch (\Throwable $e) {
    $error_msg = 'Query failed. Please try again.';
  }
}

/* ---------- POST: remove_sku (the “reverse” of add) ---------- */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['action']) && $_POST['action'] === 'remove_sku'
) {

  $r_loc_id = (int) ($_POST['loc_id'] ?? 0);
  $r_sku_num = trim($_POST['sku_num'] ?? '');
  $r_quantity = (int) ($_POST['quantity'] ?? 0);

  // Lookup SKU id by number (ACTIVE)
  $r_sku_id = 0;
  $r_sku_desc = '';
  $r_sku_lot_required = false;
  if ($r_sku_num !== '') {
    $stmt_sku = $conn->prepare("SELECT id, `desc`, lot_required FROM sku WHERE sku_num = ? AND status = 'ACTIVE'");
    $stmt_sku->bind_param('s', $r_sku_num);
    $stmt_sku->execute();
    $res_sku = $stmt_sku->get_result();
    if ($sku_record = $res_sku->fetch_assoc()) {
      $r_sku_id = (int) $sku_record['id'];
      $r_sku_desc = (string) ($sku_record['desc'] ?? '');
      $r_sku_lot_required = (bool) ($sku_record['lot_required'] ?? false);
    }
    $stmt_sku->close();
  }

  $r_lot_id = (int) ($_POST['lot_id'] ?? 0);

  if ($r_loc_id === $loc_id && $r_loc_id > 0 && $r_sku_id > 0 && $r_quantity > 0) {
    $ok = false;
    $txn_started = false;
    try {
      $conn->begin_transaction();
      $txn_started = true;
      if ($r_lot_id > 0) {
        $stmt_rows = $conn->prepare("
          SELECT i.pallet_id, i.quantity
          FROM inventory i
          JOIN pallet p ON p.id = i.pallet_id
          WHERE p.loc_id = ? AND i.sku_id = ? AND i.lot_id = ?
          ORDER BY i.quantity DESC, i.pallet_id ASC
          FOR UPDATE
        ");
        $stmt_rows->bind_param('iii', $r_loc_id, $r_sku_id, $r_lot_id);
      } else {
        $stmt_rows = $conn->prepare("
          SELECT i.pallet_id, i.quantity
          FROM inventory i
          JOIN pallet p ON p.id = i.pallet_id
          WHERE p.loc_id = ? AND i.sku_id = ?
          ORDER BY i.quantity DESC, i.pallet_id ASC
          FOR UPDATE
        ");
        $stmt_rows->bind_param('ii', $r_loc_id, $r_sku_id);
      }
      $stmt_rows->execute();
      $res_rows = $stmt_rows->get_result();

      $pallet_rows = [];
      $total_on_hand = 0;
      while ($row = $res_rows->fetch_assoc()) {
        $qty = (int) $row['quantity'];
        $pallet_rows[] = [
          'pallet_id' => (int) $row['pallet_id'],
          'quantity' => $qty,
        ];
        $total_on_hand += $qty;
      }
      $stmt_rows->close();

      if ($total_on_hand <= 0) {
        throw new \RuntimeException('Nothing on hand for this SKU at the selected location.');
      }
      if ($r_quantity > $total_on_hand) {
        throw new \RuntimeException("Cannot remove {$r_quantity}. Only {$total_on_hand} on hand.");
      }

      if ($r_lot_id > 0) {
        $update_stmt = $conn->prepare("
          UPDATE inventory
          SET quantity = quantity - ?
          WHERE pallet_id = ? AND sku_id = ? AND lot_id = ? AND quantity >= ?
        ");
        $delete_stmt = $conn->prepare("
          DELETE FROM inventory
          WHERE pallet_id = ? AND sku_id = ? AND lot_id = ? AND quantity <= 0
        ");
      } else {
        $update_stmt = $conn->prepare("
          UPDATE inventory
          SET quantity = quantity - ?
          WHERE pallet_id = ? AND sku_id = ? AND quantity >= ?
        ");
        $delete_stmt = $conn->prepare("
          DELETE FROM inventory
          WHERE pallet_id = ? AND sku_id = ? AND quantity <= 0
        ");
      }

      $update_qty = 0;
      $update_pallet = 0;
      $update_threshold = 0;
      if ($r_lot_id > 0) {
        $update_stmt->bind_param('iiiii', $update_qty, $update_pallet, $r_sku_id, $r_lot_id, $update_threshold);
      } else {
        $update_stmt->bind_param('iiii', $update_qty, $update_pallet, $r_sku_id, $update_threshold);
      }

      $delete_pallet = 0;
      if ($r_lot_id > 0) {
        $delete_stmt->bind_param('iii', $delete_pallet, $r_sku_id, $r_lot_id);
      } else {
        $delete_stmt->bind_param('ii', $delete_pallet, $r_sku_id);
      }

      $remaining = $r_quantity;
      $chunk_notes = [];

      foreach ($pallet_rows as $row) {
        if ($remaining <= 0) {
          break;
        }
        $deduct = min($remaining, $row['quantity']);
        $update_qty = $deduct;
        $update_pallet = $row['pallet_id'];
        $update_threshold = $deduct;
        $update_stmt->execute();
        if ($update_stmt->affected_rows === 0) {
          throw new \RuntimeException('Removal failed while updating inventory entry.');
        }
        if ($row['quantity'] === $deduct) {
          $delete_pallet = $row['pallet_id'];
          $delete_stmt->execute();
        }
        $chunk_notes[] = (string) $deduct;
        $remaining -= $deduct;
      }

      $update_stmt->close();
      $delete_stmt->close();

      if ($remaining > 0) {
        throw new \RuntimeException('Removal halted due to concurrent updates. Please retry.');
      }

      $movement_type = 'OUT';
      $qty_change = -$r_quantity;
      $batch_note = $chunk_notes ? implode(', ', $chunk_notes) : (string) $r_quantity;
      $reference = sprintf('Removed via take.php (batches: %s)', $batch_note);
      log_inventory_movement($conn, $r_sku_id, $r_loc_id, $qty_change, $movement_type, $reference, $user_id);

      $conn->commit();
      $txn_started = false;
      $ok = true;
    } catch (\RuntimeException $e) {
      if ($txn_started) {
        $conn->rollback();
        $txn_started = false;
      }
      $error_msg = $e->getMessage();
      $ok = false;
    } catch (\Throwable $e) {
      if ($txn_started) {
        $conn->rollback();
        $txn_started = false;
      }
      $error_msg = 'Removal failed due to a database transaction error. Error: ' . h($e->getMessage());
      $ok = false;
    }

    if ($ok) {
      $_SESSION['toast'] = [
        'msg' => 'Inventory successfully removed.',
        'type' => 'success',
        'details' => [
          [
            'sku' => $r_sku_num,
            'desc' => $r_sku_desc,
            'qty' => $r_quantity,
          ]
        ],
      ];
      $qs = http_build_query(data: $sel);
      header("Location: take.php?{$qs}");
      exit;
    }
  } else {
    $error_msg = 'Invalid data for removal. Check Location, Quantity, or if the SKU Number is valid/active.';
  }
}

// Read toast set during redirect (if any)
if (isset($_SESSION['toast'])) {
  $toast_msg = $_SESSION['toast']['msg'] ?? '';
  $toast_type = $_SESSION['toast']['type'] ?? '';
  $toast_details = $_SESSION['toast']['details'] ?? [];
  unset($_SESSION['toast']);
} elseif ($success_msg) {
  $toast_msg = $success_msg;
  $toast_type = 'success';
} elseif ($error_msg) {
  $toast_msg = $error_msg;
  $toast_type = 'error';
}

/* ---------- Page Output (Remove UI) ---------- */
$title = 'Remove Stock (Take Out)';
$page_class = 'page-loc-details';
$extra_css = '<link rel="stylesheet" href="/refactor_css/suggest.css">';
$js[] = '/js/lot_management.js';
ob_start();
?>

<h2 class="title">Remove Stock from Location</h2>

<?php if ($toast_msg): ?>
  <div class="toast toast--banner <?= h($toast_type) ?>" <?= $toast_type === 'success' && !empty($toast_details) ? ' data-toast-details="' . h(json_encode($toast_details)) . '"' : '' ?>>
    <?= h($toast_msg) ?>
  </div>
<?php endif; ?>

<form method="get" class="card" style="padding:12px; margin-bottom:16px;">
  <div class="mt-1">
    <div class="text-muted small mb-1">Row</div>
    <div id="rowButtons" class="d-flex flex-wrap gap-2"></div>
  </div>

  <div class="mt-3">
    <div class="text-muted small mb-1">Bay</div>
    <div id="bayButtons" class="d-flex flex-wrap gap-2"></div>
  </div>

  <div class="mt-3">
    <div class="text-muted small mb-1">Level</div>
    <div id="levelButtons" class="d-flex flex-wrap gap-2"></div>
  </div>

  <div class="mt-3">
    <div class="text-muted small mb-1">Side</div>
    <div id="sideButtons" class="d-flex flex-wrap gap-2"></div>
  </div>

  <!-- Clear Selection Button -->
  <div class="mb-3 d-flex align-items-center gap-2">
    <button type="button" id="btnClearSelection" class="btn btn-outline-danger btn-sm">
      <i class="bi bi-x-circle"></i> Clear Selection
    </button>
  </div>



  <input type="hidden" id="row_code_input" name="row_code" value="<?= h($sel['row_code']) ?>">
  <input type="hidden" id="bay_num_input" name="bay_num" value="<?= h($sel['bay_num']) ?>">
  <input type="hidden" id="level_code_input" name="level_code" value="<?= h($sel['level_code']) ?>">
  <input type="hidden" id="side_input" name="side" value="<?= h($sel['side']) ?>">
</form>


<?php if ($loc_id !== null): ?>
  <div class="mb-4">
    <span class="badge bg-info text-dark">Selected Location: <?= h($loc_label) ?></span>
  </div>

  <div class="card p-3 mb-4">
    <h4>Remove Stock</h4>
    <form method="POST" action="take.php?<?= h(http_build_query($sel)) ?>">
      <div class="row g-3" style="margin-right: 12px; align-items: flex-end;">
        <div class="col-lg-8 col-md-7">
          <label for="sku_num" class="form-label mb-1">SKU Number</label>
          <div class="input-group flex">
            <input type="text" class="form-control" id="sku_num" name="sku_num" required placeholder="Enter SKU"
              autocomplete="off" data-suggest="inventory" data-suggest-box="takeSuggest" data-suggest-submit="false">
            <button class="btn btn-outline-secondary" type="button" id="scan-button">Search</button>
          </div>
          <div id="takeSuggest" class="inv-suggest"></div>
        </div>

        <div class="col-lg-4 col-md-5" style="align-self: start;">
          <label for="rmQuantity" class="form-label mb-1">Quantity</label>
          <div class="input-group flex-nowrap">
            <input type="number" class="form-control" id="rmQuantity" name="quantity" min="1" value="1" required>
          </div>
        </div>

        <div class="col-lg-2 col-md-5">
          <button type="submit" id="scanBtn" class="btn btn-danger" data-sku-id="" data-sku-num="" data-lot-required="0"
            data-lot-select="1" data-lot-mode="remove" data-loc-id="<?= h((string) $loc_id) ?>">
            Remove inventory
          </button>
        </div>

        <input type="hidden" name="action" value="remove_sku">
        <input type="hidden" name="loc_id" value="<?= h((string) $loc_id) ?>">
        <input type="hidden" name="lot_id" id="take_lot_id" value="">
      </div>
    </form>
    <div id="scan-results" class="mt-3"></div>
  </div>

  <div class="card" style="overflow:auto;">
    <h5 class="card-header">Current Inventory at <?= h($loc_label) ?></h5>
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>SKU</th>
          <th>Description</th>
          <th>Status</th>
          <th class="text-end">On-Hand</th>
          <th>Last Movement At</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($loc_id !== null && empty($rows_out)): ?>
          <tr>
            <td colspan="5" class="text-muted">No inventory with quantity &gt; 0 at this location.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows_out as $r): ?>
            <tr class="sku-row-selectable">
              <td class="sku-selector" data-sku="<?= h($r["sku"]) ?>">
                <button type="button" class="btn btn-sm btn-link p-0 fw-bold text-decoration-none"
                  style="vertical-align: middle;">
                  <?= h((string) $r["sku"]) ?>
                </button>
                <?php if (!empty($r["lot_required"])): ?>
                  <span class="badge ms-1 lot-exp-badge">Lot</span>
                <?php endif; ?>
                <?= getRequestBadge((int) ($r['open_req_qty'] ?? 0), (int) ($r['has_priority'] ?? 0)) ?>
              </td>
              <td><?= h($r['desc']) ?></td>

              <td><?= h($r['sku_status'] ?? 'ACTIVE') ?></td>

              <td class="text-end"><?= h((string) $r['on_hand']) ?></td>
              <td><?= $r['last_moved_at'] ? h($r['last_moved_at']) : '<span class="text-muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($_GET): ?>
  <div class="alert alert-warning">No matching location found for selection: **<?= h($row_code) ?>**.</div>
<?php else: ?>
  <div class="alert alert-info">Pick a location above to begin removing stock.</div>
<?php endif; ?>
<?php include __DIR__ . "/utils/lot_management.php"; ?>

<?php
$content = ob_get_clean();

/* ---------- Footer JS ---------- */
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$boot_data = [
  'loc_list' => $loc_list,
  'sku_all' => $sku_all,
  'specialRows' => $current_special_rows,
  'specialDefaults' => $special_defaults,
];
$loc_json = json_encode($boot_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$js_fs = __DIR__ . '/js/location-buttons.js';
$ver = is_file($js_fs) ? fileMtime($js_fs) : time();
$script_src = $BASE_URL . '/js/location-buttons.js?v=' . $ver;

// Part 1: interpolate PHP variables safely
$footer_js = <<<HTML
<script id="loc-data" type="application/json">{$loc_json}</script>
<script id="sku-data" type="application/json">{$loc_json}</script>

<style>
/* Modal fallback (matches transfer/place styling) */
.modal {
position: fixed;
inset: 0;
display: none;
align-items: center;
justify-content: center;
z-index: 1000;
}
.modal.is-open {
display: flex;
}
.modal__backdrop {
position: absolute;
inset: 0;
background: rgba(0, 0, 0, 0.45);
}
.modal__dialog {
position: relative;
background: #fff;
padding: 20px 24px;
border-radius: 8px;
max-width: 420px;
width: 90%;
box-shadow: 0 12px 25px rgba(0, 0, 0, 0.25);
z-index: 1001;
}
.modal__body { margin: 8px 0 16px; }
.modal__actions {
display: flex;
gap: 8px;
justify-content: flex-end;
flex-wrap: wrap;
}
</style>

<script src="{$script_src}"></script>
<script defer src="/inventory/js/suggest.js"></script>
<script>
(function(){
  var dataEl = document.getElementById('sku-data');
  var data = dataEl ? JSON.parse(dataEl.textContent) : {};
  var ALL_SKUS = Array.isArray(data.sku_all) ? data.sku_all : [];
  var input = document.getElementById('sku_num');
  var removeBtn = document.getElementById('scanBtn');
  var lotIdInput = document.getElementById('take_lot_id');

  function isLotRequired(item){
    if (!item) return false;
    return item.lot_required === true || item.lot_required === 1 || item.lot_required === '1';
  }

  function findSkuItem(skuValue){
    var value = String(skuValue || '').trim().toLowerCase();
    if (!value) return null;
    for (var i = 0; i < ALL_SKUS.length; i++) {
      var item = ALL_SKUS[i] || {};
      var skuNum = String(item.sku_num || '').toLowerCase();
      if (skuNum && skuNum === value) return item;
    }
    return null;
  }

  function syncRemoveButton(item){
    if (!removeBtn) return;
    if (!item) {
      removeBtn.setAttribute('data-sku-id', '');
      removeBtn.setAttribute('data-sku-num', '');
      removeBtn.setAttribute('data-lot-required', '0');
      removeBtn.classList.remove('btn-lot');
      removeBtn.type = 'submit';
      if (lotIdInput) lotIdInput.value = '';
      return;
    }
    removeBtn.setAttribute('data-sku-id', item.id != null ? String(item.id) : '');
    removeBtn.setAttribute('data-sku-num', item.sku_num || '');
    var lotRequired = isLotRequired(item);
    removeBtn.setAttribute('data-lot-required', lotRequired ? '1' : '0');
    if (lotRequired) {
      removeBtn.classList.add('btn-lot');
      removeBtn.type = 'button';
    } else {
      removeBtn.classList.remove('btn-lot');
      removeBtn.type = 'submit';
    }
    if (lotIdInput) lotIdInput.value = '';
  }

  function syncRemoveButtonForSku(skuValue){
    var item = findSkuItem(skuValue);
    syncRemoveButton(item);
    return item;
  }

  window.syncRemoveButtonForSku = syncRemoveButtonForSku;

  if (input) {
    input.addEventListener('change', function(){ syncRemoveButtonForSku(input.value); });
    input.addEventListener('blur', function(){ syncRemoveButtonForSku(input.value); });
  }

  if (removeBtn) {
    removeBtn.addEventListener('click', function(e){
      syncRemoveButtonForSku(input ? input.value : '');
      if (removeBtn.classList.contains('btn-lot')) {
        e.preventDefault();
      }
    });
  }
})();
</script>
HTML;

// Part 2: JS that contains \${...} must be in a NOWDOC (no interpolation)
$footer_js .= <<<'HTML'
<script>
(function(){
var el = document.getElementById('loc-data');
var data = el ? JSON.parse(el.textContent) : {};

window.PLACE_LOCATIONS = data.loc_list || [];

// Store special rows globally for location-buttons.js logic
window.SPECIAL_ROWS = data.specialRows || [];
// Defaults used when a special row is chosen (mirrors server-side defaults)
window.SPECIAL_ROW_DEFAULTS = data.specialDefaults || { bay_num: '01', level_code: '11', side: 'F' };

// Rule: R11 has only Front (same rule you had)
window.filterSides = function(ctx){
if (!ctx || !ctx.row || !ctx.sides) return (ctx && ctx.sides) ? ctx.sides : [];
return (ctx.row === 'R11') ? ctx.sides.filter(function(s){ return s !== 'Back'; }) : ctx.sides;
};
})();
</script>

<script>
// Simple renderer for scan results (Existing function, kept)
function renderScanResults(container, data, selectedLocId) {
 if (!data || !Array.isArray(data.rows) || data.rows.length === 0) {
 container.innerHTML = '<div class="alert alert-warning mb-0">No matching inventory found for this SKU.</div>';
 return;
}

// Find row for the selected location (if any)
 var matchAtSelected = null;
 if (selectedLocId) {
 matchAtSelected = data.rows.find(function(r){ return String(r.loc_id) === String(selectedLocId); }) || null;
}

// Optional total
 var total = 0;
 var firstSku = (data.rows[0] && data.rows[0].sku_num) ? data.rows[0].sku_num : '';
 if (data.totals && data.totals[firstSku]) total = data.totals[firstSku].sum || 0;

 var rowsHtml = data.rows.map(function(r) {
 var locCode = [r.row_code, r.bay_num, r.level_code, r.side].join('-');
 var moved = r.last_moved_at ? r.last_moved_at : '—';
 var isCurrent = (String(r.loc_id) === String(selectedLocId));
 return `
 <tr data-loc-id="${r.loc_id}" ${isCurrent ? 'class="table-success"' : ''}>
 <td>${r.sku_num}</td>
 <td>${r.sku_desc}</td>
 <td>${locCode}${isCurrent ? ' <span class="badge bg-success ms-2">Selected</span>' : ''}</td>
 <td class="text-end">${r.on_hand}</td>
 <td>${moved}</td>
 </tr>
`;
}).join('');

 container.innerHTML = `
<div class="card">
 <div class="card-header d-flex justify-content-between align-items-center">
 <span>Scan Results</span>
 <small class="text-muted">Path: ${data.path}${total ? ` • Total on hand: ${total}` : ''}</small>
 </div>
 <div class="table-responsive">
 <table class="table table-sm table-hover mb-0">
 <thead class="table-light">
 <tr>
  <th>SKU</th>
  <th>Description</th>
  <th>Location</th>
  <th class="text-end">On-Hand</th>
  <th>Last Movement</th>
 </tr>
 <tbody>${rowsHtml}</tbody>
 </table>
 </div>
</div>
`;

// If we have a match at the selected location, set max and focus qty
 var qty = document.getElementById('rmQuantity');
 if (qty) {
 if (matchAtSelected) {
 qty.setAttribute('max', String(matchAtSelected.on_hand));
} else {
 qty.removeAttribute('max');
}
 qty.focus();
}
}

// Hook up the Scan button and Enter key (Existing function, retained)
(function(){
 var scanBtn = document.getElementById('scan-button');
 var skuInput = document.getElementById('sku_num');
 var resultsEl = document.getElementById('scan-results');
 var selectedLoc = (document.querySelector('input[name="loc_id"]') || {}).value || '';

 async function fetchAndShow() {
 var sku = (skuInput && skuInput.value ? skuInput.value : '').trim();
 if (!sku) {
 resultsEl.innerHTML = '<div class="alert alert-info mb-0">Enter/scan a SKU first.</div>';
 if (skuInput) skuInput.focus();
 return;
}
 resultsEl.innerHTML = '<div class="text-muted">Searching…</div>';
 try {
 const res = await fetch('invSku.php?ajax=1&sku_q=' + encodeURIComponent(sku), {
 headers: { 'X-Requested-With': 'fetch' }
 });
 if (!res.ok) throw new Error('HTTP ' + res.status);
 const data = await res.json();
 renderScanResults(resultsEl, data, selectedLoc);
} catch (err) {
 resultsEl.innerHTML = '<div class="alert alert-danger mb-0">Lookup failed. Please try again.</div>';
 console.error(err);
}
}

  if (scanBtn) scanBtn.addEventListener('click', function(e){ e.preventDefault(); fetchAndShow(); });
  if (skuInput) skuInput.addEventListener('keydown', function(e){
  if (e.key === 'Enter') { e.preventDefault(); fetchAndShow(); }
 });
})();
</script>

<script>
// Click a SKU in the table to populate the REMOVE input & sync lot-required state
(function() {
  var tableBody = document.querySelector('.table tbody');
  var skuInput = document.getElementById('sku_num');
  var quantityInput = document.getElementById('rmQuantity');

  if (tableBody && skuInput && quantityInput) {
    tableBody.addEventListener('click', function(e) {
      var cellSku = e.target.closest('.sku-selector');
      if (!cellSku) return;

      var selectedSku = cellSku.getAttribute('data-sku');
      if (selectedSku) {
        skuInput.value = selectedSku;
        if (window.syncRemoveButtonForSku) {
          window.syncRemoveButtonForSku(selectedSku);
        }
      }

      quantityInput.value = 1;
      quantityInput.focus();
    });
  }
})();
</script>

<script>
// Auto-submit after row/side selection (matches place.php behavior)
(function(){
  var rowWrap = document.getElementById('rowButtons');
  var sideWrap = document.getElementById('sideButtons');

  var specialRows = (window.SPECIAL_ROWS || []).map(function(r){ return String(r).toUpperCase(); });

  // Check if a row is a special row (using the new global array from PHP)
  function isSpecialRow(rowCode) {
    var code = (rowCode || '').toUpperCase();
    return specialRows.indexOf(code) !== -1;
  }

  // Auto-submit on special row click (no bay/level/side needed)
  if (rowWrap) {
    rowWrap.addEventListener('click', function(e){
      if (e.target && e.target.tagName === 'BUTTON') {
        var r = e.target.getAttribute('data-value');
        if (r && isSpecialRow(r)) {
          setTimeout(function(){
            var form = document.querySelector('form[method="get"]');
            if (form) form.submit();
          }, 0);
        }
      }
    });
  }

  if (!sideWrap) return;

  sideWrap.addEventListener('click', function(e){
    if (e.target && e.target.tagName === 'BUTTON') {
      setTimeout(function(){
        var r = document.getElementById('row_code_input')?.value;
        var b = document.getElementById('bay_num_input')?.value;
        var l = document.getElementById('level_code_input')?.value;
        var s = document.getElementById('side_input')?.value;

        // If it's a special row, only the row code is needed for submission (all other inputs are forced by PHP)
        if (isSpecialRow(r)) {
          var form = document.querySelector('form[method="get"]');
          if (form) form.submit();
        }
        // Otherwise, check if all 4 components are present for standard racking
        else if (r && b && l && s) {
          var form = document.querySelector('form[method="get"]');
          if (form) form.submit();
        }
      }, 0);
    }
  });
})();
</script>

<script>
// NEW: Click a SKU in the table to populate the removal input & focus qty
(function() {
 var tableBody = document.querySelector('.table tbody');
 var skuInput = document.getElementById('sku_num');
 var quantityInput = document.getElementById('rmQuantity');

 if (tableBody && skuInput && quantityInput) {
  tableBody.addEventListener('click', function(e) {
   var cell = e.target.closest('.sku-selector');
   if (cell) {
    var selectedSku = cell.getAttribute('data-sku');
    if (selectedSku) {
     skuInput.value = selectedSku;
     quantityInput.value = 1;
     quantityInput.focus();
    }
 }
 });
 }
})();
</script>

<!-- Confirmation modal (mirrors transfer flow) -->
<div id="transferConfirmModal" class="modal" hidden aria-hidden="true">
  <div class="modal__backdrop"></div>
  <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="transferConfirmTitle">
    <h3 id="transferConfirmTitle">Confirm Removal</h3>
    <p id="transferConfirmMessage" class="modal__body">
      Are you sure you want to remove this inventory from the selected location?
    </p>
    <div class="modal__actions">
      <button type="button" class="btn" id="transferConfirmYes">Yes, remove inventory</button>
      <button type="button" class="btn secondary" id="transferConfirmNo">Cancel</button>
    </div>
  </div>
</div>

<script>
// Toast + confirmation helpers inspired by transfer.js
(function(){
  function makeToastCloseable(toastEl) {
    if (!toastEl) return null;
    let closeBtn = toastEl.querySelector('.toast__close');
    if (!closeBtn) {
      closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'toast__close';
      closeBtn.textContent = 'Close';
      toastEl.appendChild(closeBtn);
    }
    closeBtn.style.position = 'absolute';
    closeBtn.style.top = '12px';
    closeBtn.style.right = '14px';
    closeBtn.style.border = 'none';
    closeBtn.style.background = 'transparent';
    closeBtn.style.color = 'inherit';
    closeBtn.style.fontWeight = '700';
    closeBtn.style.cursor = 'pointer';
    closeBtn.style.fontSize = '0.95rem';
    const handleClose = () => toastEl.remove();
    closeBtn.onclick = handleClose;
    toastEl.addEventListener('keydown', evt => { if (evt.key === 'Escape') handleClose(); });
    if (!toastEl.getAttribute('role')) toastEl.setAttribute('role', 'alertdialog');
    if (!toastEl.getAttribute('aria-live')) toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('tabindex', '-1');
    if (typeof toastEl.focus === 'function') { setTimeout(() => toastEl.focus(), 0); }
    return toastEl;
  }

  function loadActionApprovedToast(options = {}) {
    const opts = typeof options === 'string' ? { message: options } : options;
    const { message, meta } = opts;
    const existing = document.querySelector('.toast.toast--banner');
    if (existing) existing.remove();
    const toastEl = document.createElement('div');
    toastEl.className = 'toast toast--banner success';
    const title = document.createElement('div');
    title.className = 'toast__headline';
    title.textContent = message || 'Action completed';
    toastEl.appendChild(title);
    const details = Array.isArray(meta) ? meta : (meta ? [meta] : []);
    if (details.length) {
      const list = document.createElement('ul');
      list.className = 'toast__details';
      details.forEach(detail => {
        const li = document.createElement('li');
        if (detail && typeof detail === 'object') {
          const row = (label, value) => {
            const wrap = document.createElement('div');
            wrap.className = 'toast__details-row';
            const lbl = document.createElement('span');
            lbl.className = 'toast__details-label';
            lbl.textContent = label;
            const val = document.createElement('span');
            val.className = 'toast__details-value';
            val.textContent = value;
            wrap.appendChild(lbl);
            wrap.appendChild(val);
            li.appendChild(wrap);
          };
          row('Sku', detail.sku || detail.sku_num || '');
          row('Desc', detail.desc || detail.sku_desc || '');
          row('Qty', detail.qty ?? '');
        } else {
          li.textContent = String(detail);
        }
        list.appendChild(li);
      });
      toastEl.appendChild(list);
    }
    makeToastCloseable(toastEl);
    document.body.appendChild(toastEl);
    return toastEl;
  }

  const successToast = document.querySelector('.toast.toast--banner.success');
  if (successToast) {
    const msg = (successToast.textContent || '').trim();
    let meta = [];
    const rawDetails = successToast.dataset.toastDetails;
    if (rawDetails) {
      try {
        const parsed = JSON.parse(rawDetails);
        if (Array.isArray(parsed)) {
          meta = parsed.map(det => ({
            sku: det?.sku || det?.sku_num || '',
            desc: det?.desc || det?.sku_desc || '',
            qty: det?.qty ?? ''
          }));
        }
      } catch (err) {
        console.warn('[take] failed to parse toast details', err);
      }
    }
    successToast.remove();
    loadActionApprovedToast({ message: msg, meta });
  } else {
    const infoToast = document.querySelector('.toast.toast--banner');
    if (infoToast) { makeToastCloseable(infoToast); }
  }

  const modal = document.getElementById('transferConfirmModal');
  const modalMsg = document.getElementById('transferConfirmMessage');
  const yesBtn = document.getElementById('transferConfirmYes');
  const noBtn = document.getElementById('transferConfirmNo');
  let pendingAction = null;

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modal.setAttribute('hidden', 'hidden');
    pendingAction = null;
  }

  function openModal(message, onConfirm) {
    if (!modal || !modalMsg) {
      if (typeof onConfirm === 'function') onConfirm();
      return;
    }
    pendingAction = typeof onConfirm === 'function' ? onConfirm : null;
    modalMsg.textContent = message || 'Are you sure you want to continue?';
    modal.removeAttribute('hidden');
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('is-open');
    if (yesBtn) yesBtn.focus();
  }

  if (yesBtn) {
    yesBtn.addEventListener('click', () => {
      const action = pendingAction;
      closeModal();
      if (typeof action === 'function') action();
    });
  }
  if (noBtn) {
    noBtn.addEventListener('click', closeModal);
  }

  const postForm = document.querySelector('form[method=\"POST\"][action^=\"take.php\"]');
  let allowSubmit = false;
  if (postForm) {
    postForm.addEventListener('submit', evt => {
      if (allowSubmit) return;
      evt.preventDefault();
      openModal('Confirm removing inventory from this location?', () => {
        allowSubmit = true;
        postForm.submit();
      });
    });
  }

  window.takeConfirm = { open: openModal, close: closeModal };
})();
</script>

<script>
  // Clear selection button logic
(function(){
    var form = document.querySelector('form[method="get"]');
    var btnClear = document.getElementById('btnClearSelection');
    
    // Define the inputs inside this scope so they are available
    var inRow   = document.getElementById('row_code_input');
    var inBay   = document.getElementById('bay_num_input');
    var inLevel = document.getElementById('level_code_input');
    var inSide  = document.getElementById('side_input');

    if (btnClear) {
        btnClear.addEventListener('click', function() {
            // 1. Clear the actual hidden input values
            if (inRow)   inRow.value   = '';
            if (inBay)   inBay.value   = '';
            if (inLevel) inLevel.value = '';
            if (inSide)  inSide.value  = '';

            console.debug('[invLoc] Selection cleared, submitting form...');
            
            // 2. Submit the form to reload the page to the initial state
            if (form) {
                form.submit();
            } else {
                // Fallback: reload page without query strings
                window.location.href = window.location.pathname;
            }
        });
    }
})();
</script>
HTML;

require __DIR__ . '/templates/layout.php';
