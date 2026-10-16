<?php
// templates/navbar.php

$role_id = (int) ($role_id ?? ($_SESSION['role_id'] ?? 0));

// include __DIR__ . '/access_control.php';
require_once __DIR__ . '/../dbusers.php';

$inventory_conn = $conn ?? null;

$user_conn = $conn ?? null;
if ($inventory_conn instanceof mysqli) {
    $conn = $inventory_conn;
}
$user_db_name = null;
// Debug helpers
$__navbar_last_profile_row = null;
$__navbar_last_query = null;
$__navbar_last_query_error = null;
$__navbar_probe_rows = null;
$__navbar_probe_count = null;
if ($user_conn instanceof mysqli) {
    if ($resDb = $user_conn->query('SELECT DATABASE() AS db')) {
        if ($resDb instanceof mysqli_result) {
            $rowDb = $resDb->fetch_assoc();
            $user_db_name = $rowDb['db'] ?? null;
            $resDb->free();
        }
    } elseif ($user_conn->error) {
        error_log('[navbar] failed to read current database: ' . $user_conn->error);
    }
    // If we're still pointed at the inventory DB, switch to the users DB.
    if ($user_db_name && strcasecmp($user_db_name, 'xserqhmy_users') !== 0) {
        if (!$user_conn->select_db('xserqhmy_users')) {
            error_log('[navbar] failed to switch to users DB: ' . $user_conn->error);
        } else {
            $user_db_name = 'xserqhmy_users';
        }
    }
    // Debug probe: count and a few rows to verify visibility
    if ($resProbe = $user_conn->query("SELECT COUNT(*) AS c FROM users")) {
        if ($resProbe instanceof mysqli_result) {
            $rowProbe = $resProbe->fetch_assoc();
            $__navbar_probe_count = $rowProbe['c'] ?? null;
            $resProbe->free();
        }
    }
    if ($resRows = $user_conn->query("SELECT id, username, email, phone_number, phone_number AS phone_fallback, birthday FROM users ORDER BY id ASC LIMIT 5")) {
        if ($resRows instanceof mysqli_result) {
            $__navbar_probe_rows = $resRows->fetch_all(MYSQLI_ASSOC);
            $resRows->free();
        }
    }
}
$session_username = $_SESSION['username'] ?? '';
$session_user_id = (int) ($_SESSION['user_id'] ?? 0);

$page_map = $page_permissions_by_role ?? [];
$menu_left = [];
$nav_labels = $NAVBAR_DISPLAY_MAP ?? [];

// --- Filter Menu Links for the current user ---

// Iterate through the map and only include links the user is allowed to see
foreach ($page_map as $file => $allowed_roles) {
    if (in_array($file, $EXCLUDED_MENU_FILES, true)) {
        continue; // Skip this file - it's explicitly excluded
    }

    // Keep admin pages inside the settings drawer, not in the main nav.
    if (in_array($file, ['manage_users.php', 'manage_roles.php', 'manage_access.php'], true)) {
        continue;
    }

    // Only render items that have a navbar label defined
    if (!isset($nav_labels[$file])) {
        continue;
    }

    if (in_array($role_id, $allowed_roles, true)) {
        // Find the custom label, falling back to the old derived label if not found.
        $label = $nav_labels[$file] ?? ucwords(str_replace(['_', '.php'], [' ', ''], $file));

        $menu_left[$file] = $label;
    }
}

// --- 3. Remaining helpers (as before) ---

$BASE_URL = $BASE_URL ?? (function () {
    $script_path = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(dirname($script_path), '/\\');
    return ($base === DIRECTORY_SEPARATOR || $base === '.') ? '' : $base;
})();
$username = $username ?? $session_username;

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$u = static fn($file) => rtrim($BASE_URL, '/') . '/' . ltrim($file, '/');

$REQ_FILE = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$active = static fn($file) => $REQ_FILE === $file ? ' is-active' : '';
$isCurrent = static fn($file) => $REQ_FILE === $file ? ' aria-current="page"' : '';

$brand_link = 'dashboard.php';
unset($menu_left['dashboard.php']);

$can_manage_users = in_array($role_id, $page_map['manage_users.php'] ?? [], true);
$can_manage_roles = in_array($role_id, $page_map['manage_roles.php'] ?? [], true);
$can_manage_access = in_array($role_id, $page_map['manage_access.php'] ?? [], true);


// --- Handle Profile Update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_phone = trim($_POST['phone_number'] ?? '');
    $new_birthday = trim($_POST['birthday'] ?? '');

    if ($user_conn instanceof mysqli && $session_user_id > 0) {
        $stmt = $user_conn->prepare("UPDATE users SET phone_number = ?, birthday = ? WHERE id = ?");
        $stmt->bind_param("ssi", $new_phone, $new_birthday, $session_user_id);

        if ($stmt->execute()) {
            // Update local variables so the UI reflects changes immediately
            $user_profile['phone_number'] = $new_phone;
            $user_profile['birthday'] = $new_birthday;
            $phone = $new_phone;
            // Re-format birthday for display
            try {
                $birthday = !empty($new_birthday) ? (new DateTime($new_birthday))->format('F j, Y') : 'N/A';
            } catch (Exception $e) {
                $birthday = $new_birthday;
            }
        } else {
            error_log("Update failed: " . $user_conn->error);
        }
        $stmt->close();
    }
}

$user_profile = [
    'username' => $session_username,
    'first_name' => $_SESSION['first_name'] ?? ($_SESSION['firstName'] ?? $_SESSION['firstname'] ?? ''),
    'last_name' => $_SESSION['last_name'] ?? '',
    'email' => $_SESSION['email'] ?? '',
    'phone_number' => $_SESSION['phone_number'] ?? ($_SESSION['phone'] ?? ''),
    'birthday' => $_SESSION['birthday'] ?? ''
];

// For debugging, capture the raw DB row we fetched (if any).
if ($user_conn instanceof mysqli) {
    try {
        // Ensure we have a user id; fall back to username lookup if missing.
        // Simple direct query (more reliable on hosts without mysqlnd).
        $esc_username = $user_conn->real_escape_string($session_username);
        $sql = $session_user_id > 0
            ? "SELECT username, first_name, last_name, email, phone_number, phone_number AS phone_fallback, birthday, id FROM users WHERE id = " . (int) $session_user_id . " LIMIT 1"
            : "SELECT username, first_name, last_name, email, phone_number, phone_number AS phone_fallback, birthday, id FROM users WHERE username = '{$esc_username}' LIMIT 1";
        $__navbar_last_query = $sql ?? null;
        $row = null;
        if ($res = $user_conn->query($sql)) {
            if ($res instanceof mysqli_result) {
                $row = $res->fetch_assoc();
                $res->free();
            }
        } else {
            $__navbar_last_query_error = $user_conn->error;
            error_log('[navbar] direct query failed: ' . $user_conn->error);
        }

        if (is_array($row)) {
            $__navbar_last_profile_row = $row;
            $user_profile['username'] = $row['username'] ?? $user_profile['username'];
            $user_profile['first_name'] = $row['first_name'] ?? $user_profile['first_name'];
            $user_profile['last_name'] = $row['last_name'] ?? $user_profile['last_name'];
            $user_profile['email'] = $row['email'] ?? $user_profile['email'];
            $user_profile['phone_number'] = $row['phone_number'] ?? ($row['phone_fallback'] ?? $user_profile['phone_number']);
            $user_profile['birthday'] = $row['birthday'] ?? $user_profile['birthday'];

            // Backfill session id if we only had a username.
            if ($session_user_id <= 0 && isset($row['id'])) {
                $session_user_id = (int) $row['id'];
                if ($session_user_id > 0) {
                    $_SESSION['user_id'] = $session_user_id;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[navbar] Failed to load user profile: ' . $e->getMessage());
    }
}

if (!empty($user_profile['username'])) {
    $username = $user_profile['username'];
}

$full_name = trim(trim($user_profile['first_name'] ?? '') . ' ' . trim($user_profile['last_name'] ?? ''));
$birthday_display = '';
if (!empty($user_profile['birthday'])) {
    try {
        $birthday_display = (new DateTime($user_profile['birthday']))->format('F j, Y');
    } catch (Throwable $e) {
        $birthday_display = $user_profile['birthday'];
    }
}

$profile_data = [
    'name' => $full_name ?: ($_SESSION['full_name'] ?? $user_profile['username'] ?? $username ?? 'N/A'),
    'email' => $user_profile['email'] ?: 'N/A',
    'phone_number' => $user_profile['phone_number'] ?: 'N/A',
    'birthday' => $birthday_display ?: 'N/A',
    'profile_image' => $_SESSION['profile_image'] ?? ''
];

$name = $profile_data['name'];
$email = $profile_data['email'];
$phone = $profile_data['phone_number'];
$birthday = $profile_data['birthday'];
$image_path = $profile_data['profile_image'] ?: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120' viewBox='0 0 120 120'%3E%3Crect width='120' height='120' rx='16' fill='%23e2e8f0'/%3E%3Ccircle cx='60' cy='44' r='24' fill='%2399a7bf'/%3E%3Cpath fill='%2399a7bf' d='M30 108c4-24 56-24 60 0'/%3E%3C/svg%3E";

// Debug: emit the hydrated profile data to the browser console for troubleshooting missing fields.
$debug_payload = [
    'session_username' => $session_username,
    'session_user_id' => $session_user_id,
    'user_profile' => $user_profile,
    'profile_data' => $profile_data,
    'db_row' => $__navbar_last_profile_row,
    'conn_ok' => $user_conn instanceof mysqli,
    'conn_errno' => $user_conn instanceof mysqli ? $user_conn->connect_errno : 'no-conn',
    'conn_error' => $user_conn instanceof mysqli ? $user_conn->connect_error : 'no-conn',
    'user_db' => $user_db_name,
    'last_query' => $__navbar_last_query,
    'last_query_error' => $__navbar_last_query_error,
    'probe_count' => $__navbar_probe_count,
    'probe_rows' => $__navbar_probe_rows
];
?>

<nav class="navbar">
    <div class="container flex items-center justify-between p-4">

        <!-- Left: Brand -->
        <div class="nav-left flex items-center g-4">
            <a href="<?= $u('dashboard.php') ?>">
                <span class='brand'>OPS App</span>
            </a>
        </div>

        <!-- Right: User and logout (desktop) -->
        <div class="nav-right flex items-center g-4 desktop-only">
            <span class="welcome text-muted">Welcome, <?= $h($username) ?></span>
            <!-- <button class="btn btn--ghost" type="button" data-notifications-launch aria-haspopup="dialog"
                aria-controls="notificationsDrawer" aria-expanded="false" title="Notifications">
                <span aria-hidden="true">🔔</span>
                <span class="sr-only">Open profile and settings panel</span>
            </button> -->
            <button class="btn btn--ghost" type="button" data-settings-launch aria-haspopup="dialog"
                aria-controls="settingsDrawer" aria-expanded="false" title="Profile and settings">
                <span aria-hidden="true">⚙️</span>
                <span class="sr-only">Open profile and settings panel</span>
            </button>
            <a class="btn btn--ghost" href="<?= $u('logout.php') ?>" alt="logout">➜]</a>
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
        <button class="mobile-menu__close" type="button" aria-label="Close menu" id="menuCloseBtn">&times;</button>

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
        <a class="mobile-link" href="#" role="button" data-settings-launch aria-haspopup="dialog"
            aria-controls="settingsDrawer" aria-expanded="false">
            Settings
        </a>
        <a class="mobile-link" href="<?= $u('logout.php') ?>">Logout</a>
    </div>
</div>

<aside id="settingsDrawer" class="app-drawer settings-drawer" aria-hidden="true" data-open="false" role="dialog"
    aria-label="User profile and settings">
    <div class="app-drawer__panel">
        <header class="settings-drawer__hdr">
            <div class="settings-drawer__title">
                <p class="text-muted m-0">Signed in</p>
                <h2 class="m-0"><?= $h($name) ?></h2>
                <small class="text-muted"><?= $h($email) ?></small>
            </div>
            <button class="btn btn--ghost" type="button" id="settingsCloseBtn"
                aria-label="Close profile panel">&times;</button>
        </header>

        <section class="profile-card">
            <div class="profile-summary">
                <img src="<?= $h($image_path) ?>" alt="Profile picture" class="profile-avatar">
                <div class="profile-meta">
                    <div class="profile-name"><?= $h($name) ?></div>
                    <div class="profile-email text-muted"><?= $h($email) ?></div>
                </div>
            </div>

            <form method="POST" action="" id="profileEditForm">
                <input type="hidden" name="update_profile" value="1">

                <div class="bio-grid">
                    <div class="bio-item">
                        <span>Name</span>
                        <span><?= $h($name) ?></span>
                    </div>

                    <div class="bio-item">
                        <label for="phone_number">Phone Number</label>
                        <?php if (!empty($user_profile['phone_number'])): ?>
                            <?php
                            $clean_phone = preg_replace('/[^0-9]/', '', $user_profile['phone_number']);
                            $formatted_phone = preg_replace('/(\d{3})(\d{3})(\d{4})/', '$1-$2-$3', $clean_phone);
                            ?>
                            <input type="text" name="phone_number" id="phone_field" value="<?= $h($formatted_phone) ?>"
                                readonly class="editable-input">
                        <?php else: ?>
                            <span>N/A</span>
                        <?php endif; ?>
                    </div>


                    <div class="bio-item">
                        <label for="birthday">Birthday</label>
                        <input type="date" name="birthday" id="birthday_field"
                            value="<?= $h($user_profile['birthday']) ?>" readonly class="editable-input">
                    </div>
                </div>

                <div class="profile-actions" style="margin-top: 15px;">
                    <button type="button" id="editBtn" class="btn secondary" style="width: 100%;">Edit Profile</button>
                    <div id="saveGroup" style="display: none; gap: 10px;">
                        <button type="submit" class="btn primary" style="flex: 1;">Save Changes</button>
                        <button type="button" id="cancelBtn" class="btn btn--ghost"
                            style="flex: 1; border: 1px solid #ddd;">Cancel</button>
                    </div>
                    <button type="button" class="btn btn--ghost" data-password-modal-open
                        style="width: 100%; margin-top: 10px; border: 1px solid #ddd;">Change Password</button>
                    <button type="button" class="btn btn--ghost" data-pin-modal-open
                        style="width: 100%; margin-top: 10px; border: 1px solid #ddd;">Change PIN</button><!-- js/punch-pin.js relabels to "Set PIN" if none -->
                </div>
            </form>

            <hr class="settings-separator">
        </section>

        <?php
        // My time: the signed-in user's punches for the current work week (Sunday to Saturday),
        // from punch_records (see utils/timeclock.php).
        require_once __DIR__ . '/../utils/timeclock.php';
        $my_today = new DateTimeImmutable('today');
        $my_week_start = $my_today->modify('-' . $my_today->format('w') . ' days');
        $my_week_end = $my_week_start->modify('+6 days');
        $my_punch_in = null;
        $my_punches = [];
        if ($user_conn instanceof mysqli && $session_user_id > 0) {
            try {
                $my_open = tc_open_punch($user_conn, $session_user_id);
                $my_punch_in = $my_open['punchedInAt'] ?? null;
                $my_punches = tc_fetch_punches(
                    $user_conn,
                    [$session_user_id],
                    $my_week_start->format('Y-m-d 00:00:00'),
                    $my_week_end->modify('+1 day')->format('Y-m-d 00:00:00')
                );
            } catch (Throwable $e) {
                error_log('[navbar] My Time failed: ' . $e->getMessage()); // e.g. punch_records not created yet
            }
        }
        $my_by_day = [];
        foreach ($my_punches as $p) {
            [, $paid, , $open] = tc_punch_minutes($p);
            $d = substr($p['in'], 0, 10);
            $my_by_day[$d]['mins'] = ($my_by_day[$d]['mins'] ?? 0) + $paid;
            $my_by_day[$d]['first'] = $my_by_day[$d]['first'] ?? $p['in'];
            $my_by_day[$d]['last'] = $open ? null : $p['out'];
        }
        $my_time_days = [];
        $my_time_total = 0;
        for ($i = 0; $i < 7; $i++) {
            $day = $my_week_start->modify("+{$i} days");
            $key = $day->format('Y-m-d');
            $future = $day > $my_today;
            $worked = $my_by_day[$key] ?? null;
            $mins = $worked['mins'] ?? 0;
            $my_time_total += $mins;
            $range = '';
            if ($worked) {
                $range = date('g:ia', strtotime($worked['first'])) . ' – '
                    . ($worked['last'] ? date('g:ia', strtotime($worked['last'])) : 'now');
            }
            $my_time_days[] = [
                'label' => $day->format('D M j'),
                'range' => $range,
                'hours' => ($future && !$worked) ? null : $mins / 60,
                'today' => $day == $my_today,
            ];
        }
        ?>
        <section class="settings-links">
            <div class="settings-links__hdr my-time__hdr">
                <div class="my-time__title">
                    <h3 class="m-0">My Time</h3>
                    <span class="my-time__status<?= $my_punch_in ? ' is-in' : '' ?>" data-punch-status>
                        <?= $my_punch_in ? 'Clocked in · ' . $h(date('g:ia', strtotime($my_punch_in))) : 'Clocked out' ?>
                    </span>
                </div>
                <small class="text-muted"><?= $h($my_week_start->format('M j') . ' – ' . $my_week_end->format('M j')) ?></small>
            </div>
            <ul class="my-time">
                <?php foreach ($my_time_days as $d): ?>
                    <li class="my-time__row<?= $d['hours'] ? '' : ' is-off' ?><?= $d['today'] ? ' is-today' : '' ?>">
                        <span class="my-time__date"><?= $h($d['label']) ?></span>
                        <span class="my-time__range"><?= $h($d['range']) ?></span>
                        <span class="my-time__hours"><?= $d['hours'] === null ? '—' : number_format($d['hours'], 2) . 'h' ?></span>
                    </li>
                <?php endforeach; ?>
                <li class="my-time__total">
                    <span>Total</span>
                    <strong><?= number_format($my_time_total / 60, 2) ?>h</strong>
                </li>
            </ul>
            <hr class="settings-separator">
        </section>

        <?php if ($can_manage_users || $can_manage_roles || $can_manage_access): ?>
            <section class="settings-links">
                <div class="settings-links__hdr">
                    <h3 class="m-0">Admin</h3>
                </div>
                <div class="settings-links__list">
                    <a class="settings-link<?= $active('timesheet.php') ?>" href="<?= $u('timesheet.php') ?>"
                        <?= $isCurrent('timesheet.php') ?>>Open timesheet</a>
                    <?php if (in_array($role_id, [1, 2], true)): // GEOFENCE_EDITOR_ROLES ?>
                        <a class="settings-link<?= $active('geofence.php') ?>" href="<?= $u('geofence.php') ?>"
                            <?= $isCurrent('geofence.php') ?>>Geofence</a>
                    <?php endif; ?>
                    <?php if ($can_manage_users): ?>
                        <a class="settings-link<?= $active('manage_users.php') ?>" href="<?= $u('manage_users.php') ?>"
                            <?= $isCurrent('manage_users.php') ?>>Manage Users</a>
                    <?php endif; ?>
                    <?php if ($can_manage_roles): ?>
                        <a class="settings-link<?= $active('manage_roles.php') ?>" href="<?= $u('manage_roles.php') ?>"
                            <?= $isCurrent('manage_roles.php') ?>>Manage Roles</a>
                    <?php endif; ?>
                    <?php if ($can_manage_access): ?>
                        <a class="settings-link<?= $active('manage_access.php') ?>" href="<?= $u('manage_access.php') ?>"
                            <?= $isCurrent('manage_access.php') ?>>Page Access</a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
    <button class="app-drawer__backdrop" id="settingsBackdrop" aria-label="Close profile panel"></button>
</aside>

<!-- Password modal -->
<div id="passwordModal" class="modal" data-open="false" aria-hidden="true"
    style="position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:1200;">
    <button type="button" class="modal__backdrop" data-password-modal-close aria-label="Close dialog"
        style="position:absolute; inset:0; background:rgba(0,0,0,0.35); border:none; padding:0; margin:0;"></button>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="passwordModalTitle"
        style="position:relative; background:#fff; color:#111827; padding:22px 20px 18px; width:min(420px, 92vw); border-radius:12px; box-shadow:0 22px 60px rgba(15,23,42,0.30); border:1px solid rgba(148,163,184,0.35); max-height:90vh; overflow:auto;">
        <div class="modal__header"
            style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
            <div>
                <p class="text-muted m-0" style="margin:0; font-size:0.9rem;">Security</p>
                <h3 id="passwordModalTitle" style="margin:2px 0 0;">Change Password</h3>
            </div>
            <button type="button" class="btn btn--ghost" data-password-modal-close aria-label="Close dialog"
                style="border:none; background:transparent; font-size:1.2rem; line-height:1; cursor:pointer;">&times;</button>
        </div>

        <div id="passwordChangeAlertModal" class="settings-alert" role="status" aria-live="polite" hidden
            style="margin:12px 0; padding:10px 12px; border-radius:8px; border:1px solid transparent;"></div>

        <form id="passwordChangeFormModal" class="password-form" method="post" action="<?= $u('change_password.php') ?>"
            style="display:grid; gap:12px; margin-top:8px;">
            <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token'] ?? ($csrfToken ?? '')) ?>">

            <label class="form-field" style="display:grid; gap:6px;">
                <span class="text-muted">Current password</span>
                <input type="password" name="current_password" autocomplete="current-password" required>
            </label>

            <label class="form-field" style="display:grid; gap:6px;">
                <span class="text-muted">New password</span>
                <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
            </label>

            <label class="form-field" style="display:grid; gap:6px;">
                <span class="text-muted">Confirm new password</span>
                <input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
            </label>

            <button class="btn secondary" type="submit" style="width:100%;">Update Password</button>
            <p class="text-muted small m-0" style="margin-top:-6px;">Use at least 8 characters.</p>
        </form>
    </div>
</div>

<!-- Set/Change PIN modal: templates/punch_clock.php (included by the layout) -->
