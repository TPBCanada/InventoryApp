<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';



if (!isset($_SESSION['username'])) {
  header('Location: ../login.php');
  exit;
}

$username = $_SESSION['username'];
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role_id = (int) ($_SESSION['role_id'] ?? 0);

if (!$conn) {
  die("Connection failed: " . mysqli_connect_error());
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$flash = $_SESSION['flash'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['flash'], $_SESSION['error']);

try {
  $conn->set_charset('utf8mb4');
} catch (\Throwable $_) {
}

/* ========== Inline Suggest API (SKU only) ==========
   GET ?suggest=1&type=sku&q=term
   -> { ok:true, items:[{sku_num: "...", desc: "..."}] }
===================================================== */
if (isset($_GET['suggest']) && $_GET['suggest'] === '1') {
  while (ob_get_level() > 0)
    ob_end_clean();

  header('Content-Type: application/json; charset=utf-8');

  $q = trim((string) ($_GET['q'] ?? ''));
  $items = [];

  try {
    $sql = "
      SELECT sku_num, `desc`
      FROM sku
      WHERE status='ACTIVE'
        AND (sku_num LIKE CONCAT('%', ?, '%') OR `desc` LIKE CONCAT('%', ?, '%'))
      ORDER BY sku_num ASC
      LIMIT 30
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $q, $q);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
      $items[] = [
        'sku_num' => (string) $r['sku_num'],
        'desc' => (string) ($r['desc'] ?? '')
      ];
    }
    $stmt->close();
  } catch (\Throwable $e) {
    error_log('[requests suggest] ' . $e->getMessage());
  }

  echo json_encode(['ok' => true, 'type' => 'sku', 'items' => $items], JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

/* ========== AJAX: Fetch Location Picker HTML ========== */
if (isset($_GET['get_locations']) && $_GET['get_locations'] === '1') {
  while (ob_get_level() > 0)
    ob_end_clean();
  $sku = $_GET['sku'] ?? '';

  echo getSkuLocationPickerHtml($conn, $sku);
  exit;
}

/* ---------- Create ---------- */
if (($_POST['action'] ?? '') === 'create') {
  $requested_by = $username;
  $sku_num = post('sku_num');
  $qty_requested = n(post('qty_requested'));
  $bin_content = (int) post('bin_content');
  $is_high_priority = (($_POST['is_high_priority'] ?? '0') === '1') ? 1 : 0;
  $notes = post('notes');

  if ($sku_num && $qty_requested > 0) {
    $stmt = $conn->prepare("
      SELECT s.id, COALESCE(SUM(i.quantity), 0) AS total_on_hand
      FROM sku s
      LEFT JOIN inventory i ON i.sku_id = s.id
      WHERE s.sku_num = ?
      GROUP BY s.id
    ");
    $stmt->bind_param('s', $sku_num);
    $stmt->execute();
    $res = $stmt->get_result();
    $sku = $res->fetch_assoc();
    $stmt->close();

    if (!$sku) {
      $error = "Unknown SKU: {$sku_num}";
    } elseif ((int) ($sku['total_on_hand'] ?? 0) <= 0) {
      $error = "Cannot create a request for {$sku_num}. There is no inventory available for this item.";
    } else {
      $sku_id = (int) $sku['id'];
      $stmt = $conn->prepare("INSERT INTO back_requests (requested_by, sku_id, qty_requested, notes, created_by, status, is_high_priority, bin_content)
                              VALUES (?,?,?,?,?, 'OPEN', ?, ?)");
      $stmt->bind_param('ssisiii', $requested_by, $sku_id, $qty_requested, $notes, $user_id, $is_high_priority, $bin_content);
      $stmt->execute();
      $stmt->close();

      $_SESSION['flash'] = "Request added for {$sku_num}.";
      header("Location: requests.php");
      exit;
    }
  } else {
    $error = "Please complete SKU and Qty.";
  }
}

/* ========== Unified Action: Update Request, Inventory, and Auto-Close ========== */
if (($_POST['action'] ?? '') === 'update') {
  $id = (int) ($_POST['id'] ?? 0);
  $picked_qty = (int) ($_POST['picked_qty'] ?? 0);
  $notes = trim((string) ($_POST['notes'] ?? ''));

  // Handle checkbox (0 or 1)
  $is_high_priority = (int) ($_POST['is_high_priority'] ?? 0);
  $pick_details = json_decode($_POST['pick_details'] ?? '[]', true);

  if ($id > 0) {
    $conn->begin_transaction();
    try {
      $stmt = $conn->prepare("SELECT sku_id, qty_requested, status FROM back_requests WHERE id = ? FOR UPDATE");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $request = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$request)
        throw new Exception("Request ID not found.");

      $new_status = ($picked_qty >= (int) $request['qty_requested']) ? 'CLOSED' : $request['status'];
      $updReq = $conn->prepare("
                UPDATE back_requests 
                SET picked_qty = ?, 
                    status = ?, 
                    notes = ?, 
                    is_high_priority = ?, 
                    updated_at = NOW() 
                WHERE id = ?
            ");
      $updReq->bind_param('issii', $picked_qty, $new_status, $notes, $is_high_priority, $id);
      $updReq->execute();
      $updReq->close();

      if (!empty($pick_details)) {
        foreach ($pick_details as $item) {
          $inv_id = (int) ($item['invId'] ?? $item['inv_id'] ?? 0);
          $loc_id = (int) ($item['locId'] ?? $item['loc_id'] ?? 0);
          $qty_taken = $picked_qty;

          if ($qty_taken <= 0 || $inv_id === 0)
            continue;

          // Reduce Stock
          $stock = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
          $stock->bind_param("iii", $qty_taken, $inv_id, $qty_taken);
          $stock->execute();
          if ($stock->affected_rows === 0) {
            throw new Exception("Inventory mismatch: Bin #$inv_id does not have enough stock.");
          }
          $stock->close();

          // Log Movement
          $mov = $conn->prepare("INSERT INTO inventory_movements (sku_id, loc_id, quantity_change, movement_type, reference, user_id) 
                                           VALUES (?, ?, ?, 'OUT', ?, ?)");
          $ref = "Back-Rack Pick #$id";
          $change = -$qty_taken;
          $mov->bind_param("iiisi", $request['sku_id'], $loc_id, $change, $ref, $user_id);
          $mov->execute();
          $mov->close();
        }
      }

      $conn->commit();
      $_SESSION['flash'] = ($new_status === 'CLOSED') ? "Request #$id fulfilled and CLOSED." : "Request #$id updated.";

    } catch (Exception $e) {
      $conn->rollback();
      $_SESSION['error'] = "Update failed: " . $e->getMessage();
    }
  }

  header("Location: requests.php");
  exit;
}

/* ========== Delete Request ========== */
if (($_POST['action'] ?? '') === 'delete') {
  $id = (int) ($_POST['id'] ?? 0);

  if ($id > 0) {
    try {
      $stmt = $conn->prepare("DELETE FROM back_requests WHERE id = ?");
      $stmt->bind_param('i', $id);
      $stmt->execute();

      if ($stmt->affected_rows > 0) {
        $_SESSION['flash'] = "Request #$id deleted.";
      }
      $stmt->close();
    } catch (Exception $e) {
      $_SESSION['error'] = "Delete failed: " . $e->getMessage();
    }

    header("Location: requests.php");
    exit;
  }
}

/* ---------- Migration Block (as provided) ---------- */
try {
  $conn->query("ALTER TABLE back_requests CHANGE COLUMN bin_qty picked_qty INT NOT NULL DEFAULT 0");
} catch (\Throwable $e) {
  try {
    $conn->query("SELECT picked_qty FROM back_requests LIMIT 1");
  } catch (\Throwable $e2) {
    $conn->query("ALTER TABLE back_requests ADD COLUMN picked_qty INT NOT NULL DEFAULT 0 AFTER qty_requested");
  }
}
try {
  $conn->query("SELECT is_high_priority FROM back_requests LIMIT 1");
} catch (\Throwable $e) {
  $conn->query("ALTER TABLE back_requests ADD COLUMN is_high_priority TINYINT(1) NOT NULL DEFAULT 0 AFTER notes");
}
try {
  $conn->query("ALTER TABLE back_requests DROP COLUMN date_requested");
} catch (\Throwable $___) {
}
try {
  $conn->query("ALTER TABLE back_requests DROP COLUMN ready_to_pick");
} catch (\Throwable $___) {
}
try {
  $conn->query("ALTER TABLE back_requests DROP COLUMN ready_to_ship");
} catch (\Throwable $___) {
}

/* ---------- Helpers ---------- */
function post($k, $d = '')
{
  return trim($_POST[$k] ?? $d);
}
function n($v): int
{
  return max(0, (int) $v);
}

/* ---------- Filters ---------- */
$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'OPEN';
$min_date = trim($_GET['min_date'] ?? '');
$max_date = trim($_GET['max_date'] ?? '');
$high_priority_only = isset($_GET['high_priority_only']);

$where = [];
$types = '';
$params = [];

if ($q !== '') {
  $where[] = "(s.sku_num LIKE CONCAT('%', ?, '%') OR s.`desc` LIKE CONCAT('%', ?, '%') OR r.requested_by LIKE CONCAT('%', ?, '%'))";
  $types .= 'sss';
  $params[] = $q;
  $params[] = $q;
  $params[] = $q;
}
if ($status === 'OPEN' || $status === 'CLOSED') {
  $where[] = "r.status = ?";
  $types .= 's';
  $params[] = $status;
}
if ($min_date !== '') {
  $where[] = "DATE(r.requested_at) >= ?";
  $types .= 's';
  $params[] = $min_date;
}
if ($max_date !== '') {
  $where[] = "DATE(r.requested_at) <= ?";
  $types .= 's';
  $params[] = $max_date;
}
if ($high_priority_only) {
  $where[] = "r.is_high_priority = 1";
}

$sql = "
  SELECT
    r.id, r.requested_by, r.qty_requested, r.picked_qty, r.status, r.notes,
    r.requested_at, r.updated_at, r.is_high_priority, r.bin_content,
    s.sku_num, s.`desc` AS sku_desc,
    COALESCE(inv.on_hand, 0) AS total_on_hand
  FROM back_requests r
  LEFT JOIN sku s ON s.id = r.sku_id  
  LEFT JOIN (
    SELECT sku_id, SUM(quantity) AS on_hand
    FROM inventory
    GROUP BY sku_id
  ) inv ON inv.sku_id = r.sku_id
  " . (count($where) ? " WHERE " . implode(' AND ', $where) : "") . "
  ORDER BY 
    r.is_high_priority DESC,
    s.sku_num ASC,
    r.requested_at ASC
  LIMIT 500
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
  $rows[] = $row;
}
$stmt->close();

$show_clear = $q !== '' || $status !== 'OPEN' || $min_date !== '' || $max_date !== '' || $high_priority_only;

/* ---------- Page ---------- */
$title = 'Back-Rack Requests';
$page_class = 'page-back-requests';
$extra_css = '
<link rel="stylesheet" href="/inventory/refactor_css/request.css" />
';
ob_start();
?>

<h2 class="title">Back-Rack Requests</h2>

<?php if ($flash): ?>
  <div class="alert alert--info mb-3"><?= h($flash) ?></div>
<?php endif; ?>

<section class="card card--pad">
  <form method="get" action="" id="filterForm" autocomplete="off">
    <div class="flex flex-wrap justify-between items-end g-4">
      <div class="filter-item-grow pos-rel">
        <label for="q" class="label">Search (SKU / product / requester)</label>
        <input class="input" type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="e.g. ZZ101001 or George">
        <div id="suggestBox" class="suggest-dropdown"></div>
      </div>

      <div>
        <label class="label" for="status">Status</label>
        <select class="input" id="status" name="status">
          <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
          <option value="OPEN" <?= $status === 'OPEN' ? 'selected' : '' ?>>Open</option>
          <option value="CLOSED" <?= $status === 'CLOSED' ? 'selected' : '' ?>>Closed</option>
        </select>
      </div>

      <div class="flex flex-col justify-end">
        <label class="label" for="high_priority_only">Priority</label>
        <input type="checkbox" class="checkbox-18" name="high_priority_only" id="high_priority_only"
          <?= $high_priority_only ? 'checked' : '' ?>>
      </div>

      <div>
        <label class="label">Requested date range</label>
        <div class="flex g-2">
          <input class="input" type="date" name="min_date" value="<?= h($min_date) ?>">
          <input class="input" type="date" name="max_date" value="<?= h($max_date) ?>">
        </div>
      </div>

      <div class="flex g-2 items-end">
        <button class="btn btn--primary" type="submit">Filter</button>
        <?php if ($show_clear): ?><a class="btn btn-outline" href="?">Clear</a><?php endif; ?>
      </div>
    </div>
  </form>
</section>

<section class="card card--pad">
  <h3 class="m-0 mb-3">Add Request</h3>
  <form method="post" action="" class="flex flex-wrap g-3 justify-between" id="addForm" autocomplete="off">
    <input type="hidden" name="action" value="create">
    <div class="pos-rel">
      <label class="label" for="sku_num">SKU</label>
      <input class="input" type="text" id="sku_num" name="sku_num" placeholder="e.g. ZZ101001"
        data-suggest="inventory" data-suggest-box="addSuggest" autocomplete="on" required>
      <input type="hidden" name="is_high_priority" value="0">
      <div id="addSuggest" class="ac-list"></div>
    </div>
    <div class="add-qty-width flex flex-col">
      <label class="label">Qty</label>
      <input class="input" type="number" name="qty_requested" min="1" step="1" value="1" required>
    </div>
    <div class="add-qty-width flex flex-col">
      <label class="label">Bin Content</label>
      <input class="input" type="number" name="bin_content" min="0" step="1" value="" required>
    </div>
    <div class="add-priority-width flex flex-col">
      <label class="label">Priority</label>
      <input type="hidden" name="is_high_priority" value="0">
      <input class="input checkbox-add-priority" id="is_high_priority" type="checkbox" name="is_high_priority"
        value="1">
    </div>
    <div class="add-notes-grow">
      <label class="label">Notes</label>
      <input class="input" type="text" name="notes" placeholder='e.g. "F&F order" or "Not found on back"'>
    </div>
    <div class="text-center m-0">
      <label class="label">
        <p class="text-muted mt-1">Requester</p>
      </label>
      <p><strong><?= h($username) ?></strong></p>
    </div>
    <div class="flex items-center"><button class="btn btn--primary" type="submit">Add</button></div>
  </form>
</section>

<section class="card">
  <?php if (!$rows): ?>
    <p class="text-muted">No matching requests.</p>
  <?php else: ?>
    <div class="table-wrap">
      <?php
      $sku_counts = [];
      $sku_totals = [];

      foreach ($rows as $row) {
        $sku = $row['sku_num'];
        $sku_counts[$sku] = ($sku_counts[$sku] ?? 0) + 1;
        $sku_totals[$sku] = ($sku_totals[$sku] ?? 0) + (int) $row['qty_requested'];
      }
      ?>
      <table id="request_table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Request</th>
            <th>SKU</th>
            <th>Product</th>
            <th>Qty Req</th>
            <th>On-Hand</th>
            <th>Picked</th>
            <th>Bin<br />Content</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Notes</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $last_sku = null;
          foreach ($rows as $r):
            $sku = $r['sku_num'];
            $is_new_sku = ($sku !== $last_sku);
            $has_multiple = ($sku_counts[$sku] > 1);
            $requested = $r['requested_at'] ? date('Y-m-d H:i', strtotime($r['requested_at'])) : '';

            $is_closed = ($r['status'] === 'CLOSED');
            $disabled = $is_closed ? 'disabled' : '';
            $row_status_class = $is_closed ? 'row-closed' : '';

            if ($is_new_sku && $has_multiple):
              ?>
              <tr class="group-header" onclick="toggleGroup('<?= h($sku) ?>')">
                <td class="text-center group-toggle-cell">
                  <span class="toggle-icon" id="icon-<?= h($sku) ?>">+</span>
                </td>
                <td colspan="2"><strong>SKU: <?= h($sku) ?></strong></td>
                <td><?= h($r['sku_desc']) ?></td>
                <td class="text-right"><strong>Total Req: <?= $sku_totals[$sku] ?></strong></td>
                <td colspan="6" class="text-muted small">
                  (<?= $sku_counts[$sku] ?> requests) — Click to expand
                </td>
              </tr>
              <?php
            endif;

            $is_priority = ((int)$r['is_high_priority'] === 1);
            $row_class = ($has_multiple && !$is_priority)
                ? "child-row-" . h($sku)
                : "";
            $last_sku = $sku;
            ?>

            <tr
              class="<?= $row_class ?> <?= (int) $r['is_high_priority'] ? 'table-row-priority' : '' ?> <?= $row_status_class ?>"
              data-id="<?= (int) $r['id'] ?>" data-status="<?= h($r['status']) ?>">
              <form id="form-update-<?= $r['id'] ?>" method="post" action=""></form>

              <td><?= h($requested) ?></td>
              <td><?= h($r['requested_by']) ?></td>
              <td>
                <?php if ($is_closed): ?>
                  <span class="font-bold text-muted"><?= h($sku) ?></span>
                <?php else: ?>
                  <a href="javascript:void(0)" class="link font-bold"
                    onclick="openLocPicker('<?= h($sku) ?>', <?= (int) $r['id'] ?>)">
                    <?= h($sku) ?>
                  </a>
                <?php endif; ?>
              </td>
              <td><?= h($r['sku_desc']) ?></td>
              <td class="text-right" data-label="qty-req"><?= (int) $r['qty_requested'] ?></td>
              <td class="text-right"><?= (int) $r['total_on_hand'] ?></td>

              <td>
                <input form="form-update-<?= $r['id'] ?>" type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input form="form-update-<?= $r['id'] ?>" type="hidden" name="pick_details" class="pick-details-json">
                <input form="form-update-<?= $r['id'] ?>" class="input" type="number" name="picked_qty" min="0"
                  value="<?= (int) $r['picked_qty'] ?>" <?= $disabled ?>>
              </td>

              <td><?= (int) ($r['bin_content'] ?? 0) ?></td>

              <td>
                <?php if ($is_closed): ?>
                  <?= (int) $r['is_high_priority'] ? '<span class="text-muted">✓</span>' : '<span class="text-muted"></span>' ?>
                <?php else: ?>
                  <input form="form-update-<?= $r['id'] ?>" type="hidden" name="is_high_priority" value="0">
                  <input form="form-update-<?= $r['id'] ?>" type="checkbox" name="is_high_priority"
                    <?= (int) $r['is_high_priority'] ? 'checked' : '' ?> value="1">
                <?php endif; ?>
              </td>

              <td>
                <span class="status-label status-<?= strtolower(h($r['status'])) ?>">
                  <?= h($r['status']) ?>
                </span>
              </td>

              <td>
                <input form="form-update-<?= $r['id'] ?>" class="input notes-input" type="text" name="notes"
                  value="<?= h($r['notes'] ?? '') ?>" <?= $disabled ?>>
              </td>

              <td class="text-center">
                <div class="flex g-1">
                  <?php if (!$is_closed): ?>
                    <button form="form-update-<?= $r['id'] ?>" class="btn btn--primary" type="submit" name="action"
                      value="update">✓</button>
                    <button form="form-update-<?= $r['id'] ?>" class="btn btn--secondary btn-delete" type="submit"
                      name="action" value="delete" onclick="return confirm('Delete?');">X</button>
                  <?php else: ?>
                    <span title="This request is closed and cannot be modified"></span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<div id="locModal" class="modal loc-modal">
  <div class="card loc-modal-content">
    <div class="flex justify-between items-center mb-1">
      <h3 class="modal-title">Select Pick Location</h3>
      <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
    </div>

    <form id="closeReqForm" method="POST">
      <input type="hidden" name="action" value="close_request">
      <input type="hidden" name="req_id" id="modal_req_id">

      <div id="locPickerBody" class="loc-picker-body"></div>

      <div class="flex justify-end gap-2 mt-4">
        <button type="button" id="confirm-selection-btn" class="btn btn-success w-100 mt-3">
          <i class="fas fa-check-circle"></i> Confirm Selection
        </button>
        <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
  function toggleGroup(sku) {
    const rows = document.querySelectorAll(`.child-row-${sku}`);
    const icon = document.getElementById(`icon-${sku}`);

    rows.forEach(row => {
      if (row.style.display === "none" || row.style.display === "") {
        row.style.display = "table-row";
        if (icon) icon.textContent = "-";
      } else {
        row.style.display = "none";
        if (icon) icon.textContent = "+";
      }
    });
  }

  let activeRow = null;

  function openLocPicker(sku, reqId) {
    const modal = document.getElementById('locModal');
    const body = document.getElementById('locPickerBody');

    activeRow = document.querySelector(`tr[data-id="${reqId}"]`);

    if (activeRow && activeRow.getAttribute('data-status') === 'CLOSED') {
      return;
    }
    if (modal) modal.style.display = 'flex';
    if (body) body.innerHTML = '<div class="p-3">Loading locations...</div>';

    fetch(`requests.php?get_locations=1&sku=${encodeURIComponent(sku)}`)
      .then(response => response.text())
      .then(html => {
        body.innerHTML = html;
      });
  }

  function closeModal() {
    const modal = document.getElementById('locModal');
    if (modal) modal.style.display = 'none';
    activeRow = null;
  }

  document.addEventListener('click', function (e) {
    if (e.target && (e.target.id === 'confirm-selection-btn' || e.target.closest('.btn-success'))) {
      if (!activeRow) return;

      const checkboxes = document.querySelectorAll('.loc-checkbox:checked');
      const reqQty = parseInt(activeRow.querySelector('[data-label="qty-req"]').textContent) || 0;

      let totalAccumulated = 0;
      let locations = [];
      let pickDetails = [];

      checkboxes.forEach(cb => {
        const qty = parseInt(cb.dataset.qty) || 0;
        totalAccumulated += qty;
        locations.push(cb.value);

        pickDetails.push({
          inv_id: cb.dataset.invId,
          loc_id: cb.dataset.locId,
          qty: qty
        });
      });

      const finalQty = totalAccumulated > reqQty ? reqQty : totalAccumulated;
      const pickedInput = activeRow.querySelector('input[name="picked_qty"]');
      const notesInput = activeRow.querySelector('input[name="notes"]');
      const jsonInput = activeRow.querySelector('.pick-details-json');

      if (pickedInput) pickedInput.value = finalQty;
      if (jsonInput) jsonInput.value = JSON.stringify(pickDetails);
      if (notesInput) {
        notesInput.value = "Picked from: " + locations.join(', ');
      }

      closeModal();
    }
  });
</script>

<?php if ($error): ?>
<script>
  window.alert(<?= json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
</script>
<?php endif; ?>

<?php
// Ensure autosuggest script is loaded on this page
echo '<script defer src="/inventory/js/suggest.js"></script>';
$content = ob_get_clean();
include __DIR__ . "/templates/layout.php";
