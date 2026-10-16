<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';


if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'] ?? 0;
$role_id = $_SESSION['role_id'] ?? 0;


if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn->set_charset('utf8mb4');
} catch (\Throwable $_) {
}

if (!defined('SPECIAL_ROWS')) {
    define('SPECIAL_ROWS', $dynamic_special_rows ?? []);
}

$current_special_rows = SPECIAL_ROWS;
if (isset($dynamic_special_rows) && $current_special_rows !== $dynamic_special_rows) {
    error_log("Updated SPECIAL_ROWS from database: " . implode(", ", $dynamic_special_rows));
}

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
    $current_special_rows = array_values(array_diff(array_unique($row_codes), $structured_rows));
    error_log("[invLoc] SPECIAL_ROWS fallback derived: " . implode(", ", $current_special_rows));
}

$loc_id = null;
$loc_label = '';


// ---------- Pull all active locations (for buttons) ----------
$loc_list = [];
$res = $conn->query("SELECT id, row_code, bay_num, level_code, side FROM location WHERE is_disabled = 0 ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side");

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

$norm_row = $row_code === '' ? '' : strtoupper($row_code);

// ---------- Conditional Defaulting Logic for Unstructured Rows (match take.php) ----------
$norm_bay = '';
$norm_level = '';
$norm_side = '';
$is_special_row = false;

if ($norm_row && in_array($norm_row, $current_special_rows, true)) {
    $is_special_row = true;
    $norm_bay = '01';
    $norm_level = '11';
    $norm_side = 'F';
    $sel['bay_num'] = $norm_bay;
    $sel['level_code'] = $norm_level;
    $sel['side'] = $norm_side;

    $bay_num = $norm_bay;
    $level = $norm_level;
    $side = $norm_side;
} else {
    $norm_bay = $bay_num === '' ? '' : ltrim($bay_num, '0');
    if ($norm_bay === '' && $bay_num !== '') {
        $norm_bay = '0';
    }
    $norm_level = $level === '' ? '' : strtoupper($level);
    $norm_side = $side === '' ? '' : strtoupper($side);
}

if ($norm_row && $norm_bay !== '' && $norm_level && $norm_side) {
    $sql_loc_resolve = "
    SELECT id, row_code, bay_num, level_code, side
    FROM location
    WHERE UPPER(row_code) = ?
     AND CAST(bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
     AND UPPER(level_code) = ?
     AND LEFT(UPPER(side), 1) = LEFT(?, 1)
     AND is_disabled = 0
     LIMIT 1
  ";

    $stmt = $conn->prepare($sql_loc_resolve);
    $stmt->bind_param('ssss', $norm_row, $norm_bay, $norm_level, $norm_side);

    $stmt->execute();
    $rs = $stmt->get_result();
    if ($rec = $rs->fetch_assoc()) {
        $loc_id = (int) $rec['id'];
        $sel['row_code'] = $rec['row_code'];
        $sel['bay_num'] = $rec['bay_num'];
        $sel['level_code'] = $rec['level_code'];
        $sel['side'] = $rec['side'];
        $loc_label = getLocationDisplayLabel($rec);
    }
    $stmt->close();
}

// ---------- Query inventory (qty > 0), join sku, pallet, last movement ----------
$rows_out = [];
$error_msg = '';

if ($loc_id !== null) {

    $sql = "
        SELECT 
            s.id AS sku_id, 
            s.sku_num AS sku, 
            s.`desc` AS sku_description, 
            s.lot_required AS lot_required,
            SUM(i.quantity) AS on_hand,
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
        WHERE p.loc_id = ?
        AND i.quantity > 0
        GROUP BY s.id, s.sku_num, s.`desc`, p.loc_id
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
                'on_hand' => (int) ($r['on_hand'] ?? 0),
                'lot_required' => (int) ($r['lot_required'] ?? 0),
                'last_moved_at' => $r['last_moved_at'] ?? null,
                'open_req_qty' => (int) ($r['open_req_qty'] ?? 0),
                'has_priority' => (int) ($r['has_priority'] ?? 0)
            ];
        }
        $stmt->close();
    } catch (\Throwable $e) {
        $error_msg = 'Query failed: ' . h($e->getMessage());
    }
}


// ---------- Page ----------
$title = 'Location Details';
$page_class = 'page-loc-details';
ob_start();
?>

<h2 class="title">Location Details</h2>

<form method="get" class="card" style="padding:12px; margin-bottom:16px;">
    <div class="mt-1">
        <div class="text-muted small mb-1">Row</div>
        <div id="rowButtons" class="d-flex flex-wrap gap-2"></div>
    </div>

    <div class="mt-3" id="bayContainer">
        <div class="text-muted small mb-1">Bay</div>
        <div id="bayButtons" class="d-flex flex-wrap gap-2"></div>
    </div>

    <div class="mt-3" id="levelContainer">
        <div class="text-muted small mb-1">Level</div>
        <div id="levelButtons" class="d-flex flex-wrap gap-2"></div>
        <div class="text-muted small mt-1">
            <small>Selecting an intermediate component (Row, Bay, Level) reloads the page to show the next level of
                buttons.</small>
        </div>
    </div>

    <div class="mt-3" id="sideContainer">
        <div class="text-muted small mb-1">Side</div>
        <div id="sideButtons" class="d-flex flex-wrap gap-2"></div>
    </div>

    <div class="mb-3 d-flex align-items-center gap-2">
        <button type="button" id="btnClearSelection" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-x-circle"></i> Clear Selection
        </button>
    </div>

    <?php
    function render_value_attr($value)
    {
        return !empty($value) ? ' value="' . h($value) . '"' : '';
    }
    ?>

    <input type="hidden" id="row_code_input" name="row_code" <?= render_value_attr($sel['row_code']) ?>>
    <input type="hidden" id="bay_num_input" name="bay_num" <?= render_value_attr($sel['bay_num']) ?>>
    <input type="hidden" id="level_code_input" name="level_code" <?= render_value_attr($sel['level_code']) ?>>
    <input type="hidden" id="side_input" name="side" <?= render_value_attr($sel['side']) ?>>
</form>

<div class="mb-3">
    <?php if ($loc_id !== null): ?>
        <span class="badge bg-success text-white p-2">Location: <?= h($loc_label) ?></span>
    <?php elseif ($row_code): ?>
        <?php
        $is_structured = !$is_special_row;
        $missing_parts = [];
        if ($is_structured) {
            if (empty($bay_num))
                $missing_parts[] = 'Bay';
            if (empty($level))
                $missing_parts[] = 'Level';
            if (empty($side))
                $missing_parts[] = 'Side';
        }
        ?>
        <?php if ($is_structured && $missing_parts): ?>
            <div class="alert alert-info p-2 small mb-0">Partial Selection: **<?= h($row_code) ?>**. Missing:
                <?= h(implode(', ', $missing_parts)) ?>.
            </div>
        <?php else: ?>
            <div class="alert alert-warning p-2 small mb-0">No location found matching current selection:
                **<?= h(implode('/', array_filter($sel))) ?>**.</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-light p-2 small mb-0">Please select a Row to begin.</div>
    <?php endif; ?>
</div>

<?php if ($error_msg): ?>
    <div class="alert alert-danger"><?= $error_msg ?></div>
<?php endif; ?>

<div class="card" style="overflow:auto;">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>SKU</th>
                <th>Description</th>
                <th class="text-end">On-Hand</th>
                <th>Last Movement At</th>
                <th>Quick Links</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows_out as $r): ?>
                <tr>
                    <td>
                        <span class="fw-bold"><?= h($r['sku']) ?></span>
                        <?php if (!empty($r['lot_required'])): ?>
                            <span class="badge ms-1 lot-exp-badge">Lot</span>
                        <?php endif; ?>
                        <?php if ($r['open_req_qty'] > 0): ?>
                            <?= getRequestBadge($r['open_req_qty'], $r['has_priority']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= h($r['desc']) ?></td>
                    <td class="text-end"><?= h((string) $r['on_hand']) ?></td>
                    <td><?= $r['last_moved_at'] ? h($r['last_moved_at']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <a class="btn btn-outline-secondary btn-sm"
                            href="history.php?sku=<?= urlencode($r['sku']) ?>&row=<?= urlencode($sel['row_code']) ?>&bay=<?= urlencode($sel['bay_num']) ?>&lvl=<?= urlencode($sel['level_code']) ?>&side=<?= urlencode($sel['side']) ?>">
                            History
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
$content = ob_get_clean();

$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$boot_data = [
    'loc_list' => $loc_list,
    'specialRows' => $current_special_rows,
];

$loc_json = json_encode($boot_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$js_fs = __DIR__ . '/js/location-buttons.js';
$ver = is_file($js_fs) ? filemtime($js_fs) : time();
$script_src = $BASE_URL . '/js/location-buttons.js?v=' . $ver;

$footer_js = <<<HTML
<script id="loc-data" type="application/json">{$loc_json}</script>

<script>
(function(){
  var el = document.getElementById('loc-data');
  var data = el ? JSON.parse(el.textContent) : {};

  window.PLACE_LOCATIONS = data.loc_list || [];
  window.SPECIAL_ROWS = data.specialRows || [];

  window.filterSides = function(ctx){
    if (!ctx || !ctx.row || !ctx.sides) return (ctx && ctx.sides) ? ctx.sides : [];
    return (ctx.row === 'R11') ? ctx.sides.filter(function(s){ return s !== 'Back'; }) : ctx.sides;
  };

})();
</script>

<script src="{$script_src}"></script>

<script>
(function(){
  var form = document.querySelector('form[method="get"]');
  var wraps = [
    document.getElementById('rowButtons'),
    document.getElementById('bayButtons'),
    document.getElementById('levelButtons'),
    document.getElementById('sideButtons')
  ].filter(Boolean);

  var inRow = document.getElementById('row_code_input');
  var inBay = document.getElementById('bay_num_input');
  var inLevel = document.getElementById('level_code_input');
  var inSide = document.getElementById('side_input');

  var specialSet = new Set((window.SPECIAL_ROWS || []).map(function(r){ return String(r).toUpperCase(); }));
  var SPECIAL_DEFAULTS = { bay: '01', level: '11', side: 'F' };
    
//   Clear Selection Button
  const btnClear = document.getElementById('btnClearSelection');
    if (btnClear) {
        btnClear.addEventListener('click', function() {
            if (inRow) inRow.value = '';
            if (inBay) inBay.value = '';
            if (inLevel) inLevel.value = '';
            if (inSide) inSide.value = '';

            console.debug('[invLoc] Selection cleared by user.');
            form.submit();
        });
    }

  function debugState(trigger){
    var state = {
      row: (inRow?.value || '').trim(),
      bay: (inBay?.value || '').trim(),
      level: (inLevel?.value || '').trim(),
      side: (inSide?.value || '').trim()
    };
    state.isSpecial = specialSet.has(state.row.toUpperCase());
    state.ready = !!(state.row && state.bay && state.level && state.side);
    return state;
  }  

  function applySpecialDefaultsIfNeeded(rowVal){
    var rowUp = String(rowVal || '').toUpperCase();
    if (!specialSet.has(rowUp)) return false;
    if (inBay) inBay.value = SPECIAL_DEFAULTS.bay;
    if (inLevel) inLevel.value = SPECIAL_DEFAULTS.level;
    if (inSide) inSide.value = SPECIAL_DEFAULTS.side;
    return true;
  }

  function maybeSubmit(trigger){
    var s = debugState(trigger);
    if (s.ready && form) {
      console.debug('[invLoc] submitting form', trigger);
      form.submit();
    } else {
      console.debug('[invLoc] not submitting (incomplete)', trigger);
    }
  }

  wraps.forEach(function(wrap){
    wrap.addEventListener('click', function(){
      setTimeout(function(){
        var rowVal = (inRow?.value || '').trim();
        var autoFilled = applySpecialDefaultsIfNeeded(rowVal);
        maybeSubmit(autoFilled ? 'auto-special-default' : 'button-click');
      }, 30); 
    });
  });

  debugState('page-load');
})();
</script>


HTML;
require __DIR__ . '/templates/layout.php';
