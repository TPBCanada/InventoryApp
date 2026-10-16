<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/../utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/utils/db_importers.php';

app_require_current_page_access();

if (!isset($_SESSION['username'])) {
  header('Location: ../login.php');
  exit;
}

$title = 'Receiving';
$page_class = 'page-receiving';
$css = ['/refactor_css/receiving.css'];

if (!function_exists('h')) {
  function h($value): string
  {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

function receiving_value(string $key, string $default = ''): string
{
  return trim((string) ($_POST[$key] ?? $default));
}

function receiving_choice(string $key, string $default = ''): string
{
  return trim((string) ($_POST[$key] ?? $default));
}

function receiving_items(array $postedItems, int $minimumRows = 5): array
{
  $rows = [];

  foreach ($postedItems as $item) {
    if (!is_array($item)) {
      continue;
    }

    $rows[] = [
      'sku' => trim((string) ($item['sku'] ?? '')),
      'description' => trim((string) ($item['description'] ?? '')),
      'unit' => trim((string) ($item['unit'] ?? '')),
      'ordered' => receiving_preview_number((string) ($item['ordered'] ?? '')),
      'received' => receiving_preview_number((string) ($item['received'] ?? '')),
      'qty' => trim((string) ($item['qty'] ?? '')),
      'po' => trim((string) ($item['po'] ?? '')),
    ];
  }

  while (count($rows) < $minimumRows) {
    $rows[] = ['sku' => '', 'description' => '', 'unit' => '', 'ordered' => '', 'received' => '', 'qty' => '', 'po' => ''];
  }

  return $rows;
}

function receiving_has_item_content(array $item): bool
{
  foreach (['sku', 'description', 'unit', 'ordered', 'received', 'qty', 'po'] as $key) {
    if (trim((string) ($item[$key] ?? '')) !== '') {
      return true;
    }
  }

  return false;
}

function receiving_filter_items(array $items): array
{
  return array_values(array_filter($items, 'receiving_has_item_content'));
}

function receiving_preview_report(array $postedReport): array
{
  return [
    'purchase_order' => trim((string) ($postedReport['purchase_order'] ?? '')),
    'arrival_date' => trim((string) ($postedReport['arrival_date'] ?? '')),
    'vendor' => trim((string) ($postedReport['vendor'] ?? '')),
  ];
}

function receiving_preview_number(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  $normalized = str_replace(',', '', $value);
  if (!is_numeric($normalized)) {
    return $value;
  }

  return number_format((float) $normalized, 0, '.', ',');
}

function receiving_decimal_value(string $value): ?string
{
  $value = trim($value);
  if ($value === '') {
    return null;
  }

  $normalized = str_replace(',', '', $value);
  if (!is_numeric($normalized)) {
    return null;
  }

  $formatted = number_format((float) $normalized, 3, '.', '');
  return $formatted === '-0.000' ? '0.000' : $formatted;
}

function receiving_parse_counted_qty(string $value): ?int
{
  $value = trim(str_replace(',', '', $value));
  if ($value === '' || !preg_match('/^\d+$/', $value)) {
    return null;
  }

  $qty = (int) $value;
  return $qty > 0 ? $qty : null;
}

function receiving_prepare_or_fail(mysqli $conn, string $sql): mysqli_stmt
{
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new RuntimeException('Database prepare failed: ' . $conn->error);
  }

  return $stmt;
}

function receiving_counted_items(array $items): array
{
  $counted = [];
  foreach (receiving_filter_items($items) as $item) {
    $qty = receiving_parse_counted_qty((string) ($item['qty'] ?? ''));
    if ($qty !== null && $qty > 0) {
      $counted[] = $item;
    }
  }

  return $counted;
}

function receiving_total_counted_qty(array $items): int
{
  $total = 0;
  foreach ($items as $item) {
    $qty = receiving_parse_counted_qty((string) ($item['qty'] ?? ''));
    if ($qty !== null) {
      $total += $qty;
    }
  }

  return $total;
}

function receiving_validate_push(array $report, array $items): array
{
  $errors = [];

  if (trim($report['purchase_order']) === '') {
    $errors[] = 'Purchase Order # is required.';
  }

  if (trim($report['arrival_date']) === '') {
    $errors[] = 'Arrival Date is required.';
  }

  if (trim($report['received_on']) === '') {
    $errors[] = 'Received On date is required.';
  }

  foreach ([
    'delivery_type' => 'Delivered As',
    'trailer_damaged' => 'Trailer Damaged?',
    'packing_slip' => 'Packing Slip Included?',
    'odor_dirty' => 'Bad Odor / Dirty?',
    'damaged_product' => 'Damaged Product?',
  ] as $key => $label) {
    if (trim((string) ($report[$key] ?? '')) === '') {
      $errors[] = $label . ' is required.';
    }
  }

  if (trim($report['received_by']) === '') {
    $errors[] = 'Received By is required.';
  }

  if (trim($report['counted_by']) === '') {
    $errors[] = 'Counted By is required.';
  }

  $skuLineCount = 0;
  foreach ($items as $item) {
    if (trim((string) ($item['sku'] ?? '')) !== '') {
      $skuLineCount++;
    }
  }

  if ($skuLineCount === 0) {
    $errors[] = 'At least one SKU line is required.';
  }

  return $errors;
}

function receiving_generate_next_pallet_tag(mysqli $conn): string
{
  $res = $conn->query("SELECT MAX(CAST(SUBSTRING(pallet_tag, 2) AS UNSIGNED)) AS max_seq FROM pallet WHERE pallet_tag LIKE 'P%'");
  $row = $res ? $res->fetch_assoc() : null;
  $next = (int) ($row['max_seq'] ?? 0) + 1;
  return sprintf('P%03d', $next);
}

function receiving_ensure_location_pallet(mysqli $conn, int $locId): ?array
{
  $stmt = receiving_prepare_or_fail($conn, "SELECT id, pallet_tag FROM pallet WHERE loc_id = ? AND status = 'ACTIVE' ORDER BY id ASC LIMIT 1");
  $stmt->bind_param('i', $locId);
  $stmt->execute();
  $res = $stmt->get_result();
  $existing = $res->fetch_assoc() ?: null;
  $stmt->close();

  if ($existing) {
    return ['id' => (int) $existing['id'], 'tag' => (string) $existing['pallet_tag']];
  }

  for ($attempt = 0; $attempt < 5; $attempt++) {
    $palletTag = receiving_generate_next_pallet_tag($conn);
    try {
      $insert = receiving_prepare_or_fail($conn, "INSERT INTO pallet (pallet_tag, loc_id, status) VALUES (?, ?, 'ACTIVE')");
      $insert->bind_param('si', $palletTag, $locId);
      $insert->execute();
      $insert->close();

      return ['id' => (int) $conn->insert_id, 'tag' => $palletTag];
    } catch (\mysqli_sql_exception $e) {
      if ((int) $e->getCode() !== 1062) {
        throw $e;
      }
    }
  }

  return null;
}

function receiving_find_or_create_sku(mysqli $conn, array $item): int
{
  $skuNum = trim((string) ($item['sku'] ?? ''));
  if ($skuNum === '') {
    throw new RuntimeException('Each counted line needs a SKU before it can be imported.');
  }

  $skuId = inv_find_sku_id_by_num($conn, $skuNum);
  if ($skuId > 0) {
    return $skuId;
  }

  $desc = trim((string) ($item['description'] ?? ''));
  if ($desc === '') {
    $desc = $skuNum;
  }
  $desc = substr($desc, 0, 255);
  $status = 'ACTIVE';

  $stmt = $conn->prepare('INSERT INTO sku (sku_num, `desc`, `status`) VALUES (?, ?, ?)');
  if (!$stmt) {
    throw new RuntimeException('Unable to create SKU record: ' . $conn->error);
  }
  $stmt->bind_param('sss', $skuNum, $desc, $status);
  $stmt->execute();
  $skuId = (int) $conn->insert_id;
  $stmt->close();

  return $skuId;
}

function receiving_insert_report(mysqli $conn, array $report, int $userId, string $status): int
{
  $sql = "INSERT INTO receiving_reports (
      purchase_order,
      arrival_date,
      page_number,
      page_total,
      delivery_type,
      delivered_by,
      trailer_damaged,
      container_count,
      vendor,
      trailer_number,
      odor_dirty,
      inspected_by,
      packing_slip,
      notes,
      damaged_product,
      received_by_name,
      received_on,
      counted_by_name,
      floor_location_code,
      import_status,
      imported_by_user_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

  $stmt = receiving_prepare_or_fail($conn, $sql);
  $pageNumber = (int) ($report['page_number'] !== '' ? $report['page_number'] : 1);
  $pageTotal = (int) ($report['page_total'] !== '' ? $report['page_total'] : 1);
  $stmt->bind_param(
    'ssiissssssssssssssssi',
    $report['purchase_order'],
    $report['arrival_date'],
    $pageNumber,
    $pageTotal,
    $report['delivery_type'],
    $report['delivered_by'],
    $report['trailer_damaged'],
    $report['container_count'],
    $report['vendor'],
    $report['trailer_number'],
    $report['odor_dirty'],
    $report['inspected_by'],
    $report['packing_slip'],
    $report['notes'],
    $report['damaged_product'],
    $report['received_by'],
    $report['received_on'],
    $report['counted_by'],
    $report['floor_location'],
    $status,
    $userId
  );
  $stmt->execute();
  $reportId = (int) $conn->insert_id;
  $stmt->close();

  return $reportId;
}

function receiving_insert_report_item(mysqli $conn, int $reportId, array $item, int $skuId, int $locId, ?int $movementId): void
{
  $orderedQty = receiving_decimal_value((string) ($item['ordered'] ?? ''));
  $receivedQty = receiving_decimal_value((string) ($item['received'] ?? ''));
  $countedQty = (int) receiving_parse_counted_qty((string) ($item['qty'] ?? ''));
  $poNumber = trim((string) ($item['po'] ?? '')) ?: null;
  $stmt = receiving_prepare_or_fail(
    $conn,
    "INSERT INTO receiving_report_items (
      receiving_report_id,
      sku_id,
      sku_num,
      description,
      ordered_qty,
      received_qty,
      counted_qty,
      po_number,
      inventory_loc_id,
      inventory_movement_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );
  $stmt->bind_param(
    'iissssisii',
    $reportId,
    $skuId,
    $item['sku'],
    $item['description'],
    $orderedQty,
    $receivedQty,
    $countedQty,
    $poNumber,
    $locId,
    $movementId
  );
  $stmt->execute();
  $stmt->close();
}

function receiving_insert_inventory_movement(mysqli $conn, int $skuId, int $locId, int $qtyChange, string $movementType, string $reference, int $userId): int
{
  $stmt = receiving_prepare_or_fail(
    $conn,
    "INSERT INTO inventory_movements (sku_id, loc_id, quantity_change, movement_type, reference, user_id)
     VALUES (?, ?, ?, ?, ?, ?)"
  );
  $stmt->bind_param('iiissi', $skuId, $locId, $qtyChange, $movementType, $reference, $userId);
  $stmt->execute();
  $movementId = (int) $conn->insert_id;
  $stmt->close();

  return $movementId;
}

function receiving_media_table_exists(mysqli $conn): bool
{
  static $exists = null;
  if ($exists !== null) {
    return $exists;
  }

  $result = $conn->query("SHOW TABLES LIKE 'receiving_report_media'");
  $exists = $result instanceof mysqli_result && $result->num_rows > 0;
  if ($result instanceof mysqli_result) {
    $result->close();
  }

  return $exists;
}

function receiving_media_has_uploads(): bool
{
  foreach (['packing_slip_photo', 'damaged_product_photo'] as $field) {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file)) {
      continue;
    }

    $errors = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (is_array($errors)) {
      foreach ($errors as $error) {
        if ((int) $error !== UPLOAD_ERR_NO_FILE) {
          return true;
        }
      }
    } elseif ((int) $errors !== UPLOAD_ERR_NO_FILE) {
      return true;
    }
  }

  return false;
}

function receiving_media_label(string $mediaType): string
{
  return match ($mediaType) {
    'packing_slip' => 'Packing Slip Photo',
    'damaged_product' => 'Damaged Product Photo',
    default => ucwords(str_replace('_', ' ', $mediaType)),
  };
}

function receiving_media_upload_root(): string
{
  return __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'receiving';
}

function receiving_media_relative_url(int $mediaId): string
{
  return 'receiving.php?receiving_report_media_file=1&media_id=' . $mediaId;
}

function receiving_store_media_upload(mysqli $conn, array $file, int $reportId, string $mediaType, int $userId): ?array
{
  $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($error === UPLOAD_ERR_NO_FILE) {
    return null;
  }

  if ($error !== UPLOAD_ERR_OK) {
    throw new RuntimeException('The ' . strtolower(receiving_media_label($mediaType)) . ' upload failed with error code ' . $error . '.');
  }

  $tmpPath = (string) ($file['tmp_name'] ?? '');
  if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    throw new RuntimeException('The uploaded media file could not be verified.');
  }

  $sizeBytes = (int) ($file['size'] ?? 0);
  if ($sizeBytes <= 0) {
    throw new RuntimeException('Uploaded media files cannot be empty.');
  }

  if ($sizeBytes > 10 * 1024 * 1024) {
    throw new RuntimeException('Uploaded media files must be 10MB or smaller.');
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mimeType = (string) $finfo->file($tmpPath);
  $allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
  ];
  $extension = $allowedTypes[$mimeType] ?? null;

  if ($extension === null) {
    throw new RuntimeException('Only image uploads are supported for receiving photos.');
  }

  $relativeDir = date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . 'report_' . $reportId;
  $absoluteDir = receiving_media_upload_root() . DIRECTORY_SEPARATOR . $relativeDir;
  if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
    throw new RuntimeException('Unable to create media storage for receiving uploads.');
  }

  $storedName = $mediaType . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
  $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
  if (!move_uploaded_file($tmpPath, $absolutePath)) {
    throw new RuntimeException('Unable to save the uploaded media file.');
  }

  $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativeDir . DIRECTORY_SEPARATOR . $storedName);
  $originalName = substr((string) ($file['name'] ?? $storedName), 0, 255);

  $stmt = receiving_prepare_or_fail(
    $conn,
    "INSERT INTO receiving_report_media (
      receiving_report_id,
      media_type,
      original_name,
      stored_name,
      relative_path,
      mime_type,
      size_bytes,
      uploaded_by_user_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
  );
  $stmt->bind_param(
    'isssssii',
    $reportId,
    $mediaType,
    $originalName,
    $storedName,
    $relativePath,
    $mimeType,
    $sizeBytes,
    $userId
  );
  $stmt->execute();
  $mediaId = (int) $conn->insert_id;
  $stmt->close();

  return [
    'id' => $mediaId,
    'path' => $absolutePath,
  ];
}

function receiving_store_report_media(mysqli $conn, int $reportId, int $userId): array
{
  $stored = [];
  $fieldMap = [
    'packing_slip_photo' => 'packing_slip',
    'damaged_product_photo' => 'damaged_product',
  ];

  foreach ($fieldMap as $field => $mediaType) {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file)) {
      continue;
    }

    $errors = $file['error'] ?? null;
    if (is_array($errors)) {
      $totalFiles = count($errors);
      for ($index = 0; $index < $totalFiles; $index++) {
        $singleFile = [
          'name' => $file['name'][$index] ?? '',
          'type' => $file['type'][$index] ?? '',
          'tmp_name' => $file['tmp_name'][$index] ?? '',
          'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
          'size' => $file['size'][$index] ?? 0,
        ];
        $saved = receiving_store_media_upload($conn, $singleFile, $reportId, $mediaType, $userId);
        if ($saved !== null) {
          $stored[] = $saved;
        }
      }
    } else {
      $saved = receiving_store_media_upload($conn, $file, $reportId, $mediaType, $userId);
      if ($saved !== null) {
        $stored[] = $saved;
      }
    }
  }

  return $stored;
}

function receiving_search_saved_reports(mysqli $conn, string $query, int $limit = 20): array
{
  $term = trim($query);
  if ($term === '') {
    return [];
  }

  $like = '%' . $term . '%';
  $startsWith = $term . '%';
  $sql = "SELECT
      rr.id,
      rr.purchase_order,
      rr.arrival_date,
      rr.vendor,
      rr.received_by_name,
      rr.received_on,
      rr.counted_by_name,
      rr.floor_location_code,
      rr.import_status,
      rr.created_at,
      COUNT(rri.id) AS item_count,
      COALESCE(SUM(rri.counted_qty), 0) AS total_counted_qty
    FROM receiving_reports rr
    LEFT JOIN receiving_report_items rri ON rri.receiving_report_id = rr.id
    WHERE rr.purchase_order LIKE ?
       OR rr.vendor LIKE ?
       OR rr.received_by_name LIKE ?
       OR rr.counted_by_name LIKE ?
    GROUP BY
      rr.id,
      rr.purchase_order,
      rr.arrival_date,
      rr.vendor,
      rr.received_by_name,
      rr.received_on,
      rr.counted_by_name,
      rr.floor_location_code,
      rr.import_status,
      rr.created_at
    ORDER BY
      CASE
        WHEN rr.purchase_order = ? THEN 0
        WHEN rr.purchase_order LIKE ? THEN 1
        ELSE 2
      END,
      rr.created_at DESC
    LIMIT ?";

  $stmt = receiving_prepare_or_fail($conn, $sql);
  $stmt->bind_param('ssssssi', $like, $like, $like, $like, $term, $startsWith, $limit);
  $stmt->execute();
  $result = $stmt->get_result();
  $rows = [];

  while ($row = $result->fetch_assoc()) {
    $rows[] = [
      'id' => (int) ($row['id'] ?? 0),
      'purchase_order' => (string) ($row['purchase_order'] ?? ''),
      'arrival_date' => (string) ($row['arrival_date'] ?? ''),
      'vendor' => (string) ($row['vendor'] ?? ''),
      'received_by' => (string) ($row['received_by_name'] ?? ''),
      'received_on' => (string) ($row['received_on'] ?? ''),
      'counted_by' => (string) ($row['counted_by_name'] ?? ''),
      'floor_location' => (string) ($row['floor_location_code'] ?? ''),
      'import_status' => (string) ($row['import_status'] ?? ''),
      'created_at' => (string) ($row['created_at'] ?? ''),
      'item_count' => (int) ($row['item_count'] ?? 0),
      'total_counted_qty' => (int) ($row['total_counted_qty'] ?? 0),
    ];
  }

  $stmt->close();

  return $rows;
}

function receiving_load_saved_report(mysqli $conn, int $reportId): ?array
{
  if ($reportId <= 0) {
    return null;
  }

  $reportStmt = receiving_prepare_or_fail(
    $conn,
    "SELECT
      id,
      purchase_order,
      arrival_date,
      page_number,
      page_total,
      delivery_type,
      delivered_by,
      trailer_damaged,
      container_count,
      vendor,
      trailer_number,
      odor_dirty,
      inspected_by,
      packing_slip,
      notes,
      damaged_product,
      received_by_name,
      received_on,
      counted_by_name,
      floor_location_code,
      import_status,
      imported_to_inventory_at,
      created_at
     FROM receiving_reports
     WHERE id = ?
     LIMIT 1"
  );
  $reportStmt->bind_param('i', $reportId);
  $reportStmt->execute();
  $report = $reportStmt->get_result()->fetch_assoc() ?: null;
  $reportStmt->close();

  if (!$report) {
    return null;
  }

  $itemsStmt = receiving_prepare_or_fail(
    $conn,
    "SELECT
      sku_num,
      description,
      ordered_qty,
      received_qty,
      counted_qty,
      po_number
     FROM receiving_report_items
     WHERE receiving_report_id = ?
     ORDER BY id ASC"
  );
  $itemsStmt->bind_param('i', $reportId);
  $itemsStmt->execute();
  $result = $itemsStmt->get_result();
  $items = [];

  while ($row = $result->fetch_assoc()) {
    $items[] = [
      'sku' => (string) ($row['sku_num'] ?? ''),
      'description' => (string) ($row['description'] ?? ''),
      'ordered' => $row['ordered_qty'] !== null ? receiving_preview_number((string) $row['ordered_qty']) : '',
      'received' => $row['received_qty'] !== null ? receiving_preview_number((string) $row['received_qty']) : '',
      'counted' => (int) ($row['counted_qty'] ?? 0),
      'po' => (string) ($row['po_number'] ?? ''),
    ];
  }

  $itemsStmt->close();

  $media = [];
  if (receiving_media_table_exists($conn)) {
    $mediaStmt = receiving_prepare_or_fail(
      $conn,
      "SELECT
        id,
        media_type,
        original_name,
        mime_type,
        size_bytes,
        created_at
       FROM receiving_report_media
       WHERE receiving_report_id = ?
       ORDER BY id ASC"
    );
    $mediaStmt->bind_param('i', $reportId);
    $mediaStmt->execute();
    $mediaResult = $mediaStmt->get_result();

    while ($row = $mediaResult->fetch_assoc()) {
      $mimeType = (string) ($row['mime_type'] ?? '');
      $mediaId = (int) ($row['id'] ?? 0);
      $media[] = [
        'id' => $mediaId,
        'media_type' => (string) ($row['media_type'] ?? ''),
        'label' => receiving_media_label((string) ($row['media_type'] ?? '')),
        'original_name' => (string) ($row['original_name'] ?? ''),
        'mime_type' => $mimeType,
        'is_image' => str_starts_with($mimeType, 'image/'),
        'size_bytes' => (int) ($row['size_bytes'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'url' => receiving_media_relative_url($mediaId),
      ];
    }

    $mediaStmt->close();
  }

  return [
    'report' => [
      'id' => (int) ($report['id'] ?? 0),
      'purchase_order' => (string) ($report['purchase_order'] ?? ''),
      'arrival_date' => (string) ($report['arrival_date'] ?? ''),
      'page_number' => (int) ($report['page_number'] ?? 1),
      'page_total' => (int) ($report['page_total'] ?? 1),
      'delivery_type' => (string) ($report['delivery_type'] ?? ''),
      'delivered_by' => (string) ($report['delivered_by'] ?? ''),
      'trailer_damaged' => (string) ($report['trailer_damaged'] ?? ''),
      'container_count' => (string) ($report['container_count'] ?? ''),
      'vendor' => (string) ($report['vendor'] ?? ''),
      'trailer_number' => (string) ($report['trailer_number'] ?? ''),
      'odor_dirty' => (string) ($report['odor_dirty'] ?? ''),
      'inspected_by' => (string) ($report['inspected_by'] ?? ''),
      'packing_slip' => (string) ($report['packing_slip'] ?? ''),
      'notes' => (string) ($report['notes'] ?? ''),
      'damaged_product' => (string) ($report['damaged_product'] ?? ''),
      'received_by' => (string) ($report['received_by_name'] ?? ''),
      'received_on' => (string) ($report['received_on'] ?? ''),
      'counted_by' => (string) ($report['counted_by_name'] ?? ''),
      'floor_location' => (string) ($report['floor_location_code'] ?? ''),
      'import_status' => (string) ($report['import_status'] ?? ''),
      'imported_to_inventory_at' => (string) ($report['imported_to_inventory_at'] ?? ''),
      'created_at' => (string) ($report['created_at'] ?? ''),
    ],
    'items' => $items,
    'media' => $media,
  ];
}

if (isset($_GET['receiving_report_media_file'])) {
  $mediaId = (int) ($_GET['media_id'] ?? 0);
  if ($mediaId <= 0 || !receiving_media_table_exists($conn)) {
    http_response_code(404);
    exit('Media file not found.');
  }

  $stmt = receiving_prepare_or_fail(
    $conn,
    "SELECT original_name, relative_path, mime_type
     FROM receiving_report_media
     WHERE id = ?
     LIMIT 1"
  );
  $stmt->bind_param('i', $mediaId);
  $stmt->execute();
  $media = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();

  if (!$media) {
    http_response_code(404);
    exit('Media file not found.');
  }

  $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($media['relative_path'] ?? ''));
  $absolutePath = receiving_media_upload_root() . DIRECTORY_SEPARATOR . $relativePath;
  if (!is_file($absolutePath)) {
    http_response_code(404);
    exit('Media file not found.');
  }

  $mimeType = (string) ($media['mime_type'] ?? 'application/octet-stream');
  $filename = basename((string) ($media['original_name'] ?? 'receiving-media'));
  header('Content-Type: ' . $mimeType);
  header('Content-Length: ' . (string) filesize($absolutePath));
  header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
  readfile($absolutePath);
  exit;
}

if (isset($_GET['receiving_report_lookup'])) {
  header('Content-Type: application/json; charset=UTF-8');

  try {
    $query = trim((string) ($_GET['q'] ?? ''));
    $reportId = (int) ($_GET['report_id'] ?? 0);

    if ($reportId > 0) {
      $payload = receiving_load_saved_report($conn, $reportId);
      if ($payload === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Receiving report not found.']);
      } else {
        echo json_encode(['ok' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
      }
    } else {
      echo json_encode([
        'ok' => true,
        'results' => receiving_search_saved_reports($conn, $query),
      ], JSON_UNESCAPED_UNICODE);
    }
  } catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
  }

  exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$import_error = '';
$import_notice = '';
$push_error = '';
$push_notice = '';
$push_result_payload = null;
$import_preview_items = receiving_items(is_array($_POST['import_preview_items'] ?? null) ? $_POST['import_preview_items'] : [], 0);
$import_preview_report = receiving_preview_report(is_array($_POST['import_preview_report'] ?? null) ? $_POST['import_preview_report'] : []);

$report = [
  'purchase_order' => receiving_value('purchase_order'),
  'arrival_date' => receiving_value('arrival_date', date('Y-m-d')),
  'page_number' => receiving_value('page_number', '1'),
  'page_total' => receiving_value('page_total', '1'),
  'delivery_type' => receiving_choice('delivery_type'),
  'delivered_by' => receiving_value('delivered_by'),
  'trailer_damaged' => receiving_choice('trailer_damaged'),
  'container_count' => receiving_value('container_count'),
  'vendor' => receiving_value('vendor'),
  'trailer_number' => receiving_value('trailer_number'),
  'odor_dirty' => receiving_choice('odor_dirty'),
  'inspected_by' => receiving_value('inspected_by'),
  'packing_slip' => receiving_choice('packing_slip'),
  'notes' => receiving_value('notes'),
  'damaged_product' => receiving_choice('damaged_product'),
  'received_by' => receiving_value('received_by', $_SESSION['username'] ?? ''),
  'received_on' => receiving_value('received_on', date('Y-m-d')),
  'counted_by' => receiving_value('counted_by'),
  'floor_location' => receiving_value('floor_location', 'RECEIVING-01-11-F'),
];

$items = receiving_items(is_array($_POST['items'] ?? null) ? $_POST['items'] : []);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import_receipt') {
  $upload = $_FILES['receipt_import_file'] ?? null;

  if (!is_array($upload) || (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
    $import_error = 'Choose a PDF or spreadsheet file to import.';
  } elseif (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    $import_error = 'The import file upload failed with error code ' . (string) ($upload['error'] ?? '');
  } else {
    $fileName = (string) ($upload['name'] ?? 'receipt.xlsx');
    $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    $tmpPath = (string) ($upload['tmp_name'] ?? '');

    $read = inv_read_raw_rows($tmpPath, $extension, $fileName);
    if ($read['error'] !== '') {
      $import_error = $read['error'];
    } else {
      $parsed = inv_parse_receiving_receipt_rows($read['rows'], $report['purchase_order']);
      if (($parsed['error'] ?? '') !== '') {
        $import_error = (string) $parsed['error'];
      } else {
        $import_preview_items = receiving_items($parsed['items'] ?? [], 0);
        $import_preview_report = receiving_preview_report($parsed['report'] ?? []);
        $import_notice = 'Review and select the line item(s) to add from ' . $fileName . '.';
      }
    }
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'apply_import_selection') {
  $selectedIndexes = array_map('intval', (array) ($_POST['import_selected'] ?? []));
  $selectedLookup = array_fill_keys($selectedIndexes, true);
  $selectedItems = [];

  foreach ($import_preview_items as $index => $item) {
    if (isset($selectedLookup[$index]) && receiving_has_item_content($item)) {
      $selectedItems[] = $item;
    }
  }

  if ($selectedItems === []) {
    $import_error = 'Select at least one imported line item to add.';
  } else {
    $report = array_merge($report, array_filter($import_preview_report, static fn($value) => $value !== ''));
    $existingItems = receiving_filter_items($items);
    $mergedItems = array_merge($existingItems, $selectedItems);
    $items = receiving_items($mergedItems, max(5, count($mergedItems)));
    $import_preview_items = [];
    $import_preview_report = [];
    $import_notice = 'Added ' . count($selectedItems) . ' selected line item(s) to the receiving form.';
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel_import_preview') {
  $import_preview_items = [];
  $import_preview_report = [];
  $import_notice = 'Import preview cancelled.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'push_counted_to_inventory') {
  $countedItems = receiving_counted_items($items);
  $floorLocation = trim($report['floor_location']);
  $validationErrors = receiving_validate_push($report, $items);

  if ($validationErrors !== []) {
    $push_error = implode(' ', $validationErrors);
  } elseif ($floorLocation === '') {
    $push_error = 'Enter the receiving location before pushing counted data.';
  } elseif ($countedItems === []) {
    $push_error = 'Add at least one counted quantity before pushing data to inventory.';
  } else {
    $locId = inv_find_location_id_by_code($conn, $floorLocation);
    if ($locId <= 0) {
      $push_error = 'The location ' . $floorLocation . ' was not found.';
    } else {
      $invalidLines = [];
      foreach (receiving_filter_items($items) as $index => $item) {
        $rawQty = trim((string) ($item['qty'] ?? ''));
        if ($rawQty === '' || in_array($rawQty, ['0', '0.0', '0.00', '0.000', '0.0000', '0.00000'], true)) {
          continue;
        }

        if (trim((string) ($item['sku'] ?? '')) === '') {
          $invalidLines[] = 'line ' . ($index + 1) . ' is missing a SKU';
        } elseif (receiving_parse_counted_qty($rawQty) === null) {
          $invalidLines[] = 'line ' . ($index + 1) . ' has an invalid counted quantity';
        }
      }

      if ($invalidLines !== []) {
        $push_error = 'Cannot import until ' . implode('; ', $invalidLines) . '.';
      } elseif (receiving_media_has_uploads() && !receiving_media_table_exists($conn)) {
        $push_error = 'Receiving media storage is not available yet. Apply the receiving media table migration before uploading photos.';
      } else {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $storedMediaFiles = [];
        $pushChangeDetails = [];
        $conn->begin_transaction();

        try {
          $reportId = receiving_insert_report($conn, $report, $userId, 'PENDING');
          if (receiving_media_has_uploads()) {
            $storedMediaFiles = receiving_store_report_media($conn, $reportId, $userId);
          }
          $pallet = receiving_ensure_location_pallet($conn, $locId);
          if (!$pallet) {
            throw new RuntimeException('Unable to prepare storage for the destination receiving location.');
          }

          $selectInventoryStmt = receiving_prepare_or_fail(
            $conn,
            "SELECT quantity
             FROM inventory
             WHERE sku_id = ? AND pallet_id = ?
             LIMIT 1
             FOR UPDATE"
          );

          $inventoryStmt = receiving_prepare_or_fail(
            $conn,
            "INSERT INTO inventory (sku_id, pallet_id, quantity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = CURRENT_TIMESTAMP"
          );

          foreach ($countedItems as $item) {
            $countedQty = (int) receiving_parse_counted_qty((string) ($item['qty'] ?? ''));
            $skuId = receiving_find_or_create_sku($conn, $item);

            $currentQty = 0;
            $selectInventoryStmt->bind_param('ii', $skuId, $pallet['id']);
            $selectInventoryStmt->execute();
            $existingRow = $selectInventoryStmt->get_result()->fetch_assoc();
            if ($existingRow) {
              $currentQty = (int) ($existingRow['quantity'] ?? 0);
            }

            $inventoryStmt->bind_param('iii', $skuId, $pallet['id'], $countedQty);
            $inventoryStmt->execute();

            $deltaQty = $countedQty;
            $newQty = $currentQty + $countedQty;
            $poRef = trim((string) ($item['po'] ?? '')) !== '' ? trim((string) $item['po']) : trim((string) $report['purchase_order']);
            $reference = $poRef !== '' ? 'Receiving PO# ' . $poRef : 'Receiving Count Import';
            $movementId = null;
            $movementType = 'IN';

            if ($deltaQty > 0) {
              $movementId = receiving_insert_inventory_movement($conn, $skuId, $locId, $deltaQty, $movementType, $reference, $userId);
            }

            $pushChangeDetails[] = [
              'sku' => (string) ($item['sku'] ?? ''),
              'description' => (string) ($item['description'] ?? ''),
              'po' => $poRef,
              'previous_qty' => $currentQty,
              'counted_qty' => $countedQty,
              'delta_qty' => $deltaQty,
              'new_qty' => $newQty,
              'movement_type' => $movementType,
            ];

            receiving_insert_report_item($conn, $reportId, $item, $skuId, $locId, $movementId > 0 ? $movementId : null);
          }

          $selectInventoryStmt->close();
          $inventoryStmt->close();

          $status = 'IMPORTED';
          $update = receiving_prepare_or_fail(
            $conn,
            "UPDATE receiving_reports
             SET import_status = ?, imported_to_inventory_at = CURRENT_TIMESTAMP, imported_by_user_id = ?
             WHERE id = ?"
          );
          $update->bind_param('sii', $status, $userId, $reportId);
          $update->execute();
          $update->close();

          $conn->commit();
          $push_notice = 'Added ' . count($countedItems) . ' receiving line item(s) totaling ' . receiving_total_counted_qty($countedItems) . ' unit(s) into inventory at ' . $floorLocation . '.';
          $push_result_payload = [
            'report_id' => $reportId,
            'purchase_order' => trim((string) $report['purchase_order']),
            'location' => $floorLocation,
            'line_count' => count($countedItems),
            'total_counted_qty' => receiving_total_counted_qty($countedItems),
            'changes' => $pushChangeDetails,
          ];

          $report = [
            'purchase_order' => '',
            'arrival_date' => date('Y-m-d'),
            'page_number' => '1',
            'page_total' => '1',
            'delivery_type' => '',
            'delivered_by' => '',
            'trailer_damaged' => '',
            'container_count' => '',
            'vendor' => '',
            'trailer_number' => '',
            'odor_dirty' => '',
            'inspected_by' => '',
            'packing_slip' => '',
            'notes' => '',
            'damaged_product' => '',
            'received_by' => (string) ($_SESSION['username'] ?? ''),
            'received_on' => date('Y-m-d'),
            'counted_by' => '',
            'floor_location' => 'RECEIVING-01-11-F',
          ];
          $items = receiving_items([]);
          $import_preview_items = [];
          $import_preview_report = [];
          $import_notice = '';
          $import_error = '';
        } catch (\Throwable $e) {
          $conn->rollback();
          foreach ($storedMediaFiles as $storedMedia) {
            $path = (string) ($storedMedia['path'] ?? '');
            if ($path !== '' && is_file($path)) {
              @unlink($path);
            }
          }
          $push_error = 'Inventory import failed: ' . $e->getMessage();
        }
      }
    }
  }
}

$import_panel_open = $import_preview_items !== [] || $import_error !== '' || $action === 'import_receipt';
$push_attempted = $action === 'push_counted_to_inventory';

ob_start();
?>
<section class="receiving-shell">
  <header class="receiving-intro">
    <div>
      <h2>Receiving</h2>
    </div>
    <div class="receiving-toolbar__actions">
      <button type="button" class="btn btn--ghost" id="receivingImportToggleBtn"
        aria-expanded="<?= $import_panel_open ? 'true' : 'false' ?>"
        aria-controls="receivingImportPanel">Import</button>
      <button type="button" class="btn btn--ghost" id="receivingHistoryLaunchBtn">Search</button>
    </div>
  </header>

  <form method="post" class="receiving-app" autocomplete="off" enctype="multipart/form-data">
    <div class="card card--pad receiving-import-panel no-print" id="receivingImportPanel" <?= $import_panel_open ? '' : 'hidden' ?>>
      <div class="receiving-import">
        <label class="receiving-import__label" for="receiptImportFile">Import Receipt (.xlsx or .pdf)</label>
        <div class="receiving-import__controls">
          <input id="receiptImportFile" class="receiving-import__input" type="file" name="receipt_import_file"
            accept=".pdf,.xlsx,.xlsm,.csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" />
          <button type="submit" class="btn btn--ghost" name="action" value="import_receipt" formnovalidate>Import
            Document</button>
        </div>
        <p class="receiving-import__help">Upload a purchase receipt or purchase order file, then choose which line
          items to add to the receiving table.</p>
        <?php if ($import_error !== ''): ?>
          <div class="receiving-import__status is-error">
            <?= h($import_error) ?>
          </div>
        <?php elseif ($import_notice !== ''): ?>
          <div class="receiving-import__status is-success">
            <?= h($import_notice) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($import_preview_items !== []): ?>
      <section class="card card--pad receiving-import-preview no-print">
        <div class="receiving-import-preview__header">
          <div class="receiving-import-preview__intro">
            <p class="receiving-eyebrow">Import Review</p>
            <h2>Choose Items To Add</h2>
            <p class="receiving-import-preview__summary">
              <?= count($import_preview_items) ?>
              imported line item<?= count($import_preview_items) === 1 ? '' : 's' ?> ready for review.
            </p>
          </div>
          <div class="receiving-import-preview__actions">
            <button type="button" class="btn btn--ghost" id="receivingImportSelectAllBtn">Select All</button>
            <button type="button" class="btn btn--ghost" id="receivingImportClearBtn">Clear</button>
            <button type="submit" class="btn btn--ghost" name="action" value="apply_import_selection" formnovalidate>Add
              Selected
              Lines</button>
            <button type="submit" class="btn btn--ghost" name="action" value="cancel_import_preview" formnovalidate>Cancel
              Preview</button>
          </div>
        </div>

        <?php foreach ($import_preview_report as $key => $value): ?>
          <input type="hidden" name="import_preview_report[<?= h($key) ?>]" value="<?= h($value) ?>" />
        <?php endforeach; ?>

        <?php if ($import_preview_report['purchase_order'] !== '' || $import_preview_report['arrival_date'] !== '' || $import_preview_report['vendor'] !== ''): ?>
          <div class="receiving-import-preview__meta">
            <?php if ($import_preview_report['purchase_order'] !== ''): ?>
              <span class="receiving-import-preview__meta-item">
                <strong>PO #</strong>
                <span><?= h($import_preview_report['purchase_order']) ?></span>
              </span>
            <?php endif; ?>
            <?php if ($import_preview_report['arrival_date'] !== ''): ?>
              <span class="receiving-import-preview__meta-item">
                <strong>Date</strong>
                <span><?= h($import_preview_report['arrival_date']) ?></span>
              </span>
            <?php endif; ?>
            <?php if ($import_preview_report['vendor'] !== ''): ?>
              <span class="receiving-import-preview__meta-item">
                <strong>Vendor</strong>
                <span><?= h($import_preview_report['vendor']) ?></span>
              </span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="receiving-import-preview__table-wrap">
          <table class="receiving-import-preview__table">
            <thead>
              <tr>
                <th class="receiving-import-preview__select-col">Import</th>
                <th>SKU</th>
                <th>Description</th>
                <th>Unit</th>
                <th>Ordered</th>
                <th>Received</th>
                <th>PO #</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($import_preview_items as $index => $item): ?>
                <tr>
                  <td class="receiving-import-preview__select-col">
                    <input type="checkbox" class="receiving-import-preview__checkbox" name="import_selected[]"
                      value="<?= $index ?>" checked />
                    <input type="hidden" name="import_preview_items[<?= $index ?>][sku]" value="<?= h($item['sku']) ?>" />
                    <input type="hidden" name="import_preview_items[<?= $index ?>][description]"
                      value="<?= h($item['description']) ?>" />
                    <input type="hidden" name="import_preview_items[<?= $index ?>][unit]" value="<?= h($item['unit']) ?>" />
                    <input type="hidden" name="import_preview_items[<?= $index ?>][ordered]"
                      value="<?= h($item['ordered']) ?>" />
                    <input type="hidden" name="import_preview_items[<?= $index ?>][received]"
                      value="<?= h($item['received']) ?>" />
                    <input type="hidden" name="import_preview_items[<?= $index ?>][qty]" value="<?= h($item['qty']) ?>" />
                    <input type="hidden" name="import_preview_items[<?= $index ?>][po]" value="<?= h($item['po']) ?>" />
                  </td>
                  <td class="receiving-import-preview__sku">
                    <?= h($item['sku']) ?>
                  </td>
                  <td class="receiving-import-preview__description">
                    <?= h($item['description']) ?>
                  </td>
                  <td class="receiving-import-preview__unit">
                    <?= h($item['unit']) ?>
                  </td>
                  <td class="receiving-import-preview__qty">
                    <?= h(receiving_preview_number($item['ordered'])) ?>
                  </td>
                  <td class="receiving-import-preview__qty">
                    <?= h(receiving_preview_number($item['received'])) ?>
                  </td>
                  <td class="receiving-import-preview__po">
                    <?= h($item['po']) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
    <article class="receiving-sheet">
      <section class="receiving-sheet__masthead">

        <label class="receiving-sheet__cell receiving-sheet__cell--po">
          <span>Purchase Order #</span>
          <input type="text" name="purchase_order" value="<?= h($report['purchase_order']) ?>" required />
        </label>


        <label class="receiving-sheet__cell receiving-sheet__cell--arrival">
          <span>Arrival Date</span>
          <input type="date" name="arrival_date" value="<?= h($report['arrival_date']) ?>" required />
        </label>

        <div class="receiving-sheet__cell receiving-sheet__cell--pages">
          <div class="receiving-pages">
            <label>
              <span>Page</span>
              <input type="number" min="1" name="page_number" value="<?= h($report['page_number']) ?>" />
            </label>
            <span class="receiving-title__divider">of</span>
            <label>
              <span>Total</span>
              <input type="number" min="1" name="page_total" value="<?= h($report['page_total']) ?>" />
            </label>
          </div>
        </div>
      </section>

      <section class="receiving-grid">
        <div class="receiving-grid__main">
          <div class="receiving-row">
            <div class="receiving-choice">
              <span class="receiving-label">Delivered As</span>
              <label><input type="radio" name="delivery_type" value="parcel" <?= $report['delivery_type'] === 'parcel' ? 'checked' : '' ?> required /> Parcel</label>
              <label><input type="radio" name="delivery_type" value="pallet" <?= $report['delivery_type'] === 'pallet' ? 'checked' : '' ?> /> Pallet</label>
            </div>

            <label class="receiving-field">
              <span>Delivered By</span>
              <input type="text" name="delivered_by" value="<?= h($report['delivered_by']) ?>" />
            </label>

            <div class="receiving-choice">
              <span class="receiving-label">Trailer Damaged?</span>
              <label><input type="radio" name="trailer_damaged" value="yes" <?= $report['trailer_damaged'] === 'yes' ? 'checked' : '' ?> required /> Yes</label>
              <label><input type="radio" name="trailer_damaged" value="no" <?= $report['trailer_damaged'] === 'no' ? 'checked' : '' ?> /> No</label>
              <small>Holes, roof leak, visual damage to trailer.</small>
            </div>
          </div>

          <div class="receiving-row">
            <label class="receiving-field">
              <span>Number of CTNS / PLTS Delivered</span>
              <input type="text" name="container_count" value="<?= h($report['container_count']) ?>" />
            </label>

            <label class="receiving-field">
              <span>Vendor</span>
              <input type="text" name="vendor" value="<?= h($report['vendor']) ?>" />
            </label>

            <label class="receiving-field">
              <span>Trailer #</span>
              <input type="text" name="trailer_number" value="<?= h($report['trailer_number']) ?>" />
            </label>
          </div>

          <div class="receiving-row">
            <div class="receiving-choice">
              <span class="receiving-label">Packing Slip Included?</span>
              <label><input type="radio" name="packing_slip" value="yes" <?= $report['packing_slip'] === 'yes' ? 'checked' : '' ?> required /> Yes</label>
              <label><input type="radio" name="packing_slip" value="no" <?= $report['packing_slip'] === 'no' ? 'checked' : '' ?> /> No</label>
              <div class="receiving-media" id="receivingPackingSlipMediaWrap" <?= $report['packing_slip'] === 'yes' ? '' : 'hidden' ?>>
                <label class="receiving-media__button" for="receivingPackingSlipPhoto">Take Photo(s)</label>
                <input id="receivingPackingSlipPhoto" class="receiving-media__input" type="file"
                  name="packing_slip_photo[]" accept="image/*" capture="environment" multiple />
                <div class="receiving-media__filename" id="receivingPackingSlipPhotoName">No photos selected</div>
                <div class="receiving-media__preview" id="receivingPackingSlipPhotoPreview" hidden></div>
              </div>
            </div>

            <div class="receiving-choice">
              <span class="receiving-label">Bad Odor / Dirty?</span>
              <label><input type="radio" name="odor_dirty" value="yes" <?= $report['odor_dirty'] === 'yes' ? 'checked' : '' ?> required /> Yes</label>
              <label><input type="radio" name="odor_dirty" value="no" <?= $report['odor_dirty'] === 'no' ? 'checked' : '' ?> /> No</label>
            </div>

            <label class="receiving-field">
              <span>Inspected By</span>
              <input type="text" name="inspected_by" value="<?= h($report['inspected_by']) ?>" />
            </label>
          </div>
        </div>
      </section>

      <section class="receiving-notes">
        <div class="receiving-field receiving-field--notes">
          <span>Notes</span>
          <textarea name="notes" rows="6"><?= h($report['notes']) ?></textarea>

        </div>

        <div class="receiving-damage">
          <div class="receiving-damage__title">Damaged Product?</div>
          <div class="receiving-choice">
            <label><input type="radio" name="damaged_product" value="yes" <?= $report['damaged_product'] === 'yes' ? 'checked' : '' ?> required /> Yes</label>
            <label><input type="radio" name="damaged_product" value="no" <?= $report['damaged_product'] === 'no' ? 'checked' : '' ?> /> No</label>
          </div>
          <div class="receiving-media" id="receivingDamageMediaWrap" <?= $report['damaged_product'] === 'yes' ? '' : 'hidden' ?>>
            <label class="receiving-media__button" for="receivingDamagePhoto">Take Photo(s)</label>
            <input id="receivingDamagePhoto" class="receiving-media__input" type="file" name="damaged_product_photo[]"
              accept="image/*" capture="environment" multiple />
            <div class="receiving-media__filename" id="receivingDamagePhotoName">No photos selected</div>
            <div class="receiving-media__preview" id="receivingDamagePhotoPreview" hidden></div>
          </div>
        </div>
      </section>

      <section class="receiving-signoff">
        <label class="receiving-field">
          <span>Received By</span>
          <input type="text" name="received_by" value="<?= h($report['received_by']) ?>" required />
        </label>

        <label class="receiving-field">
          <span>RECEIVED DATE</span>
          <input type="date" name="received_on" value="<?= h($report['received_on']) ?>" required />
        </label>
      </section>



      <section class="receiving-lines">
        <div class="card card--pad receiving-toolbar no-print">
          <div>
            <p class="muted">Add line rows as needed, then print or save the filled form from your browser.</p>
          </div>
          <div class="receiving-toolbar__actions">
            <button type="button" class="btn btn--ghost" id="receivingAddRowBtn">Add Line</button>
            <button type="button" class="btn btn--ghost" id="receivingPrintBtn">Print</button>
            <button type="button" class="btn btn--ghost" id="receivingResetBtn">Reset</button>
          </div>
        </div>




        <table class="receiving-lines__table">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Description</th>
              <th>Unit</th>
              <th>Ordered</th>
              <th>Counted Qty</th>
              <th class="no-print receiving-lines__action-col">Action</th>
            </tr>
          </thead>
          <tbody id="receivingLinesBody" data-next-index="<?= count($items) ?>">
            <?php foreach ($items as $index => $item): ?>
              <tr>
                <td><input type="text" name="items[<?= $index ?>][sku]" value="<?= h($item['sku']) ?>" /></td>
                <td><input type="text" name="items[<?= $index ?>][description]" value="<?= h($item['description']) ?>" />
                </td>
                <td><input type="text" name="items[<?= $index ?>][unit]" value="<?= h($item['unit']) ?>" /></td>
                <td><input type="text" name="items[<?= $index ?>][ordered]" value="<?= h($item['ordered']) ?>" /></td>
                <td>
                  <input type="text" name="items[<?= $index ?>][qty]" value="<?= h($item['qty']) ?>" />
                  <input type="hidden" name="items[<?= $index ?>][received]" value="<?= h($item['received']) ?>" />
                  <input type="hidden" name="items[<?= $index ?>][po]" value="<?= h($item['po']) ?>" />
                </td>
                <td class="no-print receiving-lines__action-cell">
                  <button type="button" class="receiving-lines__delete" data-receiving-delete-row>Delete</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>

      <section class="receiving-footer">
        <label class="receiving-field">
          <span>Counted By</span>
          <input type="text" name="counted_by" value="<?= h($report['counted_by']) ?>" required />
        </label>

        <div class="receiving-push no-print" id="receivingPushPanel"
          data-scroll-into-view="<?= $push_attempted ? '1' : '0' ?>">
          <div class="receiving-push__copy"></div>

          <div class="receiving-push__controls">
            <!-- <div class="receiving-push__summary"><?= count(receiving_counted_items($items)) ?> counted line item<?= count(receiving_counted_items($items)) === 1 ? '' : 's' ?> ready</div> -->
            <button type="submit" class="btn btn--ghost receiving-push__button" name="action"
              value="push_counted_to_inventory">Import Data</button>
          </div>

          <?php if ($push_error !== ''): ?>
            <div class="receiving-push__status receiving-push__status--error"><?= h($push_error) ?></div>
          <?php elseif ($push_notice !== '' && $push_result_payload === null): ?>
            <div class="receiving-push__status receiving-push__status--success"><?= h($push_notice) ?></div>
          <?php endif; ?>
        </div>
      </section>
    </article>
  </form>
</section>

<template id="receivingRowTemplate">
  <tr>
    <td><input type="text" name="items[__INDEX__][sku]" value="" /></td>
    <td><input type="text" name="items[__INDEX__][description]" value="" /></td>
    <td><input type="text" name="items[__INDEX__][unit]" value="" /></td>
    <td><input type="text" name="items[__INDEX__][ordered]" value="" /></td>
    <td>
      <input type="text" name="items[__INDEX__][qty]" value="" />
      <input type="hidden" name="items[__INDEX__][received]" value="" />
      <input type="hidden" name="items[__INDEX__][po]" value="" />
    </td>
    <td class="no-print receiving-lines__action-cell">
      <button type="button" class="receiving-lines__delete" data-receiving-delete-row>Delete</button>
    </td>
  </tr>
</template>

<div id="receivingPushConfirmModal" class="receiving-action-modal no-print" hidden aria-hidden="true">
  <div class="receiving-action-modal__backdrop" data-receiving-push-confirm-close></div>
  <div class="receiving-action-modal__dialog card card--pad" role="dialog" aria-modal="true" aria-labelledby="receivingPushConfirmTitle">
    <div class="receiving-action-modal__copy">
      <h2 id="receivingPushConfirmTitle">Confirm Inventory Import</h2>
      <p>Confirm adding the receiving lines into inventory?</p>
      <div class="receiving-action-modal__meta">
        <span id="receivingPushConfirmPo">PO #</span>
      </div>
    </div>
    <div class="receiving-action-modal__actions">
      <button type="button" class="btn btn--ghost" id="receivingPushConfirmProceedBtn">Yes, import data</button>
      <button type="button" class="btn btn--ghost" data-receiving-push-confirm-close>Cancel</button>
    </div>
  </div>
</div>

<div
  id="receivingPushResultModal"
  class="receiving-action-modal receiving-action-modal--success no-print"
  <?= $push_result_payload !== null ? '' : 'hidden' ?>
  aria-hidden="<?= $push_result_payload !== null ? 'false' : 'true' ?>"
  data-auto-open="<?= $push_result_payload !== null ? '1' : '0' ?>">
  <div class="receiving-action-modal__backdrop" data-receiving-push-result-close></div>
  <div class="receiving-action-modal__dialog card card--pad" role="dialog" aria-modal="true" aria-labelledby="receivingPushResultTitle">
    <div class="receiving-action-modal__copy">
      <div class="receiving-action-modal__header">
        <h2 id="receivingPushResultTitle">Inventory Successfully Updated</h2>
        <button type="button" class="btn btn--ghost" data-receiving-push-result-close>Close</button>
      </div>
      <?php if ($push_result_payload !== null): ?>
        <p>
          Added <?= (int) ($push_result_payload['line_count'] ?? 0) ?> receiving line item(s)
          totaling <?= (int) ($push_result_payload['total_counted_qty'] ?? 0) ?> unit(s)
          into <?= h((string) ($push_result_payload['location'] ?? '')) ?>.
        </p>
        <div class="receiving-action-modal__meta receiving-action-modal__meta--success">
          <span>PO # <?= h((string) ($push_result_payload['purchase_order'] ?? '')) ?></span>
          <span>Report #<?= (int) ($push_result_payload['report_id'] ?? 0) ?></span>
        </div>
        <div class="receiving-action-modal__table-wrap">
          <table class="receiving-action-modal__table">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Description</th>
                <th>Previous</th>
                <th>Counted</th>
                <th>Change</th>
                <th>New</th>
                <th>Type</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($push_result_payload['changes'] ?? []) as $change): ?>
                <tr>
                  <td><?= h((string) ($change['sku'] ?? '')) ?></td>
                  <td><?= h((string) ($change['description'] ?? '')) ?></td>
                  <td><?= (int) ($change['previous_qty'] ?? 0) ?></td>
                  <td><?= (int) ($change['counted_qty'] ?? 0) ?></td>
                  <td><?= (int) ($change['delta_qty'] ?? 0) ?></td>
                  <td><?= (int) ($change['new_qty'] ?? 0) ?></td>
                  <td><?= h((string) ($change['movement_type'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div id="receivingHistoryModal" class="receiving-history-modal no-print" hidden aria-hidden="true">
  <div class="receiving-history-modal__backdrop" data-receiving-history-close></div>
  <div class="receiving-history-modal__dialog card card--pad" role="dialog" aria-modal="true"
    aria-labelledby="receivingHistoryModalTitle">
    <div class="receiving-history-modal__header">
      <div>
        <p class="receiving-eyebrow">Saved Reports</p>
        <h2 id="receivingHistoryModalTitle">Search Receiving Reports</h2>
      </div>
      <button type="button" class="btn btn--ghost" data-receiving-history-close>Close</button>
    </div>

    <form id="receivingHistorySearchForm" class="receiving-history-modal__search">
      <label class="receiving-history-modal__search-field" for="receivingHistorySearchInput">
        <span>Purchase Order / Vendor / User</span>
        <input id="receivingHistorySearchInput" type="search" placeholder="Search by PO #, vendor, or receiver" />
      </label>
      <button type="submit" class="btn btn--ghost">Search</button>
    </form>

    <div id="receivingHistoryStatus" class="receiving-history-modal__status">
      Search old receiving reports to review the saved header details and item lines.
    </div>

    <div class="receiving-history-modal__grid">
      <div class="receiving-history-modal__results" id="receivingHistoryResults"></div>
      <div class="receiving-history-modal__detail" id="receivingHistoryDetail">
        <div class="receiving-history-modal__empty">
          Select a saved report to view its full receiving details.
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

$footer_js = <<<'HTML'
<script>
(function () {
  const form = document.querySelector('.receiving-app');
  const addRowBtn = document.getElementById('receivingAddRowBtn');
  const printBtn = document.getElementById('receivingPrintBtn');
  const resetBtn = document.getElementById('receivingResetBtn');
  const importToggleBtn = document.getElementById('receivingImportToggleBtn');
  const importPanel = document.getElementById('receivingImportPanel');
  const importInput = document.getElementById('receiptImportFile');
  const pushPanel = document.getElementById('receivingPushPanel');
  const pushBtn = document.querySelector('button[name="action"][value="push_counted_to_inventory"]');
  const importSelectAllBtn = document.getElementById('receivingImportSelectAllBtn');
  const importClearBtn = document.getElementById('receivingImportClearBtn');
  const body = document.getElementById('receivingLinesBody');
  const template = document.getElementById('receivingRowTemplate');
  const historyLaunchBtn = document.getElementById('receivingHistoryLaunchBtn');
  const historyModal = document.getElementById('receivingHistoryModal');
  const historySearchForm = document.getElementById('receivingHistorySearchForm');
  const historySearchInput = document.getElementById('receivingHistorySearchInput');
  const historyStatus = document.getElementById('receivingHistoryStatus');
  const historyResults = document.getElementById('receivingHistoryResults');
  const historyDetail = document.getElementById('receivingHistoryDetail');
  const historyCloseEls = document.querySelectorAll('[data-receiving-history-close]');
  const pushConfirmModal = document.getElementById('receivingPushConfirmModal');
  const pushConfirmProceedBtn = document.getElementById('receivingPushConfirmProceedBtn');
  const pushConfirmCloseEls = document.querySelectorAll('[data-receiving-push-confirm-close]');
  const pushConfirmPo = document.getElementById('receivingPushConfirmPo');
  const pushResultModal = document.getElementById('receivingPushResultModal');
  const pushResultCloseEls = document.querySelectorAll('[data-receiving-push-result-close]');
  let historyLastFocused = null;
  const importCheckboxes = function () {
    return document.querySelectorAll('.receiving-import-preview__checkbox');
  };
  const mediaFields = [
    {
      radioName: 'packing_slip',
      wrap: document.getElementById('receivingPackingSlipMediaWrap'),
      input: document.getElementById('receivingPackingSlipPhoto'),
      button: document.querySelector('label[for="receivingPackingSlipPhoto"]'),
      name: document.getElementById('receivingPackingSlipPhotoName'),
      preview: document.getElementById('receivingPackingSlipPhotoPreview'),
      alt: 'Packing slip preview',
      previewUrls: [],
      selectedInputs: [],
      inputCounter: 0
    },
    {
      radioName: 'damaged_product',
      wrap: document.getElementById('receivingDamageMediaWrap'),
      input: document.getElementById('receivingDamagePhoto'),
      button: document.querySelector('label[for="receivingDamagePhoto"]'),
      name: document.getElementById('receivingDamagePhotoName'),
      preview: document.getElementById('receivingDamagePhotoPreview'),
      alt: 'Damaged product preview',
      previewUrls: [],
      selectedInputs: [],
      inputCounter: 0
    }
  ];

  function appendRow(shouldFocus) {
    if (!(body && template)) {
      return;
    }

    const nextIndex = Number(body.dataset.nextIndex || body.children.length || 0);
    const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex));
    body.insertAdjacentHTML('beforeend', html);
    body.dataset.nextIndex = String(nextIndex + 1);

    if (shouldFocus) {
      const lastInput = body.querySelector('tr:last-child input');
      if (lastInput) {
        lastInput.focus();
      }
    }
  }

  function ensureAtLeastOneRow() {
    if (body && !body.querySelector('tr')) {
      appendRow(false);
    }
  }

  function formatHistoryDateTime(value) {
    if (!value) {
      return '-';
    }

    const parsed = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) {
      return String(value);
    }

    return parsed.toLocaleString([], {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  function formatHistoryFileSize(bytes) {
    const value = Number(bytes || 0);
    if (value <= 0) {
      return '';
    }

    if (value >= 1024 * 1024) {
      return (value / (1024 * 1024)).toFixed(1) + ' MB';
    }

    if (value >= 1024) {
      return Math.round(value / 1024) + ' KB';
    }

    return value + ' B';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function buildLookupUrl(params) {
    const url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('receiving_report_lookup', '1');

    Object.keys(params).forEach(function (key) {
      const value = params[key];
      if (value !== undefined && value !== null && String(value) !== '') {
        url.searchParams.set(key, String(value));
      }
    });

    return url.toString();
  }

  function setHistoryStatus(message, isError) {
    if (!historyStatus) {
      return;
    }

    historyStatus.textContent = message;
    historyStatus.classList.toggle('is-error', Boolean(isError));
  }

  function syncModalBodyLock() {
    const anyOpen = [historyModal, pushConfirmModal, pushResultModal].some(function (modal) {
      return modal && !modal.hidden;
    });
    document.body.classList.toggle('receiving-modal-open', anyOpen);
  }

  function setModalState(modal, open) {
    if (!modal) {
      return;
    }

    modal.hidden = !open;
    modal.setAttribute('aria-hidden', open ? 'false' : 'true');
    syncModalBodyLock();
  }

  function openHistoryModal() {
    if (!historyModal) {
      return;
    }

    historyLastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    setModalState(historyModal, true);

    if (historySearchInput) {
      historySearchInput.focus();
      historySearchInput.select();
    }
  }

  function closeHistoryModal() {
    if (!historyModal) {
      return;
    }

    setModalState(historyModal, false);

    if (historyLastFocused) {
      historyLastFocused.focus();
    }
  }

  function openPushConfirmModal() {
    if (!pushConfirmModal) {
      return;
    }

    const poInput = form ? form.querySelector('input[name="purchase_order"]') : null;
    if (pushConfirmPo) {
      pushConfirmPo.textContent = 'PO # ' + ((poInput instanceof HTMLInputElement && poInput.value.trim() !== '') ? poInput.value.trim() : 'Not provided');
    }

    historyLastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    setModalState(pushConfirmModal, true);
    if (pushConfirmProceedBtn) {
      pushConfirmProceedBtn.focus();
    }
  }

  function closePushConfirmModal() {
    setModalState(pushConfirmModal, false);
    if (historyLastFocused) {
      historyLastFocused.focus();
    }
  }

  function openPushResultModal() {
    if (!pushResultModal) {
      return;
    }
    historyLastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    setModalState(pushResultModal, true);
  }

  function closePushResultModal() {
    setModalState(pushResultModal, false);
    if (historyLastFocused) {
      historyLastFocused.focus();
    }
  }

  function renderHistoryResults(results) {
    if (!historyResults) {
      return;
    }

    if (!results.length) {
      historyResults.innerHTML = '<div class="receiving-history-modal__empty">No saved receiving reports matched that search.</div>';
      return;
    }

    historyResults.innerHTML = results.map(function (row, index) {
      const po = row.purchase_order ? 'PO# ' + escapeHtml(row.purchase_order) : 'No PO';
      const vendor = row.vendor ? escapeHtml(row.vendor) : 'Vendor not saved';
      const arrivalDate = row.arrival_date ? escapeHtml(row.arrival_date) : 'No arrival date';
      const savedAt = escapeHtml(formatHistoryDateTime(row.created_at || ''));
      const status = row.import_status ? escapeHtml(row.import_status) : 'PENDING';
      const counted = Number(row.total_counted_qty || 0).toLocaleString();

      return '' +
        '<button type="button" class="receiving-history-modal__result' + (index === 0 ? ' is-active' : '') + '" data-report-id="' + Number(row.id || 0) + '">' +
          '<div class="receiving-history-modal__result-top">' +
            '<strong>' + po + '</strong>' +
            '<span class="receiving-history-modal__chip">' + escapeHtml(status) + '</span>' +
          '</div>' +
          '<div class="receiving-history-modal__result-meta">' +
            '<span>Arrival ' + arrivalDate + '</span>' +
            '<span>' + vendor + '</span>' +
          '</div>' +
          '<div class="receiving-history-modal__result-meta">' +
            '<span>' + savedAt + '</span>' +
            '<span>' + Number(row.item_count || 0) + ' line item(s)</span>' +
          '</div>' +
          '<div class="receiving-history-modal__result-meta">' +
            '<span>' + counted + ' counted</span>' +
          '</div>' +
        '</button>';
    }).join('');
  }

  function renderHistoryDetail(payload) {
    if (!historyDetail) {
      return;
    }

    if (!payload || !payload.report) {
      historyDetail.innerHTML = '<div class="receiving-history-modal__empty">Select a saved report to view its full receiving details.</div>';
      return;
    }

    const report = payload.report;
    const items = Array.isArray(payload.items) ? payload.items : [];
    const media = Array.isArray(payload.media) ? payload.media : [];

    const detailMeta = [
      ['PO #', report.purchase_order || '-'],
      ['Arrival Date', report.arrival_date || '-'],
      ['Vendor', report.vendor || '-'],
      ['Received By', report.received_by || '-'],
      ['Received Date', report.received_on || '-'],
      ['Counted By', report.counted_by || '-'],
      ['Saved At', formatHistoryDateTime(report.created_at || '')],
      ['Imported At', report.imported_to_inventory_at ? formatHistoryDateTime(report.imported_to_inventory_at) : '-'],
      ['Floor Location', report.floor_location || '-'],
      ['Import Status', report.import_status || '-'],
      ['Page', (report.page_number || 1) + ' of ' + (report.page_total || 1)],
      ['Delivered As', report.delivery_type || '-'],
      ['Delivered By', report.delivered_by || '-'],
      ['Trailer #', report.trailer_number || '-'],
      ['Trailer Damaged?', report.trailer_damaged || '-'],
      ['Packing Slip Included?', report.packing_slip || '-'],
      ['Bad Odor / Dirty?', report.odor_dirty || '-'],
      ['Damaged Product?', report.damaged_product || '-'],
      ['Inspected By', report.inspected_by || '-'],
      ['CTNS / PLTS Delivered', report.container_count || '-']
    ];

    const metaHtml = detailMeta.map(function (entry) {
      return '' +
        '<div class="receiving-history-modal__detail-meta-item">' +
          '<span>' + escapeHtml(entry[0]) + '</span>' +
          '<strong>' + escapeHtml(entry[1]) + '</strong>' +
        '</div>';
    }).join('');

    const notesHtml = report.notes
      ? '<div class="receiving-history-modal__notes"><span>Notes</span><p>' + escapeHtml(report.notes) + '</p></div>'
      : '';

    const mediaHtml = media.length
      ? '<section class="receiving-history-modal__media">' +
          '<div class="receiving-history-modal__media-header">' +
            '<span class="receiving-eyebrow">Media</span>' +
            '<strong>' + media.length + ' file(s)</strong>' +
          '</div>' +
          '<div class="receiving-history-modal__media-grid">' +
            media.map(function (file) {
              const imageHtml = file.is_image
                ? '<a class="receiving-history-modal__media-thumb" href="' + escapeHtml(file.url || '#') + '" target="_blank" rel="noopener noreferrer">' +
                    '<img src="' + escapeHtml(file.url || '') + '" alt="' + escapeHtml(file.label || file.original_name || 'Receiving media') + '" loading="lazy" />' +
                  '</a>'
                : '<a class="receiving-history-modal__media-file" href="' + escapeHtml(file.url || '#') + '" target="_blank" rel="noopener noreferrer">Open File</a>';

              return '' +
                '<article class="receiving-history-modal__media-card">' +
                  imageHtml +
                  '<div class="receiving-history-modal__media-copy">' +
                    '<strong>' + escapeHtml(file.label || 'Media') + '</strong>' +
                    '<span>' + escapeHtml(file.original_name || '') + '</span>' +
                    '<span>' + escapeHtml(formatHistoryDateTime(file.created_at || '')) + '</span>' +
                    '<span>' + escapeHtml(formatHistoryFileSize(file.size_bytes || 0)) + '</span>' +
                  '</div>' +
                '</article>';
            }).join('') +
          '</div>' +
        '</section>'
      : '';

    const rowsHtml = items.length
      ? items.map(function (item) {
          return '' +
            '<tr>' +
              '<td>' + escapeHtml(item.sku || '') + '</td>' +
              '<td>' + escapeHtml(item.description || '') + '</td>' +
              '<td>' + escapeHtml(item.ordered || '') + '</td>' +
              '<td>' + escapeHtml(item.received || '') + '</td>' +
              '<td>' + Number(item.counted || 0).toLocaleString() + '</td>' +
              '<td>' + escapeHtml(item.po || report.purchase_order || '') + '</td>' +
            '</tr>';
        }).join('')
      : '<tr><td colspan="6" class="receiving-history-modal__empty-cell">No saved line items.</td></tr>';

    historyDetail.innerHTML = '' +
      '<div class="receiving-history-modal__detail-header">' +
        '<div>' +
          '<p class="receiving-eyebrow">Report #' + Number(report.id || 0) + '</p>' +
          '<h3>' + escapeHtml(report.purchase_order ? 'Receiving PO# ' + report.purchase_order : 'Saved Receiving Report') + '</h3>' +
        '</div>' +
        '<div class="receiving-history-modal__detail-chips">' +
          '<span class="receiving-history-modal__chip">' + escapeHtml(report.import_status || 'PENDING') + '</span>' +
          '<span class="receiving-history-modal__chip">' + Number(items.length).toLocaleString() + ' line(s)</span>' +
        '</div>' +
      '</div>' +
      '<div class="receiving-history-modal__detail-meta">' + metaHtml + '</div>' +
      notesHtml +
      mediaHtml +
      '<div class="receiving-history-modal__table-wrap">' +
        '<table class="receiving-history-modal__table">' +
          '<thead><tr><th>SKU</th><th>Description</th><th>Ordered</th><th>Received</th><th>Counted</th><th>PO #</th></tr></thead>' +
          '<tbody>' + rowsHtml + '</tbody>' +
        '</table>' +
      '</div>';
  }

  async function loadHistoryDetail(reportId) {
    if (!reportId) {
      renderHistoryDetail(null);
      return;
    }

    setHistoryStatus('Loading report details...', false);

    try {
      const response = await fetch(buildLookupUrl({ report_id: reportId }), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await response.json();

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Unable to load the selected receiving report.');
      }

      renderHistoryDetail(payload.data || null);
      setHistoryStatus('Loaded saved receiving report details.', false);
    } catch (error) {
      renderHistoryDetail(null);
      setHistoryStatus(error instanceof Error ? error.message : 'Unable to load the selected receiving report.', true);
    }
  }

  async function searchHistoryReports(query, autoSelectFirst) {
    const term = String(query || '').trim();
    if (term === '') {
      setHistoryStatus('Enter a PO #, vendor, or user name to search saved receiving reports.', false);
      if (historyResults) {
        historyResults.innerHTML = '<div class="receiving-history-modal__empty">Enter a search to load saved receiving reports.</div>';
      }
      renderHistoryDetail(null);
      return;
    }

    setHistoryStatus('Searching saved receiving reports…', false);

    try {
      const response = await fetch(buildLookupUrl({ q: term }), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await response.json();

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Search failed.');
      }

      const results = Array.isArray(payload.results) ? payload.results : [];
      renderHistoryResults(results);

      if (!results.length) {
        setHistoryStatus('No saved receiving reports matched that search.', false);
        renderHistoryDetail(null);
        return;
      }

      setHistoryStatus('Found ' + results.length + ' saved receiving report(s). Select one to review the details.', false);

      if (autoSelectFirst) {
        loadHistoryDetail(results[0].id);
      }
    } catch (error) {
      if (historyResults) {
        historyResults.innerHTML = '<div class="receiving-history-modal__empty">Unable to search saved receiving reports right now.</div>';
      }
      renderHistoryDetail(null);
      setHistoryStatus(error instanceof Error ? error.message : 'Search failed.', true);
    }
  }

  if (addRowBtn) {
    addRowBtn.addEventListener('click', function () {
      appendRow(true);
    });
  }

  if (body) {
    body.addEventListener('click', function (event) {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }

      const deleteBtn = target.closest('[data-receiving-delete-row]');
      if (!deleteBtn) {
        return;
      }

      const row = deleteBtn.closest('tr');
      if (row) {
        row.remove();
        ensureAtLeastOneRow();
      }
    });
  }

  if (printBtn) {
    printBtn.addEventListener('click', function () {
      window.print();
    });
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      window.location.href = window.location.pathname + window.location.search;
    });
  }

  if (importToggleBtn && importPanel) {
    importToggleBtn.addEventListener('click', function () {
      const willOpen = importPanel.hidden;
      importPanel.hidden = !willOpen;
      importToggleBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

      if (willOpen && importInput) {
        importInput.focus();
      }
    });
  }

  if (importSelectAllBtn) {
    importSelectAllBtn.addEventListener('click', function () {
      importCheckboxes().forEach(function (checkbox) {
        checkbox.checked = true;
      });
    });
  }

  if (importClearBtn) {
    importClearBtn.addEventListener('click', function () {
      importCheckboxes().forEach(function (checkbox) {
        checkbox.checked = false;
      });
    });
  }

  function bindMediaField(field) {
    const radios = document.querySelectorAll('input[name="' + field.radioName + '"]');

    function syncMediaVisibility() {
      if (!field.wrap) return;
      const selected = document.querySelector('input[name="' + field.radioName + '"]:checked');
      field.wrap.hidden = !(selected && selected.value === 'yes');
    }

    if (radios.length) {
      radios.forEach(function (radio) {
        radio.addEventListener('change', syncMediaVisibility);
      });
      syncMediaVisibility();
    }

    if (!(field.input && field.name && field.preview)) {
      return;
    }

    function getSelectedFiles() {
      return field.selectedInputs.flatMap(function (input) {
        return input.files ? Array.from(input.files) : [];
      });
    }

    function revokePreviewUrls() {
      field.previewUrls.forEach(function (url) {
        URL.revokeObjectURL(url);
      });
      field.previewUrls = [];
    }

    function syncButtonTarget() {
      if (field.button && field.input && field.input.id) {
        field.button.htmlFor = field.input.id;
      }
    }

    function renderSelectedFiles() {
      const files = getSelectedFiles();
      revokePreviewUrls();

      if (!files.length) {
        field.name.textContent = 'No photos selected';
        field.preview.innerHTML = '';
        field.preview.hidden = true;
        return;
      }

      field.name.textContent = files.length === 1
        ? files[0].name
        : files.length + ' photos selected';
      field.previewUrls = files.map(function (file) {
        return URL.createObjectURL(file);
      });
      field.preview.innerHTML = field.previewUrls.map(function (url, index) {
        const label = files[index] && files[index].name ? files[index].name : field.alt;
        return '<img src="' + url + '" alt="' + label.replaceAll('"', '&quot;') + '" class="receiving-media__thumb" />';
      }).join('');
      field.preview.hidden = false;
    }

    function prepareNextInput() {
      const nextInput = field.input.cloneNode(false);
      field.inputCounter += 1;
      nextInput.id = field.input.id + '__' + String(field.inputCounter);
      nextInput.value = '';
      nextInput.addEventListener('change', handleInputChange);
      field.input.insertAdjacentElement('afterend', nextInput);
      field.input = nextInput;
      syncButtonTarget();
    }

    function handleInputChange(event) {
      const changedInput = event.currentTarget;
      if (!(changedInput instanceof HTMLInputElement)) {
        return;
      }

      const files = changedInput.files ? Array.from(changedInput.files) : [];
      if (!files.length) {
        return;
      }

      if (!field.selectedInputs.includes(changedInput)) {
        field.selectedInputs.push(changedInput);
      }

      if (changedInput === field.input) {
        prepareNextInput();
      }

      renderSelectedFiles();
    }

    field.input.addEventListener('change', handleInputChange);
    syncButtonTarget();
  }

  if (form && pushBtn) {
    form.addEventListener('submit', function (event) {
      const submitter = event.submitter;
      if (submitter !== pushBtn) {
        form.dataset.pushConfirmed = '0';
        return;
      }

      const skuInputs = Array.from(form.querySelectorAll('input[name*="[sku]"]'));
      const hasSkuLine = skuInputs.some(function (input) {
        return input instanceof HTMLInputElement && input.value.trim() !== '';
      });

      if (!hasSkuLine) {
        event.preventDefault();
        window.alert('Add at least one SKU line before pushing counted data.');
        const firstSkuInput = skuInputs[0];
        if (firstSkuInput instanceof HTMLInputElement) {
          firstSkuInput.focus();
        }
        return;
      }

      if (form.dataset.pushConfirmed !== '1') {
        event.preventDefault();
        openPushConfirmModal();
        return;
      }

      form.dataset.pushConfirmed = '0';
    });
  }

  if (historyLaunchBtn) {
    historyLaunchBtn.addEventListener('click', function () {
      openHistoryModal();

      const poInput = form ? form.querySelector('input[name="purchase_order"]') : null;
      const seed = poInput instanceof HTMLInputElement ? poInput.value.trim() : '';
      if (historySearchInput && seed && historySearchInput.value.trim() === '') {
        historySearchInput.value = seed;
      }

      if (historySearchInput && historySearchInput.value.trim() !== '') {
        searchHistoryReports(historySearchInput.value, true);
      }
    });
  }

  if (historySearchForm) {
    historySearchForm.addEventListener('submit', function (event) {
      event.preventDefault();
      searchHistoryReports(historySearchInput ? historySearchInput.value : '', true);
    });
  }

  if (historyResults) {
    historyResults.addEventListener('click', function (event) {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }

      const button = target.closest('[data-report-id]');
      if (!(button instanceof HTMLElement)) {
        return;
      }

      historyResults.querySelectorAll('.receiving-history-modal__result').forEach(function (item) {
        item.classList.remove('is-active');
      });
      button.classList.add('is-active');
      loadHistoryDetail(Number(button.dataset.reportId || '0'));
    });
  }

  if (historyCloseEls.length) {
    historyCloseEls.forEach(function (el) {
      el.addEventListener('click', closeHistoryModal);
    });
  }

  if (pushConfirmCloseEls.length) {
    pushConfirmCloseEls.forEach(function (el) {
      el.addEventListener('click', closePushConfirmModal);
    });
  }

  if (pushConfirmProceedBtn && form && pushBtn) {
    pushConfirmProceedBtn.addEventListener('click', function () {
      form.dataset.pushConfirmed = '1';
      closePushConfirmModal();
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(pushBtn);
      } else {
        pushBtn.click();
      }
    });
  }

  if (pushResultCloseEls.length) {
    pushResultCloseEls.forEach(function (el) {
      el.addEventListener('click', closePushResultModal);
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }

    if (pushConfirmModal && !pushConfirmModal.hidden) {
      closePushConfirmModal();
      return;
    }

    if (pushResultModal && !pushResultModal.hidden) {
      closePushResultModal();
      return;
    }

    if (historyModal && !historyModal.hidden) {
      closeHistoryModal();
    }
  });

  mediaFields.forEach(bindMediaField);
  ensureAtLeastOneRow();

  if (pushPanel && pushPanel.dataset.scrollIntoView === '1') {
    pushPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  if (pushResultModal && pushResultModal.dataset.autoOpen === '1') {
    openPushResultModal();
  }
})();
</script>
HTML;

include __DIR__ . '/templates/layout.php';
