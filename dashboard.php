<?php
session_start();
include 'dbinv.php';

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];
$role_id  = $_SESSION['role_id'] ?? 0;

if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }

$history_table = 'inventory_movements'; // history table

// ---- helpers ----
function fetch_all($conn, $sql) {
  $res = mysqli_query($conn, $sql);
  if (!$res) return [];
  $rows = [];
  while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  return $rows;
}
function table_exists($conn, $table){
  $t = mysqli_real_escape_string($conn, $table);
  $res = mysqli_query($conn, "SHOW TABLES LIKE '$t'");
  return $res && mysqli_num_rows($res) > 0;
}

$has_history = table_exists($conn, $history_table);

// Date window: last 30 days
$to_dt   = date('Y-m-d 23:59:59');
$from_dt = date('Y-m-d 00:00:00', strtotime('-30 days'));

// ---------- DATA QUERIES (existing) ----------
$latest_movements = [];
if ($has_history) {
  $latest_movements = fetch_all($conn, "
    SELECT s.sku_num,
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
    SELECT s.sku_num, f.first_move
    FROM first_seen f
    JOIN sku s ON s.id = f.sku_id
    WHERE f.first_move BETWEEN '$from_dt' AND '$to_dt'
    ORDER BY f.first_move DESC
    LIMIT 20
  ");
}

// Replenished (back in stock) in last 30 days:
// Heuristic: current qty > 0, there was an IN in last 30 days,
// and the immediate previous movement before that IN was OUT or a negative ADJUSTMENT.
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
           lir.created_at AS replenished_at,
           lir.qty_in AS qty_in
    FROM last_in_row lir
    JOIN cur ON cur.sku_id = lir.sku_id
    LEFT JOIN prev_move pm ON pm.sku_id = lir.sku_id
    JOIN sku s ON s.id = lir.sku_id
    WHERE cur.cur_qty > 0
      AND (pm.movement_type IN ('OUT','ADJUSTMENT') OR pm.movement_type IS NULL)
    ORDER BY lir.created_at DESC
    LIMIT 20
  ");
}

// ---------- SHAPE FOR UI ----------
$payload = [
  'has_history' => $has_history,
  'latest_movements' => [
    'labels' => array_map(fn($r) => $r['sku_num'], $latest_movements),
    'data'   => array_map(fn($r) => (int)$r['tot_moved'], $latest_movements),
  ],
  'last10_in' => [
    'labels' => array_map(fn($r) => $r['sku_num'] . ' • ' . date('m/d H:i', strtotime($r['created_at'])), $last10_in),
    'data'   => array_map(fn($r) => (int)$r['qty'], $last10_in),
  ],
  'last10_out' => [
    'labels' => array_map(fn($r) => $r['sku_num'] . ' • ' . date('m/d H:i', strtotime($r['created_at'])), $last10_out),
    'data'   => array_map(fn($r) => (int)$r['qty'], $last10_out),
  ],
  'zero_board' => $zero_board,
  // new notifications:
  'new_skus' => $new_skus,
  'replenished' => $replenished,
  'meta' => [
    'from' => substr($from_dt,0,10),
    'to'   => substr($to_dt,0,10),
  ]
];



$title = 'Dashboard';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

ob_start();


?>

<!-- CONTENT -->
<div class="dashboard">
  <?php if (!$payload['has_history']): ?>
    <div class="warn">History table <code><?= htmlspecialchars($history_table) ?></code> not found. Movement charts and notifications require it.</div>
  <?php else: ?>
    <div class="grid">
         <!-- Notifications: Reached Zero -->
      <section class="card" style="grid-column: 1 / -1;">
        <h2>Notifications — Reached Zero Quantity</h2>
        <?php if (empty($payload['zero_board'])): ?>
          <div class="note">No SKUs have reached 0 in the last 30 days.</div>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>SKU</th><th>Last Movement</th><th>Qty (last move)</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($payload['zero_board'] as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['sku_num']) ?></td>
                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['last_movement_at']))) ?></td>
                <td><?= (int)$r['last_move_qty'] ?></td>
                <td><span class="badge">Now at 0</span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <div class="note">Shows SKUs with total quantity = 0 whose latest movement (within 30 days) was an OUT.</div>
      </section>

      <!-- NEW: Notifications: New SKUs + Replenished -->
      <section class="card" style="grid-column: 1 / -1;">
        <h2>Notifications — New SKUs & Replenishments (last 30 days)</h2>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:16px;">

          <!-- New SKUs -->
          <div>
            <h3 style="margin:0 0 8px; font-size:15px;">New SKUs</h3>
            <?php if (empty($payload['new_skus'])): ?>
              <div class="note">No newly-introduced SKUs in the last 30 days.</div>
            <?php else: ?>
              <table class="table">
                <thead><tr><th>SKU</th><th>First Seen</th><th>Status</th></tr></thead>
                <tbody>
                  <?php foreach ($payload['new_skus'] as $r): ?>
                  <tr>
                    <td><?= htmlspecialchars($r['sku_num']) ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['first_move']))) ?></td>
                    <td><span class="badge-ok">New</span></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <!-- Replenished -->
          <div>
            <h3 style="margin:0 0 8px; font-size:15px;">Replenished (Back in Stock)</h3>
            <?php if (empty($payload['replenished'])): ?>
              <div class="note">No replenishments detected in the last 30 days.</div>
            <?php else: ?>
              <table class="table">
                <thead><tr><th>SKU</th><th>Replenished At</th><th>Qty IN</th><th>Status</th></tr></thead>
                <tbody>
                  <?php foreach ($payload['replenished'] as $r): ?>
                  <tr>
                    <td><?= htmlspecialchars($r['sku_num']) ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['replenished_at']))) ?></td>
                    <td><?= (int)$r['qty_in'] ?></td>
                    <td><span class="badge-ok">Back in stock</span></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

        </div>
        <div class="note">“New SKUs” are first seen via any movement in the last 30 days. “Replenished” shows SKUs with current stock &gt; 0 where the last IN in the window was preceded by an OUT/negative adjustment.</div>
      </section>
      <!-- Latest Movements (full width) -->
      <section class="card card--wide">
        <h2>Latest Movements — last 10 SKUs</h2>
        <div class="sub">Window: <?= htmlspecialchars($payload['meta']['from']) ?> → <?= htmlspecialchars($payload['meta']['to']) ?></div>
        <div class="chartbox"><canvas id="chartLatest"></canvas></div>
      </section>

      <!-- Pair: IN / OUT -->
      <div class="chart-pair card--wide">
        <!-- Last 10 IN -->
        <section class="card">
          <h2>Last 10 Items Placed IN</h2>
          <div class="chartbox chartbox--sm"><canvas id="chartIn"></canvas></div>
        </section>

        <!-- Last 10 OUT -->
        <section class="card">
          <h2>Last 10 Items Taken OUT</h2>
          <div class="chartbox chartbox--sm"><canvas id="chartOut"></canvas></div>
        </section>
      </div>
    </div>
  <?php endif; ?>
</div>


<?php
$content = ob_get_clean();

$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);

$footer_js = <<<HTML
  <script>window.DASHBOARD = {$payload_json};</script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="{$BASE_URL}/js/dashboard.js" defer></script>
HTML;

include __DIR__ . '/templates/layout.php';
