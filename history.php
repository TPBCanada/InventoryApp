<?php
// dev/history.php — Movement ledger (location-select dropdowns)
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';

// ---------- auth ----------
if (!isset($_SESSION['username'])) {
  header("Location: login.php");
  exit;
}

$username = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];

date_default_timezone_set('America/Toronto');
$title = 'Movement Ledger';

// ---------- helpers ----------
function qs_with(array $extra): string {
  $base = $_GET;
  foreach ($extra as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  // Ensure 'page' is removed if we are just changing filters, so it defaults to 1
  if (!isset($extra['page'])) {
      unset($base['page']);
  }
  return http_build_query($base);
}

// ---------- inputs (location only) ----------
// Changed input names to match the select dropdowns in the new UI
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

// ---------- load locations for location-select dropdowns (REPLACED) ----------
// Build nested data structure for dynamic dropdowns
$rows_list = []; // Renamed to avoid collision with $rows (results)
$baysByRow = [];
$levelsByRow = [];
$sidesByRow = [];

try {
  $q = "
    SELECT row_code, bay_num, level_code, side
    FROM location
    ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side
  ";
  if ($res = $conn->query($q)) {
    while ($r = $res->fetch_assoc()) {
      $row = $r['row_code'];
      $bay = $r['bay_num'];
      $lvl = $r['level_code'];
      $side = $r['side'];

      if (!in_array($row, $rows_list, true))
        $rows_list[] = $row;
      
      $baysByRow[$row] = $baysByRow[$row] ?? [];
      $levelsByRow[$row] = $levelsByRow[$row] ?? [];
      $levelsByRow[$row][$bay] = $levelsByRow[$row][$bay] ?? [];
      $sidesByRow[$row] = $sidesByRow[$row] ?? [];
      $sidesByRow[$row][$bay] = $sidesByRow[$row][$bay] ?? [];
      $sidesByRow[$row][$bay][$lvl] = $sidesByRow[$row][$bay][$lvl] ?? [];

      if (!in_array($bay, $baysByRow[$row], true))
        $baysByRow[$row][] = $bay;
      if (!in_array($lvl, $levelsByRow[$row][$bay], true))
        $levelsByRow[$row][$bay][] = $lvl;
      if (!in_array($side, $sidesByRow[$row][$bay][$lvl], true))
        $sidesByRow[$row][$bay][$lvl][] = $side;
    }
    $res->free();
  }
} catch (\Throwable $_) {
  // non-fatal
}

// ---------- WHERE + params (location only) ----------
$where  = [];
$params = [];
$types  = '';

if ($row_code !== '')    { $where[] = 'l.row_code    = ?'; $params[] = $row_code;    $types .= 's'; }
if ($bay_num !== '')     { $where[] = 'l.bay_num     = ?'; $params[] = $bay_num;     $types .= 's'; }
if ($level_code !== '') { $where[] = 'l.level_code = ?'; $params[] = $level_code; $types .= 's'; }
if ($side !== '')        { $where[] = 'l.side        = ?'; $params[] = $side;        $types .= 's'; }

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// --- Queries for Movements (unchanged from original) ---

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
      s.`desc`    AS sku_desc,
      l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code,'-',l.bay_num,'-',l.level_code,'-',l.side) AS location_code,
      CASE
        WHEN im.movement_type = 'IN'      THEN  im.quantity_change
        WHEN im.movement_type = 'OUT'     THEN -im.quantity_change
        WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
        ELSE 0
      END AS delta
    FROM inventory_movements im
    INNER JOIN sku       s ON s.id = im.sku_id
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
  $types_win    = $types . 'ii';
  $params_win    = $params;
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
      s.`desc`    AS sku_desc,
      CONCAT(l.row_code,'-',l.bay_num,'-',l.level_code,'-',l.side) AS location_code,
      im.movement_type,
      CASE
        WHEN im.movement_type = 'IN'      THEN  im.quantity_change
        WHEN im.movement_type = 'OUT'     THEN -im.quantity_change
        WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
        ELSE 0
      END AS signed_delta,
      im.reference,
      CAST(im.user_id AS CHAR) AS user_name
    FROM inventory_movements im
    INNER JOIN sku       s ON s.id = im.sku_id
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
  INNER JOIN sku       s ON s.id = im.sku_id
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

// ---------- view (MODIFIED LOCATION UI) ----------
ob_start();
?>
<h2 class="title">Movement Ledger</h2>

<div class="card card--pad">
  <form class="form" method="get" action="">
  <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
    <div style="flex-basis:100%;">
      <label class="label">Filter by Location</label>
      <div class="grid grid-4" style="margin-top:4px;">
        <div>
          <label for="row_code_select" class="label">Row</label>
          <select id="row_code_select" name="row_code" class="input">
            <option value="">— Any —</option>
            <?php foreach ($rows_list as $r): ?>
                <option value="<?= htmlspecialchars($r) ?>" <?= $r === $row_code ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r) ?>
                </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="bay_num_select" class="label">Bay</label>
          <select id="bay_num_select" name="bay_num" class="input" <?= $row_code === '' ? 'disabled' : '' ?>>
            <option value="">— Any —</option>
            </select>
        </div>
        <div>
          <label for="level_code_select" class="label">Level</label>
          <select id="level_code_select" name="level_code" class="input" <?= $bay_num === '' ? 'disabled' : '' ?>>
            <option value="">— Any —</option>
            </select>
        </div>
        <div>
          <label for="side_select" class="label">Side</label>
          <select id="side_select" name="side" class="input" <?= $level_code === '' ? 'disabled' : '' ?>>
            <option value="">— Any —</option>
            </select>
        </div>
      </div>
    </div>
  </div>

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
          <th>&Delta;Qty</th>
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
              <td data-label="&Delta;Qty" class="qty"><?= (int)($r['signed_delta'] ?? 0) ?></td>
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

// ---- Footer JS injection for dynamic location selection ----
$boot_data = [
    'rows' => $rows_list, // All unique row codes
    'baysByRow' => $baysByRow, // Bays nested under rows
    'levelsByRow' => $levelsByRow, // Levels nested under bays
    'sidesByRow' => $sidesByRow, // Sides nested under levels
    'current' => [
        'row' => $row_code,
        'bay' => $bay_num,
        'level' => $level_code,
        'side' => $side,
    ]
];

// JSON encode the data for the JavaScript
try {
  $boot_json = json_encode($boot_data, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
  $footer_js = '<script id="history-location-data" type="application/json">' . $boot_json . '</script>';
} catch (Throwable $e) {
  error_log('[history.php] boot json encode failed: ' . $e->getMessage());
  $footer_js = '<script id="history-location-data" type="application/json">{"rows":[]}</script>';
}

// Inject the custom JavaScript for the dropdown logic
$footer_js .= <<<HTML
<script>
(function() {
    const dataEl = document.getElementById('history-location-data');
    if (!dataEl) return;

    let bootData;
    try {
        bootData = JSON.parse(dataEl.textContent);
    } catch (e) {
        console.error("Failed to parse location data:", e);
        return;
    }

    const { rows, baysByRow, levelsByRow, sidesByRow, current } = bootData;
    
    // Select elements
    const s_row = document.getElementById('row_code_select');
    const s_bay = document.getElementById('bay_num_select');
    const s_lvl = document.getElementById('level_code_select');
    const s_side = document.getElementById('side_select');

    // Helper to populate a select box
    function populate(selectEl, values, selectedValue) {
        const currentVal = selectEl.value; // Save the selected value before clearing
        selectEl.innerHTML = '<option value="">— Any —</option>';
        if (values && values.length > 0) {
            selectEl.disabled = false;
            values.forEach(val => {
                const opt = document.createElement('option');
                opt.value = opt.textContent = val;
                // Pre-select if it matches the current GET param value
                if (val === selectedValue) {
                    opt.selected = true;
                }
                selectEl.appendChild(opt);
            });
        } else {
            selectEl.disabled = true;
        }
    }

    // Update Bay dropdown based on Row selection
    function updateBays() {
        const rowVal = s_row.value;
        const bays = baysByRow[rowVal] || [];
        populate(s_bay, bays, current.bay);
        if (!rowVal) {
            // Reset and disable subsequent dropdowns if the main filter is cleared
            s_bay.value = s_lvl.value = s_side.value = '';
            s_lvl.disabled = true;
            s_side.disabled = true;
        }
        updateLevels(); // Cascade the update
    }

    // Update Level dropdown based on Bay selection
    function updateLevels() {
        const rowVal = s_row.value;
        const bayVal = s_bay.value;
        const levels = levelsByRow[rowVal] ? levelsByRow[rowVal][bayVal] || [] : [];
        populate(s_lvl, levels, current.level);
        if (!bayVal) {
             s_lvl.value = s_side.value = '';
             s_side.disabled = true;
        }
        updateSides(); // Cascade the update
    }

    // Update Side dropdown based on Level selection
    function updateSides() {
        const rowVal = s_row.value;
        const bayVal = s_bay.value;
        const lvlVal = s_lvl.value;
        const sides = sidesByRow[rowVal] && sidesByRow[rowVal][bayVal] ? sidesByRow[rowVal][bayVal][lvlVal] || [] : [];
        populate(s_side, sides, current.side);
    }

    // Attach event listeners for cascading updates
    s_row.addEventListener('change', updateBays);
    s_bay.addEventListener('change', updateLevels);
    s_lvl.addEventListener('change', updateSides);

    // Initial population on page load (uses current URL parameters)
    updateBays();
    updateLevels();
    updateSides();

    // The form will naturally submit with the selected values.
})();
</script>
HTML;

include __DIR__ . '/templates/layout.php';