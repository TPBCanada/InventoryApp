<?php
declare(strict_types=1);
session_start();
// **CRITICAL:** Ensure this path to dbinv.php is correct and the file establishes the $conn object.
// We assume this file exists and successfully connects, setting the $conn variable.
require_once __DIR__ . '/dbinv.php';

// =================================================================
// ===== 1. SHOPIFY CONFIGURATION (REQUIRED FOR IMAGE FETCH) =======
// =================================================================
// These values are retrieved from the environment (e.g., set via putenv() in wp-config.php)
const SHOPIFY_API_VERSION = '2024-10'; 
const SHOPIFY_ADMIN_TOKEN_FALLBACK = 'shpat_...'; // Fallback token (MUST BE REPLACED/SECURED)
const SHOPIFY_DOMAIN_FALLBACK = 'tpbmarketplace.myshopify.com'; // Fallback domain updated based on user input
// =================================================================

// TEMPORARY DEBUG: Check database connection status
if (!isset($conn) || !$conn || $conn->connect_error) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DBinv failed to connect.']);
  exit;
}
// END TEMPORARY DEBUG

header('Content-Type: application/json; charset=utf-8');

// Ensure MySQLi throws exceptions for error handling
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn->set_charset('utf8mb4');
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB Connection/Init Failed','detail'=>$e->getMessage()]);
  exit;
}

// --- Shopify Helper Function ------------------------------------------
/**
 * Securely fetches the product image URL from Shopify Admin API using GraphQL.
 * @param string $skuOrBarcode The SKU or Barcode to search for.
 * @return string|null The image URL or null if not found/failed.
 */
function fetchShopifyVariantImage(string $skuOrBarcode): ?string {
    // Retrieve credentials securely, prioritizing environment variables
    $token = getenv('SHOPIFY_ADMIN_TOKEN') ?: SHOPIFY_ADMIN_TOKEN_FALLBACK;
    $domain = getenv('SHOPIFY_DOMAIN') ?: SHOPIFY_DOMAIN_FALLBACK;
    
    // Check if token is secured and configured (prevent running with fallbacks)
    if (empty($token) || $token === SHOPIFY_ADMIN_TOKEN_FALLBACK) {
        // If credentials are not properly set, we skip the Shopify call.
        return null; 
    }
    
    // Search query to match either SKU or Barcode
    $search_filter = "sku:\"{$skuOrBarcode}\" OR barcode:\"{$skuOrBarcode}\""; 

    $graphql_query = '
        query GetVariantImage($searchQuery: String!) {
          productVariants(first: 1, query: $searchQuery) {
            edges {
              node {
                image {
                  url
                }
              }
            }
          }
        }
    ';

    $payload = json_encode([
        'query' => $graphql_query,
        'variables' => ['searchQuery' => $search_filter]
    ]);

    $url = "https://" . $domain . "/admin/api/" . SHOPIFY_API_VERSION . "/graphql.json";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "X-Shopify-Access-Token: " . $token // Use the dynamically retrieved token
    ]);

    $shopify_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200 || !$shopify_response) {
        // Log API error here if necessary, but return null to keep local data working
        return null; 
    }

    $shopify_data = json_decode($shopify_response, true);
    
    return $shopify_data['data']['productVariants']['edges'][0]['node']['image']['url'] ?? null;
}
// ----------------------------------------------------------------------


try {
// --- helpers --------------------------------------------------------------
$dbNameRes = $conn->query('SELECT DATABASE()');
$dbName = $dbNameRes->fetch_row()[0] ?? '';

$hasCol = function(mysqli $conn, string $db, string $table, string $col): bool {
 $stmt = $conn->prepare("
  SELECT 1
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
  LIMIT 1
 ");
 $stmt->bind_param('sss', $db, $table, $col);
 $stmt->execute();
 $exists = (bool)$stmt->get_result()->fetch_row();
 $stmt->close();
 return $exists;
};

// --- input ----------------------------------------------------------------
$raw = $_GET['code'] ?? $_POST['code'] ?? '';
$code = trim(trim($raw), " \t\n\r\0\x0B'\"");
if ($code === '') { echo json_encode(['ok'=>false,'error'=>'Missing code']); exit; }

// --- find SKU (Enhanced to search by SKU or Description) --------------------
$searchCode = '%' . $code . '%';

$skuSql = "
 SELECT id, sku_num, `desc`, `status`, 0 AS sku_quantity
 FROM sku
 WHERE sku_num = ?
    OR sku_num LIKE ?
    OR `desc` LIKE ?
 LIMIT 1
";
$skuStmt = $conn->prepare($skuSql);
// Bind the code for: exact SKU, partial SKU, partial Description
$skuStmt->bind_param('sss', $code, $searchCode, $searchCode);
$skuStmt->execute();
$sku = $skuStmt->get_result()->fetch_assoc();
$skuStmt->close();

if (!$sku) {
 echo json_encode(['ok'=>false,'error'=>"SKU not found for code: {$code}"]); exit;
}

// --- figure out the PK column of `location` -------------------------------
$locPk = $hasCol($conn, $dbName, 'location', 'id') ? 'id'
  : ($hasCol($conn, $dbName, 'location', 'loc_id') ? 'loc_id' : null);

if ($locPk === null) {
 // Fallback response if location table is inaccessible/missing PK
 echo json_encode([
 'ok'=>true,
 'sku'=>[
  'id'=>(int)$sku['id'], 'sku_num'=>$sku['sku_num'], 'desc'=>$sku['desc'],
  'status'=>$sku['status'], 'sku_quantity'=>(int)$sku['sku_quantity'],
  'scanned_code'=>$code,
 ],
 'total_on_hand'=>0, 'locations'=>[],
 '_warning'=>'Location table missing id/loc_id; cannot lookup locations.',
 ]);
 exit;
}

// --- locations breakdown (Uses 'inventory' snapshot table) ----------------
$locSql = "
 SELECT
 i.loc_id,
 l.row_code, l.bay_num, l.level_code, l.side,
 CONCAT(l.row_code,'-',l.bay_num,'-',l.level_code,'-',l.side) AS location_label,
 i.quantity AS on_hand,
 i.updated_at AS last_movement
 FROM inventory i
 JOIN location l ON l.`$locPk` = i.loc_id
 WHERE i.sku_id = ?
 AND i.quantity > 0
 ORDER BY l.row_code, l.bay_num, l.level_code, l.side
";
$locStmt = $conn->prepare($locSql);
$sku_id = (int)$sku['id'];
$locStmt->bind_param('i', $sku_id);
$locStmt->execute();
$res = $locStmt->get_result();

$locations = [];
$total_on_hand = 0;
while ($row = $res->fetch_assoc()) {
 $qty = (int)$row['on_hand'];
 $row['on_hand'] = $qty;
 $total_on_hand += $qty;
 $locations[] = $row;
}
$locStmt->close();

// ===== 2. IMAGE FETCH AND DATA MERGE =====================================
// Use the primary SKU found in the local DB for the Shopify lookup
$imageUrl = fetchShopifyVariantImage($sku['sku_num']);

// Add the fetched image URL to the SKU array
$sku['image_url'] = $imageUrl; 
// =========================================================================

// --- response -------------------------------------------------------------
echo json_encode([
 'ok' => true,
 'sku' => [
 'id'     => (int)$sku['id'],
 'sku_num'    => $sku['sku_num'],
 'desc'    => $sku['desc'],
 'status'   => $sku['status'],
 'sku_quantity' => (int)$total_on_hand, 
 'scanned_code' => $code,
 'image_url'  => $sku['image_url'] ?? null, // Final image URL included here
 ],
 'total_on_hand' => (int)$total_on_hand,
 'locations'  => $locations,
]);

} catch (\mysqli_sql_exception $e) {
http_response_code(500);
echo json_encode(['ok'=>false,'error'=>'SQL error','detail'=>$e->getMessage()]);
} catch (\Throwable $e) {
http_response_code(500);
echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
}
