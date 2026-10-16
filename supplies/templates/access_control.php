<?php
// access_control.php (supplies module)

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

if (!function_exists('ac_is_ajax_request')) {
    function ac_is_ajax_request(): bool
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        if (!empty($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            return true;
        }
        return isset($_GET['ajax']) || isset($_POST['ajax']);
    }
}

if (!function_exists('ac_reject_access')) {
    function ac_reject_access(int $status = 401, string $message = 'Unauthorized'): void
    {
        http_response_code($status);
        if (ac_is_ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => $message,
                'status' => $status,
            ]);
            exit;
        }
        header('Location: ../login.php?timeout=1');
        exit;
    }
}

// Page filename (key) => Array of allowed Role IDs (value).
$page_permissions_by_role = [
    'whse_sup.php' => [1, 2, 3, 4],
    'box_usage.php' => [1, 2, 3, 4],
    'ai_reports.php' => [1, 2, 3, 4],
    'import.php' => [1, 2, 3, 4],
    'order_history.php' => [1, 2, 3, 4],
    'stock_monitor.php' => [1, 2, 3, 4],
];

// Supplies is stripped down to supply requests (dashboard.php, the navbar brand link).
// The pages above still exist and are permission-checked, but are hidden from the menu.
$HIDDEN_MENU_FILES = array_keys($page_permissions_by_role);

$page_permissions_by_role['stock_on_hand.php'] = [1, 2, 3, 4];

$NAVBAR_DISPLAY_MAP = [
    'stock_on_hand.php' => 'Stock on Hand',
    'whse_sup.php' => 'Catelogue',
    'box_usage.php' => 'Box Usage',
    'ai_reports.php' => 'Reports',
    'import.php' => 'Import',
    'order_history.php' => 'History',
];

// --- UTILITY/HELPER PAGES (Require check, but not in navbar) ---
$utility_permissions_by_role_raw = [
    '/utils/helpers.php' => [1, 2, 3, 4],
    '/utils/export_csv.php' => [1, 2, 3, 4],
    '/../logout.php' => [1, 2, 3, 4],
];

// --- 1. PREPARE DATA FOR CHECK AND NAVBAR ---
$EXCLUDED_MENU_FILES = array_map('basename', array_keys($utility_permissions_by_role_raw));
// Never surface core admin pages in the supplies navbar, even if permissions allow them.
$EXCLUDED_MENU_FILES = array_merge(
    $EXCLUDED_MENU_FILES,
    $HIDDEN_MENU_FILES,
    ['manage_access.php', 'manage_roles.php', 'manage_users.php']
);

$utility_permissions_by_role_normalized = [];
foreach ($utility_permissions_by_role_raw as $path => $roles) {
    $utility_permissions_by_role_normalized[basename($path)] = $roles;
}

$page_permissions_by_role = array_merge($page_permissions_by_role, $utility_permissions_by_role_normalized);

// Apply admin overrides stored with module prefix
$page_permissions_by_role = ac_build_permissions($page_permissions_by_role, 'supplies/');

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
        ac_reject_access(401, 'Session expired or unauthorized');
    }
}
?>
