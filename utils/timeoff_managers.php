<?php
// Returns a JSON list of manager users for the time-off calendar.
header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../dbusers.php';

if (!$conn instanceof mysqli || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database not available']);
    exit;
}

/** * --- ORIGINAL LOGIC (Commented out for future use) ---
 * $managerRoleIds = [2]; 
 * $placeholders = implode(',', array_fill(0, count($managerRoleIds), '?'));
 * $types = str_repeat('i', count($managerRoleIds));
 * $stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM users WHERE role_id IN ($placeholders) AND email IS NOT NULL AND email != '' ORDER BY first_name, last_name");
 * $stmt->bind_param($types, ...$managerRoleIds);
 */

// --- CURRENT LOGIC: Restrict to specific Admin record (ID: 1) ---
$targetId = 2;
$stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ? LIMIT 1");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
    exit;
}

$stmt->bind_param('i', $targetId);
$stmt->execute();
$res = $stmt->get_result();

$managers = [];
while ($row = $res->fetch_assoc()) {
    $nameParts = array_filter([$row['first_name'] ?? '', $row['last_name'] ?? '']);
    $name = trim(implode(' ', $nameParts));
    $managers[] = [
        'id' => (int) $row['id'],
        'name' => $name ?: 'Ray', 
        'email' => $row['email']
    ];
}

$stmt->close();

echo json_encode(['ok' => true, 'managers' => $managers]);