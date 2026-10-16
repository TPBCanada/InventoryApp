<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

require_once __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/../utils/helpers.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

if (!function_exists('h')) {
    function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

function clean_scanner_value(string $value): string
{
    // Remove ASCII control characters often sent by scanners, trim whitespace.
    $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    return trim($clean ?? $value);
}

function redirect_to_shelf_page(string $selectedTagCode = '', string $shelfSearch = '', bool $debug = false): void
{
    $params = [];
    if ($selectedTagCode !== '') {
        $params['selected_tag_code'] = $selectedTagCode;
    }
    if ($shelfSearch !== '') {
        $params['shelf_search'] = $shelfSearch;
    }
    if ($debug) {
        $params['debug'] = '1';
    }

    $url = $_SERVER['PHP_SELF'];
    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    header('Location: ' . $url);
    exit;
}

function parse_shelf_tags(?string $shelf): array
{
    if ($shelf === null || trim($shelf) === '') {
        return [];
    }

    $parts = array_map('trim', explode(',', $shelf));
    $parts = array_values(array_filter($parts, static fn($v) => $v !== ''));

    $unique = [];
    foreach ($parts as $part) {
        if (!in_array($part, $unique, true)) {
            $unique[] = $part;
        }
    }

    return $unique;
}

function format_shelf_tags(array $tags): ?string
{
    $tags = array_values(array_filter(array_map('trim', $tags), static fn($v) => $v !== ''));
    return empty($tags) ? null : implode(', ', $tags);
}

$success_msg = '';
$error_msg = '';
$debug_enabled = ((string)($_GET['debug'] ?? $_POST['debug'] ?? '') === '1');
$debug_log = [
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'get' => $_GET,
    'post' => $_POST,
    'events' => [],
];

// ---------- Inputs ----------
$shelf_search = clean_scanner_value((string)($_GET['shelf_search'] ?? ''));
$selected_tag_code = clean_scanner_value((string)($_GET['selected_tag_code'] ?? ''));
$selected_sku_id = (int)($_GET['selected_sku_id'] ?? 0);

// ---------- Process Add SKU Scan ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_sku_to_shelf') {
    $selected_tag_code = clean_scanner_value((string)($_POST['selected_tag_code'] ?? ''));
    $shelf_search = clean_scanner_value((string)($_POST['shelf_search'] ?? ''));
    $sku_scan_raw = (string)($_POST['sku_scan'] ?? '');
    $sku_scan = clean_scanner_value($sku_scan_raw);
    $sku_scan_compact = preg_replace('/\s+/', '', $sku_scan) ?? $sku_scan;

    $debug_log['events'][] = [
        'event' => 'assign_post_received',
        'selected_tag_code' => $selected_tag_code,
        'shelf_search' => $shelf_search,
        'sku_scan_raw' => $sku_scan_raw,
        'sku_scan_clean' => $sku_scan,
        'sku_scan_compact' => $sku_scan_compact,
    ];

    if ($selected_tag_code === '') {
        $error_msg = 'Select a shelf location first.';
        $debug_log['events'][] = ['event' => 'assign_failed', 'reason' => 'missing_selected_tag_code'];
    } elseif ($sku_scan === '') {
        $error_msg = 'Scan or enter a SKU number.';
        $debug_log['events'][] = ['event' => 'assign_failed', 'reason' => 'missing_sku_scan'];
    } else {
        try {
            $stmt = $conn->prepare("
                SELECT id, tag_code, barcode, section, aisle, shelf_level
                FROM shelf_tags
                WHERE tag_code = ? OR barcode = ?
                LIMIT 1
            ");
            $stmt->bind_param('ss', $selected_tag_code, $selected_tag_code);
            $stmt->execute();
            $shelf = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $debug_log['events'][] = [
                'event' => 'shelf_lookup',
                'found' => (bool)$shelf,
                'selected_tag_code' => $selected_tag_code,
            ];

            if (!$shelf) {
                $error_msg = 'Selected shelf location was not found: ' . h($selected_tag_code);
                $debug_log['events'][] = ['event' => 'assign_failed', 'reason' => 'shelf_not_found'];
            } else {
                $stmt = $conn->prepare("
                    SELECT
                        s.id,
                        s.sku_num,
                        s.`desc`,
                        s.shelf,
                        pub.upc AS matched_upc,
                        pub.uom_label AS matched_uom_label,
                        pub.multiplier AS matched_multiplier,
                        CASE
                            WHEN s.sku_num = ? OR REPLACE(s.sku_num, ' ', '') = ? THEN 'sku_num'
                            WHEN pub.id IS NOT NULL THEN 'product_uom_barcodes.upc'
                            ELSE 'unknown'
                        END AS match_type
                    FROM sku s
                    LEFT JOIN product_uom_barcodes pub
                        ON pub.sku_id = s.id
                       AND (
                            pub.upc = ?
                            OR REPLACE(pub.upc, ' ', '') = ?
                       )
                    WHERE
                        s.sku_num = ?
                        OR REPLACE(s.sku_num, ' ', '') = ?
                        OR pub.id IS NOT NULL
                    LIMIT 1
                ");
                $stmt->bind_param(
                    'ssssss',
                    $sku_scan,
                    $sku_scan_compact,
                    $sku_scan,
                    $sku_scan_compact,
                    $sku_scan,
                    $sku_scan_compact
                );
                $stmt->execute();
                $sku = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $debug_log['events'][] = [
                    'event' => 'sku_or_barcode_lookup',
                    'found' => (bool)$sku,
                    'sku_scan_clean' => $sku_scan,
                    'sku_scan_compact' => $sku_scan_compact,
                    'matched_sku_num' => $sku ? (string)$sku['sku_num'] : null,
                    'matched_upc' => $sku ? (string)($sku['matched_upc'] ?? '') : null,
                    'matched_uom_label' => $sku ? (string)($sku['matched_uom_label'] ?? '') : null,
                    'matched_multiplier' => $sku ? (string)($sku['matched_multiplier'] ?? '') : null,
                    'match_type' => $sku ? (string)($sku['match_type'] ?? '') : null,
                ];

                if (!$sku) {
                    $error_msg = 'SKU/barcode not found: ' . h($sku_scan) . '. The scanned value must match sku.sku_num or product_uom_barcodes.upc.';
                    $debug_log['events'][] = ['event' => 'assign_failed', 'reason' => 'sku_or_barcode_not_found'];
                } else {
                    $tagCode = (string)$shelf['tag_code'];
                    $oldShelfValue = (string)($sku['shelf'] ?? '');
                    $tags = parse_shelf_tags($sku['shelf'] ?? null);
                    $alreadyHadTag = in_array($tagCode, $tags, true);

                    if (!$alreadyHadTag) {
                        $tags[] = $tagCode;
                    }

                    $newShelfValue = format_shelf_tags($tags);

                    $stmt = $conn->prepare("UPDATE sku SET shelf = ? WHERE id = ?");
                    $skuId = (int)$sku['id'];
                    $stmt->bind_param('si', $newShelfValue, $skuId);
                    $stmt->execute();
                    $affectedRows = $stmt->affected_rows;
                    $stmtError = $stmt->error;
                    $stmt->close();

                    $debug_log['events'][] = [
                        'event' => 'sku_update',
                        'sku_id' => $skuId,
                        'sku_num' => (string)$sku['sku_num'],
                        'old_shelf' => $oldShelfValue,
                        'new_shelf' => $newShelfValue,
                        'already_had_tag' => $alreadyHadTag,
                        'affected_rows' => $affectedRows,
                        'stmt_error' => $stmtError,
                    ];

                    $_SESSION['shelf_debug_last'] = $debug_log;

                    if ($alreadyHadTag) {
                        $_SESSION['shelf_success_msg'] = 'SKU ' . h((string)$sku['sku_num']) . ' already had location ' . h($tagCode) . '.';
                    } else {
                        $_SESSION['shelf_success_msg'] = 'Added location ' . h($tagCode) . ' to SKU ' . h((string)$sku['sku_num']) . '.';
                    }

                    redirect_to_shelf_page($tagCode, $shelf_search, $debug_enabled);
                }
            }
        } catch (\Throwable $e) {
            $error_msg = 'Assignment failed: ' . h($e->getMessage());
            $debug_log['events'][] = [
                'event' => 'assign_exception',
                'message' => $e->getMessage(),
            ];
        }
    }
}

// ---------- Process Remove Tag From SKU ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_shelf_tag_from_sku') {
    $selected_tag_code = clean_scanner_value((string)($_POST['selected_tag_code'] ?? ''));
    $shelf_search = clean_scanner_value((string)($_POST['shelf_search'] ?? ''));
    $sku_id = (int)($_POST['sku_id'] ?? 0);

    if ($sku_id <= 0 || $selected_tag_code === '') {
        $error_msg = 'Invalid SKU or shelf tag selected.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT id, sku_num, shelf FROM sku WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $sku_id);
            $stmt->execute();
            $sku = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sku) {
                $error_msg = 'SKU not found.';
            } else {
                $tags = parse_shelf_tags($sku['shelf'] ?? null);
                $tags = array_values(array_filter($tags, static fn($tag) => $tag !== $selected_tag_code));
                $newShelfValue = format_shelf_tags($tags);

                $stmt = $conn->prepare("UPDATE sku SET shelf = ? WHERE id = ?");
                $stmt->bind_param('si', $newShelfValue, $sku_id);
                $stmt->execute();
                $stmt->close();

                $_SESSION['shelf_success_msg'] = 'Removed location ' . h($selected_tag_code) . ' from SKU ' . h((string)$sku['sku_num']) . '.';
                redirect_to_shelf_page($selected_tag_code, $shelf_search, $debug_enabled);
            }
        } catch (\Throwable $e) {
            $error_msg = 'Remove failed: ' . h($e->getMessage());
        }
    }
}

if (!empty($_SESSION['shelf_success_msg'])) {
    $success_msg = (string)$_SESSION['shelf_success_msg'];
    unset($_SESSION['shelf_success_msg']);
}

$last_debug_log = $_SESSION['shelf_debug_last'] ?? null;
unset($_SESSION['shelf_debug_last']);

// ---------- Load Shelf Search Results ----------
$shelf_results = [];
$sku_search = clean_scanner_value((string)($_GET['sku_search'] ?? ''));
$sku_results = [];

// Only load shelf results if doing a shelf search (not a SKU search)
try {
    if ($sku_search === '') {
        if ($shelf_search !== '') {
            $stmt = $conn->prepare("
                SELECT id, tag_code, barcode, section, aisle, shelf_level
                FROM shelf_tags
                WHERE tag_code LIKE ?
                   OR barcode LIKE ?
                   OR section LIKE ?
                   OR aisle LIKE ?
                   OR shelf_level LIKE ?
                ORDER BY section ASC, aisle ASC, shelf_level ASC
                LIMIT 100
            ");
            $wild = '%' . $shelf_search . '%';
            $stmt->bind_param('sssss', $wild, $wild, $wild, $wild, $wild);
        } else {
            $stmt = $conn->prepare("
                SELECT id, tag_code, barcode, section, aisle, shelf_level
                FROM shelf_tags
                ORDER BY section ASC, aisle ASC, shelf_level ASC
                LIMIT 25
            ");
        }

        $stmt->execute();
        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
            $shelf_results[] = [
                'id' => (int)$r['id'],
                'tag_code' => (string)$r['tag_code'],
                'barcode' => (string)$r['barcode'],
                'section' => (string)$r['section'],
                'aisle' => (string)$r['aisle'],
                'shelf_level' => (string)$r['shelf_level'],
            ];
        }

        $stmt->close();
    }
} catch (\Throwable $e) {
    $error_msg = 'Shelf lookup failed: ' . h($e->getMessage());
}

// ---------- Load SKU Search Results ----------
try {
    if ($sku_search !== '') {
        $sku_search_compact = preg_replace('/\s+/', '', $sku_search) ?? $sku_search;
        $stmt = $conn->prepare("
            SELECT
                id,
                sku_num,
                `desc`,
                status,
                shelf
            FROM sku
            WHERE sku_num LIKE ?
               OR REPLACE(sku_num, ' ', '') LIKE ?
            ORDER BY sku_num ASC
            LIMIT 100
        ");
        $wild = '%' . $sku_search . '%';
        $wild_compact = '%' . $sku_search_compact . '%';
        $stmt->bind_param('ss', $wild, $wild_compact);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
            $sku_results[] = [
                'sku_id' => (int)$r['id'],
                'sku_num' => (string)$r['sku_num'],
                'desc' => (string)$r['desc'],
                'status' => (string)$r['status'],
                'shelf' => (string)($r['shelf'] ?? ''),
            ];
        }

        $stmt->close();
    }
} catch (\Throwable $e) {
    $error_msg = 'SKU lookup failed: ' . h($e->getMessage());
}

// ---------- Load Selected Shelf ----------
$selected_shelf = null;

if ($selected_tag_code !== '') {
    try {
        $stmt = $conn->prepare("
            SELECT id, tag_code, barcode, section, aisle, shelf_level
            FROM shelf_tags
            WHERE tag_code = ? OR barcode = ?
            LIMIT 1
        ");
        $stmt->bind_param('ss', $selected_tag_code, $selected_tag_code);
        $stmt->execute();
        $selected_shelf = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($selected_shelf) {
            $selected_tag_code = (string)$selected_shelf['tag_code'];
        } else {
            $error_msg = 'Selected shelf location not found.';
        }
    } catch (\Throwable $e) {
        $error_msg = 'Selected shelf lookup failed: ' . h($e->getMessage());
    }
}

// ---------- Load Selected SKU ----------
$selected_sku = null;
$sku_assigned_shelves = [];

if ($selected_sku_id > 0) {
    try {
        $stmt = $conn->prepare("
            SELECT id, sku_num, `desc`, status, shelf
            FROM sku
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $selected_sku_id);
        $stmt->execute();
        $selected_sku = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$selected_sku) {
            $error_msg = 'Selected SKU not found.';
        } else {
            // Load shelf locations already assigned to this SKU
            $sku_shelf_tags = parse_shelf_tags($selected_sku['shelf'] ?? null);
            if (!empty($sku_shelf_tags)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($sku_shelf_tags), '?'));
                    $stmt = $conn->prepare("
                        SELECT id, tag_code, barcode, section, aisle, shelf_level
                        FROM shelf_tags
                        WHERE tag_code IN ($placeholders)
                        ORDER BY section ASC, aisle ASC, shelf_level ASC
                    ");
                    $stmt->bind_param(str_repeat('s', count($sku_shelf_tags)), ...$sku_shelf_tags);
                    $stmt->execute();
                    $res = $stmt->get_result();

                    while ($r = $res->fetch_assoc()) {
                        $sku_assigned_shelves[] = [
                            'id' => (int)$r['id'],
                            'tag_code' => (string)$r['tag_code'],
                            'barcode' => (string)$r['barcode'],
                            'section' => (string)$r['section'],
                            'aisle' => (string)$r['aisle'],
                            'shelf_level' => (string)$r['shelf_level'],
                        ];
                    }

                    $stmt->close();
                } catch (\Throwable $e) {
                    $error_msg = 'Failed to load SKU shelf locations: ' . h($e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        $error_msg = 'Selected SKU lookup failed: ' . h($e->getMessage());
    }
}

// ---------- Load SKUs Having Selected Location In sku.shelf ----------
$assigned_skus = [];

if ($selected_shelf) {
    try {
        $tagCode = (string)$selected_shelf['tag_code'];

        // Remove spaces from shelf before FIND_IN_SET, so "RACK, S01-10-23" still matches "S01-10-23".
        $stmt = $conn->prepare("
            SELECT
                id,
                sku_num,
                `desc`,
                status,
                image_url,
                created_at,
                shelf
            FROM sku
            WHERE FIND_IN_SET(?, REPLACE(COALESCE(shelf, ''), ' ', '')) > 0
            ORDER BY sku_num ASC
            LIMIT 1000
        ");
        $stmt->bind_param('s', $tagCode);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
            $assigned_skus[] = [
                'sku_id' => (int)$r['id'],
                'sku_num' => (string)$r['sku_num'],
                'desc' => (string)$r['desc'],
                'status' => (string)$r['status'],
                'image_url' => (string)($r['image_url'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'shelf' => (string)($r['shelf'] ?? ''),
            ];
        }

        $stmt->close();
    } catch (\Throwable $e) {
        $error_msg = 'Assigned SKU lookup failed: ' . h($e->getMessage());
    }
}

// ---------- Page Layout ----------
$title = 'Shelf Location SKU Tagging';
$page_class = 'page-shelf-location-sku-tagging';
ob_start();
?>

<h2 class="title">Shelf Location SKU Tagging</h2>

<?php if ($success_msg): ?>
    <div class="alert alert-success p-2 small"><?= $success_msg ?></div>
<?php endif; ?>

<?php if ($error_msg): ?>
    <div class="alert alert-danger p-2 small"><?= $error_msg ?></div>
<?php endif; ?>

<?php if (!$debug_enabled): ?>
    <div class="small text-muted mb-3">
        Debug is off. Add <code>&debug=1</code> to the URL after selecting a location to log scan details to the browser console.
    </div>
<?php endif; ?>

<div class="card mb-4" style="padding: 16px;">
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="shelf-tab" data-bs-toggle="tab" data-bs-target="#shelf-search" type="button" role="tab">Find Shelf Location</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="sku-tab" data-bs-toggle="tab" data-bs-target="#sku-search" type="button" role="tab">Find SKU</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="shelf-search" role="tabpanel">
            <form method="get" class="row g-2 align-items-end" id="shelfSearchForm">
                <?php if ($debug_enabled): ?>
                    <input type="hidden" name="debug" value="1">
                <?php endif; ?>
                <div class="col-md-8">
                    <label class="form-label small text-muted" for="shelf_search">Find shelf location</label>
                    <input type="text"
                           name="shelf_search"
                           id="shelf_search"
                           class="form-control"
                           placeholder="Search by location tag or shelf barcode..."
                           value="<?= h($shelf_search) ?>">
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
                <div class="col-md-2 d-grid">
                    <a href="<?= h($_SERVER['PHP_SELF']) ?><?= $debug_enabled ? '?debug=1' : '' ?>" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>

        <div class="tab-pane fade" id="sku-search" role="tabpanel">
            <form method="get" class="row g-2 align-items-end" id="skuSearchForm">
                <?php if ($debug_enabled): ?>
                    <input type="hidden" name="debug" value="1">
                <?php endif; ?>
                <div class="col-md-8">
                    <label class="form-label small text-muted" for="sku_search">Find SKU number</label>
                    <input type="text"
                           name="sku_search"
                           id="sku_search"
                           class="form-control"
                           placeholder="Search by SKU number..."
                           value="<?= h($sku_search) ?>">
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
                <div class="col-md-2 d-grid">
                    <a href="<?= h($_SERVER['PHP_SELF']) ?><?= $debug_enabled ? '?debug=1' : '' ?>" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card mb-4" style="overflow: auto;">
    <div class="p-3 border-bottom">
        <strong>Locations</strong>
        <div class="small text-muted">Choose the shelf first, then scan SKUs to add this location into sku.shelf.</div>
    </div>

    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>Location</th>
                <th style="width: 120px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($shelf_results)): ?>
                <?php foreach ($shelf_results as $tag): ?>
                    <?php $is_selected = $selected_tag_code !== '' && $tag['tag_code'] === $selected_tag_code; ?>
                    <tr class="<?= $is_selected ? 'table-success' : '' ?>">
                        <td><strong><?= h($tag['tag_code']) ?></strong></td>
                        <td>
                            <a class="btn btn-sm <?= $is_selected ? 'btn-success' : 'btn-outline-primary' ?>"
                               href="<?= h($_SERVER['PHP_SELF']) ?>?selected_tag_code=<?= urlencode($tag['tag_code']) ?>&shelf_search=<?= urlencode($shelf_search) ?><?= $debug_enabled ? '&debug=1' : '' ?>">
                                <?= $is_selected ? 'Selected' : 'Use' ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="2" class="text-center text-muted p-4">No shelf locations found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($sku_results)): ?>
    <div class="card mb-4" style="overflow: auto;">
        <div class="p-3 border-bottom">
            <strong>SKU Search Results</strong>
            <div class="small text-muted">Click a SKU to assign shelf locations to it.</div>
        </div>

        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 130px;">SKU Number</th>
                    <th style="min-width: 280px;">Description</th>
                    <th>Status</th>
                    <th>Current Locations</th>
                    <th style="width: 120px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sku_results as $s): ?>
                    <tr>
                        <td><strong><?= h($s['sku_num']) ?></strong></td>
                        <td><?= h($s['desc']) ?></td>
                        <td><?= h($s['status']) ?></td>
                        <td class="small"><?= h($s['shelf']) ?: '(none)' ?></td>
                        <td>
                            <?php
                                $sku_tags = parse_shelf_tags($s['shelf'] ?? null);
                                $first_tag = !empty($sku_tags) ? $sku_tags[0] : null;
                            ?>
                            <?php if ($first_tag): ?>
                                <a href="<?= h($_SERVER['PHP_SELF']) ?>?selected_tag_code=<?= urlencode($first_tag) ?>&sku_search=<?= urlencode($sku_search) ?><?= $debug_enabled ? '&debug=1' : '' ?>"
                                   class="btn btn-sm btn-success" style="text-align: center" title="Load first shelf location">
                                    Use Shelf
                                </a>
                            <?php else: ?>
                                <a href="<?= h($_SERVER['PHP_SELF']) ?>?sku_search=<?= urlencode($sku_search) ?>&selected_sku_id=<?= urlencode((string)$s['sku_id']) ?><?= $debug_enabled ? '&debug=1' : '' ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    Assign
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($selected_shelf): ?>
    <div class="card mb-4" style="padding: 16px;">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
            <div>
                <div class="small text-muted">Selected Location</div>
                <h4 class="mb-1" id="selectedLocationText"><?= h((string)$selected_shelf['tag_code']) ?></h4>
            </div>

            <form method="post" id="skuAssignForm" class="d-flex gap-2 align-items-end m-0" action="<?= h($_SERVER['PHP_SELF']) ?>">
                <input type="hidden" name="action" value="assign_sku_to_shelf">
                <input type="hidden" name="selected_tag_code" id="selected_tag_code" value="<?= h((string)$selected_shelf['tag_code']) ?>">
                <input type="hidden" name="shelf_search" value="<?= h($shelf_search) ?>">
                <?php if ($debug_enabled): ?>
                    <input type="hidden" name="debug" value="1">
                <?php endif; ?>

                <div>
                    <label class="form-label small text-muted" for="sku_scan">Scan SKU</label>
                    <input type="text"
                           name="sku_scan"
                           id="sku_scan"
                           class="form-control"
                           placeholder="Scan or enter sku_num..."
                           autocomplete="off"
                           autocapitalize="off"
                           spellcheck="false"
                           inputmode="text"
                           autofocus
                           required>
                </div>

                <button type="submit" id="skuAssignSubmit" class="btn btn-success">
                    Add Location To SKU
                </button>
            </form>
        </div>
    </div>

    <div class="card" style="overflow: auto;">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <strong>SKUs assigned to <?= h((string)$selected_shelf['tag_code']) ?></strong>
                <div class="small text-muted"><?= count($assigned_skus) ?> SKU<?= count($assigned_skus) === 1 ? '' : 's' ?> currently have this location in sku.shelf.</div>
            </div>
        </div>

        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 130px;">SKU</th>
                    <th style="min-width: 280px;">Description</th>
                    <th>Status</th>
                    <th>All Shelf Tags</th>
                    <th style="width: 120px;">Remove Tag</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($assigned_skus)): ?>
                    <?php foreach ($assigned_skus as $s): ?>
                        <tr>
                            <td><strong><?= h($s['sku_num']) ?></strong></td>
                            <td><?= h($s['desc']) ?></td>
                            <td><?= h($s['status']) ?></td>
                            <td class="small"><?= h($s['shelf']) ?></td>
                            <td>
                                <form method="post" class="m-0" action="<?= h($_SERVER['PHP_SELF']) ?>" onsubmit="return confirm('Remove this location from the SKU?');">
                                    <input type="hidden" name="action" value="remove_shelf_tag_from_sku">
                                    <input type="hidden" name="sku_id" value="<?= h((string)$s['sku_id']) ?>">
                                    <input type="hidden" name="selected_tag_code" value="<?= h((string)$selected_shelf['tag_code']) ?>">
                                    <input type="hidden" name="shelf_search" value="<?= h($shelf_search) ?>">
                                    <?php if ($debug_enabled): ?>
                                        <input type="hidden" name="debug" value="1">
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted p-4">
                            No SKUs currently have this location. Scan a SKU above to add it.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php elseif ($selected_sku): ?>
    <div class="card mb-4" style="padding: 16px;">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
            <div>
                <div class="small text-muted">Selected SKU</div>
                <h4 class="mb-1"><?= h((string)$selected_sku['sku_num']) ?></h4>
                <p class="mb-0 text-muted small"><?= h((string)$selected_sku['desc']) ?></p>
            </div>

            <a href="<?= h($_SERVER['PHP_SELF']) ?>?sku_search=<?= urlencode($sku_search) ?><?= $debug_enabled ? '&debug=1' : '' ?>" class="btn btn-outline-secondary">
                Back to Results
            </a>
        </div>
    </div>

    <div class="card mb-4" style="overflow: auto;">
        <div class="p-3 border-bottom">
            <strong>Shelf Locations Assigned to This SKU</strong>
            <div class="small text-muted"><?= count($sku_assigned_shelves) ?> location<?= count($sku_assigned_shelves) === 1 ? '' : 's' ?> assigned. Click "Remove" to unassign.</div>
        </div>

        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Location</th>
                    <th>Section / Aisle / Level</th>
                    <th style="width: 120px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($sku_assigned_shelves)): ?>
                    <?php foreach ($sku_assigned_shelves as $tag): ?>
                        <tr>
                            <td><strong><?= h($tag['tag_code']) ?></strong></td>
                            <td class="small"><?= h($tag['section']) ?> / <?= h($tag['aisle']) ?> / <?= h($tag['shelf_level']) ?></td>
                            <td>
                                <form method="post" class="m-0" action="<?= h($_SERVER['PHP_SELF']) ?>">
                                    <input type="hidden" name="action" value="remove_shelf_tag_from_sku">
                                    <input type="hidden" name="sku_id" value="<?= h((string)$selected_sku_id) ?>">
                                    <input type="hidden" name="selected_tag_code" value="<?= h($tag['tag_code']) ?>">
                                    <input type="hidden" name="sku_search" value="<?= h($sku_search) ?>">
                                    <input type="hidden" name="selected_sku_id" value="<?= h((string)$selected_sku_id) ?>">
                                    <?php if ($debug_enabled): ?>
                                        <input type="hidden" name="debug" value="1">
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this location from the SKU?');">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted p-4">
                            No shelf locations assigned to this SKU.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="card" style="padding: 16px;">
        <div class="text-muted">
            Select a shelf location or search for a SKU to get started.
        </div>
    </div>
<?php endif; ?>

<?php if ($debug_enabled): ?>
    <div class="card mt-4" style="padding: 16px;">
        <strong>Debug</strong>
        <div class="small text-muted mb-2">This same information is also written to the browser console.</div>
        <pre style="white-space: pre-wrap; font-size: 12px; max-height: 320px; overflow: auto;"><?=
            h(json_encode([
                'current_request' => $debug_log,
                'last_redirected_post' => $last_debug_log,
                'selected_shelf' => $selected_shelf,
                'assigned_sku_count' => count($assigned_skus),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
        ?></pre>
    </div>
<?php endif; ?>

<script>
(function () {
    const DEBUG = <?= $debug_enabled ? 'true' : 'false' ?>;
    const hasSKUResults = <?= !empty($sku_results) ? 'true' : 'false' ?>;
    const hasSelectedSKU = <?= $selected_sku ? 'true' : 'false' ?>;
    const serverDebug = <?= json_encode([
        'current_request' => $debug_log,
        'last_redirected_post' => $last_debug_log,
        'selected_tag_code' => $selected_tag_code,
        'selected_shelf' => $selected_shelf,
        'assigned_sku_count' => count($assigned_skus),
        'success_msg' => $success_msg,
        'error_msg' => $error_msg,
    ], JSON_UNESCAPED_SLASHES) ?>;

    function log() {
        if (!DEBUG) return;
        console.log.apply(console, arguments);
    }

    function group(label, data) {
        if (!DEBUG) return;
        console.group(label);
        console.log(data);
        console.groupEnd();
    }

    group('[shelf-debug] page loaded', serverDebug);

    if (hasSKUResults) {
        const skuResultsTab = document.getElementById('sku-tab');
        if (skuResultsTab) {
            skuResultsTab.click();
            setTimeout(() => {
                const skuSearchSection = document.getElementById('sku-search');
                if (skuSearchSection) {
                    skuSearchSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 100);
        }
    } else if (hasSelectedSKU) {
        setTimeout(() => {
            const selectedSKUSection = document.querySelector('.text-muted');
            if (selectedSKUSection && selectedSKUSection.textContent.includes('Selected SKU')) {
                selectedSKUSection.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 100);
    }

    const skuScan = document.getElementById('sku_scan');
    const assignForm = document.getElementById('skuAssignForm');
    const selectedTag = document.getElementById('selected_tag_code');

    if (skuScan && assignForm) {
        skuScan.focus();
        skuScan.select();

        assignForm.addEventListener('submit', function () {
            group('[shelf-debug] SKU form submit', {
                formAction: assignForm.getAttribute('action') || window.location.href,
                method: assignForm.getAttribute('method'),
                selectedTagCode: selectedTag ? selectedTag.value : null,
                skuScanRaw: skuScan.value,
                skuScanTrimmed: skuScan.value.trim(),
                currentUrl: window.location.href
            });
        });

        skuScan.addEventListener('input', function () {
            log('[shelf-debug] SKU input changed:', skuScan.value);
        });

        skuScan.addEventListener('keydown', function (event) {
            log('[shelf-debug] keydown:', {
                key: event.key,
                code: event.code,
                value: skuScan.value
            });

            if (event.key === 'Enter') {
                event.preventDefault();

                const scannedValue = skuScan.value.trim();
                if (scannedValue !== '') {
                    log('[shelf-debug] Enter detected. Submitting SKU form.');
                    if (typeof assignForm.requestSubmit === 'function') {
                        assignForm.requestSubmit();
                    } else {
                        assignForm.submit();
                    }
                } else {
                    log('[shelf-debug] Enter detected but SKU input is blank.');
                }
            }
        });
    } else {
        log('[shelf-debug] SKU scan form not found on page.', {
            skuScanFound: !!skuScan,
            assignFormFound: !!assignForm
        });
    }
})();
</script>

<?php
$content = ob_get_clean();

require __DIR__ . '/templates/layout.php';
?>
