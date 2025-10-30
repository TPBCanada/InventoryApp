<?php
// utils/import.php — Handles CSV/Excel file upload and preview before insertion.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../dbinv.php'; // For database connection if needed later

// Include helpers for HTML escaping
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$title = 'Data Import';
$error = '';
$message = '';
$preview_data = [];
$filename = '';

// Max rows to show in preview
$PREVIEW_ROW_CAP = 10; 

// --- Step 1: Handle File Upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed with error code: ' . $file['error'];
    } elseif ($file['size'] == 0) {
        $error = 'The uploaded file is empty.';
    } elseif ($file['type'] !== 'text/csv' && pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        // Basic check, you might want a more robust check for TSV/XLSX
        $error = 'Only CSV files are currently supported.';
    } else {
        // Securely save the uploaded file temporarily
        $temp_path = sys_get_temp_dir() . '/' . basename($file['tmp_name']) . '.csv';
        if (move_uploaded_file($file['tmp_name'], $temp_path)) {
            
            $filename = h($file['name']);
            $handle = fopen($temp_path, 'r');
            if ($handle !== false) {
                // Read headers
                $preview_data['columns'] = fgetcsv($handle); 
                $preview_data['rows'] = [];
                $row_count = 0;

                // Read rows for preview
                while (($row = fgetcsv($handle)) !== false) {
                    // Assuming columns are read correctly, filter data if needed
                    $preview_data['rows'][] = $row;
                    $row_count++;
                    if ($row_count >= $PREVIEW_ROW_CAP) break;
                }
                fclose($handle);
                
                // Store the full path for the next (confirmation) step
                $_SESSION['import_file_path'] = $temp_path;
                $_SESSION['import_columns'] = $preview_data['columns'];
                $_SESSION['import_filename'] = $filename;

                $message = "File **$filename** uploaded successfully. Preview below. Total rows uploaded: **$row_count**+";
            } else {
                $error = "Could not read the uploaded file.";
            }
        } else {
            $error = "Failed to move the uploaded file to temp storage.";
        }
    }
} 

// --- Step 2: Handle Confirmation/Insertion ---
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_insert'])) {
    // This is where you put your final database INSERT logic
    $path = $_SESSION['import_file_path'] ?? '';
    
    if (empty($path) || !is_file($path)) {
        $error = "Import session expired or file not found. Please re-upload.";
    } else {
        // --- Database Insertion Logic Goes Here ---
        // For example:
        // $conn->begin_transaction();
        // $table_name = 'inventory_movements';
        // $columns = $_SESSION['import_columns'];
        // $handle = fopen($path, 'r');
        // while (($row = fgetcsv($handle)) !== false) {
        //     // Safely prepare and execute INSERT statement
        // }
        // $conn->commit();
        // unlink($path); // Delete the temp file after insertion
        // $message = "Successfully imported X records into the database!";
        
        $message = "Data confirmation successful. **(Database insertion skipped in this example)**";
        // After successful insertion:
        unset($_SESSION['import_file_path'], $_SESSION['import_columns'], $_SESSION['import_filename']);
    }
}


// --- HTML View Rendering ---
ob_start();
?>

<h2 class="title">Import Data</h2>

<?php if ($error): ?><p class="error" style="color:#b00020;">❌ <?= $error ?></p><?php endif; ?>
<?php if ($message): ?><p class="success" style="color:#008000;">✅ <?= $message ?></p><?php endif; ?>

<div class="card card--pad">
    <h3>Upload CSV File</h3>
    <form class="form" method="post" enctype="multipart/form-data" action="">
        <input class="input" type="file" name="import_file" accept=".csv" required />
        <button class="btn btn--primary" type="submit" style="margin-top:10px;">Preview Data</button>
    </form>
</div>

<?php if (!empty($preview_data['rows'])): ?>
    <div class="card card--pad" style="margin-top:20px;">
        <h3>Preview: <?= $_SESSION['import_filename'] ?> (Showing first <?= $PREVIEW_ROW_CAP ?> rows)</h3>
        
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?php foreach ($preview_data['columns'] as $c): ?><th><?= h($c) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_data['rows'] as $r): ?>
                        <tr>
                            <?php foreach ($r as $data): ?><td><?= h((string)($data ?? '')) ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" action="" style="margin-top:15px;">
            <input type="hidden" name="confirm_insert" value="1" />
            <p>Ready to insert this data into the database?</p>
            <button class="btn btn--primary" type="submit">Confirm and Insert Data</button>
        </form>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layout.php'; // NOTE: Assuming layout.php is one level up
?>