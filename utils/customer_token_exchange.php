<?php
declare(strict_types=1);

// Set appropriate Content-Type header
header('Content-Type: application/json; charset=utf-8');

/**
 * Exchanges the Authorization Code for an Access Token using the Shopify 
 * Customer Account API's token endpoint.
 * * This function is designed for Confidential Clients, requiring Basic Authentication.
 *
 * @param string $tokenEndpoint The discovered token endpoint URL.
 * @param string $clientId The application's Client ID.
 * @param string $clientSecret The application's Client Secret (Crucial for Confidential Clients).
 * @param string $redirectUri The exact redirect URI used in the authorize step.
 * @param string $authCode The authorization code received in the query parameters.
 * @return array The decoded JSON response array from the token endpoint.
 */
function obtainCustomerAccessToken(
    string $tokenEndpoint,
    string $clientId,
    string $clientSecret,
    string $redirectUri,
    string $authCode
): array {
    // 1. Prepare Authorization Header (Basic Auth: base64(client_id:client_secret))
    $authCredentials = base64_encode("{$clientId}:{$clientSecret}");
    $authorizationHeader = "Authorization: Basic {$authCredentials}";

    // 2. Prepare POST data (must be application/x-www-form-urlencoded)
    $postData = http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'code' => $authCode,
    ]);

    // 3. Initialize cURL session
    $ch = curl_init($tokenEndpoint);
    
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        $authorizationHeader, // Include Basic Auth header
        // It's good practice to include a User-Agent and Origin header as per Shopify documentation
        'User-Agent: MyCustomShopifyApp/1.0',
        // Replace with your application's expected origin if required for 401 errors
        // 'Origin: https://my-app-domain.com' 
    ]);
    
    // 4. Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new \Exception("cURL Error: {$error}");
    }

    $data = json_decode($response, true);

    // 5. Handle non-200 responses
    if ($httpCode !== 200) {
        $errorDetail = $data['error_description'] ?? $data['error'] ?? 'Unknown authentication error.';
        throw new \Exception("Token exchange failed with HTTP {$httpCode}. Detail: {$errorDetail}");
    }

    return $data;
}

// =================================================================
// ===== EXECUTION EXAMPLE (Replace with your actual values) =======
// =================================================================

// 1. Define configuration values
// NOTE: These should be retrieved from a secure configuration source (like wp-config.php)
$token_endpoint = 'https://shopify-customer-account.myshopify.com/account/token'; // Placeholder URL, discover this from OpenID config
$client_id      = 'YOUR_CLIENT_ID';     // Your application's Client ID
$client_secret  = 'YOUR_CLIENT_SECRET'; // Your application's Client Secret (KEEP SECRET!)
$redirect_uri   = 'https://example.com/shopify/redirect'; // Must match exactly what was used in the /authorize step

// 2. Retrieve the 'code' from the URL query parameters
$auth_code = $_GET['code'] ?? null;

if (empty($auth_code)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing authorization code in query parameters.']);
    exit;
}

try {
    $tokenData = obtainCustomerAccessToken(
        $token_endpoint,
        $client_id,
        $client_secret,
        $redirect_uri,
        $auth_code
    );

    // Success! Output the token data (contains access_token, refresh_token, etc.)
    echo json_encode(['ok' => true, 'data' => $tokenData]);

} catch (\Exception $e) {
    // Handle specific errors during the exchange process
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to obtain access token.', 'detail' => $e->getMessage()]);
}

// You can also optionally validate the 'state' parameter here if it was used in the /authorize step
$receivedState = $_GET['state'] ?? null;
// You would compare $receivedState to the state you stored in the session before redirection.

?>
