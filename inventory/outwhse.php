<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/utils/helpers.php';

if (!isset($_SESSION['username'])) {
   header('Location: ../login.php');
   exit;
}

$username = (string) ($_SESSION['username'] ?? '');
$role_id = (int) ($_SESSION['role_id'] ?? 0);

require_once __DIR__ . '/templates/access_control.php';

if (!isset($conn) || !$conn) {
   die('Connection failed: ' . mysqli_connect_error());
}

if (!function_exists('outwhse_warehouse_locations_table_name')) {
   function outwhse_warehouse_locations_table_name(): string
   {
      return 'outwhse_warehouse_locations';
   }
   function outwhse_warehouse_locations_ensure(mysqli $conn): void
   {
      $table = outwhse_warehouse_locations_table_name();
      mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS `' . $table . '` (
         `id` smallint unsigned NOT NULL AUTO_INCREMENT,
         `name` varchar(100) NOT NULL,
         `code` varchar(20) NOT NULL,
         `is_active` tinyint(1) NOT NULL DEFAULT 1,
         `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         UNIQUE KEY `uq_' . $table . '_code` (`code`),
         KEY `idx_' . $table . '_active` (`is_active`),
         KEY `idx_' . $table . '_name` (`name`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
      $result = mysqli_query($conn, 'SELECT COUNT(*) AS row_count FROM `' . $table . '`');
      $rowCount = 0;
      if ($result instanceof mysqli_result) {
         $row = mysqli_fetch_assoc($result);
         $rowCount = (int) ($row['row_count'] ?? 0);
         mysqli_free_result($result);
      }
      if ($rowCount === 0) {
         $stmt = $conn->prepare('INSERT INTO `' . $table . '` (`id`, `name`, `code`, `is_active`) VALUES (1, "Lachaine", "LA", 1), (2, "Dickson", "DI", 1), (3, "TPB", "TPB", 1)');
         if ($stmt) {
            $stmt->execute();
            $stmt->close();
         }
         mysqli_query($conn, 'ALTER TABLE `' . $table . '` AUTO_INCREMENT = 4');
      }
   }
   function outwhse_warehouse_locations_rows(mysqli $conn, bool $activeOnly = false): array
   {
      $sql = 'SELECT id, name, code, is_active FROM `' . outwhse_warehouse_locations_table_name() . '`' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, id ASC, name ASC';
      $result = mysqli_query($conn, $sql);
      if (!$result instanceof mysqli_result) {
         return [];
      }
      $rows = [];
      while ($row = mysqli_fetch_assoc($result)) {
         $rows[] = ['id' => (int) ($row['id'] ?? 0), 'name' => trim((string) ($row['name'] ?? '')), 'code' => strtoupper(trim((string) ($row['code'] ?? ''))), 'is_active' => (int) ($row['is_active'] ?? 0) === 1 ? 1 : 0];
      }
      mysqli_free_result($result);
      return $rows;
   }
   function outwhse_warehouse_location_row_by_id(mysqli $conn, int $id): ?array
   {
      if ($id < 1) {
         return null;
      }
      $stmt = $conn->prepare('SELECT id, name, code, is_active FROM `' . outwhse_warehouse_locations_table_name() . '` WHERE id = ? LIMIT 1');
      if (!$stmt) {
         return null;
      }
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : [];
      $stmt->close();
      if (empty($rows)) {
         return null;
      }
      $row = $rows[0];
      return ['id' => (int) ($row['id'] ?? 0), 'name' => trim((string) ($row['name'] ?? '')), 'code' => strtoupper(trim((string) ($row['code'] ?? ''))), 'is_active' => (int) ($row['is_active'] ?? 0) === 1 ? 1 : 0];
   }
   function outwhse_warehouse_location_row_by_code(mysqli $conn, string $code): ?array
   {
      $code = strtoupper(trim($code));
      if ($code === '') {
         return null;
      }
      $stmt = $conn->prepare('SELECT id, name, code, is_active FROM `' . outwhse_warehouse_locations_table_name() . '` WHERE UPPER(code) = ? LIMIT 1');
      if (!$stmt) {
         return null;
      }
      $stmt->bind_param('s', $code);
      $stmt->execute();
      $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : [];
      $stmt->close();
      if (empty($rows)) {
         return null;
      }
      $row = $rows[0];
      return ['id' => (int) ($row['id'] ?? 0), 'name' => trim((string) ($row['name'] ?? '')), 'code' => strtoupper(trim((string) ($row['code'] ?? ''))), 'is_active' => (int) ($row['is_active'] ?? 0) === 1 ? 1 : 0];
   }
   function outwhse_warehouse_default_origin_location_id(mysqli $conn): int
   {
      return outwhse_warehouse_default_location_id($conn);
   }
   function outwhse_warehouse_location_label(mysqli $conn, int $id): string
   {
      if ($id === 0) {
         return 'All';
      }
      $row = outwhse_warehouse_location_row_by_id($conn, $id);
      $name = trim((string) ($row['name'] ?? ''));
      return $name !== '' ? $name : ('Location #' . $id);
   }
   function outwhse_warehouse_location_code(mysqli $conn, int $id): string
   {
      if ($id === 0) {
         return 'PL';
      }
      $row = outwhse_warehouse_location_row_by_id($conn, $id);
      $code = strtoupper(trim((string) ($row['code'] ?? '')));
      return $code !== '' ? $code : 'WH';
   }
   function outwhse_warehouse_location_ids(mysqli $conn, bool $includeAll = true): array
   {
      $ids = $includeAll ? [0] : [];
      foreach (outwhse_warehouse_locations_rows($conn, false) as $row) {
         $id = (int) ($row['id'] ?? 0);
         if ($id > 0) {
            $ids[] = $id;
         }
      }
      return array_values(array_unique($ids));
   }
   function outwhse_warehouse_active_location_ids(mysqli $conn): array
   {
      $ids = [];
      foreach (outwhse_warehouse_locations_rows($conn, true) as $row) {
         $id = (int) ($row['id'] ?? 0);
         if ($id > 0) {
            $ids[] = $id;
         }
      }
      return $ids;
   }
   function outwhse_warehouse_default_location_id(mysqli $conn): int
   {
      foreach (outwhse_warehouse_locations_rows($conn, true) as $row) {
         $id = (int) ($row['id'] ?? 0);
         if ($id > 0) {
            return $id;
         }
      }
      return 0;
   }
   function outwhse_generate_warehouse_code(string $name, array $existingCodes = []): string
   {
      $name = strtoupper(trim($name));
      $tokens = preg_split('/[^A-Z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
      $code = '';
      foreach ($tokens as $token) {
         $code .= substr($token, 0, 1);
         if (strlen($code) >= 4) {
            break;
         }
      }
      if ($code === '') {
         $code = preg_replace('/[^A-Z0-9]/', '', $name) ?: 'WH';
      }
      $code = substr($code, 0, 20);
      $base = $code;
      $suffix = 2;
      $existing = array_fill_keys(array_map('strtoupper', $existingCodes), true);
      while (isset($existing[$code])) {
         $code = substr($base . $suffix, 0, 20);
         $suffix++;
      }
      return $code;
   }
   function outwhse_warehouse_location_create(mysqli $conn, string $name, ?string $code = null, int $isActive = 1): array
   {
      $name = substr(trim($name), 0, 100);
      if ($name === '') {
         return ['ok' => false, 'message' => 'Warehouse name is required.'];
      }
      $existingCodes = [];
      foreach (outwhse_warehouse_locations_rows($conn, false) as $row) {
         $existingCodes[] = (string) ($row['code'] ?? '');
      }
      $code = $code !== null ? strtoupper(preg_replace('/[^A-Z0-9]+/', '', trim((string) $code)) ?? '') : '';
      if ($code === '') {
         $code = outwhse_generate_warehouse_code($name, $existingCodes);
      }
      $stmt = $conn->prepare('INSERT INTO `' . outwhse_warehouse_locations_table_name() . '` (`name`, `code`, `is_active`) VALUES (?, ?, ?)');
      if (!$stmt) {
         return ['ok' => false, 'message' => 'Unable to prepare warehouse creation.'];
      }
      $stmt->bind_param('ssi', $name, $code, $isActive);
      $ok = $stmt->execute();
      $insertId = $ok ? (int) $stmt->insert_id : 0;
      $stmt->close();
      if (!$ok || $insertId <= 0) {
         return ['ok' => false, 'message' => 'Unable to save warehouse location.'];
      }
      return ['ok' => true, 'message' => 'Warehouse location created.', 'location' => ['id' => $insertId, 'name' => $name, 'code' => $code, 'is_active' => (int) $isActive === 1 ? 1 : 0]];
   }
}

if (!function_exists('outwhse_inventory_ensure_columns')) {
   function outwhse_inventory_ensure_columns(mysqli $conn): void
   {
      $table = 'outwhse_inventory';
      if (!outwhse_table_exists($conn, $table)) {
         return;
      }

      $columns = [
         'inventory_notes' => 'TEXT NULL',
         'import_status_raw' => 'VARCHAR(150) NULL',
         'pallet_is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
         'inactive_reason' => 'VARCHAR(50) NULL',
         'inactive_notes' => 'VARCHAR(255) NULL',
         'deactivated_at' => 'DATETIME NULL',
         'deactivated_by' => 'VARCHAR(100) NULL',
         'reactivated_at' => 'DATETIME NULL',
         'reactivated_by' => 'VARCHAR(100) NULL',
         'reactivation_reason' => 'VARCHAR(255) NULL',
      ];

      foreach ($columns as $column => $definition) {
         if (!outwhse_column_exists($conn, $table, $column)) {
            mysqli_query($conn, 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
         }
      }
   }
}

if (!function_exists('outwhse_pallet_activity_ensure')) {
   function outwhse_pallet_activity_ensure(mysqli $conn): void
   {
      mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS `outwhse_pallet_activity_log` (
         `id` bigint unsigned NOT NULL AUTO_INCREMENT,
         `warehouse_location_id` int unsigned NOT NULL,
         `pallet_number` varchar(100) NOT NULL,
         `activity_type` varchar(30) NOT NULL,
         `reason_code` varchar(50) NULL,
         `reason_notes` varchar(255) NULL,
         `changed_by` varchar(100) NOT NULL,
         `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `idx_outwhse_pallet_activity_pallet` (`warehouse_location_id`, `pallet_number`, `changed_at`),
         KEY `idx_outwhse_pallet_activity_type` (`activity_type`, `changed_at`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
   }
}

outwhse_warehouse_locations_ensure($conn);
outwhse_inventory_ensure_columns($conn);
outwhse_pallet_activity_ensure($conn);

$title = 'Warehouse Transfers';
$page_class = 'page-outwhse';
$BASE_URL = defined('APP_ROOT_URL') ? APP_ROOT_URL : rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

if (empty($_SESSION['csrf_token'])) {
   $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

function outwhse_icon(string $name): string
{
   $icons = [
      'logo' => <<<'SVG'
<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 2 27 8.3v15.4L16 30 5 23.7V8.3Z" fill="#5da7dc"/><path d="M16 2v13.4L5 23.7V8.3Z" fill="#7ac3f2"/><path d="m16 15.4 11-7.1" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round"/><path d="m16 15.4-11-7.1" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round"/><path d="M16 15.4V30" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round"/></svg>
SVG,
      'All' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.8"/><rect x="14" y="3" width="7" height="7" rx="1.8"/><rect x="3" y="14" width="7" height="7" rx="1.8"/><rect x="14" y="14" width="7" height="7" rx="1.8"/></svg>
SVG,
      'Lachaine' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 20 7.5v9L12 21l-8-4.5v-9Z"/><path d="M12 3v18"/><path d="m4 7.5 8 4.5 8-4.5"/></svg>
SVG,
      'Dickson' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 20 7.5v9L12 21l-8-4.5v-9Z"/><path d="M12 3v18"/><path d="m4 7.5 8 4.5 8-4.5"/></svg>
SVG,
      'pallets' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10h16"/><path d="M4 14h16"/><path d="M6 6h3v12H6Z"/><path d="M15 6h3v12h-3Z"/></svg>
SVG,
      'sku' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10"/><path d="M6 7h12v13H6Z"/><path d="M9 11h6"/><path d="M9 15h6"/></svg>
SVG,
      'alert' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 3 7v6c0 4.8 3.1 9.1 9 11 5.9-1.9 9-6.2 9-11V7Z"/><path d="M12 8v5"/><circle cx="12" cy="16.5" r="1"/></svg>
SVG,
      'edit' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4.5-1 9-9-3.5-3.5-9 9Z"/><path d="M13.5 6.5 17 10"/></svg>
SVG,
      'trash' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 12h10l1-12"/><path d="M9 7V4h6v3"/></svg>
SVG,
      'tag' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 3H5a2 2 0 0 0-2 2v6l9 9 9-9-9-9Z"/><circle cx="7.5" cy="7.5" r="1"/></svg>
SVG,
      'bol' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h8l4 4v14H7z"/><path d="M15 3v4h4"/><path d="M10 12h6"/><path d="M10 16h6"/></svg>
SVG,
      'history' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5l3 2"/><path d="M3 12a9 9 0 1 0 2.64-6.36"/><path d="M3 4v4h4"/></svg>
SVG,
      'plus' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
SVG,
      'address-book' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h11a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6Z"/><path d="M8 3v18"/><path d="M11 8h5"/><path d="M11 12h5"/><path d="M11 16h5"/></svg>
SVG,
      'warehouse' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10.5 12 6l8 4.5V20H4Z"/><path d="M7 20v-6h10v6"/><path d="M9 10.5V8h6v2.5"/></svg>
SVG,
   ];

   return $icons[$name] ?? '';
}

function outwhse_status_key(?string $status): string
{
   $status = strtolower(trim((string) $status));

   if ($status === '') {
      return 'exception';
   }
   if (strpos($status, 'written off') !== false || strpos($status, 'damaged') !== false || strpos($status, 'destroy') !== false) {
      return 'written-off';
   }
   if (strpos($status, 'transfer') !== false || strpos($status, 'pending') !== false) {
      return 'transfer';
   }
   if (strpos($status, 'active') !== false) {
      return 'active';
   }
   if (strpos($status, 'not in system') !== false || strpos($status, 'exception') !== false) {
      return 'exception';
   }

   return 'neutral';
}

function outwhse_status_kind(string $statusKey): string
{
   return in_array($statusKey, ['active', 'transfer', 'written-off'], true) ? 'pill' : 'plain';
}

function outwhse_space_status_key(?string $status): string
{
   $status = strtolower(trim((string) $status));
   if ($status === 'ready_to_ship') {
      $status = 'received';
   }

   return $status !== '' ? preg_replace('/[^a-z0-9]+/', '_', $status) : 'unspecified';
}

function outwhse_space_status_label(?string $status): string
{
   return match (outwhse_space_status_key($status)) {
      'staged' => 'Staged',
      'received' => 'Received',
      'ready_to_ship' => 'Received',
      'on_route' => 'On Route',
      'in_storage' => 'In Storage',
      default => 'Unspecified',
   };
}

function outwhse_location_label(int $id): string
{
   global $conn;
   return outwhse_warehouse_location_label($conn, $id);
}

function outwhse_location_ids(): array
{
   global $conn;
   return outwhse_warehouse_location_ids($conn, true);
}

function outwhse_active_location_ids(): array
{
   global $conn;
   return outwhse_warehouse_active_location_ids($conn);
}

function outwhse_location_code(int $id): string
{
   global $conn;
   return outwhse_warehouse_location_code($conn, $id);
}

function outwhse_location_icon_name(array $row): string
{
   $label = strtoupper(trim((string) ($row['name'] ?? '')));
   $code = strtoupper(trim((string) ($row['code'] ?? '')));

   if ($code === 'TPB' || $label === 'TPB') {
      return 'logo';
   }
   if ($label === 'LACHAINE') {
      return 'Lachaine';
   }
   if ($label === 'DICKSON') {
      return 'Dickson';
   }

   return 'warehouse';
}

function outwhse_table_exists(mysqli $conn, string $table): bool
{
   $safe_table = mysqli_real_escape_string($conn, $table);
   $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
   if (!$result) {
      return false;
   }

   $exists = mysqli_num_rows($result) > 0;
   mysqli_free_result($result);
   return $exists;
}

function outwhse_column_exists(mysqli $conn, string $table, string $column): bool
{
   $safe_table = mysqli_real_escape_string($conn, $table);
   $safe_column = mysqli_real_escape_string($conn, $column);
   $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
   if (!$result) {
      return false;
   }

   $exists = mysqli_num_rows($result) > 0;
   mysqli_free_result($result);
   return $exists;
}

function outwhse_post_value(string $key): string
{
   return trim((string) ($_POST[$key] ?? ''));
}

function outwhse_post_nullable_value(string $key): ?string
{
   $value = trim((string) ($_POST[$key] ?? ''));
   return $value === '' ? null : $value;
}

function outwhse_json_response(array $payload, int $statusCode = 200): never
{
   http_response_code($statusCode);
   header('Content-Type: application/json; charset=utf-8');
   echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
   exit;
}

function outwhse_bol_pdf_url_from_fields(array $fields, string $baseUrl): string
{
   $allowed_keys = [
      'bol_number',
      'ship_date',
      'shipper_name',
      'shipper_contact_name',
      'shipper_phone',
      'shipper_address_line_1',
      'shipper_address_line_2',
      'shipper_city',
      'shipper_province_state',
      'shipper_postal_code',
      'shipper_country',
      'consignee_name',
      'consignee_contact_name',
      'consignee_phone',
      'consignee_address_line_1',
      'consignee_address_line_2',
      'consignee_city',
      'consignee_province_state',
      'consignee_postal_code',
      'consignee_country',
      'carrier_name',
      'consignor_name',
      'from_location',
      'to_location',
      'warehouse',
      'item',
      'description',
      'qty',
      'unit',
      'pallet',
      'line',
      'pallet_weight',
      'pallet_count',
      'special_instructions',
      'line_description',
      'lines_json',
      'dg_class',
      'un_number',
      'consignor_date',
   ];

   $params = [];
   foreach ($allowed_keys as $key) {
      $value = trim((string) ($fields[$key] ?? ''));
      if ($value !== '') {
         $params[$key] = $value;
      }
   }

   return rtrim($baseUrl, '/') . '/outwhse_bol_pdf.php' . ($params ? ('?' . http_build_query($params)) : '');
}

function outwhse_generate_lot_number(string $sku): string
{
   $sku = strtoupper(preg_replace('/[^A-Z0-9]+/', '', trim($sku)));
   $sku = $sku !== '' ? $sku : 'SKU';
   return $sku . '-' . date('Ymd');
}

function outwhse_lookup_catalog_item(mysqli $conn, string $sku): ?array
{
   $sku = trim($sku);
   if ($sku === '') {
      return null;
   }

   $stmt = $conn->prepare('SELECT id AS sku_id, `desc` AS description, lot_required FROM sku WHERE sku_num = ? LIMIT 1');
   if (!$stmt) {
      return null;
   }

   $stmt->bind_param('s', $sku);
   $stmt->execute();
   $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : [];
   $stmt->close();

   if (empty($rows)) {
      return null;
   }

   $description = trim((string) ($rows[0]['description'] ?? ''));
   $skuId = (int) ($rows[0]['sku_id'] ?? 0);
   $lotRequired = (int) ($rows[0]['lot_required'] ?? 0) === 1;

   return [
      'sku_id' => $skuId > 0 ? $skuId : null,
      'description' => $description !== '' ? $description : null,
      'lot_required' => $lotRequired,
      'generated_lot_number' => $lotRequired ? outwhse_generate_lot_number($sku) : 'N/A',
   ];
}

function outwhse_lookup_lots_for_sku_id(mysqli $conn, int $skuId): array
{
   if ($skuId <= 0 || !outwhse_table_exists($conn, 'lot')) {
      return [];
   }

   $stmt = $conn->prepare('
      SELECT
         lot.id,
         lot.lot_code,
         lot.expiry_date,
         lot.quantity
      FROM lot
      WHERE lot.sku_id = ?
      ORDER BY lot.expiry_date IS NULL, lot.expiry_date ASC, lot.lot_code ASC
   ');
   if (!$stmt) {
      return [];
   }

   $stmt->bind_param('i', $skuId);
   $stmt->execute();
   $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : [];
   $stmt->close();

   $items = [];
   foreach ($rows as $row) {
      $lotCode = trim((string) ($row['lot_code'] ?? ''));
      if ($lotCode === '') {
         continue;
      }

      $items[] = [
         'id' => (int) ($row['id'] ?? 0),
         'lot_code' => $lotCode,
         'expiry_date' => trim((string) ($row['expiry_date'] ?? '')),
         'quantity' => (string) ($row['quantity'] ?? ''),
      ];
   }

   return $items;
}

function outwhse_next_pallet_number(mysqli $conn, int $destinationLocationId): string
{
   $prefix = outwhse_warehouse_location_code($conn, $destinationLocationId) . '-';
   $likePrefix = $prefix . '%';
   $maxSequence = 0;

   $stmt = $conn->prepare('
      SELECT pallet_number
      FROM outwhse_inventory
      WHERE pallet_number IS NOT NULL
        AND pallet_number <> ""
        AND pallet_number LIKE ?
   ');

   if ($stmt) {
      $stmt->bind_param('s', $likePrefix);
      $stmt->execute();
      $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : [];
      $stmt->close();

      foreach ($rows as $row) {
         $palletNumber = trim((string) ($row['pallet_number'] ?? ''));

         if ($palletNumber === '') {
            continue;
         }

         if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', $palletNumber, $matches) === 1) {
            $maxSequence = max($maxSequence, (int) $matches[1]);
         }
      }
   }

   return sprintf('%s%03d', $prefix, $maxSequence + 1);
}

function outwhse_build_url(
   string $baseUrl,
   int $locationId,
   ?int $editId = null,
   bool $openAddressBook = false,
   ?int $addressEditId = null,
   bool $showInactive = false
): string {
   $params = [];
   if ($locationId > 0) {
      $params['warehouse_location_id'] = $locationId;
   }
   if ($editId !== null && $editId > 0) {
      $params['edit_id'] = $editId;
   }
   if ($openAddressBook) {
      $params['address_book'] = '1';
   }
   if ($addressEditId !== null && $addressEditId > 0) {
      $params['address_edit_id'] = $addressEditId;
   }
   if ($showInactive) {
      $params['show_inactive'] = '1';
   }

   $query = http_build_query($params);
   return $baseUrl . '/outwhse.php' . ($query !== '' ? '?' . $query : '');
}

function outwhse_redirect(
   string $baseUrl,
   int $locationId,
   ?int $editId = null,
   bool $openAddressBook = false,
   ?int $addressEditId = null,
   ?bool $showInactive = null
): never {
   if ($showInactive === null) {
      $showInactive = (string) ($_POST['return_show_inactive'] ?? '') === '1';
   }

   header('Location: ' . outwhse_build_url(
      $baseUrl,
      $locationId,
      $editId,
      $openAddressBook,
      $addressEditId,
      $showInactive
   ));
   exit;
}

function outwhse_movements_url(string $baseUrl, int $locationId): string
{
   $query = $locationId > 0 ? ('?' . http_build_query(['warehouse_location_id' => $locationId])) : '';
   return $baseUrl . '/outwhse_movements.php' . $query;
}

function outwhse_item_label(array $row): string
{
   $sku = trim((string) ($row['sku'] ?? ''));
   if ($sku !== '') {
      return $sku;
   }

   $manual = trim((string) ($row['manual_label'] ?? ''));
   if ($manual !== '') {
      return $manual;
   }

   return 'Manual entry';
}

function outwhse_location_id_from_label(mysqli $conn, string $label): int
{
   $label = strtoupper(trim($label));
   if ($label === '' || $label === 'N/A') {
      return 0;
   }

   $row = outwhse_warehouse_location_row_by_code($conn, $label);
   if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
      return (int) $row['id'];
   }

   $rows = outwhse_warehouse_locations_rows($conn, false);
   foreach ($rows as $candidate) {
      $name = strtoupper(trim((string) ($candidate['name'] ?? '')));
      $code = strtoupper(trim((string) ($candidate['code'] ?? '')));
      if ($label === $name || $label === $code) {
         return (int) ($candidate['id'] ?? 0);
      }
   }

   return 0;
}

function outwhse_live_on_route_rows_from_movements(mysqli $conn, int $selectedLocationId, array $existingOnRoutePallets = []): array
{
   if (!outwhse_table_exists($conn, 'outwhse_inventory_movements')) {
      return [];
   }

   $sql = '
      SELECT
         id,
         warehouse_location_id,
         item_source,
         sku,
         manual_label,
         manual_reference,
         manual_category,
         description,
         lot_number,
         qty,
         unit_type,
         pallet_number,
         movement_direction,
         source_location_type,
         source_location_label,
         destination_location_type,
         destination_location_label,
         reference_number,
         notes,
         created_by,
         created_at
      FROM outwhse_inventory_movements
      WHERE space_status = "on_route"
   ';

   if ($selectedLocationId > 0) {
      $sql .= ' AND warehouse_location_id = ?';
   }

   $sql .= ' ORDER BY created_at, id';

   $stmt = $conn->prepare($sql);
   if (!$stmt) {
      return [];
   }

   if ($selectedLocationId > 0) {
      $stmt->bind_param('i', $selectedLocationId);
   }

   $stmt->execute();
   $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : [];
   $stmt->close();

   $liveRows = [];
   foreach ($rows as $row) {
      $palletNumber = strtoupper(trim((string) ($row['pallet_number'] ?? '')));
      if ($palletNumber !== '' && isset($existingOnRoutePallets[$palletNumber])) {
         continue;
      }

      $warehouseId = (int) ($row['warehouse_location_id'] ?? 0);
      $originLocationId = outwhse_location_id_from_label($conn, (string) ($row['source_location_label'] ?? ''));
      if ($originLocationId < 1) {
         $originLocationId = $warehouseId;
      }
      $destinationLocationId = outwhse_location_id_from_label($conn, (string) ($row['destination_location_label'] ?? ''));
      $currentLocationId = $originLocationId;
      $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
      $manualLabel = trim((string) ($row['manual_label'] ?? ''));
      $description = trim((string) ($row['description'] ?? ''));
      $lotRequired = false;
      if ($sku !== '') {
         $catalogItem = outwhse_lookup_catalog_item($conn, $sku);
         if (is_array($catalogItem)) {
            $lotRequired = (int) ($catalogItem['lot_required'] ?? 0) === 1;
            $catalogDescription = trim((string) ($catalogItem['description'] ?? ''));
            if ($description === '' && $catalogDescription !== '') {
               $description = $catalogDescription;
            }
         }
      }

      $movementRow = [
         'id' => -1 * (int) ($row['id'] ?? 0),
         'warehouse_location_id' => $warehouseId,
         'warehouse_location_name' => outwhse_location_label($warehouseId),
         'origin_location_id' => $originLocationId,
         'origin_location_name' => outwhse_location_label($originLocationId),
         'current_location_id' => $currentLocationId,
         'current_location_name' => outwhse_location_label($currentLocationId),
         'destination_location_id' => $destinationLocationId,
         'destination_location_name' => outwhse_location_label($destinationLocationId),
         'item_source' => (string) ($row['item_source'] ?? 'catalog'),
         'source_label' => (string) ($row['item_source'] ?? 'catalog') === 'manual' ? 'Manual' : 'Catalog',
         'sku' => $sku,
         'lot_required' => $lotRequired,
         'item_display' => outwhse_item_label(['sku' => $sku, 'manual_label' => $manualLabel]),
         'manual_label' => $manualLabel,
         'manual_reference' => trim((string) ($row['manual_reference'] ?? '')),
         'manual_category' => trim((string) ($row['manual_category'] ?? '')),
         'description' => $description !== '' ? $description : ($manualLabel !== '' ? $manualLabel : 'On route pallet'),
         'lot_raw' => (string) ($row['lot_number'] ?? ''),
         'qty_raw' => (string) ($row['qty'] ?? ''),
         'unit_raw' => (string) ($row['unit_type'] ?? ''),
         'pallet_raw' => $palletNumber,
         'line_number_raw' => (string) ($row['id'] ?? '0'),
         'pallet_weight_raw' => '',
         'pallet_length_raw' => '',
         'pallet_width_raw' => '',
         'pallet_height_raw' => '',
         'is_dangerous_good_raw' => '0',
         'dg_class_raw' => '',
         'un_number_raw' => '',
         'inventory_notes' => '',
         'import_status_raw' => '',
         'status_raw' => 'Transfer',
         'space_status_raw' => 'on_route',
         'lot' => (string) (($row['lot_number'] ?? '') !== '' ? $row['lot_number'] : 'N/A'),
         'qty' => (string) (($row['qty'] ?? '') !== '' ? $row['qty'] : 'N/A'),
         'unit' => (string) (($row['unit_type'] ?? '') !== '' ? $row['unit_type'] : 'N/A'),
         'pallet' => $palletNumber !== '' ? $palletNumber : 'N/A',
         'line_number' => (int) ($row['id'] ?? 0),
         'status' => 'Transfer',
         'status_key' => outwhse_status_key('transfer'),
         'status_kind' => outwhse_status_kind(outwhse_status_key('transfer')),
         'space_status' => outwhse_space_status_label('on_route'),
         'space_status_key' => outwhse_space_status_key('on_route'),
         'pallet_weight' => 'N/A',
         'pallet_dimensions' => 'N/A',
         'is_dangerous_good' => false,
         'dg_class' => '',
         'un_number' => '',
         'live_source' => 'movement',
         'is_active' => true,
         'inactive_reason' => '',
         'inactive_notes' => '',
         'deactivated_at' => '',
         'deactivated_by' => '',
         'reactivated_at' => '',
         'reactivated_by' => '',
         'reactivation_reason' => '',
      ];

      $liveRows[] = $movementRow;
   }

   return $liveRows;
}

function outwhse_next_line_number(mysqli $conn, int $warehouseLocationId, string $palletNumber, ?int $ignoreId = null): int
{
   $sql = '
      SELECT COALESCE(MAX(line_number), 0) + 1 AS next_line_number
      FROM outwhse_inventory
      WHERE warehouse_location_id = ?
        AND pallet_number = ?
        AND is_disabled = 0
   ';

   if ($ignoreId !== null && $ignoreId > 0) {
      $sql .= ' AND id <> ?';
   }

   $stmt = $conn->prepare($sql);
   if (!$stmt) {
      return 1;
   }

   if ($ignoreId !== null && $ignoreId > 0) {
      $stmt->bind_param('isi', $warehouseLocationId, $palletNumber, $ignoreId);
   } else {
      $stmt->bind_param('is', $warehouseLocationId, $palletNumber);
   }

   $stmt->execute();
   $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : [];
   $stmt->close();

   $next = (int) ($rows[0]['next_line_number'] ?? 1);
   return max(1, $next);
}

function outwhse_pallet_inactive_reason_options(): array
{
   return [
      'broken_down' => 'Broken down / pallet retired',
      'damaged' => 'Damaged',
      'temporary_hold' => 'Temporary hold',
      'duplicate' => 'Duplicate pallet record',
      'data_error' => 'Data correction',
      'other' => 'Other',
   ];
}

function outwhse_pallet_activity_log(
   mysqli $conn,
   int $warehouseLocationId,
   string $palletNumber,
   string $activityType,
   ?string $reasonCode,
   ?string $reasonNotes,
   string $changedBy
): bool {
   $stmt = $conn->prepare('
      INSERT INTO outwhse_pallet_activity_log (
         warehouse_location_id,
         pallet_number,
         activity_type,
         reason_code,
         reason_notes,
         changed_by
      ) VALUES (?, ?, ?, ?, ?, ?)
   ');
   if (!$stmt) {
      return false;
   }

   $stmt->bind_param(
      'isssss',
      $warehouseLocationId,
      $palletNumber,
      $activityType,
      $reasonCode,
      $reasonNotes,
      $changedBy
   );
   $ok = $stmt->execute();
   $stmt->close();

   return $ok;
}

function outwhse_update_pallet_active_status(
   mysqli $conn,
   int $itemId,
   string $status,
   string $changedBy
): array {
   if ($itemId < 1) {
      return ['ok' => false, 'message' => 'Invalid pallet selected.'];
   }

   $status = strtolower(trim($status));
   $changedBy = trim($changedBy) !== '' ? substr(trim($changedBy), 0, 100) : 'unknown';
   if (!in_array($status, ['active', 'inactive'], true)) {
      return ['ok' => false, 'message' => 'Choose a valid pallet status.'];
   }

   $lookupStmt = $conn->prepare('
      SELECT warehouse_location_id, pallet_number
      FROM outwhse_inventory
      WHERE id = ?
        AND is_disabled = 0
      LIMIT 1
   ');
   if (!$lookupStmt) {
      return ['ok' => false, 'message' => 'Unable to load the selected pallet.'];
   }

   $lookupStmt->bind_param('i', $itemId);
   $lookupStmt->execute();
   $lookupResult = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($lookupStmt) : [];
   $lookupStmt->close();

   $warehouseLocationId = (int) ($lookupResult[0]['warehouse_location_id'] ?? 0);
   $palletNumber = trim((string) ($lookupResult[0]['pallet_number'] ?? ''));

   if ($warehouseLocationId < 1 || $palletNumber === '') {
      return ['ok' => false, 'message' => 'Choose a valid active pallet first.'];
   }

   mysqli_begin_transaction($conn);

   $currentStmt = $conn->prepare('
      SELECT pallet_is_active
      FROM outwhse_inventory
      WHERE warehouse_location_id = ?
        AND pallet_number = ?
        AND is_disabled = 0
      LIMIT 1
   ');
   if (!$currentStmt) {
      mysqli_rollback($conn);
      return ['ok' => false, 'message' => 'Unable to validate pallet status.'];
   }

   $currentStmt->bind_param('is', $warehouseLocationId, $palletNumber);
   $currentStmt->execute();
   $currentRows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($currentStmt) : [];
   $currentStmt->close();

   $isCurrentlyActive = (int) ($currentRows[0]['pallet_is_active'] ?? 1) === 1;
   $newActiveValue = $status === 'active' ? 1 : 0;

   if ($isCurrentlyActive === ($newActiveValue === 1)) {
      mysqli_rollback($conn);
      return ['ok' => true, 'message' => 'Pallet ' . $palletNumber . ' is already ' . $status . '.'];
   }

   $updateStmt = $conn->prepare('
      UPDATE outwhse_inventory
      SET
         pallet_is_active = ?,
         ' . ($newActiveValue === 1
      ? 'reactivated_at = NOW(),
         reactivated_by = ?,
         reactivation_reason = NULL'
      : 'deactivated_at = NOW(),
         deactivated_by = ?') . '
      WHERE warehouse_location_id = ?
        AND pallet_number = ?
        AND is_disabled = 0
   ');
   if (!$updateStmt) {
      mysqli_rollback($conn);
      return ['ok' => false, 'message' => 'Unable to prepare the pallet status update.'];
   }

   if ($newActiveValue === 1) {
      $updateStmt->bind_param('isis', $newActiveValue, $changedBy, $warehouseLocationId, $palletNumber);
   } else {
      $updateStmt->bind_param('isis', $newActiveValue, $changedBy, $warehouseLocationId, $palletNumber);
   }
   $updateOk = $updateStmt->execute();
   $affectedRows = $updateStmt->affected_rows;
   $updateStmt->close();

   if (!$updateOk || $affectedRows <= 0) {
      mysqli_rollback($conn);
      return ['ok' => false, 'message' => 'No pallet rows were updated.'];
   }

   $logOk = outwhse_pallet_activity_log(
      $conn,
      $warehouseLocationId,
      $palletNumber,
      $newActiveValue === 1 ? 'reactivated' : 'deactivated',
      null,
      null,
      $changedBy
   );
   if (!$logOk) {
      mysqli_rollback($conn);
      return ['ok' => false, 'message' => 'The pallet status was not updated because its activity log could not be saved.'];
   }

   mysqli_commit($conn);

   return [
      'ok' => true,
      'message' => 'Pallet ' . $palletNumber . ' is now ' . $status . '.',
   ];
}

function outwhse_reactivate_pallet(
   mysqli $conn,
   int $itemId,
   string $reactivationReason,
   string $changedBy
): array {
   if ($itemId < 1) {
      return ['ok' => false, 'message' => 'Invalid pallet selected.'];
   }

   $reactivationReason = trim($reactivationReason);
   if ($reactivationReason === '') {
      return ['ok' => false, 'message' => 'Enter a reason for reactivating the pallet.'];
   }

   $reactivationReason = substr($reactivationReason, 0, 255);
   $changedBy = trim($changedBy) !== '' ? substr(trim($changedBy), 0, 100) : 'unknown';

   $lookupStmt = $conn->prepare('
      SELECT warehouse_location_id, pallet_number
      FROM outwhse_inventory
      WHERE id = ?
        AND is_disabled = 0
        AND pallet_is_active = 0
      LIMIT 1
   ');
   if (!$lookupStmt) {
      return ['ok' => false, 'message' => 'Unable to load the selected inactive pallet.'];
   }

   $lookupStmt->bind_param('i', $itemId);
   $lookupStmt->execute();
   $lookupResult = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($lookupStmt) : [];
   $lookupStmt->close();

   $warehouseLocationId = (int) ($lookupResult[0]['warehouse_location_id'] ?? 0);
   $palletNumber = trim((string) ($lookupResult[0]['pallet_number'] ?? ''));

   if ($warehouseLocationId < 1 || $palletNumber === '') {
      return ['ok' => false, 'message' => 'Choose a valid inactive pallet first.'];
   }

   mysqli_begin_transaction($conn);

   $activeCheckStmt = $conn->prepare('
      SELECT COUNT(*) AS active_count
      FROM outwhse_inventory
      WHERE pallet_number = ?
        AND is_disabled = 0
        AND pallet_is_active = 1
   ');
   if (!$activeCheckStmt) {
      mysqli_rollback($conn);
      return ['ok' => false, 'message' => 'Unable to validate the pallet number before reactivation.'];
   }

   $activeCheckStmt->bind_param('s', $palletNumber);
   $activeCheckStmt->execute();
   $activeRows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($activeCheckStmt) : [];
   $activeCheckStmt->close();

   if ((int) ($activeRows[0]['active_count'] ?? 0) > 0) {
      mysqli_rollback($conn);
      return [
         'ok' => false,
         'message' => 'Pallet ' . $palletNumber . ' cannot be reactivated because an active pallet with that number already exists.',
      ];
   }

   $updateStmt = $conn->prepare('
      UPDATE outwhse_inventory
      SET
         pallet_is_active = 1,
         reactivated_at = NOW(),
         reactivated_by = ?,
         reactivation_reason = ?
      WHERE warehouse_location_id = ?
        AND pallet_number = ?
        AND is_disabled = 0
        AND pallet_is_active = 0
   ');
   if (!$updateStmt) {
      mysqli_rollback($conn);
      return ['ok' => false, 'message' => 'Unable to prepare the pallet reactivation.'];
   }

   $updateStmt->bind_param(
      'ssis',
      $changedBy,
      $reactivationReason,
      $warehouseLocationId,
      $palletNumber
   );
   $updateOk = $updateStmt->execute();
   $affectedRows = $updateStmt->affected_rows;
   $updateStmt->close();

   if (!$updateOk || $affectedRows <= 0) {
      mysqli_rollback($conn);
      return ['ok' => false, 'message' => 'No inactive pallet rows were found to reactivate.'];
   }

   $logOk = outwhse_pallet_activity_log(
      $conn,
      $warehouseLocationId,
      $palletNumber,
      'reactivated',
      null,
      $reactivationReason,
      $changedBy
   );
   if (!$logOk) {
      mysqli_rollback($conn);
      return ['ok' => false, 'message' => 'The pallet was not reactivated because its activity log could not be saved.'];
   }

   mysqli_commit($conn);

   return [
      'ok' => true,
      'message' => 'Pallet ' . $palletNumber . ' is active again.',
   ];
}


function outwhse_dg_is_enabled(mixed $value): int
{
   return (string) $value === '1' ? 1 : 0;
}

function outwhse_dg_un_number_for_class(string $dg_class): string
{
   return match (trim($dg_class)) {
      '2.1' => 'UN1057',
      '9' => 'UN3481',
      default => '',
   };
}

function outwhse_movement_direction_for_space_status(string $space_status): ?string
{
   return $space_status === 'on_route' ? 'transfer' : null;
}

$warehouse_location_rows = outwhse_warehouse_locations_rows($conn, false);
$warehouse_active_location_rows = outwhse_warehouse_locations_rows($conn, true);
$warehouse_location_labels = [];
foreach ($warehouse_location_rows as $warehouse_row) {
   $warehouse_id = (int) ($warehouse_row['id'] ?? 0);
   if ($warehouse_id > 0) {
      $warehouse_location_labels[(string) $warehouse_id] = (string) ($warehouse_row['name'] ?? '');
   }
}

$selected_location_id = isset($_GET['warehouse_location_id']) ? (int) ($_GET['warehouse_location_id']) : 0;
if (!in_array($selected_location_id, outwhse_location_ids(), true)) {
   $selected_location_id = 0;
}
$show_inactive = (string) ($_GET['show_inactive'] ?? '') === '1';
$search_query = trim((string) ($_GET['search'] ?? ''));
$space_status_filter = trim((string) ($_GET['space_status'] ?? 'all'));
if (!in_array($space_status_filter, ['all', 'on_route', 'in_storage'], true)) {
   $space_status_filter = 'all';
}
$current_page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 100;
$manual_query_requested = (string) ($_GET['run_query'] ?? '') === '1';
$address_book_requested = (string) ($_GET['address_book'] ?? '') === '1';
$address_book_edit_id = (int) ($_GET['address_edit_id'] ?? 0);

$table_available = outwhse_table_exists($conn, 'outwhse_inventory');
$address_book_table_available = outwhse_table_exists($conn, 'outwhse_address_book');
$inventory_has_current_location_id = $table_available && outwhse_column_exists($conn, 'outwhse_inventory', 'current_location_id');
$flash = $_SESSION['outwhse_flash'] ?? null;
unset($_SESSION['outwhse_flash']);

$allowed_item_sources = ['catalog', 'manual'];
$allowed_space_statuses = ['in_storage', 'on_route', 'received'];
$manual_category_options = [
   '' => 'None',
   'amazon' => 'Amazon',
   'return' => 'Return',
   'receiving_hold' => 'Receiving Hold',
   'price_check' => 'Price Check',
   'misc' => 'Misc',
];
$address_type_options = [
   'shipper' => 'Shipper',
   'consignee' => 'Consignee',
   'consignor' => 'Consignor',
   'carrier' => 'Carrier',
   'other' => 'Other',
];
$space_filter_options = [
   'all' => 'All',
   'on_route' => 'On Route',
   'in_storage' => 'In Storage',
];
$space_status_form_options = [
   'in_storage' => 'In Storage',
   'on_route' => 'On Route',
   'received' => 'Received',
];
$pallet_number_destination_ids = outwhse_active_location_ids();
$unit_type_form_options = [
   'UNIT' => 'Unit',
   'MASTERCASE' => 'Mastercase',
];

if (isset($_GET['ajax'])) {
   $ajaxAction = trim((string) $_GET['ajax']);

   if ($ajaxAction === 'sku_lookup') {
      $skuLookupValue = trim((string) ($_GET['sku'] ?? ''));
      $catalogItem = outwhse_lookup_catalog_item($conn, $skuLookupValue);
      outwhse_json_response([
         'sku' => $skuLookupValue,
         'sku_id' => $catalogItem['sku_id'] ?? null,
         'description' => $catalogItem['description'] ?? null,
         'lot_required' => (bool) ($catalogItem['lot_required'] ?? false),
         'generated_lot_number' => $catalogItem['generated_lot_number'] ?? null,
      ]);
   }

   if ($ajaxAction === 'lot_lookup') {
      $skuLookupValue = trim((string) ($_GET['sku'] ?? ''));
      $catalogItem = outwhse_lookup_catalog_item($conn, $skuLookupValue);
      $skuId = (int) ($catalogItem['sku_id'] ?? 0);
      outwhse_json_response([
         'sku' => $skuLookupValue,
         'sku_id' => $skuId > 0 ? $skuId : null,
         'lots' => $skuId > 0 ? outwhse_lookup_lots_for_sku_id($conn, $skuId) : [],
      ]);
   }

   if ($ajaxAction === 'pallet_activity') {
      $itemId = (int) ($_GET['item_id'] ?? 0);
      if ($itemId < 1) {
         outwhse_json_response(['error' => 'Invalid pallet selected.'], 422);
      }

      $lookupStmt = $conn->prepare('
         SELECT warehouse_location_id, pallet_number
         FROM outwhse_inventory
         WHERE id = ?
         LIMIT 1
      ');
      if (!$lookupStmt) {
         outwhse_json_response(['error' => 'Unable to load pallet activity.'], 500);
      }

      $lookupStmt->bind_param('i', $itemId);
      $lookupStmt->execute();
      $palletRows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($lookupStmt) : [];
      $lookupStmt->close();

      $warehouseLocationId = (int) ($palletRows[0]['warehouse_location_id'] ?? 0);
      $palletNumber = trim((string) ($palletRows[0]['pallet_number'] ?? ''));
      if ($warehouseLocationId < 1 || $palletNumber === '') {
         outwhse_json_response(['error' => 'Pallet activity was not found.'], 404);
      }

      $activityStmt = $conn->prepare('
         SELECT
            warehouse_location_id,
            activity_type,
            reason_code,
            reason_notes,
            changed_by,
            changed_at
         FROM outwhse_pallet_activity_log
         WHERE pallet_number = ?
         ORDER BY id DESC
         LIMIT 50
      ');
      if (!$activityStmt) {
         outwhse_json_response(['error' => 'Unable to prepare pallet activity history.'], 500);
      }

      $activityStmt->bind_param('s', $palletNumber);
      $activityStmt->execute();
      $activityRows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($activityStmt) : [];
      $activityStmt->close();

      $reasonOptions = outwhse_pallet_inactive_reason_options();
      foreach ($activityRows as &$activityRow) {
         $reasonCode = trim((string) ($activityRow['reason_code'] ?? ''));
         $activityRow['reason_label'] = $reasonCode !== ''
            ? (string) ($reasonOptions[$reasonCode] ?? ucwords(str_replace('_', ' ', $reasonCode)))
            : '';
         $activityRow['warehouse_name'] = outwhse_location_label((int) ($activityRow['warehouse_location_id'] ?? 0));
      }
      unset($activityRow);

      outwhse_json_response([
         'pallet_number' => $palletNumber,
         'warehouse_location_id' => $warehouseLocationId,
         'activities' => $activityRows,
      ]);
   }

   if ($ajaxAction === 'next_pallet_number') {
      $ajaxLocationId = (int) ($_GET['destination_location_id'] ?? ($_GET['warehouse_location_id'] ?? 0));
      if (!in_array($ajaxLocationId, $pallet_number_destination_ids, true)) {
         outwhse_json_response(['error' => 'Invalid destination location.'], 422);
      }

      outwhse_json_response([
         'destination_location_id' => $ajaxLocationId,
         'pallet_number' => outwhse_next_pallet_number($conn, $ajaxLocationId),
      ]);
   }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $return_location_id = (int) ($_POST['return_location_id'] ?? $selected_location_id);
   if (!in_array($return_location_id, outwhse_location_ids(), true)) {
      $return_location_id = 0;
   }

   $action = outwhse_post_value('action');
   $posted_token = (string) ($_POST['csrf_token'] ?? '');
   if (!hash_equals($csrfToken, $posted_token)) {
      $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Security check failed. Please try again.'];
      outwhse_redirect($BASE_URL, $return_location_id);
   }

   if ($action === 'save_warehouse_location') {
      $warehouse_name = outwhse_post_value('warehouse_name');
      $warehouse_code = outwhse_post_nullable_value('warehouse_code');
      $is_active = isset($_POST['is_active']) ? 1 : 0;

      $save_result = outwhse_warehouse_location_create($conn, $warehouse_name, $warehouse_code, $is_active);
      if (!($save_result['ok'] ?? false)) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => (string) ($save_result['message'] ?? 'Unable to save warehouse location.')];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      $created_location = (array) ($save_result['location'] ?? []);
      $created_name = trim((string) ($created_location['name'] ?? $warehouse_name));
      $created_code = trim((string) ($created_location['code'] ?? ''));
      $_SESSION['outwhse_flash'] = [
         'type' => 'success',
         'message' => $created_code !== ''
            ? 'Created warehouse location ' . $created_name . ' (' . $created_code . ').'
            : 'Created warehouse location ' . $created_name . '.',
      ];
      outwhse_redirect($BASE_URL, $return_location_id);
   }

   if ($action === 'save_address_book_entry') {
      if (!$address_book_table_available) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'The address book table is not available.'];
         outwhse_redirect($BASE_URL, $return_location_id, null, true);
      }

      $address_id = (int) ($_POST['address_id'] ?? 0);
      $address_type = strtolower(outwhse_post_value('address_type'));
      $company_name = outwhse_post_value('company_name');
      $contact_name = outwhse_post_nullable_value('contact_name');
      $phone = outwhse_post_nullable_value('phone');
      $email = outwhse_post_nullable_value('email');
      $address_line_1 = outwhse_post_value('address_line_1');
      $address_line_2 = outwhse_post_nullable_value('address_line_2');
      $city = outwhse_post_value('city');
      $province_state = outwhse_post_nullable_value('province_state');
      $postal_code = outwhse_post_nullable_value('postal_code');
      $country = outwhse_post_value('country');
      $notes = outwhse_post_nullable_value('notes');
      $is_active = isset($_POST['is_active']) ? 1 : 0;
      $is_default = isset($_POST['is_default']) ? 1 : 0;

      if (!isset($address_type_options[$address_type])) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a valid address type.'];
         outwhse_redirect($BASE_URL, $return_location_id, null, true, $address_id > 0 ? $address_id : null);
      }

      if ($company_name === '' || $address_line_1 === '' || $city === '' || $country === '') {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Company, address line 1, city, and country are required.'];
         outwhse_redirect($BASE_URL, $return_location_id, null, true, $address_id > 0 ? $address_id : null);
      }

      mysqli_begin_transaction($conn);
      $save_ok = true;

      if ($is_default === 1) {
         $default_stmt = $conn->prepare('UPDATE outwhse_address_book SET is_default = 0 WHERE address_type = ? AND id <> ?');
         if ($default_stmt) {
            $default_stmt->bind_param('si', $address_type, $address_id);
            $save_ok = $default_stmt->execute();
            $default_stmt->close();
         } else {
            $save_ok = false;
         }
      }

      if ($save_ok && $address_id > 0) {
         $update_stmt = $conn->prepare('
            UPDATE outwhse_address_book
            SET
               address_type = ?,
               company_name = ?,
               contact_name = ?,
               phone = ?,
               email = ?,
               address_line_1 = ?,
               address_line_2 = ?,
               city = ?,
               province_state = ?,
               postal_code = ?,
               country = ?,
               notes = ?,
               is_active = ?,
               is_default = ?
            WHERE id = ?
         ');
         if ($update_stmt) {
            $update_stmt->bind_param(
               'ssssssssssssiii',
               $address_type,
               $company_name,
               $contact_name,
               $phone,
               $email,
               $address_line_1,
               $address_line_2,
               $city,
               $province_state,
               $postal_code,
               $country,
               $notes,
               $is_active,
               $is_default,
               $address_id
            );
            $save_ok = $update_stmt->execute();
            $update_stmt->close();
         } else {
            $save_ok = false;
         }
      } elseif ($save_ok) {
         $insert_stmt = $conn->prepare('
            INSERT INTO outwhse_address_book (
               address_type,
               company_name,
               contact_name,
               phone,
               email,
               address_line_1,
               address_line_2,
               city,
               province_state,
               postal_code,
               country,
               notes,
               is_active,
               is_default
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ');
         if ($insert_stmt) {
            $insert_stmt->bind_param(
               'ssssssssssssii',
               $address_type,
               $company_name,
               $contact_name,
               $phone,
               $email,
               $address_line_1,
               $address_line_2,
               $city,
               $province_state,
               $postal_code,
               $country,
               $notes,
               $is_active,
               $is_default
            );
            $save_ok = $insert_stmt->execute();
            $address_id = $save_ok ? (int) $conn->insert_id : 0;
            $insert_stmt->close();
         } else {
            $save_ok = false;
         }
      }

      if ($save_ok) {
         mysqli_commit($conn);
         $_SESSION['outwhse_flash'] = ['type' => 'success', 'message' => $address_id > 0 ? 'Address book entry saved.' : 'Address book entry created.'];
         outwhse_redirect($BASE_URL, $return_location_id, null, true);
      }

      mysqli_rollback($conn);
      $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to save the address book entry.'];
      outwhse_redirect($BASE_URL, $return_location_id, null, true, $address_id > 0 ? $address_id : null);
   }

   if ($action === 'toggle_address_book_entry') {
      if (!$address_book_table_available) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'The address book table is not available.'];
         outwhse_redirect($BASE_URL, $return_location_id, null, true);
      }

      $address_id = (int) ($_POST['address_id'] ?? 0);
      $set_active = (int) ($_POST['set_active'] ?? 0) === 1 ? 1 : 0;
      if ($address_id < 1) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Address book entry was not found.'];
         outwhse_redirect($BASE_URL, $return_location_id, null, true);
      }

      $toggle_stmt = $conn->prepare('UPDATE outwhse_address_book SET is_active = ?, is_default = CASE WHEN ? = 0 THEN 0 ELSE is_default END WHERE id = ?');
      if (!$toggle_stmt) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to update the address book entry state.'];
         outwhse_redirect($BASE_URL, $return_location_id, null, true);
      }

      $toggle_stmt->bind_param('iii', $set_active, $set_active, $address_id);
      $toggle_ok = $toggle_stmt->execute();
      $toggle_stmt->close();

      $_SESSION['outwhse_flash'] = $toggle_ok
         ? ['type' => 'success', 'message' => $set_active === 1 ? 'Address entry activated.' : 'Address entry archived.']
         : ['type' => 'error', 'message' => 'Unable to update the address entry.'];
      outwhse_redirect($BASE_URL, $return_location_id, null, true);
   }

   if (!$table_available) {
      $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'The outwhse_inventory table is not available.'];
      outwhse_redirect($BASE_URL, $return_location_id);
   }

   if ($action === 'create_pallet_batch') {
      $item_location_id = (int) ($_POST['warehouse_location_id'] ?? 0);
      if ($item_location_id < 1) {
         $item_location_id = outwhse_warehouse_default_origin_location_id($conn);
      }
      $destination_location_id = (int) ($_POST['destination_location_id'] ?? 0);
      $pallet_number = '';
      $pallet_weight = outwhse_post_nullable_value('pallet_weight');
      $pallet_length_in = outwhse_post_nullable_value('pallet_length_in');
      $pallet_width_in = outwhse_post_nullable_value('pallet_width_in');
      $pallet_height_in = outwhse_post_nullable_value('pallet_height_in');
      $inventory_notes = outwhse_post_nullable_value('inventory_notes');
      $is_dangerous_good = outwhse_dg_is_enabled($_POST['is_dangerous_good'] ?? '0');
      $dg_class = $is_dangerous_good === 1 ? trim((string) ($_POST['dg_class'] ?? '')) : null;
      $un_number = $is_dangerous_good === 1 ? outwhse_dg_un_number_for_class($dg_class ?? '') : null;
      $status = outwhse_post_nullable_value('status');
      $space_status = outwhse_post_value('space_status');
      $batch_lines_json = (string) ($_POST['batch_lines_json'] ?? '');
      $draft_lines = json_decode($batch_lines_json, true);

      if (!in_array($item_location_id, outwhse_active_location_ids(), true)) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a valid warehouse location.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if (!in_array($destination_location_id, outwhse_active_location_ids(), true)) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a valid destination warehouse.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if (!in_array($destination_location_id, $pallet_number_destination_ids, true)) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Pallet numbers can only be generated for active warehouse destinations.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if ($destination_location_id === $item_location_id) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Destination warehouse must be different from the origin warehouse.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if (!in_array($space_status, $allowed_space_statuses, true)) {
         $space_status = 'in_storage';
      }

      if ($is_dangerous_good === 1 && ($dg_class === '' || $un_number === '')) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'DG Class is required and must map to a valid UN Number for Dangerous Goods pallets.'];
         outwhse_redirect($BASE_URL, $return_location_id > 0 ? $return_location_id : $item_location_id);
      }

      $pallet_number = outwhse_next_pallet_number($conn, $destination_location_id);

      if (!is_array($draft_lines) || empty($draft_lines)) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Add at least one line item before saving the pallet.'];
         outwhse_redirect($BASE_URL, $return_location_id > 0 ? $return_location_id : $item_location_id);
      }

      $next_line_number = outwhse_next_line_number($conn, $item_location_id, $pallet_number);

      $stmt = $conn->prepare('
         INSERT INTO outwhse_inventory (
            warehouse_location_id,
            origin_location_id,
            current_location_id,
            destination_location_id,
            is_disabled,
            item_source,
            sku,
            manual_label,
            manual_reference,
            manual_category,
            description,
            inventory_notes,
            lot_number,
            total_qty,
            unit_type,
            pallet_number,
            line_number,
            pallet_weight,
            pallet_length_in,
            pallet_width_in,
            pallet_height_in,
            is_dangerous_good,
            dg_class,
            un_number,
            status,
            space_status,
            movement_direction
         ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ');

      if (!$stmt) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to prepare the create-pallet statement.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      mysqli_begin_transaction($conn);
      $batch_ok = true;
      $batch_errno = 0;

      foreach (array_values($draft_lines) as $index => $line) {
         $item_source = trim((string) ($line['item_source'] ?? 'catalog'));
         $sku = strtoupper(trim((string) ($line['sku'] ?? '')));
         $manual_label = trim((string) ($line['manual_label'] ?? ''));
         $manual_reference = trim((string) ($line['manual_reference'] ?? ''));
         $manual_category = trim((string) ($line['manual_category'] ?? ''));
         $description = trim((string) ($line['description'] ?? ''));
         $lot_number = trim((string) ($line['lot_number'] ?? ''));
         $total_qty = trim((string) ($line['total_qty'] ?? ''));
         $unit_type = trim((string) ($line['unit_type'] ?? ''));

         $item_source = in_array($item_source, $allowed_item_sources, true) ? $item_source : 'catalog';
         $sku = $sku !== '' ? $sku : null;
         $manual_label = $manual_label !== '' ? $manual_label : null;
         $manual_reference = $manual_reference !== '' ? $manual_reference : null;
         $manual_category = $manual_category !== '' ? $manual_category : null;
         $description = $description !== '' ? $description : null;
         $lot_number = $lot_number !== '' ? $lot_number : null;
         $total_qty = $total_qty !== '' ? $total_qty : null;
         $unit_type = $unit_type !== '' ? $unit_type : null;

         if ($item_source === 'catalog') {
            if ($sku === null) {
               $batch_ok = false;
               break;
            }

            $catalogItem = outwhse_lookup_catalog_item($conn, $sku);
            $catalogDescription = $catalogItem['description'] ?? null;
            $catalogLotRequired = (bool) ($catalogItem['lot_required'] ?? false);

            if ($catalogDescription !== null) {
               $description = $catalogDescription;
            }
            $manual_label = null;
            $manual_reference = null;
            $manual_category = null;
         } else {
            if ($manual_label === null) {
               $batch_ok = false;
               break;
            }

            $sku = null;
            if ($description === null) {
               $description = $manual_label;
            }
         }

         $line_number = $next_line_number++;
         $movement_direction = outwhse_movement_direction_for_space_status($space_status);
         $stmt->bind_param(
            'iiiisssssssssssissssisssss',
            $item_location_id,
            $item_location_id,
            $item_location_id,
            $destination_location_id,
            $item_source,
            $sku,
            $manual_label,
            $manual_reference,
            $manual_category,
            $description,
            $inventory_notes,
            $lot_number,
            $total_qty,
            $unit_type,
            $pallet_number,
            $line_number,
            $pallet_weight,
            $pallet_length_in,
            $pallet_width_in,
            $pallet_height_in,
            $is_dangerous_good,
            $dg_class,
            $un_number,
            $status,
            $space_status,
            $movement_direction
         );

         if (!$stmt->execute()) {
            $batch_ok = false;
            $batch_errno = $stmt->errno;
            break;
         }
      }

      $stmt->close();

      if ($batch_ok) {
         mysqli_commit($conn);
         $_SESSION['outwhse_flash'] = ['type' => 'success', 'message' => 'Pallet created with ' . count($draft_lines) . ' line item(s).'];
      } else {
         mysqli_rollback($conn);
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => $batch_errno === 1062 ? 'That pallet already has one of the proposed line numbers.' : 'Unable to save the pallet contents.'];
      }

      outwhse_redirect($BASE_URL, $return_location_id > 0 ? $return_location_id : $item_location_id);
   }

   if (in_array($action, ['create_item', 'update_item'], true)) {
      $item_id = (int) ($_POST['id'] ?? 0);
      $item_location_id = (int) ($_POST['warehouse_location_id'] ?? 0);
      if ($action === 'create_item' && $item_location_id < 1) {
         $item_location_id = outwhse_warehouse_default_origin_location_id($conn);
      }
      $destination_location_id = (int) ($_POST['destination_location_id'] ?? 0);
      $item_source = outwhse_post_value('item_source');
      $sku = outwhse_post_nullable_value('sku');
      $sku = $sku !== null ? strtoupper($sku) : null;
      $manual_label = outwhse_post_nullable_value('manual_label');
      $manual_reference = outwhse_post_nullable_value('manual_reference');
      $manual_category = outwhse_post_value('manual_category');
      $description = outwhse_post_nullable_value('description');
      $inventory_notes = outwhse_post_nullable_value('inventory_notes');
      $lot_number = outwhse_post_nullable_value('lot_number');
      $total_qty = outwhse_post_nullable_value('total_qty');
      $unit_type = outwhse_post_nullable_value('unit_type');
      $status = outwhse_post_nullable_value('status');
      if ($status === null || $status === '') {
         $status = 'Active';
      }
      $pallet_number = outwhse_post_value('pallet_number');
      $line_number_input = outwhse_post_value('line_number');
      $pallet_weight = outwhse_post_nullable_value('pallet_weight');
      $pallet_length_in = outwhse_post_nullable_value('pallet_length_in');
      $pallet_width_in = outwhse_post_nullable_value('pallet_width_in');
      $pallet_height_in = outwhse_post_nullable_value('pallet_height_in');
      $is_dangerous_good = outwhse_dg_is_enabled($_POST['is_dangerous_good'] ?? '0');
      $dg_class = $is_dangerous_good === 1 ? trim((string) ($_POST['dg_class'] ?? '')) : null;
      $un_number = $is_dangerous_good === 1 ? outwhse_dg_un_number_for_class($dg_class ?? '') : null;
      $status = outwhse_post_nullable_value('status');
      if ($status === null || $status === '') {
         $status = 'Active';
      }
      $space_status = outwhse_post_value('space_status');

      if (!in_array($item_location_id, outwhse_active_location_ids(), true)) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a valid warehouse location.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if (!in_array($item_source, $allowed_item_sources, true)) {
         $item_source = 'catalog';
      }

      if ($is_dangerous_good === 1 && ($dg_class === '' || $un_number === '')) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'DG Class is required and must map to a valid UN Number for Dangerous Goods pallets.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if ($manual_category === '') {
         $manual_category = null;
      }

      if ($action === 'create_item' && $pallet_number === '') {
         if (!in_array($destination_location_id, $pallet_number_destination_ids, true)) {
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Pallet numbers can only be generated for active warehouse destinations.'];
            outwhse_redirect($BASE_URL, $return_location_id);
         }
         $pallet_number = outwhse_next_pallet_number($conn, $destination_location_id);
      } elseif ($pallet_number === '') {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Pallet number is required.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if ($action === 'update_item') {
         $existingStmt = $conn->prepare('
            SELECT
               pallet_number,
               line_number,
               destination_location_id,
               pallet_weight,
               pallet_length_in,
               pallet_width_in,
               pallet_height_in,
               is_dangerous_good,
               dg_class,
               un_number
            FROM outwhse_inventory
            WHERE id = ?
              AND is_disabled = 0
              AND pallet_is_active = 1
            LIMIT 1
         ');
         if (!$existingStmt) {
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to verify the existing pallet information.'];
            outwhse_redirect($BASE_URL, $return_location_id);
         }

         $existingStmt->bind_param('i', $item_id);
         $existingStmt->execute();
         $existingRows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($existingStmt) : [];
         $existingStmt->close();

         if (empty($existingRows)) {
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'That pallet line could not be found.'];
            outwhse_redirect($BASE_URL, $return_location_id);
         }

         $pallet_number = (string) ($existingRows[0]['pallet_number'] ?? '');
         $line_number_input = (string) ($existingRows[0]['line_number'] ?? '1');
         $destination_location_id = (int) ($existingRows[0]['destination_location_id'] ?? 0);
         $pallet_weight = ($existingRows[0]['pallet_weight'] ?? null) !== null ? (string) $existingRows[0]['pallet_weight'] : null;
         $pallet_length_in = ($existingRows[0]['pallet_length_in'] ?? null) !== null ? (string) $existingRows[0]['pallet_length_in'] : null;
         $pallet_width_in = ($existingRows[0]['pallet_width_in'] ?? null) !== null ? (string) $existingRows[0]['pallet_width_in'] : null;
         $pallet_height_in = ($existingRows[0]['pallet_height_in'] ?? null) !== null ? (string) $existingRows[0]['pallet_height_in'] : null;
         $is_dangerous_good = (int) ($existingRows[0]['is_dangerous_good'] ?? 0) === 1 ? 1 : 0;
         $dg_class = $is_dangerous_good === 1 ? (string) ($existingRows[0]['dg_class'] ?? '') : null;
         $un_number = $is_dangerous_good === 1 ? (string) ($existingRows[0]['un_number'] ?? '') : null;
      }

      if (!in_array($destination_location_id, outwhse_active_location_ids(), true)) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a valid destination warehouse.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if ($destination_location_id === $item_location_id) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Destination warehouse must be different from the origin warehouse.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if (!in_array($space_status, $allowed_space_statuses, true)) {
         $space_status = 'in_storage';
      }

      if ($item_source === 'catalog') {
         if ($sku === null) {
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'SKU is required for catalog items.'];
            outwhse_redirect($BASE_URL, $return_location_id);
         }

         $catalogItem = outwhse_lookup_catalog_item($conn, $sku);
         $catalogDescription = $catalogItem['description'] ?? null;
         $catalogLotRequired = (bool) ($catalogItem['lot_required'] ?? false);

         if ($catalogDescription !== null) {
            $description = $catalogDescription;
         }
         $manual_label = null;
         $manual_reference = null;
         $manual_category = null;
      } else {
         if ($manual_label === null) {
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Manual label is required for manual items.'];
            outwhse_redirect($BASE_URL, $return_location_id);
         }

         $sku = null;
         if ($description === null) {
            $description = $manual_label;
         }
      }

      $line_number = $line_number_input !== '' ? (int) $line_number_input : 0;
      if ($line_number < 1) {
         $line_number = outwhse_next_line_number($conn, $item_location_id, $pallet_number, $action === 'update_item' ? $item_id : null);
      }

      if ($action === 'create_item') {
         $stmt = $conn->prepare('
            INSERT INTO outwhse_inventory (
               warehouse_location_id,
               origin_location_id,
               current_location_id,
               destination_location_id,
               is_disabled,
               item_source,
               sku,
               manual_label,
               manual_reference,
               manual_category,
               description,
               inventory_notes,
               lot_number,
               total_qty,
               unit_type,
               pallet_number,
               line_number,
               pallet_weight,
               pallet_length_in,
               pallet_width_in,
               pallet_height_in,
               is_dangerous_good,
               dg_class,
               un_number,
               status,
               space_status,
               movement_direction
            ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ');

         if (!$stmt) {
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to prepare the add-item statement.'];
            outwhse_redirect($BASE_URL, $return_location_id);
         }

         $movement_direction = outwhse_movement_direction_for_space_status($space_status);
         $stmt->bind_param(
            'iiiisssssssssssissssisssss',
            $item_location_id,
            $item_location_id,
            $item_location_id,
            $destination_location_id,
            $item_source,
            $sku,
            $manual_label,
            $manual_reference,
            $manual_category,
            $description,
            $inventory_notes,
            $lot_number,
            $total_qty,
            $unit_type,
            $pallet_number,
            $line_number,
            $pallet_weight,
            $pallet_length_in,
            $pallet_width_in,
            $pallet_height_in,
            $is_dangerous_good,
            $dg_class,
            $un_number,
            $status,
            $space_status,
            $movement_direction
         );
         $ok = $stmt->execute();
         $errno = $stmt->errno;
         $stmt->close();

         $_SESSION['outwhse_flash'] = $ok
            ? ['type' => 'success', 'message' => 'Pallet line saved.']
            : ['type' => 'error', 'message' => $errno === 1062 ? 'That pallet line number is already in use for this warehouse and pallet.' : 'Unable to save the pallet line.'];
         outwhse_redirect($BASE_URL, $return_location_id > 0 ? $return_location_id : $item_location_id);
      }

      $stmt = $conn->prepare('
         UPDATE outwhse_inventory
         SET
            warehouse_location_id = ?,
            origin_location_id = ?,
            current_location_id = ?,
            destination_location_id = ?,
            item_source = ?,
            sku = ?,
            manual_label = ?,
            manual_reference = ?,
            manual_category = ?,
            description = ?,
            inventory_notes = ?,
            lot_number = ?,
            total_qty = ?,
            unit_type = ?,
            pallet_number = ?,
            line_number = ?,
            pallet_weight = ?,
            pallet_length_in = ?,
            pallet_width_in = ?,
            pallet_height_in = ?,
            is_dangerous_good = ?,
            dg_class = ?,
            un_number = ?,
            status = ?,
            space_status = ?,
            movement_direction = ?
         WHERE id = ?
           AND is_disabled = 0
           AND pallet_is_active = 1
      ');

      if (!$stmt) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to prepare the update statement.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      $movement_direction = outwhse_movement_direction_for_space_status($space_status);
      $stmt->bind_param(
         'iiiisssssssssssissssisssssi',
         $item_location_id,
         $item_location_id,
         $item_location_id,
         $destination_location_id,
         $item_source,
         $sku,
         $manual_label,
         $manual_reference,
         $manual_category,
         $description,
         $inventory_notes,
         $lot_number,
         $total_qty,
         $unit_type,
         $pallet_number,
         $line_number,
         $pallet_weight,
         $pallet_length_in,
         $pallet_width_in,
         $pallet_height_in,
         $is_dangerous_good,
         $dg_class,
         $un_number,
         $status,
         $space_status,
         $movement_direction,
         $item_id
      );
      $ok = $stmt->execute();
      $errno = $stmt->errno;
      $stmt->close();

      $_SESSION['outwhse_flash'] = $ok
         ? ['type' => 'success', 'message' => 'Pallet line updated.']
         : ['type' => 'error', 'message' => $errno === 1062 ? 'That pallet line number is already in use for this warehouse and pallet.' : 'Unable to update the pallet line.'];
      outwhse_redirect($BASE_URL, $return_location_id > 0 ? $return_location_id : $item_location_id);
   }

   if ($action === 'delete_item' || $action === 'deactivate_pallet') {
      $item_id = (int) ($_POST['id'] ?? 0);
      $active_status = outwhse_post_value('active_status');
      $statusResult = outwhse_update_pallet_active_status(
         $conn,
         $item_id,
         $active_status,
         $username
      );

      $_SESSION['outwhse_flash'] = ($statusResult['ok'] ?? false)
         ? ['type' => 'success', 'message' => (string) $statusResult['message']]
         : ['type' => 'error', 'message' => (string) $statusResult['message']];
      outwhse_redirect($BASE_URL, $return_location_id, null, false, null, true);
   }

   if ($action === 'reactivate_pallet') {
      $item_id = (int) ($_POST['id'] ?? 0);
      $reactivation_reason = outwhse_post_value('reactivation_reason');
      $reactivateResult = outwhse_reactivate_pallet(
         $conn,
         $item_id,
         $reactivation_reason,
         $username
      );

      $_SESSION['outwhse_flash'] = ($reactivateResult['ok'] ?? false)
         ? ['type' => 'success', 'message' => (string) $reactivateResult['message']]
         : ['type' => 'error', 'message' => (string) $reactivateResult['message']];
      outwhse_redirect($BASE_URL, $return_location_id, null, false, null, true);
   }

   if ($action === 'update_pallet') {
      $item_id = (int) ($_POST['id'] ?? 0);
      $active_status = outwhse_post_value('active_status');
      $pallet_weight = outwhse_post_nullable_value('pallet_weight');
      $pallet_length_in = outwhse_post_nullable_value('pallet_length_in');
      $pallet_width_in = outwhse_post_nullable_value('pallet_width_in');
      $pallet_height_in = outwhse_post_nullable_value('pallet_height_in');
      $lot_number = outwhse_post_nullable_value('lot_number');
      $inventory_notes = outwhse_post_nullable_value('inventory_notes');
      $is_dangerous_good = outwhse_dg_is_enabled($_POST['is_dangerous_good'] ?? '0');
      $dg_class = $is_dangerous_good === 1 ? trim((string) ($_POST['dg_class'] ?? '')) : null;
      $un_number = $is_dangerous_good === 1 ? outwhse_dg_un_number_for_class($dg_class ?? '') : null;

      if ($item_id < 1) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a valid pallet line first.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      if ($is_dangerous_good === 1 && ($dg_class === '' || $un_number === '')) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a valid DG class for dangerous goods pallets.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      $pallet_lookup_stmt = $conn->prepare('SELECT warehouse_location_id, pallet_number, item_source, sku, inventory_notes AS current_inventory_notes FROM outwhse_inventory WHERE id = ? AND is_disabled = 0 LIMIT 1');
      if (!$pallet_lookup_stmt) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to prepare the pallet lookup statement.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      $pallet_lookup_stmt->bind_param('i', $item_id);
      $pallet_lookup_stmt->execute();
      $pallet_lookup_stmt->bind_result($pallet_location_id, $pallet_number, $item_source, $sku, $current_inventory_notes);
      $pallet_row = $pallet_lookup_stmt->fetch()
         ? [
            'warehouse_location_id' => $pallet_location_id,
            'pallet_number' => $pallet_number,
            'item_source' => $item_source,
            'sku' => $sku,
            'current_inventory_notes' => $current_inventory_notes,
         ]
         : null;
      $pallet_lookup_stmt->close();

      $pallet_location_id = (int) ($pallet_row['warehouse_location_id'] ?? 0);
      $pallet_number = trim((string) ($pallet_row['pallet_number'] ?? ''));
      $item_source = (string) ($pallet_row['item_source'] ?? '');
      $sku = trim((string) ($pallet_row['sku'] ?? ''));
      $current_inventory_notes = trim((string) ($pallet_row['current_inventory_notes'] ?? ''));

      if ($pallet_location_id < 1 || $pallet_number === '') {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a valid pallet first.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      $lot_number_to_save = trim((string) ($lot_number ?? ''));
      if ($lot_number_to_save === '') {
         $lot_number_to_save = null;
      }

      $stmt = $conn->prepare('
         UPDATE outwhse_inventory
         SET
            pallet_weight = ?,
            pallet_length_in = ?,
            pallet_width_in = ?,
            pallet_height_in = ?,
            inventory_notes = ?,
            is_dangerous_good = ?,
            dg_class = ?,
            un_number = ?
         WHERE warehouse_location_id = ?
           AND pallet_number = ?
           AND is_disabled = 0
      ');
      if (!$stmt) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to prepare the pallet status update.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      $stmt->bind_param('sssssissis', $pallet_weight, $pallet_length_in, $pallet_width_in, $pallet_height_in, $inventory_notes, $is_dangerous_good, $dg_class, $un_number, $pallet_location_id, $pallet_number);
      $ok = $stmt->execute();
      $stmt->close();

      if ($ok && $lot_number_to_save !== null) {
         $lot_stmt = $conn->prepare('UPDATE outwhse_inventory SET lot_number = ? WHERE id = ? AND is_disabled = 0');
         if ($lot_stmt) {
            $lot_stmt->bind_param('si', $lot_number_to_save, $item_id);
            $lot_stmt->execute();
            $lot_stmt->close();
         }
      }

      $status_result = $ok
         ? outwhse_update_pallet_active_status(
            $conn,
            $item_id,
            $active_status,
            $username
         )
         : ['ok' => false, 'message' => 'Unable to update the pallet details.'];

      $save_ok = $ok && ($status_result['ok'] ?? false);
      $_SESSION['outwhse_flash'] = $save_ok
         ? ['type' => 'success', 'message' => 'Pallet updated. ' . (string) ($status_result['message'] ?? '')]
         : ['type' => 'error', 'message' => (string) ($status_result['message'] ?? 'Unable to update the pallet.')];
      outwhse_redirect($BASE_URL, $return_location_id);
   }

   if ($action === 'update_pallet_notes') {
      $item_id = (int) ($_POST['id'] ?? 0);
      $inventory_notes = outwhse_post_nullable_value('inventory_notes');
      $is_ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

      if ($item_id < 1) {
         if ($is_ajax) {
            outwhse_json_response([
               'success' => false,
               'message' => 'Choose a valid pallet line first.',
            ], 400);
         }

         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a valid pallet line first.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      $stmt = $conn->prepare('
         UPDATE outwhse_inventory
         SET inventory_notes = ?
         WHERE id = ?
           AND is_disabled = 0
           AND pallet_is_active = 1
      ');
      if (!$stmt) {
         if ($is_ajax) {
            outwhse_json_response([
               'success' => false,
               'message' => 'Unable to prepare the pallet notes update.',
            ], 500);
         }

         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to prepare the pallet notes update.'];
         outwhse_redirect($BASE_URL, $return_location_id);
      }

      $stmt->bind_param('si', $inventory_notes, $item_id);
      $ok = $stmt->execute();
      $stmt->close();

      if ($is_ajax) {
         outwhse_json_response([
            'success' => $ok,
            'message' => $ok ? 'Pallet note saved.' : 'Unable to update the pallet note.',
            'inventory_notes' => $inventory_notes ?? '',
            'item_id' => $item_id,
         ], $ok ? 200 : 500);
      }

      $_SESSION['outwhse_flash'] = $ok
         ? ['type' => 'success', 'message' => 'Pallet note saved.']
         : ['type' => 'error', 'message' => 'Unable to update the pallet note.'];
      outwhse_redirect($BASE_URL, $return_location_id);
   }

   if ($action === 'complete_bol') {
      $is_ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
      $fail_completion = static function (string $message, int $statusCode = 400) use ($is_ajax, $BASE_URL, $return_location_id): never {
         if ($is_ajax) {
            outwhse_json_response([
               'success' => false,
               'message' => $message,
            ], $statusCode);
         }

         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => $message];
         outwhse_redirect($BASE_URL, $return_location_id);
      };

      $idsJson = (string) ($_POST['ids_json'] ?? '');
      $bol_number = outwhse_post_value('bol_number');
      $decodedIds = json_decode($idsJson, true);
      $ids = [];

      if (is_array($decodedIds)) {
         foreach ($decodedIds as $decodedId) {
            $nextId = (int) $decodedId;
            if ($nextId > 0) {
               $ids[$nextId] = $nextId;
            }
         }
      }

      $ids = array_values($ids);

      if (empty($ids)) {
         $fail_completion('Select at least one pallet line before completing the BOL.');
      }

      if ($bol_number === '') {
         $bol_number = 'BOL-' . date('YmdHis');
      }

      $id_list = implode(',', array_map('intval', $ids));
      $selected_result = $conn->query('
         SELECT
            id,
            warehouse_location_id,
            origin_location_id,
            current_location_id,
            destination_location_id,
            item_source,
            sku,
            manual_label,
            manual_reference,
            manual_category,
            description,
            lot_number,
            total_qty,
            unit_type,
            pallet_number,
            space_status
         FROM outwhse_inventory
         WHERE is_disabled = 0
           AND pallet_is_active = 1
           AND space_status <> "on_route"
           AND id IN (' . $id_list . ')
      ');

      if (!$selected_result) {
         $fail_completion('Unable to load the selected pallet rows for BOL completion.');
      }

      $selected_rows = [];
      $blocked_rows = [];
      while ($row = mysqli_fetch_assoc($selected_result)) {
         $space_status = trim((string) ($row['space_status'] ?? ''));
         if ($space_status === 'on_route') {
            $blocked_rows[] = $row;
            continue;
         }

         $selected_rows[] = $row;
      }

      if (!empty($blocked_rows)) {
         $blocked_messages = [];
         foreach ($blocked_rows as $blocked_row) {
            $pallet_number = trim((string) ($blocked_row['pallet_number'] ?? ''));
            $bol_number = '';

            if ($pallet_number !== '') {
               $bol_stmt = $conn->prepare('SELECT reference_number FROM outwhse_inventory_movements WHERE pallet_number = ? AND space_status = ? ORDER BY id DESC LIMIT 1');
               if ($bol_stmt) {
                  $on_route_status = 'on_route';
                  $bol_stmt->bind_param('ss', $pallet_number, $on_route_status);
                  $bol_stmt->execute();
                  $bol_rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($bol_stmt) : [];
                  $bol_stmt->close();
                  $bol_number = trim((string) ($bol_rows[0]['reference_number'] ?? ''));
               }
            }

            if ($pallet_number !== '' && $bol_number !== '') {
               $blocked_messages[] = 'Pallet ' . $pallet_number . ' is already tagged to BOL #' . $bol_number . '.';
            } elseif ($pallet_number !== '') {
               $blocked_messages[] = 'Pallet ' . $pallet_number . ' is already tagged to a BOL or is currently on route.';
            } else {
               $blocked_messages[] = 'One or more selected pallets are already assigned to a BOL or are currently on route.';
            }
         }

         $fail_completion($blocked_messages[0] ?? 'One or more selected pallets are already assigned to a BOL or are currently on route.');
      }

      if (count($selected_rows) !== count($ids)) {
         $fail_completion('One or more selected pallets are already assigned to a BOL or are currently on route.');
      }

      if (empty($selected_rows)) {
         $fail_completion('No active pallet rows were found for the selected BOL.');
      }

      $posted_origin_location_id = (int) ($_POST['return_location_id'] ?? 0);
      $posted_destination_location_id = (int) ($_POST['destination_location_id'] ?? 0);

      if (!in_array($posted_destination_location_id, outwhse_active_location_ids(), true)) {
         $fail_completion('Select a valid destination warehouse for the BOL.');
      }

      $origin_location_ids = [];
      foreach ($selected_rows as $selected_row) {
         $current_location_id = (int) ($selected_row['current_location_id'] ?? 0);
         $warehouse_location_id = (int) ($selected_row['warehouse_location_id'] ?? 0);
         $stored_origin_location_id = (int) ($selected_row['origin_location_id'] ?? 0);

         // For stored inventory, warehouse_location_id is the authoritative
         // physical location. Older rows can contain stale current/origin IDs.
         $origin_location_id = $warehouse_location_id > 0
            ? $warehouse_location_id
            : ($current_location_id > 0 ? $current_location_id : $stored_origin_location_id);

         if ($origin_location_id > 0) {
            $origin_location_ids[$origin_location_id] = $origin_location_id;
         }
      }

      if (empty($origin_location_ids) && $posted_origin_location_id > 0) {
         if (!in_array($posted_origin_location_id, outwhse_location_ids(), true)) {
            $fail_completion('Select a valid origin warehouse for the BOL.');
         }
         $origin_location_ids[$posted_origin_location_id] = $posted_origin_location_id;
      }

      if (count($origin_location_ids) > 1) {
         $fail_completion('Select rows that share the same current warehouse.');
      }

      $origin_location_id = (int) (array_values($origin_location_ids)[0] ?? 0);
      $destination_location_id = $posted_destination_location_id;

      if ($origin_location_id < 1) {
         $fail_completion('Unable to determine the current warehouse for the selected pallets.');
      }

      if ($destination_location_id === $origin_location_id) {
         $fail_completion('Destination warehouse must be different from the current warehouse.');
      }

      $movement_table_has_tracking_columns =
         outwhse_column_exists($conn, 'outwhse_inventory_movements', 'origin_location_id') &&
         outwhse_column_exists($conn, 'outwhse_inventory_movements', 'current_location_id') &&
         outwhse_column_exists($conn, 'outwhse_inventory_movements', 'destination_location_id');

      mysqli_begin_transaction($conn);
      $complete_stmt = $conn->prepare('
         UPDATE outwhse_inventory
         SET
            origin_location_id = ?,
            current_location_id = ?,
            destination_location_id = ?,
            space_status = ?,
            movement_direction = ?
         WHERE id = ?
           AND is_disabled = 0
           AND pallet_is_active = 1
      ');
      if ($movement_table_has_tracking_columns) {
         $movement_stmt = $conn->prepare('
            INSERT INTO outwhse_inventory_movements (
               warehouse_location_id,
               origin_location_id,
               current_location_id,
               destination_location_id,
               movement_type,
               space_status,
               movement_direction,
               item_source,
               pallet_number,
               sku,
               manual_label,
               manual_reference,
               manual_category,
               description,
               lot_number,
               qty,
               unit_type,
               source_location_type,
               source_location_label,
               destination_location_type,
               destination_location_label,
               reference_number,
               notes,
               created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ');
      } else {
         $movement_stmt = $conn->prepare('
            INSERT INTO outwhse_inventory_movements (
               warehouse_location_id,
               movement_type,
               space_status,
               movement_direction,
               item_source,
               pallet_number,
               sku,
               manual_label,
               manual_reference,
               manual_category,
               description,
               lot_number,
               qty,
               unit_type,
               source_location_type,
               source_location_label,
               destination_location_type,
               destination_location_label,
               reference_number,
               notes,
               created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ');
      }
      if (!$complete_stmt || !$movement_stmt) {
         mysqli_rollback($conn);
         $fail_completion('Unable to prepare the BOL completion update.');
      }

      $next_status = 'on_route';

      // Keep the legacy values accepted by existing ENUM-based installations.
      // The actual origin/destination warehouse IDs carry the transfer route.
      $send_movement_type = 'to_3pl';
      $send_direction = 'to_3pl';
      $source_location_type = 'warehouse';
      $destination_location_type = 'warehouse';
      $updated_count = 0;
      foreach ($selected_rows as $selected_row) {
         $item_id = (int) ($selected_row['id'] ?? 0);
         $warehouse_location_id = (int) ($selected_row['warehouse_location_id'] ?? 0);
         $row_current_location_id = (int) ($selected_row['current_location_id'] ?? 0);
         $row_stored_origin_location_id = (int) ($selected_row['origin_location_id'] ?? 0);
         $row_origin_location_id = $warehouse_location_id > 0
            ? $warehouse_location_id
            : ($row_current_location_id > 0 ? $row_current_location_id : $row_stored_origin_location_id);
         $row_destination_location_id = $destination_location_id;

         if ($row_origin_location_id < 1 || $row_origin_location_id !== $origin_location_id) {
            mysqli_rollback($conn);
            $complete_stmt->close();
            $movement_stmt->close();
            $fail_completion('One or more selected pallets are no longer stored at the expected origin warehouse.');
         }
         $item_source = trim((string) ($selected_row['item_source'] ?? 'catalog'));
         $sku = trim((string) ($selected_row['sku'] ?? ''));
         $manual_label = trim((string) ($selected_row['manual_label'] ?? ''));
         $manual_reference = trim((string) ($selected_row['manual_reference'] ?? ''));
         $manual_category = trim((string) ($selected_row['manual_category'] ?? ''));
         $description = trim((string) ($selected_row['description'] ?? ''));
         $lot_number = trim((string) ($selected_row['lot_number'] ?? ''));
         $qty = trim((string) ($selected_row['total_qty'] ?? ''));
         $unit_type = trim((string) ($selected_row['unit_type'] ?? ''));
         $pallet_number = trim((string) ($selected_row['pallet_number'] ?? ''));

         $movement_direction = $send_direction;
         $complete_stmt->bind_param(
            'iiissi',
            $row_origin_location_id,
            $row_origin_location_id,
            $row_destination_location_id,
            $next_status,
            $movement_direction,
            $item_id
         );
         if (!$complete_stmt->execute()) {
            mysqli_rollback($conn);
            $complete_stmt->close();
            $movement_stmt->close();
            $fail_completion('Unable to complete the BOL for the selected pallets.');
         }

         $movement_type = $send_movement_type;
         $space_status = 'on_route';
         $source_location_label = outwhse_location_label($row_origin_location_id);
         $destination_location_label = outwhse_location_label($row_destination_location_id);
         $reference_number = $bol_number;
         $notes = 'BOL sent from ' . $source_location_label . ' to ' . $destination_location_label . '.';
         $created_by = $username !== '' ? $username : 'system';

         if ($movement_table_has_tracking_columns) {
            $movement_stmt->bind_param(
               'iiiissssssssssssssssssss',
               $warehouse_location_id,
               $row_origin_location_id,
               $row_origin_location_id,
               $row_destination_location_id,
               $movement_type,
               $space_status,
               $movement_direction,
               $item_source,
               $pallet_number,
               $sku,
               $manual_label,
               $manual_reference,
               $manual_category,
               $description,
               $lot_number,
               $qty,
               $unit_type,
               $source_location_type,
               $source_location_label,
               $destination_location_type,
               $destination_location_label,
               $reference_number,
               $notes,
               $created_by
            );
         } else {
            $movement_stmt->bind_param(
               'issssssssssssssssssss',
               $warehouse_location_id,
               $movement_type,
               $space_status,
               $movement_direction,
               $item_source,
               $pallet_number,
               $sku,
               $manual_label,
               $manual_reference,
               $manual_category,
               $description,
               $lot_number,
               $qty,
               $unit_type,
               $source_location_type,
               $source_location_label,
               $destination_location_type,
               $destination_location_label,
               $reference_number,
               $notes,
               $created_by
            );
         }

         if (!$movement_stmt->execute()) {
            mysqli_rollback($conn);
            $complete_stmt->close();
            $movement_stmt->close();
            $fail_completion('Unable to log the BOL shipment movement.');
         }

         $updated_count += $complete_stmt->affected_rows > 0 ? 1 : 0;
      }

      $complete_stmt->close();
      $movement_stmt->close();
      mysqli_commit($conn);

      $success_message = $updated_count > 0
         ? 'BOL sent and selected pallets moved on route.'
         : 'No pallet statuses needed to be changed.';

      if ($is_ajax) {
         $pdf_fields = [
            'bol_number' => $bol_number,
            'ship_date' => outwhse_post_value('ship_date') !== '' ? outwhse_post_value('ship_date') : date('Y-m-d'),
            'from_location' => outwhse_location_label($origin_location_id),
            'to_location' => outwhse_location_label($destination_location_id),
            'shipper_name' => outwhse_location_label($origin_location_id),
            'shipper_contact_name' => outwhse_post_value('shipper_contact_name'),
            'shipper_phone' => outwhse_post_value('shipper_phone'),
            'shipper_address_line_1' => outwhse_post_value('shipper_address_line_1'),
            'shipper_address_line_2' => outwhse_post_value('shipper_address_line_2'),
            'shipper_city' => outwhse_post_value('shipper_city'),
            'shipper_province_state' => outwhse_post_value('shipper_province_state'),
            'shipper_postal_code' => outwhse_post_value('shipper_postal_code'),
            'shipper_country' => outwhse_post_value('shipper_country'),
            'consignee_name' => outwhse_location_label($destination_location_id),
            'consignee_contact_name' => outwhse_post_value('consignee_contact_name'),
            'consignee_phone' => outwhse_post_value('consignee_phone'),
            'consignee_address_line_1' => outwhse_post_value('consignee_address_line_1'),
            'consignee_address_line_2' => outwhse_post_value('consignee_address_line_2'),
            'consignee_city' => outwhse_post_value('consignee_city'),
            'consignee_province_state' => outwhse_post_value('consignee_province_state'),
            'consignee_postal_code' => outwhse_post_value('consignee_postal_code'),
            'consignee_country' => outwhse_post_value('consignee_country'),
            'carrier_name' => outwhse_post_value('carrier_name'),
            'consignor_name' => outwhse_post_value('consignor_name'),
            'warehouse' => outwhse_location_label($origin_location_id),
            'item' => outwhse_post_value('item'),
            'description' => outwhse_post_value('description'),
            'qty' => outwhse_post_value('qty'),
            'unit' => outwhse_post_value('unit'),
            'pallet' => outwhse_post_value('pallet'),
            'line' => outwhse_post_value('line'),
            'pallet_weight' => outwhse_post_value('pallet_weight'),
            'pallet_count' => outwhse_post_value('pallet_count'),
            'special_instructions' => outwhse_post_value('special_instructions'),
            'line_description' => outwhse_post_value('line_description'),
            'lines_json' => (string) ($_POST['lines_json'] ?? ''),
            'dg_class' => outwhse_post_value('dg_class'),
            'un_number' => outwhse_post_value('un_number'),
            'consignor_date' => outwhse_post_value('consignor_date'),
         ];

         outwhse_json_response([
            'success' => true,
            'message' => $success_message,
            'bol_number' => $bol_number,
            'bol_pdf_url' => outwhse_bol_pdf_url_from_fields($pdf_fields, $BASE_URL),
         ]);
      }

      $_SESSION['outwhse_flash'] = ['type' => 'success', 'message' => $success_message];
      outwhse_redirect($BASE_URL, $return_location_id);
   }
}

$inventory_rows = [];
$sku_ledger_groups = [];
$inventory_total_rows = 0;
$inventory_total_pages = 1;
if ($table_available) {
   $sku_ledger_sql = '
      SELECT
         sku,
         description,
         warehouse_location_id,
         pallet_number,
         SUM(CAST(total_qty AS DECIMAL(12,2))) AS total_qty,
         COUNT(DISTINCT CASE WHEN COALESCE(pallet_number, "") <> "" THEN pallet_number END) AS pallet_count
      FROM outwhse_inventory
      WHERE is_disabled = 0
        AND pallet_is_active = 1
        AND space_status = "in_storage"
        AND sku IS NOT NULL
        AND TRIM(sku) <> ""
   ';

   if ($selected_location_id > 0) {
      $sku_ledger_sql .= ' AND warehouse_location_id = ?';
   }

   $sku_ledger_sql .= '
      GROUP BY
         sku,
         description,
         warehouse_location_id,
         pallet_number
      ORDER BY
         sku,
         warehouse_location_id,
         pallet_number';

   $sku_ledger_stmt = mysqli_prepare($conn, $sku_ledger_sql);
   if ($sku_ledger_stmt) {
      if ($selected_location_id > 0) {
         mysqli_stmt_bind_param($sku_ledger_stmt, 'i', $selected_location_id);
      }

      mysqli_stmt_execute($sku_ledger_stmt);
      $sku_ledger_result = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($sku_ledger_stmt) : [];
      mysqli_stmt_close($sku_ledger_stmt);

      foreach ($sku_ledger_result as $ledger_row) {
         $sku = strtoupper(trim((string) ($ledger_row['sku'] ?? '')));
         $description = trim((string) ($ledger_row['description'] ?? ''));
         $warehouse_id = (int) ($ledger_row['warehouse_location_id'] ?? 0);
         $warehouse_name = outwhse_location_label($warehouse_id);
         $pallet_number = trim((string) ($ledger_row['pallet_number'] ?? ''));
         $qty = (float) ($ledger_row['total_qty'] ?? 0);

         if ($sku === '' || $sku === 'N/A') {
            continue;
         }

         if ($pallet_number === '') {
            $pallet_number = 'N/A';
         }

         if (!isset($sku_ledger_groups[$sku])) {
            $sku_ledger_groups[$sku] = [
               'sku' => $sku,
               'description' => $description,
               'total_qty' => 0.0,
               'pallets' => [],
               'warehouses' => [],
               'details' => [],
            ];
         } elseif ($sku_ledger_groups[$sku]['description'] === '' && $description !== '') {
            $sku_ledger_groups[$sku]['description'] = $description;
         }

         $sku_ledger_groups[$sku]['total_qty'] += $qty;

         if ($pallet_number !== '' && strtoupper($pallet_number) !== 'N/A') {
            $sku_ledger_groups[$sku]['pallets'][strtoupper($pallet_number)] = true;
         }

         if ($warehouse_name !== '') {
            $sku_ledger_groups[$sku]['warehouses'][(string) $warehouse_id] = $warehouse_name;
         }

         $sku_ledger_groups[$sku]['details'][] = [
            'warehouse_name' => $warehouse_name,
            'warehouse_id' => $warehouse_id,
            'pallet_number' => $pallet_number,
            'qty' => $qty,
         ];
      }
   }

   $sku_ledger_summary = [
      'total_skus' => count($sku_ledger_groups),
      'total_units' => 0.0,
      'total_pallets' => 0,
      'warehouses' => 0,
   ];

   $warehouse_ids = [];
   foreach ($sku_ledger_groups as $ledger_group) {
      $sku_ledger_summary['total_units'] += (float) ($ledger_group['total_qty'] ?? 0);
      $sku_ledger_summary['total_pallets'] += count($ledger_group['pallets'] ?? []);
      foreach (($ledger_group['warehouses'] ?? []) as $warehouse_id => $warehouse_name) {
         if ((int) $warehouse_id > 0) {
            $warehouse_ids[(int) $warehouse_id] = (string) $warehouse_name;
         }
      }
   }
   $sku_ledger_summary['warehouses'] = count($warehouse_ids);
   $whereClause = ' FROM outwhse_inventory WHERE is_disabled = 0';

   if (!$show_inactive) {
      $whereClause .= ' AND pallet_is_active = 1';
   }

   if ($selected_location_id > 0) {
      $whereClause .= ' AND (warehouse_location_id = ? OR (space_status = "on_route" AND origin_location_id = ?))';
   }

   if ($space_status_filter !== 'all') {
      $whereClause .= ' AND space_status = ?';
   }

   if ($search_query !== '') {
      $whereClause .= ' AND (
         LOWER(COALESCE(pallet_number, "")) LIKE ?
         OR LOWER(COALESCE(sku, "")) LIKE ?
         OR LOWER(COALESCE(manual_label, "")) LIKE ?
         OR LOWER(COALESCE(description, "")) LIKE ?
         OR LOWER(COALESCE(lot_number, "")) LIKE ?
         OR LOWER(COALESCE(inventory_notes, "")) LIKE ?
         OR LOWER(COALESCE(manual_reference, "")) LIKE ?
         OR LOWER(COALESCE(status, "")) LIKE ?
      )';
   }

   $countSql = 'SELECT COUNT(*) AS row_count' . $whereClause;
   $countStmt = mysqli_prepare($conn, $countSql);
   if ($countStmt) {
      $countTypes = '';
      $countParams = [];
      if ($selected_location_id > 0) {
         $countTypes .= 'ii';
         $countParams[] = $selected_location_id;
         $countParams[] = $selected_location_id;
      }
      if ($space_status_filter !== 'all') {
         $countTypes .= 's';
         $countParams[] = $space_status_filter;
      }
      if ($search_query !== '') {
         $needle = '%' . mb_strtolower($search_query) . '%';
         $countTypes .= 'ssssssss';
         for ($i = 0; $i < 8; $i++) {
            $countParams[] = $needle;
         }
      }
      if ($countTypes !== '') {
         mysqli_stmt_bind_param($countStmt, $countTypes, ...$countParams);
      }
      mysqli_stmt_execute($countStmt);
      $countRows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($countStmt) : [];
      mysqli_stmt_close($countStmt);
      $inventory_total_rows = (int) ($countRows[0]['row_count'] ?? 0);
      $inventory_total_pages = max(1, (int) ceil($inventory_total_rows / $per_page));
      if ($current_page > $inventory_total_pages) {
         $current_page = $inventory_total_pages;
      }
   }

   $offset = ($current_page - 1) * $per_page;
   $sql = '
      SELECT
         id,
         warehouse_location_id,
         origin_location_id,
         current_location_id,
         destination_location_id,
         item_source,
         sku,
         CASE
            WHEN item_source = "catalog" AND sku IS NOT NULL AND sku <> "" THEN (
               SELECT lot_required
               FROM sku s
               WHERE s.sku_num = outwhse_inventory.sku
               LIMIT 1
            )
            ELSE 0
         END AS lot_required,
         manual_label,
         manual_reference,
         manual_category,
         description,
         inventory_notes,
         lot_number,
         total_qty,
         unit_type,
         pallet_number,
         line_number,
         pallet_weight,
         pallet_length_in,
         pallet_width_in,
         pallet_height_in,
         is_dangerous_good,
         dg_class,
         un_number,
         status,
         inventory_notes,
         import_status_raw,
         space_status,
         is_disabled,
         pallet_is_active,
         inactive_reason,
         inactive_notes,
         deactivated_at,
         deactivated_by,
         reactivated_at,
         reactivated_by,
         reactivation_reason,
         created_at,
         updated_at' . $whereClause . ' ORDER BY warehouse_location_id, pallet_number, line_number, COALESCE(sku, manual_label), id LIMIT ? OFFSET ?';
   $stmt = mysqli_prepare($conn, $sql);
   if (!$stmt) {
      die('Failed to prepare warehouse query.');
   }

   $types = '';
   $params = [];
   if ($selected_location_id > 0) {
      $types .= 'ii';
      $params[] = $selected_location_id;
      $params[] = $selected_location_id;
   }
   if ($space_status_filter !== 'all') {
      $types .= 's';
      $params[] = $space_status_filter;
   }
   if ($search_query !== '') {
      $needle = '%' . mb_strtolower($search_query) . '%';
      $types .= 'ssssssss';
      for ($i = 0; $i < 8; $i++) {
         $params[] = $needle;
      }
   }
   $types .= 'ii';
   $params[] = $per_page;
   $params[] = $offset;

   mysqli_stmt_bind_param($stmt, $types, ...$params);
   mysqli_stmt_execute($stmt);
   $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : [];
   mysqli_stmt_close($stmt);

   foreach ($rows as $row) {
      $status_key = outwhse_status_key($row['status'] ?? '');
      $space_status_key = outwhse_space_status_key($row['space_status'] ?? '');
      $item_display = outwhse_item_label($row);
      $source_label = ($row['item_source'] ?? 'catalog') === 'manual' ? 'Manual' : 'Catalog';
      $pallet_dimensions = array_filter([
         trim((string) ($row['pallet_length_in'] ?? '')),
         trim((string) ($row['pallet_width_in'] ?? '')),
         trim((string) ($row['pallet_height_in'] ?? '')),
      ], static fn($value) => $value !== '');

      $inventory_rows[] = [
         'id' => (int) ($row['id'] ?? 0),
         'warehouse_location_id' => (int) ($row['warehouse_location_id'] ?? 0),
         'warehouse_location_name' => outwhse_location_label((int) ($row['warehouse_location_id'] ?? 0)),
         'origin_location_id' => (int) (($row['origin_location_id'] ?? $row['warehouse_location_id'] ?? 0)),
         'origin_location_name' => outwhse_location_label((int) (($row['origin_location_id'] ?? $row['warehouse_location_id'] ?? 0))),
         'current_location_id' => (int) (($row['current_location_id'] ?? $row['warehouse_location_id'] ?? 0)),
         'current_location_name' => outwhse_location_label((int) (($row['current_location_id'] ?? $row['warehouse_location_id'] ?? 0))),
         'destination_location_id' => (int) (($row['destination_location_id'] ?? 0)),
         'destination_location_name' => outwhse_location_label((int) ($row['destination_location_id'] ?? 0)),
         'item_source' => (string) ($row['item_source'] ?? 'catalog'),
         'source_label' => $source_label,
         'sku' => (string) ($row['sku'] ?? ''),
         'lot_required' => (int) ($row['lot_required'] ?? 0) === 1,
         'item_display' => $item_display,
         'manual_label' => (string) ($row['manual_label'] ?? ''),
         'manual_reference' => (string) ($row['manual_reference'] ?? ''),
         'manual_category' => (string) ($row['manual_category'] ?? ''),
         'description' => (string) ($row['description'] ?? ''),
         'inventory_notes' => (string) ($row['inventory_notes'] ?? ''),
         'lot_raw' => (string) ($row['lot_number'] ?? ''),
         'qty_raw' => (string) ($row['total_qty'] ?? ''),
         'unit_raw' => (string) ($row['unit_type'] ?? ''),
         'pallet_raw' => (string) ($row['pallet_number'] ?? ''),
         'line_number_raw' => (string) ($row['line_number'] ?? '1'),
         'pallet_weight_raw' => (string) ($row['pallet_weight'] ?? ''),
         'pallet_length_raw' => (string) ($row['pallet_length_in'] ?? ''),
         'pallet_width_raw' => (string) ($row['pallet_width_in'] ?? ''),
         'pallet_height_raw' => (string) ($row['pallet_height_in'] ?? ''),
         'is_dangerous_good_raw' => (string) ($row['is_dangerous_good'] ?? '0'),
         'dg_class_raw' => (string) ($row['dg_class'] ?? ''),
         'un_number_raw' => (string) ($row['un_number'] ?? ''),
         'status_raw' => (string) ($row['status'] ?? ''),
         'import_status_raw' => (string) ($row['import_status_raw'] ?? ''),
         'space_status_raw' => (string) ($row['space_status'] ?? ''),
         'lot' => (string) (($row['lot_number'] ?? '') !== '' ? $row['lot_number'] : 'N/A'),
         'qty' => (string) (($row['total_qty'] ?? '') !== '' ? $row['total_qty'] : 'N/A'),
         'unit' => (string) (($row['unit_type'] ?? '') !== '' ? $row['unit_type'] : 'N/A'),
         'pallet' => (string) (($row['pallet_number'] ?? '') !== '' ? $row['pallet_number'] : 'N/A'),
         'line_number' => (int) ($row['line_number'] ?? 1),
         'status' => (string) (($row['status'] ?? '') !== '' ? $row['status'] : 'N/A'),
         'status_key' => $status_key,
         'status_kind' => outwhse_status_kind($status_key),
         'space_status' => outwhse_space_status_label($row['space_status'] ?? ''),
         'space_status_key' => $space_status_key,
         'pallet_weight' => (string) (($row['pallet_weight'] ?? '') !== '' ? $row['pallet_weight'] . ' lb' : 'N/A'),
         'pallet_dimensions' => !empty($pallet_dimensions) ? implode(' x ', $pallet_dimensions) . ' in' : 'N/A',
         'is_dangerous_good' => (int) ($row['is_dangerous_good'] ?? 0) === 1,
         'dg_class' => (string) ($row['dg_class'] ?? ''),
         'un_number' => (string) ($row['un_number'] ?? ''),
         'live_source' => 'inventory',
         'is_active' => (int) ($row['pallet_is_active'] ?? 1) === 1,
         'inactive_reason' => (string) ($row['inactive_reason'] ?? ''),
         'inactive_notes' => (string) ($row['inactive_notes'] ?? ''),
         'deactivated_at' => (string) ($row['deactivated_at'] ?? ''),
         'deactivated_by' => (string) ($row['deactivated_by'] ?? ''),
         'reactivated_at' => (string) ($row['reactivated_at'] ?? ''),
         'reactivated_by' => (string) ($row['reactivated_by'] ?? ''),
         'reactivation_reason' => (string) ($row['reactivation_reason'] ?? ''),
      ];
   }

   $existing_on_route_pallets = [];
   foreach ($inventory_rows as $inventory_row) {
      if (($inventory_row['space_status_key'] ?? '') === 'on_route') {
         $pallet_key = strtoupper(trim((string) ($inventory_row['pallet_raw'] ?? '')));
         if ($pallet_key !== '') {
            $existing_on_route_pallets[$pallet_key] = true;
         }
      }
   }

   foreach (outwhse_live_on_route_rows_from_movements($conn, $selected_location_id, $existing_on_route_pallets) as $movement_row) {
      $inventory_rows[] = $movement_row;
   }

}

$address_book_rows = [];
if ($address_book_table_available) {
   $address_stmt = $conn->prepare('
      SELECT
         id,
         address_type,
         company_name,
         contact_name,
         phone,
         email,
         address_line_1,
         address_line_2,
         city,
         province_state,
         postal_code,
         country,
         notes,
         is_active,
         is_default,
         created_at,
         updated_at
      FROM outwhse_address_book
      ORDER BY is_active DESC, address_type, company_name, id
   ');

   if ($address_stmt) {
      $address_stmt->execute();
      $address_book_rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($address_stmt) : [];
      $address_stmt->close();
   }
}

$address_book_active_count = 0;
$address_book_edit_row = null;
$address_book_active_options = [];
$address_book_default_ids = [
   'shipper' => '',
   'consignee' => '',
   'consignor' => '',
   'carrier' => '',
];
foreach ($address_book_rows as $address_row) {
   if ((int) ($address_row['is_active'] ?? 0) === 1) {
      $address_book_active_count++;
      $address_id = (int) ($address_row['id'] ?? 0);
      $address_type = strtolower(trim((string) ($address_row['address_type'] ?? 'other')));
      $company = trim((string) ($address_row['company_name'] ?? ''));
      $city = trim((string) ($address_row['city'] ?? ''));
      $province_state = trim((string) ($address_row['province_state'] ?? ''));
      $postal_code = trim((string) ($address_row['postal_code'] ?? ''));
      $summary_parts = array_filter([$city, $province_state, $postal_code], static function ($value): bool {
         return trim((string) $value) !== '';
      });
      $summary = !empty($summary_parts) ? implode(', ', $summary_parts) : '';
      $label = $company !== '' ? $company : ('Address #' . $address_id);
      if ($summary !== '') {
         $label .= ' - ' . $summary;
      }

      $address_book_active_options[] = [
         'id' => (string) $address_id,
         'type' => isset($address_type_options[$address_type]) ? $address_type : 'other',
         'label' => $label,
         'company_name' => $company,
         'contact_name' => trim((string) ($address_row['contact_name'] ?? '')),
         'phone' => trim((string) ($address_row['phone'] ?? '')),
         'email' => trim((string) ($address_row['email'] ?? '')),
         'address_line_1' => trim((string) ($address_row['address_line_1'] ?? '')),
         'address_line_2' => trim((string) ($address_row['address_line_2'] ?? '')),
         'city' => $city,
         'province_state' => $province_state,
         'postal_code' => $postal_code,
         'country' => trim((string) ($address_row['country'] ?? '')),
      ];

      if ((int) ($address_row['is_default'] ?? 0) === 1 && isset($address_book_default_ids[$address_type]) && $address_book_default_ids[$address_type] === '') {
         $address_book_default_ids[$address_type] = (string) $address_id;
      }
   }
   if ($address_book_edit_id > 0 && (int) ($address_row['id'] ?? 0) === $address_book_edit_id) {
      $address_book_edit_row = $address_row;
   }
}

$address_book_form_values = [
   'id' => '',
   'address_type' => 'consignee',
   'company_name' => '',
   'contact_name' => '',
   'phone' => '',
   'email' => '',
   'address_line_1' => '',
   'address_line_2' => '',
   'city' => '',
   'province_state' => '',
   'postal_code' => '',
   'country' => 'Canada',
   'notes' => '',
   'is_active' => '1',
   'is_default' => '0',
];

if (is_array($address_book_edit_row)) {
   $address_book_form_values = [
      'id' => (string) ($address_book_edit_row['id'] ?? ''),
      'address_type' => (string) ($address_book_edit_row['address_type'] ?? 'consignee'),
      'company_name' => (string) ($address_book_edit_row['company_name'] ?? ''),
      'contact_name' => (string) ($address_book_edit_row['contact_name'] ?? ''),
      'phone' => (string) ($address_book_edit_row['phone'] ?? ''),
      'email' => (string) ($address_book_edit_row['email'] ?? ''),
      'address_line_1' => (string) ($address_book_edit_row['address_line_1'] ?? ''),
      'address_line_2' => (string) ($address_book_edit_row['address_line_2'] ?? ''),
      'city' => (string) ($address_book_edit_row['city'] ?? ''),
      'province_state' => (string) ($address_book_edit_row['province_state'] ?? ''),
      'postal_code' => (string) ($address_book_edit_row['postal_code'] ?? ''),
      'country' => (string) ($address_book_edit_row['country'] ?? 'Canada'),
      'notes' => (string) ($address_book_edit_row['notes'] ?? ''),
      'is_active' => (int) ($address_book_edit_row['is_active'] ?? 0) === 1 ? '1' : '0',
      'is_default' => (int) ($address_book_edit_row['is_default'] ?? 0) === 1 ? '1' : '0',
   ];
}

$inventory_catalog_skus = [];
$lots_near_expiration_count = 0;
$skus_with_lot_numbers_count = 0;
$nearest_lot_expiration_date = null;
$space_status_counts = [
   'staged' => 0,
   'received' => 0,
   'on_route' => 0,
   'in_storage' => 0,
];
$live_distinct_items = [];
$live_in_storage_pallets = [];

foreach ($inventory_rows as $row) {
   if (!($row['is_active'] ?? true)) {
      continue;
   }

   $sku_value = strtoupper(trim((string) ($row['sku'] ?? '')));
   if ($sku_value !== '') {
      $inventory_catalog_skus[$sku_value] = true;
   }

   $item_key = strtoupper(trim((string) ($row['sku'] ?? '')));
   if ($item_key === '') {
      $item_key = strtoupper(trim((string) ($row['manual_label'] ?? '')));
   }
   if ($item_key === '') {
      $item_key = strtoupper(trim((string) ($row['item_display'] ?? '')));
   }
   if ($item_key === '') {
      $item_key = strtoupper(trim((string) ($row['manual_reference'] ?? '')));
   }
   if ($item_key !== '' && $item_key !== 'N/A') {
      $live_distinct_items[$item_key] = true;
   }

   if (($row['space_status_key'] ?? '') === 'in_storage') {
      $pallet_key = strtoupper(trim((string) ($row['pallet_raw'] ?? $row['pallet'] ?? '')));
      if ($pallet_key !== '' && $pallet_key !== 'N/A') {
         $live_in_storage_pallets[$pallet_key] = true;
      }
   }

   if (isset($space_status_counts[$row['space_status_key']])) {
      $space_status_counts[$row['space_status_key']]++;
   }
}

$lot_table_available = outwhse_table_exists($conn, 'lot');
if ($lot_table_available && !empty($inventory_catalog_skus)) {
   $sku_list = array_values(array_keys($inventory_catalog_skus));
   $sku_placeholders = implode(', ', array_fill(0, count($sku_list), '?'));
   $lot_sql = '
      SELECT
         s.sku_num AS sku_num,
         COUNT(*) AS lot_count,
         SUM(CASE WHEN l.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS near_expiry_count,
         MIN(CASE WHEN l.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN l.expiry_date ELSE NULL END) AS next_expiry_date
      FROM lot l
      INNER JOIN sku s ON s.id = l.sku_id
      WHERE s.sku_num IN (' . $sku_placeholders . ')
      GROUP BY s.sku_num
   ';

   $lot_stmt = $conn->prepare($lot_sql);
   if ($lot_stmt) {
      $types = str_repeat('s', count($sku_list));
      $lot_stmt->bind_param($types, ...$sku_list);
      $lot_stmt->execute();
      $lot_rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($lot_stmt) : [];
      $lot_stmt->close();

      foreach ($lot_rows as $lot_row) {
         if ((int) ($lot_row['lot_count'] ?? 0) > 0) {
            $skus_with_lot_numbers_count++;
         }
         $lots_near_expiration_count += (int) ($lot_row['near_expiry_count'] ?? 0);

         $expiry_raw = trim((string) ($lot_row['next_expiry_date'] ?? ''));
         if ($expiry_raw !== '') {
            $expiry_date = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry_raw);
            if ($expiry_date instanceof DateTimeImmutable && ($nearest_lot_expiration_date === null || $expiry_date < $nearest_lot_expiration_date)) {
               $nearest_lot_expiration_date = $expiry_date;
            }
         }
      }
   }
}

$summary_cards = [
   [
      'label' => 'Distinct Items',
      'value' => number_format(count($live_distinct_items)),
      'meta' => 'Unique live inventory items',
      'icon' => 'sku',
      'tone' => 'sku',
   ],
   [
      'label' => 'In Storage Pallets',
      'value' => number_format(count($live_in_storage_pallets)),
      'meta' => 'Live pallets currently in storage',
      'icon' => 'storage',
      'tone' => 'pallets',
   ],
   [
      'label' => 'Orders On Route',
      'value' => number_format((int) ($space_status_counts['on_route'] ?? 0)),
      'meta' => 'Open BOL rows currently on route',
      'icon' => 'alert',
      'tone' => 'transfer',
   ],
   [
      'label' => 'Lots Near Expiration',
      'value' => number_format($lots_near_expiration_count),
      // 'meta' => ($lot_table_available ? 'Next expiry: ' . ($nearest_lot_expiration_date instanceof DateTimeImmutable ? $nearest_lot_expiration_date->format('Y-m-d') : 'N/A') : 'Next expiry: N/A (lot table unavailable)') . ' | SKUs with lot numbers on file: ' . number_format($skus_with_lot_numbers_count),
      'meta' => ($lot_table_available ? 'Next expiry: ' . ($nearest_lot_expiration_date instanceof DateTimeImmutable ? $nearest_lot_expiration_date->format('Y-m-d') : 'N/A') : 'Next expiry: N/A (lot table unavailable)'),
      'icon' => 'alert',
      'tone' => 'damage',
   ],
];

$default_warehouse_location_id = outwhse_warehouse_default_location_id($conn);
$default_origin_location_id = (
   $selected_location_id > 0 &&
   in_array($selected_location_id, outwhse_active_location_ids(), true)
)
   ? $selected_location_id
   : outwhse_warehouse_default_origin_location_id($conn);
$default_destination_location_id = 0;
foreach ($warehouse_active_location_rows as $warehouse_row) {
   $warehouse_id = (int) ($warehouse_row['id'] ?? 0);
   if ($warehouse_id > 0 && $warehouse_id !== ($selected_location_id > 0 ? $selected_location_id : $default_warehouse_location_id)) {
      $default_destination_location_id = $warehouse_id;
      break;
   }
}
if ($default_destination_location_id === 0) {
   $default_destination_location_id = $default_warehouse_location_id;
}

$rail_items = [
   ['label' => 'All', 'icon' => 'All', 'location_id' => 0],
];
foreach ($warehouse_location_rows as $warehouse_row) {
   $warehouse_id = (int) ($warehouse_row['id'] ?? 0);
   if ($warehouse_id > 0) {
      $rail_items[] = [
         'label' => (string) ($warehouse_row['name'] ?? ('Location #' . $warehouse_id)),
         'icon' => outwhse_location_icon_name($warehouse_row),
         'location_id' => $warehouse_id,
      ];
   }
}

$create_form_defaults = [
   'warehouse_location_id' => (string) $default_origin_location_id,
   'destination_location_id' => (string) $default_destination_location_id,
   'item_source' => 'catalog',
   'sku' => '',
   'manual_label' => '',
   'manual_reference' => '',
   'manual_category' => '',
   'description' => '',
   'lot_number' => '',
   'total_qty' => '',
   'unit_type' => 'UNIT',
   'pallet_number' => '',
   'line_number' => '',
   'pallet_weight' => '',
   'pallet_length_in' => '',
   'pallet_width_in' => '',
   'pallet_height_in' => '',
   'is_dangerous_good' => '0',
   'dg_class' => '',
   'un_number' => '',
   'inventory_notes' => '',
   'space_status' => 'in_storage',
];

$base_page_url = outwhse_build_url($BASE_URL, $selected_location_id, null, false, null, $show_inactive);
$inactive_toggle_url = outwhse_build_url($BASE_URL, $selected_location_id, null, false, null, !$show_inactive);
$shipments_page_url = outwhse_movements_url($BASE_URL, $selected_location_id);
$address_book_page_url = outwhse_build_url($BASE_URL, $selected_location_id, null, true, null, $show_inactive);
$default_create_location_id = (int) $create_form_defaults['warehouse_location_id'];
$outwhse_page_data = [
   'basePageUrl' => $base_page_url,
   'ajaxUrl' => $BASE_URL . '/outwhse.php',
   'bolPdfUrl' => $BASE_URL . '/outwhse_bol_pdf.php',
   'createDefaults' => $create_form_defaults,
   'defaultWarehouseLocationId' => $default_warehouse_location_id,
   'warehouseLocationLabels' => $warehouse_location_labels,
   'warehouseLocationRows' => $warehouse_location_rows,
   'plusIcon' => outwhse_icon('plus'),
   'editIcon' => outwhse_icon('edit'),
   'openAddressBook' => $address_book_requested || $address_book_edit_id > 0,
   'addressBookOptions' => $address_book_active_options,
   'addressBookDefaults' => $address_book_default_ids,
];

$outwhseCssPath = __DIR__ . '/refactor_css/outwhse.css';
$outwhseCssVersion = is_file($outwhseCssPath) ? (string) filemtime($outwhseCssPath) : '1';
$outwhseJsPath = __DIR__ . '/js/outwhse.js';
$outwhseJsVersion = is_file($outwhseJsPath) ? (string) filemtime($outwhseJsPath) : '1';
$css = ['/refactor_css/outwhse.css?v=' . rawurlencode($outwhseCssVersion)];
$js = ['/js/outwhse.js?v=' . rawurlencode($outwhseJsVersion)];

ob_start();
?>

<?php if (is_array($flash) && !empty($flash['message'])): ?>
   <div class="outwhse-flash outwhse-flash--<?= h($flash['type'] ?? 'success') ?>">
      <?= h($flash['message']) ?>
   </div>
<?php endif; ?>

<section class="outwhse-shell" aria-label="Warehouse transfers">
   <aside class="outwhse-rail" aria-label="Warehouse locations">
      <div class="outwhse-rail__brand" aria-hidden="true">
         <div class="outwhse-rail__logo">
            <?= outwhse_icon('logo') ?>
         </div>
      </div>

      <nav class="outwhse-rail__nav" aria-label="Warehouse filters">
         <?php foreach ($rail_items as $item): ?>
            <?php $is_active = $selected_location_id === $item['location_id']; ?>
            <a class="outwhse-rail__item<?= $is_active ? ' is-active' : '' ?>"
               href="<?= h(outwhse_build_url($BASE_URL, $item['location_id'], null, false, null, $show_inactive)) ?>">
               <span class="outwhse-rail__icon"><?= outwhse_icon($item['icon']) ?></span>
               <span class="outwhse-rail__label"><?= h($item['label']) ?></span>
            </a>
         <?php endforeach; ?>
      </nav>
   </aside>

   <div class="outwhse-stage">
      <header class="outwhse-header">
         <div>
            <p class="outwhse-eyebrow">Warehouse oversight</p>
            <h1>Warehouse Transfers</h1>
            <p class="outwhse-subtitle">Manage live pallet lines across all warehouse locations, with storage state
               and pallet-level details built into each record.</p>
         </div>
         <div class="outwhse-header__actions">
            <a class="outwhse-btn" href="<?= h($shipments_page_url) ?>">
               <span aria-hidden="true"><?= outwhse_icon('history') ?></span>
               <span>Shipments</span>
            </a>
            <!-- <div class="outwhse-user">
               <span class="outwhse-user__label">View</span>
               <strong><?= h(outwhse_location_label($selected_location_id)) ?></strong>
            </div> -->
         </div>
      </header>

      <section class="outwhse-kpis" aria-label="Key metrics">
         <?php foreach ($summary_cards as $card): ?>
            <article class="outwhse-kpi outwhse-kpi--<?= h($card['tone']) ?>">
               <p class="outwhse-kpi__label"><?= h($card['label']) ?></p>
               <div class="outwhse-kpi__body">
                  <span class="outwhse-kpi__icon" aria-hidden="true"><?= outwhse_icon($card['icon']) ?></span>
                  <div class="outwhse-kpi__copy">
                     <strong class="outwhse-kpi__value"><?= h($card['value']) ?></strong>
                     <span class="outwhse-kpi__meta"><?= h($card['meta']) ?></span>
                  </div>
               </div>
            </article>
         <?php endforeach; ?>
      </section>

      <section class="outwhse-panel">
         <form class="outwhse-toolbar" method="get" action="<?= h($BASE_URL . '/outwhse.php') ?>">
            <input type="hidden" name="warehouse_location_id" value="<?= h((string) $selected_location_id) ?>">
            <input type="hidden" name="show_inactive" value="<?= $show_inactive ? '1' : '0' ?>">
            <input type="hidden" name="address_book" value="<?= $address_book_requested ? '1' : '0' ?>">
            <input type="hidden" name="page" value="<?= h((string) $current_page) ?>">
            <div class="outwhse-search outwhse-search--with-action">
               <label class="outwhse-search__field" for="outwhseSearch">
                  <input id="outwhseSearch" name="search" type="search" placeholder="Search"
                     value="<?= h($search_query) ?>">
               </label>
               <button class="outwhse-btn outwhse-btn--compact outwhse-search__action" type="submit" name="run_query"
                  value="1">Search</button>
            </div>

            <div class="outwhse-toolbar__actions">
               <label class="outwhse-filter" for="outwhseSpaceFilter">
                  <span class="outwhse-toolbar__icon" aria-hidden="true">
                     <svg viewBox="0 0 24 24">
                        <path d="M5 4h14l-5.5 7v6l-3 2v-8Z"></path>
                     </svg>
                  </span>
                   <select id="outwhseSpaceFilter" name="space_status">
                      <?php foreach ($space_filter_options as $value => $label): ?>
                         <option value="<?= h($value) ?>" <?= $space_status_filter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                   </select>
               </label>
               <a class="outwhse-btn outwhse-btn--compact<?= $show_inactive ? ' is-active' : '' ?>"
                  href="<?= h($inactive_toggle_url) ?>">
                  <?= $show_inactive ? 'Hide Inactive' : 'Show Inactive' ?>
               </a>
            </div>
         </form>

         <div class="outwhse-panel__controls" aria-label="Warehouse tools">
            <button class="outwhse-rail__tool outwhse-rail__tool--inline" type="button" id="outwhseAddLaunchBtn">
               <span class="outwhse-rail__tool-icon"><?= outwhse_icon('plus') ?></span>
               <span class="outwhse-rail__tool-label">Create Pallet</span>
            </button>
            <button class="outwhse-rail__tool outwhse-rail__tool--inline" type="button" id="outwhseStatusLaunchBtn">
               <span class="outwhse-rail__tool-icon"><?= outwhse_icon('alert') ?></span>
               <span class="outwhse-rail__tool-label">Edit Pallet</span>
            </button>
            <button class="outwhse-rail__tool outwhse-rail__tool--inline" type="button" id="outwhseTagLaunchBtn">
               <span class="outwhse-rail__tool-icon"><?= outwhse_icon('tag') ?></span>
               <span class="outwhse-rail__tool-label">Pallet Tag</span>
            </button>
            <button class="outwhse-rail__tool outwhse-rail__tool--inline" type="button" id="outwhseBolLaunchBtn">
               <span class="outwhse-rail__tool-icon"><?= outwhse_icon('bol') ?></span>
               <span class="outwhse-rail__tool-label">Ship Pallet</span>
            </button>
            <button class="outwhse-rail__tool outwhse-rail__tool--inline" type="button" id="outwhseSkuLedgerLaunchBtn">
               <span class="outwhse-rail__tool-icon"><?= outwhse_icon('pallets') ?></span>
               <span class="outwhse-rail__tool-label">SKU Ledger</span>
            </button>
            <button class="outwhse-rail__tool outwhse-rail__tool--inline" type="button"
               id="outwhseAddressBookLaunchBtn">
               <span class="outwhse-rail__tool-icon"><?= outwhse_icon('address-book') ?></span>
               <span class="outwhse-rail__tool-label">Address Book</span>
            </button>
            <button class="outwhse-rail__tool outwhse-rail__tool--inline" type="button" id="outwhseWarehouseLaunchBtn">
               <span class="outwhse-rail__tool-icon"><?= outwhse_icon('warehouse') ?></span>
               <span class="outwhse-rail__tool-label">Warehouses</span>
            </button>
         </div>

         <div class="outwhse-panel__meta">
            <span><strong id="outwhseVisibleCount"><?= count($inventory_rows) ?></strong> rows on page</span>
            <span><?= number_format($inventory_total_rows) ?> total matching rows</span>
            <span>Page <?= number_format($current_page) ?> of <?= number_format($inventory_total_pages) ?></span>
         </div>

         <div class="outwhse-table-wrap" id="outwhseTableWrap" <?= empty($inventory_rows) ? ' hidden' : '' ?>>
            <table class="outwhse-table" id="outwhseTable">
               <thead>
                  <tr>
                     <th class="outwhse-table__select-head">
                        <label class="outwhse-table__select-toggle" id="outwhseSelectAllRowsToggle"
                           for="outwhseSelectAllRows">
                           <input type="checkbox" id="outwhseSelectAllRows"
                              aria-label="Select or deselect all visible rows">
                        </label>
                     </th>
                     <th data-sort-key="pallet"><button class="outwhse-sort" type="button">Pallet #</button></th>
                     <th data-sort-key="item"><button class="outwhse-sort" type="button">Item</button></th>
                     <th data-sort-key="description"><button class="outwhse-sort" type="button">Description</button>
                     </th>
                     <th data-sort-key="qty"><button class="outwhse-sort" type="button">Qty</button></th>
                     <th data-sort-key="warehouse"><button class="outwhse-sort" type="button">Warehouse</button></th>
                     <th><button class="outwhse-sort" id="outwhseCommentsLaunchBtn" type="button" aria-label="Open comments for the selected pallet">Comments</button></th>
                     <th data-sort-key="space_status"><button class="outwhse-sort" type="button">Status</button></th>
                  </tr>
               </thead>
               <tbody>
                  <?php foreach ($inventory_rows as $row): ?>
                     <?php
                     $search_blob = strtolower(implode(' ', [
                        $row['warehouse_location_name'],
                        $row['item_display'],
                        $row['description'],
                        $row['lot'],
                        $row['qty'],
                        $row['unit'],
                        $row['pallet'],
                        (string) $row['line_number'],
                        (string) ($row['is_dangerous_good'] ?? '0') === '1' ? 'dangerous goods' : '',
                        $row['dg_class'],
                        $row['un_number'],
                        $row['space_status'],
                        (string) ($row['inactive_reason'] ?? ''),
                        (string) ($row['inactive_notes'] ?? ''),
                        $row['manual_reference'],
                        $row['manual_category'],
                        $row['pallet_weight'],
                        $row['pallet_dimensions'],
                     ]));
                     ?>
                     <tr class="<?= !($row['is_active'] ?? true) ? 'is-inactive' : '' ?>"
                        data-item-id="<?= h((string) $row['id']) ?>"
                        data-item-location-id="<?= h((string) $row['warehouse_location_id']) ?>"
                        data-is-active="<?= ($row['is_active'] ?? true) ? '1' : '0' ?>"
                        data-inactive-reason="<?= h((string) ($row['inactive_reason'] ?? '')) ?>"
                        data-inactive-notes="<?= h((string) ($row['inactive_notes'] ?? '')) ?>"
                        data-deactivated-at="<?= h((string) ($row['deactivated_at'] ?? '')) ?>"
                        data-deactivated-by="<?= h((string) ($row['deactivated_by'] ?? '')) ?>"
                        data-reactivated-at="<?= h((string) ($row['reactivated_at'] ?? '')) ?>"
                        data-reactivated-by="<?= h((string) ($row['reactivated_by'] ?? '')) ?>"
                        data-reactivation-reason="<?= h((string) ($row['reactivation_reason'] ?? '')) ?>"
                        data-status="<?= h($row['status_key']) ?>" data-space-status="<?= h($row['space_status_key']) ?>"
                        data-search="<?= h($search_blob) ?>"
                        data-sort-warehouse="<?= h(strtolower($row['warehouse_location_name'])) ?>"
                        data-sort-item="<?= h(strtolower($row['item_display'])) ?>"
                        data-sort-description="<?= h(strtolower($row['description'])) ?>"
                        data-sort-qty="<?= h((string) $row['qty_raw']) ?>"
                        data-sort-pallet="<?= h(strtolower($row['pallet'])) ?>"
                        data-sort-line="<?= h((string) $row['line_number']) ?>"
                        data-sort-space-status="<?= h(strtolower($row['space_status'])) ?>"
                        data-tag-warehouse="<?= h($row['warehouse_location_name']) ?>"
                        data-tag-item="<?= h($row['item_display']) ?>" data-tag-description="<?= h($row['description']) ?>"
                        data-tag-lot="<?= h($row['lot']) ?>" data-tag-qty="<?= h($row['qty']) ?>"
                        data-tag-unit="<?= h($row['unit']) ?>" data-tag-pallet="<?= h($row['pallet']) ?>"
                        data-tag-line="<?= h((string) $row['line_number']) ?>" data-sku="<?= h($row['sku']) ?>"
                        data-lot-required="<?= $row['lot_required'] ? '1' : '0' ?>"
                        data-lot-raw="<?= h($row['lot_raw']) ?>"
                        data-inventory-notes="<?= h((string) ($row['inventory_notes'] ?? '')) ?>"
                        data-import-status-raw="<?= h((string) ($row['import_status_raw'] ?? '')) ?>"
                        data-origin-location-id="<?= h((string) $row['origin_location_id']) ?>"
                        data-origin-location-name="<?= h($row['origin_location_name']) ?>"
                        data-current-location-id="<?= h((string) $row['current_location_id']) ?>"
                        data-current-location-name="<?= h($row['current_location_name']) ?>"
                        data-destination-location-id="<?= h((string) $row['destination_location_id']) ?>"
                        data-destination-location-name="<?= h($row['destination_location_name']) ?>"
                        data-edit-pallet-weight="<?= h((string) $row['pallet_weight_raw']) ?>"
                        data-edit-pallet-length="<?= h((string) $row['pallet_length_raw']) ?>"
                        data-edit-pallet-width="<?= h((string) $row['pallet_width_raw']) ?>"
                        data-edit-pallet-height="<?= h((string) $row['pallet_height_raw']) ?>"
                        data-is-dangerous-good="<?= $row['is_dangerous_good'] ? '1' : '0' ?>"
                        data-dg-class="<?= h($row['dg_class']) ?>" data-un-number="<?= h($row['un_number']) ?>"
                        data-status-raw="<?= h($row['status_raw']) ?>"
                        data-space-status-raw="<?= h($row['space_status_raw']) ?>"
                        data-live-source="<?= h((string) ($row['live_source'] ?? 'inventory')) ?>" tabindex="0"
                        role="button" aria-label="Select pallet line <?= h($row['item_display']) ?>">
                        <td class="outwhse-table__checkbox">
                           <input type="checkbox" class="outwhse-bol-select" data-bol-select
                              <?= ($row['space_status_key'] === 'on_route' || !($row['is_active'] ?? true)) ? 'disabled' : '' ?> aria-label="Select <?= h($row['item_display']) ?>">
                        </td>
                        <td><?= h($row['pallet']) ?></td>
                        <td class="outwhse-table__sku">
                           <span class="outwhse-table__item">
                              <span><?= h($row['item_display']) ?></span>
                              <?php if ($row['lot_required']): ?>
                                 <span class="outwhse-item-badge outwhse-item-badge--lot"
                                    data-lot="<?= h($row['lot_raw']) ?>">Lot</span>
                              <?php endif; ?>
                           </span>
                        </td>
                        <td><?= h($row['description']) ?></td>
                        <td><?= h($row['qty']) ?></td>
                        <td><?= h($row['warehouse_location_name']) ?></td>
                        <td>
                           <?php
                           $inventoryNotes = trim((string) ($row['inventory_notes'] ?? ''));
                           $inventoryNotesPreview = $inventoryNotes !== ''
                              ? (function_exists('mb_strimwidth')
                                 ? mb_strimwidth($inventoryNotes, 0, 8, '...')
                                 : (strlen($inventoryNotes) > 8 ? substr($inventoryNotes, 0, 8) . '...' : $inventoryNotes))
                              : 'No notes';
                           ?>
                           <span
                              class="outwhse-note-trigger<?= $inventoryNotes === '' ? ' outwhse-note-trigger--empty' : '' ?>"
                              role="button"
                              tabindex="0"
                              data-item-id="<?= h((string) $row['id']) ?>"
                              data-note="<?= h($inventoryNotes) ?>"
                              aria-label="<?= h($inventoryNotes !== '' ? 'View pallet note' : 'No pallet notes available') ?>"
                              aria-haspopup="dialog"
                              aria-controls="outwhseNotePopover">
                              <?= h($inventoryNotesPreview) ?>
                           </span>
                        </td>
                        <td>
                           <span class="outwhse-status-pill outwhse-status-pill--space outwhse-status-pill--space-<?= h($row['space_status_key']) ?>">
                              <?= h($row['space_status']) ?>
                           </span>
                        </td>
                     </tr>
                  <?php endforeach; ?>
               </tbody>
            </table>
         </div>

         <div class="outwhse-empty" id="outwhseEmpty" <?= !empty($inventory_rows) ? ' hidden' : '' ?>>
            <?php if (!$table_available): ?>
               The <code>outwhse_inventory</code> table was not found.
            <?php else: ?>
               <?= $show_inactive ? 'No inventory rows were found' : 'No active inventory rows were found' ?> in
               <code>outwhse_inventory</code> for this warehouse filter.
            <?php endif; ?>
         </div>

         <?php if ($inventory_total_pages > 1): ?>
            <?php
            $pager_params = [
               'run_query' => '1',
               'search' => $search_query,
               'space_status' => $space_status_filter,
               'page' => 1,
            ];
            if ($selected_location_id > 0) {
               $pager_params['warehouse_location_id'] = $selected_location_id;
            }
            if ($show_inactive) {
               $pager_params['show_inactive'] = '1';
            }
            $pager_start = max(1, $current_page - 2);
            $pager_end = min($inventory_total_pages, $current_page + 2);
            if (($pager_end - $pager_start) < 4) {
               if ($pager_start === 1) {
                  $pager_end = min($inventory_total_pages, $pager_start + 4);
               } elseif ($pager_end === $inventory_total_pages) {
                  $pager_start = max(1, $pager_end - 4);
               }
            }
            ?>
            <div class="outwhse-pagination">
               <?php if ($pager_start > 1): ?>
                  <?php
                  $first_page_params = $pager_params;
                  $first_page_params['page'] = 1;
                  ?>
                  <a class="outwhse-pagination__page" href="<?= h($BASE_URL . '/outwhse.php?' . http_build_query($first_page_params)) ?>">1</a>
                  <?php if ($pager_start > 2): ?>
                     <span class="outwhse-pagination__ellipsis" aria-hidden="true">…</span>
                  <?php endif; ?>
               <?php endif; ?>
               <?php for ($page = $pager_start; $page <= $pager_end; $page++): ?>
                  <?php
                  $page_params = $pager_params;
                  $page_params['page'] = $page;
                  $page_url = $BASE_URL . '/outwhse.php?' . http_build_query($page_params);
                  $is_current_page = $page === $current_page;
                  ?>
                  <a class="outwhse-pagination__page<?= $is_current_page ? ' is-active' : '' ?>"
                     href="<?= h($page_url) ?>"
                     <?= $is_current_page ? 'aria-current="page"' : '' ?>>
                     <?= number_format($page) ?>
                  </a>
               <?php endfor; ?>
               <?php if ($pager_end < $inventory_total_pages): ?>
                  <?php if ($pager_end < ($inventory_total_pages - 1)): ?>
                     <span class="outwhse-pagination__ellipsis" aria-hidden="true">…</span>
                  <?php endif; ?>
                  <?php
                  $last_page_params = $pager_params;
                  $last_page_params['page'] = $inventory_total_pages;
                  ?>
                  <a class="outwhse-pagination__page" href="<?= h($BASE_URL . '/outwhse.php?' . http_build_query($last_page_params)) ?>">
                     <?= number_format($inventory_total_pages) ?>
                  </a>
               <?php endif; ?>
            </div>
         <?php endif; ?>
      </section>
   </div>
</section>

<div id="outwhseAddressBookModal" class="outwhse-modal" hidden aria-hidden="true">
   <div class="outwhse-modal__backdrop" data-close-modal></div>
   <div class="outwhse-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="outwhseAddressBookTitle">
      <div class="outwhse-modal__header">
         <div>
            <p class="outwhse-eyebrow">Warehouse setup</p>
            <h2 class="outwhse-modal__title" id="outwhseAddressBookTitle">Address Book</h2>
            <p class="outwhse-modal__subtitle">Create, update, and archive shared addresses that can be used for either
               shipper or consignee on warehouse documents.</p>
         </div>
         <button class="outwhse-modal__close" type="button" data-close-modal aria-label="Close">Close</button>
      </div>
      <div class="outwhse-modal__body">
         <?php if (!$address_book_table_available): ?>
            <div class="outwhse-empty">The <code>outwhse_address_book</code> table is not available.</div>
         <?php else: ?>
            <section class="outwhse-admin">
               <div class="outwhse-admin__top">
                  <div>
                     <h2><?= $address_book_form_values['id'] !== '' ? 'Edit Address Entry' : 'Add Address Entry' ?></h2>
                     <p><strong><?= number_format($address_book_active_count) ?></strong> active address(es) in the book.
                     </p>
                  </div>
               </div>
               <form method="post" class="outwhse-admin__form">
                  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                  <input type="hidden" name="return_location_id" value="<?= h((string) $selected_location_id) ?>">
                  <input type="hidden" name="action" value="save_address_book_entry">
                  <input type="hidden" name="address_id" value="<?= h($address_book_form_values['id']) ?>">
                  <div class="outwhse-admin__grid">
                     <div class="outwhse-admin__field">
                        <label for="address_type">Address Type</label>
                        <select id="address_type" name="address_type" required>
                           <?php foreach ($address_type_options as $value => $label): ?>
                              <option value="<?= h($value) ?>" <?= $address_book_form_values['address_type'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                           <?php endforeach; ?>
                        </select>
                     </div>
                     <div class="outwhse-admin__field outwhse-admin__field--wide">
                        <label for="company_name">Company Name</label>
                        <input id="company_name" name="company_name" type="text"
                           value="<?= h($address_book_form_values['company_name']) ?>" required>
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="contact_name">Contact Name</label>
                        <input id="contact_name" name="contact_name" type="text"
                           value="<?= h($address_book_form_values['contact_name']) ?>">
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" type="text" value="<?= h($address_book_form_values['phone']) ?>">
                     </div>
                     <div class="outwhse-admin__field outwhse-admin__field--wide">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= h($address_book_form_values['email']) ?>">
                     </div>
                     <div class="outwhse-admin__field outwhse-admin__field--wide">
                        <label for="address_line_1">Address Line 1</label>
                        <input id="address_line_1" name="address_line_1" type="text"
                           value="<?= h($address_book_form_values['address_line_1']) ?>" required>
                     </div>
                     <div class="outwhse-admin__field outwhse-admin__field--wide">
                        <label for="address_line_2">Address Line 2</label>
                        <input id="address_line_2" name="address_line_2" type="text"
                           value="<?= h($address_book_form_values['address_line_2']) ?>">
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="city">City</label>
                        <input id="city" name="city" type="text" value="<?= h($address_book_form_values['city']) ?>"
                           required>
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="province_state">Province / State</label>
                        <input id="province_state" name="province_state" type="text"
                           value="<?= h($address_book_form_values['province_state']) ?>">
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="postal_code">Postal Code</label>
                        <input id="postal_code" name="postal_code" type="text"
                           value="<?= h($address_book_form_values['postal_code']) ?>">
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="country">Country</label>
                        <input id="country" name="country" type="text"
                           value="<?= h($address_book_form_values['country']) ?>" required>
                     </div>
                     <div class="outwhse-admin__field outwhse-admin__field--wide">
                        <label for="address_notes">Notes</label>
                        <textarea id="address_notes" name="notes"
                           rows="3"><?= h($address_book_form_values['notes']) ?></textarea>
                     </div>
                     <label class="outwhse-admin__field">
                        <span>Active</span>
                        <input name="is_active" type="checkbox" value="1" <?= $address_book_form_values['is_active'] === '1' ? ' checked' : '' ?>>
                     </label>
                     <label class="outwhse-admin__field">
                        <span>Default For Type</span>
                        <input name="is_default" type="checkbox" value="1" <?= $address_book_form_values['is_default'] === '1' ? ' checked' : '' ?>>
                     </label>
                  </div>
                  <div class="outwhse-admin__buttons">
                     <button class="outwhse-btn outwhse-btn--primary"
                        type="submit"><?= $address_book_form_values['id'] !== '' ? 'Update Address' : 'Save Address' ?></button>
                     <a class="outwhse-btn" href="<?= h($address_book_page_url) ?>">Clear</a>
                  </div>
               </form>
            </section>
            <section class="outwhse-panel">
               <div class="outwhse-panel__meta">
                  <span><?= number_format(count($address_book_rows)) ?> total address record(s)</span>
               </div>
               <div class="outwhse-table-wrap" <?= empty($address_book_rows) ? ' hidden' : '' ?>>
                  <table class="outwhse-table">
                     <thead>
                        <tr>
                           <th>Type</th>
                           <th>Company</th>
                           <th>Contact</th>
                           <th>Address</th>
                           <th>Status</th>
                           <th>Manage</th>
                        </tr>
                     </thead>
                     <tbody>
                        <?php foreach ($address_book_rows as $address_row): ?>
                           <?php
                           $address_row_id = (int) ($address_row['id'] ?? 0);
                           $is_row_active = (int) ($address_row['is_active'] ?? 0) === 1;
                           $is_row_default = (int) ($address_row['is_default'] ?? 0) === 1;
                           $address_display = trim((string) ($address_row['address_line_1'] ?? ''));
                           $address_line_2 = trim((string) ($address_row['address_line_2'] ?? ''));
                           if ($address_line_2 !== '') {
                              $address_display .= ', ' . $address_line_2;
                           }
                           $city_display = trim((string) ($address_row['city'] ?? ''));
                           $province_display = trim((string) ($address_row['province_state'] ?? ''));
                           $postal_display = trim((string) ($address_row['postal_code'] ?? ''));
                           $country_display = trim((string) ($address_row['country'] ?? ''));
                           $city_line = trim(implode(', ', array_filter([$city_display, $province_display, $postal_display])));
                           $address_edit_url = outwhse_build_url($BASE_URL, $selected_location_id, null, true, $address_row_id, $show_inactive);
                           ?>
                           <tr>
                              <td><?= h(ucfirst((string) ($address_row['address_type'] ?? 'other'))) ?></td>
                              <td>
                                 <strong><?= h((string) ($address_row['company_name'] ?? '')) ?></strong>
                                 <?php if ($is_row_default): ?>
                                    <div>Default</div>
                                 <?php endif; ?>
                              </td>
                              <td>
                                 <div><?= h((string) ($address_row['contact_name'] ?? '')) ?></div>
                                 <div><?= h((string) ($address_row['phone'] ?? '')) ?></div>
                                 <div><?= h((string) ($address_row['email'] ?? '')) ?></div>
                              </td>
                              <td>
                                 <div><?= h($address_display) ?></div>
                                 <div><?= h($city_line) ?></div>
                                 <div><?= h($country_display) ?></div>
                              </td>
                              <td><?= $is_row_active ? 'Active' : 'Archived' ?></td>
                              <td>
                                 <a class="outwhse-btn outwhse-btn--compact" href="<?= h($address_edit_url) ?>">Edit</a>
                                 <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="return_location_id"
                                       value="<?= h((string) $selected_location_id) ?>">
                                    <input type="hidden" name="action" value="toggle_address_book_entry">
                                    <input type="hidden" name="address_id" value="<?= h((string) $address_row_id) ?>">
                                    <input type="hidden" name="set_active" value="<?= $is_row_active ? '0' : '1' ?>">
                                    <button
                                       class="outwhse-btn outwhse-btn--compact<?= $is_row_active ? ' outwhse-btn--danger' : '' ?>"
                                       type="submit">
                                       <?= $is_row_active ? 'Archive' : 'Activate' ?>
                                    </button>
                                 </form>
                              </td>
                           </tr>
                        <?php endforeach; ?>
                     </tbody>
                  </table>
               </div>
               <?php if (empty($address_book_rows)): ?>
                  <div class="outwhse-empty">No address entries yet.</div>
               <?php endif; ?>
            </section>
         <?php endif; ?>
      </div>
   </div>
</div>

<div id="outwhseWarehouseModal" class="outwhse-modal outwhse-modal--compact" hidden aria-hidden="true">
   <div class="outwhse-modal__backdrop" data-close-modal></div>
   <div class="outwhse-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="outwhseWarehouseTitle">
      <div class="outwhse-modal__header">
         <div>
            <p class="outwhse-eyebrow">Warehouse setup</p>
            <h2 class="outwhse-modal__title" id="outwhseWarehouseTitle">Warehouses</h2>
            <p class="outwhse-modal__subtitle">Create new warehouse locations and reserve a unique pallet prefix code
               for each one.</p>
         </div>
         <button class="outwhse-modal__close" type="button" data-close-modal aria-label="Close">Close</button>
      </div>
      <div class="outwhse-modal__body">
         <section class="outwhse-admin">
            <div class="outwhse-admin__top">
               <div>
                  <h3>Create Warehouse</h3>
                  <p>Leave the code blank to auto-generate it from the warehouse name.</p>
               </div>
            </div>
            <form method="post" class="outwhse-admin__form">
               <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
               <input type="hidden" name="return_location_id" value="<?= h((string) $selected_location_id) ?>">
               <input type="hidden" name="action" value="save_warehouse_location">
               <div class="outwhse-admin__grid">
                  <div class="outwhse-admin__field outwhse-admin__field--wide">
                     <label for="warehouse_name">Warehouse Name</label>
                     <input id="warehouse_name" name="warehouse_name" type="text" required
                        placeholder="Dickson, Lachine, York, etc.">
                  </div>
                  <div class="outwhse-admin__field">
                     <label for="warehouse_code">Pallet Prefix Code</label>
                     <input id="warehouse_code" name="warehouse_code" type="text" maxlength="20"
                        placeholder="Auto-generated if blank">
                  </div>
                  <div class="outwhse-admin__field">
                     <label class="outwhse-admin__checkbox">
                        <input type="checkbox" name="is_active" checked>
                        <span>Active</span>
                     </label>
                  </div>
               </div>
               <div class="outwhse-admin__buttons">
                  <button class="outwhse-btn outwhse-btn--primary" type="submit">Save Warehouse</button>
                  <button class="outwhse-btn" type="button" data-close-modal>Cancel</button>
               </div>
            </form>
         </section>

         <section class="outwhse-admin">
            <div class="outwhse-admin__top">
               <div>
                  <h3>Current Warehouses</h3>
                  <p>These locations are used by the pallet and BOL selectors.</p>
               </div>
            </div>
            <div class="outwhse-table-wrap">
               <table class="outwhse-table outwhse-table--compact">
                  <thead>
                     <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Code</th>
                     </tr>
                  </thead>
                  <tbody>
                     <?php foreach ($warehouse_location_rows as $warehouse_row): ?>
                        <tr>
                           <td><?= h((string) ($warehouse_row['id'] ?? '')) ?></td>
                           <td><?= h((string) ($warehouse_row['name'] ?? '')) ?></td>
                           <td><?= h((string) ($warehouse_row['code'] ?? '')) ?></td>
                        </tr>
                     <?php endforeach; ?>
                  </tbody>
               </table>
            </div>
         </section>
      </div>
   </div>
</div>

<div id="outwhseLineModal" class="outwhse-modal" hidden aria-hidden="true">
   <div class="outwhse-modal__backdrop"></div>
   <div class="outwhse-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="outwhseLineModalTitle">
      <div class="outwhse-modal__header">
         <div>
            <p class="outwhse-eyebrow">Pallet workflow</p>
            <h2 class="outwhse-modal__title" id="outwhseLineModalTitle">Create Pallet</h2>
            <p class="outwhse-modal__subtitle" id="outwhseLineModalText">Create a warehouse pallet with catalog or
               manual item
               details, then confirm the label and BOL preview.</p>
         </div>
         <button class="outwhse-modal__close" type="button" data-close-modal aria-label="Close">Close</button>
      </div>
      <div class="outwhse-modal__body">
         <section class="outwhse-admin" id="lineItemForm">
            <?php if (!$table_available): ?>
               <div class="outwhse-empty">
                  The <code>outwhse_inventory</code> table is not available yet, so pallet creation is disabled.
               </div>
            <?php else: ?>
               <form method="post" class="outwhse-admin__form" id="outwhseLineForm">
                  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                  <input type="hidden" name="return_location_id" value="<?= h((string) $selected_location_id) ?>">
                  <input type="hidden" name="action" value="create_pallet_batch" id="outwhseLineAction">
                  <input type="hidden" name="id" value="" id="outwhseLineItemId">
                  <input type="hidden" name="batch_lines_json" value="" id="outwhseBatchLinesJson">
                  <div class="outwhse-wizard" id="outwhseWizard">
                     <div class="outwhse-wizard__steps" aria-label="Wizard progress">
                        <div class="outwhse-wizard__step is-active" data-wizard-tab="1">
                           <span class="outwhse-wizard__step-index">1</span>
                           <span class="outwhse-wizard__step-label">Line Items</span>
                        </div>
                        <div class="outwhse-wizard__step" data-wizard-tab="2">
                           <span class="outwhse-wizard__step-index">2</span>
                           <span class="outwhse-wizard__step-label">Pallet Info</span>
                        </div>
                        <div class="outwhse-wizard__step" data-wizard-tab="3">
                           <span class="outwhse-wizard__step-index">3</span>
                           <span class="outwhse-wizard__step-label">Pallet Tag</span>
                        </div>
                     </div>

                     <div class="outwhse-wizard__panel" data-wizard-step="1">
                        <div class="outwhse-admin__top">
                           <div>
                              <h2>Line Items</h2>
                              <p>Add one or more SKU/manual lines to the pallet before entering the shared pallet
                                 dimensions and status.</p>
                           </div>
                        </div>
                        <div class="outwhse-admin__grid">
                           <div class="outwhse-admin__field">
                              <label for="warehouse_location_id">Origin Location</label>
                              <select id="warehouse_location_id" name="warehouse_location_id" required>
                                 <?php foreach ($warehouse_active_location_rows as $warehouse_row): ?>
                                    <?php
                                    $warehouse_option_id = (int) ($warehouse_row['id'] ?? 0);
                                    $warehouse_option_name = (string) ($warehouse_row['name'] ?? ('Location #' . $warehouse_option_id));
                                    ?>
                                    <option value="<?= h((string) $warehouse_option_id) ?>"
                                       <?= $create_form_defaults['warehouse_location_id'] === (string) $warehouse_option_id ? ' selected' : '' ?>>
                                       <?= h($warehouse_option_name) ?>
                                    </option>
                                 <?php endforeach; ?>
                              </select>
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="destination_location_id">Destination Warehouse</label>
                              <select id="destination_location_id" name="destination_location_id" required>
                                 <?php foreach ($warehouse_active_location_rows as $warehouse_row): ?>
                                    <?php
                                    $warehouse_option_id = (int) ($warehouse_row['id'] ?? 0);
                                    $warehouse_option_name = (string) ($warehouse_row['name'] ?? ('Location #' . $warehouse_option_id));
                                    ?>
                                    <option value="<?= h((string) $warehouse_option_id) ?>"
                                       <?= $create_form_defaults['destination_location_id'] === (string) $warehouse_option_id ? ' selected' : '' ?>>
                                       <?= h($warehouse_option_name) ?>
                                    </option>
                                 <?php endforeach; ?>
                              </select>
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="item_source">Item Source</label>
                              <select id="item_source" name="item_source" required>
                                 <option value="catalog">Catalog</option>
                                 <option value="manual">Manual</option>
                              </select>
                           </div>

                           <div class="outwhse-admin__field" data-source-group="catalog">
                              <label for="sku">SKU</label>
                              <input id="sku" name="sku" type="text" value="<?= h($create_form_defaults['sku']) ?>">
                           </div>

                           <div class="outwhse-admin__field outwhse-admin__field--wide" data-source-group="manual" hidden>
                              <label for="manual_label">Manual Label</label>
                              <input id="manual_label" name="manual_label" type="text"
                                 value="<?= h($create_form_defaults['manual_label']) ?>"
                                 placeholder="Amazon Items, SRP Return - Mixed Items, etc.">
                           </div>

                           <div class="outwhse-admin__field" data-source-group="manual" hidden>
                              <label for="manual_reference">Manual Reference</label>
                              <input id="manual_reference" name="manual_reference" type="text"
                                 value="<?= h($create_form_defaults['manual_reference']) ?>"
                                 placeholder="PO0838, return ref, receipt note">
                           </div>

                           <div class="outwhse-admin__field" data-source-group="manual" hidden>
                              <label for="manual_category">Manual Category</label>
                              <select id="manual_category" name="manual_category">
                                 <?php foreach ($manual_category_options as $value => $label): ?>
                                    <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                 <?php endforeach; ?>
                              </select>
                           </div>

                           <div class="outwhse-admin__field outwhse-admin__field--wide">
                              <label for="description">Description</label>
                              <input id="description" name="description" type="text"
                                 value="<?= h($create_form_defaults['description']) ?>">
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="lot_number">Lot #</label>
                              <input id="lot_number" name="lot_number" type="date"
                                 value="<?= h($create_form_defaults['lot_number']) ?>" placeholder="MM-DD-YYYY">
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="total_qty">Total Qty</label>
                              <input id="total_qty" name="total_qty" type="text"
                                 value="<?= h($create_form_defaults['total_qty']) ?>">
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="unit_type">Unit Type</label>
                              <select id="unit_type" name="unit_type" required>
                                 <?php foreach ($unit_type_form_options as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $create_form_defaults['unit_type'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                                 <?php endforeach; ?>
                              </select>
                           </div>
                        </div>

                        <div class="outwhse-admin__buttons">
                           <button class="outwhse-btn outwhse-btn--primary" type="button" id="outwhseAddDraftLineBtn">
                              <span><?= outwhse_icon('plus') ?></span>
                              <span>Add Line Item</span>
                           </button>
                           <button class="outwhse-btn" type="button" id="outwhseResetDraftFieldsBtn">Clear Fields</button>
                        </div>

                        <section class="outwhse-draft-lines" id="outwhseDraftLinesSection">
                           <div class="outwhse-draft-lines__head">
                              <div>
                                 <h3>Pallet Contents</h3>
                                 <p>Each line added here will be saved onto the same pallet when you finish the wizard.</p>
                              </div>
                           </div>
                           <div class="outwhse-draft-lines__empty" id="outwhseDraftLinesEmpty">No line items added yet.
                           </div>
                           <div class="outwhse-table-wrap" id="outwhseDraftLinesWrap" hidden>
                              <table class="outwhse-table outwhse-table--draft">
                                 <thead>
                                    <tr>
                                       <th>Item</th>
                                       <th>Description</th>
                                       <th>Lot #</th>
                                       <th>Qty</th>
                                       <th>Unit</th>
                                       <th>Remove</th>
                                    </tr>
                                 </thead>
                                 <tbody id="outwhseDraftLinesBody"></tbody>
                              </table>
                           </div>
                        </section>
                     </div>

                     <div class="outwhse-wizard__panel" data-wizard-step="2" hidden>
                        <div class="outwhse-admin__top">
                           <div>
                              <h2>Pallet Info</h2>
                              <p>Enter the shared pallet number and dimensions after the pallet contents are complete.</p>
                           </div>
                        </div>

                        <div class="outwhse-admin__grid">
                           <div class="outwhse-admin__field">
                              <label for="pallet_number">Pallet #</label>
                              <input id="pallet_number" type="text" value="Auto-generated when saved" readonly>
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="line_number">Line #</label>
                              <input id="line_number" name="line_number" type="number" min="1" step="1"
                                 value="<?= h($create_form_defaults['line_number']) ?>"
                                 placeholder="Auto-next if left blank">
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="space_status">Status</label>
                              <select id="space_status" name="space_status" required>
                                 <?php foreach ($space_status_form_options as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $create_form_defaults['space_status'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                                 <?php endforeach; ?>
                              </select>
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="pallet_weight">Pallet Weight (lb)</label>
                              <input id="pallet_weight" name="pallet_weight" type="number" min="0" step="0.01" required
                                 value="<?= h($create_form_defaults['pallet_weight']) ?>">
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="pallet_length_in">Length (in)</label>
                              <input id="pallet_length_in" name="pallet_length_in" type="number" min="0" step="0.01"
                                 required value="<?= h($create_form_defaults['pallet_length_in']) ?>">
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="pallet_width_in">Width (in)</label>
                              <input id="pallet_width_in" name="pallet_width_in" type="number" min="0" step="0.01" required
                                 value="<?= h($create_form_defaults['pallet_width_in']) ?>">
                           </div>

                           <div class="outwhse-admin__field">
                              <label for="pallet_height_in">Height (in)</label>
                              <input id="pallet_height_in" name="pallet_height_in" type="number" min="0" step="0.01"
                                 required value="<?= h($create_form_defaults['pallet_height_in']) ?>">
                           </div>

                           <div class="outwhse-admin__field outwhse-admin__field--wide">
                              <label for="inventory_notes">Pallet Comments</label>
                              <textarea id="inventory_notes" name="inventory_notes" rows="3"
                                 placeholder="Optional pallet notes or import comments"><?= h($create_form_defaults['inventory_notes']) ?></textarea>
                           </div>

                           <div class="outwhse-admin__field outwhse-dg-group outwhse-admin__field--wide">
                              <span class="outwhse-dg-group__title">Dangerous Goods</span>
                              <div class="outwhse-dg-group__grid">
                                 <label class="outwhse-dg-group__toggle">
                                    <span>Dangerous Goods</span>
                                    <input id="is_dangerous_good" name="is_dangerous_good" type="checkbox" value="1"
                                       <?= $create_form_defaults['is_dangerous_good'] === '1' ? ' checked' : '' ?>>
                                 </label>

                                 <div class="outwhse-admin__field">
                                    <label for="dg_class">DG Class</label>
                                    <select id="dg_class" name="dg_class"
                                       <?= $create_form_defaults['is_dangerous_good'] === '1' ? '' : ' disabled' ?>>
                                       <option value="">None</option>
                                       <option value="2.1" <?= $create_form_defaults['dg_class'] === '2.1' ? ' selected' : '' ?>>2.1
                                          - Flammable Gas</option>
                                       <option value="9" <?= $create_form_defaults['dg_class'] === '9' ? ' selected' : '' ?>>9
                                          -
                                          Miscellaneous Dangerous Goods</option>
                                    </select>
                                 </div>

                                 <div class="outwhse-admin__field">
                                    <label for="un_number">UN Number</label>
                                    <select id="un_number" name="un_number"
                                       <?= $create_form_defaults['is_dangerous_good'] === '1' ? '' : ' disabled' ?>>
                                       <option value="">None</option>
                                       <option value="UN1057" <?= $create_form_defaults['un_number'] === 'UN1057' ? ' selected' : '' ?>>UN1057 - Lighters</option>
                                       <option value="UN3481" <?= $create_form_defaults['un_number'] === 'UN3481' ? ' selected' : '' ?>>UN3481 - Lithium ion batteries packed with equipment</option>
                                    </select>
                                 </div>
                              </div>
                           </div>
                        </div>
                     </div>

                     <div class="outwhse-wizard__panel" data-wizard-step="3" hidden>
                        <section class="outwhse-tag-builder">
                           <div class="outwhse-tag-builder__head">
                              <div>
                                 <h2>Pallet Tag Preview</h2>
                                 <p>Review the pallet tag that will be generated from the saved pallet contents before
                                    continuing.</p>
                              </div>
                           </div>

                           <article class="outwhse-label-preview">
                              <div class="outwhse-label-preview__brand">Turning Point Brands Canada</div>
                              <div class="outwhse-label-preview__hero">
                                 <p class="outwhse-label-preview__eyebrow">Pallet Tag</p>
                                 <h3 class="outwhse-label-preview__title" id="wizardTagDescription">-</h3>
                                 <p class="outwhse-label-preview__subtitle">SKU: <strong id="wizardTagItem">-</strong></p>
                              </div>
                              <div class="outwhse-label-preview__grid">
                                 <div class="outwhse-label-preview__cell">
                                    <span>Lot</span>
                                    <strong id="wizardTagLot">-</strong>
                                 </div>
                                 <div class="outwhse-label-preview__cell">
                                    <span>SKU</span>
                                    <strong id="wizardTagItemMirror">-</strong>
                                 </div>
                                 <div class="outwhse-label-preview__cell">
                                    <span>Pallet No.</span>
                                    <strong id="wizardTagPallet">-</strong>
                                 </div>
                                 <div class="outwhse-label-preview__cell">
                                    <span>Qty</span>
                                    <strong><span id="wizardTagQty">-</span> <span id="wizardTagUnit">-</span></strong>
                                 </div>
                              </div>
                              <div class="outwhse-label-preview__footer">
                                 <span>Warehouse: <strong id="wizardTagWarehouse">-</strong></span>
                                 <span>Line: <strong id="wizardTagLine">-</strong></span>
                              </div>
                              <div class="outwhse-tag-note" id="wizardTagNote">Update the details step if anything looks
                                 off before moving forward.</div>
                           </article>

                           <article class="outwhse-pallet-only-label">
                              <p class="outwhse-pallet-only-label__eyebrow">Pallet Number</p>
                              <strong class="outwhse-pallet-only-label__value" id="wizardTagPalletOnly">-</strong>
                           </article>
                        </section>
                     </div>

                  </div>

                  <div class="outwhse-admin__footer">
                     <div class="outwhse-admin__hint" id="outwhseWizardHint">Add at least one line item to the pallet
                        before moving to pallet info.</div>
                     <div class="outwhse-admin__buttons">
                        <button class="outwhse-btn" type="button" id="outwhseWizardPrevBtn" hidden>Back</button>
                        <button class="outwhse-btn" type="button" id="outwhseWizardNextBtn">Next</button>
                        <button class="outwhse-btn outwhse-btn--primary" type="submit" id="outwhseLineSubmitBtn" hidden>
                           <span id="outwhseLineSubmitIcon"><?= outwhse_icon('plus') ?></span>
                           <span id="outwhseLineSubmitText">Save Pallet</span>
                        </button>
                        <button class="outwhse-btn" type="button" data-close-modal>Cancel</button>
                     </div>
                  </div>
               </form>
            <?php endif; ?>
         </section>
      </div>
   </div>
</div>

<div id="outwhseStatusModal" class="outwhse-modal outwhse-modal--compact" hidden aria-hidden="true">
   <div class="outwhse-modal__backdrop" data-close-modal></div>
   <div class="outwhse-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="outwhseStatusTitle">
      <div class="outwhse-modal__header">
         <div>
            <h2 class="outwhse-modal__title" id="outwhseStatusTitle">Edit Pallet</h2>
         </div>
         <button class="outwhse-modal__close" type="button" data-close-modal aria-label="Close">Close</button>
      </div>
      <div class="outwhse-modal__body">
         <form method="post" class="outwhse-admin__form" id="outwhseStatusForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="return_location_id" value="<?= h((string) $selected_location_id) ?>">
            <input type="hidden" name="return_show_inactive" value="<?= $show_inactive ? '1' : '0' ?>">
            <input type="hidden" name="action" value="update_pallet" id="outwhseStatusAction">
            <input type="hidden" name="id" value="" id="outwhseStatusItemId">
            <div class="outwhse-admin__grid outwhse-edit-pallet__layout">
               <div class="outwhse-admin__field">
                  <label for="outwhseStatusPallet">Pallet #</label>
                  <input id="outwhseStatusPallet" type="text" readonly value="-">
               </div>
               <div class="outwhse-admin__field">
                  <label for="outwhseStatusItem">Item</label>
                  <input id="outwhseStatusItem" type="text" readonly value="-">
               </div>
               <div class="outwhse-admin__field">
                  <label for="outwhseStatusDescription">Description</label>
                  <input id="outwhseStatusDescription" type="text" readonly value="-">
               </div>
               <div class="outwhse-admin__field">
                  <label for="outwhseStatusLotNumber">Lot Date</label>
                  <input id="outwhseStatusLotNumber" name="lot_number" type="date">
               </div>
               <div class="outwhse-admin__field">
                  <label for="outwhseStatusSpaceDisplay">Current Location Status</label>
                  <input id="outwhseStatusSpaceDisplay" type="text" readonly value="-">
               </div>
               <div class="outwhse-admin__field outwhse-admin__field--wide">
                  <label for="outwhseStatusInventoryNotes">Pallet Comments</label>
                  <textarea id="outwhseStatusInventoryNotes" name="inventory_notes" rows="3"
                     placeholder="Pallet notes or import comments"></textarea>
               </div>
               <div
                  class="outwhse-edit-pallet__section outwhse-edit-pallet__section--dimensions outwhse-admin__field--wide">
                  <div class="outwhse-edit-pallet__dimensions-grid">
                     <div class="outwhse-admin__field">
                        <label for="outwhseEditPalletWeight">Pallet Weight (lb)</label>
                        <input id="outwhseEditPalletWeight" name="pallet_weight" type="number" min="0" step="0.01">
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="outwhseEditPalletLength">Length (in)</label>
                        <input id="outwhseEditPalletLength" name="pallet_length_in" type="number" min="0" step="0.01">
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="outwhseEditPalletWidth">Width (in)</label>
                        <input id="outwhseEditPalletWidth" name="pallet_width_in" type="number" min="0" step="0.01">
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="outwhseEditPalletHeight">Height (in)</label>
                        <input id="outwhseEditPalletHeight" name="pallet_height_in" type="number" min="0" step="0.01">
                     </div>
                  </div>
               </div>
               <div class="outwhse-edit-pallet__section outwhse-edit-pallet__section--dg outwhse-admin__field--wide">
                  <span class="outwhse-edit-pallet__group-title">Dangerous Goods</span>
                  <div class="outwhse-edit-pallet__dg-grid">
                     <label class="outwhse-edit-pallet__dg-checkbox">
                        <span>Dangerous Goods</span>
                        <input id="outwhseStatusIsDangerousGood" name="is_dangerous_good" type="checkbox" value="1">
                     </label>
                     <div class="outwhse-admin__field">
                        <label for="outwhseStatusDgClass">DG Class</label>
                        <select id="outwhseStatusDgClass" name="dg_class" disabled>
                           <option value="">None</option>
                           <option value="2.1">2.1 - Flammable Gas</option>
                           <option value="9">9 - Miscellaneous Dangerous Goods</option>
                        </select>
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="outwhseStatusUnNumber">UN Number</label>
                        <select id="outwhseStatusUnNumber" name="un_number" disabled>
                           <option value="">None</option>
                           <option value="UN1057">UN1057 - Lighters</option>
                           <option value="UN3481">UN3481 - Lithium ion batteries packed with equipment</option>
                        </select>
                     </div>
                  </div>
               </div>
               <div class="outwhse-edit-pallet__section outwhse-admin__field--wide" id="outwhsePalletLifecycleSection">
                  <span class="outwhse-edit-pallet__group-title">Pallet Activity</span>
                  <div class="outwhse-admin__grid">
                     <div class="outwhse-admin__field">
                        <label for="outwhsePalletActiveStatus">Active Status</label>
                        <select id="outwhsePalletActiveStatus" name="active_status">
                           <option value="active">Active</option>
                           <option value="inactive">Inactive</option>
                        </select>
                     </div>
                     <div class="outwhse-admin__field">
                        <label for="outwhsePalletLastActivity">Last Activity</label>
                        <input id="outwhsePalletLastActivity" type="text" readonly value="-">
                     </div>
                  </div>
                  <p class="outwhse-admin__hint" id="outwhsePalletLifecycleHint">
                     Set the pallet status and save the change.
                  </p>
               </div>
            </div>
            <section class="outwhse-modal__section">
               <div class="outwhse-modal__section-header">
                  <h3 class="outwhse-modal__section-title">Pallet Contents</h3>
               </div>
               <div class="outwhse-table-wrap">
                  <table class="outwhse-table outwhse-table--compact">
                     <thead>
                        <tr>
                           <th>Line</th>
                           <th>Item</th>
                           <th>Description</th>
                           <th>Qty</th>
                           <th>Lot</th>
                           <th>Notes</th>
                        </tr>
                     </thead>
                     <tbody id="outwhseStatusContentsBody">
                        <tr>
                           <td colspan="6">Select a pallet to load its contents.</td>
                        </tr>
                     </tbody>
                  </table>
               </div>
            </section>
            <section class="outwhse-modal__section">
               <div class="outwhse-modal__section-header">
                  <h3 class="outwhse-modal__section-title">Activation History</h3>
               </div>
               <div class="outwhse-table-wrap">
                  <table class="outwhse-table outwhse-table--compact">
                     <thead>
                        <tr>
                           <th>Activity</th>
                           <th>Warehouse</th>
                           <th>Reason</th>
                           <th>User</th>
                           <th>Date</th>
                        </tr>
                     </thead>
                     <tbody id="outwhsePalletActivityBody">
                        <tr>
                           <td colspan="5">No activation activity recorded.</td>
                        </tr>
                     </tbody>
                  </table>
               </div>
            </section>
            <div class="outwhse-admin__buttons">
               <button class="outwhse-btn outwhse-btn--primary" type="submit" id="outwhseStatusSubmitBtn">Save
                  Pallet</button>
               <button class="outwhse-btn" type="button" data-close-modal>Cancel</button>
            </div>
         </form>
      </div>
   </div>
</div>

<div id="outwhseTagModal" class="outwhse-modal" hidden aria-hidden="true">
   <div class="outwhse-modal__backdrop" data-close-modal></div>
   <div class="outwhse-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="outwhseTagTitle">
      <div class="outwhse-modal__header">
         <div>
            <h2 class="outwhse-modal__title" id="outwhseTagTitle">Print</h2>
            <p class="outwhse-modal__subtitle">Choose whether to print a BOL or pallet tags.</p>
         </div>
         <button class="outwhse-modal__close" type="button" data-close-modal aria-label="Close">Close</button>
      </div>
      <div class="outwhse-modal__body">
         <section class="outwhse-tag-builder" id="outwhseTagBuilder">
            <div class="outwhse-tag-builder__actions">
               <button class="outwhse-btn" type="button" id="outwhseTagPrintBtn">Print Tag</button>
            </div>

            <div class="outwhse-tag-preview-list" id="outwhseTagPreviewList">
               <article class="outwhse-label-preview">
                  <div class="outwhse-label-preview__brand">Turning Point Brands Canada</div>
                  <div class="outwhse-label-preview__hero">
                     <p class="outwhse-label-preview__eyebrow">Pallet Tag</p>
                     <h3 class="outwhse-label-preview__title">-</h3>
                     <p class="outwhse-label-preview__subtitle">SKU: <strong>-</strong></p>
                  </div>
                  <div class="outwhse-label-preview__grid">
                     <div class="outwhse-label-preview__cell">
                        <span>Lot</span>
                        <strong>-</strong>
                     </div>
                     <div class="outwhse-label-preview__cell">
                        <span>SKU</span>
                        <strong>-</strong>
                     </div>
                     <div class="outwhse-label-preview__cell">
                        <span>Pallet No.</span>
                        <strong>-</strong>
                     </div>
                     <div class="outwhse-label-preview__cell">
                        <span>Qty</span>
                        <strong>-</strong>
                     </div>
                  </div>
                  <div class="outwhse-label-preview__footer">
                     <span>Warehouse: <strong>Select one or more lines</strong></span>
                     <span>Line: <strong>-</strong></span>
                  </div>
               </article>
            </div>

            <div class="outwhse-tag-preview-list outwhse-tag-preview-list--pallet-only"
               id="outwhsePalletOnlyPreviewList">
               <article class="outwhse-pallet-only-label">
                  <p class="outwhse-pallet-only-label__eyebrow">Pallet Number</p>
                  <strong class="outwhse-pallet-only-label__value">-</strong>
               </article>
            </div>
            <div class="outwhse-tag-note" id="tagNote">Choose one or more rows using the Select checkboxes to build the
               pallet tag set.</div>
         </section>
      </div>
   </div>
</div>

<div id="outwhsePrintModal" class="outwhse-modal outwhse-modal--compact" hidden aria-hidden="true">
   <div class="outwhse-modal__backdrop" data-close-modal></div>
   <div class="outwhse-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="outwhsePrintTitle">
      <div class="outwhse-modal__header">
         <div>
            <h2 class="outwhse-modal__title" id="outwhsePrintTitle">Print</h2>
            <p class="outwhse-modal__subtitle">Choose the output you want to print.</p>
         </div>
         <button class="outwhse-modal__close" type="button" data-close-modal aria-label="Close">Close</button>
      </div>
      <div class="outwhse-modal__body">
         <div class="outwhse-admin__buttons">
            <button class="outwhse-btn outwhse-btn--primary" type="button" id="outwhsePrintBolBtn">Print BOL</button>
            <button class="outwhse-btn outwhse-btn--primary" type="button" id="outwhsePrintTagBtn">Print Pallet
               Tags</button>
            <button class="outwhse-btn" type="button" data-close-modal>Cancel</button>
         </div>
      </div>
   </div>
</div>

<div id="outwhseSkuLedgerModal" class="outwhse-modal" hidden aria-hidden="true">
   <div class="outwhse-modal__backdrop" data-close-modal></div>
   <div class="outwhse-modal__dialog outwhse-modal__dialog--wide sku-ledger-modal" role="dialog" aria-modal="true"
      aria-labelledby="outwhseSkuLedgerTitle">
      <div class="outwhse-modal__header">
         <div>
            <h2 class="outwhse-modal__title modal-title" id="outwhseSkuLedgerTitle">SKU Ledger</h2>
            <p class="outwhse-modal__subtitle ledger-subtitle">Current in-storage inventory grouped by SKU with
               pallet-level detail.</p>
         </div>
         <button class="outwhse-modal__close modal-close close-button" type="button" data-close-modal>Close</button>
      </div>
      <div class="outwhse-modal__body">
         <div class="sku-ledger-summary">
            <div class="sku-ledger-summary-card">
               <span>Total SKUs</span>
               <strong><?= h((string) $sku_ledger_summary['total_skus']) ?></strong>
            </div>
            <div class="sku-ledger-summary-card">
               <span>Total Units</span>
               <strong><?= h(number_format((float) $sku_ledger_summary['total_units'], 2)) ?></strong>
            </div>
            <div class="sku-ledger-summary-card">
               <span>Total Pallets</span>
               <strong><?= h((string) $sku_ledger_summary['total_pallets']) ?></strong>
            </div>
            <div class="sku-ledger-summary-card">
               <span>Warehouses</span>
               <strong><?= h((string) $sku_ledger_summary['warehouses']) ?></strong>
            </div>
         </div>

         <div class="outwhse-toolbar sku-ledger-filters">
            <label class="outwhse-search" for="outwhseSkuLedgerSearchSku">
               <span class="outwhse-toolbar__icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                     <circle cx="11" cy="11" r="6.5"></circle>
                     <path d="m16 16 4.5 4.5"></path>
                  </svg>
               </span>
               <input id="outwhseSkuLedgerSearchSku" type="search" placeholder="Search SKU #">
            </label>
            <label class="outwhse-search" for="outwhseSkuLedgerSearchWarehouse">
               <span class="outwhse-toolbar__icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                     <circle cx="11" cy="11" r="6.5"></circle>
                     <path d="m16 16 4.5 4.5"></path>
                  </svg>
               </span>
               <input id="outwhseSkuLedgerSearchWarehouse" type="search" placeholder="Search Warehouse #/Name">
            </label>
            <label class="outwhse-search" for="outwhseSkuLedgerSearchQty">
               <span class="outwhse-toolbar__icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                     <circle cx="11" cy="11" r="6.5"></circle>
                     <path d="m16 16 4.5 4.5"></path>
                  </svg>
               </span>
               <input id="outwhseSkuLedgerSearchQty" type="search" placeholder="Search Qty #">
            </label>
         </div>

         <?php if (!empty($sku_ledger_groups)): ?>
            <div class="outwhse-table-wrap outwhse-table-wrap--history sku-ledger-table-wrap">
               <table class="outwhse-table outwhse-table--history sku-ledger-table">
                  <thead>
                     <tr>
                        <th>SKU #</th>
                        <th>Description</th>
                        <th>Total Qty</th>
                        <th>Pallet Count</th>
                        <th>Warehouses</th>
                     </tr>
                  </thead>
                  <tbody id="outwhseSkuLedgerTableBody">
                     <?php foreach ($sku_ledger_groups as $sku => $group): ?>
                        <?php
                        $detail_search = [];
                        foreach ($group['details'] as $detail) {
                           $detail_search[] = (string) ($detail['warehouse_name'] ?? '');
                           $detail_search[] = (string) ($detail['pallet_number'] ?? '');
                           $detail_search[] = number_format((float) ($detail['qty'] ?? 0), 2, '.', '');
                        }

                        $search_blob = strtoupper(trim(implode(' ', array_filter([
                           (string) ($group['sku'] ?? ''),
                           (string) ($group['description'] ?? ''),
                           number_format((float) ($group['total_qty'] ?? 0), 2, '.', ''),
                           number_format(count($group['pallets'] ?? []), 0, '.', ''),
                           number_format(count($group['warehouses'] ?? []), 0, '.', ''),
                           implode(' ', $detail_search),
                        ], static fn($value) => trim((string) $value) !== ''))));

                        $ledger_qty_class = ((float) ($group['total_qty'] ?? 0)) === 0.0 ? 'qty-zero' : '';
                        $pallet_count = count($group['pallets'] ?? []);
                        $warehouse_count = count($group['warehouses'] ?? []);
                        ?>
                        <tr class="sku-ledger-parent" data-sku-parent="<?= h($sku) ?>"
                           data-ledger-search="<?= h($search_blob) ?>">
                           <td>
                              <button type="button" class="sku-ledger-toggle" data-sku-toggle="<?= h($sku) ?>"
                                 aria-expanded="false" aria-label="Expand SKU <?= h($sku) ?>">
                                 <span class="sku-ledger-chevron" aria-hidden="true">&#9654;</span>
                              </button>
                              <strong><?= h($group['sku']) ?></strong>
                           </td>
                           <td><?= h($group['description']) ?></td>
                           <td class="ledger-number <?= h($ledger_qty_class) ?>">
                              <?= h(number_format((float) $group['total_qty'], 2)) ?></td>
                           <td class="ledger-number"><?= h((string) $pallet_count) ?></td>
                           <td class="ledger-number"><?= h((string) $warehouse_count) ?></td>
                        </tr>
                        <tr class="sku-ledger-details-row" data-sku-details="<?= h($sku) ?>" hidden>
                           <td colspan="5">
                              <table class="sku-ledger-detail-table" aria-label="SKU <?= h($group['sku']) ?> pallet details">
                                 <thead>
                                    <tr>
                                       <th>Warehouse Name</th>
                                       <th>Pallet #</th>
                                       <th>Qty</th>
                                    </tr>
                                 </thead>
                                 <tbody>
                                    <?php foreach ($group['details'] as $detail): ?>
                                       <tr>
                                          <td><?= h((string) ($detail['warehouse_name'] ?? '')) ?></td>
                                          <td><?= h((string) ($detail['pallet_number'] ?? 'N/A')) ?></td>
                                          <td class="ledger-number"><?= h(number_format((float) ($detail['qty'] ?? 0), 2)) ?>
                                          </td>
                                       </tr>
                                    <?php endforeach; ?>
                                 </tbody>
                              </table>
                           </td>
                        </tr>
                     <?php endforeach; ?>
                  </tbody>
               </table>
            </div>
         <?php else: ?>
            <div class="outwhse-empty">No active SKU inventory rows were found.</div>
         <?php endif; ?>
      </div>
   </div>
</div>

<div id="outwhseBolModal" class="outwhse-modal" hidden aria-hidden="true">
   <div class="outwhse-modal__backdrop" data-close-modal></div>
   <div class="outwhse-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="outwhseBolTitle">
      <div class="outwhse-modal__header">
         <div>
            <p class="outwhse-eyebrow">Warehouse output</p>
            <h2 class="outwhse-modal__title" id="outwhseBolTitle">BOL Preview</h2>
         </div>
         <button class="outwhse-modal__close" type="button" data-close-modal aria-label="Close">Close</button>
      </div>
      <div class="outwhse-modal__body">
         <section class="outwhse-tag-builder outwhse-tag-builder--bol" id="outwhseBolBuilder">
            <div class="outwhse-tag-builder__head">
               <div class="outwhse-tag-builder__actions">
                  <button class="outwhse-btn" type="button" id="outwhseBolAddMoreBtn">Send BOL</button>
                  <button class="outwhse-btn" type="button" id="outwhseBolCopyBtn">Copy BOL</button>
                  <button class="outwhse-btn" type="button" id="outwhseBolPrintBtn">Print BOL</button>
               </div>
            </div>

            <article class="outwhse-tag-card" id="outwhseBolCard">
               <!-- <h3 class="outwhse-tag-card__title">Bill of Lading Draft</h3> -->
               <div class="outwhse-tag-card__meta">
                  <div class="outwhse-tag-field"><span>From</span><strong
                        id="bolFrom"><?= h(outwhse_location_label($default_warehouse_location_id)) ?></strong>
                     <label class="outwhse-tag-field outwhse-tag-field--input">
                        <span>To</span>
                        <select id="bolTo">
                           <option value="">Select destination</option>
                           <?php foreach ($warehouse_active_location_rows as $warehouse_row): ?>
                              <?php
                              $warehouse_option_id = (int) ($warehouse_row['id'] ?? 0);
                              $warehouse_option_name = (string) ($warehouse_row['name'] ?? ('Location #' . $warehouse_option_id));
                              ?>
                              <option value="<?= h((string) $warehouse_option_id) ?>"><?= h($warehouse_option_name) ?>
                              </option>
                           <?php endforeach; ?>
                        </select>
                     </label>
                  </div>
                  <!-- <div class="outwhse-tag-field"><span>Item / SKU</span><strong id="bolItem">-</strong></div>
                  <div class="outwhse-tag-field"><span>Description</span><strong id="bolDescription">-</strong></div>
                  <div class="outwhse-tag-field"><span>Total Qty</span><strong id="bolQty">-</strong></div>
                  <div class="outwhse-tag-field"><span>Unit Type</span><strong id="bolUnit">-</strong></div>
                  <div class="outwhse-tag-field"><span>Pallet #</span><strong id="bolPallet">-</strong></div> -->
                  <!-- <div class="outwhse-tag-field"><span>Line #</span><strong id="bolLine">-</strong></div> -->
                  <div class="outwhse-tag-field">
                     <label class="outwhse-tag-field outwhse-tag-field--input">
                        <span>Shipper Address</span>
                        <select id="bolShipperAddress" data-address-role="shipper"></select>
                     </label>
                     <label class="outwhse-tag-field outwhse-tag-field--input">
                        <span>Consignee Address</span>
                        <select id="bolConsigneeAddress" data-address-role="consignee"></select>
                     </label>
                  </div>
               </div>
               <div class="outwhse-table-wrap" id="bolLinesWrap" hidden>
                  <table class="outwhse-table outwhse-table--draft">
                     <thead>
                        <tr>
                           <!-- <th>Warehouse</th> -->
                           <th>Pallet #</th>
                           <th>Item</th>
                           <th>Description</th>
                           <th>Qty</th>
                           <th>DG?</th>
                           <th>DG Class</th>
                           <th>UN #</th>
                        </tr>
                     </thead>
                     <tbody id="bolLinesBody"></tbody>
                  </table>
               </div>
               <div class="outwhse-tag-note" id="bolNote">Choose a row using the selector in the table to build the BOL
                  draft.</div>
            </article>
         </section>
      </div>
   </div>
</div>

<div id="outwhseNotePopover" class="outwhse-note-popover" hidden role="dialog" aria-modal="false" aria-live="polite">
   <div class="outwhse-note-popover__header">
      <strong id="outwhseNotePopoverTitle">Pallet Note</strong>
      <button type="button" class="outwhse-note-popover__close" id="outwhseNotePopoverClose"
         aria-label="Close note">Close</button>
   </div>
   <textarea class="outwhse-note-popover__body" id="outwhseNotePopoverBody" rows="5"
      placeholder="Add a pallet note"></textarea>
   <div class="outwhse-note-popover__actions">
      <button type="button" class="outwhse-btn outwhse-btn--primary outwhse-note-popover__save"
         id="outwhseNotePopoverSave">Save Note</button>
   </div>
</div>

<script id="outwhsePageData"
   type="application/json"><?= json_encode($outwhse_page_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<script>
   (function () {
      'use strict';

      function initializePalletActivityControls() {
         const table = document.getElementById('outwhseTable');
         const statusModal = document.getElementById('outwhseStatusModal');
         const statusLaunchBtn = document.getElementById('outwhseStatusLaunchBtn');
         const form = document.getElementById('outwhseStatusForm');
         const actionInput = document.getElementById('outwhseStatusAction');
         const itemIdInput = document.getElementById('outwhseStatusItemId');
         const saveBtn = document.getElementById('outwhseStatusSubmitBtn');
         const activeStatus = document.getElementById('outwhsePalletActiveStatus');
         const lastActivity = document.getElementById('outwhsePalletLastActivity');
         const lifecycleHint = document.getElementById('outwhsePalletLifecycleHint');
         const activityBody = document.getElementById('outwhsePalletActivityBody');
         const pageDataElement = document.getElementById('outwhsePageData');

         if (!table) {
            return;
         }

         let lastSelectedRow = null;
         let pageData = {};
         try {
            pageData = JSON.parse(pageDataElement ? pageDataElement.textContent || '{}' : '{}');
         } catch (error) {
            pageData = {};
         }

         function selectedRow() {
            return table.querySelector('tbody tr.is-selected') || lastSelectedRow;
         }

         function renderEmptyActivity(message) {
            if (!activityBody) { return; }
            activityBody.textContent = '';
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 5;
            cell.textContent = message;
            row.appendChild(cell);
            activityBody.appendChild(row);
         }

         async function loadActivity(row) {
            if (!activityBody || !row || row.dataset.liveSource === 'movement' || !row.dataset.itemId || !pageData.ajaxUrl) {
               renderEmptyActivity('No activation activity recorded.');
               return;
            }
            renderEmptyActivity('Loading activity...');
            try {
               const url = new URL(pageData.ajaxUrl, window.location.href);
               url.searchParams.set('ajax', 'pallet_activity');
               url.searchParams.set('item_id', row.dataset.itemId);
               const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
               const payload = await response.json();
               if (!response.ok) throw new Error(payload.error || 'Unable to load pallet activity.');
               const activities = Array.isArray(payload.activities) ? payload.activities : [];
               if (activities.length === 0) { renderEmptyActivity('No activation activity recorded.'); return; }
               activityBody.textContent = '';
               activities.forEach(function (activity) {
                  const tr = document.createElement('tr');
                  const cells = [activity.activity_type === 'reactivated' ? 'Reactivated' : 'Deactivated', String(activity.warehouse_name || '-'), String(activity.reason_label || '-'), String(activity.changed_by || '-'), String(activity.changed_at || '-')];
                  cells.forEach(function (value) { const td = document.createElement('td'); td.textContent = value; tr.appendChild(td); });
                  activityBody.appendChild(tr);
               });
            } catch (error) {
               renderEmptyActivity(error instanceof Error ? error.message : 'Unable to load pallet activity.');
            }
         }

         function syncLifecycle() {
            const row = selectedRow(); if (!row) { return; }
            const isActive = row.dataset.isActive !== '0';
            const isInventoryRow = row.dataset.liveSource !== 'movement';
            const warehouseName = String(row.dataset.tagWarehouse || '').trim();
            const deactivatedAt = String(row.dataset.deactivatedAt || '').trim();
            const deactivatedBy = String(row.dataset.deactivatedBy || '').trim();
            const reactivatedAt = String(row.dataset.reactivatedAt || '').trim();
            const reactivatedBy = String(row.dataset.reactivatedBy || '').trim();
            if (activeStatus) { activeStatus.value = isActive ? 'active' : 'inactive'; }
            if (lastActivity) {
               if (!isActive && deactivatedAt) lastActivity.value = 'Deactivated ' + deactivatedAt + (deactivatedBy ? ' by ' + deactivatedBy : '');
               else if (isActive && reactivatedAt) lastActivity.value = 'Reactivated ' + reactivatedAt + (reactivatedBy ? ' by ' + reactivatedBy : '');
               else lastActivity.value = '-';
            }
            if (saveBtn) saveBtn.hidden = !isInventoryRow;
            if (!isInventoryRow) { if (lifecycleHint) lifecycleHint.textContent = 'This row is reconstructed from shipment history and cannot be changed here.'; renderEmptyActivity('Activation activity is available on the live pallet record.'); return; }
            if (lifecycleHint) lifecycleHint.textContent = warehouseName.toUpperCase() === 'TPB' ? 'Set the pallet status and save the change.' : 'Set the pallet status and save the change.';
            loadActivity(row);
         }

         if (statusModal && form && actionInput && itemIdInput) {
            table.querySelectorAll('tbody tr').forEach(function (row) {
               row.addEventListener('click', function () {
                  lastSelectedRow = row;
                  window.setTimeout(syncLifecycle, 0);
               });
            });
            if (statusLaunchBtn) {
               statusLaunchBtn.addEventListener('click', function () { window.setTimeout(syncLifecycle, 0); });
            }
            const modalObserver = new MutationObserver(function () { if (!statusModal.hidden) window.setTimeout(syncLifecycle, 0); });
            modalObserver.observe(statusModal, { attributes: true, attributeFilter: ['hidden', 'aria-hidden'] });
            form.addEventListener('submit', function () { if (activeStatus && activeStatus.value !== 'active' && activeStatus.value !== 'inactive') { activeStatus.value = 'active'; } });
         }

      }

      if (document.readyState === 'loading') {
         document.addEventListener('DOMContentLoaded', initializePalletActivityControls);
      } else {
         initializePalletActivityControls();
      }
   }());
</script>

<?php
$content = ob_get_clean();

include __DIR__ . '/templates/layout.php';
