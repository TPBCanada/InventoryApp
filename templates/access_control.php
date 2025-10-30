<?php
// access_control.php
//
// This file serves two purposes:
// 1. Defines the definitive map of which roles are allowed to access which files.
// 2. Executes the check to ensure the current user's role is permitted to view the current script.

// Define the definitive mapping: Page filename (key) => Array of allowed Role IDs (value).
// Include utility pages that don't need checks (like logout) or have global access (dashboard).
$page_permissions_by_role = [
    'dashboard.php'       => [1, 2, 3, 4], // All roles should see the dashboard
    'manage_roles.php'    => [1],
    'manage_users.php'    => [1, 2],
    'manage_sku.php'      => [1],
    'manage_location.php' => [1],
    'invSku.php'          => [1, 2, 3, 4],
    'invLoc.php'          => [1, 2, 3, 4],
    'history.php'         => [1, 2, 4],
    'place.php'           => [1, 2, 4],
    'take.php'            => [1, 2, 4],
    'transfer.php'        => [1, 2, 4],
    // 'ai_reports.php'      => [1],
    // 'audit.php'           => [1],
    'whse_sup.php'        => [1, 2, 3, 4],
    'invReq.php'          => [1, 2, 3, 4],
    // 'receive.php'         => [1, 2, 3, 4],

    // Any page *not* listed here will default to denying access in the check below.
];

// ----------------------------------------------------------------------
// SECURITY CHECK IMPLEMENTATION
// ----------------------------------------------------------------------

// 1. Get the current user role ID (Default to 0 if not logged in)
$current_role_id = $_SESSION['role_id'] ?? 0;

// 2. Determine the current script filename
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$current_page = basename(parse_url($request_uri, PHP_URL_PATH) ?: '');

// 3. Look up the allowed roles for the current page
// If the page is not in the map, assume no one is allowed (default to empty array).
$allowed_roles = $page_permissions_by_role[$current_page] ?? [];

// 4. Check if the user's role is in the allowed list
if (empty($allowed_roles) || !in_array($current_role_id, $allowed_roles, true)) {
    // Access denied!
    
    // NOTE: Removed http_response_code(403) to prevent "headers already sent" warning,
    // as output has already started in layout.php.
    
    // ACTION: Display a message and redirect using client-side HTML meta refresh
    die("
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <!-- Redirect after 3 seconds -->
            <meta http-equiv='refresh' content='3;url=/dashboard.php'>
            <title>Access Denied</title>
            <style>
                body {
                    font-family: sans-serif;
                    text-align: center;
                    margin-top: 10vh;
                    background-color: #f8f8f8;
                }
                .message-box {
                    padding: 30px;
                    border: 2px solid #e74c3c;
                    background-color: #fff0f0;
                    display: inline-block;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                    max-width: 400px;
                    margin: auto;
                }
                h1 {
                    color: #c0392b;
                    margin-top: 0;
                }
                p {
                    color: #333;
                    margin-bottom: 5px;
                }
                .redirect-link {
                    display: block;
                    margin-top: 20px;
                    color: #3498db;
                    text-decoration: none;
                    font-weight: bold;
                }
            </style>
        </head>
        <body>
            <div class='message-box'>
                <h1>Access Denied</h1>
                <p>Your role (ID: <strong>{$current_role_id}</strong>) does not have permission to view <strong>{$current_page}</strong>.</p>
                <p>You are being automatically redirected to the home page...</p>
                <p>Please contact an administrator if you believe this is an error.</p>
                <a href='/dev/dashboard.php' class='redirect-link'>Click here to return immediately</a>
            </div>
        </body>
        </html>
    ");
}

// NOTE: The redundant second access check that was previously here has been removed.

?>
