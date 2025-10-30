<?php
// invReq.php — Back-Rack Requests Board (OPEN/CLOSED, auto timestamps, total on-hand)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/dbinv.php';

if (!isset($_SESSION['username'])) { header("Location: login.php"); exit; }
$username = $_SESSION['username'];
$user_id  = (int)($_SESSION['user_id'] ?? 0);
$role_id  = (int)($_SESSION['role_id'] ?? 0);

if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (\Throwable $_) {}

// Optional: ensure table has new cols (soft auto-provision)
// Comment out if you’re handling DDL separately.
try {
  $conn->query("SELECT requested_at, status FROM back_requests LIMIT 1");
} catch (\Throwable $__) {
  $conn->query("ALTER TABLE back_requests
    ADD COLUMN requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN'");
  // Best-effort cleanup of old columns; ignore errors if absent
  try { $conn->query("ALTER TABLE back_requests DROP COLUMN date_requested"); } catch (\Throwable $___) {}
  try { $conn->query("ALTER TABLE back_requests DROP COLUMN ready_to_pick"); } catch (\Throwable $___) {}
  try { $conn->query("ALTER TABLE back_requests DROP COLUMN ready_to_ship"); } catch (\Throwable $___) {}
}

function post($k, $d=''){ return trim($_POST[$k] ?? $d); }
function n($v): int { return max(0, (int)$v); }

// ---------- Create (no requester input; use logged-in user) ----------
$flash = null;
if (($_POST['action'] ?? '') === 'create') {
  $requested_by   = $username;                     // <- auto from session
  $sku_num        = post('sku_num');
  $qty_requested  = n(post('qty_requested'));
  $notes          = post('notes');

  if ($sku_num && $qty_requested > 0) {
    $stmt = $conn->prepare("SELECT id FROM sku WHERE sku_num = ?");
    $stmt->bind_param('s', $sku_num);
    $stmt->execute(); $res = $stmt->get_result(); $sku = $res->fetch_assoc(); $stmt->close();

    if (!$sku) {
      $flash = "Unknown SKU: ".htmlspecialchars($sku_num);
    } else {
      $sku_id = (int)$sku['id'];
      $stmt = $conn->prepare("
        INSERT INTO back_requests
          (requested_by, sku_id, qty_requested, notes, created_by, status)
        VALUES (?,?,?,?,?, 'OPEN')
      ");
      $stmt->bind_param('ssisi', $requested_by, $sku_id, $qty_requested, $notes, $user_id);
      $stmt->execute(); $stmt->close();
      $flash = "Request added for {$sku_num}.";
    }
  } else {
    $flash = "Please complete SKU and Qty.";
  }
}


// ---------- Update (status OPEN/CLOSED only; keep bin_qty & notes) ----------
if (($_POST['action'] ?? '') === 'update') {
  $id      = (int)post('id');
  $bin_qty = n(post('bin_qty'));
  $status  = (post('status') === 'CLOSED') ? 'CLOSED' : 'OPEN'; // CHANGED

  $notes   = post('notes');
  $stmt = $conn->prepare("
    UPDATE back_requests
    SET bin_qty=?, status=?, notes=?
    WHERE id=?
  ");
  $stmt->bind_param('issi', $bin_qty, $status, $notes, $id);
  $stmt->execute(); $stmt->close();
  $flash = "Request updated.";
}

// ---------- Delete ----------
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int)post('id');
  $stmt = $conn->prepare("DELETE FROM back_requests WHERE id=?");
  $stmt->bind_param('i', $id);
  $stmt->execute(); $stmt->close();
  $flash = "Request removed.";
}

// ---------- Filters (status open/closed; optional date range on requested_at) ----------
$q          = trim($_GET['q'] ?? '');
$status     = $_GET['status'] ?? ''; // '', 'OPEN', 'CLOSED'
$min_date   = trim($_GET['min_date'] ?? ''); // optional: filter by requested_at
$max_date   = trim($_GET['max_date'] ?? '');

$where  = [];
$types  = '';
$params = [];

if ($q !== '') {
  $where[] = "(s.sku_num LIKE CONCAT('%', ?, '%') OR s.`desc` LIKE CONCAT('%', ?, '%') OR r.requested_by LIKE CONCAT('%', ?, '%'))";
  $types  .= 'sss'; $params[] = $q; $params[] = $q; $params[] = $q;
}
if ($status === 'OPEN' || $status === 'CLOSED') {
  $where[] = "r.status = ?"; $types .= 's'; $params[] = $status;
}
if ($min_date !== '') { $where[] = "DATE(r.requested_at) >= ?"; $types.='s'; $params[]=$min_date; }
if ($max_date !== '') { $where[] = "DATE(r.requested_at) <= ?"; $types.='s'; $params[]=$max_date; }

// Pre-aggregate total on-hand per SKU (all locations) via subquery join — CHANGED
$sql = "
  SELECT
    r.id, r.requested_by, r.qty_requested, r.bin_qty, r.status, r.notes,
    r.requested_at, r.updated_at,
    s.sku_num, s.`desc` AS sku_desc,
    COALESCE(inv.on_hand, 0) AS total_on_hand
  FROM back_requests r
  JOIN sku s ON s.id = r.sku_id
  LEFT JOIN (
    SELECT sku_id, SUM(quantity) AS on_hand
    FROM inventory
    GROUP BY sku_id
  ) inv ON inv.sku_id = r.sku_id
  ".(count($where) ? "WHERE ".implode(' AND ', $where) : "")."
  ORDER BY r.requested_at DESC, r.updated_at DESC, r.id DESC
  LIMIT 500
";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) { $rows[] = $row; }
$stmt->close();

// ---------- Page ----------
$title = 'Back-Rack Requests';
$page_class = 'page-back-requests';
ob_start();
?>
<h2 class="title">Back-Rack Requests</h2>

<?php if ($flash): ?>
  <div class="alert alert--info" style="margin-bottom:12px;"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<section class="card card--pad">
  <form method="get" action="">
    <div class="flex flex-wrap items-end" style="gap:16px;">
      <div style="flex:1 1 300px; min-width:240px;">
        <label for="q" class="label">Search (SKU / product / requester)</label>
        <input class="input" type="text" id="q" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="e.g. ZZ101001 or George">
      </div>
      <div>
        <label class="label" for="status">Status</label>
        <select class="input" id="status" name="status">
          <option value="" <?= $status===''?'selected':'' ?>>All</option>
          <option value="OPEN" <?= $status==='OPEN'?'selected':'' ?>>Open</option>
          <option value="CLOSED" <?= $status==='CLOSED'?'selected':'' ?>>Closed</option>
        </select>
      </div>
      <div>
        <label class="label">Requested date range</label>
        <div style="display:flex; gap:8px;">
          <input class="input" type="date" name="min_date" value="<?= htmlspecialchars($min_date) ?>">
          <input class="input" type="date" name="max_date" value="<?= htmlspecialchars($max_date) ?>">
        </div>
      </div>
      <div style="display:flex; gap:8px; align-items:center;">
        <button class="btn btn--primary" type="submit">Filter</button>
        <?php if ($q!=='' || $status!=='' || $min_date!=='' || $max_date!==''): ?>
          <a class="btn btn-outline" href="?">Clear</a>
        <?php endif; ?>
      </div>
    </div>
  </form>
</section>

<section class="card card--pad">
  <h3 style="margin:0 0 12px;">Add Request</h3>
  <form method="post" action="" class="flex flex-wrap" style="gap:12px;">
    <input type="hidden" name="action" value="create">
    <!-- requester auto-filled from session; no input -->
    <div><label class="label">SKU</label><input class="input" type="text" name="sku_num" placeholder="e.g. ZZ101001" required></div>
    <div style="width:120px;">
      <label class="label">Qty</label>
      <input class="input" type="number" name="qty_requested" min="1" step="1" value="1" required>
    </div>
    <div style="flex:1 1 420px; min-width:260px;">
      <label class="label">Notes</label>
      <input class="input" type="text" name="notes" placeholder='e.g. "F&F order" or "Not found on back"'>
    </div>
    <div><button class="btn btn--primary" type="submit">Add</button></div>
  </form>
  <p class="text-muted" style="margin-top:8px;">Requester: <strong><?= htmlspecialchars($username) ?></strong></p>
</section>


<section class="card card--pad">
  <?php if (!$rows): ?>
    <p class="text-muted">No matching requests.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Requested</th> <!-- CHANGED: timestamp -->
            <th>Requester</th>
            <th>SKU</th>
            <th>Product</th>
            <th style="text-align:right;">Qty Req</th>
            <th style="text-align:right;">Total On-Hand</th> <!-- CHANGED -->
            <th style="text-align:right;">Bin Qty</th>
            <th>Status</th> <!-- CHANGED: OPEN/CLOSED -->
            <th>Notes</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $skuLink  = "invSku.php?" . http_build_query(["q" => $r["sku_num"]]);
          $requested= $r['requested_at'] ? date('Y-m-d H:i', strtotime($r['requested_at'])) : '';
          $updated  = $r['updated_at']   ? date('Y-m-d H:i', strtotime($r['updated_at']))   : '';
        ?>
          <tr>
            <td><?= htmlspecialchars($requested) ?></td>
            <td><?= htmlspecialchars($r['requested_by']) ?></td>
            <td><a class="link" href="<?= htmlspecialchars($skuLink) ?>"><?= htmlspecialchars($r['sku_num']) ?></a></td>
            <td><?= htmlspecialchars($r['sku_desc']) ?></td>
            <td style="text-align:right;"><?= (int)$r['qty_requested'] ?></td>
            <td style="text-align:right;"><?= (int)$r['total_on_hand'] ?></td> <!-- CHANGED -->
            <td style="text-align:right;">
              <form method="post" action="" style="display:flex; gap:6px; align-items:center;">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input class="input" type="number" name="bin_qty" min="0" step="1" value="<?= (int)$r['bin_qty'] ?>" style="width:90px;">
            </td>
            <td>
              <select class="input" name="status"> <!-- CHANGED -->
                <option value="OPEN"   <?= $r['status']==='OPEN'?'selected':'' ?>>OPEN</option>
                <option value="CLOSED" <?= $r['status']==='CLOSED'?'selected':'' ?>>CLOSED</option>
              </select>
            </td>
            <td><input class="input" type="text" name="notes" value="<?= htmlspecialchars($r['notes'] ?? '') ?>" placeholder="Notes"></td>
            <td><?= htmlspecialchars($updated) ?></td>
            <td style="white-space:nowrap;">
              <button class="btn btn--primary" type="submit">Save</button>
              </form>
              <form method="post" action="" onsubmit="return confirm('Delete this request?')" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-outline" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php
$content = ob_get_clean();
include __DIR__ . "/templates/layout.php";
