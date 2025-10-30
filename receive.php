<?php
declare(strict_types=1);
session_start();
require_once __DIR__.'/dbinv.php';
require_once __DIR__ . '/lib/inv_queries.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action  = $_POST['action'] ?? '';
  $user_id = (int)($_SESSION['user_id'] ?? 0);

  if ($action === 'queue_receive') {
    $res = rq_queue_receive(
      $conn,
      (int)($_POST['sku_id'] ?? 0),
      (int)($_POST['quantity'] ?? 0),
      trim($_POST['supplier_name'] ?? '') ?: null,
      trim($_POST['po_number'] ?? '') ?: null,
      trim($_POST['reference_note'] ?? '') ?: null,
      $user_id ?: null
    );
    $_SESSION['flash'] = $res['ok'] ? ['ok','Queued'] : ['err','Queue failed'];
    header('Location: receive.php'); exit;
  }

  if ($action === 'approve_receive') {
    $res = rq_approve_receive($conn, (int)($_POST['id'] ?? 0), /*loc_id*/ 1, $user_id);
    $_SESSION['flash'] = $res['ok'] ? ['ok','Approved'] : ['err','Approve failed'];
    header('Location: receive.php'); exit;
  }

  if ($action === 'reject_receive') {
    $res = rq_reject_receive($conn, (int)($_POST['id'] ?? 0));
    $_SESSION['flash'] = $res['ok'] ? ['ok','Rejected'] : ['err','Reject failed'];
    header('Location: receive.php'); exit;
  }
}


header_register_callback(function () {
  foreach (headers_list() as $h) {
    if (stripos($h, 'Location:') === 0) {
      error_log('[REDIRECT TRAP] ' . $h);
      error_log('[REDIRECT TRAP] Included files: ' . implode(', ', get_included_files()));
    }
  }
});



if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/templates/access_control.php';

$username = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];
$role_id  = $_SESSION['role_id'] ?? 0;

if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }


header_register_callback(function () {
  foreach (headers_list() as $h) {
    if (stripos($h, 'Location:') === 0) {
      error_log('[REDIRECT TRAP] ' . $h);
      error_log('[REDIRECT TRAP] Included files: ' . implode(', ', get_included_files()));
      error_log('[REDIRECT TRAP] Session user_id=' . ($_SESSION['user_id'] ?? 'null') . ' username=' . ($_SESSION['username'] ?? 'null'));
    }
  }
});


$history_table = 'inventory_movements';
$title = 'Receive Incoming Shipment';

// Always define payload before anything might use it
if (!isset($payload) || !is_array($payload)) {
  $payload = [];
}

ob_start();
?>
  <h2>Receive Incoming Shipment</h2>

<form method="post">
    <div class="card">
      <div class="grid-2col">
        <div class="form-col">
          <label for="sku">SKU</label>
          <select name="sku_id" id="sku" required>
            <option value="">Select SKU</option>
            <?php
                $stmt = $conn->prepare("SELECT id, sku_num, `desc` FROM sku WHERE status='ACTIVE' ORDER BY sku_num");
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                  echo "<option value='{$r['id']}'>{$r['sku_num']} - {$r['desc']}</option>";
                }
                $stmt->close();
                ?>
          </select>
        </div>

        <div class="form-col">
          <label for="qty">Quantity Received</label>
          <input type="number" id="qty" name="quantity" min="1" required>
        </div>

        <div class="form-col">
          <label for="supplier">Supplier</label>
          <input type="text" id="supplier" name="supplier_name" placeholder="Optional">
        </div>

        <div class="form-col">
          <label for="po">PO Number</label>
          <input type="text" id="po" name="po_number" placeholder="Optional">
        </div>

        <div class="form-col" style="grid-column:1 / -1">
          <label for="notes">Notes</label>
          <textarea id="notes" name="reference_note" rows="3"></textarea>
        </div>
      </div>

      <div class="row actions">
        <button type="submit" name="action" value="queue_receive" class="btn btn-primary">Submit to Receiving Queue</button>
      </div>
    </div>
  </form>

  <div class="card mt-4">
    <h2>Pending Receipts</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>SKU</th><th>Description</th><th>Qty</th><th>Supplier</th>
            <th>PO</th><th>Status</th><th>Received</th><th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $stmt = $conn->prepare("
              SELECT rq.*, s.sku_num, s.`desc`
              FROM receiving_queue rq
              JOIN sku s ON rq.sku_id = s.id
              WHERE rq.status=?
              ORDER BY rq.received_at DESC
            ");
            $status = 'PENDING';
            $stmt->bind_param('s', $status);
            $stmt->execute();
            $rows = $stmt->get_result();
            while ($r = $rows->fetch_assoc()) {
              // ... existing echo logic ...
            }
            $stmt->close();
        ?>
        </tbody>
      </table>
    </div>
  </div>

<?php
$content = ob_get_clean();

$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);



include __DIR__ . '/templates/layout.php';
