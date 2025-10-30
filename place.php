<?php
// take.php — Add Stock (Add/Take/Receive)
// Select Row/Bay/Level/Side via buttons → list SKUs to add (e.g., via scan)
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';
// IMPORTANT: inventory_ops.php must contain safe_inventory_change() and addInventory()
require_once __DIR__ . '/utils/inventory_ops.php';
require_once __DIR__ . '/utils/helpers.php';

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn->set_charset('utf8mb4');
} catch (\Throwable $_) {
}
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// ---------- Pull all locations (for buttons) ----------
$loc_list = [];
$res = $conn->query("SELECT id, row_code, bay_num, level_code, side FROM location ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side");
while ($r = $res->fetch_assoc()) {
    $loc_list[] = [
        'id' => (int) $r['id'],
        'row_code' => (string) $r['row_code'],
        'bay_num' => (string) $r['bay_num'],
        'level_code' => (string) $r['level_code'],
        'side' => (string) $r['side'],
    ];
}

// ---------- Read selection from GET (from hidden inputs) ----------
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

// ---------- Resolve loc_id (tolerant: 01==1, F/B == Front/Back) ----------
$loc_id = null;
$loc_label = '';

$norm_row = $row_code === '' ? '' : strtoupper($row_code);
$norm_bay = $bay_num === '' ? '' : ltrim($bay_num, '0');
if ($norm_bay === '' && $bay_num !== '')
    $norm_bay = '0';
$norm_level = $level === '' ? '' : strtoupper($level);
$norm_side = $side === '' ? '' : strtoupper($side);

if ($norm_row && $norm_bay !== '' && $norm_level && $norm_side) {
    $stmt = $conn->prepare(
        "SELECT id, row_code, bay_num, level_code, side
         FROM location
         WHERE UPPER(row_code) = ?
          AND CAST(bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
          AND UPPER(level_code) = ?
          AND LEFT(UPPER(side), 1) = LEFT(?, 1)
         LIMIT 1"
    );
    $stmt->bind_param('ssss', $norm_row, $norm_bay, $norm_level, $norm_side);
    $stmt->execute();
    $rs = $stmt->get_result();
    if ($rec = $rs->fetch_assoc()) {
        $loc_id = (int) $rec['id'];
        $loc_label = "{$rec['row_code']}-{$rec['bay_num']}-{$rec['level_code']}-{$rec['side']}";
    }
    $stmt->close();
}


// ---------- Query inventory (qty > 0) [Keep for display, but main action is POST] ----------
$rows_out = [];
$error_msg = '';
$success_msg = '';


// take.php (Inside the POST block for 'add_sku')

// --- Handle Add (Place) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_sku') {
    // 1) POST data
    $r_loc_id   = (int) ($_POST['loc_id']   ?? 0);
    $r_sku_num  = trim($_POST['sku_num']    ?? '');
    $r_quantity = (int) ($_POST['quantity'] ?? 0);

    // 1a) LOOKUP sku_id
    $r_sku_id = 0;
    if (!empty($r_sku_num)) {
        $stmt_sku = $conn->prepare("SELECT id FROM sku WHERE sku_num = ? AND status = 'ACTIVE'");
        $stmt_sku->bind_param('s', $r_sku_num);
        $stmt_sku->execute();
        $res_sku = $stmt_sku->get_result();
        if ($sku_record = $res_sku->fetch_assoc()) {
            $r_sku_id = (int) $sku_record['id'];
        }
        $stmt_sku->close();
    }

    // 2) Sanity check
    if ($r_loc_id === $loc_id && $r_loc_id > 0 && $r_sku_id > 0 && $r_quantity > 0) {
        // 3) Prepare details
        $quantity_to_add = $r_quantity;
        $movement_type   = 'IN';
        $note = "Inbound receipt of {$r_quantity} units of {$r_sku_num} by user {$username} ({$user_id})";

        // 4) Perform
        if (addInventory(
                $conn,
                $r_sku_id,
                $r_loc_id,
                $quantity_to_add,
                $user_id,
                $note,
                $movement_type
            )) {
            // Redirect back to *place.php* with success flag
            $qs = http_build_query(array_merge($sel, ['success' => 'added']));
            header("Location: place.php?{$qs}");
            exit;
        } else {
            $error_msg = 'Addition failed due to a database error. Check logs.';
        }
    } else {
        $error_msg = 'Invalid data provided for addition. Check Location, Quantity, or if the SKU Number is valid/active.';
    }
} // <-- this closes the main POST handler


// Check for success flag from redirect
if (isset($_GET['success']) && $_GET['success'] === 'added') {
    $success_msg = 'Inventory successfully added.';
}

if ($loc_id !== null) {
    // The query can stay the same, as it's useful to see what's already there
    $sql = "
      SELECT
        s.id       AS sku_id,
        s.sku_num  AS sku,
        s.`desc`   AS `desc`,
        i.quantity AS on_hand,
        (
          SELECT MAX(m.created_at)
            FROM inventory_movements m
           WHERE m.sku_id = i.sku_id
             AND m.loc_id = i.loc_id
        ) AS last_moved_at
      FROM inventory i
      JOIN sku s ON s.id = i.sku_id
     WHERE i.loc_id = ?
       AND i.quantity > 0
     ORDER BY s.sku_num ASC
    ";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $loc_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows_out[] = [
                'sku_id' => (int) $r['sku_id'],
                'sku' => (string) $r['sku'],
                'desc' => (string) $r['desc'],
                'on_hand' => (int) $r['on_hand'],
                'last_moved_at' => $r['last_moved_at'] ?? null,
            ];
        }
        $stmt->close();
    } catch (\Throwable $e) {
        $error_msg = 'Query failed. Please try again.';
    }
}

// ---------- Page Output (Adjusted for "Add" UI) ----------
$title = 'Add Stock (Take In)';
$page_class = 'page-loc-details'; // Reuse existing class structure
ob_start();
?>

<h2 class="title">Add Stock to Location</h2>

<?php if ($success_msg): ?>
    <div class="alert alert-success"><?= h($success_msg) ?></div>
<?php endif; ?>

<?php if ($error_msg): ?>
    <div class="alert alert-danger"><?= h($error_msg) ?></div>
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
        <h4>Receive Stock</h4>
        <form method="POST" action="place.php?<?= h(http_build_query($sel)) ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="sku_num" class="form-label">SKU Number</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="sku_num" name="sku_num" required
                            placeholder="Scan or Enter SKU" autocomplete="off">

                        <button type="button" class="btn btn-primary" id="scan-button">
                            <i class="fas fa-barcode"></i> Scan
                        </button>
                    </div>
                    <div class="form-text">Type or scan the SKU.</div>
                </div>
                
                <div class="col-md-4">
                    <label for="addQuantity" class="form-label">Quantity to Add</label>
                    <input type="number" class="form-control" id="addQuantity" name="quantity" min="1" value="1" required>
                    <div class="form-text">Enter the quantity received.</div>
                </div>
                
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">Add Inventory</button>
                </div>
                
                <input type="hidden" name="action" value="add_sku">
                <input type="hidden" name="loc_id" value="<?= h((string)$loc_id) ?>">
        </form>
    </div>

    <div class="card" style="overflow:auto;">
        <h5 class="card-header">Current Inventory at <?= h($loc_label) ?></h5>
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>SKU</th>
                    <th>Description</th>
                    <th class="text-end">On-Hand</th>
                    <th>Last Movement At</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($loc_id !== null && empty($rows_out)): ?>
                    <tr>
                        <td colspan="4" class="text-muted">No inventory with quantity &gt; 0 at this location.</td>
                    </tr>
                <?php elseif ($loc_id !== null): ?>
                    <?php foreach ($rows_out as $r): ?>
                        <tr>
                            <td><?= h($r['sku']) ?></td>
                            <td><?= h($r['desc']) ?></td>
                            <td class="text-end"><?= h((string) $r['on_hand']) ?></td>
                            <td><?= $r['last_moved_at'] ? h($r['last_moved_at']) : '<span class="text-muted">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($_GET): ?>
    <div class="alert alert-warning">No matching location found. Please check your selection.</div>
<?php else: ?>
    <div class="alert alert-info">Pick a location above to begin receiving stock.</div>
<?php endif; ?>


<?php
$content = ob_get_clean();

// ---------- Footer JS: publish data, include locator script, auto-submit on side pick ----------
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$loc_json = json_encode($loc_list, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$js_fs = __DIR__ . '/js/location-buttons.js';
$ver = is_file($js_fs) ? filemtime($js_fs) : time();
$script_src = $BASE_URL . '/js/location-buttons.js?v=' . $ver;

// NOTE: You will need to add JavaScript here (or in a separate file) to 
// look up the SKU_ID based on the SKU_NUM entered by the user 
// and populate the hidden input #addSkuId before submission.

$footer_js = <<<HTML
<script id="loc-data" type="application/json">{$loc_json}</script>
<script>
(function(){
  try {
    var el = document.getElementById('loc-data');
    window.PLACE_LOCATIONS = el ? JSON.parse(el.textContent) : [];
  } catch (e) {
    console.error('Failed to parse PLACE_LOCATIONS:', e);
    window.PLACE_LOCATIONS = [];
  }

  // Rule: R11 has only Front (Keep same filtering rule)
  window.filterSides = function(ctx){
    if (!ctx || !ctx.row || !ctx.sides) return ctx?.sides || [];
    return (ctx.row === 'R11') ? ctx.sides.filter(function(s){ return s !== 'Back'; }) : ctx.sides;
  };
})();
</script>
<script src="{$script_src}"></script>
<script>
// Functionality for the Scan Button
(function() {
    var scanButton = document.getElementById('scan-button');
    var skuInput = document.getElementById('sku_num');

    if (scanButton && skuInput) {
        scanButton.addEventListener('click', function(e) {
            e.preventDefault(); // Stop the button from submitting the form if it was not type="button"
            skuInput.focus();
            skuInput.select(); // Select the text for quick overwrite
        });
    }
})();
</script>
<script>
(function(){
  var sideWrap = document.getElementById('sideButtons');
  sideWrap?.addEventListener('click', function(e){
    if (e.target && e.target.tagName === 'BUTTON') {
      setTimeout(function(){
        var r = document.getElementById('row_code_input')?.value;
        var b = document.getElementById('bay_num_input')?.value;
        var l = document.getElementById('level_code_input')?.value;
        var s = document.getElementById('side_input')?.value;
        if (r && b && l && s) {
          document.querySelector('form[method="get"]')?.submit();
        }
      }, 0);
    }
  });
})();
</script>

HTML;

require __DIR__ . '/templates/layout.php';