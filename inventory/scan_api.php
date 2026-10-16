<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/shopify_fetch.php';


const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions'; 
// =================================================================

// =================================================================
// ===== 1. SHOPIFY CONFIGURATION (REQUIRED FOR IMAGE FETCH) =======
// =================================================================
// These values are retrieved from the environment (e.g., set via putenv() in wp-config.php)
// $shopify_domain = SHOPIFY_STORE_DOMAIN;
// $admin_api_version = SHOPIFY_API_VERSION;
// $admin_access_token = SHOPIFY_ADMIN_ACCESS_TOKEN;
// =================================================================

header('Content-Type: application/json; charset=utf-8');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn->set_charset('utf8mb4');
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB Connection/Init Failed','detail'=>$e->getMessage()]);
  exit;
}
 

/**
 * Uses OpenAI's chat model to attempt to identify an SKU from a raw, ambiguous code.
 * @param string $rawCode The unknown code scanned by the user.
 * @return string|null A cleaned, predicted SKU number, or null if identification fails.
 */
function identifySkuWithAi(string $rawCode): ?string {
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY') ?: '');
    if ($apiKey === 'Your_Fallback_Key' || $apiKey === '') {
        // Skip AI if the key is not configured
        return null;
    }

    // 1. Define the system prompt (The "Instructions" for the AI)
    // We instruct the AI to be a clean SKU identifier only.
    $systemPrompt = "You are an inventory SKU identification assistant for a warehouse. Your task is to analyze a raw, ambiguous barcode or product code and determine the most likely internal SKU number. You must only respond with a single, clean SKU number (e.g., '1234567-A') or the word 'UNKNOWN'. DO NOT include any other text, explanation, or formatting. If the code is generic or unidentifiable, respond only with UNKNOWN.";

    // 2. Define the message payload
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Identify the internal SKU for this code: '{$rawCode}'"]
    ];

    $payload = json_encode([
        'model' => 'gpt-3.5-turbo', // A fast, cost-effective model for this task
        'messages' => $messages,
        'temperature' => 0.0, // Set to 0.0 for deterministic, factual identification
    ]);

    // 3. Execute the cURL request to OpenAI
    $ch = curl_init(OPENAI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}"
        ]
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 4. Process the response
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? 'UNKNOWN';

        $predictedSku = trim(strtoupper($content));

        // Return the clean SKU, or null if the AI couldn't find it
        return ($predictedSku === 'UNKNOWN' || $predictedSku === '') ? null : $predictedSku;
    }
    
    // Log error if API call failed
    error_log("OpenAI API call failed. HTTP Code: {$http_code}. Response: {$response}");
    return null;
}




// --- Shopify Helper Function ------------------------------------------
/**
 * Securely fetches the product image URL from Shopify Admin API using GraphQL.
 * @param string $skuOrBarcode The SKU or Barcode to search for.
 * @return string|null The image URL or null if not found/failed.
 */
// function fetchShopifyVariantImage(string $skuOrBarcode): ?string {
//     // Retrieve credentials securely, prioritizing environment variables
//     $token = getenv('SHOPIFY_ADMIN_TOKEN') ?: SHOPIFY_ADMIN_TOKEN_FALLBACK;
//     $domain = getenv('SHOPIFY_DOMAIN') ?: SHOPIFY_DOMAIN_FALLBACK;
    
//     // Check if token is secured and configured (prevent running with fallbacks)
//     if (empty($token) || $token === SHOPIFY_ADMIN_TOKEN_FALLBACK) {
//         // If credentials are not properly set, we skip the Shopify call.
//         return null; 
//     }
    
//     // Search query to match either SKU or Barcode
//     $search_filter = "sku:\"{$skuOrBarcode}\" OR barcode:\"{$skuOrBarcode}\""; 

//     $graphql_query = '
//         query GetVariantImage($searchQuery: String!) {
//           productVariants(first: 1, query: $searchQuery) {
//             edges {
//               node {
//                 image {
//                   url
//                 }
//               }
//             }
//           }
//         }
//     ';

//     $payload = json_encode([
//         'query' => $graphql_query,
//         'variables' => ['searchQuery' => $search_filter]
//     ]);

//     $url = "https://" . $domain . "/admin/api/" . SHOPIFY_API_VERSION . "/graphql.json";
    
//     $ch = curl_init($url);
//     curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
//     curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
//     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//     curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
//     curl_setopt($ch, CURLOPT_TIMEOUT, 10);
//     curl_setopt($ch, CURLOPT_HTTPHEADER, [
//         'Content-Type: application/json',
//         "X-Shopify-Access-Token: " . $token // Use the dynamically retrieved token
//     ]);

//     $shopify_response = curl_exec($ch);
//     $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
//     curl_close($ch);
    
//     if ($http_code !== 200 || !$shopify_response) {
//         // Log API error here if necessary, but return null to keep local data working
//         return null; 
//     }

//     $shopify_data = json_decode($shopify_response, true);
    
//     return $shopify_data['data']['productVariants']['edges'][0]['node']['image']['url'] ?? null;
// }
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
 
$hasTable = function(mysqli $conn, string $db, string $table): bool {
 $stmt = $conn->prepare("
  SELECT 1
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
  LIMIT 1
 ");
 $stmt->bind_param('ss', $db, $table);
 $stmt->execute();
 $exists = (bool)$stmt->get_result()->fetch_row();
 $stmt->close();
 return $exists;
};

// --- input ----------------------------------------------------------------
$raw = $_GET['code'] ?? $_POST['code'] ?? '';
$code = trim(trim($raw), " \t\n\r\0\x0B'\"");
if ($code === '') { echo json_encode(['ok'=>false,'error'=>'Missing code']); exit; }

// --- find SKU (SQL Search) ------------------------------------------------
// Define $searchCode
$searchCode = '%' . $code . '%'; // e.g., '%2024%'

$hasUpc = $hasCol($conn, $dbName, 'sku', 'upc');
if ($hasUpc) {
  $skuSql = "
  SELECT id, sku_num, `desc`, `status`, upc, shelf
  FROM sku
  WHERE sku_num = ?   
    OR upc = ?        
    OR sku_num LIKE ? 
    OR `desc` LIKE ?  
  LIMIT 1
  ";
  $skuStmt = $conn->prepare($skuSql);
  // Bind: 'ssss' (4 strings)
  // Parameter 1 (Exact SKU) and 2 (Exact UPC) use $code
  // Parameter 3 (Partial SKU) and 4 (Partial Desc) use $searchCode
  $skuStmt->bind_param('ssss', $code, $code, $searchCode, $searchCode);
} else {
  $skuSql = "
  SELECT id, sku_num, `desc`, `status`, shelf
  FROM sku
  WHERE sku_num = ?   
    OR sku_num LIKE ? 
    OR `desc` LIKE ?  
  LIMIT 1
  ";
  $skuStmt = $conn->prepare($skuSql);
  // Bind: 'sss' (3 strings)
  $skuStmt->bind_param('sss', $code, $searchCode, $searchCode);
}
$skuStmt->execute();

$sku = $skuStmt->get_result()->fetch_assoc();
$skuStmt->close();

$uomMatch = null;
if (!$sku && $hasTable($conn, $dbName, 'product_uom_barcodes')) {
  $uomSql = "
    SELECT
      s.id,
      s.sku_num,
      s.`desc`,
      s.`status`,
      s.shelf,
      b.upc,
      b.uom_label,
      b.multiplier,
      b.length,
      b.width,
      b.height,
      b.weight_lbs
    FROM product_uom_barcodes b
    JOIN sku s ON s.id = b.sku_id
    WHERE b.upc = ?
    LIMIT 1
  ";
  $uomStmt = $conn->prepare($uomSql);
  $uomStmt->bind_param('s', $code);
  $uomStmt->execute();
  $uomRow = $uomStmt->get_result()->fetch_assoc();
  $uomStmt->close();
  if ($uomRow) {
    $sku = [
      'id' => $uomRow['id'],
      'sku_num' => $uomRow['sku_num'],
      'desc' => $uomRow['desc'],
      'status' => $uomRow['status'],
      'shelf' => $uomRow['shelf'],
    ];
    $uomMatch = [
      'upc' => $uomRow['upc'],
      'uom_label' => $uomRow['uom_label'],
      'multiplier' => (int) $uomRow['multiplier'],
      'length' => $uomRow['length'],
      'width' => $uomRow['width'],
      'height' => $uomRow['height'],
      'weight_lbs' => $uomRow['weight_lbs'],
    ];
  }
}

// --- AI Fallback Logic -----------------------------------------------------
if (!$sku) {
    // 1. SQL failed to find a match, run the AI identification
    $ai_predicted_sku = identifySkuWithAi($code);
    
    if ($ai_predicted_sku) {
        // 2. AI returned a predicted SKU, now search the database again using the AI result
        
        $aiSkuSql = "
            SELECT id, sku_num, `desc`, `status`
            FROM sku
            WHERE sku_num = ?
            LIMIT 1
        ";
        $aiSkuStmt = $conn->prepare($aiSkuSql);
        $aiSkuStmt->bind_param('s', $ai_predicted_sku);
        $aiSkuStmt->execute();
        $sku = $aiSkuStmt->get_result()->fetch_assoc();
        $aiSkuStmt->close();
        
        // Add a flag to the SKU data so the frontend knows it was AI-identified
        if ($sku) {
            $sku['_ai_identified'] = true;
            $sku['_scanned_raw'] = $code;
        }
    }
}


// --- Final check before proceeding ------------------------------------------
if (!$sku) {
   // If SQL failed AND AI failed, send final failure response
   echo json_encode(['ok'=>false,'error'=>"SKU not found. SQL and AI search failed for code: {$code}"]); 
    exit;
}

$locPk = $hasCol($conn, $dbName, 'location', 'id') ? 'id'
  : ($hasCol($conn, $dbName, 'location', 'loc_id') ? 'loc_id' : null);

if ($locPk === null) {
 echo json_encode([
  'ok'=>true,
  'sku'=>[
   'id'=>(int)$sku['id'],
   'sku_num'=>$sku['sku_num'],
   'desc'=>$sku['desc'],
   'status'=>$sku['status'],
   'sku_quantity'=>0,
   'scanned_code'=>$code,
  ],
  'total_on_hand'=>0,
  'locations'=>[],
  '_warning'=>'Location table missing id/loc_id; cannot lookup locations.',
 ]);
 exit;
}

// --- locations breakdown ---------------------------------------------------
$locColumn = $locPk === 'id' ? 'l.id' : 'l.loc_id';
$sku_id = (int)$sku['id'];
$locSql = "
 SELECT
  {$locColumn} AS loc_id,
  l.row_code,
  l.bay_num,
  l.level_code,
  l.side,
  p.id AS pallet_id,
  p.pallet_tag,
  i.quantity AS on_hand,
  i.updated_at AS last_movement
 FROM inventory i
 JOIN pallet p ON p.id = i.pallet_id
 JOIN location l ON l.`{$locPk}` = p.loc_id
 WHERE i.sku_id = ?
   AND i.quantity > 0
 ORDER BY l.row_code, l.bay_num, l.level_code, l.side, p.pallet_tag
";
$locStmt = $conn->prepare($locSql);
$locStmt->bind_param('i', $sku_id);
$locStmt->execute();
$res = $locStmt->get_result();

$locations = [];
$total_on_hand = 0;
while ($row = $res->fetch_assoc()) {
 $qty = (int)$row['on_hand'];
 $row['on_hand'] = $qty;
 $row['location_label'] = sprintf(
  '%s-%s-%s-%s',
  $row['row_code'],
  $row['bay_num'],
  $row['level_code'],
  $row['side'],
  // $row['pallet_tag'] ? ' (' . $row['pallet_tag'] . ')' : ''
 );
 $total_on_hand += $qty;
 $locations[] = $row;
}
$locStmt->close();

if (!$locations) {
 $fallbackSql = "
  SELECT
   l.`{$locPk}` AS loc_id,
   l.row_code,
   l.bay_num,
   l.level_code,
   l.side,
   NULL AS pallet_id,
   NULL AS pallet_tag,
   SUM(im.quantity_change) AS on_hand,
   MAX(im.created_at) AS last_movement
  FROM inventory_movements im
  JOIN location l ON l.`{$locPk}` = im.loc_id
  WHERE im.sku_id = ?
  GROUP BY l.`{$locPk}`
  HAVING on_hand <> 0
  ORDER BY l.row_code, l.bay_num, l.level_code, l.side
 ";
 $fbStmt = $conn->prepare($fallbackSql);
 $fbStmt->bind_param('i', $sku_id);
 $fbStmt->execute();
 $res = $fbStmt->get_result();
 while ($row = $res->fetch_assoc()) {
  $qty = (int)$row['on_hand'];
  $row['on_hand'] = $qty;
  $row['location_label'] = sprintf(
   '%s-%s-%s-%s',
   $row['row_code'],
   $row['bay_num'],
   $row['level_code'],
   $row['side']
  );
  $total_on_hand += $qty;
  $locations[] = $row;
 }
 $fbStmt->close();
}

// ===== 2. IMAGE FETCH AND DATA MERGE =====================================
// Use the primary SKU found in the local DB for the Shopify lookup
$imageUrl = null;
$shopifyRaw = null;
try {
  $shopifyProduct = shopify_fetch_product_by_sku((string)$sku['sku_num'], 0);
  $shopifyRaw = $shopifyProduct;
  if ($shopifyProduct && !empty($shopifyProduct['image_src'])) {
    $imageUrl = $shopifyProduct['image_src'];
  }
} catch (\Throwable $e) {
  // Non-fatal for scanner; log for diagnostics only
  error_log('[scan_api] Shopify lookup failed: ' . $e->getMessage());
}

// Add the fetched image URL to the SKU array
$sku['image_url'] = $imageUrl; 
if ($shopifyRaw !== null) {
  $sku['_shopify_product'] = $shopifyRaw; // debug visibility for client-side console
}

// --- response -------------------------------------------------------------
echo json_encode([
 'ok' => true,
 'sku' => [
 'id'     => (int)$sku['id'],
 'sku_num'    => $sku['sku_num'],
 'desc'    => $sku['desc'],
 'status'   => $sku['status'],
 'shelf_code' => $sku['shelf'] ?? null,
 'sku_quantity' => (int)$total_on_hand, 
 'scanned_code' => $code,
 'image_url'  => $sku['image_url'] ?? null,
 ],
 'uom' => $uomMatch,
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
