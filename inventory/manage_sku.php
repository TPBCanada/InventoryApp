<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/utils/helpers.php';


if (!isset($_SESSION['username'])) {
  header('Location: ../login.php');
  exit;
}

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

// ---------- config ----------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!isset($conn) || !($conn instanceof mysqli)) {
  die("<p style='color:red; text-align:center; font-size:18px; margin-top:50px;'>Database connection error.</p>");
}

$DEBUG = false;
if (!empty($_GET['debug']) && $_GET['debug'] === '1' && ($role_id === 99 /* admin */ || getenv('APP_ENV') === 'dev')) {
  $DEBUG = true;
  // Enable PHP error reporting
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

try {
  $conn->set_charset('utf8mb4');
} catch (\Throwable $_) {
}
if (!defined('PAGE_SIZE'))
  define('PAGE_SIZE', 25);
// --- STATUS UPDATED ---
$STATUS_ALLOWED = ['ACTIVE', 'DISCONTINUED'];
// -----------------------

// ---------- Barcode management AJAX (CRUD for product_uom_barcodes) ----------
if (isset($_POST['barcode_action'])) {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  $action = $_POST['barcode_action'] ?? '';
  $sku_id = (int) ($_POST['sku_id'] ?? 0);

  $out = ['ok' => false, 'msg' => '', 'rows' => []];
  if ($sku_id <= 0) {
    $out['msg'] = 'Invalid SKU';
    echo json_encode($out);
    exit;
  }

  $readBarcodeFields = function (): array {
    $dim = function (string $key): ?float {
      $v = trim((string) ($_POST[$key] ?? ''));
      return ($v === '' || $v === 'null') ? null : (float) $v;
    };
    $no_barcode = (int) ($_POST['no_barcode'] ?? 0) === 1 ? 1 : 0;
    $upc = trim((string) ($_POST['upc'] ?? ''));
    if ($upc === 'null') $upc = '';
    if ($upc === '' && !$no_barcode) {
      throw new RuntimeException('UPC is required unless No Barcode is checked.');
    }
    // NULL, not '', so several no-barcode rows don't collide on uniq_upc
    $upc = ($no_barcode || $upc === '') ? null : $upc;
    $uom = strtoupper(trim((string) ($_POST['uom_label'] ?? 'UNIT')));
    $mult = max(1, (int) ($_POST['multiplier'] ?? 1));
    $src = strtoupper(trim((string) ($_POST['source'] ?? '')));
    $source = in_array($src, ['SUPPLIER', 'OWN_LABEL', 'REPACK'], true) ? $src : null;
    $weight = $dim(isset($_POST['weight_lbs']) ? 'weight_lbs' : 'weight');
    return [$upc, $uom, $mult, $source, $no_barcode, $dim('length'), $dim('width'), $dim('height'), $weight];
  };
  $assertUpcUnique = function (?string $upc, int $excludeId) use ($conn): void {
    if ($upc === null) return;
    $chk = $conn->prepare('SELECT id FROM product_uom_barcodes WHERE upc = ? AND id <> ? LIMIT 1');
    $chk->bind_param('si', $upc, $excludeId);
    $chk->execute();
    $chk->store_result();
    $dup = $chk->num_rows > 0;
    $chk->close();
    if ($dup) throw new RuntimeException("Duplicate UPC: {$upc} already exists.");
  };

  try {
    if ($action === 'list') {
      $stmt = $conn->prepare("SELECT id, upc, uom_label, multiplier, enabled, source, no_barcode, length, width, height, weight_lbs FROM product_uom_barcodes WHERE sku_id = ? ORDER BY upc ASC");
      $stmt->bind_param('i', $sku_id);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        $out['rows'][] = [
          'id' => (int) $r['id'],
          'upc' => (string) ($r['upc'] ?? ''),
          'uom_label' => (string) $r['uom_label'],
          'multiplier' => (int) $r['multiplier'],
          'enabled' => (int) ($r['enabled'] ?? 1),
          'source' => (string) ($r['source'] ?? ''),
          'no_barcode' => (int) $r['no_barcode'],
          'length' => $r['length'],
          'width' => $r['width'],
          'height' => $r['height'],
          'weight_lbs' => $r['weight_lbs'],
        ];
      }
      $stmt->close();
      $out['ok'] = true;
    } elseif ($action === 'create') {
      [$upc, $uom, $mult, $source, $no_barcode, $length, $width, $height, $weight] = $readBarcodeFields();
      $chk = $conn->prepare('SELECT id FROM product_uom_barcodes WHERE sku_id = ? AND uom_label = ? LIMIT 1');
      $chk->bind_param('is', $sku_id, $uom);
      $chk->execute();
      $chk->store_result();
      if ($chk->num_rows > 0) {
        $chk->close();
        throw new RuntimeException("A {$uom} barcode already exists for this SKU.");
      }
      $chk->close();
      $assertUpcUnique($upc, 0);
      $stmt = $conn->prepare('INSERT INTO product_uom_barcodes (upc, sku_id, uom_label, multiplier, source, no_barcode, length, width, height, weight_lbs, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
      $stmt->bind_param('sisisiddddi', $upc, $sku_id, $uom, $mult, $source, $no_barcode, $length, $width, $height, $weight, $user_id);
      $stmt->execute();
      $new_id = $stmt->insert_id;
      $stmt->close();
      $out['ok'] = true;
      $out['id'] = $new_id;
    } elseif ($action === 'update') {
      $id = (int) ($_POST['id'] ?? 0);
      if ($id <= 0) {
        throw new RuntimeException('Invalid id');
      }
      [$upc, $uom, $mult, $source, $no_barcode, $length, $width, $height, $weight] = $readBarcodeFields();
      $assertUpcUnique($upc, $id);
      $stmt = $conn->prepare("UPDATE product_uom_barcodes SET upc = ?, uom_label = ?, multiplier = ?, length = ?, width = ?, height = ?, weight_lbs = ?, source = ?, no_barcode = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND sku_id = ?");
      $stmt->bind_param('ssiddddsiiii', $upc, $uom, $mult, $length, $width, $height, $weight, $source, $no_barcode, $user_id, $id, $sku_id);
      $stmt->execute();
      $stmt->close();
      $out['ok'] = true;
    } elseif ($action === 'delete') {
      $id = (int) ($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid id');
      $stmt = $conn->prepare("DELETE FROM product_uom_barcodes WHERE id = ? AND sku_id = ?");
      $stmt->bind_param('ii', $id, $sku_id);
      $stmt->execute();
      $stmt->close();
      $out['ok'] = true;
    } elseif ($action === 'toggle_enabled') {
      $id = (int) ($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid id');
      if (isset($_POST['enabled_value'])) {
        $enabledValue = (int) $_POST['enabled_value'] === 1 ? 1 : 0;
        $stmt = $conn->prepare('UPDATE product_uom_barcodes SET enabled = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND sku_id = ?');
        $stmt->bind_param('iiii', $enabledValue, $user_id, $id, $sku_id);
      } else {
        $stmt = $conn->prepare('UPDATE product_uom_barcodes SET enabled = 1 - enabled, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND sku_id = ?');
        $stmt->bind_param('iii', $user_id, $id, $sku_id);
      }
      $stmt->execute();
      $stmt->close();
      $out['ok'] = true;
    } else {
      $out['msg'] = 'Unknown action';
    }
  } catch (Throwable $e) {
    $out['msg'] = $e->getMessage();
  }

  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}


/* -------------------- SUGGEST API (lightweight) --------------------*/
// This function will exit if ?suggest=1 is present.
handle_suggestions($conn, $DEBUG);

// Optional: enable debug via ?debug=1
if (isset($_GET['debug'])) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

// ---------- php compat / polyfills ----------
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle)
  {
    if ($needle === '')
      return true;
    return strncmp((string) $haystack, (string) $needle, strlen($needle)) === 0;
  }
}



if (!function_exists('is_valid_status')) {
  function is_valid_status($s, $allowed)
  {
    return in_array((string) $s, $allowed, true);
  }
}

// ---------- messaging ----------
$message = "";
$message_type = ""; // success | error | warning
$inline_errors = [];

// ---------- Search + pagination (GET/POST to preserve state after actions) ----------
$q = trim($_POST['q'] ?? $_GET['q'] ?? '');

$filter_status = trim($_POST['filter_status'] ?? $_GET['filter_status'] ?? '');
if ($filter_status === 'ALL')
  $filter_status = ''; // Treat 'ALL' as no filter

// Reset page to 1 if a new search query or filter is detected
$page = max(1, (int) ($_POST['page'] ?? $_GET['page'] ?? 1));
$off = ($page - 1) * PAGE_SIZE;

// Predeclare sticky add values
$add_sku_num = $add_desc = '';
// $add_upc = ''; // REMOVED
$add_status = 'ACTIVE';

// ---------- Add SKU (POST) ----------
if (isset($_POST['add_sku'])) {
  $add_sku_num = trim($_POST['sku_num'] ?? '');
  // $add_upc = trim($_POST['upc'] ?? ''); // REMOVED
  $add_desc = trim(substr($_POST['desc'] ?? '', 0, 255));
  $add_status = trim($_POST['status'] ?? 'ACTIVE');

  // $add_upc_for_db = $add_upc === '' ? NULL : $add_upc; // REMOVED - Convert empty string to NULL for insertion

  if ($add_sku_num === '')
    $inline_errors['sku_num'] = 'SKU number is required.';
  if ($add_desc === '')
    $inline_errors['desc'] = 'Description is required.';
  if (!is_valid_status($add_status, $STATUS_ALLOWED))
    $inline_errors['status'] = 'Invalid status.';

  // duplicate check (SKU)
  if (!$inline_errors) {
    $found_dup = false;

    // Check SKU uniqueness
    $stmt_sku = $conn->prepare('SELECT id FROM sku WHERE sku_num = ? LIMIT 1');
    $stmt_sku->bind_param('s', $add_sku_num);
    $stmt_sku->execute();
    $stmt_sku->store_result();
    if ($stmt_sku->num_rows > 0) {
      $inline_errors['sku_num'] = 'This SKU number already exists.';
      $found_dup = true;
    }
    $stmt_sku->close();

    // Check UPC uniqueness (only if UPC is provided) - REMOVED

  }

  if ($inline_errors) {
    $message = "Please fix the errors below.";
    $message_type = "error";
  } else {
    // Insert without 'upc'
    $stmt = $conn->prepare('INSERT INTO sku (sku_num, `desc`, `status`) VALUES (?,?,?)');

    // Bind parameters without UPC
    $stmt->bind_param('sss', $add_sku_num, $add_desc, $add_status);

    $stmt->execute();
    $stmt->close();
    $message = "SKU added successfully.";
    $message_type = "success";

    // Reset sticky values
    $add_sku_num = $add_desc = '';
    $add_status = 'ACTIVE';
  }
}

// ---------- Row actions (POST) ----------
if (isset($_POST['update_sku']) || isset($_POST['delete_sku'])) {
  $id = (int) ($_POST['id'] ?? 0);
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
        $message = "Delete error: " . h($e->getMessage());
        $message_type = "error";
      }
    }
  } else {
    $sku_num = trim($_POST['sku_num'] ?? '');
    // $upc = trim($_POST['upc'] ?? ''); // REMOVED
    $desc = trim(substr($_POST['desc'] ?? '', 0, 255));
    $status = trim($_POST['status'] ?? 'ACTIVE');
    $lot_required = isset($_POST['lot_checkbox']) ? 1 : 0;
 
    $row_err = [];
    if ($sku_num === '')
      $row_err[] = 'SKU number is required.';
    if ($desc === '')
      $row_err[] = 'Description is required.';
    if (!is_valid_status($status, $STATUS_ALLOWED))
      $row_err[] = 'Invalid status.';

    // duplicate check (exclude self)
    if (!$row_err) {
      $found_dup = false;

      // Check for duplicate SKU_num (excluding self)
      $stmt = $conn->prepare('SELECT id FROM sku WHERE sku_num = ? AND id <> ? LIMIT 1');
      $stmt->bind_param('si', $sku_num, $id);
      $stmt->execute();
      $stmt->store_result();
      if ($stmt->num_rows > 0) {
        $row_err[] = 'This SKU number is already used by another record.';
        $found_dup = true;
      }
      $stmt->close();
    }

    if ($row_err) {
      $message = "Update error: " . implode(' ', $row_err);
      $message_type = "error";
    } else {
      // Update without 'upc'
      $stmt = $conn->prepare('
        UPDATE sku
        SET
          sku_num = ?,
          `desc` = ?,
          `status` = ?,
          `lot_required` = ?
        WHERE id = ?
      ');

      // Bind order: sku_num(s), desc(s), status(s), lot_required(i), id(i)
      $stmt->bind_param('sssii', $sku_num, $desc, $status, $lot_required, $id);
      $stmt->execute();
      $stmt->close();

      $message = "SKU updated successfully.";
      $message_type = "success";
    }
  }
}

// ---------- Fetch list (search + pagination) ----------
$where_clauses = [];
$params = [];
$types = '';

// Status Filter
if ($filter_status !== '' && is_valid_status($filter_status, $STATUS_ALLOWED)) {
  $where_clauses[] = '`status` = ?';
  $params[] = $filter_status;
  $types .= 's';
}

// Search Query Filter
if ($q !== '') {
  // Search includes sku_num, and desc (removed upc)
  $where_clauses[] = '(sku_num LIKE ? OR `desc` LIKE ?)';
  $like = "%{$q}%";
  $params[] = $like;
  // $params[] = $like; // Removed UPC param
  $params[] = $like;
  $types .= 'ss'; // Changed from 'sss' to 'ss'
}

// Construct the final WHERE clause
$where = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

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
  $total = (int) ($rowCnt['c'] ?? 0);
  $resCnt->free();
} else {
  $stmt->bind_result($c);
  if ($stmt->fetch())
    $total = (int) $c;
}
$stmt->close();

// rows
if ($where) {
  // Removed 'upc' from SELECT
  $stmt = $conn->prepare("SELECT id, sku_num, `desc`, `status`, lot_required, DATE(created_at) AS created_at FROM sku $where ORDER BY sku_num ASC LIMIT ? OFFSET ?");
  $limit = PAGE_SIZE;
  $offset = $off;
  $params2 = $params;
  $types2 = $types . 'ii';
  $params2[] = $limit;
  $params2[] = $offset;
  $stmt->bind_param($types2, ...$params2);
} else {
  // Removed 'upc' from SELECT
  $stmt = $conn->prepare("SELECT id, sku_num, `desc`, `status`, lot_required, DATE(created_at) AS created_at FROM sku ORDER BY sku_num ASC LIMIT ? OFFSET ?");
  $limit = PAGE_SIZE;
  $offset = $off;
  $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$rows = stmt_fetch_all_assoc($stmt);
$stmt->close();

$pages = max(1, (int) ceil($total / PAGE_SIZE));

// --- Calculate dynamic search information text ---
$range_start = $total === 0 ? 0 : ($off + 1);
$range_end = $off + count($rows);
$search_info_content = "Showing {$range_start}-{$range_end} of {$total}";


// ---------- PAGE LAYOUT WIRING ----------
$title = 'Manage SKUs';
$page_class = 'page-manage-sku';
$js[] = '/js/lot_management.js';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$is_ajax_request = isset($_GET['ajax']) && $_GET['ajax'] === '1';

ob_start();
?>



<!-- SKU List -->
<div class="_card card--pad sku-panel sku-list" style="margin-top:16px;">
  <div id="suggestBox" class="suggest-list" style="display:none;"></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="min-width:120px">SKU Number</th>
          <th style="min-width:90px"></th>
          <th style="min-width:120px">LOT Required</th>
          <th style="min-width:280px">Description</th>
          <th>Status</th>
          <th style="min-width:120px">Created</th>
          <th style="min-width:160px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="7" class="text-center">No results.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <tr class="editable-row">
              <td>
                <div class="sku-wrapper" style="position: relative; display: flex; align-items: center;">
                  <span class="sku-text"><?= h($row['sku_num']) ?></span>
                  <button type="button" class="btn-lot" data-sku-id="<?= (int) $row['id'] ?>"
                    data-sku-num="<?= h($row['sku_num']) ?>"
                    data-lot-required="<?= ($row['lot_required'] ?? 0) ? '1' : '0' ?>"
                    style="<?= ($row['lot_required'] ?? 0) ? 'display:block;' : 'display:none;' ?>">
                    Lot
                  </button>
                </div>
              </td>

              <td>
                <button type="button" class="btn-barcode" data-sku-id="<?= (int) $row['id'] ?>"
                  data-sku-num="<?= h($row['sku_num']) ?>">
                  UPC
                </button>
              </td>

              <td>
                <input type="checkbox" class="lot_checkbox" name="lot_checkbox" value="1"
                  form="form-<?= (int) $row['id'] ?>" <?= ($row['lot_required'] ?? 0) ? 'checked' : '' ?>>
              </td>

              <td>
                <?= h($row['desc']) ?>
              </td>

              <td>
                <select class="select" name="status" form="form-<?= (int) $row['id'] ?>" style="width: 120px;">
                  <?php foreach ($STATUS_ALLOWED as $opt): ?>
                    <option value="<?= h($opt) ?>" <?= $row['status'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>

              <td>
                <div class="small muted"><?= h((string) ($row['created_at'] ?? '')) ?></div>
              </td>

              <td>
                <form method="POST" class="row-action-form" id="form-<?= (int) $row['id'] ?>"
                  style="display:flex; gap:8px;">
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <input type="hidden" name="sku_num" value="<?= h($row['sku_num']) ?>">
                  <input type="hidden" name="desc" value="<?= h($row['desc']) ?>">
                  <button class="btn btn--ghost update-btn" type="submit" name="update_sku">Update</button>
                  <button class="link-danger delete-btn" type="submit" name="delete_sku">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <nav style="margin-top:12px; display:flex; gap:6px; flex-wrap:wrap;">
      <?php
      // Pagination links are built to handle the current query ($q) AND filter_status
      $base_params = ['q' => $q, 'filter_status' => $filter_status];
      $base_query = http_build_query($base_params);
      $base = $_SERVER['PHP_SELF'] . '?' . $base_query . '&page=';

      $prev = max(1, $page - 1);
      $next = min($pages, $page + 1);
      $link = function ($p, $label, $disabled = false, $active = false) use ($base) {
        $cls = 'btn btn--ghost';
        if ($disabled)
          $cls .= ' disabled';
        if ($active)
          $cls .= ' btn--primary';
        $href = $disabled ? 'javascript:void(0)' : h($base . $p);
        return '<a class="' . $cls . '" href="' . $href . '">' . h($label) . '</a>';
      };
      echo $link($prev, 'Prev', $page <= 1);
      $win = 2;
      $start = max(1, $page - $win);
      $end = min($pages, $page + $win);
      if ($start > 1)
        echo $link(1, '1');
      if ($start > 2)
        echo '<span class="small" style="align-self:center;">...</span>';
      for ($i = $start; $i <= $end; $i++)
        echo $link($i, (string) $i, false, $i === $page);
      if ($end < $pages - 1)
        echo '<span class="small" style="align-self:center;">...</span>';
      if ($end < $pages)
        echo $link($pages, (string) $pages);
      echo $link($next, 'Next', $page >= $pages);
      ?>
    </nav>
  <?php endif; ?>
  <p class="small" style="margin:10px 0 0">Tip: You can edit inline and press <strong>Update</strong> per row.</p>
</div>

<?php
$list_content = ob_get_clean();



if ($is_ajax_request) {
  header('Content-Type: application/json');
  echo json_encode([
    'list_html' => $list_content,
    'info_content' => $search_info_content,
  ]);
  exit;
}

ob_start();
?>

<section class="sku-shell">
  <header class="sku-header">
    <div>
      <h2 class="title sku-title">Manage SKUs</h2>
      <span class="sku-badge">Admin-only</span>
    </div>
  </header>

  <?php if ($message): ?>
    <?php
    $cls = 'message';
    if ($message_type === 'success')
      $cls = 'message message--good';
    elseif ($message_type === 'error')
      $cls = 'message message--bad';
    elseif ($message_type === 'warning')
      $cls = 'message';
    ?>
    <div class="card card--pad sku-panel <?= h($cls) ?>" style="margin-top:12px; position:relative;">
      <?= h($message) ?>
      <?php if ($message_type === 'success'): ?>
        <button type="button" class="btn btn--ghost message-close" aria-label="Close" title="Close"
          style="position:absolute; top:6px; right:6px; padding:4px 8px; line-height:1;">&times;</button>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Search and Filter Form -->
  <div class="card card--pad sku-panel sku-toolbar" style="margin-top:12px;">
    <form method="get" class="form" id="search-form" onsubmit="return false;"
      style="display:flex; gap:8px; align-items:center;">
      <select class="select" name="filter_status" id="filter-status" style="width:160px;">
        <option value="ALL">All</option>
        <?php foreach ($STATUS_ALLOWED as $opt): ?>
          <option value="<?= h($opt) ?>" <?= $filter_status === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
        <?php endforeach; ?>
      </select>

      <input class="input" type="text" name="q" id="q" value="<?= h($q) ?>" placeholder="Search by SKU or description"
        style="flex:1; min-width:260px;">
      <button class="btn btn--primary" type="submit">Search</button>
      <?php if ($q !== '' || $filter_status !== ''): ?>
        <a class="btn btn--ghost clear-search-link" href="<?= h($_SERVER['PHP_SELF']) ?>">Clear</a>
      <?php endif; ?>
    </form>
    <div class="sku-meta small">
      <div id="search-info"><?= h($search_info_content) ?></div>
    </div>
  </div>

  <!-- Add New SKU Form -->
  <div class="card card--pad sku-panel sku-add" style="margin-top:16px;">
    <form class="form" method="POST" novalidate>
      <h3 style="margin:0 0 10px">Add New SKU</h3>
      <div class="form-row" style="display:grid;grid-template-columns:1fr 2fr 1fr auto;gap:8px;">
        <div>
          <input class="input <?= isset($inline_errors['sku_num']) ? 'is-invalid' : '' ?>" type="text" name="sku_num"
            placeholder="SKU Number" value="<?= h($add_sku_num) ?>" required>
          <?php if (!empty($inline_errors['sku_num'])): ?>
            <div class="invalid small"><?= h($inline_errors['sku_num']) ?></div>
          <?php endif; ?>
        </div>
        <div>
          <input class="input <?= isset($inline_errors['desc']) ? 'is-invalid' : '' ?>" type="text" name="desc"
            maxlength="255" placeholder="Description (max 255 chars)" value="<?= h($add_desc) ?>" required>
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
          <button class="btn btn--primary" type="submit" name="add_sku">Add SKU</button>
        </div>
      </div>
    </form>
  </div>

  <?php include __DIR__ . '/utils/lot_management.php'; ?>

  <!-- Barcode Management Modal -->
  <div id="barcode-modal" class="bc-modal" hidden>
    <div class="bc-modal__overlay" data-bc-close></div>
    <div class="bc-modal__card card card--pad">
      <div class="bc-modal__header">
        <div>
          <div class="small muted">Manage Barcodes for</div>
          <strong class="bc-sku"></strong>
        </div>
        <button type="button" class="btn btn--ghost" data-bc-close>&times;</button>
      </div>

      <form id="bc-add-form" class="bc-form" autocomplete="off">
        <div class="bc-form__row">
          <input class="input" name="upc" placeholder="UPC / barcode" required>
          <select class="select" name="uom_label" required>
            <option value="UNIT">UNIT</option>
            <option value="CARTON">CARTON</option>
            <option value="MASTERCASE">MASTERCASE</option>
          </select>
          <input class="input" name="multiplier" type="number" min="1" value="1">
          <button class="btn btn--primary" type="submit">Add / Update</button>
        </div>
      </form>

      <div class="bc-list">
        <div class="bc-rows"></div>
      </div>
    </div>
  </div>

  <!-- SKU List Container -->
  <div id="sku-list-container">
    <?= $list_content ?>
  </div>
</section>


<script>
  document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('q');
    const statusSelect = document.getElementById('filter-status');
    const listContainer = document.getElementById('sku-list-container');
    const searchInfo = document.getElementById('search-info');
    const clearLink = document.querySelector('.clear-search-link');
    const toastClose = document.querySelector('.message-close');


    if (toastClose) {
      toastClose.addEventListener('click', () => {
        const card = toastClose.closest('.card');
        if (card) card.remove();
      });
    }


    // Debounce function to limit AJAX requests while typing
    let debounceTimer;
    const debounce = (callback, time) => {
      window.clearTimeout(debounceTimer);
      window.setTimeout(callback, time);
    };

    // Helper to get current search/filter parameters
    const getCurrentParams = () => {
      return {
        q: searchInput.value,
        filter_status: statusSelect.value === 'ALL' ? '' : statusSelect.value,
      };
    };

    // Function to perform the AJAX search and update the content
    const performSearch = async (params, page = 1) => {
      const queryParams = new URLSearchParams({
        ajax: '1',
        q: params.q,
        filter_status: params.filter_status,
        page: page
      }).toString();
      const url = `<?= h($_SERVER['PHP_SELF']) ?>?${queryParams}`;

      // Visually indicate loading
      listContainer.style.opacity = '0.5';

      try {
        const response = await fetch(url);
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        // Expect JSON response
        const result = await response.json();

        // 1. Replace the entire list container content with the new HTML
        listContainer.innerHTML = result.list_html;

        // 2. Update the search info text with the content from the JSON response
        if (searchInfo) {
          searchInfo.textContent = result.info_content;
        }

        // Re-enable opacity
        listContainer.style.opacity = '1';

      } catch (error) {
        console.error('Error fetching SKU list:', error);
        listContainer.innerHTML = '<div class="message message--bad card card--pad" style="margin-top:16px;">Failed to load SKUs. Please check your connection or try refreshing the page.</div>';
        listContainer.style.opacity = '1';
      }
    };

    // Input listener for active filtering (debounce 300ms)
    searchInput.addEventListener('input', () => {
      // Always reset to page 1 on a new search query
      debounce(() => performSearch(getCurrentParams(), 1), 300);
    });

    // Status select change listener (no debounce needed)
    statusSelect.addEventListener('change', () => {
      // Always reset to page 1 when filtering by status
      performSearch(getCurrentParams(), 1);
    });

    //  Pagination listener (using event delegation since pagination links are replaced)
    listContainer.addEventListener('click', (e) => {
      const target = e.target;
      // Check if the clicked element is a pagination link
      if (target.matches('nav a.btn--ghost') && target.getAttribute('href') !== 'javascript:void(0)') {
        e.preventDefault();

        // Get the URL parameters from the link
        const href = target.getAttribute('href');
        const urlParams = new URLSearchParams(href.substring(href.indexOf('?')));

        const q = urlParams.get('q') || '';
        const filter_status = urlParams.get('filter_status') || '';
        const page = urlParams.get('page') || 1;

        // Perform search with the new page, but keep the current query/filter
        performSearch({ q, filter_status }, page);

        // Update URL in browser history without reloading
        urlParams.set('ajax', '0'); // Remove the ajax flag for clean history URL
        window.history.pushState({ q, page, filter_status }, '', '?' + urlParams.toString());
      }
    });

    // Handle button clicks, collect row data, and submit via a temporary form
    listContainer.addEventListener('click', (e) => {
      const targetEl = e.target;
      if (!(targetEl instanceof HTMLElement)) return;

      const button = targetEl.matches('button[name="update_sku"], button[name="delete_sku"]')
        ? targetEl
        : targetEl.closest('button[name="update_sku"], button[name="delete_sku"]');
      if (!button) return;

      e.preventDefault();

      const form = button.closest('form');
      const row = button.closest('tr.editable-row');
      if (!form || !row) {
        console.error('Submission failed: missing form or row');
        return;
      }

      if (button.name === 'delete_sku' && !confirm('Delete this SKU?')) {
        return;
      }

      // Collect row data (inputs/selects with names)
      const rowData = {};
      // Note: UPC input is no longer collected here as it was removed from the row.
      row.querySelectorAll('input[name], select[name]').forEach((input) => {
        if (input instanceof HTMLInputElement && input.type === 'checkbox') {
          if (input.checked) {
            rowData[input.name] = '1';
          }
          return;
        }
        rowData[input.name] = input.value;
      });

      const params = getCurrentParams();
      const urlParams = new URLSearchParams(window.location.search);
      const currentPage = urlParams.get('page') || 1;

      const submitFields = {
        ...rowData,
        id: form.querySelector('input[name="id"]')?.value || '',
        [button.name]: '1',
        q: params.q,
        filter_status: params.filter_status,
        page: currentPage,
      };

      const tempForm = document.createElement('form');
      tempForm.method = 'POST';
      tempForm.action = form.action || window.location.href;
      tempForm.style.display = 'none';

      Object.entries(submitFields).forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        tempForm.appendChild(input);
      });

      document.body.appendChild(tempForm);
      tempForm.submit();
    });

    // Clear link handler
    if (clearLink) {
      clearLink.addEventListener('click', (e) => {
        e.preventDefault();
        searchInput.value = '';
        statusSelect.value = 'ALL'; // Reset select
        performSearch({ q: '', filter_status: '' }, 1);

        // Update history to clear query and filter
        window.history.pushState({ q: '', page: 1, filter_status: '' }, '', '<?= h($_SERVER['PHP_SELF']) ?>');
      });
    }
  });
</script>

<?php
$content = ob_get_clean();

// Layout handoff
require __DIR__ . '/templates/layout.php';

