<?php
// Simple email trigger for time-off requests.
// Expects JSON POST: {start, end, note?, recipientEmail}

header('Content-Type: application/json');

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$start = isset($data['start']) ? $data['start'] : null;
$end = isset($data['end']) ? $data['end'] : null;
$note = isset($data['note']) ? trim($data['note']) : '';
$recipientEmail = isset($data['recipientEmail']) ? trim($data['recipientEmail']) : '';

if (!$start || !$end || !$recipientEmail) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid recipient email']);
    exit;
}

$fromEmail = getenv('TIMEOFF_FROM_EMAIL') ?: 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
$subject = 'Time-off request submitted';

// Build message lines
$userName = isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown user';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$roleId = isset($_SESSION['role_id']) ? $_SESSION['role_id'] : null;

$lines = [
    'A new time-off request was submitted.',
    'User: ' . $userName . ($userId ? " (ID: {$userId})" : ''),
    'Role ID: ' . ($roleId !== null ? $roleId : 'N/A'),
    'Start: ' . $start,
    'End: ' . $end,
];

if ($note !== '') {
    $lines[] = 'Note: ' . $note;
}

$lines[] = 'Submitted at: ' . date('c');

$message = implode("\r\n", $lines);

$headers = [
    'From: ' . $fromEmail,
    'Reply-To: ' . $fromEmail,
    'X-Mailer: PHP/' . phpversion()
];

$sent = mail($recipientEmail, $subject, $message, implode("\r\n", $headers));

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to send email']);
}
