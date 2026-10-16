<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';

if (!isset($conn) || !$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

if (!function_exists('pm_escape')) {
    function pm_escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pm_table_exists')) {
    function pm_table_exists(mysqli $conn, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return (bool) $cache[$table];
        }

        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $cache[$table] = false;
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $cache[$table] = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return (bool) $cache[$table];
    }
}

if (!function_exists('pm_table_column_exists')) {
    function pm_table_column_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return (bool) $cache[$key];
        }

        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $cache[$key] = false;
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $cache[$key] = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return (bool) $cache[$key];
    }
}

if (pm_table_exists($conn, 'pick_orders') && !pm_table_column_exists($conn, 'pick_orders', 'is_priority')) {
    try {
        $conn->query("ALTER TABLE pick_orders ADD COLUMN is_priority TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        error_log('[packing_manager] unable to add pick_orders.is_priority: ' . $e->getMessage());
    }
}
if (!pm_table_exists($conn, 'pick_order_notes')) {
    try {
        $conn->query("CREATE TABLE pick_order_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            note TEXT NOT NULL,
            created_by_user_id INT NULL,
            workflow_stage VARCHAR(20) NOT NULL DEFAULT 'pick',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            acknowledged_at DATETIME NULL,
            INDEX idx_pick_order_notes_order_id (order_id),
            INDEX idx_pick_order_notes_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[packing_manager] unable to create pick_order_notes: ' . $e->getMessage());
    }
}

if (!function_exists('pm_ensure_pick_order_status_values')) {
    function pm_ensure_pick_order_status_values(mysqli $conn, array $requiredValues): void
    {
        if (!pm_table_exists($conn, 'pick_orders') || !pm_table_column_exists($conn, 'pick_orders', 'status')) {
            return;
        }

        $stmt = $conn->prepare("
            SELECT COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'pick_orders'
              AND COLUMN_NAME = 'status'
            LIMIT 1
        ");
        if (!$stmt) {
            return;
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $columnType = trim((string) ($row['COLUMN_TYPE'] ?? ''));
        if ($columnType === '' || stripos($columnType, 'enum(') !== 0) {
            return;
        }

        if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $columnType, $matches)) {
            return;
        }

        $values = $matches[1] ?? [];
        $changed = false;
        foreach ($requiredValues as $value) {
            $value = strtoupper(trim((string) $value));
            if ($value !== '' && !in_array($value, $values, true)) {
                $values[] = $value;
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $enumSql = implode(', ', array_map(static function (string $value): string {
            return "'" . str_replace("'", "''", $value) . "'";
        }, $values));

        $alterSql = "ALTER TABLE pick_orders MODIFY COLUMN status ENUM({$enumSql}) NOT NULL DEFAULT 'PENDING'";
        try {
            $conn->query($alterSql);
        } catch (Throwable $e) {
            error_log('[packing_manager] unable to expand pick_orders.status enum: ' . $e->getMessage());
        }
    }
}

pm_ensure_pick_order_status_values($conn, ['PACKED', 'SHIPPED']);

if (!function_exists('pm_json_response')) {
    function pm_json_response(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('pm_build_url')) {
    function pm_build_url(array $params = []): string
    {
        $base = 'packing_manager.php';
        return $params === [] ? $base : $base . '?' . http_build_query($params);
    }
}

if (!function_exists('pm_generate_token')) {
    function pm_generate_token(): string
    {
        return bin2hex(random_bytes(16));
    }
}

if (!function_exists('pm_cleanup_session_batches')) {
    function pm_cleanup_session_batches(string $bucket, int $ttlSeconds): void
    {
        if (!isset($_SESSION[$bucket]) || !is_array($_SESSION[$bucket])) {
            $_SESSION[$bucket] = [];
            return;
        }

        $cutoff = time() - $ttlSeconds;
        foreach ($_SESSION[$bucket] as $token => $batch) {
            $createdAt = (int) ($batch['created_at'] ?? 0);
            if ($createdAt < $cutoff) {
                unset($_SESSION[$bucket][$token]);
            }
        }
    }
}

if (!function_exists('pm_load_completed_orders')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function pm_load_completed_orders(mysqli $conn, string $search, int $limit = 100): array
    {
        $orders = [];
        $search = trim($search);
        $like = '%' . $search . '%';

        if ($search === '') {
            $stmt = $conn->prepare("
                SELECT id, order_number, status, COALESCE(is_priority, 0) AS is_priority, updated_at, created_at
                FROM pick_orders
                WHERE status = 'COMPLETED'
                ORDER BY COALESCE(is_priority, 0) DESC, COALESCE(updated_at, created_at, NOW()) DESC, id DESC
                LIMIT ?
            ");
            if ($stmt) {
                $stmt->bind_param('i', $limit);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res instanceof mysqli_result) {
                    while ($row = $res->fetch_assoc()) {
                        $orders[] = $row;
                    }
                    $res->free();
                }
                $stmt->close();
            }
            return $orders;
        }

        $stmt = $conn->prepare("
            SELECT id, order_number, status, COALESCE(is_priority, 0) AS is_priority, updated_at, created_at
            FROM pick_orders
            WHERE status = 'COMPLETED'
              AND (
                    CAST(id AS CHAR) LIKE ?
                 OR order_number LIKE ?
              )
            ORDER BY COALESCE(is_priority, 0) DESC, COALESCE(updated_at, created_at, NOW()) DESC, id DESC
            LIMIT ?
        ");
        if ($stmt) {
            $stmt->bind_param('ssi', $like, $like, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res instanceof mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $orders[] = $row;
                }
                $res->free();
            }
            $stmt->close();
        }

        return $orders;
    }
}

if (!function_exists('pm_normalize_queue_mode')) {
    function pm_normalize_queue_mode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['ship', 'shipped', 'to_be_shipped'], true) ? 'ship' : 'pack';
    }
}

if (!function_exists('pm_queue_statuses_for_mode')) {
    /**
     * @return array<int,string>
     */
    function pm_queue_statuses_for_mode(string $mode): array
    {
        return pm_normalize_queue_mode($mode) === 'ship' ? ['PACKED'] : ['COMPLETED'];
    }
}

if (!function_exists('pm_load_orders_by_statuses')) {
    /**
     * @param array<int,string> $statuses
     * @return array<int,array<string,mixed>>
     */
    function pm_load_orders_by_statuses(mysqli $conn, array $statuses, string $search, int $limit = 100): array
    {
        $statuses = array_values(array_filter(array_map(static fn($status) => strtoupper(trim((string) $status)), $statuses)));
        if ($statuses === []) {
            return [];
        }

        $search = trim($search);
        $like = '%' . $search . '%';
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));
        $statusTypes = str_repeat('s', count($statuses));
        $baseSql = "
            SELECT id, order_number, status, COALESCE(is_priority, 0) AS is_priority, updated_at, created_at
            FROM pick_orders
            WHERE status IN ({$statusPlaceholders})
        ";

        if ($search === '') {
            $sql = $baseSql . "
            ORDER BY COALESCE(is_priority, 0) DESC, COALESCE(updated_at, created_at, NOW()) DESC, id DESC
            LIMIT ?
        ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $types = $statusTypes . 'i';
            $params = array_merge($statuses, [$limit]);
            $bindArgs = [$types];
            foreach ($params as $idx => $value) {
                $bindArgs[] = &$params[$idx];
            }
            call_user_func_array([$stmt, 'bind_param'], $bindArgs);
            $stmt->execute();
        } else {
            $sql = $baseSql . "
              AND (
                    CAST(id AS CHAR) LIKE ?
                 OR order_number LIKE ?
              )
            ORDER BY COALESCE(is_priority, 0) DESC, COALESCE(updated_at, created_at, NOW()) DESC, id DESC
            LIMIT ?
        ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $types = $statusTypes . 'ssi';
            $params = array_merge($statuses, [$like, $like, $limit]);
            $bindArgs = [$types];
            foreach ($params as $idx => $value) {
                $bindArgs[] = &$params[$idx];
            }
            call_user_func_array([$stmt, 'bind_param'], $bindArgs);
            $stmt->execute();
        }

        $orders = [];
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $orders[] = $row;
            }
            $res->free();
        }
        $stmt->close();

        return $orders;
    }
}

if (!function_exists('pm_load_order_notes')) {
    function pm_load_order_notes(mysqli $conn, int $orderId): array
    {
        if ($orderId <= 0 || !pm_table_exists($conn, 'pick_order_notes')) {
            return [];
        }
        $stmt = $conn->prepare('SELECT id, order_id, note, created_by_user_id, workflow_stage, created_at, acknowledged_at FROM pick_order_notes WHERE order_id = ? ORDER BY created_at DESC, id DESC');
        if (!$stmt) return [];
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $res = $stmt->get_result();
        $notes = [];
        while ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) $notes[] = $row;
        $stmt->close();
        return $notes;
    }
}

if (!function_exists('pm_load_order_record')) {
    function pm_load_order_record(mysqli $conn, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT id, order_number, status, COALESCE(is_priority, 0) AS is_priority, updated_at, created_at
            FROM pick_orders
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return null;
        }
        return $row;
    }
}

if (!function_exists('pm_load_pack_items')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function pm_load_pack_items(mysqli $conn, int $orderId): array
    {
        if ($orderId <= 0 || !pm_table_exists($conn, 'pick_items')) {
            return [];
        }

        $hasPackerVerify = pm_table_column_exists($conn, 'pick_items', 'qty_verified_packer');
        $hasShortShip = pm_table_column_exists($conn, 'pick_items', 'is_short_ship');
        $hasShortShipQty = pm_table_column_exists($conn, 'pick_items', 'short_ship_qty');
        $verifiedExpr = $hasPackerVerify
            ? 'COALESCE(pi.qty_verified_packer, pi.qty_verified, 0)'
            : 'COALESCE(pi.qty_verified, 0)';
        $shortShipExpr = $hasShortShip ? 'COALESCE(pi.is_short_ship, 0)' : '0';
        $shortShipQtyExpr = $hasShortShipQty
            ? 'COALESCE(pi.short_ship_qty, 0)'
            : 'CASE WHEN COALESCE(pi.is_short_ship, 0) = 1 THEN GREATEST(pi.qty_ordered - COALESCE(pi.qty_verified, 0), 0) ELSE 0 END';

        $items = [];
        $stmt = $conn->prepare("
            SELECT
                pi.id AS pick_item_id,
                pi.item_no,
                pi.description,
                pi.qty_ordered,
                {$verifiedExpr} AS qty_verified,
                {$shortShipExpr} AS is_short_ship,
                {$shortShipQtyExpr} AS short_ship_qty,
                s.sku_num
            FROM pick_items pi
            LEFT JOIN sku s ON s.id = pi.sku_id
            WHERE pi.order_id = ?
            ORDER BY pi.id ASC
        ");
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $qty = (int) ($row['qty_verified'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $pickItemId = (int) ($row['pick_item_id'] ?? 0);
                $items[] = [
                    'key' => 'pick:' . $pickItemId,
                    'pick_item_id' => $pickItemId,
                    'item_no' => (string) ($row['item_no'] ?? ''),
                    'sku_num' => (string) ($row['sku_num'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'qty_ordered' => (int) ($row['qty_ordered'] ?? 0),
                    'qty_verified' => $qty,
                    'is_short_ship' => (int) ($row['is_short_ship'] ?? 0),
                    'short_ship_qty' => (int) ($row['short_ship_qty'] ?? 0),
                ];
            }
            $res->free();
        }
        $stmt->close();

        return $items;
    }
}

if (!function_exists('pm_normalize_box_dimensions')) {
    function pm_normalize_box_dimensions(string $dimensions): string
    {
        $dimensions = trim((string) (preg_replace('/\s+/', ' ', $dimensions) ?? $dimensions));
        if ($dimensions === '') {
            return '';
        }
        $dimensions = str_replace('X', 'x', $dimensions);
        $dimensions = preg_replace('/\s*x\s*/i', 'x', $dimensions) ?? $dimensions;
        return strtolower(trim($dimensions));
    }
}

if (!function_exists('pm_resolve_box_supply_match')) {
    /**
     * @return array{supply_name:string,supply_category:string,inside_dimensions:string}|null
     */
    function pm_resolve_box_supply_match(mysqli $conn, string $dimensions): ?array
    {
        static $stageRows = null;
        if (!pm_table_exists($conn, 'warehouse_supplies_stage')) {
            return null;
        }

        if ($stageRows === null) {
            $stageRows = [];
            $rows = fetch_all($conn, "
                SELECT supply_name, supply_category, inside_dimensions
                FROM warehouse_supplies_stage
                WHERE inside_dimensions IS NOT NULL
                  AND inside_dimensions <> ''
            ");
            foreach ($rows as $row) {
                $insideDimensions = trim((string) ($row['inside_dimensions'] ?? ''));
                if ($insideDimensions === '') {
                    continue;
                }
                $stageRows[] = [
                    'supply_name' => trim((string) ($row['supply_name'] ?? '')),
                    'supply_category' => trim((string) ($row['supply_category'] ?? '')),
                    'inside_dimensions' => $insideDimensions,
                    'normalized' => pm_normalize_box_dimensions($insideDimensions),
                ];
            }
        }

        $target = pm_normalize_box_dimensions($dimensions);
        if ($target === '') {
            return null;
        }

        foreach ($stageRows as $row) {
            if (($row['normalized'] ?? '') === $target) {
                return [
                    'supply_name' => (string) ($row['supply_name'] ?? ''),
                    'supply_category' => (string) ($row['supply_category'] ?? ''),
                    'inside_dimensions' => (string) ($row['inside_dimensions'] ?? ''),
                ];
            }
        }

        return null;
    }
}

if (!function_exists('pm_load_available_box_types')) {
    function pm_load_available_box_types(mysqli $conn): array
    {
        if (!pm_table_exists($conn, 'box_usage_tracker')) {
            return [];
        }

        $rows = fetch_all($conn, "
            SELECT
                id,
                supply_id,
                dimensions_label,
                last_weight_label,
                cycle_used_count,
                lifetime_used_count,
                reorder_count,
                last_used_at
            FROM box_usage_tracker
            WHERE dimensions_label IS NOT NULL
              AND dimensions_label <> ''
            ORDER BY dimensions_label ASC
        ");

        $boxTypes = array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'supply_id' => (int) ($row['supply_id'] ?? 0),
                'dimensions' => trim((string) ($row['dimensions_label'] ?? '')),
                'weight_label' => trim((string) ($row['last_weight_label'] ?? '')),
                'cycle_used_count' => (int) ($row['cycle_used_count'] ?? 0),
                'lifetime_used_count' => (int) ($row['lifetime_used_count'] ?? 0),
                'reorder_count' => (int) ($row['reorder_count'] ?? 0),
                'last_used_at' => (string) ($row['last_used_at'] ?? ''),
            ];
        }, $rows);

        if (pm_table_exists($conn, 'warehouse_supplies_stage')) {
            foreach ($boxTypes as &$boxType) {
                $match = pm_resolve_box_supply_match($conn, (string) ($boxType['dimensions'] ?? ''));
                if ($match) {
                    $boxType['supply_name'] = (string) ($match['supply_name'] ?? '');
                    $boxType['supply_category'] = (string) ($match['supply_category'] ?? '');
                    $boxType['inside_dimensions'] = (string) ($match['inside_dimensions'] ?? '');
                }
            }
            unset($boxType);
        }

        return array_values($boxTypes);
    }
}

if (!function_exists('pm_resolve_current_user_id')) {
    function pm_resolve_current_user_id(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('pm_record_box_usage')) {
    /**
     * @param array<int,array<string,mixed>> $boxes
     * @return array{recorded_count:int,rows:array<int,array<string,mixed>>}
     */
    function pm_record_box_usage(mysqli $conn, string $salesOrderNumber, array $boxes): array
    {
        if (!pm_table_exists($conn, 'box_usage_tracker') || !pm_table_exists($conn, 'box_usage_events')) {
            return ['recorded_count' => 0, 'rows' => []];
        }

        $salesOrderNumber = trim((string) (preg_replace('/\s+/', ' ', $salesOrderNumber) ?? $salesOrderNumber));
        if ($salesOrderNumber === '' || $boxes === []) {
            return ['recorded_count' => 0, 'rows' => []];
        }
        if (count($boxes) > 50) {
            throw new RuntimeException('A maximum of 50 box labels can be recorded at once.');
        }

        $currentUserId = pm_resolve_current_user_id();
        $results = [];
        $recordedCount = 0;

        $conn->begin_transaction();
        try {
            foreach ($boxes as $boxRow) {
                if (!is_array($boxRow)) {
                    continue;
                }
                $boxIdentifier = trim((string) (preg_replace('/\s+/', ' ', (string) ($boxRow['box_identifier'] ?? '')) ?? ($boxRow['box_identifier'] ?? '')));
                $dimensionsLabel = trim((string) (preg_replace('/\s+/', ' ', (string) ($boxRow['dimensions'] ?? '')) ?? ($boxRow['dimensions'] ?? '')));
                $weightLabel = trim((string) (preg_replace('/\s+/', ' ', (string) ($boxRow['weight_label'] ?? '')) ?? ($boxRow['weight_label'] ?? '')));
                if ($boxIdentifier === '' || $dimensionsLabel === '' || $weightLabel === '') {
                    throw new InvalidArgumentException('Each box label needs a box id, dimensions, and weight.');
                }

                $dimensionsNormalized = pm_normalize_box_dimensions($dimensionsLabel);
                if ($dimensionsNormalized === '') {
                    throw new InvalidArgumentException('Dimensions are required.');
                }

                $trackerId = 0;
                $cycleUsedCount = 0;
                $lifetimeUsedCount = 0;

                $selectStmt = $conn->prepare('
                    SELECT id, cycle_used_count, lifetime_used_count
                    FROM box_usage_tracker
                    WHERE dimensions_normalized = ?
                    LIMIT 1
                    FOR UPDATE
                ');
                if (!$selectStmt) {
                    throw new RuntimeException('Unable to prepare box tracker lookup.');
                }
                $selectStmt->bind_param('s', $dimensionsNormalized);
                $selectStmt->execute();
                $row = $selectStmt->get_result()->fetch_assoc() ?: null;
                $selectStmt->close();

                if ($row) {
                    $trackerId = (int) ($row['id'] ?? 0);
                    $cycleUsedCount = ((int) ($row['cycle_used_count'] ?? 0)) + 1;
                    $lifetimeUsedCount = ((int) ($row['lifetime_used_count'] ?? 0)) + 1;
                    $updateStmt = $conn->prepare('
                        UPDATE box_usage_tracker
                        SET dimensions_label = ?,
                            last_weight_label = ?,
                            cycle_used_count = ?,
                            lifetime_used_count = ?,
                            last_sales_order_number = ?,
                            last_box_identifier = ?,
                            last_used_by_user_id = ?,
                            last_used_at = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ');
                    if (!$updateStmt) {
                        throw new RuntimeException('Unable to prepare box tracker update.');
                    }
                    $updateStmt->bind_param(
                        'ssiissii',
                        $dimensionsLabel,
                        $weightLabel,
                        $cycleUsedCount,
                        $lifetimeUsedCount,
                        $salesOrderNumber,
                        $boxIdentifier,
                        $currentUserId,
                        $trackerId
                    );
                    $updateStmt->execute();
                    $updateStmt->close();
                } else {
                    $cycleUsedCount = 1;
                    $lifetimeUsedCount = 1;
                    $insertStmt = $conn->prepare('
                        INSERT INTO box_usage_tracker (
                            dimensions_label,
                            dimensions_normalized,
                            last_weight_label,
                            cycle_used_count,
                            lifetime_used_count,
                            last_sales_order_number,
                            last_box_identifier,
                            created_by_user_id,
                            last_used_by_user_id,
                            last_used_at
                        ) VALUES (?, ?, ?, 1, 1, ?, ?, ?, ?, NOW())
                    ');
                    if (!$insertStmt) {
                        throw new RuntimeException('Unable to prepare box tracker insert.');
                    }
                    $insertStmt->bind_param(
                        'sssssii',
                        $dimensionsLabel,
                        $dimensionsNormalized,
                        $weightLabel,
                        $salesOrderNumber,
                        $boxIdentifier,
                        $currentUserId,
                        $currentUserId
                    );
                    $insertStmt->execute();
                    $trackerId = (int) $insertStmt->insert_id;
                    $insertStmt->close();
                }

                $eventStmt = $conn->prepare('
                    INSERT INTO box_usage_events (
                        tracker_id,
                        sales_order_number,
                        box_identifier,
                        dimensions_label,
                        weight_label,
                        created_by_user_id
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ');
                if (!$eventStmt) {
                    throw new RuntimeException('Unable to prepare box usage event insert.');
                }
                $eventStmt->bind_param(
                    'issssi',
                    $trackerId,
                    $salesOrderNumber,
                    $boxIdentifier,
                    $dimensionsLabel,
                    $weightLabel,
                    $currentUserId
                );
                $eventStmt->execute();
                $eventStmt->close();

                $results[] = [
                    'tracker_id' => $trackerId,
                    'dimensions_label' => $dimensionsLabel,
                    'cycle_used_count' => $cycleUsedCount,
                    'lifetime_used_count' => $lifetimeUsedCount,
                ];
                $recordedCount++;
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        return [
            'recorded_count' => $recordedCount,
            'rows' => $results,
        ];
    }
}

if (!function_exists('pm_load_manifest_batch')) {
    function pm_load_manifest_batch(mysqli $conn, string $manifestToken): ?array
    {
        $manifestToken = trim($manifestToken);
        if ($manifestToken === '') {
            return null;
        }

        pm_cleanup_session_batches('packing_manifest_batches', 86400);
        if (isset($_SESSION['packing_manifest_batches'][$manifestToken]) && is_array($_SESSION['packing_manifest_batches'][$manifestToken])) {
            return $_SESSION['packing_manifest_batches'][$manifestToken];
        }

        if (!pm_table_exists($conn, 'packing_manifests')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT manifest_token, order_id, sales_order_number, box_count, item_count, ordered_count, unassigned_count, manifest_json, finalized_by_user_id, created_at, updated_at
            FROM packing_manifests
            WHERE manifest_token = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $manifestToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$row) {
            return null;
        }

        $manifestJson = json_decode((string) ($row['manifest_json'] ?? ''), true);
        if (!is_array($manifestJson)) {
            $manifestJson = [];
        }

        return [
            'manifest_token' => (string) ($row['manifest_token'] ?? $manifestToken),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'sales_order_number' => (string) ($row['sales_order_number'] ?? ''),
            'summary' => [
                'box_count' => (int) ($row['box_count'] ?? 0),
                'item_count' => (int) ($row['item_count'] ?? 0),
                'ordered_count' => (int) ($row['ordered_count'] ?? 0),
                'unassigned_count' => (int) ($row['unassigned_count'] ?? 0),
            ],
            'boxes' => is_array($manifestJson['boxes'] ?? null) ? $manifestJson['boxes'] : [],
            'items' => is_array($manifestJson['items'] ?? null) ? $manifestJson['items'] : [],
            'finalized_by_user_id' => (int) ($row['finalized_by_user_id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}

if (!function_exists('pm_store_manifest_batch')) {
    function pm_store_manifest_batch(mysqli $conn, array $batch): array
    {
        if (!pm_table_exists($conn, 'packing_manifests')) {
            throw new RuntimeException('Packing manifest table is missing. Run inventory/sql/2026_04_23_create_packing_manifests.sql first.');
        }

        $json = json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Unable to serialize packing manifest.');
        }

        $token = trim((string) ($batch['manifest_token'] ?? ''));
        $orderId = (int) ($batch['order_id'] ?? 0);
        $salesOrderNumber = trim((string) ($batch['sales_order_number'] ?? ''));
        $summary = is_array($batch['summary'] ?? null) ? $batch['summary'] : [];
        $boxCount = (int) ($summary['box_count'] ?? 0);
        $itemCount = (int) ($summary['item_count'] ?? 0);
        $orderedCount = (int) ($summary['ordered_count'] ?? 0);
        $unassignedCount = (int) ($summary['unassigned_count'] ?? 0);
        $finalizedByUserId = (int) ($batch['finalized_by_user_id'] ?? 0);

        if ($token === '' || $orderId <= 0 || $salesOrderNumber === '') {
            throw new RuntimeException('Incomplete packing manifest payload.');
        }

        $conn->begin_transaction();
        try {
            $deleteStmt = $conn->prepare('DELETE FROM packing_manifests WHERE order_id = ?');
            if (!$deleteStmt) {
                throw new RuntimeException('Unable to prepare packing manifest cleanup.');
            }
            $deleteStmt->bind_param('i', $orderId);
            $deleteStmt->execute();
            $deleteStmt->close();

            $stmt = $conn->prepare('
                INSERT INTO packing_manifests
                    (manifest_token, order_id, sales_order_number, box_count, item_count, ordered_count, unassigned_count, manifest_json, finalized_by_user_id)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare packing manifest save.');
            }
            $stmt->bind_param(
                'sisiiiisi',
                $token,
                $orderId,
                $salesOrderNumber,
                $boxCount,
                $itemCount,
                $orderedCount,
                $unassignedCount,
                $json,
                $finalizedByUserId
            );
            if (!$stmt->execute()) {
                $error = $stmt->error ?: $conn->error ?: 'Unable to save packing manifest.';
                $stmt->close();
                throw new RuntimeException($error);
            }
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        return $batch;
    }
}

if (!function_exists('pm_render_manifest_print_page')) {
    function pm_render_manifest_print_page(array $batch): void
    {
        $title = 'Packing Manifest';
        $page_class = 'page-packing-manifest-print';
        $css = [];
        $so = htmlspecialchars((string) ($batch['sales_order_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $summary = is_array($batch['summary'] ?? null) ? $batch['summary'] : [];
        $boxes = is_array($batch['boxes'] ?? null) ? $batch['boxes'] : [];
        ob_start();
        ?>
        <div class="manifest-sheet">
            <div class="manifest-head">
                <div>
                    <h1 class="manifest-title">Packing Manifest</h1>
                    <div class="manifest-sub">Sales order <?= $so ?></div>
                </div>
                <div>
                    <span class="manifest-pill">Boxes: <?= (int) ($summary['box_count'] ?? 0) ?></span>
                    <span class="manifest-pill">Items: <?= (int) ($summary['item_count'] ?? 0) ?></span>
                    <span class="manifest-pill">Ordered: <?= (int) ($summary['ordered_count'] ?? 0) ?></span>
                    <span class="manifest-pill">Unassigned: <?= (int) ($summary['unassigned_count'] ?? 0) ?></span>
                </div>
            </div>
            <?php foreach ($boxes as $box): ?>
                <div class="manifest-box">
                    <div class="manifest-box-name"><?= pm_escape((string) ($box['box_identifier'] ?? 'Box')) ?></div>
                    <div class="manifest-sub">
                        <?= pm_escape((string) ($box['dimensions'] ?? '')) ?> ·
                        <?= pm_escape((string) ($box['weight_label'] ?? '')) ?>
                    </div>
                    <ul class="manifest-items">
                        <?php foreach (($box['items'] ?? []) as $item): ?>
                            <li>
                                <?= pm_escape((string) ($item['sku'] ?? '')) ?>
                                <?= pm_escape(trim(' ' . (string) ($item['description'] ?? ''))) ?>
                                × <?= (int) ($item['qty'] ?? 0) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        $content = ob_get_clean();
        include __DIR__ . '/templates/layout.php';
        exit;
    }
}

if (!function_exists('pm_render_box_label_print_page')) {
    function pm_render_box_label_print_page(array $batch): void
    {
        $title = 'Box Labels';
        $page_class = 'page-box-label-print';
        $css = [];
        $so = htmlspecialchars((string) ($batch['sales_order_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $boxes = is_array($batch['boxes'] ?? null) ? $batch['boxes'] : [];
        ob_start();
        ?>
        <div class="box-label-print-stack">
            <?php foreach ($boxes as $box): ?>
                <div class="box-label-print">
                    <div class="box-label-print__top">
                        <div>
                            <div class="box-label-print__label">SALES ORDER #</div>
                            <div class="box-label-print__value"><?= $so ?></div>
                        </div>
                        <div>
                            <div class="box-label-print__label">BOX ID</div>
                            <div class="box-label-print__value"><?= pm_escape((string) ($box['box_identifier'] ?? '')) ?></div>
                        </div>
                    </div>
                    <div class="box-label-print__bottom">
                        <div>
                            <div class="box-label-print__label">DIMENSIONS</div>
                            <div class="box-label-print__value"><?= pm_escape((string) ($box['dimensions'] ?? '')) ?></div>
                        </div>
                        <div>
                            <div class="box-label-print__label">WEIGHT</div>
                            <div class="box-label-print__value"><?= pm_escape((string) ($box['weight_label'] ?? '')) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        $content = ob_get_clean();
        include __DIR__ . '/templates/layout.php';
        exit;
    }
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));

if ($action === 'packing_manifest' || $action === 'packing_manifest_print' || $action === 'box_label_print') {
    $token = trim((string) ($_GET['token'] ?? $_GET['manifest_token'] ?? ''));
    $batch = pm_load_manifest_batch($conn, $token);
    if (!is_array($batch)) {
        http_response_code(404);
        echo $action === 'packing_manifest' ? '{"ok":false,"error":"Packing manifest not found or expired."}' : 'Packing manifest not found or expired.';
        exit;
    }

    if ($action === 'packing_manifest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($action === 'packing_manifest_print') {
        pm_render_manifest_print_page($batch);
    }

    pm_render_box_label_print_page($batch);
}

if ($action === 'record_box_usage') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        pm_json_response(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $salesOrderNumber = trim((string) ($payload['sales_order_number'] ?? ''));
    $boxes = is_array($payload['boxes'] ?? null) ? $payload['boxes'] : [];
    if ($salesOrderNumber === '' || $boxes === []) {
        pm_json_response(['ok' => false, 'error' => 'Sales order and box data are required.'], 400);
    }

    try {
        $result = pm_record_box_usage($conn, $salesOrderNumber, $boxes);
    } catch (Throwable $e) {
        pm_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    pm_json_response([
        'ok' => true,
        'recorded_count' => (int) ($result['recorded_count'] ?? 0),
        'rows' => is_array($result['rows'] ?? null) ? $result['rows'] : [],
    ]);
}

if ($action === 'finalize_packing') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        pm_json_response(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    $salesOrderNumber = trim((string) ($payload['sales_order_number'] ?? ''));
    $queueMode = 'pack';
    $targetStatus = 'PACKED';
    $boxes = is_array($payload['boxes'] ?? null) ? $payload['boxes'] : [];
    if ($orderId <= 0 || $salesOrderNumber === '' || $boxes === []) {
        pm_json_response(['ok' => false, 'error' => 'Order and packed box data are required.'], 400);
    }

    $order = pm_load_order_record($conn, $orderId);
    if (!$order) {
        pm_json_response(['ok' => false, 'error' => 'Order not found.'], 404);
    }
    $currentStatus = strtoupper(trim((string) ($order['status'] ?? '')));
    $allowedInputStatuses = pm_queue_statuses_for_mode($queueMode);
    if (!in_array($currentStatus, $allowedInputStatuses, true)) {
        pm_json_response(['ok' => false, 'error' => 'This order cannot be finalized from the current queue.'], 400);
    }

    $workspaceItems = pm_load_pack_items($conn, $orderId);
    $expectedQtyByKey = [];
    foreach ($workspaceItems as $item) {
        $itemKey = (string) ($item['key'] ?? '');
        if ($itemKey === '') {
            continue;
        }
        $expectedQtyByKey[$itemKey] = (int) ($item['qty_verified'] ?? 0);
    }

    $manifestBoxes = [];
    $manifestItems = [];
    $packedQtyByKey = [];
    $boxCount = 0;

    foreach ($boxes as $boxRow) {
        if (!is_array($boxRow)) {
            continue;
        }
        $boxIdentifier = trim((string) ($boxRow['box_identifier'] ?? ''));
        $dimensions = trim((string) ($boxRow['dimensions'] ?? ''));
        $weightLabel = trim((string) ($boxRow['weight_label'] ?? ''));
        $items = is_array($boxRow['items'] ?? null) ? $boxRow['items'] : [];
        if ($boxIdentifier === '' || $dimensions === '' || $weightLabel === '') {
            pm_json_response(['ok' => false, 'error' => 'Each box needs an id, dimensions, and weight before finalizing.'], 400);
        }

        $boxCount++;
        $packedItems = [];
        foreach ($items as $itemRow) {
            if (!is_array($itemRow)) {
                continue;
            }
            $itemKey = trim((string) ($itemRow['key'] ?? ''));
            $sku = trim((string) ($itemRow['sku'] ?? ''));
            $desc = trim((string) ($itemRow['description'] ?? ''));
            $qty = (int) ($itemRow['qty'] ?? 0);
            $sourceQty = (int) ($itemRow['source_qty'] ?? 0);
            if ($itemKey === '' || $sku === '' || $qty <= 0) {
                continue;
            }
            if (!array_key_exists($itemKey, $expectedQtyByKey)) {
                pm_json_response(['ok' => false, 'error' => 'Packed item metadata does not match the order.'], 400);
            }

            $packedQtyByKey[$itemKey] = ($packedQtyByKey[$itemKey] ?? 0) + $qty;
            $packedItems[] = [
                'key' => $itemKey,
                'sku' => $sku,
                'description' => $desc,
                'qty' => $qty,
                'source_qty' => $sourceQty > 0 ? $sourceQty : $qty,
            ];
            $manifestItems[] = [
                'key' => $itemKey,
                'box_identifier' => $boxIdentifier,
                'sku' => $sku,
                'description' => $desc,
                'qty' => $qty,
                'source_qty' => $sourceQty > 0 ? $sourceQty : $qty,
            ];
        }

        $manifestBoxes[] = [
            'box_identifier' => $boxIdentifier,
            'dimensions' => $dimensions,
            'weight_label' => $weightLabel,
            'items' => $packedItems,
        ];
    }

    if ($manifestBoxes === [] || $manifestItems === []) {
        pm_json_response(['ok' => false, 'error' => 'Assign at least one verified item to a box before finalizing.'], 400);
    }

    foreach ($expectedQtyByKey as $itemKey => $expectedQty) {
        $packedQty = (int) ($packedQtyByKey[$itemKey] ?? 0);
        if ($packedQty !== (int) $expectedQty) {
            pm_json_response(['ok' => false, 'error' => 'Every verified item must be fully allocated across boxes before finalizing.'], 400);
        }
    }

    $totalItems = array_reduce($manifestBoxes, static function (int $carry, array $box): int {
        $boxTotal = 0;
        foreach (($box['items'] ?? []) as $item) {
            $boxTotal += (int) ($item['qty'] ?? 0);
        }
        return $carry + $boxTotal;
    }, 0);

    $manifestToken = pm_generate_token();
    $manifestBatch = [
        'created_at' => time(),
        'manifest_token' => $manifestToken,
        'order_id' => $orderId,
        'sales_order_number' => $salesOrderNumber,
        'summary' => [
            'box_count' => count($manifestBoxes),
            'item_count' => $totalItems,
            'ordered_count' => array_sum($expectedQtyByKey),
            'unassigned_count' => 0,
        ],
        'boxes' => $manifestBoxes,
        'items' => $manifestItems,
        'finalized_by_user_id' => (int) ($_SESSION['user_id'] ?? 0),
    ];

    if ($queueMode === 'pack') {
        try {
            $manifestBatch = pm_store_manifest_batch($conn, $manifestBatch);
        } catch (Throwable $e) {
            pm_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        try {
            pm_record_box_usage($conn, $salesOrderNumber, $manifestBoxes);
        } catch (Throwable $e) {
            // Finalization should still complete even if usage tracking is unavailable.
        }
    }

    $statusStmt = $conn->prepare('
        UPDATE pick_orders
        SET status = ?, updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ');
    if (!$statusStmt) {
        pm_json_response(['ok' => false, 'error' => 'Unable to update order status.'], 500);
    }
    $statusStmt->bind_param('si', $targetStatus, $orderId);
    if (!$statusStmt->execute()) {
        $error = $statusStmt->error ?: $conn->error ?: 'Unable to update order status.';
        $statusStmt->close();
        pm_json_response(['ok' => false, 'error' => $error], 500);
    }
    $statusStmt->close();

    pm_cleanup_session_batches('packing_manifest_batches', 86400);
    $_SESSION['packing_manifest_batches'][$manifestToken] = $manifestBatch;

    pm_json_response([
        'ok' => true,
        'token' => $manifestToken,
        'box_count' => count($manifestBoxes),
        'item_count' => $totalItems,
        'summary' => $manifestBatch['summary'],
    ]);
}

if ($action === 'ship_order') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        pm_json_response(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    $salesOrderNumber = trim((string) ($payload['sales_order_number'] ?? ''));
    if ($orderId <= 0) {
        pm_json_response(['ok' => false, 'error' => 'Order is required.'], 400);
    }

    $order = pm_load_order_record($conn, $orderId);
    if (!$order) {
        pm_json_response(['ok' => false, 'error' => 'Order not found.'], 404);
    }

    $currentStatus = strtoupper(trim((string) ($order['status'] ?? '')));
    if ($currentStatus !== 'PACKED') {
        pm_json_response(['ok' => false, 'error' => 'Only packed orders can be shipped.'], 400);
    }

    if ($salesOrderNumber === '') {
        $salesOrderNumber = trim((string) ($order['order_number'] ?? ''));
    }

    if (!pm_table_exists($conn, 'packing_manifests')) {
        pm_json_response(['ok' => false, 'error' => 'Packed manifest table is missing.'], 500);
    }

    $manifestStmt = $conn->prepare("
        SELECT manifest_token, order_id, sales_order_number, box_count, item_count, ordered_count, unassigned_count, manifest_json, finalized_by_user_id, created_at, updated_at
        FROM packing_manifests
        WHERE order_id = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    if (!$manifestStmt) {
        pm_json_response(['ok' => false, 'error' => 'Unable to load packed manifest.'], 500);
    }

    $manifestStmt->bind_param('i', $orderId);
    $manifestStmt->execute();
    $manifestRow = $manifestStmt->get_result()->fetch_assoc() ?: null;
    $manifestStmt->close();
    if (!$manifestRow) {
        pm_json_response(['ok' => false, 'error' => 'Packed manifest not found for this order.'], 404);
    }

    $statusStmt = $conn->prepare('
        UPDATE pick_orders
        SET status = ?, is_priority = 0, updated_at = NOW()
        WHERE id = ?
        LIMIT 1
    ');
    if (!$statusStmt) {
        pm_json_response(['ok' => false, 'error' => 'Unable to update order status.'], 500);
    }
    $shippedStatus = 'SHIPPED';
    $statusStmt->bind_param('si', $shippedStatus, $orderId);
    if (!$statusStmt->execute()) {
        $error = $statusStmt->error ?: $conn->error ?: 'Unable to update order status.';
        $statusStmt->close();
        pm_json_response(['ok' => false, 'error' => $error], 500);
    }
    $statusStmt->close();

    pm_json_response([
        'ok' => true,
        'token' => (string) ($manifestRow['manifest_token'] ?? ''),
        'status' => 'SHIPPED',
    ]);
}

$search = trim((string) ($_GET['q'] ?? ''));
$queueMode = pm_normalize_queue_mode((string) ($_GET['mode'] ?? 'pack'));
$queueStatuses = pm_queue_statuses_for_mode($queueMode);
$queueSearchLabel = $queueMode === 'ship' ? 'packed order' : 'completed order';
$queueSearchPlaceholder = $queueMode === 'ship'
    ? 'Search packed orders by number or id'
    : 'Search completed orders by number or id';
$queueEmptyMessage = $queueMode === 'ship'
    ? 'No packed orders matched the current search.'
    : 'No completed orders matched the current search.';
$queueCountLabel = $queueMode === 'ship' ? 'packed order' : 'completed order';
$queueItemPaneTitle = $queueMode === 'ship' ? 'Items to Ship' : 'Items to Pack';
$queueItemPaneNote = $queueMode === 'ship'
    ? 'Review the verified items assigned to the active box.'
    : 'Choose which verified items belong in the active box.';
$queueFinalizeLabel = $queueMode === 'ship' ? 'Mark Shipped' : 'Finalize Packing';
$selectedOrderStatus = '';
$selectedOrderId = (int) ($_GET['order_id'] ?? 0);
$selectedOrderNumber = trim((string) ($_GET['order_number'] ?? ''));
$orders = pm_load_orders_by_statuses($conn, $queueStatuses, $search, 100);
$queueFiltered = false;
$selectedOrder = null;
if ($selectedOrderId > 0) {
    $selectedOrder = pm_load_order_record($conn, $selectedOrderId);
}
if ($selectedOrder) {
    $selectedOrderStatus = strtoupper(trim((string) ($selectedOrder['status'] ?? '')));
}
if ($selectedOrder && !in_array($selectedOrderStatus, $queueStatuses, true)) {
    $selectedOrder = null;
    $selectedOrderId = 0;
    $selectedOrderNumber = '';
}
if (!$selectedOrder && $selectedOrderNumber !== '') {
    foreach ($orders as $candidate) {
        if ((string) ($candidate['order_number'] ?? '') === $selectedOrderNumber) {
            $selectedOrder = $candidate;
            $selectedOrderId = (int) ($candidate['id'] ?? 0);
            break;
        }
    }
    if (!$selectedOrder && $selectedOrderId <= 0) {
        $stmt = $conn->prepare("SELECT id, order_number, status, COALESCE(is_priority, 0) AS is_priority, updated_at, created_at FROM pick_orders WHERE order_number = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $selectedOrderNumber);
            $stmt->execute();
            $selectedOrder = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if ($selectedOrder) {
                $selectedOrderStatus = strtoupper(trim((string) ($selectedOrder['status'] ?? '')));
                if (!in_array($selectedOrderStatus, $queueStatuses, true)) {
                    $selectedOrder = null;
                    $selectedOrderId = 0;
                    $selectedOrderNumber = '';
                } else {
                    $selectedOrderId = (int) ($selectedOrder['id'] ?? 0);
                }
            }
        }
    }
}

if ($selectedOrder) {
    $selectedOrderStatus = strtoupper(trim((string) ($selectedOrder['status'] ?? '')));
}

if ($selectedOrder && !in_array($selectedOrderStatus, $queueStatuses, true)) {
    $selectedOrder = null;
    $selectedOrderId = 0;
    $selectedOrderNumber = '';
}

if ($selectedOrderId > 0 && $selectedOrder) {
    $orders = array_values(array_filter($orders, static fn(array $order): bool => (int) ($order['id'] ?? 0) === $selectedOrderId));
    if ($orders === [] && $selectedOrder) {
        $orders = [$selectedOrder];
    }
    $queueFiltered = true;
}

$selectedWorkspaceItems = $selectedOrderId > 0 ? pm_load_pack_items($conn, $selectedOrderId) : [];
$selectedOrderNotes = $selectedOrderId > 0 ? pm_load_order_notes($conn, $selectedOrderId) : [];
$availableBoxTypes = pm_load_available_box_types($conn);
$selectedManifest = null;
if ($selectedOrderId > 0) {
    if (pm_table_exists($conn, 'packing_manifests')) {
        $stmt = $conn->prepare("
            SELECT manifest_token, order_id, sales_order_number, box_count, item_count, ordered_count, unassigned_count, manifest_json, finalized_by_user_id, created_at, updated_at
            FROM packing_manifests
            WHERE order_id = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $selectedOrderId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if ($row) {
                $manifestJson = json_decode((string) ($row['manifest_json'] ?? ''), true);
                if (is_array($manifestJson)) {
                    $selectedManifest = [
                        'manifest_token' => (string) ($row['manifest_token'] ?? ''),
                        'order_id' => (int) ($row['order_id'] ?? 0),
                        'sales_order_number' => (string) ($row['sales_order_number'] ?? ''),
                        'summary' => [
                            'box_count' => (int) ($row['box_count'] ?? 0),
                            'item_count' => (int) ($row['item_count'] ?? 0),
                            'ordered_count' => (int) ($row['ordered_count'] ?? 0),
                            'unassigned_count' => (int) ($row['unassigned_count'] ?? 0),
                        ],
                        'boxes' => is_array($manifestJson['boxes'] ?? null) ? $manifestJson['boxes'] : [],
                        'items' => is_array($manifestJson['items'] ?? null) ? $manifestJson['items'] : [],
                        'finalized_by_user_id' => (int) ($row['finalized_by_user_id'] ?? 0),
                        'created_at' => (string) ($row['created_at'] ?? ''),
                        'updated_at' => (string) ($row['updated_at'] ?? ''),
                    ];
                }
            }
        }
    }
}

$workspacePayload = [
    'queue_mode' => $queueMode,
    'order' => $selectedOrder ? [
        'id' => (int) ($selectedOrder['id'] ?? 0),
        'order_number' => (string) ($selectedOrder['order_number'] ?? ''),
        'status' => (string) ($selectedOrder['status'] ?? ''),
        'is_priority' => (int) ($selectedOrder['is_priority'] ?? 0),
        'updated_at' => (string) ($selectedOrder['updated_at'] ?? ''),
        'created_at' => (string) ($selectedOrder['created_at'] ?? ''),
    ] : null,
    'items' => $selectedWorkspaceItems,
    'notes' => $selectedOrderNotes,
    'available_box_types' => $availableBoxTypes,
    'manifest' => $selectedManifest,
];

$title = 'Packing Manager';
$page_class = 'page-packing-manager';
$css = ['/refactor_css/packing_manager.css'];

ob_start();
?>
<section class="packing-manager-shell">
    <article>
        <h2>Packing Manager</h2>
        <div class="packing-manager-hero__actions">
            <div class="packing-manager-mode-switch" role="tablist" aria-label="Packing manager queue mode">
                <a
                    class="btn<?= $queueMode === 'pack' ? ' btn--primary packing-manager-mode-switch__btn is-active' : ' btn--ghost packing-manager-mode-switch__btn' ?>"
                    href="<?= pm_escape(pm_build_url(['mode' => 'pack', 'q' => $search])) ?>"
                    aria-pressed="<?= $queueMode === 'pack' ? 'true' : 'false' ?>"
                >
                    TO BE PACKED
                </a>
                <a
                    class="btn<?= $queueMode === 'ship' ? ' btn--primary packing-manager-mode-switch__btn is-active' : ' btn--ghost packing-manager-mode-switch__btn' ?>"
                    href="<?= pm_escape(pm_build_url(['mode' => 'ship', 'q' => $search])) ?>"
                    aria-pressed="<?= $queueMode === 'ship' ? 'true' : 'false' ?>"
                >
                    TO BE SHIPPED
                </a>
            </div>
            <?php if ($selectedOrderId > 0): ?>
                <a href="<?= pm_escape(pm_build_url(['mode' => $queueMode, 'q' => $search])) ?>" class="btn btn--ghost">Clear Selection</a>
            <?php endif; ?>
        </div>
    </article>

    <div class="packing-manager-grid">
        <section class="card packing-manager-panel">
            <form method="get" class="packing-manager-search">
                <input type="hidden" name="mode" value="<?= pm_escape($queueMode) ?>" />
                <input type="text" name="q" class="form-control" placeholder="<?= pm_escape($queueSearchPlaceholder) ?>"
                    value="<?= pm_escape($search) ?>" autocomplete="off" />
                <button type="submit" class="btn btn--ghost">Search</button>
            </form>

            <div class="muted">
                Showing <?= count($orders) ?> <?= pm_escape($queueCountLabel) ?><?= count($orders) === 1 ? '' : 's' ?>.
                <?php if ($queueFiltered): ?>
                    Filtered to the selected order.
                <?php endif; ?>
            </div>

            <div class="packing-manager-list packing-manager-list--cards">
                <?php if ($orders === []): ?>
                    <div class="muted"><?= pm_escape($queueEmptyMessage) ?></div>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $orderId = (int) ($order['id'] ?? 0);
                        $orderNumber = (string) ($order['order_number'] ?? '');
                        $isSelected = $selectedOrderId > 0 && $selectedOrderId === $orderId;
                        ?>
                        <article
                            class="packing-manager-order--card<?= $isSelected ? ' packing-manager-order--selected' : '' ?><?= !empty($order['is_priority']) ? ' packing-manager-order--priority' : '' ?>">
                            <div class="packing-manager-order__body">
                                <?php if (!empty($order['is_priority'])): ?><div class="packing-priority-badge">⚑ PRIORITY</div><?php endif; ?>
                                <div class="packing-manager-order__title"><?= pm_escape($orderNumber) ?></div>
                                <div class="packing-manager-order__meta">
                                    Status: <?= pm_escape((string) ($order['status'] ?? '')) ?><br />
                                    Updated <?= pm_escape((string) ($order['updated_at'] ?? $order['created_at'] ?? '')) ?>
                                </div>
                            </div>
                            <div class="packing-manager-order__actions">
                                <a class="btn btn--ghost"
                                    href="<?= pm_escape(pm_build_url(['mode' => $queueMode, 'order_id' => $orderId, 'order_number' => $orderNumber, 'q' => $search])) ?>">
                                    Open
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <aside class="card packing-manager-panel packing-manager-workspace">
            <!-- <div class="packing-manager-workspace__header">
                <div>
                    <h2 class="packing-manager-workspace__title">Packing Workspace</h2>
                    <div class="muted">
                        <?php if ($selectedOrder): ?>
                            Working order <?= pm_escape((string) ($selectedOrder['order_number'] ?? '')) ?>.
                        <?php else: ?>
                            Open a completed order card to start packing.
                        <?php endif; ?>
                    </div>
                </div>
            </div> -->

            <?php if ($selectedOrder): ?>
                <div class="packing-workspace">
                    <div class="card packing-workspace__header-card">
                            <?php if (!empty($selectedOrder['is_priority'])): ?><div class="packing-priority-banner">⚑ PRIORITY ORDER — complete this order first</div><?php endif; ?>
                            <section class="packing-order-notes" aria-labelledby="packingOrderNotesTitle">
                                <div class="packing-order-notes__header"><h3 id="packingOrderNotesTitle">Pick / Pack Notes</h3><button type="button" class="btn btn--ghost" id="packingAddNoteBtn">Add Note</button></div>
                                <div id="packingOrderNotesList" class="packing-order-notes__list"></div>
                            </section>
                            <div class="box-label-modal-grid">
                                <div>
                                    <label for="boxLabelSO" style="font-weight:600; display:block; margin-bottom:4px;">Sales
                                        Order #</label>
                                <input class="input" id="boxLabelSO" name="boxLabelSO"
                                    value="<?= pm_escape((string) ($selectedOrder['order_number'] ?? '')) ?>" />
                            </div>
                            <div>
                                <label for="boxLabelCount" style="font-weight:600; display:block; margin-bottom:4px;">Box number</label>
                                <div style="display:flex; gap:8px; align-items:center;">
                                    <button type="button" class="btn btn--ghost" id="boxLabelCountDecrease" aria-label="Decrease box number">-</button>
                                    <input class="input" id="boxLabelCount" name="boxLabelCount" type="number" min="1" step="1"
                                        value="<?= $selectedManifest ? (int) ($selectedManifest['summary']['box_count'] ?? 1) : 1 ?>" />
                                    <button type="button" class="btn btn--ghost" id="boxLabelCountIncrease" aria-label="Increase box number">+</button>
                                </div>
                            </div>
                        </div>
                        <!-- <div id="boxLabelSequenceNote" class="box-label-sequence-note">
                            Labels will print as <strong>Box 1</strong>. Each box can have its own dimensions, weight, and item assignments.
                        </div> -->
                        <div class="box-label-modal-actions" id="boxLabelManagerActions">
                            <button type="button" class="btn btn-danger" id="boxLabelCancelBtn">Reset Draft</button>
                            <?php if ($queueMode === 'ship' && $selectedOrderStatus === 'PACKED'): ?>
                                <button type="button" class="btn btn--ghost" id="boxLabelShipBtn">Ship Order</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-success" id="boxLabelFinalizeBtn"><?= pm_escape($queueFinalizeLabel) ?></button>
                            <?php endif; ?>
                            <button type="button" class="btn btn--ghost" id="boxLabelPrintLabelsBtn">Print Labels</button>
                            <button type="button" class="btn btn--ghost" id="boxLabelPrintSummaryBtn">Print Summary</button>
                        </div>
                    </div>

                    <div class="card packing-workspace__body-card">
                        <div class="box-label-modal-layout">
                            <div class="box-label-modal-main">
                                <div id="boxLabelRows" class="box-label-rows" aria-live="polite"></div>
                            </div>
                            <aside id="boxLabelItemPane" class="box-label-item-pane" hidden aria-hidden="true">
                                <div class="box-label-item-pane__header">
                                    <div>
                                        <div class="box-label-item-pane__title" id="boxLabelItemPaneTitle"><?= pm_escape($queueItemPaneTitle) ?></div>
                                        <div class="box-label-item-pane__note" id="boxLabelItemPaneNote"><?= pm_escape($queueItemPaneNote) ?></div>
                                    </div>
                                </div>
                                <div id="boxLabelTargetBar" class="box-label-target-bar" aria-label="Target box selector">
                                </div>
                                <div id="boxLabelItemList" class="box-label-item-list" aria-live="polite"></div>
                            </aside>
                        </div>
                        <!-- <div id="packingSummaryPanel" class="packing-summary-panel" hidden aria-live="polite"></div>
                        <div class="box-label-print-tip">
                            Print tip: choose <strong>Actual size / 100%</strong>, disable headers/footers.
                        </div> -->
                    </div>
                </div>
            <?php else: ?>
                <div class="packing-manager-workspace__empty">
                    Select a <?= pm_escape($queueSearchLabel) ?> from the queue to continue.
                </div>
            <?php endif; ?>
        </aside>
    </div>
</section>

<?php if ($selectedOrder): ?>
    <script id="packingWorkspaceData" type="application/json">
            <?= json_encode($workspacePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
        </script>
<?php endif; ?>

<script>
    (function () {
        const workspaceNode = document.getElementById('packingWorkspaceData');
        const workspaceData = workspaceNode ? JSON.parse(workspaceNode.textContent || '{}') : {};
        const selectedOrder = workspaceData.order || null;
        const selectedManifest = workspaceData.manifest || null;
        const selectedOrderStatus = String(selectedOrder?.status || '').trim().toUpperCase();
        const queueMode = String(workspaceData.queue_mode || 'pack').toLowerCase() === 'ship' ? 'ship' : 'pack';
        const queueItemPaneTitle = queueMode === 'ship' ? 'Items to Ship' : 'Items to Pack';
        const queueItemPaneNote = queueMode === 'ship'
            ? 'Review the verified items assigned to the active box.'
            : 'Choose which verified items belong in the active box.';
        const queueFinalizeLabel = queueMode === 'ship' ? 'Mark Shipped' : 'Finalize Packing';
        const allowItemRemoval = queueMode !== 'ship';
        const shipActionAllowed = queueMode === 'ship' && selectedOrderStatus === 'PACKED' && !!selectedManifest;
        const pmLog = (...args) => console.debug('[packing-manager]', ...args);

        const packingNotesList = document.getElementById('packingOrderNotesList');
        const packingAddNoteBtn = document.getElementById('packingAddNoteBtn');
        const pmEscape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
        const renderPackingNotes = (notes) => {
            if (!packingNotesList) return;
            const rows = Array.isArray(notes) ? notes : [];
            packingNotesList.innerHTML = rows.length ? rows.map((note) => {
                const acknowledged = !!note.acknowledged_at;
                const author = Number(note.created_by_user_id || 0) > 0 ? `User #${Number(note.created_by_user_id)}` : 'User';
                return `<article class="packing-order-note${acknowledged ? ' is-acknowledged' : ''}">
                    <div class="packing-order-note__body">${pmEscape(note.note)}</div>
                    <div class="packing-order-note__meta">${pmEscape(author)} · ${pmEscape(note.created_at)} · ${pmEscape(note.workflow_stage || 'pick')}</div>
                    ${acknowledged ? '<span class="packing-order-note__ack">Acknowledged</span>' : `<button type="button" class="btn btn--ghost packing-note-ack" data-note-id="${Number(note.id || 0)}">Acknowledge</button>`}
                </article>`;
            }).join('') : '<div class="muted">No notes yet.</div>';
            packingNotesList.querySelectorAll('.packing-note-ack').forEach((button) => {
                button.addEventListener('click', async () => {
                    button.disabled = true;
                    try {
                        const res = await fetch('../inventory/verify_pick.php?action=acknowledge_order_note', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ order_id: selectedOrder.id, note_id: Number(button.dataset.noteId || 0) }) });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || !data?.ok) throw new Error(data?.error || 'Unable to acknowledge note.');
                        renderPackingNotes(data.notes);
                    } catch (err) { alert(err?.message || 'Unable to acknowledge note.'); button.disabled = false; }
                });
            });
        };
        renderPackingNotes(workspaceData.notes || []);
        if (packingAddNoteBtn && selectedOrder) {
            packingAddNoteBtn.addEventListener('click', async () => {
                const note = window.prompt('Add a note for this order:');
                if (!note || !note.trim()) return;
                packingAddNoteBtn.disabled = true;
                try {
                    const res = await fetch('../inventory/verify_pick.php?action=add_order_note', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ order_id: selectedOrder.id, note: note.trim(), workflow_stage: 'pack' }) });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data?.ok) throw new Error(data?.error || 'Unable to save note.');
                    renderPackingNotes(data.notes);
                } catch (err) { alert(err?.message || 'Unable to save note.'); }
                finally { packingAddNoteBtn.disabled = false; }
            });
        }

        let packItems = Array.isArray(workspaceData.items) ? workspaceData.items.map((item) => ({
            key: String(item.key || ''),
            pick_item_id: Number(item.pick_item_id || 0),
            sku: String(item.sku_num || ''),
            description: String(item.description || ''),
            qty: Number(item.qty_verified || 0),
            source_qty: Number(item.qty_ordered || item.qty_verified || 0),
        })) : [];
        const availableBoxTypes = Array.isArray(workspaceData.available_box_types)
            ? workspaceData.available_box_types.map((entry) => ({
                id: Number(entry.id || 0),
                supply_id: Number(entry.supply_id || 0),
                dimensions: String(entry.dimensions || ''),
                display_dimensions: formatRoundedBoxDimensions(entry.dimensions || entry.inside_dimensions || ''),
                weight_label: String(entry.weight_label || ''),
                cycle_used_count: Number(entry.cycle_used_count || 0),
                lifetime_used_count: Number(entry.lifetime_used_count || 0),
                reorder_count: Number(entry.reorder_count || 0),
                last_used_at: String(entry.last_used_at || ''),
                supply_name: String(entry.supply_name || ''),
                supply_category: String(entry.supply_category || ''),
                inside_dimensions: String(entry.inside_dimensions || ''),
            }))
            .sort((left, right) => compareBoxDimensionStrings(
                left.display_dimensions || left.dimensions || '',
                right.display_dimensions || right.dimensions || ''
            ))
            : [];

        const boxLabelSO = document.getElementById('boxLabelSO');
        const boxLabelCount = document.getElementById('boxLabelCount');
        const boxLabelCountDecrease = document.getElementById('boxLabelCountDecrease');
        const boxLabelCountIncrease = document.getElementById('boxLabelCountIncrease');
        const boxLabelSequenceNote = document.getElementById('boxLabelSequenceNote');
        const boxLabelRows = document.getElementById('boxLabelRows');
        const boxLabelItemPane = document.getElementById('boxLabelItemPane');
        const boxLabelItemPaneTitle = document.getElementById('boxLabelItemPaneTitle');
        const boxLabelItemPaneNote = document.getElementById('boxLabelItemPaneNote');
        const boxLabelTargetBar = document.getElementById('boxLabelTargetBar');
        const boxLabelItemList = document.getElementById('boxLabelItemList');
        const boxLabelFinalizeBtn = document.getElementById('boxLabelFinalizeBtn');
        const boxLabelShipBtn = document.getElementById('boxLabelShipBtn');
        const boxLabelPrintLabelsBtn = document.getElementById('boxLabelPrintLabelsBtn');
        const boxLabelPrintSummaryBtn = document.getElementById('boxLabelPrintSummaryBtn');
        const boxLabelActionBtn = boxLabelFinalizeBtn || boxLabelShipBtn;
        const packingSummaryPanel = document.getElementById('packingSummaryPanel');
        const boxLabelCancel = document.getElementById('boxLabelCancelBtn');
        const packingSummaryActions = document.getElementById('packingSummaryActions');
        const storageKey = selectedOrder
            ? `packing_manager_draft:${queueMode}:${selectedOrder.id}`
            : `packing_manager_draft:${queueMode}:unknown`;

        let draftBoxCounter = 0;
        let draftBoxes = [];
        let packItemAllocations = new Map();
        let activeDraftId = '';
        let packingFinalized = false;
        let packingManifestToken = selectedManifest?.manifest_token ? String(selectedManifest.manifest_token) : '';
        let packingManifestData = selectedManifest || null;

        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        const escapeSelector = (value) => {
            const text = String(value || '');
            if (window.CSS && typeof window.CSS.escape === 'function') {
                return window.CSS.escape(text);
            }
            return text.replace(/["\\]/g, '\\$&');
        };

        const makeDomId = (value) => String(value || '')
            .replace(/[^A-Za-z0-9_-]+/g, '_')
            .replace(/^_+|_+$/g, '');

        const formatPackBoxLabel = (boxIdentifier) => {
            const raw = String(boxIdentifier || '').trim();
            const match = raw.match(/^(\d+)(?:\/\d+)?$/);
            if (match) return `Box ${match[1]}`;
            const prefix = raw.match(/^(\d+)\//);
            if (prefix) return `Box ${prefix[1]}`;
            return raw ? `Box ${raw}` : 'Box';
        };

        const formatPackWeightLabel = (weightLabel) => {
            const raw = String(weightLabel || '').trim();
            if (!raw) return '';
            return /\b(?:lb|lbs)\.?\s*$/i.test(raw) ? raw : `${raw} lbs`;
        };

        const normalizeBoxDimensions = (dimensions) => {
            let value = String(dimensions || '').trim();
            if (!value) return '';
            value = value.replace(/\s+/g, ' ');
            value = value.replace(/X/g, 'x');
            value = value.replace(/\s*x\s*/gi, 'x');
            return value.toLowerCase().trim();
        };

        function parseDimensionComponent(component) {
            const text = String(component || '').trim();
            if (!text) return NaN;
            const mixedFraction = text.match(/^(\d+)\s+(\d+)\/(\d+)$/);
            if (mixedFraction) {
                const whole = Number.parseFloat(mixedFraction[1]);
                const numerator = Number.parseFloat(mixedFraction[2]);
                const denominator = Number.parseFloat(mixedFraction[3]);
                if (denominator === 0) return NaN;
                return whole + (numerator / denominator);
            }
            const simpleFraction = text.match(/^(\d+)\/(\d+)$/);
            if (simpleFraction) {
                const numerator = Number.parseFloat(simpleFraction[1]);
                const denominator = Number.parseFloat(simpleFraction[2]);
                if (denominator === 0) return NaN;
                return numerator / denominator;
            }
            const numeric = Number.parseFloat(text);
            return Number.isFinite(numeric) ? numeric : NaN;
        }

        function formatRoundedBoxDimensions(dimensions) {
            const raw = String(dimensions || '').trim();
            if (!raw) return '';
            const parts = raw.split(/\s*[xX]\s*/).filter(Boolean);
            if (parts.length === 0) return raw;
            return parts.map((part) => {
                const value = parseDimensionComponent(part);
                return Number.isFinite(value) ? String(Math.ceil(value)) : part.trim();
            }).join(' X ');
        }

        function compareBoxDimensionStrings(leftValue, rightValue) {
            const leftParts = String(leftValue || '')
                .split(/\s*[xX]\s*/g)
                .map((part) => parseDimensionComponent(part))
                .filter((value) => Number.isFinite(value));
            const rightParts = String(rightValue || '')
                .split(/\s*[xX]\s*/g)
                .map((part) => parseDimensionComponent(part))
                .filter((value) => Number.isFinite(value));

            for (let i = 0; i < Math.max(leftParts.length, rightParts.length); i += 1) {
                const leftPart = leftParts[i] ?? Number.POSITIVE_INFINITY;
                const rightPart = rightParts[i] ?? Number.POSITIVE_INFINITY;
                if (leftPart !== rightPart) {
                    return leftPart - rightPart;
                }
            }

            return String(leftValue || '').localeCompare(String(rightValue || ''), undefined, {
                numeric: true,
                sensitivity: 'base',
            });
        }

        const findBoxTypeById = (boxTypeId) => {
            const id = String(boxTypeId || '').trim();
            if (!id) return null;
            return availableBoxTypes.find((entry) => String(entry.id || '') === id) || null;
        };

        const findBoxTypeByDimensions = (dimensions) => {
            const target = normalizeBoxDimensions(String(dimensions || ''));
            if (!target) return null;
            return availableBoxTypes.find((entry) => normalizeBoxDimensions(entry.dimensions || entry.inside_dimensions || '') === target) || null;
        };

        pmLog('boot', {
            selectedOrderId: selectedOrder?.id || 0,
            selectedOrderNumber: selectedOrder?.order_number || '',
            queueMode,
            hasManifest: !!selectedManifest,
            itemCount: Array.isArray(workspaceData.items) ? workspaceData.items.length : 0,
        });

        const normalizeCount = () => {
            const raw = boxLabelCount ? Number.parseInt(boxLabelCount.value, 10) : 1;
            if (!Number.isFinite(raw) || raw < 1) return 1;
            return Math.min(raw, 50);
        };

        const buildGeneratedBoxIdentifiers = (count) => {
            const safeCount = Number.isFinite(count) && count > 0 ? Math.min(count, 50) : 1;
            return Array.from({ length: safeCount }, (_, index) => `${index + 1}/${safeCount}`);
        };

        const newDraftBox = (seed = {}) => ({
            draftId: `draft-${++draftBoxCounter}`,
            box_identifier: String(seed.box_identifier || '').trim(),
            box_type_id: String(seed.box_type_id || '').trim(),
            dimensions: String(seed.dimensions || '').trim(),
            weight_label: String(seed.weight_label || '').trim(),
        });

        const reseedDraftBoxCounter = (boxes = draftBoxes) => {
            let maxCounter = draftBoxCounter;
            (Array.isArray(boxes) ? boxes : []).forEach((box) => {
                const draftId = String(box?.draftId || '');
                const match = draftId.match(/^draft-(\d+)$/i);
                if (!match) return;
                const parsed = Number.parseInt(match[1], 10) || 0;
                if (parsed > maxCounter) {
                    maxCounter = parsed;
                }
            });
            draftBoxCounter = maxCounter;
        };

        const getAllocationMap = (itemKey) => {
            const key = String(itemKey || '').trim();
            if (!key) return new Map();
            const current = packItemAllocations.get(key);
            if (current instanceof Map) return current;
            const next = new Map();
            if (current && typeof current === 'object') {
                Object.entries(current).forEach(([draftId, qty]) => {
                    const parsed = Number.parseInt(String(qty ?? '0'), 10) || 0;
                    if (draftId && parsed > 0) {
                        next.set(String(draftId), parsed);
                    }
                });
            }
            packItemAllocations.set(key, next);
            return next;
        };

        const getAllocatedQtyForDraft = (itemKey, draftId) => {
            const map = getAllocationMap(itemKey);
            return Number.parseInt(String(map.get(String(draftId || '')) ?? 0), 10) || 0;
        };

        const getTotalAllocatedQty = (itemKey) => {
            let total = 0;
            const map = getAllocationMap(itemKey);
            map.forEach((qty) => {
                total += Number.parseInt(String(qty ?? 0), 10) || 0;
            });
            return total;
        };

        const getRemainingPackQty = (itemKey) => {
            const item = packItems.find((entry) => String(entry.key || '') === String(itemKey || ''));
            if (!item) return 0;
            return Math.max(0, Number(item.qty || 0) - getTotalAllocatedQty(itemKey));
        };

        const saveDraftState = () => {
            if (!selectedOrder) return;
            try {
                window.sessionStorage.setItem(storageKey, JSON.stringify({
                    draftBoxes,
                    activeDraftId,
                    packItemAllocations: Array.from(packItemAllocations.entries()).map(([itemKey, map]) => [
                        itemKey,
                        Array.from((map instanceof Map ? map : new Map()).entries()),
                    ]),
                    packingFinalized,
                    packingManifestToken,
                    packingManifestData,
                    so: boxLabelSO?.value || '',
                    count: boxLabelCount?.value || '1',
                }));
            } catch (err) {
                // Session storage is optional.
            }
        };

        const loadDraftState = () => {
            if (!selectedOrder) return false;
            try {
                const raw = window.sessionStorage.getItem(storageKey);
                if (!raw) return false;
                const parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object') return false;
                draftBoxes = Array.isArray(parsed.draftBoxes) ? parsed.draftBoxes.map((box) => ({
                    draftId: String(box.draftId || `draft-${++draftBoxCounter}`),
                    box_identifier: String(box.box_identifier || '').trim(),
                    box_type_id: String(box.box_type_id || findBoxTypeByDimensions(box.dimensions || '')?.id || '').trim(),
                    dimensions: String(box.dimensions || '').trim(),
                    weight_label: String(box.weight_label || '').trim(),
                })) : [];
                reseedDraftBoxCounter(draftBoxes);
                const allocations = new Map();
                if (Array.isArray(parsed.packItemAllocations)) {
                    parsed.packItemAllocations.forEach(([itemKey, entries]) => {
                        const map = new Map();
                        if (Array.isArray(entries)) {
                            entries.forEach(([draftId, qty]) => {
                                const parsedQty = Number.parseInt(String(qty ?? '0'), 10) || 0;
                                if (draftId && parsedQty > 0) {
                                    map.set(String(draftId), parsedQty);
                                }
                            });
                        }
                        if (map.size > 0) {
                            allocations.set(String(itemKey), map);
                        }
                    });
                }
                packItemAllocations = allocations;
                activeDraftId = String(parsed.activeDraftId || '');
                packingFinalized = !!parsed.packingFinalized;
                packingManifestToken = String(parsed.packingManifestToken || packingManifestToken || '');
                packingManifestData = parsed.packingManifestData || packingManifestData;
                if (boxLabelSO && typeof parsed.so === 'string' && parsed.so) {
                    boxLabelSO.value = parsed.so;
                }
                if (boxLabelCount && typeof parsed.count === 'string' && parsed.count) {
                    boxLabelCount.value = parsed.count;
                }
                pmLog('draft restored', {
                    boxCount: draftBoxes.length,
                    activeDraftId,
                    packingFinalized,
                    packingManifestToken: packingManifestToken || '',
                });
                return draftBoxes.length > 0;
            } catch (err) {
                pmLog('draft restore failed', err);
                return false;
            }
        };

        const clearDraftState = () => {
            pmLog('clear draft state');
            draftBoxes = [];
            packItemAllocations = new Map();
            activeDraftId = '';
            draftBoxCounter = 0;
            packingFinalized = false;
            packingManifestToken = '';
            packingManifestData = null;
            try {
                window.sessionStorage.removeItem(storageKey);
            } catch (err) {
                // Ignore storage errors.
            }
            if (boxLabelSO && selectedOrder) {
                boxLabelSO.value = String(selectedOrder.order_number || '');
            }
            if (boxLabelCount) {
                boxLabelCount.value = '1';
            }
        };

        const renderBoxLabelSequenceNote = () => {
            if (!boxLabelSequenceNote) return;
            const count = normalizeCount();
            const labels = buildGeneratedBoxIdentifiers(count);
            const preview = labels.length <= 3
                ? labels.map((label) => formatPackBoxLabel(label)).join(', ')
                : `${formatPackBoxLabel(labels[0])}, ${formatPackBoxLabel(labels[1])}, ${formatPackBoxLabel(labels[2])} ... ${formatPackBoxLabel(labels[labels.length - 1])}`;
            boxLabelSequenceNote.innerHTML = `Labels will print as <strong>${preview}</strong>. Use the <strong>${queueItemPaneTitle}</strong> pane to assign verified items to boxes.`;
        };

        const syncDraftBoxCount = () => {
            const desiredCount = normalizeCount();
            if (String(desiredCount) !== String(boxLabelCount.value || '')) {
                boxLabelCount.value = String(desiredCount);
            }

            while (draftBoxes.length < desiredCount) {
                const nextIndex = draftBoxes.length + 1;
                const defaultBoxType = availableBoxTypes[0] || null;
                draftBoxes.push(newDraftBox({
                    box_identifier: buildGeneratedBoxIdentifiers(desiredCount)[nextIndex - 1] || `${nextIndex}/${desiredCount}`,
                    box_type_id: defaultBoxType ? String(defaultBoxType.id || '') : '',
                    dimensions: defaultBoxType ? String(defaultBoxType.dimensions || '') : '',
                    weight_label: '',
                }));
            }

            while (draftBoxes.length > desiredCount) {
                const removed = draftBoxes.pop();
                if (removed && removed.draftId) {
                    for (const [itemKey, map] of packItemAllocations.entries()) {
                        if (map instanceof Map) {
                            map.delete(removed.draftId);
                            if (map.size === 0) {
                                packItemAllocations.delete(itemKey);
                            }
                        }
                    }
                }
            }

            const generatedLabels = buildGeneratedBoxIdentifiers(desiredCount);
            draftBoxes.forEach((box, index) => {
                if (!box) return;
                const current = String(box.box_identifier || '').trim();
                const isAutoNumbered = current === '' || /^\d+\/\d+$/.test(current);
                if (isAutoNumbered) {
                    box.box_identifier = generatedLabels[index] || `${index + 1}/${desiredCount}`;
                }
            });

            if (!activeDraftId && draftBoxes[0]) {
                activeDraftId = draftBoxes[0].draftId;
            }
            if (activeDraftId && !draftBoxes.some((box) => box.draftId === activeDraftId)) {
                activeDraftId = draftBoxes[0]?.draftId || '';
            }

            pmLog('sync box count', {
                desiredCount,
                draftBoxCount: draftBoxes.length,
                activeDraftId,
            });
        };

        const setPackingLockedState = (locked) => {
            packingFinalized = !!locked;
            const readOnlyQueue = queueMode === 'ship';
            if (boxLabelSO) boxLabelSO.disabled = locked || readOnlyQueue;
            if (boxLabelCount) boxLabelCount.disabled = locked || readOnlyQueue;
            if (boxLabelCountDecrease) boxLabelCountDecrease.disabled = locked || readOnlyQueue;
            if (boxLabelCountIncrease) boxLabelCountIncrease.disabled = locked || readOnlyQueue;
            if (boxLabelCancel) boxLabelCancel.disabled = locked || readOnlyQueue;
            document.querySelectorAll('[data-packing-input="1"]').forEach((input) => {
                input.disabled = locked || readOnlyQueue;
            });
            if (boxLabelFinalizeBtn) {
                boxLabelFinalizeBtn.disabled = locked && !readOnlyQueue;
                boxLabelFinalizeBtn.textContent = queueFinalizeLabel;
            }
            if (boxLabelShipBtn) {
                boxLabelShipBtn.disabled = !shipActionAllowed;
            }
            if (boxLabelItemPane) {
                boxLabelItemPane.hidden = !activeDraftId || locked;
                boxLabelItemPane.setAttribute('aria-hidden', locked || !activeDraftId ? 'true' : 'false');
            }
        };

        const getActiveDraft = () => draftBoxes.find((box) => box.draftId === activeDraftId) || null;

        const scrollActiveDraftIntoView = () => {
            if (!activeDraftId) return;
            const row = boxLabelRows?.querySelector(`.box-label-row[data-draft-id="${escapeSelector(activeDraftId)}"]`);
            if (row && typeof row.scrollIntoView === 'function') {
                row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        };

        const getAssignedItemsForDraft = (draftId) => {
            const box = draftBoxes.find((entry) => entry.draftId === draftId);
            if (!box) return [];
            return packItems.flatMap((item) => {
                const qty = getAllocatedQtyForDraft(item.key, draftId);
                if (qty <= 0) return [];
                return [{
                    key: item.key,
                    pick_item_id: item.pick_item_id,
                    sku: item.sku,
                    description: item.description,
                    qty,
                    source_qty: item.source_qty,
                }];
            });
        };

        const renderAssignedItemsMarkupForDraft = (draftId) => {
            const assignedItems = getAssignedItemsForDraft(draftId);
            if (!assignedItems.length) {
                return '<span class="muted">No items assigned yet.</span>';
            }
            if (!allowItemRemoval) {
                return assignedItems.map((item) => `
                    <span class="box-label-chip" aria-label="Assigned item" data-item-key="${escapeHtml(item.key)}" data-item-qty="${escapeHtml(String(item.qty || 0))}" data-draft-id="${escapeHtml(draftId)}">
                        ${escapeHtml(item.sku || item.description || 'Item')} × ${Number(item.qty || 0)}
                    </span>
                `).join('');
            }
            return assignedItems.map((item) => `
                <button type="button" class="box-label-chip" data-remove-item="1" data-item-key="${escapeHtml(item.key)}" data-item-qty="${escapeHtml(String(item.qty || 0))}" data-draft-id="${escapeHtml(draftId)}" title="Remove from this box">
                    ${escapeHtml(item.sku || item.description || 'Item')} × ${Number(item.qty || 0)}
                </button>
            `).join('');
        };

        const renderBoxRows = () => {
            if (!boxLabelRows) return;
            syncDraftBoxCount();
            pmLog('render box rows', {
                draftBoxCount: draftBoxes.length,
                activeDraftId,
                packingFinalized,
            });

            if (draftBoxes.length === 0) {
                boxLabelRows.innerHTML = '<div class="muted">Add at least one box to start packing.</div>';
                return;
            }

            boxLabelRows.innerHTML = '';
            draftBoxes.forEach((box, index) => {
                const row = document.createElement('div');
                row.className = `box-label-row${activeDraftId === box.draftId ? ' is-active' : ''}`;
                row.dataset.draftId = box.draftId;
                const rowIdBase = `box_label_${makeDomId(box.draftId) || index + 1}`;
                const boxIdId = `${rowIdBase}_box_identifier`;
                const dimensionsId = `${rowIdBase}_dimensions`;
                const weightId = `${rowIdBase}_weight`;
                const inferredBoxType = findBoxTypeById(box.box_type_id) || findBoxTypeByDimensions(box.dimensions || '');
                const selectedBoxTypeId = String(box.box_type_id || inferredBoxType?.id || '');
                const selectedBoxType = findBoxTypeById(selectedBoxTypeId) || inferredBoxType || null;
                const boxTypeOptions = availableBoxTypes.map((entry) => {
                    const id = String(entry.id || '');
                    const dims = String(entry.dimensions || '');
                    const displayDims = String(entry.display_dimensions || dims || '');
                    const parts = [displayDims];
                    if (entry.supply_name) {
                        parts.push(entry.supply_name);
                    }
                    return `
                        <option value="${escapeHtml(id)}" ${id === selectedBoxTypeId ? 'selected' : ''}>
                            ${escapeHtml(parts.filter(Boolean).join(' · '))}
                        </option>
                    `;
                }).join('');
                const assignedItems = getAssignedItemsForDraft(box.draftId);
                const itemsMarkup = assignedItems.length > 0
                    ? assignedItems.map((item) => `
                    <button type="button" class="box-label-chip" data-remove-item="1" data-item-key="${escapeHtml(item.key)}" data-item-qty="${escapeHtml(String(item.qty || 0))}" data-draft-id="${escapeHtml(box.draftId)}" title="Remove from this box">
                        ${escapeHtml(item.sku || item.description || 'Item')} × ${Number(item.qty || 0)}
                    </button>
                `).join('')
                    : '<span class="muted">No items assigned yet.</span>';

                row.innerHTML = `
                <div class="box-label-row__head">
                    <button type="button" class="box-label-row__select btn btn--ghost" data-select-box="1" data-draft-id="${escapeHtml(box.draftId)}">
                        ${escapeHtml(formatPackBoxLabel(box.box_identifier || `${index + 1}/${draftBoxes.length}`))}
                    </button>
                    <span class="box-label-row__index">Box ${index + 1}</span>
                </div>
                <div class="box-label-row__grid">
                    <div>
                        <label class="box-label-row__label" for="${escapeHtml(boxIdId)}">Box ID</label>
                        <input class="input" id="${escapeHtml(boxIdId)}" name="${escapeHtml(boxIdId)}" data-packing-input="1" data-field="box_identifier" data-draft-id="${escapeHtml(box.draftId)}" value="${escapeHtml(box.box_identifier || `${index + 1}/${draftBoxes.length}`)}" />
                    </div>
                    <div>
                        <label class="box-label-row__label" for="${escapeHtml(dimensionsId)}">Box Type</label>
                        <select class="input" id="${escapeHtml(dimensionsId)}" name="${escapeHtml(dimensionsId)}" data-packing-input="1" data-field="box_type_id" data-draft-id="${escapeHtml(box.draftId)}">
                            <option value="">Select box size</option>
                            ${boxTypeOptions}
                        </select>
                        <input type="hidden" data-packing-input="1" data-field="dimensions" data-draft-id="${escapeHtml(box.draftId)}" value="${escapeHtml(selectedBoxType?.dimensions || box.dimensions || '')}" />
                    </div>
                    <div>
                        <label class="box-label-row__label" for="${escapeHtml(weightId)}">Weight</label>
                        <input class="input" id="${escapeHtml(weightId)}" name="${escapeHtml(weightId)}" data-packing-input="1" data-field="weight_label" data-draft-id="${escapeHtml(box.draftId)}" placeholder="enter weight..." value="${escapeHtml(box.weight_label || '')}" />
                    </div>
                </div>
                <div class="box-label-row__items">${renderAssignedItemsMarkupForDraft(box.draftId)}</div>
            `;
                row.addEventListener('click', (ev) => {
                    const target = ev.target;
                    if (allowItemRemoval && target && target.getAttribute('data-remove-item') === '1') {
                        const itemKey = String(target.getAttribute('data-item-key') || '');
                        const draftId = String(target.getAttribute('data-draft-id') || '');
                        if (!itemKey || !draftId) return;
                        const map = getAllocationMap(itemKey);
                        map.delete(draftId);
                        if (map.size === 0) {
                            packItemAllocations.delete(itemKey);
                        }
                        saveDraftState();
                        renderBoxRows();
                        renderPackItemPane();
                        renderPackingSummary();
                        return;
                    }
                    const selectBtn = target && target.getAttribute('data-select-box') === '1'
                        ? target
                        : (target?.closest?.('[data-select-box="1"]') || null);
                    if (selectBtn) {
                        activeDraftId = String(selectBtn.getAttribute('data-draft-id') || '');
                        pmLog('select box', { activeDraftId });
                        saveDraftState();
                        renderBoxRows();
                        renderPackItemPane();
                        return;
                    }
                });
                boxLabelRows.appendChild(row);
            });

            boxLabelRows.querySelectorAll('[data-packing-input="1"]').forEach((input) => {
                const eventName = input.tagName === 'SELECT' ? 'change' : 'input';
                input.addEventListener(eventName, () => {
                    const draftId = String(input.getAttribute('data-draft-id') || '');
                    const field = String(input.getAttribute('data-field') || '');
                    const box = draftBoxes.find((entry) => entry.draftId === draftId);
                    if (!box || !field) return;
                    box[field] = input.value;
                    pmLog('box field updated', { draftId, field, value: input.value });
                    if (field === 'box_type_id') {
                        const selectedType = findBoxTypeById(input.value);
                        const dimensionsInput = boxLabelRows.querySelector(`[data-field="dimensions"][data-draft-id="${escapeSelector(draftId)}"]`);
                        if (selectedType) {
                            box.box_type_id = String(selectedType.id || '');
                            box.dimensions = String(selectedType.dimensions || '').trim();
                            if (dimensionsInput) {
                                dimensionsInput.value = box.dimensions;
                            }
                        } else {
                            box.box_type_id = '';
                            box.dimensions = '';
                            if (dimensionsInput) {
                                dimensionsInput.value = '';
                            }
                        }
                    }
                    saveDraftState();
                    if (field === 'box_identifier') {
                        const selectBtn = boxLabelRows.querySelector(`[data-select-box="1"][data-draft-id="${escapeSelector(draftId)}"]`);
                        if (selectBtn) {
                            selectBtn.textContent = formatPackBoxLabel(box.box_identifier || draftBoxes.indexOf(box) + 1);
                        }
                        if (activeDraftId === draftId && boxLabelItemPaneTitle) {
                            boxLabelItemPaneTitle.textContent = `${queueItemPaneTitle} - ${formatPackBoxLabel(box.box_identifier || draftId)}`;
                        }
                    }
                });
            });
        };

        const renderPackItemPane = () => {
            if (!boxLabelItemPane || !boxLabelItemList) return;
            const activeBox = getActiveDraft();
            const locked = packingFinalized;
            pmLog('render item pane', {
                activeDraftId,
                locked,
                activeBoxIdentifier: activeBox?.box_identifier || '',
                remainingItems: packItems.filter((item) => getRemainingPackQty(item.key) > 0).length,
            });
            if (!activeBox || locked) {
                boxLabelItemPane.hidden = true;
                boxLabelItemPane.setAttribute('aria-hidden', 'true');
                return;
            }

            boxLabelItemPane.hidden = false;
            boxLabelItemPane.setAttribute('aria-hidden', 'false');
            boxLabelItemPaneTitle.textContent = `${queueItemPaneTitle} - ${formatPackBoxLabel(activeBox.box_identifier || activeBox.draftId)}`;
            boxLabelItemPaneNote.textContent = queueItemPaneNote;
            if (boxLabelTargetBar) {
                boxLabelTargetBar.innerHTML = draftBoxes.map((box, index) => `
                <button
                    type="button"
                    class="box-label-target-bar__btn${box.draftId === activeDraftId ? ' is-active' : ''}"
                    data-target-box="1"
                    data-draft-id="${escapeHtml(box.draftId)}"
                >
                    Box ${index + 1}
                </button>
            `).join('');
                boxLabelTargetBar.querySelectorAll('[data-target-box="1"]').forEach((button) => {
                    button.addEventListener('click', () => {
                        activeDraftId = String(button.getAttribute('data-draft-id') || '');
                        pmLog('target box selected', { activeDraftId });
                        saveDraftState();
                        renderBoxRows();
                        renderPackItemPane();
                        scrollActiveDraftIntoView();
                    });
                });
            }

            const rows = [];
            packItems.forEach((item) => {
                const remaining = getRemainingPackQty(item.key);
                if (remaining <= 0) return;
                const targetDraftId = activeDraftId || draftBoxes[0]?.draftId || '';
                rows.push(`
                <div class="packing-item-row">
                    <div class="packing-item-row__title">${escapeHtml(item.sku || '')}</div>
                    <div class="packing-item-row__desc">${escapeHtml(item.description || '')}</div>
                    <div class="packing-item-row__meta">Verified: ${Number(item.qty || 0)} · Remaining: ${remaining}</div>
                    <div class="packing-item-row__controls">
                        <label class="sr-only" for="${escapeHtml(`pack_qty_${makeDomId(item.key) || makeDomId(item.sku) || 'item'}`)}">Quantity for ${escapeHtml(item.sku || item.description || 'item')}</label>
                        <input class="input" id="${escapeHtml(`pack_qty_${makeDomId(item.key) || makeDomId(item.sku) || 'item'}`)}" name="${escapeHtml(`pack_qty_${makeDomId(item.key) || makeDomId(item.sku) || 'item'}`)}" type="number" min="1" max="${remaining}" value="${remaining}" data-item-qty="${escapeHtml(item.key)}" aria-label="Quantity for ${escapeHtml(item.sku || item.description || 'item')}" />
                        <button type="button" class="btn btn--ghost" data-assign-item="${escapeHtml(item.key)}" data-target-draft-id="${escapeHtml(targetDraftId)}">Add to box</button>
                    </div>
                </div>
            `);
            });

            boxLabelItemList.innerHTML = rows.length > 0
                ? rows.join('')
                : '<div class="muted">No verified items remain to assign.</div>';

            boxLabelItemList.querySelectorAll('[data-assign-item]').forEach((button) => {
                button.addEventListener('click', () => {
                    const itemKey = String(button.getAttribute('data-assign-item') || '');
                    const buttonTargetDraftId = String(button.getAttribute('data-target-draft-id') || '');
                    const item = packItems.find((entry) => String(entry.key || '') === itemKey);
                    if (!item) return;
                    const input = boxLabelItemList.querySelector(`input[data-item-qty="${escapeSelector(itemKey)}"]`);
                    let targetBox = draftBoxes.find((box) => box.draftId === buttonTargetDraftId) || getActiveDraft();
                    if (!targetBox && draftBoxes[0]) {
                        targetBox = draftBoxes[0];
                        activeDraftId = targetBox.draftId;
                        pmLog('assign fallback selected first box', { activeDraftId });
                        saveDraftState();
                        renderBoxRows();
                        renderPackItemPane();
                    }
                    if (!targetBox) {
                        pmLog('assign blocked: no active box', { itemKey });
                        alert('Select a box first.');
                        return;
                    }
                    pmLog('assign target resolved', {
                        itemKey,
                        buttonTargetDraftId,
                        activeDraftId,
                        resolvedDraftId: targetBox.draftId,
                    });
                    const remaining = getRemainingPackQty(itemKey);
                    const inputValue = String(input?.value || remaining || 0);
                    let qty = Number.parseInt(inputValue, 10) || 0;
                    if (qty < 1) qty = 1;
                    if (qty > remaining) qty = remaining;
                    const map = getAllocationMap(itemKey);
                    const beforeEntries = Array.from(map.entries());
                    const current = Number.parseInt(String(map.get(targetBox.draftId) || 0), 10) || 0;
                    map.set(targetBox.draftId, current + qty);
                    pmLog('assign item', {
                        draftId: targetBox.draftId,
                        itemKey,
                        inputValue,
                        qty,
                        remainingBefore: remaining,
                        mapBefore: beforeEntries,
                        mapAfter: Array.from(map.entries()),
                        packItemAllocationsSize: packItemAllocations.size,
                    });
                    saveDraftState();
                    const targetRow = boxLabelRows?.querySelector(`.box-label-row[data-draft-id="${escapeSelector(targetBox.draftId)}"]`);
                    const targetItems = targetRow?.querySelector('.box-label-row__items');
                    if (targetItems) {
                        targetItems.innerHTML = renderAssignedItemsMarkupForDraft(targetBox.draftId);
                    }
                    pmLog('assign row updated', {
                        targetDraftId: targetBox.draftId,
                        hasTargetRow: !!targetRow,
                        hasTargetItems: !!targetItems,
                        itemMarkup: renderAssignedItemsMarkupForDraft(targetBox.draftId),
                        childCount: targetItems?.childElementCount || 0,
                    });
                    renderBoxRows();
                    renderPackItemPane();
                    renderPackingSummary();
                    scrollActiveDraftIntoView();
                });
            });
        };

        const buildBoxesForFinalize = () => {
            const boxRows = Array.from(boxLabelRows?.querySelectorAll('.box-label-row[data-draft-id]') || []);
            return draftBoxes.map((box) => {
                const row = boxRows.find((entry) => String(entry.getAttribute('data-draft-id') || '') === String(box.draftId || ''));
                const chips = row ? Array.from(row.querySelectorAll('[data-item-key][data-item-qty]')) : [];
                const items = chips.flatMap((chip) => {
                    const itemKey = String(chip.getAttribute('data-item-key') || '').trim();
                    const qty = Number.parseInt(String(chip.getAttribute('data-item-qty') || '0'), 10) || 0;
                    if (!itemKey || qty <= 0) return [];
                    const item = packItems.find((entry) => String(entry.key || '') === itemKey);
                    if (!item) return [];
                    return [{
                        key: item.key,
                        pick_item_id: item.pick_item_id,
                        sku: item.sku,
                        description: item.description,
                        qty,
                        source_qty: item.source_qty,
                    }];
                });
                return {
                    box_identifier: String(box.box_identifier || '').trim(),
                    dimensions: String(box.dimensions || '').trim(),
                    weight_label: String(box.weight_label || '').trim(),
                    items,
                };
            });
        };

        const getPackingConsoleSummary = () => {
            const boxEntries = buildBoxesForFinalize();
            const boxSummaries = [];
            const allocatedByItem = new Map();
            let totalVerifiedQty = 0;
            let totalAllocatedQty = 0;

            boxEntries.forEach((box, index) => {
                const items = Array.isArray(box.items) ? box.items : [];
                let boxTotal = 0;
                items.forEach((item) => {
                    const key = String(item.key || '').trim();
                    const qty = Number.parseInt(String(item.qty || 0), 10) || 0;
                    if (!key || qty <= 0) return;
                    boxTotal += qty;
                    totalAllocatedQty += qty;
                    allocatedByItem.set(key, (allocatedByItem.get(key) || 0) + qty);
                });
                boxSummaries.push({
                    box: box.box_identifier || `Box ${index + 1}`,
                    dimensions: box.dimensions || '',
                    weight: box.weight_label || '',
                    item_lines: items.length,
                    qty_total: boxTotal,
                });
            });

            const itemSummaries = packItems.map((item) => {
                const verifiedQty = Number(item.qty || 0);
                const allocatedQty = Number(allocatedByItem.get(item.key) || 0);
                const remainingQty = Math.max(0, verifiedQty - allocatedQty);
                totalVerifiedQty += verifiedQty;
                return {
                    key: item.key,
                    sku: item.sku,
                    verified_qty: verifiedQty,
                    allocated_qty: allocatedQty,
                    remaining_qty: remainingQty,
                };
            });

            return {
                order_id: Number(selectedOrder?.id || 0),
                sales_order_number: String(boxLabelSO?.value || ''),
                box_count: boxEntries.length,
                total_verified_qty: totalVerifiedQty,
                total_allocated_qty: totalAllocatedQty,
                remaining_qty: Math.max(0, totalVerifiedQty - totalAllocatedQty),
                boxes: boxSummaries,
                items: itemSummaries,
            };
        };

        window.packingManagerSummary = () => {
            const summary = getPackingConsoleSummary();
            if (console && typeof console.table === 'function') {
                console.table(summary.items);
                console.table(summary.boxes);
            } else if (console && typeof console.log === 'function') {
                console.log(summary);
            }
            return summary;
        };
        window.packingManagerDump = window.packingManagerSummary;

        const renderPackingSummary = (manifest = packingManifestData) => {
            if (!packingSummaryPanel) return;
            if (!manifest) {
                pmLog('clear packing summary');
                packingSummaryPanel.hidden = true;
                packingSummaryPanel.innerHTML = '';
                if (packingSummaryActions) packingSummaryActions.hidden = true;
                return;
            }
            pmLog('render packing summary', {
                token: manifest.manifest_token || manifest.token || '',
                boxCount: manifest.summary?.box_count || 0,
                itemCount: manifest.summary?.item_count || 0,
            });
            const summary = manifest.summary || {};
            const boxes = Array.isArray(manifest.boxes) ? manifest.boxes : [];
            packingSummaryPanel.hidden = false;
            packingSummaryPanel.innerHTML = `
            <div class="packing-summary-panel__title">Finalized Packing Manifest</div>
            <div class="packing-summary-panel__meta">
                Box count: ${Number(summary.box_count || 0)} ·
                Item count: ${Number(summary.item_count || 0)} ·
                Ordered: ${Number(summary.ordered_count || 0)} ·
                Unassigned: ${Number(summary.unassigned_count || 0)}
            </div>
            <div class="packing-summary-panel__list">
                ${boxes.map((box) => `
                    <div class="packing-summary-panel__item">
                        <strong>${escapeHtml(formatPackBoxLabel(box.box_identifier || 'Box'))}</strong>
                        <span>${escapeHtml(String(box.dimensions || ''))} · ${escapeHtml(formatPackWeightLabel(String(box.weight_label || '')))}</span>
                    </div>
                `).join('')}
            </div>
        `;
            if (packingSummaryActions) packingSummaryActions.hidden = false;
        };

        const finalizePacking = async () => {
            if (packingFinalized && queueMode !== 'ship') {
                pmLog('finalize skipped: already finalized');
                return;
            }
            if (!selectedOrder) {
                alert('No order loaded.');
                return;
            }
            const soVal = boxLabelSO ? boxLabelSO.value.trim() : '';
            if (!soVal) {
                alert('Please enter a sales order before finalizing.');
                return;
            }
            const boxEntries = buildBoxesForFinalize();
            const packingSummary = getPackingConsoleSummary();
            if (!boxEntries.length) {
                alert('Add at least one box before finalizing.');
                return;
            }
            const hasMissingBoxData = boxEntries.some((entry) => !entry.box_identifier || !entry.dimensions || !entry.weight_label);
            if (hasMissingBoxData) {
                alert('Please fill in dimensions and weight for every box before finalizing.');
                return;
            }
            const unassignedQty = Number(packingSummary.remaining_qty || 0);
            if (unassignedQty > 0) {
                alert(`${unassignedQty} unit(s) are still unassigned to a box.`);
                return;
            }
            const emptyBoxes = boxEntries.filter((entry) => !Array.isArray(entry.items) || entry.items.length === 0);
            if (emptyBoxes.length > 0) {
                alert('Assign at least one verified item to each box before finalizing.');
                return;
            }

            pmLog('finalize start', {
                orderId: Number(selectedOrder.id || 0),
                salesOrderNumber: soVal,
                boxCount: boxEntries.length,
                itemCount: boxEntries.reduce((sum, box) => sum + (Array.isArray(box.items) ? box.items.length : 0), 0),
            });
            if (boxLabelActionBtn) {
                boxLabelActionBtn.disabled = true;
            }
            try {
                const finalizeRes = await fetch(`packing_manager.php?action=finalize_packing`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        order_id: Number(selectedOrder.id || 0),
                        sales_order_number: soVal,
                        boxes: boxEntries,
                    }),
                });
                const finalizeText = await finalizeRes.text();
                let finalizeData = {};
                try {
                    finalizeData = finalizeText ? JSON.parse(finalizeText) : {};
                } catch (err) {
                    console.error('Finalize returned non-JSON response:', {
                        status: finalizeRes.status,
                        body: finalizeText,
                    });
                    alert(
                        `Unable to finalize ${queueMode === 'ship' ? 'shipping' : 'packing'}. ` +
                        `Server returned ${finalizeRes.status}. Check console/network response.`
                    );
                    return;
                }
                pmLog('finalize response', {
                    ok: finalizeRes.ok,
                    status: finalizeRes.status,
                    body: finalizeData,
                });
                if (!finalizeRes.ok || !finalizeData?.ok || !finalizeData?.token) {
                    const fallbackDetail = finalizeData?.error
                        || (finalizeText ? String(finalizeText) : '')
                        || `Server returned ${finalizeRes.status}.`;
                    alert(
                        `${queueMode === 'ship' ? 'Unable to finalize shipping.' : 'Unable to finalize packing.'} ` +
                        fallbackDetail
                    );
                    return;
                }

                packingManifestToken = String(finalizeData.token || '');
                packingManifestData = {
                    token: packingManifestToken,
                    order_id: Number(selectedOrder.id || 0),
                    sales_order_number: soVal,
                    created_at: new Date().toISOString(),
                    boxes: boxEntries,
                    summary: finalizeData.summary || {},
                };
                packingFinalized = true;
                saveDraftState();
                setPackingLockedState(true);
                renderPackingSummary(packingManifestData);
                pmLog('finalize complete', {
                    token: packingManifestToken,
                    summary: packingManifestData.summary || {},
                });
                window.location.reload();
                return;
            } catch (err) {
                pmLog('finalize failed', err);
                alert(err?.message || (queueMode === 'ship' ? 'Unable to finalize shipping.' : 'Unable to finalize packing.'));
            } finally {
                if (boxLabelActionBtn) {
                    boxLabelActionBtn.disabled = false;
                }
            }
        };

        const shipOrder = async () => {
            if (!selectedOrder) {
                alert('No order loaded.');
                return;
            }
            if (selectedOrderStatus !== 'PACKED') {
                alert('Only packed orders can be shipped.');
                return;
            }
            if (!selectedManifest) {
                alert('No packed manifest is available to ship.');
                return;
            }

            const soVal = boxLabelSO ? boxLabelSO.value.trim() : '';
            if (!soVal) {
                alert('Please enter a sales order before shipping.');
                return;
            }

            pmLog('ship start', {
                orderId: Number(selectedOrder.id || 0),
                salesOrderNumber: soVal,
                manifestToken: selectedManifest?.manifest_token || '',
            });
            if (boxLabelActionBtn) {
                boxLabelActionBtn.disabled = true;
            }
            try {
                const shipRes = await fetch(`packing_manager.php?action=ship_order`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        order_id: Number(selectedOrder.id || 0),
                        sales_order_number: soVal,
                    }),
                });
                const shipText = await shipRes.text();
                let shipData = {};
                try {
                    shipData = shipText ? JSON.parse(shipText) : {};
                } catch (err) {
                    console.error('Ship returned non-JSON response:', {
                        status: shipRes.status,
                        body: shipText,
                    });
                    alert(`Unable to finalize shipping. Server returned ${shipRes.status}. Check console/network response.`);
                    return;
                }
                pmLog('ship response', {
                    ok: shipRes.ok,
                    status: shipRes.status,
                    body: shipData,
                });
                if (!shipRes.ok || !shipData?.ok) {
                    const fallbackDetail = shipData?.error
                        || (shipText ? String(shipText) : '')
                        || `Server returned ${shipRes.status}.`;
                    alert(`Unable to finalize shipping. ${fallbackDetail}`);
                    return;
                }

                window.location.reload();
                return;
            } catch (err) {
                pmLog('ship failed', err);
                alert(err?.message || 'Unable to finalize shipping.');
            } finally {
                if (boxLabelActionBtn) {
                    boxLabelActionBtn.disabled = false;
                }
            }
        };

        const openPrintUrl = (actionName) => {
            if (!packingManifestToken) {
                alert('No finalized packing manifest is available.');
                return;
            }
            pmLog('open print url', {
                actionName,
                token: packingManifestToken,
            });
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('action', actionName);
            url.searchParams.set('token', packingManifestToken);
            window.open(url.toString(), '_blank', 'noopener,noreferrer');
        };

        if (boxLabelCount) {
            boxLabelCount.addEventListener('input', () => {
                const count = normalizeCount();
                pmLog('box count changed', { count });
                boxLabelCount.value = String(count);
                syncDraftBoxCount();
                renderBoxLabelSequenceNote();
                renderBoxRows();
                renderPackItemPane();
                saveDraftState();
            });
        }
        if (boxLabelCountDecrease) {
            boxLabelCountDecrease.addEventListener('click', () => {
                if (!boxLabelCount || boxLabelCount.disabled) return;
                const count = Math.max(1, normalizeCount() - 1);
                boxLabelCount.value = String(count);
                syncDraftBoxCount();
                renderBoxLabelSequenceNote();
                renderBoxRows();
                renderPackItemPane();
                saveDraftState();
            });
        }
        if (boxLabelCountIncrease) {
            boxLabelCountIncrease.addEventListener('click', () => {
                if (!boxLabelCount || boxLabelCount.disabled) return;
                const count = Math.min(50, normalizeCount() + 1);
                boxLabelCount.value = String(count);
                syncDraftBoxCount();
                renderBoxLabelSequenceNote();
                renderBoxRows();
                renderPackItemPane();
                saveDraftState();
            });
        }

        if (boxLabelCancel) {
            boxLabelCancel.addEventListener('click', () => {
                const ok = window.confirm('Reset the current packing draft?');
                if (!ok) return;
                pmLog('reset draft confirmed');
                clearDraftState();
                syncDraftBoxCount();
                renderBoxLabelSequenceNote();
                renderBoxRows();
                renderPackItemPane();
                renderPackingSummary();
                saveDraftState();
            });
        }

        if (boxLabelFinalizeBtn) {
            boxLabelFinalizeBtn.addEventListener('click', () => {
                void finalizePacking();
            });
        }
        if (boxLabelShipBtn) {
            boxLabelShipBtn.addEventListener('click', () => {
                void shipOrder();
            });
        }

        if (boxLabelPrintLabelsBtn) {
            boxLabelPrintLabelsBtn.addEventListener('click', () => openPrintUrl('box_label_print'));
        }
        if (boxLabelPrintSummaryBtn) {
            boxLabelPrintSummaryBtn.addEventListener('click', () => openPrintUrl('packing_manifest_print'));
        }

        if (selectedOrder) {
            if (selectedManifest && Array.isArray(selectedManifest.boxes)) {
                pmLog('hydrating finalized manifest', {
                    token: selectedManifest.manifest_token || '',
                    boxCount: selectedManifest.boxes.length,
                });
                draftBoxes = selectedManifest.boxes.map((box) => newDraftBox({
                    box_identifier: box.box_identifier || '',
                    box_type_id: (findBoxTypeByDimensions(box.dimensions || '')?.id || ''),
                    dimensions: box.dimensions || '',
                    weight_label: box.weight_label || '',
                }));
                selectedManifest.boxes.forEach((box, boxIndex) => {
                    const draft = draftBoxes[boxIndex];
                    if (!draft) return;
                    (Array.isArray(box.items) ? box.items : []).forEach((item) => {
                        const key = String(item.key || '');
                        const qty = Number.parseInt(String(item.qty || 0), 10) || 0;
                        if (!key || qty <= 0) return;
                        const map = getAllocationMap(key);
                        map.set(draft.draftId, qty);
                    });
                });
                activeDraftId = draftBoxes[0]?.draftId || '';
                packingFinalized = queueMode === 'ship' || selectedOrderStatus === 'PACKED';
                renderPackingSummary(selectedManifest);
            } else {
                const existingDraft = loadDraftState();
                if (!existingDraft) {
                    pmLog('creating new draft', { initialCount: normalizeCount() });
                    const initialCount = normalizeCount();
                    draftBoxes = [];
                    for (let i = 0; i < initialCount; i++) {
                        const defaultBoxType = availableBoxTypes[0] || null;
                        draftBoxes.push(newDraftBox({
                            box_identifier: buildGeneratedBoxIdentifiers(initialCount)[i] || `${i + 1}/${initialCount}`,
                            box_type_id: defaultBoxType ? String(defaultBoxType.id || '') : '',
                            dimensions: defaultBoxType ? String(defaultBoxType.dimensions || '') : '',
                            weight_label: '',
                        }));
                    }
                    activeDraftId = draftBoxes[0]?.draftId || '';
                }
                packingFinalized = false;
                renderPackingSummary(null);
            }
            if (boxLabelSO && !boxLabelSO.value) {
                boxLabelSO.value = String(selectedOrder.order_number || '');
            }
        }

        syncDraftBoxCount();
        renderBoxLabelSequenceNote();
        renderBoxRows();
        renderPackItemPane();
        setPackingLockedState(packingFinalized);
        saveDraftState();
    })();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';
