<?php
declare(strict_types=1);
/*
 * Forgot-to-punch-out alerts. Run from cron every 15 minutes (CLI only):
 *   /usr/local/bin/php /home2/xserqhmy/public_html/dev/utils/punch_alert_cron.php [--dry-run]
 *
 * For each open punch past its alert time (tc_late_status: 60 min after the scheduled shift end,
 * 10 h after punch-in if nothing is scheduled, or the end of a "Still working" snooze):
 *   - emails the employee (users.email), once per alert time, so a snooze re-arms it;
 *   - emails one summary of everyone newly alerted to managers (TC_MANAGER_ROLES).
 * Punches are never closed automatically; managers fix times on timesheet.php.
 *
 * On dev (/dev/ in the path) every email goes to PUNCH_ALERT_DEV_REDIRECT instead, with the real
 * recipient noted in the body, because dev and live share xserqhmy_users (real staff addresses).
 * --dry-run prints the emails and changes nothing.
 * State: punch_late_alerts (sql/2026_10_15_create_punch_late_alerts.sql).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const PUNCH_ALERT_DEV_REDIRECT = 'steve@turningpointbrands.ca'; // crontab MAILTO
const PUNCH_ALERT_APP_URL      = 'https://tpbwhse.com';

require_once __DIR__ . '/../dbusers.php'; // $conn -> xserqhmy_users
require_once __DIR__ . '/timeclock.php';

date_default_timezone_set(TC_TZ);
$dryRun = in_array('--dry-run', $argv ?? [], true);
$isDev  = strpos(__DIR__, '/dev/') !== false || substr(__DIR__, -4) === '/dev';
$appUrl = PUNCH_ALERT_APP_URL . ($isDev ? '/dev' : '');

function punch_alert_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

function punch_alert_fmt(?string $local): string
{
    return $local ? date('g:i A', strtotime($local)) : '';
}

/** Send (or print) one plain-text email; on dev, redirect to PUNCH_ALERT_DEV_REDIRECT. */
function punch_alert_mail(string $to, string $subject, string $body, bool $isDev, bool $dryRun): bool
{
    if ($isDev) {
        $body = "[DEV] This alert would have gone to: {$to}\n\n" . $body;
        $subject = '[DEV] ' . $subject;
        $to = PUNCH_ALERT_DEV_REDIRECT;
    }
    if ($dryRun) {
        echo "---- To: {$to}\nSubject: {$subject}\n\n{$body}\n----\n";
        return true;
    }
    $from = getenv('MAIL_FROM') ?: getenv('APP_MAIL_FROM') ?: 'no-reply@tpbwhse.com';
    $headers = ['From: ' . $from, 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

// Open punches with the employee's name and email
$open = [];
$res = $conn->query(
    "SELECT p.id, p.user_id, p.punched_in_at, u.username, u.first_name, u.last_name, u.email
     FROM punch_records p JOIN users u ON u.id = p.user_id
     WHERE p.punched_out_at IS NULL AND p.status = 'active'
     ORDER BY p.punched_in_at"
);
while ($row = $res->fetch_assoc()) {
    $open[] = $row;
}
$res->free();

$alerted = [];
foreach ($open as $p) {
    $punchId = (int) $p['id'];
    $userId  = (int) $p['user_id'];
    $inLocal = (string) tc_utc_to_local($p['punched_in_at']);
    $late    = tc_late_status($conn, $userId, $punchId, $inLocal);

    // Overdue, and not yet emailed for this alert time (a snooze moves dueAt past lastAlertAt).
    if (!$late['overdue'] || ($late['lastAlertAt'] !== null && $late['lastAlertAt'] >= $late['dueAt'])) {
        continue;
    }

    $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: (string) $p['username'];
    $p += ['name' => $name, 'in' => $inLocal, 'late' => $late];
    $alerted[] = $p;

    $email = trim((string) ($p['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $body = "Hi {$name},\n\n"
            . 'You are still clocked in since ' . punch_alert_fmt($inLocal) . ' on ' . date('D M j', strtotime($inLocal)) . '.'
            . ($late['shiftEnd'] ? ' Your shift ended at ' . punch_alert_fmt($late['shiftEnd']) . '.' : '') . "\n\n"
            . "If you've finished, open the app and punch out: {$appUrl}/dashboard.php\n"
            . "If you're still working, tap \"Still working\" on the banner in the app.\n\n"
            . "If you forgot to punch out earlier, tell your manager the time you left so they can fix your time card.\n";
        $ok = punch_alert_mail($email, 'Reminder: you are still clocked in', $body, $isDev, $dryRun);
        punch_alert_log(($ok ? 'emailed ' : 'FAILED to email ') . "{$name} <{$email}> (punch {$punchId})");
    } else {
        punch_alert_log("no email on file for {$name} (punch {$punchId}); managers only");
    }

    if (!$dryRun) {
        $stmt = $conn->prepare(
            'INSERT INTO punch_late_alerts (punch_id, last_alert_at, alert_count) VALUES (?, UTC_TIMESTAMP(), 1)
             ON DUPLICATE KEY UPDATE last_alert_at = UTC_TIMESTAMP(), alert_count = alert_count + 1'
        );
        $stmt->bind_param('i', $punchId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!$alerted) {
    punch_alert_log('no new alerts (' . count($open) . ' open punch(es))');
    exit(0);
}

// One summary to managers
$lines = [];
foreach ($alerted as $p) {
    $l = $p['late'];
    $lines[] = '- ' . $p['name'] . ': in since ' . punch_alert_fmt($p['in']) . ' ' . date('D M j', strtotime($p['in']))
        . ($l['shiftEnd'] ? ', shift ended ' . punch_alert_fmt($l['shiftEnd']) : ', no shift scheduled')
        . ($l['snoozedUntil'] ? ' (said "still working" until ' . punch_alert_fmt($l['snoozedUntil']) . ')' : '');
}
$body = "These employees are still clocked in past their alert time:\n\n" . implode("\n", $lines) . "\n\n"
    . "Nothing has been changed. If someone forgot to punch out, fix their time card on Timesheets:\n"
    . "{$appUrl}/timesheet.php\n";

$roles = implode(',', array_map('intval', TC_MANAGER_ROLES));
$res = $conn->query("SELECT email FROM users WHERE role_id IN ($roles) AND email IS NOT NULL AND email <> ''");
$managers = [];
while ($row = $res->fetch_assoc()) {
    if (filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
        $managers[] = $row['email'];
    }
}
$res->free();

if (!$managers) {
    punch_alert_log('no manager email addresses on file');
} elseif ($isDev) {
    $ok = punch_alert_mail(implode(', ', $managers), count($alerted) . ' employee(s) still clocked in', $body, $isDev, $dryRun);
    punch_alert_log(($ok ? 'sent' : 'FAILED') . ' manager summary (dev redirect)');
} else {
    foreach ($managers as $to) {
        $ok = punch_alert_mail($to, count($alerted) . ' employee(s) still clocked in', $body, $isDev, $dryRun);
        punch_alert_log(($ok ? 'sent' : 'FAILED') . " manager summary to {$to}");
    }
}
