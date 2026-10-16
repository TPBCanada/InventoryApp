<?php
// templates/navbar.php
// NOTE: This file is updated to dynamically build link URLs based on module prefix.

// --- 1. Load Permissions Map and user info ---

$role_id = (int) ($role_id ?? ($_SESSION['role_id'] ?? 0));

// Same convention as inventory/templates/navbar.php: APP_ROOT_URL is THIS module's folder
// (e.g. /dev/supplies, set by utils/config_init.php from the running script), so links are
// APP_ROOT_URL/<file>. Don't append '/supplies' again -- that produced /dev/supplies/supplies/.
if (!defined('APP_ROOT_URL')) {
    define('APP_ROOT_URL', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'));
}
if (!defined('SUPPLIES_MODULE_URL')) {
    define('SUPPLIES_MODULE_URL', rtrim(APP_ROOT_URL, '/'));
}


require_once __DIR__ . '/access_control.php';

$page_map = $page_permissions_by_role ?? [];
$menu_left = [];
$nav_labels = $NAVBAR_DISPLAY_MAP ?? [];

// --- 2. Filter Menu Links for the current user ---

foreach ($page_map as $file => $allowed_roles) {
    if (in_array($file, $EXCLUDED_MENU_FILES, true)) { 
        continue;
    }
    
    if (in_array($role_id, $allowed_roles, true)) {
        $label = $nav_labels[$file] ?? ucwords(str_replace(['_', '.php'], [' ', ''], $file));
        $menu_left[$file] = $label;
    }
}

// --- 3. Define the list of pages that belong to the /dev/ root ---
// Since ALL pages seem to belong to the Inventory module, we empty this array.
// Any file listed here will use APP_ROOT_URL (e.g., /dev/page.php).
$ROOT_PAGES = []; 


// --- 4. Remaining helpers (CRITICAL: Redefining $u to be smart) ---

$username = $username ?? ($_SESSION['username'] ?? '');

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/**
 * Smart URL generation: Assumes all pages are in the INVENTORY module 
 * unless explicitly listed in $ROOT_PAGES.
 * @param string $file The filename (e.g., 'dashboard.php')
 * @return string The absolute URL (e.g., '/dev/inventory/dashboard.php')
 */
$u = static function($file) use ($ROOT_PAGES) {
    // Only use APP_ROOT_URL for the explicitly defined management pages.
    if (in_array($file, $ROOT_PAGES, true)) {
        return APP_ROOT_URL . '/' . ltrim($file, '/');
    }
    
    // Default: All other files (including all management pages now) 
    // belong in the Inventory Module.
    return SUPPLIES_MODULE_URL . '/' . ltrim($file, '/');
};


$REQ_FILE = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');

// Active state check remains the same based on simple filename.
$active = static fn($file) => $REQ_FILE === $file ? ' is-active' : '';
$isCurrent = static fn($file) => $REQ_FILE === $file ? ' aria-current="page"' : '';

// Ensure the brand link uses the correct path string for lookups later
$brand_link = 'dashboard.php'; 
unset($menu_left['dashboard.php']); 

?>
 
<nav class="navbar">
    <div class="container flex items-center justify-between p-4">

        <!-- Left: Brand -->
        <div class="nav-left flex items-center g-4">
            <a class="brand<?= $active($brand_link) ?>" href="<?= $u($brand_link) ?>">SUPPLIES</a>
        </div>

        <!-- Center: Main navigation (desktop) -->
        <div class="nav-center flex items-center g-4 nav-links desktop-only">
            <?php foreach ($menu_left as $file => $label): ?>
                <a class="nav-link<?= $active($file) ?>" href="<?= $u($file) ?>" <?= $isCurrent($file) ?>><?= $h($label) ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Right: User and logout (desktop) -->
        <div class="nav-right flex items-center g-4 desktop-only">
            <span class="welcome text-muted">Welcome, <?= $h($username) ?></span>
            <a class="btn btn--ghost" href="<?= $u('../logout.php') ?>">Logout</a>
        </div>

        <!-- Hamburger (mobile) -->
        <button class="hamburger" type="button" aria-label="Open menu" aria-controls="site-menu" aria-expanded="false"
            id="hamburgerBtn">
            <svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 6h18M3 12h18M3 18h18" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" />
            </svg>
        </button>

    </div>
</nav>

<!-- Mobile slide-out menu -->
<div class="mobile-menu" id="site-menu" hidden>
    <div class="mobile-menu__inner">
        <button class="mobile-menu__close" type="button" aria-label="Close menu" id="menuCloseBtn">×</button>

        <!-- Signed-in user -->
        <div class="mobile-user">
            <div class="mobile-user__name">Signed in as <strong><?= $h($username) ?></strong></div>
        </div>

        <!-- Links (reuse the same PHP array as desktop) -->
        <nav class="mobile-nav">
            <?php foreach ($menu_left as $file => $label): ?>
                <a class="mobile-link<?= $active($file) ?>" href="<?= $u($file) ?>" <?= $isCurrent($file) ?>><?= $h($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <hr>

        <a class="mobile-link" href="<?= $u('../logout.php') ?>">Sign out</a>
    </div>
</div>
