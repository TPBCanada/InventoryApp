<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
include __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/../utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/../templates/access_control.php';

if (!isset($_SESSION['username'])) {
  header('Location: login.php');
  exit;
}

$role_id = (int) ($_SESSION['role_id'] ?? 0);

// Restrict to admins/managers/analysts
$can_view_reports = in_array($role_id, [1, 2, 3], true);
if (!$can_view_reports) {
  http_response_code(403);
  echo 'Access denied.';
  exit;
}

$title = 'Inventory Reports';
$ROW_CAP_PAGE = 50000;
$css = ['/refactor_css/ai_reports.css'];
$page_class = 'reports-page';

$hotkeys = [
  [
    'key' => 'tpb_inventory',
    'label' => 'Show Inventory',
    'message' => "SELECT s.sku_num, s.desc, i.quantity, i.updated_at\nFROM inventory i\nJOIN sku s ON i.sku_id = s.id",
    'shortcut' => '1'
  ],
  [
    'key' => 'inventory_by_location',
    'label' => 'Inventory by Location',
    'message' => "SELECT s.sku_num, s.desc, i.quantity, l.row_code, l.bay_num, l.level_code, l.side FROM inventory i JOIN sku s ON i.sku_id = s.id JOIN pallet p ON i.pallet_id = p.id JOIN location l ON p.loc_id = l.id ORDER BY l.row_code, l.bay_num, l.level_code;",
    'shortcut' => '2'
  ],
  [
    'key' => 'discontinued_skus',
    'label' => 'List all discontinued skus',
    'message' => "SELECT sku_num, `desc`, status, shelf, created_at FROM sku WHERE status = 'DISCONTINUED' ORDER BY sku_num ASC;",
    'shortcut' => '3'
  ],
  [
    'key' => 'top_movers',
    'label' => 'Top movers (last 30 days)',
    'message' => "SELECT s.sku_num, s.desc, SUM(ABS(m.quantity_change)) AS total_shipped, COUNT(m.id) AS shipment_count FROM inventory_movements m JOIN sku s ON m.sku_id = s.id WHERE m.movement_type = 'OUT' AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY s.id ORDER BY total_shipped DESC LIMIT 10;",
    'shortcut' => '4'
  ],
  [
    'key' => 'low_inventory',
    'label' => 'Low inventory (<20 units)',
    'message' => "SELECT s.sku_num, s.desc, SUM(i.quantity) AS current_stock FROM sku s LEFT JOIN inventory i ON s.id = i.sku_id WHERE s.status = 'ACTIVE' GROUP BY s.id HAVING current_stock < 20 ORDER BY current_stock ASC;",
    'shortcut' => '5'
  ],
  [
    'key' => 'adjustments_summary',
    'label' => 'Adjustments summary (14 days)',
    'message' => "SELECT m.reference AS reason_code, COUNT(*) AS adjustment_count, SUM(m.quantity_change) AS net_quantity_adjusted FROM inventory_movements m WHERE m.movement_type = 'ADJUST' AND m.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY m.reference ORDER BY adjustment_count DESC;",
    'shortcut' => '6'
  ],
  [
    'key' => 'stale_on_hand',
    'label' => 'On-hand with no moves in 90 days',
    'message' => "SELECT s.sku_num, s.`desc`, s.status, SUM(i.quantity) AS current_on_hand, MAX(m.created_at) AS last_move_date FROM sku s LEFT JOIN inventory i ON s.id = i.sku_id LEFT JOIN inventory_movements m ON s.id = m.sku_id AND m.created_at > DATE_SUB(NOW(), INTERVAL 90 DAY) WHERE m.id IS NULL GROUP BY s.id HAVING current_on_hand > 0 ORDER BY current_on_hand DESC;",
    'shortcut' => '7'
  ],
  [
    'key' => 'unentered_locations',
    'label' => 'Unentered Locations',
    'message' => "SELECT l.row_code, l.bay_num, l.level_code, l.side FROM location l WHERE l.is_disabled = 0 AND NOT EXISTS (SELECT 1 FROM pallet p JOIN inventory i ON p.id = i.pallet_id WHERE p.loc_id = l.id AND p.status <> 'ARCHIVED' AND i.quantity > 0 ) ORDER BY l.row_code, l.bay_num, l.level_code, l.side;",
    'shortcut' => '8'
  ],
  [
    'key' => 'Pick_Verify_Events',
    'label' => 'Pick Verify Events',
    'message' => "SELECT po.order_number, po.status, pi.item_no, pve.workflow_stage, pve.event_type, pve.sku_scanned, pve.expected_sku, pve.qty_delta, u.username, u.first_name, u.last_name, pve.meta_json FROM pick_verify_events pve INNER JOIN pick_orders po ON pve.order_id = po.id INNER JOIN pick_items pi ON pve.pick_item_id = pi.id LEFT JOIN xserqhmy_users.users u ON pve.user_id = u.id WHERE po.order_number LIKE '%ENTER_ORDER_NUM%' AND pve.workflow_stage='PICK_OR_PACK'",
    'shortcut' => '9'
  ],
  [
    'key' => 'Order Overview (30 Days)',
    'label' => 'Order Overview (30 Days)',
    'message' => "SELECT po.order_number, po.status, po.created_at AS order_date, item_counts.order_size, pick_times.pick_minutes, u.username FROM pick_orders po LEFT JOIN (SELECT order_id, COUNT(*) AS order_size FROM pick_items GROUP BY order_id) item_counts ON item_counts.order_id = po.id LEFT JOIN (SELECT order_id, MIN(created_at) AS pick_start, MAX(CASE WHEN event_type = 'order_ready_to_pack' THEN created_at END) AS pick_end, MAX(CASE WHEN event_type = 'order_ready_to_pack' THEN user_id END) AS picker_id, ROUND(TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(CASE WHEN event_type = 'order_ready_to_pack' THEN created_at END))/60, 1) AS pick_minutes FROM pick_verify_events WHERE workflow_stage = 'pick' GROUP BY order_id HAVING pick_end IS NOT NULL AND DATE(pick_start) = DATE(pick_end)) pick_times ON pick_times.order_id = po.id LEFT JOIN xserqhmy_users.users u ON u.id = pick_times.picker_id WHERE po.created_at >= NOW() - INTERVAL 30 DAY ORDER BY po.created_at DESC",
    'shortcut' => '10'
  ],
  [
    'key' => 'supplier_barcodes',
    'label' => 'Supplier Barcodes',
    'message' => "SELECT s.sku_num, b.upc, b.source, b.uom_label, b.multiplier, b.created_at, b.updated_at, b.no_barcode FROM product_uom_barcodes b INNER JOIN sku s ON b.sku_id = s.id",
    'shortcut' => '11'
  ],
  // [
  //   'key' => 'Pick Time by Order Size',
  //   'label' => 'Pick Time by Order Size',
  //   'message' => "SELECT CASE WHEN item_counts.order_size <= 4 THEN 'Small (1-4)' WHEN item_counts.order_size <= 15 THEN 'Medium (5-15)' ELSE 'Large (16+)' END AS size_tier, COUNT(*) AS order_count, ROUND(AVG(pick_times.pick_minutes), 1) AS avg_pick_minutes, ROUND(MIN(pick_times.pick_minutes), 1) AS min_pick_minutes, ROUND(MAX(pick_times.pick_minutes), 1) AS max_pick_minutes FROM (SELECT order_id, COUNT(*) AS order_size FROM pick_items GROUP BY order_id) item_counts JOIN (SELECT order_id, TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(CASE WHEN event_type = 'order_ready_to_pack' THEN created_at END))/60 AS pick_minutes, MIN(created_at) AS pick_start, MAX(CASE WHEN event_type = 'order_ready_to_pack' THEN created_at END) AS pick_end FROM pick_verify_events WHERE workflow_stage = 'pick' GROUP BY order_id HAVING pick_end IS NOT NULL AND DATE(pick_start) = DATE(pick_end)) pick_times ON pick_times.order_id = item_counts.order_id GROUP BY size_tier ORDER BY FIELD(size_tier, 'Small (1-4)', 'Medium (5-15)', 'Large (16+)')",
  //   'shortcut' => '12'
  // ],
  // [
  //   'key' => 'Daily Order Velocity',
  //   'label' => 'Daily Order Velocity',
  //   'message' => "SELECT pick_date, orders_completed, ROUND(AVG(orders_completed) OVER (ORDER BY pick_date ROWS BETWEEN 6 PRECEDING AND CURRENT ROW), 1) AS rolling_7day_avg FROM (SELECT DATE(pick_end) AS pick_date, COUNT(*) AS orders_completed FROM (SELECT order_id, MIN(created_at) AS pick_start, MAX(CASE WHEN event_type = 'order_ready_to_pack' THEN created_at END) AS pick_end FROM pick_verify_events WHERE workflow_stage = 'pick' GROUP BY order_id HAVING pick_end IS NOT NULL AND DATE(pick_start) = DATE(pick_end)) t WHERE DATE(pick_end) >= CURDATE() - INTERVAL 30 DAY GROUP BY DATE(pick_end)) daily ORDER BY pick_date DESC",
  //   'shortcut' => '13'
  // ],
  // [
  //   'key' => 'Avg Orders Per Active Hour',
  //   'label' => 'Avg Orders Per Active Hour',
  //   'message' => "SELECT COUNT(*) AS total_orders, COUNT(DISTINCT DATE(pick_end), HOUR(pick_end)) AS active_hours, ROUND(COUNT(*) / COUNT(DISTINCT DATE(pick_end), HOUR(pick_end)), 1) AS avg_orders_per_active_hour FROM (SELECT order_id, MIN(created_at) AS pick_start, MAX(CASE WHEN event_type = 'order_ready_to_pack' THEN created_at END) AS pick_end FROM pick_verify_events WHERE workflow_stage = 'pick' GROUP BY order_id HAVING pick_end IS NOT NULL AND DATE(pick_start) = DATE(pick_end)) t",
  //   'shortcut' => '14'
  // ],
  // [
  //   'key' => 'Orders Per Hour',
  //   'label' => 'Orders Per Hour',
  //   'message' => "SELECT DATE(pick_end) AS period, HOUR(pick_end) AS hour, COUNT(*) AS orders_completed FROM (SELECT order_id, MIN(created_at) AS pick_start, MAX(CASE WHEN event_type='order_ready_to_pack' THEN created_at END) AS pick_end FROM pick_verify_events WHERE workflow_stage='pick' GROUP BY order_id HAVING pick_end IS NOT NULL AND DATE(pick_start)=DATE(pick_end)) t GROUP BY period, hour ORDER BY period DESC, hour",
  //   'shortcut' => '15'
  // ],
  // [
  //   'key' => 'Orders Per Day',
  //   'label' => 'Orders Per Day',
  //   'message' => "SELECT DATE(pick_end) AS period, COUNT(*) AS orders_completed FROM (SELECT order_id, MIN(created_at) AS pick_start, MAX(CASE WHEN event_type='order_ready_to_pack' THEN created_at END) AS pick_end FROM pick_verify_events WHERE workflow_stage='pick' GROUP BY order_id HAVING pick_end IS NOT NULL AND DATE(pick_start)=DATE(pick_end)) t GROUP BY period ORDER BY period DESC",
  //   'shortcut' => '16'
  // ],
  // [
  //   'key' => 'Orders Per Month',
  //   'label' => 'Orders Per Month',
  //   'message' => "SELECT DATE_FORMAT(pick_end, '%Y-%m') AS period, COUNT(*) AS orders_completed FROM (SELECT order_id, MAX(CASE WHEN event_type='order_ready_to_pack' THEN created_at END) AS pick_end FROM pick_verify_events WHERE workflow_stage='pick' GROUP BY order_id HAVING pick_end IS NOT NULL) t GROUP BY period ORDER BY period DESC",
  //   'shortcut' => '17'
  // ],
  // [
  //   'key' => 'Avg Order Size by SKU (Lines)',
  //   'label' => 'Avg Order Size by SKU (Lines)',
  //   'message' => "SELECT pi.item_no AS sku, ROUND(AVG(sz.order_size),1) AS avg_order_size_lines, COUNT(DISTINCT pi.order_id) AS orders FROM pick_items pi JOIN (SELECT order_id, COUNT(*) AS order_size FROM pick_items GROUP BY order_id) sz ON sz.order_id = pi.order_id LEFT JOIN pick_verify_events pve ON pve.order_id = pi.order_id AND pve.event_type='order_ready_to_pack' WHERE pve.user_id = 'ENTER_USER_ID' OR 'ENTER_USER_ID' = 'ALL' GROUP BY pi.item_no ORDER BY orders DESC",
  //   'shortcut' => '10'
  // ],
  // [
  //   'key' => 'Avg Order Size by SKU (Quantity)',
  //   'label' => 'Avg Order Size by SKU (Quantity)',
  //   'message' => "SELECT pi.item_no AS sku, ROUND(AVG(pi.qty_ordered),1) AS avg_qty_per_order, COUNT(DISTINCT pi.order_id) AS orders FROM pick_items pi LEFT JOIN pick_verify_events pve ON pve.order_id = pi.order_id AND pve.event_type='order_ready_to_pack' WHERE pve.user_id = 'ENTER_USER_ID' OR 'ENTER_USER_ID' = 'ALL' GROUP BY pi.item_no ORDER BY orders DESC",
  //   'shortcut' => '10'
  // ],
];

$hotkey_sql_map = [];
$hotkey_label_map = [];
foreach ($hotkeys as $hotkey) {
  $hotkey_sql_map[$hotkey['key']] = $hotkey['message'];
  $hotkey_label_map[$hotkey['key']] = $hotkey['label'];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ---------------------------------------------------------------------------
// Order operations dashboard: JSON data endpoint
// GET ai_reports.php?action=order_ops_data
// Same access rules as the rest of this page (roles 1-3, checked above).
// ---------------------------------------------------------------------------
if ($action === 'order_ops_data') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');

  // MySQL server runs on Mountain time; the warehouse is on Eastern.
  // Both zones change for daylight saving on the same dates, so a fixed shift stays correct.
  // If dbinv.php ever sets the session time_zone, revisit this value.
  $OPS_TZ_SHIFT_HOURS = 2;
  $shift = (int) $OPS_TZ_SHIFT_HOURS;

  // One row per completed order: the last "order_ready_to_pack" pick event decides
  // when it was completed and by whom; pick_items give its size in SKU lines and verified units.
  $ops_base = "WITH last_rtp AS (
      SELECT order_id, MAX(id) AS mid FROM pick_verify_events
      WHERE workflow_stage = 'pick' AND event_type = 'order_ready_to_pack' GROUP BY order_id),
    done AS (
      SELECT e.order_id, e.user_id AS u, DATE_ADD(e.created_at, INTERVAL {$shift} HOUR) AS t
      FROM pick_verify_events e JOIN last_rtp m ON m.mid = e.id),
    st AS (
      SELECT order_id, DATE_ADD(MIN(created_at), INTERVAL {$shift} HOUR) AS s
      FROM pick_verify_events WHERE workflow_stage = 'pick' GROUP BY order_id),
    sz AS (
      SELECT order_id, COUNT(*) AS k, SUM(qty_verified) AS q FROM pick_items GROUP BY order_id),
    b AS (
      SELECT d.u, d.t, sz.k, sz.q, st.s FROM done d
      LEFT JOIN sz ON sz.order_id = d.order_id
      LEFT JOIN st ON st.order_id = d.order_id) ";
  $ops_sizes = "COUNT(*) AS o,
      IFNULL(SUM(k BETWEEN 1 AND 4), 0) AS s,
      IFNULL(SUM(k BETWEEN 5 AND 15), 0) AS m,
      IFNULL(SUM(k >= 16), 0) AS l,
      IFNULL(SUM(k), 0) AS k,
      IFNULL(SUM(q), 0) AS q";

  $ops_query = function (string $sql) use ($conn): array {
    $res = $conn->query($sql);
    if (!$res instanceof mysqli_result) {
      throw new RuntimeException((string) $conn->error);
    }
    $rows = $res->fetch_all(MYSQLI_NUM);
    $res->free();
    return $rows;
  };
  $ints = fn(array $rows, int $from) => array_map(function ($r) use ($from) {
    foreach ($r as $i => $v) {
      if ($i >= $from)
        $r[$i] = (int) $v;
    }
    return $r;
  }, $rows);

  try {
    $days = $ints($ops_query($ops_base . "SELECT DATE_FORMAT(t, '%Y-%m-%d') AS dy, u, {$ops_sizes} FROM b GROUP BY dy, u ORDER BY dy, u"), 1);
    $hours = $ints($ops_query($ops_base . "SELECT DATE_FORMAT(t, '%Y-%m-%d') AS dy, HOUR(t) AS h, u, {$ops_sizes} FROM b GROUP BY dy, h, u ORDER BY dy, h, u"), 1);
    $pick = [];
    foreach ($ops_query($ops_base . "SELECT u, ROUND(AVG(CASE WHEN DATE(s) = DATE(t) THEN TIMESTAMPDIFF(SECOND, s, t) END) / 60, 1) AS pm FROM b GROUP BY u") as $r) {
      if ($r[1] !== null)
        $pick[(int) $r[0]] = (float) $r[1];
    }

    // Packer data (from pack workflow_stage)
    $pack_base = "WITH last_pack AS (
        SELECT order_id, MAX(id) AS mid FROM pick_verify_events
        WHERE workflow_stage = 'pack' AND event_type = 'order_ready_to_pack' GROUP BY order_id),
      pack_done AS (
        SELECT e.order_id, e.user_id AS u, DATE_ADD(e.created_at, INTERVAL {$shift} HOUR) AS t
        FROM pick_verify_events e JOIN last_pack m ON m.mid = e.id),
      pack_sz AS (
        SELECT order_id, COUNT(*) AS k, SUM(qty_verified) AS q FROM pick_items GROUP BY order_id),
      pb AS (
        SELECT pd.u, pd.t, pack_sz.k, pack_sz.q FROM pack_done pd
        LEFT JOIN pack_sz ON pack_sz.order_id = pd.order_id) ";
    $pack_days = $ints($ops_query($pack_base . "SELECT DATE_FORMAT(t, '%Y-%m-%d') AS dy, u, {$ops_sizes} FROM pb GROUP BY dy, u ORDER BY dy, u"), 1);

    $asof = $ops_query("SELECT DATE_FORMAT(DATE_ADD(NOW(), INTERVAL {$shift} HOUR), '%Y-%m-%d %H:%i')")[0][0] ?? '';
  } catch (Throwable $e) {
    error_log('order_ops_data: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The order operations query failed. Check the PHP error log for details.']);
    exit;
  }

  // Picker and packer names (optional: falls back to "User #id" if the users database can't be read)
  $names = [];
  $picker_ids = array_values(array_unique(array_map(fn($r) => (int) $r[1], $days)));
  $packer_ids = array_values(array_unique(array_map(fn($r) => (int) $r[1], $pack_days)));
  $all_ids = array_unique(array_merge($picker_ids, $packer_ids));
  if ($all_ids) {
    try {
      foreach ($ops_query('SELECT id, first_name, last_name FROM xserqhmy_users.users WHERE id IN (' . implode(',', $all_ids) . ')') as $r) {
        $n = trim(trim((string) $r[1]) . ' ' . trim((string) $r[2]));
        if ($n !== '')
          $names[(int) $r[0]] = $n;
      }
    } catch (Throwable $e) {
      error_log('order_ops_data names: ' . $e->getMessage());
    }
  }

  echo json_encode([
    'asof' => $asof,
    'days' => $days,    // [date, user_id, orders, small, medium, large, sku_lines, units]
    'hours' => $hours,  // [date, hour, user_id, orders, small, medium, large, sku_lines, units]
    'pack_days' => $pack_days,  // [date, user_id, orders, small, medium, large, sku_lines, units]
    'pick_minutes' => $pick,
    'names' => $names,
  ]);
  exit;
}

$error = '';
$preview = ['columns' => [], 'rows' => [], 'row_count' => 0, 'total_count' => 0, 'sql' => '', 'label' => ''];
$manual_sql_value = '';

if ($action === 'reset') {
  unset($_SESSION['ai_preview']);
  header('Location: ai_reports.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'manual_sql') {
  $preset_key = trim((string) ($_POST['preset_key'] ?? ''));
  $manual_sql = trim((string) ($_POST['manual_sql'] ?? ''));
  $current_label = '';
  if ($preset_key !== '' && isset($hotkey_sql_map[$preset_key])) {
    $manual_sql = $hotkey_sql_map[$preset_key];
    $current_label = $hotkey_label_map[$preset_key] ?? '';
  }
  $manual_sql_value = $manual_sql;
  if ($manual_sql === '') {
    $error = 'Please enter a SQL query.';
  } else {
    $errs = [];
    if (!validate_select_only($manual_sql, $errs)) {
      $error = 'SQL validation failed: ' . implode('; ', $errs);
    } else {
      $sql_no_limit = preg_replace('/\s+LIMIT\s+.*(\\s+OFFSET\\s+.*)?$/is', '', $manual_sql);
      $total_count = null;
      try {
        $count_res = $conn->query("SELECT COUNT(*) AS cnt FROM ({$sql_no_limit}) AS subq");
      } catch (Throwable $e) {
        $count_res = false;
        $errs[] = 'SQL syntax error: ' . $e->getMessage();
      }

      if ($count_res instanceof mysqli_result) {
        $row = $count_res->fetch_assoc();
        $total_count = isset($row['cnt']) ? (int) $row['cnt'] : null;
        $count_res->free();
      } else {
        $total_count = 0;
      }

      $sql_run = wrap_with_limit($manual_sql, $ROW_CAP_PAGE, 0);
      try {
        $res = $conn->query($sql_run);
      } catch (Throwable $e) {
        $res = false;
        $errs[] = 'SQL error: ' . $e->getMessage();
      }

      if ($res instanceof mysqli_result) {
        $fields = $res->fetch_fields();
        $cols = [];
        foreach ($fields as $f) {
          $cols[] = $f->name;
        }

        $rows = [];
        while ($r = $res->fetch_assoc()) {
          $rows[] = $r;
        }

        $preview = [
          'columns' => $cols,
          'rows' => $rows,
          'row_count' => count($rows),
          'total_count' => $total_count ?? count($rows),
          'sql' => $manual_sql,
          'label' => $current_label,
        ];
        $res->free();
        $_SESSION['ai_preview'] = $preview;
      } else {
        $error = !empty($errs)
          ? implode('; ', $errs)
          : ('SQL error: ' . $conn->error);
      }
    }
  }
}

ob_start();
?>



<div class="reports-shell">
  <h2 class="title">Inventory Reports</h2>

  <div class="reports-page-tabs" role="tablist" aria-label="Report type">
    <button type="button" class="reports-page-tab is-active" role="tab" aria-selected="true" data-tab="sql">SQL
      reports</button>
    <button type="button" class="reports-page-tab" role="tab" aria-selected="false" data-tab="ops">Order
      operations</button>
  </div>

  <div id="tab-sql" class="reports-tab-panel">

    <div class="card card--pad reports-card">
      <form id="reports-form" class="form reports-form" method="post" action="">
        <input type="hidden" name="action" value="manual_sql" />
        <input type="hidden" name="preset_key" id="preset-key" value="" />
        <label for="manual-sql" style="display:block; color:var(--text-muted);">Direct SQL (SELECT/WITH only)</label>
        <textarea id="manual-sql" name="manual_sql" rows="5" class="input" style="width:100%; font-family:ui-monospace;"
          placeholder="SELECT col1, col2 FROM sku WHERE ..."><?= h($manual_sql_value) ?></textarea>
        <div class="reports-actions">
          <button class="btn btn--primary" type="submit">Run SQL</button>
          <a class="btn btn--ghost" href="?reset=1"
            onclick="event.preventDefault(); location.href='ai_reports.php?action=reset'">Reset</a>
        </div>
        <?php if ($error): ?>
          <p class="error" style="margin-top:8px; color:#b00020;"><?= h($error) ?></p>
        <?php endif; ?>
      </form>
    </div>

    <div class="reports-actions">
      <?php if (($preview['row_count'] ?? 0) > 0): ?>
        <a class="btn" href="/utils/export_csv.php?mode=preview">
          Download CSV (<?= (int) $preview['row_count'] ?> rows shown)
        </a>
      <?php endif; ?>
    </div>

    <div id="reports-fab" class="reports-fab">
      <button id="reports-fab-toggle" class="reports-fab__button" title="Quick reports">
        <span style="font-size: 1.5rem;">⚡</span>
      </button>
      <div class="reports-fab__menu">
        <?php foreach ($hotkeys as $hk): ?>
          <button type="button" class="reports-fab__item reports-hotkey" data-preset-key="<?= h($hk['key']) ?>"
            data-hotkey="<?= h($hk['shortcut']) ?>" title="<?= h($hk['label']) ?> (<?= h($hk['shortcut']) ?>)">
            <span class="reports-fab__label"><?= h($hk['label']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

    <?php if ($preview['columns']): ?>
      <div class="card card--pad reports-card">
        <div class="reports-preview-meta">
          <div>
            <div style="margin-bottom: 8px;">
              <strong>Preview (<?= (int) $preview['row_count'] ?> rows)</strong>
              <?php if (!empty($preview['label'])): ?>
                <div style="margin-top: 4px; color: var(--text-muted); font-size: 0.9rem;">
                  <em><?= h($preview['label']) ?></em>
                </div>
              <?php endif; ?>
            </div>
            <div class="reports-view-tabs">
              <button type="button" class="reports-view-tab is-active" data-view="table">Table</button>
              <button type="button" class="reports-view-tab" data-view="chart">Chart</button>
              <button type="button" class="reports-view-tab" data-view="summary">Summary</button>
            </div>
          </div>
        </div>

        <!-- Table View -->
        <div id="view-table" class="reports-view-content">
          <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
            <div style="display: flex; gap: 8px; align-items: center;">
              <label for="group-by-select" style="color: var(--text-muted); font-size: 0.9rem;">Group by:</label>
              <select id="group-by-select" class="input" style="max-width: 200px;">
                <option value="">— No grouping —</option>
                <?php foreach ($preview['columns'] as $c): ?>
                  <option value="<?= h($c) ?>"><?= h($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="reports-filters" id="reports-filters">
            <div class="reports-filters__head">
              <span class="reports-filters__title">Filters</span>
              <label class="reports-filters__match">
                <span>Show rows that match</span>
                <select id="filter-match" class="input">
                  <option value="all">all filters</option>
                  <option value="any">any filter</option>
                </select>
              </label>
              <span class="reports-filters__count" id="filter-count" aria-live="polite"></span>
            </div>
            <div class="reports-filters__rows" id="filter-rows"></div>
            <div class="reports-filters__actions">
              <button id="add-filter-btn" class="btn btn--ghost" type="button">+ Add filter</button>
              <button id="clear-filter-btn" class="btn btn--ghost" type="button">Clear all</button>
            </div>
          </div>
          <div id="tabulator-table" style="height: 60vh;"></div>
        </div>

        <!-- Chart View -->
        <div id="view-chart" class="reports-view-content" hidden>
          <div class="reports-chart-controls">
            <div>
              <label for="chart-x-axis">X Axis:</label>
              <select id="chart-x-axis" class="input"></select>
            </div>
            <div>
              <label for="chart-y-axis">Y Axis:</label>
              <select id="chart-y-axis" class="input"></select>
            </div>
            <div>
              <label for="chart-type">Type:</label>
              <select id="chart-type" class="input">
                <option value="bar">Bar</option>
                <option value="line">Line</option>
                <option value="pie">Pie</option>
              </select>
            </div>
          </div>
          <div class="reports-chart-wrapper">
            <canvas id="reports-chart"></canvas>
          </div>
        </div>

        <!-- Summary View -->
        <div id="view-summary" class="reports-view-content" hidden>
          <div id="reports-summary-grid" class="reports-summary-grid"></div>
        </div>
      </div>
      <link href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.5.0/dist/css/tabulator.min.css" rel="stylesheet">
      <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.5.0/dist/js/tabulator.min.js"></script>
      <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@1.2.0"></script>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          const previewData = <?= json_encode($preview['rows']) ?>;
          const columns = <?= json_encode(array_map(function ($col) {
            return ['title' => $col, 'field' => $col];
          }, $preview['columns'])) ?>;
          const groupSelect = document.getElementById('group-by-select');

          const table = new Tabulator('#tabulator-table', {
            data: previewData,
            columns: columns,
            layout: 'fitColumns',
            pagination: 'local',
            paginationSize: 20,
            movableColumns: true,
            resizableColumns: true,
            resizableRows: false,
            selectable: true,
          });

          if (groupSelect) {
            groupSelect.addEventListener('change', function () {
              const groupField = this.value;
              if (groupField) {
                table.setGroupBy(groupField);
              } else {
                table.setGroupBy(false);
              }
            });
          }

          // ---------- Multiple filters ----------
          const filterRowsEl = document.getElementById('filter-rows');
          const filterMatch = document.getElementById('filter-match');
          const filterCount = document.getElementById('filter-count');
          const addFilterBtn = document.getElementById('add-filter-btn');
          const clearFilterBtn = document.getElementById('clear-filter-btn');
          const FILTER_OPS = [
            ['like', 'Contains'], ['notlike', 'Does not contain'], ['starts', 'Starts with'],
            ['=', '='], ['!=', '!='], ['>', '>'], ['>=', '>='], ['<', '<'], ['<=', '<='],
            ['empty', 'Is empty'], ['notempty', 'Is not empty'],
          ];
          const NO_VALUE_OPS = ['empty', 'notempty'];
          const isNum = v => v !== null && v !== '' && !isNaN(v) && isFinite(v);
          const isBlank = v => v === null || v === undefined || String(v).trim() === '';

          const testValue = (cell, op, want) => {
            if (op === 'empty') return isBlank(cell);
            if (op === 'notempty') return !isBlank(cell);
            const a = cell === null || cell === undefined ? '' : String(cell);
            const al = a.toLowerCase(), wl = want.toLowerCase();
            switch (op) {
              case 'like': return al.includes(wl);
              case 'notlike': return !al.includes(wl);
              case 'starts': return al.startsWith(wl);
              case '=': return isNum(a) && isNum(want) ? +a === +want : al === wl;
              case '!=': return isNum(a) && isNum(want) ? +a !== +want : al !== wl;
              default: {
                // >, >=, <, <=: numbers compare as numbers, dates/text compare as text (YYYY-MM-DD sorts correctly)
                if (isNum(want) && !isNum(a)) return false; // blank or text cell vs a number: no match
                const cmp = isNum(a) && isNum(want) ? (+a - +want) : al.localeCompare(wl);
                return op === '>' ? cmp > 0 : op === '>=' ? cmp >= 0 : op === '<' ? cmp < 0 : cmp <= 0;
              }
            }
          };

          const readFilters = () => [...filterRowsEl.querySelectorAll('.reports-filter-row')].map(row => ({
            field: row.querySelector('.rf-col').value,
            op: row.querySelector('.rf-op').value,
            value: row.querySelector('.rf-val').value.trim(),
          })).filter(f => NO_VALUE_OPS.includes(f.op) || f.value !== '');

          const updateCount = () => {
            if (!filterCount) return;
            const active = readFilters().length;
            const shown = table.getDataCount('active'), total = table.getDataCount();
            filterCount.textContent = active ? `Showing ${shown.toLocaleString()} of ${total.toLocaleString()} rows` : '';
          };

          const applyFilters = () => {
            const filters = readFilters();
            if (!filters.length) { table.clearFilter(); updateCount(); return; }
            const allCols = columns.map(c => c.field);
            const matchAll = !filterMatch || filterMatch.value === 'all';
            table.setFilter(data => {
              const results = filters.map(f => {
                if (f.field) return testValue(data[f.field], f.op, f.value);
                // "Any column": negative tests must hold for every column, positive tests for at least one
                const neg = ['notlike', '!=', 'empty'].includes(f.op);
                return neg ? allCols.every(c => testValue(data[c], f.op, f.value)) : allCols.some(c => testValue(data[c], f.op, f.value));
              });
              return matchAll ? results.every(Boolean) : results.some(Boolean);
            });
          };
          table.on('dataFiltered', () => setTimeout(updateCount, 0));

          const addFilterRow = (preset = {}) => {
            const row = document.createElement('div');
            row.className = 'reports-filter-row';
            const col = document.createElement('select');
            col.className = 'input rf-col'; col.setAttribute('aria-label', 'Column');
            [['', 'Any column'], ...columns.map(c => [c.field, c.title])].forEach(([v, t]) => {
              const o = document.createElement('option'); o.value = v; o.textContent = t; col.appendChild(o);
            });
            const op = document.createElement('select');
            op.className = 'input rf-op'; op.setAttribute('aria-label', 'Condition');
            FILTER_OPS.forEach(([v, t]) => { const o = document.createElement('option'); o.value = v; o.textContent = t; op.appendChild(o); });
            const val = document.createElement('input');
            val.type = 'text'; val.className = 'input rf-val'; val.placeholder = 'Value…'; val.setAttribute('aria-label', 'Value');
            const del = document.createElement('button');
            del.type = 'button'; del.className = 'btn btn--ghost rf-del'; del.textContent = '×'; del.title = 'Remove this filter'; del.setAttribute('aria-label', 'Remove this filter');
            col.value = preset.field || ''; op.value = preset.op || 'like'; val.value = preset.value || '';
            const syncValue = () => { val.hidden = NO_VALUE_OPS.includes(op.value); };
            syncValue();
            col.addEventListener('change', applyFilters);
            op.addEventListener('change', () => { syncValue(); applyFilters(); });
            val.addEventListener('input', applyFilters);
            del.addEventListener('click', () => {
              row.remove();
              if (!filterRowsEl.children.length) addFilterRow();
              applyFilters();
            });
            row.append(col, op, val, del);
            filterRowsEl.appendChild(row);
            return row;
          };

          if (filterRowsEl) {
            addFilterRow();
            if (addFilterBtn) addFilterBtn.addEventListener('click', () => { addFilterRow().querySelector('.rf-col').focus(); });
            if (filterMatch) filterMatch.addEventListener('change', applyFilters);
            if (clearFilterBtn) {
              clearFilterBtn.addEventListener('click', () => {
                filterRowsEl.textContent = '';
                addFilterRow();
                if (filterMatch) filterMatch.value = 'all';
                table.clearFilter();
                updateCount();
              });
            }
          }

          // Tab switching & multi-view logic
          const viewTabs = document.querySelectorAll('.reports-view-tab');
          const viewContents = document.querySelectorAll('.reports-view-content');
          let chartInstance = null;
          let summaryComputed = false;

          const columnNames = columns.map(c => c.field);
          const isNumericColumn = (field) => {
            const samples = previewData
              .map(row => row[field])
              .filter(v => v != null && v !== '')
              .slice(0, 50);
            return samples.length > 0 && samples.every(v => {
              const num = parseFloat(v);
              return !isNaN(num) && isFinite(num);
            });
          };

          const numericColumns = columnNames.filter(isNumericColumn);
          const textColumns = columnNames.filter(f => !isNumericColumn(f));

          // Chart controls (get references early)
          const chartXAxis = document.getElementById('chart-x-axis');
          const chartYAxis = document.getElementById('chart-y-axis');
          const chartType = document.getElementById('chart-type');

          // Define chart builder BEFORE using it in event listeners
          const buildChart = () => {
            if (numericColumns.length === 0) return;

            const canvas = document.getElementById('reports-chart');
            if (!canvas) {
              console.error('Chart canvas not found');
              return;
            }

            const xField = chartXAxis?.value || columnNames[0];
            const yField = chartYAxis?.value || numericColumns[0];
            const type = chartType?.value || 'bar';

            const grouped = {};
            previewData.forEach(row => {
              const key = String(row[xField] ?? '');
              if (!grouped[key]) grouped[key] = [];
              grouped[key].push(parseFloat(row[yField]) || 0);
            });

            let labels = Object.keys(grouped);
            let values = labels.map(k => grouped[k].reduce((a, b) => a + b, 0));

            // Limit to top 30
            if (labels.length > 30) {
              const pairs = labels.map((l, i) => [l, values[i]]).sort((a, b) => b[1] - a[1]).slice(0, 30);
              labels = pairs.map(p => p[0]);
              values = pairs.map(p => p[1]);
            }

            const ctx = canvas.getContext('2d');
            if (chartInstance) chartInstance.destroy();

            const bgColor = type === 'pie' ?
              ['#0dcaf0', '#15b3a1', '#20a393', '#2b9285', '#358177', '#3f7069', '#4a5f5b', '#544e4d'] :
              '#0dcaf0';

            chartInstance = new Chart(ctx, {
              type: type,
              data: {
                labels: labels,
                datasets: [{
                  label: yField,
                  data: values,
                  backgroundColor: bgColor,
                  borderColor: type === 'line' ? '#0dcaf0' : undefined,
                  borderWidth: type === 'line' ? 2 : undefined,
                  tension: type === 'line' ? 0.4 : undefined,
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: type === 'bar' ? 'x' : undefined,
                plugins: {
                  legend: {
                    display: type !== 'pie'
                  },
                  zoom: type !== 'pie' ? {
                    zoom: {
                      wheel: { enabled: false },
                      pinch: { enabled: true },
                      mode: 'xy'
                    },
                    pan: {
                      enabled: true,
                      mode: 'xy',
                      modifierKey: 'ctrl'
                    }
                  } : undefined
                },
                scales: type === 'pie' ? {} : {
                  y: {
                    beginAtZero: true,
                    ticks: {
                      stepSize: Math.ceil(Math.max(...values) / 5) || 1
                    }
                  }
                }
              }
            });

            // Handle window resize
            const resizeHandler = () => {
              if (chartInstance) chartInstance.resize();
            };
            window.addEventListener('resize', resizeHandler);
          };

          const buildSummary = () => {
            const grid = document.getElementById('reports-summary-grid');
            grid.innerHTML = '';

            const card = (label, value, meta = '') => {
              const div = document.createElement('div');
              div.className = 'reports-summary-card';
              div.innerHTML = `
              <div class="reports-summary-card__label">${label}</div>
              <div class="reports-summary-card__value">${value}</div>
              ${meta ? `<div class="reports-summary-card__meta">${meta}</div>` : ''}
            `;
              grid.appendChild(div);
            };

            card('Total Rows', previewData.length.toLocaleString());
            card('Total Columns', columnNames.length.toLocaleString());

            // Numeric columns: sum, avg, min, max
            numericColumns.forEach(col => {
              const vals = previewData
                .map(r => parseFloat(r[col]) || 0)
                .filter(v => isFinite(v));
              if (vals.length === 0) return;
              const sum = vals.reduce((a, b) => a + b, 0);
              const avg = sum / vals.length;
              const min = Math.min(...vals);
              const max = Math.max(...vals);
              card(`${col} Sum`, sum.toLocaleString('en-US', { maximumFractionDigits: 2 }));
              card(`${col} Avg`, avg.toLocaleString('en-US', { maximumFractionDigits: 2 }));
              card(`${col} Min`, min.toLocaleString('en-US', { maximumFractionDigits: 2 }));
              card(`${col} Max`, max.toLocaleString('en-US', { maximumFractionDigits: 2 }));
            });

            // Text columns: distinct, null
            textColumns.forEach(col => {
              const vals = previewData.map(r => r[col]);
              const distinct = new Set(vals.filter(v => v != null && v !== '')).size;
              const nullCount = vals.filter(v => v == null || v === '').length;
              card(`${col} Distinct`, distinct.toLocaleString(), `of ${vals.length}`);
              card(`${col} Empty/Null`, nullCount.toLocaleString(), `of ${vals.length}`);
            });

            summaryComputed = true;
          };

          // Populate chart controls
          if (chartXAxis && numericColumns.length > 0) {
            // Populate X axis with ALL columns (prefer text first)
            const xAxisCols = textColumns.length > 0 ? [...textColumns, ...numericColumns] : columnNames;
            xAxisCols.forEach(col => {
              const opt = document.createElement('option');
              opt.value = col;
              opt.textContent = col;
              chartXAxis.appendChild(opt);
            });
            if (textColumns.length > 0) chartXAxis.value = textColumns[0];
            else chartXAxis.value = columnNames[0];

            // Populate Y axis (numeric only)
            if (chartYAxis) {
              numericColumns.forEach(col => {
                const opt = document.createElement('option');
                opt.value = col;
                opt.textContent = col;
                chartYAxis.appendChild(opt);
              });
              chartYAxis.value = numericColumns[0];
            }

            // Rebuild chart on control change
            [chartXAxis, chartYAxis, chartType].forEach(ctrl => {
              if (ctrl) ctrl.addEventListener('change', buildChart);
            });
          }

          // Setup tab switching (now that buildChart and buildSummary are defined)
          if (viewTabs.length > 0) {
            viewTabs.forEach(tab => {
              tab.addEventListener('click', function () {
                const targetView = this.dataset.view;
                viewTabs.forEach(t => t.classList.remove('is-active'));
                viewContents.forEach(v => v.hidden = true);
                this.classList.add('is-active');
                document.getElementById('view-' + targetView).hidden = false;

                // Lazy-build chart on first open
                if (targetView === 'chart' && !chartInstance && numericColumns.length > 0) {
                  buildChart();
                }
                // Lazy-compute summary on first open
                if (targetView === 'summary' && !summaryComputed) {
                  buildSummary();
                }
              });
            });
          }

          // Show fallback if no numeric columns
          if (numericColumns.length === 0) {
            const chartWrapper = document.querySelector('.reports-chart-wrapper');
            if (chartWrapper) chartWrapper.innerHTML = '<div class="reports-chart-message">No numeric columns to chart</div>';
            if (chartXAxis) chartXAxis.disabled = true;
            if (chartYAxis) chartYAxis.disabled = true;
            if (chartType) chartType.disabled = true;
          }
        });
      </script>
    <?php endif; ?>
  </div><!-- /tab-sql -->

  <div id="tab-ops" class="reports-tab-panel" hidden>
    <div class="card card--pad reports-card ops-card">
      <div class="ops-head">
        <div>
          <h3 class="ops-title">Order operations</h3>
          <p class="ops-range" id="ops-range">Orders completed at the pick stage. Times are Eastern.</p>
        </div>
        <div class="ops-status-row">
          <span class="ops-status" id="ops-status" aria-live="polite"></span>
          <button type="button" class="btn btn--ghost" id="ops-refresh">Refresh</button>
        </div>
      </div>

      <div class="reports-chart-controls ops-controls">
        <div class="ops-seg" role="group" aria-label="Group by">
          <button type="button" data-p="hour" aria-pressed="false">Hour of day</button>
          <button type="button" data-p="day" aria-pressed="true">Day</button>
          <button type="button" data-p="month" aria-pressed="false">Month</button>
          <button type="button" data-p="quarter" aria-pressed="false">Quarter</button>
        </div>
        <div class="ops-dates">
          <label for="ops-preset">Dates:</label>
          <select id="ops-preset" class="input">
            <option value="all">All time</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="90">Last 90 days</option>
            <option value="this_month">This month</option>
            <option value="last_month">Last month</option>
            <option value="this_quarter">This quarter</option>
            <option value="last_quarter">Last quarter</option>
            <option value="ytd">Year to date</option>
            <option value="custom">Custom range</option>
          </select>
          <input type="date" id="ops-from" class="input" aria-label="From date">
          <span class="ops-dates__to">to</span>
          <input type="date" id="ops-to" class="input" aria-label="To date">
        </div>
        <div style="display: flex; gap: 12px; align-items: center;">
          <label id="ops-mode-label" for="ops-user">Picker:</label>
          <select id="ops-user" class="input">
            <option value="all">All pickers</option>
          </select>
          <button type="button" id="ops-mode-toggle" class="btn btn--ghost" style="padding: 6px 10px; font-size: 0.9rem; white-space: nowrap;">Switch to Packer</button>
        </div>
        <div id="ops-flagbox" hidden>
          <label class="ops-check"><input type="checkbox" id="ops-flag"> <span id="ops-flaglabel">Include flagged
              account in picker comparisons</span></label>
        </div>
      </div>

      <div id="ops-kpis" class="reports-summary-grid"></div>
      <p class="ops-note" id="ops-flagnote" hidden></p>
    </div>

    <section class="card card--pad reports-card" aria-labelledby="ops-h1">
      <h3 class="ops-title" id="ops-h1">Orders completed</h3>
      <p class="ops-cap" id="ops-cap1"></p>
      <div class="ops-grid">
        <div class="ops-panel">
          <h4 id="ops-t1">Over time</h4>
          <div class="ops-chart"><canvas id="ops-c1"></canvas></div>
        </div>
        <div class="ops-panel">
          <h4>By picker <span>orders</span></h4>
          <div class="ops-chart ops-chart--tall" id="ops-w1u"><canvas id="ops-c1u"></canvas></div>
        </div>
      </div>
    </section>

    <section class="card card--pad reports-card" aria-labelledby="ops-h2">
      <h3 class="ops-title" id="ops-h2">Order size by SKU lines</h3>
      <p class="ops-cap" id="ops-cap2"></p>
      <div class="ops-legend"><span><i class="ops-sw" data-tier="s"></i>Small, 1–4 SKUs</span><span><i class="ops-sw"
            data-tier="m"></i>Medium, 5–15 SKUs</span><span><i class="ops-sw" data-tier="l"></i>Large, 16+ SKUs</span>
      </div>
      <div class="ops-grid">
        <div class="ops-panel">
          <h4 id="ops-t2">Over time</h4>
          <div class="ops-chart"><canvas id="ops-c2"></canvas></div>
        </div>
        <div class="ops-panel">
          <h4>By picker <span>share of each picker's orders</span></h4>
          <div class="ops-chart ops-chart--tall" id="ops-w2u"><canvas id="ops-c2u"></canvas></div>
        </div>
      </div>
    </section>

    <section class="card card--pad reports-card" aria-labelledby="ops-h3">
      <h3 class="ops-title" id="ops-h3">Order size by units picked</h3>
      <p class="ops-cap" id="ops-cap3"></p>
      <div class="ops-grid">
        <div class="ops-panel">
          <h4 id="ops-t3">Average units per order</h4>
          <div class="ops-chart"><canvas id="ops-c3"></canvas></div>
        </div>
        <div class="ops-panel">
          <h4>By picker <span>average units per order</span></h4>
          <div class="ops-chart ops-chart--tall" id="ops-w3u"><canvas id="ops-c3u"></canvas></div>
        </div>
      </div>
    </section>

    <details class="card card--pad reports-card ops-about">
      <summary>How these numbers are worked out</summary>
      <p>An order counts as completed when its pick is marked ready to pack (the last <code>order_ready_to_pack</code>
        event in <code>pick_verify_events</code>), and the picker is the user on that event. Order size in SKU lines is
        the number of <code>pick_items</code> rows; units are the summed <code>qty_verified</code>. Orders with no item
        lines count as orders but not in size or unit figures.</p>
      <p>Every chart and total follows the selected dates, including hour of day. Months and quarters marked with an
        asterisk are only partly inside the selected dates. Per-picker averages only include pickers with at least 10
        orders. Server times are shifted two hours from Mountain to Eastern.</p>
    </details>
  </div>
</div>

<script>
  /* Order operations dashboard (tab "Order operations") */
  (function () {
    // ----- page tabs: SQL reports / Order operations -----
    const pageTabs = document.querySelectorAll('.reports-page-tab');
    const panels = { sql: document.getElementById('tab-sql'), ops: document.getElementById('tab-ops') };
    let opsStarted = false;
    function showTab(name, push) {
      pageTabs.forEach(t => {
        const on = t.dataset.tab === name;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Object.keys(panels).forEach(k => { if (panels[k]) panels[k].hidden = k !== name; });
      if (push) history.replaceState(null, '', name === 'ops' ? '#order-ops' : location.pathname + location.search);
      if (name === 'ops' && !opsStarted) { opsStarted = true; load(); }
      else if (name === 'ops') render();
    }
    pageTabs.forEach(t => t.addEventListener('click', () => showTab(t.dataset.tab, true)));

    // ----- state -----
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const MIN_ORDERS = 10;
    const st = { p: 'day', u: 'all', flag: false, mode: 'picker' };
    const charts = {};
    let D = [], H = [], PACK_D = [], PICK = {}, NAMES = {}, ALL_DAYS = [], PERIOD_DAYS = {}, PU = [], PAC = [], FLAG = null;
    let RAW_D = [], RAW_H = [], RAW_PACK_D = [], DATA_MIN = '', DATA_MAX = '', TODAY = '';
    const range = { from: '', to: '' }; // '' = open-ended
    const $ = id => document.getElementById(id);

    const fmt = n => n.toLocaleString('en-CA');
    const f1 = n => n == null || !isFinite(n) ? '–' : n.toLocaleString('en-CA', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const tidy = n => {
      const parts = n.trim().split(/\s+/);
      if (parts.length === 2 && parts[0].toLowerCase() === parts[1].toLowerCase()) n = parts[0];
      return n.replace(/\b([A-Z])([A-Z]+)\b/g, (m, a, b) => a + b.toLowerCase());
    };
    const name = u => (NAMES[u] ? tidy(NAMES[u]) : 'User') + ' (#' + u + ')';
    const z = () => ({ o: 0, s: 0, m: 0, l: 0, k: 0, q: 0 });
    const add = (a, r) => { a.o += r.o; a.s += r.s; a.m += r.m; a.l += r.l; a.k += r.k; a.q += r.q; return a; };
    const sized = a => a.s + a.m + a.l;
    const ordersTxt = n => fmt(n) + (n === 1 ? ' order' : ' orders');
    const qKey = d => d.slice(0, 4) + '-Q' + (Math.floor((+d.slice(5, 7) - 1) / 3) + 1);
    const pKey = d => st.p === 'month' ? d.slice(0, 7) : st.p === 'quarter' ? qKey(d) : d;
    const fmtDay = d => MONTHS[+d.slice(5, 7) - 1] + ' ' + (+d.slice(8, 10));
    const inUser = r => st.u === 'all' || r.u === +st.u;

    // ----- data -----
    function setData(json) {
      const row = (a, o) => ({ u: +a[o], o: +a[o + 1], s: +a[o + 2], m: +a[o + 3], l: +a[o + 4], k: +a[o + 5], q: +a[o + 6] });
      RAW_D = (json.days || []).map(a => Object.assign(row(a, 1), { d: a[0] }));
      RAW_H = (json.hours || []).map(a => Object.assign(row(a, 2), { d: a[0], h: +a[1] }));
      RAW_PACK_D = (json.pack_days || []).map(a => Object.assign(row(a, 1), { d: a[0] }));
      PICK = json.pick_minutes || {}; NAMES = json.names || {};
      const days = [...new Set(RAW_D.map(r => r.d))].sort();
      DATA_MIN = days[0] || ''; DATA_MAX = days[days.length - 1] || '';
      TODAY = (json.asof || '').slice(0, 10) || DATA_MAX;
      ['ops-from', 'ops-to'].forEach(id => { $(id).min = DATA_MIN; $(id).max = TODAY; });
      applyPreset($('ops-preset').value, true);
    }
    const inRange = r => (!range.from || r.d >= range.from) && (!range.to || r.d <= range.to);
    function derive() {
      D = RAW_D.filter(inRange); H = RAW_H.filter(inRange); PACK_D = RAW_PACK_D.filter(inRange);
      ALL_DAYS = [...new Set(D.map(r => r.d))].sort();
      const m = new Map();
      D.forEach(r => {
        if (!m.has(r.u)) m.set(r.u, Object.assign(z(), { u: r.u, days: new Set() }));
        const a = m.get(r.u); add(a, r); a.days.add(r.d);
      });
      PU = [...m.values()];
      // Derive packer data
      const mp = new Map();
      PACK_D.forEach(r => {
        if (!mp.has(r.u)) mp.set(r.u, Object.assign(z(), { u: r.u, days: new Set() }));
        const a = mp.get(r.u); add(a, r); a.days.add(r.d);
      });
      PAC = [...mp.values()];
      // Shared-login check (within the selected dates): a large share of orders at a small fraction of everyone else's pick time.
      const total = PU.reduce((a, p) => a + p.o, 0);
      FLAG = null;
      PU.forEach(p => {
        if (PICK[p.u] == null || !total) return;
        const others = PU.filter(x => x.u !== p.u && PICK[x.u] != null);
        const w = others.reduce((a, x) => a + x.o, 0);
        const avgOthers = w ? others.reduce((a, x) => a + PICK[x.u] * x.o, 0) / w : null;
        if (avgOthers && p.o / total >= 0.2 && PICK[p.u] < avgOthers * 0.25 && (!FLAG || p.o > FLAG.o))
          FLAG = { u: p.u, o: p.o, share: p.o / total, pm: PICK[p.u], others: avgOthers };
      });
    }

    // ----- date range -----
    const iso = dt => dt.toISOString().slice(0, 10);
    const parseIso = d => new Date(d + 'T00:00:00Z');
    const shiftDays = (d, n) => { const x = parseIso(d); x.setUTCDate(x.getUTCDate() + n); return iso(x); };
    const monthStart = d => d.slice(0, 8) + '01';
    const monthEnd = d => { const x = parseIso(monthStart(d)); x.setUTCMonth(x.getUTCMonth() + 1); x.setUTCDate(0); return iso(x); };
    const quarterStart = d => d.slice(0, 4) + '-' + String(Math.floor((+d.slice(5, 7) - 1) / 3) * 3 + 1).padStart(2, '0') + '-01';
    const quarterEnd = d => { const x = parseIso(quarterStart(d)); x.setUTCMonth(x.getUTCMonth() + 3); x.setUTCDate(0); return iso(x); };
    function presetRange(p) {
      const t = TODAY;
      if (!t) return ['', ''];
      switch (p) {
        case '7': case '30': case '90': return [shiftDays(t, -(+p - 1)), t];
        case 'this_month': return [monthStart(t), t];
        case 'last_month': { const e = shiftDays(monthStart(t), -1); return [monthStart(e), e]; }
        case 'this_quarter': return [quarterStart(t), t];
        case 'last_quarter': { const e = shiftDays(quarterStart(t), -1); return [quarterStart(e), e]; }
        case 'ytd': return [t.slice(0, 4) + '-01-01', t];
        default: return ['', ''];
      }
    }
    function applyPreset(p, quiet) {
      if (p !== 'custom') {
        const [f, t] = presetRange(p);
        range.from = f; range.to = t;
        $('ops-from').value = f || DATA_MIN; $('ops-to').value = t || TODAY;
      }
      derive(); fillUsers(); setRange();
      if (!quiet) render();
    }
    function onDateInput() {
      let f = $('ops-from').value, t = $('ops-to').value;
      if (f && t && f > t) { [f, t] = [t, f]; $('ops-from').value = f; $('ops-to').value = t; }
      range.from = f || '';
      range.to = t || '';
      $('ops-preset').value = 'custom';
      derive(); fillUsers(); setRange(); render();
    }
    // First and last days covered by the current selection, for marking partial months/quarters
    const selStart = () => range.from && range.from > DATA_MIN ? range.from : DATA_MIN;
    const selEnd = () => range.to && range.to < TODAY ? range.to : TODAY;

    function keysFor() {
      if (st.p === 'hour') { const k = []; for (let h = 5; h <= 23; h++) k.push(h); return k; }
      return [...new Set(ALL_DAYS.map(pKey))].sort();
    }
    function label(key, long) {
      if (st.p === 'hour') { const h = String(key).padStart(2, '0'); return long ? h + ':00–' + h + ':59' : h + ':00'; }
      if (st.p === 'day') return long ? MONTHS_LONG[+key.slice(5, 7) - 1] + ' ' + (+key.slice(8, 10)) : fmtDay(key);
      let s, ps, pe;
      if (st.p === 'month') {
        s = (long ? MONTHS_LONG : MONTHS)[+key.slice(5, 7) - 1] + ' ' + key.slice(0, 4);
        ps = key + '-01'; pe = monthEnd(ps);
      } else {
        s = key.slice(5) + ' ' + key.slice(0, 4);
        ps = key.slice(0, 4) + '-' + String((+key.slice(6) - 1) * 3 + 1).padStart(2, '0') + '-01'; pe = quarterEnd(ps);
      }
      const a = selStart(), b = selEnd(), cutStart = a > ps, cutEnd = b < pe;
      if (!long) return s + (cutStart || cutEnd ? '*' : '');
      if (cutStart && cutEnd) return s + ` (${fmtDay(a)}–${fmtDay(b)})`;
      if (cutStart) return s + ` (from ${fmtDay(a)})`;
      if (cutEnd) return s + (b === TODAY ? ' (to date)' : ` (to ${fmtDay(b)})`);
      return s;
    }
    function series(filter) {
      const keys = keysFor(), map = new Map(keys.map(k => [k, z()]));
      const data = st.mode === 'packer' ? PACK_D : D;
      if (st.p === 'hour') H.filter(filter).forEach(r => { if (map.has(r.h)) add(map.get(r.h), r); });
      else data.filter(filter).forEach(r => add(map.get(pKey(r.d)), r));
      return { keys, vals: keys.map(k => map.get(k)) };
    }
    const daysFor = u => {
      const users = st.mode === 'packer' ? PAC : PU;
      return u === 'all' ? ALL_DAYS.length : (users.find(p => p.u === +u) || { days: new Set() }).days.size;
    };

    // ----- colours from the app's own theme variables -----
    const probe = document.createElement('span'); probe.style.display = 'none'; document.body.appendChild(probe);
    function rgb(expr, fallback) {
      probe.style.color = ''; probe.style.color = fallback; probe.style.color = expr;
      const m = getComputedStyle(probe).color.match(/[\d.]+/g) || [0, 0, 0];
      return m.slice(0, 3).map(Number);
    }
    const mix = (a, b, t) => 'rgb(' + a.map((v, i) => Math.round(v * (1 - t) + b[i] * t)).join(',') + ')';
    const css = c => 'rgb(' + c.join(',') + ')';
    const alpha = (c, a) => 'rgba(' + c.join(',') + ',' + a + ')';
    function theme() {
      const brand = rgb('var(--brand)', '#0f766e'), surface = rgb('var(--surface)', '#ffffff'),
        ink = rgb('var(--inv-ink)', '#1f2937'), muted = rgb('var(--text-muted)', '#6b7280'),
        grid = rgb('var(--inv-grid)', '#e5e7eb'), border = rgb('var(--border)', '#d1d5db');
      return {
        accent: css(brand), soft: mix(brand, surface, 0.78), ink: css(ink), muted: css(muted), grid: css(grid), rule: css(border), surface: css(surface),
        s: mix(brand, surface, 0.6), m: css(brand), l: mix(brand, ink, 0.5), flag: alpha(muted, 0.55), brandRgb: brand,
        font: getComputedStyle(document.body).fontFamily
      };
    }

    // ----- chart helpers -----
    function base(T, tip, more) {
      return Object.assign({
        responsive: true, maintainAspectRatio: false, animation: false, interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false }, zoom: false,
          tooltip: Object.assign({ backgroundColor: T.surface, titleColor: T.ink, bodyColor: T.ink, footerColor: T.muted, borderColor: T.rule, borderWidth: 1, padding: 10, boxPadding: 4 }, tip || {})
        }
      }, more || {});
    }
    function axes(T, o) {
      o = o || {};
      const tick = { color: T.muted, font: { size: 12 } };
      const val = {
        stacked: !!o.stacked, beginAtZero: true, grid: { color: T.grid }, border: { display: false }, max: o.xMax,
        ticks: Object.assign({}, tick, o.pct ? { callback: v => v + '%' } : {}),
        title: { display: !!o.yTitle, text: o.yTitle || '', color: T.muted, font: { size: 12 } }
      };
      const cat = { stacked: !!o.stacked, grid: { display: false }, border: { color: T.rule }, ticks: Object.assign({}, tick, { autoSkip: true, maxRotation: 0, autoSkipPadding: 12 }) };
      return o.horizontal ? { x: val, y: Object.assign(cat, { ticks: Object.assign({}, tick, { autoSkip: false }) }) } : { x: cat, y: val };
    }
    function make(id, cfg) { if (charts[id]) charts[id].destroy(); charts[id] = new Chart($(id), cfg); }
    function sizeTall(id, n) { $(id).style.height = Math.max(220, n * 26 + 40) + 'px'; }

    // ----- render -----
    function kpis() {
      const t = D.filter(inUser).reduce(add, z()), days = daysFor(st.u), n = sized(t);
      const who = st.u === 'all' ? 'All pickers' : name(+st.u);
      const k = [[fmt(t.o), 'Orders completed', who], [fmt(days), 'Working days', 'with at least one order'],
      [f1(t.o / days), 'Orders per working day', ''], [f1(t.k / n), 'SKU lines per order', 'average'], [f1(t.q / n), 'Units per order', 'average, verified qty']];
      $('ops-kpis').innerHTML = k.map(([v, l, m]) => `<div class="reports-summary-card"><div class="reports-summary-card__label">${esc(l)}</div><div class="reports-summary-card__value">${esc(v)}</div><div class="reports-summary-card__meta">${esc(m)}</div></div>`).join('');
    }
    function flagNote() {
      $('ops-flagbox').hidden = !FLAG;
      const el = $('ops-flagnote');
      if (!FLAG || st.flag) { el.hidden = true; return; }
      el.hidden = false;
      el.innerHTML = `<strong>${esc(name(FLAG.u))} is left out of picker comparisons.</strong> This account closed ${fmt(FLAG.o)} orders, ${Math.round(FLAG.share * 100)}% of the total, at an average of ${f1(FLAG.pm)} minutes per pick against about ${Math.round(FLAG.others)} minutes for everyone else. That pace and volume can mean a shared or bulk-verification login rather than one person's work. Its orders still count in every total and time chart.`;
      $('ops-flaglabel').textContent = 'Include account #' + FLAG.u + ' in picker comparisons';
    }
    function render() {
      const data = st.mode === 'packer' ? PACK_D : D;
      const users = st.mode === 'packer' ? PAC : PU;
      const filter = st.u === 'all' ? () => true : r => r.u === +st.u;
      if (panels.ops.hidden || !window.Chart || !RAW_D.length) return;
      if (!data.length) {
        Object.keys(charts).forEach(k => { charts[k].destroy(); delete charts[k]; });
        $('ops-kpis').innerHTML = '';
        const msg = st.mode === 'packer' ? 'No packed orders in the selected dates.' : 'No completed orders in the selected dates.';
        ['ops-cap1', 'ops-cap2', 'ops-cap3'].forEach(id => { $(id).textContent = msg; });
        $('ops-flagnote').hidden = true; $('ops-flagbox').hidden = true;
        return;
      }
      PERIOD_DAYS = {};
      ALL_DAYS.forEach(d => { const k = pKey(d); (PERIOD_DAYS[k] = PERIOD_DAYS[k] || new Set()).add(d); });
      const T = theme();
      Chart.defaults.font.family = T.font;
      document.querySelectorAll('.ops-sw').forEach(s => { s.style.background = T[s.dataset.tier]; });
      kpis(); flagNote();
      const role = st.mode === 'packer' ? 'packers' : 'pickers';
      const who = st.u === 'all' ? `all ${role}` : name(+st.u);
      const per = { hour: 'by hour of day', day: 'by day', month: 'by month', quarter: 'by quarter' }[st.p];
      const S = series(filter), ALL = series(() => true), labels = S.keys.map(k => label(k));
      const asBars = st.p === 'hour' || st.p === 'quarter';
      const dayCount = daysFor(st.u);
      const perDay = (k, v) => {
        if (st.p === 'hour') return v.o / dayCount;
        if (st.p === 'day') return null;
        const days = st.u === 'all' ? (PERIOD_DAYS[k] || new Set()).size : new Set(D.filter(r => r.u === +st.u && pKey(r.d) === k).map(r => r.d)).size;
        return days ? v.o / days : null;
      };
      const titleCb = i => label(S.keys[i[0].dataIndex], true);

      // 1. Orders completed
      $('ops-t1').innerHTML = `Orders ${per} <span>${esc(who)}</span>`;
      make('ops-c1', {
        type: asBars ? 'bar' : 'line',
        data: {
          labels, datasets: [{
            label: 'Orders', data: S.vals.map(v => v.o), borderColor: T.accent, backgroundColor: asBars ? T.accent : T.soft, fill: !asBars,
            borderWidth: 2, pointRadius: st.p === 'month' ? 3 : 0, pointHoverRadius: 4, tension: .25, cubicInterpolationMode: 'monotone', borderRadius: 3, maxBarThickness: 64
          }]
        },
        options: base(T, { callbacks: { title: titleCb, label: c => { const k = S.keys[c.dataIndex], v = S.vals[c.dataIndex], pd = perDay(k, v); return ` ${fmt(v.o)} orders` + (pd != null ? `, ${f1(pd)} per working day` : ''); } } },
          { scales: axes(T, { yTitle: 'Orders' }) })
      });
      const peak = S.vals.reduce((b, v, i) => v.o > b.v ? { v: v.o, i } : b, { v: -1, i: 0 }), tot = S.vals.reduce((a, v) => a + v.o, 0);
      const pdPeak = perDay(S.keys[peak.i], S.vals[peak.i]);
      const msg = st.mode === 'packer' ? 'No packed orders' : 'No completed orders';
      $('ops-cap1').textContent = tot ? `${ordersTxt(tot)} for ${who}. Busiest ${st.p === 'hour' ? 'hour' : st.p}: ${label(S.keys[peak.i], true)} with ${ordersTxt(peak.v)}${pdPeak != null ? ` (${f1(pdPeak)} per working day)` : ''}.` : `${msg} for ${who}.`;

      const userList = users.filter(p => st.flag || !FLAG || p.u !== FLAG.u);
      const isFlag = u => FLAG && u === FLAG.u, hi = u => st.u !== 'all' && +st.u === u;
      const uColor = u => isFlag(u) ? T.flag : (st.u === 'all' || hi(u)) ? T.accent : T.soft;
      const byO = [...userList].sort((a, b) => b.o - a.o); sizeTall('ops-w1u', byO.length);
      make('ops-c1u', {
        type: 'bar', data: { labels: byO.map(p => name(p.u)), datasets: [{ data: byO.map(p => p.o), backgroundColor: byO.map(p => uColor(p.u)), borderRadius: 3, barThickness: 16 }] },
        options: base(T, { mode: 'nearest', intersect: true, callbacks: { label: c => { const p = byO[c.dataIndex]; return [` ${fmt(p.o)} orders over ${p.days.size} days`, ` ${f1(p.o / p.days.size)} per day worked`].concat(isFlag(p.u) ? [' Flagged: possible shared login'] : []); } } },
          { indexAxis: 'y', scales: axes(T, { horizontal: true }) })
      });

      // 2. Order size mix
      $('ops-t2').innerHTML = `Orders by size ${per} <span>${esc(who)}</span>`;
      const tier = (k, lab, col) => ({ label: lab, data: S.vals.map(v => v[k]), backgroundColor: col, stack: 'a', maxBarThickness: 64 });
      make('ops-c2', {
        type: 'bar', data: { labels, datasets: [tier('s', 'Small, 1–4 SKUs', T.s), tier('m', 'Medium, 5–15 SKUs', T.m), tier('l', 'Large, 16+ SKUs', T.l)] },
        options: base(T, { callbacks: { title: titleCb, label: c => { const n = sized(S.vals[c.dataIndex]); return ` ${c.dataset.label}: ${fmt(c.raw)}${n ? ` (${Math.round(c.raw / n * 100)}%)` : ''}`; } } },
          { scales: axes(T, { stacked: true, yTitle: 'Orders' }) })
      });
      const tt = S.vals.reduce(add, z()), tn = sized(tt);
      $('ops-cap2').textContent = tn ? `For ${who}: ${Math.round(tt.s / tn * 100)}% small, ${Math.round(tt.m / tn * 100)}% medium and ${Math.round(tt.l / tn * 100)}% large, averaging ${f1(tt.k / tn)} SKU lines per order.` : '';
      const mixU = userList.filter(p => p.o >= MIN_ORDERS && sized(p)).sort((a, b) => b.l / sized(b) - a.l / sized(a)); sizeTall('ops-w2u', mixU.length);
      const dim = col => st.u !== 'all' ? col.replace('rgb(', 'rgba(').replace(')', ',0.35)') : col;
      const ut = (k, lab, col) => ({ label: lab, data: mixU.map(p => +(p[k] / sized(p) * 100).toFixed(1)), backgroundColor: mixU.map(p => isFlag(p.u) ? T.flag : hi(p.u) ? col : dim(col)), stack: 'a', barThickness: 16 });
      make('ops-c2u', {
        type: 'bar', data: { labels: mixU.map(p => name(p.u)), datasets: [ut('s', 'Small', T.s), ut('m', 'Medium', T.m), ut('l', 'Large', T.l)] },
        options: base(T, {
          callbacks: {
            label: c => { const p = mixU[c.dataIndex], k = ['s', 'm', 'l'][c.datasetIndex]; return ` ${c.dataset.label}: ${fmt(p[k])} orders (${Math.round(c.raw)}%)`; },
            footer: i => `${f1(mixU[i[0].dataIndex].k / sized(mixU[i[0].dataIndex]))} SKU lines per order`
          }
        },
          { indexAxis: 'y', scales: axes(T, { horizontal: true, stacked: true, xMax: 100, pct: true }) })
      });

      // 3. Units per order
      $('ops-t3').innerHTML = `Average units per order ${per} <span>${esc(who)}</span>`;
      const avg = v => sized(v) ? +(v.q / sized(v)).toFixed(1) : null;
      const ds = [{
        label: st.u === 'all' ? 'All pickers' : name(+st.u), data: S.vals.map(avg), borderColor: T.accent, backgroundColor: T.accent, borderWidth: 2,
        pointRadius: st.p === 'day' ? 1.5 : 3, pointHoverRadius: 4, tension: .25, cubicInterpolationMode: 'monotone', spanGaps: true
      }];
      if (st.u !== 'all') ds.push({ label: 'All pickers', data: ALL.vals.map(avg), borderColor: T.muted, backgroundColor: T.muted, borderWidth: 1.5, borderDash: [5, 4], pointRadius: 0, tension: .25, cubicInterpolationMode: 'monotone', spanGaps: true });
      const o3 = base(T, { callbacks: { title: titleCb, label: c => { const v = (c.datasetIndex === 0 ? S : ALL).vals[c.dataIndex]; return sized(v) ? ` ${c.dataset.label}: ${f1(v.q / sized(v))} units per order (${fmt(v.q)} units, ${fmt(sized(v))} orders)` : ` ${c.dataset.label}: no orders`; } } },
        { scales: axes(T, { yTitle: 'Units per order' }) });
      o3.plugins.legend = { display: st.u !== 'all', position: 'top', align: 'start', labels: { color: T.muted, boxWidth: 18, boxHeight: 2 } };
      make('ops-c3', { type: 'line', data: { labels, datasets: ds }, options: o3 });
      const pu = S.vals.reduce((b, v, i) => { const a = avg(v); return a != null && a > b.v ? { v: a, i } : b; }, { v: -1, i: 0 });
      $('ops-cap3').textContent = tn ? `${fmt(tt.q)} units picked for ${who}, ${f1(tt.q / tn)} per order on average. Largest average: ${label(S.keys[pu.i], true)} at ${f1(pu.v)} units per order.` : '';
      const uu = userList.filter(p => p.o >= MIN_ORDERS && sized(p)).sort((a, b) => b.q / sized(b) - a.q / sized(a)); sizeTall('ops-w3u', uu.length);
      make('ops-c3u', {
        type: 'bar', data: { labels: uu.map(p => name(p.u)), datasets: [{ data: uu.map(p => +(p.q / sized(p)).toFixed(1)), backgroundColor: uu.map(p => uColor(p.u)), borderRadius: 3, barThickness: 16 }] },
        options: base(T, { mode: 'nearest', intersect: true, callbacks: { label: c => { const p = uu[c.dataIndex]; return [` ${f1(p.q / sized(p))} units per order`, ` ${fmt(p.q)} units across ${fmt(sized(p))} orders`, ` ${f1(p.k / sized(p))} SKU lines per order`]; } } },
          { indexAxis: 'y', scales: axes(T, { horizontal: true }) })
      });
    }

    function fillUsers() {
      const sel = $('ops-user'), cur = sel.value || 'all';
      const label = $('ops-mode-label');
      sel.textContent = '';
      const opt = (v, t) => { const o = document.createElement('option'); o.value = v; o.textContent = t; sel.appendChild(o); };
      if (st.mode === 'packer') {
        label.textContent = 'Packer:';
        opt('all', 'All packers');
        [...PAC].sort((a, b) => b.o - a.o).forEach(p => opt(p.u, name(p.u)));
      } else {
        label.textContent = 'Picker:';
        opt('all', 'All pickers');
        [...PU].sort((a, b) => b.o - a.o).forEach(p => opt(p.u, name(p.u) + (FLAG && p.u === FLAG.u ? ' – flagged' : '')));
      }
      sel.value = [...sel.options].some(o => o.value === cur) ? cur : 'all'; st.u = sel.value;
    }
    function setRange() {
      const longDay = d => `${+d.slice(8, 10)} ${MONTHS_LONG[+d.slice(5, 7) - 1]} ${d.slice(0, 4)}`;
      if (!ALL_DAYS.length) {
        $('ops-range').textContent = range.from || range.to ? `No completed orders between ${longDay(range.from || DATA_MIN)} and ${longDay(range.to || TODAY)}.` : 'No completed orders found yet.';
        return;
      }
      const a = ALL_DAYS[0], b = ALL_DAYS[ALL_DAYS.length - 1];
      $('ops-range').textContent = `Orders completed at the pick stage from ${longDay(a)} to ${longDay(b)}. Times are Eastern.`;
    }
    function status(msg, kind) { const el = $('ops-status'); el.textContent = msg; el.dataset.kind = kind || ''; }

    let busy = false;
    async function load() {
      if (busy) return; busy = true; $('ops-refresh').disabled = true;
      status('Loading…', 'loading');
      try {
        if (!window.Chart) throw new Error('The chart library did not load. Reload the page to try again.');
        const res = await fetch(location.pathname + '?action=order_ops_data', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); } catch (e) { throw new Error(res.redirected || /<form|login/i.test(text) ? 'Your session may have expired. Reload the page and sign in again.' : 'The server sent an unexpected response.'); }
        if (!res.ok || json.error) throw new Error(json.error || ('Server error ' + res.status));
        setData(json); render();
        const [dt, tm] = (json.asof || '').split(' ');
        status(dt ? `Updated ${+dt.slice(8, 10)} ${MONTHS[+dt.slice(5, 7) - 1]} ${tm} Eastern` : 'Updated', 'live');
        if (!RAW_D.length) status('No completed orders found yet.', 'error');
      } catch (e) {
        status(e.message || 'Could not load the report.', 'error');
      } finally { busy = false; $('ops-refresh').disabled = false; }
    }

    // ----- controls -----
    $('ops-user').addEventListener('change', e => { st.u = e.target.value; render(); });
    $('ops-mode-toggle').addEventListener('click', () => {
      st.mode = st.mode === 'picker' ? 'packer' : 'picker';
      st.u = 'all';
      const btn = $('ops-mode-toggle');
      btn.textContent = st.mode === 'packer' ? 'Switch to Picker' : 'Switch to Packer';
      fillUsers();
      render();
    });
    document.querySelectorAll('.ops-seg button').forEach(b => b.addEventListener('click', () => {
      st.p = b.dataset.p;
      document.querySelectorAll('.ops-seg button').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
      render();
    }));
    $('ops-flag').addEventListener('change', e => { st.flag = e.target.checked; render(); });
    $('ops-refresh').addEventListener('click', load);
    $('ops-preset').addEventListener('change', e => applyPreset(e.target.value));
    $('ops-from').addEventListener('change', onDateInput);
    $('ops-to').addEventListener('change', onDateInput);
    // Redraw when the app switches light/dark theme
    const mo = new MutationObserver(() => render());
    mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    mo.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    (mq.addEventListener ? mq.addEventListener('change', render) : mq.addListener(render));

    if (location.hash === '#order-ops') showTab('ops', false);
  })();
</script>

<?php
$content = ob_get_clean();
$footer_js = <<<JS
<script>
  (function () {
    const form = document.getElementById('reports-form');
    const textarea = document.getElementById('manual-sql');
    const presetKeyInput = document.getElementById('preset-key');
    const fabToggle = document.getElementById('reports-fab-toggle');
    const fab = document.getElementById('reports-fab');

    const submitPreset = (presetKey) => {
      if (presetKeyInput) {
        presetKeyInput.value = presetKey;
      }
      if (textarea) {
        textarea.focus();
      }
      if (form) {
        form.requestSubmit();
      }
      if (fab) {
        fab.classList.remove('open');
        if (fabToggle) fabToggle.classList.remove('active');
      }
    };

    if (fabToggle) {
      fabToggle.addEventListener('click', () => {
        fab.classList.toggle('open');
        fabToggle.classList.toggle('active');
      });
    }

    document.addEventListener('click', (e) => {
      if (fab && fabToggle && !fab.contains(e.target) && e.target !== fabToggle) {
        fab.classList.remove('open');
        if (fabToggle) fabToggle.classList.remove('active');
      }
    });

    document.querySelectorAll('.reports-hotkey').forEach(btn => {
      btn.addEventListener('click', () => {
        const presetKey = btn.dataset.presetKey || '';
        if (presetKey) {
          submitPreset(presetKey);
        }
      });
    });

    document.addEventListener('keydown', (e) => {
      if (!e.altKey) return;
      const btn = document.querySelector('.reports-hotkey[data-hotkey="' + e.key + '"]');
      if (!btn) return;
      const presetKey = btn.dataset.presetKey || '';
      if (!presetKey) return;
      e.preventDefault();
      submitPreset(presetKey);
    });
  })();
</script>
JS;
include __DIR__ . '/templates/layout.php';

?>