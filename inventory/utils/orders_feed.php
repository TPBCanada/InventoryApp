<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/config_init.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$sheetKey = isset($_GET['sheet']) ? strtolower(trim((string) $_GET['sheet'])) : 'sales';
$debugMode = isset($_GET['debug']) && (string) $_GET['debug'] === '1';
$debug = [
    'ok' => false,
    'step' => 'init',
    'sheet' => $sheetKey,
];

$fileMap = [
    'sales' => 'SALES ORDERS_Latest.csv',
    'completed' => 'COMPLETED ORDERS_Latest.csv',
    'shortshipped' => 'Shortshipped Orders_Latest.csv',
];

if (!isset($fileMap[$sheetKey])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid sheet parameter']);
    exit;
}

$serviceAccountPath = defined('DRIVE_SERVICE_ACCOUNT_JSON') ? (string) DRIVE_SERVICE_ACCOUNT_JSON : '';
$targetFileName = $fileMap[$sheetKey];
$debug['target_file'] = $targetFileName;

$folderIds = [];
if (defined('DRIVE_PICK_FOLDER_ID')) {
    $rawFolder = DRIVE_PICK_FOLDER_ID;
    if (is_array($rawFolder)) {
        $folderIds = array_values(array_filter(array_map(static fn($v) => trim((string) $v), $rawFolder), static fn($v) => $v !== ''));
    } elseif (is_string($rawFolder) && trim($rawFolder) !== '') {
        $folderIds = [trim($rawFolder)];
    }
}
$debug['service_account_path'] = $serviceAccountPath;
$debug['service_account_exists'] = is_file($serviceAccountPath);
$debug['folder_ids'] = $folderIds;

if ($serviceAccountPath === '' || !is_file($serviceAccountPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Drive service account configuration is missing']);
    exit;
}

if (count($folderIds) === 0) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Drive folder configuration is missing']);
    exit;
}

if (!class_exists('Google\\Client')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Google API client not available']);
    exit;
}

try {
    $debug['step'] = 'auth_client';
    $client = new \Google\Client();
    $client->setAuthConfig($serviceAccountPath);
    $client->addScope(\Google\Service\Drive::DRIVE_READONLY);
    $service = new \Google\Service\Drive($client);

    $bestFile = null;
    $bestTs = 0;
    $debug['folder_scan'] = [];
    $debug['step'] = 'scan_folders';
    foreach ($folderIds as $folderId) {
        $query = sprintf(
            "'%s' in parents and trashed = false and name = '%s'",
            addslashes($folderId),
            str_replace("'", "\\'", $targetFileName)
        );

        $resp = $service->files->listFiles([
            'q' => $query,
            'fields' => 'files(id,name,modifiedTime,mimeType)',
            'orderBy' => 'modifiedTime desc',
            'pageSize' => 10,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        $files = $resp->getFiles();
        $scanItem = [
            'folder_id' => $folderId,
            'matches' => 0,
            'candidates' => [],
        ];
        if (!$files || count($files) === 0) {
            $debug['folder_scan'][] = $scanItem;
            continue;
        }

        foreach ($files as $candidate) {
            $ts = strtotime((string) $candidate->getModifiedTime()) ?: 0;
            $scanItem['matches']++;
            $scanItem['candidates'][] = [
                'id' => (string) $candidate->getId(),
                'name' => (string) $candidate->getName(),
                'modified' => (string) $candidate->getModifiedTime(),
                'modified_ts' => $ts,
            ];
            if ($bestFile === null || $ts > $bestTs) {
                $bestFile = $candidate;
                $bestTs = $ts;
            }
        }
        $debug['folder_scan'][] = $scanItem;
    }

    if ($bestFile === null) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No export file found for selected sheet']);
        exit;
    }

    $file = $bestFile;
    $fileId = (string) $file->getId();
    $fileName = (string) $file->getName();
    $debug['selected_file'] = [
        'id' => $fileId,
        'name' => $fileName,
        'modified' => (string) $file->getModifiedTime(),
        'modified_ts' => $bestTs,
    ];

    $debug['step'] = 'download_csv';
    $download = $service->files->get($fileId, ['alt' => 'media']);
    $csv = (string) $download->getBody()->getContents();
    $debug['csv_bytes'] = strlen($csv);
    $debug['csv_preview'] = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $csv) ?: [], 0, 3));
    $debug['ok'] = true;
    $debug['step'] = 'done';

    if ($debugMode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($debug);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Orders-File-Name: ' . rawurlencode($fileName));
    echo $csv;
} catch (\Throwable $e) {
    if ($debugMode) {
        $debug['ok'] = false;
        $debug['step'] = 'exception';
        $debug['error'] = $e->getMessage();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($debug);
        exit;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unable to fetch orders CSV']);
}
