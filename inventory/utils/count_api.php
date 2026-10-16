<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../dbinv.php';

if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Your session has expired.']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$userId = (int) $_SESSION['user_id'];

function count_api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function count_api_load_groups(mysqli $conn, int $userId): array
{
    $sql = "
        SELECT
            g.id AS group_id,
            g.group_name,
            g.status AS group_status,
            g.assigned_user_id,
            g.claimed_by_user_id,
            gi.id AS item_id,
            gi.status AS item_status,
            gi.observed_total,
            gi.completed_at,
            s.id AS sku_id,
            s.sku_num,
            s.`desc` AS sku_desc,
            COALESCE((
                SELECT GROUP_CONCAT(
                    DISTINCT CONCAT(
                        l.row_code, '-',
                        LPAD(l.bay_num, 2, '0'), '-',
                        LPAD(l.level_code, 2, '0'), '-',
                        l.side
                    )
                    ORDER BY l.row_code, CAST(l.bay_num AS UNSIGNED), l.level_code, l.side
                    SEPARATOR ', '
                )
                FROM inventory i
                JOIN pallet p ON p.id = i.pallet_id
                JOIN location l ON l.id = p.loc_id
                WHERE i.sku_id = s.id
                  AND i.quantity > 0
            ), '') AS locations
        FROM inventory_count_groups g
        JOIN inventory_count_group_items gi ON gi.group_id = g.id
        JOIN sku s ON s.id = gi.sku_id
        WHERE g.status IN ('OPEN', 'IN_PROGRESS')
          AND (
              g.assigned_user_id = ?
              OR g.claimed_by_user_id = ?
          )
        ORDER BY
            g.created_at ASC,
            FIELD(gi.status, 'PENDING', 'COMPLETED', 'SKIPPED'),
            s.sku_num ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    $groups = [];
    while ($row = $res->fetch_assoc()) {
        $groupId = (int) $row['group_id'];

        if (!isset($groups[$groupId])) {
            $groups[$groupId] = [
                'id' => $groupId,
                'group_name' => (string) ($row['group_name'] ?: ('Count block #' . $groupId)),
                'status' => (string) $row['group_status'],
                'items' => [],
            ];
        }

        $groups[$groupId]['items'][] = [
            'id' => (int) $row['item_id'],
            'sku_id' => (int) $row['sku_id'],
            'sku_num' => (string) $row['sku_num'],
            'description' => (string) $row['sku_desc'],
            'locations' => (string) $row['locations'],
            'status' => (string) $row['item_status'],
            'observed_total' => $row['observed_total'] === null ? null : (int) $row['observed_total'],
            'completed_at' => $row['completed_at'],
        ];
    }

    $stmt->close();
    return array_values($groups);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        count_api_json([
            'ok' => true,
            'groups' => count_api_load_groups($conn, $userId),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        count_api_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $expectedToken = app_csrf_token();

    if ($postedToken === '' || !hash_equals($expectedToken, $postedToken)) {
        count_api_json(['ok' => false, 'error' => 'The form expired. Reload the page and try again.'], 419);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action !== 'complete_item') {
        count_api_json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $rawObserved = trim((string) ($_POST['observed_total'] ?? ''));

    if (!$itemId || $itemId < 1) {
        count_api_json(['ok' => false, 'error' => 'Invalid count item.'], 400);
    }

    if ($rawObserved === '' || !preg_match('/^\d+$/', $rawObserved)) {
        count_api_json(['ok' => false, 'error' => 'Count must be a whole number of zero or greater.'], 422);
    }

    $observedTotal = (int) $rawObserved;

    $conn->begin_transaction();

    $stmt = $conn->prepare("
        SELECT
            gi.id,
            gi.group_id,
            gi.sku_id,
            gi.status,
            g.status AS group_status,
            g.assigned_user_id,
            g.claimed_by_user_id
        FROM inventory_count_group_items gi
        JOIN inventory_count_groups g ON g.id = gi.group_id
        WHERE gi.id = ?
        FOR UPDATE
    ");
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) {
        throw new RuntimeException('Count item not found.');
    }

    $assignedUserId = $item['assigned_user_id'] === null ? null : (int) $item['assigned_user_id'];
    $claimedUserId = $item['claimed_by_user_id'] === null ? null : (int) $item['claimed_by_user_id'];

    if ($assignedUserId !== $userId && $claimedUserId !== $userId) {
        count_api_json(['ok' => false, 'error' => 'This count block is not assigned to you.'], 403);
    }

    if (!in_array((string) $item['group_status'], ['OPEN', 'IN_PROGRESS'], true)) {
        throw new RuntimeException('This count block is no longer active.');
    }

    if ((string) $item['status'] !== 'PENDING') {
        throw new RuntimeException('This item has already been completed.');
    }

    $groupId = (int) $item['group_id'];
    $skuId = (int) $item['sku_id'];

    // Claim/start the block on the worker's first completed item.
    $stmt = $conn->prepare("
        UPDATE inventory_count_groups
        SET status = 'IN_PROGRESS',
            claimed_by_user_id = COALESCE(claimed_by_user_id, ?),
            claimed_at = COALESCE(claimed_at, CURRENT_TIMESTAMP)
        WHERE id = ?
          AND status IN ('OPEN', 'IN_PROGRESS')
    ");
    $stmt->bind_param('ii', $userId, $groupId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        UPDATE inventory_count_group_items
        SET status = 'COMPLETED',
            observed_total = ?,
            completed_by_user_id = ?,
            completed_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND status = 'PENDING'
    ");
    $stmt->bind_param('iii', $observedTotal, $userId, $itemId);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The count item changed before it could be saved.');
    }
    $stmt->close();

    // Keep the existing SKU-level manual-count summary in sync.
    $stmt = $conn->prepare("
        UPDATE sku
        SET count_required = 0,
            last_manual_count_at = CURRENT_TIMESTAMP,
            last_manual_count_by = ?,
            last_manual_count_total = ?,
            count_requested_at = NULL,
            count_requested_by = NULL
        WHERE id = ?
    ");
    $stmt->bind_param('iii', $userId, $observedTotal, $skuId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM inventory_count_group_items
        WHERE group_id = ?
          AND status = 'PENDING'
    ");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $pending = (int) ($stmt->get_result()->fetch_column() ?: 0);
    $stmt->close();

    if ($pending === 0) {
        $stmt = $conn->prepare("
            UPDATE inventory_count_groups
            SET status = 'COMPLETED',
                completed_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND status IN ('OPEN', 'IN_PROGRESS')
        ");
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();

    count_api_json([
        'ok' => true,
        'groups' => count_api_load_groups($conn, $userId),
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable) {
    }

    error_log('[inventory count user api] ' . $e->getMessage());
    count_api_json([
        'ok' => false,
        'error' => 'Unable to update the count right now.',
    ], 500);
}
