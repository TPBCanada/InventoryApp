<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/security.php';

require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/templates/access_control.php';
require_once __DIR__ . '/utils/db_importers.php';
require __DIR__ . '/vendor/autoload.php';

// --- INITIAL AUTH CHECK ---
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
$user_id = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'User';
$role_id = (int)($_SESSION['role_id'] ?? 0);

if (!ac_can_access_page($role_id, 'import.php', $page_permissions_by_role)) {
    http_response_code(403);
    echo 'Access Denied. You will be redirected to the home screen.';
    echo '
        <script>
            setTimeout(function() {
                window.location.href = "dashboard.php";
            }, 2000); 
        </script>
    ';
    exit;
}

// Restrict to admins/managers/analysts
$can_use_ai = in_array($role_id, [1,2,3], true);
if (!$can_use_ai) { http_response_code(403); echo 'Access denied.'; exit; }


// --- Configuration and Setup ---

// Check for the OpenAI Key
$OPENAI_KEY = (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '')
    ? OPENAI_API_KEY
    : (getenv('OPENAI_API_KEY') ?: '');
$AI_ENABLED = $OPENAI_KEY !== '';

// Require the necessary PhpSpreadsheet classes
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

// Max rows to show in preview
$PREVIEW_ROW_CAP = 10;


// Define the required database fields for the current import target
$IMPORT_TARGETS = [
    'sku' => [
        'name' => 'SKU Details (Products)',
        'required_fields' => [
            'sku_num' => ['label' => 'SKU Number (sku_num)', 'required' => true],
            'desc'    => ['label' => 'Description (desc)', 'required' => true],
            'upc'     => ['label' => 'UPC Code (upc)', 'required' => false],
        ],
        'import_function' => 'import_sku_details_from_file', 
    ],
    'location' => [
        'name' => 'Location Details (Bins/Areas)',
        'required_fields' => [
            'loc_code' => ['label' => 'Location Code (loc_code)', 'required' => true],
            'warehouse' => ['label' => 'Warehouse (warehouse)', 'required' => true],
            'loc_type' => ['label' => 'Location Type (loc_type)', 'required' => false],
        ],
        'import_function' => 'import_location_details_from_file', 
    ],
    'inventory' => [
        'name' => 'Inventory Counts',
        'required_fields' => [
            'sku_num' => ['label' => 'SKU Number (sku_num)', 'required' => true],
            'quantity' => ['label' => 'Quantity (qty)', 'required' => true],
            'loc_code' => ['label' => 'Location Code (loc_code)', 'required' => false],
        ],
        'import_function' => 'import_inventory_counts_from_file', 
    ]
];

// Initialize variables
$target_key = $_POST['import_target'] ?? 'sku'; // Default to SKU
$current_target = $IMPORT_TARGETS[$target_key] ?? $IMPORT_TARGETS['sku'];
$DB_TARGET_FIELDS = $current_target['required_fields']; // Dynamic field list
$import_func = $current_target['import_function']; // Used in the confirmation step


/**
 * Reads a file (CSV or XLSX) and returns the header and a sample of rows.
 * This is the only file processing function remaining here, as it uses 
 * PhpSpreadsheet specific to this page.
 * @return array{columns: array<int,string>, rows: array<int,array<int,string>>, error: string, total_rows: int}
 */
function read_file_for_preview(string $filePath, string $extension, int $cap): array
{
    // ... (Your existing read_file_for_preview function logic remains here) ...
    $results = ['columns' => [], 'rows' => [], 'error' => '', 'total_rows' => 0];

    try {
        if ($extension === 'csv') {
            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open the file.');
            }
            
            $results['columns'] = fgetcsv($handle) ?: [];
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === false) continue;
                
                if ($results['total_rows'] < $cap) {
                    $results['rows'][] = $row;
                }
                $results['total_rows']++;
            }
            fclose($handle);

        } elseif ($extension === 'xlsx') {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray(null, true, true, true);
            
            if (empty($data)) {
                throw new RuntimeException('The spreadsheet is empty.');
            }
            
            // First row is the header
            $headerRow = array_shift($data);
            $results['columns'] = array_map('strval', array_filter($headerRow));
            
            $results['total_rows'] = count($data);
            
            // Get sample rows, convert to string array
            $previewData = array_slice($data, 0, $cap);
            $results['rows'] = array_map(function($row) {
                return array_map('strval', $row);
            }, $previewData);

        } else {
            $results['error'] = 'Unsupported file type.';
        }
    } catch (ReaderException $e) {
        $results['error'] = 'Error reading Excel file: ' . $e->getMessage();
    } catch (Throwable $e) {
        $results['error'] = 'File processing error: ' . $e->getMessage();
    }

    return $results;
}


// --- Main Page Logic ---

$title = 'Data Import';
$error = '';
$message = '';
$previewData = [];
$filename = '';
$tempPath = '';
$aiSuggestion = '';
$imported_rows = 0; // Initialize for use in the POST block

// 1. Handle GET parameter redirect message
if (isset($_GET['msg'])) {
    $message = urldecode($_GET['msg']);
} 


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['import_file'])) {
        $file = $_FILES['import_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'File upload failed with error code: ' . $file['error'];
        } elseif ($file['size'] === 0) {
            $error = 'The uploaded file is empty.';
        } else {
            $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

            if (!in_array($extension, ['csv', 'xlsx'])) {
                $error = 'Only CSV and XLSX files are supported.';
            } else {
                // Determine the correct temp file extension
                $tempPath = sys_get_temp_dir() . '/' . basename((string) $file['tmp_name']) . '.' . $extension;

                if (move_uploaded_file((string) $file['tmp_name'], $tempPath)) {
                    $filename = h((string) $file['name']);
                    $previewResult = read_file_for_preview($tempPath, $extension, $PREVIEW_ROW_CAP);
                    
                    if ($previewResult['error'] === '') {
                        $previewData['columns'] = $previewResult['columns'];
                        $previewData['rows'] = $previewResult['rows'];
                        $totalRows = $previewResult['total_rows'];

                        // Store for confirmation step and AI analysis
                        $_SESSION['import_file_path'] = $tempPath;
                        $_SESSION['import_columns'] = $previewData['columns'];
                        $_SESSION['import_filename'] = $filename;

                        // Set simple success message for upload - NO BANNER/TOAST HERE
                        $message = "File **{$filename}** ({$extension}) uploaded successfully. Preview below. Total rows detected: **" . number_format($totalRows) . "**.";
                        
                        // AI Feature: Call the centralized AiService class
                        if ($AI_ENABLED && !empty($previewData['columns'])) {
                            try {
                                $aiService = new AiService($OPENAI_KEY);
                                $aiSuggestion = $aiService->getFormatSuggestion($conn, $previewData['columns']);
                            } catch (InvalidArgumentException $e) {
                                $aiSuggestion = 'AI Initialization Error: ' . $e->getMessage();
                            }
                        }

                    } else {
                        $error = $previewResult['error'];
                        if (is_file($tempPath)) {
                            unlink($tempPath); // Clean up temp file on read error
                        }
                    }
                } else {
                    $error = 'Failed to move the uploaded file to temp storage.';
                }
            }
        }
    } elseif (isset($_POST['confirm_insert'])) {
        $path = $_SESSION['import_file_path'] ?? '';
        $filename = $_SESSION['import_filename'] ?? 'uploaded file';
        $columns = $_SESSION['import_columns'] ?? []; 
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        $target_key = $_POST['import_target'] ?? 'sku';
        $current_target = $IMPORT_TARGETS[$target_key] ?? $IMPORT_TARGETS['sku'];
        $import_func = $current_target['import_function'];
        $target_table = $current_target['name']; // Use dynamic name
        
        
        // --- NEW: Retrieve the user-defined column map ---
        $userColumnMap = $_POST['column_map'] ?? [];
        
        // --- NEW: Basic validation for required fields using the $DB_TARGET_FIELDS array ---
        $requiredMapValid = true;
        foreach ($DB_TARGET_FIELDS as $db_col => $info) {
            if ($info['required'] && (empty($userColumnMap[$db_col]) || $userColumnMap[$db_col] === '')) {
                $error = "Required database field **{$info['label']}** must be mapped to a file column.";
                $requiredMapValid = false;
                break;
            }
        }
        
        if (!$requiredMapValid) {
            // Stop execution, the form will be re-displayed with the error.
        } elseif ($path === '' || !is_file($path)) {
            $error = 'Import session expired or file not found. Please re-upload.';
        } else {
            try {
                $conn->begin_transaction(); 

                if (!function_exists($import_func)) {
                    throw new RuntimeException("Database import function '{$import_func}' is not defined in dbinv.php.");
                }
                $imported_rows = $import_func(
                    $conn, 
                    $path, 
                    $user_id, 
                    $columns, 
                    $extension,
                    $userColumnMap 
                );
                
                $conn->commit();

                // 2. CONFIGURE TOAST MESSAGE FOR REDIRECT (This is the required banner)
                $formatted_rows = number_format($imported_rows);
                $badge_html = '<span class="badge-ok">' . $formatted_rows . '</span>';
                $target_name_escaped = h($target_table);
                $filename_escaped = h($filename);
                
                $toast_html = '<div class="toast is-success" style="align-items: center; justify-content: space-between; max-width: 800px; margin: 20px 0;">' .
                           '<div>Import Complete for **' . $filename_escaped . '** into **' . $target_name_escaped . '**</div>' .
                           'Rows Imported:  ' . $badge_html .
                           '</div>';
                           
                // Redirect using the HTML structure (MUST be URL encoded)
                header('Location: import.php?msg=' . urlencode($toast_html)); 
                exit;

            } catch (Throwable $e) {
                // ... (Error handling remains the same) ...
                if (isset($conn)) {
                    $conn->rollback();
                }
                $error = 'Import failed due to an error: ' . h($e->getMessage());
            }
        }
    }
}

// Ensure clean up if session is present and file is gone (e.g., manual deletion)
if (!empty($_SESSION['import_file_path']) && !is_file($_SESSION['import_file_path'])) {
    unset($_SESSION['import_file_path'], $_SESSION['import_columns'], $_SESSION['import_filename']);
}


// Check if preview data exists and extract the first row
$firstRowData = $previewData['rows'][0] ?? [];

if (isset($_POST['confirm_insert']) && empty($previewData['rows'])) {
    if (!empty($_SESSION['import_columns'])) {
        if (isset($_POST['confirm_insert'])) {
            $target_key = $_POST['import_target'] ?? 'sku';
            $current_target = $IMPORT_TARGETS[$target_key] ?? $IMPORT_TARGETS['sku'];
            $DB_TARGET_FIELDS = $current_target['required_fields'];
            
            // Re-populate preview data from session/POST if mapping fails validation
            if (isset($_SESSION['import_columns'])) {
                $previewData['columns'] = $_SESSION['import_columns'];
            }
        }
    }
}

// Extract the first row, only if preview data exists.
$firstRowData = $previewData['rows'][0] ?? [];

ob_start();
?>

<h2 class="title">Data Import Wizard</h2>

<?php if ($error !== ''): ?>
    <p class="message message--bad" style="margin-bottom: 20px;"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($message !== ''): ?>
    <?php
    // 3. CHECK MESSAGE TYPE FOR OUTPUT
    // If the message is the raw HTML toast (set via redirect), print the full banner.
    if (strpos($message, '<div class="toast') === 0) {
        echo $message; 
    } else {
        // If it's the plain text message from file upload, use a simple paragraph (not a banner).
        ?>
        <p style="font-weight: bold; margin-bottom: 20px; color: #008000;"><?= h($message) ?></p>
        <?php
    }
    ?>
<?php endif; ?>

<div class="card card--pad">
    <h3>Step 1: Select Target & Upload File</h3>
    <form class="form" method="post" enctype="multipart/form-data" action="">
        
        <label for="import_target" style="display: block; margin-bottom: 5px; font-weight: bold;">Select Target Table:</label>
        <select class="input" id="import_target" name="import_target" required style="margin-bottom: 15px;">
            <?php foreach ($IMPORT_TARGETS as $key => $target): ?>
                <option value="<?= h($key) ?>" <?= ($target_key === $key) ? 'selected' : '' ?>>
                    <?= h($target['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <label for="import_file" style="display: block; margin-bottom: 5px; font-weight: bold;">Select File (.csv or .xlsx):</label>
        <input class="input" type="file" name="import_file" id="import_file" accept=".csv, .xlsx" required />
        
        <button class="btn btn--primary" type="submit" style="margin-top:15px;">Upload & Preview Data</button>
    </form>
</div>

<?php if ($AI_ENABLED): ?>
    <div class="card card--pad" style="margin-top:20px;">
        <h3>💡 AI Import Helper</h3>
        <?php if (!empty($previewData['columns'])): ?>
            <p>The AI has analyzed your uploaded file's header and suggests an ideal format for the **SKU** table import. Use this as a guide for your next upload.</p>
            <p style="background-color: #f0f0f0; padding: 10px; border-radius: 5px; font-family: monospace;">
                **Suggested Header:** <?= h($aiSuggestion) ?>
            </p>
        <?php else: ?>
            <p>Upload a file to receive an AI-powered suggestion on the best column format for your import.</p>
        <?php endif; ?>
    </div>
<?php endif; ?> 

<?php if (!empty($previewData['rows'])): ?>

    <div id="import-preview-modal-backdrop" class="modal-backdrop" style="display: none;"></div> 

    <div id="import-preview-modal" class="modal" style="display: none;">
        <div class="modal-content card card--pad">
            
            <h3>Preview: <?= $_SESSION['import_filename'] ?? '' ?> (showing first <?= $PREVIEW_ROW_CAP ?> rows)</h3>
            
            <form method="post" action="import.php"> 
                <input type="hidden" name="confirm_insert" value="1" />
                <input type="hidden" name="import_target" value="<?= h($target_key) ?>" /> 
                
                <h4><?= empty($error) ? 'Step 2: Map Columns and Confirm Import' : 'Step 2: Map Columns (Resolve Errors to Proceed)' ?></h4>

                <p>Match your uploaded file's columns to the required database fields for the **<?= h($current_target['name']) ?>** table. The mapped columns will be used for insertion.</p>

                <div class="table-wrap">
                    <table class="table table--map">
                        <thead>
                            <tr>
                                <th>Database Field</th>
                                <th>Required?</th>
                                <th>Sample Data (First Row)</th> 
                                <th>Select Matching File Column</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($DB_TARGET_FIELDS as $db_col => $info): 
                                $isRequired = $info['required'];
                                $label = $info['label'];
                                
                                // 1. Check for persistence (if user submitted the form before)
                                $final_selected_file_col = $_POST['column_map'][$db_col] ?? null;

                                // 2. Apply Heuristic if no persistence exists (first load after upload)
                                if ($final_selected_file_col === null) {
                                    $db_col_normalized = strtolower($db_col);
                                    $label_normalized = strtolower($label);
                                    
                                    foreach ($previewData['columns'] as $file_col) {
                                        $file_col_normalized = strtolower(trim($file_col));

                                        if ($file_col_normalized === $db_col_normalized || $file_col_normalized === $label_normalized) {
                                            $final_selected_file_col = $file_col;
                                            break;
                                        }
                                        
                                        if (str_replace('_num', '', $db_col_normalized) === $file_col_normalized) {
                                            $final_selected_file_col = $file_col;
                                            break;
                                        }
                                    }
                                }

                                // Use the selected column name to find the corresponding sample data
                                $sample_data_for_preview = '';
                                if ($final_selected_file_col !== null) {
                                    $col_index = array_search($final_selected_file_col, $previewData['columns']);
                                    if ($col_index !== false) {
                                        $sample_data_for_preview = $firstRowData[$col_index] ?? '';
                                    }
                                }
                            ?>
                                <tr>
                                    <td style="font-weight: bold;"><?= h($label) ?></td>
                                    <td style="color: <?= $isRequired ? '#b00020' : '#008000' ?>;"><?= $isRequired ? 'Yes' : 'No' ?></td>
                                    
                                    <td>
                                        <span class="first-row-preview" data-db-col="<?= h($db_col) ?>">
                                            <?php 
                                                if ($final_selected_file_col === null) {
                                                    echo '(Select column above)';
                                                } else {
                                                    echo h($sample_data_for_preview) ?: '(Empty Value)';
                                                }
                                            ?>
                                        </span>
                                    </td>
                                    
                                    <td>
                                        <select 
                                            name="column_map[<?= h($db_col) ?>]" 
                                            class="input column-mapper" 
                                            <?= $isRequired ? 'required' : '' ?>
                                            style="width: 100%; min-width: 250px;"
                                        >
                                            <option value="" <?= ($final_selected_file_col === null) ? 'selected' : '' ?>>-- DO NOT IMPORT --</option>
                                            <?php foreach ($previewData['columns'] as $col_index => $file_col): ?>
                                                <option 
                                                    value="<?= h($file_col) ?>" 
                                                    data-col-index="<?= h($col_index) ?>"
                                                    data-sample-data="<?= h($firstRowData[$col_index] ?? '') ?>"
                                                    <?= ($final_selected_file_col === $file_col) ? 'selected' : '' ?>
                                                >
                                                    <?= h($file_col) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p style="margin-top:20px;">Review the preview and column mapping. Ready to insert this data?</p>
                
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button class="btn btn--primary" type="submit">Confirm and Insert Data</button>
                    <button type="button" class="btn btn--secondary modal-close">Cancel / Close Preview</button> 
                </div>
            </form>
            </div>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();

// Define script variables (using your cache-busting logic)
$js_web_path = '/dev/js/import-preview.js';
$js_fs = __DIR__ . $js_web_path;
$ver = is_file($js_fs) ? filemtime($js_fs) : time();
$script_src = $js_web_path . '?v=' . $ver;

// Create the variable to hold the script tag HTML
$footer_js = '<script src="' . htmlspecialchars($script_src) . '"></script>' . "\n";

include __DIR__ . '/templates/layout.php';
?>
