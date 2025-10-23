<?php
// dev/place_view.php — Read-only location details (no adds, no toasts)
session_start();
require_once __DIR__ . '/dbinv.php';

// ---------------------------------------------
// Auth
// ---------------------------------------------
if (!isset($_SESSION['username'])) {
  header('Location: login.php');
  exit;
}

$username = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];
$role_id  = $_SESSION['role_id'] ?? 0;
// ---- db guard ----
if (!$conn) {
  die("DB connection failed.");
}

// ---- fetch SKUs for datalist (optional, handy for jump) ----
$sku_rs = mysqli_query($conn, "SELECT sku_num FROM sku ORDER BY sku_num ASC");
$skus = [];
if ($sku_rs) {
  while ($row = mysqli_fetch_assoc($sku_rs)) $skus[] = $row;
  mysqli_free_result($sku_rs);
}

// ---- fetch all locations to drive the button pickers ----
$loc_rs = mysqli_query($conn, "SELECT row_code, bay_num, level_code, side, loc_id FROM location ORDER BY row_code, bay_num, level_code, side");
$locations = [];
if ($loc_rs) {
  while ($r = mysqli_fetch_assoc($loc_rs)) $locations[] = $r;
  mysqli_free_result($loc_rs);
}

// Build row->allowed sides (to disable Back for R11, etc.)
$row_allowed = [];
foreach ($locations as $l) {
  $r = (string)$l['row_code'];
  $s = (string)$l['side'];
  if (!isset($row_allowed[$r])) $row_allowed[$r] = [];
  if (!in_array($s, $row_allowed[$r], true)) $row_allowed[$r][] = $s;
}

// ---- read selection from GET ----
$row_code   = trim($_GET['row_code']   ?? '');
$bay_num    = trim($_GET['bay_num']    ?? '');
$level_code = trim($_GET['level_code'] ?? '');
$side       = trim($_GET['side']       ?? '');
$hide_zero  = isset($_GET['hide_zero']) ? (int)$_GET['hide_zero'] : 1;

// ---- results ----
$results = [];
$loc_path = '';
if ($row_code !== '' && $bay_num !== '' && $level_code !== '' && $side !== '') {
  $loc_path = "{$row_code}-{$bay_num}-{$level_code}-{$side}";
  // Resolve loc_id
  $loc_id = null;
  $loc_stmt = mysqli_prepare($conn, "SELECT loc_id FROM location WHERE row_code=? AND bay_num=? AND level_code=? AND side=?");
  mysqli_stmt_bind_param($loc_stmt, "ssss", $row_code, $bay_num, $level_code, $side);
  mysqli_stmt_execute($loc_stmt);
  mysqli_stmt_bind_result($loc_stmt, $loc_id);
  mysqli_stmt_fetch($loc_stmt);
  mysqli_stmt_close($loc_stmt);

  if ($loc_id) {
    // Aggregate on-hand per SKU at this location + last movement timestamp
    $sql = "
      SELECT
        s.id            AS sku_id,
        s.sku_num       AS sku_num,
        s.`desc`        AS sku_desc,
        SUM(im.quantity_change) AS on_hand,
        MAX(im.created_at)      AS last_movement
      FROM inventory_movements im
      JOIN sku s ON s.id = im.sku_id
      WHERE im.loc_id = ?
      GROUP BY s.id, s.sku_num, s.`desc`
      " . ($hide_zero ? "HAVING on_hand <> 0" : "") . "
      ORDER BY s.sku_num
    ";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $loc_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
      $results[] = $row;
    }
    mysqli_stmt_close($stmt);
  }
}

// ----------------- page render -----------------
$title = 'Location Details';
$page_class = 'page-place-view';

ob_start();
?>
<h2 class="title">Location Details</h2>

<section class="card card--pad">
  <form method="get" action="">

    <!-- Hidden inputs reflect the selected location from the button pickers -->
    <input type="hidden" name="row_code" id="row_code_input" />
    <input type="hidden" name="bay_num" id="bay_num_input" />
    <input type="hidden" name="level_code" id="level_code_input" />
    <input type="hidden" name="side" id="side_input" />

    <p class="text-muted" style="margin:8px 0 12px;">
      Selected: <strong id="selPath">—</strong>
    </p>

    <div id="locationSelectors" aria-label="Location pickers">
      <div id="rowButtons" class="button-group" role="group" aria-label="Row"></div>
      <div id="bayButtons" class="button-group" role="group" aria-label="Bay"></div>
      <div id="levelButtons" class="button-group" role="group" aria-label="Level"></div>
      <div id="sideButtons" class="button-group" role="group" aria-label="Side"></div>
    </div>

    <div class="row actions">
      <button class="btn btn-outline" type="button" id="clearSel">Clear</button>
      <button class="btn btn--primary" type="submit">Show Results</button>
      <label class="checkbox" style="margin-left:12px;">
        <input type="checkbox" name="hide_zero" value="1" <?php echo $hide_zero ? 'checked' : ''; ?> />
        Hide zero on-hand
      </label>
    </div>
  </form>
</section>

<?php if ($loc_path !== ''): ?>
  <section class="card card--pad">
    <h3 style="margin-top:0;">SKUs at <?php echo htmlspecialchars($loc_path); ?></h3>

    <?php if (!$results): ?>
      <p class="text-muted">No SKUs found<?php echo $hide_zero ? ' (non-zero on‑hand only)' : ''; ?> at this location.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Description</th>
              <th style="text-align:right;">On‑Hand</th>
              <th>Last Movement</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['sku_num']); ?></td>
                <td><?php echo htmlspecialchars($r['sku_desc']); ?></td>
                <td style="text-align:right;"><?php echo (int)$r['on_hand']; ?></td>
                <td><?php echo htmlspecialchars($r['last_movement']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php
$content  = ob_get_clean();

$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// Safe JSON for inline data
$loc_json = json_encode(
  $locations,
  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

// Cache-buster (adjust path if your JS is not /dev/js/location-buttons.js)
$js_fs = __DIR__ . '/js/location-buttons.js';
$ver   = is_file($js_fs) ? filemtime($js_fs) : time();

// Build the script src in PHP so it can’t appear as {$BASE_URL}
$script_src = $BASE_URL . '/js/location-buttons.js?v=' . $ver;

$footer_js = <<<HTML
<script id="loc-data" type="application/json">$loc_json</script>
<script>
  (function(){
    try {
      var el = document.getElementById('loc-data');
      window.PLACE_LOCATIONS = el ? JSON.parse(el.textContent) : [];
    } catch (e) {
      console.error('Failed to parse PLACE_LOCATIONS JSON:', e);
      window.PLACE_LOCATIONS = [];
    }
    // Optional per-page rule
    window.filterSides = function({ row, sides }) {
      if (row === 'R11') return sides.filter(function(s){ return s !== 'Back'; });
      return sides;
    };
  })();
</script>
<script defer src="$script_src"></script>
HTML;

include __DIR__ . '/templates/layout.php';
