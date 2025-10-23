<?php
// dev/history.php — Movement ledger (location-buttons only; no SKU/date filters)
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';

// ---------- auth ----------
if (!isset($_SESSION['username'])) {
  header("Location: login.php");
  exit;
}

date_default_timezone_set('America/Toronto');
$title = 'Movement Ledger';

// ---------- helpers ----------
function qs_with(array $extra): string {
  $base = $_GET;
  foreach ($extra as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

// ---------- inputs (location only) ----------
$row_code    = trim($_GET['row_code'] ?? '');
$bay_num     = trim($_GET['bay_num'] ?? '');
$level_code  = trim($_GET['level_code'] ?? '');
$side        = trim($_GET['side'] ?? '');

$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = max(1, min(200, (int)($_GET['limit'] ?? 100)));
$offset      = ($page - 1) * $limit;

$error       = '';
$rows        = [];
$total_rows  = 0;
$used_window = false;

// ---------- load locations for location-buttons.js ----------
$locations = [];
try {
  $q = "
    SELECT row_code, bay_num, level_code, side
    FROM location
    ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side
  ";
  if ($res = $conn->query($q)) {
    while ($r = $res->fetch_assoc()) {
      $locations[] = [
        'row_code'   => (string)$r['row_code'],
        'bay_num'    => (string)$r['bay_num'],
        'level_code' => (string)$r['level_code'],
        'side'       => (string)$r['side'],
      ];
    }
    $res->free();
  }
} catch (\Throwable $_) {
  // non-fatal; UI can still show the page
}

// ---------- WHERE + params (location only) ----------
$where  = [];
$params = [];
$types  = '';

if ($row_code !== '')   { $where[] = 'l.row_code   = ?'; $params[] = $row_code;   $types .= 's'; }
if ($bay_num !== '')    { $where[] = 'l.bay_num    = ?'; $params[] = $bay_num;    $types .= 's'; }
if ($level_code !== '') { $where[] = 'l.level_code = ?'; $params[] = $level_code; $types .= 's'; }
if ($side !== '')       { $where[] = 'l.side       = ?'; $params[] = $side;       $types .= 's'; }

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---------- try MySQL 8 window function (running balance per (sku_id, loc_id)) ----------
$sql_win = "
  WITH base AS (
    SELECT
      im.id,
      im.created_at,
      im.movement_type,
      im.quantity_change,
      im.reference,
      im.user_id,
      im.sku_id,
      im.loc_id AS loc_id,  -- single name to avoid dupes
      s.sku_num AS sku,
      s.`desc`   AS sku_desc,
      l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code,'-',l.bay_num,'-',l.level_code,'-',l.side) AS location_code,
      CASE
        WHEN im.movement_type = 'IN'         THEN  im.quantity_change
        WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
        WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
        ELSE 0
      END AS delta
    FROM inventory_movements im
    INNER JOIN sku      s ON s.id = im.sku_id
    INNER JOIN location l ON l.id = im.loc_id
    $where_sql
  )
  SELECT
    id, created_at, sku, sku_desc, location_code,
    movement_type,
    (CASE WHEN movement_type='OUT' THEN -ABS(delta) ELSE delta END) AS signed_delta,
    reference,
    CAST(user_id AS CHAR) AS user_name,
    SUM(delta) OVER (
      PARTITION BY sku_id, loc_id
      ORDER BY created_at ASC, id ASC
      ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS running_balance
  FROM base
  ORDER BY created_at DESC, id DESC
  LIMIT ? OFFSET ?
";

$ok = false;
if ($stmt = $conn->prepare($sql_win)) {
  $used_window = true;
  $types_win   = $types . 'ii';
  $params_win  = $params;
  $params_win[] = $limit;
  $params_win[] = $offset;
  $stmt->bind_param($types_win, ...$params_win);
  try {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $r['created_at']      = $r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : null;
      $r['signed_delta']    = (int)$r['signed_delta'];
      $r['running_balance'] = (int)$r['running_balance'];
      $rows[] = $r;
    }
    $stmt->close();
    $ok = true;
  } catch (\Throwable $_) {
    $stmt->close();
    $used_window = false; // fallback below
  }
}

// ---------- fallback (no running column) ----------
if (!$ok) {
  $sql_fb = "
    SELECT
      im.id,
      im.created_at,
      s.sku_num AS sku,
      s.`desc`   AS sku_desc,
      CONCAT(l.row_code,'-',l.bay_num,'-',l.level_code,'-',l.side) AS location_code,
      im.movement_type,
      CASE
        WHEN im.movement_type = 'IN'         THEN  im.quantity_change
        WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
        WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
        ELSE 0
      END AS signed_delta,
      im.reference,
      CAST(im.user_id AS CHAR) AS user_name
    FROM inventory_movements im
    INNER JOIN sku      s ON s.id = im.sku_id
    INNER JOIN location l ON l.id = im.loc_id
    $where_sql
    ORDER BY im.created_at DESC, im.id DESC
    LIMIT ? OFFSET ?
  ";
  if ($stmt = $conn->prepare($sql_fb)) {
    $types_fb  = $types . 'ii';
    $params_fb = $params;
    $params_fb[] = $limit;
    $params_fb[] = $offset;
    $stmt->bind_param($types_fb, ...$params_fb);
    try {
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        $r['created_at']   = $r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : null;
        $r['signed_delta'] = (int)$r['signed_delta'];
        $rows[] = $r;
      }
      $stmt->close();
    } catch (\Throwable $_) {
      $stmt->close();
      $error = 'Database error (rows): ' . $conn->error;
      $rows = [];
    }
  } else {
    $error = 'Database error (prepare): ' . $conn->error;
  }
}

// ---------- count for pagination ----------
$count_sql = "
  SELECT COUNT(*) AS c
  FROM inventory_movements im
  INNER JOIN sku      s ON s.id = im.sku_id
  INNER JOIN location l ON l.id = im.loc_id
  $where_sql
";
if ($c = $conn->prepare($count_sql)) {
  if ($types !== '') $c->bind_param($types, ...$params);
  $c->execute();
  $cres = $c->get_result();
  $total_rows = (int)($cres->fetch_assoc()['c'] ?? 0);
  $c->close();
}

$pages = max(1, (int)ceil($total_rows / $limit));

// ---------- view ----------
ob_start();
?>
<h2 class="title">Movement Ledger</h2>

<div class="card card--pad">
  <form class="form" method="get" action="">
  <!-- LOCATION SELECTOR ON TOP -->
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <div style="flex-basis:100%;">
      <label class="label">Location</label>
      <div id="locBtnWrap" class="card" style="padding:8px">
        <div id="locSelected" style="margin-bottom:6px; font-size:14px; opacity:.8">
          <?php
            $selCode = ($row_code && $bay_num && $level_code && $side)
              ? ($row_code.'-'.$bay_num.'-'.$level_code.'-'.$side)
              : '— none —';
          ?>
          Selected: <b id="locCodeText"><?= htmlspecialchars($selCode) ?></b>
          <button type="button" id="locClearBtn" class="btn btn--ghost" style="margin-left:8px; padding:2px 8px">Clear</button>
        </div>

        <!-- Button rows populated by js/location-buttons.js -->
        <div id="rowButtons"   style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px"></div>
        <div id="bayButtons"   style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px"></div>
        <div id="levelButtons" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px"></div>
        <div id="sideButtons"  style="display:flex; flex-wrap:wrap; gap:6px"></div>

        <!-- Hidden inputs updated by the buttons -->
        <input type="hidden" id="row_code_input"   name="row_code"   value="<?= htmlspecialchars($row_code) ?>">
        <input type="hidden" id="bay_num_input"    name="bay_num"    value="<?= htmlspecialchars($bay_num) ?>">
        <input type="hidden" id="level_code_input" name="level_code" value="<?= htmlspecialchars($level_code) ?>">
        <input type="hidden" id="side_input"       name="side"       value="<?= htmlspecialchars($side) ?>">
      </div>
    </div>
  </div>

  <!-- CONTROLS UNDERNEATH -->
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:end; margin-top:10px;">
    <div>
      <label class="label">Rows</label>
      <input class="input" type="number" min="1" max="200" name="limit" value="<?= (int)$limit ?>" />
    </div>
    <div style="display:flex; gap:8px;">
      <button class="btn btn--primary" type="submit">Search</button>
      <a class="btn btn--ghost" href="history.php">Reset</a>
      <a class="btn btn--ghost" href="export_csv.php?<?= qs_with([]) ?>">Export CSV</a>
    </div>
  </div>

  <?php if ($error): ?>
    <p class="error" style="margin-top:8px"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>
</form>

</div>

<div class="card card--pad">
  <div class="meta" style="display:flex; gap:16px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
    <div>Total matches: <b><?= (int)$total_rows ?></b></div>
    <div>Page <b><?= (int)$page ?></b> of <b><?= (int)$pages ?></b></div>
    <?php if ($used_window): ?>
      <div><em>Running balance enabled (MySQL 8+)</em></div>
    <?php else: ?>
      <div><em>Running balance unavailable (fallback)</em></div>
    <?php endif; ?>
  </div>

  <div class="table-wrap table-responsive">
    <table class="table table-stack">
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>SKU</th>
          <th>Description</th>
          <th>Location Code</th>
          <th>ΔQty</th>
          <th>Type</th>
          <th>Reference</th>
          <th>User</th>
          <?php if ($used_window): ?><th>Running</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="<?= $used_window ? 9 : 8 ?>" class="empty">No results.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td data-label="Timestamp"><?= htmlspecialchars($r['created_at'] ?? '') ?></td>
              <td data-label="SKU"><?= htmlspecialchars($r['sku'] ?? '') ?></td>
              <td data-label="Description"><?= htmlspecialchars($r['sku_desc'] ?? '') ?></td>
              <td data-label="Location Code"><?= htmlspecialchars($r['location_code'] ?? '') ?></td>
              <td data-label="ΔQty" class="qty"><?= (int)($r['signed_delta'] ?? 0) ?></td>
              <td data-label="Type"><?= htmlspecialchars($r['movement_type'] ?? '') ?></td>
              <td data-label="Reference"><?= htmlspecialchars($r['reference'] ?? '') ?></td>
              <td data-label="User"><?= htmlspecialchars($r['user_name'] ?? '') ?></td>
              <?php if ($used_window): ?>
                <td data-label="Running" class="qty"><b><?= (int)($r['running_balance'] ?? 0) ?></b></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <?php
      $base = [
        'row_code' => $row_code, 'bay_num' => $bay_num,
        'level_code' => $level_code, 'side' => $side,
        'limit' => $limit
      ];
      $prev = max(1, $page - 1);
      $next = min($pages, $page + 1);
    ?>
    <nav class="pager" style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
      <a class="btn btn--ghost" href="?<?= http_build_query($base + ['page' => 1]) ?>">« First</a>
      <a class="btn btn--ghost" href="?<?= http_build_query($base + ['page' => $prev]) ?>">‹ Prev</a>
      <span class="btn btn--ghost" style="pointer-events:none">Page <?= (int)$page ?> / <?= (int)$pages ?></span>
      <a class="btn btn--ghost" href="?<?= http_build_query($base + ['page' => $next]) ?>">Next ›</a>
      <a class="btn btn--ghost" href="?<?= http_build_query($base + ['page' => $pages]) ?>">Last »</a>
    </nav>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();

// ---- Footer JS injection for location-buttons.js ----
$loc_json = json_encode($locations, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$js_fs    = __DIR__ . '/js/location-buttons.js';
$ver      = is_file($js_fs) ? filemtime($js_fs) : time();
$base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$src_url  = htmlspecialchars($base_url . '/js/location-buttons.js?v=' . $ver, ENT_QUOTES, 'UTF-8');

$footer_js = <<<HTML
<script>
// Provide data for the location-buttons
window.PLACE_LOCATIONS = $loc_json;
</script>
<!-- 1) Load buttons library first -->
<script defer src="$src_url"></script>
<!-- 2) Then run our stabilizer AFTER it has initialized -->
<script>
(function(){
  // Read params safely (works after reloads)
  function getParam(name){
    var m = new RegExp('[?&]'+name+'=([^&]*)').exec(location.search);
    return m ? decodeURIComponent(m[1].replace(/\\+/g,' ')) : '';
  }

  function applyPrefill(){
    var r = document.getElementById('row_code_input');
    var b = document.getElementById('bay_num_input');
    var l = document.getElementById('level_code_input');
    var s = document.getElementById('side_input');

    // Never overwrite non-empty inputs; if empty, try URL params
    if (r && !r.value) r.value = getParam('row_code') || r.value;
    if (b && !b.value) b.value = getParam('bay_num') || b.value;
    if (l && !l.value) l.value = getParam('level_code') || l.value;
    if (s && !s.value) s.value = getParam('side') || s.value;

    updateLabel();
  }

  function updateLabel(){
    var r = document.getElementById('row_code_input')?.value || '';
    var b = document.getElementById('bay_num_input')?.value || '';
    var l = document.getElementById('level_code_input')?.value || '';
    var s = document.getElementById('side_input')?.value || '';
    var code = (r && b && l && s) ? (r+'-'+b+'-'+l+'-'+s) : '— none —';
    var el = document.getElementById('locCodeText');
    if (el) el.textContent = code;
  }

  // Bind listeners to keep label sticky
  document.addEventListener('change', updateLabel, true);
  document.addEventListener('click',  updateLabel, true);

  // Run once DOM is ready, then once more on the next tick to win any race
  window.addEventListener('DOMContentLoaded', function(){
    // First pass: restore from URL/hidden inputs
    applyPrefill();
    // Second pass after location-buttons likely finished any async work
    setTimeout(applyPrefill, 0);
  });

  // Clear button logic (doesn't flicker back)
  window.addEventListener('DOMContentLoaded', function(){
    var clr = document.getElementById('locClearBtn');
    if (!clr) return;
    clr.addEventListener('click', function(){
      ['row_code_input','bay_num_input','level_code_input','side_input'].forEach(function(id){
        var el = document.getElementById(id); if (el) el.value = '';
      });
      updateLabel();
    });
  });
})();
</script>
HTML;


include __DIR__ . '/templates/layout.php';
