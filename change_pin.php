<?php
declare(strict_types=1);
/*
 * Set / change the signed-in user's 4-digit punch PIN (users.punch_pin_hash).
 * Verifies the current PIN, or the account password when no PIN is set yet.
 * Wrong attempts count toward users.punch_pin_failed_count and lock for a while.
 * Requires sql/2026_10_14_create_timeclock.sql (users.punch_pin_* columns).
 */
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/templates/access_control.php';
// Plain include: access_control.php already loaded dbusers.php inside a function, so require_once would skip it and leave $conn unset.
include __DIR__ . '/dbusers.php';

header('Content-Type: application/json');

const PIN_MAX_FAILED   = 5;
const PIN_LOCK_MINUTES = 15;

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
if ($userId <= 0) {
    $send(401, ['success' => false, 'message' => 'You must be signed in to change your PIN.']);
}

$current = (string) ($_POST['current_pin'] ?? '');
$new = (string) ($_POST['new_pin'] ?? '');
$confirm = (string) ($_POST['confirm_pin'] ?? '');

if ($current === '' || $new === '' || $confirm === '') {
    $send(400, ['success' => false, 'message' => 'Please fill out all PIN fields.']);
}
if (!preg_match('/^\d{4}$/', $new)) {
    $send(400, ['success' => false, 'message' => 'Your PIN must be exactly 4 digits.']);
}
if ($new !== $confirm) {
    $send(400, ['success' => false, 'message' => 'New PINs do not match.']);
}
if (in_array($new, ['0000', '1111', '1234', '4321', '9999'], true)) {
    $send(400, ['success' => false, 'message' => 'That PIN is too easy to guess. Choose another.']);
}

if (!isset($conn) || !$conn instanceof mysqli) {
    error_log('[change_pin] missing DB connection');
    $send(500, ['success' => false, 'message' => 'Database connection is not available.']);
}

try {
    $stmt = $conn->prepare(
        'SELECT password, punch_pin_hash, punch_pin_failed_count, punch_pin_locked_until > UTC_TIMESTAMP() AS is_locked
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->store_result();
    $row = null;
    $stmt->bind_result($pwHash, $pinHash, $failedCount, $isLocked); // bind_result: works without mysqlnd
    if ($stmt->fetch()) {
        $row = [
            'password'               => $pwHash,
            'punch_pin_hash'         => $pinHash,
            'punch_pin_failed_count' => $failedCount,
            'is_locked'              => $isLocked,
        ];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log('[change_pin] lookup failed: ' . $e->getMessage());
    $send(500, ['success' => false, 'message' => 'PINs are not set up in the database yet (run the time clock migration).']);
}

if (!$row) {
    $send(404, ['success' => false, 'message' => 'Account not found.']);
}
if ((int) $row['is_locked'] === 1) {
    $send(429, ['success' => false, 'message' => 'Too many wrong attempts. Try again in ' . PIN_LOCK_MINUTES . ' minutes.']);
}

// Existing PIN -> must match it; no PIN yet -> must match the account password.
$hasPin = !empty($row['punch_pin_hash']);
$verified = $hasPin
    ? password_verify($current, (string) $row['punch_pin_hash'])
    : password_verify($current, (string) $row['password']);

if (!$verified) {
    $failed = (int) $row['punch_pin_failed_count'] + 1;
    $lock = $failed >= PIN_MAX_FAILED;
    $upd = $conn->prepare(
        'UPDATE users SET punch_pin_failed_count = ?, punch_pin_locked_until = '
        . ($lock ? 'UTC_TIMESTAMP() + INTERVAL ' . PIN_LOCK_MINUTES . ' MINUTE' : 'punch_pin_locked_until')
        . ' WHERE id = ?'
    );
    $count = $lock ? 0 : $failed;
    $upd->bind_param('ii', $count, $userId);
    $upd->execute();
    $upd->close();

    $send(400, ['success' => false, 'message' => $lock
        ? 'Too many wrong attempts. Try again in ' . PIN_LOCK_MINUTES . ' minutes.'
        : ($hasPin ? 'Current PIN is incorrect.' : 'Account password is incorrect.')]);
}

if ($hasPin && password_verify($new, (string) $row['punch_pin_hash'])) {
    $send(400, ['success' => false, 'message' => 'Choose a PIN different from your current one.']);
}

$newHash = password_hash($new, PASSWORD_DEFAULT);
$upd = $conn->prepare(
    'UPDATE users SET punch_pin_hash = ?, punch_pin_set_at = UTC_TIMESTAMP(),
            punch_pin_failed_count = 0, punch_pin_locked_until = NULL
     WHERE id = ?'
);
$upd->bind_param('si', $newHash, $userId);
if (!$upd->execute()) {
    error_log('[change_pin] update failed: ' . $upd->error);
    $send(500, ['success' => false, 'message' => 'Could not save your PIN. Please try again.']);
}
$upd->close();

$send(200, ['success' => true, 'message' => $hasPin ? 'PIN updated.' : 'PIN set. You can now use it to punch in.']);
