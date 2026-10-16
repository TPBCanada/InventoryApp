<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/utils/shopify_fetch.php';


if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;
// $can_save_shelf = in_array((int) $role_id, [1, 2], true);
$can_save_shelf =  true;

if (!$conn) {
    http_response_code(500);
    echo "Connection failed: " . mysqli_connect_error();
    exit;
}

$DEBUG = false;
if (!empty($_GET['debug']) && $_GET['debug'] === '1' && ($role_id === 99 /* admin */ || getenv('APP_ENV') === 'dev')) {
    $DEBUG = true;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn->set_charset('utf8mb4');
} catch (\Throwable $_) {
}

if (!function_exists('parse_location_code')) {
    /**
     * Parses a location string like ROW-BAY-LEVEL-SIDE into its components.
     */
    function parse_location_code(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        $parts = preg_split('/[-\s]+/', $code);
        return [
            'row_code' => $parts[0] ?? '',
            'bay_num' => $parts[1] ?? '',
            'level_code' => $parts[2] ?? '',
            'side' => $parts[3] ?? '',
        ];
    }
}


/* -------------------- SUGGEST API (lightweight) --------------------
Returns suggestions for dropdown while typing.
?suggest=1&type=sku|loc&q=... -> JSON list
---------------------------------------------------------------------*/
if (isset($_GET['suggest']) && $_GET['suggest'] === '1') {
    while (ob_get_level() > 0)
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $type = $_GET['type'] ?? 'sku';
    $q = trim($_GET['q'] ?? '');
    $out = [];

    try {
        if ($type === 'sku') {
            // Suggest ACTIVE SKUs by number or description
            $sql = "SELECT sku_num, `desc`
                FROM sku
                WHERE status='ACTIVE'
                AND (sku_num LIKE CONCAT('%', ?, '%') OR `desc` LIKE CONCAT('%', ?, '%'))
                ORDER BY sku_num ASC
                LIMIT 30";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $q, $q);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $out[] = ['sku_num' => (string) $r['sku_num'], 'desc' => (string) ($r['desc'] ?? '')];
            }
            $stmt->close();
        } else {
            // Suggest Location codes (ROW-BAY-LEVEL-SIDE)
            $sql = "SELECT row_code, bay_num, level_code, side
                FROM location
                WHERE CONCAT(row_code,'-',LPAD(bay_num,2,'0'),'-',LPAD(level_code,2,'0'),'-',side) LIKE CONCAT('%', ?, '%')
                ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side
                LIMIT 30";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $q);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $rows_out[] = [
                    'sku_id' => (int) ($r['sku_id'] ?? 0),
                    'sku' => (string) ($r['sku'] ?? ''),
                    'desc' => (string) ($r['sku_description'] ?? ''),
                    'on_hand' => (int) ($r['on_hand'] ?? 0),
                    // 'last_moved_at' => $r['last_moved_at'] ?? null,
                    'last_manual_count_by' => (string) ($r['last_manual_count_by'] ?? ''),
                    'last_manual_count_at' => (string) ($r['last_manual_count_at'] ?? ''),
                    'open_req_qty' => (int) ($r['open_req_qty'] ?? 0), // Updated Key
                    'has_priority' => (int) ($r['has_priority'] ?? 0)
                ];
            }
            $stmt->close();
        }
    } catch (\Throwable $e) {
        if ($DEBUG) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['ok' => true, 'type' => $type, 'items' => $out], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* -------------------- SHELF SAVE (AJAX) -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shelf_update'])) {
    while (ob_get_level() > 0)
        ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!$can_save_shelf) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Not authorized to update shelf.'], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $sku_num = trim((string) ($_POST['sku_num'] ?? ''));
    $shelf_value = trim((string) ($_POST['shelf_value'] ?? ''));

    if ($sku_num === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Missing SKU to update.'], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if (strlen($shelf_value) > 120) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Shelf value is too long (max 120 characters).'], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    try {
        $stmt = $conn->prepare("UPDATE sku SET shelf = ? WHERE sku_num = ? LIMIT 1");
        $stmt->bind_param('ss', $shelf_value, $sku_num);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => true, 'sku' => $sku_num, 'shelf' => $shelf_value], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'err' => $DEBUG ? $e->getMessage() : 'Unable to save shelf right now.'], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

/* -------------------- MAIN SEARCH -------------------- */

$q_raw = trim($_GET['q'] ?? '');
$sku_q_exact = trim($_GET['sku_q'] ?? '');

$is_exact_match = false;
$q = '';

if ($sku_q_exact !== '') {
    $q = $sku_q_exact;
    $is_exact_match = true;
} elseif ($q_raw !== '') {
    $q = $q_raw;
    $is_exact_match = false;
}
// --------------------------------------------------

$rows = [];
$totals_by_sku = [];
$sku_history_records = [];
$path_used = 'view';
$shelf_form_sku = '';
$shelf_form_value = '';
$sku_exists = false;
$sku_status = null;

$loc_pk = detect_location_pk($conn);

$shopify_product = null;
$shopify_product_error = null;
$shopify_lookup_sku = '';

if ($is_exact_match && $q !== '') {
    $shopify_lookup_sku = $q;
} elseif ($q !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $q) && substr_count($q, '-') < 2) {
    $shopify_lookup_sku = $q;
}


if ($q !== '') {
    try {
        $stmt_chk = $conn->prepare("SELECT status FROM sku WHERE sku_num = ? LIMIT 1");
        $stmt_chk->bind_param('s', $q);
        $stmt_chk->execute();
        $res_chk = $stmt_chk->get_result();
        if ($row_chk = $res_chk->fetch_assoc()) {
            $sku_exists = true;
            $sku_status = (string) ($row_chk['status'] ?? null);
        }
        $stmt_chk->close();
    } catch (\Throwable $_) {

    }
}

// Universal behavior: when the entered q is an exact SKU in DB, treat it as exact match.
if (!$is_exact_match && $q !== '' && $sku_exists) {
    $is_exact_match = true;
}

if ($shopify_lookup_sku !== '') {
    try {
        $shopify_product = shopify_fetch_product_by_sku($shopify_lookup_sku, 30);
    } catch (\Throwable $e) {
        $shopify_product_error = $DEBUG ? $e->getMessage() : 'Unable to load Shopify product right now.';
    }
    if ($shopify_product === null && $shopify_product_error === null) {
        $shopify_product_error = 'No Shopify product found for this SKU.';
    }
}

$harvest = function (mysqli_result $res) use (&$rows, &$totals_by_sku, &$sku_manual_count_total, &$sku_last_manual_count_at) {
    while ($r = $res->fetch_assoc()) {
        $r['on_hand'] = (int) $r['on_hand'];
        $row_code = (string) $r['row_code'];
        $bay = pad2((string) $r['bay_num']);
        $level = pad2((string) $r['level_code']);
        $side = (string) $r['side'];

        $r['bay_num'] = $bay;
        $r['level_code'] = $level;
        $r['location_code'] = "{$row_code}-{$bay}-{$level}-{$side}";
        $r['location_label'] = getLocationDisplayLabel([
            'row_code' => $row_code,
            'bay_num' => $bay,
            'level_code' => $level,
            'side' => $side,
        ]);

        $rows[] = $r;
        $k = $r['sku_num'];
        if (!isset($totals_by_sku[$k]))
            $totals_by_sku[$k] = ['desc' => $r['sku_desc'], 'sum' => 0];
        $totals_by_sku[$k]['sum'] += $r['on_hand'];

        if (!empty($r['last_manual_count_total'])) {
            $sku_manual_count_total += (int) $r['last_manual_count_total'];
        }
        if (!empty($r['last_manual_count_at'])) {
            if ($sku_last_manual_count_at === null) {
                $sku_last_manual_count_at = $r['last_manual_count_at'];
            } else {
                $sku_last_manual_count_at = max($sku_last_manual_count_at, $r['last_manual_count_at']);
            }
        }
    }
};

$sku_manual_count_total = 0;
$sku_last_manual_count_at = null;
$shelf_manual_count_total = 0;
$shelf_last_manual_count_at = null;

if ($q !== '') {
    $search_param = $q;

    if ($is_exact_match) {
        $where_clause = "s.sku_num = ?";
        $bind_types = 's';
    } else {
        $where_clause = "(s.sku_num LIKE CONCAT('%', ?, '%') OR s.`desc` LIKE CONCAT('%', ?, '%'))";
        $bind_types = 'ss';
    }

    $sql_view = "
        SELECT
        s.sku_num,
        s.`desc` AS sku_desc,
        MAX(slc.counted_at) AS last_manual_count_at,
        MAX(slc.counted_qty) AS last_manual_count_total,
        ub.upc AS unit_upc,
        l.row_code,
        l.bay_num,
        l.level_code,
        l.side,
        l.{$loc_pk} AS loc_id,
        lt.lot_code,
        lt.expiry_date,
        SUM(i.quantity) AS on_hand,
        (
        SELECT MAX(m.created_at)
        FROM inventory_movements m
        WHERE m.sku_id = s.id
        AND m.loc_id = l.{$loc_pk}
        ) AS last_moved_at,
         (SELECT COALESCE(SUM(qty_requested - picked_qty), 0) 
        FROM back_requests 
        WHERE sku_id = s.id AND status = 'OPEN') as open_req_qty,
        (SELECT COALESCE(MAX(is_high_priority), 0) 
        FROM back_requests 
        WHERE sku_id = s.id AND status = 'OPEN') as has_priority
        FROM inventory i
        JOIN sku s ON s.id = i.sku_id
        LEFT JOIN (
            SELECT sku_id, MAX(upc) AS upc
            FROM product_uom_barcodes
            WHERE uom_label = 'UNIT'
            GROUP BY sku_id
        ) ub ON ub.sku_id = s.id
        JOIN pallet p ON p.id = i.pallet_id  
        JOIN location l ON l.{$loc_pk} = p.loc_id 
        LEFT JOIN sku_location_counts slc
          ON slc.sku_id = s.id
         AND slc.loc_id = l.{$loc_pk}
        LEFT JOIN lot lt ON lt.id = i.lot_id
        WHERE {$where_clause} /* DYNAMIC WHERE CLAUSE APPLIED */
        AND i.quantity > 0
        GROUP BY s.id, l.{$loc_pk}, lt.id, lt.lot_code, lt.expiry_date
        HAVING on_hand <> 0
        ORDER BY s.sku_num, l.row_code, CAST(l.bay_num AS UNSIGNED), l.level_code, l.side
        ";
    try {
        $stmt = $conn->prepare($sql_view);
        if ($is_exact_match) {
            $stmt->bind_param($bind_types, $search_param);
        } else {
            $stmt->bind_param($bind_types, $search_param, $search_param);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $harvest($res);
        } else {
            $path_used = 'movements_fallback';
            $stmt->close();

            $fallback_where_clause = $is_exact_match ? "s.sku_num = ?" : "(s.sku_num LIKE CONCAT('%', ?, '%') OR s.`desc` LIKE CONCAT('%', ?, '%'))";

            $sql_fb = "
                SELECT
                    s.sku_num,
                    s.`desc` AS sku_desc,
                    MAX(slc.counted_at) AS last_manual_count_at,
                    MAX(slc.counted_qty) AS last_manual_count_total,
                    ub.upc AS unit_upc,
                    l.row_code,
                    l.bay_num,
                    l.level_code,
                    l.side,
                    l.{$loc_pk} AS loc_id,
                    SUM(im.quantity_change) AS on_hand,
                    MAX(im.created_at) AS last_moved_at
                FROM inventory_movements im
                    JOIN sku s ON s.id = im.sku_id
                    LEFT JOIN (
                    SELECT sku_id, MAX(upc) AS upc
                    FROM product_uom_barcodes
                    WHERE uom_label = 'UNIT'
                    GROUP BY sku_id
                    ) ub ON ub.sku_id = s.id
                JOIN location l ON l.{$loc_pk} = im.loc_id
                LEFT JOIN sku_location_counts slc
                  ON slc.sku_id = s.id
                 AND slc.loc_id = l.{$loc_pk}
                WHERE {$fallback_where_clause}
                GROUP BY s.id, l.{$loc_pk}
                HAVING on_hand <> 0
                ORDER BY s.sku_num, l.row_code, CAST(l.bay_num AS UNSIGNED), l.level_code, l.side
                ";

            $stmt = $conn->prepare($sql_fb);
            if ($is_exact_match) {
                $stmt->bind_param($bind_types, $search_param);
            } else {
                $stmt->bind_param($bind_types, $search_param, $search_param);
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $harvest($res);
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if ($DEBUG) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "SQL error: ", $e->getMessage(), "\n\nPath: {$path_used}\nPK: {$loc_pk}\n";
            echo "SQL(view):\n{$sql_view}\n\n";
            exit;
        }
        throw $e;
    }
}

if ($q !== '') {
    if ($is_exact_match && $q !== '') {
        $shelf_form_sku = $q;
    } elseif (count($totals_by_sku) === 1) {
        $keys = array_keys($totals_by_sku);
        $shelf_form_sku = (string) ($keys[0] ?? '');
    } elseif (preg_match('/^[A-Za-z0-9_-]+$/', $q)) {
        $shelf_form_sku = $q;
    }

    if ($shelf_form_sku !== '') {
        try {
            $stmt = $conn->prepare("SELECT shelf FROM sku WHERE sku_num = ? LIMIT 1");
            $stmt->bind_param('s', $shelf_form_sku);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $shelf_form_value = (string) ($row['shelf'] ?? '');
            }
            $stmt->close();
        } catch (\Throwable $e) {
            if ($DEBUG) {
                $shelf_form_value = '[error loading shelf: ' . $e->getMessage() . ']';
            }
        }

        // Fetch shelf count data (loc_id = 0)
        try {
            $stmt = $conn->prepare("
                SELECT counted_at, counted_qty
                FROM sku_location_counts
                WHERE sku_id = (SELECT id FROM sku WHERE sku_num = ?)
                AND loc_id = 0
                ORDER BY counted_at DESC
                LIMIT 1
            ");
            $stmt->bind_param('s', $shelf_form_sku);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $shelf_last_manual_count_at = (string) ($row['counted_at'] ?? '');
                $shelf_manual_count_total = (int) ($row['counted_qty'] ?? 0);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            if ($DEBUG) {
                error_log('[invSku shelf count fetch] ' . $e->getMessage());
            }
        }
    }
}

$total_on_hand_for_display = null;
if (!empty($totals_by_sku)) {
    if ($shopify_product !== null && isset($totals_by_sku[$shopify_product['sku']])) {
        $total_on_hand_for_display = (int) $totals_by_sku[$shopify_product['sku']]['sum'];
    } elseif ($shelf_form_sku !== '' && isset($totals_by_sku[$shelf_form_sku])) {
        $total_on_hand_for_display = (int) $totals_by_sku[$shelf_form_sku]['sum'];
    } elseif (count($totals_by_sku) === 1) {
        $only_total = array_values($totals_by_sku)[0];
        $total_on_hand_for_display = (int) ($only_total['sum'] ?? 0);
    }
}

$history_sku = '';
$history_sku_id = 0;
$history_sku_desc = '';
if ($is_exact_match && $q !== '') {
    $history_sku = $q;
} elseif (count($totals_by_sku) === 1) {
    $history_sku = (string) array_key_first($totals_by_sku);
}

$sku_history_total = 0;
if ($history_sku !== '') {
    try {
        $stmt_sku = $conn->prepare("
            SELECT id, `desc`
            FROM sku
            WHERE sku_num = ?
            LIMIT 1
        ");
        $stmt_sku->bind_param('s', $history_sku);
        $stmt_sku->execute();
        $sku_row = $stmt_sku->get_result()->fetch_assoc();
        $stmt_sku->close();

        if (is_array($sku_row)) {
            $history_sku_id = (int) ($sku_row['id'] ?? 0);
            $history_sku_desc = (string) ($sku_row['desc'] ?? '');
        }
    } catch (\Throwable $e) {
        if ($DEBUG) {
            error_log('[invSku sku lookup] ' . $e->getMessage());
        }
    }

    try {
        $stmt_count = $conn->prepare("
            SELECT COUNT(id)
            FROM inventory_movements
            WHERE sku_id = ?
        ");
        $stmt_count->bind_param('i', $history_sku_id);
        $stmt_count->execute();
        $sku_history_total = (int) ($stmt_count->get_result()->fetch_column() ?: 0);
        $stmt_count->close();
    } catch (\Throwable $e) {
        if ($DEBUG) {
            error_log('[invSku history count] ' . $e->getMessage());
        }
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                im.id,
                im.created_at,
                im.movement_type,
                im.quantity_change AS signed_delta,
                im.reference,
                im.user_id,
                s.sku_num AS sku,
                s.`desc` AS sku_desc,
                CONCAT(l.row_code, '-', LPAD(l.bay_num, 2, '0'), '-', LPAD(l.level_code, 2, '0'), '-', l.side) AS location_code,
                NULL AS running_balance
            FROM inventory_movements im
            JOIN sku s ON s.id = im.sku_id
            LEFT JOIN location l ON l.id = im.loc_id
            WHERE im.sku_id = ?
            ORDER BY im.created_at DESC, im.id DESC
            LIMIT 10
        ");
        $stmt->bind_param('i', $history_sku_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $sku_history_records[] = $r;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if ($DEBUG) {
            error_log('[invSku history fetch] ' . $e->getMessage());
        }
    }
}

/* -------------------- AJAX OUT -------------------- */
$title = 'SKU -> All Locations';
$page_class = 'page-inv-sku';

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'query' => $q,
        'rows' => $rows,
        'totals' => $totals_by_sku,
        'path' => $path_used,
        'sku_exists' => $sku_exists,
        'sku_status' => $sku_status,
    ]);
    exit;
}

/* -------------------- RENDER -------------------- */
ob_start();
?>
<style>
    .movement-type {
        padding: 2px 8px;
        border-radius: 999px;
        font-weight: 700;
        font-size: .8rem;
    }

    .movement-in {
        background: rgba(22, 163, 74, .12);
    }

    .movement-out {
        background: rgba(220, 38, 38, .12);
    }

    .movement-adjust,
    .movement-transfer,
    .movement-unknown {
        background: rgba(14, 165, 233, .12);
    }

    .positive-change {
        color: #16a34a;
        font-weight: 700;
    }

    .negative-change {
        color: #dc2626;
        font-weight: 700;
    }
</style>
<h2 class="title">SKU -> All Locations</h2>

<section class="card card--pad">
    <form method="get" action="" id="searchForm">
        <div class="flex flex-wrap items-end" style="gap:16px;">
            <div style="flex:1 1 360px; min-width:260px; position:relative;">
                <label for="q" class="label">Search SKU # or description - or type a location code</label>
                <input type="text" id="q" name="q" value="<?= h($q) ?>"
                    placeholder="e.g. 20240007, hinge, or R10-01-11-F" class="input" autofocus />
                <div id="suggestBox" class="suggest-list" style="display:none;"></div>
            </div>

            <div style="display:flex; gap:8px;">
                <button class="btn btn--primary" type="submit">Search</button>
                <?php if ($q !== ""): ?>
                    <a class="btn btn-outline" href="?">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <div id="searchStatus" class="text-muted" style="display:none; margin-top:8px;">Search in progress...</div>
</section>

<?php if ($q !== ""): ?>
    <?php if ($shopify_product !== null || $shopify_product_error !== null): ?>
        <section class="card card--pad" style="margin-bottom:16px;">
            <h3 style="margin:0 0 10px;">Product</h3>
            <?php if ($shopify_product !== null): ?>
                <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap; justify-content:space-between;">
                    <div style="display:flex; gap:16px; align-items:flex-start; flex:1 1 420px; min-width:300px;">
                        <?php if (!empty($shopify_product['image_src'])): ?>
                            <div style="flex:0 0 160px;">
                                <img id="shopifyProductImage" src="<?= h($shopify_product['image_src']) ?>"
                                    alt="<?= h($shopify_product['title'] ?: $shopify_product['sku']) ?>"
                                    style="max-width:160px; width:100%; height:auto; border:1px solid #eee; border-radius:4px;">
                            </div>
                        <?php endif; ?>
                        <div style="flex:1 1 auto; min-width:0;">
                            <div style="font-weight:600;"><?= h($shopify_product['title'] ?: $shopify_product['sku']) ?></div>
                            <div class="text-muted" style="margin:4px 0 8px;">SKU <?= h($shopify_product['sku']) ?></div>
                            <?php if (!empty($shopify_product['description'])): ?>
                                <p style="margin:0;"><?= h($shopify_product['description']) ?></p>
                            <?php else: ?>
                                <p class="text-muted" style="margin:0;">No description available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($total_on_hand_for_display !== null): ?>
                        <div
                            style="flex:0 0 200px; min-width:180px; background:#f7f9fb; border:1px solid #e1e7ef; border-radius:10px; padding:12px; text-align:center;">
                            <div class="text-muted" style="font-size:13px; letter-spacing:0.03em;">Total On-Hand</div>
                            <div style="font-size:30px; font-weight:700; color:#0f5132; margin-top:4px;">
                                <?= (int) $total_on_hand_for_display ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <script>
                    console.info('Shopify product (from invSku.php):', <?= json_encode($shopify_product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
                </script>
                <div id="shopifyImageModal"
                    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.72); align-items:center; justify-content:center; padding:2vw; z-index:9999;">
                    <div
                        style="max-width:80vw; max-height:80vh; width:80vw; height:80vh; display:flex; align-items:center; justify-content:center;">
                        <img id="shopifyImageModalImg" src="" alt="Shopify product large view"
                            style="max-width:100%; max-height:100%; width:100%; height:100%; object-fit:contain; background:#fff; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.35);">
                    </div>
                </div>
                <script>
                    (function () {
                        const thumb = document.getElementById('shopifyProductImage');
                        const modal = document.getElementById('shopifyImageModal');
                        const modalImg = document.getElementById('shopifyImageModalImg');
                        if (!thumb || !modal || !modalImg) return;

                        const open = () => {
                            if (!thumb.src) return;
                            modalImg.src = thumb.src;
                            modal.style.display = 'flex';
                            document.body.style.overflow = 'hidden';
                        };
                        const close = () => {
                            modal.style.display = 'none';
                            modalImg.src = '';
                            document.body.style.overflow = '';
                        };

                        thumb.style.cursor = 'zoom-in';
                        thumb.addEventListener('click', open);
                        modal.addEventListener('click', (e) => {
                            if (e.target === modal) close();
                        });
                        document.addEventListener('keydown', (e) => {
                            if (e.key === 'Escape' && modal.style.display !== 'none') close();
                        });
                    })();
                </script>
            <?php else: ?>
                <p class="text-muted" style="margin:0;"><?= h((string) $shopify_product_error) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($shelf_form_sku !== ''): ?>
        <section class="card card--pad" style="margin-bottom:16px;">
            <form id="shelfForm">
                <input type="hidden" name="sku_num" value="<?= h($shelf_form_sku) ?>">
                <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                    <div style="flex:1 1 280px; min-width:240px;">
                        <label for="shelfValue" class="label">Shelf Location for SKU <?= h($shelf_form_sku) ?></label>
                        <input type="text" id="shelfValue" name="shelf_value" class="input" maxlength="120"
                            value="<?= h($shelf_form_value) ?>" placeholder="e.g., Shelf A3, Back Wall" autocomplete="off" />
                    </div>
                    <div>
                        <button type="submit" class="btn btn--primary" <?= $can_save_shelf ? '' : 'disabled' ?>>Save</button>
                    </div>
                </div>
                <div id="shelfStatus" class="text-muted" style="display:none; margin-top:8px;"></div>
            </form>
        </section>
    <?php endif; ?>

    <section class="card card--pad" style="margin-bottom:16px;">
        <?php if (!$rows && $shelf_form_value === ''): ?>
            <p class="text-muted">No matching locations with on-hand &gt; 0.</p>
        <?php else: ?>
            <h3 style="margin:0 0 8px;">SKU Locations</h3>

            <?php if ($shelf_form_value !== ''): ?>
                <div style="margin-bottom:12px; padding:8px 12px; background:#fff8f0; border-left:3px solid #ff8c42; border-radius:4px;">
                    <span style="color:#c0392b; font-size:0.9rem;">
                        <strong style="color:#ff8c42;">■</strong> Orange row = Shelf location
                    </span>
                </div>
            <?php endif; ?>

            <div class="table-wrap">
                <table class="table" id="location-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Description</th>
                            <th>Location</th>
                            <th style="text-align:right;">On-Hand</th>
                            <th>Last Manual Count</th>
                            <th>Manual Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($shelf_form_value !== ''): ?>
                            <?php
                            $shelf_locations = preg_split('/[&,;]/', $shelf_form_value);
                            $shelf_locations = array_map('trim', $shelf_locations);
                            $shelf_locations = array_filter($shelf_locations);
                            foreach ($shelf_locations as $idx => $shelf_loc):
                            ?>
                            <tr style="background-color:#ff8c42; color:#fff; font-weight:600; border-top:<?= $idx === 0 ? '2px solid #e67e22' : 'none' ?>;">
                                <td>
                                    <span style="color:#fff;"><?= h($shelf_form_sku) ?></span>
                                </td>
                                <td>
                                    <?= isset($totals_by_sku[$shelf_form_sku]) ? h($totals_by_sku[$shelf_form_sku]['desc']) : '—' ?>
                                </td>
                                <td>
                                    <span style="font-weight:600;"><?= h($shelf_loc) ?></span>
                                </td>
                                <td style="text-align:right; color:#fff;">
                                    —
                                </td>
                                <td>
                                    <?= !empty($shelf_last_manual_count_at)
                                        ? h(date('M j, Y', strtotime((string) $shelf_last_manual_count_at)))
                                        : 'Never' ?>
                                </td>
                                <td>
                                    <?= $shelf_manual_count_total > 0 ? number_format($shelf_manual_count_total) : '—' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!$rows && $shelf_form_value !== ''): ?>
                            <tr style="background-color:#f5f5f5;">
                                <td colspan="6" style="text-align:center; padding:12px; color:#666; font-style:italic;">
                                    No warehouse inventory — shelf location shown above for reference.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r):
                            $href_location = "take.php?" . http_build_query([
                                "row_code" => $r["row_code"],
                                "bay_num" => $r["bay_num"],
                                "level_code" => $r["level_code"],
                                "side" => $r["side"],
                                "return" => "invSku.php?" . http_build_query(["q" => $q]),
                            ]);
                            $href_sku = "?sku_q=" . urlencode((string) $r["sku_num"]);
                            $req_qty = (int) ($r['open_req_qty'] ?? 0);
                            $is_high = (int) ($r['has_priority'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <a class="link" href="<?= h($href_sku) ?>"><?= h((string) $r["sku_num"]) ?></a>
                                    <?= getRequestBadge($req_qty, $is_high) ?>
                                </td>
                                <td>
                                    <?= h($r["sku_desc"]) ?>
                                    <?php if (!empty($r["expiry_date"])): ?>
                                        <?php
                                        $exp_date = (string) $r["expiry_date"];
                                        $today_dt = new DateTime('today');
                                        $exp_dt = new DateTime($exp_date);
                                        $days_until = (int) $today_dt->diff($exp_dt)->format('%r%a');
                                        if ($days_until <= 90) {
                                            $badge_class = "badge lot-exp-badge--danger";
                                        } elseif ($days_until <= 180) {
                                            $badge_class = "badge lot-exp-badge--warn";
                                        } else {
                                            $badge_class = "badge lot-exp-badge--ok";
                                        }
                                        ?>
                                        <span class="<?= h($badge_class) ?> ms-2 lot-exp-badge">Lot exp: <?= h(
                                               $exp_date,
                                           ) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $location_label = $r['location_label'] ?? $r['location_code']; ?>
                                    <a class="link" href="<?= h($href_location) ?>"><?= h($location_label) ?></a>
                                </td>
                                <td style="text-align:right;"><?= (int) $r["on_hand"] ?></td>
                                <td><?= !empty($r["last_manual_count_at"])
                                    ? h(date('M j, Y', strtotime((string) $r["last_manual_count_at"])))
                                    : 'Never' ?></td>
                                <td><?= number_format((int) ($r["last_manual_count_total"] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($history_sku !== ''): ?>
    <section class="card card--pad" style="margin-bottom:16px;">
        <div style="margin-bottom:8px;">
            <h3 style="margin:0;">History for Movements for SKU: <?= h($history_sku) ?></h3>
            <div class="text-muted">Total Records: <?= (int) $sku_history_total ?></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Location</th>
                        <th>SKU</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th style="text-align:right">Change</th>
                        <th style="text-align:right">Balance</th>
                        <th>Reference</th>
                        <th>User Name</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$sku_history_records): ?>
                        <tr>
                            <td colspan="9" class="muted">No movement records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sku_history_records as $rec): ?>
                            <?php
                            $created = $rec['created_at'] ?? null;
                            $locationCode = (string) ($rec['location_code'] ?? '');
                            $skuVal = h($rec['sku'] ?? '');
                            $skuDesc = h($rec['sku_desc'] ?? '');
                            $ref = h($rec['reference'] ?? '');
                            $userName = h($rec['user_name'] ?? '—');
                            $typeRaw = strtoupper((string) ($rec['movement_type'] ?? ''));
                            $typeNorm = ($typeRaw === 'ADJUSTMENT') ? 'ADJUST' : $typeRaw;
                            switch ($typeNorm) {
                                case 'IN':
                                    $typeClass = 'movement-in';
                                    break;
                                case 'OUT':
                                    $typeClass = 'movement-out';
                                    break;
                                case 'ADJUST':
                                    $typeClass = 'movement-adjust';
                                    break;
                                case 'TRANSFER':
                                    $typeClass = 'movement-transfer';
                                    break;
                                default:
                                    $typeClass = 'movement-unknown';
                                    break;
                            }
                            $delta = (int) ($rec['signed_delta'] ?? 0);
                            $balance = $rec['running_balance'];
                            ?>
                            <tr>
                                <td><?= $created ? h(date('Y-m-d H:i', strtotime((string) $created))) : '—' ?></td>
                                <td><?= h($locationCode !== '' ? $locationCode : '—') ?></td>
                                <td><?= $skuVal ?></td>
                                <td><?= $skuDesc ?></td>
                                <td><span class="movement-type <?= h($typeClass) ?>"><?= h($typeNorm) ?></span></td>
                                <td style="text-align:right" class="<?= $delta > 0 ? 'positive-change' : 'negative-change' ?>"><?= (int) $delta ?></td>
                                <td style="text-align:right">
                                    <?php if ($balance !== NULL): ?>
                                        <strong><?= number_format((int) $balance) ?></strong>
                                    <?php else: ?>
                                        <span class="muted">N/A*</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $ref ?></td>
                                <td><?= $userName ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<script>
    function setupTableFilter(filterInputId, tableId, columnIndex) {
        const filterInput = document.getElementById(filterInputId);
        const tableBody = document.querySelector(`#${tableId} tbody`);
        if (!filterInput || !tableBody) return;

        const run = () => {
            const needle = filterInput.value.toLowerCase();
            const rows = tableBody.querySelectorAll('tr');
            let any = false;
            rows.forEach(row => {
                const cell = row.querySelector(`td:nth-child(${columnIndex + 1})`);
                if (!cell) return;
                const ok = (cell.textContent || '').toLowerCase().includes(needle);
                row.style.display = ok ? '' : 'none';
                if (ok) any = true;
            });
            const noId = `${tableId}-no-results`;
            let noRow = document.getElementById(noId);
            if (!any) {
                if (!noRow) {
                    noRow = document.createElement('tr');
                    noRow.id = noId;
                    noRow.innerHTML = '<td colspan="6" class="text-muted" style="text-align:center; padding:12px;">No matching locations found.</td>';
                    tableBody.appendChild(noRow);
                }
                noRow.style.display = '';
            } else if (noRow) {
                noRow.remove();
            }
        };

        filterInput.addEventListener('input', run);
        run();
    }

    document.addEventListener('DOMContentLoaded', () => {
        const locationTable = document.getElementById('location-table');
        if (locationTable) setupTableFilter('locationFilterInput', 'location-table', 2);

        const searchForm = document.getElementById('searchForm');
        const searchStatus = document.getElementById('searchStatus');
        if (searchForm && searchStatus) {
            searchForm.addEventListener('submit', () => {
                searchStatus.textContent = 'Search in progress...';
                searchStatus.style.display = 'block';
            });
        }

        const shelfForm = document.getElementById('shelfForm');
        const shelfStatus = document.getElementById('shelfStatus');
        const shelfInput = document.getElementById('shelfValue');
        const shelfSkuInput = shelfForm ? shelfForm.querySelector('input[name=\"sku_num\"]') : null;
        if (shelfForm && shelfStatus && shelfInput && shelfSkuInput) {
            shelfForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const params = new URLSearchParams();
                params.set('shelf_update', '1');
                params.set('sku_num', shelfSkuInput.value);
                params.set('shelf_value', shelfInput.value.trim());
                shelfStatus.style.display = 'block';
                shelfStatus.style.color = '';
                shelfStatus.textContent = 'Saving...';
                try {
                    const res = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: params.toString()
                    });
                    const data = await res.json();
                    if (!res.ok || !data.ok) {
                        throw new Error(data.err || 'Unable to save shelf.');
                    }
                    shelfStatus.textContent = 'Saved.';
                } catch (err) {
                    shelfStatus.textContent = err?.message || 'Unable to save shelf.';
                    shelfStatus.style.color = '#c0392b';
                }
            });
        }
    });
</script>

<script>
    /* ---------- Typeahead on the main search (#q) -----------
     - Fetches SKUs and Location codes in parallel (server-side LIKE)
     - Click SKU -> fill input & submit
     - Click Location -> navigate to place.php with parsed parts
    ----------------------------------------------------------*/
    (function () {
        const input = document.getElementById('q');
        const form = document.getElementById('searchForm');
        const box = document.getElementById('suggestBox');
        if (!input || !box) return;

        let ticket = 0, sel = -1;

        function hide() { box.style.display = 'none'; box.innerHTML = ''; sel = -1; }
        function show() { box.style.display = 'block'; }

        function buildUrl(params) {
            const url = new URL(window.location.href);
            for (const [k, v] of Object.entries(params)) {
                if (v === null) url.searchParams.delete(k);
                else url.searchParams.set(k, String(v));
            }
            return url.toString();
        }

        function render(skus, locs) {
            box.innerHTML = '';
            sel = -1;
            const frag = document.createDocumentFragment();

            if (Array.isArray(skus) && skus.length) {
                const g = document.createElement('div'); g.className = 'suggest-group';
                const title = document.createElement('div'); title.className = 'suggest-title'; title.textContent = 'SKUs';
                g.appendChild(title);
                skus.forEach((r, i) => {
                    const it = document.createElement('div'); it.className = 'suggest-item'; it.dataset.kind = 'sku'; it.dataset.index = String(i);
                    const sku = document.createElement('span'); sku.className = 'sku-chip'; sku.textContent = r.sku_num;
                    it.appendChild(sku);
                    if (r.desc) { const d = document.createElement('span'); d.style.color = '#6c757d'; d.textContent = r.desc; it.appendChild(d); }
                    it.addEventListener('mousedown', (e) => { e.preventDefault(); choose({ kind: 'sku', value: r.sku_num }); });
                    g.appendChild(it);
                });
                frag.appendChild(g);
            }

            if (Array.isArray(locs) && locs.length) {
                const g = document.createElement('div'); g.className = 'suggest-group';
                const title = document.createElement('div'); title.className = 'suggest-title'; title.textContent = 'Locations';
                g.appendChild(title);
                locs.forEach((r, i) => {
                    const it = document.createElement('div'); it.className = 'suggest-item'; it.dataset.kind = 'loc'; it.dataset.index = String(i);
                    const code = document.createElement('span'); code.className = 'loc-chip'; code.textContent = r.code;
                    g.appendChild(it);
                    it.appendChild(code);
                    it.addEventListener('mousedown', (e) => { e.preventDefault(); choose({ kind: 'loc', value: r }); });
                });
                frag.appendChild(g);
            }

            if (!frag.children.length) { hide(); return; }
            box.appendChild(frag);
            show();
        }

        function choose(selObj) {
            if (!selObj) return;
            if (selObj.kind === 'sku') {
                input.value = selObj.value;
                hide();
                // When selecting a SKU from the search box, use the standard form submit (partial match)
                if (form.requestSubmit) form.requestSubmit(); else form.submit();
            } else if (selObj.kind === 'loc') {
                const r = selObj.value; // {code,row_code,bay_num,level_code,side}
                const params = {
                    row_code: r.row_code,
                    bay_num: r.bay_num,
                    level_code: r.level_code,
                    side: r.side
                };
                const qs = new URLSearchParams(params).toString();
                window.location.href = 'place.php?' + qs;
            }
        }

        async function fetchSuggest(kind, q, myTicket) {
            const url = buildUrl({ suggest: '1', type: kind, q, ajax: null, debug: null, path: null, sku_q: null });
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (ticket !== myTicket) return null; // stale
            return Array.isArray(data.items) ? data.items : [];
        }

        function looksLikeLocation(q) {
            // heuristic: contains at least two dashes like "R10-01" or ends with "-F"/"-B"
            return /-/.test(q) || /^[A-Za-z]+\d{0,}-/.test(q);
        }

        // No query on typing; just drop any open/pending suggestions
        input.addEventListener('input', () => {
            ++ticket;
            hide();
        });

        input.addEventListener('blur', () => setTimeout(hide, 150));

        // Keyboard navigation
        input.addEventListener('keydown', (e) => {
            if (box.style.display === 'none') return;
            const items = Array.from(box.querySelectorAll('.suggest-item'));
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                sel = (sel + 1) % items.length;
                items.forEach(el => el.classList.remove('active'));
                items[sel].classList.add('active');
                items[sel].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                sel = (sel - 1 + items.length) % items.length;
                items.forEach(el => el.classList.remove('active'));
                items[sel].classList.add('active');
                items[sel].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                if (sel >= 0) {
                    e.preventDefault();
                    const el = items[sel];
                    if (el.dataset.kind === 'sku') {
                        choose({ kind: 'sku', value: el.querySelector('.sku-chip')?.textContent || '' });
                    } else {
                        // rebuild loc object from text (we stored full record in list via closure on mousedown;
                        // for Enter we re-fetch the code and split)
                        const code = el.querySelector('.loc-chip')?.textContent || '';
                        const parts = code.split('-');
                        if (parts.length === 4) {
                            choose({ kind: 'loc', value: { code, row_code: parts[0], bay_num: parts[1], level_code: parts[2], side: parts[3] } });
                        }
                    }
                }
            } else if (e.key === 'Escape') {
                hide();
            }
        });
    })();
</script>

<?php
$content = ob_get_clean();
$js = isset($js) && is_array($js) ? $js : [];
$js[] = "/js/print_widget.js?v=1";

include __DIR__ . "/templates/layout.php";
