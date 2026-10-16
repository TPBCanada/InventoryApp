<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';


$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}
// require_once __DIR__ . '/templates/auth_guard.php';
// app_require_login();

if (!isset($_SESSION['username'], $_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function inventory_count_ensure_location_history(mysqli $conn): bool
{
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS sku_location_counts (
            sku_id INT NOT NULL,
            loc_id INT NOT NULL,
            counted_qty INT UNSIGNED NOT NULL,
            counted_at DATETIME NOT NULL,
            counted_by INT NOT NULL,
            PRIMARY KEY (sku_id, loc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $e) {
        error_log('[inventoryCounts location history] ' . $e->getMessage());
        return false;
    }
}

$locationHistoryAvailable = inventory_count_ensure_location_history($conn);
$locationPk = detect_location_pk($conn);

$user_id = (int) $_SESSION['user_id'];

if (empty($_SESSION['inventory_count_csrf'])) {
    $_SESSION['inventory_count_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = (string) $_SESSION['inventory_count_csrf'];

function inventory_count_redirect(string $type, string $message): never
{
    $_SESSION['inventory_count_flash'] = ['type' => $type, 'message' => $message];

    $query = [];
    if (isset($_POST['return_q']) && trim((string) $_POST['return_q']) !== '') {
        $query['q'] = trim((string) $_POST['return_q']);
    }

    $location = 'invCount.php';
    if ($query !== []) {
        $location .= '?' . http_build_query($query);
    }

    header('Location: ' . $location);
    exit;
}

function inventory_count_validate_csrf(): void
{
    $posted = (string) ($_POST['csrf_token'] ?? '');
    $stored = (string) ($_SESSION['inventory_count_csrf'] ?? '');
    if ($stored === '' || !hash_equals($stored, $posted)) {
        http_response_code(419);
        exit('The form expired. Reload the page and try again.');
    }
}

/*
|--------------------------------------------------------------------------
| POST handling
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    inventory_count_validate_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_counts') {
        $posted = $_POST['counts'] ?? [];

        if (!is_array($posted)) {
            inventory_count_redirect('error', 'Invalid count data.');
        }

        // If submitting a single SKU, only process that SKU.
        if (isset($_POST['submit_sku_id'])) {
            $submitSkuId = filter_var(
                $_POST['submit_sku_id'],
                FILTER_VALIDATE_INT
            );

            if (
                !$submitSkuId ||
                $submitSkuId < 1 ||
                !isset($posted[$submitSkuId])
            ) {
                inventory_count_redirect('error', 'Invalid product.');
            }

            $posted = [
                $submitSkuId => $posted[$submitSkuId]
            ];
        }

        /*
         * Build one total manual count per SKU.
         *
         * counts[sku_id][loc_id] = quantity
         *
         * The SKU total goes to sku.last_manual_count_*; each location's
         * count is also kept in sku_location_counts for the per-location view.
         */
        $skuTotals = [];
        $locationCounts = [];

        foreach ($posted as $skuIdRaw => $locations) {
            $skuId = filter_var($skuIdRaw, FILTER_VALIDATE_INT);

            if (!$skuId || $skuId < 1 || !is_array($locations)) {
                continue;
            }

            foreach ($locations as $locIdRaw => $qtyRaw) {
                $qtyRaw = trim((string) $qtyRaw);

                // Blank input = not counted, ignore it.
                if ($qtyRaw === '') {
                    continue;
                }

                // Only allow whole numbers >= 0.
                if (!preg_match('/^\d+$/', $qtyRaw)) {
                    continue;
                }

                $skuTotals[$skuId] =
                    ($skuTotals[$skuId] ?? 0) + (int) $qtyRaw;

                $locId = filter_var($locIdRaw, FILTER_VALIDATE_INT);
                // loc 0 = shelf or "enter a count anyway" (no location on file).
                // loc > 0 = pallet location.
                if ($locId !== false && $locId >= 0) {
                    $locationCounts[] = [$skuId, $locId, (int) $qtyRaw];
                }
            }
        }

        if ($skuTotals === []) {
            inventory_count_redirect('warning', 'No counts were entered.');
        }

        try {
            $conn->begin_transaction();

            $skuUpdate = $conn->prepare(
                "UPDATE sku
                 SET
                    last_manual_count_total = ?,
                    last_manual_count_at = CURRENT_TIMESTAMP,
                    last_manual_count_by = ?
                 WHERE id = ?"
            );

            foreach ($skuTotals as $skuId => $total) {
                $skuUpdate->bind_param(
                    'iii',
                    $total,
                    $user_id,
                    $skuId
                );

                $skuUpdate->execute();

                if ($skuUpdate->affected_rows < 1) {
                    throw new RuntimeException(
                        "Manual count was not saved for SKU ID {$skuId}."
                    );
                }
            }

            $skuUpdate->close();

            if ($locationHistoryAvailable && $locationCounts !== []) {
                $locUpsert = $conn->prepare(
                    "INSERT INTO sku_location_counts
                        (sku_id, loc_id, counted_qty, counted_at, counted_by)
                     VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)
                     ON DUPLICATE KEY UPDATE
                        counted_qty = VALUES(counted_qty),
                        counted_at = VALUES(counted_at),
                        counted_by = VALUES(counted_by)"
                );

                foreach ($locationCounts as [$locSkuId, $locId, $locQty]) {
                    $locUpsert->bind_param('iiii', $locSkuId, $locId, $locQty, $user_id);
                    $locUpsert->execute();
                }

                $locUpsert->close();
            }

            $conn->commit();

            inventory_count_redirect('success', sprintf('Count saved for %d SKU%s.', count($skuTotals), count($skuTotals) === 1 ? '' : 's'));

        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable) {
            }

            error_log('[inventoryCounts save_counts] ' . $e->getMessage());

            inventory_count_redirect('error', 'The counts could not be saved.');
        }
    }

    inventory_count_redirect('error', 'Unknown action.');
}

/*
|--------------------------------------------------------------------------
| Read / search
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['inventory_count_flash'] ?? null;
unset($_SESSION['inventory_count_flash']);

$search = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(s.sku_num LIKE CONCAT('%', ?, '%') OR s.`desc` LIKE CONCAT('%', ?, '%'))";
    $params[] = $search;
    $params[] = $search;
    $types .= 'ss';
}

$sql = "
    SELECT
        s.id,
        s.sku_num,
        s.`desc`,
        s.shelf,
        s.last_manual_count_at,
        s.last_manual_count_total,
        COALESCE(SUM(CASE WHEN i.quantity > 0 THEN i.quantity ELSE 0 END), 0) AS system_total
    FROM sku s
    LEFT JOIN inventory i ON i.sku_id = s.id
";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= "
    GROUP BY s.id
    ORDER BY s.sku_num ASC
    LIMIT 300
";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$skus = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


$locationsBySku = [];
$skuIds = array_map(static fn(array $row): int => (int) $row['id'], $skus);

if ($skuIds !== []) {
    $placeholders = implode(',', array_fill(0, count($skuIds), '?'));
    $locTypes = str_repeat('i', count($skuIds));
    $lastCountSelect = $locationHistoryAvailable
        ? 'MAX(slc.counted_at) AS last_count_at, MAX(slc.counted_qty) AS last_manual_count'
        : 'NULL AS last_count_at, NULL AS last_manual_count';
    $lastCountJoin = $locationHistoryAvailable
        ? "LEFT JOIN sku_location_counts slc ON slc.sku_id = i.sku_id AND slc.loc_id = l.{$locationPk}"
        : '';

    $locSql = "
        SELECT
            i.sku_id,
            l.{$locationPk} AS loc_id,
            CONCAT(l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS loc_label,
            SUM(i.quantity) AS on_hand,
            {$lastCountSelect}
        FROM inventory i
        JOIN pallet p ON p.id = i.pallet_id
        JOIN location l ON l.{$locationPk} = p.loc_id
        {$lastCountJoin}
        WHERE i.quantity > 0
          AND i.sku_id IN ({$placeholders})
        GROUP BY i.sku_id, l.{$locationPk}
        ORDER BY l.row_code, l.bay_num, l.level_code, l.side
    ";

    $locStmt = $conn->prepare($locSql);
    $locStmt->bind_param($locTypes, ...$skuIds);
    $locStmt->execute();
    $locResult = $locStmt->get_result();
    while ($locRow = $locResult->fetch_assoc()) {
        $locationsBySku[(int) $locRow['sku_id']][] = $locRow;
    }
    $locStmt->close();

    $missingSkuIds = array_values(array_filter(
        $skuIds,
        static fn(int $skuId): bool => empty($locationsBySku[$skuId])
    ));

    if ($missingSkuIds !== []) {
        $fallbackPlaceholders = implode(',', array_fill(0, count($missingSkuIds), '?'));
        $fallbackTypes = str_repeat('i', count($missingSkuIds));
        $fallbackSql = "
            SELECT
                im.sku_id,
                l.{$locationPk} AS loc_id,
                CONCAT(l.row_code, '-', l.bay_num, '-', l.level_code, '-', l.side) AS loc_label,
                SUM(im.quantity_change) AS on_hand,
                {$lastCountSelect}
            FROM inventory_movements im
            JOIN location l ON l.{$locationPk} = im.loc_id
            {$lastCountJoin}
            WHERE im.sku_id IN ({$fallbackPlaceholders})
            GROUP BY im.sku_id, l.{$locationPk}
            HAVING SUM(im.quantity_change) > 0
            ORDER BY l.row_code, l.bay_num, l.level_code, l.side
        ";

        // The history join aliases the SKU source as `i`; use the movement alias here.
        $fallbackSql = str_replace('slc.sku_id = i.sku_id', 'slc.sku_id = im.sku_id', $fallbackSql);
        $fallbackStmt = $conn->prepare($fallbackSql);
        $fallbackStmt->bind_param($fallbackTypes, ...$missingSkuIds);
        $fallbackStmt->execute();
        $fallbackResult = $fallbackStmt->get_result();
        while ($fallbackRow = $fallbackResult->fetch_assoc()) {
            $locationsBySku[(int) $fallbackRow['sku_id']][] = $fallbackRow;
        }
        $fallbackStmt->close();

        foreach ($skus as &$sku) {
            $skuId = (int) $sku['id'];
            if (!empty($locationsBySku[$skuId]) && in_array($skuId, $missingSkuIds, true)) {
                $sku['system_total'] = array_sum(array_map(
                    static fn(array $location): int => (int) $location['on_hand'],
                    $locationsBySku[$skuId]
                ));
            }
        }
        unset($sku);
    }
}

// Add shelf locations as pseudo-locations for counting
$shelfCountIds = [];
try {
    $shelfCountSql = "SELECT sku_id, counted_at as last_count_at, counted_qty as last_manual_count
                      FROM sku_location_counts WHERE loc_id = 0
                      ORDER BY sku_id, counted_at DESC";
    $shelfCountResult = $conn->query($shelfCountSql);
    if ($shelfCountResult) {
        while ($row = $shelfCountResult->fetch_assoc()) {
            $skuId = (int) $row['sku_id'];
            if (!isset($shelfCountIds[$skuId])) {
                $shelfCountIds[$skuId] = $row;
            }
        }
    }
} catch (Throwable $e) {
    error_log('[inventoryCounts shelf counts fetch] ' . $e->getMessage());
}

foreach ($skus as &$sku) {
    $skuId = (int) $sku['id'];
    if (!empty($sku['shelf'])) {
        $shelfData = [
            'sku_id' => $skuId,
            'loc_id' => 0,
            'loc_label' => 'Shelf: ' . h((string) $sku['shelf']),
            'on_hand' => '—',
            'last_count_at' => $shelfCountIds[$skuId]['last_count_at'] ?? null,
            'last_manual_count' => $shelfCountIds[$skuId]['last_manual_count'] ?? null,
        ];
        $locationsBySku[$skuId][] = $shelfData;
    }
}
unset($sku);

$title = 'Inventory Counts';
$page_class = 'page-inventory-counts';

ob_start();
?>

<h2 class="title">Inventory Counts</h2>

<?php if (is_array($flash)): ?>
    <div class="count-flash count-flash--<?= h((string) ($flash['type'] ?? 'success')) ?>">
        <?= h((string) ($flash['message'] ?? '')) ?>
    </div>
<?php endif; ?>

<section class="card card--pad" style="margin-bottom:16px;">
    <form method="get" action="invCount.php">
        <div class="count-filter-grid">
            <div>
                <label class="label" for="q">Search SKU or description</label>
                <input class="input" id="q" name="q" value="<?= h($search) ?>" placeholder="SKU or product description"
                    autofocus>
            </div>

            <div style="display:flex;gap:10px;">
                <button class="btn btn--primary" type="submit">Search</button>
                <a class="btn btn-outline" href="invCount.php">Clear</a>
            </div>
        </div>
    </form>
</section>

<div class="count-toolbar">
    <span class="text-muted"><?= number_format(count($skus)) ?> result<?= count($skus) === 1 ? '' : 's' ?></span>

</div>

<?php if (!$skus): ?>
    <p class="text-muted">No products match your search.</p>
<?php else: ?>
    <form method="post" id="count-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
        <input type="hidden" name="return_q" value="<?= h($search) ?>">
        <input type="hidden" name="action" value="save_counts">

        <?php foreach ($skus as $sku): ?>
            <?php
            $skuId = (int) $sku['id'];
            $locations = $locationsBySku[$skuId] ?? [];
            $locationCount = count($locations);
            $manualCountTotal = (int) ($sku['last_manual_count_total'] ?? 0);

            $groupId = 'count-locations-' . $skuId;
            ?>
            <div class="count-sku-group">
                <div class="count-sku-header" role="button" tabindex="0" aria-expanded="false"
                    aria-controls="<?= h($groupId) ?>" onclick="toggleCountGroup('<?= h($groupId) ?>', this)"
                    onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleCountGroup('<?= h($groupId) ?>', this);}">
                    <div class="count-sku-header__main">
                        <span class="toggle-icon">+</span>
                        <div>
                            <div class="count-sku-header__title">
                                <?= h((string) $sku['sku_num']) ?>
                            </div>
                            <div class="count-sku-header__desc"><?= h((string) $sku['desc']) ?></div>
                        </div>
                    </div>
                    <div class="text-muted" style="text-align:right;">
                        <?= $locationCount ?> location<?= $locationCount === 1 ? '' : 's' ?>
                        &middot; On-hand <?= number_format((int) $sku['system_total']) ?>
                        <?php if ($sku['last_manual_count_at']): ?>
                            <br>Last count: <?= h(date('M j, Y g:i A', strtotime((string) $sku['last_manual_count_at']))) ?>
                            (<?= number_format((int) $sku['last_manual_count_total']) ?>)
                        <?php endif; ?>
                    </div>
                </div>

                <div id="<?= h($groupId) ?>" class="count-locations">
                    <?php if ($locationCount === 0): ?>
                        <div class="count-location-empty">
                            No active inventory location on file for this
                            SKU<?= !empty($sku['shelf']) ? ' (shelf: ' . h((string) $sku['shelf']) . ')' : '' ?>.
                            <div class="count-location-row" style="grid-template-columns:1fr 140px;padding-left:0;border-top:0;">
                                <div class="text-muted">Enter a count anyway:</div>
                                <div>
                                    <input class="input count-input" type="number" min="0" step="1" inputmode="numeric"
                                        placeholder="Count" name="counts[<?= $skuId ?>][0]">
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="count-location-row count-location-header" aria-hidden="true">
                            <div>Location</div>
                            <div>System On-hand</div>
                            <div>Last Count</div>
                            <div>Manual Count</div>
                            <div></div>
                        </div>
                        <?php foreach ($locations as $loc): ?>
                            <div class="count-location-row">
                                <div><strong><?= h((string) $loc['loc_label']) ?></strong></div>
                                <div class="text-muted">
                                    <?= is_numeric($loc['on_hand'])
                                        ? number_format((int) $loc['on_hand'])
                                        : h((string) $loc['on_hand']) ?>
                                </div>
                                <div class="text-muted">
                                    <?= $loc['last_count_at']
                                        ? h(date('M j, Y', strtotime((string) $loc['last_count_at'])))
                                        : 'Never' ?>
                                </div>
                                <div class="text-muted">
                                    <?= $loc['last_manual_count'] !== null
                                        ? number_format((int) $loc['last_manual_count'])
                                        : '&mdash;' ?>
                                </div>
                                <div>
                                    <input class="input count-input" type="number" min="0" step="1" inputmode="numeric"
                                        placeholder="Count" name="counts[<?= $skuId ?>][<?= (int) $loc['loc_id'] ?>]">
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="count-location-row count-location-totals">
                            <div><strong>Totals</strong></div>
                            <div>
                                <span class="count-location-total-label">System On-hand</span>
                                <strong class="count-location-total-value"><?= number_format((int) $sku['system_total']) ?></strong>
                            </div>
                            <div></div>
                            <div>
                                <span class="count-location-total-label">Manual Count</span>
                                <strong class="count-location-total-value"><?= number_format($manualCountTotal) ?></strong>
                            </div>
                            <div>
                                <button class="btn btn--primary count-submit count-row-submit" type="submit" name="submit_sku_id"
                                    value="<?= $skuId ?>" aria-label="Submit <?= h((string) $sku['sku_num']) ?> count"
                                    title="Submit count">&#10003;</button>
                            </div>
                        </div>

                    <?php endif; ?>

                </div>
            </div>
        <?php endforeach; ?>

        <!-- <div class="count-save-bar">
            <span class="text-muted" style="align-self:center;">Click a SKU to open its location count fields. Location
                totals are added together and saved as the SKU's count.</span>
            <button class="btn btn--primary" type="submit">Save counts</button>
        </div> -->
    </form>
<?php endif; ?>

<script nonce="<?= $cspNonceAttr ?>">
    function toggleCountGroup(groupId, trigger) {
        const group = document.getElementById(groupId);
        if (!group) return;

        const opening = !group.classList.contains('is-open');
        group.classList.toggle('is-open', opening);

        const icon = trigger ? trigger.querySelector('.toggle-icon') : null;
        if (icon) {
            icon.textContent = opening ? '−' : '+';
        }
        if (trigger) {
            trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
        }
    }

    const countForm = document.getElementById('count-form');
    if (countForm) {
        countForm.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.isComposing || !event.target.matches('.count-input')) {
                return;
            }
            event.preventDefault();
            if (event.repeat) return;
            const submitButton = event.target.closest('.count-sku-group').querySelector('.count-submit');
            countForm.requestSubmit(submitButton);
        });
    }

</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';