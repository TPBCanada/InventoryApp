<?php
// take.php — Remove Stock (Issue/Withdraw)
// Select Row/Bay/Level/Side via buttons → enter/scan SKU to remove from location
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/inventory_ops.php'; // must have safe_inventory_change()
require_once __DIR__ . '/utils/helpers.php';

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
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

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ---------- Pull all locations (for buttons) ---------- */
$loc_list = [];
$res = $conn->query("SELECT id, row_code, bay_num, level_code, side
                       FROM location
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

/* ---------- Load current inventory at location (qty > 0) ---------- */
$rows_out = [];
$error_msg = '';
$success_msg = '';

if ($loc_id !== null) {
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
    if ($r_sku_num !== '') {
        $stmt_sku = $conn->prepare("SELECT id FROM sku WHERE sku_num = ? AND status = 'ACTIVE'");
        $stmt_sku->bind_param('s', $r_sku_num);
        $stmt_sku->execute();
        $res_sku = $stmt_sku->get_result();
        if ($sku_record = $res_sku->fetch_assoc()) {
            $r_sku_id = (int) $sku_record['id'];
        }
        $stmt_sku->close();
    }

    if ($r_loc_id === $loc_id && $r_loc_id > 0 && $r_sku_id > 0 && $r_quantity > 0) {
        // Check current on_hand at this location for the SKU
        $on_hand = 0;
        $stmt_q = $conn->prepare("SELECT quantity FROM inventory WHERE loc_id = ? AND sku_id = ? LIMIT 1");
        $stmt_q->bind_param('ii', $r_loc_id, $r_sku_id);
        $stmt_q->execute();
        $res_q = $stmt_q->get_result();
        if ($qrow = $res_q->fetch_assoc()) {
            $on_hand = (int) $qrow['quantity'];
        }
        $stmt_q->close();

        if ($on_hand <= 0) {
            $error_msg = 'Nothing on hand for this SKU at the selected location.';
        } elseif ($r_quantity > $on_hand) {
            $error_msg = "Cannot remove {$r_quantity}. Only {$on_hand} on hand.";
        } else {
            // Perform OUT movement using safe_inventory_change (negative quantity)
            $movement_type = 'OUT';
            $note = "Outbound removal of {$r_quantity} units of {$r_sku_num} by user {$username} ({$user_id})";

            $ok = safe_inventory_change(
                $conn,
                $r_sku_id,
                $r_loc_id,
                -$r_quantity,
                $movement_type,
                $user_id,
                $note
            );


            if ($ok) {
                // Redirect to clear POST and show success
                $qs = http_build_query(array_merge($sel, ['success' => 'removed']));
                header("Location: take.php?{$qs}");
                exit;
            } else {
                $error_msg = 'Removal failed due to a database error. Check logs.';
            }
        }
    } else {
        $error_msg = 'Invalid data for removal. Check Location, Quantity, or if the SKU Number is valid/active.';
    }
}

/* ---------- Success flag ---------- */
if (isset($_GET['success']) && $_GET['success'] === 'removed') {
    $success_msg = 'Inventory successfully removed.';
}

/* ---------- Page Output (Remove UI) ---------- */
$title = 'Remove Stock (Take Out)';
$page_class = 'page-loc-details';
ob_start();
?>

<h2 class="title">Remove Stock from Location</h2>

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
    <h4>Issue / Remove Stock</h4>
    <form method="POST" action="take.php?<?= h(http_build_query($sel)) ?>">
        <div class="row g-3">
            <div class="col-lg-6 col-md-7">
  <label for="sku_num" class="form-label mb-1">SKU Number</label>
  <div class="input-group flex-nowrap">
    <input
      type="text"
      class="form-control"
      id="sku_num"
      name="sku_num"
      required
      placeholder="Scan or Enter SKU"
      autocomplete="off"
    >
    <button type="button" class="btn btn-primary" id="scan-button">
      <i class="fas fa-barcode"></i> Scan
    </button>
  </div>
  <!-- suggestions live here -->
  <div id="sku-suggest" class="mt-2"></div>
</div>


            <div class="col-lg-6 col-md-5">
  <label for="rmQuantity" class="form-label mb-1">Quantity</label>
  <div class="input-group flex-nowrap">
    <input
      type="number"
      class="form-control"
      id="rmQuantity"
      name="quantity"
      min="1"
      value="1"
      required
    >
    <button type="submit" class="btn btn-danger">
      Remove inventory
    </button>
  </div>
</div>


            <input type="hidden" name="action" value="remove_sku">
            <input type="hidden" name="loc_id" value="<?= h((string)$loc_id) ?>">
        </div>
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
                <?php else: ?>
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
    <div class="alert alert-info">Pick a location above to begin removing stock.</div>
<?php endif; ?>

<?php
$content = ob_get_clean();

/* ---------- Footer JS ---------- */
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$loc_json = json_encode($loc_list, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$js_fs = __DIR__ . '/js/location-buttons.js';
$ver = is_file($js_fs) ? filemtime($js_fs) : time();
$script_src = $BASE_URL . '/js/location-buttons.js?v=' . $ver;

// Part 1: interpolate PHP variables safely
$footer_js = <<<HTML
<script id="loc-data" type="application/json">{$loc_json}</script>
<script src="{$script_src}"></script>
HTML;

// Part 2: JS that contains `${...}` must be in a NOWDOC (no interpolation)
$footer_js .= <<<'HTML'
<script>
(function(){
  try {
    var el = document.getElementById('loc-data');
    window.PLACE_LOCATIONS = el ? JSON.parse(el.textContent) : [];
  } catch (e) {
    console.error('Failed to parse PLACE_LOCATIONS:', e);
    window.PLACE_LOCATIONS = [];
  }
  // Rule: R11 has only Front (same rule you had)
  window.filterSides = function(ctx){
    if (!ctx || !ctx.row || !ctx.sides) return (ctx && ctx.sides) ? ctx.sides : [];
    return (ctx.row === 'R11') ? ctx.sides.filter(function(s){ return s !== 'Back'; }) : ctx.sides;
  };
})();
</script>

<script>
// Simple renderer for scan results
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
    var moved   = r.last_moved_at ? r.last_moved_at : '—';
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
          </thead>
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

// Hook up the Scan button and Enter key
(function(){
  var scanBtn     = document.getElementById('scan-button');
  var skuInput    = document.getElementById('sku_num');
  var resultsEl   = document.getElementById('scan-results');
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
      const res = await fetch('invSku.php?ajax=1&q=' + encodeURIComponent(sku), {
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
// Auto-submit after side selection (same pattern)
(function(){
  var sideWrap = document.getElementById('sideButtons');
  if (!sideWrap) return;
  sideWrap.addEventListener('click', function(e){
    if (e.target && e.target.tagName === 'BUTTON') {
      setTimeout(function(){
        var r = document.getElementById('row_code_input')?.value;
        var b = document.getElementById('bay_num_input')?.value;
        var l = document.getElementById('level_code_input')?.value;
        var s = document.getElementById('side_input')?.value;
        if (r && b && l && s) {
          var form = document.querySelector('form[method="get"]');
          if (form) form.submit();
        }
      }, 0);
    }
  });
})();
</script>
HTML;


require __DIR__ . '/templates/layout.php';
