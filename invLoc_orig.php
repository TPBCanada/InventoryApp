<?php
// invLoc.php — Location Details (read-only)
// Pick a location (dropdowns or code) → show SKUs at that loc with qty > 0
declare(strict_types=1);

session_start();
require_once __DIR__ . '/dbinv.php';

// ---------------------------------------------
// Auth
// ---------------------------------------------
if (!isset($_SESSION['username'])) {
  header('Location: login.php');
  exit;
}

// ---------------------------------------------
// Helpers
// ---------------------------------------------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $conn->set_charset('utf8mb4'); } catch (\Throwable $_) {}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fetchDistinct(mysqli $conn, string $col): array {
  $valid = ['row_code','bay_num','level_code','side'];
  if (!in_array($col, $valid, true)) return [];
  $sql = "SELECT DISTINCT $col AS v FROM location ORDER BY $col";
  $res = $conn->query($sql);
  $out = [];
  while ($row = $res->fetch_assoc()) $out[] = (string)$row['v'];
  return $out;
}

// ---------------------------------------------
// Populate dropdown choices
// ---------------------------------------------
$rows   = fetchDistinct($conn, 'row_code');
$bays   = fetchDistinct($conn, 'bay_num');
$levels = fetchDistinct($conn, 'level_code');
$sides  = fetchDistinct($conn, 'side');

// ---------------------------------------------
// Read filters
// ---------------------------------------------
$loc_code = trim($_GET['loc_code'] ?? '');                 // e.g. R10-1-11-F
$row_code = trim($_GET['row_code'] ?? '');
$bay_num  = trim($_GET['bay_num'] ?? '');
$level    = trim($_GET['level_code'] ?? '');
$side     = trim($_GET['side'] ?? '');

// Selected values for sticky form
$sel = [
  'loc_code'   => $loc_code,
  'row_code'   => $row_code,
  'bay_num'    => $bay_num,
  'level_code' => $level,
  'side'       => $side,
];

// ---------------------------------------------
// Resolve loc_id
// ---------------------------------------------
$loc_id = null;
$loc_label = '';

if ($loc_code !== '') {
  // Prefer a dedicated code column if it exists; otherwise match composed code
  // Try direct code column first
  try {
    $stmt = $conn->prepare("SELECT id, row_code, bay_num, level_code, side FROM location WHERE code = ?");
    $stmt->bind_param('s', $loc_code);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($rec = $res->fetch_assoc()) {
      $loc_id = (int)$rec['id'];
      $loc_label = "{$rec['row_code']}-{$rec['bay_num']}-{$rec['level_code']}-{$rec['side']}";
    }
    $stmt->close();
  } catch (\Throwable $_) {
    // Fall back to composed code if 'code' column doesn't exist
  }

  if ($loc_id === null) {
    // Parse "R10-1-11-F" into parts and match
    $parts = preg_split('/\s*-\s*/', $loc_code);
    if (count($parts) === 4) {
      [$r,$b,$l,$s] = $parts;
      $stmt = $conn->prepare(
        "SELECT id, row_code, bay_num, level_code, side
           FROM location
          WHERE row_code=? AND bay_num=? AND level_code=? AND side=?"
      );
      $stmt->bind_param('ssss', $r,$b,$l,$s);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($rec = $res->fetch_assoc()) {
        $loc_id = (int)$rec['id'];
        $loc_label = "{$rec['row_code']}-{$rec['bay_num']}-{$rec['level_code']}-{$rec['side']}";
      }
      $stmt->close();
    }
  }
} elseif ($row_code !== '' && $bay_num !== '' && $level !== '' && $side !== '') {
  $stmt = $conn->prepare(
    "SELECT id, row_code, bay_num, level_code, side
       FROM location
      WHERE row_code=? AND bay_num=? AND level_code=? AND side=?"
  );
  $stmt->bind_param('ssss', $row_code, $bay_num, $level, $side);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($rec = $res->fetch_assoc()) {
    $loc_id = (int)$rec['id'];
    $loc_label = "{$rec['row_code']}-{$rec['bay_num']}-{$rec['level_code']}-{$rec['side']}";
  }
  $stmt->close();
}

// ---------------------------------------------
// Query inventory for that loc_id (hide zero)
// Join sku and compute last movement (latest created_at)
// ---------------------------------------------
$rows_out = [];
$error_msg = '';

if ($loc_id !== null) {
  $sql = "
    SELECT
      s.id          AS sku_id,
      s.sku_num     AS sku,
      s.`desc`      AS `desc`,
      i.quantity    AS on_hand,
      (
        SELECT MAX(m.created_at)
          FROM inventory_movements m
         WHERE m.sku_id = i.sku_id
           AND m.loc_id = i.loc_id
      ) AS last_moved_at
    FROM inventory i
    JOIN sku s ON s.id = i.sku_id
   WHERE i.loc_id = ?
     AND i.quantity > 0
   ORDER BY s.sku_num ASC
  ";
  try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $loc_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $rows_out[] = [
        'sku_id'        => (int)$r['sku_id'],
        'sku'           => (string)$r['sku'],
        'desc'          => (string)$r['desc'],
        'on_hand'       => (int)$r['on_hand'],
        'last_moved_at' => $r['last_moved_at'] ?? null,
      ];
    }
    $stmt->close();
  } catch (\Throwable $e) {
    $error_msg = 'Query failed. Please try again.';
  }
}

// ---------------------------------------------
// Page content
// ---------------------------------------------
$title = 'Location Details';
$page_class = 'page-loc-details';
ob_start();
?>

  <h1 class="h3" style="margin:0 0 12px;">Location Details</h1>

  <form method="get" class="card" style="padding:16px; margin-bottom:16px;">
    <div style="display:grid; gap:12px; grid-template-columns: repeat(auto-fit,minmax(150px,1fr)); align-items:end;">
      <div>
        <label for="loc_code" class="form-label">Location Code</label>
        <input type="text" id="loc_code" name="loc_code" class="form-control" placeholder="e.g. R10-1-11-F" value="<?= h($sel['loc_code']) ?>">
        <small class="text-muted">You can use this instead of the dropdowns.</small>
      </div>

      <div>
        <label for="row_code" class="form-label">Row</label>
        <select id="row_code" name="row_code" class="form-select">
          <option value="">—</option>
          <?php foreach ($rows as $v): ?>
            <option value="<?= h($v) ?>"<?= $v===$sel['row_code']?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="bay_num" class="form-label">Bay</label>
        <select id="bay_num" name="bay_num" class="form-select">
          <option value="">—</option>
          <?php foreach ($bays as $v): ?>
            <option value="<?= h($v) ?>"<?= $v===$sel['bay_num']?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="level_code" class="form-label">Level</label>
        <select id="level_code" name="level_code" class="form-select">
          <option value="">—</option>
          <?php foreach ($levels as $v): ?>
            <option value="<?= h($v) ?>"<?= $v===$sel['level_code']?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="side" class="form-label">Side</label>
        <select id="side" name="side" class="form-select">
          <option value="">—</option>
          <?php foreach ($sides as $v): ?>
            <option value="<?= h($v) ?>"<?= $v===$sel['side']?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Show Inventory</button>
      </div>
    </div>

    <div class="form-text" style="margin-top:8px;">
      If both a Location Code and dropdowns are provided, the Location Code takes precedence.
    </div>
  </form>

  <?php if ($loc_id !== null): ?>
    <div class="mb-2">
      <span class="badge bg-info text-dark">Location: <?= h($loc_label) ?> (ID <?= h((string)$loc_id) ?>)</span>
    </div>
  <?php endif; ?>

  <?php if ($error_msg): ?>
    <div class="alert alert-danger"><?= h($error_msg) ?></div>
  <?php endif; ?>

  <?php if ($loc_id !== null): ?>
    <div class="card" style="overflow:auto;">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>SKU</th>
            <th>Description</th>
            <th class="text-end">On-Hand</th>
            <th>Last Movement At</th>
            <th>Quick Links</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows_out)): ?>
          <tr><td colspan="5" class="text-muted">No inventory with quantity &gt; 0 at this location.</td></tr>
        <?php else: ?>
          <?php foreach ($rows_out as $r): ?>
            <tr>
              <td><?= h($r['sku']) ?></td>
              <td><?= h($r['desc']) ?></td>
              <td class="text-end"><?= h((string)$r['on_hand']) ?></td>
              <td><?= $r['last_moved_at'] ? h($r['last_moved_at']) : '<span class="text-muted">—</span>' ?></td>
              <td>
                <a class="btn btn-outline-secondary btn-sm"
                   href="history.php?sku=<?= urlencode($r['sku']) ?>&loc=<?= urlencode((string)$loc_id) ?>">
                  History
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php elseif ($_GET): ?>
    <div class="alert alert-warning">No matching location found. Please check your inputs.</div>
  <?php endif; ?>

<?php
$content = ob_get_clean();

// Optional: tiny JS to auto-fill dropdowns if user types a code like R10-1-11-F
$footer_js = <<<HTML
<script>
  (function(){
    const code = document.getElementById('loc_code');
    code?.addEventListener('change', () => {
      const v = code.value.trim();
      const parts = v.split('-');
      if (parts.length === 4){
        const [r,b,l,s] = parts;
        const setVal = (id,val)=>{ const el=document.getElementById(id); if(el){ el.value = val; } };
        setVal('row_code', r);
        setVal('bay_num', b);
        setVal('level_code', l);
        setVal('side', s);
      }
    });
  })();
</script>
HTML;

$css = []; $js = [];
$page_class .= ' theme-default';
require __DIR__ . '/templates/layout.php';
