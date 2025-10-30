<?php
// invSku.php — SKU → all locations (non-zero only), view-first with fallback to movements
declare(strict_types=1);
session_start();
require_once __DIR__ . '/dbinv.php';

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];
$role_id  = $_SESSION['role_id'] ?? 0;

if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }
$DEBUG = false;
if (!empty($_GET['debug']) && $_GET['debug'] === '1' && ($role_id === 99 /* admin */ || getenv('APP_ENV') === 'dev')) {
  $DEBUG = true;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn->set_charset('utf8mb4');
} catch (\Throwable $_) {
}

$q = trim($_GET['q'] ?? '');

// detect if location PK is loc_id or id
function detect_location_pk(mysqli $conn): string
{
  // Schema shows 'id' is PRI, so this will return 'id'.
  foreach (['loc_id', 'id'] as $cand) {
    try {
      $r = $conn->query("SHOW COLUMNS FROM location LIKE '{$cand}'");
      if ($r && $r->num_rows)
        return $cand;
    } catch (\Throwable $_) {
    }
  }
  return 'loc_id';
}

$loc_pk = detect_location_pk($conn);
$rows = [];
$totals_by_sku = [];
$path_used = 'view'; // 'view' now refers to the inventory table

// -------- helper to run and harvest rows into $rows / $totals --------
$harvest = function (mysqli_result $res) use (&$rows, &$totals_by_sku) {
  while ($r = $res->fetch_assoc()) {
    $r['on_hand'] = (int) $r['on_hand'];
    $r['location_code'] = "{$r['row_code']}-{$r['bay_num']}-{$r['level_code']}-{$r['side']}";
    $rows[] = $r;
    $k = $r['sku_num'];
    if (!isset($totals_by_sku[$k]))
      $totals_by_sku[$k] = ['desc' => $r['sku_desc'], 'sum' => 0];
    $totals_by_sku[$k]['sum'] += $r['on_hand'];
  }
};

if ($q !== '') {
  // 1) Try the NON-ZERO INVENTORY TABLE (inventory table substituted for v_inventory_nonzero)
  $sql_view = "
        SELECT 
            s.sku_num, 
            s.desc AS sku_desc, 
            l.row_code, 
            l.bay_num, 
            l.level_code, 
            l.side, 
            l.{$loc_pk} AS loc_id, 
            i.quantity AS on_hand, -- Corrected: Use quantity from inventory table
            i.updated_at AS last_moved_at -- Optimized: Use updated_at from inventory table
        FROM 
            inventory i
        JOIN 
            sku s ON s.id = i.sku_id 
        JOIN 
            location l ON l.{$loc_pk} = i.loc_id 
        WHERE 
            (s.sku_num LIKE CONCAT('%', ?, '%') OR s.desc LIKE CONCAT('%', ?, '%'))
            AND i.quantity <> 0  -- Filter for non-zero stock
        ORDER BY 
            s.sku_num, 
            l.row_code, 
            CAST(l.bay_num AS UNSIGNED), 
            l.level_code, 
            l.side
    ";
  // NOTE: The separate subquery for last_moved_at is removed for performance.
  // The i.updated_at column is used instead.

  try {
    $stmt = $conn->prepare($sql_view);
    $stmt->bind_param('ss', $q, $q);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
      $harvest($res);
    } else {
      // 2) Fallback: aggregate directly from inventory_movements (non-zero only)
      $path_used = 'movements_fallback';
      // IMPORTANT: We must close the statement before creating a new one!
      $stmt->close();

      $sql_fb = "
                SELECT 
                    s.sku_num, 
                    s.desc AS sku_desc, 
                    l.row_code, 
                    l.bay_num, 
                    l.level_code, 
                    l.side, 
                    l.{$loc_pk} AS loc_id, 
                    SUM(im.quantity_change) AS on_hand, 
                    MAX(im.created_at) AS last_moved_at 
                FROM 
                    inventory_movements im 
                JOIN 
                    sku s ON s.id = im.sku_id 
                JOIN 
                    location l ON l.{$loc_pk} = im.loc_id 
                WHERE 
                    (s.sku_num LIKE CONCAT('%', ?, '%') OR s.desc LIKE CONCAT('%', ?, '%')) 
                GROUP BY 
                    s.id, l.{$loc_pk} 
                HAVING 
                    on_hand <> 0 
                ORDER BY 
                    s.sku_num, 
                    l.row_code, 
                    CAST(l.bay_num AS UNSIGNED), 
                    l.level_code, 
                    l.side
            ";

      $stmt = $conn->prepare($sql_fb);
      $stmt->bind_param('ss', $q, $q);
      $stmt->execute();
      $res = $stmt->get_result();
      $harvest($res);
    }
    $stmt->close();
  } catch (\Throwable $e) {
    if ($DEBUG) {
      http_response_code(500);
      header('Content-Type: text/plain; charset=utf-8');
      echo "SQL error: ", $e->getMessage(), "\n\nPath: {$path_used}\nPK: {$loc_pk}\n";
      echo "SQL(view):\n{$sql_view}\n\n";
      exit;
    }
    throw $e;
  }
}

$title = 'SKU → All Locations';
$page_class = 'page-inv-sku';

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'query'   => $q,
    'rows'    => $rows,            
    'totals'  => $totals_by_sku,   
    'path'    => $path_used,       
  ]);
  exit;
}
ob_start();
?>
<h2 class="title">SKU → All Locations</h2>
<section class="card card--pad">
  <form method="get" action="">
    <div class="flex flex-wrap items-end" style="gap:16px;">
      <div style="flex:1 1 360px; min-width:260px;">
        <label for="q" class="label">Search SKU # or description</label>
        <input type="text" id="q" name="q" value="<?= htmlspecialchars(
          $q
        ) ?>" placeholder="e.g. 20240007 or “hinge”" class="input" autofocus />
      </div>
      <div style="display:flex; gap:8px;">
        <button class="btn btn--primary" type="submit">Search</button>
        <?php if ($q !== ""): ?>
          <a class="btn btn-outline" href="?">Clear</a>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($q !== ""): ?>
    <?php endif; ?>
  </form>
</section>

<?php if ($q !== ""): ?>
  
<section class="card card--pad">
  <?php if (!$rows): ?>
    <p class="text-muted">No matching locations with on-hand &gt; 0.</p>
  <?php else: ?>
    <h3 style="margin:20px 0 8px;">Totals</h3>
    <div class="table-wrap">
      <table class="table table--compact total-summary-table">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Description</th>
            <th style="text-align:right;">Total On-Hand</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($totals_by_sku as $sku => $info): ?>
            <tr class="total-row">
              <td><?= htmlspecialchars((string) $sku) ?></td>
              <td><?= htmlspecialchars(
                $info["desc"]
              ) ?></td>
              <td style="text-align:right;"><strong><?= (int) $info["sum"] ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    
    <h3 style="margin:20px 0 8px;">Locations</h3> <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Description</th>
            <th>Location Code</th>
            <th style="text-align:right;">On-Hand</th>
            <th>Last Movement</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $href =
              "place.php?" .
              http_build_query([
                "row_code" => $r["row_code"],
                "bay_num" => $r["bay_num"],
                "level_code" => $r["level_code"],
                "side" => $r["side"],
                "return" => "invSku.php?" . http_build_query(["q" => $q]),
              ]); ?>
            <tr>
              <td><?= htmlspecialchars(
                (string) $r["sku_num"]
              ) ?></td>
              <td><?= htmlspecialchars(
                $r["sku_desc"]
              ) ?></td>
              <td><a class="link" href="<?= htmlspecialchars(
                $href
              ) ?>"><?= htmlspecialchars(
                 $r["location_code"]
              ) ?></a></td>
              <td style="text-align:right;"><?= (int) $r["on_hand"] ?></td>
              <td><?= htmlspecialchars(
                $r["last_moved_at"] ?? ""
              ) ?></td>
            </tr>
            <?php
          endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . "/templates/layout.php";