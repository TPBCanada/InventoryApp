<?php
// access_control.php (inventory module)

// Try to load shared helper; fall back to inline stubs if missing.
$__ac_store_loaded = false;
$__ac_store_paths = [
    __DIR__ . '/../../utils/access_control_store.php',
    dirname(__DIR__, 2) . '/utils/access_control_store.php',
];
foreach ($__ac_store_paths as $__ac_path) {
    if (is_file($__ac_path)) {
        require_once $__ac_path;
        $__ac_store_loaded = true;
        break;
    }
}
if (!$__ac_store_loaded) {
    if (!function_exists('ac_normalize_roles')) {
        function ac_normalize_roles(array $roles): array
        {
            $roles = array_values(array_unique(array_map('intval', $roles)));
            sort($roles);
            return $roles;
        }
    }
    if (!function_exists('ac_normalize_page_key')) {
        function ac_normalize_page_key(?string $scriptName = null): string
        {
            $scriptName = $scriptName ?? ($_SERVER['SCRIPT_NAME'] ?? '');
            $scriptName = str_replace('\\', '/', $scriptName);
            $scriptName = strtok($scriptName, '?') ?: $scriptName;
            $pos = stripos($scriptName, '/dev/');
            if ($pos !== false) {
                $key = substr($scriptName, $pos + 5);
            } else {
                $key = ltrim($scriptName, '/');
            }
            return $key !== '' ? $key : basename($scriptName);
        }
    }
    if (!function_exists('ac_can_access_page')) {
        function ac_can_access_page(int $roleId, string $pageKey, array $map): bool
        {
            $allowed = $map[$pageKey] ?? $map[basename($pageKey)] ?? [];
            return in_array($roleId, $allowed, true);
        }
    }
    if (!function_exists('ac_build_permissions')) {
        function ac_build_permissions(array $defaults, string $modulePrefix = ''): array
        {
            return array_map('ac_normalize_roles', $defaults);
        }
    }
}

// Page filename (key) => Array of allowed Role IDs (value).
$page_permissions_by_role = [
    'dashboard.php' => [1, 2, 3, 4],
    'manage_sku.php' => [1, 2, 3, 4],
    'manage_location.php' => [1, 2, 3, 4],
    'manage_barcode.php' => [1, 2, 3, 4],
    'invSku.php' => [1, 2, 3, 4],
    'invLoc.php' => [1, 2, 3, 4],
    'invCount.php' => [1, 2, 3, 4],
    'shelf.php' => [1, 2, 3, 4],
    'history.php' => [1, 2, 3, 4],
    'pallet.php' => [1, 2, 3, 4],
    'place.php' => [1, 2, 3, 4],
    'take.php' => [1, 2, 3, 4],
    'transfer.php' => [1, 2, 3, 4],
    'ai_reports.php' => [1, 2, 3, 4],
    'import.php' => [1, 2, 3, 4],
    'requests.php' => [1, 2, 3, 4],
    'verify_pick.php' => [1, 2, 3, 4],
    'receiving.php' => [1, 2, 3, 4],
    'outwhse.php' => [1, 2, 3, 4],
];

$NAVBAR_DISPLAY_MAP = [
    'dashboard.php' => 'Dashboard',
    'manage_sku.php' => 'Product Mgmt',
    'manage_location.php' => 'Location Mgmt',
    'manage_barcode.php' => 'Barcode Mgmt',
    'pallet.php' => 'Pallet Management',
    'invSku.php' => 'Inv by Products',
    'invLoc.php' => 'Inv by Locations',
    'invCount.php' => 'Count Inventory',
    'shelf.php' => 'Shelf Mgmt',
    'history.php' => 'Transaction History',
    'place.php' => 'Add Inventory',
    'take.php' => 'Remove Inventory',
    'transfer.php' => 'Transfer Product',
    'requests.php' => 'Requests',
    'verify_pick.php' => 'Pick Detail',
    'receiving.php' => 'Receiving',
    'ai_reports.php' => 'Reports',
    'import.php' => 'Import Data',
    'outwhse.php' => '3PL',
    'pick_import_audit.php' => 'Import Audits',
];

// --- UTILITY/HELPER PAGES (Require check, but not in navbar) ---
$utility_permissions_by_role_raw = [
    '/utils/helpers.php' => [1, 2, 3, 4],
    '/utils/export_csv.php' => [1, 2, 3, 4],
    'logout.php' => [1, 2, 3, 4],
    'pick_import_audit.php' => [1, 2],
];

// --- 1. PREPARE DATA FOR CHECK AND NAVBAR ---
$EXCLUDED_MENU_FILES = array_map('basename', array_keys($utility_permissions_by_role_raw));

$utility_permissions_by_role_normalized = [];
foreach ($utility_permissions_by_role_raw as $path => $roles) {
    $utility_permissions_by_role_normalized[basename($path)] = $roles;
}

$page_permissions_by_role = array_merge($page_permissions_by_role, $utility_permissions_by_role_normalized);

// Apply admin overrides stored with module prefix
$page_permissions_by_role = ac_build_permissions($page_permissions_by_role, 'inventory/');

// ----------------------------------------------------------------------
// SECURITY CHECK (non-fatal guard; individual pages enforce as needed)
// ----------------------------------------------------------------------
$current_role_id = (int) ($_SESSION['role_id'] ?? 0);
$current_page_key = ac_normalize_page_key();
$allowed_roles = $page_permissions_by_role[$current_page_key] ?? $page_permissions_by_role[basename($current_page_key)] ?? [];

// Enforce when a rule exists
if (is_array($allowed_roles)) {
    $is_allowed = in_array($current_role_id, $allowed_roles, true);
    $has_rule = !empty($allowed_roles) || array_key_exists($current_page_key, $page_permissions_by_role) || array_key_exists(basename($current_page_key), $page_permissions_by_role);
    if ($has_rule && !$is_allowed) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Location: /dev/login.php');
            exit;
        }
        echo "<p style='color:red; text-align:center;'>Access denied. Please <a href='/dev/login.php'>log in</a>.</p>";
        exit;
    }
}
?>
