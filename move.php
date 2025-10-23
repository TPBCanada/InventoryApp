<?php
// dev/move.php — Place (IN) and Take (OUT) combined
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

// ---------- DB guard ----------
if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }

// Make sure we have a CSRF token
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// -------------------- AJAX: location info --------------------
if (($_GET['ajax'] ?? '') === 'locinfo') {
  header('Content-Type: text/html; charset=UTF-8');

  // Optional: CSRF check for AJAX (recommended)
  $csrf_hdr = $_SERVER['HTTP_X_CSRF'] ?? '';
  if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf_hdr)) {
    http_response_code(403);
    echo '<div class="card card--pad"><div class="toast is-error">Security token invalid.</div></div>';
    exit;
  }

  $row_code   = trim($_GET['row'] ?? '');
  $bay_num    = trim($_GET['bay'] ?? '');
  $level_code = trim($_GET['level'] ?? '');
  $side       = trim($_GET['side'] ?? '');

  if ($row_code === '' || $bay_num === '' || $level_code === '' || $side === '') {
    http_response_code(400);
    echo '<div class="card card--pad"><div class="toast is-error">Incomplete location.</div></div>';
    exit;
  }

  // Find loc_id
  $loc_id = null;
  if ($st = mysqli_prepare($conn, "SELECT loc_id FROM location WHERE row_code=? AND bay_num=? AND level_code=? AND side=?")) {
    mysqli_stmt_bind_param($st, "ssss", $row_code, $bay_num, $level_code, $side);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $loc_id);
    mysqli_stmt_fetch($st);
    mysqli_stmt_close($st);
  }
  if (!$loc_id) {
    echo '<div class="card card--pad"><div class="toast is-error">Location not found.</div></div>';
    exit;
  }

  // --- On-hand by SKU at this location ---
  $onhand_rows = [];
  $sql_onhand =
    "SELECT s.sku_num,
            COALESCE(SUM(CASE
                WHEN im.movement_type='IN'  THEN im.quantity_change
                WHEN im.movement_type='OUT' THEN -im.quantity_change
                ELSE 0 END),0) AS on_hand
     FROM inventory_movements im
     JOIN sku s ON s.id = im.sku_id
     WHERE im.loc_id = ?
     GROUP BY s.sku_num
     HAVING on_hand <> 0
     ORDER BY on_hand DESC, s.sku_num ASC
     LIMIT 200";
if ($st = mysqli_prepare($conn, $sql_mov)) {
  mysqli_stmt_bind_param($st, "i", $loc_id);
  mysqli_stmt_execute($st);
  mysqli_stmt_bind_result($st, $sku_num_m, $qty_m, $move_m, $user_m, $created_m);
  while (mysqli_stmt_fetch($st)) {
    $mov_rows[] = [
      'sku_num'         => $sku_num_m,
      'quantity_change' => (int)$qty_m,
      'movement_type'   => $move_m,
      'user_name'       => $user_m,
      'created_at'      => $created_m
    ];
  }
  mysqli_stmt_close($st);
}


  // --- Latest movements at this location ---
  $mov_rows = [];
  $sql_mov =
    "SELECT s.sku_num, im.quantity_change, im.movement_type,
            COALESCE(u.username, CAST(im.user_id AS CHAR)) AS user_name,
            im.created_at
     FROM inventory_movements im
     LEFT JOIN sku s   ON s.id = im.sku_id
     LEFT JOIN users u ON u.id = im.user_id
     WHERE im.loc_id = ?
     ORDER BY im.created_at DESC
     LIMIT 10";
  if ($st = mysqli_prepare($conn, $sql_mov)) {
    mysqli_stmt_bind_param($st, "i", $loc_id);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    while ($r = mysqli_fetch_assoc($rs)) $mov_rows[] = $r;
    mysqli_stmt_close($st);
  }

  // --- Render HTML fragment ---
  ?>
  <section class="card card--pad" id="locSnapshot">
    <h3 style="margin:0 0 10px;">Location Snapshot: <?=htmlspecialchars("$row_code-$bay_num-$level_code-$side")?></h3>

    <div class="grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
      <div>
        <h4 style="margin:0 0 8px;">On-hand by SKU</h4>
        <div class="table-responsive">
          <table class="table">
            <thead><tr><th>SKU</th><th style="text-align:right;">On-hand</th></tr></thead>
            <tbody>
              <?php if ($onhand_rows): foreach ($onhand_rows as $r): ?>
                <tr>
                  <td><?=htmlspecialchars($r['sku_num'])?></td>
                  <td style="text-align:right;"><?=htmlspecialchars((string)$r['on_hand'])?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="2">No stock currently at this location.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div>
        <h4 style="margin:0 0 8px;">Latest Movements</h4>
        <div class="table-responsive">
          <table class="table">
            <thead><tr><th>Date</th><th>SKU</th><th>Move</th><th style="text-align:right;">Qty</th><th>User</th></tr></thead>
            <tbody>
              <?php if ($mov_rows): foreach ($mov_rows as $m):
                $sign = ($m['movement_type']==='OUT')?'-':'+';
              ?>
                <tr>
                  <td><?=htmlspecialchars($m['created_at'])?></td>
                  <td><?=htmlspecialchars($m['sku_num'])?></td>
                  <td><?=htmlspecialchars($m['movement_type'])?></td>
                  <td style="text-align:right;"><?=htmlspecialchars($sign . abs((int)$m['quantity_change']))?></td>
                  <td><?=htmlspecialchars($m['user_name'] ?? 'System/API')?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="5">No recent movements.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
  <?php
  exit;
}


// ---------- Data: master lists ----------
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

// Build row -> allowed sides
$row_allowed = [];
foreach ($locations as $l) {
  $r = (string)$l['row_code'];
  $s = (string)$l['side'];
  if (!isset($row_allowed[$r])) $row_allowed[$r] = [];
  if (!in_array($s, $row_allowed[$r], true)) $row_allowed[$r][] = $s;
}

// ---------- POST handler ----------
$toast_msg=''; $toast_type='info';
$location_history=[]; $history_heading=''; $show_history=false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
    $toast_msg = "Security token invalid. Please refresh and try again.";
    $toast_type = 'error';
  } else {
    $op         = strtoupper(trim($_POST['op'] ?? ''));   // PLACE or TAKE
    $sku_num    = trim($_POST['sku'] ?? '');
    $row_code   = trim($_POST['row_code'] ?? '');
    $bay_num    = trim($_POST['bay_num'] ?? '');
    $level_code = trim($_POST['level_code'] ?? '');
    $side       = trim($_POST['side'] ?? '');
    $qty        = (int)($_POST['quantity'] ?? 0);

    $errors = [];
    if (!in_array($op, ['PLACE','TAKE'], true)) $errors[] = "Choose Place or Take.";
    if ($sku_num === '')     $errors[] = "SKU is required.";
    if ($row_code === '')    $errors[] = "Row is required.";
    if ($bay_num === '')     $errors[] = "Bay is required.";
    if ($level_code === '')  $errors[] = "Level is required.";
    if ($side === '')        $errors[] = "Side is required.";
    if ($qty <= 0)           $errors[] = "Quantity must be a positive number.";

    if (!$errors) {
      // Lookup sku_id
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
          if ($op === 'PLACE') {
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
              $toast_msg  = "Placed +{$qty} of SKU {$sku_num} to {$row_code}-{$bay_num}-{$level_code}-{$side}.";
              $toast_type = 'success';
            } else {
              $errors[] = "Error placing item: " . htmlspecialchars($sql_err);
            }

          } else { // TAKE
            // Transaction-safe OUT with app lock
            $lock_name = sprintf('invapp_sku_loc_%d_%d', $sku_id, $loc_id);
            $lock_rs = mysqli_query($conn, "SELECT GET_LOCK('" . mysqli_real_escape_string($conn, $lock_name) . "', 5) AS got_lock");
            $got_lock = $lock_rs && ($row = mysqli_fetch_assoc($lock_rs)) ? (int)$row['got_lock'] : 0;
            if (!$got_lock) {
              $errors[] = "Couldn't secure lock for this SKU/location. Please try again.";
            } else {
              mysqli_begin_transaction($conn, MYSQLI_TRANS_START_READ_WRITE);
              try {
                // Recompute on-hand inside txn and lock rows
                $on_hand = 0;
                $bal_stmt = mysqli_prepare(
                  $conn,
                  "SELECT COALESCE(SUM(
                     CASE WHEN movement_type='IN' THEN quantity_change
                          WHEN movement_type='OUT' THEN -quantity_change
                          ELSE 0 END
                   ),0) AS on_hand
                   FROM inventory_movements
                   WHERE sku_id=? AND loc_id=?
                   FOR UPDATE"
                );
                if (!$bal_stmt) throw new Exception("Failed to prepare on-hand query.");
                mysqli_stmt_bind_param($bal_stmt, "ii", $sku_id, $loc_id);
                mysqli_stmt_execute($bal_stmt);
                mysqli_stmt_bind_result($bal_stmt, $on_hand);
                mysqli_stmt_fetch($bal_stmt);
                mysqli_stmt_close($bal_stmt);

                if ($qty > $on_hand) {
                  throw new Exception("Insufficient quantity at this location. On hand: {$on_hand}, requested: {$qty}.");
                }

                // Insert OUT movement
                $movement_type = 'OUT';
                $ins_stmt = mysqli_prepare(
                  $conn,
                  "INSERT INTO inventory_movements (sku_id, loc_id, quantity_change, movement_type, user_id, created_at)
                   VALUES (?, ?, ?, ?, ?, NOW())"
                );
                if (!$ins_stmt) throw new Exception("Error preparing INSERT for OUT.");
                mysqli_stmt_bind_param($ins_stmt, "iiisi", $sku_id, $loc_id, $qty, $movement_type, $user_id);
                $ok = mysqli_stmt_execute($ins_stmt);
                $sql_err = mysqli_stmt_error($ins_stmt);
                mysqli_stmt_close($ins_stmt);
                if (!$ok) throw new Exception("Error removing item: " . htmlspecialchars($sql_err));

                mysqli_commit($conn);
                $toast_msg  = "Took -{$qty} of SKU {$sku_num} from {$row_code}-{$bay_num}-{$level_code}-{$side}.";
                $toast_type = 'success';
              } catch (Exception $e) {
                mysqli_rollback($conn);
                $errors[] = $e->getMessage();
              } finally {
                mysqli_query($conn, "DO RELEASE_LOCK('" . mysqli_real_escape_string($conn, $lock_name) . "')");
              }
            }
          }

          // Recent history regardless of op attempt (if we know loc_id)
          $hist_stmt = mysqli_prepare(
            $conn,
            "SELECT s.sku_num,
                    im.quantity_change,
                    im.movement_type,
                    COALESCE(u.username, CAST(im.user_id AS CHAR)) AS user_name,
                    im.created_at
             FROM inventory_movements im
             LEFT JOIN sku s   ON s.id = im.sku_id
             LEFT JOIN users u ON u.id = im.user_id
             WHERE im.loc_id = ?
             ORDER BY im.created_at DESC
             LIMIT 25"
          );
          if ($hist_stmt) {
            mysqli_stmt_bind_param($hist_stmt, "i", $loc_id);
          mysqli_stmt_execute($hist_stmt);
            mysqli_stmt_bind_result(
              $hist_stmt,
              $h_sku, $h_qty, $h_move, $h_user, $h_created
            );
            while (mysqli_stmt_fetch($hist_stmt)) {
              $location_history[] = [
                'sku_num'         => $h_sku,
                'quantity_change' => (int)$h_qty,
                'movement_type'   => $h_move,
                'user_name'       => $h_user,
                'created_at'      => $h_created
              ];
            }
            mysqli_stmt_close($hist_stmt);

        }
      }
    }

    if ($errors) { $toast_msg = implode(" ", $errors); $toast_type = 'error'; }
  }

  // Rotate CSRF after handling POST
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
} else {
  // GET: fresh CSRF
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// ---------- Page body ----------
$title = 'Move Inventory (Place / Take)';
$locations_json   = json_encode($locations, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$row_allowed_json = json_encode($row_allowed, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

ob_start(); ?>
<main class="container">
  <h2 class="title">Move Inventory</h2>

  <?php if ($toast_msg): ?>
    <div class="toast <?='is-'.htmlspecialchars($toast_type)?>"><?=htmlspecialchars($toast_msg)?></div>
  <?php endif; ?>

  <section class="card card--pad">
    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>" />

      <!-- Mode toggle (Place vs Take) -->
      <div class="row" style="align-items:center; gap:12px;">
        <span class="label" style="min-width:90px;">Action:</span>
        <div class="segmented" role="tablist" aria-label="Action">
          <button type="submit" name="op" value="PLACE" class="btn btn--primary" aria-selected="false">Place</button>
          <button type="submit" name="op" value="TAKE"  class="btn btn-outline" aria-selected="false">Take</button>
        </div>
      </div>

      <div class="row" style="margin-top:8px;">
        <label class="label" for="sku">SKU:</label>
        <input class="input" list="sku_list" id="sku" name="sku" placeholder="Select or type SKU" required />
        <datalist id="sku_list">
          <?php foreach ($skus as $s): ?>
            <option value="<?=htmlspecialchars($s['sku_num'])?>"></option>
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

      <p class="text-muted" style="margin:8px 0 12px;">Selected: <strong id="selPath">—</strong></p>

      <!-- Inline location pickers (same 3D style as place.php) -->
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
        <!-- The primary action is chosen by clicking one of the top "Place" / "Take" buttons,
             but keep a default submit for accessibility (defaults to Place). -->
        <button class="btn btn--primary" type="submit" name="op" value="PLACE">Submit (Place)</button>
      </div>
    </form>
  </section>
  
  <div id="locInfoMount" style="margin-top:16px;"></div>


  <?php if ($show_history): ?>
    <section class="card card--pad" style="margin-top:16px;">
      <h3 class="title" style="margin-top:0; text-align:center;"><?=htmlspecialchars($history_heading)?></h3>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>SKU</th>
              <th>Movement</th>
              <th>Qty</th>
              <th>User</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($location_history as $h): ?>
            <?php
              $cls = strtolower($h['movement_type']);
              $sign = ($h['movement_type'] === 'OUT') ? '-' : '+';
              $qty_display = $sign . abs((int)$h['quantity_change']);
            ?>
            <tr>
              <td><?=htmlspecialchars($h['created_at'])?></td>
              <td><?=htmlspecialchars($h['sku_num'])?></td>
              <td><?=htmlspecialchars($h['movement_type'])?></td>
              <td class="<?=$cls?>"><?=htmlspecialchars($qty_display)?></td>
              <td><?=htmlspecialchars($h['user_name'] ?? 'System/API')?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</main>
<?php
$content = ob_get_clean();

// ---------- Footer assets (single include) ----------
$footer_js = <<<HTML
<style>
  #locationSelectors .button-group{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 12px}
  #locationSelectors .group-label{font-weight:700;margin-top:8px}
  .btn:disabled{opacity:.45;transform:none}
  .segmented .btn{margin-right:8px}
</style>
<script>
// boot data for the picker
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

    const mount   = document.getElementById('locInfoMount');

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

    // ---------- NEW: snapshot loader ----------
    let lastKey = '';
    let inflight = null;

    function tryLoadLocationCard(){
      const r = inRow.value, b = inBay.value, l = inLevel.value, s = inSide.value;
      const complete = r && b && l && s;
      if (!complete){
        if (mount) mount.innerHTML = '';
        lastKey = '';
        return;
      }
      const key = [r,b,l,s].join('|');
      if (key === lastKey) return; // avoid duplicate fetches
      lastKey = key;

      if (!mount) return;
      mount.innerHTML = '<section class="card card--pad"><div class="text-muted">Loading location snapshot…</div></section>';

      // Cancel previous request if any (best-effort)
      if (inflight && 'abort' in inflight) inflight.abort?.();
      const ctrl = new AbortController();
      inflight = ctrl;

      const params = new URLSearchParams({ ajax:'locinfo', row:r, bay:b, level:l, side:s });

      fetch(window.location.pathname + '?' + params.toString(), {
        method: 'GET',
        headers: { 'X-CSRF': '<?=htmlspecialchars($_SESSION['csrf'])?>' },
        signal: ctrl.signal
      })
      .then(res => res.text())
      .then(html => { if (mount) mount.innerHTML = html; })
      .catch(() => { if (mount) mount.innerHTML = '<section class="card card--pad"><div class="toast is-error">Failed to load location snapshot.</div></section>'; });
    }
    // ------------------------------------------

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

    // Build all buttons up front
    const rows   = uniq(LOCS.map(l=>String(l.row_code)));
    const bays   = uniq(LOCS.map(l=>String(l.bay_num)));
    const levels = uniq(LOCS.map(l=>String(l.level_code)));
    const sides  = uniq(LOCS.map(l=>String(l.side)));

    rows.forEach(v=>rowWrap.appendChild(btn(v,v,'row')));
    bays.forEach(v=>bayWrap.appendChild(btn(v,v,'bay')));
    levels.forEach(v=>levelWrap.appendChild(btn(v,v,'level')));
    sides.forEach(v=>sideWrap.appendChild(btn(v,v,'side')));

    function toggleGroup(wrap, validSet, hasSelection){
      qsa(wrap,'button').forEach(btn=>{
        const enable = hasSelection || validSet.has(btn.dataset.value);
        btn.disabled = !enable;
        btn.title = enable ? '' : 'Not available for current selection';
      });
    }

    function updateAvailability(){
      // Compute valid combos from LOCS and current selection
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

      // Enforce row->allowed sides rule from DB
      if (state.row){
        const allowed = (RULES[state.row] || []).map(s=>s.toLowerCase());
        if (allowed.length){
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
      tryLoadLocationCard(); // <-- NEW: fetch snapshot when complete
    }

    // Wire up clicks
    rowWrap.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>select('row',b.dataset.value)));
    bayWrap.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>select('bay',b.dataset.value)));
    levelWrap.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>select('level',b.dataset.value)));
    sideWrap.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>select('side',b.dataset.value)));

    if (clearBtn){
      clearBtn.addEventListener('click', ()=>{
        state.row = state.bay = state.level = state.side = null;
        setSelected(rowWrap,null); setSelected(bayWrap,null);
        setSelected(levelWrap,null); setSelected(sideWrap,null);
        setHidden(); updatePath(); updateAvailability();
        if (mount) mount.innerHTML = ''; // clear snapshot on reset
        lastKey = '';
      });
    }

    // First paint
    setHidden(); updatePath(); updateAvailability();
    tryLoadLocationCard(); // in case fields prefilled (rare)
  }

  if (document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded', init, { once:true });
  } else {
    init();
  }
})();
</script>

HTML;

// Layout include (expects $title, $content, $footer_js)
include __DIR__ . '/templates/layout.php';
