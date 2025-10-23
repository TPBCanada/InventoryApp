<?php
// invLoc.php — Location Details (read-only)
// Select Row/Bay/Level/Side via buttons → list SKUs at that location (qty > 0)
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';

if (!isset($_SESSION['username'])) {
  header('Location: login.php');
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (\Throwable $_) {}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- Pull all locations (for buttons) ----------
$loc_list = [];
$res = $conn->query("SELECT id, row_code, bay_num, level_code, side FROM location ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side");
while ($r = $res->fetch_assoc()) {
  $loc_list[] = [
    'id'         => (int)$r['id'],
    'row_code'   => (string)$r['row_code'],
    'bay_num'    => (string)$r['bay_num'],
    'level_code' => (string)$r['level_code'],
    'side'       => (string)$r['side'],
  ];
}

// ---------- Read selection from GET (from hidden inputs) ----------
$row_code = trim($_GET['row_code']   ?? '');
$bay_num  = trim($_GET['bay_num']    ?? '');
$level    = trim($_GET['level_code'] ?? '');
$side     = trim($_GET['side']       ?? '');

$sel = [
  'row_code'   => $row_code,
  'bay_num'    => $bay_num,
  'level_code' => $level,
  'side'       => $side,
];

// ---------- Normalize (fix “01” vs “1” bay) ----------
$norm_row   = $row_code === '' ? '' : strtoupper($row_code);
$norm_bay   = $bay_num  === '' ? '' : ltrim($bay_num, '0');  // "01" → "1"
if ($norm_bay === '' && $bay_num !== '') $norm_bay = '0';    // edge case: actual "0"
$norm_level = $level    === '' ? '' : strtoupper($level);
$norm_side  = $side     === '' ? '' : strtoupper($side);

// ---------- Resolve loc_id (tolerant: 01==1, F/B == Front/Back) ----------
$loc_id = null; 
$loc_label = '';

$norm_row   = $row_code === '' ? '' : strtoupper($row_code);
$norm_bay   = $bay_num  === '' ? '' : ltrim($bay_num, '0');  // "01" → "1"
if ($norm_bay === '' && $bay_num !== '') $norm_bay = '0';
$norm_level = $level    === '' ? '' : strtoupper($level);
$norm_side  = $side     === '' ? '' : strtoupper($side);

// Accept both full words and initial letters for side
// e.g., "B" or "BACK" should match DB "Back"; "F" or "FRONT" match "Front"
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
    $loc_id = (int)$rec['id'];
    $loc_label = "{$rec['row_code']}-{$rec['bay_num']}-{$rec['level_code']}-{$rec['side']}";
  }
  $stmt->close();
}


// ---------- Query inventory (qty > 0), join sku, last movement ----------
$rows_out = [];
$error_msg = '';

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
        'sku_id'        => (int)$r['sku_id'],
        'sku'           => (string)$r['sku'],
        'desc'          => (string)$r['desc'],
        'on_hand'       => (int)$r['on_hand'],
        'last_moved_at' => $r['last_moved_at'] ?? null,
      ];
    }
    $stmt->close();
  } catch (\Throwable $e) {
    $error_msg = 'Query failed. Please try again.';
  }
}

// ---------- Page ----------
$title = 'Location Details';
$page_class = 'page-loc-details';
ob_start();
?>

<h2 class="title">Location Details</h2>

  <!-- Locator (buttons at the top; no “Selected/Clear/Use Selection” header) -->
  <form method="get" class="card" style="padding:12px; margin-bottom:16px;">
    <!-- Row -->
    <div class="mt-1">
      <div class="text-muted small mb-1">Row</div>
      <div id="rowButtons" class="d-flex flex-wrap gap-2"></div>
    </div>

    <!-- Bay -->
    <div class="mt-3">
      <div class="text-muted small mb-1">Bay</div>
      <div id="bayButtons" class="d-flex flex-wrap gap-2"></div>
    </div>

    <!-- Level -->
    <div class="mt-3">
      <div class="text-muted small mb-1">Level</div>
      <div id="levelButtons" class="d-flex flex-wrap gap-2"></div>
    </div>

    <!-- Side -->
    <div class="mt-3">
      <div class="text-muted small mb-1">Side</div>
      <div id="sideButtons" class="d-flex flex-wrap gap-2"></div>
    </div>

    <!-- Hidden inputs that the locator JS writes to; they are the GET params -->
    <input type="hidden" id="row_code_input"   name="row_code"   value="<?= h($sel['row_code']) ?>">
    <input type="hidden" id="bay_num_input"    name="bay_num"    value="<?= h($sel['bay_num']) ?>">
    <input type="hidden" id="level_code_input" name="level_code" value="<?= h($sel['level_code']) ?>">
    <input type="hidden" id="side_input"       name="side"       value="<?= h($sel['side']) ?>">
  </form>

  <?php if ($loc_id !== null): ?>
    <div class="mb-2">
      <span class="badge bg-info text-dark">Location: <?= h($loc_label) ?>)</span>
    </div>
  <?php elseif ($_GET): ?>
    <div class="alert alert-warning">No matching location found. Please check your selection.</div>
  <?php endif; ?>

  <?php if ($error_msg): ?>
    <div class="alert alert-danger"><?= h($error_msg) ?></div>
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
      <?php if ($loc_id !== null && empty($rows_out)): ?>
        <tr><td colspan="5" class="text-muted">No inventory with quantity &gt; 0 at this location.</td></tr>
      <?php elseif ($loc_id !== null): ?>
        <?php foreach ($rows_out as $r): ?>
          <tr>
            <td><?= h($r['sku']) ?></td>
            <td><?= h($r['desc']) ?></td>
            <td class="text-end"><?= h((string)$r['on_hand']) ?></td>
            <td><?= $r['last_moved_at'] ? h($r['last_moved_at']) : '<span class="text-muted">—</span>' ?></td>
            <td>
              <a class="btn btn-outline-secondary btn-sm"
                 href="history.php?sku=<?= urlencode($r['sku']) ?>&loc=<?= urlencode((string)$loc_id) ?>">
                History
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="5" class="text-muted">Pick a location above.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>


<?php
$content  = ob_get_clean();

// ---------- Footer JS: publish data, include locator script, auto-submit on side pick ----------
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// Pass the array we actually built
$loc_json = json_encode($loc_list, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);

// Cache-bust the JS
$js_fs = __DIR__ . '/js/location-buttons.js';
$ver   = is_file($js_fs) ? filemtime($js_fs) : time();
$script_src = $BASE_URL . '/js/location-buttons.js?v=' . $ver;

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

  // Rule: R11 has only Front
  window.filterSides = function(ctx){
    if (!ctx || !ctx.row || !ctx.sides) return ctx?.sides || [];
    return (ctx.row === 'R11') ? ctx.sides.filter(function(s){ return s !== 'Back'; }) : ctx.sides;
  };
})();
</script>
<script src="{$script_src}"></script>

<!-- Auto-submit the form as soon as a Side is picked -->
<script>
(function(){
  var sideWrap = document.getElementById('sideButtons');
  sideWrap?.addEventListener('click', function(e){
    if (e.target && e.target.tagName === 'BUTTON') {
      // Let location-buttons.js update hidden inputs first
      setTimeout(function(){
        var r = document.getElementById('row_code_input')?.value;
        var b = document.getElementById('bay_num_input')?.value;
        var l = document.getElementById('level_code_input')?.value;
        var s = document.getElementById('side_input')?.value;
        if (r && b && l && s) {
          // Submit the only form on the page (the locator form)
          document.querySelector('form[method="get"]')?.submit();
        }
      }, 0);
    }
  });
})();
</script>
HTML;

require __DIR__ . '/templates/layout.php';
