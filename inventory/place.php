<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . "/dbinv.php";
require_once __DIR__ . "/utils/helpers.php";
require_once __DIR__ . '/../utils/config_init.php';

if (!isset($_SESSION["username"])) {
  header("Location: ../login.php");
  exit();
}

$username = $_SESSION["username"];
$user_id = (int) ($_SESSION["user_id"] ?? 0);
$role_id = (int) ($_SESSION["role_id"] ?? 0);

if (!$conn) {
  die("Connection failed: " . mysqli_connect_error());
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn->set_charset("utf8mb4");
} catch (\Throwable $_) {
}

/* ========== Inline Suggest API (SKU only) ==========
   GET ?suggest=1&type=sku&q=term
   -> { ok:true, items:[{id, sku_num, desc, lot_required}] }
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
    error_log('[place suggest] ' . $e->getMessage());
  }

  echo json_encode(['ok' => true, 'type' => 'sku', 'items' => $items], JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

if (!defined("SPECIAL_ROWS")) {
  define("SPECIAL_ROWS", $dynamic_special_rows);
}

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


// /* ---------- Pull all active locations (for buttons) ---------- */

$loc_list = [];

$res = $conn->query("SELECT id, row_code, bay_num, level_code, side 
 FROM location
 WHERE is_disabled = 0
 ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side");
while ($r = $res->fetch_assoc()) {
  $loc_list[] = [
    "id" => (int) $r["id"],
    "row_code" => (string) $r["row_code"],
    "bay_num" => (string) $r["bay_num"],
    "level_code" => (string) $r["level_code"],
    "side" => (string) $r["side"],
  ];
}

/* ---------- Pull all active SKUs once (sku_num and desc for client-side typeahead) ---------- */
$sku_all = [];
$skuRes = $conn->query(
  "SELECT id, sku_num, `desc`, lot_required FROM sku WHERE status = 'ACTIVE' ORDER BY sku_num ASC",
);
while ($r = $skuRes->fetch_assoc()) {
  $sku_all[] = [
    "id" => (int) $r["id"],
    "sku_num" => (string) $r["sku_num"],
    "desc" => (string) $r["desc"],
    "lot_required" => (bool) ($r["lot_required"] ?? false)
  ];
}

/* ---------- Read selection from GET ---------- */
$row_code = trim($_GET["row_code"] ?? "");
$bay_num = trim($_GET["bay_num"] ?? "");
$level = trim($_GET["level_code"] ?? "");
$side = trim($_GET["side"] ?? "");

$sel = [
  "row_code" => $row_code,
  "bay_num" => $bay_num,
  "level_code" => $level,
  "side" => $side,
];

/* ---------- Resolve loc_id (tolerant) ---------- */

$loc_id = null;
$loc_label = "";

$norm_row = $row_code === "" ? "" : strtoupper($row_code);

// === START: Conditional Defaulting Logic for Unstructured Rows ===

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

// === END: Conditional Defaulting Logic ===
if ($norm_row && $norm_bay !== "" && $norm_level && $norm_side) {
  $stmt = $conn->prepare(
    "SELECT id, row_code, bay_num, level_code, side
 FROM location
 WHERE UPPER(row_code) = ?
 AND CAST(bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
 AND UPPER(level_code) = ?
 AND LEFT(UPPER(side), 1) = LEFT(?, 1)
 AND is_disabled = 0
 LIMIT 1",
  );

  $stmt->bind_param("ssss", $norm_row, $norm_bay, $norm_level, $norm_side);
  $stmt->execute();
  $rs = $stmt->get_result();

  if ($rec = $rs->fetch_assoc()) {
    $loc_id = (int) $rec["id"];
    $loc_label = getLocationDisplayLabel($rec);
  }

  $stmt->close();
}

/* ---------- Load current inventory at location (qty > 0) - LOGIC FROM invLoc.php ---------- */

$rows_out = [];
$error_msg = "";
$success_msg = "";
$toast_msg = "";
$toast_type = "";
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

    // loc_id is passed to filter p.loc_id
    $stmt->bind_param("i", $loc_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {

      $rows_out[] = [
        'sku_id' => (int) ($r['sku_id'] ?? 0),
        'sku' => (string) ($r['sku'] ?? ''),
        'desc' => (string) ($r['sku_description'] ?? ''),
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
    $error_msg =
      "Query failed. Please try again. Error: " . h($e->getMessage());
  }
}

/* * ---------- POST: add_sku (NEW LOGIC TO HANDLE PALLETS) ----------
 * Replaces the call to safe_inventory_change with inline transactional logic.
 */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "add_sku") {

  // INPUT RETRIEVAL AND VALIDATION
  $r_loc_id = (int) ($_POST["loc_id"] ?? 0);
  $r_sku_num = trim($_POST["sku_num"] ?? "");
  $r_quantity = (int) ($_POST["quantity"] ?? 0);

  // Initial Validation
  if ($r_loc_id !== $loc_id || $r_loc_id <= 0 || $r_sku_num === "" || $r_quantity <= 0) {
    $error_msg = "Invalid or missing data (Location, SKU, or Quantity).";
    goto skip_post;
  }

  // Lookup SKU id
  $r_sku_id = 0;
  $r_sku_desc = "";
  $r_sku_lot_required = false;

  $stmt_sku = $conn->prepare(
    // "SELECT id, `desc` FROM sku WHERE sku_num = ?",
    "SELECT id, `desc`, lot_required FROM sku WHERE sku_num = ?",
  );

  $stmt_sku->bind_param("s", $r_sku_num);
  $stmt_sku->execute();
  $res_sku = $stmt_sku->get_result();

  if ($sku_record = $res_sku->fetch_assoc()) {
    $r_sku_id = (int) $sku_record["id"];
    $r_sku_desc = (string) ($sku_record["desc"] ?? "");
    $r_sku_lot_required = (bool) ($sku_record["lot_required"] ?? false);
  }

  $stmt_sku->close();

  if ($r_sku_id === 0) {
    $error_msg = "SKU Number is not valid.";
    goto skip_post;
  }

  // PALLET RESOLUTION 
  $conn->begin_transaction();
  $ok = false;

  try {
    $pallet = ensure_location_pallet($conn, $r_loc_id);
    $r_pallet_id = $pallet["id"];

    // INVENTORY UPDATE/INSERT
    $stmt_upsert_inv = $conn->prepare(
      "INSERT INTO inventory (sku_id, pallet_id, quantity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)",
    );

    $stmt_upsert_inv->bind_param("iii", $r_sku_id, $r_pallet_id, $r_quantity);
    $stmt_upsert_inv->execute();
    $stmt_upsert_inv->close();

    // INVENTORY MOVEMENT LOG
    $movement_type = "IN";
    $reference = sprintf(
      "Inbound addition of %d units of %s to %s",
      $r_quantity,
      $r_sku_num,
      $loc_label !== "" ? $loc_label : "location " . $r_loc_id,
    );

    log_inventory_movement(
      $conn,
      $r_sku_id,
      $r_loc_id,
      $r_quantity,
      $movement_type,
      $reference,
      $user_id,
    );

    // COMMIT TRANSACTION
    $conn->commit();
    $ok = true; // Success flag
  } catch (\Throwable $e) {
    $conn->rollback();
    $error_msg =
      "Addition failed due to a database transaction error. Error: " .
      h($e->getMessage());
    $ok = false;
  }

  skip_post: // Label for the goto statement

  if (isset($ok) && $ok) {
    // Redirect to clear POST and show success
    $_SESSION["toast"] = [
      "msg" => "Inventory successfully added.",
      "type" => "success",
      "details" => [
        [
          "sku" => $r_sku_num,
          "desc" => $r_sku_desc,
          "qty" => $r_quantity,
        ],
      ],
    ];

    $qs = http_build_query($sel);

    header("Location: place.php?{$qs}");
    exit();
  }
}

// Read any toast set during redirect
if (isset($_SESSION["toast"])) {
  $toast_msg = $_SESSION["toast"]["msg"] ?? "";
  $toast_type = $_SESSION["toast"]["type"] ?? "";
  $toast_details = $_SESSION["toast"]["details"] ?? [];
  unset($_SESSION["toast"]);
} elseif ($success_msg) {
  $toast_msg = $success_msg;
  $toast_type = "success";
} elseif ($error_msg) {
  $toast_msg = $error_msg;
  $toast_type = "error";
}

/* ---------- Page Output (Add UI) ---------- */

$title = "Add Stock (Put Away)";
$page_class = "page-loc-details";
$extra_css = '<link rel="stylesheet" href="/refactor_css/suggest.css">';
$js[] = '/js/lot_management.js';

ob_start();

?>

<h2 class="title">Add Stock to Location</h2>

<?php if ($toast_msg): ?>
  <div class="toast toast--banner <?= h($toast_type) ?>" <?= $toast_type ===
      "success" && !empty($toast_details)
      ? ' data-toast-details="' . h(json_encode($toast_details)) . '"'
      : "" ?>>
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

  <input type="hidden" id="row_code_input" name="row_code" value="<?= h(
    $sel["row_code"],
  ) ?>">

  <input type="hidden" id="bay_num_input" name="bay_num" value="<?= h(
    $sel["bay_num"],
  ) ?>">

  <input type="hidden" id="level_code_input" name="level_code" value="<?= h(
    $sel["level_code"],
  ) ?>">

  <input type="hidden" id="side_input" name="side" value="<?= h(
    $sel["side"],
  ) ?>">
</form>

<?php if ($loc_id !== null): ?>
  <div class="mb-4">
    <span class="badge bg-info text-dark">Selected Location: <?= h(
      $loc_label,
    ) ?></span>
  </div>

  <div class="card p-3 mb-4">
    <h4>Add Inventory</h4>
    <form method="POST" action="place.php?<?= h(http_build_query($sel)) ?>">
      <div class="row g-3" style="margin-right: 12px; align-items: flex-end;">
        <div class="col-lg-6 col-md-6">
          <label for="sku_num" class="form-label mb-1">SKU or Description</label>
          <div class="input-group flex">
            <input type="text" class="form-control" id="sku_num" name="sku_num" required
              placeholder="Enter SKU or description" autocomplete="off" data-suggest="inventory"
              data-suggest-box="placeSuggest" data-suggest-submit="false">
            <button class="btn btn-outline-secondary" type="button" id="scan-button">Search</button>
          </div>
          <div id="placeSuggest" class="inv-suggest"></div>
        </div>

        <div class="col-lg-4 col-md-4">
          <label for="rmQuantity" class="form-label mb-1">Quantity to Add</label>
          <div class="input-group flex-nowrap">
            <input type="number" class="form-control" id="rmQuantity" name="quantity" min="1" value="1" required>
          </div>
        </div>

        <div class="col-lg-2 col-md-2">
          <button type="submit" class="btn btn-success w-100" id="mainAddBtn" data-sku-id="" data-sku-num=""
            data-lot-required="0" data-loc-id="<?= h((string) $loc_id) ?>">
            Add Inventory
          </button>
        </div>
      </div>

      <input type="hidden" name="action" value="add_sku">
      <input type="hidden" name="loc_id" value="<?= h(
        (string) $loc_id,
      ) ?>">
    </form>
  </div>

  <div id="scan-results" class="mb-4"></div>

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
            <td colspan="4" class="text-muted">No inventory with quantity &gt; 0 at this location.</td>
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
                  <span title="Lot required" class="badge ms-1 lot-exp-badge">Lot</span>
                <?php endif; ?>
                <?= getRequestBadge((int) ($r['open_req_qty'] ?? 0), (int) ($r['has_priority'] ?? 0)) ?>
              </td>
              <td>
                <?= h($r["desc"]) ?>
                <?php if (!empty($r["lot_expiry"])): ?>
                  <?php
                  $exp_date = (string) $r["lot_expiry"];
                  $today_dt = new DateTime('today');
                  $exp_dt = new DateTime($exp_date);
                  $days_until = (int) $today_dt->diff($exp_dt)->format('%r%a');
                  if ($days_until <= 90) {
                    $badge_class = "badge lot-exp-badge--danger";
                  } elseif ($days_until <= 180) {
                    $badge_class = "badge lot-exp-badge--warn";
                  } else {
                    $badge_class = "badge lot-exp-badge--ok";
                  }
                  ?>
                  <span class="<?= h($badge_class) ?> ms-2 lot-exp-badge" title="Lot Expired: <?= h($exp_date, ) ?>">Lot exp: <?= h(
                         $exp_date,
                       ) ?></span>
                <?php endif; ?>
              </td>
              <td><?= h($r["sku_status"] ?? $r["status"] ?? 'ACTIVE') ?></td>
              <td class="text-end"><?= h((string) $r["on_hand"]) ?></td>
              <td>
                <?= $r["last_moved_at"] ? h($r["last_moved_at"]) : '<span class="text-muted">—</span>' ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>


<?php elseif ($_GET): ?>
  <div class="alert alert-warning">No matching location found for selection: **<?= h(
    $row_code,
  ) ?>**.</div>
<?php else: ?>
  <div class="alert alert-info">Pick a location above to begin adding stock.</div>
<?php endif; ?>
<?php include __DIR__ . "/utils/lot_management.php"; ?>
<?php

$content = ob_get_clean();

/* ---------- Footer JS ---------- */
$BASE_URL = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");
$boot_data = [
  "loc_list" => $loc_list,
  "sku_all" => $sku_all,
  "specialRows" => $current_special_rows,
];

$loc_json = json_encode(
  $boot_data,
  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
);


$js_fs = __DIR__ . "/js/location-buttons.js";
$ver = is_file($js_fs) ? fileMtime($js_fs) : time();
$script_src = $BASE_URL . "/js/location-buttons.js?v=" . $ver;

// Part 1: interpolate PHP variables safely
$footer_js = <<<HTML
<script id="loc-data" type="application/json">{$loc_json}</script>
<script id="sku-data" type="application/json">{$loc_json}</script>
<!-- <script src="js/lot_management.js"></script> -->

<style>
/* Modal fallback (matches transfer/take styling) */
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

HTML;

// JS that contains \${...} must be in a NOWDOC (no interpolation)
$footer_js .= <<<'HTML'
<script>
// Load location and SKU data from PHP-generated JSON
(function(){
var el = document.getElementById('loc-data');
var data = el ? JSON.parse(el.textContent) : {};

window.PLACE_LOCATIONS = data.loc_list || [];

// Store special rows globally for location-buttons.js logic
window.SPECIAL_ROWS = data.specialRows || [];

// Expose all active SKUs for lookups outside the typeahead
window.PLACE_SKUS = data.sku_all || [];

// Rule: R11 has only Front (same rule you had)
window.filterSides = function(ctx){
if (!ctx || !ctx.row || !ctx.sides) return (ctx && ctx.sides) ? ctx.sides : [];
return (ctx.row === 'R11') ? ctx.sides.filter(function(s){ return s !== 'Back'; }) : ctx.sides;
};
})();
</script>

<script>
(function(){
 var dataEl = document.getElementById('sku-data');
 var data = dataEl ? JSON.parse(dataEl.textContent) : {};
 var ALL_SKUS = Array.isArray(data.sku_all) ? data.sku_all : [];

 var input = document.getElementById('sku_num');
 var addBtn = document.getElementById('mainAddBtn');
 var lotIdInput = document.getElementById('take_lot_id'); // not present, kept for parity

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

 function syncAddButtonFromItem(item){
  if (!addBtn) return;
  if (!item) {
    addBtn.setAttribute('data-sku-id', '');
    addBtn.setAttribute('data-sku-num', '');
    addBtn.setAttribute('data-lot-required', '0');
    addBtn.classList.remove('btn-lot');
    addBtn.type = 'submit';
    if (lotIdInput) lotIdInput.value = '';
    return;
  }
  addBtn.setAttribute('data-sku-id', item.id != null ? String(item.id) : '');
  addBtn.setAttribute('data-sku-num', item.sku_num || '');
  var lotRequired = isLotRequired(item);
  addBtn.setAttribute('data-lot-required', lotRequired ? '1' : '0');
  if (lotRequired) {
    addBtn.classList.add('btn-lot');
    addBtn.type = 'button';
  } else {
    addBtn.classList.remove('btn-lot');
    addBtn.type = 'submit';
  }
  if (lotIdInput) lotIdInput.value = '';
 }

 function syncAddButtonForSku(skuValue){
  var item = findSkuItem(skuValue);
  syncAddButtonFromItem(item);
  return item;
 }

 window.syncAddButtonForSku = syncAddButtonForSku;

 if (input) {
  input.addEventListener('change', function(){ syncAddButtonForSku(input.value); });
  input.addEventListener('blur', function(){ syncAddButtonForSku(input.value); });
 }

 if (addBtn) {
  addBtn.addEventListener('click', function(e){
    syncAddButtonForSku(input ? input.value : '');
    if (addBtn.classList.contains('btn-lot')) {
      e.preventDefault();
    }
  });
 }
})();
</script>

<script>

// Basic HTML escaper for inline text

function escapeHtml(str) {

 var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

 return String(str || '').replace(/[&<>"']/g, function(c){ return map[c] || c; });

}



function renderDescMatchesSection(matches, headingText) {
 if (!Array.isArray(matches) || matches.length === 0) return '';
 var rowsHtml = matches.map(function(m){
  var badge = (m.status || '').toUpperCase() === 'ACTIVE' ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
  return `
   <tr>
    <td><button type="button" class="btn btn-link p-0" data-sku-fill="${escapeHtml(m.sku_num || '')}">${escapeHtml(m.sku_num || '')}</button></td>
    <td>${escapeHtml(m.desc || '')}</td>
    <td>${badge}</td>
   </tr>
  `;
 }).join('');
 var title = headingText ? escapeHtml(headingText) : 'Matching SKUs by description';
 return `
  <div class="card mt-3">
   <div class="card-header">${title}</div>
   <div class="table-responsive">
    <table class="table table-sm mb-0">
     <thead class="table-light">
      <tr>
       <th>SKU</th>
       <th>Description</th>
       <th>Status</th>
      </tr>
     </thead>
     <tbody>${rowsHtml}</tbody>
    </table>
   </div>
  </div>
 `;
}



function bindSkuFillButtons(host) {
 var skuInput = document.getElementById('sku_num');

 if (!host || !skuInput) return;

 host.querySelectorAll('[data-sku-fill]').forEach(function(btn){
  btn.addEventListener('click', function(){
   var val = btn.getAttribute('data-sku-fill') || '';
   skuInput.value = val;
   skuInput.focus();
   if (window.syncAddButtonForSku) {
    window.syncAddButtonForSku(val);
   }
  });

 });

}

// Simple renderer for scan results (Existing function, kept)

function renderScanResults(container, data, selectedLocId) {
 var querySku = (data && data.query ? String(data.query) : '').trim();
 var skuList = window.PLACE_SKUS || [];
 var skuExistsClient = querySku && skuList.some(function(s){
  var skuVal = (s && typeof s === 'object') ? s.sku_num : s;
  return String(skuVal || '').toLowerCase() === querySku.toLowerCase();
 });

 var skuExistsServer = !!(data && data.sku_exists);

 var skuStatus = (data && data.sku_status ? String(data.sku_status) : '').toUpperCase();

 var skuExists = skuExistsClient || skuExistsServer;
 var skuIsActive = skuStatus === 'ACTIVE' || skuStatus === '';
 var descMatches = Array.isArray(data && data.desc_matches) ? data.desc_matches : [];
 var heading = '';
 if (descMatches.length) {
  var firstMatch = descMatches[0] || {};
  var parts = [];
  if (firstMatch.sku_num) parts.push(firstMatch.sku_num);
  if (firstMatch.desc) parts.push(firstMatch.desc);
  if (firstMatch.status) parts.push(String(firstMatch.status).toUpperCase());
  heading = parts.length ? 'Results: ' + parts.join(' - ') : 'Matching SKUs';
 }
 var descMatchesHtml = renderDescMatchesSection(descMatches, heading);

 var summarySku = (data && data.rows && data.rows[0] && data.rows[0].sku_num) ? data.rows[0].sku_num : (descMatches[0] && descMatches[0].sku_num) ? descMatches[0].sku_num : querySku;
 var summaryDesc = (data && data.rows && data.rows[0] && data.rows[0].sku_desc) ? data.rows[0].sku_desc : (descMatches[0] && descMatches[0].desc) ? descMatches[0].desc : '';
 var resolvedStatus = (data && data.sku_status) || (descMatches[0] && descMatches[0].status) || '';
 var summaryStatus = resolvedStatus || (skuExists ? 'ACTIVE' : '');
 var summaryParts = [];
 if (summarySku) summaryParts.push(summarySku);
 if (summaryDesc) summaryParts.push(summaryDesc);
 if (summaryStatus) summaryParts.push(String(summaryStatus).toUpperCase());
 var summaryLine = summaryParts.join(' - ');
 var summaryHtml = summaryLine ? `<div class="alert alert-info py-2 px-3 mb-2">${escapeHtml(summaryLine)}</div>` : '';

 if (!data || !Array.isArray(data.rows) || data.rows.length === 0) {
 if (descMatches.length > 0) {
  container.innerHTML = summaryHtml + descMatchesHtml;
 } else if (skuExists) {
  var newMsg = '';
  var msgSku = summarySku || querySku;
  var clientSkuData = (window.PLACE_SKUS || []).find(function(s){
   return String(s && s.sku_num ? s.sku_num : '').toLowerCase() === String(msgSku || '').toLowerCase();
  });
   var msgDesc = summaryDesc || (clientSkuData ? clientSkuData.desc : 'N/A');
   var msgStatus = summaryStatus || (clientSkuData ? clientSkuData.status : 'ACTIVE');
   newMsg = `${escapeHtml(msgSku)} - ${escapeHtml(msgDesc)} - ${escapeHtml(msgStatus)}`;
   var alertClass = 'alert-info';
   // If status is not active, display a warning but still allow adding stock.
   if (String(msgStatus).toUpperCase() !== 'ACTIVE') {
    alertClass = 'alert-warning';
    newMsg = `⚠️ ${newMsg} (Status is ${escapeHtml(String(msgStatus).toUpperCase())}).`;
   }
   container.innerHTML = `<div class="${alertClass} mb-0">${newMsg}</div>` + descMatchesHtml;
  } else {
   var warn = '<div class="alert alert-warning mb-0">No matching inventory found for this SKU.</div>';
   container.innerHTML = summaryHtml + warn + descMatchesHtml;
  }
  bindSkuFillButtons(container);
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
 var moved = r.last_moved_at ? r.last_moved_at : '-';
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
${summaryHtml}

<div class="card">
 <div class="card-header d-flex justify-content-between align-items-center">
 <span>Scan Results: Locations where SKU is found</span>
 <small class="text-muted">Path: ${data.path}${total ? ` - Total on hand: ${total}` : ''}</small>
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

${descMatchesHtml}

`;

// In 'place.php', we don't set a max on quantity, but we still focus the input.

 var qty = document.getElementById('rmQuantity');

 if (qty) {
    qty.removeAttribute('max');
    qty.focus();
 }
 bindSkuFillButtons(container);
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
 resultsEl.innerHTML = '<div class="alert alert-info mb-0">Enter/scan a SKU or description first.</div>';
 if (skuInput) skuInput.focus();
 return;
}

 resultsEl.innerHTML = '<div class="text-muted">Searching...</div>';

 try {
 const res = await fetch('invSku.php?ajax=1&include_desc=1&q=' + encodeURIComponent(sku), {
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

// Auto-submit after row/side selection (same pattern)

(function(){
  var rowWrap = document.getElementById('rowButtons');
  var sideWrap = document.getElementById('sideButtons');

// Check if a row is a special row (using the new global array from PHP)

  function isSpecialRow(rowCode) {
 return window.SPECIAL_ROWS.includes(rowCode.toUpperCase());
}

 // Auto-submit on special row click

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
 
 // Auto-submit on side click for standard rows

  if (!sideWrap) return;

  sideWrap.addEventListener('click', function(e){

  if (e.target && e.target.tagName === 'BUTTON') {

 setTimeout(function(){

 var r = document.getElementById('row_code_input')?.value;

 var b = document.getElementById('bay_num_input')?.value;

 var l = document.getElementById('level_code_input')?.value;

 var s = document.getElementById('side_input')?.value;



 // If it's a standard row AND complete, submit

 if (!isSpecialRow(r) && r && b && l && s) {

   var form = document.querySelector('form[method="get"]');

   if (form) form.submit();

 }

 }, 0);

}

});

})();

</script>



<script>

// Click a SKU in the table to populate the ADD input & focus qty

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

   }

   if (window.syncAddButtonForSku) {
    window.syncAddButtonForSku(selectedSku);
   }

   quantityInput.value = 1;

   quantityInput.focus();

 });

 }

})();

</script>



<!-- Confirmation modal (mirrors transfer flow) -->

<div id="transferConfirmModal" class="modal" hidden aria-hidden="true">

  <div class="modal__backdrop"></div>

  <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="transferConfirmTitle">

    <h3 id="transferConfirmTitle">Confirm Placement</h3>

    <p id="transferConfirmMessage" class="modal__body">

      Are you sure you want to add this inventory to the selected location?

    </p>

    <div class="modal__actions">

      <button type="button" class="btn" id="transferConfirmYes">Yes, place inventory</button>

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
    const title = document.createElement('div');

    toastEl.className = 'toast toast--banner success';
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
        console.warn('[place] failed to parse toast details', err);
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

  const postForm = document.querySelector('form[method=\"POST\"][action^=\"place.php\"]');

  let allowSubmit = false;

  if (postForm) {
    postForm.addEventListener('submit', evt => {
      if (allowSubmit) return;
      evt.preventDefault();
      openModal('Confirm adding inventory to this location?', () => {
        allowSubmit = true;
        postForm.submit();
      });
    });
  }
  window.placeConfirm = { open: openModal, close: closeModal };
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
require __DIR__ . "/templates/layout.php";
