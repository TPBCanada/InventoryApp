<?php
// CRITICAL: Set the appropriate Content-Type header
header('Content-Type: application/json');

// --- 1. SECURE CREDENTIALS (Store these securely outside the web root if possible)
$shopify_domain = 'your-store-name.myshopify.com';
$admin_api_version = '2024-10';
// THE SENSITIVE KEY: Store this in an environment variable or a configuration file outside the web root if possible.
$admin_access_token = 'shpat_...'; 

// --- 2. Get SKU from the JavaScript POST request
$input = json_decode(file_get_contents('php://input'), true);
$sku = $input['sku'] ?? '';

if (empty($sku)) {
    http_response_code(400);
    echo json_encode(['error' => 'SKU is missing.']);
    exit;
}

// --- 3. Build the GraphQL Payload (PHP)
$graphql_query = '
    query GetVariantBySKU($skuQuery: String!) {
      productVariants(first: 1, query: $skuQuery) {
        edges {
          node {
            sku
            title
            image {
              url
            }
            inventoryItem {
              inventoryLevels(first: 5) {
                edges {
                  node {
                    available
                    location {
                      name
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
';

$payload = json_encode([
    'query' => $graphql_query,
    'variables' => ['skuQuery' => "sku:{$sku}"] // GraphQL query string for exact SKU match
]);

// --- 4. Make the secure request to Shopify (PHP)
$ch = curl_init("https://{$shopify_domain}/admin/api/{$admin_api_version}/graphql.json");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "X-Shopify-Access-Token: {$admin_access_token}"
]);

$shopify_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- 5. Return Shopify's response directly to the frontend
http_response_code($http_code);
echo $shopify_response;

?>