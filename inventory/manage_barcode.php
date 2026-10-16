<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';


error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', './manageBarcodes.log');

if (!isset($_SESSION['username'], $_SESSION['user_id'])) {
  header('Location: ../login.php');
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$user_id = (int) $_SESSION['user_id'];

// CSP nonce for inline scripts
$cspNonceAttr = bin2hex(random_bytes(16));

/* Handle suggest API for SKU search */
if (isset($_GET['suggest']) && $_GET['suggest'] === '1') {
  header('Content-Type: application/json; charset=utf-8');
  $type = (string) ($_GET['type'] ?? 'sku');
  $q = trim((string) ($_GET['q'] ?? ''));
  $out = [];

  try {
    if ($type === 'sku') {
      $sql = "SELECT sku_num, `desc` FROM sku WHERE status='ACTIVE'
              AND (sku_num LIKE CONCAT('%', ?, '%') OR `desc` LIKE CONCAT('%', ?, '%'))
              ORDER BY sku_num ASC LIMIT 30";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param('ss', $q, $q);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        $out[] = ['sku_num' => (string) $r['sku_num'], 'desc' => (string) ($r['desc'] ?? '')];
      }
      $stmt->close();
    }
  } catch (Throwable $e) {
    http_response_code(500);
  }

  echo json_encode(['ok' => true, 'type' => $type, 'items' => $out], JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

const BARCODE_UOMS = ['UNIT', 'CARTON', 'MASTERCASE'];
const BARCODE_RETIRED_UOMS = ['PALLET']; // still in the DB enum; kept on existing rows, not offered for new ones
const BARCODE_SOURCES = ['SUPPLIER' => 'Supplier', 'OWN_LABEL' => 'Own label', 'REPACK' => 'Repack'];

if (empty($_SESSION['manage_barcode_csrf'])) {
  $_SESSION['manage_barcode_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = (string) $_SESSION['manage_barcode_csrf'];

function manage_barcode_redirect(string $type, string $message): never
{
  $_SESSION['manage_barcode_flash'] = ['type' => $type, 'message' => $message];

  $query = [];
  foreach (['q', 'status', 'source', 'enabled'] as $key) {
    $value = trim((string) ($_POST['return_' . $key] ?? ''));
    if ($value !== '') {
      $query[$key] = $value;
    }
  }

  // Reopen the SKU that was just saved.
  $openSku = filter_var($_POST['submit_sku_id'] ?? $_POST['delete_sku_id'] ?? null, FILTER_VALIDATE_INT);
  if ($openSku) {
    $query['open'] = $openSku;
  }

  $location = 'manage_barcode.php';
  if ($query !== []) {
    $location .= '?' . http_build_query($query);
  }

  header('Location: ' . $location);
  exit;
}

function manage_barcode_validate_csrf(): void
{
  $posted = (string) ($_POST['csrf_token'] ?? '');
  $stored = (string) ($_SESSION['manage_barcode_csrf'] ?? '');
  if ($stored === '' || !hash_equals($stored, $posted)) {
    http_response_code(419);
    exit('The form expired. Reload the page and try again.');
  }
}

/**
 * Validates one posted barcode row. Returns [values, error].
 * $keepUom lets an existing row keep a retired UOM (e.g. PALLET) it already has.
 */
function manage_barcode_read_row(array $fields, string $label, ?string $keepUom = null): array
{
  $uom = (string) ($fields['uom_label'] ?? '');
  $source = trim((string) ($fields['source'] ?? ''));
  $multRaw = trim((string) ($fields['multiplier'] ?? ''));
  $upc = trim((string) ($fields['upc'] ?? ''));
  $noBarcode = isset($fields['no_barcode']) && $fields['no_barcode'] === '1';

  if (!in_array($uom, BARCODE_UOMS, true) && $uom !== $keepUom) {
    return [null, "{$label}: invalid UOM."];
  }
  if ($source !== '' && !isset(BARCODE_SOURCES[$source])) {
    return [null, "{$label}: invalid source."];
  }
  if (!preg_match('/^\d+$/', $multRaw) || (int) $multRaw < 1) {
    $got = $multRaw === '' ? 'blank' : "'{$multRaw}'";
    return [null, "{$label}: units per scan must be 1 or more (got {$got})."];
  }

  // "No barcode" rows never carry a UPC.
  if ($noBarcode) {
    $upc = null;
  } elseif ($upc === '' || strlen($upc) > 100) {
    return [null, "{$label}: UPC is required (max 100 characters)."];
  }

  return [
    [
      'uom_label' => $uom,
      'no_barcode' => $noBarcode ? 1 : 0,
      'upc' => $upc,
      'source' => $source === '' ? null : $source,
      'multiplier' => (int) $multRaw,
    ],
    null
  ];
}

/*
|--------------------------------------------------------------------------
| POST handling
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string) ($_POST['action'] ?? '');

  // CSRF validation for most actions
  if ($action === 'toggle_enabled') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $storedToken = (string) ($_SESSION['manage_barcode_csrf'] ?? '');
    if ($storedToken === '' || !hash_equals($storedToken, $postedToken)) {
      header('Content-Type: application/json; charset=utf-8');
      http_response_code(419);
      echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed']);
      exit;
    }
  } else {
    manage_barcode_validate_csrf();
  }

  /*
   * Delete one barcode row: delete_id = product_uom_barcodes.id
   */
  if (isset($_POST['delete_id'])) {
    $deleteId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT);
    if (!$deleteId || $deleteId < 1) {
      manage_barcode_redirect('error', 'Invalid barcode.');
    }

    try {
      // Remember the SKU so its group reopens after the redirect.
      $stmt = $conn->prepare('SELECT sku_id FROM product_uom_barcodes WHERE id = ?');
      $stmt->bind_param('i', $deleteId);
      $stmt->execute();
      $_POST['delete_sku_id'] = (string) ($stmt->get_result()->fetch_assoc()['sku_id'] ?? '');
      $stmt->close();

      $stmt = $conn->prepare('DELETE FROM product_uom_barcodes WHERE id = ?');
      $stmt->bind_param('i', $deleteId);
      $stmt->execute();
      $deleted = $stmt->affected_rows > 0;
      $stmt->close();
    } catch (Throwable $e) {
      error_log('[manageBarcodes delete] ' . $e->getMessage());
      manage_barcode_redirect('error', 'The barcode could not be deleted.');
    }

    manage_barcode_redirect($deleted ? 'success' : 'warning', $deleted ? 'Barcode deleted.' : 'Barcode was already removed.');
  }

  if ($action === 'toggle_enabled') {
    $toggleId = filter_var($_POST['toggle_enabled_id'] ?? null, FILTER_VALIDATE_INT);
    $enabledValue = isset($_POST['enabled_value']) ? (int) $_POST['enabled_value'] : 0;

    header('Content-Type: application/json; charset=utf-8');

    if (!$toggleId || $toggleId < 1) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'Invalid barcode ID.']);
      exit;
    }

    try {
      $stmt = $conn->prepare('UPDATE product_uom_barcodes SET enabled = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?');
      $stmt->bind_param('iii', $enabledValue, $user_id, $toggleId);
      $stmt->execute();
      $updated = $stmt->affected_rows > 0;
      $stmt->close();

      echo json_encode([
        'ok' => true,
        'updated' => $updated,
        'enabled' => $enabledValue,
      ]);
      exit;
    } catch (Throwable $e) {
      error_log('[manageBarcodes toggle_enabled] ' . $e->getMessage());
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'Could not update enabled state: ' . $e->getMessage()]);
      exit;
    }
  }

  if ($action === 'save_barcodes') {
    $posted = $_POST['barcodes'] ?? [];

    if (!is_array($posted)) {
      manage_barcode_redirect('error', 'Invalid barcode data.');
    }

    // Each SKU group has its own save button; only process that SKU.
    $submitSkuId = filter_var($_POST['submit_sku_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$submitSkuId || $submitSkuId < 1) {
      manage_barcode_redirect('error', 'Invalid product.');
    }
    $postedRows = is_array($posted[$submitSkuId] ?? null) ? $posted[$submitSkuId] : [];
    $newRow = $_POST['new'][$submitSkuId] ?? null;
    $newRow = is_array($newRow) ? $newRow : null;

    /*
     * barcodes[sku_id][barcode_id|new_UOM] = [uom_label, no_barcode, upc, source, multiplier]
     *
     * Only rows whose values actually changed are written, so
     * updated_at / updated_by reflect real edits.
     */
    try {
      $conn->begin_transaction();

      $current = [];
      $stmt = $conn->prepare(
        'SELECT id, uom_label, no_barcode, upc, source, multiplier, enabled
                 FROM product_uom_barcodes
                 WHERE sku_id = ?
                 FOR UPDATE'
      );
      $stmt->bind_param('i', $submitSkuId);
      $stmt->execute();
      $result = $stmt->get_result();
      while ($row = $result->fetch_assoc()) {
        $current[$row['uom_label']] = $row;
      }
      $stmt->close();

      $errors = [];
      $updates = [];
      $inserts = [];

      foreach ($postedRows as $barcodeKeyRaw => $fields) {
        if (!is_array($fields)) {
          continue;
        }

        // Keys are a barcode id (existing row) or "new_UOM" (empty placeholder slot).
        $isNew = strpos((string) $barcodeKeyRaw, 'new_') === 0;
        $old = null;

        if ($isNew) {
          $uom = substr((string) $barcodeKeyRaw, 4);
          if (!in_array($uom, BARCODE_UOMS, true) || isset($current[$uom])) {
            continue;
          }
          // Untouched placeholder: skip before validating, or its blank UPC fails the whole save.
          $postedUpc = trim((string) ($fields['upc'] ?? ''));
          if ($postedUpc === '' && ($fields['no_barcode'] ?? '') !== '1') {
            continue;
          }
        } else {
          // Disabled rows post only their hidden "enabled" flag; skip them before any validation.
          if ((string) ($fields['enabled'] ?? '1') === '0') {
            continue;
          }
          $barcodeId = filter_var($barcodeKeyRaw, FILTER_VALIDATE_INT);
          foreach ($current as $data) {
            if ((int) $data['id'] === $barcodeId) {
              $old = $data;
              break;
            }
          }
          // Disabled rows aren't editable, and their disabled inputs aren't posted
          // (only the hidden uom_label arrives), so validating them always fails.
          if ($old === null || (int) $old['enabled'] === 0) {
            continue;
          }
          $uom = $old['uom_label'];
        }

        [$new, $error] = manage_barcode_read_row($fields, "{$uom} barcode", $isNew ? null : $uom);
        if ($error !== null) {
          $errors[] = $error;
          continue;
        }

        if ($isNew) {
          $inserts[$uom] = $new;
          continue;
        }

        $changed = $new['no_barcode'] !== (int) $old['no_barcode']
          || $new['upc'] !== $old['upc']
          || $new['source'] !== $old['source']
          || $new['multiplier'] !== (int) $old['multiplier'];
        if ($changed) {
          $updates[$barcodeId] = $new;
        }
      }

      if ($errors !== []) {
        $conn->rollback();
        manage_barcode_redirect('error', implode(' ', $errors));
      }

      if ($updates === [] && $inserts === []) {
        $conn->rollback();
        // The Enabled checkbox saves itself instantly (toggle_enabled), so a
        // Save right after toggling finds the DB already up to date.
        manage_barcode_redirect('success', 'All changes are saved.');
      }

      $updateStmt = $conn->prepare(
        'UPDATE product_uom_barcodes
                 SET no_barcode = ?, upc = ?, source = ?, multiplier = ?,
                     updated_at = CURRENT_TIMESTAMP, updated_by = ?
                 WHERE id = ? AND sku_id = ?'
      );

      foreach ($updates as $barcodeId => $new) {
        $updateStmt->bind_param(
          'issiiii',
          $new['no_barcode'],
          $new['upc'],
          $new['source'],
          $new['multiplier'],
          $user_id,
          $barcodeId,
          $submitSkuId
        );
        $updateStmt->execute();
      }
      $updateStmt->close();

      $insertStmt = $conn->prepare(
        'INSERT INTO product_uom_barcodes
                  (sku_id, uom_label, no_barcode, upc, source, multiplier, created_at, created_by)
               VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)'
      );

      foreach ($inserts as $uom => $new) {
        $insertStmt->bind_param(
          'isissii',
          $submitSkuId,
          $new['uom_label'],
          $new['no_barcode'],
          $new['upc'],
          $new['source'],
          $new['multiplier'],
          $user_id
        );
        $insertStmt->execute();
      }
      $insertStmt->close();

      $conn->commit();

      $parts = [];
      if ($inserts !== []) {
        $parts[] = sprintf('%d barcode%s added', count($inserts), count($inserts) === 1 ? '' : 's');
      }
      if ($updates !== []) {
        $parts[] = sprintf('%d barcode%s updated', count($updates), count($updates) === 1 ? '' : 's');
      }
      manage_barcode_redirect('success', implode('; ', $parts) . '.');

    } catch (mysqli_sql_exception $e) {
      try {
        $conn->rollback();
      } catch (Throwable) {
      }

      if ($e->getCode() === 1062) {
        // Determine if it's a duplicate UOM or duplicate UPC
        $message = $e->getMessage();
        if (strpos($message, 'unique_sku_uom') !== false) {
          manage_barcode_redirect('error', 'Not saved: one of those UOMs was already saved for this product.');
        } else {
          manage_barcode_redirect('error', 'Not saved: one of those UPCs is already assigned to another product.');
        }
      }

      error_log('[manageBarcodes save_barcodes] ' . $e->getMessage());
      manage_barcode_redirect('error', 'The barcodes could not be saved.');
    }
  }

  manage_barcode_redirect('error', 'Unknown action.');
}

/*
|--------------------------------------------------------------------------
| Read / search
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['manage_barcode_flash'] ?? null;
unset($_SESSION['manage_barcode_flash']);

$search = trim((string) ($_GET['q'] ?? ''));
$sourceFilter = (string) ($_GET['source'] ?? '');
$enabledFilter = (string) ($_GET['enabled'] ?? '');
$openSkuId = (int) ($_GET['open'] ?? 0);

if ($sourceFilter !== 'UNSET' && !isset(BARCODE_SOURCES[$sourceFilter])) {
  $sourceFilter = '';
}

if ($enabledFilter !== '' && $enabledFilter !== '0' && $enabledFilter !== '1') {
  $enabledFilter = '';
}

// Row-level filters (applied to the barcode rows shown under each SKU).
$rowWhere = [];
$rowParams = [];
$rowTypes = '';
if ($sourceFilter === 'UNSET') {
  $rowWhere[] = 'b.source IS NULL';
} elseif ($sourceFilter !== '') {
  $rowWhere[] = 'b.source = ?';
  $rowParams[] = $sourceFilter;
  $rowTypes .= 's';
}
if ($enabledFilter !== '') {
  $rowWhere[] = 'b.enabled = ?';
  $rowParams[] = (int) $enabledFilter;
  $rowTypes .= 'i';
}
$rowWhereSql = $rowWhere ? ' AND ' . implode(' AND ', $rowWhere) : '';

// SKUs that have at least one barcode row matching the filters.
// A plain search also lists SKUs with no barcodes yet, so one can be added.
$barcodeJoin = ($search !== '' && $rowWhere === []) ? 'LEFT JOIN' : 'JOIN';
$where = [];
$params = [];
$types = '';

if ($search !== '') {
  $where[] = "(s.sku_num LIKE CONCAT('%', ?, '%')
                 OR s.`desc` LIKE CONCAT('%', ?, '%')
                 OR EXISTS (SELECT 1 FROM product_uom_barcodes bs WHERE bs.sku_id = s.id AND bs.upc LIKE CONCAT('%', ?, '%')))";
  array_push($params, $search, $search, $search);
  $types .= 'sss';
}

$sql = "
    SELECT
        s.id,
        s.sku_num,
        s.`desc`,
        COUNT(b.id) AS barcode_count,
        COALESCE(SUM(b.no_barcode = 1), 0) AS no_barcode_count,
        COALESCE(SUM(b.id IS NOT NULL AND b.source IS NULL), 0) AS missing_source_count,
        GROUP_CONCAT(DISTINCT b.uom_label ORDER BY FIELD(b.uom_label, 'UNIT', 'CARTON', 'MASTERCASE', 'PALLET') SEPARATOR ', ') AS uoms,
        MAX(COALESCE(b.updated_at, b.created_at)) AS last_change_at
    FROM sku s
    {$barcodeJoin} product_uom_barcodes b ON b.sku_id = s.id {$rowWhereSql}
";
if ($where) {
  $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= "
    GROUP BY s.id
    ORDER BY s.sku_num ASC
    LIMIT 300
";

$stmt = $conn->prepare($sql);
$allParams = [...$rowParams, ...$params];
if ($allParams) {
  $stmt->bind_param($rowTypes . $types, ...$allParams);
}
$stmt->execute();
$skus = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


$barcodesBySku = [];
$skuIds = array_map(static fn(array $row): int => (int) $row['id'], $skus);

if ($skuIds !== []) {
  $placeholders = implode(',', array_fill(0, count($skuIds), '?'));

  $barcodeSql = "
        SELECT
            b.id,
            b.sku_id,
            b.upc,
            b.no_barcode,
            b.source,
            b.uom_label,
            b.multiplier,
            b.enabled,
            b.created_at,
            b.created_by,
            b.updated_at,
            b.updated_by
        FROM product_uom_barcodes b
        WHERE b.sku_id IN ({$placeholders})
        ORDER BY FIELD(b.uom_label, 'UNIT', 'CARTON', 'MASTERCASE', 'PALLET'), b.upc
    ";

  $barcodeStmt = $conn->prepare($barcodeSql);
  $barcodeStmt->bind_param(str_repeat('i', count($skuIds)), ...$skuIds);
  $barcodeStmt->execute();
  $barcodeResult = $barcodeStmt->get_result();
  while ($barcodeRow = $barcodeResult->fetch_assoc()) {
    $barcodesBySku[(int) $barcodeRow['sku_id']][] = $barcodeRow;
  }
  $barcodeStmt->close();

  // Ensure each SKU has entries for all standard UOMs (UNIT, CARTON, MASTERCASE)
  foreach ($skuIds as $skuId) {
    $existingUoms = array_column($barcodesBySku[$skuId] ?? [], 'uom_label');
    foreach (BARCODE_UOMS as $uom) {
      if (!in_array($uom, $existingUoms, true)) {
        $barcodesBySku[$skuId][] = [
          'id' => null,
          'sku_id' => $skuId,
          'upc' => null,
          'no_barcode' => 0,
          'source' => null,
          'uom_label' => $uom,
          'multiplier' => 1,
          'enabled' => 1,
          'created_at' => null,
          'created_by' => null,
          'updated_at' => null,
          'updated_by' => null,
        ];
      }
    }
    // Sort by UOM order
    usort($barcodesBySku[$skuId], function($a, $b) {
      $order = ['UNIT' => 0, 'CARTON' => 1, 'MASTERCASE' => 2];
      return ($order[$a['uom_label']] ?? 999) <=> ($order[$b['uom_label']] ?? 999);
    });
  }
}

$formatAudit = static function (?string $at, $by): string {
  if (!$at) {
    return '';
  }
  return date('d/m/Y H:i:s', strtotime($at));
};

$hasFilters = $search !== '' || $sourceFilter !== '' || $enabledFilter !== '';

$title = 'Manage Barcodes';
$page_class = 'page-manage-barcodes';
$is_ajax_request = isset($_GET['ajax']) && $_GET['ajax'] === '1';

ob_start();
?>

<h2 class="title">Manage Barcodes</h2>

<?php if (is_array($flash)): ?>
  <div class="count-flash count-flash--<?= h((string) ($flash['type'] ?? 'success')) ?>">
    <?= h((string) ($flash['message'] ?? '')) ?>
  </div>
<?php endif; ?>

<section class="card card--pad" style="margin-bottom:16px;">
  <form method="get" action="manage_barcode.php">
    <div class="sku-search-wrapper">

      <div class="sku-search-top">
        <label class="label" for="q">SKU Search</label>
        <input class="input" type="text" id="q" name="q" value="<?= h($search) ?>"
          placeholder="Search SKU or description" autocomplete="off" data-suggest="inventory"
          data-suggestBox="suggestBox" data-suggestSubmit="false">

        <div id="suggestBox" class="suggest-list" style="display:none;">
        </div>
      </div>

      <div class="sku-search-bottom">

        <div class="sku-search-source">
          <label class="label" for="source">Source</label>
          <select class="select" id="source" name="source">
            <option value="">All</option>
            <?php foreach (BARCODE_SOURCES as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $sourceFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
            <option value="UNSET" <?= $sourceFilter === 'UNSET' ? 'selected' : '' ?>>Not set</option>
          </select>
        </div>

        <div class="sku-search-source">
          <label class="label" for="enabled">Status</label>
          <select class="select" id="enabled" name="enabled">
            <option value="">All</option>
            <option value="1" <?= $enabledFilter === '1' ? 'selected' : '' ?>>Enabled</option>
            <option value="0" <?= $enabledFilter === '0' ? 'selected' : '' ?>>Disabled</option>
          </select>
        </div>

      </div>

      <div class="sku-search-actions">
        <button class="btn btn--primary" type="submit">Search</button>
        <a class="btn btn-outline" href="manage_barcode.php">Clear</a>
      </div>

    </div>

  </form>
</section>

<div class="count-toolbar">
  <span class="text-muted">
    <?= number_format(count($skus)) ?> product<?= count($skus) === 1 ? '' : 's' ?>
    <?= count($skus) === 300 ? '(first 300 — narrow your search)' : '' ?>
  </span>
</div>

<?php if (!$skus): ?>
  <p class="text-muted"><?= $hasFilters ? 'No products match your search.' : 'No barcodes on file.' ?></p>
<?php else: ?>
  <form method="post" id="barcode-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
    <input type="hidden" name="return_q" value="<?= h($search) ?>">
    <input type="hidden" name="return_source" value="<?= h($sourceFilter) ?>">
    <input type="hidden" name="return_enabled" value="<?= h($enabledFilter) ?>">
    <input type="hidden" name="action" value="save_barcodes">

    <?php foreach ($skus as $sku): ?>
      <?php
      $skuId = (int) $sku['id'];
      $barcodes = $barcodesBySku[$skuId] ?? [];
      $barcodeCount = (int) $sku['barcode_count'];
      $noBarcodeCount = (int) $sku['no_barcode_count'];
      $missingSourceCount = (int) $sku['missing_source_count'];
      $isOpen = $skuId === $openSkuId || count($skus) === 1;

      $groupId = 'barcode-rows-' . $skuId;
      ?>
      <div class="count-sku-group">
        <div class="count-sku-header" role="button" tabindex="0" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
          aria-controls="<?= h($groupId) ?>" onclick="toggleBarcodeGroup('<?= h($groupId) ?>', this)"
          onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleBarcodeGroup('<?= h($groupId) ?>', this);}">
          <div class="count-sku-header__main">
            <span class="toggle-icon"><?= $isOpen ? '−' : '+' ?></span>
            <div>
              <div class="count-sku-header__title">
                <?= h((string) $sku['sku_num']) ?>
              </div>
              <div class="count-sku-header__desc"><?= h((string) $sku['desc']) ?></div>
            </div>
          </div>
          <div class="text-muted" style="text-align:right;">
            <?php if ($barcodeCount === 0): ?>
              <span class="bc-pill bc-pill--warn">No barcodes on file</span>
            <?php else: ?>
              <?= $barcodeCount ?> barcode<?= $barcodeCount === 1 ? '' : 's' ?>
              &middot; <?= h((string) $sku['uoms']) ?>
            <?php endif; ?>
            <?php if ($noBarcodeCount > 0 || $missingSourceCount > 0): ?>
              <br>
              <?php if ($noBarcodeCount > 0): ?>
                <span class="bc-pill bc-pill--none"><?= $noBarcodeCount ?> no barcode</span>
              <?php endif; ?>
              <?php if ($missingSourceCount > 0): ?>
                <span class="bc-pill bc-pill--warn"><?= $missingSourceCount ?> source not set</span>
              <?php endif; ?>
            <?php elseif ($sku['last_change_at']): ?>
              <br>Last change: <?= h(date('M j, Y g:i A', strtotime((string) $sku['last_change_at']))) ?>
            <?php endif; ?>
          </div>
        </div>

        <div id="<?= h($groupId) ?>" class="count-locations <?= $isOpen ? 'is-open' : '' ?>">
          <div class="count-location-row count-location-header bc-row" aria-hidden="true">
            <div style="min-width: 70px;">UOM</div>
            <div>UPC</div>
            <div>Source</div>
            <div>Units / scan</div>
            <div style="text-align: center; width: 80px;">No Barcode</div>
            <div>Created</div>
            <div>Updated</div>
            <div>Actions</div>
          </div>
          <?php foreach ($barcodes as $barcode): ?>
            <?php
            $barcodeId = (int) $barcode['id'];
            $noBarcode = (int) $barcode['no_barcode'] === 1;
            $enabled = (int) ($barcode['enabled'] ?? 1) === 1;
            $fieldName = static fn(string $field): string => $barcodeId
              ? "barcodes[{$skuId}][{$barcodeId}][{$field}]"
              : "barcodes[{$skuId}][new_{$barcode['uom_label']}][{$field}]";
            ?>
            <div class="count-location-row bc-row <?= $noBarcode ? 'bc-row--none' : '' ?><?= !$enabled ? ' bc-row--disabled' : '' ?><?= $barcodeId ? '' : ' bc-row--new' ?>" style="<?= !$enabled ? 'opacity: 0.6; background-color: #f5f5f5;' : '' ?>">
              <div style="min-width: 70px; font-weight: 500;">
                <?= h($barcode['uom_label']) ?>
                <input type="hidden" name="<?= h($fieldName('uom_label')) ?>" value="<?= h($barcode['uom_label']) ?>">
                <?php if ($barcodeId): ?>
                  <input type="hidden" class="bc-enabled-flag" name="<?= h($fieldName('enabled')) ?>" value="<?= $enabled ? '1' : '0' ?>">
                <?php endif; ?>
              </div>
              <div>
                <input class="input bc-input bc-upc" name="<?= h($fieldName('upc')) ?>" maxlength="100" inputmode="numeric"
                  aria-label="UPC" value="<?= h((string) ($barcode['upc'] ?? '')) ?>"
                  placeholder="<?= $noBarcode ? 'None' : 'UPC' ?>" <?= ($noBarcode || !$enabled) ? 'disabled' : ($barcodeId ? 'required' : '') ?>>
              </div>
              <div>
                <select class="select" name="<?= h($fieldName('source')) ?>" aria-label="Source" <?= !$enabled ? 'disabled' : '' ?>>
                  <option value="">Not set</option>
                  <?php foreach (BARCODE_SOURCES as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $barcode['source'] === $value ? 'selected' : '' ?>><?= h($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <input class="input bc-input" type="number" min="1" step="1" inputmode="numeric"
                  name="<?= h($fieldName('multiplier')) ?>" aria-label="Units per scan"
                  value="<?= (int) $barcode['multiplier'] ?>" <?= !$enabled ? 'disabled' : 'required' ?>>
              </div>
              <div style="text-align: center; width: 80px;">
                <input type="checkbox" class="bc-no-barcode" name="<?= h($fieldName('no_barcode')) ?>" value="1"
                  aria-label="No barcode required" <?= $noBarcode ? 'checked' : '' ?> <?= !$enabled ? 'disabled' : '' ?>>
              </div>
              <div class="text-muted small">
                <?= $barcodeId ? h($formatAudit($barcode['created_at'], $barcode['created_by'])) ?: '&mdash;' : '&mdash;' ?>
              </div>
              <div class="text-muted small">
                <?php if ($barcodeId && !empty($barcode['updated_at'])): ?>
                  <?= h($formatAudit($barcode['updated_at'], $barcode['updated_by'])) ?>
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </div>
              <div style="display:flex; gap:6px;">
                <?php if ($barcodeId): ?>
                  <?php $enabled = (int) ($barcode['enabled'] ?? 1) === 1; ?>
                  <button class="btn btn--sm bc-toggle-enabled" type="button" data-barcode-id="<?= $barcodeId ?>" data-enabled="<?= $enabled ? '1' : '0' ?>"
                    title="<?= $enabled ? 'Disable' : 'Enable' ?> this barcode" aria-label="<?= $enabled ? 'Disable' : 'Enable' ?> this barcode">
                    <?= $enabled ? 'I' : 'O' ?>
                  </button>
                <?php endif; ?>
                <button class="btn btn--sm" type="button" onclick="openDimensionModal(<?= $barcodeId ?>, <?= $skuId ?>, '<?= h($barcode['uom_label']) ?>')"
                  title="Weight & dimensions" aria-label="Weight and dimensions">
                  ⚖️
                </button>
                <?php /* if ($barcodeId): ?>
                  <button class="bc-delete" type="submit" name="delete_id" value="<?= $barcodeId ?>" formnovalidate
                    aria-label="Delete barcode" title="Delete barcode"
                    data-confirm="Delete <?= h($barcode['upc'] ?: $barcode['uom_label'] . ' no-barcode record') ?>?">
                    &times;
                  </button>
                <?php endif; */ ?>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="count-location-row count-location-totals bc-row">
            <div style="display: flex;">
              <button
                class="btn btn--primary count-submit"
                type="submit"
                name="submit_sku_id"
                value="<?= $skuId ?>" aria-label="Save <?= h((string) $sku['sku_num']) ?> barcodes"
                title="Save barcodes">
                Save
              </button>
            </div>
          </div>
        </div>
      </div>
      </div>
    <?php endforeach; ?>
  </form>
<?php endif; ?>

<script nonce="<?= $cspNonceAttr ?>">

  function toggleBarcodeGroup(groupId, trigger) {
    const group = document.getElementById(groupId);
    if (!group) return;

    const opening = !group.classList.contains('is-open');
    group.classList.toggle('is-open', opening);

    const icon = trigger ? trigger.querySelector('.toggle-icon') : null;
    if (icon) {
      icon.textContent = opening ? '−' : '+';
    }
    if (trigger) {
      trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
    }
  }

  // Fields are disabled during submit; reload if the page comes back from the back/forward cache.
  window.addEventListener('pageshow', (event) => { if (event.persisted) window.location.reload(); });

  const barcodeForm = document.getElementById('barcode-form');
  if (barcodeForm) {
    // Only validate/submit the SKU group being saved; other groups' fields are disabled for this submit.
    barcodeForm.addEventListener('submit', function (event) {
      const submitter = event.submitter;
      if (!submitter) return;

      if (submitter.dataset.confirm && !confirm(submitter.dataset.confirm)) {
        event.preventDefault();
        return;
      }

      const activeGroup = submitter.closest('.count-sku-group');
      if (submitter.name === 'submit_sku_id') {
        const invalid = Array.from(activeGroup.querySelectorAll('input, select')).find((field) => !field.checkValidity());
        if (invalid) {
          event.preventDefault();
          invalid.reportValidity();
          return;
        }
      }
      barcodeForm.querySelectorAll('.count-sku-group').forEach((group) => {
        if (group === activeGroup && submitter.name === 'submit_sku_id') return;
        group.querySelectorAll('input, select').forEach((field) => { field.disabled = true; });
      });
    });

    // "No barcode" checkbox disables the UPC field on that row.
    barcodeForm.addEventListener('change', function (event) {
      if (!event.target.matches('.bc-no-barcode')) return;
      const upc = event.target.closest('.bc-row').querySelector('.bc-upc');
      if (!upc) return;
      const none = event.target.checked;
      const isNew = event.target.closest('.bc-row--new') !== null;
      upc.disabled = none;
      upc.required = !none && !isNew;
      upc.placeholder = none ? 'None' : (isNew ? '+ New UPC' : 'UPC');
      if (none) upc.value = '';
    });

    // Enable/Disable button handler
    barcodeForm.addEventListener('click', function (event) {
      if (!event.target.matches('.bc-toggle-enabled')) return;
      event.preventDefault();

      const button = event.target;
      const barcodeId = button.dataset.barcodeId;
      const currentEnabled = button.dataset.enabled === '1';
      const newEnabled = currentEnabled ? 0 : 1;

      const action = currentEnabled ? 'Disable' : 'Enable';
      const uomLabel = button.closest('.bc-row').querySelector('div[style*="min-width"]')?.textContent?.trim() || 'barcode';

      if (!confirm(`${action} ${uomLabel}?`)) {
        return;
      }

      const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

      const formData = new FormData();
      formData.append('action', 'toggle_enabled');
      formData.append('toggle_enabled_id', barcodeId);
      formData.append('enabled_value', newEnabled);
      formData.append('csrf_token', csrfToken);

      fetch(window.location.pathname, {
        method: 'POST',
        body: formData,
      })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          button.dataset.enabled = data.enabled;
          const enabledFlag = button.closest('.bc-row')?.querySelector('.bc-enabled-flag');
          if (enabledFlag) enabledFlag.value = data.enabled === 1 ? '1' : '0';
          button.textContent = data.enabled === 1 ? 'I' : 'O';
          button.title = data.enabled === 1 ? 'Disable this barcode' : 'Enable this barcode';
          button.setAttribute('aria-label', data.enabled === 1 ? 'Disable this barcode' : 'Enable this barcode');

          const row = button.closest('.bc-row');
          if (row) {
            if (data.enabled === 1) {
              row.classList.remove('bc-row--disabled');
              row.style.opacity = '';
              row.style.backgroundColor = '';
              // Enable all fields except those disabled by no_barcode
              const inputs = row.querySelectorAll('input:not(.bc-toggle-enabled):not(.bc-enabled-flag), select');
              inputs.forEach((field) => {
                const upc = row.querySelector('.bc-upc');
                if (field === upc) {
                  field.disabled = row.querySelector('.bc-no-barcode').checked;
                } else {
                  field.disabled = false;
                }
              });
            } else {
              row.classList.add('bc-row--disabled');
              row.style.opacity = '0.6';
              row.style.backgroundColor = '#f5f5f5';
              // Disable all fields
              const inputs = row.querySelectorAll('input:not(.bc-toggle-enabled):not(.bc-enabled-flag), select');
              inputs.forEach((field) => {
                field.disabled = true;
              });
            }
          }
        }
      })
      .catch(err => {
        console.error('Error saving enabled state:', err);
      });
    });

    // Enter in a field saves that SKU group.
    barcodeForm.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' || event.isComposing || !event.target.matches('.bc-input')) {
        return;
      }
      event.preventDefault();
      if (event.repeat) return;
      const submitButton = event.target.closest('.count-sku-group').querySelector('.count-submit');
      barcodeForm.requestSubmit(submitButton);
    });
  }

  // AJAX search on filter/search input change
  const searchForm = document.querySelector('form[action="manage_barcode.php"][method="get"]');

  if (searchForm) {
    const searchInput = searchForm.querySelector('input[name="q"]');
    const sourceSelect = searchForm.querySelector('select[name="source"]');
    const enabledSelect = searchForm.querySelector('select[name="enabled"]');

    let debounceTimer;
    const debounce = (callback, delay) => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(callback, delay);
    };

    const getCurrentParams = () => {
      return {
        q: searchInput?.value || '',
        source: sourceSelect?.value || '',
        enabled: enabledSelect?.value || '',
      };
    };

    const performSearch = async (params) => {
      const queryParams = new URLSearchParams({
        ajax: '1',
        q: params.q,
        source: params.source,
        enabled: params.enabled,
      }).toString();
      const url = `${window.location.pathname}?${queryParams}`;

      try {
        const response = await fetch(url);
        if (!response.ok) throw new Error('Network response was not ok');
        const result = await response.json();

        // Create temporary container to parse new HTML
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = result.results_html;

        // Replace toolbar
        const toolbar = document.querySelector('.count-toolbar');
        const newToolbar = tempDiv.querySelector('.count-toolbar');
        if (toolbar && newToolbar) toolbar.replaceWith(newToolbar);

        // Replace results
        const barcodeForm = document.getElementById('barcode-form');
        const noResults = tempDiv.querySelector('.text-muted');
        const newForm = tempDiv.querySelector('#barcode-form');

        if (barcodeForm) {
          if (newForm) {
            barcodeForm.replaceWith(newForm);
            attachBarcodeFormListeners(newForm);
          } else if (noResults) {
            barcodeForm.replaceWith(noResults.cloneNode(true));
          }
        }
      } catch (error) {
        console.error('Search error:', error);
      }
    };

    if (searchInput) {
      searchInput.addEventListener('input', () => {
        debounce(() => performSearch(getCurrentParams()), 300);
      });
    }

    if (sourceSelect) {
      sourceSelect.addEventListener('change', () => {
        performSearch(getCurrentParams());
      });
    }

    if (enabledSelect) {
      enabledSelect.addEventListener('change', () => {
        performSearch(getCurrentParams());
      });
    }
  }

  // Helper to attach listeners to barcode form
  function attachBarcodeFormListeners(form) {
    if (!form) return;

    form.addEventListener('change', function (event) {
      if (!event.target.matches('.bc-no-barcode')) return;
      const upc = event.target.closest('.bc-row').querySelector('.bc-upc');
      if (!upc) return;
      const none = event.target.checked;
      const isNew = event.target.closest('.bc-row--new') !== null;
      upc.disabled = none;
      upc.required = !none && !isNew;
      upc.placeholder = none ? 'None' : (isNew ? '+ New UPC' : 'UPC');
      if (none) upc.value = '';
    });

    // Enable/Disable button handler for AJAX-loaded forms
    form.addEventListener('click', function (event) {
      if (!event.target.matches('.bc-toggle-enabled')) return;
      event.preventDefault();

      const button = event.target;
      const barcodeId = button.dataset.barcodeId;
      const currentEnabled = button.dataset.enabled === '1';
      const newEnabled = currentEnabled ? 0 : 1;

      const action = currentEnabled ? 'Disable' : 'Enable';
      const uomLabel = button.closest('.bc-row').querySelector('div[style*="min-width"]')?.textContent?.trim() || 'barcode';

      if (!confirm(`${action} ${uomLabel}?`)) {
        return;
      }

      const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

      const formData = new FormData();
      formData.append('action', 'toggle_enabled');
      formData.append('toggle_enabled_id', barcodeId);
      formData.append('enabled_value', newEnabled);
      formData.append('csrf_token', csrfToken);

      fetch(window.location.pathname, {
        method: 'POST',
        body: formData,
      })
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          button.dataset.enabled = data.enabled;
          const enabledFlag = button.closest('.bc-row')?.querySelector('.bc-enabled-flag');
          if (enabledFlag) enabledFlag.value = data.enabled === 1 ? '1' : '0';
          button.textContent = data.enabled === 1 ? 'I' : 'O';
          button.title = data.enabled === 1 ? 'Disable this barcode' : 'Enable this barcode';
          button.setAttribute('aria-label', data.enabled === 1 ? 'Disable this barcode' : 'Enable this barcode');

          const row = button.closest('.bc-row');
          if (row) {
            if (data.enabled === 1) {
              row.classList.remove('bc-row--disabled');
              row.style.opacity = '';
              row.style.backgroundColor = '';
              // Enable all fields except those disabled by no_barcode
              const inputs = row.querySelectorAll('input:not(.bc-toggle-enabled):not(.bc-enabled-flag), select');
              inputs.forEach((field) => {
                const upc = row.querySelector('.bc-upc');
                if (field === upc) {
                  field.disabled = row.querySelector('.bc-no-barcode').checked;
                } else {
                  field.disabled = false;
                }
              });
            } else {
              row.classList.add('bc-row--disabled');
              row.style.opacity = '0.6';
              row.style.backgroundColor = '#f5f5f5';
              // Disable all fields
              const inputs = row.querySelectorAll('input:not(.bc-toggle-enabled):not(.bc-enabled-flag), select');
              inputs.forEach((field) => {
                field.disabled = true;
              });
            }
          }
        }
      })
      .catch(err => {
        console.error('Error saving enabled state:', err);
      });
    });

    form.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' || event.isComposing || !event.target.matches('.bc-input')) {
        return;
      }
      event.preventDefault();
      if (event.repeat) return;
      const submitButton = event.target.closest('.count-sku-group').querySelector('.count-submit');
      form.requestSubmit(submitButton);
    });
  }

</script>
<script src="js/dimension-launcher.js" nonce="<?= $cspNonceAttr ?>"></script>

<!-- Dimension Modal -->
<div id="dimensionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
  <div style="background: white; border: 2px solid #333; padding: 24px; border-radius: 4px; width: 90%; max-width: 500px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 12px;">
      <div>
        <h2 style="margin: 0; font-size: 18px; font-weight: 600;">Weight & Dimensions</h2>
        <p style="margin: 4px 0 0 0; font-size: 12px; color: #666;" id="dimModalSku"></p>
      </div>
      <button type="button" onclick="closeDimensionModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; padding: 0; width: 28px; height: 28px;">×</button>
    </div>

    <form id="dimensionForm" style="display: flex; flex-direction: column; gap: 16px;">
      <input type="hidden" id="dimBarcodeId">
      <input type="hidden" id="dimSkuId">
      <input type="hidden" id="dimUomLabel">

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
        <div>
          <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Width (cm)</label>
          <input type="number" id="dimWidth" step="0.01" placeholder="0" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; font-family: monospace;">
        </div>
        <div>
          <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Height (cm)</label>
          <input type="number" id="dimHeight" step="0.01" placeholder="0" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; font-family: monospace;">
        </div>
        <div>
          <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Length (cm)</label>
          <input type="number" id="dimLength" step="0.01" placeholder="0" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; font-family: monospace;">
        </div>
        <div>
          <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Weight (kg)</label>
          <input type="number" id="dimWeight" step="0.01" placeholder="0" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; font-family: monospace;">
        </div>
      </div>

      <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px;">
        <button type="button" onclick="closeDimensionModal()" style="padding: 8px 16px; border: 1px solid #ccc; background: #f5f5f5; border-radius: 3px; cursor: pointer; font-size: 14px;">Cancel</button>
        <button type="submit" style="padding: 8px 16px; border: none; background: #0f766e; color: white; border-radius: 3px; cursor: pointer; font-size: 14px; font-weight: 600;">Save</button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= $cspNonceAttr ?>"></script>

<?php
$page_html = ob_get_clean();

// If AJAX request, return only results section as JSON
if ($is_ajax_request) {
  // Extract results section from page_html (everything after the search form)
  // We'll re-render the results section cleanly
  ob_start();
  ?>
  <div class="count-toolbar">
    <span class="text-muted">
      <?= number_format(count($skus)) ?> product<?= count($skus) === 1 ? '' : 's' ?>
      <?= count($skus) === 300 ? '(first 300 — narrow your search)' : '' ?>
    </span>
  </div>

  <?php if (!$skus): ?>
    <p class="text-muted"><?= $hasFilters ? 'No products match your search.' : 'No barcodes on file.' ?></p>
  <?php else: ?>
    <form method="post" id="barcode-form" novalidate>
      <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
      <input type="hidden" name="return_q" value="<?= h($search) ?>">
      <input type="hidden" name="return_source" value="<?= h($sourceFilter) ?>">
      <input type="hidden" name="return_enabled" value="<?= h($enabledFilter) ?>">
      <input type="hidden" name="action" value="save_barcodes">

      <?php foreach ($skus as $sku): ?>
        <?php
        $skuId = (int) $sku['id'];
        $barcodes = $barcodesBySku[$skuId] ?? [];
        $barcodeCount = (int) $sku['barcode_count'];
        $noBarcodeCount = (int) $sku['no_barcode_count'];
        $missingSourceCount = (int) $sku['missing_source_count'];
        $isOpen = $skuId === $openSkuId || count($skus) === 1;

        $groupId = 'barcode-rows-' . $skuId;
        ?>
        <div class="count-sku-group">
          <div class="count-sku-header" role="button" tabindex="0" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
            aria-controls="<?= h($groupId) ?>" onclick="toggleBarcodeGroup('<?= h($groupId) ?>', this)"
            onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleBarcodeGroup('<?= h($groupId) ?>', this);}">
            <div class="count-sku-header__main">
              <span class="toggle-icon"><?= $isOpen ? '−' : '+' ?></span>
              <div>
                <div class="count-sku-header__title">
                  <?= h((string) $sku['sku_num']) ?>
                </div>
                <div class="count-sku-header__desc"><?= h((string) $sku['desc']) ?></div>
              </div>
            </div>
            <div class="text-muted" style="text-align:right;">
              <?php if ($barcodeCount === 0): ?>
                <span class="bc-pill bc-pill--warn">No barcodes on file</span>
              <?php else: ?>
                <?= $barcodeCount ?> barcode<?= $barcodeCount === 1 ? '' : 's' ?>
                &middot; <?= h((string) $sku['uoms']) ?>
              <?php endif; ?>
              <?php if ($noBarcodeCount > 0 || $missingSourceCount > 0): ?>
                <br>
                <?php if ($noBarcodeCount > 0): ?>
                  <span class="bc-pill bc-pill--none"><?= $noBarcodeCount ?> no barcode</span>
                <?php endif; ?>
                <?php if ($missingSourceCount > 0): ?>
                  <span class="bc-pill bc-pill--warn"><?= $missingSourceCount ?> source not set</span>
                <?php endif; ?>
              <?php elseif ($sku['last_change_at']): ?>
                <br>Last change: <?= h(date('M j, Y g:i A', strtotime((string) $sku['last_change_at']))) ?>
              <?php endif; ?>
            </div>
          </div>

          <div id="<?= h($groupId) ?>" class="count-locations <?= $isOpen ? 'is-open' : '' ?>">
            <div class="count-location-row count-location-header bc-row" aria-hidden="true">
              <div style="min-width: 70px;">UOM</div>
              <div>UPC</div>
              <div>Source</div>
              <div>Units / scan</div>
              <div style="text-align: center; width: 80px;">No Barcode</div>
              <div style="text-align: center; width: 60px;">Enabled</div>
              <div>Created</div>
              <div>Updated</div>
              <div>Actions</div>
            </div>
            <?php foreach ($barcodes as $barcode): ?>
              <?php
              $barcodeId = (int) $barcode['id'];
              $noBarcode = (int) $barcode['no_barcode'] === 1;
              $enabled = (int) ($barcode['enabled'] ?? 1) === 1;
              $fieldName = static fn(string $field): string => $barcodeId
                ? "barcodes[{$skuId}][{$barcodeId}][{$field}]"
                : "barcodes[{$skuId}][new_{$barcode['uom_label']}][{$field}]";
              ?>
              <div class="count-location-row bc-row <?= $noBarcode ? 'bc-row--none' : '' ?> <?= !$enabled ? 'bc-row--disabled' : '' ?><?= $barcodeId ? '' : ' bc-row--new' ?>" style="<?= !$enabled ? 'opacity: 0.6; background-color: #f5f5f5;' : '' ?>">
                <div style="min-width: 70px; font-weight: 500;">
                  <?= h($barcode['uom_label']) ?>
                  <input type="hidden" name="<?= h($fieldName('uom_label')) ?>" value="<?= h($barcode['uom_label']) ?>">
                <?php if ($barcodeId): ?>
                  <input type="hidden" class="bc-enabled-flag" name="<?= h($fieldName('enabled')) ?>" value="<?= $enabled ? '1' : '0' ?>">
                <?php endif; ?>
                </div>
                <div>
                  <input class="input bc-input bc-upc" name="<?= h($fieldName('upc')) ?>" maxlength="100" inputmode="numeric"
                    aria-label="UPC" value="<?= h((string) ($barcode['upc'] ?? '')) ?>"
                    placeholder="<?= $noBarcode ? 'None' : 'UPC' ?>" <?= ($noBarcode || !$enabled) ? 'disabled' : ($barcodeId ? 'required' : '') ?>>
                </div>
                <div>
                  <select class="select" name="<?= h($fieldName('source')) ?>" aria-label="Source" <?= !$enabled ? 'disabled' : '' ?>>
                    <option value="">Not set</option>
                    <?php foreach (BARCODE_SOURCES as $value => $label): ?>
                      <option value="<?= h($value) ?>" <?= $barcode['source'] === $value ? 'selected' : '' ?>><?= h($label) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <input class="input bc-input" type="number" min="1" step="1" inputmode="numeric"
                    name="<?= h($fieldName('multiplier')) ?>" aria-label="Units per scan"
                    value="<?= (int) $barcode['multiplier'] ?>" <?= !$enabled ? 'disabled' : 'required' ?>>
                </div>
                <div style="text-align: center; width: 80px;">
                  <input type="checkbox" class="bc-no-barcode" name="<?= h($fieldName('no_barcode')) ?>" value="1"
                    aria-label="No barcode required" <?= $noBarcode ? 'checked' : '' ?> <?= !$enabled ? 'disabled' : '' ?>>
                </div>
                <div class="text-muted small">
                  <?= $barcodeId ? h($formatAudit($barcode['created_at'], $barcode['created_by'])) ?: '&mdash;' : '&mdash;' ?>
                </div>
                <div class="text-muted small">
                  <?php if ($barcodeId && !empty($barcode['updated_at'])): ?>
                    <?= h($formatAudit($barcode['updated_at'], $barcode['updated_by'])) ?>
                  <?php else: ?>
                    &mdash;
                  <?php endif; ?>
                </div>
                <div style="display:flex; gap:6px;">
                  <?php if ($barcodeId): ?>
                    <button class="btn btn--sm bc-toggle-enabled" type="button" data-barcode-id="<?= $barcodeId ?>" data-enabled="<?= $enabled ? '1' : '0' ?>"
                      title="<?= $enabled ? 'Disable' : 'Enable' ?> this barcode" aria-label="<?= $enabled ? 'Disable' : 'Enable' ?> this barcode">
                      <?= $enabled ? 'I' : 'O' ?>
                    </button>
                    <button class="bc-delete" type="submit" name="delete_id" value="<?= $barcodeId ?>" formnovalidate
                      aria-label="Delete barcode" title="Delete barcode"
                      data-confirm="Delete <?= h($barcode['upc'] ?: $barcode['uom_label'] . ' no-barcode record') ?>?">&times;</button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
            <div class="count-location-row count-location-totals bc-row">
              <div style="display: flex; gap: 6px;">
                <button class="btn btn--primary count-submit" type="submit" name="submit_sku_id"
                  value="<?= $skuId ?>" aria-label="Save <?= h((string) $sku['sku_num']) ?> barcodes"
                  title="Save barcodes">Save</button>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </form>
  <?php endif; ?>
  <?php
  $results_html = ob_get_clean();

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'results_html' => $results_html,
    'search' => $search,
    'source' => $sourceFilter,
  ]);
  exit;
}

// Don't leak render-loop variables ($barcode, $barcodes, ...) into the layout;
// utils/barcode_modal.php uses $barcode as a string.
unset($barcode, $barcodes);

$content = $page_html;

include __DIR__ . '/templates/layout.php';