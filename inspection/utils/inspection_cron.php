<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
    session_start();
}

/**
 * Single worker for inspection finalization and cron retries.
 *
 * The finalize button posts here directly.
 * Bluehost cron can also call this file on a schedule to retry unsent items.
 */

function inspectionCronQueuePath(): string
{
    return __DIR__ . '/inspection_cron_queue.json';
}

function inspectionCronLogPath(): string
{
    return __DIR__ . '/inspection_cron.log';
}

function inspectionCronWriteLog(string $message): void
{
    @file_put_contents(
        inspectionCronLogPath(),
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function inspectionCronLoadQueue(): array
{
    $path = inspectionCronQueuePath();
    if (!is_file($path)) {
        return [];
    }

    $raw = (string) @file_get_contents($path);
    if (trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function inspectionCronSaveQueue(array $queue): void
{
    @file_put_contents(
        inspectionCronQueuePath(),
        json_encode(array_values($queue), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function inspectionCronNormalizeRecipients(mixed $recipients): array
{
    if (is_string($recipients)) {
        $recipients = preg_split('/[;,]+/', $recipients) ?: [];
    }

    if (!is_array($recipients)) {
        return [];
    }

    $out = [];
    foreach ($recipients as $recipient) {
        $email = '';
        $name = '';
        if (is_string($recipient)) {
            $email = trim($recipient);
        } elseif (is_array($recipient)) {
            $email = trim((string) ($recipient['email'] ?? ''));
            $name = trim((string) ($recipient['name'] ?? ''));
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[] = ['email' => $email, 'name' => $name];
        }
    }

    return $out;
}

function inspectionCronDefaultRecipients(array $payload): array
{
    // TODO(testing): hardcoded recipient override. Remove before go-live.
    return inspectionCronNormalizeRecipients('steve@turningpointbrands.ca');

    $recipientSource = $payload['recipient_email'] ?? $payload['email_to'] ?? $payload['notify_email'] ?? '';
    $recipients = inspectionCronNormalizeRecipients($recipientSource);
    if ($recipients !== []) {
        return $recipients;
    }

    $fallback = getenv('INSPECTION_EMAIL_TO') ?: getenv('APP_INSPECTION_EMAIL_TO') ?: '';
    return inspectionCronNormalizeRecipients($fallback);
}

function inspectionCronBuildJob(array $payload): array
{
    $templateId = trim((string) ($payload['template_id'] ?? ''));
    $inspectionId = trim((string) ($payload['inspection_id'] ?? $payload['id'] ?? ''));
    $inspectionType = trim((string) ($payload['inspection_type'] ?? $payload['type'] ?? 'Inspection'));
    $comments = trim((string) ($payload['comments'] ?? ''));
    $truckLabel = trim((string) ($payload['truck_label'] ?? ''));
    $signedAt = trim((string) ($payload['signed_at'] ?? $payload['finalized_at'] ?? date('Y-m-d H:i:s')));
    $sender = trim((string) ($_SESSION['username'] ?? $payload['inspector_name'] ?? 'Unknown'));

    $job = [
        'job_id' => bin2hex(random_bytes(8)),
        'created_at' => date('Y-m-d H:i:s'),
        'status' => 'pending',
        'attempts' => 0,
        'finalized' => true,
        'template_id' => $templateId,
        'inspection_id' => $inspectionId,
        'inspection_type' => $inspectionType,
        'inspector_name' => $sender,
        'truck_label' => $truckLabel,
        'comments' => $comments,
        'signed_at' => $signedAt,
        'recipient_email' => $payload['recipient_email'] ?? $payload['email_to'] ?? $payload['notify_email'] ?? '',
        'subject' => trim((string) ($payload['subject'] ?? ('Inspection report: ' . $inspectionType))),
        'body_text' => trim((string) ($payload['body_text'] ?? '')),
        'body_html' => trim((string) ($payload['body_html'] ?? '')),
        'report_json' => trim((string) ($payload['report_json'] ?? '')),
        'source' => 'finalize_button',
        'mail_from' => inspectionMailDefaultFrom(),
    ];

    foreach ($payload as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (substr($key, 0, strlen('item_status_')) === 'item_status_' || substr($key, 0, strlen('item_note_')) === 'item_note_') {
            $job[$key] = is_array($value) ? '' : trim((string) $value);
        }
    }

    return $job;
}

function inspectionCronBuildFallbackReport(array $job): array
{
    $report = [];
    $report['submitted_at'] = (string) ($job['signed_at'] ?? date('Y-m-d H:i:s'));
    $report['inspection_type'] = (string) ($job['inspection_type'] ?? 'Inspection');
    $report['comments'] = (string) ($job['comments'] ?? '');
    $report['recipient_email'] = (string) ($job['recipient_email'] ?? '');
    $report['items'] = [];

    $raw = trim((string) ($job['report_json'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $report['submitted_at'] = (string) ($decoded['submitted_at'] ?? $report['submitted_at']);
            $report['inspection_type'] = (string) ($decoded['inspection_type'] ?? $report['inspection_type']);
            $report['comments'] = (string) ($decoded['comments'] ?? $report['comments']);
            $report['recipient_email'] = (string) ($decoded['recipient_email'] ?? $report['recipient_email']);
            $items = $decoded['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $report['items'][] = [
                        'label' => trim((string) ($item['label'] ?? 'Item')),
                        'status_label' => trim((string) ($item['status_label'] ?? ($item['status'] ?? 'Unspecified'))),
                        'note' => trim((string) ($item['note'] ?? '')),
                        'photos' => is_array($item['photos'] ?? null)
                            ? array_values(array_filter(array_map(static fn($photo) => trim((string) $photo), $item['photos'])))
                            : [],
                    ];
                }
            }
        }
    }

    if ($report['items'] === []) {
        $items = [];
        foreach ($job as $key => $value) {
            if (!is_string($key) || substr($key, 0, strlen('item_status_')) !== 'item_status_') {
                continue;
            }
            $itemId = substr($key, strlen('item_status_'));
            $items[] = [
                'label' => 'Item ' . $itemId,
                'status_label' => trim((string) $value) !== '' ? trim((string) $value) : 'Unspecified',
                'note' => trim((string) ($job['item_note_' . $itemId] ?? '')),
                'photos' => [],
            ];
        }
        $report['items'] = $items;
    }

    return $report;
}

function inspectionCronBuildReportText(array $job): string
{
    $report = inspectionCronBuildFallbackReport($job);
    $lines = [];
    $lines[] = 'Inspection completed and signed.';
    $lines[] = '';
    $lines[] = 'Completed: ' . $report['submitted_at'];
    $lines[] = 'Type: ' . $report['inspection_type'];
    if (!empty($job['inspection_id'])) {
        $lines[] = 'Inspection ID: ' . $job['inspection_id'];
    }
    if (!empty($job['template_id'])) {
        $lines[] = 'Template ID: ' . $job['template_id'];
    }
    if (!empty($job['inspector_name'])) {
        $lines[] = 'Inspector: ' . $job['inspector_name'];
    }
    if (!empty($job['truck_label'])) {
        $lines[] = 'Lift Truck: ' . $job['truck_label'];
    }
    if ($report['recipient_email'] !== '') {
        $lines[] = 'Recipient: ' . $report['recipient_email'];
    }
    if ($report['comments'] !== '') {
        $lines[] = '';
        $lines[] = 'Comments:';
        $lines[] = $report['comments'];
    }

    $lines[] = '';
    $lines[] = 'Items:';
    foreach ($report['items'] as $item) {
        $lines[] = '- ' . ($item['label'] ?: 'Item') . ' [' . ($item['status_label'] ?: 'Unspecified') . ']';
        if (!empty($item['note'])) {
            $lines[] = '  Note: ' . $item['note'];
        }
        if (!empty($item['photos'])) {
            $lines[] = '  Photos: ' . implode(', ', $item['photos']);
        }
    }

    return implode("\n", $lines);
}

function inspectionCronBuildReportHtml(array $job): string
{
    $report = inspectionCronBuildFallbackReport($job);
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rows = '';
    foreach ($report['items'] as $item) {
        $details = '';
        if (!empty($item['note'])) {
            $details .= '<div style="margin-top:4px;color:#4b5563;">Note: ' . $escape($item['note']) . '</div>';
        }
        if (!empty($item['photos'])) {
            $details .= '<div style="margin-top:4px;color:#6b7280;">Photos: ' . $escape(implode(', ', $item['photos'])) . '</div>';
        }
        $rows .= '<tr>'
            . '<td style="padding:10px;border:1px solid #d1d5db;vertical-align:top;">' . $escape($item['label'] ?: 'Item') . '</td>'
            . '<td style="padding:10px;border:1px solid #d1d5db;vertical-align:top;">' . $escape($item['status_label'] ?: 'Unspecified') . '</td>'
            . '<td style="padding:10px;border:1px solid #d1d5db;vertical-align:top;">' . $details . '</td>'
            . '</tr>';
    }

    $recipientLine = $report['recipient_email'] !== '' ? '<p><strong>Recipient:</strong> ' . $escape($report['recipient_email']) . '</p>' : '';
    $commentsBlock = $report['comments'] !== ''
        ? '<div style="margin:16px 0;padding:12px;border-left:4px solid #0ea5e9;background:#f8fafc;"><strong>Comments</strong><div style="margin-top:6px;white-space:pre-wrap;">' . $escape($report['comments']) . '</div></div>'
        : '';

    return '<div style="font-family:Arial,sans-serif;color:#111827;line-height:1.45;">'
        . '<h2 style="margin:0 0 12px;">Inspection completed</h2>'
        . '<p><strong>Completed:</strong> ' . $escape($report['submitted_at']) . '</p>'
        . '<p><strong>Type:</strong> ' . $escape($report['inspection_type']) . '</p>'
        . (!empty($job['inspection_id']) ? '<p><strong>Inspection ID:</strong> ' . $escape((string) $job['inspection_id']) . '</p>' : '')
        . (!empty($job['template_id']) ? '<p><strong>Template ID:</strong> ' . $escape((string) $job['template_id']) . '</p>' : '')
        . (!empty($job['inspector_name']) ? '<p><strong>Inspector:</strong> ' . $escape((string) $job['inspector_name']) . '</p>' : '')
        . (!empty($job['truck_label']) ? '<p><strong>Lift Truck:</strong> ' . $escape((string) $job['truck_label']) . '</p>' : '')
        . $recipientLine
        . $commentsBlock
        . '<table style="width:100%;border-collapse:collapse;margin-top:16px;">'
        . '<thead><tr>'
        . '<th style="padding:10px;border:1px solid #d1d5db;text-align:left;background:#f3f4f6;">Item</th>'
        . '<th style="padding:10px;border:1px solid #d1d5db;text-align:left;background:#f3f4f6;">Status</th>'
        . '<th style="padding:10px;border:1px solid #d1d5db;text-align:left;background:#f3f4f6;">Details</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '</table>'
        . '</div>';
}

function inspectionCronRenderText(array $job): string
{
    $lines = [];
    $lines[] = 'Inspection finalized and signed.';
    $lines[] = '';
    if (!empty($job['inspection_id'])) {
        $lines[] = 'Inspection ID: ' . $job['inspection_id'];
    }
    if (!empty($job['template_id'])) {
        $lines[] = 'Template ID: ' . $job['template_id'];
    }
    if (!empty($job['inspection_type'])) {
        $lines[] = 'Type: ' . $job['inspection_type'];
    }
    if (!empty($job['inspector_name'])) {
        $lines[] = 'Inspector: ' . $job['inspector_name'];
    }
    if (!empty($job['truck_label'])) {
        $lines[] = 'Lift Truck: ' . $job['truck_label'];
    }
    if (!empty($job['signed_at'])) {
        $lines[] = 'Signed at: ' . $job['signed_at'];
    }
    if (!empty($job['comments'])) {
        $lines[] = '';
        $lines[] = 'Comments:';
        $lines[] = $job['comments'];
    }

    return implode("\n", $lines);
}

function inspectionMailDefaultFrom(): string
{
    $configured = getenv('MAIL_FROM') ?: getenv('APP_MAIL_FROM') ?: '';
    if ($configured !== '') {
        return $configured;
    }
    // Same fallback as utils/timeoff_email.php; the host's own domain passes the server's sender checks.
    $host = preg_replace('/^www\./i', '', (string) ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    return 'no-reply@' . ($host !== '' ? $host : 'localhost');
}

/**
 * Reads the job's saved photos (paths relative to /inspection, from save_inspection.php).
 * Returns [attachments, skippedCount]; stops adding once the total would pass the size cap.
 */
function inspectionCronLoadAttachments(array $job): array
{
    $maxTotalBytes = 15 * 1024 * 1024;
    $mediaRoot = realpath(dirname(__DIR__) . '/uploads/checklist-media');
    $attachments = [];
    $skipped = 0;
    $total = 0;

    foreach ((array) ($job['attachments'] ?? []) as $media) {
        if (!is_array($media) || $mediaRoot === false) {
            continue;
        }
        $path = realpath(dirname(__DIR__) . '/' . ltrim((string) ($media['path'] ?? ''), '/'));
        if ($path === false || strpos($path, $mediaRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            continue;
        }
        $size = (int) filesize($path);
        if ($total + $size > $maxTotalBytes) {
            $skipped++;
            continue;
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            continue;
        }
        $total += $size;
        $attachments[] = [
            'item_id' => (string) ($media['item_id'] ?? ''),
            'name' => (string) ($media['name'] ?? basename($path)),
            'mime' => (string) ($media['mime'] ?? 'application/octet-stream'),
            'data' => $data,
        ];
    }

    return [$attachments, $skipped];
}

function inspectionCronSendMail(array $job): array
{
    $recipients = inspectionCronDefaultRecipients($job);
    if ($recipients === []) {
        return ['ok' => false, 'error' => 'No recipient configured.'];
    }

    $subject = trim((string) ($job['subject'] ?? 'Inspection finalized'));
    $bodyText = trim((string) ($job['body_text'] ?? ''));
    $bodyHtml = trim((string) ($job['body_html'] ?? ''));
    if ($bodyText === '' && $bodyHtml === '') {
        $bodyText = inspectionCronBuildReportText($job);
        $bodyHtml = inspectionCronBuildReportHtml($job);
    }

    [$attachments, $skipped] = inspectionCronLoadAttachments($job);
    if ($skipped > 0) {
        $skipNote = $skipped . ' more photo(s) were too large to attach. View them on the inspection dashboard.';
        $bodyText .= ($bodyText !== '' ? "\n\n" : '') . $skipNote;
        if ($bodyHtml !== '') {
            $bodyHtml .= '<p style="color:#6b7280;">' . htmlspecialchars($skipNote, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }

    $from = trim((string) ($job['mail_from'] ?? '')) ?: inspectionMailDefaultFrom();
    $replyTo = getenv('MAIL_REPLY_TO') ?: getenv('APP_MAIL_REPLY_TO') ?: $from;
    $contentType = $bodyHtml !== '' ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';
    $message = $bodyHtml !== '' ? $bodyHtml : $bodyText;
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
    ];

    if ($attachments === []) {
        $headers[] = 'Content-Type: ' . $contentType;
    } else {
        // Embed photos in the HTML (cid: images) so they show in the item's Details cell.
        if ($bodyHtml === '') {
            $bodyHtml = '<div style="font-family:Arial,sans-serif;white-space:pre-wrap;">'
                . htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $unplaced = '';
        foreach ($attachments as $index => $attachment) {
            $cid = 'photo' . $index . '.' . bin2hex(random_bytes(6)) . '@inspection';
            $attachments[$index]['cid'] = $cid;
            $img = '<img src="cid:' . $cid . '" alt="' . htmlspecialchars($attachment['name'], ENT_QUOTES, 'UTF-8') . '"'
                . ' style="display:block;width:100%;max-width:320px;height:auto;margin-top:6px;border:1px solid #d1d5db;border-radius:4px;">';
            $marker = '/<div data-photo-item="' . preg_quote($attachment['item_id'], '/') . '"[^>]*>.*?<\/div>/s';
            $placed = 0;
            if ($attachment['item_id'] !== '') {
                $bodyHtml = (string) preg_replace($marker, $img, $bodyHtml, 1, $placed);
            }
            if ($placed === 0) {
                $unplaced .= $img;
            }
        }
        if ($unplaced !== '') {
            $bodyHtml .= '<h3 style="font-family:Arial,sans-serif;margin:20px 0 4px;">Photos</h3>' . $unplaced;
        }

        $boundary = 'insp_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/related; type="text/html"; boundary="' . $boundary . '"';
        $message = "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($bodyHtml));
        foreach ($attachments as $attachment) {
            $name = str_replace(['"', "\r", "\n"], '', $attachment['name']);
            $message .= "--{$boundary}\r\n"
                . "Content-Type: {$attachment['mime']}; name=\"{$name}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-ID: <{$attachment['cid']}>\r\n"
                . "Content-Disposition: inline; filename=\"{$name}\"\r\n\r\n"
                . chunk_split(base64_encode($attachment['data']));
        }
        $message .= "--{$boundary}--\r\n";
    }

    $errors = [];
    $sent = 0;
    foreach ($recipients as $recipient) {
        $to = $recipient['email'];
        $ok = @mail($to, $subject, $message, implode("\r\n", $headers));
        if ($ok) {
            $sent++;
            continue;
        }
        $errors[] = $to;
    }

    return $sent > 0
        ? ['ok' => true, 'sent' => $sent, 'error' => '']
        : ['ok' => false, 'error' => 'Unable to send mail to: ' . implode(', ', $errors)];
}

function inspectionCronEnqueue(array $job): void
{
    $queue = inspectionCronLoadQueue();
    $queue[] = $job;
    inspectionCronSaveQueue($queue);
}

function inspectionCronProcessQueue(): array
{
    $queue = inspectionCronLoadQueue();
    if ($queue === []) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0];
    }

    $remaining = [];
    $sent = 0;
    $failed = 0;

    foreach ($queue as $job) {
        if (!is_array($job) || empty($job['finalized'])) {
            continue;
        }

        $result = inspectionCronSendMail($job);
        if (!empty($result['ok'])) {
            $sent++;
            continue;
        }

        $failed++;
        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        $job['last_error'] = (string) ($result['error'] ?? 'Unknown error');
        $remaining[] = $job;
    }

    inspectionCronSaveQueue($remaining);

    return [
        'processed' => count($queue),
        'sent' => $sent,
        'failed' => $failed,
    ];
}

function runInspectionCron(): array
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
    $isCli = PHP_SAPI === 'cli';

    if ($isCli) {
        $result = inspectionCronProcessQueue();
        inspectionCronWriteLog('cron processed=' . $result['processed'] . ' sent=' . $result['sent'] . ' failed=' . $result['failed']);
        return $result;
    }

    $raw = file_get_contents('php://input');
    $payload = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    $payload = array_merge($_POST, $payload);

    if (isset($_SESSION['csrf_token']) && !empty($payload['csrf_token']) && !hash_equals((string) $_SESSION['csrf_token'], (string) $payload['csrf_token'])) {
        http_response_code(403);
        $response = ['ok' => false, 'error' => 'Invalid CSRF token.'];
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $response;
        }
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $response['error'];
        return $response;
    }

    if ($method === 'POST' && !empty($payload)) {
        $job = inspectionCronBuildJob($payload);
        $sendResult = inspectionCronSendMail($job);
        if (empty($sendResult['ok'])) {
            $job['attempts'] = 1;
            $job['last_error'] = (string) ($sendResult['error'] ?? 'Unknown error');
            inspectionCronEnqueue($job);
        }

        if (!empty($sendResult['ok'])) {
            $response = [
                'ok' => true,
                'message' => 'Inspection finalized and email sent.',
                'sent' => $sendResult['sent'] ?? 0,
            ];
        } else {
            $response = [
                'ok' => true,
                'message' => 'Inspection finalized. Email queued for retry.',
                'error' => $sendResult['error'] ?? 'Unknown error',
            ];
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $response;
        }

        $redirect = rtrim((string) (defined('APP_ROOT_URL') ? APP_ROOT_URL : ''), '/') . '/inspection/inspection.php';
        if (!empty($payload['type'])) {
            $redirect .= '?type=' . rawurlencode((string) $payload['type']);
        }

        if (!headers_sent()) {
            header('Location: ' . $redirect);
            exit;
        }

        echo $response['message'];
        return $response;
    }

    $result = inspectionCronProcessQueue();
    inspectionCronWriteLog('web processed=' . $result['processed'] . ' sent=' . $result['sent'] . ' failed=' . $result['failed']);

    header('Content-Type: text/plain; charset=UTF-8');
    echo 'processed=' . $result['processed'] . ' sent=' . $result['sent'] . ' failed=' . $result['failed'];
    return $result;
}

// Only run when executed directly (web request or cron), not when included by save_inspection.php.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    runInspectionCron();
}
