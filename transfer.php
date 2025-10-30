<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';
// NEW REQUIRE: Load generic helper functions
require_once __DIR__ . '/utils/helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['username'])) {
  header('Location: login.php'); exit;
}
$username = $_SESSION['username'];
$user_id  = (int)($_SESSION['user_id'] ?? 0);
date_default_timezone_set('America/Toronto');
$title = 'Transfer Inventory';

/* ---------- Helpers ---------- */

// The helper functions (find_loc_id, ensure_location, fetch_stock) have been
// moved to 'helpers.php' and are now available via the 'require_once' above.


/* Live on-hand for a location (hide 0/NULL); used by AJAX + post-transfer display */
// The fetch_stock function has been moved to 'helpers.php'


/* ---------- Inline AJAX: items by location (qty > 0) ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'inv_by_loc') {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=UTF-8');

  if (!isset($_SESSION['username'])) { echo json_encode(['ok'=>false,'error'=>'auth_required']); exit; }

  $row  = trim($_GET['row']  ?? '');
  $bay  = trim($_GET['bay']  ?? '');
  $lvl  = trim($_GET['lvl']  ?? '');
  $side = trim($_GET['side'] ?? '');
  if ($row === '' || $bay === '' || $lvl === '' || $side === '') {
    echo json_encode(['ok'=>false,'error'=>'missing_params']); exit;
  }

  try {
    $sql = "
      SELECT s.id AS sku_id, s.sku_num, SUM(COALESCE(i.quantity,0)) AS quantity
      FROM location L
      JOIN inventory i ON i.loc_id = L.id
      JOIN sku s       ON s.id     = i.sku_id
      WHERE L.row_code = ?
        AND CAST(L.bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
        AND L.level_code = ?
        AND L.side = ?
      GROUP BY s.id, s.sku_num
      HAVING SUM(COALESCE(i.quantity,0)) > 0
      ORDER BY s.sku_num
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssss', $row, $bay, $lvl, $side);
    $stmt->execute();
    $items = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $r['quantity'] = (int)$r['quantity']; $items[] = $r; }
    echo json_encode(['ok'=>true,'items'=>$items]);
  } catch (Throwable $e) {
    error_log('[transfer ajax] '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'server_error']);
  }
  exit;
}

/* ---------- Load selectors for JS ---------- */
$rows = []; $baysByRow=[]; $levelsByRow=[]; $sidesByRow=[];
$res = $conn->query("
  SELECT row_code, bay_num, level_code, side
  FROM location
  ORDER BY row_code, CAST(bay_num AS UNSIGNED), bay_num, level_code, side
");
while ($r = $res->fetch_assoc()) {
  $row=$r['row_code']; $bay=$r['bay_num']; $lvl=$r['level_code']; $side=$r['side'];
  if (!in_array($row,$rows,true)) $rows[]=$row;
  $baysByRow[$row]                 = $baysByRow[$row]                 ?? [];
  $levelsByRow[$row]               = $levelsByRow[$row]               ?? [];
  $levelsByRow[$row][$bay]         = $levelsByRow[$row][$bay]         ?? [];
  $sidesByRow[$row]                = $sidesByRow[$row]                ?? [];
  $sidesByRow[$row][$bay]          = $sidesByRow[$row][$bay]          ?? [];
  $sidesByRow[$row][$bay][$lvl] = $sidesByRow[$row][$bay][$lvl] ?? [];

  if (!in_array($bay,$baysByRow[$row],true))            $baysByRow[$row][]=$bay;
  if (!in_array($lvl,$levelsByRow[$row][$bay],true))    $levelsByRow[$row][$bay][]=$lvl;
  if (!in_array($side,$sidesByRow[$row][$bay][$lvl],true))  $sidesByRow[$row][$bay][$lvl][]=$side;
}

/* ---------- PRG state ---------- */
$toast_msg=''; $toast_type='info'; $show_history=false;
$s_loc_str=''; $d_loc_str=''; $inv_src=[]; $inv_dst=[];
if (isset($_SESSION['toast'])) { $toast_msg=$_SESSION['toast']['msg']??''; $toast_type=$_SESSION['toast']['type']??'info'; unset($_SESSION['toast']); }
if (isset($_SESSION['last_transfer'])) {
  $lt=$_SESSION['last_transfer'];
  $s_loc_str=$lt['s_loc_str']??''; $d_loc_str=$lt['d_loc_str']??'';
  $inv_src=$lt['inv_src']??[]; $inv_dst=$lt['inv_dst']??[];
  $show_history=true; unset($_SESSION['last_transfer']);
}

/* ---------- Transfer handler ---------- */
if (!isset($_SESSION['transfer_token'])) $_SESSION['transfer_token']=bin2hex(random_bytes(32));
$transfer_token=$_SESSION['transfer_token'];

if ($_SERVER['REQUEST_METHOD']==='POST' && $conn) {
  $error_message=null; $success_message=null;

  // Expect hidden fields named exactly like your existing form:
  $transfer_sku = trim((string)($_POST['transfer_sku'] ?? ''));        // sku_num text
  $transfer_qty = (int)($_POST['transfer_qty'] ?? 0);

  $s_row  = trim((string)($_POST['s_row']  ?? ''));
  $s_bay  = trim((string)($_POST['s_bay']  ?? ''));
  $s_lvl  = trim((string)($_POST['s_lvl']  ?? ''));
  $s_side = trim((string)($_POST['s_side'] ?? ''));

  $d_row  = trim((string)($_POST['d_row']  ?? ''));
  $d_bay  = trim((string)($_POST['d_bay']  ?? ''));
  $d_lvl  = trim((string)($_POST['d_lvl']  ?? ''));
  $d_side = trim((string)($_POST['d_side'] ?? ''));

  if ($transfer_sku === '' || $transfer_qty <= 0) {
    $error_message = 'Select a SKU and enter a positive quantity.';
  } elseif (!$s_row || !$s_bay || !$s_lvl || !$s_side || !$d_row || !$d_bay || !$d_lvl || !$d_side) {
    $error_message = 'Missing source or destination details.';
  } else {
    // Resolve ids
    $sku_id = null;
    $st = $conn->prepare("SELECT id FROM sku WHERE sku_num=? LIMIT 1");
    $st->bind_param('s', $transfer_sku);
    $st->execute();
    $sku_id = (int)($st->get_result()->fetch_column() ?: 0);

    // Call moved helper functions
    $srcLoc = ensure_location($conn, $s_row, $s_bay, $s_lvl, $s_side);
    $dstLoc = ensure_location($conn, $d_row, $d_bay, $d_lvl, $d_side);

    if ($sku_id <= 0 || !$srcLoc || !$dstLoc) {
      $error_message = 'Invalid SKU or location.';
    } elseif ($srcLoc === $dstLoc) {
      $error_message = 'Source and destination cannot be the same.';
    } else {
      // Check availability at source from inventory (NULL treated as 0)
      $chk = $conn->prepare("SELECT COALESCE(quantity,0) FROM inventory WHERE sku_id=? AND loc_id=?");
      $chk->bind_param('ii', $sku_id, $srcLoc);
      $chk->execute();
      $avail = (int) ($chk->get_result()->fetch_column() ?: 0);

      if ($transfer_qty > $avail) {
        $error_message = "Transfer quantity ($transfer_qty) exceeds available stock ($avail).";
      } else {
        $pos = $transfer_qty;  // magnitude
        $neg = -$pos;          // signed for movements only
        $ref = "Transfer $pos of $transfer_sku from $s_row-$s_bay-$s_lvl-$s_side to $d_row-$d_bay-$d_lvl-$d_side";
        try {
          $conn->begin_transaction();

          // 1) Source guarded decrement (never below zero)
          $dec = $conn->prepare("
            UPDATE inventory
               SET quantity = COALESCE(quantity,0) - ?
             WHERE sku_id=? AND loc_id=? AND COALESCE(quantity,0) >= ?
          ");
          $dec->bind_param('iiii', $pos, $sku_id, $srcLoc, $pos);
          $dec->execute();
          if ($dec->affected_rows !== 1) { throw new RuntimeException('Insufficient stock at source.'); }

          // 2) Destination upsert increment
          $inc = $conn->prepare("
            INSERT INTO inventory (sku_id, loc_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = COALESCE(quantity,0) + VALUES(quantity)
          ");
          $inc->bind_param('iii', $sku_id, $dstLoc, $pos);
          $inc->execute();

          // 3) Movements — single pair, descriptive reference, correct user id
          $now = (new DateTime('now', new DateTimeZone('America/Toronto')))->format('Y-m-d H:i:s');
          $userLabel = $username ?: ('user#' . (string)$user_id);

          $ref_detail = sprintf(
              'Transfer %d × SKU %s from %s-%s-%s-%s to %s-%s-%s-%s (by %s, %s)',
              $pos,
              $transfer_sku,
              $s_row, $s_bay, $s_lvl, $s_side,
              $d_row, $d_bay, $d_lvl, $d_side,
              $userLabel,
              $now
          );
          // trim to column length (255)
          if (strlen($ref_detail) > 255) {
              $ref_detail = substr($ref_detail, 0, 252) . '...';
          }

          // OUT (negative)
          $mv1 = $conn->prepare("
            INSERT INTO inventory_movements
              (sku_id, loc_id, quantity_change, movement_type, reference, user_id, created_at)
            VALUES (?, ?, ?, 'OUT', ?, ?, NOW())
          ");
          $mv1->bind_param('iisis', $sku_id, $srcLoc, $neg, $ref_detail, $user_id);
          $mv1->execute();

          // IN (positive)
          $mv2 = $conn->prepare("
            INSERT INTO inventory_movements
              (sku_id, loc_id, quantity_change, movement_type, reference, user_id, created_at)
            VALUES (?, ?, ?, 'IN', ?, ?, NOW())
          ");
          $mv2->bind_param('iisis', $sku_id, $dstLoc, $pos, $ref_detail, $user_id);
          $mv2->execute();


          $conn->commit();

          $success_message = "Transfer completed for $transfer_sku (Qty: $pos).";

          // Prepare post-commit display (read on-hand from inventory)
          $s_loc_str = "$s_row-$s_bay-$s_lvl-$s_side";
          $d_loc_str = "$d_row-$d_bay-$d_lvl-$d_side";
          // Call moved helper function
          $inv_src = fetch_stock($conn, $s_row, $s_bay, $s_lvl, $s_side);
          $inv_dst = fetch_stock($conn, $d_row, $d_bay, $d_lvl, $d_side);

        } catch (\Throwable $e) {
          $conn->rollback();
          $error_message = 'Transfer failed: '.$e->getMessage();
          error_log('[transfer] '.$e->getMessage());
        }
      }
    }
  }

  // PRG
  if ($error_message !== null || $success_message !== null) {
    $_SESSION['toast'] = ['msg'=>$error_message ?? $success_message, 'type'=>$error_message ? 'error' : 'success'];
    if ($success_message) {
      $_SESSION['last_transfer'] = [
        's_loc_str'=>$s_loc_str, 'd_loc_str'=>$d_loc_str,
        'inv_src'=>$inv_src, 'inv_dst'=>$inv_dst,
      ];
    }
    $_SESSION['transfer_token'] = bin2hex(random_bytes(32));
    header('Location: transfer.php'); exit;
  }
}

/* ---------- View ---------- */
ob_start();
?>
<h2>Transfer Inventory</h2>

<?php if ($toast_msg): ?>
  <div class="toast <?= htmlspecialchars($toast_type) ?>"><?= htmlspecialchars($toast_msg) ?></div>
<?php else: ?>
  <div class="toast info">Stage 1: search the source location. Stage 2: search the destination. Then choose SKU &amp; qty and approve.</div>
<?php endif; ?>

<form id="transferForm" method="post" autocomplete="off">
  <input type="hidden" name="do_transfer" value="1">
  <!-- set by JS when you click a SKU in source table -->
  <input type="hidden" name="transfer_sku" id="transfer_sku_val">

  <!-- hidden loc fields (JS populates) -->
  <input type="hidden" name="s_row"  id="s_row_val">
  <input type="hidden" name="s_bay"  id="s_bay_val">
  <input type="hidden" name="s_lvl"  id="s_lvl_val">
  <input type="hidden" name="s_side" id="s_side_val">
  <input type="hidden" name="d_row"  id="d_row_val">
  <input type="hidden" name="d_bay"  id="d_bay_val">
  <input type="hidden" name="d_lvl"  id="d_lvl_val">
  <input type="hidden" name="d_side" id="d_side_val">

  <div class="grid-2col">
    <fieldset>
      <legend>1) Source Location</legend>
      <div class="grid grid-4">
        <div><label>Row</label><select id="s_row"></select></div>
        <div><label>Bay</label><select id="s_bay" disabled></select></div>
        <div><label>Level</label><select id="s_lvl" disabled></select></div>
        <div><label>Side</label><select id="s_side" disabled></select></div>
      </div>
      <div class="results">
        <table id="srcTable" aria-label="Source inventory table">
          <thead><tr><th>SKU</th><th>Qty</th></tr></thead>
          <tbody><tr><td class="muted" colspan="2">Pick a full location to load items.</td></tr></tbody>
        </table>
      </div>
    </fieldset>

    <fieldset>
      <legend>2) Destination Location</legend>
      <div class="grid grid-4">
        <div><label>Row</label><select id="d_row"></select></div>
        <div><label>Bay</label><select id="d_bay" disabled></select></div>
        <div><label>Level</label><select id="d_lvl" disabled></select></div>
        <div><label>Side</label><select id="d_side" disabled></select></div>
      </div>
      <div class="results">
        <table id="dstTable" aria-label="Destination inventory table">
          <thead><tr><th>SKU</th><th>Qty</th></tr></thead>
          <tbody><tr><td class="muted" colspan="2">Pick a full location to load items.</td></tr></tbody>
        </table>
      </div>
    </fieldset>
  </div>

  <fieldset style="margin-top:12px;">
    <legend>3) Select SKU</legend>
    <div class="form-row-2">
      <div class="form-col">
        <label for="chosenSkuNum">Chosen SKU (from source results)</label>
        <input id="chosenSkuNum" type="text" placeholder="Click a row in Source results" readonly>
      </div>
      <div class="form-col">
        <label for="qty">Quantity</label>
        <input class="input" type="number" name="transfer_qty" id="qty" min="1" step="1" disabled>
      </div>
      <div class="form-note">Available at source: <span id="avail">—</span></div>
    </div>
  </fieldset>

  <div class="actions">
    <button class="btn" type="submit" id="approveBtn" disabled>Approve Transfer</button>
    <button class="btn secondary" type="reset" id="resetBtn">Reset</button>
  </div>
</form>

<?php if ($show_history): ?>
  <h2 style="margin-top:18px;">Inventory After Transfer</h2>
  <div class="grid-2col">
    <div>
      <h3>Source (<?= htmlspecialchars($s_loc_str) ?>)</h3>
      <table><thead><tr><th>SKU</th><th>Qty</th></tr></thead><tbody>
      <?php if (!$inv_src): ?><tr><td colspan="2" class="muted">No items at this location.</td></tr>
      <?php else: foreach ($inv_src as $it): ?>
        <tr><td><?= htmlspecialchars($it['sku_num']) ?></td><td><?= (int)$it['quantity'] ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody></table>
    </div>
    <div>
      <h3>Destination (<?= htmlspecialchars($d_loc_str) ?>)</h3>
      <table><thead><tr><th>SKU</th><th>Qty</th></tr></thead><tbody>
      <?php if (!$inv_dst): ?><tr><td colspan="2" class="muted">No items at this location.</td></tr>
      <?php else: foreach ($inv_dst as $it): ?>
        <tr><td><?= htmlspecialchars($it['sku_num']) ?></td><td><?= (int)$it['quantity'] ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody></table>
    </div>
  </div>
<?php endif; ?>

<?php
// Boot for transfer.js
$boot = [
  'rows'=>$rows,'baysByRow'=>$baysByRow,'levelsByRow'=>$levelsByRow,'sidesByRow'=>$sidesByRow,
  'ajaxPath'=>$_SERVER['PHP_SELF'],
];
$footer_js = '<script id="transfer-boot" type="application/json">'
  . json_encode($boot, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)
  . '</script>';

$js = isset($js)&&is_array($js) ? $js : [];
$js[] = '/js/transfer.js';

$content = ob_get_clean();
require __DIR__ . '/templates/layout.php';
