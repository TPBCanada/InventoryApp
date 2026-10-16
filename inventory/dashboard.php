<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/security.php';
include __DIR__ . '/dbinv.php';


$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

// if (!isset($_SESSION['username'])) {
//    header('Location: ../login.php');
//    exit;
// }

require_once __DIR__ . '/templates/access_control.php';

if (!$conn) {
   die("Connection failed: " . mysqli_connect_error());
}

$history_table = 'inventory_movements';

// ---- helpers ----
function fetch_all($conn, $sql)
{
   $res = mysqli_query($conn, $sql);
   if (!$res) {
      error_log('dashboard.php SQL error: ' . mysqli_error($conn));
      return [];
   }
   $rows = [];
   while ($r = mysqli_fetch_assoc($res))
      $rows[] = $r;
   return $rows;
}
function table_exists($conn, $table)
{
   $t = mysqli_real_escape_string($conn, $table);
   $res = mysqli_query($conn, "SHOW TABLES LIKE '$t'");
   return $res && mysqli_num_rows($res) > 0;
}
function column_exists($conn, $table, $column)
{
   $t = mysqli_real_escape_string($conn, $table);
   $c = mysqli_real_escape_string($conn, $column);
   $res = mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '$c'");
   return $res && mysqli_num_rows($res) > 0;
}

$has_history = table_exists($conn, $history_table);

// Date window: last 30 days
$to_dt = date('Y-m-d 23:59:59');
$from_dt = date('Y-m-d 00:00:00', strtotime('-30 days'));

// ---------- DATA QUERIES (existing) ----------

$active_locations = [];
if ($has_history) {
   // Locations that currently hold stock.
   // Prefer inventory.loc_id when present; otherwise derive location via pallet.
    $active_locations_sql = column_exists($conn, 'inventory', 'loc_id')
    ? "
        SELECT 
            l.id AS loc_id,
            -- NEW FORMATTING HERE:
            CONCAT( l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS full_loc,
            CONCAT(l.row_code, l.bay_num) AS loc_identifier,
            SUM(i.quantity) AS total_qty
        FROM location l
        JOIN inventory i ON i.loc_id = l.id
        WHERE i.quantity > 0
        GROUP BY l.id, full_loc, loc_identifier
        ORDER BY total_qty DESC
    "
    : "
        SELECT 
            l.id AS loc_id,
            -- NEW FORMATTING HERE:
            CONCAT( l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS full_loc,
            CONCAT(l.row_code, l.bay_num) AS loc_identifier,
            SUM(i.quantity) AS total_qty
        FROM location l
        JOIN pallet p ON p.loc_id = l.id
        JOIN inventory i ON i.pallet_id = p.id
        WHERE i.quantity > 0
        GROUP BY l.id, full_loc, loc_identifier
        ORDER BY total_qty DESC
    ";
   $active_locations = fetch_all($conn, $active_locations_sql);
}

$all_locations = [];
if ($has_history) {
   $all_locations = fetch_all($conn, "
        SELECT CONCAT(l.row_code, l.bay_num) AS loc_identifier
        FROM location l
        ORDER BY loc_identifier ASC
    ");
}

$location_movement_data = [];
if ($has_history) {
   $location_movement_data = fetch_all($conn, "
  SELECT 
   DATE(h.created_at) AS move_date,
   CONCAT(l.row_code, l.bay_num) AS loc_identifier,
   h.loc_id,
   SUM(ABS(h.quantity_change)) AS daily_movement
  FROM `$history_table` h
  JOIN location l ON l.id = h.loc_id
  WHERE h.created_at BETWEEN '$from_dt' AND '$to_dt'
  GROUP BY 
   DATE(h.created_at), 
   h.loc_id, 
   l.row_code, 
   l.bay_num
  ORDER BY move_date, loc_identifier
  ");
}

$location_activity_layout = [];
if ($has_history) {
   $location_activity_layout = fetch_all($conn, "
  SELECT
   l.id AS loc_id,
   l.row_code,
   CAST(l.bay_num AS UNSIGNED) AS bay_num,
   l.level_code,
   LEFT(UPPER(l.side), 1) AS side_code,
   COALESCE(SUM(ABS(h.quantity_change)), 0) AS activity_units
  FROM location l
  LEFT JOIN `$history_table` h
    ON h.loc_id = l.id
   AND h.created_at BETWEEN '$from_dt' AND '$to_dt'
   AND h.movement_type IN ('IN', 'OUT', 'TRANSFER', 'ADJUSTMENT', 'ADJUST')
   AND h.quantity_change <> 0
  GROUP BY l.id, l.row_code, l.bay_num, l.level_code, side_code
  ORDER BY l.row_code, CAST(l.bay_num AS UNSIGNED), l.level_code, side_code
  ");
}


// Movement aggregation by Day (0=Sun to 6=Sat) and Hour
$movement_heatmap_data = [];
if ($has_history) {
   $movement_heatmap_data = fetch_all($conn, "
  SELECT 
   DATE(created_at) AS move_date,
   DAYOFWEEK(created_at) AS day_of_week, /* 1 = Sunday, 7 = Saturday */
   HOUR(created_at) AS move_hour,
   SUM(ABS(quantity_change)) AS total_movement
  FROM `$history_table`
  WHERE created_at BETWEEN '$from_dt' AND '$to_dt'
  GROUP BY 
         DATE(created_at), 
         DAYOFWEEK(created_at), 
         HOUR(created_at)
  ORDER BY move_date, move_hour
  ");
}

$latest_movements = [];
if ($has_history) {
   $latest_movements = fetch_all($conn, "
  SELECT s.sku_num,
     s.`desc` AS sku_desc,
     x.sku_id,
     x.last_move,
     COALESCE(m.tot_moved, 0) AS tot_moved
  FROM (
   SELECT sku_id, MAX(created_at) AS last_move
   FROM `$history_table`
   WHERE created_at BETWEEN '$from_dt' AND '$to_dt'
   GROUP BY sku_id
  ) x
  JOIN sku s ON s.id = x.sku_id
  LEFT JOIN (
   SELECT sku_id, SUM(ABS(quantity_change)) AS tot_moved
   FROM `$history_table`
   WHERE created_at BETWEEN '$from_dt' AND '$to_dt'
   GROUP BY sku_id
  ) m ON m.sku_id = x.sku_id
  ORDER BY x.last_move DESC
  LIMIT 10
 ");
}

$last10_in = $has_history ? fetch_all($conn, "
 SELECT h.created_at, s.sku_num, ABS(h.quantity_change) AS qty
 FROM `$history_table` h
 JOIN sku s ON s.id = h.sku_id
 WHERE h.movement_type='IN'
 ORDER BY h.created_at DESC
 LIMIT 10
") : [];

$last10_out = $has_history ? fetch_all($conn, "
 SELECT h.created_at, s.sku_num, ABS(h.quantity_change) AS qty
 FROM `$history_table` h
 JOIN sku s ON s.id = h.sku_id
 WHERE h.movement_type='OUT'
 ORDER BY h.created_at DESC
 LIMIT 10
") : [];

$zero_board = [];
if ($has_history) {
   $zero_board = fetch_all($conn, "
  WITH cur AS (
   SELECT sku_id, SUM(quantity) AS cur_qty
   FROM inventory
   GROUP BY sku_id
  ),
  lm AS (
   SELECT h.sku_id, MAX(h.created_at) AS last_move
   FROM `$history_table` h
   GROUP BY h.sku_id
  ),
  lmr AS (
   SELECT h.sku_id, h.created_at, h.movement_type, ABS(h.quantity_change) AS qty
   FROM `$history_table` h
   JOIN lm ON lm.sku_id = h.sku_id AND lm.last_move = h.created_at
  )
  SELECT s.sku_num,
     s.desc, /* <<< CORRECTED: Using 'desc' from the sku table */
     lmr.created_at AS last_movement_at,
     lmr.qty AS last_move_qty
  FROM cur
  JOIN lmr ON lmr.sku_id = cur.sku_id
  JOIN sku s ON s.id = cur.sku_id
  WHERE cur.cur_qty = 0
   AND lmr.movement_type = 'OUT'
   AND lmr.created_at BETWEEN '$from_dt' AND '$to_dt'
  ORDER BY lmr.created_at DESC
  LIMIT 10
 ");
}

// ---------- NEW: Notifications for New SKUs & Replenished ----------

// New SKUs in last 30 days (first-ever movement within window)
$new_skus = [];
if ($has_history) {
   $new_skus = fetch_all($conn, "
  WITH first_seen AS (
   SELECT sku_id, MIN(created_at) AS first_move
   FROM `$history_table`
   GROUP BY sku_id
  )
  SELECT s.sku_num,
     s.desc, /* <<< CORRECTED: Using 'desc' from the sku table */
     f.first_move
  FROM first_seen f
  JOIN sku s ON s.id = f.sku_id
  WHERE f.first_move BETWEEN '$from_dt' AND '$to_dt'
  ORDER BY f.first_move DESC
  LIMIT 20
 ");
}

// Replenished (back in stock) in last 30 days:
// Heuristic: current qty > 0, there was an IN in last 30 days,
// and the immediate previous movement before that IN was OUT or a negative adjustment.
$replenished = [];
if ($has_history) {
   $replenished = fetch_all($conn, "
  WITH cur AS (
   SELECT sku_id, SUM(quantity) AS cur_qty
   FROM inventory
   GROUP BY sku_id
  ),
  last_in AS (
   SELECT sku_id, MAX(created_at) AS last_in_at
   FROM `$history_table`
   WHERE movement_type='IN' AND created_at BETWEEN '$from_dt' AND '$to_dt'
   GROUP BY sku_id
  ),
  last_in_row AS (
   SELECT h.sku_id, h.created_at, ABS(h.quantity_change) AS qty_in
   FROM `$history_table` h
   JOIN last_in li ON li.sku_id = h.sku_id AND li.last_in_at = h.created_at
  ),
  prev_move_time AS (
   SELECT h.sku_id, MAX(h.created_at) AS prev_at
   FROM `$history_table` h
   JOIN last_in li ON li.sku_id = h.sku_id
   WHERE h.created_at < li.last_in_at
   GROUP BY h.sku_id
  ),
  prev_move AS (
   SELECT h.sku_id, h.movement_type
   FROM `$history_table` h
   JOIN prev_move_time p ON p.sku_id = h.sku_id AND p.prev_at = h.created_at
  )
  SELECT s.sku_num,
     s.desc, /* <<< CORRECTED: Using 'desc' from the sku table */
     lir.created_at AS replenished_at,
     lir.qty_in AS qty_in
  FROM last_in_row lir
  JOIN cur ON cur.sku_id = lir.sku_id
  LEFT JOIN prev_move pm ON pm.sku_id = lir.sku_id
  JOIN sku s ON s.id = lir.sku_id
  WHERE cur.cur_qty > 0
   AND (pm.movement_type IN ('OUT','ADJUSTMENT','ADJUST') OR pm.movement_type IS NULL)
  ORDER BY lir.created_at DESC
  LIMIT 20
 ");
}

$request_table = '';
if (table_exists($conn, 'requests')) {
   $request_table = 'requests';
} elseif (table_exists($conn, 'back_requests')) {
   $request_table = 'back_requests';
}

$current_requests = [];
$request_counts = ['total' => 0, 'high' => 0, 'overdue' => 0];
if ($request_table !== '') {
   $current_requests = fetch_all($conn, "
  SELECT
   r.id,
   r.requested_by,
   r.qty_requested,
   r.picked_qty,
   r.status,
   r.requested_at,
   r.is_high_priority,
   s.sku_num,
   s.`desc` AS sku_desc
  FROM `$request_table` r
  LEFT JOIN sku s ON s.id = r.sku_id
  WHERE r.status = 'OPEN'
  ORDER BY r.is_high_priority DESC, r.requested_at ASC
  LIMIT 20
 ");

   // Full counts (the list above is capped at 20 rows)
   $counts = fetch_all($conn, "
  SELECT
   COUNT(*) AS total,
   COALESCE(SUM(is_high_priority = 1), 0) AS high,
   COALESCE(SUM(requested_at < NOW() - INTERVAL 4 HOUR), 0) AS overdue
  FROM `$request_table`
  WHERE status = 'OPEN'
 ");
   if ($counts) {
      $request_counts = array_map('intval', $counts[0]);
   }
}

$has_pick_orders = table_exists($conn, 'pick_orders');
$my_work_now = [];
if ($has_pick_orders) {
   $my_work_now = fetch_all($conn, "
  SELECT
   p.id,
   p.order_number,
   p.status,
   p.assigned_user_id
  FROM pick_orders p
  WHERE (p.status IS NULL OR p.status <> 'COMPLETED')
  ORDER BY p.updated_at DESC, p.id DESC
 ");
}

// ---------- SHAPE FOR UI ----------
$payload = [
   'has_history' => $has_history,
   'time_heatmap_data' => $movement_heatmap_data,
   'location_heatmap_data' => $location_movement_data,
   'location_activity_layout' => $location_activity_layout,
   'active_locations' => $active_locations,
   'all_location_labels' => array_values(array_unique(array_map(fn($r) => $r['loc_identifier'], $all_locations))),
   'heatmap_data' => $movement_heatmap_data,
   'latest_movements' => [
      'labels' => array_map(function ($r) {
         $sku = trim((string) ($r['sku_num'] ?? ''));
         $desc = trim((string) ($r['sku_desc'] ?? ''));
         $qty = number_format((int) ($r['tot_moved'] ?? 0));
         $head = $desc !== '' ? ($sku . ' - ' . $desc) : $sku;
         return $head . ' - ' . $qty;
      }, $latest_movements),
      'data' => array_map(fn($r) => (int) $r['tot_moved'], $latest_movements),
   ],
   'last10_in' => [
      'labels' => array_map(fn($r) => $r['sku_num'] . ' | ' . date('m/d H:i', strtotime($r['created_at'])), $last10_in),
      'data' => array_map(fn($r) => (int) $r['qty'], $last10_in),
   ],
   'last10_out' => [
      'labels' => array_map(fn($r) => $r['sku_num'] . ' | ' . date('m/d H:i', strtotime($r['created_at'])), $last10_out),
      'data' => array_map(fn($r) => (int) $r['qty'], $last10_out),
   ],
   'zero_board' => $zero_board,
   'new_skus' => $new_skus,
   'replenished' => $replenished,
   'current_requests' => $current_requests,
   'my_work_now' => $my_work_now,
   'meta' => [
      'from' => substr($from_dt, 0, 10),
      'to' => substr($to_dt, 0, 10),
   ]
];

$sku_counts = fetch_all($conn, "
 SELECT
  (SELECT COUNT(*) FROM sku) AS total,
  (SELECT COUNT(DISTINCT sku_id) FROM inventory WHERE quantity > 0) AS in_stock
");
$kpi_total_skus = (int) ($sku_counts[0]['total'] ?? 0);
$kpi_skus_in_stock = (int) ($sku_counts[0]['in_stock'] ?? 0);
$kpi_active_locations = count($active_locations);
$kpi_zero_skus = count($zero_board);
$kpi_new_skus = count($new_skus);
$kpi_replenished = count($replenished);
$kpi_open_requests = $request_counts['total'];
$kpi_my_work = count($my_work_now);
$kpi_unassigned = count(array_filter($my_work_now, fn($r) => (int) ($r['assigned_user_id'] ?? 0) <= 0));



$title = 'Dashboard';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

ob_start();
?>

<div class="dashboard">
   <?php if (!$payload['has_history']): ?>
      <div class="warn">History table <code><?= htmlspecialchars($history_table) ?></code> not found. Movement charts and
         notifications require it.</div>
   <?php else: ?>
      <div class="grid">
         <section class="card bi-kpi-strip">
            <div class="bi-kpis">
               <div class="bi-kpi" data-kpi-filter="skus" role="button" tabindex="0">
                  <div class="bi-kpi__label">Total SKUs</div>
                  <div class="bi-kpi__value"><?= number_format($kpi_total_skus) ?></div>
               </div>
               <div class="bi-kpi" data-kpi-filter="active" role="button" tabindex="0">
                  <div class="bi-kpi__label">Active Locations</div>
                  <div class="bi-kpi__value"><?= number_format($kpi_active_locations) ?></div>
               </div>
               <div class="bi-kpi" data-kpi-filter="zero" role="button" tabindex="0">
                  <div class="bi-kpi__label">Reached Zero</div>
                  <div class="bi-kpi__value"><?= number_format($kpi_zero_skus) ?></div>
               </div>
               <div class="bi-kpi" data-kpi-filter="new" role="button" tabindex="0">
                  <div class="bi-kpi__label">New SKUs</div>
                  <div class="bi-kpi__value"><?= number_format($kpi_new_skus) ?></div>
               </div>
               <div class="bi-kpi" data-kpi-filter="repl" role="button" tabindex="0">
                  <div class="bi-kpi__label">Replenished</div>
                  <div class="bi-kpi__value"><?= number_format($kpi_replenished) ?></div>
               </div>
               <div class="bi-kpi" data-kpi-filter="requests" role="button" tabindex="0">
                  <div class="bi-kpi__label">Open Requests</div>
                  <div class="bi-kpi__value"><?= number_format($kpi_open_requests) ?></div>
               </div>
            </div>
         </section>

         <div class="dash-board">
            <!-- 1. What work is waiting? -->
            <section class="card" id="panelMyWork">
               <div class="bi-section-tag">Work Waiting</div>
               <h2>Open Sales Orders</h2>
               <div class="dash-bignum">
                  <?= number_format($kpi_my_work) ?>
                  <?php if ($kpi_my_work > 0): ?>
                     <span class="dash-bignum__sub"><?= number_format($kpi_unassigned) ?> unassigned</span>
                  <?php endif; ?>
               </div>
               <?php if (!$has_pick_orders): ?>
                  <div class="note">Pick orders table not found.</div>
               <?php elseif (empty($payload['my_work_now'])): ?>
                  <div class="note">No open sales orders.</div>
               <?php else: ?>
                  <table class="table table-requests table-requests--compact">
                     <thead>
                        <tr>
                           <th>Sales Order</th>
                           <th>Status</th>
                           <th>Assigned</th>
                        </tr>
                     </thead>
                     <tbody>
                        <?php foreach ($payload['my_work_now'] as $r): ?>
                           <tr>
                              <td><?= htmlspecialchars((string) ($r['order_number'] ?? '-')) ?></td>
                              <td><?= htmlspecialchars((string) ($r['status'] ?? 'PENDING')) ?></td>
                              <td><?= ((int) ($r['assigned_user_id'] ?? 0) > 0) ? 'Assigned' : 'Unassigned' ?></td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               <?php endif; ?>
            </section>

            <!-- 2. What is urgent? -->
            <section class="card" id="panelRequests">
               <div class="bi-section-tag">Urgent</div>
               <h2>Inventory Requests</h2>
               <div class="dash-bignum">
                  <?= number_format($kpi_open_requests) ?>
                  <?php if ($kpi_open_requests > 0): ?>
                     <span class="dash-bignum__sub">
                        <?= number_format($request_counts['high']) ?> high priority
                        · <span class="<?= $request_counts['overdue'] > 0 ? 'dash-text--critical' : '' ?>"><?= number_format($request_counts['overdue']) ?> past 4h SLA</span>
                     </span>
                  <?php endif; ?>
               </div>
               <?php if ($request_table === ''): ?>
                  <div class="note">Requests table not found.</div>
               <?php elseif (empty($payload['current_requests'])): ?>
                  <div class="note">No open requests.</div>
               <?php else: ?>
                  <table class="table table-requests table-requests--full">
                     <thead>
                        <tr>
                           <th>Requested</th>
                           <th>SKU</th>
                           <th>Qty</th>
                           <th>Age</th>
                           <th>SLA</th>
                           <th>Priority</th>
                           <th>Requester</th>
                        </tr>
                     </thead>
                     <tbody>
                        <?php foreach ($payload['current_requests'] as $r): ?>
                           <?php
                           $qty_open = max(0, (int) ($r['qty_requested'] ?? 0) - (int) ($r['picked_qty'] ?? 0));
                           $ts = !empty($r['requested_at']) ? strtotime((string) $r['requested_at']) : false;
                           $requested_at = $ts ? date('Y-m-d H:i', $ts) : '-';
                           $age_min = $ts ? max(0, (int) floor((time() - $ts) / 60)) : 0;
                           $age_label = $age_min >= 60 ? floor($age_min / 60) . 'h ' . ($age_min % 60) . 'm' : $age_min . 'm';
                           $sla_class = ($age_min >= 240) ? 'sla--critical' : (($age_min >= 120) ? 'sla--warn' : 'sla--ok');
                           $sla_text = ($age_min >= 240) ? '4h+' : (($age_min >= 120) ? '2h+' : 'On Time');
                           ?>
                           <tr>
                              <td><?= htmlspecialchars($requested_at) ?></td>
                              <td title="<?= htmlspecialchars($r['sku_desc'] ?? '') ?>"><?= htmlspecialchars($r['sku_num'] ?? '-') ?></td>
                              <td><?= number_format($qty_open) ?></td>
                              <td><?= htmlspecialchars($age_label) ?></td>
                              <td><span class="sla-chip <?= $sla_class ?>"><?= htmlspecialchars($sla_text) ?></span></td>
                              <td><?= ((int) ($r['is_high_priority'] ?? 0) === 1) ? 'High' : 'Normal' ?></td>
                              <td><?= htmlspecialchars($r['requested_by'] ?? '-') ?></td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               <?php endif; ?>
            </section>

            <!-- 3. What is the inventory state? -->
            <section class="card" id="panelActiveLocations">
               <div class="bi-section-tag">Inventory State</div>
               <h2>Inventory Distribution</h2>
               <?php if (empty($payload['active_locations'])): ?>
                  <div class="note">No locations currently hold stock.</div>
               <?php else: ?>
                  <div class="storage-utilization">
                     <div class="storage-utilization__pie-chart">
                        <canvas id="activeLocationsPie"></canvas>
                     </div>
                     <div class="storage-utilization__legend">
                        <div class="storage-utilization__title">Top Locations</div>
                        <div id="activeLocationsPieLegend"></div>
                        <div class="note">Current on-hand units by location.</div>
                     </div>
                  </div>
               <?php endif; ?>
            </section>

            <section class="card" id="panelAlerts">
               <div class="bi-section-tag">Inventory State</div>
               <h2>Alerts <span class="dash-h2-sub">last 30 days</span></h2>
               <div class="dash-alerts">
                  <?php
                  $alerts = [
                     ['id' => 'alertZero', 'label' => 'Reached Zero', 'tone' => 'critical', 'rows' => $payload['zero_board']],
                     ['id' => 'alertNew', 'label' => 'New SKUs', 'tone' => 'info', 'rows' => $payload['new_skus']],
                     ['id' => 'alertRepl', 'label' => 'Replenished', 'tone' => 'info', 'rows' => $payload['replenished']],
                  ];
                  ?>
                  <?php foreach ($alerts as $a): ?>
                     <?php $n = count($a['rows']); ?>
                     <?php if ($n === 0): ?>
                        <div class="dash-alert dash-alert--clear" id="<?= $a['id'] ?>">
                           <div class="dash-alert__row">
                              <span class="dash-alert__label"><?= $a['label'] ?></span>
                              <span class="dash-alert__count">0 <span class="dash-alert__ok" aria-label="none">✓</span></span>
                           </div>
                        </div>
                     <?php else: ?>
                        <details class="dash-alert dash-alert--<?= $a['tone'] ?>" id="<?= $a['id'] ?>">
                           <summary class="dash-alert__row">
                              <span class="dash-alert__label"><?= $a['label'] ?></span>
                              <span class="dash-alert__count"><?= number_format($n) ?></span>
                           </summary>
                           <table class="table table-notify table-notify--alert">
                              <thead>
                                 <tr>
                                    <th>SKU</th>
                                    <th>Description</th>
                                    <th>When</th>
                                    <th>Qty</th>
                                 </tr>
                              </thead>
                              <tbody>
                                 <?php foreach ($a['rows'] as $r): ?>
                                    <?php
                                    $when = $r['last_movement_at'] ?? $r['first_move'] ?? $r['replenished_at'] ?? null;
                                    $qty = $r['last_move_qty'] ?? $r['qty_in'] ?? null;
                                    ?>
                                    <tr>
                                       <td><?= htmlspecialchars($r['sku_num'] ?? '-') ?></td>
                                       <td title="<?= htmlspecialchars($r['desc'] ?? '') ?>"><?= htmlspecialchars($r['desc'] ?? '-') ?></td>
                                       <td><?= $when ? htmlspecialchars(date('m/d H:i', strtotime($when))) : '-' ?></td>
                                       <td><?= $qty !== null ? number_format((int) $qty) : '-' ?></td>
                                    </tr>
                                 <?php endforeach; ?>
                              </tbody>
                           </table>
                        </details>
                     <?php endif; ?>
                  <?php endforeach; ?>
               </div>
            </section>

            <!-- 4. What recently happened? -->
            <section class="card dash-span-full" id="panelLatest">
               <div class="bi-section-tag">Recent Activity</div>
               <h2>Recent Inventory Movement</h2>
               <div class="dash-movement">
                  <div class="dash-movement__col">
                     <h3>Most Recently Moved SKUs</h3>
                     <?php if (empty($latest_movements)): ?>
                        <div class="note">No movement in the last 30 days.</div>
                     <?php else: ?>
                        <table class="table table-requests table-move--latest">
                           <thead>
                              <tr>
                                 <th>SKU</th>
                                 <th>Description</th>
                                 <th>Moved (30d)</th>
                                 <th>Last Move</th>
                              </tr>
                           </thead>
                           <tbody>
                              <?php foreach ($latest_movements as $r): ?>
                                 <tr>
                                    <td><?= htmlspecialchars($r['sku_num'] ?? '-') ?></td>
                                    <td title="<?= htmlspecialchars($r['sku_desc'] ?? '') ?>"><?= htmlspecialchars($r['sku_desc'] ?? '-') ?></td>
                                    <td><?= number_format((int) ($r['tot_moved'] ?? 0)) ?></td>
                                    <td><?= htmlspecialchars(date('m/d H:i', strtotime($r['last_move']))) ?></td>
                                 </tr>
                              <?php endforeach; ?>
                           </tbody>
                        </table>
                     <?php endif; ?>
                  </div>

                  <?php foreach (['Last 10 In' => $last10_in, 'Last 10 Out' => $last10_out] as $heading => $rows): ?>
                     <div class="dash-movement__col">
                        <h3><?= $heading ?></h3>
                        <?php if (empty($rows)): ?>
                           <div class="note">No movements.</div>
                        <?php else: ?>
                           <table class="table table-requests table-move--io">
                              <thead>
                                 <tr>
                                    <th>Time</th>
                                    <th>SKU</th>
                                    <th>Qty</th>
                                 </tr>
                              </thead>
                              <tbody>
                                 <?php foreach ($rows as $r): ?>
                                    <tr>
                                       <td><?= htmlspecialchars(date('m/d H:i', strtotime($r['created_at']))) ?></td>
                                       <td><?= htmlspecialchars($r['sku_num'] ?? '-') ?></td>
                                       <td><?= number_format((int) ($r['qty'] ?? 0)) ?></td>
                                    </tr>
                                 <?php endforeach; ?>
                              </tbody>
                           </table>
                        <?php endif; ?>
                     </div>
                  <?php endforeach; ?>
               </div>
            </section>
         </div>
      </div>
   <?php endif; ?>
</div>

<?php
$content = ob_get_clean();

$payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);
$dashboard_js_v = @filemtime(__DIR__ . '/js/dashboard.js') ?: time();

$footer_js = <<<HTML
<script>window.DASHBOARD = {$payload_json};</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script defer src="https://cdn.jsdelivr.net/npm/chartjs-chart-matrix@2"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const el = document.getElementById('activeLocationsPie');
  if (!el || !window.DASHBOARD || !Array.isArray(window.DASHBOARD.active_locations)) return;

  // Use the string we already generate in SQL
  function formatLoc(rawLoc) {
    return rawLoc || '-';
  }

  const rows = window.DASHBOARD.active_locations
    .map(r => ({
      label: formatLoc(r.full_loc),
      qty: Number(r.total_qty || 0),
      // include identifiers if you want tighter matching later
      loc_id: r.loc_id ?? null,
      loc_identifier: r.loc_identifier ?? null
    }))
    .filter(r => r.qty > 0);

  if (!rows.length) return;

  const top = rows.slice(0, 5);
  const otherSum = rows.slice(5).reduce((a, b) => a + b.qty, 0);

  const labels = top.map(r => r.label);
  const data = top.map(r => r.qty);
  if (otherSum > 0) {
    labels.push('Other');
    data.push(otherSum);
  }
  const total = data.reduce((a,b)=>a+b,0);

  // Legend text with percentages
    const legend = document.getElementById('activeLocationsPieLegend');
    
    if (legend) {
      legend.innerHTML = labels.map((l, i) => {
        const v = data[i] || 0;
        const pct = total ? (v / total * 100) : 0;
    
        return '<div style="display:flex; justify-content:space-between; gap:10px;">' +
                 '<span>' + l + '</span>' +
                 '<span style="font-weight:600;">' + v.toLocaleString() +
                   ' <span style="font-weight:500; color:#6b7280;">(' + pct.toFixed(1) + '%)</span></span>' +
               '</div>';
      }).join('');
    }


  // Plugin: draw center total
  const centerTotalPlugin = {
    id: 'centerTotalPlugin',
    afterDraw(chart) {
      const { ctx } = chart;
      const meta = chart.getDatasetMeta(0);
      if (!meta || !meta.data || !meta.data.length) return;

      const x = meta.data[0].x;
      const y = meta.data[0].y;

      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';

      ctx.font = '600 18px system-ui, -apple-system, Segoe UI, Roboto, Arial';
      ctx.fillStyle = '#111827';
      ctx.fillText(total.toLocaleString(), x, y - 8);

      ctx.font = '500 12px system-ui, -apple-system, Segoe UI, Roboto, Arial';
      ctx.fillStyle = '#6b7280';
      ctx.fillText('Units', x, y + 12);

      ctx.restore();
    }
  };

  function scrollToHeatmap() {
    const target =
      document.getElementById('panelLocationHeatmap') ||
      document.getElementById('panelHeatmap') ||
      document.getElementById('panelMovement') ||
      document.querySelector('[data-kpi-filter=\"activity\"]') ||
      null;

    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function notifyHeatmapFilter(label) {
    window.dispatchEvent(new CustomEvent('dashboard:filter-location', {
      detail: { label }
    }));
  }

  const chart = new Chart(el, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{ data }]
    },
    plugins: [centerTotalPlugin],
    options: {
      responsive: true,
      cutout: '62%',
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const val = ctx.parsed || 0;
              const pct = total ? (val / total * 100) : 0;
              return ' ' + ctx.label + ': ' + val.toLocaleString() + ' (' + pct.toFixed(1) + '%)';
            }
          }
        }
      },
      onClick: (evt, elements) => {
        if (!elements || !elements.length) return;
        const idx = elements[0].index;
        const label = labels[idx];

        if (label === 'Other') {
          scrollToHeatmap();
          return;
        }

        scrollToHeatmap();
        notifyHeatmapFilter(label);
      }
    }
  });
});
</script>

<script src="{$BASE_URL}/js/dashboard.js?v={$dashboard_js_v}" defer></script>
HTML;



include __DIR__ . '/templates/layout.php';

