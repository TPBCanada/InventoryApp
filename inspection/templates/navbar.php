<?php
// templates/navbar.php

$role_id = $role_id ?? ($_SESSION['role_id'] ?? null);

include __DIR__ . '/access_control.php';

$page_map = $page_permissions_by_role ?? [];
if (!is_array($page_map)) {
    $page_map = [];
}

if (!defined('APP_ROOT_URL')) {
    define('APP_ROOT_URL', '/dev');
}

$menu_left = [];
$nav_labels = $NAVBAR_DISPLAY_MAP ?? [];
$nav_routes = $NAVBAR_ROUTE_MAP ?? [];
$MODULE_BASE = APP_ROOT_URL . '/inspection';

$routeFor = static function ($file) use ($nav_routes) {
    return $nav_routes[$file] ?? $file;
};


foreach ($page_map as $file => $allowed_roles) {
    if (in_array($file, $EXCLUDED_MENU_FILES, true)) {
        continue;
    }

    if (in_array($role_id, $allowed_roles, true)) {
        $label = $nav_labels[$file] ?? ucwords(str_replace(['_', '.php'], [' ', ''], $file));
        $menu_left[$file] = $label;
    }
}

$ROOT_PAGES = [];

$display_username = (string) ($_SESSION['username'] ?? ($username ?? ''));

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$u = static function ($file) use ($MODULE_BASE) {
    $file = (string) $file;

    // keep query string
    $path = $file;
    $qs = '';
    if (strpos($file, '?') !== false) {
        [$path, $q] = explode('?', $file, 2);
        $qs = '?' . $q;
    }

    $clean = ltrim($path, '/');

    // ../logout.php goes to site root
    if (strpos($clean, '..') === 0) {
        return '/' . ltrim($clean, './') . $qs;
    }

    // if route already starts with "inspection/", don't add twice
    $module = trim($MODULE_BASE, '/'); // "inspection"
    if ($module !== '' && strpos($clean, $module . '/') === 0) {
        return '/' . $clean . $qs;     // already module-prefixed
    }

    return rtrim($MODULE_BASE, '/') . '/' . $clean . $qs;
};

$REQ_FILE = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$REQ_TYPE = (string) ($_GET['type'] ?? '');

$isRouteActive = static function ($file) use ($REQ_FILE, $REQ_TYPE, $routeFor): bool {
    $route = (string) $routeFor($file);
    $routePath = (string) parse_url($route, PHP_URL_PATH);
    $routeFile = basename($routePath);
    if ($REQ_FILE !== $routeFile) {
        return false;
    }
    parse_str((string) parse_url($route, PHP_URL_QUERY), $routeQuery);
    if (isset($routeQuery['type'])) {
        return $REQ_TYPE === (string) $routeQuery['type'];
    }
    return true;
};

$active = static fn($file) => $isRouteActive($file) ? ' is-active' : '';
$isCurrent = static fn($file) => $isRouteActive($file) ? ' aria-current="page"' : '';

// Ensure the brand link uses the correct path string for lookups later
$brand_link = 'dashboard.php';
unset($menu_left['dashboard.php']);

?>

<nav class="navbar">
    <div class="container flex items-center justify-between p-4">

        <!-- Left: Brand -->
        <div class="nav-left flex items-center g-4">
            <a class="brand<?= $active($brand_link) ?>" href="<?= $u($brand_link) ?>">Inspection</a>
        </div>

        <!-- Center: Main navigation (desktop) -->
        <div class="nav-center flex items-center g-4 nav-links desktop-only">
            <?php foreach ($menu_left as $file => $label): ?>
                <a class="nav-link<?= $active($file) ?>" href="<?= $u($routeFor($file)) ?>" <?= $isCurrent($file) ?>><?= $h($label) ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Right: User and logout (desktop) -->
        <div class="nav-right flex items-center g-4 desktop-only">
            <span class="welcome text-muted">Welcome, <?= $h($display_username) ?></span>
            <a class="btn btn--ghost" href="../logout.php">Logout</a>
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
            <div class="mobile-user__name">Signed in as <strong><?= $h($display_username) ?></strong></div>
        </div>

        <!-- Links (reuse the same PHP array as desktop) -->
        <nav class="mobile-nav">
            <?php foreach ($menu_left as $file => $label): ?>
                <a class="mobile-link<?= $active($file) ?>" href="<?= $u($routeFor($file)) ?>" <?= $isCurrent($file) ?>><?= $h($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <hr>

        <a class="mobile-link" href="<?= $u('../logout.php') ?>">Sign out</a>
    </div>
</div>