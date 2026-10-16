<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';

if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}

$username = (string) ($_SESSION['username'] ?? '');
$role_id = (int) ($_SESSION['role_id'] ?? 0);

require_once __DIR__ . '/templates/access_control.php';

if (!isset($conn) || !$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

function boxUsageTableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return (bool) $cache[$table];
    }
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        $cache[$table] = false;
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    $cache[$table] = $exists;
    return $exists;
}

function boxUsageNormalizeDimensions(string $dimensions): string
{
    $dimensions = trim($dimensions);
    if ($dimensions === '') {
        return '';
    }
    $dimensions = preg_replace('/\s+/', ' ', $dimensions) ?? $dimensions;
    $dimensions = str_replace('X', 'x', $dimensions);
    $dimensions = preg_replace('/\s*x\s*/i', 'x', $dimensions) ?? $dimensions;
    return strtolower(trim($dimensions));
}

/**
 * @return array{length:?float,width:?float,height:?float}
 */
function boxUsageParseDimensions(string $dimensions): array
{
    $normalized = boxUsageNormalizeDimensions($dimensions);
    if ($normalized === '') {
        return ['length' => null, 'width' => null, 'height' => null];
    }
    $parts = array_values(array_filter(array_map('trim', explode('x', $normalized)), static fn($value) => $value !== ''));
    if (count($parts) < 2 || count($parts) > 3) {
        return ['length' => null, 'width' => null, 'height' => null];
    }
    foreach ($parts as $part) {
        if (!is_numeric($part)) {
            return ['length' => null, 'width' => null, 'height' => null];
        }
    }
    return [
        'length' => round((float) $parts[0], 2),
        'width' => round((float) $parts[1], 2),
        'height' => isset($parts[2]) ? round((float) $parts[2], 2) : null,
    ];
}

/**
 * @return array{supply_name:string,supply_category:string,inside_dimensions:string}|null
 */
function boxUsageResolveSupplyMatch(mysqli $conn, string $dimensions): ?array
{
    static $stageRows = null;
    if (!boxUsageTableExists($conn, 'warehouse_supplies_stage')) {
        return null;
    }
    if ($stageRows === null) {
        $stageRows = [];
        $result = $conn->query("
            SELECT supply_name, supply_category, inside_dimensions
            FROM warehouse_supplies_stage
            WHERE inside_dimensions IS NOT NULL
              AND inside_dimensions <> ''
        ");
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $row['inside_dimensions'] = trim((string) ($row['inside_dimensions'] ?? ''));
                if ($row['inside_dimensions'] === '') {
                    continue;
                }
                $stageRows[] = $row;
            }
            $result->free();
        }
    }

    $target = boxUsageNormalizeDimensions($dimensions);
    if ($target === '') {
        return null;
    }

    foreach ($stageRows as $row) {
        $candidate = boxUsageNormalizeDimensions((string) ($row['inside_dimensions'] ?? ''));
        if ($candidate === $target) {
            return [
                'supply_name' => trim((string) ($row['supply_name'] ?? '')),
                'supply_category' => trim((string) ($row['supply_category'] ?? '')),
                'inside_dimensions' => trim((string) ($row['inside_dimensions'] ?? '')),
            ];
        }
    }

    return null;
}

function boxUsageUsersTable(mysqli $conn): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $candidates = ['users', 'xserqhmy_users.users'];
    foreach ($candidates as $table) {
        try {
            $res = $conn->query("SELECT id FROM {$table} LIMIT 1");
            if ($res instanceof mysqli_result) {
                $res->free();
                $cached = $table;
                return $cached;
            }
            if ($res === true) {
                $cached = $table;
                return $cached;
            }
        } catch (Throwable $e) {
            // Try next candidate.
        }
    }
    $cached = false;
    return null;
}

function boxUsageResolveCurrentUserId(mysqli $conn): int
{
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($sessionUserId > 0) {
        return $sessionUserId;
    }
    $username = trim((string) ($_SESSION['username'] ?? ''));
    if ($username === '') {
        return 0;
    }
    $table = boxUsageUsersTable($conn);
    if ($table === null) {
        return 0;
    }
    $stmt = $conn->prepare("SELECT id FROM {$table} WHERE username = ? LIMIT 1");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return (int) ($row['id'] ?? 0);
}

function boxUsageSetFlash(string $type, string $message): void
{
    $_SESSION['box_usage_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function boxUsageRedirect(): never
{
    header('Location: box_usage.php');
    exit;
}

$trackerReady = boxUsageTableExists($conn, 'box_usage_tracker') && boxUsageTableExists($conn, 'box_usage_events');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = trim((string) ($_POST['action'] ?? ''));
    if (!$trackerReady) {
        boxUsageSetFlash('error', 'Tracker tables are missing. Run inventory/sql/2026_04_01_create_box_usage_tracker.sql first.');
        boxUsageRedirect();
    }

    if ($formAction === 'reset_tracker') {
        $trackerId = (int) ($_POST['tracker_id'] ?? 0);
        $resetNote = trim((string) ($_POST['reset_note'] ?? ''));
        if ($trackerId <= 0) {
            boxUsageSetFlash('error', 'Invalid tracker row.');
            boxUsageRedirect();
        }
        $currentUserId = boxUsageResolveCurrentUserId($conn);
        $resetStmt = $conn->prepare('
            UPDATE box_usage_tracker
            SET cycle_used_count = 0,
                reorder_count = reorder_count + 1,
                last_reset_by_user_id = ?,
                last_reset_note = ?,
                last_reset_at = NOW()
            WHERE id = ?
            LIMIT 1
        ');
        if (!$resetStmt) {
            boxUsageSetFlash('error', 'Unable to reset tracker row.');
            boxUsageRedirect();
        }
        $resetStmt->bind_param('isi', $currentUserId, $resetNote, $trackerId);
        $resetStmt->execute();
        $resetStmt->close();
        boxUsageSetFlash('success', 'Box size counter reset for the new reorder cycle.');
        boxUsageRedirect();
    }
}

$flash = $_SESSION['box_usage_flash'] ?? null;
unset($_SESSION['box_usage_flash']);

$trackerRows = [];
$eventRows = [];
$hasWarehouseSupplies = boxUsageTableExists($conn, 'warehouse_supplies_stage');
$summary = [
    'sizes' => 0,
    'cycle_used' => 0,
    'lifetime_used' => 0,
    'reorders' => 0,
];

if ($trackerReady) {
    $trackerRows = fetch_all($conn, '
        SELECT
            id,
            supply_id,
            dimensions_label,
            last_weight_label,
            cycle_used_count,
            lifetime_used_count,
            reorder_count,
            last_sales_order_number,
            last_box_identifier,
            last_used_at,
            last_reset_at,
            last_reset_note
        FROM box_usage_tracker
        ORDER BY last_used_at DESC, dimensions_label ASC
    ');

    if ($hasWarehouseSupplies) {
        foreach ($trackerRows as &$row) {
            $matchedSupply = boxUsageResolveSupplyMatch((mysqli) $conn, (string) ($row['dimensions_label'] ?? ''));
            $row['supply_name'] = (string) ($matchedSupply['supply_name'] ?? '');
            $row['supply_category'] = (string) ($matchedSupply['supply_category'] ?? '');
            $row['inside_dimensions'] = (string) ($matchedSupply['inside_dimensions'] ?? '');
        }
        unset($row);
    }

    foreach ($trackerRows as $row) {
        $summary['sizes'] += 1;
        $summary['cycle_used'] += (int) ($row['cycle_used_count'] ?? 0);
        $summary['lifetime_used'] += (int) ($row['lifetime_used_count'] ?? 0);
        $summary['reorders'] += (int) ($row['reorder_count'] ?? 0);
    }

    $eventRows = fetch_all($conn, '
        SELECT
            sales_order_number,
            box_identifier,
            dimensions_label,
            weight_label,
            created_at
        FROM box_usage_events
        ORDER BY created_at DESC
        LIMIT 50
    ');
}

$title = 'Box Usage Tracker';
$page_class = 'page-box-usage';
$css = ['/refactor_css/box_usage.css'];
ob_start();
?>
<section class="box-usage-shell">
    <header class="box-usage-header card">
        <div>
            <p class="box-usage-eyebrow">Packaging Tracker</p>
            <h1>Box Usage</h1>
            <p class="box-usage-subtitle">Track each box size by dimensions, increment usage from `verify_pick`, and reset the cycle counter whenever new supplies are reordered.</p>
        </div>
        <a class="btn btn--ghost" href="verify_pick.php">Back To Verify Pick</a>
    </header>

    <?php if ($flash): ?>
        <div class="box-usage-flash box-usage-flash--<?= htmlspecialchars((string) ($flash['type'] ?? 'info')) ?>">
            <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
        </div>
    <?php endif; ?>

    <?php if (!$trackerReady): ?>
        <section class="card box-usage-empty">
            <h2>Tracker Setup Required</h2>
            <p>The box usage tables are not in this database yet.</p>
            <p>Run <code>inventory/sql/2026_04_01_create_box_usage_tracker.sql</code>, then reload this page.</p>
        </section>
    <?php else: ?>
        <section class="box-usage-stats">
            <article class="card box-usage-stat">
                <span class="box-usage-stat__label">Tracked Sizes</span>
                <strong class="box-usage-stat__value"><?= (int) $summary['sizes'] ?></strong>
            </article>
            <article class="card box-usage-stat">
                <span class="box-usage-stat__label">Used This Cycle</span>
                <strong class="box-usage-stat__value"><?= (int) $summary['cycle_used'] ?></strong>
            </article>
            <article class="card box-usage-stat">
                <span class="box-usage-stat__label">Lifetime Used</span>
                <strong class="box-usage-stat__value"><?= (int) $summary['lifetime_used'] ?></strong>
            </article>
            <article class="card box-usage-stat">
                <span class="box-usage-stat__label">Reorder Resets</span>
                <strong class="box-usage-stat__value"><?= (int) $summary['reorders'] ?></strong>
            </article>
        </section>

        <section class="card box-usage-panel">
            <p class="box-usage-muted">
                <?php if ($hasWarehouseSupplies): ?>
                    Box sizes are linked automatically from <code>warehouse_supplies_stage</code> when dimensions match a printed label. This page stays focused on the running counter and reorder resets.
                <?php else: ?>
                    This page is focused on the running counter and reorder resets. If <code>warehouse_supplies_stage</code> is available in this environment, linked supply matching will turn on automatically.
                <?php endif; ?>
            </p>
        </section>

        <section class="card box-usage-panel">
                <div class="box-usage-panel__header">
                    <h2>Tracked Box Sizes</h2>
                    <span><?= count($trackerRows) ?> size(s)</span>
                </div>
                <div class="table-wrap box-usage-table-wrap">
                    <table class="table box-usage-table">
                        <thead>
                            <tr>
                                <th>Linked Supply</th>
                                <th>Dimensions</th>
                                <th>Cycle Used</th>
                                <th>Lifetime Used</th>
                                <th>Last SO</th>
                                <th>Last Box</th>
                                <th>Last Used</th>
                                <th>Reset</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trackerRows as $row): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($row['supply_name'])): ?>
                                            <div class="box-usage-muted"><?= htmlspecialchars((string) ($row['supply_name'] ?? '')) ?></div>
                                        <?php if (!empty($row['supply_category'])): ?>
                                            <div class="box-usage-muted"><?= htmlspecialchars((string) ($row['supply_category'] ?? '')) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="box-usage-muted">No linked supply</span>
                                    <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) ($row['dimensions_label'] ?? '')) ?></strong>
                                        <?php if (!empty($row['inside_dimensions'])): ?>
                                            <div class="box-usage-muted">Supply dims: <?= htmlspecialchars((string) $row['inside_dimensions']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['last_weight_label'])): ?>
                                            <div class="box-usage-muted"><?= htmlspecialchars((string) $row['last_weight_label']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int) ($row['cycle_used_count'] ?? 0) ?></td>
                                    <td><?= (int) ($row['lifetime_used_count'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['last_sales_order_number'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['last_box_identifier'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['last_used_at'] ?? '')) ?></td>
                                    <td>
                                        <form method="post" class="box-usage-reset-form">
                                            <input type="hidden" name="action" value="reset_tracker" />
                                            <input type="hidden" name="tracker_id" value="<?= (int) ($row['id'] ?? 0) ?>" />
                                            <input class="input" type="text" name="reset_note" placeholder="PO / reorder note" />
                                            <button type="submit" class="btn btn--ghost">Reset Cycle</button>
                                        </form>
                                        <?php if (!empty($row['last_reset_at'])): ?>
                                            <div class="box-usage-muted">Last reset: <?= htmlspecialchars((string) $row['last_reset_at']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                        <?php endforeach; ?>
                        <?php if ($trackerRows === []): ?>
                            <tr>
                                <td colspan="8" class="box-usage-empty-row">No box sizes tracked yet.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
        </section>

        <section class="card box-usage-panel">
            <div class="box-usage-panel__header">
                <h2>Recent Box Usage</h2>
                <span>Last <?= count($eventRows) ?> label event(s)</span>
            </div>
            <div class="table-wrap box-usage-table-wrap">
                <table class="table box-usage-table">
                    <thead>
                        <tr>
                            <th>Printed At</th>
                            <th>Sales Order</th>
                            <th>Box ID</th>
                            <th>Dimensions</th>
                            <th>Weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($row['sales_order_number'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($row['box_identifier'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($row['dimensions_label'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($row['weight_label'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($eventRows === []): ?>
                            <tr>
                                <td colspan="5" class="box-usage-empty-row">No box labels have been tracked yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';
