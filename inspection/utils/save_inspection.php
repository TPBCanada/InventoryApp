<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/inspection_cron.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function table_columns(mysqli $conn, string $table): array
{
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW COLUMNS FROM `{$safe}`");
    $columns = [];
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $columns[] = (string) ($row['Field'] ?? '');
        }
        $res->free();
    }
    return $columns;
}

function first_available_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function insert_with_known_columns(mysqli $conn, string $table, array $values): void
{
    if (!$values) {
        $conn->query("INSERT INTO `{$table}` () VALUES ()");
        return;
    }

    $columns = array_keys($values);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnSql = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
    $types = '';
    $params = [];

    foreach ($values as $value) {
        if (is_int($value)) {
            $types .= 'i';
        } else {
            $types .= 's';
        }
        $params[] = $value;
    }

    $stmt = $conn->prepare("INSERT INTO `{$table}` ({$columnSql}) VALUES ({$placeholders})");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

function ensure_upload_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create media upload directory.');
    }
}

function detect_upload_extension(string $originalName, string $mimeType): string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];
    if (isset($map[$mimeType])) {
        return $map[$mimeType];
    }
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{2,5}$/', $extension) ? $extension : 'bin';
}

function store_response_media(mysqli $conn, int $responseId, int $itemId, array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Media upload failed for checklist item ' . $itemId . '.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Uploaded media file is invalid for checklist item ' . $itemId . '.');
    }
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Uploaded media must be smaller than 10MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        throw new RuntimeException('Only image uploads are supported right now.');
    }

    $uploadDir = dirname(__DIR__) . '/uploads/checklist-media';
    ensure_upload_directory($uploadDir);

    $extension = detect_upload_extension($originalName, $mimeType);
    $fileName = sprintf('response_%d_item_%d_%s.%s', $responseId, $itemId, bin2hex(random_bytes(8)), $extension);
    $destination = $uploadDir . '/' . $fileName;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Unable to store uploaded media.');
    }

    $relativePath = 'uploads/checklist-media/' . $fileName;
    $stmt = $conn->prepare(
        'INSERT INTO inspection_response_media (inspection_response_id, media_type, file_path, original_name, mime_type, file_size_bytes)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $mediaType = 'image';
    $stmt->bind_param('issssi', $responseId, $mediaType, $relativePath, $originalName, $mimeType, $size);
    $stmt->execute();
    $stmt->close();

    return [
        'path' => $relativePath,
        'item_id' => $itemId,
        'item_id' => $itemId,
        'name' => sprintf('item_%d_photo.%s', $itemId, $extension),
        'mime' => $mimeType,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if (!isset($_SESSION['username'])) {
    respond(401, ['ok' => false, 'error' => 'Authentication required']);
}

if (!$conn) {
    respond(500, ['ok' => false, 'error' => 'Database connection failed']);
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
$sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    respond(403, ['ok' => false, 'error' => 'Invalid CSRF token']);
}

$templateId = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
$comments = trim((string) ($_POST['comments'] ?? ''));
$truckLabel = trim((string) ($_POST['truck_label'] ?? ''));
if (strlen($comments) > 2000) {
    $comments = substr($comments, 0, 2000);
}
if (strlen($truckLabel) > 100) {
    $truckLabel = substr($truckLabel, 0, 100);
}

$itemsRaw = trim((string) ($_POST['items'] ?? ''));
$items = $itemsRaw !== '' ? json_decode($itemsRaw, true) : null;
if (!is_array($items) || $items === []) {
    $reportRaw = trim((string) ($_POST['report_json'] ?? ''));
    if ($reportRaw !== '') {
        $report = json_decode($reportRaw, true);
        if (is_array($report) && isset($report['items']) && is_array($report['items'])) {
            $items = $report['items'];
        }
    }
}
if ($templateId <= 0 || !is_array($items) || $items === []) {
    respond(422, ['ok' => false, 'error' => 'Invalid checklist payload']);
}

foreach (['checklist_templates', 'checklist_items', 'inspections', 'inspection_responses'] as $table) {
    if (!table_exists($conn, $table)) {
        respond(503, ['ok' => false, 'error' => "Missing required table: {$table}"]);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->set_charset('utf8mb4');

    $templateStmt = $conn->prepare('SELECT id FROM checklist_templates WHERE id = ? LIMIT 1');
    $templateStmt->bind_param('i', $templateId);
    $templateStmt->execute();
    $templateExists = (bool) $templateStmt->get_result()->fetch_assoc();
    $templateStmt->close();

    if (!$templateExists) {
        respond(404, ['ok' => false, 'error' => 'Checklist template not found']);
    }

    $templateSlugStmt = $conn->prepare('SELECT slug FROM checklist_templates WHERE id = ? LIMIT 1');
    $templateSlugStmt->bind_param('i', $templateId);
    $templateSlugStmt->execute();
    $templateSlugRow = $templateSlugStmt->get_result()->fetch_assoc() ?: [];
    $templateSlugStmt->close();
    $templateSlug = (string) ($templateSlugRow['slug'] ?? '');
    if ($templateSlug === 'lift_truck_checklist' && $truckLabel === '') {
        respond(422, ['ok' => false, 'error' => 'Lift truck selection is required.']);
    }
    if (!table_exists($conn, 'inspection_response_media')) {
        respond(503, ['ok' => false, 'error' => 'Media table is missing. Run the inspection response media SQL migration first.']);
    }

    $inspectionColumns = table_columns($conn, 'inspections');
    $responseColumns = table_columns($conn, 'inspection_responses');
    $responseInspectionColumn = first_available_column($responseColumns, ['inspection_id']);
    $responseItemColumn = first_available_column($responseColumns, ['item_id', 'checklist_item_id']);
    $responseCheckedColumn = first_available_column($responseColumns, ['is_checked', 'checked', 'is_complete']);
    $responseNoteColumn = first_available_column($responseColumns, ['notes', 'note', 'comments', 'comment']);
    $responseUserIdColumn = first_available_column($responseColumns, ['user_id', 'inspected_by_user_id', 'created_by_user_id']);
    $responseUsernameColumn = first_available_column($responseColumns, ['inspected_by', 'created_by', 'username']);

    if ($responseInspectionColumn === null || $responseItemColumn === null || $responseCheckedColumn === null) {
        throw new RuntimeException('inspection_responses is missing required columns.');
    }

    $inspectionData = [];
    $templateColumn = first_available_column($inspectionColumns, ['template_id', 'checklist_template_id']);
    if ($templateColumn !== null) {
        $inspectionData[$templateColumn] = $templateId;
    }

    $username = (string) $_SESSION['username'];
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $inspectionUserIdColumn = first_available_column($inspectionColumns, ['user_id', 'inspected_by_user_id', 'created_by_user_id']);
    $userColumn = first_available_column($inspectionColumns, ['inspected_by', 'created_by', 'username']);
    if ($inspectionUserIdColumn !== null) {
        if ($userId <= 0) {
            throw new RuntimeException('Session user_id is missing for inspection save.');
        }
        $inspectionData[$inspectionUserIdColumn] = $userId;
    }
    if ($userColumn !== null) {
        $inspectionData[$userColumn] = $username;
    }

    if (in_array('comments', $inspectionColumns, true)) {
        $inspectionData['comments'] = $comments === '' ? null : $comments;
    }
    $truckColumn = first_available_column($inspectionColumns, ['truck_label', 'lift_truck', 'asset_name', 'equipment_name']);
    if ($truckColumn !== null) {
        $inspectionData[$truckColumn] = $truckLabel === '' ? null : $truckLabel;
    }

    $conn->begin_transaction();

    insert_with_known_columns($conn, 'inspections', $inspectionData);
    $inspectionId = (int) $conn->insert_id;

    $itemStmt = $conn->prepare('SELECT id FROM checklist_items WHERE id = ? AND template_id = ? LIMIT 1');
    $emailAttachments = [];

    foreach ($items as $item) {
        $itemId = isset($item['item_id']) ? (int) $item['item_id'] : 0;
        $status = strtolower(trim((string) ($item['status'] ?? '')));
        if (!in_array($status, ['pass', 'fail', 'na'], true)) {
            $status = !empty($item['is_checked']) ? 'pass' : 'fail';
        }
        $isChecked = $status === 'fail' ? 0 : 1;
        $itemNote = trim((string) ($item['note'] ?? ''));
        if (strlen($itemNote) > 1000) {
            $itemNote = substr($itemNote, 0, 1000);
        }
        if ($status === 'na') {
            $itemNote = $itemNote !== '' ? '[N/A] ' . $itemNote : '[N/A]';
        }
        if ($itemId <= 0) {
            continue;
        }

        $itemStmt->bind_param('ii', $itemId, $templateId);
        $itemStmt->execute();
        $validItem = (bool) $itemStmt->get_result()->fetch_assoc();
        if (!$validItem) {
            continue;
        }

        $responseData = [];
        $responseData[$responseInspectionColumn] = $inspectionId;
        $responseData[$responseItemColumn] = $itemId;
        $responseData[$responseCheckedColumn] = $isChecked;
        if ($responseUserIdColumn !== null) {
            if ($userId <= 0) {
                throw new RuntimeException('Session user_id is missing for inspection response save.');
            }
            $responseData[$responseUserIdColumn] = $userId;
        }
        if ($responseUsernameColumn !== null) {
            $responseData[$responseUsernameColumn] = $username;
        }
        if ($responseNoteColumn !== null) {
            $responseData[$responseNoteColumn] = $itemNote === '' ? null : $itemNote;
        }

        insert_with_known_columns($conn, 'inspection_responses', $responseData);
        $responseId = (int) $conn->insert_id;
        $uploadKey = 'media_item_' . $itemId;
        if (isset($_FILES[$uploadKey]) && $responseId > 0) {
            $savedMedia = store_response_media($conn, $responseId, $itemId, $_FILES[$uploadKey]);
            if ($savedMedia !== null) {
                $emailAttachments[] = $savedMedia;
            }
        }
    }

    $itemStmt->close();
    $conn->commit();
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
    }
    $reference = strtoupper(bin2hex(random_bytes(4)));
    error_log(sprintf(
        'save_inspection failed [ref %s] user=%s template=%d: %s in %s:%d',
        $reference,
        (string) ($_SESSION['username'] ?? ''),
        $templateId,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    respond(500, ['ok' => false, 'error' => 'Save failed, reference #' . $reference]);
}

// The inspection is committed; email problems must not turn the save into a failure.
$emailSent = false;
try {
    $job = inspectionCronBuildJob(array_merge($_POST, ['inspection_id' => (string) $inspectionId]));
    $job['attachments'] = $emailAttachments;
    $sendResult = inspectionCronSendMail($job);
    $emailSent = !empty($sendResult['ok']);
    if (!$emailSent) {
        $job['attempts'] = 1;
        $job['last_error'] = (string) ($sendResult['error'] ?? 'Unknown error');
        inspectionCronEnqueue($job);
        error_log(sprintf('save_inspection email queued for retry, inspection=%d: %s', $inspectionId, $job['last_error']));
    }
} catch (Throwable $mailError) {
    error_log(sprintf('save_inspection email error, inspection=%d: %s', $inspectionId, $mailError->getMessage()));
}

respond(200, [
    'ok' => true,
    'inspection_id' => $inspectionId,
    'email_sent' => $emailSent,
    'message' => $emailSent
        ? 'Inspection finalized and report emailed.'
        : 'Inspection saved. Report email queued for retry.',
]);
