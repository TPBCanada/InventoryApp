<?php
// dev/place.php
session_start();
require_once __DIR__ . '/dbinv.php';

// auth
if (!isset($_SESSION['username'])) {
  header("Location: login.php");
  exit;
}

$user_id  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$username = $_SESSION['username'] ?? 'User';
$role_id  = (int)($_SESSION['role_id'] ?? 0);

// db conn guard
if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }

$toast_msg = ''; 
$toast_type = 'info';
$location_history = []; 
$history_heading = ''; 
$show_history = false;

// --- Master lists ---
$skus = [];
if ($sku_rs = mysqli_query($conn, "SELECT id, sku_num FROM sku ORDER BY sku_num ASC")) {
  while ($r = mysqli_fetch_assoc($sku_rs)) $skus[] = $r;
  mysqli_free_result($sku_rs);
}

$locations = [];
if ($loc_rs = mysqli_query($conn, "SELECT loc_id, row_code, bay_num, level_code, side FROM location ORDER BY row_code, bay_num, level_code, side")) {
  while ($r = mysqli_fetch_assoc($loc_rs)) $locations[] = $r;
  mysqli_free_result($loc_rs);
}

// --- Build row -> allowed sides (from locations) ---
$row_allowed = []; // e.g. ['R11' => ['Front'], 'R12' => ['Front','Back']]
foreach ($locations as $l) {
  $r = (string)$l['row_code'];
  $s = (string)$l['side'];            // 'Front' or 'Back'
  if (!isset($row_allowed[$r])) $row_allowed[$r] = [];
  if (!in_array($s, $row_allowed[$r], true)) $row_allowed[$r][] = $s;
}

// If you prefer to compute with SQL instead of PHP:
// $rules_rs = mysqli_query($conn, "SELECT row_code, GROUP_CONCAT(DISTINCT side) AS sides FROM location GROUP BY row_code");
// while ($r = mysqli_fetch_assoc($rules_rs)) $row_allowed[$r['row_code']] = array_map('trim', explode(',', $r['sides']));

// ---------- Page meta + body-only render ----------
$title = 'Add Inventory';
ob_start();
// ... (your HTML body) ...
$content = ob_get_clean();

$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$locations_json   = json_encode($locations, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$row_allowed_json = json_encode($row_allowed, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// Inline CSS + DATA + Builder (keeps order deterministic)
$footer_js = <<<HTML
<style>
  #locationSelectors .button-group{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 12px}
  #locationSelectors .group-label{font-weight:700;margin-top:8px}
  .btn:disabled{opacity:.45;transform:none}
</style>
<script>
  window.PLACE_LOCATIONS = {$locations_json};
  window.PLACE_RULES = { rowAllowedSides: {$row_allowed_json} };
</script>
<script>
(function(){
  function init(){
    const LOCS   = Array.isArray(window.PLACE_LOCATIONS) ? window.PLACE_LOCATIONS : [];
    const RULES  = (window.PLACE_RULES && window.PLACE_RULES.rowAllowedSides) ? window.PLACE_RULES.rowAllowedSides : {};

    const rowWrap   = document.getElementById('rowButtons');
    const bayWrap   = document.getElementById('bayButtons');
    const levelWrap = document.getElementById('levelButtons');
    const sideWrap  = document.getElementById('sideButtons');
    const selPath   = document.getElementById('selPath');
    const clearBtn  = document.getElementById('clearSel');

    const inRow   = document.getElementById('row_code_input');
    const inBay   = document.getElementById('bay_num_input');
    const inLevel = document.getElementById('level_code_input');
    const inSide  = document.getElementById('side_input');

    if (!rowWrap || !bayWrap || !levelWrap || !sideWrap) return;

    const uniq = (a)=>[...new Set(a)];
    const qsa  = (el, sel)=>Array.from(el.querySelectorAll(sel));
    const btn  = (label, value, group)=>{
      const b = document.createElement('button');
      b.type='button'; b.className='btn btn--primary';
      b.textContent=label; b.dataset.value=value; b.dataset.group=group;
      b.setAttribute('aria-pressed','false');
      b.setAttribute('aria-label', `${group}: ${label}`);
      return b;
    };

    const state = { row:null, bay:null, level:null, side:null };

    function setSelected(wrap,val){
      qsa(wrap,'button').forEach(b=>{
        const sel = b.dataset.value===val;
        b.classList.toggle('btn--ghost', sel);
        b.classList.toggle('btn--primary', !sel);
        b.setAttribute('aria-pressed', sel?'true':'false');
        if (sel) b.setAttribute('aria-current','true'); else b.removeAttribute('aria-current');
      });
    }
    function setHidden(){
      if (inRow)   inRow.value   = state.row   ?? '';
      if (inBay)   inBay.value   = state.bay   ?? '';
      if (inLevel) inLevel.value = state.level ?? '';
      if (inSide)  inSide.value  = state.side  ?? '';
    }
    function updatePath(){
      if (!selPath) return;
      const parts=[state.row,state.bay,state.level,state.side].filter(Boolean);
      selPath.textContent = parts.length ? parts.join(' / ') : '—';
    }

    // Build ALL buttons immediately
    const rows   = uniq(LOCS.map(l=>String(l.row_code)));
    const bays   = uniq(LOCS.map(l=>String(l.bay_num)));
    const levels = uniq(LOCS.map(l=>String(l.level_code)));
    const sides  = uniq(LOCS.map(l=>String(l.side)));

    rows.forEach(v=>rowWrap.appendChild(btn(v,v,'row')));
    bays.forEach(v=>bayWrap.appendChild(btn(v,v,'bay')));
    levels.forEach(v=>levelWrap.appendChild(btn(v,v,'level')));
    sides.forEach(v=>sideWrap.appendChild(btn(v,v,'side')));

    function updateAvailability(){
      // Base compatibility from existing locations (cascading filters)
      const valid = { row:new Set(), bay:new Set(), level:new Set(), side:new Set() };
      LOCS.forEach(l=>{
        const R=String(l.row_code), B=String(l.bay_num), L=String(l.level_code), S=String(l.side);
        const rowOk   = !state.row   || state.row===R;
        const bayOk   = !state.bay   || state.bay===B;
        const levelOk = !state.level || state.level===L;
        const sideOk  = !state.side  || state.side===S;
        if (rowOk && bayOk && levelOk && sideOk){
          valid.row.add(R); valid.bay.add(B); valid.level.add(L); valid.side.add(S);
        }
      });

      toggleGroup(rowWrap,   valid.row,   !!state.row);
      toggleGroup(bayWrap,   valid.bay,   !!state.bay);
      toggleGroup(levelWrap, valid.level, !!state.level);
      toggleGroup(sideWrap,  valid.side,  !!state.side);

      // --- DB-driven row rule: restrict allowed sides by selected row
      if (state.row){
        const allowed = (RULES[state.row] || []).map(s=>s.toLowerCase()); // e.g. ['front'] or ['front','back']
        if (allowed.length){ // only enforce if rule exists for this row
          qsa(sideWrap,'button').forEach(btn=>{
            const isAllowed = allowed.includes((btn.dataset.value||'').toLowerCase());
            btn.disabled = btn.disabled || !isAllowed;
            if (!isAllowed && state.side && state.side.toLowerCase() === (btn.dataset.value||'').toLowerCase()){
              state.side = null;
              setSelected(sideWrap,null);
              setHidden();
            }
            if (!isAllowed) btn.title = 'This side is not allowed for the selected row';
          });
        }
      }
    }

    function toggleGroup(wrap, validSet, hasSelection){
      qsa(wrap,'button').forEach(btn=>{
        const enable = hasSelection || validSet.has(btn.dataset.value);
        btn.disabled = !enable;
        btn.title = enable ? '' : 'Not available for current selection';
      });
    }

    function select(group, value){
      state[group] = value;
      if (group==='row'){ state.bay = state.level = state.side = null; }
      if (group==='bay'){ state.level = state.side = null; }
      if (group==='level'){ state.side = null; }
      setSelected(rowWrap,state.row);
      setSelected(bayWrap,state.bay);
      setSelected(levelWrap,state.level);
      setSelected(sideWrap,state.side);
      setHidden(); updatePath(); updateAvailability();
    }

    // Wire up clicks
    qsa(rowWrap,'button').forEach(b=>b.addEventListener('click',()=>select('row',b.dataset.value)));
    qsa(bayWrap,'button').forEach(b=>b.addEventListener('click',()=>select('bay',b.dataset.value)));
    qsa(levelWrap,'button').forEach(b=>b.addEventListener('click',()=>select('level',b.dataset.value)));
    qsa(sideWrap,'button').forEach(b=>b.addEventListener('click',()=>select('side',b.dataset.value)));

    if (clearBtn){
      clearBtn.addEventListener('click', ()=>{
        state.row = state.bay = state.level = state.side = null;
        setSelected(rowWrap,null); setSelected(bayWrap,null);
        setSelected(levelWrap,null); setSelected(sideWrap,null);
        setHidden(); updatePath(); updateAvailability();
      });
    }

    // First paint
    setHidden(); updatePath(); updateAvailability();
  }

  if (document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded', init, { once:true });
  } else {
    init();
  }
})();
</script>
HTML;

include __DIR__ . '/templates/layout.php';


// --- POST handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
    $toast_msg = "Security token invalid. Please refresh and try again.";
    $toast_type = 'error';
  } else {
    $sku_num    = trim($_POST['sku'] ?? '');
    $row_code   = trim($_POST['row_code'] ?? '');
    $bay_num    = trim($_POST['bay_num'] ?? '');
    $level_code = trim($_POST['level_code'] ?? '');
    $side       = trim($_POST['side'] ?? '');
    $qty        = (int)($_POST['quantity'] ?? 0);

    $errors = [];
    if ($sku_num === '')     $errors[] = "SKU is required.";
    if ($row_code === '')    $errors[] = "Row is required.";
    if ($bay_num === '')     $errors[] = "Bay is required.";
    if ($level_code === '')  $errors[] = "Level is required.";
    if ($side === '')        $errors[] = "Side is required.";
    if ($qty <= 0)           $errors[] = "Quantity must be a positive number.";

    if (!$errors) {
      // Lookup SKU id
      $sku_id = null;
      $sku_stmt = mysqli_prepare($conn, "SELECT id FROM sku WHERE sku_num = ?");
      mysqli_stmt_bind_param($sku_stmt, "s", $sku_num);
      mysqli_stmt_execute($sku_stmt);
      mysqli_stmt_bind_result($sku_stmt, $sku_id);
      mysqli_stmt_fetch($sku_stmt);
      mysqli_stmt_close($sku_stmt);

      if (!$sku_id) {
        $errors[] = "SKU not found: " . htmlspecialchars($sku_num);
      } else {
        // Lookup loc_id
        $loc_id = null;
        $loc_stmt = mysqli_prepare($conn, "SELECT loc_id FROM location WHERE row_code=? AND bay_num=? AND level_code=? AND side=?");
        mysqli_stmt_bind_param($loc_stmt, "ssss", $row_code, $bay_num, $level_code, $side);
        mysqli_stmt_execute($loc_stmt);
        mysqli_stmt_bind_result($loc_stmt, $loc_id);
        mysqli_stmt_fetch($loc_stmt);
        mysqli_stmt_close($loc_stmt);

        if (!$loc_id) {
          $errors[] = "Location not found for selection.";
        } else {
          // Insert IN movement
          $movement_type = 'IN';
          $ins_stmt = mysqli_prepare(
            $conn,
            "INSERT INTO inventory_movements (sku_id, loc_id, quantity_change, movement_type, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
          );
          mysqli_stmt_bind_param($ins_stmt, "iiisi", $sku_id, $loc_id, $qty, $movement_type, $user_id);
          $ok = mysqli_stmt_execute($ins_stmt);
          $sql_err = mysqli_stmt_error($ins_stmt);
          mysqli_stmt_close($ins_stmt);

          if ($ok) {
            $toast_msg  = "Added +{$qty} of SKU {$sku_num} to {$row_code}-{$bay_num}-{$level_code}-{$side}.";
            $toast_type = 'success';

            // Recent history for this location (last 25)
            $hist_stmt = mysqli_prepare(
              $conn,
              "SELECT s.sku_num, im.quantity_change, im.movement_type, im.user_id, im.created_at
               FROM inventory_movements im
               LEFT JOIN sku s ON s.id = im.sku_id
               WHERE im.loc_id = ?
               ORDER BY im.created_at DESC
               LIMIT 25"
            );
            mysqli_stmt_bind_param($hist_stmt, "i", $loc_id);
            mysqli_stmt_execute($hist_stmt);
            $res = mysqli_stmt_get_result($hist_stmt);
            while ($row = mysqli_fetch_assoc($res)) $location_history[] = $row;
            mysqli_stmt_close($hist_stmt);

            $history_heading = "Transaction History for {$row_code}-{$bay_num}-{$level_code}-{$side}";
            $show_history = true;
          } else {
            $toast_msg  = "Error adding item: " . htmlspecialchars($sql_err);
            $toast_type = 'error';
          }
        }
      }
    }

    if ($errors) { $toast_msg = implode(" ", $errors); $toast_type = 'error'; }
  }
}

// New CSRF
$_SESSION['csrf'] = bin2hex(random_bytes(16));


clearstatcache();

// ---------- Page meta + body-only render ----------
$title = 'Add Inventory';

// BODY
ob_start(); ?>
<main class="container">
  <h2 class="title">Add Inventory</h2>

  <?php if ($toast_msg): ?>
    <div class="toast <?php echo 'is-'.htmlspecialchars($toast_type); ?>"><?php echo htmlspecialchars($toast_msg); ?></div>
  <?php endif; ?>

  <section class="card card--pad">
    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>" />

      <div class="row">
        <label class="label" for="sku">SKU:</label>
        <input class="input" list="sku_list" id="sku" name="sku" placeholder="Select or type SKU" required />
        <datalist id="sku_list">
          <?php foreach ($skus as $s): ?>
            <option value="<?php echo htmlspecialchars($s['sku_num']); ?>"></option>
          <?php endforeach; ?>
        </datalist>

        <label class="label" for="quantity">Quantity:</label>
        <input class="input" id="quantity" name="quantity" type="number" step="1" min="1" placeholder="e.g. 10" required />
      </div>

      <div class="divider"></div>

      <!-- Hidden inputs reflect the selected location -->
      <input type="hidden" name="row_code"   id="row_code_input" />
      <input type="hidden" name="bay_num"    id="bay_num_input" />
      <input type="hidden" name="level_code" id="level_code_input" />
      <input type="hidden" name="side"       id="side_input" />

      <p class="text-muted" style="margin:8px 0 12px;">
  Selected: <strong id="selPath">—</strong>
</p>

<div id="locationSelectors" aria-label="Location pickers">

        <div class="group-label">Row</div>
<div id="rowButtons" class="button-group" role="group" aria-label="Row"></div>

<div class="group-label">Bay</div>
<div id="bayButtons" class="button-group" role="group" aria-label="Bay"></div>

<div class="group-label">Level</div>
<div id="levelButtons" class="button-group" role="group" aria-label="Level"></div>

<div class="group-label">Side</div>
<div id="sideButtons" class="button-group" role="group" aria-label="Side"></div>

      </div>

      <p class="help">Tip: buttons are blue by default and turn gray when selected. Sides auto-disable if a row doesn’t allow them.</p>

<div class="row actions">
  <button class="btn btn-outline" type="button" id="clearSel">Clear</button>
  <button class="btn btn--primary" type="submit">Add Inventory</button>
</div>

    </form>
  </section>

 <?php
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$locations_json = json_encode($locations, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

$footer_js = <<<HTML
<script>
// ===== Inline UI builder for Place =====
(function () {
  const LOCS = Array.isArray($locations_json) ? $locations_json : [];

  // Elements
  const rowWrap   = document.getElementById('rowButtons');
  const bayWrap   = document.getElementById('bayButtons');
  const levelWrap = document.getElementById('levelButtons');
  const sideWrap  = document.getElementById('sideButtons');

  const inRow    = document.getElementById('row_code_input');
  const inBay    = document.getElementById('bay_num_input');
  const inLevel  = document.getElementById('level_code_input');
  const inSide   = document.getElementById('side_input');

  if (!rowWrap || !bayWrap || !levelWrap || !sideWrap) return;

  // Helpers
  const uniq = (arr) => [...new Set(arr)];
  const clear = (el) => { while (el.firstChild) el.removeChild(el.firstChild); };
  const mkBtn = (label, value, onClick, disabled=false) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.dataset.value = value;
    b.className = disabled ? 'btn btn--ghost' : 'btn btn--primary';
    b.disabled = !!disabled;
    if (!disabled) b.addEventListener('click', onClick);
    return b;
  };
  const selectToggle = (wrap, value) => {
    wrap.querySelectorAll('button').forEach(b => {
      const selected = b.dataset.value === value;
      b.classList.toggle('btn--ghost', selected);
      b.classList.toggle('btn--primary', !selected);
    });
  };

  // State
  let selRow = null, selBay = null, selLevel = null, selSide = null;

  // Builders
  function buildRows() {
    clear(rowWrap);
    const rows = uniq(LOCS.map(l => l.row_code));
    rows.forEach(r => rowWrap.appendChild(mkBtn(r, r, () => {
      selRow = r; inRow.value = r;
      selectToggle(rowWrap, r);
      selBay = selLevel = selSide = null;
      inBay.value = inLevel.value = inSide.value = '';
      buildBays(); clear(levelWrap); clear(sideWrap);
    })));
  }

  function buildBays() {
    clear(bayWrap);
    if (!selRow) return;
    const bays = uniq(LOCS.filter(l => l.row_code === selRow).map(l => l.bay_num));
    bays.forEach(b => bayWrap.appendChild(mkBtn(b, b, () => {
      selBay = b; inBay.value = b;
      selectToggle(bayWrap, b);
      selLevel = selSide = null;
      inLevel.value = inSide.value = '';
      buildLevels(); clear(sideWrap);
    })));
  }

  function buildLevels() {
    clear(levelWrap);
    if (!selRow || !selBay) return;
    const levels = uniq(LOCS.filter(l => l.row_code === selRow && l.bay_num === selBay).map(l => l.level_code));
    levels.forEach(L => levelWrap.appendChild(mkBtn(L, L, () => {
      selLevel = L; inLevel.value = L;
      selectToggle(levelWrap, L);
      selSide = null; inSide.value = '';
      buildSides();
    })));
  }

  function buildSides() {
    clear(sideWrap);
    if (!selRow || !selBay || !selLevel) return;

    // Sides available from DB for this exact (row,bay,level)
    let sides = uniq(LOCS
      .filter(l => l.row_code === selRow && l.bay_num === selBay && l.level_code === selLevel)
      .map(l => l.side)); // 'Front' / 'Back'

    // UX safeguard: if row is R11, do not allow "Back" even if present
    if (selRow === 'R11') {
      sides = sides.filter(s => s !== 'Back');
      // Optionally show a disabled Back button:
      const backBtn = mkBtn('Back', 'Back', () => {}, true);
      sideWrap.appendChild(backBtn);
    }

    sides.forEach(s => sideWrap.appendChild(mkBtn(s, s, () => {
      selSide = s; inSide.value = s;
      selectToggle(sideWrap, s);
    })));
  }

  // Init
  buildRows();
})();
</script>
HTML;

include __DIR__ . '/templates/layout.php';


