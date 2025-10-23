<?php

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
// ---------- DB guard ----------
if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }

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

// Build row 
$row_allowed = []; // e.g. ['R11' => ['Front'], 'R12' => ['Front','Back']]
foreach ($locations as $l) {
  $r = (string)$l['row_code'];
  $s = (string)$l['side']; // 'Front' or 'Back' (or 'F'/'B' in your data)
  if (!isset($row_allowed[$r])) $row_allowed[$r] = [];
  if (!in_array($s, $row_allowed[$r], true)) $row_allowed[$r][] = $s;
}

// ---------- POST: Remove (OUT) ----------
$toast_msg=''; $toast_type='info';
$location_history=[]; $history_heading=''; $show_history=false;

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
          // On-hand balance from movements (IN minus OUT)
          $on_hand = 0;
          $bal_stmt = mysqli_prepare(
            $conn,
            "SELECT COALESCE(SUM(
               CASE WHEN movement_type='IN' THEN quantity_change
                    WHEN movement_type='OUT' THEN -quantity_change
                    ELSE 0 END
             ),0) AS on_hand
             FROM inventory_movements
             WHERE sku_id=? AND loc_id=?"
          );
          mysqli_stmt_bind_param($bal_stmt, "ii", $sku_id, $loc_id);
          mysqli_stmt_execute($bal_stmt);
          mysqli_stmt_bind_result($bal_stmt, $on_hand);
          mysqli_stmt_fetch($bal_stmt);
          mysqli_stmt_close($bal_stmt);

          if ($qty > $on_hand) {
            $errors[] = "Insufficient quantity at this location. On hand: {$on_hand}, requested: {$qty}.";
          } else {
            // Insert OUT movement (store positive quantity with movement_type='OUT')
            $movement_type = 'OUT';
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
              $toast_msg  = "Removed -{$qty} of SKU {$sku_num} from {$row_code}-{$bay_num}-{$level_code}-{$side}.";
              $toast_type = 'success';

              // Recent history (last 25) for this location
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
              $toast_msg  = "Error removing item: " . htmlspecialchars($sql_err);
              $toast_type = 'error';
            }
          }
        }
      }
    }

    if ($errors) { $toast_msg = implode(" ", $errors); $toast_type = 'error'; }
  }
}

// New CSRF each render
$_SESSION['csrf'] = bin2hex(random_bytes(16));

// ---------- Page body ----------
$title = 'Remove Inventory';

// Build the inline builder + tiny helpers (same classes as place.php; 3D buttons come from your CSS)
$locations_json   = json_encode($locations, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$row_allowed_json = json_encode($row_allowed, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

ob_start(); 
?>




  <h2 class="title">Remove Inventory</h2>

  <?php if ($toast_msg): ?>
    <div class="toast <?='is-'.htmlspecialchars($toast_type)?>"><?=htmlspecialchars($toast_msg)?></div>
  <?php endif; ?>

  <section class="card card--pad">
    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>" />

      <div class="row">
        <label class="label" for="sku">SKU:</label>
        <input class="input" list="sku_list" id="sku" name="sku" placeholder="Select or type SKU" required />
        <datalist id="sku_list">
          <?php foreach ($skus as $s): ?>
            <option value="<?=htmlspecialchars($s['sku_num'])?>"></option>
          <?php endforeach; ?>
        </datalist>

        <label class="label" for="quantity">Quantity to Remove:</label>
        <input class="input" id="quantity" name="quantity" type="number" step="1" min="1" placeholder="e.g. 5" required />
      </div>

      <div class="divider"></div>

      <!-- Hidden inputs reflect the selected location -->
      <input type="hidden" name="row_code"   id="row_code_input" />
      <input type="hidden" name="bay_num"    id="bay_num_input" />
      <input type="hidden" name="level_code" id="level_code_input" />
      <input type="hidden" name="side"       id="side_input" />

      <p class="text-muted" style="margin:8px 0 12px;">Selected: <strong id="selPath">—</strong></p>

      <div id="locationSelectors" aria-label="Location pickers">

        <div id="rowButtons" class="button-group" role="group" aria-label="Row"></div>
        <div id="bayButtons" class="button-group" role="group" aria-label="Bay"></div>
        <div id="levelButtons" class="button-group" role="group" aria-label="Level"></div>
        <div id="sideButtons" class="button-group" role="group" aria-label="Side"></div>
      </div>

      <p class="help">Tip: buttons are blue by default and turn gray when selected. Sides auto-disable if a row doesn’t allow them.</p>

      <div class="row actions">
        <button class="btn btn-outline" type="button" id="clearSel">Clear</button>
        <button class="btn btn--primary" type="submit">Remove Inventory</button>
      </div>
    </form>
  </section>

  <?php if ($show_history): ?>
    <section class="card card--pad">
      <h3 style="text-align:center;margin:0 0 12px;"><?=htmlspecialchars($history_heading)?></h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>SKU</th><th>Quantity Change</th><th>Movement</th><th>User</th><th>Date</th></tr>
          </thead>
          <tbody>
          <?php if (!empty($location_history)): foreach ($location_history as $row): ?>
            <?php
              $cls = strtolower($row['movement_type']);
              $qty_display = ($row['movement_type'] === 'OUT' ? '-' : '+') . abs((int)$row['quantity_change']);
              $user_display = $row['user_id'] ? (int)$row['user_id'] : 'System/API';
            ?>
            <tr>
              <td><?=htmlspecialchars($row['sku_num'] ?? '')?></td>
              <td class="<?=$cls?>"><?=htmlspecialchars($qty_display)?></td>
              <td><?=htmlspecialchars($row['movement_type'])?></td>
              <td><?=htmlspecialchars($user_display)?></td>
              <td><?=htmlspecialchars($row['created_at'])?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5">No transactions found for this location.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
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
