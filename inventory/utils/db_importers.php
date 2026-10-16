<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/helpers.php';

$autoloadCandidates = [
  __DIR__ . '/../../vendor/autoload.php',
  __DIR__ . '/../vendor/autoload.php',
  dirname(__DIR__, 2) . '/vendor/autoload.php',
];
foreach ($autoloadCandidates as $autoloadPath) {
  if (is_file($autoloadPath)) {
    require_once $autoloadPath;
    break;
  }
}

/**
 * Load a spreadsheet file through PhpSpreadsheet when available.
 *
 * @throws RuntimeException when PhpSpreadsheet is unavailable or the file cannot be read.
 */
function inv_load_spreadsheet_file(string $filePath): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
  if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
    throw new RuntimeException('PhpSpreadsheet is not installed.');
  }

  return \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
}

/**
 * Convert a worksheet into a 2D array with trimmed string values.
 *
 * @return array<int, array<int, string>>
 */
function inv_sheet_to_array(
  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet,
  bool $trimValues = true,
): array {
  $rows = $worksheet->toArray(null, true, true, false);
  $out = [];

  foreach ($rows as $row) {
    $vals = array_values($row);
    if ($trimValues) {
      $vals = array_map(
        static fn($value): string => trim((string) $value),
        $vals,
      );
    } else {
      $vals = array_map(static fn($value): string => (string) $value, $vals);
    }
    $out[] = $vals;
  }

  return $out;
}


/**
 * Normalize a spreadsheet header or warehouse key for reliable matching.
 */
function inv_normalize_lookup_key(mixed $value): string
{
  $value = strtolower(trim((string) $value));
  return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

/**
 * Format an OUTWHSE pallet number before it is written to the database.
 *
 * Canonical examples:
 * - LAC / Lachaine: 213 => LA-213
 * - ED / Dickson: 213 => ED-213
 * - FF / Fast-Frate: 213 => FF-213
 *
 * Existing aliases such as LA213, LAC-213, or FF213 are normalized rather
 * than prefixed a second time.
 */
function inv_format_outwhse_pallet_number(
  mixed $sourcePallet,
  string $warehouseCode,
): ?string {
  $pallet = trim((string) $sourcePallet);
  if (
    $pallet === '' ||
    in_array(strtoupper($pallet), ['N/A', 'NA', 'NULL', '-'], true)
  ) {
    return null;
  }

  $pallet = strtoupper(preg_replace('/\s+/', '', $pallet) ?? $pallet);
  $warehouseCode = strtoupper(trim($warehouseCode));

  $prefixByWarehouseCode = [
    'LA' => 'LA',
    'ED' => 'ED',
    'TPB' => 'TPB',
    'Y' => 'Y',
    'FF' => 'FF',
  ];

  $prefix = $prefixByWarehouseCode[$warehouseCode] ?? null;
  if ($prefix === null || $prefix === '') {
    return $pallet;
  }

  $recognizedPrefixes = $warehouseCode === 'LAC'
    ? ['LAC', 'LA']
    : [$prefix];

  $palletBody = $pallet;
  foreach ($recognizedPrefixes as $recognizedPrefix) {
    $quotedPrefix = preg_quote($recognizedPrefix, '/');

    if (preg_match('/^' . $quotedPrefix . '-(.+)$/', $pallet, $matches)) {
      $palletBody = $matches[1];
      break;
    }

    if (preg_match('/^' . $quotedPrefix . '(\d.*)$/', $pallet, $matches)) {
      $palletBody = $matches[1];
      break;
    }
  }

  $palletBody = ltrim($palletBody, '-');
  if ($palletBody === '') {
    return null;
  }

  return $prefix . '-' . $palletBody;
}

/**
 * Detect supported header rows that may appear below report titles/metadata.
 *
 * @param array<int, array<int, string>>|null $headerSignatures
 */
function inv_detect_header_row(
  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet,
  ?array $headerSignatures = null,
  int $maxScanRows = 25,
): int {
  $headerSignatures ??= [
    ['Warehouse', 'SKU', 'QTY (CTN / UNIT)', 'STATUS'],
    ['Document Type', 'No.', 'Ship-to Address'],
  ];

  $normalizedSignatures = array_map(
    static fn(array $signature): array => array_map(
      static fn($value): string => inv_normalize_lookup_key($value),
      $signature,
    ),
    $headerSignatures,
  );

  $highestRow = max(1, (int) $worksheet->getHighestDataRow());
  $highestColumn = $worksheet->getHighestDataColumn();
  $scanThrough = min($highestRow, max(1, $maxScanRows));

  for ($rowNumber = 1; $rowNumber <= $scanThrough; $rowNumber++) {
    $rowData = $worksheet->rangeToArray(
      "A{$rowNumber}:{$highestColumn}{$rowNumber}",
      '',
      false,
      true,
      false,
    );

    $normalizedValues = array_values(array_filter(
      array_map(
        static fn($value): string => inv_normalize_lookup_key($value),
        array_values($rowData[0] ?? []),
      ),
      static fn(string $value): bool => $value !== '',
    ));

    foreach ($normalizedSignatures as $signature) {
      $matches = true;
      foreach ($signature as $requiredHeader) {
        if (!in_array($requiredHeader, $normalizedValues, true)) {
          $matches = false;
          break;
        }
      }

      if ($matches) {
        return $rowNumber;
      }
    }
  }

  return 1;
}

/**
 * Read the data rows used by the simple inventory importers.
 * The first row is the header row; column mapping is applied by each caller.
 *
 * @return array<int, array<int, mixed>>
 */
function inv_read_import_rows(string $filePath, string $extension): array
{
  if ($extension === 'csv') {
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
      throw new RuntimeException('Unable to open CSV file for reading.');
    }

    fgetcsv($handle, 0, ',', '"', '\\');
    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
      if ($row === [null]) {
        continue;
      }
      $rows[] = array_values($row);
    }
    fclose($handle);
    return $rows;
  }

  if ($extension === 'xlsx') {
    $spreadsheet = inv_load_spreadsheet_file($filePath);
    $rows = inv_sheet_to_array($spreadsheet->getActiveSheet(), true);
    array_shift($rows);
    return array_map(static fn(array $row): array => array_values($row), $rows);
  }

  throw new RuntimeException('Unsupported file type for inventory import.');
}

/**
 * Import SKU details into the inventory SKU master.
 */
function import_sku_details_from_file(
  mysqli $conn,
  string $filePath,
  int $userId,
  array $fileColumns,
  string $extension,
  array $userColumnMap,
  array &$rowLog = [],
): int {
  $rowLog = ['imported' => [], 'skipped' => [], 'truncated' => []];
  $rows = inv_read_import_rows($filePath, $extension);
  $stmt = $conn->prepare(
    'INSERT INTO sku (sku_num, `desc`, status) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE `desc` = VALUES(`desc`), status = VALUES(status)',
  );
  if (!$stmt) {
    throw new RuntimeException('SKU import preparation failed: ' . $conn->error);
  }

  $count = 0;
  foreach ($rows as $index => $row) {
    $row = array_pad(array_slice($row, 0, count($fileColumns)), count($fileColumns), '');
    $assoc = array_combine($fileColumns, $row);
    if ($assoc === false) {
      $rowLog['skipped'][] = ['row' => $index + 2, 'reason' => 'Column count mismatch'];
      continue;
    }

    $value = static function (array $assoc, array $map, string $field): string {
      $column = (string) ($map[$field] ?? '');
      return trim((string) ($column !== '' ? ($assoc[$column] ?? '') : ''));
    };
    $sku = $value($assoc, $userColumnMap, 'sku_num');
    $description = $value($assoc, $userColumnMap, 'desc');
    $status = $value($assoc, $userColumnMap, 'status') ?: 'ACTIVE';

    if ($sku === '' || $description === '') {
      if (count($rowLog['skipped']) < 200) {
        $rowLog['skipped'][] = ['row' => $index + 2, 'sku' => $sku, 'reason' => 'Missing SKU number or description'];
      }
      continue;
    }

    $stmt->bind_param('sss', $sku, $description, $status);
    if (!$stmt->execute()) {
      if (count($rowLog['skipped']) < 200) {
        $rowLog['skipped'][] = ['row' => $index + 2, 'sku' => $sku, 'reason' => $stmt->error];
      }
      continue;
    }

    $count++;
    if (count($rowLog['imported']) < 200) {
      $rowLog['imported'][] = ['row' => $index + 2, 'sku' => $sku, 'desc' => $description, 'status' => $status];
    }
  }
  $stmt->close();
  return $count;
}

/**
 * Import shelf/aisle assignments into the SKU master.
 */
function import_sku_shelf_from_file(
  mysqli $conn,
  string $filePath,
  int $userId,
  array $fileColumns,
  string $extension,
  array $userColumnMap,
  array &$rowLog = [],
): int {
  $rowLog = ['imported' => [], 'skipped' => [], 'truncated' => []];
  $rows = inv_read_import_rows($filePath, $extension);
  $stmt = $conn->prepare('UPDATE sku SET shelf = ? WHERE sku_num = ? LIMIT 1');
  if (!$stmt) {
    throw new RuntimeException('SKU shelf import preparation failed: ' . $conn->error);
  }

  $count = 0;
  foreach ($rows as $index => $row) {
    $row = array_pad(array_slice($row, 0, count($fileColumns)), count($fileColumns), '');
    $assoc = array_combine($fileColumns, $row);
    $skuColumn = (string) ($userColumnMap['sku_num'] ?? '');
    $shelfColumn = (string) ($userColumnMap['shelf'] ?? '');
    $sku = trim((string) ($assoc[$skuColumn] ?? ''));
    $shelf = trim((string) ($assoc[$shelfColumn] ?? ''));
    if ($sku === '' || $shelf === '') {
      if (count($rowLog['skipped']) < 200) {
        $rowLog['skipped'][] = ['row' => $index + 2, 'sku' => $sku, 'reason' => 'Missing SKU number or shelf'];
      }
      continue;
    }

    $stmt->bind_param('ss', $shelf, $sku);
    if (!$stmt->execute()) {
      if (count($rowLog['skipped']) < 200) {
        $rowLog['skipped'][] = ['row' => $index + 2, 'sku' => $sku, 'reason' => 'SKU not found or update failed'];
      }
      continue;
    }
    $count++;
    if (count($rowLog['imported']) < 200) {
      $rowLog['imported'][] = ['row' => $index + 2, 'sku' => $sku, 'shelf' => $shelf];
    }
  }
  $stmt->close();
  return $count;
}

/**
 * Import Sales Header rows into sales_headers with duplicate protection on document_type + document_no.
 * Returns the number of successfully processed rows.
 */
function import_shipping_addresses_from_file(
  mysqli $conn,
  string $filePath,
  int $user_id,
  array $columns,
  string $extension,
  array $columnMap,
  array &$row_log = [],
): int {
  $row_log = ['imported' => [], 'skipped' => []];

  $read_rows = [];
  $headerRowNumber = 1;

  if ($extension === 'csv') {
    if (($handle = fopen($filePath, 'rb')) === false) {
      throw new RuntimeException('Unable to open CSV file for reading.');
    }

    fgetcsv($handle, length: 0, separator: ",", enclosure: '"', escape: "\\");
    while (($row = fgetcsv($handle, length: 0, separator: ",", enclosure: '"', escape: "\\")) !== false) {
      $read_rows[] = $row;
    }
    fclose($handle);
  } elseif ($extension === 'xlsx') {
    $spreadsheet = inv_load_spreadsheet_file($filePath);
    $worksheet = $spreadsheet->getActiveSheet();

    $highestRow = $worksheet->getHighestDataRow();
    $highestColumn = $worksheet->getHighestDataColumn();
    $headerRowNumber = function_exists('detect_sales_header_row')
      ? detect_sales_header_row($worksheet)
      : 3;

    if ($highestRow < $headerRowNumber) {
      throw new RuntimeException('The spreadsheet is empty or missing headers.');
    }

    for ($rowNumber = $headerRowNumber + 1; $rowNumber <= $highestRow; $rowNumber++) {
      $rowData = $worksheet->rangeToArray(
        "A{$rowNumber}:{$highestColumn}{$rowNumber}",
        '',
        false,
        true,
        false,
      );
      $read_rows[] = array_values($rowData[0] ?? []);
    }
  } else {
    throw new RuntimeException('import_shipping_addresses_from_file');
  }

  $stmt = $conn->prepare("
    INSERT INTO sales_headers (
      document_type,
      document_no,
      ship_to_address,
      ship_to_address_2,
      ship_to_city,
      ship_to_zip_code,
      ship_to_state,
      ship_to_country_region_code,
      import_filename,
      imported_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      ship_to_address = VALUES(ship_to_address),
      ship_to_address_2 = VALUES(ship_to_address_2),
      ship_to_city = VALUES(ship_to_city),
      ship_to_zip_code = VALUES(ship_to_zip_code),
      ship_to_state = VALUES(ship_to_state),
      ship_to_country_region_code = VALUES(ship_to_country_region_code),
      import_filename = VALUES(import_filename),
      imported_by = VALUES(imported_by),
      updated_at = CURRENT_TIMESTAMP
  ");
  if (!$stmt) {
    throw new RuntimeException('Prepare failed: ' . $conn->error);
  }

  $clean = static function ($value): ?string {
    $value = trim((string) $value);
    return $value === '' ? null : $value;
  };

  $getRowValue = static function (array $rowAssoc, array $columnMap, string $dbCol): string {
    $fileCol = $columnMap[$dbCol] ?? '';
    return $fileCol !== '' ? (string) ($rowAssoc[$fileCol] ?? '') : '';
  };

  $processed = 0;
  $filename = basename($filePath);
  $max_samples = 30;

  foreach ($read_rows as $index => $row) {
    $row = array_slice(array_values($row), 0, count($columns));
    $row = array_pad($row, count($columns), '');
    $rowAssoc = array_combine($columns, $row);
    if ($rowAssoc === false) {
      if (count($row_log['skipped']) < $max_samples) {
        $row_log['skipped'][] = [
          'row' => $index + $headerRowNumber + 1,
          'reason' => 'Column count mismatch',
        ];
      }
      continue;
    }

    $document_type = $clean($getRowValue($rowAssoc, $columnMap, 'document_type'));
    $document_no = $clean($getRowValue($rowAssoc, $columnMap, 'document_no'));

    if ($document_type === null || $document_no === null) {
      if (count($row_log['skipped']) < $max_samples) {
        $row_log['skipped'][] = [
          'row' => $index + $headerRowNumber + 1,
          'document_type' => $document_type,
          'document_no' => $document_no,
          'reason' => 'Missing Document Type or No.',
        ];
      }
      continue;
    }

    $ship_to_address = $clean($getRowValue($rowAssoc, $columnMap, 'ship_to_address'));
    $ship_to_address_2 = $clean($getRowValue($rowAssoc, $columnMap, 'ship_to_address_2'));
    $ship_to_city = $clean($getRowValue($rowAssoc, $columnMap, 'ship_to_city'));
    $ship_to_zip_code = $clean($getRowValue($rowAssoc, $columnMap, 'ship_to_zip_code'));
    $ship_to_state = $clean($getRowValue($rowAssoc, $columnMap, 'ship_to_state'));
    $ship_to_country_region_code = $clean($getRowValue($rowAssoc, $columnMap, 'ship_to_country_region_code'));

    $stmt->bind_param(
      'sssssssssi',
      $document_type,
      $document_no,
      $ship_to_address,
      $ship_to_address_2,
      $ship_to_city,
      $ship_to_zip_code,
      $ship_to_state,
      $ship_to_country_region_code,
      $filename,
      $user_id,
    );

    if (!$stmt->execute()) {
      if (count($row_log['skipped']) < $max_samples) {
        $row_log['skipped'][] = [
          'document_type' => $document_type,
          'document_no' => $document_no,
          'reason' => $stmt->error,
        ];
      }
      continue;
    }

    $processed++;

    if (count($row_log['imported']) < $max_samples) {
      $row_log['imported'][] = [
        'document_type' => $document_type,
        'document_no' => $document_no,
        'ship_to_city' => $ship_to_city,
        'ship_to_zip_code' => $ship_to_zip_code,
        'ship_to_country_region_code' => $ship_to_country_region_code,
      ];
    }
  }

  $stmt->close();

  return $processed;
}
/**
 * Import OUTWHSE inventory rows.
 *
 * Rules:
 * - only source rows whose STATUS normalizes to exactly "in storage"
 * - quantity comes only from QTY (CTN / UNIT)
 * - unit_type is always UNIT
 * - warehouses resolve through outwhse_warehouse_locations
 * - Dickson resolves to code ED
 * - pallet numbers receive canonical warehouse aliases with a hyphen:
 *   LAC => LA-, ED => ED-, TPB => TPB-, Y => Y-, FF => FF-
 * - any non-empty dangerous-goods value sets is_dangerous_good to 1
 * - UN1057 maps to class 2.1; UN3481 maps to class 9
 */
function import_outwhse_inventory_from_file(
  mysqli $conn,
  string $filePath,
  int $user_id,
  array $columns,
  string $extension,
  array $columnMap,
  array &$row_log = [],
): int {
  if (!function_exists('ensure_outwhse_inventory_import_columns')) {
    function ensure_outwhse_inventory_import_columns(mysqli $conn): void
    {
      $table = 'outwhse_inventory';

      $hasInventoryNotes = false;
      $hasImportStatusRaw = false;

      $columnCheck = static function (mysqli $conn, string $table, string $column): bool {
        $tableEscaped = mysqli_real_escape_string($conn, $table);
        $columnEscaped = mysqli_real_escape_string($conn, $column);
        $result = mysqli_query(
          $conn,
          "SELECT 1
           FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$tableEscaped}'
             AND COLUMN_NAME = '{$columnEscaped}'
           LIMIT 1",
        );
        if (!$result) {
          return false;
        }

        $exists = mysqli_num_rows($result) > 0;
        mysqli_free_result($result);
        return $exists;
      };

      $hasInventoryNotes = $columnCheck($conn, $table, 'inventory_notes');
      $hasImportStatusRaw = $columnCheck($conn, $table, 'import_status_raw');

      if (!$hasInventoryNotes && !$hasImportStatusRaw) {
        mysqli_query($conn, 'ALTER TABLE `' . $table . '` '
          . 'ADD COLUMN `inventory_notes` TEXT NULL AFTER `status`, '
          . 'ADD COLUMN `import_status_raw` VARCHAR(150) NULL AFTER `inventory_notes`');
        return;
      }

      if (!$hasInventoryNotes) {
        mysqli_query($conn, 'ALTER TABLE `' . $table . '` '
          . 'ADD COLUMN `inventory_notes` TEXT NULL AFTER `status`');
      }

      if (!$hasImportStatusRaw) {
        mysqli_query($conn, 'ALTER TABLE `' . $table . '` '
          . 'ADD COLUMN `import_status_raw` VARCHAR(150) NULL AFTER `inventory_notes`');
      }
    }
  }

  ensure_outwhse_inventory_import_columns($conn);

  $row_log = [
    'imported' => [],
    'skipped' => [],
    'truncated' => ['imported' => false, 'skipped' => false],
  ];

  $maxSamples = 50;
  $readRows = [];
  $headerRowNumber = 1;

  if ($extension === 'csv') {
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
      throw new RuntimeException('Unable to open CSV file for reading.');
    }

    fgetcsv($handle, length: 0, separator: ",", enclosure: '"', escape: "\\"); // discard row-1 header
    $sourceRowNumber = 1;
    while (($row = fgetcsv($handle, length: 0, separator: ",", enclosure: '"', escape: "\\")) !== false) {
      $sourceRowNumber++;
      $readRows[] = [
        'row_number' => $sourceRowNumber,
        'values' => array_values($row),
      ];
    }
    fclose($handle);
  } elseif ($extension === 'xlsx') {
    $spreadsheet = inv_load_spreadsheet_file($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = (int) $worksheet->getHighestDataRow();
    $highestColumn = $worksheet->getHighestDataColumn();

    $headerRowNumber = inv_detect_header_row(
      $worksheet,
      [['Warehouse', 'SKU', 'QTY (CTN / UNIT)', 'STATUS']],
    );

    if ($highestRow <= $headerRowNumber) {
      throw new RuntimeException('The spreadsheet is empty or missing inventory rows.');
    }

    for ($rowNumber = $headerRowNumber + 1; $rowNumber <= $highestRow; $rowNumber++) {
      $rowData = $worksheet->rangeToArray(
        "A{$rowNumber}:{$highestColumn}{$rowNumber}",
        '',
        false,
        true,
        false,
      );

      $readRows[] = [
        'row_number' => $rowNumber,
        'values' => array_values($rowData[0] ?? []),
      ];
    }
  } else {
    throw new RuntimeException('Unsupported file type for OUTWHSE inventory import.');
  }

  $requiredMappings = ['warehouse', 'sku', 'qty_unit'];
  foreach ($requiredMappings as $requiredMapping) {
    if (trim((string) ($columnMap[$requiredMapping] ?? '')) === '') {
      throw new RuntimeException(
        "Missing required column mapping: {$requiredMapping}.",
      );
    }
  }

  // Load active warehouses once. Names and codes are both accepted.
  $warehouseResult = $conn->query(
    'SELECT id, name, code
     FROM outwhse_warehouse_locations
     WHERE is_active = 1',
  );
  if (!$warehouseResult) {
    throw new RuntimeException(
      'Unable to load OUTWHSE warehouse locations: ' . $conn->error,
    );
  }

  $warehousesByKey = [];
  $warehousesByCode = [];
  while ($warehouseRow = $warehouseResult->fetch_assoc()) {
    $warehouse = [
      'id' => (int) $warehouseRow['id'],
      'name' => trim((string) $warehouseRow['name']),
      'code' => strtoupper(trim((string) $warehouseRow['code'])),
    ];

    $nameKey = inv_normalize_lookup_key($warehouse['name']);
    $codeKey = inv_normalize_lookup_key($warehouse['code']);

    if ($nameKey !== '') {
      $warehousesByKey[$nameKey] = $warehouse;
    }
    if ($codeKey !== '') {
      $warehousesByKey[$codeKey] = $warehouse;
    }
    $warehousesByCode[$warehouse['code']] = $warehouse;
  }
  $warehouseResult->free();

  // Explicit source aliases. These use the actual database codes.
  $warehouseAliases = [
    'lachine' => 'LAC',
    'lachaine' => 'LAC',
    'dickson' => 'ED',
    'fastfrate' => 'FF',
  ];

  $resolveWarehouse = static function (
    mixed $sourceWarehouse
  ) use (
    $warehousesByKey,
    $warehousesByCode,
    $warehouseAliases,
  ): ?array {
    $key = inv_normalize_lookup_key($sourceWarehouse);
    if ($key === '') {
      return null;
    }

    if (isset($warehousesByKey[$key])) {
      return $warehousesByKey[$key];
    }

    $code = $warehouseAliases[$key] ?? null;
    return $code !== null ? ($warehousesByCode[$code] ?? null) : null;
  };

  $cleanNullable = static function (mixed $value): ?string {
    $value = trim((string) $value);
    if (
      $value === '' ||
      in_array(strtoupper($value), ['N/A', 'NA', 'NULL', '-'], true)
    ) {
      return null;
    }
    return $value;
  };

  $normalizeDangerousGoods = static function (
    mixed $value
  ) use ($cleanNullable): array {
    $sourceValue = $cleanNullable($value);
    if ($sourceValue === null) {
      return [
        'is_dangerous_good' => 0,
        'un_number' => null,
        'dg_class' => null,
      ];
    }

    $normalizedValue = strtoupper(trim($sourceValue));
    $compactValue = preg_replace('/[^A-Z0-9]/', '', $normalizedValue);
    $unNumber = null;

    // Accept forms such as UN1057, UN 1057, UN-1057, or 1057.
    if (preg_match('/^(?:UN)?(\d{4})$/', (string) $compactValue, $matches)) {
      $unNumber = 'UN' . $matches[1];
    } elseif (
      preg_match('/\bUN\s*[-#:]?\s*(\d{4})\b/i', $sourceValue, $matches)
    ) {
      $unNumber = 'UN' . $matches[1];
    } else {
      // Preserve an unrecognized populated DG value for review.
      $unNumber = substr($normalizedValue, 0, 30);
    }

    $classByUnNumber = [
      'UN1057' => '2.1',
      'UN3481' => '9',
    ];

    return [
      'is_dangerous_good' => 1,
      'un_number' => $unNumber,
      'dg_class' => $classByUnNumber[$unNumber] ?? null,
    ];
  };

  $normalizeDangerousGoodFlag = static function (mixed $value): ?int {
    $sourceValue = trim((string) $value);
    if ($sourceValue === '') {
      return null;
    }

    $normalized = strtolower($sourceValue);
    if (in_array($normalized, ['1', 'y', 'yes', 'true', 't', 'dg'], true)) {
      return 1;
    }
    if (in_array($normalized, ['0', 'n', 'no', 'false', 'f'], true)) {
      return 0;
    }

    return null;
  };

  $normalizeDgClass = static function (mixed $value) use ($cleanNullable): ?string {
    $sourceValue = $cleanNullable($value);
    if ($sourceValue === null) {
      return null;
    }

    return strtoupper(trim($sourceValue));
  };

  $normalizeUnNumber = static function (mixed $value) use ($cleanNullable): ?string {
    $sourceValue = $cleanNullable($value);
    if ($sourceValue === null) {
      return null;
    }

    $normalizedValue = strtoupper(trim($sourceValue));
    $compactValue = preg_replace('/[^A-Z0-9]/', '', $normalizedValue);

    if (preg_match('/^(?:UN)?(\d{4})$/', (string) $compactValue, $matches)) {
      return 'UN' . $matches[1];
    }

    if (preg_match('/\bUN\s*[-#:]?\s*(\d{4})\b/i', $sourceValue, $matches)) {
      return 'UN' . $matches[1];
    }

    return substr($normalizedValue, 0, 30);
  };

  $normalizePallet = static function (
    mixed $sourcePallet,
    string $warehouseCode
  ): ?string {
    return inv_format_outwhse_pallet_number(
      $sourcePallet,
      $warehouseCode,
    );
  };

  $getMappedValue = static function (
    array $rowAssoc,
    array $map,
    string $field
  ): mixed {
    $sourceColumn = trim((string) ($map[$field] ?? ''));
    return $sourceColumn === '' ? null : ($rowAssoc[$sourceColumn] ?? null);
  };

  $insertStmt = $conn->prepare(
    "INSERT INTO outwhse_inventory (
       warehouse_location_id,
       is_disabled,
       item_source,
       sku,
       description,
       inventory_notes,
       lot_number,
       total_qty,
       unit_type,
       pallet_number,
       line_number,
       is_dangerous_good,
       dg_class,
       un_number,
       import_status_raw,
       status,
       space_status
     ) VALUES (
       ?,
       0,
       'catalog',
       ?,
       ?,
       ?,
       ?,
       ?,
       'UNIT',
       ?,
       ?,
       ?,
       ?,
       ?,
       ?,
       'In Storage',
       'in_storage'
     )",
  );
  if (!$insertStmt) {
    throw new RuntimeException(
      'Unable to prepare OUTWHSE inventory insert: ' . $conn->error,
    );
  }

  $maxLineStmt = $conn->prepare(
    'SELECT COALESCE(MAX(line_number), 0)
     FROM outwhse_inventory
     WHERE warehouse_location_id = ?
       AND pallet_number = ?',
  );
  if (!$maxLineStmt) {
    $insertStmt->close();
    throw new RuntimeException(
      'Unable to prepare pallet line lookup: ' . $conn->error,
    );
  }

  $nextLineByPallet = [];
  $processed = 0;
  $skippedCount = 0;

  $addSkipped = static function (array $entry) use (
    &$row_log,
    &$skippedCount,
    $maxSamples,
  ): void {
    $skippedCount++;
    if (count($row_log['skipped']) < $maxSamples) {
      $row_log['skipped'][] = $entry;
    } else {
      $row_log['truncated']['skipped'] = true;
    }
  };

  foreach ($readRows as $sourceRow) {
    $rowNumber = (int) $sourceRow['row_number'];
    $row = array_slice(
      array_values($sourceRow['values']),
      0,
      count($columns),
    );
    $row = array_pad($row, count($columns), '');

    $rowAssoc = array_combine($columns, $row);
    if ($rowAssoc === false) {
      $addSkipped([
        'row' => $rowNumber,
        'reason' => 'Column count mismatch',
      ]);
      continue;
    }

    $sku = $cleanNullable(
      $getMappedValue($rowAssoc, $columnMap, 'sku'),
    );
    if ($sku === null) {
      $addSkipped([
        'row' => $rowNumber,
        'reason' => 'Missing SKU',
      ]);
      continue;
    }

    $quantity = $cleanNullable(
      $getMappedValue($rowAssoc, $columnMap, 'qty_unit'),
    );
    if ($quantity === null) {
      $addSkipped([
        'row' => $rowNumber,
        'sku' => $sku,
        'reason' => 'Missing QTY (CTN / UNIT)',
      ]);
      continue;
    }

    $sourceWarehouse = $getMappedValue(
      $rowAssoc,
      $columnMap,
      'warehouse',
    );
    $warehouse = $resolveWarehouse($sourceWarehouse);
    if ($warehouse === null) {
      $addSkipped([
        'row' => $rowNumber,
        'sku' => $sku,
        'warehouse' => $sourceWarehouse,
        'reason' => 'Unknown or inactive warehouse',
      ]);
      continue;
    }

    $description = $cleanNullable(
      $getMappedValue($rowAssoc, $columnMap, 'description'),
    );
    $inventoryNotes = $cleanNullable(
      $getMappedValue($rowAssoc, $columnMap, 'inventory_notes'),
    );
    $lotNumber = $cleanNullable(
      $getMappedValue($rowAssoc, $columnMap, 'lot_number'),
    );
    $importStatusRaw = $cleanNullable(
      $getMappedValue($rowAssoc, $columnMap, 'import_status_raw'),
    );
    if ($importStatusRaw === null) {
      $importStatusRaw = $cleanNullable(
        $getMappedValue($rowAssoc, $columnMap, 'status'),
      );
    }

    $legacyDangerousGoods = $getMappedValue(
      $rowAssoc,
      $columnMap,
      'dangerous_goods',
    );
    $explicitIsDangerousGood = $normalizeDangerousGoodFlag(
      $getMappedValue($rowAssoc, $columnMap, 'is_dangerous_good'),
    );
    $explicitDgClass = $normalizeDgClass(
      $getMappedValue($rowAssoc, $columnMap, 'dg_class'),
    );
    $explicitUnNumber = $normalizeUnNumber(
      $getMappedValue($rowAssoc, $columnMap, 'un_number'),
    );

    $dangerousGoods = $normalizeDangerousGoods($legacyDangerousGoods);
    $isDangerousGood = $explicitIsDangerousGood;
    if ($isDangerousGood === null) {
      $isDangerousGood = (int) $dangerousGoods['is_dangerous_good'];
    }

    $dgClass = $explicitDgClass ?? $dangerousGoods['dg_class'];
    $unNumber = $explicitUnNumber ?? $dangerousGoods['un_number'];

    if (
      $isDangerousGood === null &&
      (
        $legacyDangerousGoods !== null ||
        $explicitDgClass !== null ||
        $explicitUnNumber !== null
      )
    ) {
      $isDangerousGood = 1;
    }

    if ($isDangerousGood === null) {
      $isDangerousGood = 0;
    }

    $palletNumber = $normalizePallet(
      $getMappedValue($rowAssoc, $columnMap, 'pallet_number'),
      $warehouse['code'],
    );

    // Refuse to insert a raw pallet number for a warehouse with a canonical
    // alias. This guarantees formatting occurs during import, before INSERT.
    $requiredPrefixByWarehouseCode = [
      'LAC' => 'LA-',
      'ED' => 'ED-',
      'TPB' => 'TPB-',
      'Y' => 'Y-',
      'FF' => 'FF-',
    ];
    $requiredPrefix = $requiredPrefixByWarehouseCode[$warehouse['code']] ?? null;
    if (
      $palletNumber !== null &&
      $requiredPrefix !== null &&
      !str_starts_with($palletNumber, $requiredPrefix)
    ) {
      $addSkipped([
        'row' => $rowNumber,
        'sku' => $sku,
        'warehouse' => $warehouse['name'],
        'pallet_number' => $palletNumber,
        'reason' => 'Pallet number was not normalized before insert',
      ]);
      continue;
    }
    $lineNumber = 1;
    if ($palletNumber !== null) {
      $palletKey = $warehouse['id'] . '|' . $palletNumber;

      if (!array_key_exists($palletKey, $nextLineByPallet)) {
        $warehouseIdForLookup = (int) $warehouse['id'];
        $maxLineStmt->bind_param(
          'is',
          $warehouseIdForLookup,
          $palletNumber,
        );
        if (!$maxLineStmt->execute()) {
          $addSkipped([
            'row' => $rowNumber,
            'sku' => $sku,
            'pallet_number' => $palletNumber,
            'reason' => 'Unable to determine pallet line: ' . $maxLineStmt->error,
          ]);
          continue;
        }

        $maxLineStmt->bind_result($maxLine);
        $maxLineStmt->fetch();
        $maxLineStmt->free_result();

        $nextLineByPallet[$palletKey] = ((int) $maxLine) + 1;
      }

      $lineNumber = $nextLineByPallet[$palletKey];
      $nextLineByPallet[$palletKey]++;
    }

    $warehouseId = (int) $warehouse['id'];
    $insertStmt->bind_param(
      'issssssiisss',
      $warehouseId,
      $sku,
      $description,
      $inventoryNotes,
      $lotNumber,
      $quantity,
      $palletNumber,
      $lineNumber,
      $isDangerousGood,
      $dgClass,
      $unNumber,
      $importStatusRaw,
    );

    if (!$insertStmt->execute()) {
      $addSkipped([
        'row' => $rowNumber,
        'sku' => $sku,
        'warehouse' => $warehouse['name'],
        'pallet_number' => $palletNumber,
        'reason' => $insertStmt->error,
      ]);
      continue;
    }

    $processed++;
    if (count($row_log['imported']) < $maxSamples) {
      $row_log['imported'][] = [
        'row' => $rowNumber,
        'warehouse_location_id' => $warehouseId,
        'warehouse_code' => $warehouse['code'],
        'sku' => $sku,
        'total_qty' => $quantity,
        'unit_type' => 'UNIT',
        'pallet_number' => $palletNumber,
        'line_number' => $lineNumber,
        'is_dangerous_good' => $isDangerousGood,
        'dg_class' => $dgClass,
        'un_number' => $unNumber,
        'source_status' => $importStatusRaw ?? 'In Storage',
        'inventory_notes' => $inventoryNotes,
      ];
    } else {
      $row_log['truncated']['imported'] = true;
    }
  }

  $maxLineStmt->close();
  $insertStmt->close();

  $row_log['processed_count'] = $processed;
  $row_log['skipped_count'] = $skippedCount;

  return $processed;
}

/**
 * Import shelf tag master data.
 *
 * Identity rules:
 * - aisle, section, and shelf_level identify the physical location.
 * - tag_code is aisle-section-shelf_level; barcode joins the same values without separators.
 * - generated identifiers belonging to another location are rejected.
 */
function import_shelf_tags_from_file(
  mysqli $conn,
  string $filePath,
  int $userId,
  array $fileColumns,
  string $extension,
  array $userColumnMap,
  array &$rowLog = [],
): int {
  $rowLog = [
    'imported' => [],
    'skipped' => [],
    'truncated' => ['imported' => false, 'skipped' => false],
  ];

  $rows = inv_read_import_rows($filePath, $extension);
  $maxSamples = 200;

  $findStmt = $conn->prepare(
    'SELECT id, tag_code, barcode, aisle, section, shelf_level,
            (aisle = ? AND section = ? AND shelf_level = ?) AS location_match
     FROM shelf_tags
     WHERE (aisle = ? AND section = ? AND shelf_level = ?)
        OR tag_code = ? OR barcode = ?
     ORDER BY id ASC
     LIMIT 2',
  );
  if (!$findStmt) {
    throw new RuntimeException(
      'Shelf tag lookup preparation failed: ' . $conn->error,
    );
  }

  $insertStmt = $conn->prepare(
    'INSERT INTO shelf_tags (tag_code, barcode, aisle, section, shelf_level)
     VALUES (?, ?, ?, ?, ?)',
  );
  if (!$insertStmt) {
    $findStmt->close();
    throw new RuntimeException(
      'Shelf tag insert preparation failed: ' . $conn->error,
    );
  }

  $updateStmt = $conn->prepare(
    'UPDATE shelf_tags
     SET tag_code = ?, barcode = ?, aisle = ?, section = ?, shelf_level = ?
     WHERE id = ?
     LIMIT 1',
  );
  if (!$updateStmt) {
    $findStmt->close();
    $insertStmt->close();
    throw new RuntimeException(
      'Shelf tag update preparation failed: ' . $conn->error,
    );
  }

  $getValue = static function (
    array $rowAssoc,
    array $map,
    string $field,
  ): string {
    $sourceColumn = trim((string) ($map[$field] ?? ''));
    return $sourceColumn === ''
      ? ''
      : trim((string) ($rowAssoc[$sourceColumn] ?? ''));
  };

  $processed = 0;
  $skippedCount = 0;

  $addSkipped = static function (array $entry) use (
    &$rowLog,
    &$skippedCount,
    $maxSamples,
  ): void {
    $skippedCount++;
    if (count($rowLog['skipped']) < $maxSamples) {
      $rowLog['skipped'][] = $entry;
    } else {
      $rowLog['truncated']['skipped'] = true;
    }
  };

  $addImported = static function (array $entry) use (
    &$rowLog,
    $maxSamples,
  ): void {
    if (count($rowLog['imported']) < $maxSamples) {
      $rowLog['imported'][] = $entry;
    } else {
      $rowLog['truncated']['imported'] = true;
    }
  };

  foreach ($rows as $index => $row) {
    $sourceRowNumber = $index + 2;
    $row = array_pad(
      array_slice(array_values($row), 0, count($fileColumns)),
      count($fileColumns),
      '',
    );

    $rowAssoc = array_combine($fileColumns, $row);
    if ($rowAssoc === false) {
      $addSkipped([
        'row' => $sourceRowNumber,
        'reason' => 'Column count mismatch',
      ]);
      continue;
    }

    $aisle = $getValue($rowAssoc, $userColumnMap, 'aisle');
    $section = $getValue($rowAssoc, $userColumnMap, 'section');
    $shelfLevel = $getValue($rowAssoc, $userColumnMap, 'shelf_level');
    $tagCode = $section . '-' . $aisle . '-' . $shelfLevel;
    $barcode = $section . $aisle . $shelfLevel;

    if (
      $aisle === '' ||
      $section === '' ||
      $shelfLevel === ''
    ) {
      $addSkipped([
        'row' => $sourceRowNumber,
        'tag_code' => $tagCode,
        'barcode' => $barcode,
        'reason' => 'Missing required shelf tag field',
      ]);
      continue;
    }

    if (
      strlen($tagCode) > 20 ||
      strlen($barcode) > 20 ||
      strlen($aisle) > 10 ||
      strlen($section) > 10 ||
      strlen($shelfLevel) > 10
    ) {
      $addSkipped([
        'row' => $sourceRowNumber,
        'tag_code' => $tagCode,
        'barcode' => $barcode,
        'reason' => 'Value exceeds shelf_tags column length',
      ]);
      continue;
    }

    $findStmt->bind_param(
      'ssssssss',
      $aisle, $section, $shelfLevel,
      $aisle, $section, $shelfLevel,
      $tagCode, $barcode,
    );
    if (!$findStmt->execute()) {
      $addSkipped([
        'row' => $sourceRowNumber,
        'tag_code' => $tagCode,
        'barcode' => $barcode,
        'reason' => 'Lookup failed: ' . $findStmt->error,
      ]);
      continue;
    }

    $result = $findStmt->get_result();
    $matches = [];
    if ($result instanceof mysqli_result) {
      while ($existing = $result->fetch_assoc()) {
        $matches[] = $existing;
      }
      $result->free();
    }

    if ($matches === []) {
      $insertStmt->bind_param(
        'sssss',
        $tagCode,
        $barcode,
        $aisle,
        $section,
        $shelfLevel,
      );

      if (!$insertStmt->execute()) {
        $addSkipped([
          'row' => $sourceRowNumber,
          'tag_code' => $tagCode,
          'barcode' => $barcode,
          'reason' => 'Insert failed: ' . $insertStmt->error,
        ]);
        continue;
      }

      $processed++;
      $addImported([
        'row' => $sourceRowNumber,
        'action' => 'inserted',
        'tag_code' => $tagCode,
        'barcode' => $barcode,
        'aisle' => $aisle,
        'section' => $section,
        'shelf_level' => $shelfLevel,
      ]);
      continue;
    }

    $exactMatch = null;
    foreach ($matches as $existing) {
      if (
        (int) $existing['location_match'] === 1
      ) {
        $exactMatch = $existing;
        break;
      }
    }

    if ($exactMatch === null) {
      $conflicts = array_map(
        static fn(array $existing): array => [
          'id' => (int) $existing['id'],
          'tag_code' => (string) $existing['tag_code'],
          'barcode' => (string) $existing['barcode'],
        ],
        $matches,
      );

      $addSkipped([
        'row' => $sourceRowNumber,
        'tag_code' => $tagCode,
        'barcode' => $barcode,
        'reason' => 'Identity conflict: tag_code or barcode is already assigned to a different shelf tag',
        'existing' => $conflicts,
      ]);
      continue;
    }

    // Reject ambiguous locations or identifiers owned by another row.
    if (count($matches) > 1) {
      $addSkipped([
        'row' => $sourceRowNumber,
        'tag_code' => $tagCode,
        'barcode' => $barcode,
        'reason' => 'Identity conflict: identifiers resolve to multiple existing rows',
      ]);
      continue;
    }

    $existingId = (int) $exactMatch['id'];
    $updateStmt->bind_param(
      'sssssi',
      $tagCode,
      $barcode,
      $aisle,
      $section,
      $shelfLevel,
      $existingId,
    );

    if (!$updateStmt->execute()) {
      $addSkipped([
        'row' => $sourceRowNumber,
        'tag_code' => $tagCode,
        'barcode' => $barcode,
        'reason' => 'Location update failed: ' . $updateStmt->error,
      ]);
      continue;
    }

    $processed++;
    $addImported([
      'row' => $sourceRowNumber,
      'action' => 'updated',
      'id' => $existingId,
      'tag_code' => $tagCode,
      'barcode' => $barcode,
      'aisle' => $aisle,
      'section' => $section,
      'shelf_level' => $shelfLevel,
    ]);
  }

  $findStmt->close();
  $insertStmt->close();
  $updateStmt->close();

  $rowLog['processed_count'] = $processed;
  $rowLog['skipped_count'] = $skippedCount;

  return $processed;
}

