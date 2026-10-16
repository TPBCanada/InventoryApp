<?php
declare(strict_types=1);

/**
 * import_picks.php
 * Logic: Pull XLSX files from Google Drive (or local inbound), parse pick lines,
 * de-duplicate data, and import into the warehouse database.
 */

require_once __DIR__ . '/../dbinv.php'; // Existing DB connection ($conn)
require_once __DIR__ . '/../inventory/utils/config_init.php';
require_once __DIR__ . '/../vendor/autoload.php'; // Composer for Spreadsheet + Google Drive

use PhpOffice\PhpSpreadsheet\IOFactory;

// --- CONFIGURATION ---
$logFile      = __DIR__ . '/logs/import_log.txt';
$inboundDir   = __DIR__ . '/google_drive_picks/inbound/';  // Fallback local folder
$processedDir = __DIR__ . '/google_drive_picks/processed/';

$DRIVE_SERVICE_ACCOUNT_JSON = DRIVE_SERVICE_ACCOUNT_JSON;
$DRIVE_PICK_FOLDER_ID = DRIVE_PICK_FOLDER_ID;
$DRIVE_PICK_NAME_PREFIX = DRIVE_PICK_NAME_PREFIX;

if (!is_dir($processedDir)) {
    mkdir($processedDir, 0777, true);
}

// --- HELPERS ---
function writeLog(string $msg): void
{
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
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
    $value = strtoupper($value);
    return $value;
}

/**
 * List pick files from Google Drive.
 *
 * @return array<int, array{id:string,name:string,modified:string,modified_ts:int}>
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
        if (!preg_match('/\.(xlsx|xlsm)$/i', $name)) {
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
    file_put_contents($tmpPath, $content);

    return [
        'path' => $tmpPath,
        'name' => (string) $meta->getName(),
        'cleanup' => true,
    ];
}

function shouldSkipDriveFile(mysqli $conn, string $fileId): bool
{
    $stmt = $conn->prepare("SELECT id FROM pick_orders WHERE source_file = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $fileId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

/**
 * Process a spreadsheet and write to DB.
 */
function importPickSheet(mysqli $conn, string $filePath, string $fileName, ?string $sourceFile = null): void
{
    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, true);

    $headers = array_shift($data);
    $map = [
        'source_no' => findHeaderIndex($headers, [
            'source no', 'source no.', 'source number', 'source #',
            'sales order', 'sales order #', 'sales order no', 'sales order number',
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
        writeLog("Error: Missing required headers in $fileName. Skipping.");
        return;
    }

    $importCount = 0;
    $dupCount = 0;
    $touchedOrders = [];
    $seenLines = [];

    foreach ($data as $row) {
        $sourceNo = trim((string) ($row[$map['source_no']] ?? ''));
        $itemNoRaw = trim((string) ($row[$map['item_no']] ?? ''));
        $qty = (int) ($row[$map['qty']] ?? 0);
        $desc = trim((string) ($row[$map['desc']] ?? ''));
        if ($desc === '0') {
            $desc = '';
        }

        if ($sourceNo === '' || $itemNoRaw === '') {
            continue;
        }

        $lineHash = md5($sourceNo . $itemNoRaw . $qty);
        if (isset($seenLines[$lineHash])) {
            $dupCount++;
            continue;
        }
        $seenLines[$lineHash] = true;

        $stmt = $conn->prepare("
            INSERT INTO pick_orders (order_number, source_file)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE source_file = VALUES(source_file), updated_at = CURRENT_TIMESTAMP
        ");
        $sourceValue = $sourceFile ?: $fileName;
        $stmt->bind_param('ss', $sourceNo, $sourceValue);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT id FROM pick_orders WHERE order_number = ? LIMIT 1");
        $stmt->bind_param('s', $sourceNo);
        $stmt->execute();
        $orderId = (int) (($stmt->get_result()->fetch_assoc()['id'] ?? 0));
        $stmt->close();

        if ($orderId <= 0) {
            continue;
        }
        $touchedOrders[$orderId] = true;

        $itemNoNorm = normalizeSkuCode($itemNoRaw);
        $lookupCodes = array_values(array_unique(array_filter([$itemNoRaw, $itemNoNorm], static fn($v) => $v !== '')));
        $inserted = false;
        $descFallback = $desc !== '' ? $desc : '';

        $insertWithSku = $conn->prepare("
            INSERT INTO pick_items
            (order_id, sku_id, item_no, description, qty_ordered, line_hash)
            SELECT ?, s.id, ?, COALESCE(NULLIF(s.`desc`, ''), ?), ?, ?
            FROM sku s
            WHERE s.sku_num = ?
            LIMIT 1
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

        if ($insertWithSku) {
            foreach ($lookupCodes as $code) {
                $insertWithSku->bind_param('ississ', $orderId, $itemNoRaw, $descFallback, $qty, $lineHash, $code);
                $insertWithSku->execute();
                if ($conn->affected_rows > 0) {
                    $importCount++;
                    $inserted = true;
                    break;
                }
            }
            $insertWithSku->close();
        }

        if (!$inserted) {
            $stmt = $conn->prepare("
                INSERT INTO pick_items
                (order_id, sku_id, item_no, description, qty_ordered, line_hash)
                VALUES (?, NULL, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description = CASE
                        WHEN pick_items.description IS NULL
                          OR pick_items.description = ''
                          OR pick_items.description = '0'
                        THEN VALUES(description)
                        ELSE pick_items.description
                    END
            ");
            if ($stmt) {
                $stmt->bind_param('issis', $orderId, $itemNoRaw, $descFallback, $qty, $lineHash);
                $stmt->execute();
                if ($conn->affected_rows > 0) {
                    $importCount++;
                } else {
                    $dupCount++;
                }
                $stmt->close();
            }
        } else {
            // If inserted via SKU lookup, it's not a duplicate at this point.
        }
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
            $stmt->execute();
            $stmt->close();
        }
    }

    writeLog("Success: Imported $importCount unique lines. Ignored $dupCount duplicates. File: $fileName");
}

// --- MAIN ---
$driveReady = class_exists('Google\\Client') && is_file($DRIVE_SERVICE_ACCOUNT_JSON);

if ($driveReady) {
    $driveFiles = listDrivePickFiles($DRIVE_SERVICE_ACCOUNT_JSON, $DRIVE_PICK_FOLDER_ID, $DRIVE_PICK_NAME_PREFIX);
    if (empty($driveFiles)) {
        writeLog('No Drive files found to import.');
    }
    foreach ($driveFiles as $file) {
        $fileId = (string) ($file['id'] ?? '');
        $fileName = (string) ($file['name'] ?? '');
        if ($fileId === '' || $fileName === '') {
            continue;
        }
        if (shouldSkipDriveFile($conn, $fileId)) {
            writeLog("Skipping already imported Drive file: $fileName ($fileId)");
            continue;
        }
        writeLog("Processing Drive file: $fileName ($fileId)");
        try {
            $driveMeta = downloadDriveFileToTemp($DRIVE_SERVICE_ACCOUNT_JSON, $fileId);
            importPickSheet($conn, $driveMeta['path'], $fileName, $fileId);
            if ($driveMeta['cleanup']) {
                @unlink($driveMeta['path']);
            }
        } catch (Throwable $e) {
            writeLog("Critical Error in $fileName: " . $e->getMessage());
        }
    }
    exit;
}

// --- FALLBACK LOCAL IMPORT ---
$files = glob($inboundDir . "*.{xlsx,xlsm}", GLOB_BRACE);
foreach ($files as $filePath) {
    $fileName = basename($filePath);
    writeLog("Processing file: $fileName");
    try {
        importPickSheet($conn, $filePath, $fileName, $fileName);
        rename($filePath, $processedDir . $fileName);
    } catch (Throwable $e) {
        writeLog("Critical Error in $fileName: " . $e->getMessage());
    }
}
