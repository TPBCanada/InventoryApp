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
   function outwhse_warehouse_locations_table_name(): string { return 'outwhse_warehouse_locations'; }
   function outwhse_warehouse_locations_ensure(mysqli $conn): void { $table = outwhse_warehouse_locations_table_name(); mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS `' . $table . '` (`id` smallint unsigned NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `code` varchar(20) NOT NULL, `is_active` tinyint(1) NOT NULL DEFAULT 1, `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`), UNIQUE KEY `uq_outwhse_warehouse_locations_code` (`code`), KEY `idx_outwhse_warehouse_locations_active` (`is_active`), KEY `idx_outwhse_warehouse_locations_name` (`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'); $result = mysqli_query($conn, 'SELECT COUNT(*) AS row_count FROM `' . $table . '`'); $rowCount = 0; if ($result instanceof mysqli_result) { $row = mysqli_fetch_assoc($result); $rowCount = (int) ($row['row_count'] ?? 0); mysqli_free_result($result); } if ($rowCount === 0) { $stmt = $conn->prepare('INSERT INTO `' . $table . '` (`id`, `name`, `code`, `is_active`) VALUES (1, "Lachaine", "LA", 1), (2, "Dickson", "DI", 1), (3, "TPB", "TPB", 1)'); if ($stmt) { $stmt->execute(); $stmt->close(); } mysqli_query($conn, 'ALTER TABLE `' . $table . '` AUTO_INCREMENT = 4'); } }
   function outwhse_warehouse_locations_rows(mysqli $conn, bool $activeOnly = false): array { $sql = 'SELECT id, name, code, is_active FROM `' . outwhse_warehouse_locations_table_name() . '`' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, id ASC, name ASC'; $result = mysqli_query($conn, $sql); if (!$result instanceof mysqli_result) { return []; } $rows = []; while ($row = mysqli_fetch_assoc($result)) { $rows[] = ['id' => (int) ($row['id'] ?? 0), 'name' => trim((string) ($row['name'] ?? '')), 'code' => strtoupper(trim((string) ($row['code'] ?? ''))), 'is_active' => (int) ($row['is_active'] ?? 0) === 1 ? 1 : 0]; } mysqli_free_result($result); return $rows; }
   function outwhse_warehouse_location_row_by_id(mysqli $conn, int $id): ?array { if ($id < 1) { return null; } $stmt = $conn->prepare('SELECT id, name, code, is_active FROM `' . outwhse_warehouse_locations_table_name() . '` WHERE id = ? LIMIT 1'); if (!$stmt) { return null; } $stmt->bind_param('i', $id); $stmt->execute(); $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : []; $stmt->close(); if (empty($rows)) { return null; } $row = $rows[0]; return ['id' => (int) ($row['id'] ?? 0), 'name' => trim((string) ($row['name'] ?? '')), 'code' => strtoupper(trim((string) ($row['code'] ?? ''))), 'is_active' => (int) ($row['is_active'] ?? 0) === 1 ? 1 : 0]; }
   function outwhse_warehouse_location_label(mysqli $conn, int $id): string { if ($id === 0) { return 'All'; } $row = outwhse_warehouse_location_row_by_id($conn, $id); $name = trim((string) ($row['name'] ?? '')); return $name !== '' ? $name : ('Location #' . $id); }
   function outwhse_warehouse_location_code(mysqli $conn, int $id): string { if ($id === 0) { return 'PL'; } $row = outwhse_warehouse_location_row_by_id($conn, $id); $code = strtoupper(trim((string) ($row['code'] ?? ''))); return $code !== '' ? $code : 'WH'; }
   function outwhse_warehouse_location_ids(mysqli $conn, bool $includeAll = true): array { $ids = $includeAll ? [0] : []; foreach (outwhse_warehouse_locations_rows($conn, false) as $row) { $id = (int) ($row['id'] ?? 0); if ($id > 0) { $ids[] = $id; } } return array_values(array_unique($ids)); }
   function outwhse_warehouse_active_location_ids(mysqli $conn): array { $ids = []; foreach (outwhse_warehouse_locations_rows($conn, true) as $row) { $id = (int) ($row['id'] ?? 0); if ($id > 0) { $ids[] = $id; } } return $ids; }
}

outwhse_warehouse_locations_ensure($conn);

if (empty($_SESSION['csrf_token'])) {
   $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$title = 'Warehouse Movement History';
$page_class = 'page-outwhse page-outwhse-movements';
$BASE_URL = defined('APP_ROOT_URL') ? APP_ROOT_URL : rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

function outwhse_movements_icon(string $name): string
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
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h13"/><path d="m13 4 4 4-4 4"/><path d="M20 16H7"/><path d="m11 12-4 4 4 4"/></svg>
SVG,
      'history' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5l3 2"/><path d="M3 12a9 9 0 1 0 2.64-6.36"/><path d="M3 4v4h4"/></svg>
SVG,
      'pallets' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10h16"/><path d="M4 14h16"/><path d="M6 6h3v12H6Z"/><path d="M15 6h3v12h-3Z"/></svg>
SVG,
      'truck' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v8H3Z"/><path d="M14 10h3l3 3v2h-6Z"/><circle cx="7" cy="17" r="1.8"/><circle cx="18" cy="17" r="1.8"/></svg>
SVG,
      'storage' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v10H4Z"/><path d="M8 7V4h8v3"/><path d="M8 12h8"/></svg>
SVG,
      'warehouse' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10.5 12 6l8 4.5V20H4Z"/><path d="M7 20v-6h10v6"/><path d="M9 10.5V8h6v2.5"/></svg>
SVG,
      'ship' => <<<'SVG'
<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16h14"/><path d="M8 16V7h8v9"/><path d="M4 19c1 .9 2 1.5 3 1.5s2-.6 3-1.5c1 .9 2 1.5 3 1.5s2-.6 3-1.5c1 .9 2 1.5 3 1.5"/></svg>
SVG,
   ];

   return $icons[$name] ?? '';
}

function outwhse_movements_location_label(int $id): string
{
   global $conn;
   return outwhse_warehouse_location_label($conn, $id);
}

function outwhse_movements_location_id_from_label(?string $label): int
{
   global $conn;
   $needle = strtolower(trim((string) $label));
   if ($needle === '') {
      return 0;
   }

   foreach (outwhse_warehouse_locations_rows($conn, false) as $row) {
      $id = (int) ($row['id'] ?? 0);
      $name = strtolower(trim((string) ($row['name'] ?? '')));
      $code = strtolower(trim((string) ($row['code'] ?? '')));
      if ($needle === $name || $needle === $code) {
         return $id;
      }
   }

   return 0;
}

function outwhse_movements_location_ids(): array
{
   global $conn;
   return outwhse_warehouse_location_ids($conn, true);
}

function outwhse_movements_location_icon_name(array $row): string
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

function outwhse_movements_table_exists(mysqli $conn, string $table): bool
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

function outwhse_movements_column_exists(mysqli $conn, string $table, string $column): bool
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

function outwhse_movements_inventory_url(string $baseUrl, int $locationId): string
{
   $query = $locationId > 0 ? ('?' . http_build_query(['warehouse_location_id' => $locationId])) : '';
   return $baseUrl . '/outwhse.php' . $query;
}

function outwhse_movements_page_url(string $baseUrl, int $locationId): string
{
   $query = $locationId > 0 ? ('?' . http_build_query(['warehouse_location_id' => $locationId])) : '';
   return $baseUrl . '/outwhse_movements.php' . $query;
}

function outwhse_movements_type_key(?string $value): string
{
   $value = strtolower(trim((string) $value));
   return $value !== '' ? preg_replace('/[^a-z0-9]+/', '_', $value) : 'other';
}

function outwhse_movements_type_label(?string $value): string
{
   $value = trim((string) $value);
   if ($value === '') {
      return 'Other';
   }

   return match (strtolower($value)) {
      'to_3pl' => 'Sent to Warehouse',
      'received_at_3pl' => 'Received at Warehouse',
      'received_at_tpb' => 'Received at Warehouse',
      'from_3pl' => 'Returned from Warehouse',
      'shipment_cancelled' => 'Shipment Cancelled',
      'transfer' => 'Warehouse Transfer',
      'adjustment' => 'Adjustment',
      'status_update' => 'Status Update',
      'write_off' => 'Write Off',
      default => ucwords(str_replace(['_', '-'], ' ', strtolower($value))),
   };
}

function outwhse_movements_space_key(?string $value): string
{
   $value = strtolower(trim((string) $value));
   if ($value === 'ready_to_ship') {
      $value = 'received';
   }
   return $value !== '' ? preg_replace('/[^a-z0-9]+/', '_', $value) : 'unspecified';
}

function outwhse_movements_space_label(?string $value): string
{
   $value = trim((string) $value);
   if ($value === '') {
      return 'Unspecified';
   }

   return match (strtolower($value)) {
      'staged' => 'Staged',
      'ready_to_ship' => 'Received',
      'received' => 'Received',
      'on_route' => 'On Route',
      'in_storage' => 'In Storage',
      default => ucwords(str_replace(['_', '-'], ' ', strtolower($value))),
   };
}

function outwhse_movements_location_text(?string $type, ?string $label): string
{
   $label = trim((string) $label);
   if ($label !== '') {
      return $label;
   }

   $type = trim((string) $type);
   if ($type === '') {
      return 'N/A';
   }

   return ucwords(str_replace(['_', '-'], ' ', strtolower($type)));
}

$selected_location_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $movement_action = trim((string) ($_POST['action'] ?? ''));
   if ($movement_action === 'receive_shipment' || $movement_action === 'cancel_shipment') {
      $post_csrf = (string) ($_POST['csrf_token'] ?? '');
      $bol_number = trim((string) ($_POST['bol_number'] ?? ''));
      if ($post_csrf === '' || !hash_equals($csrfToken, $post_csrf)) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Your session expired. Please reload the page and try again.'];
         header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
         exit;
      }

      if ($bol_number === '') {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Choose a BOL number to receive.'];
         header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
         exit;
      }

      if (!$conn) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Database connection is not available.'];
         header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
         exit;
      }

      $shipment_select_fields = [
         'warehouse_location_id',
      ];
      if (outwhse_movements_column_exists($conn, 'outwhse_inventory_movements', 'destination_location_id')) {
         $shipment_select_fields[] = 'destination_location_id';
      }
      $shipment_table_has_destination_location_id = outwhse_movements_column_exists($conn, 'outwhse_inventory_movements', 'destination_location_id');
      $inventory_table_has_destination_location_id = outwhse_movements_column_exists($conn, 'outwhse_inventory', 'destination_location_id');
      $inventory_table_has_line_number = outwhse_movements_column_exists($conn, 'outwhse_inventory', 'line_number');
      $shipment_table_has_line_number = outwhse_movements_column_exists($conn, 'outwhse_inventory_movements', 'line_number');
      $movement_table_has_tracking_columns =
         outwhse_movements_column_exists($conn, 'outwhse_inventory_movements', 'origin_location_id') &&
         outwhse_movements_column_exists($conn, 'outwhse_inventory_movements', 'current_location_id') &&
         outwhse_movements_column_exists($conn, 'outwhse_inventory_movements', 'destination_location_id');
      $shipment_select_fields = array_merge($shipment_select_fields, [
         'pallet_number',
         'sku',
         'manual_label',
         'manual_reference',
         'manual_category',
         'description',
         'lot_number',
         'qty',
         'unit_type',
         'destination_location_type',
         'destination_location_label',
      ]);
      if ($shipment_table_has_line_number) {
         $shipment_select_fields[] = 'line_number';
      }

      $ship_stmt = $conn->prepare('
         SELECT
            ' . implode(",\n            ", $shipment_select_fields) . '
         FROM outwhse_inventory_movements
         WHERE reference_number = ?
           AND space_status = ?
      ');
      if (!$ship_stmt) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to load the shipment rows.'];
         header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
         exit;
      }

      $on_route_status = 'on_route';
      $ship_stmt->bind_param('ss', $bol_number, $on_route_status);
      $ship_stmt->execute();
      $shipment_rows = [];
      if (function_exists('stmt_fetch_all_assoc')) {
         $shipment_rows = stmt_fetch_all_assoc($ship_stmt);
      } else {
         $ship_stmt->store_result();
         $meta = $ship_stmt->result_metadata();
         if ($meta) {
            $fields = [];
            $row = [];
            while ($field = $meta->fetch_field()) {
               $fields[] = &$row[$field->name];
            }
            call_user_func_array([$ship_stmt, 'bind_result'], $fields);
            while ($ship_stmt->fetch()) {
               $copy = [];
               foreach ($row as $key => $value) {
                  $copy[$key] = $value;
               }
               $shipment_rows[] = $copy;
            }
            $meta->free();
         }
      }
      $ship_stmt->close();

      if (empty($shipment_rows)) {
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'No on-route shipment rows were found for that BOL.'];
         header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
         exit;
      }

      if ($movement_action === 'receive_shipment') {
         $shipment_destination_ids = [];
         if ($shipment_table_has_destination_location_id) {
            foreach ($shipment_rows as $shipment_row) {
               $shipment_destination_id = (int) ($shipment_row['destination_location_id'] ?? 0);
               if ($shipment_destination_id > 0) {
                  $shipment_destination_ids[$shipment_destination_id] = $shipment_destination_id;
               }
            }

            if (count($shipment_destination_ids) > 1) {
               $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'This BOL contains mixed destinations and cannot be received together.'];
               header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
               exit;
            }

            $shipment_destination_id = (int) (array_values($shipment_destination_ids)[0] ?? 0);
            if ($shipment_destination_id < 1) {
               $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to determine the destination for this shipment.'];
               header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
               exit;
            }
         } else {
            $shipment_destination_id = 0;
         }

         mysqli_begin_transaction($conn);
      $mark_received_stmt = $conn->prepare('UPDATE outwhse_inventory_movements SET space_status = ?, movement_direction = NULL WHERE reference_number = ? AND space_status = ?');
      if ($inventory_table_has_destination_location_id) {
         if ($inventory_table_has_line_number) {
            $receive_inventory_stmt = $conn->prepare('
               UPDATE outwhse_inventory
               SET
                  space_status = ?,
                  movement_direction = NULL,
                  warehouse_location_id = ?,
                  current_location_id = ?,
                  destination_location_id = NULL
               WHERE warehouse_location_id = ? AND pallet_number = ? AND line_number = ? AND is_disabled = 0
            ');
         } else {
            $receive_inventory_stmt = $conn->prepare('
               UPDATE outwhse_inventory
               SET
                  space_status = ?,
                  movement_direction = NULL,
                  warehouse_location_id = ?,
                  current_location_id = ?,
                  destination_location_id = NULL
               WHERE warehouse_location_id = ? AND pallet_number = ? AND is_disabled = 0
            ');
         }
      } else {
         if ($inventory_table_has_line_number) {
            $receive_inventory_stmt = $conn->prepare('
               UPDATE outwhse_inventory
               SET
                  space_status = ?,
                  movement_direction = NULL,
                  warehouse_location_id = ?,
                  current_location_id = ?
               WHERE warehouse_location_id = ? AND pallet_number = ? AND line_number = ? AND is_disabled = 0
            ');
         } else {
            $receive_inventory_stmt = $conn->prepare('
               UPDATE outwhse_inventory
               SET
                  space_status = ?,
                  movement_direction = NULL,
                  warehouse_location_id = ?,
                  current_location_id = ?
               WHERE warehouse_location_id = ? AND pallet_number = ? AND is_disabled = 0
            ');
         }
      }
         if ($movement_table_has_tracking_columns) {
            $receive_stmt = $conn->prepare('
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
            $receive_stmt = $conn->prepare('
               INSERT INTO outwhse_inventory_movements (
                  warehouse_location_id,
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
                  movement_type,
                  space_status,
                  movement_direction,
                  item_source,
                  reference_number,
                  notes,
                  created_by
               ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
         }

         if (!$mark_received_stmt || !$receive_inventory_stmt || !$receive_stmt) {
            mysqli_rollback($conn);
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to prepare the shipment receipt update.'];
            header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
            exit;
         }

         $tpb_location_id = 0;
         foreach (outwhse_warehouse_locations_rows($conn, false) as $warehouse_location_row) {
            if (strtoupper(trim((string) ($warehouse_location_row['code'] ?? ''))) === 'TPB') {
               $tpb_location_id = (int) ($warehouse_location_row['id'] ?? 0);
               break;
            }
         }

         $received_status = $tpb_location_id > 0 && $shipment_destination_id === $tpb_location_id
            ? 'received'
            : 'in_storage';
         $pending_status = 'on_route';
         $mark_received_stmt->bind_param('sss', $received_status, $bol_number, $pending_status);
         if (!$mark_received_stmt->execute()) {
            mysqli_rollback($conn);
            $mark_received_stmt->close();
            $receive_inventory_stmt->close();
            $receive_stmt->close();
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to update the shipment status.'];
            header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
            exit;
         }

         $received_count = 0;
         foreach ($shipment_rows as $shipment_row) {
            $warehouse_location_id = (int) ($shipment_row['warehouse_location_id'] ?? 0);
            $destination_location_id = (int) ($shipment_row['destination_location_id'] ?? $shipment_destination_id);
            $pallet_number = trim((string) ($shipment_row['pallet_number'] ?? ''));
            $line_number = (int) ($shipment_row['line_number'] ?? 0);
            if ($destination_location_id < 1) {
               $destination_location_id = outwhse_movements_location_id_from_label($shipment_row['destination_location_label'] ?? '');
            }
            if ($line_number < 1 && $warehouse_location_id > 0 && $pallet_number !== '' && $inventory_table_has_line_number) {
               $line_lookup_stmt = $conn->prepare('SELECT line_number FROM outwhse_inventory WHERE warehouse_location_id = ? AND pallet_number = ? AND is_disabled = 0 LIMIT 1');
               if ($line_lookup_stmt) {
                  $line_lookup_stmt->bind_param('is', $warehouse_location_id, $pallet_number);
                  $line_lookup_stmt->execute();
                  $line_lookup_stmt->bind_result($line_number);
                  $line_lookup_stmt->fetch();
                  $line_lookup_stmt->close();
               }
            }
            if ($destination_location_id < 1 && $inventory_table_has_destination_location_id && $warehouse_location_id > 0 && $pallet_number !== '') {
               $destination_lookup_stmt = $conn->prepare('SELECT destination_location_id FROM outwhse_inventory WHERE warehouse_location_id = ? AND pallet_number = ? AND is_disabled = 0 LIMIT 1');
               if ($destination_lookup_stmt) {
                  $destination_lookup_stmt->bind_param('is', $warehouse_location_id, $pallet_number);
                  $destination_lookup_stmt->execute();
                  $destination_lookup_stmt->bind_result($destination_location_id);
                  $destination_lookup_stmt->fetch();
                  $destination_lookup_stmt->close();
               }
            }
            if ($warehouse_location_id < 1 || $destination_location_id < 1 || $pallet_number === '') {
               continue;
            }

            $received_space_status = $tpb_location_id > 0 && $destination_location_id === $tpb_location_id
               ? 'received'
               : 'in_storage';
            if ($inventory_table_has_line_number && $line_number < 1) {
               continue;
            }

            if ($inventory_table_has_line_number) {
               $receive_inventory_stmt->bind_param('siiisi', $received_space_status, $destination_location_id, $destination_location_id, $warehouse_location_id, $pallet_number, $line_number);
            } else {
               $receive_inventory_stmt->bind_param('siiis', $received_space_status, $destination_location_id, $destination_location_id, $warehouse_location_id, $pallet_number);
            }
            if (!$receive_inventory_stmt->execute()) {
               mysqli_rollback($conn);
               $receive_inventory_stmt->close();
               $receive_stmt->close();
               $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to update the received pallets.'];
               header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
               exit;
            }

            $movement_type = $tpb_location_id > 0 && $destination_location_id === $tpb_location_id
               ? 'received_at_tpb'
               : 'received_at_3pl';
            $space_status = $received_space_status;
            $movement_direction = null;
            $item_source = 'catalog';
            $sku = trim((string) ($shipment_row['sku'] ?? ''));
            $manual_label = trim((string) ($shipment_row['manual_label'] ?? ''));
            $manual_reference = trim((string) ($shipment_row['manual_reference'] ?? ''));
            $manual_category = trim((string) ($shipment_row['manual_category'] ?? ''));
            $description = trim((string) ($shipment_row['description'] ?? ''));
            $lot_number = trim((string) ($shipment_row['lot_number'] ?? ''));
            $qty = trim((string) ($shipment_row['qty'] ?? ''));
            $unit_type = trim((string) ($shipment_row['unit_type'] ?? ''));
            $source_location_type = 'warehouse';
            $source_location_label = outwhse_movements_location_label($warehouse_location_id);
            $destination_location_type = 'warehouse';
            $destination_location_label = outwhse_movements_location_label($destination_location_id);
            $reference_number = $bol_number;
            $notes = 'Shipment received at ' . $destination_location_label . '.';
            $created_by = $username !== '' ? $username : 'system';

            if ($movement_table_has_tracking_columns) {
               $receive_stmt->bind_param(
                  'iiiissssssssssssssssssss',
                  $destination_location_id,
                  $warehouse_location_id,
                  $destination_location_id,
                  $destination_location_id,
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
               $receive_stmt->bind_param(
                  'issssssssssssssssssss',
                  $warehouse_location_id,
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
                  $movement_type,
                  $space_status,
                  $movement_direction,
                  $item_source,
                  $reference_number,
                  $notes,
                  $created_by
               );
            }

            if (!$receive_stmt->execute()) {
               mysqli_rollback($conn);
               $receive_inventory_stmt->close();
               $receive_stmt->close();
               $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to log the receipt movement.'];
               header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
               exit;
            }

            $received_count++;
         }

         $mark_received_stmt->close();
         $receive_inventory_stmt->close();
         $receive_stmt->close();
         mysqli_commit($conn);

         $_SESSION['outwhse_flash'] = ['type' => 'success', 'message' => 'Shipment marked received and pallets updated.'];
         header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
         exit;
      }

      mysqli_begin_transaction($conn);
      $mark_cancelled_stmt = $conn->prepare('UPDATE outwhse_inventory_movements SET space_status = ?, movement_direction = NULL WHERE reference_number = ? AND space_status = ?');
      if ($inventory_table_has_destination_location_id) {
         if ($inventory_table_has_line_number) {
            $cancel_inventory_stmt = $conn->prepare('
               UPDATE outwhse_inventory
               SET
                  space_status = ?,
                  movement_direction = NULL,
                  current_location_id = ?,
                  destination_location_id = NULL
               WHERE warehouse_location_id = ? AND pallet_number = ? AND line_number = ? AND is_disabled = 0
            ');
         } else {
            $cancel_inventory_stmt = $conn->prepare('
               UPDATE outwhse_inventory
               SET
                  space_status = ?,
                  movement_direction = NULL,
                  current_location_id = ?,
                  destination_location_id = NULL
               WHERE warehouse_location_id = ? AND pallet_number = ? AND is_disabled = 0
            ');
         }
      } else {
         if ($inventory_table_has_line_number) {
            $cancel_inventory_stmt = $conn->prepare('
               UPDATE outwhse_inventory
               SET
                  space_status = ?,
                  movement_direction = NULL,
                  current_location_id = ?
               WHERE warehouse_location_id = ? AND pallet_number = ? AND line_number = ? AND is_disabled = 0
            ');
         } else {
            $cancel_inventory_stmt = $conn->prepare('
               UPDATE outwhse_inventory
               SET
                  space_status = ?,
                  movement_direction = NULL,
                  current_location_id = ?
               WHERE warehouse_location_id = ? AND pallet_number = ? AND is_disabled = 0
            ');
         }
      }
      if ($movement_table_has_tracking_columns) {
         $cancel_stmt = $conn->prepare('
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
            $cancel_stmt = $conn->prepare('
               INSERT INTO outwhse_inventory_movements (
                  warehouse_location_id,
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
                  movement_type,
                  space_status,
                  movement_direction,
                  item_source,
                  reference_number,
                  notes,
                  created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ');
      }

      if (!$mark_cancelled_stmt || !$cancel_inventory_stmt || !$cancel_stmt) {
         mysqli_rollback($conn);
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to prepare the shipment cancellation update.'];
         header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
         exit;
      }

      $cancelled_status = 'in_storage';
      $pending_status = 'on_route';
      $mark_cancelled_stmt->bind_param('sss', $cancelled_status, $bol_number, $pending_status);
      if (!$mark_cancelled_stmt->execute()) {
         mysqli_rollback($conn);
         $mark_cancelled_stmt->close();
         $cancel_inventory_stmt->close();
         $cancel_stmt->close();
         $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to cancel the shipment.'];
         header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
         exit;
      }

      $cancelled_count = 0;
      foreach ($shipment_rows as $shipment_row) {
         $warehouse_location_id = (int) ($shipment_row['warehouse_location_id'] ?? 0);
         $pallet_number = trim((string) ($shipment_row['pallet_number'] ?? ''));
         $line_number = (int) ($shipment_row['line_number'] ?? 0);
         if ($warehouse_location_id < 1 || $pallet_number === '') {
            continue;
         }

         if ($line_number < 1 && $warehouse_location_id > 0 && $pallet_number !== '' && $inventory_table_has_line_number) {
            $line_lookup_stmt = $conn->prepare('SELECT line_number FROM outwhse_inventory WHERE warehouse_location_id = ? AND pallet_number = ? AND is_disabled = 0 LIMIT 1');
            if ($line_lookup_stmt) {
               $line_lookup_stmt->bind_param('is', $warehouse_location_id, $pallet_number);
               $line_lookup_stmt->execute();
               $line_lookup_stmt->bind_result($line_number);
               $line_lookup_stmt->fetch();
               $line_lookup_stmt->close();
            }
         }

         if ($inventory_table_has_line_number && $line_number < 1) {
            continue;
         }

         if ($inventory_table_has_line_number) {
            $cancel_inventory_stmt->bind_param('siisi', $cancelled_status, $warehouse_location_id, $warehouse_location_id, $pallet_number, $line_number);
         } else {
            $cancel_inventory_stmt->bind_param('siis', $cancelled_status, $warehouse_location_id, $warehouse_location_id, $pallet_number);
         }
         if (!$cancel_inventory_stmt->execute()) {
            mysqli_rollback($conn);
            $cancel_inventory_stmt->close();
            $cancel_stmt->close();
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to return the pallets to storage.'];
            header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
            exit;
         }

         $movement_type = 'shipment_cancelled';
         $space_status = $cancelled_status;
         $movement_direction = null;
         $item_source = 'catalog';
         $sku = trim((string) ($shipment_row['sku'] ?? ''));
         $manual_label = trim((string) ($shipment_row['manual_label'] ?? ''));
         $manual_reference = trim((string) ($shipment_row['manual_reference'] ?? ''));
         $manual_category = trim((string) ($shipment_row['manual_category'] ?? ''));
         $description = trim((string) ($shipment_row['description'] ?? ''));
         $lot_number = trim((string) ($shipment_row['lot_number'] ?? ''));
         $qty = trim((string) ($shipment_row['qty'] ?? ''));
         $unit_type = trim((string) ($shipment_row['unit_type'] ?? ''));
         $source_location_type = 'warehouse';
         $source_location_label = outwhse_movements_location_label($warehouse_location_id);
         $destination_location_type = $source_location_type;
         $destination_location_label = $source_location_label;
         $reference_number = $bol_number;
         $notes = 'Shipment cancelled and returned to storage.';
         $created_by = $username !== '' ? $username : 'system';

         if ($movement_table_has_tracking_columns) {
            $cancel_stmt->bind_param(
               'iiiissssssssssssssssssss',
               $warehouse_location_id,
               $warehouse_location_id,
               $warehouse_location_id,
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
         } else {
            $cancel_stmt->bind_param(
               'issssssssssssssssssss',
               $warehouse_location_id,
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
               $movement_type,
               $space_status,
               $movement_direction,
               $item_source,
               $reference_number,
               $notes,
               $created_by
            );
         }

         if (!$cancel_stmt->execute()) {
            mysqli_rollback($conn);
            $cancel_inventory_stmt->close();
            $cancel_stmt->close();
            $_SESSION['outwhse_flash'] = ['type' => 'error', 'message' => 'Unable to log the shipment cancellation.'];
            header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
            exit;
         }

         $cancelled_count++;
      }

      $mark_cancelled_stmt->close();
      $cancel_inventory_stmt->close();
      $cancel_stmt->close();
      mysqli_commit($conn);

      $_SESSION['outwhse_flash'] = ['type' => 'success', 'message' => 'Shipment cancelled and pallets returned to storage.'];
      header('Location: ' . outwhse_movements_page_url($BASE_URL, $selected_location_id));
      exit;
   }
}

$table_available = outwhse_movements_table_exists($conn, 'outwhse_inventory_movements');
$show_completed_bols = (string) ($_GET['show_completed'] ?? '') === '1';
$movement_rows = [];
$on_route_shipments = [];
$space_status_counts = [];
$space_status_options = [
   'received' => 'Received',
   'on_route' => 'On Route',
];

if ($table_available) {
   $sql = '
      SELECT
         id,
         warehouse_location_id,
         movement_type,
         space_status,
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
         created_by,
         created_at
      FROM outwhse_inventory_movements
      WHERE 1 = 1
   ';

   if ($selected_location_id > 0) {
      $sql .= ' AND warehouse_location_id = ?';
   }

   $sql .= ' ORDER BY created_at DESC, id DESC';

   $stmt = mysqli_prepare($conn, $sql);
   if (!$stmt) {
      die('Failed to prepare warehouse movement query.');
   }

   if ($selected_location_id > 0) {
      mysqli_stmt_bind_param($stmt, 'i', $selected_location_id);
   }

   mysqli_stmt_execute($stmt);
   $rows = function_exists('stmt_fetch_all_assoc') ? stmt_fetch_all_assoc($stmt) : [];
   mysqli_stmt_close($stmt);

   foreach ($rows as $row) {
      $movement_type_key = outwhse_movements_type_key($row['movement_type'] ?? '');
      $space_status_key = outwhse_movements_space_key($row['space_status'] ?? '');
      $item_source = strtolower(trim((string) ($row['item_source'] ?? 'catalog')));
      $manual_label = trim((string) ($row['manual_label'] ?? ''));
      $sku = trim((string) ($row['sku'] ?? ''));
      $description = trim((string) ($row['description'] ?? ''));
      $pallet_number = trim((string) ($row['pallet_number'] ?? ''));
      $created_at_raw = (string) ($row['created_at'] ?? '');
      $created_at_display = $created_at_raw !== '' ? date('M j, Y', strtotime($created_at_raw)) : 'N/A';
      $item_display = $sku !== '' ? $sku : ($manual_label !== '' ? $manual_label : 'Manual entry');
      $description_display = $description !== '' ? $description : ($manual_label !== '' ? $manual_label : 'N/A');
      $reference_display = trim((string) ($row['reference_number'] ?? ''));
      if ($reference_display === '') {
         $reference_display = trim((string) ($row['manual_reference'] ?? ''));
      }

      $movement_rows[] = [
         'id' => (int) ($row['id'] ?? 0),
         'warehouse_location_name' => outwhse_movements_location_label((int) ($row['warehouse_location_id'] ?? 0)),
         'movement_type_key' => $movement_type_key,
         'movement_type_label' => outwhse_movements_type_label($row['movement_type'] ?? ''),
         'space_status_key' => $space_status_key,
         'space_status_label' => outwhse_movements_space_label($row['space_status'] ?? ''),
         'item_source' => $item_source,
         'item_display' => $item_display,
         'sku' => $sku !== '' ? $sku : 'Manual',
         'description' => $description_display,
         'lot_number' => trim((string) ($row['lot_number'] ?? '')) !== '' ? (string) $row['lot_number'] : 'N/A',
         'qty' => trim((string) ($row['qty'] ?? '')) !== '' ? (string) $row['qty'] : 'N/A',
         'unit_type' => trim((string) ($row['unit_type'] ?? '')) !== '' ? (string) $row['unit_type'] : 'N/A',
         'pallet_number' => $pallet_number !== '' ? $pallet_number : 'N/A',
         'source_location' => outwhse_movements_location_text($row['source_location_type'] ?? '', $row['source_location_label'] ?? ''),
         'destination_location' => outwhse_movements_location_text($row['destination_location_type'] ?? '', $row['destination_location_label'] ?? ''),
         'reference_number_raw' => trim((string) ($row['reference_number'] ?? '')),
         'reference_number' => $reference_display !== '' ? $reference_display : 'N/A',
         'notes' => trim((string) ($row['notes'] ?? '')) !== '' ? (string) $row['notes'] : '',
         'created_by' => trim((string) ($row['created_by'] ?? '')) !== '' ? (string) $row['created_by'] : 'N/A',
         'created_at_display' => $created_at_display,
      ];

      $space_status_counts[$space_status_key] = ($space_status_counts[$space_status_key] ?? 0) + 1;
   }

   $bol_group_status = [];
   foreach ($movement_rows as $row) {
      $bol_number = trim((string) ($row['reference_number_raw'] ?? ''));
      if ($bol_number === '') {
         continue;
      }

      if (!isset($bol_group_status[$bol_number])) {
         $bol_group_status[$bol_number] = [
            'has_on_route' => false,
         ];
      }

      if (($row['space_status_key'] ?? '') === 'on_route') {
         $bol_group_status[$bol_number]['has_on_route'] = true;
      }
   }

   foreach ($movement_rows as &$row) {
      $bol_number = trim((string) ($row['reference_number_raw'] ?? ''));
      $row['history_only'] = $bol_number !== '' && isset($bol_group_status[$bol_number]) && !$bol_group_status[$bol_number]['has_on_route'];
   }
   unset($row);

   if (!$show_completed_bols) {
      $movement_rows = array_values(array_filter($movement_rows, static function (array $row): bool {
         return (int) ($row['history_only'] ?? 0) !== 1;
      }));
   }

   $shipment_lookup = [];
   foreach ($movement_rows as $row) {
      if (($row['space_status_key'] ?? '') !== 'on_route') {
         continue;
      }

      $bol_number = trim((string) ($row['reference_number_raw'] ?? ''));
      if ($bol_number === '') {
         continue;
      }

      if (!isset($shipment_lookup[$bol_number])) {
         $shipment_lookup[$bol_number] = [
            'bol_number' => $bol_number,
            'warehouse_location_name' => $row['warehouse_location_name'],
            'source_location' => $row['source_location'],
            'destination_location' => $row['destination_location'],
            'pallets' => [],
            'rows' => [],
            'created_at_display' => $row['created_at_display'],
         ];
      }

      $shipment_lookup[$bol_number]['rows'][] = $row;
      if (($row['pallet_number'] ?? '') !== 'N/A' && ($row['pallet_number'] ?? '') !== '') {
         $shipment_lookup[$bol_number]['pallets'][strtoupper((string) $row['pallet_number'])] = true;
      }
   }

   foreach ($shipment_lookup as $shipment) {
      $shipment_rows = $shipment['rows'];
      $shipment_qty_total = 0.0;
      foreach ($shipment_rows as $shipment_row) {
         $shipment_qty_total += (float) preg_replace('/[^0-9.\-]/', '', (string) ($shipment_row['qty'] ?? '0'));
      }

      $on_route_shipments[] = [
         'bol_number' => $shipment['bol_number'],
         'warehouse_location_name' => $shipment['warehouse_location_name'],
         'source_location' => $shipment['source_location'],
         'destination_location' => $shipment['destination_location'],
         'pallet_count' => count($shipment['pallets']),
         'row_count' => count($shipment_rows),
         'qty_total' => $shipment_qty_total,
         'created_at_display' => $shipment['created_at_display'],
         'rows' => $shipment_rows,
      ];
   }
}

$summary_cards = [
   [
      'label' => 'Total Movement Rows',
      'value' => number_format(count($movement_rows)),
      'meta' => $selected_location_id > 0 ? outwhse_movements_location_label($selected_location_id) . ' ledger scope' : 'Across all locations',
      'icon' => 'history',
      'tone' => 'sku',
   ],
   [
      'label' => 'Open Shipments',
      'value' => number_format(count($on_route_shipments)),
      'meta' => 'BOL groups currently on route',
      'icon' => 'truck',
      'tone' => 'transfer',
   ],
   [
      'label' => 'On Route',
      'value' => number_format((int) ($space_status_counts['on_route'] ?? 0)),
      'meta' => 'Movements currently in transit',
      'icon' => 'truck',
      'tone' => 'transfer',
   ],
];

$rail_items = [
   ['label' => 'All', 'icon' => 'All', 'location_id' => 0],
];
foreach (outwhse_warehouse_locations_rows($conn, false) as $warehouse_row) {
   $warehouse_id = (int) ($warehouse_row['id'] ?? 0);
   if ($warehouse_id > 0) {
      $rail_items[] = [
         'label' => (string) ($warehouse_row['name'] ?? ('Location #' . $warehouse_id)),
         'icon' => outwhse_movements_location_icon_name($warehouse_row),
         'location_id' => $warehouse_id,
      ];
   }
}

$inventory_page_url = outwhse_movements_inventory_url($BASE_URL, $selected_location_id);
$page_url = outwhse_movements_page_url($BASE_URL, $selected_location_id);

$css = ['/refactor_css/outwhse.css'];
$js = ['/js/outwhse_movements.js'];

ob_start();
?>

<section class="outwhse-shell" aria-label="Warehouse movement history">
   <aside class="outwhse-rail" aria-label="Warehouse locations">
      <div class="outwhse-rail__brand" aria-hidden="true">
         <div class="outwhse-rail__logo">
            <?= outwhse_movements_icon('logo') ?>
         </div>
      </div>

      <nav class="outwhse-rail__nav" aria-label="Warehouse filters">
         <?php foreach ($rail_items as $item): ?>
            <?php $is_active = $selected_location_id === $item['location_id']; ?>
            <a class="outwhse-rail__item<?= $is_active ? ' is-active' : '' ?>"
               href="<?= h(outwhse_movements_page_url($BASE_URL, $item['location_id'])) ?>">
               <span class="outwhse-rail__icon"><?= outwhse_movements_icon($item['icon']) ?></span>
               <span class="outwhse-rail__label"><?= h($item['label']) ?></span>
            </a>
         <?php endforeach; ?>
      </nav>
   </aside>

   <div class="outwhse-stage">
      <header class="outwhse-header">
         <div>
            <p class="outwhse-eyebrow">Warehouse movement ledger</p>
            <h1>Warehouse Movements</h1>
            <p class="outwhse-subtitle">Track stock sent between warehouse locations, received at its destination, returned to internal
               inventory, or updated in storage status.</p>
         </div>
         <div class="outwhse-header__actions">
            <a class="outwhse-btn outwhse-btn--primary" href="<?= h($inventory_page_url) ?>">
               <span aria-hidden="true"><?= outwhse_movements_icon('storage') ?></span>
               <span>Live Inventory</span>
            </a>
            <!-- <div class="outwhse-user">
               <span class="outwhse-user__label">Scope</span>
               <strong><?= h(outwhse_movements_location_label($selected_location_id)) ?></strong>
            </div> -->
         </div>
      </header>

      <section class="outwhse-kpis" aria-label="Warehouse movement metrics">
         <?php foreach ($summary_cards as $card): ?>
            <article class="outwhse-kpi outwhse-kpi--<?= h($card['tone']) ?>">
               <p class="outwhse-kpi__label"><?= h($card['label']) ?></p>
               <div class="outwhse-kpi__body">
                  <span class="outwhse-kpi__icon" aria-hidden="true"><?= outwhse_movements_icon($card['icon']) ?></span>
                  <div class="outwhse-kpi__copy">
                     <strong class="outwhse-kpi__value"><?= h($card['value']) ?></strong>
                     <span class="outwhse-kpi__meta"><?= h($card['meta']) ?></span>
                  </div>
               </div>
            </article>
         <?php endforeach; ?>
      </section>

      <section class="outwhse-panel">
         <div class="outwhse-toolbar">
            <label class="outwhse-search" for="outwhseMovementsSearch">
               <span class="outwhse-toolbar__icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                     <circle cx="11" cy="11" r="6.5"></circle>
                     <path d="m16 16 4.5 4.5"></path>
                  </svg>
               </span>
               <input id="outwhseMovementsSearch" type="search" placeholder="Search movements">
            </label>

            <div class="outwhse-toolbar__actions">
                  <label class="outwhse-filter outwhse-filter--checkbox" for="outwhseShowTpbReceived">
                  <input id="outwhseShowTpbReceived" type="checkbox"<?= $show_completed_bols ? ' checked' : '' ?>>
                  <span>Show completed BOLs</span>
               </label>
               <label class="outwhse-filter" for="outwhseSpaceStatusFilter">
                  <span class="outwhse-toolbar__icon" aria-hidden="true">
                     <svg viewBox="0 0 24 24">
                        <path d="M5 4h14l-5.5 7v6l-3 2v-8Z"></path>
                     </svg>
                  </span>
                  <select id="outwhseSpaceStatusFilter">
                     <?php foreach ($space_status_options as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $value === 'on_route' ? 'selected' : '' ?>>
                           <?= h($label) ?>
                        </option>
                     <?php endforeach; ?>
                  </select>
               </label>
            </div>
         </div>

         <!-- <div class="outwhse-panel__meta">
            <span><strong id="outwhseMovementsVisibleCount"><?= count($movement_rows) ?></strong> movements
               visible</span>
            <span class="outwhse-panel__legend">Staged:
               <?= number_format((int) ($space_status_counts['staged'] ?? 0)) ?>, received:
               <?= number_format((int) ($space_status_counts['received'] ?? 0)) ?>, on route:
               <?= number_format((int) ($space_status_counts['on_route'] ?? 0)) ?>, in storage:
               <?= number_format((int) ($space_status_counts['in_storage'] ?? 0)) ?></span>
         </div> -->

         <section class="outwhse-shipment-strip" aria-label="Shipments currently on route">
            <div class="outwhse-shipment-strip__head">
               <div>
                  <h2 class="outwhse-shipment-strip__title">Shipments On Route</h2>
                  <p class="outwhse-shipment-strip__subtitle">View open BOLs and mark them received when the shipment
                     arrives.</p>
               </div>
            </div>

            <?php if (!empty($on_route_shipments)): ?>
               <div class="outwhse-shipment-strip__grid">
                  <?php foreach ($on_route_shipments as $shipment_index => $shipment): ?>
                     <article class="outwhse-shipment-card" data-shipment-card>
                        <button class="outwhse-shipment-card__bol-button outwhseShipmentModal" type="button" data-shipment-toggle
                           aria-expanded="false" aria-controls="outwhseShipmentModal">
                           <span>
                              <span class="outwhse-shipment-card__bol-label">BOL #</span>
                              <strong><?= h($shipment['bol_number']) ?></strong>
                           </span>
                           <span class="outwhse-shipment-card__bol-action">View Contents</span>
                        </button>
                        <script type="application/json" data-shipment-json><?= json_encode([
                           'bol_number' => $shipment['bol_number'],
                           'warehouse_location_name' => $shipment['warehouse_location_name'],
                           'source_location' => $shipment['source_location'],
                           'destination_location' => $shipment['destination_location'],
                           'pallet_count' => (int) $shipment['pallet_count'],
                           'row_count' => (int) $shipment['row_count'],
                           'qty_total' => (float) $shipment['qty_total'],
                           'created_at_display' => $shipment['created_at_display'],
                           'return_location_id' => (string) $selected_location_id,
                           'csrf_token' => $csrfToken,
                           'rows' => array_map(static function ($row) {
                              return [
                                 'pallet_number' => $row['pallet_number'] ?? 'N/A',
                                 'sku' => $row['sku'] ?? 'Manual',
                                 'description' => $row['description'] ?? 'N/A',
                                 'qty' => $row['qty'] ?? 'N/A',
                              ];
                           }, $shipment['rows']),
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
                     </article>
                  <?php endforeach; ?>
               </div>
            <?php else: ?>
               <div class="outwhse-empty">No shipments are currently on route for this scope.</div>
            <?php endif; ?>
         </section>

         <div class="outwhse-modal outwhse-modal--compact" id="outwhseShipmentModal" data-shipment-modal hidden
            aria-hidden="true">
            <div class="outwhse-modal__backdrop" data-shipment-modal-close></div>
            <div class="outwhse-modal__dialog" role="dialog" aria-modal="true"
               aria-labelledby="outwhseShipmentModalTitle">
               <div class="outwhse-modal__header">
                  <div>
                     <h2 class="outwhse-modal__title" id="outwhseShipmentModalTitle">Shipment Contents</h2>
                     <!-- <p class="outwhse-modal__subtitle">Review the pallets included in this BOL.</p> -->
                  </div>
                  <button class="outwhse-modal__close" type="button" data-shipment-modal-close>Close</button>
               </div>
               <div class="outwhse-modal__body">
                  <div class="outwhse-modal__stack" data-shipment-modal-content></div>
               </div>
            </div>
         </div>

         <div class="outwhse-table-wrap outwhse-table-wrap--history" id="outwhseMovementsTableWrap"
            <?= empty($movement_rows) ? ' hidden' : '' ?>>
            <table class="outwhse-table outwhse-table--history" id="outwhseMovementsTable">
               <thead>
                  <tr>
                     <th>Date</th>
                     <!-- <th>Warehouse</th> -->
                     <!-- <th>Movement</th> -->
                     <th>Status</th>
                     <th>BOL #</th>
                     <th>Pallet #</th>
                     <th>SKU / Item</th>
                     <th>Description</th>
                     <th>Lot #</th>
                     <th>Qty</th>
                     <!-- <th>Unit Type</th> -->
                     <th>From</th>
                     <th>To</th>
                     <!-- <th>Created By</th> -->
                  </tr>
               </thead>
               <tbody>
                  <?php foreach ($movement_rows as $row): ?>
                     <?php
                     $search_blob = strtolower(implode(' ', [
                        $row['created_at_display'],
                        // $row['warehouse_location_name'],
                        // $row['movement_type_label'],
                        $row['space_status_label'],
                        $row['reference_number'],
                        $row['pallet_number'],
                        $row['item_display'],
                        $row['sku'],
                        $row['description'],
                        $row['lot_number'],
                        $row['qty'],
                        // $row['unit_type'],
                        $row['source_location'],
                        $row['destination_location'],
                        // $row['created_by'],
                        $row['notes'],
                     ]));
                     $is_history_only = (int) ($row['history_only'] ?? 0) === 1;
                     ?>
                     <tr data-search="<?= h($search_blob) ?>" data-movement-type="<?= h($row['movement_type_key']) ?>"
                        data-space-status="<?= h($row['space_status_key']) ?>"
                        data-history-only="<?= $is_history_only ? '1' : '0' ?>"
                        <?= $is_history_only ? 'hidden' : '' ?>>
                        <td class="outwhse-table__timestamp"><?= h($row['created_at_display']) ?></td>
                        <!-- <td><?= h($row['warehouse_location_name']) ?></td> -->
                        <!-- <td>
                           <span
                              class="outwhse-status-pill outwhse-status-pill--movement outwhse-status-pill--movement-<?= h($row['movement_type_key']) ?>">
                              <?= h($row['movement_type_label']) ?>
                           </span>
                        </td> -->
                        <td>
                           <span
                              class="outwhse-status-pill outwhse-status-pill--space outwhse-status-pill--space-<?= h($row['space_status_key']) ?>">
                              <?= h($row['space_status_label']) ?>
                           </span>
                        </td>
                        <td><?= h($row['reference_number']) ?></td>
                        <td><?= h($row['pallet_number']) ?></td>
                        <td class="outwhse-table__sku"><?= h($row['item_display']) ?></td>
                        <td><?= h($row['description']) ?></td>
                        <td><?= h($row['lot_number']) ?></td>
                        <td><?= h($row['qty']) ?></td>
                        <!-- <td><?= h($row['unit_type']) ?></td> -->
                        <td><?= h($row['source_location']) ?></td>
                        <td><?= h($row['destination_location']) ?></td>
                        <!-- <td><?= h($row['created_by']) ?></td> -->
                     </tr>
                  <?php endforeach; ?>
               </tbody>
            </table>
         </div>

         <div class="outwhse-empty" id="outwhseMovementsEmpty" <?= !empty($movement_rows) ? ' hidden' : '' ?>>
            <?php if (!$table_available): ?>
               The <code>outwhse_inventory_movements</code> table was not found.
            <?php else: ?>
               No warehouse movement rows were found for this warehouse filter yet.
            <?php endif; ?>
         </div>
      </section>
   </div>
</section>

<script id="outwhseMovementsPageData"
   type="application/json"><?= json_encode(['pageUrl' => $page_url], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<?php
$content = ob_get_clean();

include __DIR__ . '/templates/layout.php';
