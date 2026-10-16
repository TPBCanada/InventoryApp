<?php
header('Content-Type: application/json');
session_start();

include '../dbinv.php';

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role_id = (int) ($_SESSION['role_id'] ?? 0);
$is_manager = in_array($role_id, [1, 2], true);

$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');
$json = $body ? json_decode($body, true) : null;

// --- GET: Fetch from Database ---
if ($method === 'GET') {
    $sql = "SELECT t.*, u.username as createdByUsername, 
            CONCAT(u.first_name, ' ', u.last_name) as createdByName
            FROM timeoff_requests t
            LEFT JOIN xserqhmy_users.users u ON t.user_id = u.id";

    if (!$is_manager) {
        $sql .= " WHERE t.status = 'approved' OR t.user_id = $user_id";
    }

    $sql .= " ORDER BY t.start_date ASC";

    $result = $conn->query($sql);

    if (!$result) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $conn->error]);
        exit;
    }

    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = [
            'id' => $row['id'],
            'start' => $row['start_date'],
            'end' => $row['end_date'],
            'note' => $row['note'],
            'status' => $row['status'],

            'recurring' => $row['recurring'] ?? 'none',
            'recurringUntil' => $row['recurring_until'] ?? null,

            'createdById' => (int) $row['user_id'],
            'createdByUsername' => $row['createdByUsername'],
            'createdByName' => $row['createdByName']
        ];
    }

    echo json_encode(['ok' => true, 'requests' => $requests]);
    exit;
}

// --- POST: Update or Create ---
if ($method === 'POST') {
    if (!$json) {
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $action = $json['action'] ?? 'create';

    // UPDATE STATUS (Approve/Deny/Cancel)
    if ($action === 'status') {
        $id = (int) $json['id'];
        $status = $conn->real_escape_string($json['status']);

        // Security check: only manager can approve/deny, user can only cancel their own
        if (!$is_manager) {
            $checkSql = "SELECT user_id FROM timeoff_requests WHERE id = $id";
            $res = $conn->query($checkSql);
            $row = $res->fetch_assoc();
            if (!$row || (int) $row['user_id'] !== $user_id || $status !== 'cancelled') {
                echo json_encode(['ok' => false, 'error' => 'Forbidden']);
                exit;
            }
        }

        $sql = "UPDATE timeoff_requests SET status = '$status' WHERE id = $id";
        if ($conn->query($sql)) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => $conn->error]);
        }
        exit;
    }

    $start = $conn->real_escape_string($json['start']);
    $end = $conn->real_escape_string($json['end']);
    $note = $conn->real_escape_string($json['note'] ?? '');

    $recurring = strtolower(trim($json['recurring'] ?? 'none'));

    $allowedRecurring = ['none', 'weekly', 'monthly', 'yearly'];

    if (!in_array($recurring, $allowedRecurring, true)) {
        $recurring = 'none';
    }

    $recurring = $conn->real_escape_string($recurring);

    $recurringUntilRaw = trim($json['recurring_until'] ?? '');
    $recurringUntil = $recurringUntilRaw !== ''
        ? "'" . $conn->real_escape_string($recurringUntilRaw) . "'"
        : "NULL";

    $sql = "
    INSERT INTO timeoff_requests
    (
        user_id,
        start_date,
        end_date,
        note,
        status,
        recurring,
        recurring_until
    )
    VALUES
    (
        $user_id,
        '$start',
        '$end',
        '$note',
        'pending',
        '$recurring',
        $recurringUntil
    )
";

    if ($conn->query($sql)) {
        echo json_encode(['ok' => true, 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['ok' => false, 'error' => $conn->error]);
    }
    exit;
}