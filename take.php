<?php
// dev/take.php — Location viewer + Remove Inventory (uses location-buttons.js)
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';

// ---------------- Auth ----------------
if (!isset($_SESSION['username'])) {
  header('Location: login.php'); exit;
}
$username = $_SESSION['username'] ?? 'User';
$user_id  = (int)($_SESSION['user_id'] ?? 0);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (\Throwable $_) {}

// ---------- Column + location resolvers ----------
function table_has_column(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT COUNT(*) AS c
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  if ($res) $res->free();
  $stmt->close();
  return isset($row['c']) && (int)$row['c'] > 0;
}

/** Resolve location PK (id vs loc_id) with tolerant matching for side and numerics. */
function resolve_loc_id(mysqli $conn, string $row_code, string $bay_num, string $level_code, string $side): int {
  static $pk = null;
  if ($pk === null) {
    $pk = table_has_column($conn, 'location', 'loc_id') ? 'loc_id' : 'id';
  }

  // Normalize inputs
  $row_code = strtoupper(trim($row_code));
  if (preg_match('/^R?\s*(\d{1,3})$/', $row_code, $m)) {
    $row_code = 'R' . (int)$m[1];
  }
  $bay_num    = (string)(int)trim($bay_num);
  $level_code = (string)(int)trim($level_code);

  $side_in = strtolower(trim($side));
  $cands = [];
  if ($side_in === 'f' || $side_in === 'front') { $cands = ['Front','F']; }
  elseif ($side_in === 'b' || $side_in === 'back') { $cands = ['Back','B']; }
  else { $cands = ['Front','Back','F','B']; }

  $sql = "SELECT {$pk} AS loc_id
          FROM location
          WHERE row_code = ? AND bay_num = ? AND level_code = ? AND side = ?
          LIMIT 1";
  foreach ($cands as $side_try) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss', $row_code, $bay_num, $level_code, $side_try);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) $res->free();
    $stmt->close();
    if ($row && isset($row['loc_id'])) return (int)$row['loc_id'];
  }

  // Fallback with CAST for numeric columns stored as strings
  $sql2 = "SELECT {$pk} AS loc_id
           FROM location
           WHERE row_code = ?
             AND CAST(bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
             AND CAST(level_code AS UNSIGNED) = CAST(? AS UNSIGNED)
             AND (side IN ('Front','Back','F','B'))";
  $stmt = $conn->prepare($sql2);
  $stmt->bind_param('sss', $row_code, $bay_num, $level_code);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  if ($res) $res->free();
  $stmt->close();
  return $row ? (int)$row['loc_id'] : 0;
}

// ---------------- Utilities ----------------
function norm_side_in(?string $v): string {
  $v = strtolower(trim((string)$v));
  if ($v === 'f' || $v === 'front') return 'Front';
  if ($v === 'b' || $v === 'back')  return 'Back';
  return ucfirst($v);
}
function side_letter(string $side): string {
  return ($side === 'Front') ? 'F' : (($side === 'Back') ? 'B' : strtoupper(substr($side,0,1)));
}

// ---------------- AJAX: fetch skus by location ----------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'inv_by_loc') {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store, max-age=0');

  $row  = trim($_GET['row']  ?? '');
  $bay  = trim($_GET['bay']  ?? '');
  $lvl  = trim($_GET['lvl']  ?? '');
  $side = norm_side_in($_GET['side'] ?? '');

  if ($row === '' || $bay === '' || $lvl === '' || $side === '') {
    echo json_encode(['ok'=>false,'error'=>'missing_params']); exit;
  }

  $loc_id = resolve_loc_id($conn, $row, $bay, $lvl, $side);
  if (!$loc_id) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

  // Aggregate on-hand + last movement, hide <= 0
  $sql = "
    SELECT 
      s.id      AS sku_id,
      s.sku_num AS sku_num,
      s.`desc`  AS sku_desc,
      SUM(im.quantity_change) AS on_hand,
      MAX(im.created_at)      AS last_movement
    FROM inventory_movements im
    JOIN sku s ON s.id = im.sku_id
    WHERE im.loc_id = ?
    GROUP BY s.id, s.sku_num, s.`desc`
    HAVING on_hand > 0
    ORDER BY s.sku_num ASC
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $loc_id);
  $stmt->execute();
  $r = $stmt->get_result();
  $rows = [];
  while ($r && ($rowx = $r->fetch_assoc())) $rows[] = $rowx;
  if ($r) $r->free();
  $stmt->close();

  echo json_encode(['ok' => true, 'loc_id' => $loc_id, 'rows' => $rows], JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

// ---------------- POST: Remove Inventory (OUT) ----------------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_out') {
  try {
    $sku_num    = trim($_POST['sku'] ?? '');
    $qty_req    = (int)abs((int)($_POST['quantity'] ?? 0));
    $row_code   = trim($_POST['row_code'] ?? '');
    $bay_num    = trim($_POST['bay_num'] ?? '');
    $level_code = trim($_POST['level_code'] ?? '');
    $side       = norm_side_in($_POST['side'] ?? '');

    if ($sku_num === '' || $qty_req <= 0 || $row_code === '' || $bay_num === '' || $level_code === '' || $side === '') {
      throw new RuntimeException('Please fill SKU, Quantity (>0), and select a full location.');
    }

    // Find SKU id
    $sku_id = null;
    $stmt = $conn->prepare("SELECT id FROM sku WHERE sku_num = ? LIMIT 1");
    $stmt->bind_param('s', $sku_num);
    $stmt->execute();
    $stmt->bind_result($sku_id);
    $stmt->fetch();
    $stmt->close();
    if (!$sku_id) throw new RuntimeException('SKU not found: '.$sku_num);

    // Resolve location id
    $loc_id = resolve_loc_id($conn, $row_code, $bay_num, $level_code, $side);
    if (!$loc_id) throw new RuntimeException('Location not found for selection');

    // Current on-hand at this location (via movements)
    $on_hand = 0;
    $stmt = $conn->prepare("
      SELECT COALESCE(SUM(quantity_change),0) AS on_hand
      FROM inventory_movements
      WHERE sku_id = ? AND loc_id = ?
    ");
    $stmt->bind_param('ii', $sku_id, $loc_id);
    $stmt->execute();
    $stmt->bind_result($on_hand);
    $stmt->fetch();
    $stmt->close();

    if ($qty_req > (int)$on_hand) {
      throw new RuntimeException("Insufficient on-hand. Available: {$on_hand}, requested: {$qty_req}.");
    }

    // Insert OUT movement as a negative quantity_change
    $movement_type = 'OUT';
    $qty_change    = -$qty_req; // negative
    $stmt = $conn->prepare("
      INSERT INTO inventory_movements (sku_id, loc_id, quantity_change, movement_type, user_id, created_at)
      VALUES (?,?,?,?,?, NOW())
    ");
    $stmt->bind_param('iiisi', $sku_id, $loc_id, $qty_change, $movement_type, $user_id);
    $stmt->execute();
    $stmt->close();

    // Remaining on-hand
    $remaining = 0;
    $stmt = $conn->prepare("
      SELECT COALESCE(SUM(quantity_change),0) AS on_hand
      FROM inventory_movements
      WHERE sku_id = ? AND loc_id = ?
    ");
    $stmt->bind_param('ii', $sku_id, $loc_id);
    $stmt->execute();
    $stmt->bind_result($remaining);
    $stmt->fetch();
    $stmt->close();

    $flash = ['type'=>'success', 'msg'=>"Removed -{$qty_req} of {$sku_num} from {$row_code}-{$bay_num}-{$level_code}-".side_letter($side).". Remaining on-hand: {$remaining}."];
  } catch (\Throwable $e) {
    $flash = ['type'=>'error', 'msg'=>$e->getMessage()];
  }
}

// ---------------- Master lists for UI ----------------
$skus = [];
if ($rs = $conn->query("SELECT id, sku_num FROM sku ORDER BY sku_num ASC")) {
  while ($r = $rs->fetch_assoc()) $skus[] = $r;
  $rs->free();
}
$locations = [];
if ($rs = $conn->query("SELECT row_code, bay_num, level_code, side FROM location ORDER BY row_code, bay_num, level_code, side")) {
  while ($r = $rs->fetch_assoc()) $locations[] = $r;
  $rs->free();
}

// ---------------- Page ----------------
$title = 'Location — Remove & View';
$page_class = 'page-place remove-and-view';

ob_start();
?>
<div class="breadcrumbs" style="margin:6px 0 12px;">
  <a class="link" href="manage_location.php">&larr; Manage Locations</a>
</div>

<section class="card card--pad">
  <h2 class="title" style="margin-bottom:10px;">Location selector</h2>

  <!-- Hidden inputs consumed by location-buttons.js -->
  <input type="hidden" id="row_code_input"   name="row_code"   />
  <input type="hidden" id="bay_num_input"    name="bay_num"    />
  <input type="hidden" id="level_code_input" name="level_code" />
  <input type="hidden" id="side_input"       name="side"       />

  <p class="text-muted" style="margin:6px 0 10px;">Pick a Row → Bay → Level → Side</p>

  <!-- Buttons rendered by location-buttons.js -->
  <div id="rowButtons" class="button-group" role="group" aria-label="Row"></div>
  <div id="bayButtons" class="button-group" role="group" aria-label="Bay"   style="margin-top:8px;"></div>
  <div id="levelButtons" class="button-group" role="group" aria-label="Level"style="margin-top:8px;"></div>
  <div id="sideButtons" class="button-group" role="group" aria-label="Side" style="margin-top:8px;"></div>

  <div style="display:flex; gap:8px; align-items:center; margin-top:12px; flex-wrap:wrap;">
    <button id="btnSearch" class="btn btn--primary" type="button">Search</button>
    <span class="text-muted">Selected: <strong id="selPath">—</strong></span>
  </div>
</section>

<?php if ($flash): ?>
  <div class="card card--pad" style="border-left:4px solid <?= $flash['type']==='success'?'var(--success,#28a745)':'var(--danger,#dc3545)' ?>; margin-top:12px;">
    <?= htmlspecialchars($flash['msg']) ?>
  </div>
<?php endif; ?>

<section class="card card--pad" style="margin-top:12px;">
  <h2 class="title" style="margin-bottom:10px;">Remove inventory (OUT)</h2>
  <form id="removeForm" method="post" action="">
    <input type="hidden" name="action" value="remove_out" />
    <!-- tie form location to selector -->
    <input type="hidden" name="row_code"   id="form_row"   />
    <input type="hidden" name="bay_num"    id="form_bay"   />
    <input type="hidden" name="level_code" id="form_lvl"   />
    <input type="hidden" name="side"       id="form_side"  />

    <div class="row" style="gap:8px; align-items:flex-end; flex-wrap:wrap;">
      <div>
        <label class="label" for="sku">SKU</label>
        <input class="input" list="sku_list" id="sku" name="sku" placeholder="Type or pick a SKU" required />
        <datalist id="sku_list">
          <?php foreach ($skus as $s): ?>
            <option value="<?= htmlspecialchars($s['sku_num']) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label class="label" for="quantity">Quantity</label>
        <input class="input" id="quantity" name="quantity" type="number" step="1" min="1" placeholder="e.g. 10" required />
      </div>
      <div>
        <button class="btn btn-outline btn--danger" type="submit">Remove from selected location</button>
      </div>
    </div>
    <p class="text-muted" style="margin-top:6px;">Inventory will be removed from the currently selected location above.</p>
  </form>
  


</section>

<section class="card card--pad" style="margin-top:12px;">
  <h2 class="title" style="margin-bottom:10px;">SKUs at this location</h2>
  <div id="resultsStatus" class="text-muted">Select a location and click <strong>Search</strong>.</div>
  <div class="table-wrap" style="margin-top:10px; overflow:auto;">
    <table class="table table-compact" id="resultsTable" hidden>
      <thead>
        <tr>
          <th style="white-space:nowrap;">SKU</th>
          <th>Description</th>
          <th style="text-align:right; white-space:nowrap;">On-Hand</th>
          <th style="white-space:nowrap;">Last movement</th>
          <th>Links</th>
        </tr>
      </thead>
      <tbody id="resultsBody"></tbody>
    </table>
  </div>
</section>

<?php
$content = ob_get_clean();

// ---------- Footer JS: wire the selector + results ----------
$loc_json = json_encode($locations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$js_fs    = __DIR__ . '/js/location-buttons.js';
$ver      = is_file($js_fs) ? filemtime($js_fs) : time();
$script_src = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/js/location-buttons.js?v=' . $ver;

$footer_js = <<<HTML
<script id="loc-data" type="application/json">{$loc_json}</script>
<script>
(function(){
  // Feed locations to location-buttons.js
  try {
    var el = document.getElementById('loc-data');
    window.PLACE_LOCATIONS = el ? JSON.parse(el.textContent) : [];
  } catch (e) { window.PLACE_LOCATIONS = []; }

  // Example rule: R11 has no Back (keeps your earlier requirement)
  window.filterSides = function({ row, sides }) {
    if (row === 'R11') return sides.filter(function(s){ return s !== 'Back'; });
    return sides;
  };

  // Elements
  var inRow  = document.getElementById('row_code_input');
  var inBay  = document.getElementById('bay_num_input');
  var inLvl  = document.getElementById('level_code_input');
  var inSide = document.getElementById('side_input');

  var selPath   = document.getElementById('selPath');
  var btnSearch = document.getElementById('btnSearch');

  var resultsStatus = document.getElementById('resultsStatus');
  var tbl   = document.getElementById('resultsTable');
  var tbody = document.getElementById('resultsBody');

  // Tie form hidden fields to selector so POST uses picked location
  var fRow = document.getElementById('form_row');
  var fBay = document.getElementById('form_bay');
  var fLvl = document.getElementById('form_lvl');
  var fSide= document.getElementById('form_side');

  function currentCode() {
    var r = (inRow.value||'').trim();
    var b = (inBay.value||'').trim();
    var l = (inLvl.value||'').trim();
    var s = (inSide.value||'').trim();
    var sLetter = (s === 'Front') ? 'F' : (s === 'Back' ? 'B' : s);
    return {r:r,b:b,l:l,s:s, sLetter:sLetter, code: (r&&b&&l&&s) ? (r+'-'+b+'-'+l+'-'+sLetter) : ''};
  }
  function reflectSelectionIntoForm() {
    fRow.value = inRow.value;
    fBay.value = inBay.value;
    fLvl.value = inLvl.value;
    fSide.value = inSide.value;
  }
  function updateSelPath() {
    var cc = currentCode();
    selPath.textContent = cc.code || '—';
    reflectSelectionIntoForm();
  }
  ['change','input'].forEach(function(ev){
    inRow.addEventListener(ev, updateSelPath);
    inBay.addEventListener(ev, updateSelPath);
    inLvl.addEventListener(ev, updateSelPath);
    inSide.addEventListener(ev, updateSelPath);
  });

  async function loadResults() {
    var cc = currentCode();
    if (!cc.code) {
      resultsStatus.textContent = 'Please select a full location (Row, Bay, Level, Side).';
      tbl.hidden = true; return;
    }
    resultsStatus.textContent = 'Loading…';
    tbl.hidden = true;

    var url = new URL(window.location.href);
    url.searchParams.set('ajax','inv_by_loc');
    url.searchParams.set('row', cc.r);
    url.searchParams.set('bay', cc.b);
    url.searchParams.set('lvl', cc.l);
    url.searchParams.set('side', cc.s);

    try {
      var res = await fetch(url.toString(), { headers: { 'Accept':'application/json' }});
      if (!res.ok) throw new Error('Network error');
      var data = await res.json();
      if (!data.ok) {
        resultsStatus.textContent = (data.error === 'not_found') ? 'No matching location found.' : 'Failed to load.';
        tbl.hidden = true; return;
      }

      tbody.innerHTML = '';
      if (!Array.isArray(data.rows) || data.rows.length === 0) {
        resultsStatus.textContent = 'No stock on hand at this location.';
        tbl.hidden = true; return;
      }
      // ...inside loadResults(), where you loop data.rows.forEach(r) ...
data.rows.forEach(function(r){
  var tr = document.createElement('tr');
  tr.className = 'pickable';                        // <-- add a class for styling
  tr.dataset.sku    = r.sku_num || '';
  tr.dataset.onhand = String(r.on_hand || 0);       // <-- stash on-hand for quick use

  // click-to-fill behavior
  tr.addEventListener('click', function(e){
    // ignore clicks on links/buttons inside the row
    if (e.target.closest('a,button')) return;

    var skuInput = document.getElementById('sku');
    var qtyInput = document.getElementById('quantity');

    skuInput.value = tr.dataset.sku;
    // Default fill: full on-hand (you can type over it)
    var maxQ = parseInt(tr.dataset.onhand || '0', 10) || 0;
    qtyInput.value = maxQ > 0 ? String(maxQ) : '';
    qtyInput.max = maxQ > 0 ? String(maxQ) : '';    // prevent overs
    qtyInput.focus();

    // Store a "current max" on the chips container for the Max button
    var chips = document.getElementById('qtyChips');
    if (chips) chips.dataset.max = String(maxQ);

    // row highlight
    document.querySelectorAll('tr.pickable.is-selected').forEach(function(row){
      row.classList.remove('is-selected');
    });
    tr.classList.add('is-selected');
  });

  var tdSku = document.createElement('td');
  tdSku.style.whiteSpace = 'nowrap';
  tdSku.textContent = r.sku_num || '';
  tr.appendChild(tdSku);

  var tdDesc = document.createElement('td');
  tdDesc.textContent = r.sku_desc || '';
  tr.appendChild(tdDesc);

  var tdQty = document.createElement('td');
  tdQty.style.textAlign = 'right';
  tdQty.textContent = String(r.on_hand || 0);
  tr.appendChild(tdQty);

  var tdLast = document.createElement('td');
  tdLast.style.whiteSpace = 'nowrap';
  tdLast.textContent = r.last_movement || '';
  tr.appendChild(tdLast);

  var tdLink = document.createElement('td');
  // Add a “Fill” button for users who prefer explicit action
  var fillBtn = document.createElement('button');
  fillBtn.type = 'button';
  fillBtn.className = 'btn btn-xs';
  fillBtn.textContent = 'Fill';
  fillBtn.addEventListener('click', function(ev){
    ev.stopPropagation();
    tr.click(); // reuse the same behavior
  });

  var a = document.createElement('a');
  a.className = 'link';
  a.href = 'history.php?sku=' + encodeURIComponent(r.sku_num || '');
  a.style.marginLeft = '8px';
  a.textContent = 'History';

  tdLink.appendChild(fillBtn);
  tdLink.appendChild(a);
  tr.appendChild(tdLink);

  tbody.appendChild(tr);
});

      resultsStatus.textContent = cc.code + ' — ' + data.rows.length + ' SKU(s)';
      tbl.hidden = false;
    } catch (e) {
      resultsStatus.textContent = 'Error loading results.';
      tbl.hidden = true;
    }
  }

  // Search button (keeps selection visible)
  btnSearch.addEventListener('click', function(){
    updateSelPath();
    loadResults();
  });

  // After successful POST (flash present), auto-refresh if a location is selected
  document.addEventListener('DOMContentLoaded', function(){
    updateSelPath();
    var hadFlash = !!document.querySelector('.card.card--pad[style*="border-left"]');
    if (hadFlash) {
      var cc = currentCode();
      if (cc.code) loadResults();
    }
  });
})();
</script>
<script>
  var removeForm = document.getElementById('removeForm');
  if (removeForm) {
    removeForm.addEventListener('submit', function(){
      // Recompute and reflect selection to form before POST
      if (typeof updateSelPath === 'function') { updateSelPath(); }
    });
  }
</script>
<script defer src="{$script_src}"></script>
HTML;

$css = $css ?? [];
$js  = $js ?? [];
include __DIR__ . '/templates/layout.php';
