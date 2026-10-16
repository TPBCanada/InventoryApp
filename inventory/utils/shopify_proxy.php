<?php
// shopify_proxy.php - Server-side proxy for Shopify product lookup

declare(strict_types=1);

// Include the configuration file where SHOPIFY_... constants are defined.
// NOTE: Adjust the path if config_init.php is not relative to this proxy file.
require_once __DIR__ . '/../../utils/config_init.php';

if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json', true, 401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

// Function to handle the cURL request to Shopify
function fetchShopifyProductBySku(string $sku): ?array {
    
    // Use the defined constants
    $domain = SHOPIFY_STORE_DOMAIN;
    $version = SHOPIFY_API_VERSION;
    $token = SHOPIFY_ADMIN_ACCESS_TOKEN; 
    
    // Shopify API endpoint for searching products (filtering variants by SKU)
    // We use the GraphQL Admin API which is ideal for precise filtering like SKU.
    $url = "https://$domain/admin/api/$version/graphql.json";

    // GraphQL query to find the product variant by SKU
    // Note: The GraphQL API is much better for SKU searches than the REST API.
    $query = <<<GQL
    query GetProductBySku(\$sku: String!) {
      productVariants(first: 1, query: "sku:\$sku") {
        edges {
          node {
            sku
            displayName
            image {
              url
            }
            product {
              handle
              productType
              description(truncateAt: 150)
            }
          }
        }
      }
    }
    GQL;

    $payload = [
        'query' => $query,
        'variables' => ['sku' => $sku]
    ];

    $ch = curl_init();
    
    // Set cURL options for the POST request
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Set required headers for Admin API
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "X-Shopify-Access-Token: $token"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || $response === false) {
        // Log the error for server-side debugging
        error_log("Shopify API Error: HTTP $http_code. Response: " . ($response === false ? 'cURL failed' : $response));
        return null;
    }

    $data = json_decode($response, true);
    return $data;
}


// --- Main Execution Logic ---

$sku = trim($_GET['sku'] ?? '');
if (!$sku) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'SKU is missing']);
    exit;
}

$api_response = fetchShopifyProductBySku($sku);

header('Content-Type: application/json; charset=utf-8');

if (!$api_response || !empty($api_response['errors'])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => "Product details for SKU $sku not found or API error occurred."]);
    exit;
}

// Extract the first product variant found (should only be one)
$edges = $api_response['data']['productVariants']['edges'];

if (empty($edges)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => "Product variant with SKU $sku not found."]);
    exit;
}

$variant = $edges[0]['node'];
$product = $variant['product'];

// Map the Shopify response data to the structure your JavaScript expects
$product_data = [
    'sku'         => $variant['sku'],
    'name'        => $variant['displayName'],
    'description' => $product['description'] ?? '', 
    'category'    => $product['productType'] ?? 'General',
    'price'       => 0.00, // Price is usually on the variant/inventory item and requires a separate query
    'image_url'   => $variant['image']['url'] ?? 'https://via.placeholder.com/120?text=No+Image'
];


echo json_encode(['ok' => true, 'data' => $product_data]);
exit;

?>