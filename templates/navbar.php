<?php
// templates/navbar.php

$BASE_URL = $BASE_URL ?? rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$username = $username ?? ($_SESSION['username'] ?? '');
$role_id = $role_id ?? ($_SESSION['role_id'] ?? null);

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$u = static fn($file) => rtrim($BASE_URL, '/') . '/' . ltrim($file, '/');

$REQ_FILE = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$active = static fn($file) => $REQ_FILE === $file ? ' is-active' : '';
$isCurrent = static fn($file) => $REQ_FILE === $file ? ' aria-current="page"' : '';

$brand_link = 'dashboard.php'; // brand always links to dashboard

$menu_common = [
];

$menu_by_role = [
    1 => [
        'manage_roles.php' => 'Roles',
        'manage_users.php' => 'Users',
        'manage_sku.php' => 'Manage Sku',
        'manage_location.php' => 'Manage Location',
        'invSku.php' => 'Location Details',
        'invLoc.php' => 'SKU by Location',
        'history.php' => 'Transaction History',
        'place.php' => 'Place',
        'take.php' => 'Take',
        'transfer.php' => 'Transfer',
        'ai_reports.php' => 'Reports',
    ],
    2 => [
        'invSku.php' => 'SKU',
        'invLoc.php' => 'SKU by Location',
        'history.php' => 'Transaction History',
        'place.php' => 'Place',
        'take.php' => 'Take',
        'transfer.php' => 'Transfer',
    ],
];

$menu_left = ($menu_by_role[$role_id] ?? []) + $menu_common;
?>

<nav class="navbar">
    <div class="container flex items-center justify-between p-4">

        <!-- Left: Brand -->
        <div class="nav-left flex items-center g-4">
            <a class="brand<?= $active($brand_link) ?>" href="<?= $u($brand_link) ?>">TPB</a>
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
            <a class="btn btn--ghost" href="<?= $u('logout.php') ?>">Logout</a>
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

        <a class="mobile-link" href="<?= $u('logout.php') ?>">Sign out</a>
    </div>
</div>