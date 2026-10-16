<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';


require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/templates/access_control.php';
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

if (!isset($_SESSION['username'])) {
  header('Location: /../login.php');
  exit;
}

$role_id = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($role_id, [1, 2, 3], true)) {
  http_response_code(403);
  echo 'Access denied.';
  exit;
}

$title = 'Supplies Import';
$page_class = 'page-supplies';
$content = '';
$css = ['/refactor_css/table-sorting.css'];
$js = [];
$footer_js = '';

$PREVIEW_ROW_CAP = 10;
$SESSION_PATH_KEY = 'supplies_import_file_path';
$SESSION_COLS_KEY = 'supplies_import_columns';
$SESSION_NAME_KEY = 'supplies_import_filename';

$TARGET_FIELDS = [
  'model_no' => ['label' => 'Model No', 'required' => true],
  'supply_category' => ['label' => 'Supply Category', 'required' => true],
  'supply_name' => ['label' => 'Supply Name', 'required' => true],
  'inside_length_in' => ['label' => 'Inside Length (in)', 'required' => false],
  'inside_width_in' => ['label' => 'Inside Width (in)', 'required' => false],
  'inside_height_in' => ['label' => 'Inside Height (in)', 'required' => false],
  'qty_per_bundle' => ['label' => 'Qty Per Bundle', 'required' => false],
  'min_stock_level' => ['label' => 'Min Stock Level', 'required' => false],
  'reference_note' => ['label' => 'Reference Note', 'required' => false],
];

// Current stock table payload (same source logic as whse_sup.php).
$stock_view = strtolower((string) ($_GET['stock_view'] ?? 'supplies'));
$stock_is_supplies = ($stock_view !== 'boxes');
$stock_is_boxes = ($stock_view === 'boxes');
$stock_where = $stock_is_supplies
  ? "s.supply_category <> 'Corrugated Boxes'"
  : "s.supply_category = 'Corrugated Boxes'";

$stock_rows = [];
$stock_count_supplies = 0;
$stock_count_boxes = 0;
$stock_model_options = [];

/**
 * @return array{
 *   rows: array<int,array<string,mixed>>,
 *   count_supplies: int,
 *   count_boxes: int,
 *   model_options: array<int,string>
 * }
 */
function load_stock_panel_data(mysqli $conn, string $stock_where): array
{
  $rows = [];
  $countSupplies = 0;
  $countBoxes = 0;
  $modelOptions = [];

  try {
    $sqlStock = "
      SELECT
        COALESCE(NULLIF(s.model_no, ''), s.supply_name) AS model_no_display,
        s.model_no AS model_no,
        s.inside_dimensions AS title_text,
        COALESCE(st.qty_on_hand, 0) AS qty_on_hand,
        COALESCE(s.min_stock_level, 0) AS min_stock_level,
        st.last_count_date AS last_count_date,
        CASE
          WHEN (COALESCE(st.qty_on_hand, 0) * COALESCE(s.qty_per_bundle, 1)) <= COALESCE(s.min_stock_level, 0) THEN 'YES'
          ELSE 'NO'
        END AS reorder_flag
      FROM warehouse_supplies s
      LEFT JOIN Warehouse_Current_Stock st ON st.model_no = s.model_no
      WHERE {$stock_where}
      ORDER BY s.supply_name
    ";
    if ($resStock = $conn->query($sqlStock)) {
      while ($r = $resStock->fetch_assoc()) {
        $rows[] = $r;
      }
      $resStock->free();
    }

    $q1 = $conn->query("
      SELECT COUNT(*) AS c
      FROM warehouse_supplies
      WHERE supply_category <> 'Corrugated Boxes'
    ");
    if ($q1 && ($row = $q1->fetch_assoc())) {
      $countSupplies = (int) ($row['c'] ?? 0);
    }
    if ($q1) {
      $q1->free();
    }

    $q2 = $conn->query("
      SELECT COUNT(*) AS c
      FROM warehouse_supplies
      WHERE supply_category = 'Corrugated Boxes'
    ");
    if ($q2 && ($row = $q2->fetch_assoc())) {
      $countBoxes = (int) ($row['c'] ?? 0);
    }
    if ($q2) {
      $q2->free();
    }

    $q3 = $conn->query("SELECT model_no FROM warehouse_supplies WHERE model_no <> '' ORDER BY model_no");
    if ($q3) {
      while ($row = $q3->fetch_assoc()) {
        $modelOptions[] = (string) ($row['model_no'] ?? '');
      }
      $q3->free();
    }
  } catch (\Throwable $_) {
    // keep import page functional if stock query fails
  }

  return [
    'rows' => $rows,
    'count_supplies' => $countSupplies,
    'count_boxes' => $countBoxes,
    'model_options' => $modelOptions,
  ];
}

/**
 * @return array{columns: array<int,string>, rows: array<int,array<int,string>>, total_rows: int, error: string}
 */
function read_file_for_preview(string $filePath, string $extension, int $cap): array
{
  $out = ['columns' => [], 'rows' => [], 'total_rows' => 0, 'error' => ''];
  try {
    if ($extension === 'csv') {
      $h = fopen($filePath, 'rb');
      if ($h === false) {
        throw new RuntimeException('Unable to open uploaded CSV.');
      }
      $out['columns'] = fgetcsv($h) ?: [];
      while (($row = fgetcsv($h)) !== false) {
        if ($row === [null] || $row === false) {
          continue;
        }
        if ($out['total_rows'] < $cap) {
          $out['rows'][] = array_map(static fn($v): string => (string) $v, $row);
        }
        $out['total_rows']++;
      }
      fclose($h);
      return $out;
    }

    if ($extension === 'xlsx') {
      $spreadsheet = IOFactory::load($filePath);
      $all = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
      if (empty($all)) {
        throw new RuntimeException('Spreadsheet is empty.');
      }
      $header = array_shift($all);
      $out['columns'] = array_map(static fn($v): string => trim((string) $v), array_values($header));
      foreach ($all as $row) {
        $vals = array_values($row);
        $vals = array_map(static fn($v): string => trim((string) $v), $vals);
        if ($vals === [] || (count(array_filter($vals, static fn($v): bool => $v !== '')) === 0)) {
          continue;
        }
        if ($out['total_rows'] < $cap) {
          $out['rows'][] = $vals;
        }
        $out['total_rows']++;
      }
      return $out;
    }

    $out['error'] = 'Unsupported file type.';
  } catch (ReaderException $e) {
    $out['error'] = 'Excel read error: ' . $e->getMessage();
  } catch (Throwable $e) {
    $out['error'] = 'File processing error: ' . $e->getMessage();
  }
  return $out;
}

/**
 * @return array<int, array<int, string>>
 */
function read_all_rows(string $filePath, string $extension): array
{
  $rows = [];
  if ($extension === 'csv') {
    $h = fopen($filePath, 'rb');
    if ($h === false) {
      throw new RuntimeException('Unable to open CSV file.');
    }
    fgetcsv($h); // skip header
    while (($row = fgetcsv($h)) !== false) {
      if ($row === [null] || $row === false) {
        continue;
      }
      $rows[] = array_map(static fn($v): string => trim((string) $v), $row);
    }
    fclose($h);
    return $rows;
  }

  if ($extension === 'xlsx') {
    $spreadsheet = IOFactory::load($filePath);
    $all = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
    array_shift($all); // skip header
    foreach ($all as $row) {
      $vals = array_values($row);
      $vals = array_map(static fn($v): string => trim((string) $v), $vals);
      if ($vals === [] || (count(array_filter($vals, static fn($v): bool => $v !== '')) === 0)) {
        continue;
      }
      $rows[] = $vals;
    }
    return $rows;
  }

  throw new RuntimeException('Unsupported file type.');
}

$error = '';
$message = '';
$previewData = ['columns' => [], 'rows' => [], 'total_rows' => 0];
$filename = '';
$targetMap = $_POST['column_map'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['update_stock_row'])) {
    $model_no = trim((string) ($_POST['model_no'] ?? ''));
    $qty_on_hand = max(0, (int) ($_POST['qty_on_hand'] ?? 0));
    $last_count_date = trim((string) ($_POST['last_count_date'] ?? ''));
    $stock_view = strtolower((string) ($_POST['stock_view'] ?? $stock_view));
    if ($last_count_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_count_date)) {
      $error = 'Last count date must be in YYYY-MM-DD format.';
    } elseif ($model_no === '') {
      $error = 'Missing model number for stock update.';
    } else {
      try {
        $has = false;
        $stmtCheck = $conn->prepare("SELECT stock_id FROM Warehouse_Current_Stock WHERE model_no = ? LIMIT 1");
        if ($stmtCheck) {
          $stmtCheck->bind_param('s', $model_no);
          $stmtCheck->execute();
          $r = $stmtCheck->get_result();
          $has = ($r && $r->num_rows > 0);
          $stmtCheck->close();
        }

        if ($has) {
          $stmtUpd = $conn->prepare("UPDATE Warehouse_Current_Stock SET qty_on_hand = ?, last_count_date = NULLIF(?, ''), updated_at = CURRENT_TIMESTAMP WHERE model_no = ?");
          if (!$stmtUpd) {
            throw new RuntimeException('Failed to prepare update.');
          }
          $stmtUpd->bind_param('iss', $qty_on_hand, $last_count_date, $model_no);
          $stmtUpd->execute();
          $stmtUpd->close();
          $message = "Stock updated for {$model_no}.";
        } else {
          $stmtIns = $conn->prepare("INSERT INTO Warehouse_Current_Stock (model_no, qty_on_hand, last_count_date) VALUES (?, ?, NULLIF(?, ''))");
          if (!$stmtIns) {
            throw new RuntimeException('Failed to prepare insert.');
          }
          $stmtIns->bind_param('sis', $model_no, $qty_on_hand, $last_count_date);
          $stmtIns->execute();
          $stmtIns->close();
          $message = "Stock line added for {$model_no}.";
        }
      } catch (\Throwable $e) {
        $error = 'Stock update failed: ' . $e->getMessage();
      }
    }
  } elseif (isset($_POST['add_stock_row'])) {
    $model_no = trim((string) ($_POST['new_model_no'] ?? ''));
    $qty_on_hand = max(0, (int) ($_POST['new_qty_on_hand'] ?? 0));
    $last_count_date = trim((string) ($_POST['new_last_count_date'] ?? ''));
    $stock_view = strtolower((string) ($_POST['stock_view'] ?? $stock_view));
    if ($last_count_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_count_date)) {
      $error = 'Last count date must be in YYYY-MM-DD format.';
    } elseif ($model_no === '') {
      $error = 'Model No is required to add a stock line.';
    } else {
      try {
        $has = false;
        $stmtCheck = $conn->prepare("SELECT stock_id FROM Warehouse_Current_Stock WHERE model_no = ? LIMIT 1");
        if ($stmtCheck) {
          $stmtCheck->bind_param('s', $model_no);
          $stmtCheck->execute();
          $r = $stmtCheck->get_result();
          $has = ($r && $r->num_rows > 0);
          $stmtCheck->close();
        }

        if ($has) {
          $stmtUpd = $conn->prepare("UPDATE Warehouse_Current_Stock SET qty_on_hand = ?, last_count_date = NULLIF(?, ''), updated_at = CURRENT_TIMESTAMP WHERE model_no = ?");
          if (!$stmtUpd) {
            throw new RuntimeException('Failed to prepare update.');
          }
          $stmtUpd->bind_param('iss', $qty_on_hand, $last_count_date, $model_no);
          $stmtUpd->execute();
          $stmtUpd->close();
          $message = "Stock line already exists. Updated {$model_no}.";
        } else {
          $stmtIns = $conn->prepare("INSERT INTO Warehouse_Current_Stock (model_no, qty_on_hand, last_count_date) VALUES (?, ?, NULLIF(?, ''))");
          if (!$stmtIns) {
            throw new RuntimeException('Failed to prepare insert.');
          }
          $stmtIns->bind_param('sis', $model_no, $qty_on_hand, $last_count_date);
          $stmtIns->execute();
          $stmtIns->close();
          $message = "New stock line added for {$model_no}.";
        }
      } catch (\Throwable $e) {
        $error = 'Add stock line failed: ' . $e->getMessage();
      }
    }
  } elseif (isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $error = 'File upload failed.';
    } else {
      $ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['csv', 'xlsx'], true)) {
        $error = 'Only CSV and XLSX are supported.';
      } else {
        $tmp = sys_get_temp_dir() . '/supplies_import_' . uniqid('', true) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], $tmp)) {
          $error = 'Failed to move uploaded file.';
        } else {
          $filename = (string) $file['name'];
          $preview = read_file_for_preview($tmp, $ext, $PREVIEW_ROW_CAP);
          if ($preview['error'] !== '') {
            $error = $preview['error'];
            @unlink($tmp);
          } else {
            $previewData = $preview;
            $_SESSION[$SESSION_PATH_KEY] = $tmp;
            $_SESSION[$SESSION_COLS_KEY] = $preview['columns'];
            $_SESSION[$SESSION_NAME_KEY] = $filename;
            $message = "File uploaded. Previewing first {$PREVIEW_ROW_CAP} rows.";
          }
        }
      }
    }
  } elseif (isset($_POST['confirm_insert'])) {
    $path = (string) ($_SESSION[$SESSION_PATH_KEY] ?? '');
    $columns = $_SESSION[$SESSION_COLS_KEY] ?? [];
    $filename = (string) ($_SESSION[$SESSION_NAME_KEY] ?? '');
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $targetMap = $_POST['column_map'] ?? [];

    foreach ($TARGET_FIELDS as $dbCol => $meta) {
      if (($meta['required'] ?? false) && empty($targetMap[$dbCol])) {
        $error = "Required field '{$meta['label']}' must be mapped.";
        break;
      }
    }

    if ($error === '' && ($path === '' || !is_file($path))) {
      $error = 'Import session expired. Please re-upload the file.';
    }

    if ($error === '') {
      try {
        $allRows = read_all_rows($path, $ext);
        $conn->begin_transaction();

        $sql = "
          INSERT INTO `warehouse_supplies` (
            model_no,
            supply_category,
            supply_name,
            inside_length_in,
            inside_width_in,
            inside_height_in,
            qty_per_bundle,
            min_stock_level,
            reference_note
          )
          VALUES (
            ?,
            ?,
            ?,
            NULLIF(?, ''),
            NULLIF(?, ''),
            NULLIF(?, ''),
            COALESCE(NULLIF(?, ''), 1),
            COALESCE(NULLIF(?, ''), 0),
            NULLIF(?, '')
          )
          ON DUPLICATE KEY UPDATE
            supply_category = VALUES(supply_category),
            supply_name = VALUES(supply_name),
            inside_length_in = VALUES(inside_length_in),
            inside_width_in = VALUES(inside_width_in),
            inside_height_in = VALUES(inside_height_in),
            qty_per_bundle = VALUES(qty_per_bundle),
            min_stock_level = VALUES(min_stock_level),
            reference_note = VALUES(reference_note),
            updated_at = CURRENT_TIMESTAMP
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
          throw new RuntimeException('Failed to prepare import statement: ' . $conn->error);
        }

        $imported = 0;
        foreach ($allRows as $row) {
          $get = static function (string $dbCol) use ($targetMap, $columns, $row): string {
            $src = trim((string) ($targetMap[$dbCol] ?? ''));
            if ($src === '') {
              return '';
            }
            $idx = array_search($src, $columns, true);
            if ($idx === false) {
              return '';
            }
            return trim((string) ($row[$idx] ?? ''));
          };

          $model_no = $get('model_no');
          $supply_category = $get('supply_category');
          $supply_name = $get('supply_name');
          $inside_length_in = $get('inside_length_in');
          $inside_width_in = $get('inside_width_in');
          $inside_height_in = $get('inside_height_in');
          $qty_per_bundle = $get('qty_per_bundle');
          $min_stock_level = $get('min_stock_level');
          $reference_note = $get('reference_note');

          if ($model_no === '' || $supply_category === '' || $supply_name === '') {
            continue;
          }

          $stmt->bind_param(
            'sssssssss',
            $model_no,
            $supply_category,
            $supply_name,
            $inside_length_in,
            $inside_width_in,
            $inside_height_in,
            $qty_per_bundle,
            $min_stock_level,
            $reference_note
          );

          if ($stmt->execute()) {
            $imported++;
          }
        }

        $stmt->close();
        $conn->commit();

        @unlink($path);
        unset($_SESSION[$SESSION_PATH_KEY], $_SESSION[$SESSION_COLS_KEY], $_SESSION[$SESSION_NAME_KEY]);
        $message = "Import complete for {$filename}. Rows processed: {$imported}.";
      } catch (Throwable $e) {
        $conn->rollback();
        $error = 'Import failed: ' . $e->getMessage();
      }
    }

    if (!empty($_SESSION[$SESSION_PATH_KEY]) && is_file((string) $_SESSION[$SESSION_PATH_KEY])) {
      $preview = read_file_for_preview((string) $_SESSION[$SESSION_PATH_KEY], $ext, $PREVIEW_ROW_CAP);
      if ($preview['error'] === '') {
        $previewData = $preview;
      }
    }
  }
}

if (empty($previewData['columns']) && !empty($_SESSION[$SESSION_COLS_KEY]) && !empty($_SESSION[$SESSION_PATH_KEY])) {
  $path = (string) $_SESSION[$SESSION_PATH_KEY];
  if (is_file($path)) {
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $preview = read_file_for_preview($path, $ext, $PREVIEW_ROW_CAP);
    if ($preview['error'] === '') {
      $previewData = $preview;
      $filename = (string) ($_SESSION[$SESSION_NAME_KEY] ?? '');
    }
  }
}

$stockPanelData = load_stock_panel_data($conn, $stock_where);
$stock_rows = $stockPanelData['rows'];
$stock_count_supplies = $stockPanelData['count_supplies'];
$stock_count_boxes = $stockPanelData['count_boxes'];
$stock_model_options = $stockPanelData['model_options'];

ob_start();
?>
<h2>Supplies Import</h2>
<p class="muted">Import data into <code>warehouse_supplies</code> using model-based upsert.</p>

<?php if ($error !== ''): ?>
  <div class="toast is-error" style="margin-bottom:1rem;"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($message !== ''): ?>
  <div class="toast is-success" style="margin-bottom:1rem;"><?= h($message) ?></div>
<?php endif; ?>

<section class="card">
  <h3>Step 1: Upload File</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="import_file" accept=".csv,.xlsx" required />
    <button type="submit" class="btn btn-sm btn-primary">Upload & Preview</button>
  </form>
</section>

<?php if (!empty($previewData['rows'])): ?>
  <section class="card" style="margin-top:1rem;">
    <h3>Step 2: Map Columns and Import</h3>
    <p class="muted">File: <?= h($filename !== '' ? $filename : (string) ($_SESSION[$SESSION_NAME_KEY] ?? 'uploaded file')) ?> | Rows detected: <?= (int) ($previewData['total_rows'] ?? 0) ?></p>

    <form method="post">
      <input type="hidden" name="confirm_insert" value="1" />
      <div class="table-wrap">
        <table class="sortable">
          <thead>
            <tr>
              <th>Database Field</th>
              <th>Required</th>
              <th>Map To File Column</th>
              <th>Sample Value</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $first = $previewData['rows'][0] ?? [];
            foreach ($TARGET_FIELDS as $dbCol => $meta):
              $selected = (string) ($targetMap[$dbCol] ?? '');
              if ($selected === '') {
                foreach ($previewData['columns'] as $c) {
                  if (strcasecmp((string) $c, $dbCol) === 0) {
                    $selected = (string) $c;
                    break;
                  }
                }
              }
              $sample = '';
              if ($selected !== '') {
                $idx = array_search($selected, $previewData['columns'], true);
                if ($idx !== false) {
                  $sample = (string) ($first[$idx] ?? '');
                }
              }
              ?>
              <tr>
                <td><?= h((string) $meta['label']) ?></td>
                <td><?= !empty($meta['required']) ? 'Yes' : 'No' ?></td>
                <td>
                  <select name="column_map[<?= h($dbCol) ?>]" class="input" <?= !empty($meta['required']) ? 'required' : '' ?>>
                    <option value="">-- DO NOT IMPORT --</option>
                    <?php foreach ($previewData['columns'] as $col): ?>
                      <option value="<?= h((string) $col) ?>" <?= $selected === (string) $col ? 'selected' : '' ?>><?= h((string) $col) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><?= h($sample !== '' ? $sample : '(empty)') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:1rem;">
        <button type="submit" class="btn btn-sm btn-success">Confirm Import</button>
      </div>
    </form>
  </section>
<?php endif; ?>

<section class="card" style="margin-top:1rem;">
  <h3>Warehouse Current Stock</h3>
  <div class="whse-switcher">
    <a class="btn btn-sm <?= $stock_is_supplies ? 'btn-primary' : '' ?>" href="?stock_view=supplies">
      Supplies (<?= (int) $stock_count_supplies ?>)
    </a>
    <a class="btn btn-sm <?= $stock_is_boxes ? 'btn-primary' : '' ?>" href="?stock_view=boxes">
      Boxes (<?= (int) $stock_count_boxes ?>)
    </a>
  </div>
  <div class="table-wrap">
    <table class="sortable">
      <thead>
        <tr>
          <th>Model No</th>
          <th>Title</th>
          <th>Current Stock</th>
          <th>Min Stock</th>
          <th>Last Count</th>
          <th>Reorder?</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($stock_rows)): ?>
          <tr>
            <td colspan="7" class="text-center">No items found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($stock_rows as $idx => $r): ?>
            <?php $isReorder = strtoupper((string) ($r['reorder_flag'] ?? 'NO')) === 'YES'; ?>
            <?php $formId = 'stockUpdateForm' . (int) $idx; ?>
            <tr<?= $isReorder ? ' class="is-danger"' : '' ?>>
              <td><?= h((string) ($r['model_no_display'] ?? '')) ?></td>
              <td><?= h((string) (($r['title_text'] ?? '') !== '' ? $r['title_text'] : '-')) ?></td>
              <td>
                <input
                  form="<?= h($formId) ?>"
                  type="number"
                  name="qty_on_hand"
                  min="0"
                  step="1"
                  value="<?= (int) ($r['qty_on_hand'] ?? 0) ?>"
                  class="input"
                  style="max-width:110px;"
                />
              </td>
              <td><?= (int) ($r['min_stock_level'] ?? 0) ?></td>
              <td>
                <input
                  form="<?= h($formId) ?>"
                  type="date"
                  name="last_count_date"
                  value="<?= h((string) ($r['last_count_date'] ?? '')) ?>"
                  class="input"
                />
              </td>
              <td>
                <span class="badge <?= $isReorder ? 'is-danger' : 'is-success' ?>">
                  <?= $isReorder ? 'YES' : 'NO' ?>
                </span>
              </td>
              <td>
                <form id="<?= h($formId) ?>" method="post" style="margin:0;">
                  <input type="hidden" name="update_stock_row" value="1" />
                  <input type="hidden" name="stock_view" value="<?= h($stock_view) ?>" />
                  <input type="hidden" name="model_no" value="<?= h((string) ($r['model_no'] ?? '')) ?>" />
                  <button type="submit" class="btn btn-sm btn-primary">Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div style="margin-top:1rem;">
    <h4 style="margin:0 0 .5rem;">Add New Stock Line</h4>
    <form method="post" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:end;">
      <input type="hidden" name="add_stock_row" value="1" />
      <input type="hidden" name="stock_view" value="<?= h($stock_view) ?>" />
      <div>
        <label class="muted" for="new_model_no">Model No</label><br />
        <input list="stockModelList" id="new_model_no" name="new_model_no" class="input" required />
        <datalist id="stockModelList">
          <?php foreach ($stock_model_options as $opt): ?>
            <option value="<?= h($opt) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label class="muted" for="new_qty_on_hand">Qty On Hand</label><br />
        <input type="number" id="new_qty_on_hand" name="new_qty_on_hand" min="0" step="1" value="0" class="input" />
      </div>
      <div>
        <label class="muted" for="new_last_count_date">Last Count</label><br />
        <input type="date" id="new_last_count_date" name="new_last_count_date" class="input" />
      </div>
      <div>
        <button type="submit" class="btn btn-sm btn-success">Add Line</button>
      </div>
    </form>
  </div>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';
