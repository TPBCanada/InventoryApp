<?php
// templates/navbar.php

$role_id = $_SESSION['role_id'] ?? null;
$username = $_SESSION['username'] ?? '';

include __DIR__ . '/access_control.php';

$page_map = $page_permissions_by_role ?? [];
$nav_labels = $NAVBAR_DISPLAY_MAP ?? [];
$menu_left = [];


// --- 2. Filter Menu Links for the current user ---

foreach ($page_map as $file => $allowed_roles) {
    if (in_array($file, $EXCLUDED_MENU_FILES, true)) {
        continue;
    }
    // Keep admin pages out of the inventory nav
    if (in_array($file, ['manage_users.php', 'manage_roles.php', 'manage_access.php', 'pallet.php'], true)) {
        continue;
    }

    if (in_array($role_id, $allowed_roles, true)) {
        $label = $nav_labels[$file] ?? ucwords(str_replace(['_', '.php'], [' ', ''], $file));
        $menu_left[$file] = $label;
    }
}


// Remaining helpers (CRITICAL: Redefining $u to be smart) ---

$username = $username ?? ($_SESSION['username'] ?? '');
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$u = static fn($file) => rtrim(APP_ROOT_URL, '/') . '/' . ltrim($file, '/');

$REQ_FILE = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$active = static fn($file) => $REQ_FILE === $file ? ' is-active' : '';
$isCurrent = static fn($file) => $REQ_FILE === $file ? ' aria-current="page"' : '';

// Ensure the brand link uses the correct path string for lookups later
$brand_link = 'dashboard.php';
unset($menu_left['dashboard.php']);

?>

<nav class="navbar">
    <div class="container">

        <!-- Left: Brand -->
        <div class="nav-left">
            <!-- $u('dashboard.php') now resolves to /inventory/dashboard.php -->
            <a class="brand<?= $active($brand_link) ?>" href="<?= $u($brand_link) ?>">Inventory</a>
        </div>

        <!-- Center: Main navigation (desktop) -->
        <div class="nav-center nav-links desktop-only">
            <?php foreach ($menu_left as $file => $label): ?>
                <a class="nav-link<?= $active($file) ?>" href="<?= $u($file) ?>" <?= $isCurrent($file) ?>>
                    <?php
                    echo $h(str_replace(' ', "\n", $label));
                    ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Right: User and logout (desktop) -->
        <div class="nav-right desktop-only">
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