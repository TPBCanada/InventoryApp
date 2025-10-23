<?php
// dev/history.php
declare(strict_types=1);

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


date_default_timezone_set('America/Toronto');
$title = 'SKU Location Details';

// ---------------- helpers ----------------

/**
 * Summary of where a SKU is currently on-hand (non-zero).
 */
function getSkuWhereabouts(mysqli $conn, string $skuQuery, int $page = 1, int $limit = 50): array {
  $page  = max(1, $page);
  $limit = max(1, min(200, $limit));
  $offset = ($page - 1) * $limit;
  $like = '%' . $skuQuery . '%';

  $sql = "
    SELECT
      s.sku_num AS sku,
      l.loc_id, l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS location_code,
      COALESCE(SUM(CASE
        WHEN im.movement_type = 'IN'         THEN  im.quantity_change
        WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
        WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
        ELSE 0
      END), 0) AS on_hand,
      MAX(im.created_at) AS last_movement
    FROM inventory_movements im
    INNER JOIN sku s      ON s.id = im.sku_id
    INNER JOIN location l ON l.loc_id = im.loc_id
    WHERE s.sku_num LIKE ?
    GROUP BY s.sku_num, l.loc_id, l.row_code, l.bay_num, l.level_code, l.side
    HAVING on_hand <> 0
    ORDER BY l.row_code, CAST(l.bay_num AS UNSIGNED), l.level_code, l.side
    LIMIT ? OFFSET ?
  ";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return ['_error' => 'Database error (rows): ' . $conn->error, 'rows'=>[], 'grand_total'=>0, 'total_rows'=>0, 'page'=>1, 'pages'=>1];
  $stmt->bind_param('sii', $like, $limit, $offset);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = []; $pageTotal = 0;
  while ($r = $res->fetch_assoc()) {
    $r['on_hand'] = (int)$r['on_hand'];
    $r['last_movement'] = $r['last_movement'] ? date('Y-m-d H:i:s', strtotime($r['last_movement'])) : null;
    $rows[] = $r;
    $pageTotal += (int)$r['on_hand'];
  }
  $stmt->close();

  $countSql = "
    SELECT COUNT(*) AS c FROM (
      SELECT 1
      FROM inventory_movements im
      INNER JOIN sku s      ON s.id = im.sku_id
      INNER JOIN location l ON l.loc_id = im.loc_id
      WHERE s.sku_num LIKE ?
      GROUP BY s.sku_num, l.loc_id, l.row_code, l.bay_num, l.level_code, l.side
      HAVING COALESCE(SUM(CASE
        WHEN im.movement_type = 'IN'         THEN  im.quantity_change
        WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
        WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
        ELSE 0
      END), 0) <> 0
    ) x
  ";
  $cstmt = $conn->prepare($countSql);
  if (!$cstmt) return ['_error' => 'Database error (count): ' . $conn->error, 'rows'=>$rows, 'grand_total'=>0, 'total_rows'=>0, 'page'=>$page, 'pages'=>1];
  $cstmt->bind_param('s', $like);
  $cstmt->execute();
  $cres = $cstmt->get_result();
  $total_rows = (int)($cres->fetch_assoc()['c'] ?? 0);
  $cstmt->close();

  $gtSql = "
    SELECT COALESCE(SUM(on_hand),0) AS grand_total
    FROM (
      SELECT
        COALESCE(SUM(CASE
          WHEN im.movement_type = 'IN'         THEN  im.quantity_change
          WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
          WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
          ELSE 0
        END), 0) AS on_hand
      FROM inventory_movements im
      INNER JOIN sku s      ON s.id = im.sku_id
      INNER JOIN location l ON l.loc_id = im.loc_id
      WHERE s.sku_num LIKE ?
      GROUP BY s.sku_num, l.loc_id, l.row_code, l.bay_num, l.level_code, l.side
      HAVING on_hand <> 0
    ) t
  ";
  $gtstmt = $conn->prepare($gtSql);
  if (!$gtstmt) return ['_error' => 'Database error (grand): ' . $conn->error, 'rows'=>$rows, 'grand_total'=>0, 'total_rows'=>$total_rows, 'page'=>$page, 'pages'=>max(1, (int)ceil($total_rows / $limit))];
  $gtstmt->bind_param('s', $like);
  $gtstmt->execute();
  $gtres = $gtstmt->get_result();
  $grandTotal = (int)($gtres->fetch_assoc()['grand_total'] ?? 0);
  $gtstmt->close();

  return [
    'rows'        => $rows,
    'grand_total' => $grandTotal,
    'total_rows'  => $total_rows,
    'page'        => $page,
    'pages'       => max(1, (int)ceil($total_rows / $limit)),
  ];
}

/**
 * Detailed movement rows for a SKU with running balance per location.
 * Optional $locFilter to narrow by row/bay/level/side.
 * No users table join; shows user_id instead.
 *
 * @param mysqli $conn
 * @param string $skuQuery
 * @param array{row_code?:string,bay_num?:string,level_code?:string,side?:string} $locFilter
 * @return array{rows: array<int, array<string, mixed>>, used_window: bool, _error?: string}
 */
function getSkuMovementDetails(mysqli $conn, string $skuQuery, array $locFilter = []): array {
  $like = '%' . $skuQuery . '%';

  $where = ["s.sku_num LIKE ?"];
  $params = [$like];
  $types  = 's';

  // Optional location narrowing (matches your "View History" link params)
  if (!empty($locFilter['row_code']))   { $where[] = "l.row_code = ?";   $params[] = $locFilter['row_code'];   $types .= 's'; }
  if (!empty($locFilter['bay_num']))    { $where[] = "l.bay_num = ?";    $params[] = $locFilter['bay_num'];    $types .= 's'; }
  if (!empty($locFilter['level_code'])) { $where[] = "l.level_code = ?"; $params[] = $locFilter['level_code']; $types .= 's'; }
  if (!empty($locFilter['side']))       { $where[] = "l.side = ?";       $params[] = $locFilter['side'];       $types .= 's'; }

  $whereSql = implode(' AND ', $where);

  // Try MySQL 8+ window functions first (correct order: ASC in window, DESC for final listing)
  $sqlWin = "
    WITH base AS (
      SELECT
        im.id, s.sku_num AS sku, im.sku_id, im.loc_id,
        l.row_code, l.bay_num, l.level_code, l.side,
        CONCAT(l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS location_code,
        im.movement_type, im.quantity_change, im.reference, im.user_id,
        CAST(im.user_id AS CHAR) AS user_name, im.created_at,
        CASE
          WHEN im.movement_type = 'IN'         THEN  im.quantity_change
          WHEN im.movement_type = 'OUT'        THEN -im.quantity_change
          WHEN im.movement_type = 'ADJUSTMENT' THEN  im.quantity_change
          ELSE 0
        END AS delta
      FROM inventory_movements im
      INNER JOIN sku s      ON s.id = im.sku_id
      INNER JOIN location l ON l.loc_id = im.loc_id
      WHERE $whereSql
    )
    SELECT
      id, sku, sku_id, loc_id, row_code, bay_num, level_code, side, location_code,
      movement_type, quantity_change, reference, user_id, user_name, created_at,
      SUM(delta) OVER (PARTITION BY loc_id ORDER BY created_at ASC, id ASC
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_balance
    FROM base
    ORDER BY created_at DESC, id DESC
  ";

  if ($stmt = $conn->prepare($sqlWin)) {
    $stmt->bind_param($types, ...$params);
    try {
      $stmt->execute();
      $res = $stmt->get_result();
      $rows = [];
      while ($r = $res->fetch_assoc()) {
        $r['quantity_change'] = (int)$r['quantity_change'];
        $r['running_balance'] = (int)$r['running_balance'];
        $r['created_at']      = $r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : null;
        $rows[] = $r;
      }
      $stmt->close();
      return ['rows' => $rows, 'used_window' => true];
    } catch (Throwable $e) {
      $stmt->close();
      // fall through to PHP fallback
    }
  }

  // Fallback (5.7): compute running totals ASC per loc, then output DESC
  $sql = "
    SELECT
      im.id, s.sku_num AS sku, im.sku_id, im.loc_id,
      l.row_code, l.bay_num, l.level_code, l.side,
      CONCAT(l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS location_code,
      im.movement_type, im.quantity_change, im.reference, im.user_id,
      CAST(im.user_id AS CHAR) AS user_name, im.created_at
    FROM inventory_movements im
    INNER JOIN sku s      ON s.id = im.sku_id
    INNER JOIN location l ON l.loc_id = im.loc_id
    WHERE $whereSql
    ORDER BY im.created_at ASC, im.id ASC
  ";

  $stmt2 = $conn->prepare($sql);
  if (!$stmt2) {
    return ['_error' => 'Database error (details): ' . $conn->error, 'rows'=>[], 'used_window'=>false];
  }

  $stmt2->bind_param($types, ...$params);
  $stmt2->execute();
  $res = $stmt2->get_result();

  $accumulated = [];   // store rows with running_balance computed
  $balances    = [];   // per loc_id

  while ($r = $res->fetch_assoc()) {
    $loc = (string)$r['loc_id'];
    if (!isset($balances[$loc])) $balances[$loc] = 0;

    $delta = 0;
    if     ($r['movement_type'] === 'IN')         $delta = (int)$r['quantity_change'];
    elseif ($r['movement_type'] === 'OUT')        $delta = -(int)$r['quantity_change'];
    elseif ($r['movement_type'] === 'ADJUSTMENT') $delta = (int)$r['quantity_change'];

    $balances[$loc] += $delta;

    $r['running_balance'] = $balances[$loc];
    $r['quantity_change'] = (int)$r['quantity_change'];
    $r['created_at']      = $r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : null;

    $accumulated[] = $r;
  }
  $stmt2->close();

  // Present newest first to match your UI
  usort($accumulated, function ($a, $b) {
    $ta = strtotime($a['created_at'] ?? '1970-01-01 00:00:00');
    $tb = strtotime($b['created_at'] ?? '1970-01-01 00:00:00');
    if ($ta === $tb) return $b['id'] <=> $a['id'];
    return $tb <=> $ta;
  });

  return ['rows' => $accumulated, 'used_window' => false];
}


// ------------- inputs -------------
$sku_list_result = mysqli_query($conn, "SELECT sku_num FROM sku ORDER BY sku_num ASC LIMIT 500");
$sku_search = trim($_GET['sku'] ?? '');
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit      = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;

$summary  = ['rows' => [], 'grand_total' => 0, 'total_rows'=>0, 'page'=>1, 'pages'=>1];
$details  = ['rows' => [], 'used_window' => true];
$error    = '';

$locFilter = [
  'row_code'   => trim($_GET['row_code']   ?? ''),
  'bay_num'    => trim($_GET['bay_num']    ?? ''),
  'level_code' => trim($_GET['level_code'] ?? ''),
  'side'       => trim($_GET['side']       ?? ''),
];

if ($sku_search !== '') {
  $summary = getSkuWhereabouts($conn, $sku_search, $page, $limit);
  if (isset($summary['_error'])) {
    $error = $summary['_error'];
    $summary = ['rows' => [], 'grand_total' => 0, 'total_rows'=>0, 'page'=>1, 'pages'=>1];
  } else {
    // pass location filter only if any field is present
    $details = getSkuMovementDetails($conn, $sku_search, array_filter($locFilter, fn($v) => $v !== ''));
    if (isset($details['_error'])) {
      $error = $details['_error'];
      $details = ['rows' => [], 'used_window' => false];
    }
  }
}


// ------------- view -------------
ob_start();
?>



  <h2 class="title">Transaction History</h2>

  <div class="card card--pad">
    <form class="form" method="get" action="">
      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <!-- CHG: mobile keyboard for SKU -->
        <input
          class="input"
          list="sku_list"
          name="sku"
          value="<?= htmlspecialchars($sku_search) ?>"
          placeholder="Type or pick a SKU…"
          autocomplete="off"
          inputmode="numeric"
          pattern="[0-9]*"
        />
        <datalist id="sku_list">
          <?php if ($sku_list_result): ?>
            <?php while ($sku = mysqli_fetch_assoc($sku_list_result)) : ?>
              <option value="<?= htmlspecialchars($sku['sku_num']) ?>"></option>
            <?php endwhile; ?>
          <?php endif; ?>
        </datalist>

        <!-- CHG: mobile numeric keyboard for limit field -->
        <!--<input-->
        <!--  class="input"-->
        <!--  type="number"-->
        <!--  inputmode="numeric"-->
        <!--  name="limit"-->
        <!--  min="1"-->
        <!--  max="200"-->
        <!--  value="<?= (int)$limit ?>"-->
        <!--  title="Rows per page"-->
        <!--  style="width:110px"-->
        <!--/>-->

        <button class="btn btn--primary" type="submit">Search</button>
        <a class="btn btn--ghost" href="history.php">Reset</a>
      </div>
      <?php if ($error): ?><p class="error" style="margin-top:8px"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    </form>
  </div>

  <!-- Summary block (existing) -->
  <div class="card card--pad">
    <?php if ($sku_search === ''): ?>
      <div class="empty">Enter a SKU to see all locations currently holding it (based on movement history).</div>
    <?php else: ?>
      <div class="meta" style="display:flex; gap:16px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
        <div>Results for <b><?= htmlspecialchars($sku_search) ?></b></div>
        <div>On-hand total across all locations: <b><?= (int)$summary['grand_total'] ?></b></div>
        <div>Matches: <b><?= (int)$summary['total_rows'] ?></b></div>
        <?php if ($summary['pages'] > 1): ?>
          <div>Page <b><?= (int)$summary['page'] ?></b> of <b><?= (int)$summary['pages'] ?></b></div>
        <?php endif; ?>
      </div>

      <?php if (empty($summary['rows'])): ?>
        <div class="empty">No on-hand quantity found for this SKU.</div>
      <?php else: ?>
        <!-- CHG: make the summary table responsive + stackable -->
        <div class="table-wrap table-responsive">
          <table class="table table-stack">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Location</th>
                <th>On-Hand</th>
                <th>Last Movement</th>
                <th>Links</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($summary['rows'] as $r): ?>
              <tr>
                <td data-label="SKU"><?= htmlspecialchars($r['sku']) ?></td>
                <td data-label="Location"><?= htmlspecialchars($r['location_code']) ?></td>
                <td data-label="On-Hand" class="qty"><?= (int)$r['on_hand'] ?></td>
                <td data-label="Last Movement"><?= $r['last_movement'] ? htmlspecialchars($r['last_movement']) : '—' ?></td>
                <td data-label="Links">
                  <?php
                    $qs = http_build_query([
                      'sku'        => $r['sku'],
                      'row_code'   => $r['row_code'],
                      'bay_num'    => $r['bay_num'],
                      'level_code' => $r['level_code'],
                      'side'       => $r['side'],
                    ]);
                  ?>
                  <a class="link" href="history.php?<?= $qs ?>">View History</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($summary['pages'] > 1): ?>
          <nav class="pager" style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
            <?php
              $baseQs = ['sku'=>$sku_search, 'limit'=>$limit];
              $prev = max(1, $summary['page'] - 1);
              $next = min($summary['pages'], $summary['page'] + 1);
            ?>
            <a class="btn btn--ghost" href="?<?= http_build_query($baseQs + ['page'=>1]) ?>">« First</a>
            <a class="btn btn--ghost" href="?<?= http_build_query($baseQs + ['page'=>$prev]) ?>">‹ Prev</a>
            <span class="btn btn--ghost" style="pointer-events:none">Page <?= (int)$summary['page'] ?> / <?= (int)$summary['pages'] ?></span>
            <a class="btn btn--ghost" href="?<?= http_build_query($baseQs + ['page'=>$next]) ?>">Next ›</a>
            <a class="btn btn--ghost" href="?<?= http_build_query($baseQs + ['page'=>$summary['pages']]) ?>">Last »</a>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- NEW: Detailed movements for this SKU (ALL rows from inventory_movements + running balance) -->
  <?php if ($sku_search !== ''): ?>
  <div class="card card--pad">
    <h2 style="margin-top:0">Detailed Movements for <b><?= htmlspecialchars($sku_search) ?></b></h2>
    <p class="meta" style="margin: 0 0 12px 0;">
      Showing all rows from <code>inventory_movements</code> for this SKU, with a running “Remaining” balance per location.
      <?php if (!$details['used_window']): ?>
        <br><em>Computed in PHP (no window functions detected).</em>
      <?php endif; ?>
    </p>

    <?php if (empty($details['rows'])): ?>
      <div class="empty">No movement rows found.</div>
    <?php else: ?>
      <!-- CHG: make the detailed table responsive + stackable -->
      <div class="table-wrap table-responsive">
        <table class="table table-stack">
          <thead>
            <tr>
              <th>ID</th>
              <th>Location</th>
              <th>Type</th>
              <th>Qty Δ</th>
              <th>Reference</th>
              <th>User</th>
              <th>Timestamp</th>
              <th>Remaining</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($details['rows'] as $r): ?>
              <tr>
                <td data-label="ID"><?= (int)$r['id'] ?></td>
                <td data-label="Location"><?= htmlspecialchars($r['location_code']) ?></td>
                <td data-label="Type"><?= htmlspecialchars($r['movement_type']) ?></td>
                <td data-label="Qty Δ" class="qty">
                  <?= ($r['movement_type']==='OUT' ? '-' : ($r['movement_type']==='IN' ? '+' : '')) . (int)$r['quantity_change'] ?>
                </td>
                <td data-label="Reference"><?= htmlspecialchars($r['reference'] ?? '') ?></td>
                <td data-label="User"><?= htmlspecialchars($r['user_name'] ?? '') ?></td>
                <td data-label="Timestamp"><?= htmlspecialchars($r['created_at'] ?? '') ?></td>
                <td data-label="Remaining" class="qty"><b><?= (int)$r['running_balance'] ?></b></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>



<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';
