<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/dbinv.php';

// ---------- php compat / polyfills ----------
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    if ($needle === '') return true;
    return strncmp((string)$haystack, (string)$needle, strlen($needle)) === 0;
  }
}

// Safe get_result wrapper: returns mysqli_result|false
function stmt_get_result_safe(mysqli_stmt $stmt) {
  if (function_exists('mysqli_stmt_get_result')) {
    return mysqli_stmt_get_result($stmt); // procedural wrapper; only if mysqlnd is present
  }
  return false;
}

// helper: fetch ALL rows as assoc even without mysqlnd
function stmt_fetch_all_assoc(mysqli_stmt $stmt): array {
  $res = stmt_get_result_safe($stmt);
  if ($res instanceof mysqli_result) {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
    return $rows ?: [];
  }
  // fallback without mysqlnd
  $meta = $stmt->result_metadata();
  if (!$meta) return [];
  $row = [];
  $bind = [];
  while ($field = $meta->fetch_field()) {
    $row[$field->name] = null;
    $bind[] = &$row[$field->name];
  }
  call_user_func_array([$stmt, 'bind_result'], $bind);
  $out = [];
  while ($stmt->fetch()) {
    $out[] = array_map(static function($v){ return $v; }, $row);
  }
  return $out;
}

// ---------- auth ----------
if (!isset($_SESSION['username'])) {
  header("Location: login.php");
  exit;
}
$user_id  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$username = $_SESSION['username'] ?? 'User';
$role_id  = (int)($_SESSION['role_id'] ?? 0);

// Restrict access to admins only
$allowed_roles = [1]; // adjust if needed
if (!in_array($_SESSION['role_id'] ?? null, $allowed_roles, true)) {
  die("<p style='color:red; text-align:center; font-size:18px; margin-top:50px;'>
        Access denied. Only certain roles can access this page.
      </p>");
}

// ---------- config ----------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!($conn instanceof mysqli)) {
  die("<p style='color:red; text-align:center; font-size:18px; margin-top:50px;'>Database connection error.</p>");
}
try { $conn->set_charset('utf8mb4'); } catch (\Throwable $_) {}
const PAGE_SIZE = 25;
$STATUS_ALLOWED = ['ACTIVE','INACTIVE'];

// ---------- helpers ----------
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function is_valid_status(string $s, array $allowed): bool { return in_array($s, $allowed, true); }

// ---------- messaging ----------
$message = "";            // banner text
$message_type = "";       // "success" | "error" | "warning"
$inline_errors = [];      // Add form sticky errors

// ---------- Search + pagination (GET) ----------
$q     = trim($_GET['q'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$off   = ($page - 1) * PAGE_SIZE;

// Predeclare sticky add values
$add_sku_num = $add_desc = '';
$add_status  = 'ACTIVE';
$add_qty     = 0;

// ---------- Add SKU (POST) ----------
if (isset($_POST['add_sku'])) {
  $add_sku_num  = trim($_POST['sku_num'] ?? '');
  $add_desc     = trim(substr($_POST['desc'] ?? '', 0, 100));
  $add_status   = trim($_POST['status'] ?? 'ACTIVE');
  $add_qty      = (int)($_POST['quantity'] ?? 0);

  if ($add_sku_num === '') $inline_errors['sku_num'] = 'SKU number is required.';
  if ($add_desc === '')    $inline_errors['desc']    = 'Description is required.';
  if (!is_valid_status($add_status, $STATUS_ALLOWED)) $inline_errors['status'] = 'Invalid status.';
  if ($add_qty < 0)        $inline_errors['quantity'] = 'Quantity cannot be negative.';

  if (!$inline_errors) {
    $stmt = $conn->prepare('SELECT id FROM sku WHERE sku_num = ? LIMIT 1');
    $stmt->bind_param('s', $add_sku_num);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) $inline_errors['sku_num'] = 'This SKU number already exists.';
    $stmt->close();
  }

  if ($inline_errors) {
    $message = "Please fix the errors below.";
    $message_type = "error";
  } else {
    $stmt = $conn->prepare('INSERT INTO sku (sku_num, `desc`, `status`, quantity, created_at) VALUES (?,?,?,?, NOW())');
    $stmt->bind_param('sssi', $add_sku_num, $add_desc, $add_status, $add_qty);
    $stmt->execute();
    $stmt->close();
    $message = "SKU added successfully.";
    $message_type = "success";
    $add_sku_num = $add_desc = '';
    $add_status  = 'ACTIVE';
    $add_qty     = 0;
  }
}

// ---------- Row actions (POST) ----------
if (isset($_POST['update_sku']) || isset($_POST['delete_sku'])) {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) {
    $message = "Invalid record.";
    $message_type = "error";
  } elseif (isset($_POST['delete_sku'])) {
    try {
      $stmt = $conn->prepare('DELETE FROM sku WHERE id = ?');
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $stmt->close();
      $message = "SKU deleted.";
      $message_type = "success";
    } catch (\mysqli_sql_exception $e) {
      if ($e->getCode() === 1451) {
        $message = "Cannot delete: this SKU is referenced by inventory or movement history.";
        $message_type = "error";
      } else {
        $message = "Delete error: " . $e->getMessage();
        $message_type = "error";
      }
    }
  } else {
    $sku_num  = trim($_POST['sku_num'] ?? '');
    $desc     = trim(substr($_POST['desc'] ?? '', 0, 100));
    $status   = trim($_POST['status'] ?? 'ACTIVE');
    $quantity = (int)($_POST['quantity'] ?? 0);

    $row_err = [];
    if ($sku_num === '')                $row_err[] = 'SKU number is required.';
    if ($desc === '')                   $row_err[] = 'Description is required.';
    if (!is_valid_status($status, $STATUS_ALLOWED)) $row_err[] = 'Invalid status.';
    if ($quantity < 0)                  $row_err[] = 'Quantity cannot be negative.';

    if (!$row_err) {
      $stmt = $conn->prepare('SELECT id FROM sku WHERE sku_num = ? AND id <> ? LIMIT 1');
      $stmt->bind_param('si', $sku_num, $id);
      $stmt->execute();
      $stmt->store_result();
      if ($stmt->num_rows > 0) $row_err[] = 'This SKU number is already used by another record.';
      $stmt->close();
    }

    if ($row_err) {
      $message = "Update error: " . implode(' ', $row_err);
      $message_type = "error";
    } else {
      $stmt = $conn->prepare('UPDATE sku SET sku_num = ?, `desc` = ?, `status` = ?, quantity = ? WHERE id = ?');
      $stmt->bind_param('sssii', $sku_num, $desc, $status, $quantity, $id);
      $stmt->execute();
      $stmt->close();
      $message = "SKU updated successfully.";
      $message_type = "success";
    }
  }
}

// ---------- Fetch list (search + pagination) ----------
$where = '';
$params = [];
$types  = '';

if ($q !== '') {
  $where = 'WHERE (sku_num LIKE ? OR `desc` LIKE ?)';
  $like  = "%{$q}%";
  $params[] = $like; $params[] = $like;
  $types   .= 'ss';
}

// total
if ($where) {
  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sku $where");
  $stmt->bind_param($types, ...$params);
} else {
  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sku");
}
$stmt->execute();
$total = 0;
$resCnt = stmt_get_result_safe($stmt);
if ($resCnt instanceof mysqli_result) {
  $rowCnt = $resCnt->fetch_assoc();
  $total = (int)($rowCnt['c'] ?? 0);
  $resCnt->free();
} else {
  $stmt->bind_result($c);
  if ($stmt->fetch()) $total = (int)$c;
}
$stmt->close();

// rows
if ($where) {
  $stmt = $conn->prepare("SELECT id, sku_num, `desc`, `status`, quantity FROM sku $where ORDER BY sku_num ASC LIMIT ? OFFSET ?");
  $limit = PAGE_SIZE; $offset = $off;
  $params2 = $params; $types2 = $types . 'ii';
  $params2[] = $limit; $params2[] = $offset;
  $stmt->bind_param($types2, ...$params2);
} else {
  $stmt = $conn->prepare("SELECT id, sku_num, `desc`, `status`, quantity FROM sku ORDER BY sku_num ASC LIMIT ? OFFSET ?");
  $limit = PAGE_SIZE; $offset = $off;
  $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$rows = stmt_fetch_all_assoc($stmt);
$stmt->close();

$pages = max(1, (int)ceil($total / PAGE_SIZE));

// ---------- PAGE LAYOUT WIRING ----------
$title    = 'Manage SKUs';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$extra_head = <<<HTML
<link rel="stylesheet" href="{$BASE_URL}/css/site.css?v=1">
HTML;

ob_start();
?>
  <h2 class="title">Manage SKUs</h2>
  <span class="small">Admin-only</span>

  <?php if ($message): ?>
    <?php
      $cls = 'message';
      if ($message_type === 'success') $cls = 'message message--good';
      elseif ($message_type === 'error') $cls = 'message message--bad';
      elseif ($message_type === 'warning') $cls = 'message';
    ?>
    <div class="card card--pad <?= h($cls) ?>" style="margin-top:12px;">
      <?= h($message) ?>
    </div>
  <?php endif; ?>

  <!-- Search -->
  <div class="card card--pad" style="margin-top:12px;">
    <form method="get" class="form" style="display:flex; gap:8px; align-items:center;">
      <input class="input" type="text" name="q" value="<?= h($q) ?>" placeholder="Search by SKU number or description" style="flex:1; min-width:260px;">
      <button class="btn btn--primary" type="submit">Search</button>
      <?php if ($q !== ''): ?>
        <a class="btn btn--ghost" href="<?= h($_SERVER['PHP_SELF']) ?>">Clear</a>
      <?php endif; ?>
    </form>
    <div class="small" style="margin-top:6px;">Showing <?= $total === 0 ? 0 : ($off+1) ?>–<?= $off + count($rows) ?> of <?= $total ?></div>
  </div>

  <!-- Add SKU -->
  <div class="card card--pad" style="margin-top:16px;">
    <form class="form" method="POST" novalidate>
      <h3 style="margin:0 0 10px">Add New SKU</h3>
      <div class="form-row" style="display:grid;grid-template-columns:1fr 2fr 1fr 1fr auto;gap:8px;">
        <div>
          <input class="input <?= isset($inline_errors['sku_num']) ? 'is-invalid' : '' ?>" 
                 type="text" name="sku_num" placeholder="SKU Number" value="<?= h($add_sku_num) ?>" required>
          <?php if (!empty($inline_errors['sku_num'])): ?>
            <div class="invalid small"><?= h($inline_errors['sku_num']) ?></div>
          <?php endif; ?>
        </div>
        <div>
          <input class="input <?= isset($inline_errors['desc']) ? 'is-invalid' : '' ?>" 
                 type="text" name="desc" maxlength="100" placeholder="Description (max 100 chars)" value="<?= h($add_desc) ?>" required>
          <?php if (!empty($inline_errors['desc'])): ?>
            <div class="invalid small"><?= h($inline_errors['desc']) ?></div>
          <?php endif; ?>
        </div>
        <div>
          <select class="select <?= isset($inline_errors['status']) ? 'is-invalid' : '' ?>" name="status" required>
            <?php foreach ($STATUS_ALLOWED as $opt): ?>
              <option value="<?= h($opt) ?>" <?= $add_status === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($inline_errors['status'])): ?>
            <div class="invalid small"><?= h($inline_errors['status']) ?></div>
          <?php endif; ?>
        </div>
        <div>
          <input class="input <?= isset($inline_errors['quantity']) ? 'is-invalid' : '' ?>" 
                 type="number" name="quantity" min="0" placeholder="Quantity" value="<?= h((string)$add_qty) ?>" required>
          <?php if (!empty($inline_errors['quantity'])): ?>
            <div class="invalid small"><?= h($inline_errors['quantity']) ?></div>
          <?php endif; ?>
        </div>
        <div>
          <button class="btn btn--primary" type="submit" name="add_sku">Add SKU</button>
        </div>
      </div>
    </form>
  </div>

  <!-- SKU Table -->
  <div class="card card--pad" style="margin-top:16px;">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="min-width:160px">SKU Number</th>
            <th style="min-width:280px">Description</th>
            <th>Status</th>
            <th style="min-width:120px">Quantity</th>
            <th style="min-width:180px">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="text-center">No results.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td colspan="5" style="padding:0; border:0;">
                <form method="POST" class="form" style="display:grid; grid-template-columns:1fr 2fr 1fr 1fr auto; gap:8px; padding:8px;">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input class="input" type="text" name="sku_num" value="<?= h($row['sku_num']) ?>">
                  <input class="input" type="text" name="desc" maxlength="100" value="<?= h($row['desc']) ?>">
                  <select class="select" name="status">
                    <?php foreach ($STATUS_ALLOWED as $opt): ?>
                      <option value="<?= h($opt) ?>" <?= $row['status'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input class="input" type="number" min="0" name="quantity" value="<?= h((string)$row['quantity']) ?>">
                  <div style="display:flex; gap:8px; align-items:center; justify-content:flex-start;">
                    <button class="btn btn--ghost" type="submit" name="update_sku" title="Update this SKU">Update</button>
                    <button class="link-danger" type="submit" name="delete_sku" title="Delete" onclick="return confirm('Delete this SKU?');">Delete</button>
                  </div>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
      <nav style="margin-top:12px; display:flex; gap:6px; flex-wrap:wrap;">
        <?php
          $base = $_SERVER['PHP_SELF'].'?q='.urlencode($q).'&page=';
          $prev = max(1, $page-1);
          $next = min($pages, $page+1);
          $link = function($p, $label, $disabled=false, $active=false) use ($base){
            $cls = 'btn btn--ghost';
