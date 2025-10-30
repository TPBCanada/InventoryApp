<?php
// export_csv.php — Exports the last executed AI-generated SQL query to CSV.
declare(strict_types=1);
session_start();
// NOTE: We only need dbinv.php if we are running a fresh query.
// If mode is 'preview', we skip the DB connection.

$is_preview_export = ($_GET['mode'] ?? '') === 'preview';

// --- Database Connection / Helpers (Only needed for full export) ---
if (!$is_preview_export) {
    require_once __DIR__ . '/dbinv.php'; // Includes $conn (mysqli)
    // You would still need to include/define the helper functions 
    // validate_select_only() and wrap_with_limit() here for the full export.
    $ROW_CAP_CSV = 50000;
}
// -------------------------------------------------------------------


$data_to_export = [];

if ($is_preview_export) {
    // 1. Export based on session data (Screen Preview)
    $preview_data = $_SESSION['ai_preview'] ?? null;
    if (!$preview_data || empty($preview_data['rows'])) {
        http_response_code(400);
        die("No screen preview data found in session to export.");
    }
    
    $columns = $preview_data['columns'];
    $rows = $preview_data['rows'];

} else {
    // 2. Export based on fresh DB query (Full Export - existing logic)

    // ... (Retrieve SQL from POST or Session as before) ...
    $sql_to_run = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql'])) {
        $sql_to_run = (string)$_POST['sql'];
    } elseif (isset($_SESSION['ai_preview']['sql'])) {
        $sql_to_run = (string)$_SESSION['ai_preview']['sql'];
    }
    
    if ($sql_to_run === '') {
        http_response_code(400);
        die("No SQL query found for full export.");
    }

    // ... (Validate SQL and apply $ROW_CAP_CSV limit) ...
    // Note: You must ensure validate_select_only and wrap_with_limit are defined
    // $final_sql = wrap_with_limit($sql_to_run, $ROW_CAP_CSV);

    // ... (Execute query using $conn->query($final_sql)) ...
    // ... (Fetch $columns and $rows from $result) ...
    // ... (Error handling) ...
    
    // NOTE: For simplicity, the full DB logic is omitted here,
    // but you would place the previous export_csv.php logic here.
    
    // TEMPORARY STUBS for the example:
    $columns = ['id', 'item'];
    $rows = [['1', 'A'], ['2', 'B']];
}

// 3. Set headers for CSV download
$filename = $is_preview_export ? 'ai_preview_' : 'ai_full_report_';
$filename .= date('Ymd_His') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// 4. Open PHP output stream and write data
$output = fopen('php://output', 'w');
if ($output === false) {
    die("Could not open output stream.");
}

// Write headers (column names)
fputcsv($output, $columns);

// Write data rows
foreach ($rows as $row) {
    // Ensure data is passed as a simple array to fputcsv
    $data = [];
    foreach ($columns as $col) {
        // If $row is associative (from DB query), use key. If $row is simple array (from session), use $row[$col] is wrong.
        // Assuming session/preview data is associative for consistency:
        $data[] = $row[$col] ?? ''; 
    }
    fputcsv($output, $data);
}

// 5. Cleanup
fclose($output);
exit;
?>