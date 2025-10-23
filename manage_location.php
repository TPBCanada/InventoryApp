<?php
// manage_location.php — Locations (ID + Code), create form, LIKE search on code
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';

// ---- auth ----


if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
$allowed_roles = [1, 2];
if (!in_array((int)($_SESSION['role_id'] ?? 0), $allowed_roles, true)) {
  die("<p style='color:red; text-align:center; font-size:18px; margin-top:50px;'>Access denied.</p>");
}

$username = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];
$role_id  = $_SESSION['role_id'] ?? 0;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (\Throwable $_) {}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pad2(string $s){ return preg_match('/^\d+$/', $s) ? str_pad($s, 2, '0', STR_PAD_LEFT) : $s; }

$message = '';
$PAGE_SIZE = 50;

// ---------- CREATE (new location) ----------
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_location'])) {
  $row = strtoupper(trim($_POST['row_code'] ?? ''));
  $bay = pad2(trim($_POST['bay_num'] ?? ''));
  $lvl = pad2(trim($_POST['level_code'] ?? ''));
  $sd  = strtoupper(trim($_POST['side'] ?? ''));

  if ($row==='' || $bay==='' || $lvl==='' || ($sd!=='F' && $sd!=='B')) {
    $message = '❌ Please fill Row, Bay, Level and Side (F/B).';
  } else {
    // prevent duplicates
    $chk = $conn->prepare("SELECT COUNT(*) c FROM location WHERE row_code=? AND bay_num=? AND level_code=? AND side=?");
    $chk->bind_param('ssss', $row, $bay, $lvl, $sd);
    $chk->execute();
    $c = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0);
    $chk->close();

    if ($c > 0) {
      $message = '❌ That location already exists.';
    } else {
      $ins = $conn->prepare("INSERT INTO location (row_code, bay_num, level_code, side) VALUES (?,?,?,?)");
      $ins->bind_param('ssss', $row, $bay, $lvl, $sd);
      if ($ins->execute()) {
        $message = '✅ Location created.';
        // Optional redirect to clear POST
        header('Location: '.basename(__FILE__)); exit;
      } else {
        $message = '❌ Insert failed.';
      }
      $ins->close();
    }
  }
}

// ---------- SEARCH (LIKE on full code) ----------
$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
$types  = '';

if ($q !== '') {
  // Build the code the same way we render it: row-bay-lvl-side with padding
  $where = "WHERE CONCAT(row_code,'-',LPAD(bay_num,2,'0'),'-',LPAD(level_code,2,'0'),'-',side) LIKE ?";
  $params[] = "%{$q}%";
  $types   .= 's';
}

// ---------- Totals by row + grand total ----------
$totSql = "
  SELECT row_code, COUNT(*) AS cnt
  FROM location
  ".($where ?: '')."
  GROUP BY row_code
  ORDER BY row_code
";
$ts = $conn->prepare($totSql);
if ($types) $ts->bind_param($types, ...$params);
$ts->execute();
$tres = $ts->get_result();
$rowTotals = []; $grandTotal = 0;
while ($t = $tres->fetch_assoc()) {
  $rowTotals[$t['row_code']] = (int)$t['cnt'];
  $grandTotal += (int)$t['cnt'];
}
$ts->close();

// total count for lazy-load
$cntSql = "SELECT COUNT(*) AS c FROM location ".($where ?: '');
$cs = $conn->prepare($cntSql);
if ($types) $cs->bind_param($types, ...$params);
$cs->execute();
$ct = $cs->get_result()->fetch_assoc();
$totalCount = (int)($ct['c'] ?? 0);
$cs->close();

// ---------- AJAX: lazy page ----------
if (isset($_GET['ajax']) && $_GET['ajax']==='1') {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=UTF-8');

  $offset = max(0, (int)($_GET['offset'] ?? 0));
  $limit  = max(1, min(500, (int)($_GET['limit'] ?? $PAGE_SIZE)));

$pageSql = "
  SELECT id, row_code, bay_num, level_code, side
  FROM location
  ".($where ?: '')."
  ORDER BY id ASC
  LIMIT ? OFFSET ?
";

  $ptypes  = $types.'ii';
  $pparams = $params; $pparams[] = $limit; $pparams[] = $offset;

  $ps = $conn->prepare($pageSql);
  $ps->bind_param($ptypes, ...$pparams);
  $ps->execute();
  $res = $ps->get_result();

  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $row = (string)$r['row_code'];
    $bay = pad2((string)$r['bay_num']);
    $lvl = pad2((string)$r['level_code']);
    $sd  = (string)$r['side'];
    $rows[] = [
      'id'   => (int)$r['id'],
      'code' => $row.'-'.$bay.'-'.$lvl.'-'.$sd,
    ];
  }
  $ps->close();

  echo json_encode([
    'ok'      => true,
    'rows'    => $rows,
    'hasMore' => ($offset + count($rows) < $totalCount),
  ], JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

// ---------- First page ----------
$firstSql = "
  SELECT id, row_code, bay_num, level_code, side
  FROM location
  ".($where ?: '')."
  ORDER BY id ASC
  LIMIT ? OFFSET 0
";

$fs = $conn->prepare($firstSql);
$fs->bind_param($types.'i', ...array_merge($params, [$PAGE_SIZE]));
$fs->execute();
$fres = $fs->get_result();

$firstRows = [];
while ($r = $fres->fetch_assoc()) {
  $row = (string)$r['row_code'];
  $bay = pad2((string)$r['bay_num']);
  $lvl = pad2((string)$r['level_code']);
  $sd  = (string)$r['side'];
  $firstRows[] = [
    'id'   => (int)$r['id'],
    'code' => $row.'-'.$bay.'-'.$lvl.'-'.$sd,
  ];
}
$fs->close();

// ---------- Render ----------
$title = 'Manage Location';
$page_class = 'page-manage-locations';
ob_start();
?>
<div class="container" style="max-width:1100px; margin:0 auto; padding:16px;">
  <h1 style="margin:0 0 6px;">Manage Location</h1>
  <p class="muted" style="margin:0 0 12px;">Read-only list of pre-seeded locations. Code is Row-Bay-Level-Side.</p>

  <?php if ($message): ?>
    <div class="card card--pad" style="margin-bottom:12px; <?= str_starts_with($message,'✅')?'border:1px solid #cfe9cf;background:#f6fff6;':'border:1px solid #f3c2c2;background:#fff6f6;' ?>">
      <?= h($message) ?>
    </div>
  <?php endif; ?>

  <!-- Create Location -->
  <form method="post" class="card" style="padding:12px; margin-bottom:14px; display:grid; gap:8px;">
    <div style="display:grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap:8px;">
      <div>
        <label style="font-size:.9rem;opacity:.8;">Row</label>
        <input name="row_code" class="input" placeholder="e.g., R10" required>
      </div>
      <div>
        <label style="font-size:.9rem;opacity:.8;">Bay</label>
        <input name="bay_num" class="input" placeholder="01" required>
      </div>
      <div>
        <label style="font-size:.9rem;opacity:.8;">Level</label>
        <input name="level_code" class="input" placeholder="11" required>
      </div>
      <div>
        <label style="font-size:.9rem;opacity:.8;">Side</label>
        <select name="side" class="input" required>
          <option value="F">F</option>
          <option value="B">B</option>
        </select>
      </div>
      <div style="display:flex;align-items:end;">
        <button class="btn btn-primary" type="submit" name="create_location">Create Location</button>
      </div>
    </div>
    <div class="small" style="opacity:.75;">Bay/Level will be zero-padded (e.g., 1 → 01).</div>
  </form>

  <!-- Search on full code (LIKE) -->
  <form method="get" class="card" style="padding:12px; margin-bottom:14px; display:flex; gap:8px; align-items:center;">
    <label for="q" style="font-size:.9rem; opacity:.8;">Search</label>
    <input id="q" name="q" class="input" value="<?= h($q) ?>"
           placeholder="Type part of code, e.g., R10-01-11 or -F" style="flex:1; max-width:480px;">
    <button class="btn btn-primary" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= h(basename(__FILE__)) ?>">Reset</a><?php endif; ?>
  </form>

  <!-- Totals -->
  <div class="card" style="padding:12px; margin-bottom:12px;">
    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
      <strong>Totals per Row:</strong>
      <?php if ($rowTotals): ?>
        <?php foreach ($rowTotals as $rk => $cnt): ?>
          <span class="badge" style="background:#eef; border:1px solid #cde; padding:4px 8px; border-radius:999px;">
            <?= h($rk) ?>: <?= (int)$cnt ?>
          </span>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="muted">No rows matched.</span>
      <?php endif; ?>
      <span style="margin-left:auto;"><strong>Grand Total:</strong> <?= (int)$grandTotal ?></span>
    </div>
  </div>

  <!-- Grid: ID + Code only -->
  <div class="card" style="overflow:auto;">
    <table class="table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th style="text-align:left; padding:10px; border-bottom:1px solid #ddd; width:120px;">ID</th>
          <th style="text-align:left; padding:10px; border-bottom:1px solid #ddd;">Code</th>
        </tr>
      </thead>
      <tbody id="locTbody">
        <?php if (!$firstRows): ?>
          <tr><td colspan="2" style="padding:14px;">No locations found.</td></tr>
        <?php else: foreach ($firstRows as $r): ?>
          <tr>
            <td style="padding:8px; border-bottom:1px solid #eee;"><?= (int)$r['id'] ?></td>
            <td style="padding:8px; border-bottom:1px solid #eee; font-family:ui-monospace, SFMono-Regular, Menlo, monospace;"><?= h($r['code']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr id="lazySentinel">
          <td colspan="2" style="padding:14px; text-align:center;">Loading more…</td>
        </tr>
        <tr id="lazyDone" hidden>
          <td colspan="2" style="padding:14px; text-align:center; color:#777;">All results loaded (<?= (int)$totalCount ?>)</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();

// ---------- Footer JS (lazy-load append for ID + Code) ----------
$footer_js = <<<HTML
<script>
(function(){
  const PAGE_SIZE   = {$PAGE_SIZE};
  const TOTAL_COUNT = {$totalCount};
  let offset        = PAGE_SIZE;
  let busy=false, done=(offset>=TOTAL_COUNT);

  const tbody  = document.getElementById('locTbody');
  const sent   = document.getElementById('lazySentinel');
  const doneEl = document.getElementById('lazyDone');

  if (!tbody || !sent || !doneEl) return;
  if (done) { sent.hidden = true; doneEl.hidden = false; return; }

  function td(txt, mono){
    const el=document.createElement('td');
    el.style.padding='8px'; el.style.borderBottom='1px solid #eee';
    if (mono) el.style.fontFamily='ui-monospace, SFMono-Regular, Menlo, monospace';
    el.textContent = txt ?? '';
    return el;
  }

  function appendRows(rows){
    if (!rows || rows.length===0){ done=true; sent.hidden=true; doneEl.hidden=false; observer && observer.disconnect(); return; }
    for (const r of rows){
      const tr=document.createElement('tr');
      tr.appendChild(td(String(r.id)));
      tr.appendChild(td(r.code, true));
      tbody.appendChild(tr);
    }
  }

  async function fetchPage(){
    if (busy || done) return;
    busy = true;
    try{
      const url=new URL(window.location.href);
      url.searchParams.set('ajax','1');
      url.searchParams.set('offset', String(offset));
      url.searchParams.set('limit',  String(PAGE_SIZE));
      const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }});
      if(!res.ok) throw new Error('Network error');
      const data = await res.json();
      appendRows(data.rows || []);
      offset += (data.rows?.length || 0);
      done = !data.hasMore;
      if (done){ sent.hidden=true; doneEl.hidden=false; observer && observer.disconnect(); }
    }catch(e){
      console.error(e);
      observer && observer.disconnect();
      sent.textContent='Could not load more rows.';
    }finally{ busy=false; }
  }

  const observer = new IntersectionObserver((ents)=>{ for(const e of ents){ if(e.isIntersecting) fetchPage(); } }, {root:null, rootMargin:'600px 0px', threshold:0});
  observer.observe(sent);
})();
</script>
HTML;

include __DIR__ . '/templates/layout.php';
