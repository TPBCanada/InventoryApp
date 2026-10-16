<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/templates/access_control.php';
require_once __DIR__ . '/dbusers.php';

header('Content-Type: application/json');

$send = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $send(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$postedToken = $_POST['csrf_token'] ?? '';
if (!$sessionToken || !$postedToken || !hash_equals($sessionToken, $postedToken)) {
    $send(403, ['success' => false, 'message' => 'Invalid session token.']);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? '';
if ($userId <= 0 && $username === '') {
    $send(401, ['success' => false, 'message' => 'You must be signed in to change your password.']);
}

$current = $_POST['current_password'] ?? '';
$new = $_POST['new_password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

if ($current === '' || $new === '' || $confirm === '') {
    $send(400, ['success' => false, 'message' => 'Please fill out all password fields.']);
}
if ($new !== $confirm) {
    $send(400, ['success' => false, 'message' => 'New passwords do not match.']);
}
if (strlen($new) < 8) {
    $send(400, ['success' => false, 'message' => 'Password must be at least 8 characters.']);
}

// Ensure we have a DB connection (some hosts may not keep $conn populated here)
$dbConn = $conn ?? null;
if (!$dbConn instanceof mysqli) {
    $dbuserFallbacks = [
        __DIR__ . '/dbusers.php',
        dirname(__DIR__) . '/dbusers.php',
    ];
    foreach ($dbuserFallbacks as $path) {
        if (is_file($path)) {
            require $path;
            if (isset($conn) && $conn instanceof mysqli) {
                $dbConn = $conn;
                break;
            }
        }
    }
}

if (!$dbConn instanceof mysqli) {
    error_log('[change_password] missing DB connection');
    $send(500, ['success' => false, 'message' => 'Database connection is not available.']);
}

// Fetch the current user row
if ($userId > 0) {
    $stmt = $dbConn->prepare('SELECT id, password FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
} else {
    $stmt = $dbConn->prepare('SELECT id, password FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
}

$dbId = 0;
$dbHash = '';

if (!$stmt || !$stmt->execute()) {
    if ($stmt instanceof mysqli_stmt) {
        error_log('[change_password] failed to fetch user: ' . $dbConn->error);
        $stmt->close();
    }
    $send(500, ['success' => false, 'message' => 'Unable to verify your account right now.']);
}

$stmt->store_result();
$stmt->bind_result($dbId, $dbHash);
$hasRow = $stmt->fetch();
$stmt->close();

if (!$hasRow || $dbId <= 0) {
    $send(404, ['success' => false, 'message' => 'Account not found.']);
}

if (!password_verify($current, $dbHash)) {
    $send(400, ['success' => false, 'message' => 'Current password is incorrect.']);
}
if (password_verify($new, $dbHash)) {
    $send(400, ['success' => false, 'message' => 'Choose a password different from your current one.']);
}

$newHash = password_hash($new, PASSWORD_DEFAULT);
$update = $dbConn->prepare('UPDATE users SET password = ? WHERE id = ?');
if (!$update) {
    error_log('[change_password] failed to prepare update: ' . $dbConn->error);
    $send(500, ['success' => false, 'message' => 'Could not save your new password. Please try again.']);
}
$update->bind_param('si', $newHash, $dbId);

if (!$update->execute()) {
    error_log('[change_password] update failed: ' . $update->error);
    $update->close();
    $send(500, ['success' => false, 'message' => 'Could not save your new password. Please try again.']);
}

$update->close();
session_regenerate_id(true);

$send(200, ['success' => true, 'message' => 'Password updated successfully.']);
