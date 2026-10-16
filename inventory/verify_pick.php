<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

ini_set('error_log', __DIR__ . '/import_pick.log');

ob_start();


require_once __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/utils/shopify_fetch.php';


$phpSupportsSpreadsheet = version_compare(PHP_VERSION, '8.3.0', '>=');
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];
$autoloadLoaded = false;
foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}
if (!$autoloadLoaded) {
    $GLOBALS['DRIVE_LAST_ERROR'] = 'Composer autoload not found. Checked: ' . implode(' | ', $autoloadCandidates);
}

if (isset($_GET['action']) && $_GET['action'] === 'pick_events_search') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    if (!pickUserHasOrderOverride()) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Only admins or managers can access this action.',
        ]);
        exit;
    }

    $orderNumber = trim((string) ($_GET['order_number'] ?? ''));
    if ($orderNumber === '') {
        echo json_encode([
            'ok' => false,
            'error' => 'Sales order number is required.',
        ]);
        exit;
    }

    $sql = "
        SELECT
            po.order_number,
            po.status AS order_status,
            pve.workflow_stage,
            pi.item_no,
            pve.event_type,
            pve.qty_delta,
            pve.expected_sku,
            pve.sku_scanned,
            pve.meta_json,
            pve.created_at,
            pve.user_id,
            COALESCE(u.username, CONCAT('User #', pve.user_id)) AS handler
        FROM pick_verify_events pve
        INNER JOIN pick_orders po ON pve.order_id = po.id
        LEFT JOIN pick_items pi ON pve.pick_item_id = pi.id
        LEFT JOIN xserqhmy_users.users u ON pve.user_id = u.id
        WHERE po.order_number = ?
        ORDER BY pve.created_at ASC, pve.id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to prepare pick events query.',
        ]);
        exit;
    }

    $stmt->bind_param('s', $orderNumber);
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to execute pick events query.',
        ]);
        exit;
    }

    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to read pick events results.',
        ]);
        exit;
    }
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'order_number' => (string) ($row['order_number'] ?? ''),
            'order_status' => (string) ($row['order_status'] ?? ''),
            'workflow_stage' => (string) ($row['workflow_stage'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'item_no' => (string) ($row['item_no'] ?? ''),
            'qty_delta' => (int) ($row['qty_delta'] ?? 0),
            'handler' => (string) ($row['handler'] ?? ''),
            'user_id' => (string) ($row['user_id'] ?? ''),
            'expected_sku' => (string) ($row['expected_sku'] ?? ''),
            'sku_scanned' => (string) ($row['sku_scanned'] ?? ''),
            'meta_json' => (string) ($row['meta_json'] ?? ''),
        ];
    }

    $stmt->close();

    echo json_encode([
        'ok' => true,
        'summary' => [
            'order_number' => $orderNumber,
            'event_count' => count($events),
            'status' => $events[0]['order_status'] ?? '',
            'key_user' => $events[0]['handler'] ?? '',
        ],
        'events' => $events,
    ]);
    exit;
}

$DRIVE_SERVICE_ACCOUNT_JSON = DRIVE_SERVICE_ACCOUNT_JSON;
$DRIVE_PICK_FOLDER_ID = DRIVE_PICK_FOLDER_ID;
$DRIVE_PICK_NAME_PREFIX = DRIVE_PICK_NAME_PREFIX;

$FIXED_COLS = [
    'sku' => 8,
    'description' => 11,
    'quantity' => 12,
    'bin' => 6,
];
$FORCE_FIXED_COLS = false;
$DISABLE_ROW_HEURISTICS = true;

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('pickBindParam')) {
    /**
     * Bind mysqli parameters by reference from a value array.
     *
     * @param array<int,mixed> $values
     */
    function pickBindParam(mysqli_stmt $stmt, string $types, array &$values): bool
    {
        $args = [$types];
        foreach ($values as $index => &$value) {
            $args[] = &$value;
        }
        unset($value);

        return call_user_func_array([$stmt, 'bind_param'], $args);
    }
}

if (!function_exists('fetchShippedOrders')) {
    function fetchShippedOrders(mysqli $conn): array
    {
        $sql = "
            SELECT
                po.id,
                po.order_number,
                po.status,
                po.source_file,
                po.created_at,
                po.updated_at,
                po.assigned_user_id,
                po.picker_verified_by_user_id,
                po.packer_verified_by_user_id
            FROM pick_orders po
            WHERE po.status = 'SHIPPED'
            ORDER BY po.updated_at DESC, po.id DESC
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('[fetchShippedOrders] prepare failed: ' . $conn->error);
            return [];
        }

        if (!$stmt->execute()) {
            error_log('[fetchShippedOrders] execute failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            return [];
        }

        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }

        $stmt->close();

        return $orders;
    }
}

if (!function_exists('pickFetchShopifyImageUrl')) {
    function pickFetchShopifyImageUrl(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return '';
        }
        if (function_exists('shopify_fetch_product_by_sku')) {
            try {
                $product = shopify_fetch_product_by_sku($sku, 0);
                return trim((string) ($product['image_src'] ?? ''));
            } catch (\Throwable $e) {
                error_log('[pickFetchShopifyImageUrl] shopify_fetch_product_by_sku failed: ' . $e->getMessage());
            }
        }
        if (
            !defined('SHOPIFY_STORE_DOMAIN')
            || !defined('SHOPIFY_API_VERSION')
            || !defined('SHOPIFY_ADMIN_ACCESS_TOKEN')
            || !function_exists('curl_init')
        ) {
            return '';
        }

        $domain = (string) SHOPIFY_STORE_DOMAIN;
        $version = (string) SHOPIFY_API_VERSION;
        $token = (string) SHOPIFY_ADMIN_ACCESS_TOKEN;
        if ($domain === '' || $version === '' || $token === '') {
            return '';
        }

        $query = <<<'GQL'
query GetProductBySku($sku: String!) {
  productVariants(first: 1, query: "sku:$sku") {
    edges {
      node {
        image {
          url
        }
      }
    }
  }
}
GQL;

        $payload = json_encode([
            'query' => $query,
            'variables' => ['sku' => $sku],
        ]);
        if ($payload === false) {
            return '';
        }

        $ch = curl_init();
        if ($ch === false) {
            return '';
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$domain}/admin/api/{$version}/graphql.json",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Shopify-Access-Token: ' . $token,
            ],
            CURLOPT_TIMEOUT => 8,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return '';
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return '';
        }

        return trim((string) ($data['data']['productVariants']['edges'][0]['node']['image']['url'] ?? ''));
    }
}

if (!function_exists('pickCacheSkuImageUrl')) {
    function pickCacheSkuImageUrl(mysqli $conn, int $skuId, string $imageUrl): void
    {
        $skuId = (int) $skuId;
        $imageUrl = trim($imageUrl);
        if ($skuId <= 0 || $imageUrl === '' || !pickTableColumnExists($conn, 'sku', 'image_url')) {
            return;
        }

        $stmt = $conn->prepare('UPDATE sku SET image_url = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('si', $imageUrl, $skuId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('pickResolveSkuUpc')) {
    function pickResolveSkuUpc(mysqli $conn, int $skuId, string $skuNum): string
    {
        static $cache = [];

        $skuId = (int) $skuId;
        $skuNum = trim($skuNum);
        $cacheKey = $skuId > 0 ? 'id:' . $skuId : 'sku:' . strtoupper($skuNum);
        if (array_key_exists($cacheKey, $cache)) {
            return (string) $cache[$cacheKey];
        }

        $upc = '';

        if ($skuId > 0 && pickTableColumnExists($conn, 'sku', 'upc')) {
            $stmt = $conn->prepare('SELECT upc FROM sku WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $skuId);
                if ($stmt->execute()) {
                    $row = $stmt->get_result()->fetch_assoc() ?: null;
                    $upc = trim((string) ($row['upc'] ?? ''));
                }
                $stmt->close();
            }
        }

        if ($upc === '' && pickTableExists($conn, 'product_uom_barcodes')) {
            if ($skuId > 0) {
                $stmt = $conn->prepare("
                    SELECT upc
                    FROM product_uom_barcodes
                    WHERE sku_id = ?
                    ORDER BY CASE WHEN UPPER(uom_label) = 'UNIT' THEN 0 ELSE 1 END, id ASC
                    LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param('i', $skuId);
                    if ($stmt->execute()) {
                        $row = $stmt->get_result()->fetch_assoc() ?: null;
                        $upc = trim((string) ($row['upc'] ?? ''));
                    }
                    $stmt->close();
                }
            } elseif ($skuNum !== '') {
                $stmt = $conn->prepare("
                    SELECT b.upc
                    FROM product_uom_barcodes b
                    JOIN sku s ON s.id = b.sku_id
                    WHERE s.sku_num = ?
                    ORDER BY CASE WHEN UPPER(b.uom_label) = 'UNIT' THEN 0 ELSE 1 END, b.id ASC
                    LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param('s', $skuNum);
                    if ($stmt->execute()) {
                        $row = $stmt->get_result()->fetch_assoc() ?: null;
                        $upc = trim((string) ($row['upc'] ?? ''));
                    }
                    $stmt->close();
                }
            }
        }

        if ($upc === '' && $skuNum !== '' && pickTableColumnExists($conn, 'sku', 'upc')) {
            $stmt = $conn->prepare('SELECT upc FROM sku WHERE sku_num = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $skuNum);
                if ($stmt->execute()) {
                    $row = $stmt->get_result()->fetch_assoc() ?: null;
                    $upc = trim((string) ($row['upc'] ?? ''));
                }
                $stmt->close();
            }
        }

        $cache[$cacheKey] = $upc;

        return $upc;
    }
}


if (!function_exists('writePickImportAudit')) {
    function writePickImportAudit(mysqli $conn, array $audit): bool
    {
        $stmt = $conn->prepare('
            INSERT INTO pick_import_audits
                (
                    source_type,
                    source_name,
                    source_file,
                    source_path,
                    source_checksum,
                    sales_order_numbers,
                    worksheet_row_count,
                    parsed_row_count,
                    imported_row_count,
                    duplicate_row_count,
                    rejected_row_count,
                    integrity_match,
                    details_json
                )
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        if (!$stmt) {
            error_log(
                '[PICK AUDIT] prepare failed: ' .
                ($conn->error ?: 'unknown error')
            );
            return false;
        }

        $sourceType = trim((string) ($audit['source_type'] ?? 'session'));
        $sourceName = trim((string) ($audit['source_name'] ?? ''));
        $sourceFile = trim((string) ($audit['source_file'] ?? ''));
        $sourcePath = trim((string) ($audit['source_path'] ?? ''));
        $sourceChecksum = trim((string) ($audit['source_checksum'] ?? ''));
        $salesOrderNumbers = trim((string) ($audit['sales_order_numbers'] ?? ''));

        $sourceType = $sourceType !== '' ? mb_substr($sourceType, 0, 32) : 'session';
        $sourceName = $sourceName !== '' ? mb_substr($sourceName, 0, 255) : '';
        $sourceFile = $sourceFile !== '' ? mb_substr($sourceFile, 0, 255) : '';
        $sourcePath = $sourcePath !== '' ? mb_substr($sourcePath, 0, 512) : null;
        $sourceChecksum = $sourceChecksum !== '' ? mb_substr($sourceChecksum, 0, 64) : null;
        $salesOrderNumbers = $salesOrderNumbers !== ''
            ? mb_substr($salesOrderNumbers, 0, 1024)
            : null;

        $worksheetRowCount = max(0, (int) ($audit['worksheet_row_count'] ?? 0));
        $parsedRowCount = max(0, (int) ($audit['parsed_row_count'] ?? 0));
        $importedRowCount = max(0, (int) ($audit['imported_row_count'] ?? 0));
        $duplicateRowCount = max(0, (int) ($audit['duplicate_row_count'] ?? 0));
        $rejectedRowCount = max(0, (int) ($audit['rejected_row_count'] ?? 0));
        $integrityMatch = !empty($audit['integrity_match']) ? 1 : 0;

        $detailsJson = json_encode(
            $audit['details_json'] ?? [],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($detailsJson === false) {
            $detailsJson = null;
        }

        $stmt->bind_param(
            'ssssssiiiiiis',
            $sourceType,
            $sourceName,
            $sourceFile,
            $sourcePath,
            $sourceChecksum,
            $salesOrderNumbers,
            $worksheetRowCount,
            $parsedRowCount,
            $importedRowCount,
            $duplicateRowCount,
            $rejectedRowCount,
            $integrityMatch,
            $detailsJson
        );

        if (!$stmt->execute()) {
            error_log(
                '[PICK AUDIT] execute failed: ' .
                ($stmt->error ?: $conn->error ?: 'unknown error')
            );

            $stmt->close();
            return false;
        }

        $stmt->close();

        return true;
    }
}


if (!function_exists('pickDebugLoggingEnabled')) {
    function pickDebugLoggingEnabled(): bool
    {
        $raw = getenv('VERIFY_PICK_DEBUG');
        if ($raw === false || $raw === '') {
            $raw = getenv('PICK_DEBUG');
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('pickDebugLog')) {
    /**
     * Write a gated debug trace for verify_pick.php.
     *
     * @param array<string,mixed> $context
     */
    function pickDebugLog(string $event, array $context = []): void
    {
        if (!pickDebugLoggingEnabled()) {
            return;
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $event;
        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }

        error_log('[verify_pick_debug] ' . $line);
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        @file_put_contents($logDir . '/verify_pick_debug.log', $line . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('pickNormalizeItemNo')) {
    function pickNormalizeItemNo(string $value): string
    {
        $value = trim($value);
        $value = trim($value, "* \t\n\r\0\x0B");
        $value = preg_replace('/\s+/', '', $value) ?? '';
        return strtoupper($value);
    }
}

$status_message = "Ready to upload.";
$extracted_json = "No data extracted yet.";
$parsed_json = "No structured data yet.";
$parsed_data = [];
$is_error = false;
$debug_info = [];
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// These lightweight schema checks keep existing installations upgrade-safe.
pickEnsurePriorityAndNotesSchema($conn);
pickEnsureBogoColumn($conn);

function pickBuildGeneratedBoxIdentifiers(int $count): array
{
    if ($count < 1) {
        return [];
    }

    $labels = [];
    for ($i = 1; $i <= $count; $i++) {
        $labels[] = $i . '/' . $count;
    }

    return $labels;
}

function pickCleanupBoxLabelPrintBatches(): void
{
    if (!isset($_SESSION['box_label_print_batches']) || !is_array($_SESSION['box_label_print_batches'])) {
        $_SESSION['box_label_print_batches'] = [];
        return;
    }

    $cutoff = time() - 3600;
    foreach ($_SESSION['box_label_print_batches'] as $token => $batch) {
        $createdAt = (int) ($batch['created_at'] ?? 0);
        if ($createdAt < $cutoff) {
            unset($_SESSION['box_label_print_batches'][$token]);
        }
    }
}

function pickCleanupPackingManifests(): void
{
    if (!isset($_SESSION['packing_manifest_batches']) || !is_array($_SESSION['packing_manifest_batches'])) {
        $_SESSION['packing_manifest_batches'] = [];
        return;
    }

    $cutoff = time() - 86400;
    foreach ($_SESSION['packing_manifest_batches'] as $token => $batch) {
        $createdAt = (int) ($batch['created_at'] ?? 0);
        if ($createdAt < $cutoff) {
            unset($_SESSION['packing_manifest_batches'][$token]);
        }
    }
}

function pickClearPackingManifestBatchesForOrder(int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }

    if (!isset($_SESSION['packing_manifest_batches']) || !is_array($_SESSION['packing_manifest_batches'])) {
        $_SESSION['packing_manifest_batches'] = [];
        return;
    }

    foreach ($_SESSION['packing_manifest_batches'] as $token => $batch) {
        if (!is_array($batch)) {
            continue;
        }
        if ((int) ($batch['order_id'] ?? 0) === $orderId) {
            unset($_SESSION['packing_manifest_batches'][$token]);
        }
    }
}

function pickDeletePackingManifestsForOrder(mysqli $conn, int $orderId): void
{
    if ($orderId <= 0 || !pickTableExists($conn, 'packing_manifests')) {
        return;
    }

    $stmt = $conn->prepare('DELETE FROM packing_manifests WHERE order_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare packing manifest cleanup.');
    }

    $stmt->bind_param('i', $orderId);
    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error ?: 'Unable to delete packing manifest.';
        $stmt->close();
        throw new RuntimeException($error);
    }

    $stmt->close();
}

function pickEnsureShortShipQtyColumn(mysqli $conn): bool
{
    if (!pickTableExists($conn, 'pick_items')) {
        return false;
    }

    $existsStmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pick_items'
          AND COLUMN_NAME = 'short_ship_qty'
        LIMIT 1
    ");
    if (!$existsStmt) {
        return false;
    }
    $existsStmt->execute();
    $exists = (bool) $existsStmt->get_result()->fetch_row();
    $existsStmt->close();
    if ($exists) {
        try {
            $conn->query("
                UPDATE pick_items
                SET short_ship_qty = GREATEST(qty_ordered - COALESCE(qty_verified, 0), 0)
                WHERE COALESCE(is_short_ship, 0) = 1
                  AND COALESCE(short_ship_qty, 0) = 0
            ");
        } catch (Throwable $e) {
            error_log('[verify_pick] unable to repair pick_items.short_ship_qty values: ' . $e->getMessage());
        }
        return true;
    }

    try {
        $conn->query("ALTER TABLE pick_items ADD COLUMN short_ship_qty INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_short_ship");
        $conn->query("
            UPDATE pick_items
            SET short_ship_qty = GREATEST(qty_ordered - COALESCE(qty_verified, 0), 0)
            WHERE COALESCE(is_short_ship, 0) = 1
              AND COALESCE(short_ship_qty, 0) = 0
        ");
        return true;
    } catch (Throwable $e) {
        error_log('[verify_pick] unable to add pick_items.short_ship_qty column: ' . $e->getMessage());
        return false;
    }
}


/**
 * Persist BOGO detection so it survives the immediate database reload and
 * later order loads. The parser determines BOGO from raw warehouse-row
 * occurrences before duplicate rows are collapsed.
 */
function pickEnsureBogoColumn(mysqli $conn): bool
{
    if (!pickTableExists($conn, 'pick_items')) {
        return false;
    }

    $existsStmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pick_items'
          AND COLUMN_NAME = 'is_bogo'
        LIMIT 1
    ");
    if (!$existsStmt) {
        return false;
    }
    $existsStmt->execute();
    $exists = (bool) $existsStmt->get_result()->fetch_row();
    $existsStmt->close();
    if ($exists) {
        return true;
    }

    try {
        $conn->query("ALTER TABLE pick_items ADD COLUMN is_bogo TINYINT(1) NOT NULL DEFAULT 0 AFTER qty_ordered");
        return true;
    } catch (Throwable $e) {
        error_log('[verify_pick] unable to add pick_items.is_bogo column: ' . $e->getMessage());
        return false;
    }
}

/**
 * @return array<string,true>
 */
function pickExtractBogoItemKeys(array $data): array
{
    $keys = [];
    // Parser output tagged with a bogo_rule already decided is_bogo per item;
    // raw repeat counts and duplicate alerts must not override it.
    $itemFlagsAreFinal = ($data['bogo_rule'] ?? '') !== '';

    foreach (($data['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $rawCount = (int) ($item['raw_occurrence_count'] ?? 0);
        if (empty($item['is_bogo']) && ($itemFlagsAreFinal || $rawCount < 3)) {
            continue;
        }

        $itemNo = trim((string) ($item['item_no'] ?? $item['sku'] ?? ''));
        $key = pickNormalizeItemNo($itemNo);
        if ($key !== '') {
            $keys[$key] = true;
        }
    }

    if ($itemFlagsAreFinal) {
        return $keys;
    }

    $alerts = $data['duplicate_alerts'] ?? ($data['alerts'] ?? []);
    if (is_array($alerts)) {
        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }
            $type = (string) ($alert['type'] ?? '');
            $count = (int) ($alert['count'] ?? 0);
            if ($type !== 'duplicate_item_threshold_reached' || $count < 3) {
                continue;
            }
            $key = pickNormalizeItemNo((string) ($alert['item_no'] ?? ''));
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
    }

    return $keys;
}

/**
 * Apply authoritative BOGO flags from a freshly parsed source file to the
 * persisted order. This also repairs an already-imported order when the same
 * source file is uploaded again.
 */
function pickApplyBogoFlags(mysqli $conn, int $orderId, array $data): int
{
    if ($orderId <= 0 || !pickTableColumnExists($conn, 'pick_items', 'is_bogo')) {
        return 0;
    }

    $keys = pickExtractBogoItemKeys($data);

    $clearStmt = $conn->prepare('UPDATE pick_items SET is_bogo = 0 WHERE order_id = ?');
    if ($clearStmt) {
        $clearStmt->bind_param('i', $orderId);
        $clearStmt->execute();
        $clearStmt->close();
    }

    if ($keys === []) {
        return 0;
    }

    $updateStmt = $conn->prepare("
        UPDATE pick_items
        SET is_bogo = 1
        WHERE order_id = ?
          AND UPPER(REPLACE(TRIM(item_no), ' ', '')) = ?
    ");
    if (!$updateStmt) {
        return 0;
    }

    $updated = 0;
    foreach (array_keys($keys) as $key) {
        $normalizedKey = (string) $key;
        $updateStmt->bind_param('is', $orderId, $normalizedKey);
        if ($updateStmt->execute()) {
            $updated += max(0, (int) $updateStmt->affected_rows);
        }
    }
    $updateStmt->close();

    return $updated;
}

function pickEnsurePriorityAndNotesSchema(mysqli $conn): void
{
    if (pickTableExists($conn, 'pick_orders') && !pickTableColumnExists($conn, 'pick_orders', 'is_priority')) {
        try {
            $conn->query("ALTER TABLE pick_orders ADD COLUMN is_priority TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
            error_log('[verify_pick] unable to add pick_orders.is_priority: ' . $e->getMessage());
        }
    }

    if (!pickTableExists($conn, 'pick_order_notes')) {
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
            error_log('[verify_pick] unable to create pick_order_notes: ' . $e->getMessage());
        }
    }
}

function pickFetchOrderNotes(mysqli $conn, int $orderId): array
{
    if ($orderId <= 0 || !pickTableExists($conn, 'pick_order_notes')) {
        return [];
    }
    $stmt = $conn->prepare('SELECT id, order_id, note, created_by_user_id, workflow_stage, created_at, acknowledged_at FROM pick_order_notes WHERE order_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    $notes = [];
    while ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
        $notes[] = $row;
    }
    $stmt->close();
    return $notes;
}

function pickResolvedShortShipQtyExpr(string $alias = ''): string
{
    $prefix = trim($alias) !== '' ? rtrim($alias, '.') . '.' : '';

    return "COALESCE({$prefix}short_ship_qty, CASE WHEN COALESCE({$prefix}is_short_ship, 0) = 1 THEN GREATEST({$prefix}qty_ordered - COALESCE({$prefix}qty_verified, 0), 0) ELSE 0 END)";
}

function pickStorePackingManifestBatch(mysqli $conn, array $batch): array
{
    if (!pickTableExists($conn, 'packing_manifests')) {
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

    $stmt = $conn->prepare('
        INSERT INTO packing_manifests
            (manifest_token, order_id, sales_order_number, box_count, item_count, ordered_count, unassigned_count, manifest_json, finalized_by_user_id)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            order_id = VALUES(order_id),
            sales_order_number = VALUES(sales_order_number),
            box_count = VALUES(box_count),
            item_count = VALUES(item_count),
            ordered_count = VALUES(ordered_count),
            unassigned_count = VALUES(unassigned_count),
            manifest_json = VALUES(manifest_json),
            finalized_by_user_id = VALUES(finalized_by_user_id)
    ');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare packing manifest storage.');
    }

    $bindArgs = [
        'sisiiiisi',
        &
        $token,
        &
        $orderId,
        &
        $salesOrderNumber,
        &
        $boxCount,
        &
        $itemCount,
        &
        $orderedCount,
        &
        $unassignedCount,
        &
        $json,
        &
        $finalizedByUserId,
    ];
    if (!call_user_func_array([$stmt, 'bind_param'], $bindArgs)) {
        $stmt->close();
        throw new RuntimeException('Unable to bind packing manifest values.');
    }

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error ?: 'Unable to store packing manifest.';
        $stmt->close();
        throw new RuntimeException($error);
    }

    $stmt->close();

    return $batch;
}

function pickLoadPackingManifestBatch(mysqli $conn, string $manifestToken): ?array
{
    $manifestToken = trim($manifestToken);
    if ($manifestToken === '') {
        return null;
    }

    if (pickTableExists($conn, 'packing_manifests')) {
        $stmt = $conn->prepare('
            SELECT manifest_json
            FROM packing_manifests
            WHERE manifest_token = ?
            LIMIT 1
        ');
        if ($stmt) {
            $stmt->bind_param('s', $manifestToken);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if (is_array($row)) {
                $batch = json_decode((string) ($row['manifest_json'] ?? ''), true);
                if (is_array($batch)) {
                    if (!isset($batch['manifest_token']) || trim((string) $batch['manifest_token']) === '') {
                        $batch['manifest_token'] = $manifestToken;
                    }
                    return $batch;
                }
            }
        }
    }

    pickCleanupPackingManifests();
    $sessionBatch = $_SESSION['packing_manifest_batches'][$manifestToken] ?? null;
    return is_array($sessionBatch) ? $sessionBatch : null;
}

function pickLookupPackingManifestByReference(mysqli $conn, string $reference): ?array
{
    $reference = trim($reference);
    if ($reference === '') {
        return null;
    }

    $orderId = 0;
    $searchVariants = [];
    $searchVariants[] = $reference;
    $compactReference = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $reference) ?? '');
    if ($compactReference !== '' && $compactReference !== $reference) {
        $searchVariants[] = $compactReference;
    }
    $digitsReference = preg_replace('/\D+/', '', $reference) ?? '';
    if ($digitsReference !== '' && !in_array($digitsReference, $searchVariants, true)) {
        $searchVariants[] = $digitsReference;
    }
    $searchVariants = array_values(array_unique(array_filter($searchVariants, static fn($value) => trim((string) $value) !== '')));

    $order = null;
    $orderLikeVariants = array_map(static fn($value) => '%' . $value . '%', $searchVariants);
    $likeVariant0 = $orderLikeVariants[0] ?? '%' . $reference . '%';
    $likeVariant1 = $orderLikeVariants[1] ?? $likeVariant0;
    $exactVariant0 = $searchVariants[0] ?? $reference;
    $exactVariant1 = $searchVariants[1] ?? $exactVariant0;
    $exactLikeVariant0 = $likeVariant0;
    $exactLikeVariant1 = $likeVariant1;
    $orderStmt = $conn->prepare('
        SELECT id, order_number, status, source_file, assigned_user_id
        FROM pick_orders
        WHERE order_number LIKE ? OR CAST(id AS CHAR) LIKE ? OR order_number LIKE ? OR CAST(id AS CHAR) LIKE ?
        ORDER BY
            CASE
                WHEN order_number = ? THEN 0
                WHEN CAST(id AS CHAR) = ? THEN 1
                WHEN order_number LIKE ? THEN 2
                WHEN CAST(id AS CHAR) LIKE ? THEN 3
                WHEN order_number LIKE ? THEN 4
                WHEN CAST(id AS CHAR) LIKE ? THEN 5
                ELSE 6
            END,
            updated_at DESC,
            id DESC
        LIMIT 1
    ');
    if ($orderStmt) {
        $orderStmt->bind_param(
            'ssssssssss',
            $likeVariant0,
            $likeVariant0,
            $likeVariant1,
            $likeVariant1,
            $exactVariant0,
            $exactVariant0,
            $exactLikeVariant0,
            $exactLikeVariant0,
            $exactLikeVariant1,
            $exactLikeVariant1
        );
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc() ?: null;
        $orderStmt->close();
    }

    if ((!is_array($order) || isset($order['error'])) && ctype_digit($reference)) {
        $orderId = (int) $reference;
        if ($orderId > 0) {
            $order = loadPickOrderFromDb($conn, $orderId, null);
            if (!is_array($order) || isset($order['error'])) {
                $order = null;
            }
        }
    }

    if ((!is_array($order) || isset($order['error'])) && $reference !== '') {
        $order = loadPickOrderFromDb($conn, 0, $reference);
        if (!is_array($order) || isset($order['error'])) {
            $order = null;
        }
    }

    if (is_array($order)) {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId > 0) {
            pickRequireOrderAccessJson($conn, $orderId);
        }
    }

    $manifest = null;
    if ($orderId > 0 && pickTableExists($conn, 'packing_manifests')) {
        $stmt = $conn->prepare('
            SELECT manifest_json
            FROM packing_manifests
            WHERE order_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ');
        if ($stmt) {
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (is_array($row)) {
                $manifest = json_decode((string) ($row['manifest_json'] ?? ''), true);
            }
        }
    }

    if (!is_array($manifest) && pickTableExists($conn, 'packing_manifests')) {
        foreach ($searchVariants as $salesOrderNumber) {
            if ($salesOrderNumber === '') {
                continue;
            }
            $stmt = $conn->prepare('
                SELECT manifest_json
                FROM packing_manifests
                WHERE sales_order_number LIKE ?
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ');
            if ($stmt) {
                $like = '%' . $salesOrderNumber . '%';
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if (is_array($row)) {
                    $manifest = json_decode((string) ($row['manifest_json'] ?? ''), true);
                    if (is_array($manifest)) {
                        break;
                    }
                }
            }
        }
    }

    if (!is_array($manifest)) {
        return null;
    }

    if (!isset($manifest['manifest_token']) || trim((string) $manifest['manifest_token']) === '') {
        $tokenStmt = $conn->prepare('
            SELECT manifest_token
            FROM packing_manifests
            WHERE order_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ');
        if ($tokenStmt) {
            $tokenStmt->bind_param('i', $orderId);
            $tokenStmt->execute();
            $tokenRow = $tokenStmt->get_result()->fetch_assoc() ?: null;
            $tokenStmt->close();
            if (is_array($tokenRow) && trim((string) ($tokenRow['manifest_token'] ?? '')) !== '') {
                $manifest['manifest_token'] = (string) $tokenRow['manifest_token'];
            } elseif (pickTableExists($conn, 'packing_manifests')) {
                foreach ($searchVariants as $salesOrderNumber) {
                    if ($salesOrderNumber === '') {
                        continue;
                    }
                    $likeStmt = $conn->prepare('
                        SELECT manifest_token
                        FROM packing_manifests
                        WHERE sales_order_number LIKE ?
                        ORDER BY created_at DESC, id DESC
                        LIMIT 1
                    ');
                    if (!$likeStmt) {
                        continue;
                    }
                    $like = '%' . $salesOrderNumber . '%';
                    $likeStmt->bind_param('s', $like);
                    $likeStmt->execute();
                    $likeRow = $likeStmt->get_result()->fetch_assoc() ?: null;
                    $likeStmt->close();
                    if (is_array($likeRow) && trim((string) ($likeRow['manifest_token'] ?? '')) !== '') {
                        $manifest['manifest_token'] = (string) $likeRow['manifest_token'];
                        break;
                    }
                }
            }
        }
    }

    if (is_array($order)) {
        $manifest['order_id'] = (int) ($order['id'] ?? $manifest['order_id'] ?? 0);
        $manifest['sales_order_number'] = (string) ($order['order_number'] ?? $manifest['sales_order_number'] ?? '');
    }

    return $manifest;
}

// -----------------------------------------------------------------------------
// Add a dedicated route for printing box labels on DYMO 30334 labels.
// When the verify pick page is called with action=box_label_print and the
// required GET parameters (so, box, dim, wt) are provided, render a
// print-optimized page sized exactly to the 2.25" x 1.25" label.
if ($action === 'box_label_print') {
    // Ensure the user is authenticated before allowing label printing
    if (!isset($_SESSION['username'])) {
        header('Location: ../login.php');
        exit;
    }
    // Helper to sanitize and truncate input values to reasonable lengths
    $clean = static function (string $s, int $max): string {
        $s = trim($s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = mb_substr($s, 0, $max);
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    $formatPrintedWeight = static function (string $weightLabel): string {
        $weightLabel = trim($weightLabel);
        if ($weightLabel === '') {
            return '';
        }
        if (preg_match('/\b(?:lb|lbs)\.?\s*$/i', $weightLabel)) {
            return $weightLabel;
        }
        return $weightLabel . ' lbs';
    };
    // Grab query parameters and clean them
    $token = trim((string) ($_GET['token'] ?? ''));
    $workflowMode = strtolower(trim((string) ($_GET['workflow'] ?? '')));
    $debugOrderId = 0;
    $labelRows = [];
    if ($token !== '') {
        pickCleanupBoxLabelPrintBatches();
        $batch = $_SESSION['box_label_print_batches'][$token] ?? null;
        if (!is_array($batch)) {
            http_response_code(404);
            echo 'Label batch not found or expired.';
            exit;
        }
        $workflowMode = $workflowMode !== '' ? $workflowMode : 'pack';
        $debugOrderId = (int) ($batch['order_id'] ?? 0);
        $so = $clean((string) ($batch['sales_order_number'] ?? ''), 24);
        foreach (($batch['boxes'] ?? []) as $boxRow) {
            if (!is_array($boxRow)) {
                continue;
            }
            $boxIdentifier = $clean((string) ($boxRow['box_identifier'] ?? ''), 16);
            $dimensions = $clean((string) ($boxRow['dimensions'] ?? ''), 24);
            $weightLabel = $clean((string) ($boxRow['weight_label'] ?? ''), 16);
            if ($boxIdentifier === '' || $dimensions === '' || $weightLabel === '') {
                continue;
            }
            $labelRows[] = [
                'box_identifier' => $boxIdentifier,
                'dimensions' => $dimensions,
                'weight_label' => $weightLabel,
            ];
        }
    } elseif (($manifestToken = trim((string) ($_GET['manifest_token'] ?? ''))) !== '') {
        $manifestBoxIdentifier = trim((string) ($_GET['box_identifier'] ?? ''));
        $batch = pickLoadPackingManifestBatch($conn, $manifestToken);
        if (!is_array($batch)) {
            http_response_code(404);
            echo 'Packing manifest not found or expired.';
            exit;
        }
        $workflowMode = $workflowMode !== '' ? $workflowMode : 'pack';
        $debugOrderId = (int) ($batch['order_id'] ?? 0);
        $so = $clean((string) ($batch['sales_order_number'] ?? ''), 24);
        foreach (($batch['boxes'] ?? []) as $boxRow) {
            if (!is_array($boxRow)) {
                continue;
            }
            $boxIdentifier = $clean((string) ($boxRow['box_identifier'] ?? ''), 16);
            if ($manifestBoxIdentifier !== '' && strcasecmp($manifestBoxIdentifier, $boxIdentifier) !== 0) {
                continue;
            }
            $dimensions = $clean((string) ($boxRow['dimensions'] ?? ''), 24);
            $weightLabel = $clean((string) ($boxRow['weight_label'] ?? ''), 16);
            if ($boxIdentifier === '' || $dimensions === '' || $weightLabel === '') {
                continue;
            }
            $labelRows[] = [
                'box_identifier' => $boxIdentifier,
                'dimensions' => $dimensions,
                'weight_label' => $weightLabel,
            ];
            if ($manifestBoxIdentifier !== '') {
                break;
            }
        }
    } else {
        $so = $clean((string) ($_GET['so'] ?? ''), 24);
        $dim = $clean((string) ($_GET['dim'] ?? ''), 24);
        $wt = $clean((string) ($_GET['wt'] ?? ''), 16);
        $count = (int) ($_GET['count'] ?? 0);
        $legacyBox = $clean((string) ($_GET['box'] ?? ''), 16);
        $workflowMode = $workflowMode !== '' ? $workflowMode : 'direct';
        // All shared fields are required; box ids may be provided directly for
        // legacy single-label printing or generated from count for batched labels.
        if ($so === '' || $dim === '' || $wt === '') {
            http_response_code(400);
            echo 'Missing fields.';
            exit;
        }
        if ($count < 0 || $count > 50) {
            http_response_code(400);
            echo 'Invalid box count.';
            exit;
        }
        if ($count > 0) {
            foreach (pickBuildGeneratedBoxIdentifiers($count) as $generatedBoxId) {
                $labelRows[] = [
                    'box_identifier' => $clean($generatedBoxId, 16),
                    'dimensions' => $dim,
                    'weight_label' => $wt,
                ];
            }
        } elseif ($legacyBox !== '') {
            $labelRows[] = [
                'box_identifier' => $legacyBox,
                'dimensions' => $dim,
                'weight_label' => $wt,
            ];
        }
    }

    if ($so === '' || $labelRows === []) {
        http_response_code(400);
        echo 'Missing fields.';
        exit;
    }
    $debugStatus = '';
    if ($debugOrderId > 0 && pickTableExists($conn, 'pick_orders')) {
        $statusStmt = $conn->prepare('SELECT status FROM pick_orders WHERE id = ? LIMIT 1');
        if ($statusStmt) {
            $statusStmt->bind_param('i', $debugOrderId);
            $statusStmt->execute();
            $statusRow = $statusStmt->get_result()->fetch_assoc() ?: null;
            $statusStmt->close();
            $debugStatus = strtoupper(trim((string) ($statusRow['status'] ?? '')));
        }
    }
    $debugOrderJson = json_encode((string) $so, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $debugStatusJson = json_encode((string) ($debugStatus !== '' ? $debugStatus : 'UNKNOWN'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $debugWorkflowJson = json_encode((string) ($workflowMode !== '' ? strtoupper($workflowMode) : 'UNKNOWN'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $title = 'Packing Manager';
    $page_class = 'page-box-label-print';
    $css = ['/refactor_css/verify_pick.css'];
    ob_start();
    ?>

    <script>
        console.log('[box_label_print]', {
            order_number: <?= $debugOrderJson !== false ? $debugOrderJson : '""' ?>,
            order_status: <?= $debugStatusJson !== false ? $debugStatusJson : '""' ?>,
            workflow_mode: <?= $debugWorkflowJson !== false ? $debugWorkflowJson : '""' ?>,
        });
    </script>

    <div class="box-label-print-stack">
        <?php foreach ($labelRows as $labelIndex => $labelRow): ?>
            <div class="box-label-print">
                <div class="box-label-print__top">
                    <div id="boxLabelCell1-<?= (int) $labelIndex + 1 ?>"
                        class="box-label-print__sales-order box-label-print__cell box-label-print__cell--1">
                        <div class="box-label-print__label">SALES ORDER #</div>
                        <div class="box-label-print__value"><?= $so ?></div>
                    </div>
                    <div id="boxLabelCell2-<?= (int) $labelIndex + 1 ?>"
                        class="box-label-print__box-id box-label-print__cell box-label-print__cell--2">
                        <div class="box-label-print__label">BOX ID</div>
                        <div class="box-label-print__value"><?= $labelRow['box_identifier'] ?></div>
                    </div>
                </div>
                <div class="box-label-print__bottom">
                    <div id="boxLabelCell3-<?= (int) $labelIndex + 1 ?>"
                        class="box-label-print__field box-label-print__cell box-label-print__cell--3">
                        <div class="box-label-print__label">DIMENSIONS</div>
                        <div class="box-label-print__value"><?= $labelRow['dimensions'] ?></div>
                    </div>
                    <div id="boxLabelCell4-<?= (int) $labelIndex + 1 ?>"
                        class="box-label-print__field box-label-print__cell box-label-print__cell--4">
                        <div class="box-label-print__label">WEIGHT</div>
                        <div class="box-label-print__value"><?= $formatPrintedWeight((string) $labelRow['weight_label']) ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 150));
    </script>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/templates/layout.php';
    exit;
}

/**
 * Natural sort by SKU / item number (handles alphanumeric codes correctly).
 */
function sortByShelfThenSku(array $a, array $b): int
{
    $aShelf = trim((string) ($a['shelf_location'] ?? ''));
    $bShelf = trim((string) ($b['shelf_location'] ?? ''));

    if ($aShelf === '' && $bShelf !== '') {
        return 1;
    }
    if ($bShelf === '' && $aShelf !== '') {
        return -1;
    }

    $cmp = strnatcasecmp($aShelf, $bShelf);
    if ($cmp !== 0) {
        return $cmp;
    }

    return strnatcasecmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? ''));
}

/**
 * Sort sales orders by the numeric portion of the order number in descending order.
 * This keeps SO37834 ahead of SO36489 while still falling back to the full string.
 */
function pickSalesOrderSortExpr(): string
{
    return "CAST(REPLACE(REPLACE(UPPER(order_number), 'SO', ''), ' ', '') AS UNSIGNED)";
}

/**
 * Join a compact per-order summary of the BOGO items on each order: items with
 * at least one line flagged is_bogo at import (more than one Take on a source
 * line). An item merely appearing on several lines is not BOGO.
 */
function pickRepeatedItemsSummaryJoin(string $orderAlias = 'po'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $orderAlias)) {
        $orderAlias = 'po';
    }

    return "
        LEFT JOIN (
            SELECT
                repeated.order_id,
                COUNT(*) AS repeated_item_count,
                SUM(repeated.line_count) AS repeated_line_count,
                GROUP_CONCAT(
                    CONCAT(repeated.item_no, ' — quantities: ', repeated.quantities)
                    ORDER BY repeated.item_no
                    SEPARATOR '; '
                ) AS repeated_item_details
            FROM (
                SELECT
                    order_id,
                    item_no,
                    COUNT(*) AS line_count,
                    GROUP_CONCAT(
                        CAST(qty_ordered AS CHAR)
                        ORDER BY source_line_no, id
                        SEPARATOR ', '
                    ) AS quantities
                FROM pick_items
                WHERE item_no IS NOT NULL
                  AND TRIM(item_no) <> ''
                GROUP BY order_id, item_no
                HAVING SUM(COALESCE(is_bogo, 0)) > 0
            ) repeated
            GROUP BY repeated.order_id
        ) repeated_items
            ON repeated_items.order_id = {$orderAlias}.id
    ";
}

/**
 * Normalize a header value for column detection.
 */
function normalizeHeader(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

/**
 * Detect column indices from a header row.
 */
function detectColumnMap(array $headers): array
{
    $aliases = [
        'line_no' => [
            'line no',
            'line no.',
            'line number',
            'line #',
        ],
        'source_line_no' => [
            'source line no',
            'source line no.',
            'source line number',
            'source line #'
        ],
        'sku' => ['sku', 'item', 'item no', 'item number', 'item #', 'sku #', 'sku number', 'product', 'part', 'part #'],
        'description' => ['description', 'desc', 'item description', 'product description', 'product desc'],
        'quantity' => ['qty', 'quantity', 'qty ordered', 'order qty', 'pick qty', 'qty to pick', 'qty pick', 'quantity to pick'],
        'bin' => ['bin', 'bin code', 'bincode', 'bin location', 'bin loc', 'location', 'loc', 'loc code', 'location code', 'bin/loc', 'bin location'],
        'pick_order' => ['pick order', 'pick order #', 'pick order number', 'pick order no', 'pick #'],
        'sales_order' => ['sales order', 'sales order #', 'sales order number', 'sales order no', 'so #', 'so', 'source no', 'source no.', 'source number', 'source #', 'source'],
        'action_type' => ['action type'],
    ];

    $map = [];
    $qtyBestIdx = null;
    $qtyBestScore = null;
    foreach ($headers as $idx => $header) {
        $norm = normalizeHeader((string) $header);
        if ($norm === '') {
            continue;
        }
        foreach ($aliases as $key => $list) {
            if (isset($map[$key])) {
                continue;
            }
            foreach ($list as $alias) {
                $aliasNorm = normalizeHeader($alias);
                if ($aliasNorm === $norm || str_contains($norm, $aliasNorm)) {
                    $map[$key] = $idx;
                    break 2;
                }
            }
        }

        // Track best quantity column when multiple "qty/quantity" columns exist.
        if (str_contains($norm, 'qty') || str_contains($norm, 'quantity')) {
            $score = 0;
            if ($norm === 'quantity' || $norm === 'qty') {
                $score += 6;
            }
            if (str_contains($norm, 'quantity')) {
                $score += 3;
            }
            if (str_contains($norm, 'per unit') || str_contains($norm, 'per unit of measure')) {
                $score -= 4;
            }
            if (str_contains($norm, 'base')) {
                $score -= 3;
            }
            if (str_contains($norm, 'outstanding')) {
                $score -= 3;
            }
            if (str_contains($norm, 'handled') || str_contains($norm, 'to handle')) {
                $score -= 3;
            }
            if ($qtyBestScore === null || $score > $qtyBestScore) {
                $qtyBestScore = $score;
                $qtyBestIdx = $idx;
            }
        }
    }

    $fixed = $GLOBALS['FIXED_COLS'] ?? null;
    $forceFixed = $GLOBALS['FORCE_FIXED_COLS'] ?? false;
    if (is_array($fixed)) {
        $skuIdx = isset($fixed['sku']) ? (int) $fixed['sku'] - 1 : null;
        $descIdx = isset($fixed['description']) ? (int) $fixed['description'] - 1 : null;
        $qtyIdx = isset($fixed['quantity']) ? (int) $fixed['quantity'] - 1 : null;
        $binIdx = isset($fixed['bin']) ? (int) $fixed['bin'] - 1 : null;

        if ($forceFixed) {
            if (!isset($map['sku']) && $skuIdx !== null) {
                $map['sku'] = $skuIdx;
            }
            if (!isset($map['description']) && $descIdx !== null) {
                $map['description'] = $descIdx;
            }
            if (!isset($map['quantity']) && $qtyIdx !== null) {
                $map['quantity'] = $qtyIdx;
            }
            if (!isset($map['bin']) && $binIdx !== null) {
                $map['bin'] = $binIdx;
            }
        } else {
            if (!isset($map['sku']) && $skuIdx !== null && isset($headers[$skuIdx])) {
                $map['sku'] = $skuIdx;
            }
            if (!isset($map['description']) && $descIdx !== null && isset($headers[$descIdx])) {
                $map['description'] = $descIdx;
            }
            if (!isset($map['quantity']) && $qtyIdx !== null && isset($headers[$qtyIdx])) {
                $map['quantity'] = $qtyIdx;
            }
            if (!isset($map['bin']) && $binIdx !== null && isset($headers[$binIdx])) {
                $map['bin'] = $binIdx;
            }
        }
    }

    if (isset($qtyBestIdx) && (!isset($map['quantity']) || $qtyBestScore !== null)) {
        $map['quantity'] = $qtyBestIdx;
    }

    return $map;
}

function parseQuantityCell(string $value): ?int
{
    $clean = str_replace([',', ' '], '', trim($value));
    if ($clean === '') {
        return null;
    }
    if (!is_numeric($clean)) {
        $clean = preg_replace('/[^0-9\\-\\.]/', '', $clean) ?? '';
    }
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }
    return (int) round((float) $clean);
}


function looksLikeSku(string $value): bool
{
    $value = strtoupper(trim($value));

    if ($value === '') {
        return false;
    }

    // A heuristic SKU must contain at least one letter and one number.
    if (!preg_match('/[A-Z]/', $value)) {
        return false;
    }

    if (!preg_match('/[0-9]/', $value)) {
        return false;
    }

    return (bool) preg_match('/^[A-Z0-9._\/-]+$/', $value);
}

function findSkuIndexInRow(array $row): ?int
{
    foreach ($row as $idx => $cell) {
        if (looksLikeSku((string) $cell)) {
            return (int) $idx;
        }
    }
    return null;
}

function findDescriptionIndex(array $row, int $startIdx): ?int
{
    for ($i = $startIdx + 1; $i < count($row); $i++) {
        $cell = trim((string) ($row[$i] ?? ''));
        if ($cell === '') {
            continue;
        }
        if (preg_match('/[A-Z]/i', $cell)) {
            return $i;
        }
    }
    return null;
}

function findQuantityIndex(array $row, int $startIdx): ?int
{
    for ($i = $startIdx + 1; $i < count($row); $i++) {
        if (parseQuantityCell((string) ($row[$i] ?? '')) !== null) {
            return $i;
        }
    }
    return null;
}

function extractDescriptionFromSkuCell(string $skuCell, string $sku): string
{
    $sku = strtoupper(trim($sku));
    $skuCell = trim($skuCell);
    if ($skuCell === '' || $sku === '') {
        return '';
    }
    $lines = preg_split('/\\R/', $skuCell) ?: [];
    $candidate = '';
    foreach ($lines as $line) {
        $lineTrim = trim($line);
        if ($lineTrim === '') {
            continue;
        }
        if (stripos($lineTrim, $sku) !== false || stripos($lineTrim, "*{$sku}*") !== false) {
            $candidate = $lineTrim;
        }
    }
    if ($candidate === '') {
        $candidate = $skuCell;
    }

    $parts = preg_split('/\\s{2,}|\\t+/', $candidate) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));
    if (count($parts) >= 3) {
        return trim(implode(' ', array_slice($parts, 2)));
    }

    $pattern = '/^\\*?' . preg_quote($sku, '/') . '\\*?\\s*/i';
    $candidate = preg_replace($pattern, '', $candidate, 1) ?? $candidate;
    $candidate = trim(preg_replace('/^\\*?[A-Z]{1,}[0-9]{4,}(?:-[A-Z0-9]+)*\\*?\\s*/i', '', $candidate, 1) ?? $candidate);
    return trim($candidate);
}

/**
 * Extract description/bin/qty from a SKU line embedded in a cell.
 *
 * @return array{desc:string,bin:string,qty:int}
 */
function extractSkuLineDetails(string $skuCell, string $sku): array
{
    $sku = strtoupper(trim($sku));
    if ($sku === '') {
        return ['desc' => '', 'bin' => '', 'qty' => 0];
    }
    $lines = preg_split('/\\R/', $skuCell) ?: [];
    $candidate = '';
    foreach ($lines as $line) {
        $lineTrim = trim($line);
        if ($lineTrim === '') {
            continue;
        }
        if (stripos($lineTrim, $sku) !== false || stripos($lineTrim, "*{$sku}*") !== false) {
            $candidate = $lineTrim;
            break;
        }
    }
    if ($candidate === '') {
        $candidate = trim($skuCell);
    }

    $pattern = '/^\\s*\\*?' . preg_quote($sku, '/') . '\\*?\\s+\\*?' . preg_quote($sku, '/') . '\\*?\\s+(.*?)\\s+([A-Z]{1,3}\\d{2}-\\d{2}-\\d{2}|PUT-\\d+|[A-Z]{1,3}\\d{2}-\\d{2})\\s+([0-9]+(?:\\.[0-9]+)?)/i';
    if (preg_match($pattern, $candidate, $m)) {
        return [
            'desc' => trim((string) $m[1]),
            'bin' => trim((string) $m[2]),
            'qty' => parseQuantityCell((string) $m[3]) ?? 0,
        ];
    }

    $fallbackPattern = '/^\\s*\\*?' . preg_quote($sku, '/') . '\\*?\\s+(.*?)\\s+([A-Z]{1,3}\\d{2}-\\d{2}-\\d{2}|PUT-\\d+|[A-Z]{1,3}\\d{2}-\\d{2})\\s+([0-9]+(?:\\.[0-9]+)?)/i';
    if (preg_match($fallbackPattern, $candidate, $m)) {
        return [
            'desc' => trim((string) $m[1]),
            'bin' => trim((string) $m[2]),
            'qty' => parseQuantityCell((string) $m[3]) ?? 0,
        ];
    }

    return ['desc' => '', 'bin' => '', 'qty' => 0];
}

/**
 * Read rows from a CSV, XLSX, or PDF file.
 *
 * @return array{rows: array<int,array<int,string>>, error: string}
 */
function readSpreadsheetRows(string $path, string $extension): array
{
    $rows = [];
    $error = '';

    try {
        if ($extension === 'csv') {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open CSV file.');
            }
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }
                $rows[] = array_map('strval', $row);
            }
            fclose($handle);
        } elseif ($extension === 'xlsx' || $extension === 'xlsm') {
            if (version_compare(PHP_VERSION, '8.3.0', '<')) {
                throw new RuntimeException('XLSX import requires PHP 8.3+. Please export CSV or upgrade PHP.');
            }
            if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                throw new RuntimeException('PhpSpreadsheet IOFactory is not available.');
            }
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $data = $sheet->toArray(null, true, true, false);
                foreach ($data as $row) {
                    $rows[] = array_map('strval', $row);
                }
                $rows[] = [];
            }
        } else {
            $error = 'Unsupported file type. Please upload CSV, XLSX, or XLSM.';
        }
    } catch (Throwable $e) {
        $error = 'File processing error: ' . $e->getMessage();
    }

    return ['rows' => $rows, 'error' => $error];
}


/**
 * List pick files from Google Drive.
 *
 * @return array<int, array{id:string,name:string,date:string,modified:string,modified_ts:int}>
 */
function listDrivePickFiles(string $serviceAccountPath, $folderId, $namePrefix): array
{
    $folderIds = [];
    if (is_array($folderId)) {
        $folderIds = array_values(array_filter(array_map('trim', $folderId), static fn($v) => $v !== ''));
    } elseif (is_string($folderId) && trim($folderId) !== '') {
        $folderIds = [trim($folderId)];
    }

    if (!class_exists('Google\\Client') || empty($folderIds) || !is_file($serviceAccountPath)) {
        if (empty($folderIds)) {
            $GLOBALS['DRIVE_LAST_ERROR'] = 'Drive folder ID is empty.';
        } elseif (!is_file($serviceAccountPath)) {
            $GLOBALS['DRIVE_LAST_ERROR'] = 'Service account JSON not found at: ' . $serviceAccountPath;
        } elseif (!class_exists('Google\\Client')) {
            $GLOBALS['DRIVE_LAST_ERROR'] = 'Google API client not available. Check Composer autoload.';
        }
        return [];
    }

    $client = new \Google\Client();
    $client->setAuthConfig($serviceAccountPath);
    $client->addScope(\Google\Service\Drive::DRIVE_READONLY);
    $service = new \Google\Service\Drive($client);

    $files = [];
    foreach ($folderIds as $fid) {
        $query = sprintf("'%s' in parents and trashed = false", addslashes($fid));
        $params = [
            'q' => $query,
            'fields' => 'files(id,name,modifiedTime)',
            'orderBy' => 'modifiedTime desc',
            'pageSize' => 200,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ];

        try {
            $batch = $service->files->listFiles($params)->getFiles();
            foreach ($batch as $f) {
                $files[] = $f;
            }
        } catch (Throwable $e) {
            $GLOBALS['DRIVE_LAST_ERROR'] = 'Drive API error: ' . $e->getMessage();
            return [];
        }
    }
    $out = [];
    $prefixes = [];
    if (is_array($namePrefix)) {
        $prefixes = array_values(array_filter(array_map('trim', $namePrefix), static fn($v) => $v !== ''));
    } elseif (is_string($namePrefix) && trim($namePrefix) !== '') {
        $prefixes = [trim($namePrefix)];
    }

    foreach ($files as $file) {
        $name = (string) $file->getName();
        if ($prefixes) {
            $matched = false;
            foreach ($prefixes as $prefix) {
                if (stripos($name, $prefix) === 0) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
        }
        $modified = (string) $file->getModifiedTime();
        $modifiedTs = $modified !== '' ? strtotime($modified) : 0;
        $date = $modifiedTs > 0 ? gmdate('Y-m-d', $modifiedTs) : '';
        $out[] = [
            'id' => (string) $file->getId(),
            'name' => $name,
            'date' => $date,
            'modified' => $modified,
            'modified_ts' => $modifiedTs,
        ];
    }

    usort($out, static fn($a, $b) => ($b['modified_ts'] ?? 0) <=> ($a['modified_ts'] ?? 0));
    return array_slice($out, 0, 20);
}

/**
 * Download a Drive file to a local temp path.
 *
 * @return array{path:string,ext:string,name:string,cleanup:bool}
 */
function downloadDriveFileToTemp(string $serviceAccountPath, string $fileId): array
{
    if (!class_exists('Google\\Client')) {
        throw new RuntimeException('Google API client is not available.');
    }
    if (!is_file($serviceAccountPath)) {
        throw new RuntimeException('Google service account JSON not found.');
    }

    $client = new \Google\Client();
    $client->setAuthConfig($serviceAccountPath);
    $client->addScope(\Google\Service\Drive::DRIVE_READONLY);
    $service = new \Google\Service\Drive($client);

    $meta = $service->files->get($fileId, ['fields' => 'name,fullFileExtension']);
    $ext = $meta->getFullFileExtension() ?: 'xlsx';
    $response = $service->files->get($fileId, ['alt' => 'media']);
    $content = $response->getBody()->getContents();

    $tmpPath = tempnam(sys_get_temp_dir(), 'gdrive_');
    if (!$tmpPath) {
        throw new RuntimeException('Unable to create temp file.');
    }
    file_put_contents($tmpPath, $content);

    return [
        'path' => $tmpPath,
        'ext' => strtolower($ext),
        'name' => (string) $meta->getName(),
        'cleanup' => true,
    ];
}

/**
 * Locate a location id based on a bin/location string.
 *
 * @return array{loc_id:?int,loc_label:string}
 */
function resolveLocationId(mysqli $conn, string $bin): array
{
    $bin = trim($bin);
    if ($bin === '') {
        return ['loc_id' => null, 'loc_label' => ''];
    }

    $parts = preg_split('/[\s\-\/]+/', $bin) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));

    $row = strtoupper((string) ($parts[0] ?? ''));
    $bay = (string) ($parts[1] ?? '');
    $lvl = (string) ($parts[2] ?? '');
    $side = (string) ($parts[3] ?? '');

    $query = function (string $sql, array $binds) use ($conn): array {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['id' => null, 'label' => ''];
        }
        $types = str_repeat('s', count($binds));
        $bindArgs = [$types];
        foreach ($binds as $idx => $value) {
            $bindArgs[] = &$binds[$idx];
        }
        if (!call_user_func_array([$stmt, 'bind_param'], $bindArgs)) {
            $stmt->close();
            return ['id' => null, 'label' => ''];
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $id = null;
        $label = '';
        if ($res instanceof mysqli_result) {
            if ($rec = $res->fetch_assoc()) {
                $id = (int) $rec['id'];
                $label = trim((string) ($rec['row_code'] ?? ''));
                if (!empty($rec['bay_num']) && !empty($rec['level_code']) && !empty($rec['side'])) {
                    $label = "{$rec['row_code']}-{$rec['bay_num']}-{$rec['level_code']}-{$rec['side']}";
                }
            }
            $res->free();
        }
        $stmt->close();
        return ['id' => $id, 'label' => $label];
    };

    if ($row && $bay !== '' && $lvl !== '' && $side !== '') {
        $out = $query(
            'SELECT id, row_code, bay_num, level_code, side FROM location WHERE row_code = ? AND CAST(bay_num AS UNSIGNED)=CAST(? AS UNSIGNED) AND level_code = ? AND side = ? LIMIT 1',
            [$row, $bay, $lvl, $side],
        );
        if ($out['id']) {
            return ['loc_id' => $out['id'], 'loc_label' => $out['label']];
        }
    }

    if ($row && $bay !== '' && $lvl !== '') {
        $out = $query(
            'SELECT id, row_code, bay_num, level_code, side FROM location WHERE row_code = ? AND CAST(bay_num AS UNSIGNED)=CAST(? AS UNSIGNED) AND level_code = ? LIMIT 1',
            [$row, $bay, $lvl],
        );
        if ($out['id']) {
            return ['loc_id' => $out['id'], 'loc_label' => $out['label']];
        }
    }

    if ($row && $bay !== '') {
        $out = $query(
            'SELECT id, row_code, bay_num, level_code, side FROM location WHERE row_code = ? AND CAST(bay_num AS UNSIGNED)=CAST(? AS UNSIGNED) LIMIT 1',
            [$row, $bay],
        );
        if ($out['id']) {
            return ['loc_id' => $out['id'], 'loc_label' => $out['label']];
        }
    }

    $out = $query(
        'SELECT id, row_code, bay_num, level_code, side FROM location WHERE row_code = ? LIMIT 1',
        [$row],
    );

    return ['loc_id' => $out['id'], 'loc_label' => $out['label']];
}

/**
 * Normalize a location label for comparison.
 */
function normalizeLocationLabel(string $label): string
{
    $label = strtoupper(trim($label));
    $label = preg_replace('/[^A-Z0-9]+/', '-', $label) ?? '';
    return trim($label, '-');
}

/**
 * Build a display label from a location row.
 */
function buildLocationLabel(array $row): string
{
    if (function_exists('getLocationDisplayLabel')) {
        return getLocationDisplayLabel($row);
    }
    $rowCode = (string) ($row['row_code'] ?? '');
    $bay = (string) ($row['bay_num'] ?? '');
    $lvl = (string) ($row['level_code'] ?? '');
    $side = (string) ($row['side'] ?? '');
    if ($rowCode !== '' && $bay !== '' && $lvl !== '' && $side !== '') {
        return "{$rowCode}-{$bay}-{$lvl}-{$side}";
    }
    return $rowCode;
}

/**
 * Fetch database locations for a list of SKUs.
 *
 * @return array<string, array{locations: array<int, array{loc_id:int,loc_label:string}>, shelf: ?string, on_hand: int}>
 */
function fetchSkuLocations(mysqli $conn, array $skus): array
{
    $skus = array_values(array_unique(array_filter(array_map('trim', $skus), static fn($v) => $v !== '')));
    if ($skus === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $sql = "
        SELECT s.sku_num, s.shelf, l.id AS loc_id, l.row_code, l.bay_num, l.level_code, l.side,
               COALESCE(i.quantity, 0) AS qty
        FROM sku s
        LEFT JOIN inventory i ON i.sku_id = s.id
        LEFT JOIN pallet p ON i.pallet_id = p.id
        LEFT JOIN location l ON p.loc_id = l.id
        WHERE s.sku_num IN ({$placeholders})
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $types = str_repeat('s', count($skus));
    $bindArgs = [$types];
    foreach ($skus as $idx => $sku) {
        $bindArgs[] = &$skus[$idx];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $bindArgs)) {
        $stmt->close();
        return [];
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $sku = (string) ($row['sku_num'] ?? '');
            if ($sku === '') {
                continue;
            }
            $map[$sku] = $map[$sku] ?? ['locations' => [], 'shelf' => null, 'on_hand' => 0];
            if ($map[$sku]['shelf'] === null && isset($row['shelf'])) {
                $map[$sku]['shelf'] = (string) $row['shelf'];
            }
            $locId = (int) ($row['loc_id'] ?? 0);
            if ($locId > 0) {
                $map[$sku]['locations'][] = [
                    'loc_id' => $locId,
                    'loc_label' => buildLocationLabel($row),
                ];
            }
            $map[$sku]['on_hand'] += (int) ($row['qty'] ?? 0);
        }
        $res->free();
    }
    $stmt->close();

    return $map;
}

/**
 * Enrich items with DB locations and match status.
 */
function applyDbLocationMatches(mysqli $conn, array $items): array
{
    $skuList = array_map(static fn($item) => (string) ($item['sku'] ?? ''), $items);
    $dbMap = fetchSkuLocations($conn, $skuList);

    foreach ($items as &$item) {
        $sku = (string) ($item['sku'] ?? '');
        $dbEntry = $dbMap[$sku] ?? ['locations' => [], 'shelf' => null, 'on_hand' => 0];
        $dbLocs = $dbEntry['locations'] ?? [];
        $item['db_locations'] = $dbLocs;
        $item['db_location_labels'] = array_map(static fn($loc) => (string) ($loc['loc_label'] ?? ''), $dbLocs);
        $item['db_location_ids'] = array_map(static fn($loc) => (int) ($loc['loc_id'] ?? 0), $dbLocs);
        $item['shelf_location'] = $dbEntry['shelf'] ?? null;
        // $item['inventory_qty'] = (int) ($dbEntry['on_hand'] ?? 0);

        $bin = (string) ($item['bin_code'] ?? '');
        $binNorm = normalizeLocationLabel($bin);
        $matches = false;
        if ($binNorm !== '') {
            foreach ($item['db_location_labels'] as $label) {
                if ($binNorm === normalizeLocationLabel((string) $label)) {
                    $matches = true;
                    break;
                }
            }
        }
        $item['db_match'] = $matches;
    }
    unset($item);

    return $items;
}

/**
 * Load a saved pick order from the database and shape it for the UI.
 */
function loadShippingLookupForSalesOrder(mysqli $conn, string $salesOrderNumber): array
{
    $salesOrderNumber = trim($salesOrderNumber);
    if ($salesOrderNumber === '') {
        return [];
    }

    $candidates = array_values(array_unique(array_filter([
        $salesOrderNumber,
        preg_match('/^so/i', $salesOrderNumber) ? preg_replace('/^so/i', '', $salesOrderNumber) : 'SO' . $salesOrderNumber,
        strtoupper($salesOrderNumber),
    ], static fn(string $value): bool => trim($value) !== '')));

    $normalizeSo = static function (string $value): string {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
    };
    $normalizedCandidates = array_values(array_unique(array_filter(array_map(
        $normalizeSo,
        $candidates,
    ), static fn(string $value): bool => $value !== '')));

    $sql = '
        SELECT
            document_type,
            document_no AS sales_order_number,
            ship_to_address,
            ship_to_address_2,
            ship_to_city,
            ship_to_state,
            ship_to_zip_code,
            ship_to_country_region_code
        FROM sales_headers
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(UPPER(document_no), " ", ""), "-", ""), "_", ""), ".", "") = ?
        LIMIT 1
    ';

    foreach ($normalizedCandidates as $candidate) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (is_array($row)) {
            $address1 = (string) ($row['ship_to_address'] ?? '');
            $address2 = (string) ($row['ship_to_address_2'] ?? '');
            $city = (string) ($row['ship_to_city'] ?? '');
            $state = (string) ($row['ship_to_state'] ?? '');
            $zip = (string) ($row['ship_to_zip_code'] ?? '');
            $country = (string) ($row['ship_to_country_region_code'] ?? '');

            return [
                'document_type' => (string) ($row['document_type'] ?? ''),
                'sales_order_number' => (string) ($row['sales_order_number'] ?? $candidate),
                'ship_to_address' => $address1,
                'ship_to_address_1' => $address1,
                'ship_to_address_2' => $address2,
                'ship_to_city' => $city,
                'ship_to_state' => $state,
                'ship_to_zip_code' => $zip,
                'ship_to_country_region_code' => $country,
                'ship_to_country_code' => $country,
            ];
        }
    }

    return [];
}

/**
 * Format shipping lookup fields into a multiline display string.
 */
function formatShippingLookupText(array $shipping): string
{
    $lines = [];

    $address1 = trim((string) ($shipping['ship_to_address'] ?? $shipping['ship_to_address_1'] ?? ''));
    $address2 = trim((string) ($shipping['ship_to_address_2'] ?? ''));
    if ($address1 !== '' || $address2 !== '') {
        $lines[] = $address1;
        if ($address2 !== '') {
            $lines[] = $address2;
        }
    }

    $city = trim((string) ($shipping['ship_to_city'] ?? ''));
    $state = trim((string) ($shipping['ship_to_state'] ?? ''));
    $zip = trim((string) ($shipping['ship_to_zip_code'] ?? ''));
    $country = trim((string) ($shipping['ship_to_country_region_code'] ?? $shipping['ship_to_country_code'] ?? ''));
    $cityLine = trim(implode(' ', array_filter([$city, $state, $zip], static fn(string $v): bool => $v !== '')));
    if ($cityLine !== '') {
        $lines[] = $cityLine;
    }
    if ($country !== '') {
        $lines[] = $country;
    }

    return trim(implode("\n", array_filter($lines, static fn(string $v): bool => $v !== '')));
}

function loadPickOrderFromDb(mysqli $conn, int $orderId = 0, ?string $orderNumber = null): array
{
    $hasShortShipQty = pickEnsureShortShipQtyColumn($conn);

    $order = null;
    if ($orderId > 0) {
        $stmt = $conn->prepare('SELECT id, order_number, status, source_file, assigned_user_id, COALESCE(is_priority, 0) AS is_priority FROM pick_orders WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    }
    if (!$order && $orderNumber !== null && trim($orderNumber) !== '') {
        $orderNum = trim($orderNumber);
        $stmt = $conn->prepare('SELECT id, order_number, status, source_file, assigned_user_id, COALESCE(is_priority, 0) AS is_priority FROM pick_orders WHERE order_number = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $orderNum);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    }

    if (!$order) {
        return ['error' => 'Order not found.'];
    }

    $assignedUserId = (int) ($order['assigned_user_id'] ?? 0);

    $items = [];
    $hasPackerVerify = true;
    $hasShortShip = pickTableColumnExists($conn, 'pick_items', 'is_short_ship');
    $hasBogo = pickTableColumnExists($conn, 'pick_items', 'is_bogo');
    $packerSelect = ', pi.qty_verified_packer';
    $shortShipSelect = $hasShortShip ? ', COALESCE(pi.is_short_ship, 0) AS is_short_ship' : ', 0 AS is_short_ship';
    $shortShipQtySelect = $hasShortShipQty
        ? ', ' . pickResolvedShortShipQtyExpr('pi') . ' AS short_ship_qty'
        : ', 0 AS short_ship_qty';
    $bogoSelect = $hasBogo ? ', COALESCE(pi.is_bogo, 0) AS is_bogo' : ', 0 AS is_bogo';
    $stmt = $conn->prepare("
        SELECT pi.id AS pick_item_id, pi.sku_id, pi.item_no, pi.item_no AS raw_item_no, pi.description,
               pi.qty_ordered, pi.qty_verified{$packerSelect}{$shortShipSelect}{$shortShipQtySelect}{$bogoSelect}, s.sku_num
        FROM pick_items pi
        LEFT JOIN sku s ON s.id = pi.sku_id
        WHERE pi.order_id = ?
        ORDER BY pi.id
    ");
    if ($stmt) {
        $orderPk = (int) $order['id'];
        $stmt->bind_param('i', $orderPk);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $sku = (string) ($row['sku_num'] ?? '');
                if ($sku === '') {
                    $sku = (string) ($row['item_no'] ?? '');
                }
                $skuId = (int) ($row['sku_id'] ?? 0);
                $items[] = [
                    'sku_id' => $skuId,
                    'sku' => $sku,
                    'item_no' => (string) ($row['item_no'] ?? $sku),
                    'upc' => pickResolveSkuUpc($conn, $skuId, $sku),
                    'description' => (string) ($row['description'] ?? ''),
                    'quantity' => (int) ($row['qty_ordered'] ?? 0),
                    'qty_verified' => (int) ($row['qty_verified'] ?? 0),
                    'qty_verified_picker' => (int) ($row['qty_verified'] ?? 0),
                    'qty_verified_packer' => (int) ($row['qty_verified_packer'] ?? 0),
                    'is_short_ship' => (int) ($row['is_short_ship'] ?? 0) === 1,
                    'short_ship_qty' => (int) ($row['short_ship_qty'] ?? 0),
                    'is_bogo' => (int) ($row['is_bogo'] ?? 0) === 1,
                    'pick_item_id' => (int) ($row['pick_item_id'] ?? 0),
                ];
            }
            $res->free();
        }
        $stmt->close();
    }

    $items = applyDbLocationMatches($conn, $items);
    usort($items, 'sortByShelfThenSku');

    $sourceName = (string) ($order['source_file'] ?? '');
    if ($sourceName !== '' && strpos($sourceName, '.') === false && strlen($sourceName) >= 20) {
        $sourceName = 'Drive Import';
    }
    if ($sourceName === '') {
        $sourceName = 'Database Import';
    }
    $assignedUserLabel = 'Unassigned';
    $shipping = loadShippingLookupForSalesOrder($conn, (string) ($order['order_number'] ?? ''));
    if ($assignedUserId > 0) {
        foreach (pickFetchAssignableUsers($conn) as $u) {
            if ((int) ($u['id'] ?? 0) === $assignedUserId) {
                $assignedUserLabel = (string) ($u['label'] ?? $u['username'] ?? ('User #' . $assignedUserId));
                break;
            }
        }
        if ($assignedUserLabel === 'Unassigned') {
            $assignedUserLabel = 'User #' . $assignedUserId;
        }
    }

    return [
        'pick_order_number' => $order['order_number'] ?? '',
        'sales_order_number' => $order['order_number'] ?? '',
        'order_id' => $order['order_number'] ?? '',
        'db_order_id' => (int) ($order['id'] ?? 0),
        'assigned_user_id' => $assignedUserId,
        'assigned_user_label' => $assignedUserLabel,
        'status' => $order['status'] ?? null,
        'is_priority' => (int) ($order['is_priority'] ?? 0),
        'notes' => pickFetchOrderNotes($conn, (int) ($order['id'] ?? 0)),
        'has_packer_verify' => true,
        'short_ship_count' => count(array_filter($items, static fn(array $item): bool => !empty($item['is_short_ship']))),
        'source_name' => $sourceName,
        'shipping' => $shipping,
        'ship_info_text' => formatShippingLookupText($shipping),
        'items' => $items,
        'headers' => [],
        'column_map' => [],
    ];
}

/**
 * Build search variants for a sales/pick order number.
 *
 * @return array<int,string>
 */
function pickBuildOrderNumberVariants(string $orderNumber): array
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '') {
        return [];
    }

    $variants = [$orderNumber];
    $normalized = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $orderNumber) ?? '');
    if ($normalized !== '' && !in_array($normalized, $variants, true)) {
        $variants[] = $normalized;
    }

    if (preg_match('/^SO(\d+)$/i', $orderNumber, $m)) {
        if (!in_array($m[1], $variants, true)) {
            $variants[] = $m[1];
        }
    } elseif (ctype_digit($orderNumber)) {
        $prefixed = 'SO' . $orderNumber;
        if (!in_array($prefixed, $variants, true)) {
            $variants[] = $prefixed;
        }
    }

    return array_values(array_unique(array_filter($variants, static fn(string $value): bool => trim($value) !== '')));
}

/**
 * Find an existing pick order by order number, including common SO# variants.
 */
function pickFindOrderByNumber(mysqli $conn, string $orderNumber): ?array
{
    foreach (pickBuildOrderNumberVariants($orderNumber) as $candidate) {
        $stmt = $conn->prepare('
            SELECT id, order_number, status, source_file, assigned_user_id
            FROM pick_orders
            WHERE order_number = ?
            LIMIT 1
        ');
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (is_array($row)) {
            return $row;
        }
    }

    return null;
}

/**
 * Persist parsed upload data into pick_orders / pick_items.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function pickImportParsedUpload(mysqli $conn, array $data): array
{
    $pickOrderNumber = trim((string) ($data['pick_order_number'] ?? ''));
    $salesOrderNumber = trim((string) ($data['sales_order_number'] ?? ''));
    $orderNumber = $salesOrderNumber !== '' ? $salesOrderNumber : $pickOrderNumber;
    if ($orderNumber === '') {
        throw new RuntimeException('No sales order number was found in the uploaded file.');
    }

    pickDebugLog('parsed_upload_begin', [
        'order_number' => $orderNumber,
        'pick_order_number' => $pickOrderNumber,
        'sales_order_number' => $salesOrderNumber,
        'item_count' => is_array($data['items'] ?? null) ? count($data['items']) : 0,
        'source_name' => (string) ($data['source_name'] ?? ''),
    ]);

    $existing = pickFindOrderByNumber($conn, $orderNumber);
    if (is_array($existing)) {
        $existingId = (int) ($existing['id'] ?? 0);
        $existingStatus = (string) ($existing['status'] ?? '');
        $existingSourceFile = (string) ($existing['source_file'] ?? '');
        $existingOrderNumber = (string) ($existing['order_number'] ?? $orderNumber);
        $duplicateContext = [];
        $duplicateContext['order_number'] = $orderNumber;
        $duplicateContext['order_id'] = $existingId;
        $duplicateContext['status'] = $existingStatus;
        $duplicateContext['source_file'] = $existingSourceFile;
        pickDebugLog('parsed_upload_duplicate', $duplicateContext);
        if ($existingId > 0) {
            $bogoUpdated = pickApplyBogoFlags($conn, $existingId, $data);
            pickDebugLog('parsed_upload_duplicate_bogo_flags_applied', [
                'order_number' => $orderNumber,
                'order_id' => $existingId,
                'updated_rows' => $bogoUpdated,
                'bogo_items' => array_keys(pickExtractBogoItemKeys($data)),
            ]);
        }
        return [
            'ok' => false,
            'duplicate' => true,
            'order_id' => $existingId,
            'order_number' => $existingOrderNumber,
        ];
    }

    $items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
    if ($items === []) {
        throw new RuntimeException('No line items were found in the uploaded file.');
    }

    ensurePickImportUniqueness($conn);

    $sourceFile = trim((string) ($data['source_name'] ?? ''));
    $sourceFile = $sourceFile !== '' ? mb_substr($sourceFile, 0, 255) : null;
    $status = 'PENDING';
    $hasShortShip = pickTableColumnExists($conn, 'pick_items', 'is_short_ship');
    $hasPackerVerify = pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer');




    $skuLookupStmt = $conn->prepare('SELECT id, `desc` FROM sku WHERE sku_num = ? LIMIT 1');
    if (!$skuLookupStmt) {
        throw new RuntimeException('Unable to prepare SKU lookup for import.');
    }

    $itemStmtWithSku = null;
    $itemStmtWithoutSku = null;

    $itemInsertSqlWithSku = <<<SQL
        INSERT INTO pick_items
            (order_id, source_line_no, sku_id, item_no, description, qty_ordered, qty_verified, line_hash
            %SHORT_SHIP%
            %PACKER_VERIFY%
            )
        VALUES
            (?, ?, ?, ?, ?, ?, 0, ?
            %SHORT_SHIP_VALUES%
            %PACKER_VERIFY_VALUES%
            )
        ON DUPLICATE KEY UPDATE
            sku_id = COALESCE(pick_items.sku_id, VALUES(sku_id)),
            description = CASE
                WHEN pick_items.description IS NULL
                  OR pick_items.description = ''
                  OR pick_items.description = '0'
                THEN VALUES(description)
                ELSE pick_items.description
            END
SQL;
    $itemInsertSqlWithoutSku = <<<SQL
        INSERT INTO pick_items
            (order_id, source_line_no, sku_id, item_no, description, qty_ordered, qty_verified, line_hash
            %SHORT_SHIP%
            %PACKER_VERIFY%
            )
        VALUES
            (?, ?, NULL, ?, ?, ?, 0, ?
            %SHORT_SHIP_VALUES%
            %PACKER_VERIFY_VALUES%
            )
        ON DUPLICATE KEY UPDATE
            description = CASE
                WHEN pick_items.description IS NULL
                  OR pick_items.description = ''
                  OR pick_items.description = '0'
                THEN VALUES(description)
                ELSE pick_items.description
            END
SQL;
    $itemInsertSqlWithSku = str_replace(
        ['%SHORT_SHIP%', '%PACKER_VERIFY%', '%SHORT_SHIP_VALUES%', '%PACKER_VERIFY_VALUES%'],
        [
            $hasShortShip ? ', is_short_ship' : '',
            $hasPackerVerify ? ', qty_verified_packer' : '',
            $hasShortShip ? ', ?' : '',
            $hasPackerVerify ? ', ?' : '',
        ],
        $itemInsertSqlWithSku
    );
    $itemInsertSqlWithoutSku = str_replace(
        ['%SHORT_SHIP%', '%PACKER_VERIFY%', '%SHORT_SHIP_VALUES%', '%PACKER_VERIFY_VALUES%'],
        [
            $hasShortShip ? ', is_short_ship' : '',
            $hasPackerVerify ? ', qty_verified_packer' : '',
            $hasShortShip ? ', ?' : '',
            $hasPackerVerify ? ', ?' : '',
        ],
        $itemInsertSqlWithoutSku
    );
    $itemStmtWithSku = $conn->prepare($itemInsertSqlWithSku);
    if (!$itemStmtWithSku) {
        $skuLookupStmt->close();
        throw new RuntimeException('Unable to prepare pick item insert.');
    }
    $itemStmtWithoutSku = $conn->prepare($itemInsertSqlWithoutSku);
    if (!$itemStmtWithoutSku) {
        $itemStmtWithSku->close();
        $skuLookupStmt->close();
        throw new RuntimeException('Unable to prepare pick item insert.');
    }

    $conn->begin_transaction();
    try {
        $orderStmt = $conn->prepare('
            INSERT INTO pick_orders (order_number, status, source_file)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                source_file = VALUES(source_file)
        ');
        if (!$orderStmt) {
            throw new RuntimeException('Unable to prepare order insert.');
        }
        $orderStmt->bind_param('sss', $orderNumber, $status, $sourceFile);
        if (!$orderStmt->execute()) {
            $error = $orderStmt->error ?: $conn->error ?: 'Unable to insert pick order.';
            $orderStmt->close();
            throw new RuntimeException($error);
        }
        $orderStmt->close();
        $orderRow = pickFindOrderByNumber($conn, $orderNumber);
        $orderId = (int) ($orderRow['id'] ?? 0);
        if ($orderId <= 0) {
            throw new RuntimeException('Unable to create pick order.');
        }

        $importedCount = 0;
        $duplicateCount = 0;
        $lineNumber = 0;
        $seenItemQuantityVariants = [];
        $bogoItemQuantityOccurrences = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lineNumber++;
            $itemNoRaw = trim((string) ($item['item_no'] ?? $item['sku'] ?? ''));
            $sku = trim((string) ($item['sku'] ?? $itemNoRaw));
            $qty = (int) ($item['quantity'] ?? 0);
            $desc = trim((string) ($item['description'] ?? ''));
            $sourceLineNo = (int) ($item['source_line_no'] ?? $item['line'] ?? $lineNumber);
            if ($itemNoRaw === '' || $qty <= 0) {
                continue;
            }

            $itemNoKey = pickNormalizeItemNo($itemNoRaw);
            if ($itemNoKey === '') {
                continue;
            }
            $itemQuantityKey = $itemNoKey . '|' . $qty;
            if (isset($seenItemQuantityVariants[$itemQuantityKey])) {
                if (!empty($item['is_bogo'])) {
                    $bogoItemQuantityOccurrences[$itemQuantityKey] = (int) ($bogoItemQuantityOccurrences[$itemQuantityKey] ?? 1) + 1;
                } else {
                    pickDebugLog('parsed_upload_duplicate_item_no_skipped', [
                        'order_number' => $orderNumber,
                        'item_no' => $itemNoRaw,
                        'quantity' => $qty,
                        'row_number' => $lineNumber,
                        'source_name' => (string) ($data['source_name'] ?? ''),
                    ]);
                    continue;
                }
            }
            $seenItemQuantityVariants[$itemQuantityKey] = true;
            if (!empty($item['is_bogo'])) {
                $bogoItemQuantityOccurrences[$itemQuantityKey] = (int) ($bogoItemQuantityOccurrences[$itemQuantityKey] ?? 0) + 1;
            }

            $skuId = null;
            $skuLookupStmt->bind_param('s', $sku);
            $skuLookupStmt->execute();
            $skuResult = $skuLookupStmt->get_result();
            $skuRow = $skuResult ? $skuResult->fetch_assoc() : null;
            if ($skuResult instanceof mysqli_result) {
                $skuResult->free();
            }
            if (is_array($skuRow)) {
                $skuId = (int) ($skuRow['id'] ?? 0);
                if ($desc === '') {
                    $desc = trim((string) ($skuRow['desc'] ?? ''));
                }
            }
            if ($desc === '') {
                $desc = $sku;
            }

            $lineHash = md5($orderId . '|' . $lineNumber . '|' . $itemNoKey . '|' . $qty . '|' . microtime(true));
            $useSku = is_int($skuId) && $skuId > 0;
            $itemStmt = $useSku ? $itemStmtWithSku : $itemStmtWithoutSku;
            if ($hasShortShip && $hasPackerVerify) {
                $shortShip = 0;
                $packerVerified = 0;
                if ($useSku) {
                    $itemStmt->bind_param('iiissisii', $orderId, $sourceLineNo, $skuId, $itemNoRaw, $desc, $qty, $lineHash, $shortShip, $packerVerified);
                } else {
                    $itemStmt->bind_param('iissisii', $orderId, $sourceLineNo, $itemNoRaw, $desc, $qty, $lineHash, $shortShip, $packerVerified);
                }
            } elseif ($hasShortShip) {
                $shortShip = 0;
                if ($useSku) {
                    $itemStmt->bind_param('iiissisi', $orderId, $sourceLineNo, $skuId, $itemNoRaw, $desc, $qty, $lineHash, $shortShip);
                } else {
                    $itemStmt->bind_param('iissisi', $orderId, $sourceLineNo, $itemNoRaw, $desc, $qty, $lineHash, $shortShip);
                }
            } elseif ($hasPackerVerify) {
                $packerVerified = 0;
                if ($useSku) {
                    $itemStmt->bind_param('iiissisi', $orderId, $sourceLineNo, $skuId, $itemNoRaw, $desc, $qty, $lineHash, $packerVerified);
                } else {
                    $itemStmt->bind_param('iissisi', $orderId, $sourceLineNo, $itemNoRaw, $desc, $qty, $lineHash, $packerVerified);
                }
            } else {
                if ($useSku) {
                    $itemStmt->bind_param('iiissis', $orderId, $sourceLineNo, $skuId, $itemNoRaw, $desc, $qty, $lineHash);
                } else {
                    $itemStmt->bind_param('iissis', $orderId, $sourceLineNo, $itemNoRaw, $desc, $qty, $lineHash);
                }
            }
            if (!$itemStmt->execute()) {
                $error = $itemStmt->error ?: $conn->error ?: 'Unable to insert pick item.';
                throw new RuntimeException($error);
            }
            if ((int) $itemStmt->affected_rows === 1) {
                $importedCount++;
            } else {
                $duplicateCount++;
            }
        }

        if ($importedCount <= 0 && $duplicateCount <= 0) {
            throw new RuntimeException('No valid line items were found to import.');
        }

        $bogoUpdated = pickApplyBogoFlags($conn, $orderId, $data);

        pickDebugLog('parsed_upload_bogo_flags_applied', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'updated_rows' => $bogoUpdated,
            'bogo_items' => array_keys(pickExtractBogoItemKeys($data)),
        ]);




        $auditRows = is_array($data['audit_rows'] ?? null)
            ? array_values($data['audit_rows'])
            : [];

        if ($auditRows === []) {
            $auditRows = array_values(array_map(
                static function (array $item): array {
                    return [
                        'line_no' => $item['line_no'] ?? $item['line'] ?? '',
                        'source_line_no' => $item['source_line_no'] ?? '',
                        'item_no' => $item['item_no'] ?? $item['sku'] ?? '',
                        'description' => $item['description'] ?? '',
                        'quantity' => $item['quantity'] ?? 0,
                    ];
                },
                $items
            ));
        }

        $auditWritten = writePickImportAudit($conn, [
            'source_type' => 'session',
            'source_name' => (string) ($data['source_name'] ?? ''),
            'source_file' => (string) ($data['source_name'] ?? ''),
            'source_path' => '',
            'source_checksum' => (string) ($data['source_checksum'] ?? ''),

            'sales_order_numbers' => '|' . strtoupper(trim($orderNumber)) . '|',

            'worksheet_row_count' => count($items),
            'parsed_row_count' => count($items),
            'imported_row_count' => $importedCount,
            'duplicate_row_count' => $duplicateCount,
            'rejected_row_count' => 0,

            'integrity_match' => true,

            'details_json' => [
                'order_number' => $orderNumber,
                'source_name' => (string) ($data['source_name'] ?? ''),
                'status' => 'manual_upload',
                'item_count_received' => count($items),
                'audit_rows_count' => count($auditRows),

                'source_snapshot' => [
                    'kind' => 'parsed_items',

                    'headers' => [
                        'Line No.',
                        'Source Line No.',
                        'Item No.',
                        'Description',
                        'Quantity',
                    ],

                    'rows' => array_values(array_map(
                        static function (array $row, int $index): array {
                            return [
                                'number' => $index + 1,
                                'cells' => [
                                    $row['line_no'] ?? '',
                                    $row['source_line_no'] ?? '',
                                    $row['item_no'] ?? '',
                                    $row['description'] ?? '',
                                    $row['quantity'] ?? '',
                                ],
                            ];
                        },
                        $auditRows,
                        array_keys($auditRows)
                    )),
                ],
            ],
        ]);

        if (!$auditWritten) {
            error_log(
                '[PICK AUDIT FAILED] manual upload order=' . $orderNumber
            );
        }

        $conn->commit();


        $itemStmtWithSku->close();
        $itemStmtWithoutSku->close();
        $skuLookupStmt->close();
        pickDebugLog('parsed_upload_complete', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'imported_count' => $importedCount,
            'duplicate_count' => $duplicateCount,
            'source_file' => $sourceFile,
        ]);

        return [
            'ok' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'item_count' => $importedCount,
            'duplicate_count' => $duplicateCount,
        ];
    } catch (Throwable $e) {
        $conn->rollback();

        pickDebugLog('parsed_upload_failed', [
            'order_number' => $orderNumber,
            'error' => $e->getMessage(),
        ]);

        if ($itemStmtWithSku instanceof mysqli_stmt) {
            $itemStmtWithSku->close();
        }

        if ($itemStmtWithoutSku instanceof mysqli_stmt) {
            $itemStmtWithoutSku->close();
        }

        if ($skuLookupStmt instanceof mysqli_stmt) {
            $skuLookupStmt->close();
        }

        throw $e;
    }

}

/**
 * Parse spreadsheet rows into structured, sorted items.
 */
function parsePickSheetRows(mysqli $conn, array $rows, string $sourceName = ''): array
{
    $originalRows = $rows;
    $expandedRows = expandPdfTextRows($rows);
    $forceFixed = $GLOBALS['FORCE_FIXED_COLS'] ?? false;
    $headerIndex = -1;
    $bestScore = 0;

    $isLikelyHeaderRow = static function (array $row): bool {
        $aliases = [
            'sku',
            'item',
            'item no',
            'item number',
            'item #',
            'description',
            'desc',
            'qty',
            'quantity',
            'qty ordered',
            'order qty',
            'pick qty',
            'bin',
            'bin location',
            'location',
            'sales order',
            'sales order #',
            'source no',
            'source no.',
            'source number',
            'source #',
            'source',
        ];
        $aliasNorms = array_values(array_unique(array_map(static fn($v) => normalizeHeader($v), $aliases)));
        $rowText = normalizeHeader(implode(' ', array_map('strval', $row)));
        if (
            str_contains($rowText, 'item no') &&
            str_contains($rowText, 'description') &&
            (str_contains($rowText, 'bincode') || str_contains($rowText, 'bin code')) &&
            (str_contains($rowText, 'qty') || str_contains($rowText, 'quantity'))
        ) {
            return true;
        }
        $matches = 0;
        foreach ($row as $cell) {
            $norm = normalizeHeader((string) $cell);
            if ($norm === '') {
                continue;
            }
            foreach ($aliasNorms as $aliasNorm) {
                if ($aliasNorm === '') {
                    continue;
                }
                if ($norm === $aliasNorm || str_contains($norm, $aliasNorm)) {
                    if ($aliasNorm === 'source no' || $aliasNorm === 'source no.' || $aliasNorm === 'source') {
                        return true;
                    }
                    $matches++;
                    break;
                }
            }
        }
        return $matches >= 2;
    };

    if ($forceFixed) {
        $scanMax = min(80, count($originalRows));
        for ($i = 0; $i < $scanMax; $i++) {
            if ($isLikelyHeaderRow($originalRows[$i] ?? [])) {
                $headerIndex = $i;
                break;
            }
        }
    }

    if (!$forceFixed) {
        foreach ($originalRows as $idx => $row) {
            $score = 0;
            foreach ($row as $cell) {
                $norm = normalizeHeader((string) $cell);
                if ($norm === '') {
                    continue;
                }
                if (str_contains($norm, 'sku') || $norm === 'item' || str_contains($norm, 'item number')) {
                    $score++;
                }
                if (str_contains($norm, 'qty') || str_contains($norm, 'quantity')) {
                    $score++;
                }
                if (str_contains($norm, 'bin') || str_contains($norm, 'location') || str_contains($norm, 'loc')) {
                    $score++;
                }
                if (str_contains($norm, 'desc')) {
                    $score++;
                }
            }
            if ($score > $bestScore && $score >= 2) {
                $bestScore = $score;
                $headerIndex = $idx;
            }
        }
    }

    if ($headerIndex < 0) {
        // Retry header detection on expanded rows (PDF conversions).
        foreach ($expandedRows as $idx => $row) {
            if ($isLikelyHeaderRow($row)) {
                $headerIndex = $idx;
                break;
            }
        }
    }

    if ($headerIndex < 0 && !$forceFixed) {
        return ['error' => 'Unable to detect a header row. Please ensure the file has column headers.'];
    }

    $headers = [];
    $firstDataRow = [];
    $columnMap = [];
    $seedFirstRow = null;

    if ($headerIndex >= 0 && isset($originalRows[$headerIndex])) {
        $hdr = $originalRows[$headerIndex];
        $firstRow = array_fill(0, count($hdr), '');
        foreach ($hdr as $idx => $headerCell) {
            $parts = preg_split('/\\R/', (string) $headerCell) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));
            if (!empty($parts)) {
                $hdr[$idx] = $parts[0];
                if (isset($parts[1])) {
                    $firstRow[$idx] = $parts[1];
                }
            }
        }
        $headers = $hdr;
        $columnMap = detectColumnMap($hdr);
        if (!empty($firstRow) && array_filter($firstRow, static fn($v) => trim((string) $v) !== '') !== []) {
            $seedFirstRow = $firstRow;
        }
    }

    $isHeaderRow = static function (array $row): bool {
        $rowText = normalizeHeader(implode(' ', array_map('strval', $row)));
        return str_contains($rowText, 'item no')
            || str_contains($rowText, 'item number')
            || str_contains($rowText, 'description')
            || str_contains($rowText, 'bincode')
            || str_contains($rowText, 'bin code')
            || str_contains($rowText, 'qty');
    };

    $items = [];
    $auditRows = [];
    $line = 0;
    $seen = [];
    $duplicateCounts = [];
    $rawQuantityCounts = [];
    // item key => Source Line No. => rows kept on that line (Takes, or Take/Place pairs).
    $sourceLineRowCounts = [];
    $duplicateAlerts = [];
    $pickOrder = null;
    $salesOrder = null;
    // Look for Sales Order # in the pre-header rows (common in PDF exports).
    if ($salesOrder === null && $headerIndex > 0) {
        $scanRows = array_slice($rows, 0, $headerIndex);
        foreach ($scanRows as $row) {
            $cellCount = count($row);
            $rowText = implode(' ', array_map('strval', $row));
            for ($i = 0; $i < $cellCount; $i++) {
                $cellStr = trim((string) ($row[$i] ?? ''));
                if ($cellStr === '') {
                    continue;
                }
                if (preg_match('/sales\\s*order\\s*#?\\s*[:\\-]?\\s*([a-z0-9\\-]+)/i', $cellStr, $m)) {
                    $salesOrder = (string) $m[1];
                    break 2;
                }
                if (preg_match('/sales\\s*order\\s*#?\\s*[:\\-]?$/i', $cellStr)) {
                    for ($j = $i + 1; $j < $cellCount; $j++) {
                        $next = trim((string) ($row[$j] ?? ''));
                        if ($next !== '') {
                            $salesOrder = $next;
                            break 3;
                        }
                    }
                }
            }
            if ($salesOrder === null && preg_match('/\\bSO\\d{3,}\\b/i', $rowText, $m) && preg_match('/sales\\s*order/i', $rowText)) {
                $salesOrder = (string) $m[0];
                break;
            }
        }
    }

    $dataRows = $expandedRows;
    if ($seedFirstRow !== null) {
        array_unshift($dataRows, $seedFirstRow);
    }
    // Fallback: capture Sales Order from any row (common footer line like SO29993).
    if ($salesOrder === null) {
        foreach ($dataRows as $row) {
            foreach ($row as $cell) {
                $cellStr = trim((string) $cell);
                if (preg_match('/^SO\\d+$/i', $cellStr)) {
                    $salesOrder = $cellStr;
                    break 2;
                }
            }
        }
    }
    $debugSamples = [];
    $foundHeader = !empty($columnMap);
    foreach ($dataRows as $rowIndex => $row) {
        if ($isHeaderRow($row)) {
            $hdr = $row;
            $firstRow = array_fill(0, count($hdr), '');
            foreach ($hdr as $idx => $headerCell) {
                $parts = preg_split('/\\R/', (string) $headerCell) ?: [];
                $parts = array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));
                if (!empty($parts)) {
                    $hdr[$idx] = $parts[0];
                    if (isset($parts[1])) {
                        $firstRow[$idx] = $parts[1];
                    }
                }
            }
            $columnMap = detectColumnMap($hdr);
            $headers = $hdr;
            $foundHeader = true;
            if (!empty($firstRow) && array_filter($firstRow, static fn($v) => trim((string) $v) !== '') !== []) {
                $row = $firstRow;
            } else {
                continue;
            }
        }
        if (!$foundHeader) {
            continue;
        }

        $lineNoIdx = $columnMap['line_no'] ?? null;
        $sourceLineNoIdx = $columnMap['source_line_no'] ?? null;
        $actionTypeIdx = $columnMap['action_type'] ?? null;
        $skuIdx = $columnMap['sku'] ?? null;
        $descIdx = $columnMap['description'] ?? null;
        $qtyIdx = $columnMap['quantity'] ?? null;
        $binIdx = $columnMap['bin'] ?? null;


        if ($skuIdx === null) {
            continue;
        }

        $descIdxLocal = $descIdx ?? findDescriptionIndex($row, $skuIdx);
        $qtyIdxLocal = $qtyIdx ?? findQuantityIndex($row, $skuIdx);
        $forceQtyScan = false;
        if ($qtyIdxLocal === null) {
            // Allow PDF rows that embed qty in the SKU cell to be parsed via regex scan.
            $qtyIdxLocal = $skuIdx;
            $forceQtyScan = true;
        } else {
            $qtyCell = trim((string) ($row[$qtyIdxLocal] ?? ''));
            $qtyCellNorm = strtoupper(preg_replace('/[^A-Z0-9]+/', '', $qtyCell) ?? '');
            if ($qtyCellNorm !== '' && looksLikeSku($qtyCellNorm)) {
                $qtyIdxLocal = $skuIdx;
                $forceQtyScan = true;
            }
        }
        if ($descIdxLocal === null) {
            $descIdxLocal = $skuIdx + 1;
        }

        $maxIdx = max($skuIdx, $descIdxLocal, $qtyIdxLocal);
        if (count($row) <= $maxIdx) {
            // Attempt to align compact PDF rows into expected columns.
            $parts = array_values(array_filter(array_map('trim', $row), static fn($v) => $v !== ''));
            if (count($parts) >= 4) {
                $aligned = array_fill(0, $maxIdx + 1, '');
                $aligned[$skuIdx] = $parts[0];
                $aligned[$qtyIdxLocal] = $parts[count($parts) - 1];
                $aligned[($binIdx ?? $maxIdx)] = $parts[count($parts) - 2] ?? '';
                // Description is everything between SKU (and optional barcode) and bin/qty.
                $descStart = 1;
                if (isset($parts[1]) && preg_match('/^\\*?[A-Z]{1,}[0-9]{4,}(?:-[A-Z0-9]+)*\\*?$/i', $parts[1])) {
                    $descStart = 2;
                }
                $descParts = array_slice($parts, $descStart, max(0, count($parts) - ($descStart + 2)));
                if ($descParts === [] && count($parts) >= 3) {
                    $descParts = [$parts[$descStart] ?? ''];
                }
                $aligned[$descIdxLocal] = trim(implode(' ', $descParts));
                $row = $aligned;
            } else {
                $row = array_pad($row, $maxIdx + 1, '');
            }
        }
        $skuCell = (string) ($row[$skuIdx] ?? '');

        $sku = trim($skuCell);
        $sku = trim($sku, "* \t\n\r\0\x0B");

        if ($sku === '') {
            continue;
        }

        $sku = strtoupper($sku);

        $desc = trim((string) ($row[$descIdxLocal ?? $skuIdx] ?? ''));
        if ($desc === '') {
            $nextIdx = ($descIdxLocal ?? $skuIdx) + 1;
            $desc = trim((string) ($row[$nextIdx] ?? ''));
        }
        if ($desc === '') {
            $nextIdx = ($descIdxLocal ?? $skuIdx) + 2;
            $desc = trim((string) ($row[$nextIdx] ?? ''));
        }
        $qtyRaw = $forceQtyScan ? '' : (string) ($row[$qtyIdxLocal] ?? '');
        $qty = $forceQtyScan ? 0 : (parseQuantityCell($qtyRaw) ?? 0);

        $bin = $binIdx !== null ? trim((string) ($row[$binIdx] ?? '')) : '';
        $binQtyPattern = '/\\b([A-Z]{1,3}\\d{2}-\\d{2}-\\d{2}|PUT-\\d+|[A-Z]{1,3}\\d{2}-\\d{2})\\b\\s+([0-9]+(?:\\.[0-9]+)?)/i';

        // If bin/qty are missing, scan other cells for "BIN  QTY".
        if (($bin === '' || $qty === 0) && !empty($row)) {
            foreach ($row as $cell) {
                $cellStr = trim((string) $cell);
                if ($cellStr === '') {
                    continue;
                }
                if (preg_match($binQtyPattern, $cellStr, $m)) {
                    if ($bin === '') {
                        $bin = $m[1];
                    }
                    if ($qty === 0) {
                        $qty = parseQuantityCell($m[2]) ?? 0;
                    }
                    break;
                }
            }
        }

        if ($desc !== '' && preg_match($binQtyPattern, $desc, $m)) {
            if ($bin === '') {
                $bin = $m[1];
            }
            if ($qty === 0) {
                $qty = parseQuantityCell($m[2]) ?? 0;
            }
            $desc = trim(preg_replace($binQtyPattern, '', $desc, 1));
        }

        $descIsSkuEcho = (bool) preg_match('/^\\*?[A-Z]{1,}[0-9]{4,}(?:-[A-Z0-9]+)*\\*?$/i', trim((string) $desc));
        if (($forceQtyScan || $qty === 0 || $descIsSkuEcho) && $skuCell !== '') {
            $details = extractSkuLineDetails($skuCell, $sku);
            if ($details['desc'] !== '') {
                $desc = $details['desc'];
            } elseif ($descIsSkuEcho) {
                $desc = extractDescriptionFromSkuCell($skuCell, $sku);
            }
            if ($details['bin'] !== '' && $bin === '') {
                $bin = $details['bin'];
            }
            if ($details['qty'] > 0 && ($qty === 0 || $forceQtyScan)) {
                $qty = $details['qty'];
            }
        }

        // If description is empty, try to parse from the SKU cell line.
        if ($desc === '' && $skuCell !== '') {
            $desc = extractDescriptionFromSkuCell($skuCell, $sku);
        }

        // Strip PUT codes from descriptions (display should not include PUT-##).
        if ($desc !== '') {
            $desc = trim(preg_replace('/\\bPUT-\\d+\\b/i', '', $desc) ?? $desc);
            $desc = trim(preg_replace('/\\s{2,}/', ' ', $desc) ?? $desc);
        }

        // If description was actually a bin/qty cell, fall back to parsing from the SKU line.
        if ($desc === '' && $skuCell !== '') {
            $desc = extractDescriptionFromSkuCell($skuCell, $sku);
        }

        if ($pickOrder === null && isset($columnMap['pick_order'])) {
            $candidate = trim((string) ($row[$columnMap['pick_order']] ?? ''));
            if ($candidate !== '') {
                $pickOrder = $candidate;
            }
        }

        if ($salesOrder === null && isset($columnMap['sales_order'])) {
            $candidate = trim((string) ($row[$columnMap['sales_order']] ?? ''));
            if ($candidate !== '') {
                $salesOrder = $candidate;
            }
        }

        $skuUpper = strtoupper($sku);
        if (preg_match('/^(SO|WP)\\d+$/', $skuUpper)) {
            if ($salesOrder === null && str_starts_with($skuUpper, 'SO')) {
                $salesOrder = $skuUpper;
            }
            if ($pickOrder === null && str_starts_with($skuUpper, 'WP')) {
                $pickOrder = $skuUpper;
            }
            // Footer-only sales/pick order rows should never become line items.
            $descTrim = trim((string) $desc);
            $descNorm = strtoupper(preg_replace('/[^A-Z0-9]+/', '', $descTrim) ?? '');
            $skuNorm = preg_replace('/[^A-Z0-9]+/', '', $skuUpper) ?? $skuUpper;
            $binTrim = trim((string) $bin);
            if ($binTrim === '' && ($descTrim === '' || $descNorm === $skuNorm)) {
                continue;
            }
        }

        if ($sku === '' && $desc === '' && $bin === '' && $qty === 0) {
            continue;
        }
        // Capture footer-style Sales Order rows (e.g., "SO29993") and skip as line items.
        if (preg_match('/^SO\\d+$/i', $sku) && $desc === '' && $bin === '' && $qty === 0) {
            if ($salesOrder === null) {
                $salesOrder = $sku;
            }
            continue;
        }

        if (count($debugSamples) < 3) {
            $debugSamples[] = [
                'sku_idx' => $skuIdx,
                'desc_idx' => $descIdxLocal ?? $descIdx,
                'qty_idx' => $qtyIdxLocal ?? $qtyIdx,
                'row' => $row,
            ];
        }

        $itemNoKey = pickNormalizeItemNo($sku);
        if ($itemNoKey === '') {
            continue;
        }

        $sourceLineNo = $lineNoIdx !== null
            ? trim((string) ($row[$lineNoIdx] ?? ''))
            : '';

        $salesSourceLineNo = $sourceLineNoIdx !== null
            ? trim((string) ($row[$sourceLineNoIdx] ?? ''))
            : '';

        $auditRows[] = [
            'line_no' => $sourceLineNo,
            'source_line_no' => $salesSourceLineNo,
            'item_no' => $sku,
            'description' => $desc,
            'quantity' => $qty,
        ];

        // Place rows are the put-away half of a Take/Place pair; only Takes are picked.
        if ($actionTypeIdx !== null && strcasecmp(trim((string) ($row[$actionTypeIdx] ?? '')), 'Place') === 0) {
            continue;
        }

        if (!isset($duplicateCounts[$itemNoKey])) {
            $duplicateCounts[$itemNoKey] = 0;
        }
        $duplicateCounts[$itemNoKey]++;

        $itemQuantityKey = $itemNoKey . '|' . $qty;
        $rawQuantityCounts[$itemQuantityKey] = (int) ($rawQuantityCounts[$itemQuantityKey] ?? 0) + 1;

        if ($salesSourceLineNo !== '') {
            $sourceLineRowCounts[$itemNoKey][$salesSourceLineNo] = (int) ($sourceLineRowCounts[$itemNoKey][$salesSourceLineNo] ?? 0) + 1;
        }


        if (isset($seen[$itemQuantityKey])) {
            pickDebugLog('parse_pick_duplicate_item_no_skipped', [
                'source_name' => $sourceName,
                'row_number' => $rowIndex + 1,
                'item_no' => $sku,
                'quantity' => $qty,
                'duplicate_count' => $duplicateCounts[$itemNoKey],
            ]);

            if ($duplicateCounts[$itemNoKey] >= 3) {
                $duplicateAlerts[$itemNoKey] = [
                    'type' => 'duplicate_item_threshold_reached',
                    'item_no' => $sku,
                    'count' => $duplicateCounts[$itemNoKey],
                    'message' => "Item {$sku} appears {$duplicateCounts[$itemNoKey]} times on this order.",
                ];
                pickDebugLog('parse_pick_duplicate_item_threshold_reached', [
                    'source_name' => $sourceName,
                    'row_number' => $rowIndex + 1,
                    'item_no' => $sku,
                    'duplicate_count' => $duplicateCounts[$itemNoKey],
                ]);
            }

            continue;
        }
        $seen[$itemQuantityKey] = true;

        $locInfo = resolveLocationId($conn, $bin);

        $items[] = [
            'line' => ++$line,
            'sku' => $sku,
            'description' => $desc,
            'bin_code' => $bin,
            'quantity' => $qty,
            'loc_id' => $locInfo['loc_id'],
            'loc_label' => $locInfo['loc_label'],
        ];
    }

    // Rows per Take: with Action Type, Place rows were skipped above; without it,
    // each Take arrives paired with a Place row.
    $rowsPerTake = isset($columnMap['action_type']) ? 1 : 2;

    // BOGO = a Source Line with more than one Take (e.g. Take 1 + Take 3).
    // Quantities are not summed, so one Take of 3 is not BOGO. Files without
    // Source Line No. fall back to the old rule: the item appears 3+ times.
    $itemIsBogo = static function (string $itemKey) use ($sourceLineRowCounts, $duplicateCounts, $rowsPerTake): bool {
        $lineCounts = $sourceLineRowCounts[$itemKey] ?? [];
        if ($lineCounts === []) {
            return (int) ($duplicateCounts[$itemKey] ?? 0) >= 3;
        }
        foreach ($lineCounts as $rowCount) {
            if ((int) ceil($rowCount / $rowsPerTake) > 1) {
                return true;
            }
        }
        return false;
    };

    if (!empty($items)) {
        foreach ($items as &$item) {
            $itemKey = pickNormalizeItemNo((string) ($item['item_no'] ?? $item['sku'] ?? ''));
            $rawOccurrenceCount = (int) ($duplicateCounts[$itemKey] ?? 1);
            $item['raw_occurrence_count'] = $rawOccurrenceCount;
            $item['is_bogo'] = $itemIsBogo($itemKey);
        }
        unset($item);

        $skuHasQty = [];
        foreach ($items as $item) {
            $skuKey = (string) ($item['sku'] ?? '');
            if ($skuKey !== '' && (int) ($item['quantity'] ?? 0) > 0) {
                $skuHasQty[$skuKey] = true;
            }
        }
        $items = array_values(array_filter($items, static function ($item) use ($skuHasQty) {
            $skuKey = (string) ($item['sku'] ?? '');
            $qty = (int) ($item['quantity'] ?? 0);
            $desc = trim((string) ($item['description'] ?? ''));
            $bin = trim((string) ($item['bin_code'] ?? ''));
            if ($qty === 0 && $desc === '' && $bin === '' && isset($skuHasQty[$skuKey])) {
                return false;
            }
            return true;
        }));
        $line = 1;
        foreach ($items as &$item) {
            $item['line'] = $line++;
        }
        unset($item);

        // Preserve one database row per Take. Without Action Type the export
        // has Take/Place pairs: 2 raw rows become 1, 4 become 2, 6 become 3, etc.
        $currentQuantityCounts = [];
        foreach ($items as $item) {
            $itemKey = pickNormalizeItemNo((string) ($item['item_no'] ?? $item['sku'] ?? ''));
            $itemQty = (int) ($item['quantity'] ?? 0);
            if ($itemKey !== '') {
                $currentQuantityCounts[$itemKey . '|' . $itemQty] = ($currentQuantityCounts[$itemKey . '|' . $itemQty] ?? 0) + 1;
            }
        }
        foreach ($rawQuantityCounts as $itemQuantityKey => $rawCount) {
            $targetCount = (int) ceil((int) $rawCount / $rowsPerTake);
            $currentCount = (int) ($currentQuantityCounts[$itemQuantityKey] ?? 0);
            if ($targetCount <= $currentCount) {
                continue;
            }
            [$itemKey, $itemQuantity] = explode('|', $itemQuantityKey, 2);
            foreach ($items as $item) {
                $candidateKey = pickNormalizeItemNo((string) ($item['item_no'] ?? $item['sku'] ?? ''));
                if ($candidateKey !== $itemKey || (int) ($item['quantity'] ?? 0) !== (int) $itemQuantity) {
                    continue;
                }
                while ($currentCount < $targetCount) {
                    $copy = $item;
                    $copy['is_bogo'] = $itemIsBogo($itemKey);
                    $copy['raw_occurrence_count'] = (int) ($duplicateCounts[$itemKey] ?? $rawCount);
                    $items[] = $copy;
                    $currentCount++;
                }
                $currentQuantityCounts[$itemQuantityKey] = $currentCount;
                break;
            }
        }
        $line = 1;
        foreach ($items as &$item) {
            $item['line'] = $line++;
        }
        unset($item);
    }

    return [
        'items' => $items,

        'audit_rows' => $auditRows,

        'headers' => $headers,
        'column_map' => $columnMap,
        'row_debug' => $debugSamples,
        'worksheet_row_count' => count($originalRows),
        'expanded_row_count' => count($expandedRows),
        'parsed_row_count' => count($items),
        'raw_item_counts' => $duplicateCounts,
        // is_bogo on each item is authoritative (see $itemIsBogo above).
        'bogo_rule' => 'takes_per_source_line',
        'alerts' => array_values($duplicateAlerts),
        'duplicate_alerts' => array_values($duplicateAlerts),
        'pick_order_number' => $pickOrder,
        'sales_order_number' => $salesOrder,
    ];
}

/**
 * Expand rows that contain multi-line PDF text blobs into multiple rows.
 */
function expandPdfTextRows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $hasNewline = false;
        foreach ($row as $cell) {
            if (is_string($cell) && str_contains($cell, "\n")) {
                $hasNewline = true;
                break;
            }
        }
        if (!$hasNewline) {
            $out[] = $row;
            continue;
        }

        $blobLines = [];
        foreach ($row as $cell) {
            if (!is_string($cell)) {
                continue;
            }
            if (str_contains($cell, "\n")) {
                $blobLines = array_merge($blobLines, preg_split('/\\R/', $cell) ?: []);
                continue;
            }
            $cellTrim = trim($cell);
            if ($cellTrim === '') {
                continue;
            }
            // Keep non-newline cells that still contain SKU/header text.
            if (
                preg_match('/\\b[A-Z]{1,}[0-9]{4,}(?:-[A-Z0-9]+)*\\b/i', $cellTrim)
                || (stripos($cellTrim, 'item no') !== false && stripos($cellTrim, 'description') !== false)
            ) {
                $blobLines[] = $cellTrim;
            }
        }

        $expanded = false;
        $lines = array_values(array_filter(array_map('trim', $blobLines), static fn($v) => $v !== ''));
        // If a blob starts with address/header lines, drop everything before the first SKU.
        $firstSkuIdx = null;
        foreach ($lines as $idx => $line) {
            if (preg_match('/\\b[A-Z]{1,}[0-9]{4,}(?:-[A-Z0-9]+)*\\b/i', $line)) {
                $firstSkuIdx = $idx;
                break;
            }
        }
        if ($firstSkuIdx !== null && $firstSkuIdx > 0) {
            $lines = array_slice($lines, $firstSkuIdx);
        }
        $pendingSku = null;
        $splitBySku = static function (string $line): array {
            $pattern = '/\\b[A-Z]{1,}[0-9]{4,}(?:-[A-Z0-9]+)*\\b/i';
            if (!preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                return [$line];
            }
            $hits = $matches[0] ?? [];
            if (count($hits) <= 1) {
                return [$line];
            }
            $filtered = [];
            $len = strlen($line);
            foreach ($hits as $hit) {
                $pos = (int) $hit[1];
                $end = $pos + strlen((string) $hit[0]);
                $before = $pos > 0 ? $line[$pos - 1] : '';
                $after = $end < $len ? $line[$end] : '';
                // Ignore *SKU* barcode echoes when splitting.
                if ($before === '*' || $after === '*') {
                    continue;
                }
                $filtered[] = $hit;
            }
            if (count($filtered) <= 1) {
                return [$line];
            }
            $segments = [];
            $start = 0;
            for ($i = 1; $i < count($filtered); $i++) {
                $pos = (int) $filtered[$i][1];
                $seg = trim(substr($line, $start, $pos - $start));
                if ($seg !== '') {
                    $segments[] = $seg;
                }
                $start = $pos;
            }
            $tail = trim(substr($line, $start));
            if ($tail !== '') {
                $segments[] = $tail;
            }
            return $segments ?: [$line];
        };

        foreach ($lines as $line) {
            // Single-line header with multiple columns.
            if (preg_match('/item\\s*no/i', $line) && preg_match('/description/i', $line) && preg_match('/bin\\s*code|bincode/i', $line)) {
                $parts = preg_split('/\\s{2,}|\\t+/', $line) ?: [];
                $parts = array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));
                if ($parts) {
                    $out[] = $parts;
                    $expanded = true;
                }
                continue;
            }

            // Skip header/address-only lines that do not contain a SKU.
            if (!preg_match('/\\b[A-Z]{1,}[0-9]{4,}(?:-[A-Z0-9]+)*\\b/i', $line)) {
                continue;
            }

            $lineParts = $splitBySku($line);
            if (count($lineParts) > 1) {
                foreach ($lineParts as $part) {
                    $out[] = [$part];
                }
                $expanded = true;
                $pendingSku = null;
                continue;
            }

            if (preg_match('/^[A-Z]{1,}[0-9]{4,}(?:-[A-Z0-9]+)*/i', $line)) {
                if (preg_match('/\\*[A-Z]{1,}[0-9]{4,}(?:-[A-Z0-9]+)*\\*/i', $line)) {
                    $out[] = [$line];
                    $expanded = true;
                    $pendingSku = null;
                    continue;
                }
                // If the line already has multiple columns, split it.
                $parts = preg_split('/\\s{2,}|\\t+/', $line) ?: [];
                $parts = array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));
                if (count($parts) >= 3) {
                    $out[] = $parts;
                    $expanded = true;
                    $pendingSku = null;
                    continue;
                }
                // Otherwise treat as SKU-only line and wait for a detail line.
                $pendingSku = $line;
                continue;
            }

            if ($pendingSku !== null) {
                // Combine SKU with the next detail line.
                $combo = $pendingSku . '  ' . $line;
                $parts = preg_split('/\\s{2,}|\\t+/', $combo) ?: [];
                $parts = array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));
                if (count($parts) >= 3) {
                    $out[] = $parts;
                    $expanded = true;
                }
                $pendingSku = null;
                continue;
            }
        }

        if ($pendingSku !== null) {
            $out[] = [$pendingSku];
        }

        if (!$expanded) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Send a JSON response and exit. (Kept for potential functionality not shown)
 */
function respondJson(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pickUsersTable(mysqli $conn): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $candidates = ['users', 'xserqhmy_users.users'];
    foreach ($candidates as $table) {
        try {
            $sql = "SELECT id FROM {$table} LIMIT 1";
            $res = $conn->query($sql);
            if ($res instanceof mysqli_result) {
                $res->free();
                $cached = $table;
                return $cached;
            }
            if ($res === true) {
                $cached = $table;
                return $cached;
            }
        } catch (Throwable $e) {
            // Try next candidate.
        }
    }
    $cached = false;
    return null;
}

function pickResolveCurrentUserId(mysqli $conn): int
{
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($sessionUserId > 0) {
        return $sessionUserId;
    }
    $username = trim((string) ($_SESSION['username'] ?? ''));
    if ($username === '') {
        return 0;
    }
    $table = pickUsersTable($conn);
    if ($table === null) {
        return 0;
    }
    $stmt = $conn->prepare("SELECT id FROM {$table} WHERE username = ? LIMIT 1");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return (int) ($row['id'] ?? 0);
}

function pickResolveCurrentRoleId(): int
{
    return (int) ($_SESSION['role_id'] ?? 0);
}

function pickUserHasOrderOverride(?int $roleId = null): bool
{
    $roleId = $roleId ?? pickResolveCurrentRoleId();
    return in_array((int) $roleId, [1, 2, 4], true);
}

function pickUserCanAccessOrder(int $assignedUserId, int $currentUserId, bool $hasOverride = false): bool
{
    if ($hasOverride) {
        return true;
    }
    if ($assignedUserId <= 0) {
        return true;
    }

    return $currentUserId > 0 && $assignedUserId === $currentUserId;
}

/**
 * @return array{id:int,assigned_user_id:int}|null
 */
function pickFetchOrderAccessRow(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0) {
        return null;
    }

    $stmt = $conn->prepare('SELECT id, assigned_user_id FROM pick_orders WHERE id = ? LIMIT 1');
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

    return [
        'id' => (int) ($row['id'] ?? 0),
        'assigned_user_id' => (int) ($row['assigned_user_id'] ?? 0),
    ];
}

function pickRequireOrderAccessJson(mysqli $conn, int $orderId): void
{
    $order = pickFetchOrderAccessRow($conn, $orderId);
    if ($order === null) {
        respondJson(['ok' => false, 'error' => 'Order not found.'], 404);
    }

    $currentUserId = pickResolveCurrentUserId($conn);
    $hasOverride = pickUserHasOrderOverride();
    $assignedUserId = (int) ($order['assigned_user_id'] ?? 0);
    if (!pickUserCanAccessOrder($assignedUserId, $currentUserId, $hasOverride)) {
        respondJson(['ok' => false, 'error' => 'This order is assigned to another user.'], 403);
    }
}

function pickRequireOrderClaimJson(mysqli $conn, int $orderId): void
{
    $order = pickFetchOrderAccessRow($conn, $orderId);
    if ($order === null) {
        respondJson(['ok' => false, 'error' => 'Order not found.'], 404);
    }

    $currentUserId = pickResolveCurrentUserId($conn);
    if ($currentUserId <= 0) {
        respondJson(['ok' => false, 'error' => 'Unable to resolve current user.'], 400);
    }
}

function pickRequireOrderAssignJson(): void
{
    if (!pickUserHasOrderOverride()) {
        respondJson(['ok' => false, 'error' => 'Only supervisors or admins can reassign orders.'], 403);
    }
}

function pickRequireAdminManagerJson(): void
{
    if (!pickUserHasOrderOverride()) {
        respondJson(['ok' => false, 'error' => 'Only admins or managers can access this action.'], 403);
    }
}

function pickRequireOrderExistsJson(mysqli $conn, int $orderId): void
{
    $order = pickFetchOrderAccessRow($conn, $orderId);
    if ($order === null) {
        respondJson(['ok' => false, 'error' => 'Order not found.'], 404);
    }
}

/**
 * @return array<int,array{id:int,username:string,label:string}>
 */
function pickFetchAssignableUsers(mysqli $conn): array
{
    $table = pickUsersTable($conn);
    if ($table === null) {
        return [];
    }
    $sql = "
        SELECT id, username,
               COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))), ''), username) AS label
        FROM {$table}
        ORDER BY username ASC
    ";
    $res = $conn->query($sql);
    if (!$res instanceof mysqli_result) {
        return [];
    }
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'username' => (string) ($row['username'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
        ];
    }
    $res->free();
    return $out;
}

function pickTableColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '|' . $column;
    if (array_key_exists($key, $cache)) {
        return (bool) $cache[$key];
    }
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        $cache[$key] = false;
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    $cache[$key] = $exists;
    return $exists;
}

function pickIndexExists(mysqli $conn, string $table, string $indexName): bool
{
    $table = trim($table);
    $indexName = trim($indexName);
    if ($table === '' || $indexName === '') {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $indexName);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function ensurePickUniqueIndex(mysqli $conn, string $table, string $indexName, string $definition): void
{
    $table = trim($table);
    $indexName = trim($indexName);
    $definition = trim($definition);
    if ($table === '' || $indexName === '' || $definition === '') {
        return;
    }
    if (pickIndexExists($conn, $table, $indexName)) {
        return;
    }

    $sql = "ALTER TABLE `{$table}` ADD UNIQUE KEY `{$indexName}` ({$definition})";
    if (!$conn->query($sql)) {
        error_log('[verify_pick] unable to add unique index ' . $indexName . ' on ' . $table . ': ' . $conn->error);
    }
}

function dropPickIndexIfExists(mysqli $conn, string $table, string $indexName): void
{
    $table = trim($table);
    $indexName = trim($indexName);
    if ($table === '' || $indexName === '' || !pickIndexExists($conn, $table, $indexName)) {
        return;
    }

    $sql = "ALTER TABLE `{$table}` DROP INDEX `{$indexName}`";
    if (!$conn->query($sql)) {
        error_log('[verify_pick] unable to drop legacy unique index ' . $indexName . ' on ' . $table . ': ' . $conn->error);
    }
}

function ensurePickImportUniqueness(mysqli $conn): void
{
    ensurePickUniqueIndex($conn, 'pick_orders', 'uq_pick_orders_order_number', '`order_number`');
    if (!pickTableColumnExists($conn, 'pick_items', 'source_line_no')) {
        @$conn->query('ALTER TABLE pick_items ADD COLUMN source_line_no INT UNSIGNED NOT NULL DEFAULT 0 AFTER order_id');
    }
    dropPickIndexIfExists($conn, 'pick_items', 'uq_pick_items_order_item_no');
    dropPickIndexIfExists($conn, 'pick_items', 'uq_pick_items_order_line_item_no');
    ensurePickUniqueIndex(
        $conn,
        'pick_items',
        'uq_pick_items_order_line_item_qty',
        '`order_id`, `source_line_no`, `item_no`, `qty_ordered`'
    );
}

function pickFirstExistingColumn(mysqli $conn, string $table, array $candidates): ?string
{
    foreach ($candidates as $col) {
        if (is_string($col) && $col !== '' && pickTableColumnExists($conn, $table, $col)) {
            return $col;
        }
    }
    return null;
}

/**
 * Force-complete the selected orders for the bulk-complete admin flow.
 *
 * @param array<int,mixed> $orderIds
 * @return array{updated_count:int,updated_ids:array<int,int>}
 */
function pickBulkCompleteOrders(mysqli $conn, array $orderIds): array
{
    $normalizedIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0)));
    if ($normalizedIds === []) {
        throw new InvalidArgumentException('Select at least one order.');
    }

    $orderSet = ["status = 'COMPLETED'"];

    if (pickTableColumnExists($conn, 'pick_orders', 'verified_at')) {
        $orderSet[] = 'verified_at = NOW()';
    }

    $orderSetSql = implode(', ', $orderSet);
    $idList = implode(',', $normalizedIds);
    $updateSql = "UPDATE pick_orders SET {$orderSetSql} WHERE id IN ({$idList})";

    if (!$conn->query($updateSql)) {
        throw new RuntimeException($conn->error ?: 'Unable to complete selected orders.');
    }

    return [
        'updated_count' => max(0, (int) $conn->affected_rows),
        'updated_ids' => $normalizedIds,
    ];
}

/**
 * @return array{total:int,picker_verified:int,packer_verified:int,status:string,short_ship_count:int}
 */
function pickOrderVerificationSummary(mysqli $conn, int $orderId, bool $hasPackerVerify): array
{
    $total = 0;
    $pickerTotal = 0;
    $pickerVerified = 0;
    $packerTotal = 0;
    $packerVerified = 0;
    $shortShipCount = 0;
    $shortShipQty = 0;
    $hasShortShip = pickTableColumnExists($conn, 'pick_items', 'is_short_ship');
    $hasShortShipQty = pickTableColumnExists($conn, 'pick_items', 'short_ship_qty');
    $totalExpr = 'COALESCE(SUM(qty_ordered), 0)';
    $resolvedShortShipQtyExpr = $hasShortShipQty ? pickResolvedShortShipQtyExpr() : '0';
    $shortShipQtyExpr = $hasShortShipQty ? 'COALESCE(SUM(' . $resolvedShortShipQtyExpr . '), 0)' : '0';
    $pickerExpr = $hasShortShip
        ? 'COALESCE(SUM(CASE WHEN COALESCE(is_short_ship, 0) = 1 THEN qty_ordered ELSE LEAST(qty_verified, qty_ordered) END), 0)'
        : 'COALESCE(SUM(LEAST(qty_verified, qty_ordered)), 0)';
    $packerTotalExpr = $hasShortShip
        ? 'COALESCE(SUM(CASE WHEN COALESCE(is_short_ship, 0) = 1 THEN GREATEST(qty_ordered - ' . $resolvedShortShipQtyExpr . ', 0) ELSE qty_ordered END), 0)'
        : 'COALESCE(SUM(qty_ordered), 0)';
    $packerExpr = $hasPackerVerify
        ? ($hasShortShip
            ? 'COALESCE(SUM(CASE WHEN COALESCE(is_short_ship, 0) = 1 THEN LEAST(qty_verified_packer, GREATEST(qty_ordered - ' . $resolvedShortShipQtyExpr . ', 0)) ELSE LEAST(qty_verified_packer, qty_ordered) END), 0)'
            : 'COALESCE(SUM(LEAST(qty_verified_packer, qty_ordered)), 0)')
        : $pickerExpr;
    $shortShipExpr = $hasShortShip
        ? 'COALESCE(SUM(CASE WHEN COALESCE(is_short_ship, 0) = 1 THEN 1 ELSE 0 END), 0)'
        : '0';
    if ($hasPackerVerify) {
        $sumStmt = $conn->prepare("
            SELECT
                {$totalExpr} AS total_qty,
                {$totalExpr} AS picker_total_qty,
                {$pickerExpr} AS picker_verified_qty,
                {$packerTotalExpr} AS packer_total_qty,
                {$packerExpr} AS packer_verified_qty,
                {$shortShipExpr} AS short_ship_count,
                {$shortShipQtyExpr} AS short_ship_qty
            FROM pick_items
            WHERE order_id = ?
        ");
        if ($sumStmt) {
            $sumStmt->bind_param('i', $orderId);
            $sumStmt->execute();
            $row = $sumStmt->get_result()->fetch_assoc() ?: [];
            $sumStmt->close();
            $total = (int) ($row['total_qty'] ?? 0);
            $pickerTotal = (int) ($row['picker_total_qty'] ?? $total);
            $pickerVerified = (int) ($row['picker_verified_qty'] ?? 0);
            $packerTotal = (int) ($row['packer_total_qty'] ?? $total);
            $packerVerified = (int) ($row['packer_verified_qty'] ?? 0);
            $shortShipCount = (int) ($row['short_ship_count'] ?? 0);
            $shortShipQty = (int) ($row['short_ship_qty'] ?? 0);
        }
    } else {
        $sumStmt = $conn->prepare("
            SELECT
                {$totalExpr} AS total_qty,
                {$totalExpr} AS picker_total_qty,
                {$pickerExpr} AS picker_verified_qty,
                {$totalExpr} AS packer_total_qty,
                {$pickerExpr} AS packer_verified_qty,
                {$shortShipExpr} AS short_ship_count,
                {$shortShipQtyExpr} AS short_ship_qty
            FROM pick_items
            WHERE order_id = ?
        ");
        if ($sumStmt) {
            $sumStmt->bind_param('i', $orderId);
            $sumStmt->execute();
            $row = $sumStmt->get_result()->fetch_assoc() ?: [];
            $sumStmt->close();
            $total = (int) ($row['total_qty'] ?? 0);
            $pickerTotal = (int) ($row['picker_total_qty'] ?? $total);
            $pickerVerified = (int) ($row['picker_verified_qty'] ?? 0);
            $packerTotal = (int) ($row['packer_total_qty'] ?? $total);
            $packerVerified = (int) ($row['packer_verified_qty'] ?? 0);
            $shortShipCount = (int) ($row['short_ship_count'] ?? 0);
            $shortShipQty = (int) ($row['short_ship_qty'] ?? 0);
        } else {
            $pickerTotal = $total;
            $packerTotal = $total;
            $packerVerified = $pickerVerified;
        }
    }
    $pickerComplete = $pickerTotal > 0 && $pickerVerified >= $pickerTotal;
    $packerComplete = $packerTotal > 0 && $packerVerified >= $packerTotal;
    $status = 'PENDING';
    if ($hasPackerVerify) {
        if ($pickerComplete && $packerComplete) {
            $status = 'COMPLETED';
        } elseif ($pickerComplete) {
            $status = 'READY_TO_PACK';
        } elseif ($pickerVerified > 0 || $packerVerified > 0 || $shortShipCount > 0) {
            $status = 'IN_PROGRESS';
        }
    } else {
        if ($pickerComplete) {
            $status = 'COMPLETED';
        } elseif ($pickerVerified > 0 || $shortShipCount > 0) {
            $status = 'IN_PROGRESS';
        }
    }
    return [
        'total' => $total,
        'picker_total' => $pickerTotal ?? $total,
        'picker_verified' => $pickerVerified,
        'packer_total' => $packerTotal ?? $total,
        'packer_verified' => $packerVerified,
        'status' => $status,
        'short_ship_count' => $shortShipCount,
        'short_ship_qty' => $shortShipQty,
    ];
}

function pickFetchOrderStatus(mysqli $conn, int $orderId): string
{
    $stmt = $conn->prepare('SELECT status FROM pick_orders WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return strtoupper(trim((string) ($row['status'] ?? '')));
}

function pickResolvePersistedOrderStatus(string $currentStatus, array $summary): string
{
    $currentStatus = strtoupper(trim($currentStatus));
    $pickerComplete = pickSummaryIsPickerComplete($summary);
    $packerComplete = pickSummaryIsPackerComplete($summary);

    if ($pickerComplete && $packerComplete) {
        return 'COMPLETED';
    }
    if ($pickerComplete) {
        return 'READY_TO_PACK';
    }
    if ($currentStatus === 'READY_TO_PACK') {
        return 'IN_PROGRESS';
    }
    return 'IN_PROGRESS';
}

function pickResolveScanDrivenOrderStatus(string $currentStatus, array $summary): string
{
    $currentStatus = strtoupper(trim($currentStatus));
    $pickerComplete = pickSummaryIsPickerComplete($summary);
    $packerComplete = pickSummaryIsPackerComplete($summary);

    if ($pickerComplete && $packerComplete) {
        return 'COMPLETED';
    }
    if ($pickerComplete) {
        return 'READY_TO_PACK';
    }
    if (in_array($currentStatus, ['READY_TO_PACK', 'COMPLETED', 'COMPLETED_SHORT_SHIP'], true)) {
        return 'IN_PROGRESS';
    }
    return ($summary['status'] ?? '') === 'PENDING' ? 'PENDING' : 'IN_PROGRESS';
}

function pickEnsureOrderStatusSupportsReadyToPack(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $checked = true;
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
    if (stripos($columnType, "'READY_TO_PACK'") !== false) {
        return;
    }

    if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $columnType, $matches)) {
        return;
    }

    $values = $matches[1] ?? [];
    if (!in_array('READY_TO_PACK', $values, true)) {
        $values[] = 'READY_TO_PACK';
    }

    $enumSql = implode(', ', array_map(static function (string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    }, $values));

    $alterSql = "ALTER TABLE pick_orders MODIFY COLUMN status ENUM({$enumSql}) NOT NULL DEFAULT 'PENDING'";
    try {
        $conn->query($alterSql);
    } catch (Throwable $e) {
        error_log('[verify_pick] unable to expand pick_orders.status enum: ' . $e->getMessage());
    }
}

function pickSummaryIsPickerComplete(array $summary): bool
{
    $total = (int) ($summary['picker_total'] ?? $summary['total'] ?? 0);
    $pickerVerified = (int) ($summary['picker_verified'] ?? 0);
    return $total > 0 && $pickerVerified >= $total;
}

function pickSummaryIsPackerComplete(array $summary): bool
{
    $total = (int) ($summary['packer_total'] ?? $summary['total'] ?? 0);
    $packerVerified = (int) ($summary['packer_verified'] ?? 0);
    return $total > 0 && $packerVerified >= $total;
}

function pickSummaryIsTerminalShortShip(array $summary): bool
{
    $total = (int) ($summary['total'] ?? 0);
    $pickerVerified = (int) ($summary['picker_verified'] ?? 0);
    $packerVerified = (int) ($summary['packer_verified'] ?? 0);
    $shortShipCount = (int) ($summary['short_ship_count'] ?? 0);

    $pickerComplete = $total > 0 && $pickerVerified >= $total;
    $packerComplete = $total > 0 && $packerVerified >= $total;

    return $shortShipCount > 0 && $pickerComplete && $packerComplete;
}

function pickTableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return (bool) $cache[$table];
    }
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        $cache[$table] = false;
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    $cache[$table] = $exists;
    return $exists;
}

/**
 * Best-effort analytics logging. Fail open so warehouse flows keep working.
 */
function pickLogVerifyEvent(mysqli $conn, array $payload): void
{
    try {
        if (!pickTableExists($conn, 'pick_verify_events')) {
            return;
        }

        $orderId = (int) ($payload['order_id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }

        $workflowStage = strtolower(trim((string) ($payload['workflow_stage'] ?? '')));
        if (!in_array($workflowStage, ['pick', 'pack'], true)) {
            return;
        }

        $eventType = trim((string) ($payload['event_type'] ?? ''));
        if ($eventType === '') {
            return;
        }
        $eventType = substr($eventType, 0, 50);

        $pickItemId = (int) ($payload['pick_item_id'] ?? 0);
        $skuScanned = substr(trim((string) ($payload['sku_scanned'] ?? '')), 0, 100);
        $expectedSku = substr(trim((string) ($payload['expected_sku'] ?? '')), 0, 100);
        $qtyDelta = (int) ($payload['qty_delta'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            $userId = pickResolveCurrentUserId($conn);
        }
        $eventMode = $workflowStage === 'pack' ? 'packer' : 'picker';

        $meta = $payload['meta'] ?? null;
        $metaJson = null;
        if (is_array($meta) || is_object($meta)) {
            $metaPayload = is_object($meta) ? get_object_vars($meta) : $meta;
            $metaPayload['mode'] = $eventMode;

            $metaJson = json_encode($metaPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($metaJson === false) {
                $metaJson = json_encode(['mode' => $eventMode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        } elseif (is_string($meta) && trim($meta) !== '') {
            $decodedMeta = json_decode($meta, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedMeta)) {
                $decodedMeta['mode'] = $eventMode;

                $metaJson = json_encode($decodedMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($metaJson === false) {
                    $metaJson = json_encode(['mode' => $eventMode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            } else {
                $metaJson = json_encode([
                    'mode' => $eventMode,
                    'raw_meta' => $meta,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        } else {
            $metaJson = json_encode(['mode' => $eventMode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $stmt = $conn->prepare("
            INSERT INTO pick_verify_events
                (order_id, pick_item_id, workflow_stage, event_type, sku_scanned, expected_sku, qty_delta, user_id, meta_json)
            VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?, NULLIF(?, 0), ?)
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param(
            'iissssiis',
            $orderId,
            $pickItemId,
            $workflowStage,
            $eventType,
            $skuScanned,
            $expectedSku,
            $qtyDelta,
            $userId,
            $metaJson
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[verify_pick] analytics event logging failed: ' . $e->getMessage());
    }
}

function pickNormalizeBoxDimensions(string $dimensions): string
{
    $dimensions = trim($dimensions);
    if ($dimensions === '') {
        return '';
    }
    $dimensions = preg_replace('/\s+/', ' ', $dimensions) ?? $dimensions;
    $dimensions = str_replace('X', 'x', $dimensions);
    $dimensions = preg_replace('/\s*x\s*/i', 'x', $dimensions) ?? $dimensions;
    return strtolower(trim($dimensions));
}

/**
 * @return array{length:?float,width:?float,height:?float}
 */
function pickParseBoxDimensions(string $dimensions): array
{
    $normalized = pickNormalizeBoxDimensions($dimensions);
    if ($normalized === '') {
        return ['length' => null, 'width' => null, 'height' => null];
    }
    $parts = array_values(array_filter(array_map('trim', explode('x', $normalized)), static fn($value) => $value !== ''));
    if (count($parts) < 2 || count($parts) > 3) {
        return ['length' => null, 'width' => null, 'height' => null];
    }
    foreach ($parts as $part) {
        if (!is_numeric($part)) {
            return ['length' => null, 'width' => null, 'height' => null];
        }
    }
    return [
        'length' => round((float) $parts[0], 2),
        'width' => round((float) $parts[1], 2),
        'height' => isset($parts[2]) ? round((float) $parts[2], 2) : null,
    ];
}

/**
 * @return array<int,array{inside_dimensions:string,actual_dimensions:string,display_dimensions:string}>
 */
function pickFetchWarehouseBoxTypes(mysqli $conn): array
{
    if (!pickTableExists($conn, 'warehouse_supplies') || !pickTableExists($conn, 'Warehouse_Current_Stock')) {
        return [];
    }

    $rows = [];
    $stmt = $conn->prepare("
        SELECT
            s.supply_id,
            s.model_no,
            s.inside_dimensions AS actual_dimensions,
            CONCAT(
                CAST(CEIL(s.inside_length_in) AS UNSIGNED),
                ' X ',
                CAST(CEIL(s.inside_width_in) AS UNSIGNED),
                ' X ',
                CAST(CEIL(s.inside_height_in) AS UNSIGNED)
            ) AS display_dimensions,
            COALESCE(cs.qty_on_hand, 0) AS qty_on_hand,
            cs.reset_invoice_qty,
            CASE
                WHEN COALESCE(cs.qty_on_hand, 0) <= 0 THEN 'OUT'
                WHEN cs.reset_invoice_qty > 0
                  AND COALESCE(cs.qty_on_hand, 0) <= CEIL(cs.reset_invoice_qty * 0.20)
                  THEN 'LOW'
                ELSE 'OK'
            END AS stock_status
        FROM warehouse_supplies s
        LEFT JOIN Warehouse_Current_Stock cs
          ON cs.supply_id = s.supply_id
        WHERE s.inside_dimensions IS NOT NULL
          AND s.inside_dimensions <> '' 
          AND s.supply_category = ?
        ORDER BY
          CEIL(s.inside_length_in),
          CEIL(s.inside_width_in),
          CEIL(s.inside_height_in),
          s.supply_name
    ");
    if (!$stmt) {
        return [];
    }

    $category = 'Corrugated Boxes';
    $stmt->bind_param('s', $category);

    $stmt->execute();
    $result = $stmt->get_result();
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $actualDimensions = trim((string) ($row['actual_dimensions'] ?? ''));
            $displayDimensions = trim((string) ($row['display_dimensions'] ?? ''));
            if ($actualDimensions === '') {
                continue;
            }
            if ($displayDimensions === '') {
                $displayDimensions = $actualDimensions;
            }
            $rows[] = [
                'supply_id' => (int) ($row['supply_id'] ?? 0),
                'model_no' => (string) ($row['model_no'] ?? ''),
                'inside_dimensions' => $actualDimensions,
                'actual_dimensions' => $actualDimensions,
                'display_dimensions' => $displayDimensions,
                'qty_on_hand' => (int) ($row['qty_on_hand'] ?? 0),
                'reset_invoice_qty' => $row['reset_invoice_qty'] !== null ? (int) $row['reset_invoice_qty'] : null,
                'stock_status' => (string) ($row['stock_status'] ?? 'OK'),
            ];
        }
        $result->free();
    }
    $stmt->close();

    return $rows;
}

/**
 * @return array{
 *   stats:array{
 *     open_orders:int,
 *     assigned_to_me:int,
 *     unassigned:int,
 *     pick_queue_count:int,
 *     pack_queue_count:int,
 *     completed_today:int,
 *     orders_30_days:int,
 *     completed_30_days:int,
 *     pick_accuracy_30_days:float,
 *     pack_accuracy_30_days:float
 *   },
 *   pick_queue:array<int,array<string,mixed>>,
 *   pack_queue:array<int,array<string,mixed>>
 * }
 */
function pickBuildWorkflowDashboard(mysqli $conn, int $currentUserId, array $userLabelById): array
{
    $stats = [
        'open_orders' => 0,
        'assigned_to_me' => 0,
        'unassigned' => 0,
        'pick_queue_count' => 0,
        'pack_queue_count' => 0,
        'completed_today' => 0,
        'orders_30_days' => 0,
        'completed_30_days' => 0,
        'pick_accuracy_30_days' => 0.0,
        'pack_accuracy_30_days' => 0.0,
    ];
    $pickQueue = [];
    $packQueue = [];
    $salesQueue = [];
    $hasPackerVerify = pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer');
    $hasShortShip = pickTableColumnExists($conn, 'pick_items', 'is_short_ship');
    $hasShortShipQty = $hasShortShip && pickTableColumnExists($conn, 'pick_items', 'short_ship_qty');

    $completedStmt = $conn->prepare("
        SELECT COUNT(*) AS completed_today
        FROM pick_orders
        WHERE status IN ('COMPLETED', 'COMPLETED_SHORT_SHIP')
          AND DATE(COALESCE(updated_at, created_at, NOW())) = CURDATE()
    ");
    if ($completedStmt) {
        $completedStmt->execute();
        $completedRow = $completedStmt->get_result()->fetch_assoc() ?: [];
        $completedStmt->close();
        $stats['completed_today'] = (int) ($completedRow['completed_today'] ?? 0);
    }

    $totalExpr30 = 'COALESCE(SUM(pi.qty_ordered), 0)';
    $pickerExpr30 = $hasShortShip
        ? 'COALESCE(SUM(CASE WHEN COALESCE(pi.is_short_ship, 0) = 1 THEN pi.qty_ordered ELSE LEAST(pi.qty_verified, pi.qty_ordered) END), 0)'
        : 'COALESCE(SUM(LEAST(pi.qty_verified, pi.qty_ordered)), 0)';
    $resolvedShortShipQtyExpr30 = $hasShortShipQty ? pickResolvedShortShipQtyExpr('pi') : '0';
    $packerTotalExpr30 = $hasPackerVerify
        ? ($hasShortShip && $hasShortShipQty
            ? 'COALESCE(SUM(CASE WHEN COALESCE(pi.is_short_ship, 0) = 1 THEN GREATEST(pi.qty_ordered - ' . $resolvedShortShipQtyExpr30 . ', 0) ELSE pi.qty_ordered END), 0)'
            : 'COALESCE(SUM(pi.qty_ordered), 0)')
        : $totalExpr30;
    $packerExpr30 = $hasPackerVerify
        ? ($hasShortShip && $hasShortShipQty
            ? 'COALESCE(SUM(CASE WHEN COALESCE(pi.is_short_ship, 0) = 1 THEN LEAST(pi.qty_verified_packer, GREATEST(pi.qty_ordered - ' . $resolvedShortShipQtyExpr30 . ', 0)) ELSE LEAST(pi.qty_verified_packer, pi.qty_ordered) END), 0)'
            : 'COALESCE(SUM(LEAST(pi.qty_verified_packer, pi.qty_ordered)), 0)')
        : $pickerExpr30;

    $windowStmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT po.id) AS orders_30_days,
            COUNT(DISTINCT CASE WHEN po.status IN ('COMPLETED', 'COMPLETED_SHORT_SHIP') THEN po.id END) AS completed_30_days,
            {$totalExpr30} AS ordered_total_30_days,
            {$pickerExpr30} AS picker_verified_total_30_days,
            {$packerTotalExpr30} AS packer_total_30_days,
            {$packerExpr30} AS packer_verified_total_30_days
        FROM pick_orders po
        LEFT JOIN pick_items pi
            ON pi.order_id = po.id
        WHERE COALESCE(po.updated_at, po.created_at, NOW()) >= (NOW() - INTERVAL 30 DAY)
    ");
    if ($windowStmt) {
        $windowStmt->execute();
        $windowRow = $windowStmt->get_result()->fetch_assoc() ?: [];
        $windowStmt->close();
        $ordered30 = (int) ($windowRow['ordered_total_30_days'] ?? 0);
        $pickerVerified30 = (int) ($windowRow['picker_verified_total_30_days'] ?? 0);
        $packerTotal30 = (int) ($windowRow['packer_total_30_days'] ?? 0);
        $packerVerified30 = (int) ($windowRow['packer_verified_total_30_days'] ?? 0);
        $stats['orders_30_days'] = (int) ($windowRow['orders_30_days'] ?? 0);
        $stats['completed_30_days'] = (int) ($windowRow['completed_30_days'] ?? 0);
        $stats['pick_accuracy_30_days'] = $ordered30 > 0
            ? round(($pickerVerified30 / $ordered30) * 100, 1)
            : 0.0;
        $stats['pack_accuracy_30_days'] = $packerTotal30 > 0
            ? round(($packerVerified30 / $packerTotal30) * 100, 1)
            : 0.0;
    }

    $trend = pickBuildWorkflowTrend($conn, $hasPackerVerify, $hasShortShip);

    $orders = [];
    $stmt = $conn->prepare("
        SELECT id, order_number, status, assigned_user_id, updated_at, created_at
        FROM pick_orders
        WHERE (status IS NULL OR status NOT IN ('COMPLETED', 'COMPLETED_SHORT_SHIP'))
        ORDER BY
            CASE WHEN assigned_user_id IS NOT NULL AND assigned_user_id > 0 THEN 0 ELSE 1 END ASC,
            " . pickSalesOrderSortExpr() . " DESC,
            order_number DESC,
            id DESC
        LIMIT 250
    ");
    if ($stmt) {
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

    $stats['open_orders'] = count($orders);

    foreach ($orders as $order) {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        $assignedId = (int) ($order['assigned_user_id'] ?? 0);
        if ($assignedId > 0 && $assignedId === $currentUserId) {
            $stats['assigned_to_me']++;
        }
        if ($assignedId <= 0) {
            $stats['unassigned']++;
        }

        $summary = pickOrderVerificationSummary($conn, $orderId, $hasPackerVerify);
        $summaryTotal = (int) ($summary['picker_total'] ?? $summary['total'] ?? 0);
        $summaryPackTotal = (int) ($summary['packer_total'] ?? $summaryTotal);
        $summaryShortShips = (int) ($summary['short_ship_count'] ?? 0);
        $pickerVerified = (int) ($summary['picker_verified'] ?? 0);
        $packerVerified = (int) ($summary['packer_verified'] ?? 0);
        $pickerDone = ($summaryTotal > 0 && $pickerVerified >= $summaryTotal)
            || ($summaryTotal === 0 && $summaryShortShips > 0);
        $packerDone = ($summaryPackTotal > 0 && $packerVerified >= $summaryPackTotal)
            || ($summaryPackTotal === 0 && $summaryShortShips > 0);
        $assignedLabel = $assignedId > 0 ? ($userLabelById[$assignedId] ?? ('User #' . $assignedId)) : 'Unassigned';
        $rawStatus = strtoupper(trim((string) ($order['status'] ?? '')));
        $baseOrder = [
            'id' => $orderId,
            'order_number' => (string) ($order['order_number'] ?? ''),
            'status' => $rawStatus,
            'assigned_label' => $assignedLabel,
            'remaining' => max(0, $summaryTotal - $pickerVerified),
            'pack_remaining' => max(0, $summaryPackTotal - $packerVerified),
            'items_total' => $summaryTotal,
            'short_ship_count' => $summaryShortShips,
            'updated_at' => (string) ($order['updated_at'] ?? ''),
        ];

        if (count($salesQueue) < 8) {
            $salesQueue[] = $baseOrder;
        }

        if (in_array($rawStatus, ['PENDING', 'IN_PROGRESS'], true)) {
            $stats['pick_queue_count']++;
            if (count($pickQueue) < 6) {
                $pickQueue[] = $baseOrder;
            }
        }

        if ($rawStatus === 'READY_TO_PACK') {
            $stats['pack_queue_count']++;
            if (count($packQueue) < 6) {
                $packQueue[] = $baseOrder;
            }
        }
    }

    return [
        'stats' => $stats,
        'trend' => $trend,
        'sales_queue' => $salesQueue,
        'pick_queue' => $pickQueue,
        'pack_queue' => $packQueue,
    ];
}

/**
 * @return array<int,array{date_key:string,label:string,orders:int,picked:int,packed:int}>
 */
function pickBuildWorkflowTrend(mysqli $conn, bool $hasPackerVerify, bool $hasShortShip): array
{
    $hasShortShipQty = $hasShortShip && pickTableColumnExists($conn, 'pick_items', 'short_ship_qty');
    $days = [];
    $today = new DateTimeImmutable('today');
    for ($offset = 29; $offset >= 0; $offset--) {
        $day = $today->sub(new DateInterval('P' . $offset . 'D'));
        $key = $day->format('Y-m-d');
        $days[$key] = [
            'date_key' => $key,
            'label' => $day->format('M j'),
            'orders' => 0,
            'picked' => 0,
            'packed' => 0,
        ];
    }

    $totalExpr = 'COALESCE(SUM(qty_ordered), 0)';
    $pickerExpr = $hasShortShip
        ? 'COALESCE(SUM(CASE WHEN COALESCE(is_short_ship, 0) = 1 THEN qty_ordered ELSE LEAST(qty_verified, qty_ordered) END), 0)'
        : 'COALESCE(SUM(LEAST(qty_verified, qty_ordered)), 0)';
    $resolvedShortShipQtyExpr = $hasShortShipQty ? pickResolvedShortShipQtyExpr() : '0';
    $packerTotalExpr = $hasPackerVerify
        ? ($hasShortShip && $hasShortShipQty
            ? 'COALESCE(SUM(CASE WHEN COALESCE(is_short_ship, 0) = 1 THEN GREATEST(qty_ordered - ' . $resolvedShortShipQtyExpr . ', 0) ELSE qty_ordered END), 0)'
            : 'COALESCE(SUM(qty_ordered), 0)')
        : $totalExpr;
    $packerExpr = $hasPackerVerify
        ? ($hasShortShip && $hasShortShipQty
            ? 'COALESCE(SUM(CASE WHEN COALESCE(is_short_ship, 0) = 1 THEN LEAST(qty_verified_packer, GREATEST(qty_ordered - ' . $resolvedShortShipQtyExpr . ', 0)) ELSE LEAST(qty_verified_packer, qty_ordered) END), 0)'
            : 'COALESCE(SUM(LEAST(qty_verified_packer, qty_ordered)), 0)')
        : $pickerExpr;
    $shortShipExpr = $hasShortShip
        ? 'COALESCE(SUM(CASE WHEN COALESCE(is_short_ship, 0) = 1 THEN 1 ELSE 0 END), 0)'
        : '0';

    $sql = "
        SELECT
            DATE(COALESCE(po.updated_at, po.created_at, NOW())) AS day_key,
            COUNT(*) AS orders_total,
            SUM(
                CASE
                    WHEN COALESCE(s.picker_total_qty, 0) > 0 AND COALESCE(s.picker_verified_qty, 0) >= COALESCE(s.picker_total_qty, 0) THEN 1
                    WHEN COALESCE(s.picker_total_qty, 0) = 0 AND COALESCE(s.short_ship_count, 0) > 0 THEN 1
                    ELSE 0
                END
            ) AS picked_total,
            SUM(
                CASE
                    WHEN COALESCE(s.packer_total_qty, 0) > 0 AND COALESCE(s.packer_verified_qty, 0) >= COALESCE(s.packer_total_qty, 0) THEN 1
                    WHEN COALESCE(s.packer_total_qty, 0) = 0 AND COALESCE(s.short_ship_count, 0) > 0 THEN 1
                    ELSE 0
                END
            ) AS packed_total
        FROM pick_orders po
        LEFT JOIN (
            SELECT
                order_id,
                {$totalExpr} AS total_qty,
                {$totalExpr} AS picker_total_qty,
                {$pickerExpr} AS picker_verified_qty,
                {$packerTotalExpr} AS packer_total_qty,
                {$packerExpr} AS packer_verified_qty,
                {$shortShipExpr} AS short_ship_count
            FROM pick_items
            GROUP BY order_id
        ) s
            ON s.order_id = po.id
        WHERE COALESCE(po.updated_at, po.created_at, NOW()) >= (CURDATE() - INTERVAL 29 DAY)
        GROUP BY DATE(COALESCE(po.updated_at, po.created_at, NOW()))
        ORDER BY day_key ASC
    ";
    $result = $conn->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $key = (string) ($row['day_key'] ?? '');
            if (!isset($days[$key])) {
                continue;
            }
            $days[$key]['orders'] = (int) ($row['orders_total'] ?? 0);
            $days[$key]['picked'] = (int) ($row['picked_total'] ?? 0);
            $days[$key]['packed'] = (int) ($row['packed_total'] ?? 0);
        }
        $result->free();
    }

    return array_values($days);
}

/**
 * @param array<int,array{date_key:string,label:string,orders:int,picked:int,packed:int}> $trend
 * @return array{
 *   width:int,
 *   height:int,
 *   max_value:int,
 *   guides:array<int,array{value:int,y:float}>,
 *   labels:array<int,array{text:string,x:float}>,
 *   series:array<int,array{name:string,color:string,path:string,points:string}>
 * }
 */
function pickBuildWorkflowTrendChart(array $trend): array
{
    $width = 820;
    $height = 280;
    $padLeft = 42;
    $padRight = 16;
    $padTop = 14;
    $padBottom = 32;
    $plotWidth = max(1, $width - $padLeft - $padRight);
    $plotHeight = max(1, $height - $padTop - $padBottom);
    $count = max(1, count($trend));
    $xStep = $count > 1 ? ($plotWidth / ($count - 1)) : 0;
    $maxValue = 1;
    foreach ($trend as $row) {
        $maxValue = max($maxValue, (int) ($row['orders'] ?? 0), (int) ($row['picked'] ?? 0), (int) ($row['packed'] ?? 0));
    }
    $guideCount = 4;
    $guides = [];
    for ($i = 0; $i <= $guideCount; $i++) {
        $value = (int) round(($maxValue / $guideCount) * ($guideCount - $i));
        $y = $padTop + ($plotHeight / $guideCount) * $i;
        $guides[] = ['value' => $value, 'y' => round($y, 2)];
    }

    $seriesDefs = [
        'orders' => ['name' => 'Orders', 'color' => '#0f766e'],
        'picked' => ['name' => 'Picked', 'color' => '#2563eb'],
        'packed' => ['name' => 'Packed', 'color' => '#f97316'],
    ];
    $series = [];
    foreach ($seriesDefs as $key => $meta) {
        $points = [];
        foreach (array_values($trend) as $index => $row) {
            $value = (int) ($row[$key] ?? 0);
            $x = $padLeft + ($xStep * $index);
            $y = $padTop + $plotHeight - (($value / $maxValue) * $plotHeight);
            $points[] = round($x, 2) . ',' . round($y, 2);
        }
        $pointsString = implode(' ', $points);
        $series[] = [
            'name' => $meta['name'],
            'color' => $meta['color'],
            'path' => $pointsString,
            'points' => $pointsString,
        ];
    }

    $labels = [];
    foreach (array_values($trend) as $index => $row) {
        if ($index % 5 !== 0 && $index !== ($count - 1)) {
            continue;
        }
        $x = $padLeft + ($xStep * $index);
        $labels[] = [
            'text' => (string) ($row['label'] ?? ''),
            'x' => round($x, 2),
        ];
    }

    return [
        'width' => $width,
        'height' => $height,
        'max_value' => $maxValue,
        'guides' => $guides,
        'labels' => $labels,
        'series' => $series,
    ];
}

/**
 * @return array{tracker_id:int,dimensions_label:string,cycle_used_count:int,lifetime_used_count:int}
 */
function pickRecordBoxUsage(mysqli $conn, string $salesOrderNumber, string $boxIdentifier, string $dimensions, string $weightLabel, int $supplyId = 0): array
{
    if (!pickTableExists($conn, 'box_usage_tracker') || !pickTableExists($conn, 'box_usage_events')) {
        throw new RuntimeException('Box tracker tables are missing. Run inventory/sql/2026_04_01_create_box_usage_tracker.sql first.');
    }

    $dimensionsLabel = trim((string) (preg_replace('/\s+/', ' ', $dimensions) ?? $dimensions));
    $dimensionsNormalized = pickNormalizeBoxDimensions($dimensionsLabel);
    if ($dimensionsNormalized === '') {
        throw new InvalidArgumentException('Dimensions are required.');
    }

    $salesOrderNumber = trim((string) (preg_replace('/\s+/', ' ', $salesOrderNumber) ?? $salesOrderNumber));
    $boxIdentifier = trim((string) (preg_replace('/\s+/', ' ', $boxIdentifier) ?? $boxIdentifier));
    $weightLabel = trim((string) (preg_replace('/\s+/', ' ', $weightLabel) ?? $weightLabel));
    $currentUserId = pickResolveCurrentUserId($conn);
    $matchedSupplyId = max(0, $supplyId);

    $trackerId = 0;
    $cycleUsedCount = 0;
    $lifetimeUsedCount = 0;

    $conn->begin_transaction();
    try {
        $duplicateStmt = $conn->prepare('
            SELECT tracker_id
            FROM box_usage_events
            WHERE sales_order_number = ?
              AND box_identifier = ?
              AND dimensions_label = ?
            LIMIT 1
            FOR UPDATE
        ');
        if (!$duplicateStmt) {
            throw new RuntimeException('Unable to prepare box usage duplicate lookup.');
        }
        $duplicateStmt->bind_param('sss', $salesOrderNumber, $boxIdentifier, $dimensionsLabel);
        $duplicateStmt->execute();
        $duplicateRow = $duplicateStmt->get_result()->fetch_assoc() ?: null;
        $duplicateStmt->close();

        if ($duplicateRow) {
            $trackerId = (int) ($duplicateRow['tracker_id'] ?? 0);
            if ($trackerId > 0) {
                $trackerStmt = $conn->prepare('
                    SELECT id, dimensions_label, cycle_used_count, lifetime_used_count
                    FROM box_usage_tracker
                    WHERE id = ?
                    LIMIT 1
                ');
                if ($trackerStmt) {
                    $trackerStmt->bind_param('i', $trackerId);
                    $trackerStmt->execute();
                    $trackerRow = $trackerStmt->get_result()->fetch_assoc() ?: [];
                    $trackerStmt->close();
                    $dimensionsLabel = (string) ($trackerRow['dimensions_label'] ?? $dimensionsLabel);
                    $cycleUsedCount = (int) ($trackerRow['cycle_used_count'] ?? 0);
                    $lifetimeUsedCount = (int) ($trackerRow['lifetime_used_count'] ?? 0);
                }
            }
            $conn->rollback();
            return [
                'tracker_id' => $trackerId,
                'dimensions_label' => $dimensionsLabel,
                'cycle_used_count' => $cycleUsedCount,
                'lifetime_used_count' => $lifetimeUsedCount,
                'duplicate' => true,
            ];
        }

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
                SET supply_id = CASE WHEN ? > 0 THEN ? ELSE supply_id END,
                    dimensions_label = ?,
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
                'iissiissii',
                $matchedSupplyId,
                $matchedSupplyId,
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
            if ($matchedSupplyId > 0) {
                $insertStmt = $conn->prepare('
                    INSERT INTO box_usage_tracker (
                        supply_id,
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
                    ) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?, ?, NOW())
                ');
            } else {
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
            }
            if (!$insertStmt) {
                throw new RuntimeException('Unable to prepare box tracker insert.');
            }
            if ($matchedSupplyId > 0) {
                $insertStmt->bind_param(
                    'isssssii',
                    $matchedSupplyId,
                    $dimensionsLabel,
                    $dimensionsNormalized,
                    $weightLabel,
                    $salesOrderNumber,
                    $boxIdentifier,
                    $currentUserId,
                    $currentUserId
                );
            } else {
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
            }
            $insertStmt->execute();
            $trackerId = (int) $insertStmt->insert_id;
            $insertStmt->close();
        }

        $eventStmt = $conn->prepare('
            INSERT INTO box_usage_events (
                tracker_id,
                supply_id,
                sales_order_number,
                box_identifier,
                dimensions_label,
                weight_label,
                created_by_user_id
            ) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?)
        ');
        if (!$eventStmt) {
            throw new RuntimeException('Unable to prepare box usage event insert.');
        }
        $eventStmt->bind_param(
            'iissssi',
            $trackerId,
            $matchedSupplyId,
            $salesOrderNumber,
            $boxIdentifier,
            $dimensionsLabel,
            $weightLabel,
            $currentUserId
        );
        $eventStmt->execute();
        $eventStmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'tracker_id' => $trackerId,
        'dimensions_label' => $dimensionsLabel,
        'cycle_used_count' => $cycleUsedCount,
        'lifetime_used_count' => $lifetimeUsedCount,
    ];
}

/**
 * Locate the uploaded file (multipart or JSON base64) and return metadata.
 *
 * @return array{path:string,ext:string,name:string,cleanup:bool}
 */
function getUploadedFile(): array
{
    $fileField = isset($_FILES['pickFile']) ? 'pickFile' : (isset($_FILES['pdfFile']) ? 'pdfFile' : null);
    if ($fileField) {
        $file = $_FILES[$fileField];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed with error code: ' . $file['error']);
        }
        $tmpPath = $file['tmp_name'] ?? '';
        $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext === '' && is_string($tmpPath)) {
            $ext = strtolower((string) pathinfo($tmpPath, PATHINFO_EXTENSION));
        }
        return [
            'path' => $tmpPath,
            'ext' => $ext,
            'name' => $file['name'] ?? 'upload.xlsx',
            'cleanup' => false,
        ];
    }

    $raw = file_get_contents('php://input');
    $input = $raw ? json_decode($raw, true) : null;
    if (is_array($input) && isset($input['image_data'])) {
        // base64 decoding logic (kept for completeness if needed for image uploads)
        $decoded = base64_decode((string) $input['image_data'], true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 image_data payload.');
        }
        $tmpPath = tempnam(sys_get_temp_dir(), 'pick_') ?: null;
        if (!$tmpPath) {
            throw new RuntimeException('Unable to create temp file for upload.');
        }
        file_put_contents($tmpPath, $decoded);
        return [
            'path' => $tmpPath,
            'ext' => $input['extension'] ?? 'xlsx',
            'name' => $input['filename'] ?? 'upload.xlsx',
            'cleanup' => true,
        ];
    }

    throw new RuntimeException('No file uploaded.');
}

/**
 * Prepare a pared-down summary for display only.
 * (Kept to prevent errors in the view code, but does nothing.)
 */
function buildDisplaySummary(array $data): array
{
    return $data;
}

/**
 * Build the organized pick payload from an uploaded spreadsheet.
 *
 * @return array{ok:bool,message:string,data?:array,raw?:array,raw_response?:mixed}
 */
function handleOcr(): array
{
    try {
        $fileMeta = getUploadedFile();
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $extension = $fileMeta['ext'] ?? '';
    if (!in_array($extension, ['csv', 'xlsx', 'xlsm'], true)) {
        return ['ok' => false, 'message' => 'Only CSV, XLSX, and XLSM files are supported.'];
    }

    // Recorded on the import audit so the Drive importer can recognise this file later.
    $sourceChecksum = is_file($fileMeta['path']) ? (hash_file('sha256', $fileMeta['path']) ?: '') : '';

    $spreadsheet = readSpreadsheetRows($fileMeta['path'], $extension);
    if ($spreadsheet['error'] !== '') {
        return ['ok' => false, 'message' => $spreadsheet['error']];
    }

    if ($fileMeta['cleanup'] ?? false) {
        @unlink($fileMeta['path']);
    }

    $parsed = parsePickSheetRows($GLOBALS['conn'], $spreadsheet['rows'], (string) ($fileMeta['name'] ?? ''));
    if (!empty($parsed['error'])) {
        return ['ok' => false, 'message' => $parsed['error']];
    }

    $items = $parsed['items'] ?? [];
    $items = applyDbLocationMatches($GLOBALS['conn'], $items);
    usort($items, 'sortByShelfThenSku');
    $pickOrder = $parsed['pick_order_number'] ?? null;
    $salesOrder = $parsed['sales_order_number'] ?? null;
    $headers = $parsed['headers'] ?? [];
    $columnMap = $parsed['column_map'] ?? [];
    $alerts = $parsed['alerts'] ?? ($parsed['duplicate_alerts'] ?? []);

    $local = [
        'pick_order_number' => $pickOrder,
        'sales_order_number' => $salesOrder,
        'order_id' => $pickOrder,
        'source_name' => $fileMeta['name'] ?? null,
        'source_checksum' => $sourceChecksum,
        'bogo_rule' => $parsed['bogo_rule'] ?? null,
        'items' => $items,
        'audit_rows' => $parsed['audit_rows'] ?? [],
        'headers' => $headers,
        'column_map' => $columnMap,
        'alerts' => $alerts,
        'duplicate_alerts' => $alerts,
        'total_pages' => null,
        'shipping' => [],
    ];

    $item_count = count($items);

    return [
        'ok' => true,
        'message' => "Extraction successful! Sorted data displayed below. Items found: {$item_count}",
        'data' => $local,
        'alerts' => $alerts,
        'duplicate_alerts' => $alerts,
        'raw' => ['rows' => count($spreadsheet['rows'] ?? [])],
        'raw_response' => null,
    ];


}

/**
 * Output the organized pick as an Excel file.
 */
function outputOrganizedExport(array $data, bool $preferXlsx): void
{
    if (!$preferXlsx || version_compare(PHP_VERSION, '8.3.0', '<') || !class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        http_response_code(500);
        echo 'XLSX export requires PHP 8.3+ with PhpSpreadsheet installed.';
        exit;
    }

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $ss->getActiveSheet();

    $row = 1;
    $pickOrder = (string) ($data['pick_order_number'] ?? '');
    $salesOrder = (string) ($data['sales_order_number'] ?? '');

    if ($pickOrder !== '' || $salesOrder !== '') {
        $sheet->setCellValue("A{$row}", 'Pick Order #');
        $sheet->setCellValue("B{$row}", $pickOrder);
        $sheet->setCellValue("D{$row}", 'Sales Order #');
        $sheet->setCellValue("E{$row}", $salesOrder);
        $row += 2;
    }

    $headers = ['SKU', 'Description', 'Qty', 'Warehouse', 'Shelf Location'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue("{$col}{$row}", $header);
        $col++;
    }

    $row++;
    foreach (($data['items'] ?? []) as $item) {
        $sheet->setCellValue("A{$row}", (string) ($item['sku'] ?? ''));
        $sheet->setCellValue("B{$row}", (string) ($item['description'] ?? ''));
        $sheet->setCellValue("C{$row}", (string) ($item['quantity'] ?? ''));
        $sheet->setCellValue("D{$row}", implode(', ', array_filter($item['db_location_labels'] ?? [], static fn($v) => $v !== '')));
        $sheet->setCellValue("E{$row}", (string) ($item['shelf_location'] ?? ''));
        $row++;
    }

    $filename = 'organized_pick_' . gmdate('Ymd_His');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}

/**
 * Output the verified pick list as a CSV download.
 */
function outputVerifiedListCsv(array $data): void
{
    $salesOrder = trim((string) ($data['sales_order_number'] ?? $data['pick_order_number'] ?? 'order'));
    $safeOrder = preg_replace('/[^A-Za-z0-9._-]+/', '_', $salesOrder) ?: 'order';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="verified_pick_' . $safeOrder . '.csv"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        http_response_code(500);
        echo 'Unable to open CSV output stream.';
        exit;
    }

    fputcsv($out, ['Sales Order #', $salesOrder]);
    fputcsv($out, ['Exported At', gmdate('Y-m-d H:i:s') . ' UTC']);
    fputcsv($out, []);
    fputcsv($out, [
        'SKU',
        'Description',
        'Qty Ordered',
        'Picker Verified',
        'Packer Verified',
        'Short Shipped',
        'Warehouse',
        'Shelf Location',
    ]);

    foreach (($data['items'] ?? []) as $item) {
        fputcsv($out, [
            (string) ($item['sku'] ?? ''),
            (string) ($item['description'] ?? ''),
            (int) ($item['quantity'] ?? 0),
            (int) ($item['qty_verified_picker'] ?? $item['qty_verified'] ?? 0),
            (int) ($item['qty_verified_packer'] ?? 0),
            !empty($item['is_short_ship']) ? 'YES' : 'NO',
            implode(', ', array_filter($item['db_location_labels'] ?? [], static fn($v) => $v !== '')),
            (string) ($item['shelf_location'] ?? ''),
        ]);
    }

    fclose($out);
    exit;
}

// --- POST REQUEST HANDLING (Initiates the OCR/Local Process) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '') {
    $debug_info = [
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'file_uploads' => ini_get('file_uploads'),
        'max_file_uploads' => ini_get('max_file_uploads'),
    ];

    // Early check to confirm the upload reached PHP; helps isolate silent failures.
    $fileField = isset($_FILES['pickFile']) ? 'pickFile' : (isset($_FILES['pdfFile']) ? 'pdfFile' : null);
    pickDebugLog('upload_post_received', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'action' => $action,
        'file_field' => $fileField,
        'files' => array_keys($_FILES ?? []),
    ]);
    if (!$fileField || (($_FILES[$fileField]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK)) {
        $upload_error = $_FILES[$fileField]['error'] ?? 'File array missing.';
        $status_message = "File Upload Failed (Error Code: {$upload_error}). Check file size and server upload limits.";
        $is_error = true;
    } else {
        $debug_info['file_name'] = $_FILES[$fileField]['name'] ?? '';
        $debug_info['file_size_bytes'] = (string) ($_FILES[$fileField]['size'] ?? '');
        $debug_info['file_tmp'] = $_FILES[$fileField]['tmp_name'] ?? '';
        $debug_info['file_error'] = (string) ($_FILES[$fileField]['error'] ?? '');
        pickDebugLog('upload_file_ready', [
            'file_name' => $debug_info['file_name'],
            'file_size_bytes' => $debug_info['file_size_bytes'],
            'file_tmp' => $debug_info['file_tmp'],
        ]);
        $ocrResult = handleOcr();
        if ($ocrResult['ok']) {
            $parsed_data = $ocrResult['data'] ?? [];
            $_SESSION['verify_pick_data'] = $parsed_data;
            $extracted_json = json_encode($ocrResult['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parsed_json = json_encode(buildDisplaySummary($ocrResult['data'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            try {
                pickDebugLog('upload_import_begin', [
                    'order_number' => (string) ($parsed_data['sales_order_number'] ?? $parsed_data['pick_order_number'] ?? ''),
                    'item_count' => is_array($parsed_data['items'] ?? null) ? count($parsed_data['items']) : 0,
                    'source_name' => (string) ($parsed_data['source_name'] ?? ''),
                ]);
                $importResult = pickImportParsedUpload($conn, $parsed_data);
                $orderId = (int) ($importResult['order_id'] ?? 0);
                $orderNumber = (string) ($importResult['order_number'] ?? '');
                pickDebugLog('upload_import_result', [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'duplicate' => !empty($importResult['duplicate']),
                    'imported' => !empty($importResult['ok']),
                ]);
                $loaded = loadPickOrderFromDb($conn, $orderId, $orderNumber);
                if (!empty($loaded['items']) && empty($loaded['error'])) {
                    $parsed_data = $loaded;
                    $_SESSION['verify_pick_data'] = $parsed_data;
                    $status_message = !empty($importResult['duplicate'])
                        ? 'This sales order has already been entered.'
                        : 'Extraction successful. The order was saved to the database.';
                    $parsed_json = json_encode(buildDisplaySummary($loaded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } else {
                    $status_message = 'Import completed, but the order could not be reloaded from the database.';
                    $is_error = true;
                }
            } catch (Throwable $e) {
                $status_message = 'Error: ' . $e->getMessage();
                $is_error = true;
            }
        } else {
            $status_message = "Error: " . $ocrResult['message'];
            $is_error = true;
            $extracted_json = json_encode(['error' => $ocrResult['message']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
    }
}

// --- LOAD ORDER FROM DATABASE ---
// if ($action === 'load_order') {
//     $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
//     $orderNumber = isset($_GET['order_number']) ? (string) $_GET['order_number'] : null;
//     pickDebugLog('load_order_requested', [
//         'order_id' => $orderId,
//         'order_number' => $orderNumber,
//         'workflow' => (string) ($_GET['workflow'] ?? ''),
//     ]);
//     $loaded = loadPickOrderFromDb($GLOBALS['conn'], $orderId, $orderNumber);
//     if (!empty($loaded['items']) && empty($loaded['error'])) {
//         $parsed_data = $loaded;
//         $_SESSION['verify_pick_data'] = $parsed_data;
//         $status_message = 'Order loaded from database.';
//         pickDebugLog('load_order_success', [
//             'order_id' => $orderId,
//             'order_number' => $orderNumber,
//             'item_count' => is_array($loaded['items'] ?? null) ? count($loaded['items']) : 0,
//         ]);
//     } else {
//         $status_message = (string) ($loaded['error'] ?? 'No matching order found in the database.');
//         $is_error = true;
//         pickDebugLog('load_order_failed', [
//             'order_id' => $orderId,
//             'order_number' => $orderNumber,
//             'error' => $status_message,
//         ]);
//     }
// }

if ($action === 'load_order') {
    $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    $orderNumber = isset($_GET['order_number']) ? (string) $_GET['order_number'] : null;

    try {
        $loaded = loadPickOrderFromDb(
            $GLOBALS['conn'],
            $orderId,
            $orderNumber
        );
    } catch (Throwable $e) {
        http_response_code(500);

        error_log(
            '[verify_pick load_order] '
            . $e->getMessage()
            . ' | '
            . $e->getFile()
            . ':'
            . $e->getLine()
        );

        echo '<pre style="white-space:pre-wrap">';
        echo htmlspecialchars(
            $e->getMessage()
            . "\n\n"
            . $e->getFile()
            . ':'
            . $e->getLine()
        );
        echo '</pre>';
        exit;
    }

    if (!empty($loaded['items']) && empty($loaded['error'])) {
        $parsed_data = $loaded;
        $_SESSION['verify_pick_data'] = $parsed_data;
        $status_message = 'Order loaded from database.';
    } else {
        $status_message = (string) (
            $loaded['error']
            ?? 'No matching order found in the database.'
        );
        $is_error = true;
    }
}

// --- DRIVE FILE HANDLING ---
if ($action === 'drive' && isset($_GET['drive_id'])) {
    try {
        $driveMeta = downloadDriveFileToTemp($GLOBALS['DRIVE_SERVICE_ACCOUNT_JSON'], (string) $_GET['drive_id']);
        // Checksum the original download, before any PDF conversion.
        $sourceChecksum = is_file($driveMeta['path']) ? (hash_file('sha256', $driveMeta['path']) ?: '') : '';
        $extension = $driveMeta['ext'] ?? '';
        $debug_info = [
            'drive_name' => $driveMeta['name'] ?? '',
            'drive_ext' => $extension,
        ];
        if (strtolower($extension) === 'pdf') {
            if (!function_exists('adobeConvertPdfToXlsx')) {
                throw new RuntimeException('Adobe PDF conversion is not available.');
            }
            $driveMeta = adobeConvertPdfToXlsx($driveMeta['path'], (string) ($driveMeta['name'] ?? 'drive.pdf'), true);
            $extension = $driveMeta['ext'] ?? 'xlsx';
            $debug_info['converted_ext'] = $extension;
            $debug_info['converted_name'] = $driveMeta['name'] ?? '';
            $_SESSION['verify_pick_converted_path'] = $driveMeta['path'] ?? null;
            $_SESSION['verify_pick_converted_name'] = $driveMeta['name'] ?? null;
            // Keep converted XLSX available for download.
            $driveMeta['cleanup'] = false;
        }

        $spreadsheet = readSpreadsheetRows($driveMeta['path'], $extension);
        $debug_info['row_count'] = count($spreadsheet['rows'] ?? []);
        if (!empty($spreadsheet['rows'])) {
            $debug_info['row_1'] = $spreadsheet['rows'][0] ?? [];
            $debug_info['row_2'] = $spreadsheet['rows'][1] ?? [];
            $debug_info['row_3'] = $spreadsheet['rows'][2] ?? [];
        }
        if ($driveMeta['cleanup'] ?? false) {
            @unlink($driveMeta['path']);
        }
        if ($spreadsheet['error'] !== '') {
            throw new RuntimeException($spreadsheet['error']);
        }

        $parsed = parsePickSheetRows($GLOBALS['conn'], $spreadsheet['rows'], (string) ($driveMeta['name'] ?? ''));
        if (!empty($parsed['error'])) {
            throw new RuntimeException($parsed['error']);
        }

        $items = $parsed['items'] ?? [];
        $items = applyDbLocationMatches($GLOBALS['conn'], $items);
        usort($items, 'sortByShelfThenSku');

        $parsed_data = [
            'pick_order_number' => $parsed['pick_order_number'] ?? null,
            'sales_order_number' => $parsed['sales_order_number'] ?? null,
            'order_id' => $parsed['pick_order_number'] ?? null,
            'source_name' => $driveMeta['name'] ?? null,
            'source_checksum' => $sourceChecksum,
            'bogo_rule' => $parsed['bogo_rule'] ?? null,
            'items' => $items,
            'audit_rows' => $parsed['audit_rows'] ?? [],
            'headers' => $parsed['headers'] ?? [],
            'column_map' => $parsed['column_map'] ?? [],
            'alerts' => $parsed['alerts'] ?? ($parsed['duplicate_alerts'] ?? []),
            'duplicate_alerts' => $parsed['duplicate_alerts'] ?? ($parsed['alerts'] ?? []),
        ];
        $_SESSION['verify_pick_data'] = $parsed_data;

        $importResult = pickImportParsedUpload($conn, $parsed_data);
        $orderId = (int) ($importResult['order_id'] ?? 0);
        $orderNumber = (string) ($importResult['order_number'] ?? '');
        $loaded = loadPickOrderFromDb($conn, $orderId, $orderNumber);

        if (!empty($loaded['items']) && empty($loaded['error'])) {
            $parsed_data = $loaded;
            $_SESSION['verify_pick_data'] = $parsed_data;
            $status_message = !empty($importResult['duplicate'])
                ? 'This sales order has already been entered.'
                : 'Drive file imported successfully. The order and audit rows were saved to the database.';
            $parsed_json = json_encode(buildDisplaySummary($loaded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            throw new RuntimeException('Drive import completed, but the order could not be reloaded from the database.');
        }
    } catch (Throwable $e) {
        $status_message = 'Error: ' . $e->getMessage();
        $is_error = true;
    }
}

// --- DOWNLOAD HANDLER ---
if ($action === 'download') {
    $stored = $_SESSION['verify_pick_data'] ?? null;
    if (is_array($stored) && !empty($stored['items'])) {
        outputOrganizedExport($stored, true);
    }
    http_response_code(404);
    echo 'No organized data available for download. Please upload a file first.';
    exit;
}

if ($action === 'download_verified_list') {
    $orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        http_response_code(400);
        echo 'Missing order id.';
        exit;
    }

    $loaded = loadPickOrderFromDb($GLOBALS['conn'], $orderId, null);
    if (!empty($loaded['error']) || empty($loaded['items'])) {
        http_response_code(404);
        echo 'No verified pick list available for download.';
        exit;
    }

    $summary = pickOrderVerificationSummary($GLOBALS['conn'], $orderId, true);
    if (!pickSummaryIsPickerComplete($summary)) {
        http_response_code(409);
        echo 'Picker verification must be complete before saving the verified list.';
        exit;
    }

    outputVerifiedListCsv($loaded);
}

// --- EXPORT PARSED DATA (JSON) ---
if ($action === 'export_parsed') {
    $stored = $_SESSION['verify_pick_data'] ?? null;
    if (is_array($stored) && !empty($stored['items'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="verify_pick_parsed.json"');
        echo json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(404);
    echo 'No parsed data available for export. Please upload a file first.';
    exit;
}

// --- EXPORT RAW ROWS (JSON) ---
if ($action === 'export_raw_rows') {
    $path = $_SESSION['verify_pick_converted_path'] ?? null;
    if (is_string($path) && $path !== '' && is_file($path)) {
        $raw = readSpreadsheetRows($path, 'xlsx');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="verify_pick_raw_rows.json"');
        echo json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(404);
    echo 'No converted XLSX available. Please convert a PDF first.';
    exit;
}

// --- DOWNLOAD CONVERTED XLSX (from PDF) ---
if ($action === 'download_converted') {
    $path = $_SESSION['verify_pick_converted_path'] ?? null;
    $name = $_SESSION['verify_pick_converted_name'] ?? 'converted.xlsx';
    if (is_string($path) && $path !== '' && is_file($path)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . basename((string) $name) . '"');
        readfile($path);
        exit;
    }
    http_response_code(404);
    echo 'No converted XLSX available. Please convert a PDF first.';
    exit;
}

// --- VERIFY SCAN UPDATE (DB) ---
if ($action === 'prepare_box_label_print') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $salesOrderNumber = trim((string) ($payload['sales_order_number'] ?? ''));
    $boxes = $payload['boxes'] ?? null;
    if ($salesOrderNumber === '' || !is_array($boxes) || $boxes === []) {
        respondJson(['ok' => false, 'error' => 'Sales order and box labels are required.'], 400);
    }
    if (count($boxes) > 50) {
        respondJson(['ok' => false, 'error' => 'A maximum of 50 box labels can be prepared at once.'], 400);
    }

    $preparedBoxes = [];
    foreach ($boxes as $boxRow) {
        if (!is_array($boxRow)) {
            continue;
        }
        $boxIdentifier = trim((string) ($boxRow['box_identifier'] ?? ''));
        $dimensions = trim((string) ($boxRow['dimensions'] ?? ''));
        $weightLabel = trim((string) ($boxRow['weight_label'] ?? ''));
        if ($boxIdentifier === '' || $dimensions === '' || $weightLabel === '') {
            respondJson(['ok' => false, 'error' => 'Each box label needs a box id, dimensions, and weight.'], 400);
        }
        $preparedBoxes[] = [
            'box_identifier' => $boxIdentifier,
            'dimensions' => $dimensions,
            'weight_label' => $weightLabel,
        ];
    }

    if ($preparedBoxes === []) {
        respondJson(['ok' => false, 'error' => 'No valid box labels were provided.'], 400);
    }

    pickCleanupBoxLabelPrintBatches();
    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare box labels right now.'], 500);
    }
    $_SESSION['box_label_print_batches'][$token] = [
        'created_at' => time(),
        'sales_order_number' => $salesOrderNumber,
        'boxes' => $preparedBoxes,
    ];

    respondJson(['ok' => true, 'token' => $token]);
}

// --- VERIFY SCAN UPDATE (DB) ---
if ($action === 'record_box_usage') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $salesOrderNumber = trim((string) ($payload['sales_order_number'] ?? ''));
    $boxesToRecord = [];

    if (isset($payload['boxes']) && is_array($payload['boxes'])) {
        foreach ($payload['boxes'] as $boxRow) {
            if (!is_array($boxRow)) {
                continue;
            }
            $boxIdentifier = trim((string) ($boxRow['box_identifier'] ?? ''));
            $dimensions = trim((string) ($boxRow['dimensions'] ?? ''));
            $weightLabel = trim((string) ($boxRow['weight_label'] ?? ''));
            $supplyId = (int) ($boxRow['supply_id'] ?? $boxRow['selected_supply_id'] ?? 0);
            if ($boxIdentifier === '' || $dimensions === '' || $weightLabel === '') {
                respondJson(['ok' => false, 'error' => 'Each box label needs a box id, dimensions, and weight.'], 400);
            }
            $boxesToRecord[] = [
                'box_identifier' => $boxIdentifier,
                'dimensions' => $dimensions,
                'weight_label' => $weightLabel,
                'supply_id' => $supplyId,
            ];
        }
    } else {
        $dimensions = trim((string) ($payload['dimensions'] ?? ''));
        $weightLabel = trim((string) ($payload['weight_label'] ?? ''));
        $boxIdentifier = trim((string) ($payload['box_identifier'] ?? ''));
        $supplyId = (int) ($payload['supply_id'] ?? $payload['selected_supply_id'] ?? 0);
        if ($boxIdentifier === '' || $dimensions === '' || $weightLabel === '') {
            respondJson(['ok' => false, 'error' => 'Sales order, box id, dimensions, and weight are required.'], 400);
        }
        $boxesToRecord[] = [
            'box_identifier' => $boxIdentifier,
            'dimensions' => $dimensions,
            'weight_label' => $weightLabel,
            'supply_id' => $supplyId,
        ];
    }

    if ($salesOrderNumber === '' || $boxesToRecord === []) {
        respondJson(['ok' => false, 'error' => 'Sales order, box id, dimensions, and weight are required.'], 400);
    }
    if (count($boxesToRecord) > 50) {
        respondJson(['ok' => false, 'error' => 'A maximum of 50 box labels can be recorded at once.'], 400);
    }

    try {
        $lastResult = [];
        foreach ($boxesToRecord as $boxRow) {
            $lastResult = pickRecordBoxUsage(
                $conn,
                $salesOrderNumber,
                $boxRow['box_identifier'],
                $boxRow['dimensions'],
                $boxRow['weight_label'],
                (int) ($boxRow['supply_id'] ?? 0)
            );
        }
        respondJson(['ok' => true, 'recorded_count' => count($boxesToRecord)] + $lastResult);
    } catch (InvalidArgumentException $e) {
        respondJson(['ok' => false, 'error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        respondJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// --- FINALIZE PACKING (DB + SESSION) ---
if ($action === 'finalize_packing') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    $salesOrderNumber = trim((string) ($payload['sales_order_number'] ?? ''));
    $boxes = $payload['boxes'] ?? null;
    if ($orderId <= 0 || $salesOrderNumber === '' || !is_array($boxes) || $boxes === []) {
        respondJson(['ok' => false, 'error' => 'Order and packed box data are required.'], 400);
    }
    pickRequireOrderAccessJson($conn, $orderId);

    $manifestBoxes = [];
    $manifestItems = [];
    $packedQtyByKey = [];
    $expectedQtyByKey = [];
    foreach ($boxes as $boxRow) {
        if (!is_array($boxRow)) {
            continue;
        }
        $boxIdentifier = trim((string) ($boxRow['box_identifier'] ?? ''));
        $dimensions = trim((string) ($boxRow['dimensions'] ?? ''));
        $weightLabel = trim((string) ($boxRow['weight_label'] ?? ''));
        $items = $boxRow['items'] ?? [];
        if ($boxIdentifier === '' || $dimensions === '' || $weightLabel === '') {
            respondJson(['ok' => false, 'error' => 'Each box needs an id, dimensions, and weight before finalizing.'], 400);
        }
        if (!is_array($items)) {
            $items = [];
        }

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
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            if ($sourceQty <= 0) {
                respondJson(['ok' => false, 'error' => 'Packed item metadata is incomplete. Please reopen packing and try again.'], 400);
            }
            if ($itemKey === '') {
                respondJson(['ok' => false, 'error' => 'Every packed item needs a stable line key.'], 400);
            }
            if ($sourceQty > 0) {
                if (isset($expectedQtyByKey[$itemKey]) && $expectedQtyByKey[$itemKey] !== $sourceQty) {
                    respondJson(['ok' => false, 'error' => 'Packed item metadata is inconsistent across boxes.'], 400);
                }
                $expectedQtyByKey[$itemKey] = $sourceQty;
            }
            $packedQtyByKey[$itemKey] = ($packedQtyByKey[$itemKey] ?? 0) + $qty;
            $packedItems[] = [
                'key' => $itemKey,
                'sku' => $sku,
                'description' => $desc,
                'qty' => $qty,
                'source_qty' => $sourceQty,
            ];
            $manifestItems[] = [
                'key' => $itemKey,
                'box_identifier' => $boxIdentifier,
                'sku' => $sku,
                'description' => $desc,
                'qty' => $qty,
                'source_qty' => $sourceQty,
            ];
        }

        $manifestBoxes[] = [
            'box_identifier' => $boxIdentifier,
            'dimensions' => $dimensions,
            'weight_label' => $weightLabel,
            'supply_id' => (int) ($boxRow['supply_id'] ?? 0),
            'items' => $packedItems,
        ];
    }

    if ($manifestBoxes === [] || $manifestItems === []) {
        respondJson(['ok' => false, 'error' => 'Assign at least one verified item to a box before finalizing.'], 400);
    }

    // Finalize only when every verified line has been allocated exactly once across all boxes.
    foreach ($expectedQtyByKey as $itemKey => $expectedQty) {
        $packedQty = (int) ($packedQtyByKey[$itemKey] ?? 0);
        if ($packedQty !== (int) $expectedQty) {
            respondJson(['ok' => false, 'error' => 'Every verified item must be fully allocated across boxes before finalizing.'], 400);
        }
    }

    $summary = pickOrderVerificationSummary($conn, $orderId, pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer'));
    $totalItems = (int) ($summary['total'] ?? 0);
    $packedItemsCount = array_sum(array_map(static fn(array $box): int => array_sum(array_map(static fn(array $item): int => (int) ($item['qty'] ?? 0), $box['items'] ?? [])), $manifestBoxes));
    $manifestToken = '';
    try {
        pickCleanupPackingManifests();
        $manifestToken = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        error_log('[finalize_packing] manifest token generation failed: ' . $e->getMessage());
        respondJson(['ok' => false, 'error' => 'Unable to finalize packing right now.'], 500);
    }

    $manifestBatch = [
        'created_at' => time(),
        'manifest_token' => $manifestToken,
        'order_id' => $orderId,
        'sales_order_number' => $salesOrderNumber,
        'summary' => [
            'box_count' => count($manifestBoxes),
            'item_count' => $packedItemsCount,
            'ordered_count' => $totalItems,
            'unassigned_count' => max(0, $totalItems - $packedItemsCount),
        ],
        'boxes' => $manifestBoxes,
        'items' => $manifestItems,
        'finalized_by_user_id' => pickResolveCurrentUserId($conn),
    ];

    try {
        $manifestBatch = pickStorePackingManifestBatch($conn, $manifestBatch);
    } catch (Throwable $e) {
        error_log('[finalize_packing] pickStorePackingManifestBatch failed: ' . $e->getMessage());
        respondJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $_SESSION['packing_manifest_batches'][$manifestToken] = $manifestBatch;

    $boxUsageResults = [];
    try {
        foreach ($manifestBoxes as $boxRow) {
            $boxUsageResults[] = pickRecordBoxUsage(
                $conn,
                $salesOrderNumber,
                (string) ($boxRow['box_identifier'] ?? ''),
                (string) ($boxRow['dimensions'] ?? ''),
                (string) ($boxRow['weight_label'] ?? ''),
                (int) ($boxRow['supply_id'] ?? $boxRow['selected_supply_id'] ?? 0)
            );
        }
    } catch (Throwable $e) {
        error_log('[finalize_packing] pickRecordBoxUsage failed: ' . $e->getMessage());
    }

    try {
        pickLogVerifyEvent($conn, [
            'order_id' => $orderId,
            'pick_item_id' => 0,
            'workflow_stage' => 'pack',
            'event_type' => 'packing_finalized',
            'qty_delta' => 0,
            'user_id' => pickResolveCurrentUserId($conn),
            'meta' => [
                'sales_order_number' => $salesOrderNumber,
                'manifest_token' => $manifestToken,
                'box_count' => count($manifestBoxes),
                'item_count' => $packedItemsCount,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[finalize_packing] pickLogVerifyEvent failed: ' . $e->getMessage());
    }

    respondJson([
        'ok' => true,
        'token' => $manifestToken,
        'box_count' => count($manifestBoxes),
        'item_count' => $packedItemsCount,
        'summary' => $manifestBatch['summary'],
        'box_usage' => $boxUsageResults,
    ]);
}

// --- PACKING MANIFEST EXPORT ---
if ($action === 'packing_manifest') {
    $token = trim((string) ($_GET['token'] ?? ''));
    $batch = $token !== '' ? pickLoadPackingManifestBatch($conn, $token) : null;
    if (!is_array($batch)) {
        respondJson(['ok' => false, 'error' => 'Packing manifest not found or expired.'], 404);
    }

    $filename = 'packing_manifest_' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string) ($batch['sales_order_number'] ?? 'order')) . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'packing_manifest_print') {
    $token = trim((string) ($_GET['token'] ?? ''));
    $batch = $token !== '' ? pickLoadPackingManifestBatch($conn, $token) : null;
    if (!is_array($batch)) {
        http_response_code(404);
        echo 'Packing manifest not found or expired.';
        exit;
    }

    $title = 'Packing Manifest';
    $page_class = 'page-packing-manifest-print';
    $css = [];
    $so = htmlspecialchars((string) ($batch['sales_order_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $summary = $batch['summary'] ?? [];
    $boxes = is_array($batch['boxes'] ?? null) ? $batch['boxes'] : [];
    ob_start();
    ?>
    <style>
        .page-packing-manifest-print {
            font-family: Arial, Helvetica, sans-serif;
            color: #0f172a;
            background: #f6f9ff;
            padding: 20px;
        }

        .page-packing-manifest-print .manifest-sheet {
            max-width: 980px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid rgba(12, 44, 96, 0.12);
            border-radius: 16px;
            box-shadow: 0 18px 32px rgba(12, 44, 96, 0.08);
            padding: 20px;
        }

        .page-packing-manifest-print .manifest-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .page-packing-manifest-print .manifest-title {
            font-size: 1.45rem;
            font-weight: 800;
            color: #0a2e63;
            margin: 0 0 4px;
        }

        .page-packing-manifest-print .manifest-sub {
            color: #56647c;
            font-size: 0.95rem;
        }

        .page-packing-manifest-print .manifest-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .page-packing-manifest-print .manifest-pill {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 0 10px;
            border-radius: 999px;
            background: #dcecff;
            color: #0a2e63;
            font-size: 0.8rem;
            font-weight: 800;
        }

        .page-packing-manifest-print .manifest-box {
            border: 1px solid rgba(12, 44, 96, 0.12);
            border-radius: 14px;
            padding: 14px;
            margin-top: 12px;
            page-break-inside: avoid;
        }

        .page-packing-manifest-print .manifest-box-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }

        .page-packing-manifest-print .manifest-box-name {
            font-weight: 800;
            color: #0a2e63;
        }

        .page-packing-manifest-print .manifest-box-meta {
            font-size: 0.88rem;
            color: #56647c;
        }

        .page-packing-manifest-print .manifest-items {
            margin-top: 10px;
            padding-left: 18px;
            color: #163768;
            line-height: 1.35;
        }

        @media print {
            .page-packing-manifest-print {
                background: #fff;
                padding: 0;
            }

            .page-packing-manifest-print .manifest-sheet {
                box-shadow: none;
                border: 0;
                border-radius: 0;
                max-width: none;
                padding: 0;
            }
        }
    </style>
    <div class="manifest-sheet">
        <div class="manifest-head">
            <div>
                <h1 class="manifest-title">Packing Summary</h1>
                <div class="manifest-sub">Sales Order #<?= $so ?></div>
            </div>
            <div class="manifest-pills">
                <span class="manifest-pill"><?= (int) ($summary['box_count'] ?? count($boxes)) ?>
                    box<?= ((int) ($summary['box_count'] ?? count($boxes)) === 1 ? '' : 'es') ?></span>
                <span class="manifest-pill"><?= (int) ($summary['item_count'] ?? 0) ?> packed
                    item<?= ((int) ($summary['item_count'] ?? 0) === 1 ? '' : 's') ?></span>
                <span class="manifest-pill"><?= (int) ($summary['unassigned_count'] ?? 0) ?> unassigned</span>
            </div>
        </div>
        <?php foreach ($boxes as $boxRow): ?>
            <?php $items = is_array($boxRow['items'] ?? null) ? $boxRow['items'] : []; ?>
            <div class="manifest-box">
                <div class="manifest-box-head">
                    <div>
                        <div class="manifest-box-name">
                            <?= htmlspecialchars((string) ($boxRow['box_identifier'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="manifest-box-meta">
                            <?= htmlspecialchars((string) ($boxRow['dimensions'] ?? ''), ENT_QUOTES, 'UTF-8') ?> |
                            <?= htmlspecialchars((string) ($boxRow['weight_label'] . ' LBS' ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div class="manifest-pill"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></div>
                </div>
                <div class="manifest-items">
                    <?php if ($items === []): ?>
                        No items assigned.
                    <?php else: ?>
                        <ul>
                            <?php foreach ($items as $item): ?>
                                <li>
                                    <?= htmlspecialchars((string) ($item['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    <?php $desc = trim((string) ($item['description'] ?? '')); ?>
                                    <?php if ($desc !== ''): ?>
                                        - <?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                    (<?= (int) ($item['qty'] ?? 0) ?>)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 150));
    </script>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/templates/layout.php';
    exit;
}

// --- VERIFY EVENT LOG (DB) ---
if ($action === 'log_verify_event') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }
    $orderId = (int) ($payload['order_id'] ?? 0);
    if ($orderId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order'], 400);
    }
    pickRequireOrderAccessJson($conn, $orderId);
    pickLogVerifyEvent($conn, $payload);
    respondJson(['ok' => true]);
}

// --- PRIORITY AND PICK/PACK NOTES ---
if (in_array($action, ['set_order_priority', 'add_order_note', 'get_order_notes', 'acknowledge_order_note'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : [];
    if (!is_array($payload)) {
        $payload = [];
    }
    $orderId = (int) ($payload['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order.'], 400);
    }
    pickRequireOrderExistsJson($conn, $orderId);

    if ($action === 'get_order_notes') {
        respondJson(['ok' => true, 'notes' => pickFetchOrderNotes($conn, $orderId)]);
    }

    if ($action === 'set_order_priority') {
        $isPriority = !empty($payload['is_priority']) ? 1 : 0;
        $stmt = $conn->prepare('UPDATE pick_orders SET is_priority = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
        if (!$stmt) {
            respondJson(['ok' => false, 'error' => 'Unable to prepare priority update.'], 500);
        }
        $stmt->bind_param('ii', $isPriority, $orderId);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            respondJson(['ok' => false, 'error' => 'Unable to update priority.'], 500);
        }
        respondJson(['ok' => true, 'is_priority' => $isPriority]);
    }

    $userId = pickResolveCurrentUserId($conn);
    if ($action === 'add_order_note') {
        $note = trim((string) ($payload['note'] ?? ''));
        $stage = strtolower(trim((string) ($payload['workflow_stage'] ?? 'pick')));
        $stage = in_array($stage, ['pick', 'pack'], true) ? $stage : 'pick';
        if ($note === '') {
            respondJson(['ok' => false, 'error' => 'Note text is required.'], 400);
        }
        if (mb_strlen($note) > 2000) {
            respondJson(['ok' => false, 'error' => 'Notes are limited to 2,000 characters.'], 400);
        }
        $existingStmt = $conn->prepare('SELECT id FROM pick_order_notes WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $existingNoteId = 0;
        if ($existingStmt) {
            $existingStmt->bind_param('i', $orderId);
            $existingStmt->execute();
            $existingRow = $existingStmt->get_result()->fetch_assoc() ?: null;
            $existingNoteId = (int) ($existingRow['id'] ?? 0);
            $existingStmt->close();
        }

        if ($existingNoteId > 0) {
            $stmt = $conn->prepare('UPDATE pick_order_notes SET note = ?, created_by_user_id = ?, workflow_stage = ?, created_at = NOW(), acknowledged_at = NULL WHERE id = ? AND order_id = ? LIMIT 1');
        } else {
            $stmt = $conn->prepare('INSERT INTO pick_order_notes (order_id, note, created_by_user_id, workflow_stage) VALUES (?, ?, ?, ?)');
        }
        if (!$stmt) {
            respondJson(['ok' => false, 'error' => 'Unable to prepare note.'], 500);
        }
        if ($existingNoteId > 0) {
            $stmt->bind_param('sisii', $note, $userId, $stage, $existingNoteId, $orderId);
        } else {
            $stmt->bind_param('isis', $orderId, $note, $userId, $stage);
        }
        $ok = $stmt->execute();
        $noteId = $existingNoteId > 0 ? $existingNoteId : (int) $stmt->insert_id;
        $stmt->close();
        if (!$ok) {
            respondJson(['ok' => false, 'error' => 'Unable to save note.'], 500);
        }
        respondJson(['ok' => true, 'note_id' => $noteId, 'notes' => pickFetchOrderNotes($conn, $orderId)]);
    }

    $noteId = (int) ($payload['note_id'] ?? 0);
    if ($noteId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing note.'], 400);
    }
    $stmt = $conn->prepare('UPDATE pick_order_notes SET acknowledged_at = COALESCE(acknowledged_at, NOW()) WHERE id = ? AND order_id = ? LIMIT 1');
    if (!$stmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare note acknowledgement.'], 500);
    }
    $stmt->bind_param('ii', $noteId, $orderId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        respondJson(['ok' => false, 'error' => 'Unable to acknowledge note.'], 500);
    }
    respondJson(['ok' => true, 'notes' => pickFetchOrderNotes($conn, $orderId)]);
}

// --- VERIFY SCAN UPDATE (DB) ---
if ($action === 'verify_scan') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    $modeRaw = strtolower(trim((string) ($payload['mode'] ?? 'picker')));
    $mode = $modeRaw === 'packer' ? 'packer' : 'picker';
    $updates = $payload['updates'] ?? [];
    if ($orderId <= 0 || !is_array($updates) || $updates === []) {
        respondJson(['ok' => false, 'error' => 'Missing order or updates'], 400);
    }
    pickRequireOrderAccessJson($conn, $orderId);

    $hasPackerVerify = pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer');
    if ($mode === 'packer' && !$hasPackerVerify) {
        respondJson(['ok' => false, 'error' => 'Packer verification is not enabled for this environment.'], 400);
    }
    if ($mode === 'packer') {
        $packSummary = pickOrderVerificationSummary($conn, $orderId, true);
        if (!pickSummaryIsPickerComplete($packSummary)) {
            respondJson(['ok' => false, 'error' => 'This order is not ready for pack verification.'], 400);
        }
    }
    $verifyColumn = $mode === 'packer' ? 'qty_verified_packer' : 'qty_verified';
    $selectSql = "
        SELECT qty_ordered, qty_verified, qty_verified_packer, COALESCE(is_short_ship, 0) AS is_short_ship
        FROM pick_items
        WHERE id = ? AND order_id = ?
        LIMIT 1
        FOR UPDATE
    ";
    $updateSql = "
        UPDATE pick_items
        SET {$verifyColumn} = {$verifyColumn} + ?
        WHERE id = ? AND order_id = ?
    ";
    $selectStmt = $conn->prepare($selectSql);
    $updateStmt = $conn->prepare($updateSql);
    if (!$selectStmt || !$updateStmt) {
        if ($selectStmt) {
            $selectStmt->close();
        }
        if ($updateStmt) {
            $updateStmt->close();
        }
        respondJson(['ok' => false, 'error' => 'Unable to prepare update'], 500);
    }

    $applied = 0;
    $appliedUpdates = [];
    $conn->begin_transaction();
    try {
        foreach ($updates as $update) {
            $itemId = (int) ($update['item_id'] ?? 0);
            $delta = (int) ($update['delta'] ?? 0);
            if ($itemId <= 0 || $delta <= 0) {
                continue;
            }

            $selectStmt->bind_param('ii', $itemId, $orderId);
            $selectStmt->execute();
            $rowResult = $selectStmt->get_result();
            $itemRow = $rowResult ? $rowResult->fetch_assoc() : null;
            if (!$itemRow) {
                continue;
            }

            $qtyOrdered = (int) ($itemRow['qty_ordered'] ?? 0);
            $currentVerified = $mode === 'packer'
                ? (int) ($itemRow['qty_verified_packer'] ?? 0)
                : (int) ($itemRow['qty_verified'] ?? 0);
            $remaining = max(0, $qtyOrdered - $currentVerified);
            if ($delta > $remaining) {
                throw new RuntimeException('Scan exceeds the remaining quantity for one or more lines.');
            }
            $appliedDelta = min($delta, $remaining);
            if ($appliedDelta <= 0) {
                continue;
            }

            $updateStmt->bind_param('iii', $appliedDelta, $itemId, $orderId);
            $updateStmt->execute();
            if ($updateStmt->affected_rows > 0) {
                $applied += $appliedDelta;
                $appliedUpdates[] = [
                    'item_id' => $itemId,
                    'delta' => $appliedDelta,
                ];
            }
        }
        $conn->commit();
    } catch (RuntimeException $e) {
        $conn->rollback();
        $selectStmt->close();
        $updateStmt->close();
        respondJson(['ok' => false, 'error' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        $conn->rollback();
        $selectStmt->close();
        $updateStmt->close();
        respondJson(['ok' => false, 'error' => 'Unable to save verification.'], 500);
    }
    $selectStmt->close();
    $updateStmt->close();

    $summary = pickOrderVerificationSummary($conn, $orderId, $hasPackerVerify);
    $currentStatus = pickFetchOrderStatus($conn, $orderId);
    $status = pickResolveScanDrivenOrderStatus($currentStatus, $summary);
    $total = (int) ($summary['total'] ?? 0);
    $pickerVerified = (int) ($summary['picker_verified'] ?? 0);
    $packerVerified = (int) ($summary['packer_verified'] ?? 0);
    $activeVerified = $mode === 'packer' ? $packerVerified : $pickerVerified;

    $scanUserId = $applied > 0 ? pickResolveCurrentUserId($conn) : 0;
    $orderSet = ['status = ?'];
    $bindTypes = 's';
    $bindValues = [$status];
    if ($scanUserId > 0) {
        if (pickTableColumnExists($conn, 'pick_orders', 'verified_at')) {
            $orderSet[] = 'verified_at = NOW()';
        }

        $modeUserColumn = $mode === 'packer'
            ? pickFirstExistingColumn($conn, 'pick_orders', ['packer_verified_by_user_id', 'pack_verified_by_user_id'])
            : pickFirstExistingColumn($conn, 'pick_orders', ['picker_verified_by_user_id', 'pick_verified_by_user_id']);
        if ($modeUserColumn !== null) {
            $orderSet[] = "{$modeUserColumn} = ?";
            $bindTypes .= 'i';
            $bindValues[] = $scanUserId;
        }
    }
    $bindTypes .= 'i';
    $bindValues[] = $orderId;
    $orderSql = "UPDATE pick_orders SET " . implode(', ', $orderSet) . " WHERE id = ?";
    $updStmt = $conn->prepare($orderSql);
    if ($updStmt) {
        pickBindParam($updStmt, $bindTypes, $bindValues);
        $updStmt->execute();
        $updStmt->close();
    } else {
        $fallback = $conn->prepare("UPDATE pick_orders SET status = ? WHERE id = ?");
        if ($fallback) {
            $fallback->bind_param('si', $status, $orderId);
            $fallback->execute();
            $fallback->close();
        }
    }

    if ($applied > 0) {
        $firstUpdate = is_array($appliedUpdates[0] ?? null) ? $appliedUpdates[0] : [];
        pickLogVerifyEvent($conn, [
            'order_id' => $orderId,
            'pick_item_id' => (int) ($firstUpdate['item_id'] ?? 0),
            'workflow_stage' => $mode === 'packer' ? 'pack' : 'pick',
            'event_type' => 'scan_success',
            'sku_scanned' => '',
            'expected_sku' => '',
            'qty_delta' => $applied,
            'user_id' => $scanUserId,
            'meta' => [
                'mode' => $mode,
                'updates_count' => count($updates),
                'applied' => $applied,
                'status' => $status,
            ],
        ]);
    }

    respondJson([
        'ok' => true,
        'applied' => $applied,
        'mode' => $mode,
        'status' => $status,
        'verified' => $activeVerified,
        'picker_verified' => $pickerVerified,
        'packer_verified' => $packerVerified,
        'total' => $total,
        'capped' => false,
        'applied_updates' => $appliedUpdates,
    ]);
}

// --- RESET VERIFIED QTY (DB) ---
if ($action === 'reset_verified') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    $modeRaw = strtolower(trim((string) ($payload['mode'] ?? 'picker')));
    $mode = $modeRaw === 'packer' ? 'packer' : 'picker';
    $hasItemIds = array_key_exists('item_ids', $payload);
    $itemIds = $payload['item_ids'] ?? [];
    if ($orderId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order'], 400);
    }
    pickRequireOrderAccessJson($conn, $orderId);
    $hasPackerVerify = pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer');
    if ($mode === 'packer' && !$hasPackerVerify) {
        respondJson(['ok' => false, 'error' => 'Packer verification is not enabled for this environment.'], 400);
    }
    if ($mode === 'packer') {
        $packSummary = pickOrderVerificationSummary($conn, $orderId, true);
        if (!pickSummaryIsPickerComplete($packSummary)) {
            respondJson(['ok' => false, 'error' => 'This order is not ready for pack verification.'], 400);
        }
    }
    $verifyColumn = $mode === 'packer' ? 'qty_verified_packer' : 'qty_verified';

    $itemIds = is_array($itemIds) ? array_values(array_filter(array_map('intval', $itemIds), static fn($v) => $v > 0)) : [];
    if ($hasItemIds && $itemIds === []) {
        $resetRows = 0;
    } elseif ($itemIds !== []) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $types = 'i' . str_repeat('i', count($itemIds));
        $sql = "
            UPDATE pick_items
            SET {$verifyColumn} = 0
            WHERE order_id = ? AND id IN ({$placeholders}) AND {$verifyColumn} <> 0
        ";
        $stmt = $conn->prepare($sql);
    } else {
        $stmt = $conn->prepare("
            UPDATE pick_items
            SET {$verifyColumn} = 0
            WHERE order_id = ? AND {$verifyColumn} <> 0
        ");
        $types = 'i';
    }
    if (!isset($resetRows)) {
        if (!$stmt) {
            respondJson(['ok' => false, 'error' => 'Unable to prepare reset'], 500);
        }
        $bindValues = array_merge([$orderId], $itemIds);
        pickBindParam($stmt, $types, $bindValues);
        $stmt->execute();
        $resetRows = (int) $stmt->affected_rows;
        $stmt->close();
    }

    $summary = pickOrderVerificationSummary($conn, $orderId, $hasPackerVerify);
    $currentStatus = pickFetchOrderStatus($conn, $orderId);
    $status = pickResolveScanDrivenOrderStatus($currentStatus, $summary);
    $total = (int) ($summary['total'] ?? 0);
    $pickerVerified = (int) ($summary['picker_verified'] ?? 0);
    $packerVerified = (int) ($summary['packer_verified'] ?? 0);

    if ($status === 'COMPLETED') {
        $updStmt = $conn->prepare("UPDATE pick_orders SET status = ? WHERE id = ?");
        if ($updStmt) {
            $updStmt->bind_param('si', $status, $orderId);
            $updStmt->execute();
            $updStmt->close();
        }
    } else {
        $updStmt = $conn->prepare("UPDATE pick_orders SET status = ? WHERE id = ?");
        if ($updStmt) {
            $updStmt->bind_param('si', $status, $orderId);
            $updStmt->execute();
            $updStmt->close();
        }
    }

    pickLogVerifyEvent($conn, [
        'order_id' => $orderId,
        'pick_item_id' => 0,
        'workflow_stage' => $mode === 'packer' ? 'pack' : 'pick',
        'event_type' => 'reset_verified',
        'qty_delta' => 0,
        'user_id' => pickResolveCurrentUserId($conn),
        'meta' => [
            'mode' => $mode,
            'reset_rows' => $resetRows,
            'partial_reset' => $hasItemIds,
        ],
    ]);

    respondJson([
        'ok' => true,
        'mode' => $mode,
        'reset_rows' => $resetRows,
        'status' => $status,
        'total' => $total,
        'picker_verified' => $pickerVerified,
        'packer_verified' => $packerVerified,
    ]);
}

// --- CLAIM ORDER (DB) ---
if ($action === 'claim_order') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }
    $orderId = (int) ($payload['order_id'] ?? 0);
    if ($orderId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order'], 400);
    }
    $userId = pickResolveCurrentUserId($conn);
    if ($userId <= 0) {
        respondJson(['ok' => false, 'error' => 'Unable to resolve current user'], 400);
    }
    pickRequireOrderClaimJson($conn, $orderId);
    $stmt = $conn->prepare("UPDATE pick_orders SET assigned_user_id = ? WHERE id = ?");
    if (!$stmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare claim'], 500);
    }
    $stmt->bind_param('ii', $userId, $orderId);
    $stmt->execute();
    $stmt->close();
    respondJson(['ok' => true, 'assigned_user_id' => $userId]);
}

// --- ASSIGN ORDER (DB) ---
if ($action === 'assign_order') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }
    $orderId = (int) ($payload['order_id'] ?? 0);
    $userId = (int) ($payload['user_id'] ?? 0);
    if ($orderId <= 0 || $userId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order or user'], 400);
    }
    pickRequireOrderAssignJson();
    pickRequireOrderAccessJson($conn, $orderId);
    $stmt = $conn->prepare("UPDATE pick_orders SET assigned_user_id = ? WHERE id = ?");
    if (!$stmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare assignment'], 500);
    }
    $stmt->bind_param('ii', $userId, $orderId);
    $stmt->execute();
    $stmt->close();
    respondJson(['ok' => true, 'assigned_user_id' => $userId]);
}

// --- DELETE ORDER (DB) ---
if ($action === 'delete_pick_order') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    $reason = trim((string) ($payload['reason'] ?? ''));
    if ($orderId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order'], 400);
    }
    if ($reason === '') {
        respondJson(['ok' => false, 'error' => 'Deletion reason is required'], 400);
    }

    $currentUserRoleId = pickResolveCurrentRoleId();
    if (!in_array($currentUserRoleId, [1, 2], true)) {
        respondJson(['ok' => false, 'error' => 'Only managers or admins can delete sales orders.'], 403);
    }

    pickRequireOrderAccessJson($conn, $orderId);

    $orderStmt = $conn->prepare('SELECT id, order_number, status, source_file FROM pick_orders WHERE id = ? LIMIT 1');
    if (!$orderStmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare order lookup'], 500);
    }
    $orderStmt->bind_param('i', $orderId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc() ?: null;
    $orderStmt->close();
    if (!is_array($order)) {
        respondJson(['ok' => false, 'error' => 'Order not found'], 404);
    }

    $currentUserId = pickResolveCurrentUserId($conn);
    $orderNumber = (string) ($order['order_number'] ?? '');
    $status = (string) ($order['status'] ?? '');

    pickDebugLog('delete_pick_order_requested', [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'status' => $status,
        'source_file' => (string) ($order['source_file'] ?? ''),
        'reason' => $reason,
        'user_id' => $currentUserId,
    ]);

    $conn->begin_transaction();
    try {
        error_log('[verify_pick] order delete requested: order_id=' . $orderId
            . ', order_number=' . $orderNumber
            . ', status=' . $status
            . ', user_id=' . $currentUserId
            . ', reason=' . $reason);

        if (pickTableExists($conn, 'pick_verify_events')) {
            $deleteEventsStmt = $conn->prepare('DELETE FROM pick_verify_events WHERE order_id = ?');
            if (!$deleteEventsStmt) {
                throw new RuntimeException('Unable to prepare event cleanup.');
            }
            $deleteEventsStmt->bind_param('i', $orderId);
            if (!$deleteEventsStmt->execute()) {
                $error = $deleteEventsStmt->error ?: $conn->error ?: 'Unable to delete order events.';
                $deleteEventsStmt->close();
                throw new RuntimeException($error);
            }
            $deleteEventsStmt->close();
        }

        pickDeletePackingManifestsForOrder($conn, $orderId);
        pickClearPackingManifestBatchesForOrder($orderId);

        $deleteItemsStmt = $conn->prepare('DELETE FROM pick_items WHERE order_id = ?');
        if (!$deleteItemsStmt) {
            throw new RuntimeException('Unable to prepare item cleanup.');
        }
        $deleteItemsStmt->bind_param('i', $orderId);
        if (!$deleteItemsStmt->execute()) {
            $error = $deleteItemsStmt->error ?: $conn->error ?: 'Unable to delete order items.';
            $deleteItemsStmt->close();
            throw new RuntimeException($error);
        }
        $deleteItemsStmt->close();

        $deleteOrderStmt = $conn->prepare('DELETE FROM pick_orders WHERE id = ? LIMIT 1');
        if (!$deleteOrderStmt) {
            throw new RuntimeException('Unable to prepare order delete.');
        }
        $deleteOrderStmt->bind_param('i', $orderId);
        if (!$deleteOrderStmt->execute()) {
            $error = $deleteOrderStmt->error ?: $conn->error ?: 'Unable to delete order.';
            $deleteOrderStmt->close();
            throw new RuntimeException($error);
        }
        if ((int) $deleteOrderStmt->affected_rows !== 1) {
            $deleteOrderStmt->close();
            throw new RuntimeException('Order delete failed.');
        }
        $deleteOrderStmt->close();

        if (isset($_SESSION['verify_pick_data']) && is_array($_SESSION['verify_pick_data'])) {
            $sessionOrderId = (int) ($_SESSION['verify_pick_data']['db_order_id'] ?? $_SESSION['verify_pick_data']['id'] ?? 0);
            if ($sessionOrderId === $orderId) {
                unset($_SESSION['verify_pick_data']);
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        pickDebugLog('delete_pick_order_failed', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'reason' => $reason,
            'error' => $e->getMessage(),
        ]);
        error_log('[verify_pick] delete_pick_order failed: ' . $e->getMessage());
        respondJson([
            'ok' => false,
            'error' => 'Unable to delete order: ' . $e->getMessage(),
        ], 500);
    }

    pickDebugLog('delete_pick_order_completed', [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'reason' => $reason,
    ]);

    respondJson([
        'ok' => true,
        'deleted_order_id' => $orderId,
        'order_number' => $orderNumber,
        'redirect' => 'verify_pick.php',
    ]);
}

// --- REOPEN PACK (DB) ---
if ($action === 'reopen_pack') {
    $orderId = (int) ($_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order'], 400);
    }

    pickRequireOrderAccessJson($conn, $orderId);

    $currentStatus = pickFetchOrderStatus($conn, $orderId);
    pickDebugLog('reopen_pack_requested', [
        'order_id' => $orderId,
        'current_status' => $currentStatus,
    ]);
    if (!in_array($currentStatus, ['COMPLETED', 'COMPLETED_SHORT_SHIP', 'READY_TO_PACK'], true)) {
        respondJson(['ok' => false, 'error' => 'Order is not in a reopenable pack state.'], 400);
    }

    $status = 'READY_TO_PACK';
    $orderSet = ['status = ?'];
    if (pickTableColumnExists($conn, 'pick_orders', 'verified_at')) {
        $orderSet[] = 'verified_at = NULL';
    }
    $packerUserColumn = pickFirstExistingColumn($conn, 'pick_orders', ['packer_verified_by_user_id', 'pack_verified_by_user_id']);
    if ($packerUserColumn !== null) {
        $orderSet[] = "{$packerUserColumn} = NULL";
    }

    $conn->begin_transaction();
    try {
        if (pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer')) {
            $resetPackerStmt = $conn->prepare('UPDATE pick_items SET qty_verified_packer = 0 WHERE order_id = ?');
            if (!$resetPackerStmt) {
                throw new RuntimeException('Unable to prepare pack reset.');
            }
            $resetPackerStmt->bind_param('i', $orderId);
            $resetPackerStmt->execute();
            $resetPackerStmt->close();
        }

        pickDeletePackingManifestsForOrder($conn, $orderId);

        $sql = 'UPDATE pick_orders SET ' . implode(', ', $orderSet) . ' WHERE id = ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare reopen status update.');
        }
        $stmt->bind_param('si', $status, $orderId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respondJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    pickClearPackingManifestBatchesForOrder($orderId);

    respondJson([
        'ok' => true,
        'status' => $status,
        'redirect' => 'verify_pick.php?workflow=pack',
        'reopened_pack' => true,
    ]);
}

// --- SET ORDER STATUS (DB) ---
if ($action === 'set_order_status') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!is_array($payload) || $payload === []) {
        $payload = [
            'order_id' => (int) ($_GET['order_id'] ?? 0),
            'status' => (string) ($_GET['status'] ?? ''),
            'mode' => (string) ($_GET['mode'] ?? ''),
        ];
    }
    if (!is_array($payload) || !isset($payload['order_id'], $payload['status'])) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }
    $orderId = (int) ($payload['order_id'] ?? 0);
    $requestedStatus = strtoupper(trim((string) ($payload['status'] ?? '')));
    $modeRaw = strtolower(trim((string) ($payload['mode'] ?? 'picker')));
    $mode = $modeRaw === 'packer' ? 'packer' : 'picker';
    pickDebugLog('set_order_status_requested', [
        'order_id' => $orderId,
        'requested_status' => $requestedStatus,
        'mode' => $mode,
        'payload_keys' => array_keys(is_array($payload) ? $payload : []),
    ]);
    if ($orderId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order'], 400);
    }
    pickRequireOrderAccessJson($conn, $orderId);
    if ($requestedStatus !== 'COMPLETED' && $requestedStatus !== 'IN_PROGRESS' && $requestedStatus !== 'READY_TO_PACK') {
        respondJson(['ok' => false, 'error' => 'Invalid status'], 400);
    }

    try {
        $summary = pickOrderVerificationSummary($conn, $orderId, pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer'));
        if ($requestedStatus === 'READY_TO_PACK' || ($requestedStatus === 'COMPLETED' && $mode === 'picker')) {
            if (!pickSummaryIsPickerComplete($summary)) {
                respondJson(['ok' => false, 'error' => 'Picker verification is not complete yet.'], 400);
            }
            pickEnsureOrderStatusSupportsReadyToPack($conn);
        }
        $currentStatus = pickFetchOrderStatus($conn, $orderId);
        $status = $requestedStatus === 'COMPLETED' && $mode === 'picker'
            ? 'READY_TO_PACK'
            : $requestedStatus;
        $reopenPackVerification = in_array($requestedStatus, ['IN_PROGRESS', 'READY_TO_PACK'], true)
            && $mode === 'packer'
            && in_array($currentStatus, ['COMPLETED', 'COMPLETED_SHORT_SHIP'], true);
        if ($reopenPackVerification) {
            $status = 'READY_TO_PACK';
        }
        if ($requestedStatus === 'COMPLETED') {
            if ($mode === 'packer' && !pickSummaryIsPackerComplete($summary)) {
                respondJson(['ok' => false, 'error' => 'Pack verification is not complete yet.'], 400);
            }
            $status = $mode === 'packer' ? 'COMPLETED' : 'READY_TO_PACK';
        }
        $orderSet = ['status = ?'];
        if ($requestedStatus === 'COMPLETED' && pickTableColumnExists($conn, 'pick_orders', 'verified_at')) {
            $orderSet[] = 'verified_at = NOW()';
        }
        if ($reopenPackVerification && pickTableColumnExists($conn, 'pick_orders', 'verified_at')) {
            $orderSet[] = 'verified_at = NULL';
        }
        if ($reopenPackVerification) {
            $packerUserColumn = pickFirstExistingColumn($conn, 'pick_orders', ['packer_verified_by_user_id', 'pack_verified_by_user_id']);
            if ($packerUserColumn !== null) {
                $orderSet[] = "{$packerUserColumn} = NULL";
            }
            if (pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer')) {
                $resetPackerStmt = $conn->prepare("UPDATE pick_items SET qty_verified_packer = 0 WHERE order_id = ?");
                if (!$resetPackerStmt) {
                    respondJson(['ok' => false, 'error' => 'Unable to prepare pack reset'], 500);
                }
                $resetPackerStmt->bind_param('i', $orderId);
                $resetPackerStmt->execute();
                $resetPackerStmt->close();
            }
        }
        $sql = "UPDATE pick_orders SET " . implode(', ', $orderSet) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            respondJson(['ok' => false, 'error' => 'Unable to prepare status update'], 500);
        }

        $stmt->bind_param('si', $status, $orderId);
        $stmt->execute();
        $stmt->close();
        pickDebugLog('set_order_status_written', [
            'order_id' => $orderId,
            'requested_status' => $requestedStatus,
            'resolved_status' => $status,
            'current_status' => $currentStatus,
            'reopen_pack_verification' => $reopenPackVerification,
            'mode' => $mode,
        ]);

        if ($requestedStatus === 'READY_TO_PACK' || $requestedStatus === 'COMPLETED') {
            $eventType = $requestedStatus === 'READY_TO_PACK'
                ? 'order_ready_to_pack'
                : ($status === 'COMPLETED_SHORT_SHIP' ? 'order_completed_short_ship' : 'order_completed');
            pickLogVerifyEvent($conn, [
                'order_id' => $orderId,
                'pick_item_id' => 0,
                'workflow_stage' => $mode === 'packer' ? 'pack' : 'pick',
                'event_type' => $eventType,
                'qty_delta' => 0,
                'user_id' => pickResolveCurrentUserId($conn),
                'meta' => [
                    'status' => $requestedStatus,
                    'resolved_status' => $status,
                    'mode' => $mode,
                ],
            ]);
        }

        $response = ['ok' => true, 'status' => $status];
        if ($reopenPackVerification) {
            $response['redirect'] = 'verify_pick.php?workflow=pack';
            $response['reopened_pack'] = true;
        }

        respondJson($response);
    } catch (Throwable $e) {
        error_log('[verify_pick] set_order_status failed: ' . $e->getMessage());
        respondJson([
            'ok' => false,
            'error' => 'Unable to update order status: ' . $e->getMessage(),
        ], 500);
    }
}

// --- BULK COMPLETE ORDERS (DB) ---
if ($action === 'bulk_complete_orders') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    pickRequireAdminManagerJson();

    $orderIds = $payload['order_ids'] ?? [];
    if (!is_array($orderIds) || $orderIds === []) {
        respondJson(['ok' => false, 'error' => 'Select at least one order.'], 400);
    }

    try {
        $result = pickBulkCompleteOrders($conn, $orderIds);
        respondJson(['ok' => true] + $result);
    } catch (InvalidArgumentException $e) {
        respondJson(['ok' => false, 'error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('[verify_pick] bulk_complete_orders failed: ' . $e->getMessage());
        respondJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// --- BULK DELETE ORDERS (DB) ---
if ($action === 'bulk_delete_orders') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    pickRequireAdminManagerJson();

    $orderIds = array_values(array_unique(array_filter(
        array_map('intval', $payload['order_ids'] ?? []),
        static fn(int $id): bool => $id > 0
    )));
    $reason = trim((string) ($payload['reason'] ?? ''));
    if ($orderIds === []) {
        respondJson(['ok' => false, 'error' => 'Select at least one order.'], 400);
    }
    if ($reason === '') {
        respondJson(['ok' => false, 'error' => 'Deletion reason is required.'], 400);
    }

    $deletedIds = [];
    $conn->begin_transaction();
    try {
        foreach ($orderIds as $orderId) {
            // Confirm the order exists before deleting any of its related rows.
            $order = pickFetchOrderAccessRow($conn, $orderId);
            if ($order === null) {
                throw new RuntimeException("Order {$orderId} was not found.");
            }

            if (pickTableExists($conn, 'pick_verify_events')) {
                $stmt = $conn->prepare('DELETE FROM pick_verify_events WHERE order_id = ?');
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare event cleanup.');
                }
                $stmt->bind_param('i', $orderId);
                $stmt->execute();
                $stmt->close();
            }

            pickDeletePackingManifestsForOrder($conn, $orderId);
            pickClearPackingManifestBatchesForOrder($orderId);

            $stmt = $conn->prepare('DELETE FROM pick_items WHERE order_id = ?');
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare item cleanup.');
            }
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('DELETE FROM pick_orders WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare order delete.');
            }
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $wasDeleted = (int) $stmt->affected_rows === 1;
            $stmt->close();
            if (!$wasDeleted) {
                throw new RuntimeException("Order {$orderId} could not be deleted.");
            }

            $deletedIds[] = $orderId;
            pickDebugLog('bulk_delete_pick_order', [
                'order_id' => $orderId,
                'reason' => $reason,
                'user_id' => pickResolveCurrentUserId($conn),
            ]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[verify_pick] bulk_delete_orders failed: ' . $e->getMessage());
        respondJson(['ok' => false, 'error' => 'Unable to delete selected orders: ' . $e->getMessage()], 500);
    }

    respondJson([
        'ok' => true,
        'deleted_count' => count($deletedIds),
        'deleted_ids' => $deletedIds,
    ]);
}

// --- RETURN ORDER TO PICK (DB) ---
if ($action === 'return_to_pick') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }
    $orderId = (int) ($payload['order_id'] ?? 0);
    if ($orderId <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order'], 400);
    }

    pickRequireOrderAccessJson($conn, $orderId);

    $packerColumnExists = pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer');
    if (!$packerColumnExists) {
        respondJson(['ok' => false, 'error' => 'Verification columns are not available for this order.'], 400);
    }

    $itemSet = [];
    if ($packerColumnExists) {
        $itemSet[] = 'qty_verified_packer = 0';
    }
    $itemSql = "UPDATE pick_items SET " . implode(', ', $itemSet) . " WHERE order_id = ?";
    $itemStmt = $conn->prepare($itemSql);
    if (!$itemStmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare return-to-pick reset'], 500);
    }
    $itemStmt->bind_param('i', $orderId);
    $itemStmt->execute();
    $itemStmt->close();

    $status = 'PENDING';
    $orderStmt = $conn->prepare("UPDATE pick_orders SET status = ? WHERE id = ?");
    if (!$orderStmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare order update'], 500);
    }
    $orderStmt->bind_param('si', $status, $orderId);
    $orderStmt->execute();
    $orderStmt->close();

    pickLogVerifyEvent($conn, [
        'order_id' => $orderId,
        'pick_item_id' => 0,
        'workflow_stage' => 'pack',
        'event_type' => 'send_back_to_pick',
        'qty_delta' => 0,
        'user_id' => pickResolveCurrentUserId($conn),
        'meta' => [
            'status' => $status,
        ],
    ]);

    respondJson([
        'ok' => true,
        'status' => $status,
        'redirect' => 'verify_pick.php',
    ]);
}

// --- SET SHORT SHIP (DB) ---
if ($action === 'set_short_ship') {
    try {
        $raw = file_get_contents('php://input');
        $payload = $raw ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
        }

        $orderId = (int) ($payload['order_id'] ?? 0);
        $itemId = (int) ($payload['item_id'] ?? 0);
        $shortShip = !empty($payload['short_ship']) ? 1 : 0;
        $mode = strtolower(trim((string) ($payload['mode'] ?? 'picker')));
        $mode = $mode === 'packer' ? 'packer' : 'picker';
        if ($orderId <= 0 || $itemId <= 0) {
            respondJson(['ok' => false, 'error' => 'Missing order or item'], 400);
        }
        pickRequireOrderAccessJson($conn, $orderId);
        $currentOrderStatus = pickFetchOrderStatus($conn, $orderId);
        if ($mode === 'packer') {
            respondJson(['ok' => false, 'error' => 'Short ship can only be changed during picker verification.'], 400);
        }
        if (!in_array($currentOrderStatus, ['PENDING', 'IN_PROGRESS'], true)) {
            respondJson(['ok' => false, 'error' => 'Short ship cannot be triggered. All items scanned.'], 400);
        }
        if (!pickTableColumnExists($conn, 'pick_items', 'is_short_ship')) {
            respondJson(['ok' => false, 'error' => 'Short-ship persistence is not enabled until the pick_items.is_short_ship column is added.'], 400);
        }
        $shortShipQtyEnabled = pickEnsureShortShipQtyColumn($conn);
        if (!$shortShipQtyEnabled) {
            respondJson(['ok' => false, 'error' => 'Unable to enable short-ship quantity persistence.'], 500);
        }

        $currentQty = 0;
        $qtyCol = $mode === 'packer' && pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer')
            ? 'qty_verified_packer'
            : 'qty_verified';
        $lookupStmt = $conn->prepare("SELECT qty_ordered, short_ship_qty AS stored_short_ship_qty, {$qtyCol} AS current_qty FROM pick_items WHERE id = ? AND order_id = ? LIMIT 1");
        if (!$lookupStmt) {
            respondJson(['ok' => false, 'error' => 'Unable to prepare short-ship lookup'], 500);
        }
        $lookupStmt->bind_param('ii', $itemId, $orderId);
        $lookupStmt->execute();
        $lookupRow = $lookupStmt->get_result()->fetch_assoc() ?: null;
        $lookupStmt->close();
        if (!is_array($lookupRow)) {
            respondJson(['ok' => false, 'error' => 'Line not found'], 404);
        }
        $qtyOrdered = (int) ($lookupRow['qty_ordered'] ?? 0);
        $storedShortShipQty = max(0, (int) ($lookupRow['stored_short_ship_qty'] ?? 0));
        $currentVerifiedQty = max(0, (int) ($lookupRow['current_qty'] ?? 0));

        // A fully verified picker line cannot subsequently be marked short.
        // Short ship is only valid for the remaining unverified quantity.
        if ($shortShip === 1 && $qtyOrdered > 0 && $currentVerifiedQty >= $qtyOrdered) {
            respondJson([
                'ok' => false,
                'error' => 'This item is already fully picked and cannot be marked short.',
            ], 409);
        }

        $currentQty = max(0, $qtyOrdered - $currentVerifiedQty);

        if ($shortShip === 1 && $shortShipQtyEnabled) {
            $stmt = $conn->prepare("
                UPDATE pick_items
                SET is_short_ship = ?,
                    short_ship_qty = ?
                WHERE id = ? AND order_id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                respondJson(['ok' => false, 'error' => 'Unable to prepare short-ship update'], 500);
            }
            $stmt->bind_param('iiii', $shortShip, $currentQty, $itemId, $orderId);
        } else {
            $stmt = $conn->prepare("
                UPDATE pick_items
                SET is_short_ship = ?
                WHERE id = ? AND order_id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                respondJson(['ok' => false, 'error' => 'Unable to prepare short-ship update'], 500);
            }
            $stmt->bind_param('iii', $shortShip, $itemId, $orderId);
        }
        $stmt->execute();
        $stmt->close();

        $hasPackerVerify = pickTableColumnExists($conn, 'pick_items', 'qty_verified_packer');
        $summary = pickOrderVerificationSummary($conn, $orderId, $hasPackerVerify);
        $currentStatus = pickFetchOrderStatus($conn, $orderId);
        $total = (int) ($summary['total'] ?? 0);
        $pickerVerified = (int) ($summary['picker_verified'] ?? 0);
        $packerVerified = (int) ($summary['packer_verified'] ?? 0);
        $shortShipCount = (int) ($summary['short_ship_count'] ?? 0);

        pickLogVerifyEvent($conn, [
            'order_id' => $orderId,
            'pick_item_id' => $itemId,
            'workflow_stage' => 'pick',
            'event_type' => $shortShip === 1 ? 'short_ship_on' : 'short_ship_off',
            'qty_delta' => 0,
            'user_id' => pickResolveCurrentUserId($conn),
            'meta' => [
                'status' => $currentStatus,
                'short_ship' => $shortShip === 1,
                'short_ship_qty' => $shortShip === 1 ? $currentQty : $storedShortShipQty,
                'mode' => $mode,
            ],
        ]);

        respondJson([
            'ok' => true,
            'short_ship' => $shortShip === 1,
            'short_ship_qty' => $shortShip === 1 ? $currentQty : $storedShortShipQty,
            'status' => $currentStatus,
            'total' => $total,
            'picker_verified' => $pickerVerified,
            'packer_verified' => $packerVerified,
            'short_ship_count' => $shortShipCount,
        ]);
    } catch (Throwable $e) {
        error_log('[verify_pick] set_short_ship failed: ' . $e->getMessage());
        respondJson(['ok' => false, 'error' => 'Unable to update short ship.'], 500);
    }
}

// --- ADD LINE ITEM (DB) ---
if ($action === 'add_line') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    $sku = trim((string) ($payload['sku'] ?? ''));
    $qty = (int) ($payload['qty'] ?? 0);
    $descIn = trim((string) ($payload['desc'] ?? ''));
    pickDebugLog('add_line_requested', [
        'order_id' => $orderId,
        'sku' => $sku,
        'qty' => $qty,
        'desc' => $descIn,
    ]);
    if ($orderId <= 0 || $sku === '' || $qty <= 0) {
        respondJson(['ok' => false, 'error' => 'Missing order, sku, or quantity'], 400);
    }
    pickRequireOrderAccessJson($conn, $orderId);

    $skuId = null;
    $desc = $descIn;
    $skuStmt = $conn->prepare("SELECT id, `desc` FROM sku WHERE sku_num = ? LIMIT 1");
    if ($skuStmt) {
        $skuStmt->bind_param('s', $sku);
        $skuStmt->execute();
        $skuRow = $skuStmt->get_result()->fetch_assoc() ?: null;
        $skuStmt->close();
        if ($skuRow) {
            $skuId = isset($skuRow['id']) ? (int) $skuRow['id'] : null;
            if ($desc === '' && isset($skuRow['desc'])) {
                $desc = trim((string) $skuRow['desc']);
            }
        }
    }

    $lineHash = md5($orderId . '|' . $sku . '|' . $qty . '|' . microtime(true));
    $hasShortShip = pickTableColumnExists($conn, 'pick_items', 'is_short_ship');
    $insertColumns = 'order_id, sku_id, item_no, description, qty_ordered, qty_verified, line_hash';
    $insertValues = '?, ?, ?, ?, ?, 0, ?';
    $bindTypes = 'iissis';
    $bindValues = [$orderId, $skuId, $sku, $desc, $qty, $lineHash];
    if ($hasShortShip) {
        $insertColumns .= ', is_short_ship';
        $insertValues .= ', ?';
        $bindTypes .= 'i';
        $bindValues[] = 0;
    }
    $stmt = $conn->prepare("
        INSERT INTO pick_items
            ({$insertColumns})
        VALUES ({$insertValues})
        ON DUPLICATE KEY UPDATE
            sku_id = COALESCE(pick_items.sku_id, VALUES(sku_id)),
            description = CASE
                WHEN pick_items.description IS NULL
                  OR pick_items.description = ''
                  OR pick_items.description = '0'
                THEN VALUES(description)
                ELSE pick_items.description
            END
    ");
    if (!$stmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare insert'], 500);
    }
    pickBindParam($stmt, $bindTypes, $bindValues);
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    if ($newId <= 0) {
        $idStmt = $conn->prepare('SELECT id FROM pick_items WHERE order_id = ? AND item_no = ? LIMIT 1');
        if ($idStmt) {
            $idStmt->bind_param('is', $orderId, $sku);
            $idStmt->execute();
            $newId = (int) ($idStmt->get_result()->fetch_assoc()['id'] ?? 0);
            $idStmt->close();
        }
    }
    $stmt->close();

    pickDebugLog('add_line_inserted', [
        'order_id' => $orderId,
        'pick_item_id' => $newId,
        'sku' => $sku,
        'qty' => $qty,
        'reopen_status' => $reopenStatus,
    ]);

    if ($newId <= 0) {
        respondJson(['ok' => false, 'error' => 'Insert failed'], 500);
    }

    $currentOrderStatus = pickFetchOrderStatus($conn, $orderId);
    $reopenStatus = $currentOrderStatus;
    if ($reopenStatus === '' || in_array($reopenStatus, ['COMPLETED', 'COMPLETED_SHORT_SHIP'], true)) {
        $reopenStatus = 'IN_PROGRESS';
    }
    $reopenSet = ['status = ?'];
    $reopenTypes = 's';
    $reopenValues = [$reopenStatus];
    if (in_array($currentOrderStatus, ['COMPLETED', 'COMPLETED_SHORT_SHIP'], true) && pickTableColumnExists($conn, 'pick_orders', 'verified_at')) {
        $reopenSet[] = 'verified_at = NULL';
    }
    $reopenStmt = $conn->prepare("UPDATE pick_orders SET " . implode(', ', $reopenSet) . " WHERE id = ?");
    if ($reopenStmt) {
        $reopenTypes .= 'i';
        $reopenValues[] = $orderId;
        pickBindParam($reopenStmt, $reopenTypes, $reopenValues);
        $reopenStmt->execute();
        $reopenStmt->close();
    }

    $refreshedOrder = loadPickOrderFromDb($conn, $orderId, null);
    if (!empty($refreshedOrder['items']) && empty($refreshedOrder['error'])) {
        $_SESSION['verify_pick_data'] = $refreshedOrder;
    }

    pickLogVerifyEvent($conn, [
        'order_id' => $orderId,
        'pick_item_id' => $newId,
        'workflow_stage' => 'pick',
        'event_type' => 'line_add',
        'sku_scanned' => $sku,
        'expected_sku' => $sku,
        'qty_delta' => $qty,
        'user_id' => pickResolveCurrentUserId($conn),
        'meta' => [
            'sku_id' => $skuId,
            'description' => $desc,
        ],
    ]);

    respondJson([
        'ok' => true,
        'pick_item_id' => $newId,
        'sku' => $sku,
        'description' => $desc,
        'qty' => $qty,
        'is_short_ship' => false,
        'order_status' => $reopenStatus,
    ]);
}

// --- DELETE LINE ITEMS (DB) ---
if ($action === 'delete_lines') {
    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        respondJson(['ok' => false, 'error' => 'Invalid payload'], 400);
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    $itemIds = $payload['item_ids'] ?? [];
    pickDebugLog('delete_lines_requested', [
        'order_id' => $orderId,
        'item_ids' => $itemIds,
    ]);
    if ($orderId <= 0 || !is_array($itemIds) || $itemIds === []) {
        respondJson(['ok' => false, 'error' => 'Missing order or item ids'], 400);
    }
    pickRequireOrderAccessJson($conn, $orderId);

    $itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn($v) => $v > 0));
    if ($itemIds === []) {
        respondJson(['ok' => false, 'error' => 'No valid item ids'], 400);
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $types = 'i' . str_repeat('i', count($itemIds));
    $sql = "DELETE FROM pick_items WHERE order_id = ? AND id IN ({$placeholders})";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare delete'], 500);
    }
    $bindValues = array_merge([$orderId], $itemIds);
    pickBindParam($stmt, $types, $bindValues);
    $stmt->execute();
    $deleted = (int) $stmt->affected_rows;
    $stmt->close();
    pickDebugLog('delete_lines_completed', [
        'order_id' => $orderId,
        'deleted' => $deleted,
        'item_ids' => $itemIds,
    ]);

    pickLogVerifyEvent($conn, [
        'order_id' => $orderId,
        'pick_item_id' => 0,
        'workflow_stage' => 'pick',
        'event_type' => 'line_delete',
        'qty_delta' => 0,
        'user_id' => pickResolveCurrentUserId($conn),
        'meta' => [
            'deleted_count' => $deleted,
            'item_ids' => $itemIds,
        ],
    ]);

    respondJson(['ok' => true, 'deleted' => $deleted]);
}

// --- BARCODE LOOKUP (SKU/UOM) ---
if ($action === 'barcode_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = $_GET['code'] ?? $_POST['code'] ?? '';
    $code = trim((string) $raw);
    $code = trim($code, " \t\n\r\0\x0B");
    $code = trim($code, "'" . '"');
    if ($code === '') {
        respondJson(['ok' => false, 'error' => 'Missing code'], 400);
    }

    $dbNameRes = $conn->query('SELECT DATABASE()');
    $dbName = $dbNameRes instanceof mysqli_result ? ($dbNameRes->fetch_row()[0] ?? '') : '';

    $hasTable = function (mysqli $conn, string $db, string $table): bool {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            LIMIT 1
        ");
        $stmt->bind_param('ss', $db, $table);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };

    $hasCol = function (mysqli $conn, string $db, string $table, string $col): bool {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->bind_param('sss', $db, $table, $col);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };

    $skuNum = null;
    $uom = null;

    if ($hasTable($conn, $dbName, 'product_uom_barcodes')) {
        $uomSql = "
            SELECT s.sku_num, b.upc, b.uom_label, b.multiplier
            FROM product_uom_barcodes b
            JOIN sku s ON s.id = b.sku_id
            WHERE b.upc = ?
            LIMIT 1
        ";
        $uomStmt = $conn->prepare($uomSql);
        $uomStmt->bind_param('s', $code);
        $uomStmt->execute();
        $uomRow = $uomStmt->get_result()->fetch_assoc();
        $uomStmt->close();
        if ($uomRow) {
            $skuNum = (string) $uomRow['sku_num'];
            $uom = [
                'upc' => (string) $uomRow['upc'],
                'uom_label' => (string) $uomRow['uom_label'],
                'multiplier' => (int) $uomRow['multiplier'],
            ];
        }
    }

    if ($skuNum === null) {
        $hasUpc = $hasCol($conn, $dbName, 'sku', 'upc');
        if ($hasUpc) {
            $skuSql = "SELECT sku_num FROM sku WHERE sku_num = ? OR upc = ? LIMIT 1";
            $skuStmt = $conn->prepare($skuSql);
            $skuStmt->bind_param('ss', $code, $code);
        } else {
            $skuSql = "SELECT sku_num FROM sku WHERE sku_num = ? LIMIT 1";
            $skuStmt = $conn->prepare($skuSql);
            $skuStmt->bind_param('s', $code);
        }
        $skuStmt->execute();
        $skuRow = $skuStmt->get_result()->fetch_assoc();
        $skuStmt->close();
        if ($skuRow) {
            $skuNum = (string) $skuRow['sku_num'];
        }
    }

    if ($skuNum === null || $skuNum === '') {
        respondJson(['ok' => false, 'error' => 'SKU not found.'], 404);
    }

    respondJson([
        'ok' => true,
        'sku_num' => $skuNum,
        'multiplier' => $uom['multiplier'] ?? 1,
        'uom' => $uom,
        'scanned_code' => $code,
    ]);
}

// --- SKU LOOKUP (EXACT SKU/UPC FOR ADD LINE) ---
if ($action === 'sku_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = $_GET['code'] ?? $_POST['code'] ?? '';
    $code = trim((string) $raw);
    $code = trim($code, " \t\n\r\0\x0B");
    $code = trim($code, "'" . '"');
    if ($code === '') {
        respondJson(['ok' => false, 'error' => 'Missing code'], 400);
    }

    $hasUpc = pickTableColumnExists($conn, 'sku', 'upc');
    $hasStatus = pickTableColumnExists($conn, 'sku', 'status');
    $statusSelect = $hasStatus ? 's.status' : "'' AS status";

    $hasImageUrl = pickTableColumnExists($conn, 'sku', 'image_url');
    $imageUrlSelect = $hasImageUrl ? 's.image_url' : "'' AS image_url";
    $upcSelect = $hasUpc ? 's.upc' : "'' AS upc";

    if ($hasUpc) {
        $sql = "
            SELECT s.id, s.sku_num, s.`desc`, s.shelf, {$statusSelect}, {$imageUrlSelect}, {$upcSelect}
            FROM sku s
            WHERE s.sku_num = ? OR s.upc = ?
            ORDER BY CASE WHEN s.sku_num = ? THEN 0 ELSE 1 END, s.sku_num ASC
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            respondJson(['ok' => false, 'error' => 'Unable to prepare SKU lookup'], 500);
        }
        $stmt->bind_param('sss', $code, $code, $code);
    } else {
        $sql = "
            SELECT s.id, s.sku_num, s.`desc`, s.shelf, {$statusSelect}, {$imageUrlSelect}, {$upcSelect}
            FROM sku s
            WHERE s.sku_num = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            respondJson(['ok' => false, 'error' => 'Unable to prepare SKU lookup'], 500);
        }
        $stmt->bind_param('s', $code);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        respondJson(['ok' => false, 'error' => 'SKU not found.'], 404);
    }

    $skuNum = trim((string) ($row['sku_num'] ?? ''));
    $imageUrl = trim((string) ($row['image_url'] ?? ''));
    if ($imageUrl === '') {
        $imageUrl = pickFetchShopifyImageUrl($skuNum);
        if ($imageUrl !== '') {
            pickCacheSkuImageUrl($conn, (int) ($row['id'] ?? 0), $imageUrl);
        }
    }
    $upc = trim((string) ($row['upc'] ?? ''));
    if ($upc === '') {
        $upc = pickResolveSkuUpc($conn, (int) ($row['id'] ?? 0), $skuNum);
    }
    $locationMap = fetchSkuLocations($conn, [$skuNum]);
    $locationInfo = $locationMap[$skuNum] ?? ['locations' => [], 'shelf' => null, 'on_hand' => 0];

    respondJson([
        'ok' => true,
        'sku' => [
            'id' => (int) ($row['id'] ?? 0),
            'sku_num' => $skuNum,
            'desc' => trim((string) ($row['desc'] ?? '')),
            'shelf_code' => trim((string) ($row['shelf'] ?? ($locationInfo['shelf'] ?? ''))),
            'status' => trim((string) ($row['status'] ?? '')),
            'image_url' => $imageUrl,
            'upc' => $upc,
        ],
        'locations' => $locationInfo['locations'] ?? [],
        'total_on_hand' => (int) ($locationInfo['on_hand'] ?? 0),
    ]);
}

// --- SKU SEARCH (FUZZY SKU/DESCRIPTION) ---
if ($action === 'sku_search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
    if ($q === '') {
        respondJson(['ok' => true, 'results' => []]);
    }

    $hasStatus = pickTableColumnExists($conn, 'sku', 'status');
    $statusSelect = $hasStatus ? 's.status' : "'' AS status";
    $like = '%' . $q . '%';
    $prefix = $q . '%';

    $stmt = $conn->prepare("
        SELECT s.sku_num, s.`desc`, s.shelf, {$statusSelect}
        FROM sku s
        WHERE s.sku_num LIKE ? OR s.`desc` LIKE ?
        ORDER BY
            CASE
                WHEN s.sku_num = ? THEN 0
                WHEN s.sku_num LIKE ? THEN 1
                WHEN s.`desc` LIKE ? THEN 2
                ELSE 3
            END,
            s.sku_num ASC
        LIMIT 20
    ");
    if (!$stmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare SKU search'], 500);
    }
    $stmt->bind_param('sssss', $like, $like, $q, $prefix, $prefix);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    $skuNums = [];
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $sku = trim((string) ($row['sku_num'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $rows[] = $row;
            $skuNums[] = $sku;
        }
        $res->free();
    }
    $stmt->close();

    $locationMap = fetchSkuLocations($conn, $skuNums);
    $results = [];
    foreach ($rows as $row) {
        $sku = trim((string) ($row['sku_num'] ?? ''));
        $locationInfo = $locationMap[$sku] ?? ['locations' => [], 'shelf' => null, 'on_hand' => 0];
        $results[] = [
            'sku_num' => $sku,
            'desc' => trim((string) ($row['desc'] ?? '')),
            'shelf_code' => trim((string) ($row['shelf'] ?? ($locationInfo['shelf'] ?? ''))),
            'status' => trim((string) ($row['status'] ?? '')),
            'on_hand' => (int) ($locationInfo['on_hand'] ?? 0),
            'locations' => $locationInfo['locations'] ?? [],
        ];
    }

    respondJson(['ok' => true, 'results' => $results]);
}

// --- LOOKUP FINALIZED PACKING MANIFEST ---
if ($action === 'lookup_packing_manifest') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
    if ($q === '') {
        respondJson(['ok' => false, 'error' => 'Missing query'], 400);
    }

    try {
        $manifest = pickLookupPackingManifestByReference($conn, $q);
    } catch (Throwable $e) {
        respondJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    if (!is_array($manifest)) {
        respondJson(['ok' => false, 'error' => 'No finalized manifest found for that order.'], 404);
    }

    $summary = is_array($manifest['summary'] ?? null) ? $manifest['summary'] : [];
    respondJson([
        'ok' => true,
        'manifest' => [
            'token' => trim((string) ($manifest['manifest_token'] ?? '')),
            'order_id' => (int) ($manifest['order_id'] ?? 0),
            'sales_order_number' => trim((string) ($manifest['sales_order_number'] ?? '')),
            'box_count' => (int) ($summary['box_count'] ?? 0),
            'item_count' => (int) ($summary['item_count'] ?? 0),
            'ordered_count' => (int) ($summary['ordered_count'] ?? 0),
            'unassigned_count' => (int) ($summary['unassigned_count'] ?? 0),
            'created_at' => (string) ($manifest['created_at'] ?? ''),
        ],
    ]);
}

// --- SEARCH COMPLETED ORDERS (DB) ---
if ($action === 'search_completed') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
    if ($q === '') {
        respondJson(['ok' => false, 'error' => 'Missing query'], 400);
    }

    $qLike = '%' . $q . '%';
    $stmt = $conn->prepare("
        SELECT
            po.id,
            po.order_number,
            po.status,
            po.updated_at,
            COALESCE(repeated_items.repeated_item_count, 0) AS repeated_item_count,
            COALESCE(repeated_items.repeated_line_count, 0) AS repeated_line_count,
            COALESCE(repeated_items.repeated_item_details, '') AS repeated_item_details
        FROM pick_orders po
        " . pickRepeatedItemsSummaryJoin('po') . "
        WHERE po.status = 'COMPLETED'
          AND (
                po.order_number LIKE ?
             OR CAST(po.id AS CHAR) LIKE ?
             OR COALESCE(po.source_file, '') LIKE ?
          )
        ORDER BY po.updated_at DESC, po.id DESC
        LIMIT 50
    ");
    if (!$stmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare search'], 500);
    }
    $stmt->bind_param('sss', $qLike, $qLike, $qLike);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $orderId = (int) ($row['id'] ?? 0);
            $orderSummary = pickOrderVerificationSummary($conn, $orderId, true);
            $summaryTotal = (int) ($orderSummary['total'] ?? 0);
            $summaryShortShips = (int) ($orderSummary['short_ship_count'] ?? 0);
            $row['picker_done'] = ($summaryTotal > 0 && (int) ($orderSummary['picker_verified'] ?? 0) >= $summaryTotal)
                || ($summaryTotal === 0 && $summaryShortShips > 0);
            $row['packer_done'] = ($summaryTotal > 0 && (int) ($orderSummary['packer_verified'] ?? 0) >= $summaryTotal)
                || ($summaryTotal === 0 && $summaryShortShips > 0);
            $rows[] = $row;
        }
        $res->free();
    }
    $stmt->close();
    respondJson(['ok' => true, 'results' => $rows]);
}

// --- LOAD WORKFLOW QUEUE CHUNK (DB) ---
if ($action === 'load_workflow_queue_chunk') {
    header('Content-Type: application/json; charset=utf-8');

    $workflow = strtolower(trim((string) ($_GET['workflow'] ?? $_POST['workflow'] ?? '')));
    if (!in_array($workflow, ['pick', 'pack'], true)) {
        respondJson(['ok' => false, 'error' => 'Invalid workflow'], 400);
    }

    $offset = max(0, (int) ($_GET['offset'] ?? $_POST['offset'] ?? 0));
    $limit = (int) ($_GET['limit'] ?? $_POST['limit'] ?? 100);
    if ($limit < 1 || $limit > 250) {
        $limit = 100;
    }

    $workflowStatusWhere = $workflow === 'pick'
        ? "WHERE status IN ('PENDING', 'IN_PROGRESS')"
        : "WHERE status = 'READY_TO_PACK'";

    $stmt = $conn->prepare("
        SELECT
            po.id,
            po.order_number,
            po.status,
            po.assigned_user_id,
            COALESCE(po.is_priority, 0) AS is_priority,
            po.updated_at,
            COALESCE(repeated_items.repeated_item_count, 0) AS repeated_item_count,
            COALESCE(repeated_items.repeated_line_count, 0) AS repeated_line_count,
            COALESCE(repeated_items.repeated_item_details, '') AS repeated_item_details
        FROM pick_orders po
        " . pickRepeatedItemsSummaryJoin('po') . "
        {$workflowStatusWhere}
        ORDER BY
            COALESCE(po.is_priority, 0) DESC,
            CASE WHEN po.assigned_user_id IS NOT NULL AND po.assigned_user_id > 0 THEN 0 ELSE 1 END ASC,
            " . pickSalesOrderSortExpr() . " DESC,
            po.order_number DESC,
            po.id DESC
        LIMIT ? OFFSET ?
    ");
    if (!$stmt) {
        respondJson(['ok' => false, 'error' => 'Unable to prepare workflow queue chunk'], 500);
    }
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $orderId = (int) ($row['id'] ?? 0);
            $orderSummary = pickOrderVerificationSummary($conn, $orderId, true);
            $summaryTotal = (int) ($orderSummary['total'] ?? 0);
            $summaryShortShips = (int) ($orderSummary['short_ship_count'] ?? 0);
            $summaryPackTotal = (int) ($orderSummary['packer_total'] ?? $summaryTotal);
            $pickerVerified = (int) ($orderSummary['picker_verified'] ?? 0);
            $packerVerified = (int) ($orderSummary['packer_verified'] ?? 0);
            $assignedId = (int) ($row['assigned_user_id'] ?? 0);
            $rows[] = [
                'id' => $orderId,
                'order_number' => (string) ($row['order_number'] ?? ''),
                'status' => strtoupper(trim((string) ($row['status'] ?? ''))),
                'is_priority' => (int) ($row['is_priority'] ?? 0),
                'assigned_label' => $assignedId > 0 ? ('User #' . $assignedId) : 'Unassigned',
                'picker_done' => ($summaryTotal > 0 && $pickerVerified >= $summaryTotal) || ($summaryTotal === 0 && $summaryShortShips > 0),
                'packer_done' => ($summaryPackTotal > 0 && $packerVerified >= $summaryPackTotal) || ($summaryPackTotal === 0 && $summaryShortShips > 0),
                'repeated_item_count' => (int) ($row['repeated_item_count'] ?? 0),
                'repeated_line_count' => (int) ($row['repeated_line_count'] ?? 0),
                'repeated_item_details' => (string) ($row['repeated_item_details'] ?? ''),
            ];
        }
        $res->free();
    }
    $stmt->close();

    respondJson([
        'ok' => true,
        'results' => $rows,
        'count' => count($rows),
        'has_more' => count($rows) === $limit,
        'next_offset' => $offset + count($rows),
    ]);
}

$title = 'Verify Pick';
$entryWorkflow = strtolower(trim((string) ($_GET['workflow'] ?? '')));
if (!in_array($entryWorkflow, ['pick', 'pack'], true)) {
    $entryWorkflow = '';
}
$showWorkflowHub = $entryWorkflow === '' && empty($parsed_data);
$assignableUsers = pickFetchAssignableUsers($conn);
$userLabelById = [];
foreach ($assignableUsers as $u) {
    $uid = (int) ($u['id'] ?? 0);
    if ($uid > 0) {
        $userLabelById[$uid] = (string) ($u['label'] ?? $u['username'] ?? '');
    }
}
$currentUserId = pickResolveCurrentUserId($conn);
$currentUserHasOrderOverride = pickUserHasOrderOverride();
$workflowDashboard = $showWorkflowHub ? pickBuildWorkflowDashboard($conn, $currentUserId, $userLabelById) : null;
$workflowQuery = $entryWorkflow !== '' ? '&workflow=' . rawurlencode($entryWorkflow) : '';
$workflowSelectionMode = !$showWorkflowHub && empty($parsed_data) && $entryWorkflow !== '';
$workflowDisplayLabel = $entryWorkflow === 'pack' ? 'Pack' : 'Pick';
$page_class = 'page-verify-pick'
    . ($showWorkflowHub ? ' verify-home-active' : '')
    . ($workflowSelectionMode ? ' verify-workflow-modal-active' : '');
$css[] = '/refactor_css/verify_pick.css';
$boxTypeCatalog = pickFetchWarehouseBoxTypes($conn);
$shipInfoLookup = is_array($parsed_data['shipping'] ?? null) ? $parsed_data['shipping'] : [];
$shipInfoSalesOrder = trim((string) (
    $parsed_data['sales_order_number']
    ?? $parsed_data['pick_order_number']
    ?? ($_SESSION['verify_pick_data']['sales_order_number'] ?? '')
));
if ($shipInfoLookup === [] && $shipInfoSalesOrder !== '') {
    $shipInfoLookup = loadShippingLookupForSalesOrder($conn, $shipInfoSalesOrder);
}
$shipInfoPreviewText = trim((string) formatShippingLookupText($shipInfoLookup));
if ($shipInfoPreviewText === '') {
    $shipInfoPreviewText = trim((string) ($parsed_data['ship_info_text'] ?? ''));
}
$shipInfoConsoleData = !empty($shipInfoLookup)
    ? [
        'sales_order_number' => (string) ($shipInfoLookup['sales_order_number'] ?? ''),
        'ship_to_address' => (string) ($shipInfoLookup['ship_to_address'] ?? $shipInfoLookup['ship_to_address_1'] ?? ''),
        'ship_to_address_2' => (string) ($shipInfoLookup['ship_to_address_2'] ?? ''),
        'ship_to_city' => (string) ($shipInfoLookup['ship_to_city'] ?? ''),
        'ship_to_state' => (string) ($shipInfoLookup['ship_to_state'] ?? ''),
        'ship_to_zip_code' => (string) ($shipInfoLookup['ship_to_zip_code'] ?? ''),
        'ship_to_country_region_code' => (string) ($shipInfoLookup['ship_to_country_region_code'] ?? $shipInfoLookup['ship_to_country_code'] ?? ''),
    ]
    : null;
$packCompletionEnabled = true;
$packCompletionOpensMenu = false;
ob_start();
?>

<?php if ($showWorkflowHub): ?>
    <?php $hubStats = $workflowDashboard['stats'] ?? []; ?>
    <?php $hubTrend = $workflowDashboard['trend'] ?? []; ?>
    <?php $hubTrendChart = pickBuildWorkflowTrendChart(is_array($hubTrend) ? $hubTrend : []); ?>
    <section class="verify-home-shell">
        <div class="verify-home-modules">
            <a href="?workflow=pick" class="verify-home-module verify-home-module--pick">
                <span class="verify-home-module__title">Pick</span>
                <span class="verify-home-module__meta"><?= (int) ($hubStats['pick_queue_count'] ?? 0) ?> waiting</span>
                <span class="verify-home-module__action">Open Pick</span>
            </a>
            <a href="?workflow=pack" class="verify-home-module verify-home-module--pack">
                <span class="verify-home-module__title">Pack</span>
                <span class="verify-home-module__meta"><?= (int) ($hubStats['pack_queue_count'] ?? 0) ?> waiting</span>
                <span class="verify-home-module__action">Open Pack</span>
            </a>
        </div>

        <div class="verify-home-tools">
            <section class="verify-home-import card">
                <div class="verify-home-import__panel">
                    <div class="verify-home-import__header">
                        <div>
                            <div class="verify-home-module__eyebrow">Import Picks</div>
                        </div>
                    </div>
                    <div class="verify-home-module__body">
                        <p>Upload Pick Sheet</p>
                        <button type="button" class="btn btn--ghost verify-home-import-fab"
                            id="openImportPicksBtnDashboard">Import
                            Orders
                        </button>
                    </div>
                </div>
            </section>

            <section class="verify-home-manifest card">
                <div class="verify-home-queue__header">
                    <h3 class="verify-home-queue__title">Completed Orders</h3>
                </div>
                <div class="muted" style="margin-bottom:8px;">
                    Look up a finalized packing manifest by sales order or order number.
                </div>
                <div style="display:flex; gap:6px; align-items:center;">
                    <input type="text" id="manifestLookupInput" class="form-control"
                        placeholder="Search SO#, partial SO, or order #" autocomplete="off" />
                    <button type="button" class="btn btn--ghost" id="manifestLookupBtn">Find</button>
                </div>
                <div id="manifestLookupResults" class="warehouse-orders-list" style="margin-top:8px;"></div>
            </section>

            <?php if ($currentUserHasOrderOverride): ?>
                <section class="verify-home-manifest card">
                    <div class="verify-home-queue__header">
                        <h3 class="verify-home-queue__title">Bulk Complete Orders</h3>
                    </div>
                    <div class="muted" style="margin-bottom:8px;">
                        Open the dedicated manager page to select orders by status and complete them in bulk.
                    </div>
                    <a class="btn btn--ghost" href="bulk_complete_pick_orders.php">Open Bulk Complete Page</a>
                </section>
            <?php endif; ?>

            <section class="verify-home-manifest card">
                <div class="verify-home-queue__header">
                    <h3 class="verify-home-queue__title">Packing Manager</h3>
                </div>
                <div class="muted" style="margin-bottom:8px;">
                    Open the pack queue and continue packing completed pick orders.
                </div>
                <div style="display:flex; gap:6px; align-items:center;">
                    <a href="packing_manager.php" class="btn btn--ghost">Go to Packing Manager</a>
                </div>
            </section>


            <?php if ($currentUserHasOrderOverride): ?>
                <section class="verify-home-manifest card">
                    <div class="verify-home-queue__header">
                        <h3 class="verify-home-queue__title">Pick Events</h3>
                    </div>
                    <div class="muted" style="margin-bottom:8px;">
                        View and manage pick events.
                    </div>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <input type="text" placeholder="eg. SO35858" id="pickEventSearch" autocomplete="off" />
                        <!-- <a href="verify_pick.php" class="btn btn--ghost">Search</a> -->
                        <button type="button" class="btn btn--ghost" id="openModalBtn">Search</button>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($currentUserHasOrderOverride): ?>
                <section class="verify-home-manifest card">
                    <div class="verify-home-queue__header">
                        <h3 class="verify-home-queue__title">Pick Import Audits</h3>
                    </div>
                    <div class="muted" style="margin-bottom:8px;">
                        Review import counts, duplicate handling records.
                    </div>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <?php if (pickUserHasOrderOverride()): ?>
                            <a href="./pick_import_audit.php" class="btn btn--ghost">Open Import Audit</a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <script>
                window.__pickEventsUseDirectHandlers = true;

                const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;',
                }[char]));

                const runDirectPickEventsSearch = async (orderNumber) => {
                    const modal = document.getElementById('pick-events-modal');
                    const modalInput = document.getElementById('pick-events-order-number');
                    const errorBox = document.getElementById('pick-events-error');
                    const resultsBox = document.getElementById('pick-events-results');
                    const summaryBox = document.getElementById('pick-events-summary');
                    const fullReportBtn = document.getElementById('pick-events-full-report-btn');
                    const tbody = document.getElementById('pick-events-tbody');
                    const searchBtn = document.getElementById('pick-events-search-btn');

                    const nextOrderNumber = String(orderNumber || '').trim();

                    console.log('[pick-events] search button clicked', nextOrderNumber);

                    if (!modal) {
                        alert('Pick Events modal was not found on the page.');
                        return;
                    }

                    modal.hidden = false;
                    modal.setAttribute('aria-hidden', 'false');

                    if (modalInput) {
                        modalInput.value = nextOrderNumber;
                        modalInput.focus();
                    }

                    if (!nextOrderNumber) {
                        if (errorBox) {
                            errorBox.textContent = 'Enter a sales order number.';
                            errorBox.hidden = false;
                        }
                        return;
                    }

                    if (errorBox) {
                        errorBox.textContent = '';
                        errorBox.hidden = true;
                    }

                    if (resultsBox) {
                        resultsBox.hidden = true;
                    }
                    if (fullReportBtn) {
                        fullReportBtn.hidden = true;
                    }
                    if (fullReportBtn && !fullReportBtn.dataset.bound) {
                        fullReportBtn.dataset.bound = '1';
                        fullReportBtn.addEventListener('click', function () {
                            const orderNumber =
                                document.getElementById('pick-events-order-number')?.value ||
                                document.getElementById('pickEventSearch')?.value ||
                                '';

                            const cleanOrderNumber = String(orderNumber).trim();

                            if (!cleanOrderNumber) {
                                alert('Sales order number is missing.');
                                return;
                            }

                            window.open(
                                `pick_events.php?order_number=${encodeURIComponent(cleanOrderNumber)}`,
                                '_blank'
                            );
                        });
                    }

                    if (tbody) {
                        tbody.innerHTML = '<tr><td colspan="7">Loading pick events...</td></tr>';
                    }

                    if (searchBtn) {
                        searchBtn.disabled = true;
                        searchBtn.textContent = 'Searching...';
                    }

                    try {
                        const params = new URLSearchParams({
                            action: 'pick_events_search',
                            order_number: nextOrderNumber,
                        });

                        const response = await fetch(`verify_pick.php?${params.toString()}`, {
                            headers: {
                                Accept: 'application/json',
                            },
                        });

                        const rawText = await response.text();
                        console.log('[pick-events] raw response:', rawText);

                        let data;
                        try {
                            data = JSON.parse(rawText);
                        } catch (jsonError) {
                            throw new Error('Server did not return valid JSON.');
                        }

                        if (!response.ok || !data.ok) {
                            throw new Error(data.error || 'Unable to load pick events.');
                        }

                        const events = Array.isArray(data.events) ? data.events : [];

                        if (summaryBox) {
                            summaryBox.textContent =
                                `Order Summary: ${data.summary?.order_number || nextOrderNumber} | Events: ${events.length} | Status: ${data.summary?.status || 'N/A'} | Key User: ${data.summary?.key_user || 'N/A'}`;
                        }

                        if (fullReportBtn) {
                            fullReportBtn.hidden = false;
                        }

                        if (tbody) {
                            tbody.innerHTML = '';

                            if (events.length === 0) {
                                tbody.innerHTML = '<tr><td colspan="7">No pick events found for this sales order.</td></tr>';
                            } else {
                                events.forEach((eventRow) => {
                                    const tr = document.createElement('tr');
                                    tr.innerHTML = `
                                        <td>${escapeHtml(eventRow.created_at)}</td>
                                        <td>${escapeHtml(eventRow.event_type)}</td>
                                        <td>${escapeHtml(eventRow.item_no)}</td>
                                        <td>${escapeHtml(eventRow.qty_delta)}</td>
                                        <td>${escapeHtml(eventRow.handler)}</td>
                                        <td>${escapeHtml(eventRow.workflow_stage || '')}</td>
                                        <td>${escapeHtml(eventRow.meta_json || '')}</td>
                                    `;
                                    tbody.appendChild(tr);
                                });
                            }
                        }

                        if (resultsBox) {
                            resultsBox.hidden = false;
                        }
                    } catch (error) {
                        console.error('[pick-events] query failed:', error);

                        if (errorBox) {
                            errorBox.textContent = error.message || 'Pick events query failed.';
                            errorBox.hidden = false;
                        }
                        if (fullReportBtn) {
                            fullReportBtn.hidden = true;
                        }
                    } finally {
                        if (searchBtn) {
                            searchBtn.disabled = false;
                            searchBtn.textContent = 'Search';
                        }
                    }
                };

                window.__pickEventsDirectSearch = runDirectPickEventsSearch;
                const initialPickEventsOrderNumber = new URLSearchParams(window.location.search).get('order_number') || '';

                document.addEventListener('click', function (event) {
                    const target = event.target;
                    const btn = target instanceof Element ? target.closest('#openModalBtn') : null;

                    if (!btn) {
                        return;
                    }

                    event.preventDefault();
                    const input = document.getElementById('pickEventSearch');
                    runDirectPickEventsSearch(String(input && input.value ? input.value : '').trim());
                });

                document.addEventListener('click', function (event) {
                    const target = event.target;
                    const btn = target instanceof Element ? target.closest('#pick-events-search-btn') : null;

                    if (!btn) {
                        return;
                    }

                    event.preventDefault();

                    const modalInput = document.getElementById('pick-events-order-number');
                    const homeInput = document.getElementById('pickEventSearch');

                    if (homeInput && modalInput) {
                        homeInput.value = modalInput.value;
                    }

                    runDirectPickEventsSearch(String(modalInput && modalInput.value ? modalInput.value : '').trim());
                });

                if (initialPickEventsOrderNumber) {
                    window.addEventListener('DOMContentLoaded', function () {
                        runDirectPickEventsSearch(initialPickEventsOrderNumber);
                    });
                }

                document.addEventListener('click', function (event) {
                    const target = event.target;
                    const closeTarget = target instanceof Element
                        ? target.closest('#pickEventsCloseBtn, [data-pick-events-close]')
                        : null;

                    if (!closeTarget) {
                        return;
                    }

                    const modal = document.getElementById('pick-events-modal');
                    if (modal) {
                        modal.hidden = true;
                        modal.setAttribute('aria-hidden', 'true');
                        document.body.style.overflow = '';
                    }
                });
            </script>
            <style>
                .pick-events-modal[hidden],
                .pick-events-summary[hidden],
                .pick-events-error[hidden],
                #pick-events-results[hidden],
                #pick-events-export-csv[hidden],
                #pick-events-export-excel[hidden],
                #pick-events-export-pdf[hidden],
                #pick-events-full-report-btn[hidden] {
                    display: none !important;
                }

                .pick-events-modal {
                    position: fixed;
                    inset: 0;
                    z-index: 10000;
                }

                .pick-events-modal__backdrop {
                    position: absolute;
                    inset: 0;
                    background: rgba(15, 23, 42, 0.55);
                }

                .pick-events-modal__dialog {
                    position: relative;
                    width: min(1100px, calc(100vw - 32px));
                    max-height: calc(100vh - 48px);
                    margin: 24px auto;
                    background: #fff;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
                }

                .pick-events-modal__header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 12px;
                    background: #eef8f7;
                    padding: 14px 16px;
                    border-bottom: 1px solid #dbe3ef;
                }

                .pick-events-modal__actions {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    flex-wrap: wrap;
                }

                .pick-events-modal__close {
                    border: 0;
                    background: transparent;
                    font-size: 26px;
                    cursor: pointer;
                    line-height: 1;
                }

                .pick-events-modal__search {
                    display: flex;
                    align-items: end;
                    gap: 12px;
                    padding: 14px 16px;
                    border-bottom: 1px solid #dbe3ef;
                    flex-wrap: wrap;
                }

                .pick-events-field {
                    display: flex;
                    flex-direction: column;
                    gap: 6px;
                    min-width: 260px;
                    flex: 1 1 260px;
                }

                .pick-events-summary {
                    padding: 14px 16px;
                    border-bottom: 1px solid #dbe3ef;
                    font-weight: 500;
                }

                .pick-events-summary-row {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 16px;
                    padding: 12px 16px;
                    border-bottom: 1px solid #e5e7eb;
                }

                .pick-events-summary-row .pick-events-summary {
                    padding: 0;
                    border-bottom: 0;
                    flex: 1 1 auto;
                }

                #pick-events-full-report-btn {
                    white-space: nowrap;
                }

                .pick-events-table-wrap {
                    max-height: 60vh;
                    overflow: auto;
                }

                .pick-events-table {
                    width: 100%;
                    border-collapse: collapse;
                }

                .pick-events-table th,
                .pick-events-table td {
                    padding: 10px 14px;
                    border-bottom: 1px solid #e5e7eb;
                    text-align: left;
                    vertical-align: top;
                }

                .pick-events-table th {
                    position: sticky;
                    top: 0;
                    background: #f1f5f9;
                    z-index: 1;
                }

                .pick-events-error {
                    margin: 14px 16px;
                    padding: 10px 12px;
                    background: #fee2e2;
                    color: #991b1b;
                    border-radius: 8px;
                }

                @media (max-width: 720px) {
                    .pick-events-modal__dialog {
                        width: calc(100vw - 16px);
                        margin: 8px auto;
                        max-height: calc(100vh - 16px);
                    }

                    .pick-events-modal__header,
                    .pick-events-modal__search {
                        align-items: stretch;
                    }
                }
            </style>
            <div id="pick-events-modal" class="pick-events-modal" hidden aria-hidden="true">
                <div class="pick-events-modal__backdrop" data-pick-events-close></div>
                <div class="pick-events-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pick-events-title">
                    <div class="pick-events-modal__header">
                        <h3 id="pick-events-title" class="vp-modal__title">Event Log Report</h3>
                        <div class="pick-events-modal__actions">
                            <button type="button" class="btn" id="pick-events-export-csv" hidden>Export CSV</button>
                            <button type="button" class="btn" id="pick-events-export-excel" hidden>Export Excel</button>
                            <button type="button" class="btn" id="pick-events-export-pdf" hidden>Export PDF</button>
                            <button type="button" class="pick-events-modal__close" id="pickEventsCloseBtn"
                                data-pick-events-close aria-label="Close">&times;</button>
                        </div>
                    </div>
                    <div class="pick-events-modal__search">
                        <label class="pick-events-field">
                            <span>Sales Order #</span>
                            <input id="pick-events-order-number" class="input" type="text" placeholder="e.g. SO35903">
                        </label>
                        <button type="button" class="btn btn--primary" id="pick-events-search-btn">Search</button>
                    </div>
                    <div id="pick-events-error" class="pick-events-error" hidden></div>
                    <div id="pickEventsBody">
                        <div class="muted">Enter a sales order number and search to load pick events.</div>
                        <div id="pick-events-results" hidden>
                            <div class="pick-events-summary-row">
                                <div id="pick-events-summary" class="pick-events-summary"></div>
                                <button type="button" class="btn btn--primary" id="pick-events-full-report-btn" hidden>
                                    Open Full Report
                                </button>
                            </div>
                            <div class="pick-events-table-wrap">
                                <table class="pick-events-table" id="pick-events-table">
                                    <thead>
                                        <tr>
                                            <th>Date &amp; Time</th>
                                            <th>Activity Type</th>
                                            <th>Item Number</th>
                                            <th>Quantity Change</th>
                                            <th>Handler</th>
                                            <th>Workflow Stage</th>
                                            <th>Status Details</th>
                                            <!-- <th>Meta JSON</th> -->
                                        </tr>
                                    </thead>
                                    <tbody id="pick-events-tbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <section class="verify-home-dashboard card">
            <div class="verify-home-dashboard__header">
                <div>
                    <div class="verify-home-dashboard__eyebrow">Current Verification Stats</div>
                </div>
            </div>
            <div class="verify-home-stats">
                <article class="verify-home-stat-card">
                    <div class="verify-home-stat-card__label">Open Orders</div>
                    <div class="verify-home-stat-card__value"><?= (int) ($hubStats['open_orders'] ?? 0) ?></div>
                </article>
                <!-- <article class="verify-home-stat-card">
                    <div class="verify-home-stat-card__label">Assigned To Me</div>
                    <div class="verify-home-stat-card__value"><?= (int) ($hubStats['assigned_to_me'] ?? 0) ?></div>
                </article> -->
                <!-- <article class="verify-home-stat-card">
                    <div class="verify-home-stat-card__label">Unassigned</div>
                    <div class="verify-home-stat-card__value"><?= (int) ($hubStats['unassigned'] ?? 0) ?></div>
                </article> -->
                <article class="verify-home-stat-card">
                    <div class="verify-home-stat-card__label">Waiting On Pick</div>
                    <div class="verify-home-stat-card__value"><?= (int) ($hubStats['pick_queue_count'] ?? 0) ?></div>
                </article>
                <article class="verify-home-stat-card">
                    <div class="verify-home-stat-card__label">Completed Today</div>
                    <div class="verify-home-stat-card__value"><?= (int) ($hubStats['completed_today'] ?? 0) ?></div>
                </article>
                <article class="verify-home-stat-card">
                    <div class="verify-home-stat-card__label">Orders 30 Days</div>
                    <div class="verify-home-stat-card__value"><?= (int) ($hubStats['orders_30_days'] ?? 0) ?></div>
                </article>
                <article class="verify-home-stat-card">
                    <div class="verify-home-stat-card__label">Completed 30 Days</div>
                    <div class="verify-home-stat-card__value"><?= (int) ($hubStats['completed_30_days'] ?? 0) ?></div>
                </article>
                <article class="verify-home-stat-card">
                    <div class="verify-home-stat-card__label">Pick Accuracy 30 Days</div>
                    <div class="verify-home-stat-card__value">
                        <?= number_format((float) ($hubStats['pick_accuracy_30_days'] ?? 0), 1) ?>%
                    </div>
                </article>
                <article class="verify-home-stat-card">
                    <div class="verify-home-stat-card__label">Pack Accuracy 30 Days</div>
                    <div class="verify-home-stat-card__value">
                        <?= number_format((float) ($hubStats['pack_accuracy_30_days'] ?? 0), 1) ?>%
                    </div>
                </article>
            </div>

            <div class="verify-home-panels">
                <article class="verify-home-chart card">
                    <div class="verify-home-chart__header">
                        <div>
                            <div class="verify-home-dashboard__eyebrow">Order Flow</div>
                        </div>
                        <div class="verify-home-chart__legend">
                            <?php foreach (($hubTrendChart['series'] ?? []) as $series): ?>
                                <span class="verify-home-chart__legend-item">
                                    <span class="verify-home-chart__legend-swatch"
                                        style="background: <?= htmlspecialchars((string) ($series['color'] ?? '#0f172a')) ?>;"></span>
                                    <?= htmlspecialchars((string) ($series['name'] ?? 'Series')) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="verify-home-chart__canvas">
                        <svg viewBox="0 0 <?= (int) ($hubTrendChart['width'] ?? 820) ?> <?= (int) ($hubTrendChart['height'] ?? 280) ?>"
                            class="verify-home-chart__svg" role="img"
                            aria-label="Last 30 days orders, picked, and packed line chart">
                            <?php foreach (($hubTrendChart['guides'] ?? []) as $guide): ?>
                                <line x1="42" y1="<?= htmlspecialchars((string) ($guide['y'] ?? 0)) ?>"
                                    x2="<?= (int) ($hubTrendChart['width'] ?? 820) - 16 ?>"
                                    y2="<?= htmlspecialchars((string) ($guide['y'] ?? 0)) ?>"
                                    class="verify-home-chart__grid-line" />
                                <text x="0" y="<?= htmlspecialchars((string) (($guide['y'] ?? 0) + 4)) ?>"
                                    class="verify-home-chart__axis-label">
                                    <?= (int) ($guide['value'] ?? 0) ?>
                                </text>
                            <?php endforeach; ?>
                            <?php foreach (($hubTrendChart['series'] ?? []) as $series): ?>
                                <polyline points="<?= htmlspecialchars((string) ($series['path'] ?? '')) ?>" fill="none"
                                    stroke="<?= htmlspecialchars((string) ($series['color'] ?? '#0f172a')) ?>" stroke-width="3"
                                    stroke-linecap="round" stroke-linejoin="round" />
                                <?php foreach (preg_split('/\s+/', trim((string) ($series['points'] ?? ''))) as $point): ?>
                                    <?php if ($point === '') {
                                        continue;
                                    } ?>
                                    <?php [$cx, $cy] = array_pad(explode(',', $point, 2), 2, '0'); ?>
                                    <circle cx="<?= htmlspecialchars($cx) ?>" cy="<?= htmlspecialchars($cy) ?>" r="3.5"
                                        fill="<?= htmlspecialchars((string) ($series['color'] ?? '#0f172a')) ?>" />
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            <?php foreach (($hubTrendChart['labels'] ?? []) as $label): ?>
                                <text x="<?= htmlspecialchars((string) ($label['x'] ?? 0)) ?>"
                                    y="<?= (int) ($hubTrendChart['height'] ?? 280) - 6 ?>" text-anchor="middle"
                                    class="verify-home-chart__axis-label">
                                    <?= htmlspecialchars((string) ($label['text'] ?? '')) ?>
                                </text>
                            <?php endforeach; ?>
                        </svg>
                    </div>
                </article>
            </div>

            <div class="verify-home-queues">
                <article class="verify-home-queue card">
                    <div class="verify-home-queue__header">
                        <h3 class="verify-home-queue__title">Pick Queue</h3>
                        <a href="?workflow=pick" class="verify-home-queue__link">Open Pick</a>
                    </div>
                    <?php $pickQueue = $workflowDashboard['pick_queue'] ?? []; ?>
                    <?php if (empty($pickQueue)): ?>
                        <div class="muted">No orders are currently waiting on picker verification.</div>
                    <?php else: ?>
                        <div class="verify-home-queue__table-head verify-home-queue__table-head--pick">
                            <span>Sales Order</span>
                            <span>Assigned</span>
                            <!-- <span>Remaining</span> -->
                        </div>
                        <div class="verify-home-queue__list">
                            <?php foreach ($pickQueue as $queueOrder): ?>
                                <div class="verify-home-queue__item verify-home-queue__item--pick">
                                    <div class="verify-home-queue__order">
                                        <?= htmlspecialchars((string) ($queueOrder['order_number'] ?? '')) ?>
                                    </div>
                                    <div class="verify-home-queue__assigned">
                                        <?= htmlspecialchars((string) ($queueOrder['assigned_label'] ?? 'Unassigned')) ?>
                                    </div>
                                    <!-- <div class="verify-home-queue__count"><?= (int) ($queueOrder['remaining'] ?? 0) ?> left</div> -->
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="verify-home-queue card">
                    <div class="verify-home-queue__header">
                        <h3 class="verify-home-queue__title">Pack Queue</h3>
                        <a href="?workflow=pack" class="verify-home-queue__link">Open Pack</a>
                    </div>
                    <?php $packQueue = $workflowDashboard['pack_queue'] ?? []; ?>
                    <?php if (empty($packQueue)): ?>
                        <div class="muted">No orders are currently waiting on packer verification.</div>
                    <?php else: ?>
                        <div class="verify-home-queue__table-head verify-home-queue__table-head--pick">
                            <span>Sales Order</span>
                            <span>Assigned</span>
                            <!-- <span>Remaining</span> -->
                        </div>
                        <div class="verify-home-queue__list">
                            <?php foreach ($packQueue as $queueOrder): ?>
                                <div class="verify-home-queue__item verify-home-queue__item--pick">
                                    <div class="verify-home-queue__order">
                                        <?= htmlspecialchars((string) ($queueOrder['order_number'] ?? '')) ?>
                                    </div>
                                    <div class="verify-home-queue__assigned">
                                        <?= htmlspecialchars((string) ($queueOrder['assigned_label'] ?? 'Unassigned')) ?>
                                    </div>
                                    <!-- <div class="verify-home-queue__count"><?= (int) ($queueOrder['pack_remaining'] ?? 0) ?> left</div> -->
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="verify-home-queue card">
                    <div class="verify-home-queue__header">
                        <h3 class="verify-home-queue__title">Shipped Orders</h3>
                    </div>
                    <?php $shippedOrders = fetchShippedOrders($conn); ?>
                    <?php if (empty($shippedOrders)): ?>
                        <div class="muted">No shipped orders found.</div>
                    <?php else: ?>
                        <div class="verify-home-queue__table-head verify-home-queue__table-head--pick">
                            <span>Order #</span>
                            <span>Shipped At</span>
                        </div>
                        <div class="verify-home-queue__list">
                            <?php foreach ($shippedOrders as $order): ?>
                                <div class="verify-home-queue__item verify-home-queue__item--pick">
                                    <div class="verify-home-queue__order">
                                        <?= htmlspecialchars((string) ($order['order_number'] ?? '')) ?>
                                    </div>
                                    <div class="verify-home-queue__count">
                                        <?= htmlspecialchars((string) ($order['updated_at'] ?? '')) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

            </div>
            </div>

        </section>
    </section>
<?php endif; ?>

<button type="button" class="btn btn--ghost print-btn" id="printPickBtn">🖨️</button>

<div class="pick-drawer" id="pickDrawer" data-open="true">
    <div class="pick-drawer__backdrop" aria-hidden="true"></div>
    <div class="pick-overlay" id="pickOverlay" aria-hidden="true">
        <div class="pick-overlay__card">
            <div class="pick-overlay__title">Loading…</div>
            <div class="pick-loading pick-loading--overlay">
                <div class="pick-loading__bar"></div>
            </div>
        </div>
    </div>
    <div class="pick-drawer__panel" id="pickDrawerPanel">
        <button type="button" class="pick-drawer__mini-close" id="pickDrawerMiniClose"
            aria-label="Close Orders">x</button>
        <button type="button" class="pick-drawer__toggle" id="pickDrawerToggle" aria-controls="pickDrawerPanel"
            aria-expanded="true">&lt;&lt;</button>
        <div class="card warehouse-orders narrow-scroll-card">

            <!-- Warehouse Orders (DB) -->
            <div class="warehouse-orders-list<?= $entryWorkflow === 'pick' || $entryWorkflow === 'pack' ? '' : ' orders-assigned-only' ?>"
                id="availableOrdersList"
                data-default-show-all="<?= $entryWorkflow === 'pick' ? '1' : ($entryWorkflow === 'pack' ? '1' : '0') ?>"
                data-queue-workflow="<?= htmlspecialchars($entryWorkflow, ENT_QUOTES) ?>"
                data-queue-offset="<?= isset($dbOrders) && is_array($dbOrders) ? count($dbOrders) : 0 ?>">
                <div class="orders-header-row">
                    <div class="date-header">
                        <?= htmlspecialchars($workflowSelectionMode ? ($workflowDisplayLabel . ' Sales Orders') : 'Sales Order') ?>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <button type="button" class="btn btn--ghost btn-orders-toggle" id="toggleAllOrdersBtn">See
                            All</button>
                        <button type="button" class="btn btn--ghost" id="loadMoreOrdersBtn">Load Next 100</button>
                    </div>
                </div>
                <div class="verify-order-filter-row">
                    <input type="text" id="currentOrdersFilterInput" class="form-control verify-order-filter-input"
                        placeholder="Filter sales order #" autocomplete="off" spellcheck="false" />
                </div>
                <?php
                $dbOrders = [];
                $workflowStatusWhere = "WHERE (status IS NULL OR status NOT IN ('COMPLETED', 'COMPLETED_SHORT_SHIP'))";
                if ($entryWorkflow === 'pick') {
                    $workflowStatusWhere = "WHERE status IN ('PENDING', 'IN_PROGRESS')";
                } elseif ($entryWorkflow === 'pack') {
                    $workflowStatusWhere = "WHERE status = 'READY_TO_PACK'";
                }
                $priorityOrderSql = $entryWorkflow === 'pack' ? 'COALESCE(po.is_priority, 0) DESC,' : '';
                $stmt = $conn->prepare("
                    SELECT
                        po.id,
                        po.order_number,
                        po.status,
                        COALESCE(po.is_priority, 0) AS is_priority,
                        po.updated_at,
                        po.created_at,
                        po.assigned_user_id,
                        COALESCE(repeated_items.repeated_item_count, 0) AS repeated_item_count,
                        COALESCE(repeated_items.repeated_line_count, 0) AS repeated_line_count,
                        COALESCE(repeated_items.repeated_item_details, '') AS repeated_item_details
                    FROM pick_orders po
                    " . pickRepeatedItemsSummaryJoin('po') . "
                    {$workflowStatusWhere}
                    ORDER BY
                        {$priorityOrderSql}
                        CASE WHEN po.assigned_user_id IS NOT NULL AND po.assigned_user_id > 0 THEN 0 ELSE 1 END ASC,
                        " . pickSalesOrderSortExpr() . " DESC,
                        po.order_number DESC,
                        po.id DESC
                    LIMIT 100
                ");
                if ($stmt) {
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res instanceof mysqli_result) {
                        while ($row = $res->fetch_assoc()) {
                            $dbOrders[] = $row;
                        }
                        $res->free();
                    }
                    $stmt->close();
                }
                ?>
                <?php if (empty($dbOrders)): ?>
                    <div class="muted">No saved pick orders found.</div>
                <?php else: ?>
                    <?php $visibleOrderCount = 0; ?>
                    <?php foreach ($dbOrders as $order): ?>
                        <?php
                        $orderId = (int) ($order['id'] ?? 0);
                        $assignedId = (int) ($order['assigned_user_id'] ?? 0);
                        $assignedLabel = $assignedId > 0 ? ($userLabelById[$assignedId] ?? ('User #' . $assignedId)) : 'Unassigned';
                        $orderSummary = pickOrderVerificationSummary($conn, $orderId, true);
                        $summaryTotal = (int) ($orderSummary['total'] ?? 0);
                        $summaryShortShips = (int) ($orderSummary['short_ship_count'] ?? 0);
                        $pickerDone = ($summaryTotal > 0 && (int) ($orderSummary['picker_verified'] ?? 0) >= $summaryTotal)
                            || ($summaryTotal === 0 && $summaryShortShips > 0);
                        $packerDone = ($summaryTotal > 0 && (int) ($orderSummary['packer_verified'] ?? 0) >= $summaryTotal)
                            || ($summaryTotal === 0 && $summaryShortShips > 0);
                        $rawOrderStatus = strtoupper(trim((string) ($order['status'] ?? '')));
                        $derivedOrderStatus = $rawOrderStatus;
                        $showPickBadge = $entryWorkflow !== 'pack';
                        $showPackBadge = $entryWorkflow !== 'pick';
                        $showOrderForWorkflow = true;
                        if ($entryWorkflow === 'pick') {
                            $showOrderForWorkflow = in_array($rawOrderStatus, ['PENDING', 'IN_PROGRESS'], true);
                        } elseif ($entryWorkflow === 'pack') {
                            $showOrderForWorkflow = $rawOrderStatus === 'READY_TO_PACK';
                        }
                        if (!$showOrderForWorkflow) {
                            continue;
                        }
                        $visibleOrderCount++;
                        $orderEntryClasses = ['order-entry'];
                        if ($assignedId > 0 && $assignedId === $currentUserId) {
                            $orderEntryClasses[] = 'order-entry--mine';
                        } elseif ($assignedId <= 0) {
                            $orderEntryClasses[] = 'order-entry--unassigned';
                        } else {
                            $orderEntryClasses[] = 'order-entry--other-assigned';
                        }
                        ?>
                        <div class="<?= htmlspecialchars(implode(' ', $orderEntryClasses)) ?>" style="margin:4px 0;">
                            <a href="?action=load_order&order_id=<?= $orderId ?>&order_number=<?= urlencode((string) ($order['order_number'] ?? '')) ?><?= htmlspecialchars($workflowQuery, ENT_QUOTES) ?>"
                                class="btn btn--ghost order-entry__button">
                                <span class="order-entry__main">
                                    <?= htmlspecialchars((string) ($order['order_number'] ?? '')) ?>
                                    <?php if ($entryWorkflow === 'pack' && !empty($order['is_priority'])): ?>
                                        <span class="order-priority-badge" title="Priority order">⚑ PRIORITY</span>
                                    <?php endif; ?>
                                    <?php if ((int) ($order['repeated_line_count'] ?? 0) > 0): ?>
                                        <span class="bogo"
                                            title="<?= htmlspecialchars((string) ($order['repeated_item_details'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            aria-label="Repeated item lines: <?= (int) ($order['repeated_line_count'] ?? 0) ?>">
                                            BOGO
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <span class="order-entry__checks" aria-label="Verification progress">
                                    <?php if ($showPickBadge): ?>
                                        <span class="order-entry__check<?= $pickerDone ? ' is-complete' : '' ?>"
                                            title="Picker verification<?= $pickerDone ? ' complete' : ' pending' ?>">
                                            <span class="order-entry__check-box"
                                                aria-hidden="true"><?= $pickerDone ? '&#10003;' : '' ?></span>
                                            <span class="order-entry__check-label">PICK</span>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($showPackBadge): ?>
                                        <span class="order-entry__check<?= $packerDone ? ' is-complete' : '' ?>"
                                            title="Packer verification<?= $packerDone ? ' complete' : ' pending' ?>">
                                            <span class="order-entry__check-box"
                                                aria-hidden="true"><?= $packerDone ? '&#10003;' : '' ?></span>
                                            <span class="order-entry__check-label">PACK</span>
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <span class="order-entry__status muted">
                                    <?= htmlspecialchars($derivedOrderStatus) ?>
                                </span>
                            </a>
                            <div class="muted" style="margin-top:2px; font-size:0.75rem;">
                                Assigned: <?= htmlspecialchars($assignedLabel) ?>
                            </div>
                            <!-- <div style="display:flex; gap:6px; margin-top:4px;">
                                <button type="button" class="btn btn--ghost order-assign-btn"
                                    data-order-id="<?= $orderId ?>"
                                    data-order-number="<?= htmlspecialchars((string) ($order['order_number'] ?? ''), ENT_QUOTES) ?>">Assign</button>
                            </div> -->
                        </div>
                    <?php endforeach; ?>
                    <?php if ($visibleOrderCount === 0): ?>
                        <div class="muted">
                            No <?= htmlspecialchars(strtolower($workflowDisplayLabel)) ?> sales orders are currently available.
                        </div>
                    <?php else: ?>
                        <div class="muted verify-order-filter-empty" id="currentOrdersFilterEmpty" hidden>
                            No matching sales orders found.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div style="margin-top:12px;">
                <div style="display:flex; gap:6px;">
                    <input type="text" id="completedSearchInput" class="form-control" placeholder="Search completed SO#"
                        autocomplete="off" />
                    <button type="button" class="btn btn--ghost" id="completedSearchBtn">Search</button>
                </div>
                <div id="completedSearchResults" class="warehouse-orders-list" style="margin-top:8px;"></div>
            </div>
        </div>
    </div>
</div>
<button type="button" id="pickDrawerLaunch" class="pick-drawer-launch" aria-controls="pickDrawer"
    aria-expanded="false">📂</button>
<div id="importPicksModal" class="vp-modal vp-modal--import" hidden aria-hidden="true">
    <div class="vp-modal__backdrop" data-close="1"></div>
    <div class="vp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="importPicksTitle">
        <div class="vp-modal__header">
            <h3 id="importPicksTitle" class="vp-modal__title">Import Picks</h3>
            <button type="button" class="vp-modal__close" id="importPicksCloseBtn" aria-label="Close">Close</button>
        </div>
        <div class="vp-modal__body">
            <div class="upload-order">
                <h2 class="title">Upload Pick Sheet (Excel/CSV) for Verification, Sorting & Save</h2>
                <form method="POST" enctype="multipart/form-data" class="mb-3"
                    action="verify_pick.php<?= $entryWorkflow !== '' ? '?workflow=' . rawurlencode($entryWorkflow) : '' ?>"
                    id="verifyPickUploadForm">
                    <label for="pickFile" class="form-label">Upload picking list (.csv, .xlsx, or .xlsm)</label>
                    <input type="file" id="pickFile" name="pickFile" accept=".csv,.xlsx,.xlsm" required
                        class="form-control mb-2">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Import File</button>
                </form>
                <button type="button" class="btn btn--ghost" id="runImportBtn" style="width: 100%;">Import
                    Picks from Google Drive</button>
                <div class="pick-loading" id="pickLoading" aria-hidden="true" style="margin-top:8px;">
                    <div class="pick-loading__bar"></div>
                </div>
            </div>
        </div>
    </div>
</div>


<div id="assignOrderModal" class="vp-modal" hidden aria-hidden="true">
    <div class="vp-modal__backdrop" data-close="1"></div>
    <div class="vp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="assignOrderTitle">
        <div class="vp-modal__header">
            <h3 id="assignOrderTitle" class="vp-modal__title">Assign Sales Order</h3>
            <button type="button" class="vp-modal__close" id="assignOrderCloseBtn" aria-label="Close">Close</button>
        </div>
        <div class="vp-modal__body">
            <div id="assignOrderMeta" class="muted" style="margin-bottom:8px;"></div>
            <label for="assignOrderUserSelect" class="form-label">Assign to user</label>
            <select id="assignOrderUserSelect" class="form-control">
                <?php foreach (($assignableUsers ?? []) as $u): ?>
                    <option value="<?= (int) ($u['id'] ?? 0) ?>">
                        <?= htmlspecialchars((string) ($u['label'] ?? $u['username'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:10px;">
                <button type="button" class="btn btn--ghost" id="assignOrderConfirmBtn">Assign</button>
            </div>
        </div>
    </div>
</div>

<script>
    window.addEventListener('DOMContentLoaded', () => {
        const drawer = document.getElementById('pickDrawer');
        const drawerBackdrop = drawer?.querySelector('.pick-drawer__backdrop') || null;
        const drawerToggle = document.getElementById('pickDrawerToggle');
        const drawerMiniClose = document.getElementById('pickDrawerMiniClose');
        const drawerLaunch = document.getElementById('pickDrawerLaunch');
        if (!drawer) return;
        const shouldOpenDrawerByDefault = <?= $workflowSelectionMode ? 'true' : 'false' ?>;
        const isWorkflowModal = <?= $workflowSelectionMode ? 'true' : 'false' ?>;
        const workflowHubUrl = 'verify_pick.php';

        const setDrawerOpen = (open) => {
            drawer.dataset.open = open ? 'true' : 'false';
            if (drawerToggle) {
                drawerToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            if (drawerLaunch) {
                drawerLaunch.setAttribute('aria-expanded', open ? 'true' : 'false');
                drawerLaunch.textContent = '📂';
            }
        };

        setDrawerOpen(shouldOpenDrawerByDefault);

        if (drawerToggle) {
            drawerToggle.addEventListener('click', () => {
                const isOpen = drawer.dataset.open === 'true';
                setDrawerOpen(!isOpen);
            });
        }
        if (drawerLaunch) {
            drawerLaunch.addEventListener('click', () => {
                const isOpen = drawer.dataset.open === 'true';
                setDrawerOpen(!isOpen);
            });
        }
        if (drawerMiniClose) {
            drawerMiniClose.addEventListener('click', () => {
                if (isWorkflowModal) {
                    window.location.href = workflowHubUrl;
                    return;
                }
                setDrawerOpen(false);
            });
        }
        if (drawerBackdrop && isWorkflowModal) {
            drawerBackdrop.addEventListener('click', () => {
                window.location.href = workflowHubUrl;
            });
        }

        const loadingEl = document.getElementById('pickLoading');
        const overlayEl = document.getElementById('pickOverlay');
        const setLoading = (on) => {
            if (!loadingEl) return;
            loadingEl.setAttribute('aria-hidden', on ? 'false' : 'true');
            loadingEl.classList.toggle('is-active', on);
            if (overlayEl) {
                overlayEl.setAttribute('aria-hidden', on ? 'false' : 'true');
                overlayEl.classList.toggle('is-active', on);
            }
        };

        const ROOT_BASE = (
            typeof window.INVAPP_BASE === 'string' && window.INVAPP_BASE.trim() !== ''
                ? window.INVAPP_BASE
                : '/dev'
        ).replace(/\/+$/, '');
        const APP_BASE = ROOT_BASE.endsWith('/inventory') ? ROOT_BASE : `${ROOT_BASE}/inventory`;
        const importModal = document.getElementById('importPicksModal');
        const openImportBtns = [
            document.getElementById('openImportPicksBtn'),
            document.getElementById('openImportPicksBtnDashboard'),
        ].filter(Boolean);
        const closeImportBtn = document.getElementById('importPicksCloseBtn');
        const setImportModalOpenState = (isOpen) => {
            document.body.classList.toggle('verify-import-modal-open', !!isOpen);
        };
        const openImportModal = () => {
            if (!importModal) return;
            importModal.removeAttribute('hidden');
            importModal.setAttribute('aria-hidden', 'false');
            importModal.classList.add('is-open');
            setImportModalOpenState(true);
        };
        const closeImportModal = () => {
            if (!importModal) return;
            importModal.classList.remove('is-open');
            importModal.setAttribute('aria-hidden', 'true');
            importModal.setAttribute('hidden', 'hidden');
            setImportModalOpenState(false);
        };
        setImportModalOpenState(importModal ? importModal.classList.contains('is-open') : false);
        openImportBtns.forEach((btn) => {
            if (btn.dataset.importBound === '1') {
                return;
            }
            btn.dataset.importBound = '1';
            btn.addEventListener('click', openImportModal);
        });
        if (closeImportBtn) {
            closeImportBtn.addEventListener('click', closeImportModal);
        }
        if (importModal) {
            importModal.addEventListener('click', (e) => {
                if (e.target && e.target.getAttribute('data-close') === '1') {
                    closeImportModal();
                }
            });
        }

        const uploadForm = document.getElementById('verifyPickUploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', () => setLoading(true));
        }

        const IMPORT_PICKS_URL = `${ROOT_BASE}/utils/import_picks.php`;
        const runImportBtn = document.getElementById('runImportBtn');
        if (runImportBtn && runImportBtn.dataset.importBound !== '1') {
            runImportBtn.dataset.importBound = '1';
            runImportBtn.addEventListener('click', async () => {
                const ok = confirm('Import pick files from Google Drive now? This may take a minute.');
                if (!ok) return;
                runImportBtn.disabled = true;
                setLoading(true);
                try {
                    const res = await fetch(IMPORT_PICKS_URL, {
                        method: 'POST',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'application/json',
                        },
                    });
                    if (!res.ok) {
                        alert('Drive import failed (server error).');
                        return;
                    }
                    window.location.reload();
                } catch (err) {
                    alert('Drive import failed.');
                } finally {
                    runImportBtn.disabled = false;
                    setLoading(false);
                }
            });
        }

        document.querySelectorAll('.warehouse-orders-list a.btn').forEach((link) => {
            link.addEventListener('click', () => setLoading(true));
        });

        const availableOrdersList = document.getElementById('availableOrdersList');
        const toggleAllOrdersBtn = document.getElementById('toggleAllOrdersBtn');
        const loadMoreOrdersBtn = document.getElementById('loadMoreOrdersBtn');
        const currentOrdersFilterInput = document.getElementById('currentOrdersFilterInput');
        const currentOrdersFilterEmpty = document.getElementById('currentOrdersFilterEmpty');
        if (availableOrdersList && toggleAllOrdersBtn && toggleAllOrdersBtn.dataset.ordersToggleBound !== '1') {
            toggleAllOrdersBtn.dataset.ordersToggleBound = '1';
            let showAllOrders = availableOrdersList.dataset.defaultShowAll === '1';
            let queueWorkflow = String(availableOrdersList.dataset.queueWorkflow || '').trim();
            let queueOffset = Number(availableOrdersList.dataset.queueOffset || 0);
            let queueLoading = false;
            const queueWorkflowQuery = queueWorkflow ? `&workflow=${encodeURIComponent(queueWorkflow)}` : '';
            const escapeHtml = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
            const buildWorkflowQueueRowHtml = (order) => {
                const id = Number(order.id || 0);
                const num = String(order.order_number || '');
                const status = String(order.status || '');
                const pickerDone = Boolean(Number(order.picker_done || 0));
                const packerDone = Boolean(Number(order.packer_done || 0));
                const assignedLabel = String(order.assigned_label || 'Unassigned');
                const isPriority = queueWorkflow === 'pack' && Number(order.is_priority || 0) === 1;
                const repeatedLineCount = Number(order.repeated_line_count || 0);
                const repeatedItemDetails = String(order.repeated_item_details || '');
                const showPickBadge = <?= json_encode($entryWorkflow !== 'pack') ?>;
                const showPackBadge = <?= json_encode($entryWorkflow !== 'pick') ?>;
                return `
                    <div class="order-entry" style="margin:4px 0;">
                        <a href="?action=load_order&order_id=${id}&order_number=${encodeURIComponent(num)}${queueWorkflowQuery}" class="btn btn--ghost order-entry__button">
                            <span class="order-entry__main">
                                ${escapeHtml(num)}
                                ${isPriority ? '<span class="order-priority-badge" title="Priority order">⚑ PRIORITY</span>' : ''}
                                ${repeatedLineCount > 0 ? `
                                    <span class="bogo"
                                        title="${escapeHtml(repeatedItemDetails)}"
                                        aria-label="Repeated item lines: ${repeatedLineCount}">
                                        BOGO
                                    </span>
                                ` : ''}
                            </span>
                            <span class="order-entry__checks" aria-label="Verification progress">
                                ${showPickBadge ? `
                                    <span class="order-entry__check${pickerDone ? ' is-complete' : ''}" title="Picker verification${pickerDone ? ' complete' : ' pending'}">
                                        <span class="order-entry__check-box" aria-hidden="true">${pickerDone ? '&#10003;' : ''}</span>
                                        <span class="order-entry__check-label">PICK</span>
                                    </span>
                                ` : ''}
                                ${showPackBadge ? `
                                    <span class="order-entry__check${packerDone ? ' is-complete' : ''}" title="Packer verification${packerDone ? ' complete' : ' pending'}">
                                        <span class="order-entry__check-box" aria-hidden="true">${packerDone ? '&#10003;' : ''}</span>
                                        <span class="order-entry__check-label">PACK</span>
                                    </span>
                                ` : ''}
                            </span>
                            <span class="order-entry__status muted">${escapeHtml(status)}</span>
                        </a>
                        <div class="muted" style="margin-top:2px; font-size:0.75rem;">
                            Assigned: ${escapeHtml(assignedLabel)}
                        </div>
                    </div>
                `;
            };
            const bindWorkflowQueueLinks = () => {
                availableOrdersList.querySelectorAll('a.btn.order-entry__button:not([data-loading-bound="1"])').forEach((link) => {
                    link.dataset.loadingBound = '1';
                    link.addEventListener('click', () => setLoading(true));
                });
            };
            const renderWorkflowQueueEntries = (results, append = false) => {
                const markup = Array.isArray(results) ? results.map((order) => buildWorkflowQueueRowHtml(order)).join('') : '';
                if (append) {
                    if (markup !== '') {
                        availableOrdersList.insertAdjacentHTML('beforeend', markup);
                    }
                } else {
                    availableOrdersList.innerHTML = markup || '<div class="muted">No matching sales orders found.</div>';
                }
                bindWorkflowQueueLinks();
            };
            const applyOrderFilter = () => {
                const entries = availableOrdersList.querySelectorAll('.order-entry');
                const filterValue = (currentOrdersFilterInput?.value || '').trim().toLowerCase();
                let visibleCount = 0;
                entries.forEach((el) => {
                    const isMine = el.classList.contains('order-entry--mine');
                    const orderText = (el.querySelector('.order-entry__main')?.textContent || '').trim().toLowerCase();
                    const matchesFilter = filterValue === '' || orderText.includes(filterValue);
                    if (showAllOrders && matchesFilter) {
                        el.removeAttribute('hidden');
                        el.style.display = '';
                        visibleCount += 1;
                    } else {
                        if (isMine && matchesFilter) {
                            el.removeAttribute('hidden');
                            el.style.display = '';
                            visibleCount += 1;
                        } else {
                            el.setAttribute('hidden', 'hidden');
                            el.style.display = 'none';
                        }
                    }
                });
                const filterEmptyEl = document.getElementById('currentOrdersFilterEmpty');
                if (filterEmptyEl) {
                    filterEmptyEl.hidden = visibleCount !== 0;
                }
            };
            const syncOrderFilterLabel = () => {
                toggleAllOrdersBtn.textContent = showAllOrders ? 'Assigned Only' : 'See All';
            };
            const syncLoadMoreButton = () => {
                if (!loadMoreOrdersBtn) {
                    return;
                }
                const shouldShow = !!queueWorkflow;
                loadMoreOrdersBtn.hidden = !shouldShow;
                loadMoreOrdersBtn.disabled = !shouldShow || queueLoading;
                loadMoreOrdersBtn.textContent = queueLoading ? 'Loading...' : 'Load Next 100';
            };
            const loadNextWorkflowQueueChunk = async () => {
                if (!queueWorkflow || queueLoading || !loadMoreOrdersBtn) {
                    return;
                }
                queueLoading = true;
                syncLoadMoreButton();
                try {
                    const url = new URL(window.location.href);
                    url.search = '';
                    url.searchParams.set('action', 'load_workflow_queue_chunk');
                    url.searchParams.set('workflow', queueWorkflow);
                    url.searchParams.set('offset', String(queueOffset));
                    url.searchParams.set('limit', '100');
                    const res = await fetch(url.toString(), {
                        headers: {
                            'Accept': 'application/json',
                        },
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data?.ok) {
                        alert(data?.error || 'Unable to load more orders.');
                        return;
                    }
                    const results = Array.isArray(data.results) ? data.results : [];
                    if (results.length === 0) {
                        loadMoreOrdersBtn.disabled = true;
                        loadMoreOrdersBtn.textContent = 'No More Orders';
                        return;
                    }
                    renderWorkflowQueueEntries(results, true);
                    queueOffset = Number(data.next_offset || (queueOffset + results.length));
                    availableOrdersList.dataset.queueOffset = String(queueOffset);
                    if (!data.has_more) {
                        loadMoreOrdersBtn.disabled = true;
                        loadMoreOrdersBtn.textContent = 'No More Orders';
                    }
                    applyOrderFilter();
                } catch (err) {
                    alert('Unable to load more orders.');
                } finally {
                    queueLoading = false;
                    syncLoadMoreButton();
                }
            };
            availableOrdersList.classList.toggle('orders-assigned-only', !showAllOrders);
            void applyOrderFilter();
            syncOrderFilterLabel();
            syncLoadMoreButton();
            toggleAllOrdersBtn.addEventListener('click', () => {
                showAllOrders = !showAllOrders;
                availableOrdersList.classList.toggle('orders-assigned-only', !showAllOrders);
                void applyOrderFilter();
                syncOrderFilterLabel();
            });
            if (loadMoreOrdersBtn && loadMoreOrdersBtn.dataset.loadMoreBound !== '1') {
                loadMoreOrdersBtn.dataset.loadMoreBound = '1';
                loadMoreOrdersBtn.addEventListener('click', () => {
                    void loadNextWorkflowQueueChunk();
                });
            }
            if (currentOrdersFilterInput && currentOrdersFilterInput.dataset.filterBound !== '1') {
                currentOrdersFilterInput.dataset.filterBound = '1';
                currentOrdersFilterInput.addEventListener('input', applyOrderFilter);
            }
        }

        const assignModal = document.getElementById('assignOrderModal');
        const assignCloseBtn = document.getElementById('assignOrderCloseBtn');
        const assignConfirmBtn = document.getElementById('assignOrderConfirmBtn');
        const assignMeta = document.getElementById('assignOrderMeta');
        const assignUserSelect = document.getElementById('assignOrderUserSelect');
        let assignOrderId = 0;

        const closeAssignModal = () => {
            if (!assignModal) return;
            assignModal.classList.remove('is-open');
            assignModal.setAttribute('aria-hidden', 'true');
            assignModal.setAttribute('hidden', 'hidden');
            assignOrderId = 0;
        };
        const openAssignModal = (orderId, orderNumber) => {
            if (!assignModal) return;
            assignOrderId = Number(orderId || 0);
            if (assignMeta) {
                assignMeta.textContent = orderNumber ? `Sales Order: ${orderNumber}` : '';
            }
            assignModal.removeAttribute('hidden');
            assignModal.setAttribute('aria-hidden', 'false');
            assignModal.classList.add('is-open');
        };
        window.openAssignOrderModal = openAssignModal;

        if (assignModal) {
            assignModal.addEventListener('click', (e) => {
                if (e.target && e.target.getAttribute('data-close') === '1') {
                    closeAssignModal();
                }
            });
        }
        if (assignCloseBtn) {
            assignCloseBtn.addEventListener('click', closeAssignModal);
        }
        if (assignConfirmBtn) {
            assignConfirmBtn.addEventListener('click', async () => {
                if (!assignOrderId) return;
                const userId = Number(assignUserSelect?.value || 0);
                if (!userId) {
                    alert('Select a user.');
                    return;
                }
                try {
                    setLoading(true);
                    const url = new URL(window.location.href);
                    url.searchParams.set('action', 'assign_order');
                    const res = await fetch(url.toString(), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ order_id: assignOrderId, user_id: userId }),
                    });
                    if (!res.ok) {
                        alert('Assignment failed.');
                        return;
                    }
                    const data = await res.json();
                    if (!data.ok) {
                        alert(data.error || 'Assignment failed.');
                        return;
                    }
                    closeAssignModal();
                    window.location.reload();
                } catch (err) {
                    alert('Assignment failed.');
                } finally {
                    setLoading(false);
                }
            });
        }

        document.querySelectorAll('.order-assign-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const orderId = Number(btn.getAttribute('data-order-id') || 0);
                const orderNumber = btn.getAttribute('data-order-number') || '';
                openAssignModal(orderId, orderNumber);
            });
        });
    });
</script>
<?php if (!$workflowSelectionMode && !$showWorkflowHub && empty($parsed_data)): ?>
    <div id="verifyPickNotice" class="vp-modal" hidden aria-hidden="true">
        <div class="vp-modal__backdrop" data-close="1"></div>
        <div class="vp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="verifyPickNoticeTitle">
            <div class="vp-modal__header">
                <h3 id="verifyPickNoticeTitle" class="vp-modal__title">Pick Sheet Required</h3>
                <button type="button" class="vp-modal__close" aria-label="Close">Close</button>
            </div>
            <p class="vp-modal__body">Please select a Sales Order from the list 📂.</p>
        </div>
    </div>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('verifyPickNotice');
            if (!modal) return;

            const close = () => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                modal.setAttribute('hidden', 'hidden');
            };

            const open = () => {
                modal.removeAttribute('hidden');
                modal.setAttribute('aria-hidden', 'false');
                modal.classList.add('is-open');
            };

            modal.addEventListener('click', (e) => {
                if (e.target && e.target.getAttribute('data-close') === '1') {
                    close();
                }
            });

            const closeBtn = modal.querySelector('.vp-modal__close');
            if (closeBtn) {
                closeBtn.addEventListener('click', close);
            }

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                    close();
                }
            });

            open();
        });
    </script>

<?php endif; ?>

<script>
    window.bindVerifyCompletedSearch = window.bindVerifyCompletedSearch || (() => {
        const completedSearchInput = document.getElementById('completedSearchInput');
        const completedSearchBtn = document.getElementById('completedSearchBtn');
        const completedSearchResults = document.getElementById('completedSearchResults');
        if (!completedSearchInput || completedSearchInput.dataset.completedSearchBound === '1') {
            return;
        }
        completedSearchInput.dataset.completedSearchBound = '1';

        const workflowSuffix = <?= json_encode($entryWorkflow !== '' ? '&workflow=' . $entryWorkflow : '') ?>;
        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const renderCompletedResults = (results) => {
            if (!completedSearchResults) return;
            if (!results || results.length === 0) {
                completedSearchResults.innerHTML = '<div class="muted">No completed orders found.</div>';
                return;
            }
            const showPickBadge = <?= json_encode($entryWorkflow !== 'pack') ?>;
            const showPackBadge = <?= json_encode($entryWorkflow !== 'pick') ?>;
            completedSearchResults.innerHTML = results.map((order) => {
                const id = Number(order.id || 0);
                const num = String(order.order_number || '');
                const status = String(order.status || '');
                const pickerDone = Boolean(Number(order.picker_done || 0));
                const packerDone = Boolean(Number(order.packer_done || 0));
                const repeatedLineCount = Number(order.repeated_line_count || 0);
                const repeatedItemDetails = String(order.repeated_item_details || '');
                return `
                    <div style="margin:4px 0;">
                        <a href="?action=load_order&order_id=${id}&order_number=${encodeURIComponent(num)}${workflowSuffix}" class="btn btn--ghost order-entry__button">
                            <span class="order-entry__main">
                                ${escapeHtml(num)}
                                ${repeatedLineCount > 0 ? `
                                    <span class="bogo"
                                        title="${escapeHtml(repeatedItemDetails)}"
                                        aria-label="Repeated item lines: ${repeatedLineCount}">
                                        BOGO
                                    </span>
                                ` : ''}
                            </span>
                            <span class="order-entry__checks" aria-label="Verification progress">
                                ${showPickBadge ? `
                                    <span class="order-entry__check${pickerDone ? ' is-complete' : ''}" title="Picker verification${pickerDone ? ' complete' : ' pending'}">
                                        <span class="order-entry__check-box" aria-hidden="true">${pickerDone ? '&#10003;' : ''}</span>
                                        <span class="order-entry__check-label">PICK</span>
                                    </span>
                                ` : ''}
                                ${showPackBadge ? `
                                    <span class="order-entry__check${packerDone ? ' is-complete' : ''}" title="Packer verification${packerDone ? ' complete' : ' pending'}">
                                        <span class="order-entry__check-box" aria-hidden="true">${packerDone ? '&#10003;' : ''}</span>
                                        <span class="order-entry__check-label">PACK</span>
                                    </span>
                                ` : ''}
                            </span>
                            <span class="order-entry__status muted">${escapeHtml(status)}</span>
                        </a>
                    </div>
                `;
            }).join('');
        };

        const runCompletedSearch = async () => {
            const q = completedSearchInput.value.trim();
            if (!q) {
                if (completedSearchResults) completedSearchResults.innerHTML = '';
                return;
            }
            if (completedSearchResults) {
                completedSearchResults.innerHTML = '<div class="muted">Searching…</div>';
            }
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('action', 'search_completed');
                url.searchParams.set('q', q);
                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) {
                    if (completedSearchResults) completedSearchResults.innerHTML = '<div class="muted">Search failed.</div>';
                    return;
                }
                const data = await res.json();
                if (!data.ok) {
                    if (completedSearchResults) completedSearchResults.innerHTML = `<div class="muted">${data.error || 'Search failed.'}</div>`;
                    return;
                }
                renderCompletedResults(data.results || []);
            } catch (err) {
                if (completedSearchResults) completedSearchResults.innerHTML = '<div class="muted">Search failed.</div>';
            }
        };

        if (completedSearchBtn) {
            completedSearchBtn.addEventListener('click', runCompletedSearch);
        }
        completedSearchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                runCompletedSearch();
            }
        });
        let completedSearchTimer = null;
        completedSearchInput.addEventListener('input', () => {
            if (completedSearchTimer) {
                clearTimeout(completedSearchTimer);
            }
            completedSearchTimer = setTimeout(runCompletedSearch, 300);
        });
    });

    window.bindVerifyManifestLookup = window.bindVerifyManifestLookup || (() => {
        const manifestLookupInput = document.getElementById('manifestLookupInput');
        const manifestLookupBtn = document.getElementById('manifestLookupBtn');
        const manifestLookupResults = document.getElementById('manifestLookupResults');
        if (!manifestLookupInput || manifestLookupInput.dataset.manifestLookupBound === '1') {
            return;
        }
        manifestLookupInput.dataset.manifestLookupBound = '1';

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const openManifestUrl = (action, token) => {
            if (!token) {
                return;
            }
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('action', action);
            url.searchParams.set('token', token);
            window.open(url.toString(), '_blank', 'noopener,noreferrer');
        };

        const openLabelsUrl = (token) => {
            if (!token) {
                return;
            }
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('action', 'box_label_print');
            url.searchParams.set('manifest_token', token);
            window.open(url.toString(), '_blank', 'noopener,noreferrer');
        };

        const renderLookupResult = (manifest) => {
            if (!manifestLookupResults) return;
            if (!manifest) {
                manifestLookupResults.innerHTML = '<div class="muted">No finalized manifest found.</div>';
                return;
            }
            const token = String(manifest.token || '').trim();
            const so = String(manifest.sales_order_number || '');
            const boxCount = Number(manifest.box_count || 0);
            const itemCount = Number(manifest.item_count || 0);
            const unassigned = Number(manifest.unassigned_count || 0);
            manifestLookupResults.innerHTML = `
                <div style="display:grid; gap:8px;">
                    <div class="order-entry__main">${escapeHtml(so || 'Finalized Manifest')}</div>
                    <div class="muted">Boxes: ${boxCount} | Packed items: ${itemCount} | Unassigned: ${unassigned}</div>
                    <div style="display:flex; flex-wrap:wrap; gap:6px;">
                        <button type="button" class="btn btn--ghost" data-manifest-action="review">Review Manifest</button>
                        <button type="button" class="btn btn--ghost" data-manifest-action="labels">Print All Labels</button>
                    </div>
                </div>
            `;
            manifestLookupResults.querySelectorAll('[data-manifest-action]').forEach((button) => {
                button.addEventListener('click', () => {
                    const action = button.getAttribute('data-manifest-action') || '';
                    if (action === 'review') {
                        openManifestUrl('packing_manifest_print', token);
                    } else if (action === 'download') {
                        openManifestUrl('packing_manifest', token);
                    } else if (action === 'labels') {
                        openLabelsUrl(token);
                    }
                });
            });
        };

        const runManifestLookup = async () => {
            const q = manifestLookupInput.value.trim();
            if (!q) {
                if (manifestLookupResults) {
                    manifestLookupResults.innerHTML = '';
                }
                return;
            }
            if (manifestLookupResults) {
                manifestLookupResults.innerHTML = '<div class="muted">Searching…</div>';
            }
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('action', 'lookup_packing_manifest');
                url.searchParams.set('q', q);
                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    if (manifestLookupResults) {
                        manifestLookupResults.innerHTML = `<div class="muted">${escapeHtml(data.error || 'Lookup failed.')}</div>`;
                    }
                    return;
                }
                renderLookupResult(data.manifest || null);
            } catch (err) {
                if (manifestLookupResults) {
                    manifestLookupResults.innerHTML = '<div class="muted">Lookup failed.</div>';
                }
            }
        };

        if (manifestLookupBtn) {
            manifestLookupBtn.addEventListener('click', runManifestLookup);
        }
        manifestLookupInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                runManifestLookup();
            }
        });
        let manifestLookupTimer = null;
        manifestLookupInput.addEventListener('input', () => {
            if (manifestLookupTimer) {
                clearTimeout(manifestLookupTimer);
            }
            manifestLookupTimer = setTimeout(runManifestLookup, 300);
        });
    });

    window.addEventListener('DOMContentLoaded', () => {
        if (typeof window.bindVerifyCompletedSearch === 'function') {
            window.bindVerifyCompletedSearch();
        }
        if (typeof window.bindVerifyManifestLookup === 'function') {
            window.bindVerifyManifestLookup();
        }
    });
</script>


<!-- TABLE HEADER -->
<?php if (!empty($parsed_data)): ?>
    <?php
    $hasPackerVerify = true;
    $renderWorkflow = in_array($entryWorkflow, ['pick', 'pack'], true) ? $entryWorkflow : '';
    $renderIsPacker = $renderWorkflow === 'pack';
    ?>
    <section class="card mode-picker" id="pickContent" data-pick-order-id="<?= (int) ($parsed_data['db_order_id'] ?? 0) ?>"
        data-has-packer-verify="<?= $hasPackerVerify ? '1' : '0' ?>"
        data-entry-workflow="<?= htmlspecialchars($entryWorkflow, ENT_QUOTES) ?>"
        data-workflow-render="<?= htmlspecialchars($renderWorkflow, ENT_QUOTES) ?>"
        data-order-status="<?= htmlspecialchars(strtoupper((string) ($parsed_data['status'] ?? '')), ENT_QUOTES) ?>"
        data-is-priority="<?= !empty($parsed_data['is_priority']) ? '1' : '0' ?>"
        data-sales-order-number="<?= htmlspecialchars((string) ($parsed_data['sales_order_number'] ?? ''), ENT_QUOTES) ?>"
        style="padding: 20px; margin-top: 16px;">
        <div class="verify-summary">
            <div class="verify-summary__primary">
                <div class="verify-summary__label">Sales Order #</div>
                <div class="verify-summary__value">
                    <?= htmlspecialchars((string) ($parsed_data['sales_order_number'] ?? '')) ?>
                </div>
            </div>
            <div class="verify-summary__primary">
                <div class="verify-summary__label">Items Found</div>
                <div class="verify-summary__value"><?= count($parsed_data['items'] ?? []) ?></div>
            </div>
            <div class="verify-summary__primary">
                <div class="verify-summary__label">Short Shipped</div>
                <div class="verify-summary__value" id="shortShipCountValue">
                    <?= (int) ($parsed_data['short_ship_count'] ?? count(array_filter($parsed_data['items'] ?? [], static fn(array $item): bool => !empty($item['is_short_ship'])))) ?>
                </div>
            </div>
            <div class="verify-summary__meta">
                <div class="verify-summary__label">Order Assigned</div>
                <div><?= htmlspecialchars((string) ($parsed_data['assigned_user_label'] ?? 'Unassigned')) ?></div>
            </div>
            <div class="verify-priority-summary<?= !empty($parsed_data['is_priority']) ? ' is-active' : '' ?>"
                id="verifyPrioritySummary" <?= empty($parsed_data['is_priority']) ? 'hidden' : '' ?>>
                <span aria-hidden="true">⚑</span> PRIORITY
            </div>
        </div>

        <div class="card-table-header">

            <div class="card verify-scan-card" id="verifyScanPanel">
                <div id="verifyModeBadge" class="verify-mode-badge">
                    <?= $renderIsPacker ? 'Packer Verification Mode' : 'Picker Verification Mode' ?>
                </div>
                <label for="scanInput" style="display:block; font-weight:600; margin-bottom:6px;">Scan or type a barcode
                    (SKU)</label>
                <form id="scanInputForm" class="verify-scan-row">
                    <input id="scanInput" class="input" type="text" placeholder="Scan barcode here" autocomplete="off"
                        autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="go" />
                    <button type="button" id="scanCameraBtn" class="verify-scan-btn" aria-label="Start scan">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="camera-icon">
                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z">
                            </path>
                            <circle cx="12" cy="13" r="4"></circle>
                        </svg>
                    </button>
                    <button type="submit" hidden aria-hidden="true" tabindex="-1"></button>
                </form>
                <div id="scanPreview" class="verify-scan-preview" hidden aria-hidden="true">
                    <video id="scanPreviewVideo" playsinline></video>
                </div>
                <div id="scanStatus" class="muted" style="margin-top:6px;" aria-live="polite"></div>
                <div id="stageStatusNote" class="muted" style="margin-top:6px;" aria-live="polite"></div>
            </div>

            <div class="card verify-ship-info-card" id="verifyShipInfoCard">
                <div class="verify-ship-info-card__header">
                    <div>
                        <div class="verify-ship-info-card__label">Shipping Info</div>
                    </div>
                    <button type="button" class="verify-ship-info-card__add" id="openShipInfoBtn" aria-label="Add ship info"
                        onclick="window.toggleShipInfoInput && window.toggleShipInfoInput(); return false;">+</button>
                </div>
                <?php if (!empty($shipInfoLookup)): ?>
                    <div id="verifyShipInfoPreview" class="verify-ship-info-card__preview">
                        <?php if (($shipInfoLookup['sales_order_number'] ?? '') !== ''): ?>
                            <div class="verify-ship-info-card__section">
                                <div class="verify-ship-info-card__section-label">Sales Order</div>
                                <div class="verify-ship-info-card__section-value">
                                    <?= htmlspecialchars((string) $shipInfoLookup['sales_order_number'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="verify-ship-info-card__section">
                            <div class="verify-ship-info-card__section-label">Ship To</div>
                            <div class="verify-ship-info-card__section-value">
                                <?php
                                $shipLines = [];
                                $addr1 = trim((string) ($shipInfoLookup['ship_to_address'] ?? $shipInfoLookup['ship_to_address_1'] ?? ''));
                                $addr2 = trim((string) ($shipInfoLookup['ship_to_address_2'] ?? ''));
                                $city = trim((string) ($shipInfoLookup['ship_to_city'] ?? ''));
                                $state = trim((string) ($shipInfoLookup['ship_to_state'] ?? ''));
                                $zip = trim((string) ($shipInfoLookup['ship_to_zip_code'] ?? ''));
                                $country = trim((string) ($shipInfoLookup['ship_to_country_region_code'] ?? $shipInfoLookup['ship_to_country_code'] ?? ''));

                                if ($addr1 !== '') {
                                    $shipLines[] = $addr1;
                                }
                                if ($addr2 !== '') {
                                    $shipLines[] = $addr2;
                                }

                                $cityLine = trim(implode(' ', array_filter([$city, $state, $zip], static fn(string $v): bool => $v !== '')));
                                if ($cityLine !== '') {
                                    $shipLines[] = $cityLine;
                                }
                                if ($country !== '') {
                                    $shipLines[] = $country;
                                }

                                echo $shipLines !== []
                                    ? nl2br(htmlspecialchars(implode("\n", $shipLines), ENT_QUOTES, 'UTF-8'))
                                    : '<span class="muted">No ship-to address saved.</span>';
                                ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div id="verifyShipInfoPreview"
                        class="verify-ship-info-card__preview<?= $shipInfoPreviewText === '' ? ' is-empty' : '' ?>">
                        <?= htmlspecialchars($shipInfoPreviewText !== '' ? $shipInfoPreviewText : 'No ship info saved for this order.', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <?php if ($shipInfoConsoleData !== null): ?>
                    <script>
                        console.info('verify_pick shipping lookup:', <?= json_encode($shipInfoConsoleData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                    </script>
                <?php endif; ?>
            </div>
            <div id="shipInfoInputRow" class="verify-ship-info-card__input-row hidden">
                <input id="shipInfoInput" class="input ship-info-input" type="text" placeholder="Paste ship info here"
                    autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="done" />
                <div class="ship-info-editor__actions">
                    <button type="button" class="btn btn--ghost" id="shipInfoClearBtn">Clear</button>
                </div>
            </div>

            <section class="verify-order-notes" id="verifyOrderNotesPanel" aria-labelledby="verifyOrderNotesTitle">
                <div class="verify-order-notes__header">
                    <h3 id="verifyOrderNotesTitle">Pick / Pack Notes</h3>
                    <button type="button" class="btn btn--ghost" id="addOrderNoteBtn">Add Note</button>
                </div>
                <div id="verifyOrderNotesList" class="verify-order-notes__list" aria-live="polite"></div>
            </section>
        </div>

        <div class="card verify-scan-result-panel" id="verifyScanResultPanel" aria-live="polite" hidden aria-hidden="true">
            <h3 class="verify-scan-result-panel__title">Scan Result</h3>
            <div class="verify-scan-result-panel__body">
                <div class="verify-scan-result-panel__media" id="verifyScanResultMedia" hidden>
                    <img id="verifyScanResultImage" class="verify-scan-result-panel__image" src="" alt="" hidden />
                    <div id="verifyScanResultImageFallback" class="verify-scan-result-panel__image-fallback">No image
                    </div>
                </div>
                <div class="verify-scan-result-panel__details" id="verifyScanResultDetails" hidden>
                    <div class="verify-scan-result-panel__topline">
                        <div>
                            <div class="verify-scan-result-panel__field-label">Item</div>
                            <h4 class="verify-scan-result-panel__sku" id="verifyScanResultSku">-</h4>
                        </div>

                    </div>
                    <div class="verify-scan-result-panel__desc" id="verifyScanResultDesc">-</div>
                    <div class="verify-scan-result-panel__status" id="verifyScanResultStatus">ACTIVE</div>
                    <div class="verify-scan-result-panel__grid">
                        <div class="verify-scan-result-panel__field">
                            <div class="verify-scan-result-panel__field-label">Scanned Code</div>
                            <div class="verify-scan-result-panel__field-value" id="verifyScanResultCode">-</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="verify-header-actions">
            <div class="verify-order-actions">
                <button type="button" class="btn btn--ghost" id="claimCurrentOrderBtn">Claim Order</button>
                <button type="button" class="btn btn--ghost"
                    id="toggleOrderPriorityBtn"><?= !empty($parsed_data['is_priority']) ? 'Remove Priority' : 'Mark Priority' ?></button>
                <?php if ($currentUserHasOrderOverride): ?>
                    <button type="button" class="btn btn--ghost" id="assignCurrentOrderBtn">Assign</button>
                <?php endif; ?>
                <?php if (in_array(pickResolveCurrentRoleId(), [1, 2], true)): ?>
                    <button type="button" class="btn verify-delete-btn" id="deletePickOrderBtn">Delete Order</button>
                <?php endif; ?>
                <!-- <button type="button" class="btn btn--ghost" id="saveVerifiedListBtn" disabled>Save Verified
                    List</button> -->
                <button type="button" class="btn btn--ghost" id="toggleVerifyModeBtn">Packer Verify</button>
                <button type="button" class="btn btn--ghost" id="returnToPickBtn" <?= $renderIsPacker ? '' : ' hidden' ?>>Send Back To Pick</button>
                <button type="button" class="btn verify-complete-btn"
                    id="closeCurrentOrderBtn"><?= $renderIsPacker ? 'Complete Pack' : 'Complete Pick' ?></button>
            </div>
            <div class="button-group verify-action-buttons">
                <!-- <a class="btn btn--ghost" href="?action=download">Download Excel</a> -->
                <!-- <a class="btn btn--ghost" href="?action=export_parsed">Export Parsed JSON</a> -->
                <!-- <a class="btn btn--ghost" href="?action=download_converted">Download Converted XLSX</a> -->
                <!-- <a class="btn btn--ghost" href="?action=export_raw_rows">Download Raw Rows</a> -->
                <button type="button" class="btn btn--ghost workflow-picker-action" id="addPickLineBtn">Add Line</button>
                <button type="button" class="btn btn--ghost workflow-picker-action" id="resetVerifiedBtn">Reset</button>
                <button type="button" class="btn btn--ghost workflow-picker-action" id="toggleDeleteModeBtn">Edit</button>
                <button type="button" class="btn btn--ghost workflow-picker-action" id="deleteSelectedBtn"
                    disabled>Delete</button>

                <!-- Additional button to print box labels -->
                <button type="button" class="btn btn--ghost workflow-pack-action" id="printBoxLabelBtn"
                    title="Packing Manager">🏷️</button>
                <!-- <button type="button" class="btn btn--primary" id="pickEventsOpenBtn">
                    Pick Events Search
                </button> -->
                <div id="pickProgress" class="verify-progress-pill verify-progress-pill--inline"
                    aria-label="Order progress">
                    <span id="pickProgressValue">0%</span>
                </div>

            </div>
        </div>

        </div>



        <div class="table-wrap verify-results-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th data-sort="text">SKU (Item No.)</th>
                        <th data-sort="text">Warehouse</th>
                        <th data-sort="text">Shelf Location</th>
                        <th data-sort="text">Description</th>
                        <th class="text-right" data-sort="number">Qty</th>
                        <th class="text-right workflow-picker-only" data-sort="number">Picker Verified</th>
                        <th class="text-right workflow-packer-only" data-sort="number">Packer Verified</th>
                        <!-- <th class="text-right" data-sort="number">Inventory</th> -->
                        <th data-sort="text">Short ship</th>
                        <th class="delete-col">Edit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parsed_data['items'] as $item): ?>
                        <?php
                        $qty = (int) ($item['quantity'] ?? 0);
                        $verifiedPicker = (int) ($item['qty_verified_picker'] ?? $item['qty_verified'] ?? 0);
                        $verifiedPacker = (int) ($item['qty_verified_packer'] ?? 0);
                        $isShortShip = !empty($item['is_short_ship']);
                        $shortShipQty = (int) ($item['short_ship_qty'] ?? 0);
                        $packExpectedQty = max(0, $qty - $shortShipQty);
                        $pickItemId = (int) ($item['pick_item_id'] ?? 0);
                        $itemSku = trim((string) ($item['sku'] ?? ''));
                        $itemNo = trim((string) ($item['item_no'] ?? $itemSku));
                        // Set at import: more than one Take on the item's source line.
                        $isBogoItem = !empty($item['is_bogo']);
                        ?>
                        <tr<?= $pickItemId > 0 ? ' data-pick-item-id="' . $pickItemId . '"' : '' ?>
                            data-short-ship-qty="<?= $shortShipQty ?>" data-is-bogo="<?= $isBogoItem ? '1' : '0' ?>"
                            data-sku="<?= htmlspecialchars((string) ($item['sku'] ?? '')) ?>"
                            data-item-no="<?= htmlspecialchars($itemNo) ?>"
                            data-upc="<?= htmlspecialchars((string) ($item['upc'] ?? '')) ?>">
                            <td>
                                <span class="sku-cell-wrap">
                                    <strong class="sku-cell"><?= htmlspecialchars($itemSku) ?></strong>
                                    <?php if ($isBogoItem): ?>
                                        <span class="bogo" title="Repeated item line in this order">BOGO</span>
                                    <?php endif; ?>
                                    <button type="button" class="sku-barcode-trigger sku-barcode-trigger--inline"
                                        data-sku-barcode-trigger="1"
                                        aria-label="View Code 128 barcode for SKU <?= htmlspecialchars((string) ($item['sku'] ?? ''), ENT_QUOTES) ?>"
                                        title="View SKU Code 128 barcode">
                                        <span class="sku-barcode-trigger__label">View SKU</span>
                                    </button>
                                </span>
                            </td>
                            <?php
                            $locLabels = array_values(array_filter($item['db_location_labels'] ?? [], static fn($v) => $v !== ''));
                            $firstLoc = $locLabels[0] ?? '';
                            ?>
                            <td><?= htmlspecialchars((string) $firstLoc) ?></td>
                            <td><?= htmlspecialchars((string) ($item['shelf_location'] ?? '')) ?></td>
                            <td class="desc-cell"><?= htmlspecialchars((string) ($item['description'] ?? '')) ?></td>
                            <td class="text-right qty-cell" data-qty="<?= htmlspecialchars((string) ($qty)) ?>">
                                <?= htmlspecialchars((string) ($qty)) ?>
                            </td>
                            <td class="text-right verified-cell verified-picker-cell workflow-picker-only"
                                data-verified="<?= $verifiedPicker ?>">
                                <?= $verifiedPicker ?> /
                                <?= htmlspecialchars((string) ($qty)) ?>
                                <span class="verify-check verify-check-picker" aria-hidden="true">✔</span>
                            </td>
                            <td class="text-right verified-cell verified-packer-cell workflow-packer-only"
                                data-verified="<?= $verifiedPacker ?>">
                                <?= $verifiedPacker ?> /
                                <?= htmlspecialchars((string) ($packExpectedQty)) ?>
                                <span class="verify-check verify-check-packer" aria-hidden="true">&#10003;</span>
                            </td>
                            <!-- <td class="text-right"><?= htmlspecialchars((string) ($item['inventory_qty'] ?? '0')) ?></td> -->
                            <td style="text-align:center;">
                                <input type="checkbox" class="short-ship-check" <?= $isShortShip ? ' checked' : '' ?>
                                    aria-label="Short ship <?= htmlspecialchars((string) ($item['sku'] ?? '')) ?>">
                                <div class="short-ship-tag" hidden></div>
                            </td>
                            <td class="delete-col">
                                <input type="checkbox" class="delete-check"
                                    aria-label="Delete line <?= htmlspecialchars((string) ($item['sku'] ?? '')) ?>">
                            </td>
                            </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <div id="verifyPickToast" class="vp-toast" aria-live="polite" aria-atomic="true"></div>
    <!-- Modal for printing box labels on DYMO 30334 -->
    <div id="boxLabelModal" class="vp-modal" hidden aria-hidden="true">
        <div class="vp-modal__backdrop" data-close="1"></div>
        <div class="vp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="boxLabelTitle">
            <div class="vp-modal__header">
                <h3 id="boxLabelTitle" class="vp-modal__title">Packing Manager</h3>
                <button type="button" class="vp-modal__close" id="boxLabelCloseBtn" aria-label="Close">Close</button>
            </div>
            <div class="vp-modal__body">
                <div class="box-label-modal-grid">
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:4px;">Sales Order #</label>
                        <input class="input" id="boxLabelSO" placeholder="SO25364" />
                    </div>
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:4px;">Boxes To Print</label>
                        <input class="input" id="boxLabelCount" type="number" min="1" step="1" value="1" />
                    </div>
                </div>
                <div id="boxLabelSequenceNote" class="box-label-sequence-note">
                    Labels will print as <strong>Box 1</strong>. Each box can have its own dimensions, weight, and item
                    assignments.
                </div>
                <div class="box-label-modal-layout">
                    <div class="box-label-modal-main">
                        <div id="boxLabelRows" class="box-label-rows" aria-live="polite"></div>
                    </div>
                    <aside id="boxLabelItemPane" class="box-label-item-pane" hidden aria-hidden="true">
                        <div class="box-label-item-pane__header">
                            <div>
                                <div class="box-label-item-pane__title" id="boxLabelItemPaneTitle">Items to Pack</div>
                                <div class="box-label-item-pane__note" id="boxLabelItemPaneNote">Choose which verified items
                                    belong in each box.</div>
                            </div>
                        </div>
                        <div id="boxLabelItemList" class="box-label-item-list" aria-live="polite"></div>
                    </aside>
                </div>
                <div id="packingSummaryPanel" class="packing-summary-panel" hidden aria-live="polite"></div>
                <div class="box-label-modal-actions" id="boxLabelManagerActions">
                    <button type="button" class="btn btn--ghost" id="boxLabelCancelBtn">Cancel</button>
                    <!-- <button type="button" class="btn btn--ghost" id="boxLabelManifestBtn" hidden>Download JSON</button> -->
                    <!-- <button type="button" class="btn btn--ghost" id="boxLabelManifestPrintBtn" hidden>Print
                        Manifest</button> -->
                    <button type="button" class="btn btn--ghost" id="boxLabelFinalizeBtn">Finalize Packing</button>
                </div>
                <div class="box-label-modal-actions packing-summary-panel__meta packing-summary-panel__actions"
                    id="packingSummaryActions" hidden>
                    <button type="button" class="btn btn--ghost" id="boxLabelPrintLabelsBtn">Print Labels</button>
                    <button type="button" class="btn btn--ghost" id="boxLabelPrintSummaryBtn">Print Summary</button>
                </div>
                <div class="box-label-print-tip">
                    Print tip: choose <strong>Actual size / 100%</strong>, disable headers/footers.
                </div>
            </div>
        </div>
    </div>
    <div id="verifySkuModal" class="vp-modal vp-modal--sku" hidden aria-hidden="true">
        <div class="vp-modal__backdrop" data-close="1"></div>
        <div class="vp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="verifySkuTitle">
            <div class="vp-modal__header">
                <h3 id="verifySkuTitle" class="vp-modal__title">Add SKU Line</h3>
                <button type="button" class="vp-modal__close" id="verifySkuCloseBtn" aria-label="Close">Close</button>
            </div>
            <div class="vp-modal__body">
                <label for="verifySkuInput" style="display:block; font-weight:600; margin-bottom:6px;">Search SKU</label>
                <div class="vp-sku-search">
                    <input id="verifySkuInput" class="input" type="text" placeholder="Type SKU or description"
                        autocomplete="off" />
                    <input id="verifySkuQty" class="input vp-sku-qty" type="number" min="1" step="1" value="1"
                        aria-label="Quantity" />
                    <button type="button" class="btn btn--ghost" id="verifySkuSearchBtn">Enter</button>
                </div>
                <div id="verifySkuResults" class="vp-sku-results" aria-live="polite"></div>
            </div>
        </div>
    </div>
    <div id="verifySkuBarcodeModal" class="vp-modal vp-modal--barcode" hidden aria-hidden="true">
        <div class="vp-modal__backdrop" data-close="1"></div>
        <div class="vp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="verifySkuBarcodeTitle">
            <div class="vp-modal__header">
                <h3 id="verifySkuBarcodeTitle" class="vp-modal__title">SKU Code 128 Barcode</h3>
                <button type="button" class="vp-modal__close" id="verifySkuBarcodeCloseBtn"
                    aria-label="Close">Close</button>
            </div>
            <div class="vp-modal__body">
                <div class="vp-barcode-modal__meta">
                    <div class="vp-barcode-modal__sku-line">
                        <span class="vp-barcode-modal__label">SKU</span>
                        <strong id="verifySkuBarcodeSku"></strong>
                    </div>
                    <div class="vp-barcode-modal__sku-line">
                        <span class="vp-barcode-modal__label">Code 128 value</span>
                        <span id="verifySkuBarcodeValue" class="vp-barcode-modal__code"></span>
                    </div>
                </div>
                <div id="verifySkuBarcodeTarget" class="vp-barcode-modal__target" aria-live="polite"></div>
                <div id="verifySkuBarcodeNote" class="vp-barcode-modal__note"></div>
                <div class="vp-barcode-modal__actions">
                    <button type="button" class="btn btn--ghost" id="verifySkuBarcodePrintBtn">Print Barcode</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            'use strict';

            window.__pickEventsStandaloneScriptLoaded = true;

            const homeInput = document.getElementById('pickEventSearch');
            const homeSearchBtn = document.getElementById('openModalBtn');
            const toolbarOpenBtn = document.getElementById('pickEventsOpenBtn');
            const modal = document.getElementById('pick-events-modal');
            const closeBtn = document.getElementById('pickEventsCloseBtn');
            const modalInput = document.getElementById('pick-events-order-number');
            const modalSearchBtn = document.getElementById('pick-events-search-btn');
            const errorBox = document.getElementById('pick-events-error');
            const resultsBox = document.getElementById('pick-events-results');
            const summaryBox = document.getElementById('pick-events-summary');
            const tbody = document.getElementById('pick-events-tbody');
            const csvBtn = document.getElementById('pick-events-export-csv');
            const excelBtn = document.getElementById('pick-events-export-excel');
            const pdfBtn = document.getElementById('pick-events-export-pdf');

            if (!modal) {
                console.warn('[pick-events] Modal not found.');
                return;
            }

            const toggleExports = (show) => {
                [csvBtn, excelBtn, pdfBtn].forEach((btn) => {
                    if (btn) {
                        btn.hidden = !show;
                    }
                });
            };

            const clearError = () => {
                if (!errorBox) {
                    return;
                }
                errorBox.textContent = '';
                errorBox.hidden = true;
            };

            const showError = (message) => {
                if (errorBox) {
                    errorBox.textContent = String(message || 'Unable to load pick events.');
                    errorBox.hidden = false;
                }
                if (resultsBox) {
                    resultsBox.hidden = true;
                }
                toggleExports(false);
            };

            const openPickEventsModal = (orderNumber = '') => {
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';

                if (modalInput && orderNumber) {
                    modalInput.value = orderNumber;
                }

                clearError();
                if (resultsBox) {
                    resultsBox.hidden = true;
                }
                toggleExports(false);

                window.requestAnimationFrame(() => {
                    if (modalInput) {
                        modalInput.focus();
                        modalInput.select();
                    }
                });
            };

            const closePickEventsModal = () => {
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            };

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            }[char]));

            const formatActivity = (eventType) => {
                const type = String(eventType || '').toLowerCase();
                if (type.includes('short')) return 'Short Ship';
                if (type.includes('resolve')) return 'Shortage Resolved';
                if (type.includes('bulk')) return 'Successful Scan (Bulk Pick)';
                if (type.includes('scan')) return 'Successful Scan';
                return String(eventType || 'Pick Event');
            };

            const formatQty = (qty) => {
                const value = Number(qty || 0);
                if (value > 0) {
                    return `${value} ${value === 1 ? 'unit' : 'units'} verified`;
                }
                return '0 (Status Flag)';
            };

            const formatStatusDetails = (event) => {
                const type = String(event.event_type || '').toLowerCase();
                if (type.includes('short')) return 'Status: In Progress, Item Shorted.';
                if (type.includes('resolve')) return 'Status: In Progress, Item Not Shorted.';
                return 'Item processed successfully.';
            };

            const renderRows = (events) => {
                if (!tbody) {
                    return;
                }

                tbody.innerHTML = '';

                if (!events.length) {
                    tbody.innerHTML = '<tr><td colspan="7">No pick events found.</td></tr>';
                    return;
                }

                events.forEach((event) => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${escapeHtml(event.created_at)}</td>
                        <td>${escapeHtml(formatActivity(event.event_type))}</td>
                        <td>${escapeHtml(event.item_no)}</td>
                        <td>${escapeHtml(formatQty(event.qty_delta))}</td>
                        <td>${escapeHtml(event.handler)}</td>
                        <td>${escapeHtml(formatStatusDetails(event))}</td>
                    `;
                    tbody.appendChild(tr);
                });
            };

            const searchPickEvents = async (orderNumberOverride = '') => {
                const orderNumber = String(orderNumberOverride || modalInput?.value || homeInput?.value || '').trim();

                if (!orderNumber) {
                    openPickEventsModal('');
                    showError('Enter a sales order number.');
                    return;
                }

                if (homeInput) {
                    homeInput.value = orderNumber;
                }
                if (modalInput) {
                    modalInput.value = orderNumber;
                }

                openPickEventsModal(orderNumber);
                clearError();

                if (modalSearchBtn) {
                    modalSearchBtn.disabled = true;
                    modalSearchBtn.textContent = 'Searching...';
                }

                try {
                    const params = new URLSearchParams({
                        action: 'pick_events_search',
                        order_number: orderNumber,
                    });
                    const response = await fetch(`verify_pick.php?${params.toString()}`, {
                        headers: {
                            Accept: 'application/json',
                        },
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data?.ok) {
                        showError(data?.error || 'Unable to load pick events.');
                        return;
                    }

                    if (summaryBox) {
                        summaryBox.textContent = `Order Summary: ${data.summary?.order_number || orderNumber} | Events: ${(Array.isArray(data.events) ? data.events.length : 0)} | Status: ${data.summary?.status || 'N/A'} | Key User: ${data.summary?.key_user || 'N/A'}`;
                    }

                    renderRows(Array.isArray(data.events) ? data.events : []);

                    if (resultsBox) {
                        resultsBox.hidden = false;
                    }

                    toggleExports(Array.isArray(data.events) && data.events.length > 0);
                } catch (err) {
                    console.error('[pick-events] Search failed:', err);
                    showError('Search failed. Check the PHP error log.');
                } finally {
                    if (modalSearchBtn) {
                        modalSearchBtn.disabled = false;
                        modalSearchBtn.textContent = 'Search';
                    }
                }
            };

            window.pickEventsRunSearch = searchPickEvents;

            if (!window.__pickEventsUseDirectHandlers) {
                homeSearchBtn?.addEventListener('click', (event) => {
                    event.preventDefault();
                    searchPickEvents(homeInput?.value || '');
                });

                homeInput?.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        searchPickEvents(homeInput.value);
                    }
                });
            }

            toolbarOpenBtn?.addEventListener('click', () => {
                openPickEventsModal('');
            });

            if (!window.__pickEventsUseDirectHandlers) {
                modalSearchBtn?.addEventListener('click', () => {
                    searchPickEvents(modalInput?.value || '');
                });

                modalInput?.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        searchPickEvents(modalInput.value);
                    }
                });
            }

            closeBtn?.addEventListener('click', closePickEventsModal);

            modal.addEventListener('click', (event) => {
                const target = event.target;
                if (target instanceof HTMLElement && target.hasAttribute('data-pick-events-close')) {
                    closePickEventsModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !modal.hidden) {
                    closePickEventsModal();
                }
            });

            console.log('[pick-events] standalone modal script loaded');
        })();
    </script>
    <script>
        (function () {
            const ROOT_BASE = (
                typeof window.INVAPP_BASE === 'string' && window.INVAPP_BASE.trim() !== ''
                    ? window.INVAPP_BASE
                    : '/dev'
            ).replace(/\/+$/, '');
            const APP_BASE = ROOT_BASE.endsWith('/inventory') ? ROOT_BASE : `${ROOT_BASE}/inventory`;
            const buildVerifyActionUrl = (action, params = {}) => {
                const url = new URL(window.location.href);
                url.search = '';
                url.searchParams.set('action', action);
                Object.entries(params).forEach(([key, value]) => {
                    if (value !== undefined && value !== null && String(value) !== '') {
                        url.searchParams.set(key, String(value));
                    }
                });
                return url;
            };
            const logVerifyEvent = (payload = {}) => {
                if (!pickOrderId) {
                    return;
                }
                const body = JSON.stringify({
                    order_id: pickOrderId,
                    workflow_stage: resolveUiMode() === 'packer' ? 'pack' : 'pick',
                    ...payload,
                });
                const url = buildVerifyActionUrl('log_verify_event');
                try {
                    if (navigator.sendBeacon) {
                        const beacon = navigator.sendBeacon(url.toString(), new Blob([body], { type: 'application/json' }));
                        if (beacon) {
                            return;
                        }
                    }
                } catch (err) {
                    // Fallback to fetch below.
                }
                void fetch(url.toString(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body,
                    keepalive: true,
                }).catch(() => { });
            };
            const pickContent = document.getElementById('pickContent');
            const pickOrderId = Number(pickContent?.dataset.pickOrderId || 0);
            let currentOrderStatus = String(pickContent?.dataset.orderStatus || '').trim().toUpperCase();
            const salesOrderNumber = String(pickContent?.dataset.salesOrderNumber || '');
            const claimCurrentOrderBtn = document.getElementById('claimCurrentOrderBtn');
            const toggleOrderPriorityBtn = document.getElementById('toggleOrderPriorityBtn');
            const addOrderNoteBtn = document.getElementById('addOrderNoteBtn');
            const orderNotesList = document.getElementById('verifyOrderNotesList');
            const prioritySummary = document.getElementById('verifyPrioritySummary');
            const assignCurrentOrderBtn = document.getElementById('assignCurrentOrderBtn');
            const deletePickOrderBtn = document.getElementById('deletePickOrderBtn');
            const closeCurrentOrderBtn = document.getElementById('closeCurrentOrderBtn');
            const returnToPickBtn = document.getElementById('returnToPickBtn');
            const completeStageBtn = document.getElementById('completeStageBtn');
            const saveVerifiedListBtn = document.getElementById('saveVerifiedListBtn');
            const shortShipCountValue = document.getElementById('shortShipCountValue');
            const addPickLineBtn = document.getElementById('addPickLineBtn');
            const resetVerifiedBtn = document.getElementById('resetVerifiedBtn');
            const toggleDeleteModeBtn = document.getElementById('toggleDeleteModeBtn');
            const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
            const printBoxLabelBtn = document.getElementById('printBoxLabelBtn');
            const runImportBtn = document.getElementById('runImportBtn');
            const pickOverlay = document.getElementById('pickOverlay');
            const pickLoading = document.getElementById('pickLoading');
            const completedSearchInput = document.getElementById('completedSearchInput');
            const completedSearchBtn = document.getElementById('completedSearchBtn');
            const completedSearchResults = document.getElementById('completedSearchResults');
            const availableOrdersList = document.getElementById('availableOrdersList');
            const toggleAllOrdersBtn = document.getElementById('toggleAllOrdersBtn');

            const renderOrderNotes = (notes) => {
                if (!orderNotesList) return;
                const rows = Array.isArray(notes) ? notes : [];
                if (addOrderNoteBtn) addOrderNoteBtn.textContent = rows.length ? 'Edit Note' : 'Add Note';
                orderNotesList.innerHTML = rows.length ? rows.map((note) => {
                    const acknowledged = !!note.acknowledged_at;
                    const author = Number(note.created_by_user_id || 0) > 0 ? `User #${Number(note.created_by_user_id)}` : 'User';
                    return `<article class="verify-order-note${acknowledged ? ' is-acknowledged' : ''}">
                        <div class="verify-order-note__body">${escapeHtml(String(note.note || ''))}</div>
                        <div class="verify-order-note__meta">${escapeHtml(author)} · ${escapeHtml(String(note.created_at || ''))} · ${escapeHtml(String(note.workflow_stage || 'pick'))}</div>
                    </article>`;
                }).join('') : '<div class="muted">No notes yet.</div>';
            };

            const loadOrderNotes = async () => {
                if (!pickOrderId) return;
                const url = new URL(window.location.href);
                url.searchParams.set('action', 'get_order_notes');
                url.searchParams.set('order_id', String(pickOrderId));
                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data?.ok) renderOrderNotes(data.notes);
            };

            if (toggleOrderPriorityBtn) {
                toggleOrderPriorityBtn.addEventListener('click', async () => {
                    toggleOrderPriorityBtn.disabled = true;
                    try {
                        const nextValue = pickContent?.dataset.isPriority === '1' ? 0 : 1;
                        const url = new URL(window.location.href);
                        url.searchParams.set('action', 'set_order_priority');
                        const res = await fetch(url.toString(), {
                            method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({ order_id: pickOrderId, is_priority: nextValue }),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || !data?.ok) throw new Error(data?.error || 'Unable to update priority.');
                        pickContent.dataset.isPriority = String(data.is_priority || 0);
                        const active = Number(data.is_priority || 0) === 1;
                        toggleOrderPriorityBtn.textContent = active ? 'Remove Priority' : 'Mark Priority';
                        if (prioritySummary) { prioritySummary.hidden = !active; prioritySummary.classList.toggle('is-active', active); }
                        showToast(active ? 'Order marked priority.' : 'Priority removed.');
                    } catch (err) { alert(err?.message || 'Unable to update priority.'); }
                    finally { toggleOrderPriorityBtn.disabled = false; }
                });
            }
            if (addOrderNoteBtn) {
                addOrderNoteBtn.addEventListener('click', async () => {
                    const note = window.prompt('Note for packing:');
                    if (!note || !note.trim()) return;
                    addOrderNoteBtn.disabled = true;
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('action', 'add_order_note');
                        const res = await fetch(url.toString(), {
                            method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({ order_id: pickOrderId, note: note.trim(), workflow_stage: 'pick' }),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || !data?.ok) throw new Error(data?.error || 'Unable to save note.');
                        renderOrderNotes(data.notes);
                        showToast('Note added for packing.');
                    } catch (err) { alert(err?.message || 'Unable to save note.'); }
                    finally { addOrderNoteBtn.disabled = false; }
                });
            }
            loadOrderNotes().catch(() => { });

            const scanInput = document.getElementById('scanInput');
            const scanInputForm = document.getElementById('scanInputForm');
            const scanStatus = document.getElementById('scanStatus');
            const verifyModeBadge = document.getElementById('verifyModeBadge');
            const toggleVerifyModeBtn = document.getElementById('toggleVerifyModeBtn');
            const pickProgressValue = document.getElementById('pickProgressValue');
            const pickProgressLabel = document.getElementById('pickProgressLabel');
            const scanPanel = document.getElementById('verifyScanPanel');
            const scanCameraBtn = document.getElementById('scanCameraBtn');
            const scanPreview = document.getElementById('scanPreview');
            const scanPreviewVideo = document.getElementById('scanPreviewVideo');
            const scanCameraNote = document.getElementById('scanCameraNote');
            const stageStatusNote = document.getElementById('stageStatusNote');
            const scanResultPanel = document.getElementById('verifyScanResultPanel');
            const scanResultEmpty = document.getElementById('verifyScanResultEmpty');
            const scanResultMedia = document.getElementById('verifyScanResultMedia');
            const scanResultImage = document.getElementById('verifyScanResultImage');
            const scanResultImageFallback = document.getElementById('verifyScanResultImageFallback');
            const scanResultDetails = document.getElementById('verifyScanResultDetails');
            const scanResultTitle = scanResultPanel ? scanResultPanel.querySelector('.verify-scan-result-panel__title') : null;
            const scanResultSku = document.getElementById('verifyScanResultSku');
            const scanResultDesc = document.getElementById('verifyScanResultDesc');
            const scanResultStatus = document.getElementById('verifyScanResultStatus');
            const scanResultCode = document.getElementById('verifyScanResultCode');
            const scanResultOnHand = document.getElementById('verifyScanResultOnHand');
            const scanResultShelf = document.getElementById('verifyScanResultShelf');
            const scanResultLocations = document.getElementById('verifyScanResultLocations');
            const shipInfoInputRow = document.getElementById('shipInfoInputRow');
            const shipInfoInput = document.getElementById('shipInfoInput');
            const shipInfoPreview = document.getElementById('verifyShipInfoPreview');
            const shipInfoClearBtn = document.getElementById('shipInfoClearBtn');
            let scanStream = null;
            let scanDetector = null;
            let scanTimer = null;
            let scanActive = false;
            let scanDecodeBusy = false;
            let scanCooldownUntil = 0;
            let scanSupportedFormats = null;
            let scanMissCount = 0;
            let lastCameraCode = '';
            const scanFocusCanvas = document.createElement('canvas');
            const scanFocusContext = scanFocusCanvas.getContext('2d', { willReadFrequently: true });

            const skuModal = document.getElementById('verifySkuModal');
            const skuModalInput = document.getElementById('verifySkuInput');
            const skuModalQty = document.getElementById('verifySkuQty');
            const skuModalResults = document.getElementById('verifySkuResults');
            const skuModalClose = document.getElementById('verifySkuCloseBtn');
            const skuModalSearch = document.getElementById('verifySkuSearchBtn');
            let skuSearchTimer = null;

            const skuBarcodeModal = document.getElementById('verifySkuBarcodeModal');
            const skuBarcodeModalTitle = document.getElementById('verifySkuBarcodeTitle');
            const skuBarcodeModalSku = document.getElementById('verifySkuBarcodeSku');
            const skuBarcodeModalValue = document.getElementById('verifySkuBarcodeValue');
            const skuBarcodeModalTarget = document.getElementById('verifySkuBarcodeTarget');
            const skuBarcodeModalNote = document.getElementById('verifySkuBarcodeNote');
            const skuBarcodeModalClose = document.getElementById('verifySkuBarcodeCloseBtn');
            const skuBarcodeModalPrint = document.getElementById('verifySkuBarcodePrintBtn');
            const pickEventSearchInput = document.getElementById('pickEventSearch');
            const openModalBtn = document.getElementById('openModalBtn');
            const pickEventsOpenBtn = document.getElementById('pickEventsOpenBtn');
            const pickEventsModal = document.getElementById('pick-events-modal');
            const pickEventsBody = document.getElementById('pickEventsBody');
            const pickEventsCloseBtn = document.getElementById('pickEventsCloseBtn');
            const pickEventsOrderNumberInput = document.getElementById('pick-events-order-number');
            const pickEventsSearchBtn = document.getElementById('pick-events-search-btn');
            const pickEventsError = document.getElementById('pick-events-error');
            const pickEventsResults = document.getElementById('pick-events-results');
            const pickEventsSummary = document.getElementById('pick-events-summary');
            const pickEventsTbody = document.getElementById('pick-events-tbody');
            const pickEventsTable = document.getElementById('pick-events-table');
            const pickEventsExportCsvBtn = document.getElementById('pick-events-export-csv');
            const pickEventsExportExcelBtn = document.getElementById('pick-events-export-excel');
            const pickEventsExportPdfBtn = document.getElementById('pick-events-export-pdf');
            let pickEventsCurrentEvents = [];
            let pickEventsCurrentSummary = null;

            const pickEventsToggleExports = (show) => {
                [pickEventsExportCsvBtn, pickEventsExportExcelBtn, pickEventsExportPdfBtn].forEach((btn) => {
                    if (btn) {
                        btn.hidden = !show;
                    }
                });
            };

            const pickEventsClearError = () => {
                if (!pickEventsError) {
                    return;
                }
                pickEventsError.textContent = '';
                pickEventsError.hidden = true;
            };

            const pickEventsShowError = (message) => {
                if (!pickEventsError) {
                    return;
                }
                pickEventsError.textContent = String(message || 'Unable to load pick events.');
                pickEventsError.hidden = false;
                if (pickEventsResults) {
                    pickEventsResults.hidden = true;
                }
                pickEventsToggleExports(false);
            };

            const pickEventsOpenModal = (prefillOrderNumber = '') => {
                if (!pickEventsModal) {
                    return;
                }
                const nextOrderNumber = String(prefillOrderNumber || pickEventsOrderNumberInput?.value || pickEventSearchInput?.value || '').trim();
                if (pickEventsOrderNumberInput && nextOrderNumber) {
                    pickEventsOrderNumberInput.value = nextOrderNumber;
                }
                pickEventsModal.hidden = false;
                pickEventsModal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                pickEventsClearError();
                if (pickEventsResults) {
                    pickEventsResults.hidden = true;
                }
                if (pickEventsBody) {
                    pickEventsBody.dataset.state = 'idle';
                }
                pickEventsToggleExports(false);
                window.requestAnimationFrame(() => {
                    if (pickEventsOrderNumberInput) {
                        pickEventsOrderNumberInput.focus();
                        pickEventsOrderNumberInput.select();
                    }
                });
            };

            const pickEventsCloseModal = () => {
                if (!pickEventsModal) {
                    return;
                }
                pickEventsModal.hidden = true;
                pickEventsModal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            };

            const pickEventsEscapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            }[char]));

            const pickEventsFormatActivity = (eventType) => {
                const type = String(eventType || '').toLowerCase();
                if (type.includes('short')) {
                    return 'Short Ship';
                }
                if (type.includes('resolve')) {
                    return 'Shortage Resolved';
                }
                if (type.includes('bulk')) {
                    return 'Successful Scan (Bulk Pick)';
                }
                if (type.includes('scan')) {
                    return 'Successful Scan';
                }
                return String(eventType || 'Pick Event');
            };

            const pickEventsFormatQty = (qty) => {
                const value = Number(qty || 0);
                if (value > 0) {
                    return `${value} ${value === 1 ? 'unit' : 'units'} verified`;
                }
                return '0 (Status Flag)';
            };

            const pickEventsFormatStatusDetails = (event) => {
                const type = String(event.event_type || '').toLowerCase();
                if (type.includes('short')) {
                    return 'Status: In Progress, Item Shorted.';
                }
                if (type.includes('resolve')) {
                    return 'Status: In Progress, Item Not Shorted.';
                }
                return 'Item processed successfully.';
            };

            const pickEventsRenderRows = (events) => {
                if (!pickEventsTbody) {
                    return;
                }
                pickEventsTbody.innerHTML = '';
                if (!events.length) {
                    pickEventsTbody.innerHTML = '<tr><td colspan="7">No pick events found.</td></tr>';
                    return;
                }

                events.forEach((event) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${pickEventsEscapeHtml(event.created_at)}</td>
                        <td>${pickEventsEscapeHtml(pickEventsFormatActivity(event.event_type))}</td>
                        <td>${pickEventsEscapeHtml(event.item_no)}</td>
                        <td>${pickEventsEscapeHtml(pickEventsFormatQty(event.qty_delta))}</td>
                        <td>${pickEventsEscapeHtml(event.handler)}</td>
                        <td>${pickEventsEscapeHtml(String(event.workflow_stage || '').toLowerCase())}</td>
                        <td>${pickEventsEscapeHtml(pickEventsFormatStatusDetails(event))}</td>
                        <td>${pickEventsEscapeHtml(event.meta_json)}</td>
                    `;
                    pickEventsTbody.appendChild(row);
                });
            };

            const pickEventsDownloadBlob = (content, filename, mimeType) => {
                const blob = new Blob([content], { type: mimeType });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                link.click();
                URL.revokeObjectURL(url);
            };

            const pickEventsBuildExportRows = () => pickEventsCurrentEvents.map((event) => ({
                'Date & Time': event.created_at,
                'Activity Type': pickEventsFormatActivity(event.event_type),
                'Item Number': event.item_no,
                'Quantity Change': pickEventsFormatQty(event.qty_delta),
                'Handler': event.handler,
                'Workflow Stage': String(event.workflow_stage || '').toLowerCase(),
                'Status Details': pickEventsFormatStatusDetails(event),
                'Meta JSON': event.meta_json,
            }));

            const pickEventsExportCsv = () => {
                const rows = pickEventsBuildExportRows();
                if (!rows.length) {
                    return;
                }
                const headers = Object.keys(rows[0]);
                const csv = [
                    headers.join(','),
                    ...rows.map((row) => headers.map((header) => `"${String(row[header] ?? '').replace(/"/g, '""')}"`).join(',')),
            ].join('\r\n');
            const orderNumber = String(pickEventsCurrentSummary?.order_number || pickEventsOrderNumberInput?.value || 'report').trim() || 'report';
            pickEventsDownloadBlob(csv, `pick-events-${orderNumber}.csv`, 'text/csv;charset=utf-8;');
        };

        const pickEventsExportExcel = () => {
            const rows = pickEventsBuildExportRows();
            if (!rows.length) {
                return;
            }
            const headers = Object.keys(rows[0]);
            const html = `
                                                                                    <table>
                                                                                        <thead>
                                                                                            <tr>${headers.map((header) => `<th>${pickEventsEscapeHtml(header)}</th>`).join('')}</tr>
                                                                                        </thead>
                                                                                        <tbody>
                                                                                            ${rows.map((row) => `<tr>${headers.map((header) => `<td>${pickEventsEscapeHtml(row[header])}</td>`).join('')}</tr>`).join('')}
                                                                                        </tbody>
                                                                                    </table>
                                                                                `;
            const orderNumber = String(pickEventsCurrentSummary?.order_number || pickEventsOrderNumberInput?.value || 'report').trim() || 'report';
            pickEventsDownloadBlob(html, `pick-events-${orderNumber}.xls`, 'application/vnd.ms-excel');
        };

        const pickEventsExportPdf = () => {
            if (!pickEventsTable) {
                return;
            }

            const printWindow = window.open('', '_blank');
            if (!printWindow) {
                alert('Popup blocked. Allow popups to export PDF.');
                return;
            }

            printWindow.document.write(`
                                                                                    <!doctype html>
                                                                                    <html>
                                                                                    <head>
                                                                                        <title>Pick Events Report</title>
                                                                                        <style>
                                                                                            body {
                                                                                                font-family: Arial, sans-serif;
                                                                                                padding: 20px;
                                                                                            }
                                                                                            h1 {
                                                                                                font-size: 20px;
                                                                                            }
                                                                                            table {
                                                                                                width: 100%;
                                                                                                border-collapse: collapse;
                                                                                            }
                                                                                            th,
                                                                                            td {
                                                                                                border: 1px solid #ddd;
                                                                                                padding: 8px;
                                                                                                font-size: 12px;
                                                                                                text-align: left;
                                                                                                vertical-align: top;
                                                                                            }
                                                                                            th {
                                                                                                background: #f1f5f9;
                                                                                            }
                                                                                        </style>
                                                                                    </head>
                                                                                    <body>
                                                                                        <h1>Pick Events Report</h1>
                                                                                        <p>${pickEventsEscapeHtml(pickEventsSummary?.textContent || '')}</p>
                                                                                        ${pickEventsTable.outerHTML}
                                                                                    </body>
                                                                                    </html>
                                                                                `);
                printWindow.document.close();
                printWindow.focus();
                printWindow.print();
            };

            const pickEventsSearch = async (orderNumberOverride = '') => {
                const orderNumber = String(orderNumberOverride || pickEventsOrderNumberInput?.value || pickEventSearchInput?.value || '').trim();
                if (!orderNumber) {
                    pickEventsShowError('Enter a sales order number.');
                    return;
                }

                if (pickEventsOrderNumberInput) {
                    pickEventsOrderNumberInput.value = orderNumber;
                }
                if (pickEventSearchInput) {
                    pickEventSearchInput.value = orderNumber;
                }

                pickEventsClearError();
                if (pickEventsSearchBtn) {
                    pickEventsSearchBtn.disabled = true;
                    pickEventsSearchBtn.textContent = 'Searching...';
                }

                try {
                    const url = buildVerifyActionUrl('pick_events_search', { order_number: orderNumber });
                    const response = await fetch(url.toString(), {
                        headers: {
                            Accept: 'application/json',
                        },
                    });
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok || !data?.ok) {
                        pickEventsShowError(data?.error || 'Unable to load pick events.');
                        return;
                    }

                    pickEventsCurrentEvents = Array.isArray(data.events) ? data.events : [];
                    pickEventsCurrentSummary = data.summary || null;
                    if (pickEventsSummary) {
                        pickEventsSummary.textContent = `Order Summary: ${pickEventsCurrentSummary?.order_number || orderNumber} | Events: ${pickEventsCurrentEvents.length} | Status: ${pickEventsCurrentSummary?.status || 'N/A'} | Key User: ${pickEventsCurrentSummary?.key_user || 'N/A'}`;
                    }
                    pickEventsRenderRows(pickEventsCurrentEvents);
                    if (pickEventsResults) {
                        pickEventsResults.hidden = false;
                    }
                    pickEventsToggleExports(pickEventsCurrentEvents.length > 0);
                } catch (err) {
                    pickEventsShowError('Search failed. Check the PHP error log.');
                } finally {
                    if (pickEventsSearchBtn) {
                        pickEventsSearchBtn.disabled = false;
                        pickEventsSearchBtn.textContent = 'Search';
                    }
                }
            };

            if (!window.__pickEventsStandaloneScriptLoaded) {
                const runPickEventsSearchFromHome = async () => {
                    const orderNumber = String(pickEventSearchInput?.value || '').trim();
                    pickEventsOpenModal(orderNumber);
                    if (!orderNumber) {
                        return;
                    }
                    await pickEventsSearch(orderNumber);
                };

                if (pickEventsOpenBtn) {
                    pickEventsOpenBtn.addEventListener('click', () => pickEventsOpenModal());
                }
                if (openModalBtn) {
                    openModalBtn.addEventListener('click', (ev) => {
                        console.log('[verify_pick] open modal button clicked');
                        ev.preventDefault();
                        runPickEventsSearchFromHome().catch((err) => {
                            console.error('[verify_pick] pick events search failed:', err);
                        });
                    });
                }
                if (pickEventSearchInput) {
                    pickEventSearchInput.addEventListener('keydown', (ev) => {
                        if (ev.key === 'Enter') {
                            ev.preventDefault();
                            runPickEventsSearchFromHome().catch((err) => {
                                console.error('[verify_pick] pick events search failed:', err);
                            });
                        }
                    });
                }
                if (pickEventsSearchBtn) {
                    pickEventsSearchBtn.addEventListener('click', pickEventsSearch);
                }
                if (pickEventsOrderNumberInput) {
                    pickEventsOrderNumberInput.addEventListener('keydown', (ev) => {
                        if (ev.key === 'Enter') {
                            pickEventsSearch();
                        }
                    });
                }
                if (pickEventsExportCsvBtn) {
                    pickEventsExportCsvBtn.addEventListener('click', pickEventsExportCsv);
                }
                if (pickEventsExportExcelBtn) {
                    pickEventsExportExcelBtn.addEventListener('click', pickEventsExportExcel);
                }
                if (pickEventsExportPdfBtn) {
                    pickEventsExportPdfBtn.addEventListener('click', pickEventsExportPdf);
                }
                if (pickEventsModal) {
                    pickEventsModal.addEventListener('click', (ev) => {
                        const target = ev.target;
                        if (target instanceof HTMLElement && target.hasAttribute('data-pick-events-close')) {
                            pickEventsCloseModal();
                        }
                    });
                }
                document.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Escape' && pickEventsModal && !pickEventsModal.hidden) {
                        pickEventsCloseModal();
                    }
                });
                if (pickEventsCloseBtn) {
                    pickEventsCloseBtn.addEventListener('click', pickEventsCloseModal);
                }
            }

            const closeSkuBarcodeModal = () => {
                if (!skuBarcodeModal) return;
                skuBarcodeModal.classList.remove('is-open');
                skuBarcodeModal.setAttribute('aria-hidden', 'true');
                skuBarcodeModal.setAttribute('hidden', 'hidden');
                if (skuBarcodeModalTarget) {
                    skuBarcodeModalTarget.innerHTML = '';
                }
                if (skuBarcodeModalNote) {
                    skuBarcodeModalNote.textContent = '';
                }
            };

            const renderSkuBarcodePreview = (sku) => {
                if (!skuBarcodeModalTarget) return;
                skuBarcodeModalTarget.innerHTML = '';
                const cleanSku = String(sku || '').trim();
                if (!cleanSku) {
                    skuBarcodeModalTarget.innerHTML = '<div class="muted">No SKU found for this item.</div>';
                    if (skuBarcodeModalNote) {
                        skuBarcodeModalNote.textContent = 'Barcode preview is unavailable until a SKU is available.';
                    }
                    return;
                }
                const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.classList.add('vp-barcode-modal__svg');
                skuBarcodeModalTarget.appendChild(svg);
                try {
                    if (typeof JsBarcode !== 'function') {
                        throw new Error('JsBarcode is not loaded.');
                    }
                    JsBarcode(svg, cleanSku, {
                        format: 'CODE128',
                        width: 2,
                        height: 96,
                        displayValue: true,
                        margin: 6,
                        fontSize: 18,
                        textMargin: 8,
                    });
                    if (skuBarcodeModalPrint) {
                        skuBarcodeModalPrint.disabled = false;
                    }
                    if (skuBarcodeModalNote) {
                        skuBarcodeModalNote.textContent = 'Use Print Barcode to print this render.';
                    }
                } catch (err) {
                    skuBarcodeModalTarget.innerHTML = '<div class="muted">Unable to render barcode.</div>';
                    if (skuBarcodeModalPrint) {
                        skuBarcodeModalPrint.disabled = true;
                    }
                    if (skuBarcodeModalNote) {
                        skuBarcodeModalNote.textContent = String(err?.message || 'Unable to render barcode.');
                    }
                }
            };

            const openSkuBarcodeModal = async (sku) => {
                if (!skuBarcodeModal) return;
                const normalizedSku = String(sku || '').trim();
                if (!normalizedSku) {
                    return;
                }
                skuBarcodeModal.removeAttribute('hidden');
                skuBarcodeModal.setAttribute('aria-hidden', 'false');
                skuBarcodeModal.classList.add('is-open');
                if (skuBarcodeModalSku) {
                    skuBarcodeModalSku.textContent = normalizedSku;
                }
                if (skuBarcodeModalValue) {
                    skuBarcodeModalValue.textContent = normalizedSku;
                }
                if (skuBarcodeModalTitle) {
                    skuBarcodeModalTitle.textContent = `SKU Code 128 Barcode: ${normalizedSku}`;
                }
                if (skuBarcodeModalTarget) {
                    skuBarcodeModalTarget.innerHTML = '<div class="muted">Rendering barcode...</div>';
                }
                if (skuBarcodeModalNote) {
                    skuBarcodeModalNote.textContent = 'Rendering the SKU as a Code 128 barcode.';
                }
                if (skuBarcodeModalPrint) {
                    skuBarcodeModalPrint.disabled = true;
                }
                renderSkuBarcodePreview(normalizedSku);
            };

            const printSkuBarcode = () => {
                const barcodeSvg = skuBarcodeModalTarget?.querySelector('svg');
                const sku = String(skuBarcodeModalValue?.textContent || '').trim();
                if (!(barcodeSvg instanceof SVGElement) || !sku) {
                    if (skuBarcodeModalNote) {
                        skuBarcodeModalNote.textContent = 'The barcode must finish rendering before it can be printed.';
                    }
                    return;
                }

                const printFrame = document.createElement('iframe');
                printFrame.className = 'vp-barcode-print-frame';
                printFrame.setAttribute('title', 'SKU barcode print');
                document.body.appendChild(printFrame);

                const printDocument = printFrame.contentDocument;
                const printWindow = printFrame.contentWindow;
                if (!printDocument || !printWindow) {
                    printFrame.remove();
                    return;
                }

                printDocument.open();
                printDocument.write(`<!doctype html>
                                                                                    <html lang="en">
                                                                                    <head>
                                                                                        <meta charset="utf-8">
                                                                                        <title>SKU Code 128 Barcode</title>
                                                                                        <style>
                                                                                            @page { margin: 0.35in; }
                                                                                            * { box-sizing: border-box; }
                                                                                            body { margin: 0; color: #000; font-family: Arial, sans-serif; }
                                                                                            main { width: 3.25in; margin: 0 auto; text-align: center; }
                                                                                            svg { display: block; width: 100%; height: auto; margin: 0 auto; }
                                                                                            .type { margin-top: 0.04in; font-size: 8pt; letter-spacing: 0.08em; text-transform: uppercase; }
                                                                                        </style>
                                                                                    </head>
                                                                                    <body><main id="barcodePrintContent"></main></body>
                                                                                    </html>`);
                printDocument.close();

                const printContent = printDocument.getElementById('barcodePrintContent');
                if (!printContent) {
                    printFrame.remove();
                    return;
                }

                const printedBarcode = printDocument.importNode(barcodeSvg, true);
                printedBarcode.removeAttribute('class');
                const typeLabel = printDocument.createElement('div');
                typeLabel.className = 'type';
                typeLabel.textContent = 'Code 128';
                printContent.append(printedBarcode, typeLabel);

                const removePrintFrame = () => window.setTimeout(() => printFrame.remove(), 250);
                printWindow.addEventListener('afterprint', removePrintFrame, { once: true });
                window.setTimeout(() => {
                    printWindow.focus();
                    printWindow.print();
                    removePrintFrame();
                }, 100);
            };

            let rows = Array.from(document.querySelectorAll('tbody tr'));
            const rowBySku = new Map();
            const tableWrap = document.querySelector('.verify-results-wrap');
            const table = document.querySelector('.verify-results-wrap table');
            const tableBody = table ? table.querySelector('tbody') : null;
            const tableHeaders = table ? Array.from(table.querySelectorAll('thead th')) : [];
            let sortState = { index: -1, dir: 'asc', type: 'text' };
            let deleteMode = false;
            let lastScannedSku = '';
            let consecutiveScanCount = 0;
            const hasPackerVerify = pickContent?.dataset.hasPackerVerify === '1';
            const packCompletionEnabled = <?= $packCompletionEnabled ? 'true' : 'false' ?>;
            const packCompletionOpensMenu = <?= $packCompletionOpensMenu ? 'true' : 'false' ?>;
            const requestedWorkflow = String(pickContent?.dataset.entryWorkflow || '').trim();
            const renderedWorkflow = String(pickContent?.dataset.workflowRender || '').trim();
            const isWorkflowLocked = requestedWorkflow === 'pick' || requestedWorkflow === 'pack';
            let activeMode = 'picker';
            let keyboardScanBuffer = '';
            let keyboardScanTimer = null;

            const normalizeSkuValue = (value) => String(value || '').trim().toUpperCase();
            const isTerminalOrderStatus = (status) => ['COMPLETED', 'COMPLETED_SHORT_SHIP'].includes(String(status || '').trim().toUpperCase());
            let isCurrentOrderCompleted = isTerminalOrderStatus(currentOrderStatus);
            const getLockedWorkflowMode = () => requestedWorkflow === 'pack' ? 'packer' : 'picker';
            const getRenderedWorkflowMode = () => renderedWorkflow === 'pack'
                ? 'packer'
                : (renderedWorkflow === 'pick' ? 'picker' : '');
            let lastCloseButtonDebug = '';
            const logCloseButtonState = (source = 'sync') => {
                const payload = {
                    source,
                    order_id: pickOrderId,
                    order_number: salesOrderNumber,
                    current_order_status: currentOrderStatus,
                    is_current_order_completed: isCurrentOrderCompleted,
                    requested_workflow: requestedWorkflow || 'none',
                    rendered_workflow: renderedWorkflow || 'none',
                    active_mode: activeMode,
                };
                const signature = JSON.stringify(payload);
                if (signature === lastCloseButtonDebug) {
                    return;
                }
                lastCloseButtonDebug = signature;
                console.log('[verify_pick:close_button]', payload);
            };
            logCloseButtonState('init');
            const canCloseWithShortages = (summary) => {
                return !!summary?.isComplete && Number(summary?.shortShipCount || 0) > 0;
            };
            const canCompleteStage = (summary) => {
                return !!summary?.isComplete || canCloseWithShortages(summary);
            };

            const isEditableElement = (element) => {
                if (!(element instanceof HTMLElement)) {
                    return false;
                }
                if (element === scanInput) {
                    return true;
                }
                return !!element.closest('input, textarea, select');
            };

            const isGlobalScanBlocked = () => {
                if (isCurrentOrderCompleted) {
                    return true;
                }
                if (!scanInput) {
                    return true;
                }
                if (scanResultLock) {
                    return true;
                }
                if (scanErrorLatchActive) {
                    return true;
                }
                if (skuModal && skuModal.classList.contains('is-open')) {
                    return true;
                }
                if (boxLabelModal && boxLabelModal.classList.contains('is-open')) {
                    return true;
                }
                return false;
            };

            const clearKeyboardScanBuffer = () => {
                keyboardScanBuffer = '';
                if (keyboardScanTimer) {
                    clearTimeout(keyboardScanTimer);
                    keyboardScanTimer = null;
                }
            };

            const syncScanInputValue = (value) => {
                if (!scanInput) {
                    return;
                }
                scanInput.value = value;
            };

            const queueKeyboardScanReset = () => {
                if (keyboardScanTimer) {
                    clearTimeout(keyboardScanTimer);
                }
                keyboardScanTimer = setTimeout(() => {
                    clearKeyboardScanBuffer();
                    syncScanInputValue('');
                }, 250);
            };

            const submitKeyboardScanBuffer = () => {
                const code = keyboardScanBuffer.trim();
                clearKeyboardScanBuffer();
                syncScanInputValue('');
                if (!code) {
                    return;
                }
                if (isGlobalScanBlocked()) {
                    setScanErrorStatus('Close the scan result to continue scanning.');
                    return;
                }
                void handleScan(code);
            };

            const scanConstraintProfiles = [
                {
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 640 },
                        height: { ideal: 480 },
                    },
                    audio: false,
                },
                {
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 960 },
                        height: { ideal: 540 },
                        aspectRatio: { ideal: 1.7777777778 },
                    },
                    audio: false,
                },
                {
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                    },
                    audio: false,
                },
                {
                    video: {
                        facingMode: 'environment',
                    },
                    audio: false,
                },
            ];

            const describeError = (err) => {
                if (!err) {
                    return 'Unknown error.';
                }
                if (typeof err === 'string') {
                    return err;
                }
                const name = err.name ? `${err.name}: ` : '';
                const message = err.message || String(err);
                return `${name}${message}`;
            };

            const setScanButtonState = (isActive) => {
                if (!scanCameraBtn) {
                    return;
                }
                scanCameraBtn.setAttribute('aria-label', isActive ? 'Stop scan' : 'Start scan');
                const label = scanCameraBtn.querySelector('span');
                if (label) {
                    label.textContent = isActive ? 'Stop' : 'Scan';
                }
            };

            const waitFor = (ms) => new Promise((resolve) => {
                window.setTimeout(resolve, ms);
            });

            const resetScanResultOverscanState = () => {
                scanResultLock = false;
                scanResultOverScanState = false;
                scanResultOverScanMessage = '';
                if (scanResultPanel) {
                    scanResultPanel.classList.remove('is-over-scanned');
                }
            };

            const hideScanResultCard = () => {
                if (!scanResultPanel) {
                    return;
                }
                if (scanResultAutoHideTimer) {
                    window.clearTimeout(scanResultAutoHideTimer);
                    scanResultAutoHideTimer = null;
                }
                if (scanResultFadeTimer) {
                    window.clearTimeout(scanResultFadeTimer);
                    scanResultFadeTimer = null;
                }
                scanResultPanel.classList.remove('is-fading');
                scanResultPanel.hidden = true;
                scanResultPanel.setAttribute('aria-hidden', 'true');
                if (scanResultMedia) {
                    scanResultMedia.classList.remove('is-visible');
                }
                resetScanResultOverscanState();
                syncScanInteractionLock();
            };

            const syncScanInteractionLock = () => {
                const locked = scanResultLock && !isCurrentOrderCompleted;
                const errorLatched = scanErrorLatchActive && !isCurrentOrderCompleted;
                const isInteractionLocked = locked || errorLatched;
                if (scanPanel) {
                    scanPanel.classList.toggle('is-scan-locked', locked);
                    scanPanel.classList.toggle('is-scan-error-latched', errorLatched);
                }
                if (scanInput) {
                    scanInput.disabled = isCurrentOrderCompleted || isInteractionLocked;
                    scanInput.readOnly = isCurrentOrderCompleted || isInteractionLocked;
                    scanInput.toggleAttribute('disabled', isCurrentOrderCompleted || isInteractionLocked);
                    scanInput.toggleAttribute('readonly', isCurrentOrderCompleted || isInteractionLocked);
                    scanInput.setAttribute('aria-disabled', (isCurrentOrderCompleted || isInteractionLocked) ? 'true' : 'false');
                    scanInput.title = errorLatched
                        ? (scanErrorLatchMessage || 'Wrong item scanned. Tap to continue.')
                        : (locked
                            ? 'Close the scan result to continue scanning.'
                            : (isCurrentOrderCompleted ? 'This pick is completed and read-only.' : ''));
                    if (isInteractionLocked) {
                        scanInput.blur();
                    }
                }
                if (scanCameraBtn) {
                    scanCameraBtn.disabled = isCurrentOrderCompleted || isInteractionLocked;
                    scanCameraBtn.toggleAttribute('disabled', isCurrentOrderCompleted || isInteractionLocked);
                    scanCameraBtn.setAttribute('aria-disabled', (isCurrentOrderCompleted || isInteractionLocked) ? 'true' : 'false');
                    scanCameraBtn.title = errorLatched
                        ? (scanErrorLatchMessage || 'Wrong item scanned. Tap to continue.')
                        : (locked
                            ? 'Close the scan result to continue scanning.'
                            : (isCurrentOrderCompleted ? 'This pick is completed and read-only.' : ''));
                }
            };

            if (scanResultImage) {
                scanResultImage.addEventListener('load', () => {
                    scanResultImage.hidden = false;
                    if (scanResultImageFallback) {
                        scanResultImageFallback.hidden = true;
                    }
                });
                scanResultImage.addEventListener('error', () => {
                    scanResultImage.hidden = true;
                    scanResultImage.removeAttribute('src');
                    if (scanResultImageFallback) {
                        scanResultImageFallback.hidden = false;
                    }
                });
            }
            if (scanResultPanel) {
                scanResultPanel.addEventListener('pointerdown', (ev) => {
                    ev.preventDefault();
                    ev.stopPropagation();
                    hideScanResultCard();
                });
                scanResultPanel.addEventListener('click', (ev) => {
                    ev.preventDefault();
                    ev.stopPropagation();
                    hideScanResultCard();
                });
            }
            if (scanPanel) {
                scanPanel.addEventListener('pointerdown', (ev) => {
                    if (scanErrorLatchActive && !isCurrentOrderCompleted) {
                        ev.preventDefault();
                        clearScanErrorLatch();
                    }
                });
            }
            document.addEventListener('pointerdown', (ev) => {
                if (scanErrorLatchActive && !isCurrentOrderCompleted) {
                    clearScanErrorLatch();
                }
            }, true);

            const syncCloseOrderButton = () => {
                if (!closeCurrentOrderBtn) {
                    return;
                }
                logCloseButtonState('sync');
                const pickerSummary = getVerificationSummary('picker');
                const packerSummary = hasPackerVerify ? getVerificationSummary('packer') : pickerSummary;
                const effectiveMode = isWorkflowLocked ? getLockedWorkflowMode() : activeMode;
                const currentStageLabel = effectiveMode === 'packer' ? 'Pack' : 'Pick';
                const currentStageSummary = effectiveMode === 'packer' ? packerSummary : pickerSummary;
                if (!pickOrderId) {
                    closeCurrentOrderBtn.disabled = true;
                    closeCurrentOrderBtn.classList.remove('is-reopen');
                    closeCurrentOrderBtn.textContent = isWorkflowLocked
                        ? `Complete ${currentStageLabel}`
                        : (hasPackerVerify ? 'Complete Pick' : 'Close Pick');
                    closeCurrentOrderBtn.title = 'No order loaded.';
                    return;
                }
                if (isCurrentOrderCompleted) {
                    closeCurrentOrderBtn.disabled = false;
                    closeCurrentOrderBtn.classList.add('is-reopen');
                    closeCurrentOrderBtn.textContent = effectiveMode === 'packer'
                        ? 'ReOpen Pack'
                        : `ReOpen ${currentStageLabel}`;
                    closeCurrentOrderBtn.title = effectiveMode === 'packer'
                        ? 'ReOpen this pack verification.'
                        : `ReOpen this ${currentStageLabel.toLowerCase()}.`;
                    console.log('[verify_pick:close_button_label]', {
                        source: 'completed',
                        effective_mode: effectiveMode,
                        button_text: closeCurrentOrderBtn.textContent,
                        button_title: closeCurrentOrderBtn.title,
                        order_status: currentOrderStatus,
                    });
                    return;
                }
                closeCurrentOrderBtn.classList.remove('is-reopen');
                if (isWorkflowLocked) {
                    closeCurrentOrderBtn.textContent = effectiveMode === 'packer' ? 'Complete Pack' : 'Complete Pick';
                    if (effectiveMode === 'packer' && !packCompletionEnabled) {
                        closeCurrentOrderBtn.disabled = true;
                        closeCurrentOrderBtn.title = 'Complete Pack is temporarily disabled.';
                        return;
                    }
                    if (effectiveMode === 'packer' && !pickerSummary.isComplete) {
                        closeCurrentOrderBtn.disabled = true;
                        closeCurrentOrderBtn.title = 'Picker verification must be completed before packing can begin.';
                        return;
                    }
                    closeCurrentOrderBtn.disabled = !canCompleteStage(currentStageSummary);
                    if (currentStageSummary.isComplete) {
                        closeCurrentOrderBtn.title = effectiveMode === 'packer'
                            ? 'Confirm packer verification and complete this order.'
                            : 'Confirm picker verification and move this order to the pack queue.';
                    } else if (canCloseWithShortages(currentStageSummary)) {
                        closeCurrentOrderBtn.title = effectiveMode === 'packer'
                            ? 'Confirm pack completion with accepted shortages.'
                            : 'Confirm picker completion with accepted shortages and move this order to the pack queue.';
                    } else {
                        closeCurrentOrderBtn.title = `${currentStageLabel} still has ${currentStageSummary.remaining} item(s) left to scan.`;
                    }
                    return;
                }
                closeCurrentOrderBtn.textContent = hasPackerVerify ? 'Complete Pick' : 'Close Pick';
                if (hasPackerVerify) {
                    closeCurrentOrderBtn.disabled = !canCompleteStage(pickerSummary);
                    if (pickerSummary.isComplete) {
                        closeCurrentOrderBtn.title = 'Confirm picker verification and move this order to the pack queue.';
                    } else if (canCloseWithShortages(pickerSummary)) {
                        closeCurrentOrderBtn.title = 'Confirm picker completion with accepted shortages and move this order to the pack queue.';
                    } else {
                        closeCurrentOrderBtn.title = `Picker still has ${pickerSummary.remaining} item(s) left to scan.`;
                    }
                } else {
                    closeCurrentOrderBtn.disabled = !canCompleteStage(pickerSummary);
                    if (pickerSummary.isComplete) {
                        closeCurrentOrderBtn.title = 'Close this sales order.';
                    } else if (canCloseWithShortages(pickerSummary)) {
                        closeCurrentOrderBtn.title = 'Close this sales order with accepted shortages.';
                    } else {
                        closeCurrentOrderBtn.title = `This sales order still has ${pickerSummary.remaining} item(s) left to scan.`;
                    }
                }
            };

            const syncReturnToPickButton = () => {
                if (!returnToPickBtn) {
                    return;
                }
                const effectiveMode = isWorkflowLocked ? getLockedWorkflowMode() : activeMode;
                const shouldShow = !!pickOrderId && !isCurrentOrderCompleted && effectiveMode === 'packer';
                returnToPickBtn.hidden = !shouldShow;
                returnToPickBtn.disabled = !shouldShow;
                returnToPickBtn.title = shouldShow ? 'Reset this order and move it back to the pick queue.' : '';
            };

            const syncWorkflowActionVisibility = () => {
                const isPacker = resolveUiMode() === 'packer';
                const hidePickerTools = isCurrentOrderCompleted;

                if (saveVerifiedListBtn) {
                    saveVerifiedListBtn.hidden = isPacker || isCurrentOrderCompleted;
                }
                if (addPickLineBtn) {
                    addPickLineBtn.hidden = isPacker || isCurrentOrderCompleted;
                }
                if (resetVerifiedBtn) {
                    resetVerifiedBtn.hidden = hidePickerTools;
                    resetVerifiedBtn.style.display = hidePickerTools ? 'none' : 'inline-flex';
                }
                if (toggleDeleteModeBtn) {
                    toggleDeleteModeBtn.hidden = hidePickerTools;
                    toggleDeleteModeBtn.style.display = hidePickerTools ? 'none' : 'inline-flex';
                }
                if (deleteSelectedBtn) {
                    deleteSelectedBtn.hidden = isPacker || isCurrentOrderCompleted;
                    deleteSelectedBtn.style.display = (isPacker || isCurrentOrderCompleted) ? 'none' : 'inline-flex';
                }
                if (printBoxLabelBtn) {
                    printBoxLabelBtn.hidden = !isPacker;
                }
            };

            const isShortShipRow = (row) => !!row?.querySelector('.short-ship-check')?.checked;

            const getDistinctOrderRows = () => {
                const sourceRows = tableBody ? Array.from(tableBody.querySelectorAll('tr')) : rows;
                const seenPickItemIds = new Set();
                return sourceRows.filter((row) => {
                    const pickItemId = String(row?.dataset.pickItemId || '').trim();
                    if (!pickItemId) {
                        return true;
                    }
                    if (seenPickItemIds.has(pickItemId)) {
                        return false;
                    }
                    seenPickItemIds.add(pickItemId);
                    return true;
                });
            };

            const getVerificationSummary = (mode = activeMode) => {
                rows = getDistinctOrderRows();
                let total = 0;
                let verified = 0;
                let lineCount = 0;
                let activeLineCount = 0;
                let completedLineCount = 0;
                let shortShipCount = 0;
                let orderedTotal = 0;
                let handledTotal = 0;
                let shortShipQty = 0;
                rows.forEach((row) => {
                    const qtyCell = row.querySelector('.qty-cell');
                    const qty = parseInt(qtyCell?.dataset.qty || '0', 10) || 0;
                    const verifiedValue = Math.max(0, getVerifiedValueForMode(row, mode));
                    const shortShip = isShortShipRow(row);
                    const storedShortQty = parseInt(row?.dataset.shortShipQty || '0', 10) || 0;
                    const resolvedShortQty = shortShip
                        ? (storedShortQty > 0 ? storedShortQty : Math.max(0, qty - Math.min(verifiedValue, qty)))
                        : 0;
                    const expectedQty = mode === 'packer'
                        ? Math.max(0, qty - resolvedShortQty)
                        : qty;
                    const handledQty = mode === 'packer'
                        ? Math.min(verifiedValue, expectedQty)
                        : Math.min(verifiedValue + resolvedShortQty, expectedQty);
                    const lineComplete = expectedQty === 0 || handledQty >= expectedQty;
                    total += expectedQty;
                    orderedTotal += Math.max(0, qty);
                    lineCount += 1;
                    verified += handledQty;
                    if (shortShip) {
                        shortShipCount += 1;
                        shortShipQty += resolvedShortQty;
                    }
                    if (lineComplete) {
                        completedLineCount += 1;
                    } else {
                        activeLineCount += 1;
                    }
                    handledTotal += handledQty;
                });
                return {
                    total,
                    verified,
                    lineCount,
                    activeLineCount,
                    completedLineCount,
                    shortShipCount,
                    shortShipQty,
                    orderedTotal,
                    handledTotal,
                    remaining: Math.max(0, total - verified),
                    isComplete: lineCount > 0 && completedLineCount === lineCount,
                };
            };

            const syncStageStatus = () => {
                const pickerSummary = getVerificationSummary('picker');
                const packerSummary = hasPackerVerify ? getVerificationSummary('packer') : pickerSummary;
                const pickerDone = pickerSummary.isComplete;
                const packerDone = hasPackerVerify ? packerSummary.isComplete : pickerDone;
                const pickerProgressValue = pickerDone ? pickerSummary.total : pickerSummary.verified;
                const packerProgressValue = packerDone ? packerSummary.total : packerSummary.verified;
                const pickerText = `Picker ${pickerProgressValue}/${pickerSummary.total}${pickerDone ? ' complete' : `, ${pickerSummary.remaining} left`}`;
                const packerText = hasPackerVerify
                    ? `Packer ${packerProgressValue}/${packerSummary.total}${packerDone ? ' complete' : `, ${packerSummary.remaining} left`}`
                    : 'No separate packer stage configured.';
                if (shortShipCountValue) {
                    shortShipCountValue.textContent = String(pickerSummary.shortShipCount);
                }
                if (stageStatusNote) {
                    const shortShipText = pickerSummary.shortShipCount > 0
                        ? ` | Short shipped ${pickerSummary.shortShipCount} line(s), ${pickerSummary.shortShipQty} unit(s)`
                        : '';
                    if (isWorkflowLocked) {
                        if (activeMode === 'packer' && !pickerSummary.isComplete) {
                            stageStatusNote.textContent = `Picker ${pickerSummary.verified}/${pickerSummary.total}, ${pickerSummary.remaining} left | Packing is unavailable until picker verification is complete.${shortShipText}`;
                        } else {
                            const currentText = activeMode === 'packer' ? packerText : pickerText;
                            stageStatusNote.textContent = `${currentText}${shortShipText}`;
                        }
                    } else {
                        stageStatusNote.textContent = `${pickerText} ${hasPackerVerify ? '| ' + packerText : '| Ready to finish once picking is complete.'}${shortShipText}`;
                    }
                }
                if (saveVerifiedListBtn) {
                    if (isWorkflowLocked && activeMode === 'packer') {
                        saveVerifiedListBtn.hidden = true;
                        saveVerifiedListBtn.disabled = true;
                        saveVerifiedListBtn.title = '';
                    } else {
                        saveVerifiedListBtn.hidden = false;
                    }
                    if (!pickOrderId) {
                        saveVerifiedListBtn.disabled = true;
                        saveVerifiedListBtn.title = 'No order loaded.';
                    } else if (!saveVerifiedListBtn.hidden && pickerSummary.isComplete) {
                        saveVerifiedListBtn.disabled = false;
                        saveVerifiedListBtn.title = 'Download the picker verified list as a CSV file.';
                    } else if (!saveVerifiedListBtn.hidden) {
                        saveVerifiedListBtn.disabled = true;
                        saveVerifiedListBtn.title = `Picker still has ${pickerSummary.remaining} item(s) left to scan.`;
                    }
                }
                syncReturnToPickButton();
                if (!completeStageBtn) {
                    return;
                }
                if (!pickOrderId) {
                    completeStageBtn.disabled = true;
                    completeStageBtn.textContent = hasPackerVerify ? 'Picker Done' : 'Finish Pick';
                    completeStageBtn.title = 'No order loaded.';
                    return;
                }
                if (isCurrentOrderCompleted) {
                    completeStageBtn.disabled = true;
                    completeStageBtn.textContent = 'Pick Completed';
                    completeStageBtn.title = 'Completed picks cannot advance further.';
                    return;
                }

                const isPacker = activeMode === 'packer' && hasPackerVerify;
                if (!isPacker) {
                    completeStageBtn.textContent = hasPackerVerify ? 'Picker Done' : 'Finish Pick';
                    if (pickerSummary.isComplete) {
                        completeStageBtn.disabled = false;
                        completeStageBtn.title = hasPackerVerify
                            ? 'Confirm picker scans are complete and move to packing.'
                            : 'Confirm picking is complete and close this pick.';
                    } else if (canCloseWithShortages(pickerSummary)) {
                        completeStageBtn.disabled = false;
                        completeStageBtn.title = hasPackerVerify
                            ? 'Confirm picker completion with accepted shortages and move to packing.'
                            : 'Confirm picking completion with accepted shortages and close this pick.';
                    } else {
                        completeStageBtn.disabled = true;
                        completeStageBtn.title = `Picker still has ${pickerSummary.remaining} item(s) left to scan.`;
                    }
                    return;
                }

                completeStageBtn.textContent = 'Packer Done';
                if (packerSummary.isComplete) {
                    completeStageBtn.disabled = false;
                    completeStageBtn.title = 'Confirm packer scans are complete and close this pick.';
                } else if (canCloseWithShortages(packerSummary)) {
                    completeStageBtn.disabled = false;
                    completeStageBtn.title = 'Confirm packer completion with accepted shortages and close this pick.';
                } else {
                    completeStageBtn.disabled = true;
                    completeStageBtn.title = `Packer still has ${packerSummary.remaining} item(s) left to scan.`;
                }
            };

            const syncCompletedRowState = (row) => {
                if (!row) {
                    return;
                }
                const shortShipEditable = !isCurrentOrderCompleted
                    && currentOrderStatus !== 'READY_TO_PACK'
                    && resolveUiMode() !== 'packer';
                row.querySelectorAll('.short-ship-check, .delete-check').forEach((input) => {
                    input.disabled = input.classList.contains('short-ship-check')
                        ? !shortShipEditable
                        : isCurrentOrderCompleted;
                    if (isCurrentOrderCompleted && input.classList.contains('delete-check')) {
                        input.checked = false;
                    }
                });
            };

            const syncCompletedPickState = () => {
                if (pickContent) {
                    pickContent.classList.toggle('pick-completed', isCurrentOrderCompleted);
                }
                if (isCurrentOrderCompleted) {
                    setDeleteMode(false);
                    stopVerifyCameraScan();
                }
                if (scanInput) {
                    scanInput.disabled = isCurrentOrderCompleted;
                    scanInput.readOnly = isCurrentOrderCompleted;
                    scanInput.title = isCurrentOrderCompleted ? 'This pick is completed and read-only.' : '';
                }
                if (scanCameraBtn) {
                    scanCameraBtn.disabled = isCurrentOrderCompleted;
                    scanCameraBtn.title = isCurrentOrderCompleted ? 'This pick is completed and read-only.' : '';
                }
                syncScanInteractionLock();
                if (addPickLineBtn) {
                    addPickLineBtn.disabled = isCurrentOrderCompleted;
                    addPickLineBtn.title = isCurrentOrderCompleted ? 'Completed picks cannot be edited.' : '';
                }
                if (resetVerifiedBtn) {
                    resetVerifiedBtn.disabled = isCurrentOrderCompleted;
                    resetVerifiedBtn.title = isCurrentOrderCompleted ? 'Completed picks cannot be edited.' : '';
                }
                if (toggleDeleteModeBtn) {
                    toggleDeleteModeBtn.disabled = isCurrentOrderCompleted;
                    toggleDeleteModeBtn.title = isCurrentOrderCompleted ? 'Completed picks cannot be edited.' : '';
                }
                if (deleteSelectedBtn) {
                    deleteSelectedBtn.disabled = isCurrentOrderCompleted || !deleteMode;
                    deleteSelectedBtn.title = isCurrentOrderCompleted ? 'Completed picks cannot be edited.' : '';
                }
                if (tableWrap) {
                    tableWrap.style.opacity = isCurrentOrderCompleted ? '0.72' : '';
                }
                rows.forEach(syncCompletedRowState);
                if (scanStatus && isCurrentOrderCompleted) {
                    scanStatus.textContent = 'This pick is completed and read-only.';
                }
                syncWorkflowActionVisibility();
                syncStageStatus();
            };

            const scheduleNextScanPass = (delay = 260) => {
                if (!scanActive) {
                    return;
                }
                if (scanTimer) {
                    clearTimeout(scanTimer);
                }
                scanTimer = window.setTimeout(runVerifyBarcodeDetectorLoop, delay);
            };

            const applyCameraTuning = async (track) => {
                if (!track || typeof track.applyConstraints !== 'function') {
                    return;
                }

                if (typeof track.getCapabilities !== 'function') {
                    return;
                }

                const capabilities = track.getCapabilities() || {};
                const advanced = [];

                if (Array.isArray(capabilities.focusMode) && capabilities.focusMode.includes('continuous')) {
                    advanced.push({ focusMode: 'continuous' });
                } else if (Array.isArray(capabilities.focusMode) && capabilities.focusMode.includes('single-shot')) {
                    advanced.push({ focusMode: 'single-shot' });
                }

                if (capabilities.zoom && typeof capabilities.zoom.max === 'number' && capabilities.zoom.max > 1) {
                    const preferredZoom = Math.min(2, capabilities.zoom.max);
                    advanced.push({ zoom: preferredZoom });
                }

                if (advanced.length === 0) {
                    return;
                }

                try {
                    await track.applyConstraints({ advanced });
                } catch (err) {
                    console.warn('[verify_pick] camera tuning failed:', err);
                }
            };

            const getSupportedBarcodeFormats = async () => {
                if (scanSupportedFormats !== null) {
                    return scanSupportedFormats;
                }

                const preferredFormats = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code', 'itf'];
                if (typeof window.BarcodeDetector?.getSupportedFormats !== 'function') {
                    scanSupportedFormats = preferredFormats;
                    return scanSupportedFormats;
                }

                try {
                    const available = await window.BarcodeDetector.getSupportedFormats();
                    const availableSet = new Set((available || []).map((format) => String(format || '').toLowerCase()));
                    const filtered = preferredFormats.filter((format) => availableSet.has(format));
                    scanSupportedFormats = filtered.length ? filtered : preferredFormats;
                } catch (_) {
                    scanSupportedFormats = preferredFormats;
                }

                return scanSupportedFormats;
            };

            const stopVerifyCameraScan = () => {
                scanActive = false;
                scanDetector = null;
                if (scanTimer) {
                    clearTimeout(scanTimer);
                    scanTimer = null;
                }
                scanDecodeBusy = false;
                scanCooldownUntil = 0;
                scanMissCount = 0;
                lastCameraCode = '';
                if (scanStream) {
                    scanStream.getTracks().forEach((t) => t.stop());
                    scanStream = null;
                }
                if (scanPreviewVideo) {
                    scanPreviewVideo.pause();
                    scanPreviewVideo.srcObject = null;
                }
                if (scanPreview) {
                    scanPreview.setAttribute('aria-hidden', 'true');
                    scanPreview.setAttribute('hidden', 'hidden');
                }
                if (scanCameraNote) {
                    scanCameraNote.textContent = 'Tap Scan to use the camera decoder. Best results come from good lighting and a steady label.';
                }
                if (scanStatus) {
                    scanStatus.textContent = '';
                }
                setScanButtonState(false);
            };

            const ensureVerifyBarcodeDetector = async () => {
                if (!('BarcodeDetector' in window)) {
                    throw new Error('BarcodeDetector is not available in this browser.');
                }
                if (scanDetector) {
                    return scanDetector;
                }
                const supportedFormats = await getSupportedBarcodeFormats();
                try {
                    scanDetector = new window.BarcodeDetector({
                        formats: supportedFormats,
                    });
                } catch (_) {
                    scanDetector = new window.BarcodeDetector();
                }
                return scanDetector;
            };

            const captureFocusFrame = () => {
                if (!scanPreviewVideo || !scanFocusContext) {
                    return null;
                }

                const sourceWidth = scanPreviewVideo.videoWidth || 0;
                const sourceHeight = scanPreviewVideo.videoHeight || 0;
                if (sourceWidth < 2 || sourceHeight < 2) {
                    return null;
                }

                const cropWidth = Math.max(1, Math.floor(sourceWidth * 0.84));
                const cropHeight = Math.max(1, Math.floor(sourceHeight * 0.42));
                const sourceX = Math.max(0, Math.floor((sourceWidth - cropWidth) / 2));
                const sourceY = Math.max(0, Math.floor((sourceHeight - cropHeight) / 2));

                scanFocusCanvas.width = sourceWidth;
                scanFocusCanvas.height = sourceHeight;
                scanFocusContext.clearRect(0, 0, sourceWidth, sourceHeight);
                scanFocusContext.drawImage(
                    scanPreviewVideo,
                    sourceX,
                    sourceY,
                    cropWidth,
                    cropHeight,
                    0,
                    0,
                    sourceWidth,
                    sourceHeight
                );

                return scanFocusCanvas;
            };

            const detectBarcodeValue = async () => {
                if (!scanDetector || !scanPreviewVideo) {
                    return '';
                }

                const codes = await scanDetector.detect(scanPreviewVideo);
                const directValue = String(codes?.[0]?.rawValue || '').trim();
                if (directValue) {
                    return directValue;
                }

                if (scanMissCount < 4) {
                    return '';
                }

                const focusFrame = captureFocusFrame();
                if (!focusFrame) {
                    return '';
                }

                try {
                    const focusCodes = await scanDetector.detect(focusFrame);
                    return String(focusCodes?.[0]?.rawValue || '').trim();
                } catch (_) {
                    return '';
                }
            };

            const openVerifyCameraStream = async () => {
                let lastError = null;

                for (const profile of scanConstraintProfiles) {
                    try {
                        return await navigator.mediaDevices.getUserMedia(profile);
                    } catch (err) {
                        lastError = err;
                    }
                }

                throw lastError || new Error('Camera access failed.');
            };

            const runVerifyBarcodeDetectorLoop = async () => {
                if (!scanActive || !scanPreviewVideo || !scanDetector) {
                    return;
                }

                try {
                    if (scanDecodeBusy) {
                        scheduleNextScanPass(180);
                        return;
                    }

                    if (Date.now() < scanCooldownUntil) {
                        scheduleNextScanPass(180);
                        return;
                    }

                    if (scanPreviewVideo.readyState < HTMLMediaElement.HAVE_ENOUGH_DATA) {
                        scheduleNextScanPass(180);
                        return;
                    }

                    scanDecodeBusy = true;
                    const value = await detectBarcodeValue();

                    if (value) {
                        scanMissCount = 0;
                        const now = Date.now();
                        if (value !== lastCameraCode || now >= scanCooldownUntil) {
                            lastCameraCode = value;
                            scanCooldownUntil = now + 1200;
                            if (scanResultLock) {
                                if (scanStatus) {
                                    scanStatus.textContent = 'Close the scan result to continue scanning.';
                                }
                                scheduleNextScanPass(260);
                                return;
                            }
                            if (scanStatus) {
                                scanStatus.textContent = `Camera scanned ${value}.`;
                            }
                            await handleScan(value);
                            if (scanInput) {
                                scanInput.value = '';
                            }
                        }
                    } else {
                        scanMissCount += 1;
                    }
                } catch (err) {
                    if (scanStatus) {
                        scanStatus.textContent = describeError(err);
                    }
                } finally {
                    scanDecodeBusy = false;
                    if (scanActive) {
                        scheduleNextScanPass(scanMissCount >= 4 ? 320 : 240);
                    }
                }
            };

            const startVerifyCameraScan = async () => {
                stopVerifyCameraScan();

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    throw new Error('Camera access is unavailable in this browser.');
                }

                scanStream = await openVerifyCameraStream();

                if (!scanPreviewVideo) {
                    throw new Error('Camera preview element is missing.');
                }

                if (scanPreview) {
                    scanPreview.removeAttribute('hidden');
                    scanPreview.setAttribute('aria-hidden', 'false');
                }

                scanPreviewVideo.autoplay = true;
                scanPreviewVideo.muted = true;
                scanPreviewVideo.playsInline = true;
                scanPreviewVideo.setAttribute('autoplay', 'autoplay');
                scanPreviewVideo.setAttribute('muted', 'muted');
                scanPreviewVideo.setAttribute('playsinline', 'playsinline');
                scanPreviewVideo.srcObject = scanStream;
                try {
                    await scanPreviewVideo.play();
                } catch (err) {
                    await waitFor(120);
                    await scanPreviewVideo.play();
                }
                if (scanPreviewVideo.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
                    await waitFor(180);
                }

                const track = scanStream.getVideoTracks()[0] || null;
                await applyCameraTuning(track);
                await waitFor(220);
                await ensureVerifyBarcodeDetector();

                scanActive = true;
                scanMissCount = 0;
                setScanButtonState(true);

                if (scanCameraNote) {
                    scanCameraNote.textContent = 'Camera is live. Hold the barcode steady 6-10 inches away and fill the center of the frame.';
                }
                if (scanStatus) {
                    scanStatus.textContent = 'Scanning with the on-page camera decoder...';
                }

                scheduleNextScanPass(260);
            };

            if (availableOrdersList && toggleAllOrdersBtn && toggleAllOrdersBtn.dataset.ordersToggleBound !== '1') {
                toggleAllOrdersBtn.dataset.ordersToggleBound = '1';
                let showAllOrders = availableOrdersList.dataset.defaultShowAll === '1';
                const applyOrderFilter = () => {
                    const entries = availableOrdersList.querySelectorAll('.order-entry');
                    const filterValue = (currentOrdersFilterInput?.value || '').trim().toLowerCase();
                    let visibleCount = 0;
                    entries.forEach((el) => {
                        const isMine = el.classList.contains('order-entry--mine');
                        const orderText = (el.querySelector('.order-entry__main')?.textContent || '').trim().toLowerCase();
                        const matchesFilter = filterValue === '' || orderText.includes(filterValue);
                        if (showAllOrders && matchesFilter) {
                            el.removeAttribute('hidden');
                            el.style.display = '';
                            visibleCount += 1;
                        } else {
                            if (isMine && matchesFilter) {
                                el.removeAttribute('hidden');
                                el.style.display = '';
                                visibleCount += 1;
                            } else {
                                el.setAttribute('hidden', 'hidden');
                                el.style.display = 'none';
                            }
                        }
                    });
                    const filterEmptyEl = document.getElementById('currentOrdersFilterEmpty');
                    if (filterEmptyEl) {
                        filterEmptyEl.hidden = visibleCount !== 0;
                    }
                };
                const syncOrderFilterLabel = () => {
                    toggleAllOrdersBtn.textContent = showAllOrders ? 'Assigned Only' : 'See All';
                };
                availableOrdersList.classList.toggle('orders-assigned-only', !showAllOrders);
                applyOrderFilter();
                syncOrderFilterLabel();
                toggleAllOrdersBtn.addEventListener('click', () => {
                    showAllOrders = !showAllOrders;
                    availableOrdersList.classList.toggle('orders-assigned-only', !showAllOrders);
                    applyOrderFilter();
                    syncOrderFilterLabel();
                });
                if (currentOrdersFilterInput && currentOrdersFilterInput.dataset.filterBound !== '1') {
                    currentOrdersFilterInput.dataset.filterBound = '1';
                    currentOrdersFilterInput.addEventListener('input', applyOrderFilter);
                }
            }

            const indexRowBySku = (row, prevSku) => {
                if (prevSku) {
                    const list = rowBySku.get(prevSku);
                    if (list) {
                        rowBySku.set(prevSku, list.filter(r => r !== row));
                        if (rowBySku.get(prevSku)?.length === 0) {
                            rowBySku.delete(prevSku);
                        }
                    }
                }
                const skuCell = row.querySelector('.sku-cell');
                if (!skuCell) return;
                const sku = normalizeSkuValue(skuCell.textContent);
                if (!sku) return;
                if (!rowBySku.has(sku)) rowBySku.set(sku, []);
                rowBySku.get(sku).push(row);
            };

            rows.forEach(row => indexRowBySku(row));
            const rebuildRowIndex = () => {
                rowBySku.clear();
                rows = tableBody ? Array.from(tableBody.querySelectorAll('tr')) : rows;
                rows.forEach(row => indexRowBySku(row));
            };

            const getCellValue = (row, index) => {
                const cell = row.children[index];
                if (!cell) return '';
                const input = cell.querySelector('input, select, textarea');
                if (input && input.type === 'checkbox') return input.checked ? '1' : '0';
                return (cell.textContent || '').trim();
            };

            const parseValue = (value, type) => {
                if (type === 'number') {
                    const num = parseFloat(String(value).replace(/[^0-9.-]/g, ''));
                    return Number.isFinite(num) ? num : 0;
                }
                return String(value).toLowerCase();
            };

            const sortRows = (index, type, dir) => {
                if (!tableBody) return;
                const sorted = [...tableBody.querySelectorAll('tr')].sort((a, b) => {
                    const av = parseValue(getCellValue(a, index), type);
                    const bv = parseValue(getCellValue(b, index), type);
                    if (av < bv) return dir === 'asc' ? -1 : 1;
                    if (av > bv) return dir === 'asc' ? 1 : -1;
                    return 0;
                });
                sorted.forEach(row => tableBody.appendChild(row));
                sorted.forEach(updateRowState);
            };

            const updateHeaderIndicators = () => {
                tableHeaders.forEach((th, i) => {
                    const isActive = i === sortState.index;
                    th.classList.toggle('is-sorted', isActive);
                    th.setAttribute('aria-sort', isActive ? (sortState.dir === 'asc' ? 'ascending' : 'descending') : 'none');
                    th.setAttribute('title', 'Sort');
                    if (isActive) {
                        th.setAttribute('data-sort-dir', sortState.dir);
                    } else {
                        th.removeAttribute('data-sort-dir');
                    }
                });
            };

            if (tableHeaders.length) {
                tableHeaders.forEach((th, index) => {
                    const type = th.dataset.sort || 'text';
                    th.style.cursor = 'pointer';
                    th.addEventListener('click', () => {
                        if (isCurrentOrderCompleted) {
                            return;
                        }
                        const nextDir = (sortState.index === index && sortState.dir === 'asc') ? 'desc' : 'asc';
                        sortState = { index, dir: nextDir, type };
                        sortRows(index, type, nextDir);
                        updateHeaderIndicators();
                    });
                });
                updateHeaderIndicators();
            }


            const toastEl = document.getElementById('verifyPickToast');
            let toastTimer = null;
            const showToast = (msg) => {
                if (!toastEl) return;
                toastEl.textContent = String(msg || '');
                toastEl.classList.add('is-visible');
                if (toastTimer) clearTimeout(toastTimer);
                toastTimer = setTimeout(() => {
                    toastEl.classList.remove('is-visible');
                }, 4000);
            };

            let scanAudioContext = null;
            let scanPanelFlashTimer = null;
            let scanResultFlashTimer = null;
            let scanResultAutoHideTimer = null;
            let scanResultFadeTimer = null;
            let scanResultOverScanState = false;
            let scanResultOverScanMessage = '';
            let scanResultLock = false;
            let scanErrorLatchActive = false;
            let scanErrorLatchMessage = '';
            let overScanRowFlashTimer = null;
            const flashScanPanel = (state = 'success') => {
                if (!scanPanel) return;
                scanPanel.style.transition = 'box-shadow 140ms ease, background-color 140ms ease';
                if (state === 'error') {
                    scanPanel.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.32)';
                    scanPanel.style.backgroundColor = 'rgba(220, 53, 69, 0.08)';
                } else {
                    scanPanel.style.boxShadow = '0 0 0 3px rgba(25, 135, 84, 0.32)';
                    scanPanel.style.backgroundColor = 'rgba(25, 135, 84, 0.08)';
                }
                if (scanPanelFlashTimer) clearTimeout(scanPanelFlashTimer);
                scanPanelFlashTimer = setTimeout(() => {
                    if (!scanPanel) return;
                    scanPanel.style.boxShadow = '';
                    scanPanel.style.backgroundColor = '';
                }, 900);
            };

            const flashOverScanRows = (rowsForSku, duration = 1200) => {
                const targetRows = Array.isArray(rowsForSku) ? rowsForSku.filter(Boolean) : [];
                if (targetRows.length === 0) {
                    return;
                }
                targetRows.forEach((row) => row.classList.add('verify-row-over-attempt'));
                if (overScanRowFlashTimer) {
                    clearTimeout(overScanRowFlashTimer);
                }
                overScanRowFlashTimer = setTimeout(() => {
                    targetRows.forEach((row) => row.classList.remove('verify-row-over-attempt'));
                }, duration);
            };

            const flashScanResultOverScan = (message) => {
                if (!scanResultPanel) return;
                scanResultOverScanState = true;
                scanResultOverScanMessage = String(message || 'Overscanned');
                scanResultPanel.classList.add('is-over-scanned');
                scanResultLock = true;
                syncScanInteractionLock();
                if (scanResultStatus && message) {
                    scanResultStatus.textContent = scanResultOverScanMessage;
                    scanResultStatus.classList.add('is-inactive');
                }
            };

            const clearScanErrorLatch = () => {
                scanErrorLatchActive = false;
                scanErrorLatchMessage = '';
                if (scanPanel) {
                    scanPanel.classList.remove('is-scan-error-latched');
                }
                if (scanStatus && !isCurrentOrderCompleted) {
                    scanStatus.textContent = '';
                }
                syncScanInteractionLock();
                if (scanInput && !isCurrentOrderCompleted) {
                    window.setTimeout(() => {
                        try {
                            scanInput.focus({ preventScroll: true });
                        } catch (_) {
                            scanInput.focus();
                        }
                    }, 0);
                }
            };

            const latchWrongScan = (message) => {
                scanErrorLatchActive = true;
                scanErrorLatchMessage = String(message || 'Wrong item scanned. Tap to continue.');
                if (scanPanel) {
                    scanPanel.classList.add('is-scan-error-latched');
                }
                stopVerifyCameraScan();
                setScanErrorStatus(scanErrorLatchMessage);
                syncScanInteractionLock();
            };

            const getScanAudioContext = () => {
                try {
                    const AudioCtor = window.AudioContext || window.webkitAudioContext;
                    if (!AudioCtor) return null;
                    scanAudioContext = scanAudioContext || new AudioCtor();
                    const ctx = scanAudioContext;
                    if (ctx.state === 'suspended') {
                        void ctx.resume().catch(() => { });
                    }
                    return ctx;
                } catch (_) { }
                return null;
            };

            const playTone = (steps, gainPeak = 0.08) => {
                const ctx = getScanAudioContext();
                if (!ctx) {
                    return;
                }
                const startAt = ctx.currentTime + 0.01;
                steps.forEach((step, index) => {
                    const toneStart = startAt + (step.offset || 0);
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = step.type || 'sine';
                    osc.frequency.setValueAtTime(step.frequency, toneStart);
                    gain.gain.setValueAtTime(0.0001, toneStart);
                    gain.gain.exponentialRampToValueAtTime(step.gain || gainPeak, toneStart + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, toneStart + step.duration);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start(toneStart);
                    osc.stop(toneStart + step.duration + 0.02);
                });
            };

            const playScanSuccessCue = () => {
                try {
                    if (navigator.vibrate) {
                        navigator.vibrate([70, 40, 90]);
                    }
                } catch (_) { }

                try {
                    playTone([
                        { frequency: 880, duration: 0.08, offset: 0 },
                        { frequency: 1174, duration: 0.14, offset: 0.08 },
                    ], 0.08);
                } catch (_) { }
            };

            const playScanErrorCue = () => {
                try {
                    if (navigator.vibrate) {
                        navigator.vibrate([110, 45, 110]);
                    }
                } catch (_) { }

                try {
                    playTone([
                        { frequency: 440, duration: 0.11, offset: 0, type: 'triangle', gain: 0.14 },
                        { frequency: 349, duration: 0.16, offset: 0.11, type: 'triangle', gain: 0.14 },
                    ], 0.14);
                } catch (_) { }
            };

            const notifyScanError = (message) => {
                flashScanPanel('error');
                playScanErrorCue();
                showToast(message);
            };

            const setScanErrorStatus = (message) => {
                if (scanStatus) {
                    scanStatus.textContent = message;
                }
                notifyScanError(message);
            };

            const setScanSuccessStatus = (message) => {
                if (scanStatus) {
                    scanStatus.textContent = message;
                }
                flashScanPanel();
                playScanSuccessCue();
            };

            const notifyScanSuccess = (message) => {
                showToast(message);
            };

            const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (m) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[m]));

            const renderScanResultCard = (result, options = {}) => {
                if (!scanResultPanel || !result) return;
                if (scanResultAutoHideTimer) {
                    window.clearTimeout(scanResultAutoHideTimer);
                    scanResultAutoHideTimer = null;
                }
                if (scanResultFadeTimer) {
                    window.clearTimeout(scanResultFadeTimer);
                    scanResultFadeTimer = null;
                }
                scanResultPanel.classList.remove('is-fading');
                const isOverScanned = !!options.overScanned;
                if (isOverScanned) {
                    scanResultOverScanState = true;
                    scanResultOverScanMessage = 'Over scanned';
                    scanResultPanel.classList.add('is-over-scanned');
                    scanResultLock = true;
                } else if (!scanResultOverScanState) {
                    scanResultPanel.classList.remove('is-over-scanned');
                }
                const skuText = String(result.sku || '').trim() || 'Unknown SKU';
                const descText = String(result.description || '').trim() || 'No description available.';
                const statusText = String(result.status || '').trim() || 'Unknown';
                const codeText = String(result.scannedCode || '').trim() || '-';
                const shelfText = String(result.shelf || '').trim() || '-';
                const onHandText = Number.isFinite(Number(result.onHand)) ? String(Number(result.onHand)) : '-';
                const locationsText = Array.isArray(result.locations) && result.locations.length
                    ? result.locations.map((loc) => String(loc.location_label || loc.loc_label || '').trim()).filter(Boolean).slice(0, 3).join(', ')
                    : '-';

                if (scanResultEmpty) {
                    scanResultEmpty.hidden = true;
                }
                scanResultPanel.hidden = false;
                scanResultPanel.setAttribute('aria-hidden', 'false');
                if (scanResultMedia) {
                    scanResultMedia.hidden = false;
                    scanResultMedia.classList.remove('is-visible');
                }
                if (scanResultDetails) {
                    scanResultDetails.hidden = false;
                }
                if (scanResultTitle) {
                    scanResultTitle.textContent = 'Scan Result';
                }
                if (scanResultSku) {
                    scanResultSku.textContent = skuText;
                }
                if (scanResultDesc) {
                    scanResultDesc.textContent = descText;
                }
                if (scanResultStatus) {
                    scanResultStatus.textContent = isOverScanned ? 'Overscanned' : statusText;
                    scanResultStatus.classList.toggle('is-inactive', isOverScanned || statusText.toUpperCase() !== 'ACTIVE');
                }
                if (scanResultCode) {
                    scanResultCode.textContent = codeText;
                }
                if (scanResultOnHand) {
                    scanResultOnHand.textContent = onHandText;
                }
                if (scanResultShelf) {
                    scanResultShelf.textContent = shelfText;
                }
                if (scanResultLocations) {
                    scanResultLocations.textContent = locationsText || '-';
                }

                const imageUrl = String(result.imageUrl || '').trim();
                if (scanResultImage) {
                    if (imageUrl) {
                        scanResultImage.src = imageUrl;
                        scanResultImage.alt = `${skuText} product image`;
                        scanResultImage.hidden = false;
                    } else {
                        scanResultImage.removeAttribute('src');
                        scanResultImage.alt = '';
                        scanResultImage.hidden = true;
                    }
                }
                if (scanResultImageFallback) {
                    if (isOverScanned) {
                        scanResultImageFallback.hidden = true;
                    } else {
                        scanResultImageFallback.hidden = imageUrl !== '';
                        scanResultImageFallback.textContent = 'No image';
                    }
                }
                if (scanResultMedia) {
                    // Restart the fade each time we render a fresh scan result.
                    void scanResultMedia.offsetWidth;
                    window.requestAnimationFrame(() => {
                        scanResultMedia.classList.add('is-visible');
                    });
                }
                if (scanResultImage) {
                    if (isOverScanned) {
                        scanResultImage.hidden = true;
                        scanResultImage.style.filter = '';
                        scanResultImage.style.opacity = '';
                    } else {
                        scanResultImage.style.filter = '';
                        scanResultImage.style.opacity = '';
                    }
                }
                if (scanResultOverScanState && scanResultStatus) {
                    scanResultStatus.textContent = scanResultOverScanMessage || 'Overscanned';
                    scanResultStatus.classList.add('is-inactive');
                    scanResultPanel.classList.add('is-over-scanned');
                }
                if (!isOverScanned) {
                    scanResultAutoHideTimer = window.setTimeout(() => {
                        if (!scanResultPanel || scanResultPanel.hidden) return;
                        scanResultPanel.classList.add('is-fading');
                        scanResultFadeTimer = window.setTimeout(() => {
                            hideScanResultCard();
                        }, 260);
                    }, 4000);
                }
            };

            const fetchShopifyImageForSku = async (sku) => {
                const normalizedSku = String(sku || '').trim();
                if (!normalizedSku) {
                    return '';
                }
                try {
                    const url = new URL(`${APP_BASE}/utils/shopify_proxy.php`, window.location.origin);
                    url.searchParams.set('sku', normalizedSku);
                    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) {
                        return '';
                    }
                    const data = await res.json();
                    return String(data?.data?.image_url || '').trim();
                } catch (err) {
                    console.warn('[verify_pick] shopify image lookup failed:', err);
                    return '';
                }
            };

            const fetchScanResultCardData = async (rawCode, fallbackSku, fallbackDesc) => {
                const fallback = {
                    scannedCode: rawCode,
                    sku: fallbackSku,
                    description: fallbackDesc,
                    status: '',
                    shelf: '',
                    onHand: 0,
                    locations: [],
                    imageUrl: '',
                };
                try {
                    const url = buildVerifyActionUrl('sku_lookup', { code: rawCode });
                    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) {
                        return fallback;
                    }
                    const data = await res.json();
                    if (!data?.ok || !data?.sku) {
                        return fallback;
                    }
                    const skuNum = String(data.sku.sku_num || fallbackSku || '').trim();
                    let imageUrl = String(data.sku.image_url || '').trim();
                    if (!imageUrl && skuNum) {
                        imageUrl = await fetchShopifyImageForSku(skuNum);
                    }
                    return {
                        scannedCode: String(data.sku.scanned_code || rawCode || ''),
                        sku: skuNum,
                        description: String(data.sku.desc || fallbackDesc || ''),
                        status: String(data.sku.status || ''),
                        shelf: String(data.sku.shelf_code || ''),
                        onHand: Number(data.total_on_hand ?? data.sku.sku_quantity ?? 0) || 0,
                        locations: Array.isArray(data.locations) ? data.locations : [],
                        imageUrl,
                    };
                } catch (err) {
                    console.warn('[verify_pick] scan result lookup failed:', err);
                    return fallback;
                }
            };

            const openSkuModal = () => {
                if (isCurrentOrderCompleted) {
                    showToast('Completed picks are read-only.');
                    return;
                }
                if (!skuModal) return;
                skuModal.removeAttribute('hidden');
                skuModal.setAttribute('aria-hidden', 'false');
                skuModal.classList.add('is-open');
                if (skuModalInput) {
                    skuModalInput.value = '';
                    skuModalInput.focus();
                }
                if (skuModalQty) {
                    skuModalQty.value = '1';
                }
                if (skuModalResults) {
                    skuModalResults.innerHTML = '';
                }
            };

            const closeSkuModal = () => {
                if (!skuModal) return;
                skuModal.classList.remove('is-open');
                skuModal.setAttribute('aria-hidden', 'true');
                skuModal.setAttribute('hidden', 'hidden');
                if (skuModalResults) {
                    skuModalResults.innerHTML = '';
                }
            };

            const showSkuResultsMessage = (msg) => {
                if (!skuModalResults) return;
                skuModalResults.innerHTML = `<div class="muted" style="padding:10px 12px;">${esc(msg)}</div>`;
            };

            const persistShortShipState = async (row, nextShortShip, mode = activeMode) => {
                const itemId = Number(row?.dataset.pickItemId || 0);
                if (!pickOrderId || itemId <= 0) {
                    return { ok: true, short_ship: !!nextShortShip, persisted: false };
                }
                const url = buildVerifyActionUrl('set_short_ship');
                const res = await fetch(url.toString(), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        order_id: pickOrderId,
                        item_id: itemId,
                        short_ship: !!nextShortShip,
                        mode,
                    }),
                });
                if (!res.ok) {
                    throw new Error(await readJsonErrorMessage(res, 'Unable to update short ship.'));
                }
                const data = await res.json();
                if (!data?.ok) {
                    throw new Error(data?.error || 'Unable to update short ship.');
                }
                if (row) {
                    row.dataset.shortShipQty = String(Number(data?.short_ship_qty || 0) || 0);
                }
                return data;
            };

            const hasShortShipLockedRowsForSku = (rowsForSku) => Array.isArray(rowsForSku) && rowsForSku.some((row) => isShortShipRow(row));

            const bindShortShipCell = (row) => {
                const shortShip = row.querySelector('.short-ship-check');
                if (!shortShip) return;
                if (shortShip.dataset.shortShipBound !== '1') {
                    shortShip.dataset.shortShipBound = '1';
                    shortShip.addEventListener('change', async () => {
                        const shortShipEditable = !isCurrentOrderCompleted
                            && currentOrderStatus !== 'READY_TO_PACK'
                            && resolveUiMode() !== 'packer';
                        if (!shortShipEditable) {
                            shortShip.checked = !shortShip.checked;
                            shortShip.disabled = true;
                            return;
                        }
                        const nextShortShip = shortShip.checked;
                        const prevShortShip = !nextShortShip;
                        shortShip.disabled = true;
                        updateRowState(row);
                        updateProgress(activeMode);
                        try {
                            await persistShortShipState(row, nextShortShip, activeMode);
                            updateRowState(row);
                            updateProgress(activeMode);
                            showToast(nextShortShip ? 'Line marked short shipped.' : 'Short ship removed.');
                        } catch (err) {
                            shortShip.checked = prevShortShip;
                            updateRowState(row);
                            updateProgress(activeMode);
                            alert(err?.message || 'Unable to update short ship.');
                        } finally {
                            shortShip.disabled = !shortShipEditable;
                        }
                    });
                }
            };

            const resolveUiMode = (mode = null) => {
                if (mode === 'picker' || mode === 'packer') {
                    return mode;
                }
                return isWorkflowLocked ? getLockedWorkflowMode() : activeMode;
            };

            const getVerifiedCellForMode = (row, mode = null) => {
                const resolvedMode = resolveUiMode(mode);
                const css = resolvedMode === 'packer' ? '.verified-packer-cell' : '.verified-picker-cell';
                return row.querySelector(css);
            };

            const getVerifiedValueForMode = (row, mode = null) => {
                const cell = getVerifiedCellForMode(row, mode);
                return parseInt(cell?.dataset.verified || '0', 10) || 0;
            };

            const setVerifiedCellValue = (row, mode, next, qty) => {
                const cell = getVerifiedCellForMode(row, mode);
                if (!cell) return;
                cell.dataset.verified = String(next);
                const checkClass = mode === 'packer' ? 'verify-check-packer' : 'verify-check-picker';
                cell.innerHTML = `${next} / ${qty} <span class="verify-check ${checkClass}" aria-hidden="true">&#10003;</span>`;
            };

            const snapshotVerifiedValues = (targetRows, mode = null) => targetRows.map((row) => ({
                row,
                qty: parseInt(row.querySelector('.qty-cell')?.dataset.qty || '0', 10) || 0,
                verified: getVerifiedValueForMode(row, mode),
            }));

            const restoreVerifiedValues = (snapshot, mode = null) => {
                const resolvedMode = resolveUiMode(mode);
                snapshot.forEach((entry) => {
                    if (!entry?.row) return;
                    setVerifiedCellValue(entry.row, resolvedMode, Math.max(0, Number(entry.verified) || 0), Math.max(0, Number(entry.qty) || 0));
                    updateRowState(entry.row);
                });
            };

            const updateRowState = (row) => {
                const effectiveMode = resolveUiMode();
                const qtyCell = row.querySelector('.qty-cell');
                const qty = parseInt(qtyCell?.dataset.qty || '0', 10) || 0;
                const verifiedPicker = getVerifiedValueForMode(row, 'picker');
                const verifiedPacker = getVerifiedValueForMode(row, 'packer');
                const verified = effectiveMode === 'packer' ? verifiedPacker : verifiedPicker;
                const shortShip = !!row.querySelector('.short-ship-check')?.checked;
                const shortShipTag = row.querySelector('.short-ship-tag');
                const storedShortQty = parseInt(row?.dataset.shortShipQty || '0', 10) || 0;
                const shortQty = shortShip ? (storedShortQty > 0 ? storedShortQty : Math.max(0, qty - verified)) : 0;
                const expectedQty = effectiveMode === 'packer'
                    ? Math.max(0, qty - shortQty)
                    : qty;
                const resolvedVerified = effectiveMode === 'packer'
                    ? verified
                    : Math.min(qty, verified + shortQty);
                const rowComplete = expectedQty === 0 || resolvedVerified >= expectedQty;
                const isOver = qty > 0 && ((effectiveMode === 'packer' ? verified : verified) > expectedQty);
                row.classList.toggle('verify-row-over', isOver);
                row.classList.toggle('verify-row-complete', rowComplete && !isOver);
                row.classList.toggle('verify-row-short', shortShip);
                const pickerCell = row.querySelector('.verified-picker-cell');
                if (pickerCell) {
                    pickerCell.dataset.verified = String(verifiedPicker);
                    pickerCell.innerHTML = `${verifiedPicker} / ${qty} <span class="verify-check verify-check-picker" aria-hidden="true">&#10003;</span>`;
                }
                const packerCell = row.querySelector('.verified-packer-cell');
                if (packerCell) {
                    const packerExpectedQty = Math.max(0, qty - storedShortQty);
                    packerCell.dataset.verified = String(verifiedPacker);
                    packerCell.innerHTML = `${verifiedPacker} / ${packerExpectedQty} <span class="verify-check verify-check-packer" aria-hidden="true">&#10003;</span>`;
                }
                if (shortShipTag) {
                    shortShipTag.hidden = !shortShip || shortQty <= 0;
                    shortShipTag.textContent = shortShip
                        ? (shortQty > 0 ? `SHORT x${shortQty}` : 'SHORT')
                        : '';
                }
                const pickerCheck = row.querySelector('.verify-check-picker');
                if (pickerCheck) pickerCheck.classList.toggle('is-visible', qty > 0 && rowComplete);
                const packerCheck = row.querySelector('.verify-check-packer');
                if (packerCheck) packerCheck.classList.toggle('is-visible', qty > 0 && rowComplete);
            };

            function updateRow(row, delta, mode = null) {
                if (isCurrentOrderCompleted) {
                    return 0;
                }
                const resolvedMode = resolveUiMode(mode);
                const qtyCell = row.querySelector('.qty-cell');
                const qty = parseInt(qtyCell?.dataset.qty || '0', 10) || 0;
                const verified = getVerifiedValueForMode(row, resolvedMode);
                const next = Math.min(qty, verified + Math.max(0, delta));
                const applied = Math.max(0, next - verified);
                setVerifiedCellValue(row, resolvedMode, next, qty);
                updateRowState(row);
                return applied;
            }

            function updateProgress(mode = null) {
                const resolvedMode = resolveUiMode(mode);
                const pickerSummary = getVerificationSummary('picker');
                const packerSummary = hasPackerVerify ? getVerificationSummary('packer') : pickerSummary;
                const pickerPct = pickerSummary.total > 0
                    ? Math.min(100, Math.round((pickerSummary.handledTotal / pickerSummary.total) * 100))
                    : (pickerSummary.isComplete ? 100 : 0);
                const packerPct = hasPackerVerify
                    ? (packerSummary.total > 0
                        ? Math.min(100, Math.round((packerSummary.handledTotal / packerSummary.total) * 100))
                        : (packerSummary.isComplete ? 100 : 0))
                    : pickerPct;
                const pct = isWorkflowLocked
                    ? (resolvedMode === 'packer' ? packerPct : pickerPct)
                    : hasPackerVerify
                        ? Math.round((pickerPct * 0.5) + (packerPct * 0.5))
                        : pickerPct;
                if (pickProgressValue) pickProgressValue.textContent = `${pct}%`;
                if (pickProgressLabel) pickProgressLabel.textContent = resolvedMode === 'packer' ? 'Packed' : 'Picked';
                syncStageStatus();
                syncCloseOrderButton();
            }

            const setVerifyMode = (nextMode) => {
                activeMode = isWorkflowLocked
                    ? getLockedWorkflowMode()
                    : (nextMode === 'packer' ? 'packer' : 'picker');
                const isPacker = activeMode === 'packer';
                if (pickContent) {
                    pickContent.classList.toggle('mode-picker', !isPacker);
                    pickContent.classList.toggle('mode-packer', isPacker);
                    pickContent.dataset.workflowRender = isWorkflowLocked
                        ? (activeMode === 'packer' ? 'pack' : 'pick')
                        : '';
                }
                if (verifyModeBadge) {
                    verifyModeBadge.textContent = isPacker ? 'Packer Verification Mode' : 'Picker Verification Mode';
                }
                if (toggleVerifyModeBtn) {
                    toggleVerifyModeBtn.hidden = isWorkflowLocked;
                    toggleVerifyModeBtn.style.display = isWorkflowLocked ? 'none' : '';
                    toggleVerifyModeBtn.textContent = isPacker ? 'Picker Verify' : 'Packer Verify';
                }
                const resetVerifiedBtn = document.getElementById('resetVerifiedBtn');
                if (resetVerifiedBtn) {
                    resetVerifiedBtn.textContent = isPacker ? 'Reset' : 'Reset';
                }
                lastScannedSku = '';
                consecutiveScanCount = 0;
                rows.forEach(updateRowState);
                syncCloseOrderButton();
                syncReturnToPickButton();
                syncWorkflowActionVisibility();
                updateProgress(activeMode);
            };

            const handleCompleteVerifyAction = async (triggerButton = null) => {
                if (!pickOrderId) {
                    alert('No order loaded.');
                    return;
                }

                const pickerSummary = getVerificationSummary('picker');
                const packerSummary = hasPackerVerify ? getVerificationSummary('packer') : pickerSummary;
                const isPackerStage = activeMode === 'packer' && hasPackerVerify;
                const pickerCanCloseWithShortages = canCloseWithShortages(pickerSummary);
                const packerCanCloseWithShortages = canCloseWithShortages(packerSummary);

                if (!isPackerStage) {
                    if (!pickerSummary.isComplete && !pickerCanCloseWithShortages) {
                        setScanErrorStatus(`Picker still has ${pickerSummary.remaining} item(s) left to scan.`);
                        return;
                    }
                    const closeWithShortages = !pickerSummary.isComplete && pickerCanCloseWithShortages;
                    const ok = confirm(closeWithShortages
                        ? 'This sales order has short-shipped lines. Close picker verification and move it to packing anyway?'
                        : 'Picking is complete. Mark this order ready for packing now?');
                    if (!ok) {
                        return;
                    }
                    if (triggerButton) {
                        triggerButton.disabled = true;
                    }
                    try {
                        const nextStatus = 'READY_TO_PACK';
                        console.log('[verify_pick] complete_stage_submit', {
                            order_id: pickOrderId,
                            order_number: salesOrderNumber,
                            next_status: nextStatus,
                            mode: activeMode,
                            picker_summary: pickerSummary,
                            packer_summary: packerSummary,
                            close_with_shortages: !!closeWithShortages,
                        });
                        await submitOrderStatus(
                            nextStatus,
                            'complete pick',
                            nextStatus === 'READY_TO_PACK'
                                ? (closeWithShortages
                                    ? 'Pick closed with accepted shortages. Ready for pack verification.'
                                    : 'Pick completed. Ready for pack verification.')
                                : (closeWithShortages
                                    ? 'Pick closed with accepted shortages.'
                                    : 'Pick closed.')
                        );
                        window.setTimeout(() => window.location.reload(), 250);
                    } catch (err) {
                        alert(err?.message || 'Unable to complete pick.');
                    } finally {
                        syncCloseOrderButton();
                        syncStageStatus();
                    }
                    return;
                }

                if (!packCompletionEnabled) {
                    alert('Complete Pack is temporarily disabled.');
                    return;
                }
                if (!pickerSummary.isComplete && !pickerCanCloseWithShortages) {
                    setVerifyMode('picker');
                    setScanErrorStatus('Picker scans must be completed before packing can be finished.');
                    return;
                }
                if (!packerSummary.isComplete && !packerCanCloseWithShortages) {
                    setScanErrorStatus(`Packer still has ${packerSummary.remaining} item(s) left to scan.`);
                    return;
                }

                const closeWithShortages = !packerSummary.isComplete && packerCanCloseWithShortages;
                const ok = confirm('Complete order?');
                if (!ok) {
                    return;
                }
                if (triggerButton) {
                    triggerButton.disabled = true;
                }
                try {
                    console.log('[verify_pick] complete_stage_submit', {
                        order_id: pickOrderId,
                        order_number: salesOrderNumber,
                        next_status: 'COMPLETED',
                        mode: activeMode,
                        picker_summary: pickerSummary,
                        packer_summary: packerSummary,
                        close_with_shortages: !!closeWithShortages,
                    });
                    await submitOrderStatus(
                        'COMPLETED',
                        'complete pack',
                        closeWithShortages
                            ? 'Order completed with accepted shortages.'
                            : 'Order completed.'
                    );
                    promptPackingManagerRedirect();
                } catch (err) {
                    alert(err?.message || 'Unable to close pick.');
                } finally {
                    syncCloseOrderButton();
                    syncStageStatus();
                }
            };

            const submitOrderStatus = async (nextStatus, actionLabel, successMessage, mode = activeMode) => {
                if (!pickOrderId) {
                    throw new Error('No order loaded.');
                }
                const url = new URL(window.location.href);
                url.searchParams.set('action', 'set_order_status');
                url.searchParams.set('order_id', String(pickOrderId));
                url.searchParams.set('status', String(nextStatus || ''));
                url.searchParams.set('mode', String(mode || 'picker'));
                const res = await fetch(url.toString(), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ order_id: pickOrderId, status: nextStatus, mode }),
                });
                const payloadText = await res.text();
                let data = null;
                const trimmed = payloadText.trim();
                if (trimmed) {
                    try {
                        data = JSON.parse(trimmed);
                    } catch (err) {
                        data = { ok: false, error: trimmed };
                    }
                }
                if (!res.ok) {
                    throw new Error(data?.error || `Unable to ${actionLabel} pick.`);
                }
                if (!data?.ok) {
                    throw new Error(data?.error || `Unable to ${actionLabel} pick.`);
                }
                if (data?.status) {
                    currentOrderStatus = String(data.status || '').trim().toUpperCase();
                }
                isCurrentOrderCompleted = isTerminalOrderStatus(data?.status || nextStatus);
                syncCloseOrderButton();
                syncCompletedPickState();
                syncStageStatus();
                showToast(successMessage);
                return data;
            };

            const promptPackingManagerRedirect = () => {
                const openManager = confirm('Open Packing Manager?');
                if (openManager) {
                    window.location.assign('packing_manager.php');
                    return;
                }
                window.location.assign('verify_pick.php');
            };

            const addPickRow = (data) => {
                if (!tableBody) return;
                const sku = String(data?.sku || '').trim();
                const upc = String(data?.upc || '').trim();
                const desc = String(data?.desc || '').trim();
                const warehouse = String(data?.warehouse || '').trim();
                const shelf = String(data?.shelf || '').trim();
                const qty = Number(data?.qty || 1) || 1;
                const inventory = Number(data?.inventory || 0) || 0;
                const isShortShip = !!data?.is_short_ship;
                const shortShipQty = Number(data?.short_ship_qty || data?.shortShipQty || 0) || 0;
                const packExpectedQty = Math.max(0, qty - shortShipQty);
                const pickItemId = Number(data?.pick_item_id || data?.pickItemId || 0);
                const itemNo = String(data?.item_no || data?.itemNo || sku).trim();

                const row = document.createElement('tr');
                if (pickItemId > 0) {
                    row.dataset.pickItemId = String(pickItemId);
                }
                row.dataset.sku = sku;
                row.dataset.itemNo = itemNo;
                row.dataset.upc = upc;
                row.dataset.shortShipQty = String(shortShipQty);
                row.innerHTML = `
                                                                                    <td>
                                                                                        <span class="sku-cell-wrap">
                                                                                    <strong class="sku-cell"></strong>
                                                                                            <button type="button" class="sku-barcode-trigger sku-barcode-trigger--inline"
                                                                                                data-sku-barcode-trigger="1"
                                                                                                aria-label="View SKU Code 128 barcode" title="View SKU Code 128 barcode">
                                                                                                <span class="sku-barcode-trigger__label">CODE 128</span>
                                                                                            </button>
                                                                                        </span>
                                                                                    </td>
                                                                                    <td></td>
                                                                                    <td></td>
                                                                                    <td class="desc-cell"></td>
                                                                                    <td class="text-right qty-cell" data-qty="1">1</td>
                                                                                    <td class="text-right verified-cell verified-picker-cell workflow-picker-only" data-verified="0">0 / 1 <span class="verify-check verify-check-picker" aria-hidden="true">&#10003;</span></td>
                                                                                    <td class="text-right verified-cell verified-packer-cell workflow-packer-only" data-verified="0">0 / ${packExpectedQty} <span class="verify-check verify-check-packer" aria-hidden="true">&#10003;</span></td>
                                                                                    <td class="text-right">0</td>
                                                                                    <td style="text-align:center;">
                                                                                        <input type="checkbox" class="short-ship-check"${isShortShip ? ' checked' : ''} aria-label="Short ship new line">
                                                                                        <div class="short-ship-tag" hidden></div>
                                                                                    </td>
                                                                                    <td class="delete-col">
                                                                                        <input type="checkbox" class="delete-check" aria-label="Delete line new">
                                                                                    </td>
                                                                                `;

                const skuCell = row.querySelector('.sku-cell');
                const warehouseCell = row.children[1];
                const shelfCell = row.children[2];
                const descCell = row.querySelector('.desc-cell');
                const qtyCell = row.querySelector('.qty-cell');
                const inventoryCell = row.children[7];

                if (skuCell) skuCell.textContent = sku;
                const inlineBarcodeTrigger = row.querySelector('.sku-barcode-trigger');
                if (inlineBarcodeTrigger) {
                    inlineBarcodeTrigger.setAttribute('aria-label', `View Code 128 barcode for SKU ${sku}`);
                }
                if (warehouseCell) warehouseCell.textContent = warehouse;
                if (shelfCell) shelfCell.textContent = shelf;
                if (descCell) descCell.textContent = desc;
                row.dataset.sku = sku;
                row.dataset.upc = upc;
                if (qtyCell) {
                    qtyCell.dataset.qty = String(qty);
                    qtyCell.textContent = String(qty);
                }
                setVerifiedCellValue(row, 'picker', 0, qty);
                setVerifiedCellValue(row, 'packer', 0, qty);
                if (inventoryCell) inventoryCell.textContent = String(inventory);
                row.dataset.shortShipQty = String(shortShipQty);

                tableBody.appendChild(row);
                rows = tableBody ? Array.from(tableBody.querySelectorAll('tr')) : rows;

                updateRowState(row);

                bindShortShipCell(row);
                syncCompletedRowState(row);

                indexRowBySku(row);
                refreshBogoMarkers();
                updateProgress(activeMode);
                return row;
            };

            // BOGO comes only from the flag stored at import (more than one Take
            // on a source line); a SKU repeated on several rows is not BOGO.
            const refreshBogoMarkers = () => {
                if (!tableBody) return;
                tableBody.querySelectorAll('tr').forEach((candidate) => {
                    const skuWrap = candidate.querySelector('.sku-cell-wrap');
                    if (!skuWrap) return;
                    skuWrap.querySelectorAll('.bogo').forEach((marker) => marker.remove());
                    if (candidate.dataset.isBogo === '1') {
                        const marker = document.createElement('span');
                        marker.className = 'bogo';
                        marker.title = 'Repeated item line in this order';
                        marker.textContent = 'BOGO';
                        skuWrap.appendChild(marker);
                    }
                });
            };

            const renderSkuResults = (results) => {
                if (!skuModalResults) return;
                if (!results || !results.length) {
                    showSkuResultsMessage('No matches found.');
                    return;
                }
                skuModalResults.innerHTML = results.map((r) => `
                                                                                    <div class="vp-sku-result" data-sku="${esc(r.sku_num)}">
                                                                                        <div class="vp-sku-result__title"><strong>${esc(r.sku_num)}</strong> — ${esc(r.desc || '')}</div>
                                                                                        <div class="vp-sku-result__meta">On-hand: ${Number(r.on_hand)} | ${esc(r.status || '')}</div>
                                                                                    </div>
                                                                                `).join('');

                skuModalResults.querySelectorAll('.vp-sku-result').forEach((el) => {
                    el.addEventListener('click', () => {
                        const sku = el.getAttribute('data-sku') || '';
                        if (skuModalInput) {
                            skuModalInput.value = sku;
                            skuModalInput.focus();
                            skuModalInput.select();
                        }
                    });
                });
            };

            const lookupSkuFuzzy = async (q) => {
                if (!q) return;
                if (!skuModalResults) return;
                skuModalResults.innerHTML = `<div class="loading">Searching…</div>`;
                try {
                    const url = buildVerifyActionUrl('sku_search', { q });
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const ct = res.headers.get('content-type') || '';
                    if (!res.ok) {
                        showSkuResultsMessage('Search failed (server error).');
                        return;
                    }
                    if (!ct.includes('application/json')) {
                        showSkuResultsMessage('Search failed (unexpected response).');
                        return;
                    }
                    const data = await res.json();
                    if (!data.ok) {
                        showSkuResultsMessage(data.error || 'Search failed.');
                        return;
                    }
                    renderSkuResults(data.results || []);
                } catch (e) {
                    console.error('[verify_pick] SKU search failed:', e);
                    showSkuResultsMessage('Search failed.');
                }
            };

            const lookupSkuDirect = async (code, options = {}) => {
                const showNotFound = options.showNotFound !== false;
                if (isCurrentOrderCompleted) {
                    showSkuResultsMessage('This pick is completed and read-only.');
                    return false;
                }
                const value = String(code || '').trim();
                if (!value) {
                    showSkuResultsMessage('Enter a SKU to search.');
                    return false;
                }
                let requestedQty = 1;
                if (skuModalQty) {
                    const rawQty = parseInt(String(skuModalQty.value || '').replace(/[^0-9-]/g, ''), 10);
                    requestedQty = Number.isFinite(rawQty) && rawQty > 0 ? rawQty : 1;
                }
                if (skuModalResults) {
                    skuModalResults.innerHTML = `<div class="loading">Loading…</div>`;
                }
                try {
                    const url = buildVerifyActionUrl('sku_lookup', { code: value });
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const ct = res.headers.get('content-type') || '';
                    if (!res.ok) {
                        if (res.status === 404 && !showNotFound) {
                            return false;
                        }
                        showSkuResultsMessage('Lookup failed (server error).');
                        return false;
                    }
                    if (!ct.includes('application/json')) {
                        showSkuResultsMessage('Lookup failed (unexpected response).');
                        return false;
                    }
                    const data = await res.json();
                    if (!data.ok || !data.sku) {
                        if (showNotFound) {
                            showSkuResultsMessage(data.error || 'SKU not found.');
                        }
                        return false;
                    }
                    const skuNum = String(data.sku.sku_num || value).trim();
                    const desc = String(data.sku.desc || '').trim();
                    const shelf = String(data.sku.shelf_code || '').trim();
                    const warehouse = Array.isArray(data.locations)
                        ? data.locations.map((l) => l.location_label).filter(Boolean).join(', ')
                        : '';
                    const inventory = Number(data.total_on_hand ?? data.sku.sku_quantity ?? 0) || 0;
                    if (!pickOrderId) {
                        showSkuResultsMessage('No order loaded.');
                        return false;
                    }
                    const addUrl = buildVerifyActionUrl('add_line');
                    const addRes = await fetch(addUrl.toString(), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            order_id: pickOrderId,
                            sku: skuNum,
                            qty: requestedQty,
                            desc,
                        }),
                    });
                    if (!addRes.ok) {
                        showSkuResultsMessage('Unable to add line (server error).');
                        return false;
                    }
                    const addData = await addRes.json();
                    if (!addData.ok) {
                        showSkuResultsMessage(addData.error || 'Unable to add line.');
                        return false;
                    }
                    addPickRow({
                        pick_item_id: addData.pick_item_id,
                        sku: skuNum,
                        desc: addData.description || desc,
                        shelf,
                        warehouse,
                        qty: requestedQty,
                        inventory,
                    });
                    closeSkuModal();
                    showToast(`Added: ${skuNum}${desc ? ` — ${desc}` : ''}`);
                } catch (e) {
                    console.error('[verify_pick] SKU lookup failed:', e);
                    showSkuResultsMessage('Lookup failed.');
                    return false;
                }
            };

            const looksLikeExactSkuInput = (value) => /^[a-z0-9._\/-]+$/i.test(String(value || '').trim()) && !/\s/.test(String(value || ''));

            const runSkuModalSearch = async () => {
                const value = String(skuModalInput?.value || '').trim();
                if (!value) {
                    showSkuResultsMessage('Enter a SKU or description to search.');
                    return;
                }

                if (looksLikeExactSkuInput(value)) {
                    const matched = await lookupSkuDirect(value, { showNotFound: false });
                    if (matched !== false) {
                        return;
                    }
                }

                await lookupSkuFuzzy(value);
            };

            async function resolveScanToSku(code) {
                const normalized = String(code || '').trim().toUpperCase();
                if (!normalized) return null;
                if (rowBySku.has(normalized)) {
                    return { sku: normalized, multiplier: 1, uom: null };
                }

                const tryResolveWith = async (action, acceptFallbackMultiplier = 1) => {
                    const url = new URL(window.location.href);
                    url.searchParams.set('action', action);
                    url.searchParams.set('code', normalized);
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) {
                        return null;
                    }
                    const data = await res.json();
                    const skuNum = String(data?.sku_num || data?.sku?.sku_num || '').trim().toUpperCase();
                    if (!data?.ok || !skuNum) {
                        return null;
                    }
                    return {
                        sku: skuNum,
                        multiplier: Number(data.multiplier || data?.uom?.multiplier || acceptFallbackMultiplier || 1),
                        uom: data.uom || null,
                    };
                };

                try {
                    const barcodeResolved = await tryResolveWith('barcode_lookup', 1);
                    if (barcodeResolved) {
                        return barcodeResolved;
                    }
                    return await tryResolveWith('sku_lookup', 1);
                } catch (err) {
                    console.error('[verify_pick] barcode lookup failed:', err);
                    return null;
                }
            }

            function applyDeltaToRows(rowsForSku, delta, mode = activeMode) {
                let remaining = Math.max(1, delta);
                const updates = [];
                for (const row of rowsForSku) {
                    if (remaining <= 0) break;
                    const qtyCell = row.querySelector('.qty-cell');
                    const qty = parseInt(qtyCell?.dataset.qty || '0', 10) || 0;
                    const verified = getVerifiedValueForMode(row, mode);
                    const toTarget = Math.max(0, qty - verified);
                    const step = Math.min(remaining, toTarget);
                    if (step <= 0) {
                        continue;
                    }
                    const applied = updateRow(row, step, mode);
                    if (applied > 0) {
                        const itemId = Number(row.dataset.pickItemId || 0);
                        if (itemId > 0) {
                            updates.push({ item_id: itemId, delta: applied });
                        }
                    }
                    remaining -= applied;
                }
                return { applied: Math.max(0, delta - remaining), updates };
            }

            async function persistScanUpdates(updates, mode = activeMode) {
                if (!pickOrderId || !updates || updates.length === 0) return;
                const url = new URL(window.location.href);
                url.searchParams.set('action', 'verify_scan');
                const res = await fetch(url.toString(), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ order_id: pickOrderId, updates, mode }),
                    keepalive: true,
                });
                if (!res.ok) {
                    throw new Error(await readJsonErrorMessage(res, `Unable to save ${mode} verification.`));
                }
                const data = await res.json();
                if (!data?.ok) {
                    throw new Error(typeof data?.error === 'string' && data.error.trim() !== '' ? data.error.trim() : `Unable to save ${mode} verification.`);
                }
                return data;
            }

            async function readJsonErrorMessage(response, fallbackMessage) {
                if (!response) {
                    return fallbackMessage;
                }
                try {
                    const data = await response.clone().json();
                    const message = typeof data?.error === 'string' ? data.error.trim() : '';
                    if (message) {
                        return message;
                    }
                } catch (err) {
                    // Ignore JSON parse failures and fall back to response text.
                }
                try {
                    const text = (await response.text()).trim();
                    if (text) {
                        return text;
                    }
                } catch (err) {
                    // Ignore text read failures and use fallback.
                }
                return fallbackMessage;
            }

            async function handleScan(value) {
                if (scanResultLock) {
                    setScanErrorStatus('Close the scan result to continue scanning.');
                    return;
                }
                if (scanErrorLatchActive) {
                    setScanErrorStatus(scanErrorLatchMessage || 'Wrong item scanned. Tap to continue.');
                    return;
                }
                scanResultOverScanState = false;
                scanResultOverScanMessage = '';
                if (scanResultPanel) {
                    scanResultPanel.classList.remove('is-over-scanned');
                }
                if (isCurrentOrderCompleted) {
                    logVerifyEvent({
                        event_type: 'scan_error',
                        sku_scanned: String(value || '').trim().toUpperCase(),
                        qty_delta: 0,
                        meta: { reason: 'order_completed' },
                    });
                    setScanErrorStatus('This pick is completed and read-only.');
                    return;
                }
                const workflowMode = resolveUiMode();
                if (isWorkflowLocked && activeMode !== workflowMode) {
                    setVerifyMode(workflowMode);
                }
                const code = value.trim().toUpperCase();
                if (!code) return;
                const resolved = await resolveScanToSku(code);
                if (!resolved) {
                    logVerifyEvent({
                        event_type: 'scan_error',
                        sku_scanned: code,
                        qty_delta: 0,
                        meta: { reason: 'sku_not_found' },
                    });
                    latchWrongScan(`Wrong item scanned. ${code} is not part of this order.`);
                    return;
                }
                const sku = resolved.sku;
                const rowsForSku = rowBySku.get(sku);
                if (!rowsForSku || rowsForSku.length === 0) {
                    logVerifyEvent({
                        event_type: 'scan_error',
                        sku_scanned: code,
                        expected_sku: sku,
                        qty_delta: 0,
                        meta: { reason: 'sku_not_in_order' },
                    });
                    latchWrongScan(`Wrong item scanned. ${sku} is not part of this order.`);
                    return;
                }
                const delta = Math.max(1, resolved.multiplier || 1);
                if (sku === lastScannedSku) {
                    consecutiveScanCount += 1;
                } else {
                    lastScannedSku = sku;
                    consecutiveScanCount = 1;
                }
                const remainingQtyForSku = rowsForSku.reduce((sum, row) => {
                    const qtyCell = row.querySelector('.qty-cell');
                    const qty = parseInt(qtyCell?.dataset.qty || '0', 10) || 0;
                    const verified = getVerifiedValueForMode(row, workflowMode);
                    const shortShip = isShortShipRow(row);
                    const storedShortQty = parseInt(row?.dataset.shortShipQty || '0', 10) || 0;
                    const resolvedShortQty = shortShip
                        ? (storedShortQty > 0 ? storedShortQty : Math.max(0, qty - verified))
                        : 0;
                    const expectedQty = workflowMode === 'packer'
                        ? Math.max(0, qty - resolvedShortQty)
                        : qty;
                    return sum + Math.max(0, expectedQty - verified);
                }, 0);
                let requestedQty = delta;
                if (consecutiveScanCount >= 3) {
                    const requested = prompt(`Scanned ${sku} 3 times in a row. Remaining qty (${remainingQtyForSku}). Enter quantity to apply to this SKU:`, String(delta));
                    if (requested === null) {
                        requestedQty = delta;
                    } else {
                        const qtyToApply = parseInt(String(requested), 10);
                        if (!Number.isFinite(qtyToApply) || qtyToApply <= 0) {
                            logVerifyEvent({
                                event_type: 'scan_error',
                                sku_scanned: sku,
                                expected_sku: sku,
                                qty_delta: 0,
                                meta: { reason: 'invalid_quantity', requested: String(requested) },
                            });
                            setScanErrorStatus(`Invalid quantity for ${sku}.`);
                            return;
                        }
                        requestedQty = qtyToApply;
                    }
                    consecutiveScanCount = 0;
                }

                const desc = rowsForSku[0]?.querySelector('.desc-cell')?.textContent?.trim() || '';
                const label = desc ? `${sku} - ${desc}` : sku;
                const modeLabel = workflowMode === 'packer' ? 'Packer' : 'Picker';
                if (workflowMode === 'picker' && hasShortShipLockedRowsForSku(rowsForSku)) {
                    const lockedShortQty = rowsForSku.reduce((sum, row) => {
                        if (!isShortShipRow(row)) {
                            return sum;
                        }
                        const storedShortQty = parseInt(row?.dataset.shortShipQty || '0', 10) || 0;
                        if (storedShortQty > 0) {
                            return sum + storedShortQty;
                        }
                        const qtyCell = row.querySelector('.qty-cell');
                        const qty = parseInt(qtyCell?.dataset.qty || '0', 10) || 0;
                        const verified = getVerifiedValueForMode(row, workflowMode);
                        return sum + Math.max(0, qty - verified);
                    }, 0);
                    const lockQtyText = lockedShortQty > 0 ? ` (${lockedShortQty} unit(s) still missing)` : '';
                    setScanErrorStatus(`${modeLabel}: Remove short ship from ${label} before scanning again${lockQtyText}.`);
                    flashOverScanRows(rowsForSku);
                    flashScanPanel('error');
                    const scanResultData = await fetchScanResultCardData(code, sku, desc);
                    renderScanResultCard(scanResultData, { overScanned: true });
                    flashScanResultOverScan('Short ship locked');
                    return;
                }
                if (requestedQty > remainingQtyForSku) {
                    logVerifyEvent({
                        event_type: 'scan_error',
                        sku_scanned: sku,
                        expected_sku: sku,
                        qty_delta: 0,
                        meta: { reason: 'over_scanned', requested: requestedQty, applied: 0, remaining: remainingQtyForSku },
                    });
                    flashOverScanRows(rowsForSku);
                    flashScanPanel('error');
                    const scanResultData = await fetchScanResultCardData(code, sku, desc);
                    renderScanResultCard(scanResultData, { overScanned: true });
                    flashScanResultOverScan('Overscanned');
                    setScanErrorStatus(`${modeLabel}: Cannot scan more than remaining ${label}. ${remainingQtyForSku} left.`);
                    return;
                }
                const rowSnapshot = snapshotVerifiedValues(rowsForSku, workflowMode);
                const appliedResult = applyDeltaToRows(rowsForSku, requestedQty, workflowMode);
                const applied = appliedResult.applied;
                const updates = appliedResult.updates;
                updateProgress(workflowMode);
                if (applied <= 0) {
                    logVerifyEvent({
                        event_type: 'scan_error',
                        sku_scanned: sku,
                        expected_sku: sku,
                        qty_delta: 0,
                        meta: { reason: 'no_remaining_qty', remaining: remainingQtyForSku },
                    });
                    flashOverScanRows(rowsForSku);
                    flashScanPanel('error');
                    const scanResultData = await fetchScanResultCardData(code, sku, desc);
                    renderScanResultCard(scanResultData, { overScanned: true });
                    flashScanResultOverScan('Overscanned');
                    setScanErrorStatus(`${modeLabel}: ${label} is already fully verified.`);
                    return;
                }

                let persistResult = null;
                try {
                    persistResult = await persistScanUpdates(updates, workflowMode);
                } catch (err) {
                    restoreVerifiedValues(rowSnapshot, workflowMode);
                    updateProgress(workflowMode);
                    setScanErrorStatus(err?.message || `Unable to save ${modeLabel.toLowerCase()} verification.`);
                    return;
                }

                if (delta > 1 && code !== sku) {
                    setScanSuccessStatus(`${modeLabel} scanned ${code} (${label}): +${applied} verified.`);
                } else if (delta > 1) {
                    setScanSuccessStatus(`${modeLabel} scanned ${label}: +${applied} verified.`);
                } else {
                    setScanSuccessStatus(`${modeLabel} scanned ${label}: +1 verified.`);
                }

                const scanResultData = await fetchScanResultCardData(code, sku, desc);
                renderScanResultCard(scanResultData);
                const shortDesc = desc ? desc.split(/\s*PUT-\\d+\\s*/i)[0].trim() : '';
                const toastLabel = shortDesc ? `${sku} - ${shortDesc}` : sku;
                notifyScanSuccess(`${modeLabel}: ${toastLabel}`);
            }

            rows.forEach((row) => {
                bindShortShipCell(row);
                updateRowState(row);
                syncCompletedRowState(row);
            });

            if (addPickLineBtn && tableBody) {
                addPickLineBtn.addEventListener('click', () => {
                    if (isCurrentOrderCompleted) {
                        return;
                    }
                    openSkuModal();
                });
            }
            if (toggleVerifyModeBtn) {
                if (isWorkflowLocked) {
                    toggleVerifyModeBtn.disabled = true;
                    toggleVerifyModeBtn.title = '';
                } else {
                    toggleVerifyModeBtn.disabled = false;
                    toggleVerifyModeBtn.title = 'Switch between picker and packer verification.';
                    toggleVerifyModeBtn.addEventListener('click', () => {
                        setVerifyMode(activeMode === 'picker' ? 'packer' : 'picker');
                    });
                }
            }
            const initialPickerSummary = getVerificationSummary('picker');
            const initialPackerSummary = getVerificationSummary('packer');
            let initialMode = getRenderedWorkflowMode() || 'picker';
            if (requestedWorkflow === 'pack') {
                initialMode = 'packer';
            } else if (requestedWorkflow === 'pick') {
                initialMode = 'picker';
            }
            setVerifyMode(initialMode);

            if (claimCurrentOrderBtn) {
                claimCurrentOrderBtn.addEventListener('click', async () => {
                    if (!pickOrderId) {
                        alert('No order loaded.');
                        return;
                    }
                    claimCurrentOrderBtn.disabled = true;
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('action', 'claim_order');
                        const res = await fetch(url.toString(), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({ order_id: pickOrderId }),
                        });
                        if (!res.ok) {
                            alert('Claim failed.');
                            return;
                        }
                        const data = await res.json();
                        if (!data.ok) {
                            alert(data.error || 'Claim failed.');
                            return;
                        }
                        showToast('Order claimed.');
                        setTimeout(() => window.location.reload(), 250);
                    } catch (err) {
                        alert('Claim failed.');
                    } finally {
                        claimCurrentOrderBtn.disabled = false;
                    }
                });
            }
            if (assignCurrentOrderBtn) {
                assignCurrentOrderBtn.addEventListener('click', () => {
                    if (!pickOrderId) {
                        alert('No order loaded.');
                        return;
                    }
                    if (typeof window.openAssignOrderModal === 'function') {
                        window.openAssignOrderModal(pickOrderId, salesOrderNumber);
                    } else {
                        alert('Assign window is not available.');
                    }
                });
            }
            if (deletePickOrderBtn) {
                deletePickOrderBtn.addEventListener('click', async () => {
                    if (!pickOrderId) {
                        alert('No order loaded.');
                        return;
                    }
                    const reason = window.prompt('Reason for deleting this order?');
                    if (!reason || !reason.trim()) {
                        alert('Deletion reason is required.');
                        return;
                    }
                    const orderLabel = salesOrderNumber.trim() || `#${pickOrderId}`;
                    const ok = window.confirm(`Delete sales order ${orderLabel} from the system? This cannot be undone.`);
                    if (!ok) {
                        return;
                    }
                    deletePickOrderBtn.disabled = true;
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('action', 'delete_pick_order');
                        const res = await fetch(url.toString(), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({
                                order_id: pickOrderId,
                                reason: reason.trim(),
                            }),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || !data?.ok) {
                            alert(data?.error || 'Unable to delete order.');
                            return;
                        }
                        showToast('Order deleted.');
                        window.location.assign(data.redirect || 'verify_pick.php');
                    } catch (err) {
                        alert('Unable to delete order.');
                    } finally {
                        deletePickOrderBtn.disabled = false;
                    }
                });
            }
            if (returnToPickBtn) {
                returnToPickBtn.addEventListener('click', async () => {
                    if (!pickOrderId) {
                        alert('No order loaded.');
                        return;
                    }
                    const orderLabel = salesOrderNumber.trim() || `#${pickOrderId}`;
                    const ok = confirm(`Send sales order ${orderLabel} back to pick? This will keep picker verification and clear packer verification for the order.`);
                    if (!ok) {
                        return;
                    }
                    returnToPickBtn.disabled = true;
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('action', 'return_to_pick');
                        const res = await fetch(url.toString(), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({ order_id: pickOrderId }),
                        });
                        if (!res.ok) {
                            alert('Unable to return order to pick.');
                            return;
                        }
                        const data = await res.json();
                        if (!data?.ok) {
                            alert(data?.error || 'Unable to return order to pick.');
                            return;
                        }
                        showToast('Order returned to pick queue.');
                        window.location.assign(data.redirect || 'verify_pick.php');
                    } catch (err) {
                        alert('Unable to return order to pick.');
                    } finally {
                        returnToPickBtn.disabled = false;
                        syncReturnToPickButton();
                    }
                });
            }
            syncCloseOrderButton();
            syncReturnToPickButton();
            if (closeCurrentOrderBtn) {
                closeCurrentOrderBtn.addEventListener('click', async () => {
                    if (!pickOrderId) {
                        alert('No order loaded.');
                        return;
                    }
                    closeCurrentOrderBtn.disabled = true;
                    try {
                        const orderLabel = salesOrderNumber.trim() || `#${pickOrderId}`;
                        const pickerSummary = getVerificationSummary('picker');
                        const packerSummary = hasPackerVerify ? getVerificationSummary('packer') : pickerSummary;
                        const pickerCanCloseWithShortages = canCloseWithShortages(pickerSummary);
                        const packerCanCloseWithShortages = canCloseWithShortages(packerSummary);
                        if (isWorkflowLocked && !isCurrentOrderCompleted) {
                            const workflowMode = getLockedWorkflowMode();
                            if (workflowMode === 'picker') {
                                if (!pickerSummary.isComplete && !pickerCanCloseWithShortages) {
                                    setScanErrorStatus(`Picker still has ${pickerSummary.remaining} item(s) left to scan.`);
                                    return;
                                }
                                const closeWithShortages = !pickerSummary.isComplete && pickerCanCloseWithShortages;
                                const completeOk = confirm(closeWithShortages
                                    ? `This sales order has short-shipped lines. Close picker verification and move it to packing anyway?`
                                    : `Complete picker verification for sales order ${orderLabel}? This will mark it ready for packing.`);
                                if (!completeOk) {
                                    return;
                                }
                                await submitOrderStatus(
                                    'READY_TO_PACK',
                                    'complete pick',
                                    closeWithShortages
                                        ? 'Pick closed with accepted shortages. Ready for pack verification.'
                                        : 'Pick completed. Ready for pack verification.'
                                );
                                window.location.assign('verify_pick.php');
                                return;
                            }
                            if (!pickerSummary.isComplete && !pickerCanCloseWithShortages) {
                                setScanErrorStatus('Picker verification must be completed before packing can begin.');
                                return;
                            }
                            if (!packCompletionEnabled) {
                                alert('Complete Pack is temporarily disabled.');
                                return;
                            }
                            if (!packerSummary.isComplete && !packerCanCloseWithShortages) {
                                setScanErrorStatus(`Packer still has ${packerSummary.remaining} item(s) left to scan.`);
                                return;
                            }
                            const closeWithShortages = !packerSummary.isComplete && packerCanCloseWithShortages;
                            const completeOk = confirm('Complete order?');
                            if (!completeOk) {
                                return;
                            }
                            await submitOrderStatus(
                                'COMPLETED',
                                'complete pack',
                                closeWithShortages
                                    ? 'Order completed with accepted shortages.'
                                    : 'Order completed.'
                            );
                            promptPackingManagerRedirect();
                            return;
                        }
                        if (isCurrentOrderCompleted) {
                            const reopenMode = activeMode === 'packer'
                                ? 'packer'
                                : (getRenderedWorkflowMode() || 'picker');
                            const reopenLabel = reopenMode === 'packer' ? 'pack verification' : 'pick';
                            const reopenOk = confirm(`Re-open ${reopenLabel} for sales order ${orderLabel}?`);
                            if (!reopenOk) {
                                return;
                            }
                            if (reopenMode === 'packer') {
                                await submitOrderStatus(
                                    'READY_TO_PACK',
                                    'open',
                                    'Pack opened.',
                                    'packer',
                                );
                                return;
                            }
                            const reopenResult = await submitOrderStatus(
                                'IN_PROGRESS',
                                'open',
                                reopenMode === 'packer' ? 'Pack opened.' : 'Pick opened.',
                                reopenMode,
                            );
                        } else {
                            const closeWithShortages = canCloseWithShortages(pickerSummary);
                            const closeAsReadyToPack = hasPackerVerify;
                            const closePrompt = closeWithShortages
                                ? `This sales order has short-shipped lines. Close it and move it to packing anyway?`
                                : (closeAsReadyToPack
                                    ? `Complete picker verification for sales order ${orderLabel}? This will mark it ready for packing.`
                                    : `Close sales order ${orderLabel}?`);
                            const closeOk = confirm(closePrompt);
                            if (!closeOk) {
                                return;
                            }
                            if (closeAsReadyToPack) {
                                await submitOrderStatus(
                                    'READY_TO_PACK',
                                    'close',
                                    closeWithShortages
                                        ? 'Pick closed with accepted shortages. Ready for pack verification.'
                                        : 'Pick completed. Ready for pack verification.'
                                );
                                window.location.assign('verify_pick.php');
                            } else {
                                await submitOrderStatus(
                                    'READY_TO_PACK',
                                    'close',
                                    closeWithShortages
                                        ? 'Pick closed with accepted shortages. Ready for pack verification.'
                                        : 'Pick completed. Ready for pack verification.'
                                );
                            }
                        }
                        window.setTimeout(() => window.location.reload(), 250);
                    } catch (err) {
                        alert(err?.message || (isCurrentOrderCompleted ? 'Unable to re-open pick.' : 'Unable to close pick.'));
                    } finally {
                        closeCurrentOrderBtn.disabled = false;
                        syncCloseOrderButton();
                    }
                });
            }

            if (completeStageBtn) {
                completeStageBtn.addEventListener('click', async () => {
                    await handleCompleteVerifyAction(completeStageBtn);
                });
            }

            if (saveVerifiedListBtn) {
                saveVerifiedListBtn.addEventListener('click', () => {
                    if (!pickOrderId) {
                        alert('No order loaded.');
                        return;
                    }
                    const pickerSummary = getVerificationSummary('picker');
                    if (!pickerSummary.isComplete) {
                        setScanErrorStatus(`Picker still has ${pickerSummary.remaining} item(s) left to scan.`);
                        return;
                    }
                    const url = buildVerifyActionUrl('download_verified_list', { order_id: pickOrderId });
                    window.location.assign(url.toString());
                });
            }

            if (resetVerifiedBtn) {
                resetVerifiedBtn.addEventListener('click', async () => {
                    if (isCurrentOrderCompleted) {
                        return;
                    }
                    if (!pickOrderId) {
                        alert('No order loaded.');
                        return;
                    }
                    const selected = Array.from(document.querySelectorAll('.delete-check:checked'));
                    const selectedRows = selected
                        .map((cb) => cb.closest('tr'))
                        .filter((row) => !!row);
                    const selectedIds = selectedRows
                        .map((row) => Number(row.dataset.pickItemId || 0))
                        .filter((id) => id > 0);
                    const isPartialReset = selectedRows.length > 0;
                    const modeLabel = activeMode === 'packer' ? 'packer' : 'picker';
                    const ok = isPartialReset
                        ? confirm(`Reset ${modeLabel} verified quantity for ${selectedRows.length} selected line(s)?`)
                        : confirm(`Reset all ${modeLabel} verified quantities for this sales order?`);
                    if (!ok) return;
                    resetVerifiedBtn.disabled = true;
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('action', 'reset_verified');
                        const resetPayload = isPartialReset
                            ? { order_id: pickOrderId, item_ids: selectedIds, mode: activeMode }
                            : { order_id: pickOrderId, mode: activeMode };
                        const res = await fetch(url.toString(), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify(resetPayload),
                        });
                        if (!res.ok) {
                            alert(await readJsonErrorMessage(res, 'Reset failed.'));
                            return;
                        }
                        const data = await res.json();
                        if (!data.ok) {
                            alert(data.error || 'Reset failed.');
                            return;
                        }
                        const rowsToReset = isPartialReset
                            ? selectedRows
                            : (tableBody ? Array.from(tableBody.querySelectorAll('tr')) : rows);
                        rowsToReset.forEach((row) => {
                            const qtyCell = row.querySelector('.qty-cell');
                            const qty = parseInt(qtyCell?.dataset.qty || '0', 10) || 0;
                            setVerifiedCellValue(row, activeMode, 0, qty);
                            const deleteCheck = row.querySelector('.delete-check');
                            if (deleteCheck) {
                                deleteCheck.checked = false;
                            }
                            updateRowState(row);
                        });
                        lastScannedSku = '';
                        consecutiveScanCount = 0;
                        updateProgress(activeMode);
                        if (scanStatus) {
                            scanStatus.textContent = isPartialReset
                                ? `${modeLabel} verified quantity reset for ${rowsToReset.length} line(s).`
                                : `All ${modeLabel} verified quantities reset to 0.`;
                        }
                        showToast(isPartialReset ? `Selected ${modeLabel} lines reset.` : `${modeLabel} quantities reset.`);
                    } catch (err) {
                        alert('Reset failed.');
                    } finally {
                        resetVerifiedBtn.disabled = false;
                    }
                });
            }

            const setDeleteMode = (next) => {
                if (isCurrentOrderCompleted) {
                    next = false;
                }
                deleteMode = !!next;
                if (table) {
                    table.classList.toggle('delete-mode', deleteMode);
                }
                if (toggleDeleteModeBtn) {
                    toggleDeleteModeBtn.textContent = deleteMode ? 'Cancel' : 'Edit';
                }
                if (deleteSelectedBtn) {
                    deleteSelectedBtn.disabled = !deleteMode;
                }
                if (!deleteMode) {
                    document.querySelectorAll('.delete-check').forEach((cb) => {
                        cb.checked = false;
                    });
                }
            };

            if (toggleDeleteModeBtn) {
                toggleDeleteModeBtn.addEventListener('click', () => {
                    if (isCurrentOrderCompleted) {
                        return;
                    }
                    setDeleteMode(!deleteMode);
                });
            }

            if (deleteSelectedBtn) {
                deleteSelectedBtn.addEventListener('click', async () => {
                    if (isCurrentOrderCompleted) return;
                    if (!deleteMode) return;
                    const selected = Array.from(document.querySelectorAll('.delete-check:checked'));
                    if (selected.length === 0) {
                        alert('Select at least one line to delete.');
                        return;
                    }
                    const ok = confirm(`Delete ${selected.length} line(s)? This cannot be undone.`);
                    if (!ok) return;
                    const ids = [];
                    const toRemove = [];
                    selected.forEach((cb) => {
                        const row = cb.closest('tr');
                        if (!row) return;
                        const id = Number(row.dataset.pickItemId || 0);
                        if (id > 0) {
                            ids.push(id);
                        }
                        toRemove.push(row);
                    });
                    if (ids.length > 0) {
                        try {
                            const delUrl = new URL(window.location.href);
                            delUrl.searchParams.set('action', 'delete_lines');
                            const delRes = await fetch(delUrl.toString(), {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                                body: JSON.stringify({ order_id: pickOrderId, item_ids: ids }),
                            });
                            if (!delRes.ok) {
                                alert('Delete failed (server error).');
                                return;
                            }
                            const delData = await delRes.json();
                            if (!delData.ok) {
                                alert(delData.error || 'Delete failed.');
                                return;
                            }
                        } catch (err) {
                            alert('Delete failed.');
                            return;
                        }
                    }
                    toRemove.forEach((row) => {
                        if (row.parentElement) {
                            row.parentElement.removeChild(row);
                        }
                    });
                    rebuildRowIndex();
                    updateProgress();
                    setDeleteMode(false);
                });
            }
            syncCompletedPickState();

            const submitScanInput = () => {
                if (!scanInput) return;
                if (scanResultLock) {
                    setScanErrorStatus('Close the scan result to continue scanning.');
                    return;
                }
                if (scanErrorLatchActive) {
                    setScanErrorStatus(scanErrorLatchMessage || 'Wrong item scanned. Tap to continue.');
                    return;
                }
                clearKeyboardScanBuffer();
                const nextValue = scanInput.value;
                handleScan(nextValue);
                scanInput.value = '';
            };

            if (scanInputForm) {
                scanInputForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    if (isCurrentOrderCompleted) {
                        return;
                    }
                    submitScanInput();
                });
            }

            if (scanInput) {
                scanInput.addEventListener('keydown', (e) => {
                    if (isCurrentOrderCompleted) {
                        e.preventDefault();
                        return;
                    }
                    if (e.key === 'Enter' || e.key === 'Tab') {
                        e.preventDefault();
                        submitScanInput();
                    }
                });
            }

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && skuBarcodeModal && skuBarcodeModal.classList.contains('is-open')) {
                    e.preventDefault();
                    closeSkuBarcodeModal();
                    return;
                }
                const barcodeTrigger = e.target instanceof HTMLElement
                    ? e.target.closest('.sku-barcode-trigger, [data-sku-barcode-trigger="1"]')
                    : null;
                if (barcodeTrigger && (e.key === 'Enter' || e.key === ' ' || e.code === 'Space')) {
                    e.preventDefault();
                    const row = barcodeTrigger.closest('tr');
                    const sku = String(row?.dataset.sku || barcodeTrigger.getAttribute('data-sku') || row?.querySelector('.sku-cell')?.textContent || '').trim();
                    void openSkuBarcodeModal(sku);
                    return;
                }
                if (isGlobalScanBlocked()) {
                    clearKeyboardScanBuffer();
                    return;
                }
                if (e.ctrlKey || e.altKey || e.metaKey) {
                    return;
                }

                const target = e.target instanceof HTMLElement ? e.target : null;
                const targetIsEditable = isEditableElement(target);
                if (targetIsEditable) {
                    clearKeyboardScanBuffer();
                    return;
                }

                if (e.key === 'Enter' || e.key === 'Tab') {
                    if (keyboardScanBuffer !== '') {
                        e.preventDefault();
                        submitKeyboardScanBuffer();
                    }
                    return;
                }

                if (e.key === 'Escape') {
                    clearKeyboardScanBuffer();
                    syncScanInputValue('');
                    return;
                }

                if (e.key.length !== 1) {
                    return;
                }

                keyboardScanBuffer += e.key;
                syncScanInputValue(keyboardScanBuffer);
                queueKeyboardScanReset();
            }, true);

            if (skuModal) {
                skuModal.addEventListener('click', (e) => {
                    if (e.target && e.target.getAttribute('data-close') === '1') {
                        closeSkuModal();
                    }
                });
            }
            if (skuModalClose) {
                skuModalClose.addEventListener('click', closeSkuModal);
            }
            if (skuModalSearch && skuModalInput) {
                skuModalSearch.addEventListener('click', () => {
                    void runSkuModalSearch();
                });
            }
            document.addEventListener('click', (e) => {
                const trigger = e.target instanceof HTMLElement
                    ? e.target.closest('.sku-barcode-trigger, [data-sku-barcode-trigger="1"]')
                    : null;
                if (!trigger) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                const row = trigger.closest('tr');
                const sku = String(row?.dataset.sku || row?.querySelector('.sku-cell')?.textContent || '').trim();
                void openSkuBarcodeModal(sku);
            }, true);
            if (skuBarcodeModal) {
                skuBarcodeModal.addEventListener('click', (e) => {
                    if (e.target && e.target.getAttribute('data-close') === '1') {
                        closeSkuBarcodeModal();
                    }
                });
            }
            if (skuBarcodeModalClose) {
                skuBarcodeModalClose.addEventListener('click', closeSkuBarcodeModal);
            }
            if (skuBarcodeModalPrint) {
                skuBarcodeModalPrint.addEventListener('click', printSkuBarcode);
            }
            if (skuModalInput) {
                skuModalInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        void runSkuModalSearch();
                    }
                });
                skuModalInput.addEventListener('input', () => {
                    const q = skuModalInput.value.trim();
                    if (!q) {
                        if (skuModalResults) skuModalResults.innerHTML = '';
                        clearTimeout(skuSearchTimer);
                        return;
                    }
                    clearTimeout(skuSearchTimer);
                    skuSearchTimer = setTimeout(() => {
                        lookupSkuFuzzy(q);
                    }, 300);
                });
            }
            const printPickBtn = document.getElementById('printPickBtn');
            if (printPickBtn) {
                printPickBtn.addEventListener('click', () => {
                    window.print();
                });
            }

            if (shipInfoClearBtn) {
                shipInfoClearBtn.addEventListener('click', () => {
                    clearShipInfoValue();
                });
            }
            if (shipInfoInput) {
                shipInfoInput.addEventListener('input', () => {
                    currentShipInfoValue = normalizeShipInfoText(shipInfoInput.value);
                });
                shipInfoInput.addEventListener('paste', (ev) => {
                    const pasted = ev.clipboardData?.getData('text/plain') || '';
                    if (!pasted) {
                        return;
                    }
                    ev.preventDefault();
                    shipInfoInput.value = pasted;
                    currentShipInfoValue = normalizeShipInfoText(pasted);
                });
                const submitShipInfoValue = () => {
                    saveShipInfoValue();
                    closeShipInfoModal();
                };
                shipInfoInput.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        submitShipInfoValue();
                        return;
                    }
                    if (ev.key === 'Escape') {
                        ev.preventDefault();
                        closeShipInfoModal();
                    }
                });
                shipInfoInput.addEventListener('keyup', (ev) => {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        submitShipInfoValue();
                    }
                });
            }

            // -----------------------------------------------------------------
            // Box Label Printing: set up modal interactions. When the user
            // clicks the "Packing Manager" button, show a modal to collect
            // Sales Order #, Box ID, Dimensions and Weight, then open a
            // new window with the box_label_print action for printing.
            const boxLabelModal = document.getElementById('boxLabelModal');
            const boxLabelSO = document.getElementById('boxLabelSO');
            const boxLabelCount = document.getElementById('boxLabelCount');
            const boxLabelSequenceNote = document.getElementById('boxLabelSequenceNote');
            const boxLabelModalLayout = document.querySelector('#boxLabelModal .box-label-modal-layout');
            const boxLabelRows = document.getElementById('boxLabelRows');
            const boxLabelItemPane = document.getElementById('boxLabelItemPane');
            const boxLabelItemPaneNote = document.getElementById('boxLabelItemPaneNote');
            const boxLabelItemList = document.getElementById('boxLabelItemList');
            const boxLabelFinalizeBtn = document.getElementById('boxLabelFinalizeBtn');
            const boxLabelManifestBtn = document.getElementById('boxLabelManifestBtn');
            const boxLabelManifestPrintBtn = document.getElementById('boxLabelManifestPrintBtn');
            const boxLabelManagerActions = document.getElementById('boxLabelManagerActions');
            const packingSummaryActions = document.getElementById('packingSummaryActions');
            const boxLabelPrintLabelsBtn = document.getElementById('boxLabelPrintLabelsBtn');
            const boxLabelPrintSummaryBtn = document.getElementById('boxLabelPrintSummaryBtn');
            const packingSummaryPanel = document.getElementById('packingSummaryPanel');
            const boxLabelClose = document.getElementById('boxLabelCloseBtn');
            const boxLabelCancel = document.getElementById('boxLabelCancelBtn');
            const pickContentElem = document.getElementById('pickContent');
            const soDefaultVal = pickContentElem ? (pickContentElem.dataset.salesOrderNumber || '') : '';
            const warehouseBoxTypes = <?= json_encode($boxTypeCatalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const shipInfoDefaultValue = <?= json_encode($shipInfoPreviewText, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            let currentShipInfoValue = normalizeShipInfoText(shipInfoDefaultValue);
            let packItems = [];
            // Track per-box quantities for each verified line so one line can be split across boxes.
            let packItemAllocations = new Map();
            let packItemPaneOpen = false;
            let packFastMode = false;
            let packFastModeTouched = false;
            let activePackBoxIdentifier = '';
            let packExpandedItemKey = '';
            let packingFinalized = false;
            let packingManifestToken = '';
            let packingManifestData = null;
            const PACK_FAST_MODE_THRESHOLD = 24;

            function getShipInfoStorageKey() {
                const orderPart = pickOrderId > 0 ? `id:${pickOrderId}` : `so:${salesOrderNumber || 'unknown'}`;
                return `verify_pick_ship_info:${orderPart}`;
            }

            function normalizeShipInfoText(value) {
                return String(value || '').replace(/\r\n?/g, '\n').trim();
            }

            function renderShipInfoPreview() {
                if (!shipInfoPreview) return;
                if (!currentShipInfoValue) {
                    shipInfoPreview.classList.add('is-empty');
                    shipInfoPreview.textContent = 'No ship info saved for this order.';
                    return;
                }
                shipInfoPreview.classList.remove('is-empty');
                shipInfoPreview.textContent = currentShipInfoValue;
            }

            function loadShipInfoValue() {
                const defaultValue = normalizeShipInfoText(shipInfoDefaultValue);
                try {
                    const storedValue = window.sessionStorage.getItem(getShipInfoStorageKey());
                    currentShipInfoValue = storedValue !== null && storedValue !== ''
                        ? normalizeShipInfoText(storedValue)
                        : defaultValue;
                } catch (err) {
                    currentShipInfoValue = defaultValue;
                }
                if (shipInfoInput) {
                    shipInfoInput.value = currentShipInfoValue;
                }
                renderShipInfoPreview();
            }

            function saveShipInfoValue() {
                currentShipInfoValue = normalizeShipInfoText(shipInfoInput?.value || '');
                try {
                    if (currentShipInfoValue) {
                        window.sessionStorage.setItem(getShipInfoStorageKey(), currentShipInfoValue);
                    } else {
                        window.sessionStorage.removeItem(getShipInfoStorageKey());
                    }
                } catch (err) {
                    // sessionStorage can be unavailable; keep the in-memory value.
                }
                renderShipInfoPreview();
            }

            function openShipInfoModal() {
                loadShipInfoValue();
                if (shipInfoInputRow) {
                    shipInfoInputRow.classList.remove('hidden');
                }
                window.requestAnimationFrame(() => shipInfoInput?.focus());
                if (shipInfoInput?.select) {
                    shipInfoInput.select();
                }
            }

            function closeShipInfoModal() {
                if (shipInfoInputRow) {
                    shipInfoInputRow.classList.add('hidden');
                }
            }

            function clearShipInfoValue() {
                currentShipInfoValue = normalizeShipInfoText(shipInfoDefaultValue);
                try {
                    window.sessionStorage.removeItem(getShipInfoStorageKey());
                } catch (err) {
                    // Ignore storage errors.
                }
                if (shipInfoInput) {
                    shipInfoInput.value = '';
                }
                renderShipInfoPreview();
            }

            window.toggleShipInfoInput = () => {
                if (!shipInfoInputRow) return;
                if (shipInfoInputRow.classList.contains('hidden')) {
                    openShipInfoModal();
                } else {
                    closeShipInfoModal();
                }
            };

            // Initialize the preview with the lookup value, if one exists.
            loadShipInfoValue();

            const normalizeBoxLabelCount = () => {
                const rawCount = boxLabelCount ? Number.parseInt(boxLabelCount.value, 10) : 1;
                if (!Number.isFinite(rawCount) || rawCount < 1) return 1;
                return Math.min(rawCount, 50);
            };

            const buildGeneratedBoxIdentifiers = (count) => {
                const safeCount = Number.isFinite(count) && count > 0 ? Math.min(count, 50) : 1;
                return Array.from({ length: safeCount }, (_, index) => `${index + 1}/${safeCount}`);
            };

            const formatPackBoxLabel = (boxIdentifier) => {
                const raw = String(boxIdentifier || '').trim();
                const match = raw.match(/^(\d+)(?:\/\d+)?$/);
                if (match) {
                    return `Box ${match[1]}`;
                }
                const numericPrefix = raw.match(/^(\d+)\//);
                if (numericPrefix) {
                    return `Box ${numericPrefix[1]}`;
                }
                return raw ? `Box ${raw}` : 'Box';
            };

            const formatPackWeightLabel = (weightLabel) => {
                const raw = String(weightLabel || '').trim();
                if (!raw) {
                    return '';
                }
                if (/\b(?:lb|lbs)\.?\s*$/i.test(raw)) {
                    return raw;
                }
                return `${raw} lbs`;
            };

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

            const buildPackItemKey = (row, index, sku) => {
                const pickItemId = Number(row?.dataset.pickItemId || 0);
                if (pickItemId > 0) {
                    return `pick:${pickItemId}`;
                }
                return `row:${index}:${String(sku || '').trim().toUpperCase()}`;
            };

            const getPackItemByKey = (itemKey) => {
                const key = String(itemKey || '').trim();
                if (!key) {
                    return null;
                }
                return packItems.find((item) => String(item.key || '') === key) || null;
            };

            const getPackItemAllocationMap = (itemKey) => {
                const key = String(itemKey || '').trim();
                if (!key) {
                    return new Map();
                }
                const current = packItemAllocations.get(key);
                if (current instanceof Map) {
                    return current;
                }
                const next = new Map();
                if (current && typeof current === 'object' && !Array.isArray(current)) {
                    Object.entries(current).forEach(([boxIdentifier, qty]) => {
                        const box = String(boxIdentifier || '').trim();
                        const parsedQty = Number.parseInt(String(qty ?? '0'), 10) || 0;
                        if (box && parsedQty > 0) {
                            next.set(box, parsedQty);
                        }
                    });
                }
                if (next.size > 0) {
                    packItemAllocations.set(key, next);
                }
                return next;
            };

            const getPackItemAllocationEntries = (itemKey) => Array.from(getPackItemAllocationMap(itemKey).entries())
                .filter(([boxIdentifier, qty]) => String(boxIdentifier || '').trim() !== '' && Number.isFinite(qty) && qty > 0);

            const getTotalAllocatedQty = (itemKey) => getPackItemAllocationEntries(itemKey)
                .reduce((sum, [, qty]) => sum + qty, 0);

            const getAllocatedQtyForBox = (itemKey, boxIdentifier) => {
                const key = String(itemKey || '').trim();
                const box = String(boxIdentifier || '').trim();
                if (!key || !box) {
                    return 0;
                }
                return Number.parseInt(String(getPackItemAllocationMap(key).get(box) ?? 0), 10) || 0;
            };

            const getRemainingPackQty = (itemKey) => {
                const item = getPackItemByKey(itemKey);
                if (!item) {
                    return 0;
                }
                return Math.max(0, Number(item.qty || 0) - getTotalAllocatedQty(itemKey));
            };

            const normalizePackItemAllocations = () => {
                const validBoxes = new Set(getCurrentBoxIdentifiers());
                const validKeys = new Set(packItems.map((item) => String(item.key || '').trim()).filter((value) => value !== ''));
                for (const [itemKey, allocationMap] of packItemAllocations.entries()) {
                    if (!validKeys.has(itemKey)) {
                        packItemAllocations.delete(itemKey);
                        continue;
                    }
                    const nextMap = allocationMap instanceof Map ? allocationMap : getPackItemAllocationMap(itemKey);
                    for (const [boxIdentifier, qty] of Array.from(nextMap.entries())) {
                        const box = String(boxIdentifier || '').trim();
                        const parsedQty = Number.parseInt(String(qty ?? '0'), 10) || 0;
                        if (!box || !validBoxes.has(box) || parsedQty <= 0) {
                            nextMap.delete(boxIdentifier);
                        } else {
                            nextMap.set(box, parsedQty);
                        }
                    }
                    if (nextMap.size === 0) {
                        packItemAllocations.delete(itemKey);
                    } else {
                        packItemAllocations.set(itemKey, nextMap);
                    }
                }
            };

            const buildPackItemsFromOrder = () => {
                const sourceRows = getDistinctOrderRows();
                return sourceRows.map((row, index) => {
                    const sku = String(row.querySelector('.sku-cell')?.textContent || '').trim();
                    const desc = String(row.querySelector('.desc-cell')?.textContent || '').trim();
                    const qty = Number.parseInt(row.querySelector('.qty-cell')?.dataset.qty || row.querySelector('.qty-cell')?.textContent || '0', 10) || 0;
                    const verifiedPicker = Number.parseInt(row.querySelector('.verified-picker-cell')?.dataset.verified || '0', 10) || 0;
                    const verifiedPacker = Number.parseInt(row.querySelector('.verified-packer-cell')?.dataset.verified || '0', 10) || 0;
                    const verifiedQty = Math.max(verifiedPicker, verifiedPacker, 0);
                    if (sku === '' || verifiedQty <= 0) {
                        return null;
                    }
                    const key = buildPackItemKey(row, index, sku);
                    return {
                        key,
                        pick_item_id: Number(row.dataset.pickItemId || 0),
                        sku,
                        description: desc,
                        qty: verifiedQty,
                        source_qty: qty,
                        verified_picker: verifiedPicker,
                        verified_packer: verifiedPacker,
                    };
                }).filter(Boolean);
            };

            const getCurrentBoxIdentifiers = () => {
                if (!boxLabelRows) {
                    return [];
                }
                return Array.from(boxLabelRows.querySelectorAll('[data-box-identifier]'))
                    .map((row) => String(row.getAttribute('data-box-identifier') || '').trim())
                    .filter((value) => value !== '');
            };

            const getAssignedItemsForBox = (boxIdentifier) => {
                if (!boxIdentifier) {
                    return [];
                }
                return packItems.flatMap((item) => {
                    const qty = getAllocatedQtyForBox(item.key, boxIdentifier);
                    if (qty <= 0) {
                        return [];
                    }
                    return [{
                        key: item.key,
                        pick_item_id: item.pick_item_id,
                        sku: item.sku,
                        description: item.description,
                        qty,
                        source_qty: item.qty,
                        verified_picker: item.verified_picker,
                        verified_packer: item.verified_packer,
                    }];
                });
            };

            const getPackItemLabel = (item) => {
                const parts = [String(item?.sku || '').trim()];
                const desc = String(item?.description || '').trim();
                if (desc !== '') {
                    parts.push(desc);
                }
                return parts.filter((value) => value !== '').join(' - ');
            };

            const getPrimaryPackItemBoxIdentifier = (itemKey) => {
                const allocationEntries = getPackItemAllocationEntries(itemKey);
                return allocationEntries.length > 0 ? String(allocationEntries[0][0] || '').trim() : '';
            };

            const setPackItemAllocation = (itemKey, boxIdentifier, qty) => {
                const key = String(itemKey || '').trim();
                if (!key) {
                    return 0;
                }
                const box = String(boxIdentifier || '').trim();
                const item = getPackItemByKey(key);
                if (!item || !box) {
                    return 0;
                }
                const parsedQty = Number.parseInt(String(qty ?? '0'), 10) || 0;
                const allocationMap = getPackItemAllocationMap(key);
                const currentQty = Number.parseInt(String(allocationMap.get(box) ?? 0), 10) || 0;
                const otherQty = Math.max(0, getTotalAllocatedQty(key) - currentQty);
                const maxQty = Math.max(0, Number(item.qty || 0) - otherQty);
                const nextQty = Math.max(0, Math.min(parsedQty, maxQty));
                if (nextQty <= 0) {
                    allocationMap.delete(box);
                } else {
                    allocationMap.set(box, nextQty);
                }
                if (allocationMap.size === 0) {
                    packItemAllocations.delete(key);
                } else {
                    packItemAllocations.set(key, allocationMap);
                }
                return nextQty;
            };

            const assignPackItemsToBox = (boxIdentifier, itemKeys = null) => {
                const nextBox = String(boxIdentifier || '').trim();
                if (!nextBox) {
                    return;
                }
                const keys = Array.isArray(itemKeys) && itemKeys.length > 0
                    ? itemKeys.map((key) => String(key || '').trim()).filter((key) => key !== '')
                    : packItems.map((item) => String(item.key || '').trim()).filter((key) => key !== '');
                keys.forEach((key) => {
                    const item = getPackItemByKey(key);
                    if (!item || getTotalAllocatedQty(key) > 0) {
                        return;
                    }
                    setPackItemAllocation(key, nextBox, item.qty);
                });
                activePackBoxIdentifier = nextBox;
            };

            const clearPackItemsForBox = (boxIdentifier) => {
                const targetBox = String(boxIdentifier || '').trim();
                if (!targetBox) {
                    return;
                }
                for (const [itemKey, allocationMap] of packItemAllocations.entries()) {
                    if (allocationMap instanceof Map && allocationMap.has(targetBox)) {
                        allocationMap.delete(targetBox);
                        if (allocationMap.size === 0) {
                            packItemAllocations.delete(itemKey);
                        } else {
                            packItemAllocations.set(itemKey, allocationMap);
                        }
                    }
                }
            };

            const setActivePackBox = (boxIdentifier, openItemsPane = false) => {
                const nextBox = String(boxIdentifier || '').trim();
                activePackBoxIdentifier = nextBox;
                if (openItemsPane && nextBox) {
                    openPackItemPane(nextBox);
                    return;
                }
                renderBoxLabelRows();
                if (packItemPaneOpen) {
                    renderPackItemPane();
                }
            };

            const getPackItemAssignmentState = (itemKey, activeLabel) => {
                const totalAllocated = getTotalAllocatedQty(itemKey);
                if (totalAllocated <= 0) {
                    return 'unassigned';
                }
                const activeAllocated = getAllocatedQtyForBox(itemKey, activeLabel);
                const item = getPackItemByKey(itemKey);
                if (activeAllocated > 0 && item && totalAllocated >= Number(item.qty || 0)) {
                    return 'selected';
                }
                if (activeAllocated > 0) {
                    return 'partial';
                }
                return 'other';
            };

            const syncPackItemAssignments = () => {
                const validBoxes = new Set(getCurrentBoxIdentifiers());
                for (const [itemKey, allocationMap] of packItemAllocations.entries()) {
                    if (!(allocationMap instanceof Map)) {
                        packItemAllocations.delete(itemKey);
                        continue;
                    }
                    for (const [boxIdentifier, qty] of Array.from(allocationMap.entries())) {
                        const parsedQty = Number.parseInt(String(qty ?? '0'), 10) || 0;
                        if (!boxIdentifier || !validBoxes.has(boxIdentifier) || parsedQty <= 0) {
                            allocationMap.delete(boxIdentifier);
                        }
                    }
                    if (allocationMap.size === 0) {
                        packItemAllocations.delete(itemKey);
                    }
                }
            };

            const renderPackItemPane = () => {
                if (!boxLabelItemPane || !boxLabelItemList) {
                    return;
                }
                if (packingFinalized) {
                    boxLabelItemPane.hidden = true;
                    boxLabelItemPane.setAttribute('aria-hidden', 'true');
                    if (boxLabelModalLayout) {
                        boxLabelModalLayout.classList.remove('has-items-pane');
                    }
                    boxLabelItemList.innerHTML = '';
                    return;
                }
                if (!packItemPaneOpen) {
                    boxLabelItemPane.hidden = true;
                    boxLabelItemPane.setAttribute('aria-hidden', 'true');
                    if (boxLabelModalLayout) {
                        boxLabelModalLayout.classList.remove('has-items-pane');
                    }
                    return;
                }

                const boxIdentifiers = getCurrentBoxIdentifiers();
                const activeLabel = activePackBoxIdentifier || boxIdentifiers[0] || '';
                if (boxLabelModalLayout) {
                    boxLabelModalLayout.classList.add('has-items-pane');
                }
                boxLabelItemPane.hidden = false;
                boxLabelItemPane.setAttribute('aria-hidden', 'false');
                if (!packFastModeTouched && packItems.length >= PACK_FAST_MODE_THRESHOLD) {
                    packFastMode = true;
                }
                if (boxLabelItemPaneNote) {
                    if (packFastMode) {
                        boxLabelItemPaneNote.textContent = 'Showing all items. Tap an item to expand it and allocate part or all of its qty.';
                    } else if (activeLabel) {
                        boxLabelItemPaneNote.textContent = `Specific-box view is on. Showing ${formatPackBoxLabel(activeLabel)} and unassigned items. Tap an item to expand it and allocate qty.`;
                    } else {
                        boxLabelItemPaneNote.textContent = 'Tap an item to expand it and choose which verified items belong in each box.';
                    }
                }

                if (packItems.length === 0) {
                    boxLabelItemList.innerHTML = '<div class="muted">No verified items are available for packing.</div>';
                    return;
                }

                const totalUnits = packItems.reduce((sum, item) => sum + Number(item.qty || 0), 0);
                const unassignedQty = packItems.reduce((sum, item) => sum + getRemainingPackQty(item.key), 0);
                const visibleItems = packItems.filter((item) => {
                    if (packFastMode) {
                        return true;
                    }
                    const remaining = getRemainingPackQty(item.key);
                    if (activeLabel) {
                        return remaining > 0 || getAllocatedQtyForBox(item.key, activeLabel) > 0;
                    }
                    return true;
                });
                boxLabelItemList.innerHTML = `
                                                                                    <div class="box-label-item-pane__toolbar">
                                                                                        <div class="box-label-item-pane__toolbar-meta">
                                                                                            <span class="box-label-item-pane__pill">${unassignedQty} / ${totalUnits}</span>
                                                                                        </div>
                                                                                        <div class="box-label-item-pane__toolbar-actions">
                                                                                            <button type="button" class="btn btn--ghost box-label-item-pane__bulk-btn" data-pack-bulk="toggle-fast">${packFastMode ? 'Show All Items' : 'Specific Boxes'}</button>
                                                                                            <button type="button" class="btn btn--ghost box-label-item-pane__bulk-btn" data-pack-bulk="assign-unselected"${activeLabel ? '' : ' disabled'}>Assign Remaining</button>
                                                                                            <button type="button" class="btn btn--ghost box-label-item-pane__bulk-btn" data-pack-bulk="clear-active"${activeLabel ? '' : ' disabled'}>Clear Active Box</button>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="box-label-item-pane__list">
                                                                                        ${visibleItems.map((item) => {
                    const activeQty = activeLabel ? getAllocatedQtyForBox(item.key, activeLabel) : 0;
                    const totalAllocated = getTotalAllocatedQty(item.key);
                    const remainingQty = Math.max(0, Number(item.qty || 0) - totalAllocated);
                    const assignmentState = getPackItemAssignmentState(item.key, activeLabel);
                    const allocationEntries = getPackItemAllocationEntries(item.key);
                    const allocationSummary = allocationEntries.length > 0
                        ? allocationEntries.map(([boxIdentifier]) => formatPackBoxLabel(boxIdentifier)).join(' · ')
                        : 'Unassigned';
                    const isExpanded = packExpandedItemKey === item.key;
                    const selectedBoxIdentifier = getPrimaryPackItemBoxIdentifier(item.key);
                    const itemQtyLimit = Math.max(0, Number(item.qty || 0));
                    const options = boxIdentifiers.map((boxIdentifier) => {
                        const selectedAttr = boxIdentifier === selectedBoxIdentifier ? ' selected' : '';
                        return `<option value="${escapeHtml(boxIdentifier)}"${selectedAttr}>${escapeHtml(formatPackBoxLabel(boxIdentifier))}</option>`;
                    }).join('');
                    const qtyInputValue = Math.min(itemQtyLimit, remainingQty);
                    return `
                                                                                                    <div class="box-label-item-row box-label-item-row--${assignmentState}${isExpanded ? ' box-label-item-row--expanded' : ''}" data-item-key="${escapeHtml(item.key)}" data-assignment-state="${escapeHtml(assignmentState)}" tabindex="0" role="button" aria-expanded="${isExpanded ? 'true' : 'false'}" aria-label="${isExpanded ? 'Collapse' : 'Expand'} ${escapeHtml(getPackItemLabel(item))}">
                                                                                                        <div class="box-label-item-row__top">
                                                                                                            <div class="box-label-item-row__copy">
                                                                                                                <div class="box-label-item-row__sku">${escapeHtml(getPackItemLabel(item))}</div>
                                                                                                                <div class="box-label-item-row__desc">Verified qty: ${escapeHtml(String(item.qty || 0))}${item.source_qty > item.qty ? ` of ${escapeHtml(String(item.source_qty || 0))}` : ''}</div>
                                                                                                            </div>
                                                                                                            <div class="box-label-item-row__meta">
                                                                                                                <span class="box-label-item-row__qty">${escapeHtml(String(totalAllocated))} / ${escapeHtml(String(item.qty || 0))}</span>
                                                                                                                <span class="box-label-item-row__qty box-label-item-row__qty--muted">${escapeHtml(allocationSummary)}</span>
                                                                                                            </div>
                                                                                                        </div>
                                                                                                        ${isExpanded ? `
                                                                                                    <div class="box-label-item-row__controls">
                                                                                                        <div class="box-label-item-row__assign-label">Allocate to box</div>
                                                                                                        <div class="box-label-item-row__qty-assign">
                                                                                                            <input class="input box-label-item-row__qty-input" type="number" min="0" max="${escapeHtml(String(itemQtyLimit))}" step="1" data-pack-item-qty="${escapeHtml(item.key)}" value="${escapeHtml(String(qtyInputValue))}" aria-label="Quantity to allocate" />
                                                                                                        </div>
                                                                                                        <div class="box-label-item-row__select-wrap">
                                                                                                            <select class="input box-label-item-row__select" data-pack-item-select="${escapeHtml(item.key)}">
                                                                                                            <option value="">Unselected</option>
                                                                                                            ${options}
                                                                                                            </select>
                                                                                                        </div>
                                                                                                        <div class="box-label-item-row__actions">
                                                                                                            <button type="button" class="btn btn--ghost box-label-item-row__quick-btn" data-pack-item-assign-active="${escapeHtml(item.key)}"${activeLabel ? '' : ' disabled'}>Allocate</button>
                                                                                                            <button type="button" class="btn btn--ghost box-label-item-row__quick-btn" data-pack-item-clear-all="${escapeHtml(item.key)}"${totalAllocated > 0 ? '' : ' disabled'}>Clear all</button>
                                                                                                        </div>
                                                                                                    </div>
                                                                                                    ` : ''}
                                                                                                    </div>
                                                                                                `;
                }).join('')}
                                                                                    </div>
                                                                                `;
                boxLabelItemList.querySelectorAll('[data-pack-item-select]').forEach((select) => {
                    select.addEventListener('change', () => {
                        const itemKey = select.getAttribute('data-pack-item-select') || '';
                        if (!itemKey) {
                            return;
                        }
                        const row = select.closest('.box-label-item-row');
                        if (!row) {
                            return;
                        }
                        const isExpanded = packExpandedItemKey === itemKey;
                        if (!isExpanded) {
                            togglePackItemExpansion(itemKey);
                        }
                    });
                });
                boxLabelItemList.querySelectorAll('[data-pack-item-qty]').forEach((qtyInput) => {
                    qtyInput.addEventListener('input', () => {
                        const itemKey = qtyInput.getAttribute('data-pack-item-qty') || '';
                        const item = getPackItemByKey(itemKey);
                        if (!item) {
                            return;
                        }
                        const limit = Math.max(0, Number(item.qty || 0));
                        const currentValue = Number.parseInt(String(qtyInput.value || '0'), 10) || 0;
                        const clampedValue = Math.min(limit, Math.max(0, currentValue));
                        if (String(clampedValue) !== String(qtyInput.value || '')) {
                            qtyInput.value = String(clampedValue);
                        }
                    });
                    qtyInput.addEventListener('keydown', (ev) => {
                        if (ev.key !== 'Enter') {
                            return;
                        }
                        ev.preventDefault();
                        const itemKey = qtyInput.getAttribute('data-pack-item-qty') || '';
                        const row = qtyInput.closest('.box-label-item-row');
                        const select = row?.querySelector('[data-pack-item-select]');
                        const nextBox = String(select instanceof HTMLSelectElement ? select.value : '').trim();
                        if (!itemKey || !nextBox) {
                            return;
                        }
                        const item = getPackItemByKey(itemKey);
                        if (!item) {
                            return;
                        }
                        const limit = Math.max(0, Number(item.qty || 0));
                        const requestedQty = Number.parseInt(String(qtyInput.value || '0'), 10) || 0;
                        setPackItemAllocation(itemKey, nextBox, Math.min(limit, Math.max(0, requestedQty)));
                        qtyInput.value = String(getRemainingPackQty(itemKey));
                        renderBoxLabelRows();
                        renderPackItemPane();
                    });
                });
                boxLabelItemList.querySelectorAll('[data-pack-item-assign-active]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const itemKey = button.getAttribute('data-pack-item-assign-active') || '';
                        if (!itemKey) {
                            return;
                        }
                        const row = button.closest('.box-label-item-row');
                        const qtyInput = row?.querySelector('[data-pack-item-qty]');
                        const select = row?.querySelector('[data-pack-item-select]');
                        const nextBox = String(select instanceof HTMLSelectElement ? select.value : '').trim();
                        if (!nextBox) {
                            return;
                        }
                        const item = getPackItemByKey(itemKey);
                        if (!item) {
                            return;
                        }
                        const limit = Math.max(0, Number(item.qty || 0));
                        const requestedQty = Number.parseInt(String(qtyInput?.value || '0'), 10) || 0;
                        setPackItemAllocation(itemKey, nextBox, Math.min(limit, Math.max(0, requestedQty)));
                        if (qtyInput) {
                            qtyInput.value = String(getRemainingPackQty(itemKey));
                        }
                        renderBoxLabelRows();
                        renderPackItemPane();
                    });
                });
                boxLabelItemList.querySelectorAll('[data-pack-item-fill-active]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const itemKey = button.getAttribute('data-pack-item-fill-active') || '';
                        if (!itemKey || !activeLabel) {
                            return;
                        }
                        const item = getPackItemByKey(itemKey);
                        if (!item) {
                            return;
                        }
                        const allocatedToActive = getAllocatedQtyForBox(itemKey, activeLabel);
                        const remainingForItem = Math.max(0, Number(item.qty || 0) - getTotalAllocatedQty(itemKey) + allocatedToActive);
                        const appliedQty = setPackItemAllocation(itemKey, activeLabel, remainingForItem);
                        const qtyInput = boxLabelItemList.querySelector(`[data-pack-item-qty="${escapeSelector(itemKey)}"]`);
                        if (qtyInput) {
                            qtyInput.value = String(appliedQty || 1);
                        }
                        renderBoxLabelRows();
                        renderPackItemPane();
                    });
                });
                boxLabelItemList.querySelectorAll('[data-pack-item-clear]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const itemKey = button.getAttribute('data-pack-item-clear') || '';
                        if (!itemKey || !activeLabel) {
                            return;
                        }
                        const allocationMap = getPackItemAllocationMap(itemKey);
                        allocationMap.delete(activeLabel);
                        if (allocationMap.size === 0) {
                            packItemAllocations.delete(itemKey);
                        } else {
                            packItemAllocations.set(itemKey, allocationMap);
                        }
                        renderBoxLabelRows();
                        renderPackItemPane();
                    });
                });
                boxLabelItemList.querySelectorAll('[data-pack-item-clear-all]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const itemKey = button.getAttribute('data-pack-item-clear-all') || '';
                        if (!itemKey) {
                            return;
                        }
                        packItemAllocations.delete(itemKey);
                        renderBoxLabelRows();
                        renderPackItemPane();
                    });
                });
                boxLabelItemList.querySelectorAll('.box-label-item-row').forEach((row) => {
                    row.addEventListener('click', (ev) => {
                        const target = ev.target;
                        if (target instanceof HTMLElement && (target.closest('select, button, input, option') || target.closest('.box-label-item-row__controls') || target.closest('.box-label-item-row__select-wrap') || target.closest('.box-label-item-row__qty-assign'))) {
                            return;
                        }
                        const itemKey = row.getAttribute('data-item-key') || '';
                        if (!itemKey) {
                            return;
                        }
                        togglePackItemExpansion(itemKey);
                    });
                    row.addEventListener('keydown', (ev) => {
                        if (ev.key !== 'Enter' && ev.key !== ' ') {
                            return;
                        }
                        ev.preventDefault();
                        const itemKey = row.getAttribute('data-item-key') || '';
                        if (!itemKey) {
                            return;
                        }
                        togglePackItemExpansion(itemKey);
                    });
                });
                boxLabelItemList.querySelectorAll('[data-pack-bulk]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const action = button.getAttribute('data-pack-bulk') || '';
                        if (!activeLabel) {
                            if (action !== 'toggle-fast') {
                                return;
                            }
                        }
                        if (action === 'assign-unselected') {
                            const keys = packItems
                                .filter((item) => getTotalAllocatedQty(item.key) === 0)
                                .map((item) => item.key);
                            assignPackItemsToBox(activeLabel, keys);
                        } else if (action === 'clear-active') {
                            clearPackItemsForBox(activeLabel);
                        } else if (action === 'toggle-fast') {
                            packFastModeTouched = true;
                            packFastMode = !packFastMode;
                        }
                        renderBoxLabelRows();
                        renderPackItemPane();
                    });
                });
            };

            const openPackItemPane = (boxIdentifier = '') => {
                if (!boxLabelItemPane) {
                    return;
                }
                packItemPaneOpen = true;
                if (boxIdentifier) {
                    activePackBoxIdentifier = boxIdentifier;
                }
                if (!packFastModeTouched && packItems.length >= PACK_FAST_MODE_THRESHOLD) {
                    packFastMode = true;
                }
                renderPackItemPane();
                if (boxLabelItemPane.hidden) {
                    return;
                }
                boxLabelItemPane.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            const closePackItemPane = () => {
                packItemPaneOpen = false;
                activePackBoxIdentifier = '';
                packExpandedItemKey = '';
                renderPackItemPane();
                renderBoxLabelRows();
            };

            const togglePackItemExpansion = (itemKey) => {
                const nextKey = packExpandedItemKey === itemKey ? '' : itemKey;
                packExpandedItemKey = nextKey;
                renderBoxLabelRows();
                renderPackItemPane();
                if (!nextKey || !boxLabelItemList) {
                    return;
                }
                window.requestAnimationFrame(() => {
                    const expandedRow = boxLabelItemList.querySelector(`[data-item-key="${escapeSelector(nextKey)}"]`);
                    if (expandedRow instanceof HTMLElement) {
                        expandedRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                });
            };

            const getPackingStateStorageKey = () => {
                const orderPart = pickOrderId > 0 ? `id:${pickOrderId}` : `so:${salesOrderNumber || 'unknown'}`;
                return `verify_pick_packing_state:${orderPart}`;
            };

            const getPackingUsageStorageKey = () => {
                const orderPart = pickOrderId > 0 ? `id:${pickOrderId}` : `so:${salesOrderNumber || 'unknown'}`;
                return `verify_pick_packing_usage:${orderPart}`;
            };

            const loadPackingStateFromSession = () => {
                try {
                    const raw = window.sessionStorage.getItem(getPackingStateStorageKey());
                    if (!raw) {
                        return null;
                    }
                    const data = JSON.parse(raw);
                    if (!data || typeof data !== 'object' || !Array.isArray(data.boxes)) {
                        return null;
                    }
                    return data;
                } catch (err) {
                    return null;
                }
            };

            const applyPackingStateData = (data) => {
                if (!data || typeof data !== 'object' || !Array.isArray(data.boxes)) {
                    return;
                }
                if (boxLabelSO && data.sales_order_number) {
                    boxLabelSO.value = String(data.sales_order_number);
                }
                if (boxLabelCount && data.boxes.length > 0) {
                    boxLabelCount.value = String(data.boxes.length);
                }
                packingManifestData = data;
                packingManifestToken = String(data.token || '');
                packingFinalized = true;
                packItemAllocations = new Map();
                data.boxes.forEach((box) => {
                    const boxIdentifier = String(box?.box_identifier || '').trim();
                    if (!boxIdentifier) {
                        return;
                    }
                    const items = Array.isArray(box.items) ? box.items : [];
                    items.forEach((item) => {
                        const itemKey = String(item?.key || '').trim();
                        const qty = Number.parseInt(String(item?.qty ?? '0'), 10) || 0;
                        if (itemKey && qty > 0) {
                            const allocationMap = getPackItemAllocationMap(itemKey);
                            allocationMap.set(boxIdentifier, qty);
                            packItemAllocations.set(itemKey, allocationMap);
                        }
                    });
                });
                renderPackingSummary(data);
                setPackingLockedState(true);
            };

            const savePackingStateToSession = (data) => {
                try {
                    window.sessionStorage.setItem(getPackingStateStorageKey(), JSON.stringify(data));
                } catch (err) {
                    // Ignore storage errors.
                }
            };

            const markPackingUsageRecorded = () => {
                try {
                    window.sessionStorage.setItem(getPackingUsageStorageKey(), '1');
                } catch (err) {
                    // Ignore storage errors.
                }
            };

            const hasPackingUsageRecorded = () => {
                try {
                    return window.sessionStorage.getItem(getPackingUsageStorageKey()) === '1';
                } catch (err) {
                    return false;
                }
            };

            const clearPackingStateSession = () => {
                try {
                    window.sessionStorage.removeItem(getPackingStateStorageKey());
                } catch (err) {
                    // Ignore storage errors.
                }
            };

            const clearPackingUsageSession = () => {
                try {
                    window.sessionStorage.removeItem(getPackingUsageStorageKey());
                } catch (err) {
                    // Ignore storage errors.
                }
            };

            const setPackingLockedState = (locked) => {
                packingFinalized = !!locked;
                if (boxLabelManagerActions) {
                    boxLabelManagerActions.hidden = packingFinalized;
                    boxLabelManagerActions.style.display = packingFinalized ? 'none' : '';
                    boxLabelManagerActions.setAttribute('aria-hidden', packingFinalized ? 'true' : 'false');
                }
                if (boxLabelSO) {
                    boxLabelSO.disabled = packingFinalized;
                }
                if (boxLabelCount) {
                    boxLabelCount.disabled = packingFinalized;
                }
                if (boxLabelFinalizeBtn) {
                    boxLabelFinalizeBtn.hidden = packingFinalized;
                    boxLabelFinalizeBtn.disabled = packingFinalized;
                }
                if (packingSummaryActions) {
                    packingSummaryActions.hidden = !packingFinalized;
                    packingSummaryActions.style.display = packingFinalized ? 'flex' : 'none';
                }
                if (boxLabelCancel) {
                    boxLabelCancel.hidden = packingFinalized;
                    boxLabelCancel.disabled = packingFinalized;
                }
                if (boxLabelManifestBtn) {
                    boxLabelManifestBtn.hidden = !packingFinalized;
                    boxLabelManifestBtn.disabled = !packingFinalized || !packingManifestToken;
                }
                if (boxLabelManifestPrintBtn) {
                    boxLabelManifestPrintBtn.hidden = !packingFinalized;
                    boxLabelManifestPrintBtn.disabled = !packingFinalized || !packingManifestToken;
                }
                if (boxLabelRows) {
                    boxLabelRows.querySelectorAll('input, select, button').forEach((el) => {
                        if (el === boxLabelClose || el === boxLabelCancel || el === boxLabelManifestBtn) {
                            return;
                        }
                        if (el.matches('[data-field="box-type"], [data-field="weight"], [data-field="dimensions"]')) {
                            el.disabled = packingFinalized;
                        }
                    });
                }
                if (boxLabelItemPane) {
                    if (packingFinalized) {
                        boxLabelItemPane.hidden = true;
                        boxLabelItemPane.setAttribute('aria-hidden', 'true');
                        if (boxLabelModalLayout) {
                            boxLabelModalLayout.classList.remove('has-items-pane');
                        }
                    } else {
                        boxLabelItemPane.hidden = false;
                        boxLabelItemPane.setAttribute('aria-hidden', 'false');
                        boxLabelItemPane.querySelectorAll('input, select, button').forEach((el) => {
                            el.disabled = packingFinalized;
                        });
                    }
                }
                if (packingSummaryPanel) {
                    packingSummaryPanel.hidden = !packingFinalized;
                }
            };

            const renderPackingSummary = (data) => {
                if (!packingSummaryPanel) {
                    return;
                }
                if (!data || !Array.isArray(data.boxes) || data.boxes.length === 0) {
                    packingSummaryPanel.hidden = true;
                    packingSummaryPanel.innerHTML = '';
                    return;
                }
                const summary = data.summary || {};
                const boxCount = Number(summary.box_count || data.boxes.length || 0);
                const itemCount = Number(summary.item_count || 0);
                const unassignedCount = Number(summary.unassigned_count || 0);
                packingSummaryPanel.innerHTML = `
                                                                                    <div class="packing-summary-panel__title">Packing Finalized</div>
                                                                                    <div class="packing-summary-panel__boxes">
                                                                                        ${data.boxes.map((box) => {
                    const items = Array.isArray(box.items) ? box.items : [];
                    const boxUnits = items.reduce((sum, item) => sum + (Number(item.qty || 0) || 0), 0);
                    return `
                                                                                                <div class="packing-summary-panel__box">
                                                                                                    <div class="packing-summary-panel__box-head">
                                                                                                        <div>
                                                                                                            <div class="packing-summary-panel__box-name">${escapeHtml(String(box.box_identifier || 'Box'))}</div>
                                                                                                            <div class="packing-summary-panel__box-sub">${escapeHtml(String(box.dimensions || ''))} | ${escapeHtml(formatPackWeightLabel(box.weight_label))}</div>
                                                                                                        </div>
                                                                                                        <div class="packing-summary-panel__meta" style="margin:0;">
                                                                                                            <div class="packing-summary-panel__pill">${items.length} line${items.length === 1 ? '' : 's'}</div>
                                                                                                            <div class="packing-summary-panel__pill">${boxUnits} unit${boxUnits === 1 ? '' : 's'}</div>
                                                                                                        </div>
                                                                                                    </div>
                                                                                                    <div class="packing-summary-panel__box-items">
                                                                                                        ${items.length > 0
                            ? items.map((item) => `${escapeHtml(String(item.sku || ''))}${item.description ? ` - ${escapeHtml(String(item.description))}` : ''} (${escapeHtml(String(item.qty || 0))})`).join('<br>')
                            : 'No items assigned.'}
                                                                                                    </div>
                                                                                                </div>
                                                                                            `;
                }).join('')}
                                                                                    </div>
                                                                                `;
                packingSummaryPanel.hidden = false;
            };

            const getWarehouseBoxTypeBySupplyId = (value) => {
                const target = Number.parseInt(String(value || '0'), 10) || 0;
                if (target <= 0) return null;
                return warehouseBoxTypes.find((option) => Number.parseInt(String(option.supply_id || '0'), 10) === target) || null;
            };

            const getWarehouseBoxTypeByDimensions = (value) => {
                const normalized = String(value || '').trim();
                if (!normalized) return null;
                return warehouseBoxTypes.find((option) => {
                    const actual = String(option.actual_dimensions || option.inside_dimensions || '').trim();
                    const display = String(option.display_dimensions || '').trim();
                    return actual === normalized || display === normalized;
                }) || null;
            };

            const readBoxLabelRows = () => {
                if (!boxLabelRows) return [];
                const currentBoxIdentifiers = getCurrentBoxIdentifiers();
                return Array.from(boxLabelRows.querySelectorAll('[data-box-identifier]')).map((row) => ({
                    box_identifier: row.getAttribute('data-box-identifier') || '',
                    supply_id: Number.parseInt(row.querySelector('[data-field="box-type"]')?.value || '0', 10) || 0,
                    selected_supply_id: Number.parseInt(row.querySelector('[data-field="box-type"]')?.value || '0', 10) || 0,
                    selected_dimensions: row.querySelector('[data-field="dimensions"]')?.value.trim() || '',
                    dimensions: row.querySelector('[data-field="dimensions"]')?.value.trim() || '',
                    weight_label: row.querySelector('[data-field="weight"]')?.value.trim() || '',
                    items: (currentBoxIdentifiers.includes(row.getAttribute('data-box-identifier') || '')
                        ? getAssignedItemsForBox(row.getAttribute('data-box-identifier') || '').map((item) => ({
                            key: item.key,
                            pick_item_id: item.pick_item_id,
                            sku: item.sku,
                            description: item.description,
                            qty: item.qty,
                            source_qty: item.source_qty,
                        }))
                        : []),
                }));
            };

            const renderBoxLabelRows = () => {
                if (!boxLabelRows) return;
                packItems = buildPackItemsFromOrder();
                normalizePackItemAllocations();
                syncPackItemAssignments();
                const existingRows = readBoxLabelRows();
                const fallbackSelectedSupplyId = existingRows[0]?.selected_supply_id || (warehouseBoxTypes[0]?.supply_id || 0);
                const fallbackSelectedDimensions = existingRows[0]?.selected_dimensions || existingRows[0]?.dimensions || (warehouseBoxTypes[0]?.actual_dimensions || warehouseBoxTypes[0]?.inside_dimensions || '');
                const fallbackDimensions = existingRows[0]?.dimensions || (warehouseBoxTypes[0]?.actual_dimensions || warehouseBoxTypes[0]?.inside_dimensions || '');
                const fallbackWeight = existingRows[0]?.weight_label || '';
                const generatedLabels = buildGeneratedBoxIdentifiers(normalizeBoxLabelCount());
                boxLabelRows.innerHTML = '';

                generatedLabels.forEach((boxIdentifier, index) => {
                    const row = document.createElement('div');
                    row.className = 'box-label-row';
                    row.setAttribute('data-box-identifier', boxIdentifier);
                    const preserved = existingRows[index] || {};
                    const preservedSupplyId = Number.parseInt(String(preserved.selected_supply_id || preserved.supply_id || 0), 10) || 0;
                    const selectedDimensionsValue = preserved.selected_dimensions || preserved.dimensions || fallbackSelectedDimensions;
                    const selectedType = getWarehouseBoxTypeBySupplyId(preservedSupplyId) || getWarehouseBoxTypeByDimensions(selectedDimensionsValue);
                    const selectedSupplyId = Number.parseInt(String(selectedType?.supply_id || preservedSupplyId || fallbackSelectedSupplyId || 0), 10) || 0;
                    const dimensionsValue = selectedType?.actual_dimensions || selectedType?.inside_dimensions || preserved.dimensions || fallbackDimensions;
                    const weightValue = preserved.weight_label || fallbackWeight;
                    const usesWarehouseCatalog = warehouseBoxTypes.length > 0;
                    const selectMarkup = warehouseBoxTypes.length > 0
                        ? `
                                                                                            <label class="box-label-row__field">
                                                                                                <span>Box Type (Inside Dimensions)</span>
                                                                                                <select class="input" data-field="box-type">
                                                                                                    ${warehouseBoxTypes.map((option) => {
                            const optionValue = String(option.supply_id || '').trim();
                            const optionDimensions = String(option.actual_dimensions || option.inside_dimensions || '').trim();
                            const optionDisplayDimensions = String(option.display_dimensions || optionDimensions).trim();
                            const optionStatus = String(option.stock_status || 'OK').toUpperCase();
                            const optionQty = Number.parseInt(String(option.qty_on_hand || 0), 10) || 0;
                            const selectedAttr = Number.parseInt(optionValue || '0', 10) === selectedSupplyId
                                || (!selectedSupplyId && optionDimensions === String(selectedDimensionsValue).trim())
                                ? ' selected'
                                : '';
                            const label = (optionStatus === 'LOW' || optionStatus === 'OUT')
                                ? `${optionDisplayDimensions} - ${optionStatus} (${optionQty} left)`
                                : optionDisplayDimensions;
                            return `<option value="${escapeHtml(optionValue)}" data-dimensions="${escapeHtml(optionDimensions)}" data-display-dimensions="${escapeHtml(optionDisplayDimensions)}"${selectedAttr}>${escapeHtml(label)}</option>`;
                        }).join('')}
                                                                                                </select>
                                                                                            </label>
                                                                                        `
                        : `
                                                                                            <label class="box-label-row__field">
                                                                                                <span>Dimensions</span>
                                                                                                <input class="input" data-field="dimensions" placeholder="Select box" value="${escapeHtml(dimensionsValue)}" />
                                                                                            </label>
                                                                                        `;
                    const dimensionsMetaMarkup = usesWarehouseCatalog
                        ? `
                                                                                            <div class="box-label-row__meta">
                                                                                                Dimensions: <strong data-field="dimensions-label">${escapeHtml(dimensionsValue)}</strong>
                                                                                                <input type="hidden" data-field="dimensions" value="${escapeHtml(dimensionsValue)}" />
                                                                                            </div>
                                                                                        `
                        : '';
                    const assignedItems = getAssignedItemsForBox(boxIdentifier);
                    const assignedUnits = assignedItems.reduce((sum, item) => sum + (Number(item.qty || 0) || 0), 0);
                    const assignedSummary = assignedItems.length > 0
                        ? assignedItems.slice(0, 3).map((item) => `${item.sku}${item.qty > 1 ? ` (${item.qty})` : ''}`).join(', ') + (assignedItems.length > 3 ? ` +${assignedItems.length - 3} more` : '')
                        : 'No items assigned.';
                    const isActiveBox = activePackBoxIdentifier === boxIdentifier;
                    row.innerHTML = `
                                                                                        <div class="box-label-row__header${isActiveBox ? ' is-active' : ''}">
                                                                                            <span class="box-label-row__badge">${escapeHtml(formatPackBoxLabel(boxIdentifier))}</span>
                                                                                            <div class="box-label-row__header-actions">
                                                                                                <span class="box-label-row__count">${assignedItems.length} item${assignedItems.length === 1 ? '' : 's'}</span>
                                                                                                <button type="button" class="btn btn--ghost box-label-row__active-btn${isActiveBox ? ' is-active' : ''}" data-action="set-active-box">${isActiveBox ? 'Active' : 'Set Active'}</button>
                                                                                            </div>
                                                                                        </div>
                                                                                        <div class="box-label-row__grid">
                                                                                            ${selectMarkup}
                                                                                            <label class="box-label-row__field">
                                                                                                <span>Weight</span>
                                                                                                <input class="input" data-field="weight" placeholder="Enter weight..." value="${escapeHtml(weightValue)}" />
                                                                                            </label>
                                                                                            <div class="box-label-row__field">
                                                                                                <span>Assigned</span>
                                                                                                <strong>${escapeHtml(String(assignedUnits))} unit${assignedUnits === 1 ? '' : 's'}</strong>
                                                                                            </div>
                                                                                        </div>
                                                                                        ${dimensionsMetaMarkup}
                                                                                        <div class="box-label-row__items">
                                                                                            <strong>Assigned:</strong> ${escapeHtml(assignedSummary)}${assignedUnits > 0 ? ` <span class="muted">(${assignedUnits} unit${assignedUnits === 1 ? '' : 's'})</span>` : ''}
                                                                                        </div>
                                                                                    `;
                    const boxTypeSelect = row.querySelector('[data-field="box-type"]');
                    const dimensionsInput = row.querySelector('[data-field="dimensions"]');
                    const dimensionsLabel = row.querySelector('[data-field="dimensions-label"]');
                    const activeButton = row.querySelector('[data-action="set-active-box"]');
                    if (activeButton) {
                        activeButton.addEventListener('click', () => {
                            setActivePackBox(boxIdentifier, false);
                        });
                    }
                    if (boxTypeSelect && dimensionsInput && dimensionsLabel) {
                        boxTypeSelect.addEventListener('change', () => {
                            const selectedOption = getWarehouseBoxTypeBySupplyId(boxTypeSelect.value)
                                || getWarehouseBoxTypeByDimensions(boxTypeSelect.selectedOptions[0]?.dataset.dimensions || '');
                            const nextDimensions = selectedOption?.actual_dimensions || selectedOption?.inside_dimensions || '';
                            dimensionsInput.value = nextDimensions;
                            dimensionsLabel.textContent = nextDimensions || 'Not set';
                        });
                    }
                    boxLabelRows.appendChild(row);
                });
                if (packItemPaneOpen) {
                    renderPackItemPane();
                }
            };

            const updateBoxLabelSequenceNote = () => {
                if (!boxLabelSequenceNote) return;
                const count = normalizeBoxLabelCount();
                const generatedLabels = buildGeneratedBoxIdentifiers(count);
                const preview = generatedLabels.length <= 3
                    ? generatedLabels.map((label) => formatPackBoxLabel(label)).join(', ')
                    : `${formatPackBoxLabel(generatedLabels[0])}, ${formatPackBoxLabel(generatedLabels[1])}, ${formatPackBoxLabel(generatedLabels[2])} ... ${formatPackBoxLabel(generatedLabels[generatedLabels.length - 1])}`;
                const sourceNote = warehouseBoxTypes.length > 0
                    ? 'Box types come from warehouse supplies.'
                    : 'Each box can have its own dimensions and weight.';
                boxLabelSequenceNote.innerHTML = `Labels will print as <strong>${preview}</strong>. ${sourceNote} Use the <strong>Items to Pack</strong> pane to assign verified items to boxes.`;
            };

            const openBoxLabelModal = (showItemsPane = false) => {
                if (!boxLabelModal) return;
                const canRestorePackingState = ['COMPLETED', 'COMPLETED_SHORT_SHIP'].includes(currentOrderStatus);
                const persistedPackingState = canRestorePackingState ? loadPackingStateFromSession() : null;
                if (persistedPackingState) {
                    applyPackingStateData(persistedPackingState);
                } else if (!canRestorePackingState) {
                    clearPackingStateSession();
                    clearPackingUsageSession();
                    packingFinalized = false;
                    packingManifestToken = '';
                    packingManifestData = null;
                    packItemAllocations = new Map();
                    if (packingSummaryPanel) {
                        packingSummaryPanel.hidden = true;
                        packingSummaryPanel.innerHTML = '';
                    }
                } else if (!packingFinalized) {
                    packingManifestData = null;
                    packingManifestToken = '';
                    packItemAllocations = new Map();
                    packingSummaryPanel && (packingSummaryPanel.hidden = true);
                }
                // Prefill fields if empty
                if (boxLabelSO && !boxLabelSO.value) boxLabelSO.value = soDefaultVal;
                if (boxLabelCount && (!boxLabelCount.value || Number.parseInt(boxLabelCount.value, 10) < 1)) {
                    boxLabelCount.value = '1';
                }
                packItemPaneOpen = !!showItemsPane && !packingFinalized;
                updateBoxLabelSequenceNote();
                renderBoxLabelRows();
                const firstBoxIdentifier = getCurrentBoxIdentifiers()[0] || '';
                if (!activePackBoxIdentifier && firstBoxIdentifier) {
                    activePackBoxIdentifier = firstBoxIdentifier;
                }
                renderPackItemPane();
                boxLabelModal.removeAttribute('hidden');
                boxLabelModal.setAttribute('aria-hidden', 'false');
                boxLabelModal.classList.add('is-open');
                setPackingLockedState(packingFinalized);
                setTimeout(() => {
                    const firstBoxTypeInput = boxLabelRows?.querySelector('[data-field="box-type"]');
                    const firstWeightInput = boxLabelRows?.querySelector('[data-field="weight"]');
                    const firstDimensionInput = boxLabelRows?.querySelector('[data-field="dimensions"]');
                    if (firstBoxTypeInput) {
                        firstBoxTypeInput.focus();
                    } else if (firstWeightInput) {
                        firstWeightInput.focus();
                    } else if (boxLabelSO) {
                        boxLabelSO.focus();
                    } else if (firstDimensionInput) {
                        firstDimensionInput.focus();
                    }
                }, 50);
            };

            const closeBoxLabelModal = () => {
                if (!boxLabelModal) return;
                closePackItemPane();
                boxLabelModal.classList.remove('is-open');
                boxLabelModal.setAttribute('aria-hidden', 'true');
                boxLabelModal.setAttribute('hidden', 'hidden');
            };

            if (printBoxLabelBtn) {
                printBoxLabelBtn.addEventListener('click', openBoxLabelModal);
            }
            if (boxLabelClose) boxLabelClose.addEventListener('click', closeBoxLabelModal);
            if (boxLabelCancel) boxLabelCancel.addEventListener('click', closeBoxLabelModal);
            if (boxLabelManifestBtn) {
                boxLabelManifestBtn.addEventListener('click', () => {
                    if (!packingManifestToken) {
                        alert('No finalized packing manifest is available.');
                        return;
                    }
                    const url = new URL(window.location.href);
                    url.search = '';
                    url.searchParams.set('action', 'packing_manifest');
                    url.searchParams.set('token', packingManifestToken);
                    window.location.assign(url.toString());
                });
            }
            if (boxLabelManifestPrintBtn) {
                boxLabelManifestPrintBtn.addEventListener('click', () => {
                    if (!packingManifestToken) {
                        alert('No finalized packing manifest is available.');
                        return;
                    }
                    const url = new URL(window.location.href);
                    url.search = '';
                    url.searchParams.set('action', 'packing_manifest_print');
                    url.searchParams.set('token', packingManifestToken);
                    window.open(url.toString(), '_blank', 'noopener,noreferrer');
                });
            }
            if (boxLabelPrintLabelsBtn) {
                boxLabelPrintLabelsBtn.addEventListener('click', () => {
                    if (!packingManifestToken) {
                        alert('No finalized packing manifest is available.');
                        return;
                    }
                    const url = new URL(window.location.href);
                    url.search = '';
                    url.searchParams.set('action', 'box_label_print');
                    url.searchParams.set('manifest_token', packingManifestToken);
                    window.open(url.toString(), '_blank', 'noopener,noreferrer');
                });
            }
            if (boxLabelPrintSummaryBtn) {
                boxLabelPrintSummaryBtn.addEventListener('click', () => {
                    if (!packingManifestToken) {
                        alert('No finalized packing manifest is available.');
                        return;
                    }
                    const url = new URL(window.location.href);
                    url.search = '';
                    url.searchParams.set('action', 'packing_manifest_print');
                    url.searchParams.set('token', packingManifestToken);
                    window.open(url.toString(), '_blank', 'noopener,noreferrer');
                });
            }
            if (boxLabelFinalizeBtn) {
                boxLabelFinalizeBtn.addEventListener('click', async () => {
                    if (packingFinalized) {
                        return;
                    }
                    if (!pickOrderId) {
                        alert('No order loaded.');
                        return;
                    }
                    const soVal = boxLabelSO ? boxLabelSO.value.trim() : '';
                    if (!soVal) {
                        alert('Please enter a sales order before finalizing.');
                        return;
                    }
                    const boxEntries = readBoxLabelRows();
                    if (!boxEntries.length) {
                        alert('Add at least one box before finalizing.');
                        return;
                    }
                    const hasMissingBoxData = boxEntries.some((entry) => !entry.box_identifier || !entry.dimensions || !entry.weight_label);
                    if (hasMissingBoxData) {
                        alert('Please fill in dimensions and weight for every box before finalizing.');
                        return;
                    }
                    const unassignedQty = packItems.reduce((sum, item) => sum + getRemainingPackQty(item.key), 0);
                    if (unassignedQty > 0) {
                        alert(`${unassignedQty} unit(s) are still unassigned to a box.`);
                        return;
                    }
                    const emptyBoxes = boxEntries.filter((entry) => !Array.isArray(entry.items) || entry.items.length === 0);
                    if (emptyBoxes.length > 0) {
                        alert('Assign at least one verified item to each box before finalizing.');
                        return;
                    }

                    boxLabelFinalizeBtn.disabled = true;
                    try {
                        const finalizeUrl = new URL(window.location.href);
                        finalizeUrl.search = '';
                        finalizeUrl.searchParams.set('action', 'finalize_packing');
                        const finalizeRes = await fetch(finalizeUrl.toString(), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                order_id: pickOrderId,
                                sales_order_number: soVal,
                                boxes: boxEntries,
                            }),
                        });
                        const finalizeData = await finalizeRes.json().catch(() => ({}));
                        if (!finalizeRes.ok || !finalizeData?.ok || !finalizeData?.token) {
                            alert(finalizeData?.error || 'Unable to finalize packing.');
                            return;
                        }

                        packingManifestToken = String(finalizeData.token || '');
                        packingManifestData = {
                            token: packingManifestToken,
                            order_id: pickOrderId,
                            sales_order_number: soVal,
                            created_at: new Date().toISOString(),
                            boxes: boxEntries,
                            summary: finalizeData.summary || {},
                        };
                        savePackingStateToSession(packingManifestData);
                        if (finalizeData.box_usage && Array.isArray(finalizeData.box_usage)) {
                            markPackingUsageRecorded();
                        }
                        setPackingLockedState(true);
                        closePackItemPane();
                        renderPackingSummary(packingManifestData);
                        if (typeof showToast === 'function') {
                            showToast('Packing finalized.');
                        }
                    } catch (err) {
                        alert(err?.message || 'Unable to finalize packing.');
                    } finally {
                        boxLabelFinalizeBtn.disabled = false;
                    }
                });
            }
            if (boxLabelCount) {
                boxLabelCount.addEventListener('input', () => {
                    const count = normalizeBoxLabelCount();
                    if (String(count) !== boxLabelCount.value) {
                        boxLabelCount.value = String(count);
                    }
                    updateBoxLabelSequenceNote();
                    renderBoxLabelRows();
                });
            }
            if (boxLabelModal) {
                boxLabelModal.addEventListener('click', (ev) => {
                    const target = ev.target;
                    if (target && target.getAttribute('data-close') === '1') {
                        closeBoxLabelModal();
                    }
                });
            }
            document.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape' && boxLabelModal && boxLabelModal.classList.contains('is-open')) {
                    closeBoxLabelModal();
                    return;
                }
                if (ev.key === 'Escape' && shipInfoInputRow && !shipInfoInputRow.classList.contains('hidden')) {
                    closeShipInfoModal();
                }
            });
            if (scanCameraBtn) {
                scanCameraBtn.addEventListener('click', async () => {
                    if (isCurrentOrderCompleted) {
                        return;
                    }
                    if (scanActive || scanStream) {
                        stopVerifyCameraScan();
                        if (scanCameraNote) {
                            scanCameraNote.textContent = 'Camera stopped. Tap Scan to start again.';
                        }
                        return;
                    }

                    try {
                        await startVerifyCameraScan();
                    } catch (err) {
                        stopVerifyCameraScan();
                        if (scanCameraNote) {
                            scanCameraNote.textContent = 'Unable to start the on-page camera scanner.';
                        }
                    }
                });
            }

            const IMPORT_PICKS_URL = `${ROOT_BASE}/utils/import_picks.php`;
            if (runImportBtn && runImportBtn.dataset.importBound !== '1') {
                runImportBtn.dataset.importBound = '1';
                runImportBtn.addEventListener('click', async () => {
                    const ok = confirm('Import pick files from Google Drive now? This may take a minute.');
                    if (!ok) return;
                    runImportBtn.disabled = true;
                    if (pickLoading) {
                        pickLoading.classList.add('is-active');
                        pickLoading.setAttribute('aria-hidden', 'false');
                    }
                    if (pickOverlay) {
                        pickOverlay.classList.add('is-active');
                        pickOverlay.setAttribute('aria-hidden', 'false');
                    }
                    try {
                        const res = await fetch(IMPORT_PICKS_URL, {
                            method: 'POST',
                            cache: 'no-store',
                            headers: {
                                'Accept': 'application/json',
                            },
                        });
                        if (!res.ok) {
                            alert('Drive import failed (server error).');
                            return;
                        }
                        window.location.reload();
                    } catch (err) {
                        alert('Drive import failed.');
                    } finally {
                        runImportBtn.disabled = false;
                        if (pickLoading) {
                            pickLoading.classList.remove('is-active');
                            pickLoading.setAttribute('aria-hidden', 'true');
                        }
                        if (pickOverlay) {
                            pickOverlay.classList.remove('is-active');
                            pickOverlay.setAttribute('aria-hidden', 'true');
                        }
                    }
                });
            }

            if (typeof window.bindVerifyCompletedSearch === 'function') {
                window.bindVerifyCompletedSearch();
            }

            window.addEventListener('beforeunload', () => {
                stopVerifyCameraScan();
            });

            updateProgress();
        })();
    </script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';
?>