<?php
declare(strict_types=1);

/**
 * Usage:
 * 1) Browser: /dev/supplies/utils/migrate_uline_vendor_catalog.php
 *    - Rollback: /dev/supplies/utils/migrate_uline_vendor_catalog.php?rollback=1
 * 2) CLI: php migrate_uline_vendor_catalog.php [rollback]
 */

require_once __DIR__ . '/../../dbinv.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$isCli = PHP_SAPI === 'cli';
$rollback = false;

if ($isCli) {
    global $argv;
    $rollback = isset($argv[1]) && strtolower((string) $argv[1]) === 'rollback';
} else {
    $rollback = isset($_GET['rollback']) && $_GET['rollback'] === '1';
    header('Content-Type: text/plain; charset=UTF-8');
}

$sqlFile = $rollback
    ? __DIR__ . '/sql/2026-02-18_rollback_uline_vendor_catalog.sql'
    : __DIR__ . '/sql/2026-02-18_add_uline_vendor_catalog.sql';

if (!is_file($sqlFile)) {
    http_response_code(500);
    echo "SQL file not found: {$sqlFile}\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false || trim($sql) === '') {
    http_response_code(500);
    echo "SQL file is empty or unreadable: {$sqlFile}\n";
    exit(1);
}

try {
    $conn->set_charset('utf8mb4');
    $conn->multi_query($sql);
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    echo $rollback
        ? "Rollback complete: Warehouse_Stock_Vendor_Catalog dropped.\n"
        : "Migration complete: Warehouse_Stock_Vendor_Catalog created/verified.\n";
    echo "SQL: {$sqlFile}\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

