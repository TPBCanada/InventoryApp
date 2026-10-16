<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
include __DIR__ . '/../dbinv.php';

if (!isset($_SESSION['username'])) {
 header('Location: ../login.php'); exit;
}
$username = $_SESSION['username'];
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role_id = (int) ($_SESSION['role_id'] ?? 0);

if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (\Throwable $_) {}

/* ========== Inline Suggest API (SKU only) ==========
   GET ?suggest=1&type=sku&q=term
   -> { ok:true, items:[{sku_num: "..."}] }
   (matches sku_num OR description, but returns/app displays ONLY sku_num)
===================================================== */
if (isset($_GET['suggest']) && $_GET['suggest'] === '1') {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');

  $type = ($_GET['type'] ?? 'sku') === 'sku' ? 'sku' : 'sku';
  $q    = trim((string)($_GET['q'] ?? ''));
  $items = [];

  try {
    $sql = "
      SELECT sku_num
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
      $items[] = ['sku_num' => (string)$r['sku_num']];
    }
    $stmt->close();
  } catch (\Throwable $e) {
    error_log('[invReq suggest] ' . $e->getMessage());
  }

  echo json_encode(['ok'=>true,'type'=>'sku','items'=>$items], JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

/* ---------- Migration Block (as provided) ---------- */
// 1) Rename bin_qty -> picked_qty or create picked_qty
try {
  $conn->query("ALTER TABLE back_requests CHANGE COLUMN bin_qty picked_qty INT NOT NULL DEFAULT 0");
} catch (\Throwable $e) {
  try { $conn->query("SELECT picked_qty FROM back_requests LIMIT 1"); }
  catch (\Throwable $e2) { $conn->query("ALTER TABLE back_requests ADD COLUMN picked_qty INT NOT NULL DEFAULT 0 AFTER qty_requested"); }
}
// 2) Ensure is_high_priority
try { $conn->query("SELECT is_high_priority FROM back_requests LIMIT 1"); }
catch (\Throwable $e) { $conn->query("ALTER TABLE back_requests ADD COLUMN is_high_priority TINYINT(1) NOT NULL DEFAULT 0 AFTER notes"); }
// Cleanup
try { $conn->query("ALTER TABLE back_requests DROP COLUMN date_requested"); } catch (\Throwable $___) {}
try { $conn->query("ALTER TABLE back_requests DROP COLUMN ready_to_pick"); } catch (\Throwable $___) {}
try { $conn->query("ALTER TABLE back_requests DROP COLUMN ready_to_ship"); } catch (\Throwable $___) {}

/* ---------- Helpers ---------- */
function post($k, $d = ''){ return trim($_POST[$k] ?? $d); }
function n($v): int { return max(0, (int) $v); }

$flash = null;

/* ---------- Create ---------- */
if (($_POST['action'] ?? '') === 'create') {
  $requested_by   = $username;
  $sku_num        = post('sku_num');
  $qty_requested  = n(post('qty_requested'));
  $notes          = post('notes');
  $is_high_priority = 0;

  if ($sku_num && $qty_requested > 0) {
    $stmt = $conn->prepare("SELECT id FROM sku WHERE sku_num = ?");
    $stmt->bind_param('s', $sku_num);
    $stmt->execute();
    $res = $stmt->get_result();
    $sku = $res->fetch_assoc();
    $stmt->close();

    if (!$sku) {
      $flash = "Unknown SKU: " . htmlspecialchars($sku_num);
    } else {
      $sku_id = (int)$sku['id'];
      $stmt = $conn->prepare("INSERT INTO back_requests (requested_by, sku_id, qty_requested, notes, created_by, status, is_high_priority)
                              VALUES (?,?,?,?,?, 'OPEN', ?)");
      $stmt->bind_param('ssisii', $requested_by, $sku_id, $qty_requested, $notes, $user_id, $is_high_priority);
      $stmt->execute();
      $stmt->close();
      $flash = "Request added for {$sku_num}.";
    }
  } else {
    $flash = "Please complete SKU and Qty.";
  }
}

/* ---------- Update (auto-close) ---------- */
if (($_POST['action'] ?? '') === 'update') {
  $id          = (int) post('id');
  $picked_qty  = n(post('picked_qty'));
  $notes       = post('notes');
  $is_high_priority = (int) (post('is_high_priority') === 'on');

  $stmt = $conn->prepare("SELECT qty_requested FROM back_requests WHERE id=?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $request = $res->fetch_assoc();
  $stmt->close();

  if ($request) {
    $qty_requested = (int)$request['qty_requested'];
    $status_from_post = post('status');
    $status = ($picked_qty >= $qty_requested) ? 'CLOSED' : (($status_from_post === 'CLOSED') ? 'CLOSED' : 'OPEN');

    $stmt = $conn->prepare("UPDATE back_requests
                               SET picked_qty=?, status=?, notes=?, is_high_priority=?, updated_at=NOW()
                             WHERE id=?");
    $stmt->bind_param('issii', $picked_qty, $status, $notes, $is_high_priority, $id);
    $stmt->execute();
    $stmt->close();

    $flash = "Request updated. Status set to {$status}.";
  } else {
    $flash = "Error: Request ID not found.";
  }
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
  $params[] = $q; $params[] = $q; $params[] = $q;
}
if ($status === 'OPEN' || $status === 'CLOSED') { $where[] = "r.status = ?"; $types .= 's'; $params[] = $status; }
if ($min_date !== '') { $where[] = "DATE(r.requested_at) >= ?"; $types .= 's'; $params[] = $min_date; }
if ($max_date !== '') { $where[] = "DATE(r.requested_at) <= ?"; $types .= 's'; $params[] = $max_date; }
if ($high_priority_only) { $where[] = "r.is_high_priority = 1"; }

$sql = "
  SELECT
    r.id, r.requested_by, r.qty_requested, r.picked_qty, r.status, r.notes,
    r.requested_at, r.updated_at, r.is_high_priority,
    s.sku_num, s.`desc` AS sku_desc,
    COALESCE(inv.on_hand, 0) AS total_on_hand
  FROM back_requests r
  JOIN sku s ON s.id = r.sku_id
  LEFT JOIN (
    SELECT sku_id, SUM(quantity) AS on_hand
    FROM inventory
    GROUP BY sku_id
  ) inv ON inv.sku_id = r.sku_id
  " . (count($where) ? " WHERE " . implode(' AND ', $where) : "") . "
  ORDER BY r.is_high_priority DESC, r.requested_at DESC, r.updated_at DESC, r.id DESC
  LIMIT 500
";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) { $rows[] = $row; }
$stmt->close();

$show_clear = $q !== '' || $status !== 'OPEN' || $min_date !== '' || $max_date !== '' || $high_priority_only;

/* ---------- Page ---------- */
$title = 'Back-Rack Requests';
$page_class = 'page-back-requests';
ob_start();
?>
<h2 class="title">Back-Rack Requests</h2>

<?php if ($flash): ?>
  <div class="alert alert--info mb-3"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<section class="card card--pad">
  <form method="get" action="" id="filterForm" autocomplete="off">
    <div class="flex flex-wrap justify-between items-end g-4">
      <div class="filter-item-grow" style="position:relative;">
        <label for="q" class="label">Search (SKU / product / requester)</label>
        <input class="input" type="text" id="q" name="q" value="<?= htmlspecialchars($q) ?>"
               placeholder="e.g. ZZ101001 or George">
        <!-- SKU suggestions for filter -->
        <div id="qSuggest" class="ac-list" style="display:none;"></div>
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
        <input type="checkbox" style="width: 18px; height: 18px;" name="high_priority_only" id="high_priority_only" <?= $high_priority_only ? 'checked' : '' ?>>
      </div>

      <div>
        <label class="label">Requested date range</label>
        <div class="flex g-2">
          <input class="input" type="date" name="min_date" value="<?= htmlspecialchars($min_date) ?>">
          <input class="input" type="date" name="max_date" value="<?= htmlspecialchars($max_date) ?>">
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
    <div style="position:relative;">
      <label class="label" for="sku_num">SKU</label>
      <input class="input" type="text" id="sku_num" name="sku_num" placeholder="e.g. ZZ101001" required>
      <input type="hidden" name="is_high_priority" value="0">
      <!-- SKU suggestions for Add -->
      <div id="addSuggest" class="ac-list" style="display:none;"></div>
    </div>
    <div class="add-qty-width">
      <label class="label">Qty</label>
      <input class="input" type="number" name="qty_requested" min="1" step="1" value="1" required>
    </div>
    <div class="add-notes-grow">
      <label class="label">Notes</label>
      <input class="input" type="text" name="notes" placeholder='e.g. "F&F order" or "Not found on back"'>
    </div>
    <div class="text-center">
      <label class="label"><p class="text-muted mt-2">Requester</label>
      <p><strong><?= htmlspecialchars($username) ?></strong></p>
    </div>
    <div class="flex items-center"><button class="btn btn--primary" type="submit">Add</button></div>
  </form>
</section>

<section class="card card--pad">
  <?php if (!$rows): ?>
    <p class="text-muted">No matching requests.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Requested</th>
            <th>Requester</th>
            <th>SKU</th>
            <th>Product</th>
            <th class="text-right">Qty Req</th>
            <th class="text-right">Total On-Hand</th>
            <th class="text-right">Picked Qty</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Notes</th>
            <th>Save</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $skuLink = "invSku.php?" . http_build_query(["q" => $r["sku_num"]]);
            $requested = $r['requested_at'] ? date('Y-m-d H:i', strtotime($r['requested_at'])) : '';
            $updated   = $r['updated_at'] ? date('Y-m-d H:i', strtotime($r['updated_at'])) : '';
          ?>
          <tr class="<?= (int)$r['is_high_priority'] ? 'table-row-priority' : '' ?>">
            <td><?= htmlspecialchars($requested) ?></td>
            <td><?= htmlspecialchars($r['requested_by']) ?></td>
            <td><a class="link" href="<?= htmlspecialchars($skuLink) ?>"><?= htmlspecialchars($r['sku_num']) ?></a></td>
            <td><?= htmlspecialchars($r['sku_desc']) ?></td>
            <td class="text-right"><?= (int)$r['qty_requested'] ?></td>
            <td class="text-right"><?= (int)$r['total_on_hand'] ?></td>
            <td class="text-right">
              <form method="post" action="" class="flex g-1 items-center">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input class="input input-w-70" type="number" name="picked_qty" min="0" step="1" value="<?= (int)$r['picked_qty'] ?>">
            </td>
            <td>
              <input type="hidden" name="is_high_priority" value="0">
              <label class="checkbox-label no-pad-label inline-flex g-1 items-center">
                <input type="checkbox" name="is_high_priority" <?= (int)$r['is_high_priority'] ? 'checked' : '' ?>>
              </label>
            </td>
            <td>
              <select class="input" name="status">
                <option value="OPEN"   <?= $r['status'] === 'OPEN'   ? 'selected' : '' ?>>OPEN</option>
                <option value="CLOSED" <?= $r['status'] === 'CLOSED' ? 'selected' : '' ?>>CLOSED</option>
              </select>
            </td>
            <td><input class="input" type="text" name="notes" value="<?= htmlspecialchars($r['notes'] ?? '') ?>" placeholder="Notes"></td>
            <td class="text-nowrap">
              <button class="btn btn--primary" type="submit">Save</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<style>
  /* Suggestions dropdown (shared) */
  .ac-list {
    position: absolute; left: 0; right: 0; top: calc(100% + 2px);
    max-height: 260px; overflow-y: auto;
    border: 1px solid #ced4da; border-top: none; background: #fff; z-index: 9999;
    box-shadow: 0 6px 12px rgba(0,0,0,.1);
  }
  .ac-item {
    padding: .375rem .75rem; cursor: pointer;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  }
  .ac-item:hover, .ac-item.active { background: #f0f6ff; }
</style>

<script>
(function(){
  // Generic helpers
  function buildUrl(params){
    const url = new URL(window.location.href);
    for (const [k,v] of Object.entries(params)) {
      if (v === null) url.searchParams.delete(k);
      else url.searchParams.set(k, String(v));
    }
    return url.toString();
  }
  async function fetchSkuSuggest(q, myTicket){
    const url = buildUrl({ suggest:'1', type:'sku', q, page:null });
    const res = await fetch(url, { headers: { 'Accept':'application/json' }});
    if (!res.ok) throw new Error('HTTP '+res.status);
    const data = await res.json();
    if (myTicket !== (window.__ticket || 0)) return null; // stale protection (global)
    return Array.isArray(data.items) ? data.items : [];
  }

  // ====== 1) Filter box (q) — auto-submit on pick ======
  const qInput   = document.getElementById('q');
  const qList    = document.getElementById('qSuggest');
  const filterForm = document.getElementById('filterForm');
  let t1=null, idx1=-1;

  function hideQ(){ qList.style.display='none'; qList.innerHTML=''; idx1=-1; }
  function showQ(){ qList.style.display='block'; }

  function renderQ(items){
    qList.innerHTML = '';
    idx1 = -1;
    if (!items || !items.length){ hideQ(); return; }
    const frag = document.createDocumentFragment();
    items.forEach((it, i) => {
      const div = document.createElement('div');
      div.className = 'ac-item';
      div.textContent = it.sku_num; // show ONLY sku_num
      div.dataset.index = String(i);
      div.addEventListener('mousedown', (e)=>{ e.preventDefault(); chooseQ(it.sku_num); });
      frag.appendChild(div);
    });
    qList.appendChild(frag);
    showQ();
  }

  function chooseQ(sku){
    qInput.value = sku;
    hideQ();
    // Submit immediately to filter by that SKU
    if (filterForm && filterForm.requestSubmit) filterForm.requestSubmit(); else filterForm.submit();
  }

  if (qInput && qList) {
    qInput.addEventListener('input', () => {
      clearTimeout(t1);
      const q = qInput.value.trim();
      if (!q) { hideQ(); return; }
      const my = (window.__ticket = (window.__ticket||0)+1);
      t1 = setTimeout(async () => {
        try {
          const items = await fetchSkuSuggest(q, my);
          renderQ(items||[]);
        } catch (e) { console.error(e); hideQ(); }
      }, 150);
    });
    qInput.addEventListener('focus', () => {
      const q = qInput.value.trim();
      const my = (window.__ticket = (window.__ticket||0)+1);
      fetchSkuSuggest(q, my).then(items => renderQ(items||[])).catch(()=>hideQ());
    });
    qInput.addEventListener('blur', () => setTimeout(hideQ, 150));
    qInput.addEventListener('keydown', (e) => {
      if (qList.style.display === 'none') return;
      const items = Array.from(qList.querySelectorAll('.ac-item'));
      if (!items.length) return;
      if (e.key === 'ArrowDown'){
        e.preventDefault(); idx1 = (idx1+1)%items.length;
        items.forEach(el=>el.classList.remove('active')); items[idx1].classList.add('active');
        items[idx1].scrollIntoView({block:'nearest'});
      } else if (e.key === 'ArrowUp'){
        e.preventDefault(); idx1 = (idx1-1+items.length)%items.length;
        items.forEach(el=>el.classList.remove('active')); items[idx1].classList.add('active');
        items[idx1].scrollIntoView({block:'nearest'});
      } else if (e.key === 'Enter'){
        if (idx1 >= 0){ e.preventDefault(); chooseQ(items[idx1].textContent||''); }
      } else if (e.key === 'Escape'){ hideQ(); }
    });
  }

  // ====== 2) Add form SKU (sku_num) — no auto-submit ======
  const addInput = document.getElementById('sku_num');
  const addList  = document.getElementById('addSuggest');
  const addForm  = document.getElementById('addForm');
  let t2=null, idx2=-1;

  function hideAdd(){ addList.style.display='none'; addList.innerHTML=''; idx2=-1; }
  function showAdd(){ addList.style.display='block'; }

  function renderAdd(items){
    addList.innerHTML = '';
    idx2 = -1;
    if (!items || !items.length){ hideAdd(); return; }
    const frag = document.createDocumentFragment();
    items.forEach((it, i) => {
      const div = document.createElement('div');
      div.className = 'ac-item';
      div.textContent = it.sku_num; // show ONLY sku_num
      div.dataset.index = String(i);
      div.addEventListener('mousedown', (e)=>{ e.preventDefault(); chooseAdd(it.sku_num); });
      frag.appendChild(div);
    });
    addList.appendChild(frag);
    showAdd();
  }

  function chooseAdd(sku){
    addInput.value = sku;
    hideAdd();
    // Focus qty to streamline adding
    const qty = addForm?.querySelector('input[name="qty_requested"]');
    if (qty) qty.focus();
  }

  if (addInput && addList) {
    addInput.addEventListener('input', () => {
      clearTimeout(t2);
      const q = addInput.value.trim();
      if (!q) { hideAdd(); return; }
      const my = (window.__ticket = (window.__ticket||0)+1);
      t2 = setTimeout(async () => {
        try {
          const items = await fetchSkuSuggest(q, my);
          renderAdd(items||[]);
        } catch (e) { console.error(e); hideAdd(); }
      }, 150);
    });
    addInput.addEventListener('focus', () => {
      const q = addInput.value.trim();
      const my = (window.__ticket = (window.__ticket||0)+1);
      fetchSkuSuggest(q, my).then(items => renderAdd(items||[])).catch(()=>hideAdd());
    });
    addInput.addEventListener('blur', () => setTimeout(hideAdd, 150));
    addInput.addEventListener('keydown', (e) => {
      if (addList.style.display === 'none') return;
      const items = Array.from(addList.querySelectorAll('.ac-item'));
      if (!items.length) return;
      if (e.key === 'ArrowDown'){
        e.preventDefault(); idx2 = (idx2+1)%items.length;
        items.forEach(el=>el.classList.remove('active')); items[idx2].classList.add('active');
        items[idx2].scrollIntoView({block:'nearest'});
      } else if (e.key === 'ArrowUp'){
        e.preventDefault(); idx2 = (idx2-1+items.length)%items.length;
        items.forEach(el=>el.classList.remove('active')); items[idx2].classList.add('active');
        items[idx2].scrollIntoView({block:'nearest'});
      } else if (e.key === 'Enter'){
        if (idx2 >= 0){ e.preventDefault(); chooseAdd(items[idx2].textContent||''); }
      } else if (e.key === 'Escape'){ hideAdd(); }
    });
  }
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . "/templates/layout.php";
