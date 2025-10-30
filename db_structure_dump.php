<?php
declare(strict_types=1);

// --- CONFIGURATION FIX ---
// REMOVED hardcoded credentials. We now rely on 'dbinv.php' to define:
// $servername, $username, $password, and $database.
require_once __DIR__ . '/dbinv.php'; 
// -------------------------

// We must set error reporting for the mysqli connection used below
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// --- SCRIPT START ---
$conn = null;
$error_message = "";
$structure_output = "";

try {
    // 1. Establish connection using variables provided by dbinv.php (using $database)
    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error . ". Check credentials defined in dbinv.php.");
    }

    $structure_output .= "<h1>Database Structure Dump for: `{$database}`</h1>\n";

    // 2. Fetch all table names
    $tables_result = $conn->query("SHOW TABLES");
    if (!$tables_result) {
        throw new Exception("Error fetching tables: " . $conn->error);
    }

    $tables = [];
    while ($row = $tables_result->fetch_row()) {
        $tables[] = $row[0];
    }

    if (empty($tables)) {
        $structure_output .= "<p class='warning'>No tables found in database `{$database}`.</p>";
    } else {
        // 3. For each table, run SHOW CREATE TABLE
        foreach ($tables as $table_name) {
            $create_result = $conn->query("SHOW CREATE TABLE `" . $table_name . "`");
            
            if ($create_result && $row = $create_result->fetch_assoc()) {
                // The MySQL SHOW CREATE TABLE/VIEW result has two columns. The creation SQL is the second column.
                // We use array_values to get the second element regardless of its associative key name.
                $values = array_values($row);
                $create_sql = null;

                if (isset($values[1]) && is_string($values[1])) {
                    $create_sql = $values[1];
                }

                if ($create_sql === null) {
                    $create_sql = "ERROR: Could not retrieve object creation SQL.";
                }
                
                $structure_output .= "<div class='table-section'>\n";
                $structure_output .= "<h2>Table: `{$table_name}`</h2>\n";
                $structure_output .= "<pre>" . htmlspecialchars($create_sql) . "</pre>\n";
                $structure_output .= "</div>\n";
            }
        }
    }

} catch (Exception $e) {
    $error_message = "ERROR: " . $e->getMessage();
} finally {
    if ($conn) {
        $conn->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DB Structure Dump</title>
    <style>
        body { font-family: 'Consolas', 'Courier New', monospace; background-color: #f4f7f9; padding: 20px; }
        .container { max-width: 1000px; margin: auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
        h1 { color: #0056b3; border-bottom: 2px solid #0056b3; padding-bottom: 10px; }
        h2 { color: #333; margin-top: 30px; border-bottom: 1px dashed #ccc; padding-bottom: 5px; }
        .table-section { margin-bottom: 40px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 6px; }
        pre { background-color: #eee; padding: 15px; border: 1px solid #ccc; border-left: 5px solid #007bff; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; font-size: 14px; line-height: 1.4; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error_message): ?>
            <p class="error"><?= htmlspecialchars($error_message) ?></p>
        <?php else: ?>
            <?= $structure_output ?>
        <?php endif; ?>
    </div>
</body>
</html>
