<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/security.php';

if (!isset($_SESSION['username'])) {
  header('Location: login.php');
  exit;
}

// Try to load access control helper early (with fallback stubs if missing)
$__ac_store_loaded = false;
$__ac_store_paths = [
  __DIR__ . '/utils/access_control_store.php',
  __DIR__ . '/templates/utils/access_control_store.php',
  __DIR__ . '/dbusers.php',
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
  if (!function_exists('ac_get_user_db')) {
    function ac_get_user_db(): ?mysqli
    {
      // Prefer existing $conn from dbusers.php if present
      if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && !$GLOBALS['conn']->connect_errno) {
        return $GLOBALS['conn'];
      }
      $conn_local = null;
      $path = __DIR__ . '/dbusers.php';
      if (is_file($path)) {
        include $path; // defines $conn_local in this scope if present
      }
      if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        return $conn;
      }
      if (isset($conn_local) && $conn_local instanceof mysqli && !$conn_local->connect_errno) {
        return $conn_local;
      }
      return null;
    }
  }
  if (!function_exists('ac_ensure_access_table')) {
    function ac_ensure_access_table(mysqli $db): void
    {
      $sql = <<<SQL
      CREATE TABLE IF NOT EXISTS page_access (
        page_key VARCHAR(255) NOT NULL,
        role_id INT NOT NULL,
        allowed TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (page_key, role_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      SQL;
      $db->query($sql);
    }
  }
  if (!function_exists('ac_save_access_overrides')) {
    function ac_save_access_overrides(array $matrix): bool
    {
      $db = ac_get_user_db();
      if (!$db instanceof mysqli) {
        return false;
      }
      ac_ensure_access_table($db);

      $db->begin_transaction();
      $db->query("TRUNCATE TABLE page_access");

      $stmt = $db->prepare("INSERT INTO page_access (page_key, role_id, allowed) VALUES (?, ?, ?)");
      if (!$stmt) {
        $db->rollback();
        return false;
      }

      foreach ($matrix as $pageKey => $roles) {
        $roles = ac_normalize_roles($roles);
        if (empty($roles)) {
          $zero = 0;
          $allowed = 0;
          $stmt->bind_param('sii', $pageKey, $zero, $allowed);
          $stmt->execute();
          continue;
        }
        foreach ($roles as $roleId) {
          $allowed = 1;
          $stmt->bind_param('sii', $pageKey, $roleId, $allowed);
          $stmt->execute();
        }
      }

      $stmt->close();
      $db->commit();
      return true;
    }
  }
  if (!function_exists('ac_fetch_access_overrides')) {
    function ac_fetch_access_overrides(): array
    {
      $db = ac_get_user_db();
      if (!$db instanceof mysqli) {
        return [];
      }
      ac_ensure_access_table($db);
      $overrides = [];
      $res = $db->query("SELECT page_key, role_id, allowed FROM page_access");
      while ($res && ($row = $res->fetch_assoc())) {
        $key = trim($row['page_key'] ?? '');
        if ($key === '') {
          continue;
        }
        $allowed = (int) ($row['allowed'] ?? 0) === 1;
        $roleId = (int) ($row['role_id'] ?? 0);
        if (!$allowed && $roleId === 0) {
          $overrides[$key] = [];
          continue;
        }
        $overrides[$key][] = $roleId;
      }
      if ($res instanceof mysqli_result) {
        $res->free();
      }
      foreach ($overrides as $key => $roles) {
        $overrides[$key] = ac_normalize_roles($roles);
      }
      return $overrides;
    }
  }
}

require_once __DIR__ . '/templates/access_control.php';

// Force-load user DB connection even if access_control_store already included it in a local scope.
include __DIR__ . '/dbusers.php';
if (!isset($conn) || !$conn instanceof mysqli) {
  die("<p style='color:red; text-align:center;'>Database connection unavailable.</p>");
}

if (!isset($BASE_URL)) {
  $script_path = $_SERVER['SCRIPT_NAME'];
  $base = rtrim(dirname($script_path), '/\\');

  if ($base === DIRECTORY_SEPARATOR || $base === '.') {
    $BASE_URL = '';
  } else {
    $BASE_URL = $base;
  }
}

if (!isset($BASE_URL)) {
  $BASE_URL = '';
}

$role_id = (int) ($_SESSION['role_id'] ?? 0);
if (!ac_can_access_page($role_id, 'manage_access.php', $page_permissions_by_role)) {
  http_response_code(403);
  die("<p style='color:red; text-align:center;'>Access denied.</p>");
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Load roles list
$roles = [];
$res_roles = $conn->query("SELECT id, role FROM roles ORDER BY id ASC");
if ($res_roles instanceof mysqli_result) {
  while ($r = $res_roles->fetch_assoc()) {
    $roles[(int) $r['id']] = $r['role'];
  }
  $res_roles->free();
}

// Page catalog with defaults
$pages_catalog = [
  'Core' => [
    ['key' => 'dashboard.php', 'label' => 'Dashboard', 'desc' => 'Landing for all modules.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'manage_users.php', 'label' => 'Manage Users', 'desc' => 'Add, edit, or deactivate users.', 'default_roles' => [1, 2]],
    ['key' => 'manage_roles.php', 'label' => 'Manage Roles', 'desc' => 'Create or rename roles.', 'default_roles' => [1]],
    ['key' => 'manage_access.php', 'label' => 'Page Access', 'desc' => 'This matrix of page permissions.', 'default_roles' => [1]],
    ['key' => 'timesheet.php', 'label' => 'Timesheet', 'desc' => 'View and manage timesheets.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'geofence.php', 'label' => 'Punch-in Geofence', 'desc' => 'Set where staff can punch in.', 'default_roles' => [1, 2]],
    ['key' => 'inventory/dashboard.php', 'label' => 'Inventory Module', 'desc' => 'Entry point to Inventory.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inspection/dashboard.php', 'label' => 'Inspection Module', 'desc' => 'Entry point to Inspection.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'supplies/dashboard.php', 'label' => 'Supplies Module', 'desc' => 'Entry point to Supplies.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'receiving/dashboard.php', 'label' => 'Receiving Module', 'desc' => 'Entry point to Receiving.', 'default_roles' => [1, 2]],
  ],
  'Inventory' => [
    ['key' => 'inventory/manage_sku.php', 'label' => 'Manage Products', 'desc' => 'Create/edit products and SKUs.', 'default_roles' => [1, 2]],
    ['key' => 'inventory/manage_location.php', 'label' => 'Manage Locations', 'desc' => 'Create/edit pick/bin locations.', 'default_roles' => [1, 2]],
    ['key' => 'inventory/invSku.php', 'label' => 'Product', 'desc' => 'Stock counts by SKU.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/invLoc.php', 'label' => 'Location', 'desc' => 'Stock counts by location.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/pick_import_audit.php', 'label' => 'Pick Import Audit', 'desc' => 'Audit pick imports.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/history.php', 'label' => 'Transaction History', 'desc' => 'All inventory transactions.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/shelf.php', 'label' => 'Shelf', 'desc' => 'Shelf locations', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/pallet.php', 'label' => 'Pallet Management', 'desc' => 'Build or manage pallets.', 'default_roles' => [1]],
    ['key' => 'inventory/place.php', 'label' => 'Add Inventory', 'desc' => 'Put-away/place inventory.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/take.php', 'label' => 'Remove Inventory', 'desc' => 'Pick or remove inventory.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/transfer.php', 'label' => 'Transfer', 'desc' => 'Move stock between locations.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/ai_reports.php', 'label' => 'Inventory Reports', 'desc' => 'AI and standard inventory reports.', 'default_roles' => [1, 2]],
    ['key' => 'inventory/import.php', 'label' => 'Inventory Import', 'desc' => 'Import SKUs/locations/stock.', 'default_roles' => [1, 2]],
    ['key' => 'inventory/requests.php', 'label' => 'Inventory Requests', 'desc' => 'Request/approval flows.', 'default_roles' => [1, 2]],
    ['key' => 'inventory/verify_pick.php', 'label' => 'Pick Detail', 'desc' => 'Organize picks.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/receiving.php', 'label' => 'Receiving', 'desc' => 'Receive inventory.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/outwhse.php', 'label' => '3PL', 'desc' => 'Outbound warehouse management.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'inventory/pick_import_audit.php', 'label' => 'Import Audit', 'desc' => 'Audit pick imports.', 'default_roles' => [1, 2, 3, 4]],
  ],
  'Inspection' => [
    ['key' => 'inspection/inspection.php', 'label' => 'Inspection Form', 'desc' => 'Run inspections.', 'default_roles' => [1]],
    ['key' => 'inspection/ai_reports.php', 'label' => 'Inspection Reports', 'desc' => 'Reports for inspections.', 'default_roles' => [1]],
    ['key' => 'inspection/checklist_health_safety.php', 'label' => 'Health & Safety Checklist', 'desc' => 'PII-protected inspection checklists.', 'default_roles' => [1]],
    ['key' => 'inspection/checklist_lift_truck.php', 'label' => 'Lift Truck Checklist', 'desc' => 'Equipment-specific checklist.', 'default_roles' => [1]],
  ],
  'Supplies' => [
    ['key' => 'supplies/stock_on_hand.php', 'label' => 'Stock on Hand', 'desc' => 'Supply stock levels vs minimums.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'supplies/whse_sup.php', 'label' => 'Supplies Dashboard', 'desc' => 'Warehouse/office supplies.', 'default_roles' => [1, 2, 3, 4]],
    ['key' => 'supplies/ai_reports.php', 'label' => 'Supplies Reports', 'desc' => 'Reporting for supplies usage.', 'default_roles' => [1, 2]],
    ['key' => 'supplies/box_usage.php', 'label' => 'Box Usage', 'desc' => 'Box usage for supplies usage.', 'default_roles' => [1, 2]],
    ['key' => 'supplies/import.php', 'label' => 'Supplies Import', 'desc' => 'Import supplies data.', 'default_roles' => [1]],
  ],
];

$default_permissions = [];
foreach ($pages_catalog as $group) {
  foreach ($group as $page) {
    $default_permissions[$page['key']] = $page['default_roles'];
  }
}

$existing_overrides = ac_fetch_access_overrides();
if (empty($existing_overrides)) {
  // Seed the table with defaults on first run so DB becomes the source of truth ("god mode").
  ac_save_access_overrides($default_permissions);
  $existing_overrides = $default_permissions;
}

$status_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_access'])) {
  $posted_token = $_POST['csrf_token'] ?? '';
  if ($posted_token !== $_SESSION['csrf_token']) {
    http_response_code(403);
    die("<p style='color:red; text-align:center;'>Invalid CSRF token.</p>");
  }

  $submitted = $_POST['access'] ?? [];
  $matrix = [];
  foreach ($pages_catalog as $group) {
    foreach ($group as $page) {
      $key = $page['key'];
      $selected = isset($submitted[$key]) ? array_keys($submitted[$key]) : [];
      $matrix[$key] = array_map('intval', $selected);
    }
  }

  $saved = ac_save_access_overrides($matrix);
  $status_message = $saved ? 'Access matrix updated.' : 'Unable to save changes right now.';

  // Refresh effective map after save
  $page_permissions_by_role = ac_build_permissions($default_permissions, '');
  $existing_overrides = $matrix;
}

// Effective permissions (defaults merged with overrides)
$effective_permissions = ac_build_permissions($default_permissions, '');

$title = 'Manage Page Access';
$page_class = 'page-welcome';
$css = ['/refactor_css/dashboard.css'];

ob_start();
?>

<a alt="back to main" href="<?= $BASE_URL ?>/dashboard.php" class="btn btn-ghost back-to-main">
    ↩ Back to Main
</a>

<section style="margin-bottom:16px;">
  <div class="flex items-center justify-between" style="gap:12px; flex-wrap:wrap;">
    <div>
      <h1 class="m-0">Page Access</h1>
      <p class="text-muted m-0">Toggle which roles can reach each page. Unchecking all blocks the page.</p>
    </div>
    <div class="flex items-center" style="gap:8px;">
      <!-- <input type="search" id="accessSearch" class="input" placeholder="Search pages or roles" style="min-width:240px;"
        aria-label="Search pages"> -->
      <button class="btn primary save-access" type="submit" form="accessForm" name="save_access">Save changes</button>
    </div>
  </div>
  <?php if ($status_message): ?>
    <div class="alert alert-info" role="status" style="margin-top:12px;"><?= htmlspecialchars($status_message) ?></div>
  <?php endif; ?>
</section>

<form id="accessForm" method="POST" class="access-matrix">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
  <?php foreach ($pages_catalog as $section => $pages): ?>
    <section class="card access-section" data-section="<?= htmlspecialchars($section) ?>">
      <header class="access-section__header">
        <div>
          <h2 class="m-0"><?= htmlspecialchars($section) ?></h2>
          <p class="text-muted m-0">Manage access for <?= htmlspecialchars($section) ?> pages.</p>
        </div>
      </header>
      <div class="access-grid">
        <?php foreach ($pages as $page):
          $allowed_roles = $effective_permissions[$page['key']] ?? [];
          ?>
          <div class="access-item" data-page="<?= htmlspecialchars($page['key']) ?>">
            <div class="access-meta">
              <div class="access-title"><?= htmlspecialchars($page['label']) ?></div>
              <div class="access-key"><code><?= htmlspecialchars($page['key']) ?></code></div>
              <p class="text-muted m-0"><?= htmlspecialchars($page['desc']) ?></p>
            </div>
            <div class="access-roles">
              <?php foreach ($roles as $id => $name): ?>
                <?php
                $checked = in_array((int) $id, $allowed_roles, true);
                $input_id = 'chk_' . md5($page['key'] . '_' . $id);
                ?>
                <label class="access-role" for="<?= $input_id ?>">
                  <input type="checkbox" id="<?= $input_id ?>"
                    name="access[<?= htmlspecialchars($page['key']) ?>][<?= (int) $id ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                  <span><?= htmlspecialchars($name) ?> (<?= (int) $id ?>)</span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</form>

<style>
  .access-matrix {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .access-section__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
  }

  .access-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  }

  .access-item {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 12px;
    display: grid;
    gap: 8px;
    background: linear-gradient(180deg, #f9fafb 0%, #ffffff 100%);
  }

  .access-item:hover {
    border-color: #cdd5e0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
  }

  .access-meta {
    display: grid;
    gap: 4px;
  }

  .access-title {
    font-weight: 600;
    font-size: 15px;
  }

  .access-key {
    font-family: Consolas, monospace;
    font-size: 12px;
    color: #6b7280;
  }

  .access-roles {
    display: grid;
    gap: 4px;
  }

  .access-role {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
  }

  .access-role input {
    width: 16px;
    height: 16px;
  }

  .save-access {
    white-space: nowrap;
    position: fixed;
    font-weight: 700;
    letter-spacing: 0.01em;
    background: linear-gradient(135deg, #f97362, #f59e0b);
    color: #ffffff;
    border: 1px solid rgba(249, 115, 98, 0.65);
    box-shadow: 0 10px 22px rgba(249, 115, 98, 0.25);
    bottom: 3%;
    right: 3%;
    z-index: 9999;
  }

  @media (max-width: 600px) {
    .access-item {
      grid-template-columns: 1fr;
    }
  }
</style>

<script>
  const searchInput = document.getElementById('accessSearch');
  const items = Array.from(document.querySelectorAll('.access-item'));
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      const term = e.target.value.toLowerCase().trim();
      items.forEach(item => {
        const text = item.innerText.toLowerCase();
        item.style.display = term === '' || text.includes(term) ? '' : 'none';
      });
    });
  }
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';
