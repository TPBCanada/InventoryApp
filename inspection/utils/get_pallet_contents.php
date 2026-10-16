<?php
// dev/utils/get_pallet_contents.php - The lightweight AJAX endpoint

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

$base_dir = __DIR__ . '/..'; 
require_once $base_dir . '/dbinv.php'; 
require_once __DIR__ . '/helpers.php';

// echo '<pre>';
//  var_dump($_GET);
// echo '</pre>';

// 2. Safety checks 
if (!isset($conn) || !$conn || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo '<p class="text-danger m-0">Authorization failed or database connection missing/failed.</p>';
    exit;
}

// 3. Get the required input
$pallet_id = (int)($_GET['id'] ?? 0);

// 4. Set the correct content type
header('Content-Type: text/html; charset=utf-8');

// 5. Call the helper function and output the result
if ($pallet_id > 0) {
    // getPalletContentsHtml() must be defined in helpers.php
    echo getPalletContentsHtml($conn, $pallet_id);
} else {
    echo '<p class="text-danger m-0">Missing Pallet ID parameter.</p>';
    http_response_code(400); 
}

exit;