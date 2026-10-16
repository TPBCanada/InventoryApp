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

function outwhse_bol_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function outwhse_bol_autoload_candidates(): array
{
    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $normalizedDocumentRoot = $documentRoot !== ''
        ? rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $documentRoot), DIRECTORY_SEPARATOR)
        : '';
    $homeRoot = $normalizedDocumentRoot !== '' ? dirname($normalizedDocumentRoot) : '';

    return array_values(array_unique(array_filter([
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/lib/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(dirname(__DIR__)) . '/vendor/autoload.php',
        $normalizedDocumentRoot !== '' ? $normalizedDocumentRoot . '/vendor/autoload.php' : '',
        $homeRoot !== '' ? $homeRoot . '/vendor/autoload.php' : '',
        $homeRoot !== '' ? $homeRoot . '/public_html/vendor/autoload.php' : '',
        $homeRoot !== '' ? $homeRoot . '/public_html/dev/vendor/autoload.php' : '',
    ])));
}

function outwhse_bol_autoload(): bool
{
    static $loaded = false;
    static $attempted = false;
    $GLOBALS['OUTWHSE_BOL_AUTOLOAD_PATH'] = $GLOBALS['OUTWHSE_BOL_AUTOLOAD_PATH'] ?? '';

    if ($attempted) {
        return $loaded;
    }

    $attempted = true;
    $autoloadCandidates = outwhse_bol_autoload_candidates();

    foreach ($autoloadCandidates as $autoloadPath) {
        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
            $GLOBALS['OUTWHSE_BOL_AUTOLOAD_PATH'] = $autoloadPath;
            if (class_exists(\setasign\Fpdi\Fpdi::class)) {
                $loaded = true;
                break;
            }
        }
    }

    return $loaded;
}

function outwhse_bol_template_candidates_from_env(string $rawPath): array
{
    $rawPath = trim($rawPath);
    if ($rawPath === '') {
        return [];
    }

    $candidates = [$rawPath];
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rawPath);
    if ($normalized !== $rawPath) {
        $candidates[] = $normalized;
    }

    $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);

    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== '') {
        $documentRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $documentRoot), DIRECTORY_SEPARATOR);
        $homeRoot = dirname($documentRoot);
        $trimmed = ltrim($normalized, DIRECTORY_SEPARATOR);

        $candidates[] = $documentRoot . DIRECTORY_SEPARATOR . $trimmed;
        $candidates[] = $homeRoot . DIRECTORY_SEPARATOR . $trimmed;

        if (stripos($trimmed, 'public_html' . DIRECTORY_SEPARATOR) === 0) {
            $relativeToPublic = substr($trimmed, strlen('public_html' . DIRECTORY_SEPARATOR));
            $candidates[] = $documentRoot . DIRECTORY_SEPARATOR . $relativeToPublic;
            $candidates[] = $homeRoot . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . $relativeToPublic;
        }
    }

    return array_values(array_unique(array_filter($candidates)));
}

function outwhse_bol_template_candidates(): array
{
    $envTemplatePath = getenv('BOL_TEMPLATE_PATH');
    if ($envTemplatePath === false || trim((string) $envTemplatePath) === '') {
        $envTemplatePath = (string) ($_SERVER['BOL_TEMPLATE_PATH'] ?? '');
    }
    if (trim((string) $envTemplatePath) === '') {
        $envTemplatePath = (string) ($_ENV['BOL_TEMPLATE_PATH'] ?? '');
    }

    return array_values(array_unique(array_filter([
        ...outwhse_bol_template_candidates_from_env((string) $envTemplatePath),
        defined('OUTWHSE_BOL_TEMPLATE_PATH') ? (string) OUTWHSE_BOL_TEMPLATE_PATH : '',
        __DIR__ . '/lib/BOL_TEMPLATE.pdf',
        dirname(__DIR__) . '/utils/BOL_TEMPLATE.pdf',
        __DIR__ . '/utils/BOL_TEMPLATE.pdf',
        __DIR__ . '/assets/BOL_TEMPLATE.pdf',
    ])));
}

function outwhse_bol_template_path(): ?string
{
    $candidates = outwhse_bol_template_candidates();
    foreach ($candidates as $path) {
        $path = trim($path);
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    return null;
}

function outwhse_bol_value(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

function outwhse_bol_numeric_value(string $value): float
{
    $normalized = preg_replace('/[^0-9.\-]/', '', trim($value));
    if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === '-.') {
        return 0.0;
    }

    return (float) $normalized;
}

function outwhse_bol_format_number(float $value): string
{
    if (abs($value - round($value)) < 0.00001) {
        return (string) (int) round($value);
    }

    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function outwhse_bol_join_address(array $parts): string
{
    $clean = array_values(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $parts), static function (string $value): bool {
        return $value !== '';
    }));

    return implode(', ', $clean);
}

function outwhse_bol_line_items_from_rows_json(string $rowsJson): array
{
    if ($rowsJson === '') {
        return [];
    }

    $decodedRows = json_decode($rowsJson, true);
    if (!is_array($decodedRows)) {
        return [];
    }

    $lineItems = [];
    foreach ($decodedRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $pallet = trim((string) ($row['pallet_number'] ?? $row['pallet'] ?? ''));
        $sku = trim((string) ($row['sku'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $lineDescription = trim(implode(' - ', array_filter([$sku, $description], static fn (string $value): bool => $value !== '')));

        if ($lineDescription === '') {
            continue;
        }

        $lineItems[] = [
            'pallet_count' => trim((string) ($row['pallet_count'] ?? '1')) !== '' ? trim((string) ($row['pallet_count'] ?? '1')) : '1',
            'line_description' => $lineDescription,
            'pallet_weight' => trim((string) ($row['pallet_weight'] ?? '')),
            'qty' => trim((string) ($row['qty'] ?? '')),
            'pallet' => $pallet,
            'dg_class' => trim((string) ($row['dg_class'] ?? '')),
            'un_number' => trim((string) ($row['un_number'] ?? '')),
        ];
    }

    return $lineItems;
}

function outwhse_bol_error_page(string $title, string $message, array $details = []): never
{
    http_response_code(500);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= outwhse_bol_h($title) ?></title>
        <style>
            body { font-family: Arial, sans-serif; background: #f3f6fb; color: #0f182a; margin: 0; padding: 32px; }
            .card { max-width: 860px; margin: 0 auto; background: #fff; border: 1px solid #d8e0ea; border-radius: 20px; padding: 28px; box-shadow: 0 18px 40px rgba(15,23,42,.08); }
            h1 { margin: 0 0 12px; font-size: 28px; }
            p { margin: 0 0 14px; line-height: 1.6; }
            code, pre { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
            code { padding: 2px 6px; }
            pre { padding: 14px; overflow: auto; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1><?= outwhse_bol_h($title) ?></h1>
            <p><?= outwhse_bol_h($message) ?></p>
            <?php if (!empty($details)): ?>
                <pre><?php foreach ($details as $detail): echo outwhse_bol_h($detail) . "\n"; endforeach; ?></pre>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$templatePath = outwhse_bol_template_path();
if ($templatePath === null) {
    $details = [
        'BOL_TEMPLATE_PATH via getenv(): ' . (string) (getenv('BOL_TEMPLATE_PATH') ?: ''),
        'BOL_TEMPLATE_PATH via $_SERVER: ' . (string) ($_SERVER['BOL_TEMPLATE_PATH'] ?? ''),
        'DOCUMENT_ROOT: ' . (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
        'Checked paths:',
        ...outwhse_bol_template_candidates(),
    ];
    outwhse_bol_error_page(
        'BOL Template Not Found',
        'The exporter could not find BOL_TEMPLATE.pdf. Set BOL_TEMPLATE_PATH in .htaccess or place the file in utils/BOL_TEMPLATE.pdf.',
        $details
    );
}

$debugTemplate = (string) ($_GET['debug_template'] ?? '') === '1';
if ($debugTemplate) {
    $candidateReport = [];
    foreach (outwhse_bol_template_candidates() as $candidate) {
        $candidateReport[] = [
            'path' => $candidate,
            'exists' => is_file($candidate),
            'readable' => is_readable($candidate),
        ];
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'selected_template_path' => $templatePath,
        'selected_template_realpath' => realpath($templatePath) ?: $templatePath,
        'candidates' => $candidateReport,
        'bol_template_path_env_getenv' => (string) (getenv('BOL_TEMPLATE_PATH') ?: ''),
        'bol_template_path_env_server' => (string) ($_SERVER['BOL_TEMPLATE_PATH'] ?? ''),
        'document_root' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!outwhse_bol_autoload() || !class_exists(\setasign\Fpdi\Fpdi::class)) {
    outwhse_bol_error_page(
        'PDF Library Missing',
        'FPDI/FPDF is required to export data into the PDF template.',
        [
            'Install when Composer is available:',
            'composer require setasign/fpdf setasign/fpdi',
            'Template path found at: ' . $templatePath,
            'DOCUMENT_ROOT: ' . (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
            'Loaded autoload path: ' . (string) ($GLOBALS['OUTWHSE_BOL_AUTOLOAD_PATH'] ?? ''),
            'inventory/vendor/autoload.php exists: ' . (is_file(__DIR__ . '/vendor/autoload.php') ? 'yes' : 'no'),
            'inventory/vendor/setasign exists: ' . (is_dir(__DIR__ . '/vendor/setasign') ? 'yes' : 'no'),
            'FPDI class exists after autoload: ' . (class_exists(\setasign\Fpdi\Fpdi::class) ? 'yes' : 'no'),
            'Checked autoload paths:',
            ...outwhse_bol_autoload_candidates(),
        ]
    );
}

$bol = [
    'bol_number' => outwhse_bol_value('bol_number'),
    'ship_date' => outwhse_bol_value('ship_date'),
    'shipper_name' => outwhse_bol_value('shipper_name', 'Turning Point Brands Canada'),
    'shipper_contact_name' => outwhse_bol_value('shipper_contact_name'),
    'shipper_phone' => outwhse_bol_value('shipper_phone'),
    'shipper_address_line_1' => outwhse_bol_value('shipper_address_line_1'),
    'shipper_address_line_2' => outwhse_bol_value('shipper_address_line_2'),
    'shipper_city' => outwhse_bol_value('shipper_city'),
    'shipper_province_state' => outwhse_bol_value('shipper_province_state'),
    'shipper_postal_code' => outwhse_bol_value('shipper_postal_code'),
    'shipper_country' => outwhse_bol_value('shipper_country'),
    'consignee_name' => outwhse_bol_value('consignee_name'),
    'consignee_contact_name' => outwhse_bol_value('consignee_contact_name'),
    'consignee_phone' => outwhse_bol_value('consignee_phone'),
    'consignee_address_line_1' => outwhse_bol_value('consignee_address_line_1'),
    'consignee_address_line_2' => outwhse_bol_value('consignee_address_line_2'),
    'consignee_city' => outwhse_bol_value('consignee_city'),
    'consignee_province_state' => outwhse_bol_value('consignee_province_state'),
    'consignee_postal_code' => outwhse_bol_value('consignee_postal_code'),
    'consignee_country' => outwhse_bol_value('consignee_country'),
    'carrier_name' => outwhse_bol_value('carrier_name'),
    'consignor_name' => outwhse_bol_value('consignor_name'),
    'from_location' => outwhse_bol_value('from_location'),
    'to_location' => outwhse_bol_value('to_location'),
    'warehouse' => outwhse_bol_value('warehouse'),
    'warehouse_location_name' => outwhse_bol_value('warehouse_location_name'),
    'item' => outwhse_bol_value('item'),
    'description' => outwhse_bol_value('description'),
    'qty' => outwhse_bol_value('qty'),
    'qty_total' => outwhse_bol_value('qty_total'),
    'unit' => outwhse_bol_value('unit'),
    'pallet' => outwhse_bol_value('pallet'),
    'line' => outwhse_bol_value('line'),
    'pallet_weight' => outwhse_bol_value('pallet_weight'),
    'pallet_count' => outwhse_bol_value('pallet_count', '1'),
    'special_instructions' => outwhse_bol_value('special_instructions'),
    'line_description' => outwhse_bol_value('line_description'),
    'dg_class' => outwhse_bol_value('dg_class'),
    'un_number' => outwhse_bol_value('un_number'),
    'consignor_date' => outwhse_bol_value('consignor_date'),
    'created_at' => outwhse_bol_value('created_at'),
];

if ($bol['consignor_date'] === '' && $bol['ship_date'] !== '') {
    $bol['consignor_date'] = $bol['ship_date'];
}

if ($bol['ship_date'] === '' && $bol['created_at'] !== '') {
    $bol['ship_date'] = preg_match('/^\d{4}-\d{2}-\d{2}/', $bol['created_at']) === 1
        ? substr($bol['created_at'], 0, 10)
        : '';
}

if ($bol['warehouse'] === '' && $bol['warehouse_location_name'] !== '') {
    $bol['warehouse'] = $bol['warehouse_location_name'];
}

if ($bol['warehouse'] === '' && $bol['from_location'] !== '') {
    $bol['warehouse'] = $bol['from_location'];
}

if ($bol['shipper_name'] === '' && $bol['from_location'] !== '') {
    $bol['shipper_name'] = $bol['from_location'];
}

if ($bol['consignee_name'] === '' && $bol['to_location'] !== '') {
    $bol['consignee_name'] = $bol['to_location'];
}

if ($bol['qty'] === '' && $bol['qty_total'] !== '') {
    $bol['qty'] = $bol['qty_total'];
}

if ($bol['carrier_name'] !== '') {
    $bol['special_instructions'] = trim($bol['special_instructions'] . ($bol['special_instructions'] !== '' ? ' | ' : '') . 'Carrier: ' . $bol['carrier_name']);
}

$bol['shipper_contact_name'] = $bol['shipper_contact_name'] !== '' ? $bol['shipper_contact_name'] : $bol['shipper_name'];
$bol['consignee_contact_name'] = $bol['consignee_contact_name'] !== '' ? $bol['consignee_contact_name'] : $bol['consignee_name'];
$bol['consignor_name'] = $bol['consignor_name'] !== '' ? $bol['consignor_name'] : $bol['consignee_contact_name'];
$bol['shipper_address'] = outwhse_bol_join_address([$bol['shipper_address_line_1'], $bol['shipper_address_line_2']]);
$bol['consignee_address'] = outwhse_bol_join_address([$bol['consignee_address_line_1'], $bol['consignee_address_line_2']]);

if ($bol['line_description'] === '') {
    $bol['line_description'] = trim(implode(' | ', array_filter([
        $bol['item'],
        $bol['description'],
    ])));
}

$lineItems = [];
$rowsJson = outwhse_bol_value('rows_json');
if ($rowsJson !== '') {
    $lineItems = outwhse_bol_line_items_from_rows_json($rowsJson);
}

$linesJson = outwhse_bol_value('lines_json');
if (empty($lineItems) && $linesJson !== '') {
    $decodedLines = json_decode($linesJson, true);
    if (is_array($decodedLines)) {
        foreach ($decodedLines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $lineDescription = trim((string) ($line['line_description'] ?? ''));
            if ($lineDescription === '') {
                continue;
            }

            $lineItems[] = [
                'pallet_count' => trim((string) ($line['pallet_count'] ?? '1')) !== '' ? trim((string) ($line['pallet_count'] ?? '1')) : '1',
                'line_description' => $lineDescription,
                'pallet_weight' => trim((string) ($line['pallet_weight'] ?? '')),
                'qty' => trim((string) ($line['qty'] ?? '')),
                'pallet' => trim((string) ($line['pallet'] ?? '')),
                'dg_class' => trim((string) ($line['dg_class'] ?? '')),
                'un_number' => trim((string) ($line['un_number'] ?? '')),
            ];
        }
    }
}

if ($bol['item'] === '' && !empty($lineItems)) {
    $bol['item'] = (string) ($lineItems[0]['line_description'] ?? '');
}

if ($bol['description'] === '' && !empty($lineItems)) {
    $bol['description'] = (string) ($lineItems[0]['line_description'] ?? '');
}

if ($bol['line_description'] === '' && !empty($lineItems)) {
    $bol['line_description'] = (string) ($lineItems[0]['line_description'] ?? '');
}

if (empty($lineItems)) {
    $lineItems[] = [
        'pallet_count' => trim($bol['pallet_count']) !== '' ? $bol['pallet_count'] : '1',
        'line_description' => $bol['line_description'],
        'pallet_weight' => $bol['pallet_weight'],
        'qty' => $bol['qty'],
        'pallet' => $bol['pallet'],
        'dg_class' => $bol['dg_class'],
        'un_number' => $bol['un_number'],
    ];
}

$weightByPallet = [];
$totalCases = 0.0;
foreach ($lineItems as $index => $lineItem) {
    $totalCases += outwhse_bol_numeric_value($lineItem['qty']);

    $palletKey = trim((string) ($lineItem['pallet'] ?? ''));
    if ($palletKey === '') {
        $palletKey = '__line_' . $index;
    }

    if (!array_key_exists($palletKey, $weightByPallet)) {
        $weightByPallet[$palletKey] = outwhse_bol_numeric_value((string) ($lineItem['pallet_weight'] ?? ''));
    }
}

$seenDetailPallets = [];
foreach ($lineItems as $index => &$lineItem) {
    $palletKey = trim((string) ($lineItem['pallet'] ?? ''));
    if ($palletKey === '') {
        $palletKey = '__line_' . $index;
    }

    if (isset($seenDetailPallets[$palletKey])) {
        $lineItem['pallet_count'] = '';
        continue;
    }

    $seenDetailPallets[$palletKey] = true;
    $lineItem['pallet_count'] = '1';
}
unset($lineItem);

$totalPallets = !empty($weightByPallet) ? count($weightByPallet) : max(1, (int) outwhse_bol_numeric_value($bol['pallet_count']));
$totalWeight = !empty($weightByPallet)
    ? array_sum($weightByPallet)
    : outwhse_bol_numeric_value($bol['pallet_weight']);
$palletWeightDisplay = $totalWeight > 0 ? outwhse_bol_format_number($totalWeight) . ' LBS' : '';
$totalWeightDisplay = $palletWeightDisplay;

// Coordinate map aligned to the actual printable boxes on the flat BOL template.
$map = [
    'ship_date' => ['x' => 16, 'y' => 28, 'w' => 22, 'h' => 4.2, 'size' => 8],
    'bol_number' => ['x' => 64, 'y' => 28.5, 'w' => 92, 'h' => 4.2, 'size' => 8, 'bold' => true],
    'shipper_contact_name' => ['x' => 24, 'y' => 61, 'w' => 34, 'h' => 3.9, 'size' => 8],
    'shipper_phone' => ['x' => 72, 'y' => 61, 'w' => 26, 'h' => 3.9, 'size' => 8],
    'shipper_name' => ['x' => 44, 'y' => 67.2, 'w' => 60, 'h' => 3.9, 'size' => 8],
    'shipper_address' => ['x' => 44, 'y' => 75.4, 'w' => 60, 'h' => 3.9, 'size' => 8],
    'shipper_city' => ['x' => 16, 'y' => 82.2, 'w' => 28, 'h' => 3.9, 'size' => 8],
    'shipper_province_state' => ['x' => 40.5, 'y' => 82.2, 'w' => 12, 'h' => 3.9, 'size' => 8],
    'shipper_postal_code' => ['x' => 60.5, 'y' => 82.2, 'w' => 16, 'h' => 3.9, 'size' => 8],
    'shipper_country' => ['x' => 90, 'y' => 82.2, 'w' => 22, 'h' => 3.9, 'size' => 8],
    'consignee_contact_name' => ['x' => 124, 'y' => 61, 'w' => 34, 'h' => 3.9, 'size' => 8],
    'consignee_phone' => ['x' => 172, 'y' => 61, 'w' => 26, 'h' => 3.9, 'size' => 8],
    'consignee_name' => ['x' => 142, 'y' => 67.2, 'w' => 60, 'h' => 3.9, 'size' => 8],
    'consignee_address' => ['x' => 142, 'y' => 75.4, 'w' => 60, 'h' => 3.9, 'size' => 8],
    'consignee_city' => ['x' => 124, 'y' => 82.2, 'w' => 28, 'h' => 3.9, 'size' => 8],
    'consignee_province_state' => ['x' => 140, 'y' => 82.2, 'w' => 12, 'h' => 3.9, 'size' => 8],
    'consignee_postal_code' => ['x' => 162.5, 'y' => 82.2, 'w' => 17, 'h' => 3.9, 'size' => 8],
    'consignee_country' => ['x' => 190.5, 'y' => 82.2, 'w' => 22, 'h' => 3.9, 'size' => 8],
    'consignor_name' => ['x' => 172, 'y' => 250.2, 'w' => 54, 'h' => 4.2, 'size' => 8],
    'special_instructions' => ['x' => 90, 'y' => 98.3, 'w' => 112, 'h' => 3.9, 'size' => 8],
    'total_pallets' => ['x' => 12, 'y' => 218.3, 'w' => 12, 'h' => 4.2, 'size' => 8, 'bold' => true],
    'total_weight' => ['x' => 113.5, 'y' => 218.3, 'w' => 16, 'h' => 4.2, 'size' => 8, 'bold' => true],
    'total_cases' => ['x' => 140.5, 'y' => 218.3, 'w' => 14, 'h' => 4.2, 'size' => 8, 'bold' => true],
    'consignor_date' => ['x' => 172.5, 'y' => 263.4, 'w' => 22, 'h' => 4.2, 'size' => 8],
];

$detailRowMap = [
    'pallet_count' => ['x' => 12.5, 'y' => 133.4, 'w' => 12, 'h' => 4.2, 'size' => 8, 'bold' => true],
    'line_description' => ['x' => 22.5, 'y' => 133.4, 'w' => 84, 'h' => 4.2, 'size' => 8],
    'pallet_weight' => ['x' => 120.5, 'y' => 133.4, 'w' => 18, 'h' => 4.2, 'size' => 8, 'bold' => true],
    'qty' => ['x' => 140.5, 'y' => 133.6, 'w' => 18, 'h' => 4.2, 'size' => 8, 'bold' => true],
    'pallet' => ['x' => 157.5, 'y' => 133.6, 'w' => 15, 'h' => 4.2, 'size' => 8, 'bold' => true],
    'dg_class' => ['x' => 174.5, 'y' => 133.6, 'w' => 11, 'h' => 4.2, 'size' => 8, 'bold' => true],
    'un_number' => ['x' => 190.8, 'y' => 133.6, 'w' => 13, 'h' => 4.2, 'size' => 8, 'bold' => true],
];

$detailRowStep = 5;
$detailRowLimit = 12;

$pdf = new \setasign\Fpdi\Fpdi();
$pageCount = $pdf->setSourceFile($templatePath);
if ($pageCount < 1) {
    outwhse_bol_error_page('Template Error', 'The BOL template could not be opened.');
}

$templateId = $pdf->importPage(1);
$size = $pdf->getTemplateSize($templateId);

$pdf->SetAutoPageBreak(false, 0);
$pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
$pdf->useTemplate($templateId);

// These zero values are embedded in the source template itself, so mask them after import.
$pdf->SetFillColor(0, 0, 0);
$pdf->Rect(11.8, 217.7, 10, 5.6, 'F');
$pdf->Rect(113.0, 217.7, 12.0, 5.6, 'F');
$pdf->Rect(142.0, 217.7, 10.0, 5.6, 'F');

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Helvetica', '', 8);

$write = static function (\setasign\Fpdi\Fpdi $pdf, array $cfg, string $text): void {
    $pdf->SetFont('Helvetica', !empty($cfg['bold']) ? 'B' : '', (float) ($cfg['size'] ?? 8));
    $pdf->SetXY($cfg['x'], $cfg['y']);
    $pdf->MultiCell($cfg['w'], $cfg['h'], $text);
};

$write($pdf, $map['ship_date'], $bol['ship_date']);
$write($pdf, $map['bol_number'], $bol['bol_number']);
$write($pdf, $map['shipper_contact_name'], $bol['shipper_contact_name']);
$write($pdf, $map['shipper_phone'], $bol['shipper_phone']);
$write($pdf, $map['shipper_name'], $bol['shipper_name']);
$write($pdf, $map['shipper_address'], $bol['shipper_address']);
$write($pdf, $map['shipper_city'], $bol['shipper_city']);
$write($pdf, $map['shipper_province_state'], $bol['shipper_province_state']);
$write($pdf, $map['shipper_postal_code'], $bol['shipper_postal_code']);
$write($pdf, $map['shipper_country'], $bol['shipper_country']);
$write($pdf, $map['consignee_contact_name'], $bol['consignee_contact_name']);
$write($pdf, $map['consignee_phone'], $bol['consignee_phone']);
$write($pdf, $map['consignee_name'], $bol['consignee_name']);
$write($pdf, $map['consignee_address'], $bol['consignee_address']);
$write($pdf, $map['consignee_city'], $bol['consignee_city']);
$write($pdf, $map['consignee_province_state'], $bol['consignee_province_state']);
$write($pdf, $map['consignee_postal_code'], $bol['consignee_postal_code']);
$write($pdf, $map['consignee_country'], $bol['consignee_country']);
$write($pdf, $map['consignor_name'], $bol['consignor_name']);
$write($pdf, $map['special_instructions'], $bol['special_instructions']);

foreach (array_slice($lineItems, 0, $detailRowLimit) as $index => $lineItem) {
    $rowOffsetY = $detailRowStep * $index;

    foreach ($detailRowMap as $key => $cfg) {
        $rowCfg = $cfg;
        $rowCfg['y'] = (float) $cfg['y'] + $rowOffsetY;
        $value = (string) ($lineItem[$key] ?? '');

        if ($key === 'pallet_weight') {
            $numericWeight = outwhse_bol_numeric_value($value);
            $value = $numericWeight > 0 ? outwhse_bol_format_number($numericWeight) . ' LBS' : '';
        } elseif ($key === 'qty') {
            $numericQty = outwhse_bol_numeric_value($value);
            $value = $numericQty > 0 ? outwhse_bol_format_number($numericQty) : $value;
        }

        $write($pdf, $rowCfg, $value);
    }
}

$pdf->SetTextColor(255, 255, 255);
$write($pdf, $map['total_pallets'], (string) $totalPallets);
$write($pdf, $map['total_weight'], $totalWeightDisplay);
$write($pdf, $map['total_cases'], outwhse_bol_format_number($totalCases));

$pdf->SetTextColor(0, 0, 0);
$write($pdf, $map['consignor_date'], $bol['consignor_date']);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="bol-export.pdf"');
$pdf->Output('I', 'bol-export.pdf');
exit;
