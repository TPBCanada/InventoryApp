<?php
declare(strict_types=1);

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/import_pick_error.log');

require_once __DIR__ . '/../../dbinv.php';
require_once __DIR__ . '/../../utils/config_init.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// --- CONFIGURATION ---
$logFile = __DIR__ . '/logs/import_log.txt';
$inboundDir = __DIR__ . '/google_drive_picks/inbound/';  // Fallback local folder
$processedDir = __DIR__ . '/google_drive_picks/processed/';
const PICK_IMPORT_LOG_MAX_BYTES = 1048576; // 1 MB
const PICK_IMPORT_LOG_RETAIN_BYTES = 524288; // 512 KB
const PICK_IMPORT_DEBUG_MAX_BYTES = 1048576; // 1 MB
const PICK_IMPORT_DEBUG_RETAIN_BYTES = 524288; // 512 KB
const PICK_IMPORT_REJECT_SAMPLE_LIMIT = 5;
const PICK_BOGO_IMPORT_VERSION = 5; // 5: BOGO = more than one Take per source line

/**
 * @var array<string,mixed>
 */
$PICK_IMPORT_DRIVE_REJECT_STATS = [
    'total_seen' => 0,
    'accepted' => 0,
    'prefix_mismatch' => 0,
    'unsupported_extension' => 0,
    'other' => 0,
    'samples' => [],
];

$DRIVE_SERVICE_ACCOUNT_JSON = DRIVE_SERVICE_ACCOUNT_JSON;
$DRIVE_PICK_FOLDER_ID = DRIVE_PICK_FOLDER_ID;
$DRIVE_PICK_NAME_PREFIX = DRIVE_PICK_NAME_PREFIX;
$PICK_IMPORT_ARCHIVE_DIR = __DIR__ . '/google_drive_picks/archive/';

if (!is_dir($processedDir)) {
    mkdir($processedDir, 0777, true);
}
if (!is_dir($PICK_IMPORT_ARCHIVE_DIR)) {
    mkdir($PICK_IMPORT_ARCHIVE_DIR, 0777, true);
}

// --- HELPERS ---
function appendCappedLog(string $path, string $line, int $maxBytes, int $retainBytes): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    clearstatcache(true, $path);
    if (is_file($path)) {
        $size = @filesize($path);
        if (is_int($size) && $size > $maxBytes) {
            $start = max(0, $size - $retainBytes);
            $tail = file_get_contents($path, false, null, $start);
            if ($tail !== false) {
                $tail = ltrim($tail, "\r\n");
                file_put_contents($path, "[log truncated to keep the latest entries]\n" . $tail . $line . PHP_EOL, LOCK_EX);
                return;
            }
        }
    }

    file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function writeLog(string $msg): void
{
    global $logFile;
    appendCappedLog($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg, PICK_IMPORT_LOG_MAX_BYTES, PICK_IMPORT_LOG_RETAIN_BYTES);
}

function pickImportDebugEnabled(): bool
{
    $raw = getenv('VERIFY_PICK_DEBUG');
    if ($raw === false || $raw === '') {
        $raw = getenv('PICK_DEBUG');
    }
    if ($raw === false || $raw === '') {
        $raw = getenv('PICK_IMPORT_DEBUG');
    }

    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * @param array<string,mixed> $context
 */
function writeDebugLog(string $event, array $context = []): void
{
    if (!pickImportDebugEnabled()) {
        return;
    }

    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $event;
    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json !== false) {
            $line .= ' ' . $json;
        }
    }

    $debugFile = dirname($logFile) . '/import_debug.log';
    appendCappedLog($debugFile, $line, PICK_IMPORT_DEBUG_MAX_BYTES, PICK_IMPORT_DEBUG_RETAIN_BYTES);
}

function writeDriveRejectLog(string $fileName, string $reason, ?string $fileId = null): void
{
    global $PICK_IMPORT_DRIVE_REJECT_STATS;

    $label = trim($fileName);
    if ($label === '') {
        $label = '(unnamed file)';
    }
    $key = $reason === 'prefix mismatch' ? 'prefix_mismatch' : ($reason === 'unsupported extension' ? 'unsupported_extension' : 'other');
    if (!isset($PICK_IMPORT_DRIVE_REJECT_STATS[$key])) {
        $PICK_IMPORT_DRIVE_REJECT_STATS[$key] = 0;
    }
    $PICK_IMPORT_DRIVE_REJECT_STATS[$key]++;
    if (!isset($PICK_IMPORT_DRIVE_REJECT_STATS['samples']) || !is_array($PICK_IMPORT_DRIVE_REJECT_STATS['samples'])) {
        $PICK_IMPORT_DRIVE_REJECT_STATS['samples'] = [];
    }
    if (count($PICK_IMPORT_DRIVE_REJECT_STATS['samples']) < PICK_IMPORT_REJECT_SAMPLE_LIMIT) {
        $sample = $label;
        if ($fileId !== null && trim($fileId) !== '') {
            $sample .= ' (' . $fileId . ')';
        }
        $sample .= ' - ' . $reason;
        $PICK_IMPORT_DRIVE_REJECT_STATS['samples'][] = $sample;
    }
}

function normalizeHeader(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function findHeaderIndex(array $headers, array $aliases): ?string
{
    foreach ($headers as $idx => $header) {
        $norm = normalizeHeader((string) $header);
        if ($norm === '') {
            continue;
        }
        foreach ($aliases as $alias) {
            $aliasNorm = normalizeHeader($alias);
            if ($aliasNorm !== '' && ($norm === $aliasNorm || strpos($norm, $aliasNorm) !== false)) {
                return (string) $idx;
            }
        }
    }
    return null;
}

function findBestQuantityIndex(array $headers): ?string
{
    $bestIdx = null;
    $bestScore = null;
    foreach ($headers as $idx => $header) {
        $norm = normalizeHeader((string) $header);
        if ($norm === '') {
            continue;
        }
        if (strpos($norm, 'qty') === false && strpos($norm, 'quantity') === false) {
            continue;
        }
        $score = 0;
        if ($norm === 'quantity' || $norm === 'qty') {
            $score += 6;
        }
        if (strpos($norm, 'quantity') !== false) {
            $score += 3;
        }
        if (strpos($norm, 'per unit') !== false || strpos($norm, 'per unit of measure') !== false) {
            $score -= 4;
        }
        if (strpos($norm, 'base') !== false) {
            $score -= 3;
        }
        if (strpos($norm, 'outstanding') !== false) {
            $score -= 3;
        }
        if (strpos($norm, 'handled') !== false || strpos($norm, 'to handle') !== false) {
            $score -= 3;
        }
        if ($bestScore === null || $score > $bestScore) {
            $bestScore = $score;
            $bestIdx = (string) $idx;
        }
    }
    return $bestIdx;
}

function normalizeSkuCode(string $value): string
{
    $value = trim($value);
    $value = trim($value, "* \t\n\r\0\x0B");
    $value = preg_replace('/\s+/', '', $value) ?? '';
    $value = preg_replace('/-+$/', '', $value) ?? '';
    $value = strtoupper($value);
    return $value;
}

function normalizePickItemNo(string $value): string
{
    $value = trim($value);
    $value = trim($value, "* \t\n\r\0\x0B");
    $value = preg_replace('/\s+/', '', $value) ?? '';
    return strtoupper($value);
}


/**
 * Build authoritative BOGO item keys from raw warehouse rows.
 *
 * Call this after a duplicated whole-sheet block has been removed, but before
 * ordinary same-quantity/source-line warehouse rows are collapsed.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,array<string,true>> normalized order => normalized item => true
 */
function buildRawBogoItemKeysByOrder(array $rows, int $threshold = 3): array
{
    $threshold = max(3, $threshold);
    $counts = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $orderKey = strtoupper(trim((string) ($row['order_number'] ?? '')));
        $itemKey = normalizePickItemNo((string) ($row['item_no'] ?? $row['sku'] ?? ''));
        $qty = (int) ($row['quantity'] ?? 0);
        if ($orderKey === '' || $itemKey === '' || $qty <= 0) {
            continue;
        }

        if (!isset($counts[$orderKey])) {
            $counts[$orderKey] = [];
        }
        $counts[$orderKey][$itemKey] = (int) ($counts[$orderKey][$itemKey] ?? 0) + 1;
    }

    $keysByOrder = [];
    foreach ($counts as $orderKey => $itemCounts) {
        foreach ($itemCounts as $itemKey => $count) {
            if ((int) $count >= $threshold) {
                $keysByOrder[$orderKey][$itemKey] = true;
            }
        }
    }

    return $keysByOrder;
}

/**
 * Build BOGO keys from a parsed/session payload. This honors explicit parser
 * metadata and also falls back to counting raw payload rows when they are
 * present.
 *
 * @return array<string,array<string,true>>
 */
function buildParsedBogoItemKeysByOrder(array $data, string $fallbackOrderNumber = ''): array
{
    $fallbackOrderKey = strtoupper(trim($fallbackOrderNumber));
    $counts = [];
    $keysByOrder = [];

    foreach (($data['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $orderKey = strtoupper(trim((string) ($item['order_number'] ?? $fallbackOrderNumber)));
        if ($orderKey === '') {
            $orderKey = $fallbackOrderKey;
        }
        $itemKey = normalizePickItemNo((string) ($item['item_no'] ?? $item['sku'] ?? ''));
        if ($orderKey === '' || $itemKey === '') {
            continue;
        }

        $qty = (int) ($item['quantity'] ?? 0);
        if ($qty > 0) {
            $counts[$orderKey][$itemKey] = (int) ($counts[$orderKey][$itemKey] ?? 0) + 1;
        }

        $rawCount = (int) ($item['raw_occurrence_count'] ?? 0);
        if (!empty($item['is_bogo']) || $rawCount >= 3) {
            $keysByOrder[$orderKey][$itemKey] = true;
        }
    }

    foreach ($counts as $orderKey => $itemCounts) {
        foreach ($itemCounts as $itemKey => $count) {
            if ((int) $count >= 3) {
                $keysByOrder[$orderKey][$itemKey] = true;
            }
        }
    }

    $alerts = $data['duplicate_alerts'] ?? ($data['alerts'] ?? []);
    if (is_array($alerts)) {
        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $type = (string) ($alert['type'] ?? '');
            $count = (int) ($alert['row_count'] ?? $alert['count'] ?? 0);
            if ($type !== 'duplicate_item_threshold_reached' || $count < 3) {
                continue;
            }

            $orderKey = strtoupper(trim((string) ($alert['order_number'] ?? $fallbackOrderNumber)));
            if ($orderKey === '') {
                $orderKey = $fallbackOrderKey;
            }
            $itemKey = normalizePickItemNo((string) ($alert['item_no'] ?? ''));
            if ($orderKey !== '' && $itemKey !== '') {
                $keysByOrder[$orderKey][$itemKey] = true;
            }
        }
    }

    return $keysByOrder;
}

/**
 * Merge BOGO key maps without losing order/item identity.
 *
 * @param array<string,array<string,true>> ...$maps
 * @return array<string,array<string,true>>
 */
function mergeBogoItemKeysByOrder(array ...$maps): array
{
    $merged = [];
    foreach ($maps as $map) {
        foreach ($map as $orderKey => $itemKeys) {
            foreach ($itemKeys as $itemKey => $enabled) {
                if ($enabled) {
                    $merged[$orderKey][$itemKey] = true;
                }
            }
        }
    }
    return $merged;
}

/**
 * Apply authoritative BOGO flags to persisted items for the orders touched by
 * the current source file. Existing flags are cleared first so a re-import can
 * repair stale BOGO state.
 *
 * @param array<string,int> $orderIdsByNumber normalized order => order id
 * @param array<string,array<string,true>> $bogoKeysByOrder
 */
function applyPickImportBogoFlags(mysqli $conn, array $orderIdsByNumber, array $bogoKeysByOrder): int
{
    if ($orderIdsByNumber === [] || !pickTableColumnExists($conn, 'pick_items', 'is_bogo')) {
        return 0;
    }

    $clearStmt = $conn->prepare('UPDATE pick_items SET is_bogo = 0 WHERE order_id = ?');
    $selectStmt = $conn->prepare('SELECT id, item_no FROM pick_items WHERE order_id = ?');
    $setStmt = $conn->prepare('UPDATE pick_items SET is_bogo = 1 WHERE id = ?');
    if (!$clearStmt || !$selectStmt || !$setStmt) {
        if ($clearStmt) {
            $clearStmt->close();
        }
        if ($selectStmt) {
            $selectStmt->close();
        }
        if ($setStmt) {
            $setStmt->close();
        }
        return 0;
    }

    $updated = 0;

    foreach ($orderIdsByNumber as $orderKey => $orderId) {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            continue;
        }

        $clearStmt->bind_param('i', $orderId);
        if (!$clearStmt->execute()) {
            continue;
        }

        $wanted = $bogoKeysByOrder[$orderKey] ?? [];
        if ($wanted === []) {
            continue;
        }

        $selectStmt->bind_param('i', $orderId);
        if (!$selectStmt->execute()) {
            continue;
        }

        $result = $selectStmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $itemKey = normalizePickItemNo((string) ($row['item_no'] ?? ''));
            if ($itemKey === '' || empty($wanted[$itemKey])) {
                continue;
            }

            $itemId = (int) ($row['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $setStmt->bind_param('i', $itemId);
            if ($setStmt->execute()) {
                $updated += max(0, (int) $setStmt->affected_rows);
            }
        }

        if ($result instanceof mysqli_result) {
            $result->free();
        }
    }

    $clearStmt->close();
    $selectStmt->close();
    $setStmt->close();

    return $updated;
}

/**
 * Return true when every supplied cell is empty. Numeric zero is meaningful.
 *
 * @param array<mixed> $values
 */
function pickImportRowIsBlank(array $values): bool
{
    foreach ($values as $value) {
        if ($value !== null && trim((string) $value) !== '') {
            return false;
        }
    }
    return true;
}

/**
 * Build a source-line-independent signature used only to identify an exact
 * repeated worksheet block. It must not be used to deduplicate individual
 * BOGO lines.
 *
 * @param array<string,mixed> $row
 */
function pickImportLogicalRowSignature(array $row): string
{
    $orderNumber = strtoupper(trim((string) ($row['order_number'] ?? '')));
    $itemNo = normalizePickItemNo((string) ($row['item_no'] ?? ''));
    $quantity = (int) ($row['quantity'] ?? 0);
    $description = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($row['description'] ?? '')) ?? ''));

    return hash('sha256', $orderNumber . '|' . $itemNo . '|' . $quantity . '|' . $description);
}

/**
 * Remove only a high-confidence duplicated whole-sheet block.
 *
 * A repeated item, item/quantity pair, or BOGO sequence is not enough. The
 * complete meaningful row sequence must consist of two identical halves, each
 * half must contain at least four rows, and it must contain at least three
 * distinct logical rows.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{rows:array<int,array<string,mixed>>,duplicate_count:int,detected:bool}
 */
function collapseRepeatedWholeSheetBlock(array $rows): array
{
    $rowCount = count($rows);
    if ($rowCount < 8 || ($rowCount % 2) !== 0) {
        return ['rows' => $rows, 'duplicate_count' => 0, 'detected' => false];
    }

    $halfCount = intdiv($rowCount, 2);
    $firstSignatures = [];
    $secondSignatures = [];
    for ($i = 0; $i < $halfCount; $i++) {
        $firstSignatures[] = pickImportLogicalRowSignature($rows[$i]);
        $secondSignatures[] = pickImportLogicalRowSignature($rows[$i + $halfCount]);
    }

    if ($firstSignatures !== $secondSignatures || count(array_unique($firstSignatures)) < 3) {
        return ['rows' => $rows, 'duplicate_count' => 0, 'detected' => false];
    }

    return [
        'rows' => array_slice($rows, 0, $halfCount),
        'duplicate_count' => $halfCount,
        'detected' => true,
    ];
}

/**
 * Collapse ordinary duplicate warehouse rows without removing BOGO variants.
 *
 * Rows are compared by the sales order's own line identity:
 * Source No. + Source Line No. + Item No.
 *
 * Within each source-line group, keep only the first occurrence of each exact
 * Item No. + Quantity variant. This removes repeated warehouse rows while
 * preserving BOGO variants (for example 1, 6, 1, 6 becomes 1, 6).
 *
 * Excel's "Line No." is intentionally not part of the grouping key. It remains
 * on each retained row as source_line_no so legitimate repeated occurrences
 * can still be stored as separate pick_items records.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<string,array<string,true>> $bogoKeysByOrder
 * @return array{rows:array<int,array<string,mixed>>,duplicate_count:int,detected:bool}
 */
function collapseSameQuantitySourceLineRows(array $rows, array $bogoKeysByOrder = []): array
{
    $kept = [];
    $seenQuantityVariants = [];
    $usedPersistedIdentities = [];
    $duplicateCount = 0;
    foreach ($rows as $row) {
        $orderNumber = strtoupper(trim((string) ($row['order_number'] ?? '')));
        $salesSourceLineNo = (int) ($row['sales_source_line_no'] ?? 0);
        $sourceLineNo = (int) ($row['source_line_no'] ?? 0);
        $itemNo = normalizePickItemNo((string) ($row['item_no'] ?? ''));
        // Some files label the populated field "Source Line No." while
        // others provide it as the ordinary source_line_no field. Use either
        // field for duplicate detection, but keep source_line_no as the
        // persisted identity used by pick_items' unique key.
        $dedupeLineNo = $salesSourceLineNo > 0 ? $salesSourceLineNo : $sourceLineNo;
        if ($orderNumber === '' || $dedupeLineNo <= 0 || $itemNo === '') {
            $kept[] = $row;
            continue;
        }

        $quantity = (int) ($row['quantity'] ?? 0);
        $variantKey = $orderNumber . '|' . $dedupeLineNo . '|' . $itemNo . '|' . $quantity;
        $persistedSourceLineNo = $sourceLineNo > 0 ? $sourceLineNo : $dedupeLineNo;
        $persistedIdentity = $orderNumber . '|' . $persistedSourceLineNo . '|' . $itemNo . '|' . $quantity;
        $occurrence = (int) ($seenQuantityVariants[$variantKey] ?? 0) + 1;
        $seenQuantityVariants[$variantKey] = $occurrence;
        // Import files contain paired warehouse rows. Keep one row for every
        // pair: 2 raw rows become 1, 4 become 2, 6 become 3, etc.
        if (($occurrence % 2) === 0) {
            $duplicateCount++;
            continue;
        }

        if ($occurrence > 1) {
            $syntheticSourceLineNo = $persistedSourceLineNo + 1;
            do {
                $syntheticIdentity = $orderNumber . '|' . $syntheticSourceLineNo . '|' . $itemNo . '|' . $quantity;
                $syntheticSourceLineNo++;
            } while (isset($usedPersistedIdentities[$syntheticIdentity]));
            $row['source_line_no'] = $syntheticSourceLineNo - 1;
            $persistedIdentity = $orderNumber . '|' . ($syntheticSourceLineNo - 1) . '|' . $itemNo . '|' . $quantity;
        }
        $kept[] = $row;
        $usedPersistedIdentities[$persistedIdentity] = true;
    }

    return [
        'rows' => $kept,
        'duplicate_count' => $duplicateCount,
        'detected' => $duplicateCount > 0,
    ];
}

/**
 * Keep the Take rows of a warehouse pick export and flag BOGO per source line.
 *
 * BC writes a Take row and a Place row for every pick movement. When the
 * export has an Action Type column, Place rows are dropped directly. Without
 * it, Take/Place pairs are collapsed in sheet order by
 * collapseSameQuantitySourceLineRows().
 *
 * A source line is BOGO when it has more than one Take row (e.g. Take 1 and
 * Take 3); every kept row of that source line gets is_bogo = 1. Quantities are
 * not summed, so a single Take of 3 is not BOGO.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{rows:array<int,array<string,mixed>>,duplicate_count:int,detected:bool}
 */
function selectPickTakeRows(array $rows, bool $hasActionType): array
{
    if ($hasActionType) {
        $kept = [];
        $placeCount = 0;
        foreach ($rows as $row) {
            if (strcasecmp(trim((string) ($row['action_type'] ?? '')), 'Place') === 0) {
                $placeCount++;
                continue;
            }
            $kept[] = $row;
        }
        $result = [
            'rows' => $kept,
            'duplicate_count' => $placeCount,
            'detected' => $placeCount > 0,
        ];
    } else {
        $result = collapseSameQuantitySourceLineRows($rows);
    }

    $sourceLineKey = static function (array $row): string {
        $orderNumber = strtoupper(trim((string) ($row['order_number'] ?? '')));
        $salesSourceLineNo = (int) ($row['sales_source_line_no'] ?? 0);
        $itemNo = normalizePickItemNo((string) ($row['item_no'] ?? ''));
        if ($orderNumber === '' || $salesSourceLineNo <= 0 || $itemNo === '') {
            return '';
        }
        return $orderNumber . '|' . $salesSourceLineNo . '|' . $itemNo;
    };

    $takesBySourceLine = [];
    foreach ($result['rows'] as $row) {
        $key = $sourceLineKey($row);
        if ($key !== '') {
            $takesBySourceLine[$key] = (int) ($takesBySourceLine[$key] ?? 0) + 1;
        }
    }
    foreach ($result['rows'] as &$row) {
        $key = $sourceLineKey($row);
        $row['is_bogo'] = ($key !== '' && $takesBySourceLine[$key] > 1) ? 1 : 0;
    }
    unset($row);

    return $result;
}

/**
 * @param array<string,array<string,mixed>> $groups
 * @return array<int,array<string,mixed>>
 */
function buildDuplicateImportAlerts(array $groups): array
{
    $alerts = [];
    foreach ($groups as $group) {
        $quantitiesSeen = array_values(array_map('intval', (array) ($group['quantities_seen'] ?? [])));
        $distinctQuantities = array_values(array_unique($quantitiesSeen));
        $rowCount = count($quantitiesSeen);
        if ($rowCount <= 2 && count($distinctQuantities) <= 1) {
            continue;
        }

        $itemNo = (string) ($group['item_no'] ?? '');
        $orderNumber = (string) ($group['order_number'] ?? '');
        $sourceLineNo = (int) ($group['source_line_no'] ?? 0);
        $message = "Item {$itemNo} appears {$rowCount} times";
        if ($orderNumber !== '') {
            $message .= " for order {$orderNumber}";
        }
        if ($sourceLineNo > 0) {
            $message .= ", line {$sourceLineNo}";
        }
        $message .= '.';

        $alerts[] = [
            'type' => count($distinctQuantities) > 1 ? 'duplicate_quantity_variant' : 'duplicate_item_threshold_reached',
            'order_number' => $orderNumber,
            'source_line_no' => $sourceLineNo,
            'item_no' => $itemNo,
            'row_count' => $rowCount,
            'quantities_seen' => $quantitiesSeen,
            'quantities_imported' => array_values(array_map('intval', (array) ($group['quantities_imported'] ?? []))),
            'quantities_skipped' => array_values(array_map('intval', (array) ($group['quantities_skipped'] ?? []))),
            'message' => $message,
        ];
    }

    return $alerts;
}

/**
 * Build search variants for a sales/pick order number.
 *
 * @return array<int,string>
 */
function buildOrderNumberVariants(string $orderNumber): array
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
 * Locate an existing pick order by order number.
 */
function findPickOrderByNumber(mysqli $conn, string $orderNumber): ?array
{
    foreach (buildOrderNumberVariants($orderNumber) as $candidate) {
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

if (!function_exists('pickTableColumnExists')) {
    /**
     * Check whether a table column exists.
     */
    function pickTableColumnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '') {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }
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
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND index_name = ?
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
        writeLog('Unable to add unique index ' . $indexName . ' on ' . $table . ': ' . $conn->error);
    }
}

function dropPickIndexIfExists(mysqli $conn, string $table, string $indexName): void
{
    $table = trim($table);
    $indexName = trim($indexName);
    if ($table === '' || $indexName === '') {
        return;
    }
    if (!pickIndexExists($conn, $table, $indexName)) {
        return;
    }

    $sql = "ALTER TABLE `{$table}` DROP INDEX `{$indexName}`";
    if (!$conn->query($sql)) {
        writeLog('Unable to drop legacy unique index ' . $indexName . ' on ' . $table . ': ' . $conn->error);
    }
}

function ensurePickImportUniqueness(mysqli $conn): void
{
    ensurePickUniqueIndex($conn, 'pick_orders', 'uq_pick_orders_order_number', '`order_number`');
    if (!pickTableColumnExists($conn, 'pick_items', 'source_line_no')) {
        @$conn->query('ALTER TABLE pick_items ADD COLUMN source_line_no INT UNSIGNED NOT NULL DEFAULT 0 AFTER order_id');
    }
    if (!pickTableColumnExists($conn, 'pick_items', 'is_bogo')) {
        @$conn->query('ALTER TABLE pick_items ADD COLUMN is_bogo TINYINT(1) NOT NULL DEFAULT 0 AFTER qty_ordered');
    }
    dropPickIndexIfExists($conn, 'pick_items', 'uq_pick_items_order_item_no');
    dropPickIndexIfExists($conn, 'pick_items', 'uq_pick_items_order_line_item_no');
    ensurePickUniqueIndex($conn, 'pick_items', 'uq_pick_items_order_line_item_qty', '`order_id`, `source_line_no`, `item_no`, `qty_ordered`');
}

function ensurePickImportAuditTable(mysqli $conn): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $sql = '
        CREATE TABLE IF NOT EXISTS pick_import_audits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type VARCHAR(32) NOT NULL,
            source_name VARCHAR(255) NOT NULL DEFAULT "",
            source_file VARCHAR(255) NOT NULL DEFAULT "",
            source_path VARCHAR(512) DEFAULT NULL,
            source_checksum CHAR(64) DEFAULT NULL,
            sales_order_numbers VARCHAR(1024) DEFAULT NULL,
            worksheet_row_count INT UNSIGNED NOT NULL DEFAULT 0,
            parsed_row_count INT UNSIGNED NOT NULL DEFAULT 0,
            imported_row_count INT UNSIGNED NOT NULL DEFAULT 0,
            duplicate_row_count INT UNSIGNED NOT NULL DEFAULT 0,
            rejected_row_count INT UNSIGNED NOT NULL DEFAULT 0,
            integrity_match TINYINT(1) NOT NULL DEFAULT 1,
            details_json LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_pick_import_audits_source_type (source_type),
            KEY idx_pick_import_audits_source_file (source_file),
            KEY idx_pick_import_audits_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ';

    @$conn->query($sql);
    ensurePickImportAuditColumn($conn, 'sales_order_numbers', 'VARCHAR(1024) DEFAULT NULL AFTER source_checksum');
    // Used by findPriorPickImportAudit() to recognise a file imported before.
    foreach ([
        'idx_pick_import_audits_source_checksum' => 'source_checksum',
        'idx_pick_import_audits_source_name' => 'source_name',
    ] as $indexName => $column) {
        if (!pickIndexExists($conn, 'pick_import_audits', $indexName)) {
            @$conn->query("ALTER TABLE pick_import_audits ADD KEY `{$indexName}` (`{$column}`)");
        }
    }
    $initialized = true;
}

function pickImportAuditColumnExists(mysqli $conn, string $column): bool
{
    $column = trim($column);
    if ($column === '') {
        return false;
    }
    $stmt = $conn->prepare('
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
        LIMIT 1
    ');
    if (!$stmt) {
        return false;
    }
    $table = 'pick_import_audits';
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function ensurePickImportAuditColumn(mysqli $conn, string $column, string $definition): void
{
    if (pickImportAuditColumnExists($conn, $column)) {
        return;
    }
    $column = trim($column);
    $definition = trim($definition);
    if ($column === '' || $definition === '') {
        return;
    }
    @$conn->query("ALTER TABLE pick_import_audits ADD COLUMN {$column} {$definition}");
}

/**
 * @param array<string,mixed> $audit
 */
function writePickImportAudit(mysqli $conn, array $audit): bool
{
    ensurePickImportAuditTable($conn);

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
            'Unable to prepare pick import audit insert: ' .
            ($conn->error ?: 'unknown error')
        );

        return false;
    }

    $sourceType = trim((string) ($audit['source_type'] ?? 'unknown'));
    $sourceType = $sourceType !== '' ? mb_substr($sourceType, 0, 32) : 'unknown';

    $sourceName = trim((string) ($audit['source_name'] ?? ''));
    $sourceName = $sourceName !== '' ? mb_substr($sourceName, 0, 255) : '';

    $sourceFile = trim((string) ($audit['source_file'] ?? ''));
    $sourceFile = $sourceFile !== '' ? mb_substr($sourceFile, 0, 255) : '';

    $sourcePath = trim((string) ($audit['source_path'] ?? ''));
    $sourcePath = $sourcePath !== '' ? mb_substr($sourcePath, 0, 512) : null;

    $checksum = trim((string) ($audit['source_checksum'] ?? ''));
    $checksum = $checksum !== '' ? mb_substr($checksum, 0, 64) : null;

    $salesOrderNumbers = trim(
        (string) ($audit['sales_order_numbers'] ?? '')
    );

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
        $checksum,
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
            'Unable to record pick import audit: ' .
            ($stmt->error ?: $conn->error ?: 'unknown error')
        );

        $stmt->close();
        return false;
    }

    $stmt->close();
    return true;
}

/**
 * Copy an imported source file to the archive directory and return file metadata.
 *
 * @return array{archive_path:?string,checksum:?string,size:int}
 */
function archiveImportedSourceFile(string $sourcePath, string $archiveDir, string $sourceName, string $sourceKey = ''): array
{
    $sourcePath = trim($sourcePath);
    $archiveDir = rtrim(trim($archiveDir), "\\/");
    if ($sourcePath === '' || !is_file($sourcePath)) {
        return [
            'archive_path' => null,
            'checksum' => null,
            'size' => 0,
        ];
    }

    if ($archiveDir === '') {
        return [
            'archive_path' => null,
            'checksum' => is_file($sourcePath) ? (hash_file('sha256', $sourcePath) ?: null) : null,
            'size' => is_file($sourcePath) ? (int) (@filesize($sourcePath) ?: 0) : 0,
        ];
    }

    if (!is_dir($archiveDir)) {
        @mkdir($archiveDir, 0777, true);
    }

    $checksum = hash_file('sha256', $sourcePath) ?: null;
    $size = (int) (@filesize($sourcePath) ?: 0);
    $baseName = trim($sourceName) !== '' ? basename($sourceName) : basename($sourcePath);
    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName) ?? 'imported_file';
    $safeKey = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($sourceKey)) ?? '';
    $prefix = date('Ymd_His') . ($safeKey !== '' ? '_' . $safeKey : '');
    $destPath = $archiveDir . DIRECTORY_SEPARATOR . $prefix . '_' . $safeBase;
    $suffix = 1;
    while (is_file($destPath)) {
        $destPath = $archiveDir . DIRECTORY_SEPARATOR . $prefix . '_' . $suffix . '_' . $safeBase;
        $suffix++;
    }
    if (!copy($sourcePath, $destPath)) {
        throw new RuntimeException('Unable to archive imported source file.');
    }

    return [
        'archive_path' => $destPath,
        'checksum' => $checksum,
        'size' => $size,
    ];
}

/**
 * Emit a JSON response and stop execution.
 *
 * @param array<string,mixed> $payload
 */
function respondImportJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

ensurePickImportUniqueness($conn);

/**
 * Persist parsed pick data from the web import modal into the database.
 *
 * @param array<string,mixed> $data
 * @param array<string,mixed> $auditMeta
 * @return array<string,mixed>
 */
function importParsedPickData(mysqli $conn, array $data, array $auditMeta = []): array
{
    $pickOrderNumber = trim((string) ($data['pick_order_number'] ?? ''));
    $salesOrderNumber = trim((string) ($data['sales_order_number'] ?? ''));
    $orderNumber = $salesOrderNumber !== '' ? $salesOrderNumber : $pickOrderNumber;
    if ($orderNumber === '') {
        throw new RuntimeException('No sales order number was found in the uploaded file.');
    }

    $existingOrder = findPickOrderByNumber($conn, $orderNumber);
    if (is_array($existingOrder)) {
        $existingOrderId = (int) ($existingOrder['id'] ?? 0);
        if ($existingOrderId > 0) {
            $orderKey = strtoupper(trim($orderNumber));
            $bogoKeysByOrder = buildParsedBogoItemKeysByOrder($data, $orderNumber);
            $bogoUpdated = applyPickImportBogoFlags(
                $conn,
                [$orderKey => $existingOrderId],
                $bogoKeysByOrder
            );
            writeDebugLog('session_import_existing_bogo_flags_applied', [
                'order_number' => $orderNumber,
                'order_id' => $existingOrderId,
                'updated_rows' => $bogoUpdated,
                'bogo_items' => array_keys($bogoKeysByOrder[$orderKey] ?? []),
            ]);
        }
        throw new RuntimeException('This sales order has already been entered.');
    }

    $items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
    if ($items === []) {
        throw new RuntimeException('No line items were found in the uploaded file.');
    }

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

    $itemInsertSqlWithSku = '
        INSERT INTO pick_items
            (order_id, source_line_no, sku_id, item_no, description, qty_ordered, qty_verified, line_hash' .
        ($hasShortShip ? ', is_short_ship' : '') .
        ($hasPackerVerify ? ', qty_verified_packer' : '') .
        ')
        VALUES
            (?, ?, ?, ?, ?, ?, 0, ?' .
        ($hasShortShip ? ', ?' : '') .
        ($hasPackerVerify ? ', ?' : '') .
        ')
    ';
    $itemInsertSqlWithoutSku = '
        INSERT INTO pick_items
            (order_id, source_line_no, sku_id, item_no, description, qty_ordered, qty_verified, line_hash' .
        ($hasShortShip ? ', is_short_ship' : '') .
        ($hasPackerVerify ? ', qty_verified_packer' : '') .
        ')
        VALUES
            (?, ?, NULL, ?, ?, ?, 0, ?' .
        ($hasShortShip ? ', ?' : '') .
        ($hasPackerVerify ? ', ?' : '') .
        ')
    ';
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
        $orderId = (int) $conn->insert_id;
        $orderStmt->close();
        if ($orderId <= 0) {
            throw new RuntimeException('Unable to create pick order.');
        }

        $preparedItems = [];
        $blankRowsIgnored = 0;
        foreach ($items as $itemIndex => $item) {
            if (!is_array($item)) {
                $blankRowsIgnored++;
                continue;
            }
            $itemNoRaw = trim((string) ($item['item_no'] ?? $item['sku'] ?? ''));
            $skuRaw = trim((string) ($item['sku'] ?? $itemNoRaw));
            $quantityRaw = $item['quantity'] ?? '';
            $description = trim((string) ($item['description'] ?? ''));
            if (pickImportRowIsBlank([$itemNoRaw, $skuRaw, $quantityRaw, $description])) {
                $blankRowsIgnored++;
                continue;
            }
            $sourceLineNo = (int) (
                $item['source_line_no']
                ?? $item['line_no']
                ?? $item['row_number']
                ?? 0
            );
            $salesSourceLineNo = (int) (
                $item['sales_source_line_no']
                ?? $item['source_document_line_no']
                ?? 0
            );
            if ($sourceLineNo <= 0) {
                $sourceLineNo = $itemIndex + 1;
            }
            $preparedItems[] = [
                'order_number' => $orderNumber,
                'source_line_no' => $sourceLineNo,
                'sales_source_line_no' => $salesSourceLineNo,
                'item_no' => $itemNoRaw,
                'sku' => $skuRaw,
                'quantity' => (int) $quantityRaw,
                'description' => $description,
            ];
        }

        $meaningfulRowCount = count($preparedItems);
        $parsedBogoItemKeysByOrder = mergeBogoItemKeysByOrder(
            buildParsedBogoItemKeysByOrder($data, $orderNumber),
            buildRawBogoItemKeysByOrder($preparedItems)
        );

        $blockResult = collapseRepeatedWholeSheetBlock($preparedItems);
        $preparedItems = $blockResult['rows'];
        $sourceLineResult = collapseSameQuantitySourceLineRows($preparedItems, $parsedBogoItemKeysByOrder);
        $preparedItems = $sourceLineResult['rows'];

        $importedCount = 0;
        $lineNumber = 0;
        $duplicateCount = (int) $blockResult['duplicate_count']
            + (int) $sourceLineResult['duplicate_count'];
        $rejectedCount = 0;
        $duplicateCounts = [];
        $duplicateGroups = [];
        $duplicateSamples = [];
        $duplicateAlerts = [];
        $parsedRowCount = $meaningfulRowCount;
        $worksheetRowCount = $meaningfulRowCount;
        $salesOrderNumberSet = [strtoupper(trim($orderNumber)) => true];
        foreach ($preparedItems as $item) {
            $lineNumber++;
            $sourceLineNo = max(1, (int) ($item['source_line_no'] ?? $lineNumber));
            $itemNoRaw = trim((string) ($item['item_no'] ?? ''));
            $sku = normalizeSkuCode((string) ($item['sku'] ?? $itemNoRaw));
            $qty = (int) ($item['quantity'] ?? 0);
            $desc = trim((string) ($item['description'] ?? ''));
            if ($itemNoRaw === '' || $qty <= 0) {
                $rejectedCount++;
                continue;
            }

            $itemNoKey = normalizePickItemNo($itemNoRaw);
            if ($itemNoKey === '') {
                $rejectedCount++;
                continue;
            }
            if (!isset($duplicateCounts[$itemNoKey])) {
                $duplicateCounts[$itemNoKey] = 0;
            }
            $duplicateCounts[$itemNoKey]++;
            if (!isset($duplicateGroups[$itemNoKey])) {
                $duplicateGroups[$itemNoKey] = [
                    'order_number' => $orderNumber,
                    'source_line_no' => $sourceLineNo,
                    'item_no' => $itemNoRaw,
                    'quantities_seen' => [],
                    'quantities_imported' => [],
                    'quantities_skipped' => [],
                ];
            }
            $duplicateGroups[$itemNoKey]['quantities_seen'][] = $qty;
            $duplicateGroups[$itemNoKey]['quantities_imported'][] = $qty;

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

            $lineHash = hash('sha256', $orderNumber . '|' . $sourceLineNo . '|' . $itemNoKey . '|' . $qty);
            $useSku = is_int($skuId) && $skuId > 0;
            $itemStmt = $useSku ? $itemStmtWithSku : $itemStmtWithoutSku;
            if (!$useSku) {
                writeDebugLog('session_import_missing_sku', [
                    'order_number' => $orderNumber,
                    'item_no' => $itemNoRaw,
                    'row_number' => $lineNumber,
                    'source_name' => (string) ($sourceFile ?? ''),
                ]);
            }
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
            $importedCount++;
        }

        $duplicateAlerts = buildDuplicateImportAlerts($duplicateGroups);

        if ($duplicateSamples !== []) {
            writeDebugLog('session_import_duplicate_item_no_summary', [
                'order_number' => $orderNumber,
                'source_name' => $sourceFile ?? '',
                'duplicate_count' => $duplicateCount,
                'sample_rows' => $duplicateSamples,
            ]);
        }

        if ($duplicateAlerts !== []) {
            writeDebugLog('session_import_duplicate_item_threshold_alert', [
                'order_number' => $orderNumber,
                'source_name' => $sourceFile ?? '',
                'alerts' => array_values($duplicateAlerts),
            ]);
        }

        if ($importedCount <= 0) {
            throw new RuntimeException('No valid line items were found to import.');
        }

        $orderKey = strtoupper(trim($orderNumber));
        $bogoUpdated = applyPickImportBogoFlags(
            $conn,
            [$orderKey => $orderId],
            $parsedBogoItemKeysByOrder
        );
        writeDebugLog('session_import_bogo_flags_applied', [
            'order_number' => $orderNumber,
            'order_id' => $orderId,
            'updated_rows' => $bogoUpdated,
            'bogo_items' => array_keys($parsedBogoItemKeysByOrder[$orderKey] ?? []),
        ]);


        $auditWritten = writePickImportAudit($conn, [
            'source_type' => (string) ($auditMeta['source_type'] ?? 'session'),
            'source_name' => (string) ($auditMeta['source_name'] ?? ($sourceFile ?? '')),
            'source_file' => (string) ($auditMeta['source_file'] ?? ($sourceFile ?? '')),
            'source_path' => (string) ($auditMeta['source_path'] ?? ''),
            'source_checksum' => (string) ($auditMeta['source_checksum'] ?? ''),
            'sales_order_numbers' => '|' . implode('|', array_values(array_filter(array_keys($salesOrderNumberSet), static fn(string $value): bool => trim($value) !== ''))) . '|',
            'worksheet_row_count' => $worksheetRowCount,
            'parsed_row_count' => $parsedRowCount,
            'imported_row_count' => $importedCount,
            'duplicate_row_count' => $duplicateCount,
            'rejected_row_count' => $rejectedCount,
            'integrity_match' => (($importedCount + $duplicateCount + $rejectedCount) === $parsedRowCount),
            'details_json' => [
                'order_number' => $orderNumber,
                'source_name' => $sourceFile ?? '',
                'duplicate_samples' => $duplicateSamples,
                'duplicate_alerts' => array_values($duplicateAlerts),
                // 'item_count_received' => count($items),
                'blank_rows_ignored' => $blankRowsIgnored,
                'repeated_whole_sheet_block_detected' => (bool) $blockResult['detected'],
                'repeated_block_rows_skipped' => (int) $blockResult['duplicate_count'],
                'same_quantity_source_line_duplicates_detected' => (bool) $sourceLineResult['detected'],
                'same_quantity_source_line_rows_skipped' => (int) $sourceLineResult['duplicate_count'],
            ],
        ]);

        if (!$auditWritten) {
            error_log(
                '[PICK AUDIT FAILED] sales_orders=' .
                implode(',', array_keys($salesOrderNumberSet))
            );
        }

        $conn->commit();
        $itemStmtWithSku->close();
        $itemStmtWithoutSku->close();
        $skuLookupStmt->close();

        return [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'item_count' => $importedCount,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
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
 * Import the parsed verify-pick upload stored in the current session.
 */
function importSessionPickData(mysqli $conn): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $stored = $_SESSION['verify_pick_data'] ?? null;
    if (!is_array($stored) || empty($stored['items']) || !is_array($stored['items'])) {
        respondImportJson([
            'ok' => false,
            'error' => 'No parsed upload is available to import. Please extract and sort the file first.',
        ], 400);
    }

    $orderNumber = trim((string) (
        $stored['sales_order_number']
        ?? $stored['pick_order_number']
        ?? ''
    ));
    writeDebugLog('session_import_begin', [
        'order_number' => $orderNumber,
        'item_count' => is_array($stored['items'] ?? null) ? count($stored['items']) : 0,
        'source_name' => (string) ($stored['source_name'] ?? ''),
    ]);
    if ($orderNumber === '') {
        respondImportJson([
            'ok' => false,
            'error' => 'No sales order number was found in the parsed upload.',
        ], 400);
    }

    $existing = findPickOrderByNumber($conn, $orderNumber);
    if (is_array($existing)) {
        $existingOrderId = (int) ($existing['id'] ?? 0);
        $orderKey = strtoupper(trim($orderNumber));
        $bogoKeysByOrder = buildParsedBogoItemKeysByOrder($stored, $orderNumber);
        $bogoUpdated = $existingOrderId > 0
            ? applyPickImportBogoFlags($conn, [$orderKey => $existingOrderId], $bogoKeysByOrder)
            : 0;

        writeDebugLog('session_import_duplicate', [
            'order_number' => $orderNumber,
            'order_id' => $existingOrderId,
            'status' => (string) ($existing['status'] ?? ''),
            'bogo_rows_updated' => $bogoUpdated,
            'bogo_items' => array_keys($bogoKeysByOrder[$orderKey] ?? []),
        ]);
        respondImportJson([
            'ok' => false,
            'error' => 'This sales order has already been entered.',
            'order_id' => (int) ($existing['id'] ?? 0),
            'order_number' => (string) ($existing['order_number'] ?? $orderNumber),
            'redirect' => '?action=load_order&order_id=' . (int) ($existing['id'] ?? 0) . '&order_number=' . urlencode((string) ($existing['order_number'] ?? $orderNumber)) . '&workflow=pick',
        ], 409);
    }

    try {
        $result = importParsedPickData($conn, $stored, [
            'source_type' => 'session',
            'source_name' => (string) ($stored['source_name'] ?? ''),
            'source_file' => (string) ($stored['source_name'] ?? ''),
            'worksheet_row_count' => (int) ($stored['worksheet_row_count'] ?? (is_array($stored['items'] ?? null) ? count($stored['items']) : 0)),
            'parsed_row_count' => (int) ($stored['parsed_row_count'] ?? (is_array($stored['items'] ?? null) ? count($stored['items']) : 0)),
        ]);
    } catch (Throwable $e) {
        writeDebugLog('session_import_failed', [
            'order_number' => $orderNumber,
            'error' => $e->getMessage(),
        ]);
        respondImportJson([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 400);
    }

    $orderId = (int) ($result['order_id'] ?? 0);
    $importedOrderNumber = (string) ($result['order_number'] ?? $orderNumber);
    writeDebugLog('session_import_complete', [
        'order_id' => $orderId,
        'order_number' => $importedOrderNumber,
        'item_count' => (int) ($result['item_count'] ?? 0),
    ]);
    respondImportJson([
        'ok' => true,
        'order_id' => $orderId,
        'order_number' => $importedOrderNumber,
        'item_count' => (int) ($result['item_count'] ?? 0),
        'redirect' => '?action=load_order&order_id=' . $orderId . '&order_number=' . urlencode($importedOrderNumber) . '&workflow=pick',
    ]);
}

/**
 * List pick files from Google Drive.
 *
 * @return array<int, array{id:string,name:string,modified:string,modified_ts:int}>
 */
function listDrivePickFiles(string $serviceAccountPath, $folderId, $namePrefix): array
{
    global $PICK_IMPORT_DRIVE_REJECT_STATS;

    $folderIds = [];
    if (is_array($folderId)) {
        $folderIds = array_values(array_filter(array_map('trim', $folderId), static fn($v) => $v !== ''));
    } elseif (is_string($folderId) && trim($folderId) !== '') {
        $folderIds = [trim($folderId)];
    }

    if (!class_exists('Google\\Client') || empty($folderIds) || !is_file($serviceAccountPath)) {
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
            'fields' => 'files(id,name,modifiedTime,parents)',
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
            writeLog('Drive API error: ' . $e->getMessage());
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
        $PICK_IMPORT_DRIVE_REJECT_STATS['total_seen']++;
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
                writeDriveRejectLog($name, 'prefix mismatch', (string) $file->getId());
                continue;
            }
        }
        if (!preg_match('/\.(xlsx|xlsm)$/i', $name)) {
            writeDriveRejectLog($name, 'unsupported extension', (string) $file->getId());
            continue;
        }
        $modified = (string) $file->getModifiedTime();
        $modifiedTs = $modified !== '' ? strtotime($modified) : 0;
        $out[] = [
            'id' => (string) $file->getId(),
            'name' => $name,
            'modified' => $modified,
            'modified_ts' => $modifiedTs,
        ];
        $PICK_IMPORT_DRIVE_REJECT_STATS['accepted']++;
    }

    usort($out, static fn($a, $b) => ($b['modified_ts'] ?? 0) <=> ($a['modified_ts'] ?? 0));
    return $out;
}

/**
 * Download a Drive file to a local temp path.
 *
 * @return array{path:string,name:string,cleanup:bool}
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
    $response = $service->files->get($fileId, ['alt' => 'media']);
    $content = $response->getBody()->getContents();

    $tmpPath = tempnam(sys_get_temp_dir(), 'gdrive_');
    if (!$tmpPath) {
        throw new RuntimeException('Unable to create temp file.');
    }
    if (file_put_contents($tmpPath, $content) === false) {
        throw new RuntimeException('Unable to write Drive download to temp file.');
    }

    return [
        'path' => $tmpPath,
        'name' => (string) $meta->getName(),
        'cleanup' => true,
    ];
}

function shouldSkipDriveFile(mysqli $conn, string $fileId): bool
{
    /*
     * Normal Drive imports must never reprocess historical files just because
     * their import audit predates a BOGO-detection change. Reprocessing every
     * existing Drive file can make the browser request run for minutes or time
     * out, which makes the "Import from Google Drive" button appear broken.
     *
     * A Drive file is considered already imported when its immutable Google
     * Drive file ID is already stored in pick_orders.source_file.
     */
    $stmt = $conn->prepare("SELECT id FROM pick_orders WHERE source_file = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $fileId);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }

    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // A file skipped as a copy of an earlier import never touches
    // pick_orders, so its skip audit is what marks the file ID as handled.
    if (!$exists) {
        ensurePickImportAuditTable($conn);
        $stmt = $conn->prepare("
            SELECT id FROM pick_import_audits
            WHERE source_file = ?
              AND details_json LIKE '%\"status\":\"skipped_already_imported\"%'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $fileId);
            if ($stmt->execute()) {
                $exists = (bool) $stmt->get_result()->fetch_assoc();
            }
            $stmt->close();
        }
    }

    if ($exists) {
        writeDebugLog('drive_file_skipped_existing_source', [
            'file_id' => $fileId,
        ]);
    }

    return $exists;
}

/**
 * Find an earlier import of the same pick file, from any source (Drive or a
 * manual upload on the Verify Pick page), so one file is never imported twice.
 *
 * Matches on the SHA-256 checksum of the file contents, or on the filename.
 * The filename match covers manual uploads made before checksums were recorded.
 * Runs rejected for unreadable headers and earlier skips do not count as imports.
 */
function findPriorPickImportAudit(mysqli $conn, string $checksum, string $fileName): ?array
{
    $checksum = trim($checksum);
    $fileName = mb_substr(trim($fileName), 0, 255);
    if ($checksum === '' && $fileName === '') {
        return null;
    }

    ensurePickImportAuditTable($conn);
    $stmt = $conn->prepare("
        SELECT id, source_type, created_at
        FROM pick_import_audits
        WHERE ((? <> '' AND source_checksum = ?) OR (? <> '' AND source_name = ?))
          AND (
              details_json IS NULL
              OR (
                  details_json NOT LIKE '%\"status\":\"rejected_headers\"%'
                  AND details_json NOT LIKE '%\"status\":\"skipped_already_imported\"%'
              )
          )
        ORDER BY id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ssss', $checksum, $checksum, $fileName, $fileName);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

/**
 * Record that a Drive file was skipped because it was already imported.
 *
 * @param array<string,mixed> $prior
 */
function writeSkippedDrivePickImportAudit(
    mysqli $conn,
    string $fileId,
    string $fileName,
    string $checksum,
    array $prior,
    string $matchedOn
): void {
    writePickImportAudit($conn, [
        'source_type' => 'drive',
        'source_name' => $fileName,
        'source_file' => $fileId,
        'source_path' => '',
        'source_checksum' => $checksum,
        'integrity_match' => true,
        'details_json' => [
            'file_name' => $fileName,
            'source_file' => $fileId,
            'status' => 'skipped_already_imported',
            'matched_on' => $matchedOn,
            'matched_audit_id' => (int) ($prior['id'] ?? 0),
            'matched_source_type' => (string) ($prior['source_type'] ?? ''),
            'matched_created_at' => (string) ($prior['created_at'] ?? ''),
        ],
    ]);
    writeDebugLog('drive_file_skipped_already_imported', [
        'file_id' => $fileId,
        'file_name' => $fileName,
        'matched_on' => $matchedOn,
        'matched_audit_id' => (int) ($prior['id'] ?? 0),
    ]);
    writeLog('Skipped Drive file already imported: ' . $fileName
        . ' (matched ' . $matchedOn . ' of audit #' . (int) ($prior['id'] ?? 0) . ')');
}


/**
 * Process a spreadsheet and write to DB.
 *
 * @param array<string,mixed> $auditMeta
 * @return array{status:string,import_count:int,dup_count:int,touched_order_count:int}
 */
function importPickSheet(mysqli $conn, string $filePath, string $fileName, ?string $sourceFile = null, array $auditMeta = []): array
{
    // Drive imports can run without verify_pick.php having initialized the
    // upgrade-safe import schema first.
    ensurePickImportUniqueness($conn);

    writeDebugLog('drive_import_begin', [
        'file_name' => $fileName,
        'source_file' => $sourceFile,
        'file_path' => $filePath,
    ]);
    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, true);
    $physicalWorksheetRowCount = count($data);

    $headers = array_shift($data);
    $map = [
        'source_no' => findHeaderIndex($headers, [
            'source no',
            'source no.',
            'source number',
            'source #',
            'sales order',
            'sales order #',
            'sales order no',
            'sales order number',
        ]),
        'line_no' => findHeaderIndex($headers, [
            'line no',
            'line number',
            'line #',
        ]),
        'sales_source_line_no' => findHeaderIndex($headers, [
            'source line no',
            'source line number',
            'source line #',
        ]),
        'item_no' => findHeaderIndex($headers, ['item no', 'item number', 'item #', 'sku', 'sku number']),
        'desc' => findHeaderIndex($headers, ['description', 'desc', 'item description']),
        'qty' => findHeaderIndex($headers, ['quantity', 'qty', 'qty ordered', 'order qty', 'pick qty']),
    ];

    $bestQty = findBestQuantityIndex($headers);
    if ($bestQty !== null) {
        $map['qty'] = $bestQty;
    }

    if (in_array(null, $map, true)) {
        writeDebugLog('drive_import_rejected_headers', [
            'file_name' => $fileName,
            'source_file' => $sourceFile,
            'header_map' => $map,
        ]);
        writePickImportAudit($conn, [
            'source_type' => (string) ($auditMeta['source_type'] ?? 'drive'),
            'source_name' => (string) ($auditMeta['source_name'] ?? $fileName),
            'source_file' => (string) ($auditMeta['source_file'] ?? ($sourceFile ?? $fileName)),
            'source_path' => (string) ($auditMeta['source_path'] ?? $filePath),
            'source_checksum' => (string) ($auditMeta['source_checksum'] ?? ''),
            'worksheet_row_count' => 0,
            'parsed_row_count' => 0,
            'imported_row_count' => 0,
            'duplicate_row_count' => 0,
            'rejected_row_count' => 0,
            'integrity_match' => true,
            'details_json' => [
                'file_name' => $fileName,
                'source_file' => $sourceFile,
                'status' => 'rejected_headers',
                'header_map' => $map,
            ],
        ]);
        return [
            'status' => 'rejected_headers',
            'import_count' => 0,
            'dup_count' => 0,
            'touched_order_count' => 0,
        ];
    }

    // Optional: lets Place rows be dropped directly instead of by pairing.
    $actionTypeIdx = findHeaderIndex($headers, ['action type']);

    $meaningfulRows = [];
    $blankRowsIgnored = 0;
    $sourceRowNumber = 1;
    foreach ($data as $row) {
        $sourceRowNumber++;
        $sourceNo = trim((string) ($row[$map['source_no']] ?? ''));
        $lineNoRaw = trim((string) ($row[$map['line_no']] ?? ''));
        $salesSourceLineNoRaw = trim((string) ($row[$map['sales_source_line_no']] ?? ''));
        $itemNoRaw = trim((string) ($row[$map['item_no']] ?? ''));
        $quantityRaw = $row[$map['qty']] ?? '';
        $desc = trim((string) ($row[$map['desc']] ?? ''));
        if (pickImportRowIsBlank([$sourceNo, $lineNoRaw, $salesSourceLineNoRaw, $itemNoRaw, $quantityRaw, $desc])) {
            $blankRowsIgnored++;
            continue;
        }
        if ($desc === '0') {
            $desc = '';
        }
        $meaningfulRows[] = [
            'order_number' => $sourceNo,
            'source_line_no' => is_numeric($lineNoRaw) ? (int) $lineNoRaw : 0,
            'sales_source_line_no' => is_numeric($salesSourceLineNoRaw) ? (int) $salesSourceLineNoRaw : 0,
            'item_no' => $itemNoRaw,
            'quantity' => (int) $quantityRaw,
            'description' => $desc,
            'action_type' => $actionTypeIdx !== null ? trim((string) ($row[$actionTypeIdx] ?? '')) : '',
            'worksheet_row_number' => $sourceRowNumber,
        ];
    }

    $parsedCount = count($meaningfulRows);
    $worksheetRowCount = $parsedCount;
    $blockResult = collapseRepeatedWholeSheetBlock($meaningfulRows);
    $meaningfulRows = $blockResult['rows'];

    $sourceLineResult = selectPickTakeRows($meaningfulRows, $actionTypeIdx !== null);
    $meaningfulRows = $sourceLineResult['rows'];

    $importCount = 0;
    $dupCount = (int) $blockResult['duplicate_count']
        + (int) $sourceLineResult['duplicate_count'];
    $rejectedCount = 0;
    $touchedOrders = [];
    $orderIdsByNumber = [];
    $salesOrderNumberSet = [];
    $duplicateCounts = [];
    $duplicateGroups = [];
    $duplicateSamples = [];
    $duplicateAlerts = [];
    $conn->begin_transaction();
    try {
        foreach ($meaningfulRows as $row) {
            $rowNumber = (int) ($row['worksheet_row_number'] ?? 0);
            $sourceNo = trim((string) ($row['order_number'] ?? ''));
            $sourceLineNo = (int) ($row['source_line_no'] ?? 0);
            $salesSourceLineNo = (int) ($row['sales_source_line_no'] ?? 0);
            $itemNoRaw = trim((string) ($row['item_no'] ?? ''));
            $qty = (int) ($row['quantity'] ?? 0);
            $desc = trim((string) ($row['description'] ?? ''));
            $isBogo = (int) ($row['is_bogo'] ?? 0);

            if ($sourceNo !== '') {
                $salesOrderNumberSet[strtoupper(trim($sourceNo))] = true;
            }
            if ($sourceNo === '' || $sourceLineNo <= 0 || $itemNoRaw === '' || $qty <= 0) {
                $rejectedCount++;
                continue;
            }
            $itemNoKey = normalizePickItemNo($itemNoRaw);
            if ($itemNoKey === '') {
                $rejectedCount++;
                continue;
            }
            $orderItemKey = $sourceNo . '|' . $salesSourceLineNo . '|' . $itemNoKey;
            if (!isset($duplicateCounts[$orderItemKey])) {
                $duplicateCounts[$orderItemKey] = 0;
            }
            $duplicateCounts[$orderItemKey]++;
            if (!isset($duplicateGroups[$orderItemKey])) {
                $duplicateGroups[$orderItemKey] = [
                    'order_number' => $sourceNo,
                    'source_line_no' => $salesSourceLineNo,
                    'item_no' => $itemNoRaw,
                    'quantities_seen' => [],
                    'quantities_imported' => [],
                    'quantities_skipped' => [],
                ];
            }
            $duplicateGroups[$orderItemKey]['quantities_seen'][] = $qty;
            $duplicateGroups[$orderItemKey]['quantities_imported'][] = $qty;

            $lineHash = hash('sha256', $sourceNo . '|' . $sourceLineNo . '|' . $itemNoKey . '|' . $qty);

            $stmt = $conn->prepare("
            INSERT INTO pick_orders (order_number, source_file)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE source_file = VALUES(source_file), updated_at = CURRENT_TIMESTAMP
        ");
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare pick order insert.');
            }
            $sourceValue = $sourceFile ?: $fileName;
            $stmt->bind_param('ss', $sourceNo, $sourceValue);
            if (!$stmt->execute()) {
                $error = $stmt->error ?: $conn->error ?: 'Unable to insert pick order.';
                $stmt->close();
                throw new RuntimeException($error);
            }
            $stmt->close();

            $stmt = $conn->prepare("SELECT id FROM pick_orders WHERE order_number = ? LIMIT 1");
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare pick order lookup.');
            }
            $stmt->bind_param('s', $sourceNo);
            if (!$stmt->execute()) {
                $error = $stmt->error ?: $conn->error ?: 'Unable to find imported pick order.';
                $stmt->close();
                throw new RuntimeException($error);
            }
            $orderId = (int) (($stmt->get_result()->fetch_assoc()['id'] ?? 0));
            $stmt->close();

            if ($orderId <= 0) {
                throw new RuntimeException('Unable to resolve the imported pick order.');
            }
            $touchedOrders[$orderId] = true;
            $orderIdsByNumber[strtoupper(trim($sourceNo))] = $orderId;

            $itemNoNorm = normalizeSkuCode($itemNoRaw);
            $lookupCodes = array_values(array_unique(array_filter([$itemNoRaw, $itemNoNorm], static fn($v) => $v !== '')));
            $descFallback = $desc !== '' ? $desc : '';
            $skuId = 0;
            $skuDescription = '';
            $skuLookup = $conn->prepare('SELECT id, `desc` FROM sku WHERE sku_num = ? LIMIT 1');
            if (!$skuLookup) {
                throw new RuntimeException('Unable to prepare SKU lookup.');
            }
            foreach ($lookupCodes as $code) {
                $skuLookup->bind_param('s', $code);
                if (!$skuLookup->execute()) {
                    $error = $skuLookup->error ?: $conn->error ?: 'Unable to look up SKU.';
                    $skuLookup->close();
                    throw new RuntimeException($error);
                }
                $skuRow = $skuLookup->get_result()->fetch_assoc() ?: null;
                if (is_array($skuRow)) {
                    $skuId = (int) ($skuRow['id'] ?? 0);
                    $skuDescription = trim((string) ($skuRow['desc'] ?? ''));
                    break;
                }
            }
            $skuLookup->close();

            if ($skuId > 0) {
                $descriptionValue = $skuDescription !== '' ? $skuDescription : $descFallback;
                $stmt = $conn->prepare("
                INSERT INTO pick_items
                    (order_id, source_line_no, sku_id, item_no, description, qty_ordered, is_bogo, line_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    sku_id = COALESCE(pick_items.sku_id, VALUES(sku_id)),
                    description = CASE
                        WHEN pick_items.description IS NULL
                          OR pick_items.description = ''
                          OR pick_items.description = '0'
                        THEN VALUES(description)
                        ELSE pick_items.description
                    END,
                    is_bogo = VALUES(is_bogo)
            ");
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare pick item insert.');
                }
                $stmt->bind_param('iiissiis', $orderId, $sourceLineNo, $skuId, $itemNoRaw, $descriptionValue, $qty, $isBogo, $lineHash);
            } else {
                $stmt = $conn->prepare("
                INSERT INTO pick_items
                (order_id, source_line_no, sku_id, item_no, description, qty_ordered, is_bogo, line_hash)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description = CASE
                        WHEN pick_items.description IS NULL
                          OR pick_items.description = ''
                          OR pick_items.description = '0'
                        THEN VALUES(description)
                        ELSE pick_items.description
                    END,
                    is_bogo = VALUES(is_bogo)
            ");
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare unmatched pick item insert.');
                }
                $stmt->bind_param('iissiis', $orderId, $sourceLineNo, $itemNoRaw, $descFallback, $qty, $isBogo, $lineHash);
            }
            if (!$stmt->execute()) {
                $error = $stmt->error ?: $conn->error ?: 'Unable to insert pick item.';
                $stmt->close();
                throw new RuntimeException($error);
            }
            if ($conn->affected_rows === 1) {
                $importCount++;
            } else {
                $dupCount++;
            }
            $stmt->close();
        }

        $duplicateAlerts = buildDuplicateImportAlerts($duplicateGroups);

        if ($dupCount > 0) {
            writeDebugLog('drive_import_duplicate_item_no_summary', [
                'file_name' => $fileName,
                'source_file' => $sourceFile,
                'duplicate_count' => $dupCount,
                'sample_rows' => $duplicateSamples,
            ]);
        }

        if ($duplicateAlerts !== []) {
            writeDebugLog('drive_import_duplicate_item_threshold_alert', [
                'file_name' => $fileName,
                'source_file' => $sourceFile,
                'alerts' => array_values($duplicateAlerts),
            ]);
        }

        if (!empty($touchedOrders)) {
            $orderIds = array_keys($touchedOrders);
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $types = str_repeat('i', count($orderIds));
            $sql = "
            UPDATE pick_items pi
            JOIN sku s ON s.sku_num = pi.item_no
            SET
                pi.sku_id = COALESCE(pi.sku_id, s.id),
                pi.description = CASE
                    WHEN pi.description IS NULL OR pi.description = '' OR pi.description = '0'
                    THEN s.`desc`
                    ELSE pi.description
                END
            WHERE pi.order_id IN ({$placeholders})
        ";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$orderIds);
                if (!$stmt->execute()) {
                    $error = $stmt->error ?: $conn->error ?: 'Unable to refresh imported SKU details.';
                    $stmt->close();
                    throw new RuntimeException($error);
                }
                $stmt->close();
            }
        }

        // is_bogo is written per line by the upsert above (see selectPickTakeRows).
        writeDebugLog('drive_import_bogo_flags_applied', [
            'file_name' => $fileName,
            'source_file' => $sourceFile,
            'bogo_rows' => count(array_filter($meaningfulRows, static fn(array $row): bool => !empty($row['is_bogo']))),
            'action_type_column' => $actionTypeIdx !== null,
            'bogo_detection_version' => PICK_BOGO_IMPORT_VERSION,
        ]);


        $auditWritten = writePickImportAudit($conn, [
            'source_type' => (string) ($auditMeta['source_type'] ?? 'session'),
            'source_name' => (string) ($auditMeta['source_name'] ?? ($sourceFile ?? '')),
            'source_file' => (string) ($auditMeta['source_file'] ?? ($sourceFile ?? '')),
            'source_path' => (string) ($auditMeta['source_path'] ?? ''),
            'source_checksum' => (string) ($auditMeta['source_checksum'] ?? ''),
            'sales_order_numbers' => '|' . implode('|', array_values(array_filter(array_keys($salesOrderNumberSet), static fn(string $value): bool => trim($value) !== ''))) . '|',
            'worksheet_row_count' => $worksheetRowCount,
            'parsed_row_count' => $parsedCount,
            'imported_row_count' => $importCount,
            'duplicate_row_count' => $dupCount,
            'rejected_row_count' => $rejectedCount,
            'integrity_match' => (($importCount + $dupCount + $rejectedCount) === $parsedCount),
            'details_json' => [
                'source_name' => $sourceFile ?? '',
                'action_type_column' => $actionTypeIdx !== null,
                'duplicate_samples' => $duplicateSamples,
                'duplicate_alerts' => array_values($duplicateAlerts),
                // 'item_count_received' => count($items),
                'blank_rows_ignored' => $blankRowsIgnored,
                'repeated_whole_sheet_block_detected' => (bool) $blockResult['detected'],
                'repeated_block_rows_skipped' => (int) $blockResult['duplicate_count'],
                'same_quantity_source_line_duplicates_detected' => (bool) $sourceLineResult['detected'],
                'same_quantity_source_line_rows_skipped' => (int) $sourceLineResult['duplicate_count'],
            ],
        ]);

        if (!$auditWritten) {
            error_log(
                '[PICK AUDIT FAILED] sales_orders=' .
                implode(',', array_keys($salesOrderNumberSet))
            );
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    writeDebugLog('drive_import_complete', [
        'file_name' => $fileName,
        'source_file' => $sourceFile,
        'import_count' => $importCount,
        'dup_count' => $dupCount,
        'touched_order_count' => count($touchedOrders),
    ]);

    return [
        'status' => 'imported',
        'import_count' => $importCount,
        'dup_count' => $dupCount,
        'touched_order_count' => count($touchedOrders),
    ];
}

// --- MAIN ---
$isVerifyPickImport = ($_SERVER['REQUEST_METHOD'] === 'POST')
    && (string) ($_GET['mode'] ?? '') === 'verify_pick';

if ($isVerifyPickImport) {
    importSessionPickData($conn);
}

$driveReady = class_exists('Google\\Client') && is_file($DRIVE_SERVICE_ACCOUNT_JSON);

if ($driveReady) {
    $driveFiles = listDrivePickFiles($DRIVE_SERVICE_ACCOUNT_JSON, $DRIVE_PICK_FOLDER_ID, $DRIVE_PICK_NAME_PREFIX);
    $driveStats = [
        'total' => 0,
        'imported' => 0,
        'rejected_headers' => 0,
        'failed' => 0,
        'skipped_existing' => 0,
        'imported_rows' => 0,
        'duplicates' => 0,
    ];
    foreach ($driveFiles as $file) {
        $driveStats['total']++;
        $fileId = (string) ($file['id'] ?? '');
        $fileName = (string) ($file['name'] ?? '');
        if ($fileId === '' || $fileName === '') {
            continue;
        }
        if (shouldSkipDriveFile($conn, $fileId)) {
            $driveStats['skipped_existing']++;
            continue;
        }
        // The same file may already have been imported under another Drive ID
        // or uploaded by hand on the Verify Pick page.
        $priorImport = findPriorPickImportAudit($conn, '', $fileName);
        if ($priorImport !== null) {
            writeSkippedDrivePickImportAudit($conn, $fileId, $fileName, '', $priorImport, 'filename');
            $driveStats['skipped_existing']++;
            continue;
        }
        try {
            $driveMeta = downloadDriveFileToTemp($DRIVE_SERVICE_ACCOUNT_JSON, $fileId);
            $downloadChecksum = is_file($driveMeta['path']) ? (hash_file('sha256', $driveMeta['path']) ?: '') : '';
            $priorImport = findPriorPickImportAudit($conn, $downloadChecksum, '');
            if ($priorImport !== null) {
                writeSkippedDrivePickImportAudit($conn, $fileId, $fileName, $downloadChecksum, $priorImport, 'checksum');
                if ($driveMeta['cleanup']) {
                    @unlink($driveMeta['path']);
                }
                $driveStats['skipped_existing']++;
                continue;
            }
            $archiveMeta = archiveImportedSourceFile($driveMeta['path'], $PICK_IMPORT_ARCHIVE_DIR, $fileName, $fileId);
            $result = importPickSheet($conn, $driveMeta['path'], $fileName, $fileId, [
                'source_type' => 'drive',
                'source_name' => $fileName,
                'source_file' => $fileId,
                'source_path' => (string) ($archiveMeta['archive_path'] ?? $driveMeta['path']),
                'source_checksum' => (string) ($archiveMeta['checksum'] ?? ''),
            ]);
            if ($driveMeta['cleanup']) {
                @unlink($driveMeta['path']);
            }
            $status = (string) ($result['status'] ?? 'imported');
            if ($status === 'rejected_headers') {
                $driveStats['rejected_headers']++;
            } else {
                $driveStats['imported']++;
                $driveStats['imported_rows'] += (int) ($result['import_count'] ?? 0);
                $driveStats['duplicates'] += (int) ($result['dup_count'] ?? 0);
                writeLog('Imported Drive file: ' . $fileName
                    . ' (rows=' . (int) ($result['import_count'] ?? 0)
                    . ', duplicates=' . (int) ($result['dup_count'] ?? 0)
                    . ', orders=' . (int) ($result['touched_order_count'] ?? 0)
                    . ')');
            }
        } catch (Throwable $e) {
            $driveStats['failed']++;
            writeDebugLog('drive_file_failed', [
                'file_id' => $fileId,
                'file_name' => $fileName,
                'error' => $e->getMessage(),
            ]);
            writeLog("Critical Error in $fileName: " . $e->getMessage());
        }
    }
    writeLog(
        'Drive import summary: files=' . (int) $driveStats['total']
        . ', imported=' . (int) $driveStats['imported']
        . ', rejected_headers=' . (int) $driveStats['rejected_headers']
        . ', failed=' . (int) $driveStats['failed']
        . ', skipped_existing=' . (int) $driveStats['skipped_existing']
        . ', rows=' . (int) $driveStats['imported_rows']
        . ', duplicates=' . (int) $driveStats['duplicates']
        . ', drive_seen=' . (int) $PICK_IMPORT_DRIVE_REJECT_STATS['total_seen']
        . ', drive_accepted=' . (int) $PICK_IMPORT_DRIVE_REJECT_STATS['accepted']
        . ', drive_prefix_mismatch=' . (int) $PICK_IMPORT_DRIVE_REJECT_STATS['prefix_mismatch']
        . ', drive_unsupported_extension=' . (int) $PICK_IMPORT_DRIVE_REJECT_STATS['unsupported_extension']
        . ', drive_other=' . (int) $PICK_IMPORT_DRIVE_REJECT_STATS['other']
    );
    if (!empty($PICK_IMPORT_DRIVE_REJECT_STATS['samples'])) {
        writeDebugLog('drive_reject_samples', [
            'samples' => $PICK_IMPORT_DRIVE_REJECT_STATS['samples'],
        ]);
    }

    /*
     * The verify-pick page calls this endpoint with Accept: application/json.
     * Return a compact summary so the browser has a real response instead of
     * an empty 200. Existing front-end code only checks HTTP success, so this
     * remains backward-compatible while making failures easier to diagnose.
     */
    $acceptHeader = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (stripos($acceptHeader, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => ($driveStats['failed'] === 0),
            'drive_ready' => true,
            'stats' => $driveStats,
        ], JSON_UNESCAPED_SLASHES);
    }

    exit;
}

// --- FALLBACK LOCAL IMPORT ---
$files = glob($inboundDir . "*.{xlsx,xlsm}", GLOB_BRACE);
$localStats = [
    'total' => 0,
    'imported' => 0,
    'rejected_headers' => 0,
    'failed' => 0,
    'imported_rows' => 0,
    'duplicates' => 0,
];
foreach ($files as $filePath) {
    $localStats['total']++;
    $fileName = basename($filePath);
    try {
        $checksum = is_file($filePath) ? (hash_file('sha256', $filePath) ?: '') : '';
        $result = importPickSheet($conn, $filePath, $fileName, $fileName, [
            'source_type' => 'local',
            'source_name' => $fileName,
            'source_file' => $fileName,
            'source_path' => $processedDir . $fileName,
            'source_checksum' => $checksum,
        ]);
        rename($filePath, $processedDir . $fileName);
        $status = (string) ($result['status'] ?? 'imported');
        if ($status === 'rejected_headers') {
            $localStats['rejected_headers']++;
        } else {
            $localStats['imported']++;
            $localStats['imported_rows'] += (int) ($result['import_count'] ?? 0);
            $localStats['duplicates'] += (int) ($result['dup_count'] ?? 0);
            writeLog('Imported local file: ' . $fileName
                . ' (rows=' . (int) ($result['import_count'] ?? 0)
                . ', duplicates=' . (int) ($result['dup_count'] ?? 0)
                . ', orders=' . (int) ($result['touched_order_count'] ?? 0)
                . ')');
        }
    } catch (Throwable $e) {
        $localStats['failed']++;
        writeDebugLog('local_file_failed', [
            'file_name' => $fileName,
            'error' => $e->getMessage(),
        ]);
        writeLog("Critical Error in $fileName: " . $e->getMessage());
    }
}
writeLog(
    'Local import summary: files=' . (int) $localStats['total']
    . ', imported=' . (int) $localStats['imported']
    . ', rejected_headers=' . (int) $localStats['rejected_headers']
    . ', failed=' . (int) $localStats['failed']
    . ', rows=' . (int) $localStats['imported_rows']
    . ', duplicates=' . (int) $localStats['duplicates']
);
writeDebugLog('local_import_run_summary', $localStats);