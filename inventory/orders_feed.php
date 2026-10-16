<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'unauthenticated',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';

function ordersFeedTableExists(mysqli $conn, string $table): bool
{
    $safeTable = preg_replace('/[^A-Za-z0-9_]+/', '', $table) ?? '';
    if ($safeTable === '') {
        return false;
    }

    $sql = "SHOW TABLES LIKE '" . $conn->real_escape_string($safeTable) . "'";
    $res = $conn->query($sql);
    if (!$res instanceof mysqli_result) {
        return false;
    }

    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}

function ordersFeedUsersTable(mysqli $conn): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached ?: null;
    }

    foreach (['users', 'xserqhmy_users.users'] as $table) {
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
            // Try the next candidate.
        }
    }

    $cached = false;
    return null;
}

function ordersFeedSortExpr(): string
{
    return "CAST(REPLACE(REPLACE(UPPER(order_number), 'SO', ''), ' ', '') AS UNSIGNED)";
}

function respondOrdersFeed(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit = max(1, min(250, $limit));

if (!ordersFeedTableExists($conn, 'pick_orders')) {
    respondOrdersFeed(['ok' => false, 'error' => 'pick_orders table not found'], 500);
}

$orderRows = [];
$fetchLimit = $limit + 1;
$sql = "
    SELECT id, order_number, status, source_file, assigned_user_id, updated_at, created_at
    FROM pick_orders
    WHERE (status IS NULL OR status NOT IN ('COMPLETED', 'COMPLETED_SHORT_SHIP'))
    ORDER BY
        CASE WHEN assigned_user_id IS NOT NULL AND assigned_user_id > 0 THEN 0 ELSE 1 END ASC,
        " . ordersFeedSortExpr() . " DESC,
        order_number DESC,
        id DESC
    LIMIT {$fetchLimit}
";

$res = $conn->query($sql);
if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
        $orderRows[] = $row;
    }
    $res->free();
} elseif ($res === false) {
    respondOrdersFeed(['ok' => false, 'error' => 'Unable to load sales orders'], 500);
}

$hasMore = count($orderRows) > $limit;
if ($hasMore) {
    $orderRows = array_slice($orderRows, 0, $limit);
}

$assignedIds = [];
foreach ($orderRows as $row) {
    $assignedId = (int) ($row['assigned_user_id'] ?? 0);
    if ($assignedId > 0) {
        $assignedIds[$assignedId] = true;
    }
}

$assignedLabels = [];
$usersTable = ordersFeedUsersTable($conn);
if ($usersTable !== null && $assignedIds !== []) {
    $idList = implode(',', array_map('intval', array_keys($assignedIds)));
    $usersSql = "
        SELECT id,
               username,
               COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))), ''), username) AS label
        FROM {$usersTable}
        WHERE id IN ({$idList})
    ";
    $usersRes = $conn->query($usersSql);
    if ($usersRes instanceof mysqli_result) {
        while ($user = $usersRes->fetch_assoc()) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId > 0) {
                $assignedLabels[$userId] = (string) ($user['label'] ?? $user['username'] ?? ('User #' . $userId));
            }
        }
        $usersRes->free();
    }
}

$orders = array_map(static function (array $row) use ($assignedLabels): array {
    $assignedId = (int) ($row['assigned_user_id'] ?? 0);
    return [
        'id' => (int) ($row['id'] ?? 0),
        'order_number' => (string) ($row['order_number'] ?? ''),
        'status' => strtoupper(trim((string) ($row['status'] ?? 'PENDING'))) ?: 'PENDING',
        'source_file' => (string) ($row['source_file'] ?? ''),
        'assigned_user_id' => $assignedId,
        'assigned_user_label' => $assignedId > 0
            ? ($assignedLabels[$assignedId] ?? ('User #' . $assignedId))
            : 'Unassigned',
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}, $orderRows);

respondOrdersFeed([
    'ok' => true,
    'generated_at' => gmdate('c'),
    'limit' => $limit,
    'count' => count($orders),
    'has_more' => $hasMore,
    'orders' => $orders,
]);
