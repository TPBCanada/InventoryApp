<?php
// access_control.php (dev root)

// Try to load the shared store helper; fall back to inline stubs if missing.
$__ac_store_loaded = false;
$__ac_store_paths = [
    __DIR__ . '/../utils/access_control_store.php',
    dirname(__DIR__) . '/utils/access_control_store.php',
];
foreach ($__ac_store_paths as $__ac_path) {
    if (is_file($__ac_path)) {
        require_once $__ac_path;
        $__ac_store_loaded = true;
        break;
    }
}

if (!$__ac_store_loaded) {
    // Minimal fallback to keep the site running if the helper file isn't deployed.
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
            $pos = stripos($scriptName, '/dev/' || '/prod/');
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

// ----------------------------------------------------------------------
// DEFAULT PERMISSIONS (used when no override is saved)
// ----------------------------------------------------------------------
$page_permissions_by_role = [

    // Module entry points
    'inventory/dashboard.php' => [1, 2, 3, 4],
    'inspection/dashboard.php' => [1],
    'supplies/dashboard.php' => [1, 2, 3, 4],
    'receiving/dashboard.php' => [1, 2, 3, 4],

];

// Module pages not yet in the defaults (keep admin access by default)
if (!isset($page_permissions_by_role['inventory/verify_pick.php'])) {
    $page_permissions_by_role['inventory/verify_pick.php'] = [1];
}
if (!isset($page_permissions_by_role['timeclock/manage.php'])) {
    $page_permissions_by_role['timeclock/manage.php'] = [1, 2];
}

// Navbar labels (only keys defined here will render in the nav)
$NAVBAR_DISPLAY_MAP = [
    'inventory/dashboard.php' => 'Inventory',
    'inspection/dashboard.php' => 'Inspection',
    'supplies/dashboard.php' => 'Supplies',
    'receiving/dashboard.php' => 'Reporting',
];

// --- Utility/helper pages (not shown in navbar) ---
$utility_permissions_by_role_raw = [
    '/utils/helpers.php' => [1, 2, 3, 4],
    '/utils/export_csv.php' => [1, 2, 3, 4],
    'logout.php' => [1, 2, 3, 4],
];

// --- Prepare navbar exclusion list ---
$EXCLUDED_MENU_FILES = array_map('basename', array_keys($utility_permissions_by_role_raw));

// Normalize utilities to simple basenames and merge with defaults
$utility_permissions_by_role_normalized = [];
foreach ($utility_permissions_by_role_raw as $path => $roles) {
    $utility_permissions_by_role_normalized[basename($path)] = $roles;
}

$page_permissions_by_role = array_merge($page_permissions_by_role, $utility_permissions_by_role_normalized);

// Apply any admin overrides from the DB
$page_permissions_by_role = ac_build_permissions($page_permissions_by_role, '');

// ----------------------------------------------------------------------
// SECURITY CHECK (optional soft gate; individual pages should still guard)
// ----------------------------------------------------------------------
$current_role_id = $_SESSION['role_id'] ?? 0;
$current_page_key = ac_normalize_page_key();
$allowed_roles = $page_permissions_by_role[$current_page_key] ?? $page_permissions_by_role[basename($current_page_key)] ?? [];

// Enforce only when a page has a defined rule (empty array = explicit block)
if (is_array($allowed_roles)) {
    // Admin bypass: allow role 1 everywhere unless explicitly changed later.
    if ($current_role_id === 1 && !in_array(1, $allowed_roles, true)) {
        $allowed_roles[] = 1;
    }
    $is_allowed = in_array($current_role_id, $allowed_roles, true);
    $has_rule = !empty($allowed_roles) || array_key_exists($current_page_key, $page_permissions_by_role) || array_key_exists(basename($current_page_key), $page_permissions_by_role);
    if ($has_rule && !$is_allowed) {
        http_response_code(403);
        die("<p style='color:red; text-align:center;'>Access denied for this page.</p>");
    }
}

?>

