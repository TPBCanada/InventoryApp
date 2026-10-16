<?php
declare(strict_types=1);
/*
 * Time clock helpers shared by utils/punch_api.php, utils/timesheet_data.php,
 * change_pin.php and templates/navbar.php.
 *
 * Tables (xserqhmy_users, see sql/2026_10_14_create_timeclock.sql):
 *   users.punch_pin_*        4-digit punch PIN (hashed) + lockout counters
 *   punch_records            one row per shift; times stored in UTC
 *   punch_record_audit       manager changes
 *   timeclock_sites          geofences
 */

const TC_TZ               = 'America/New_York';
const TC_MANAGER_ROLES    = [1, 2];
const TC_INACTIVE_ROLE    = 9;
const TC_PIN_MAX_FAILED   = 5;
const TC_PIN_LOCK_MINUTES = 15;

// Forgot-to-punch-out alerts (utils/punch_alert_cron.php, banner in js/punch-clock.js)
const TC_LATE_GRACE_MINUTES     = 60;  // after the scheduled shift end
const TC_LATE_UNSCHEDULED_HOURS = 10;  // no schedule that day: after this long on the clock
const TC_LATE_SNOOZE_MINUTES    = 120; // "Still working" pushes the alert this far out

// ---------------------------------------------------------------------------
// Time: DB stores UTC 'Y-m-d H:i:s'; the app shows America/New_York.
// ---------------------------------------------------------------------------

function tc_utc_to_local(?string $utc): ?string
{
    if ($utc === null || $utc === '') {
        return null;
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone(TC_TZ))->format('Y-m-d H:i:s');
}

function tc_local_to_utc(string $local): string
{
    return (new DateTimeImmutable($local, new DateTimeZone(TC_TZ)))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

/** Signed-in user's id (session user_id, else looked up by username). 0 if unknown. */
function tc_session_user_id(mysqli $conn): int
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id > 0 || empty($_SESSION['username'])) {
        return $id;
    }
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $username = (string) $_SESSION['username'];
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($found);
    $id = $stmt->fetch() ? (int) $found : 0;
    $stmt->close();
    if ($id > 0) {
        $_SESSION['user_id'] = $id;
    }
    return $id;
}

function tc_is_manager(): bool
{
    return in_array((int) ($_SESSION['role_id'] ?? 0), TC_MANAGER_ROLES, true);
}

// ---------------------------------------------------------------------------
// Punch PIN
// ---------------------------------------------------------------------------

/** True if the user has set a punch PIN (users.punch_pin_hash). */
function tc_user_has_pin(mysqli $conn, int $userId): bool
{
    $stmt = $conn->prepare('SELECT punch_pin_hash IS NOT NULL AND punch_pin_hash <> \'\' FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($hasPin);
    $found = $stmt->fetch();
    $stmt->close();
    return $found && (int) $hasPin === 1;
}

/**
 * Check a punch PIN with lockout.
 * Returns 'ok', 'invalid_pin', 'pin_locked' or 'pin_not_set'.
 */
function tc_verify_pin(mysqli $conn, int $userId, string $pin): string
{
    $stmt = $conn->prepare(
        'SELECT punch_pin_hash, punch_pin_failed_count, punch_pin_locked_until > UTC_TIMESTAMP()
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($hash, $failed, $locked);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || empty($hash)) {
        return 'pin_not_set';
    }
    if ((int) $locked === 1) {
        return 'pin_locked';
    }

    if (password_verify($pin, (string) $hash)) {
        if ((int) $failed > 0) {
            $stmt = $conn->prepare('UPDATE users SET punch_pin_failed_count = 0, punch_pin_locked_until = NULL WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
        return 'ok';
    }

    $failed = (int) $failed + 1;
    if ($failed >= TC_PIN_MAX_FAILED) {
        $stmt = $conn->prepare(
            'UPDATE users SET punch_pin_failed_count = 0,
                    punch_pin_locked_until = UTC_TIMESTAMP() + INTERVAL ' . TC_PIN_LOCK_MINUTES . ' MINUTE
             WHERE id = ?'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
        return 'pin_locked';
    }
    $stmt = $conn->prepare('UPDATE users SET punch_pin_failed_count = ? WHERE id = ?');
    $stmt->bind_param('ii', $failed, $userId);
    $stmt->execute();
    $stmt->close();
    return 'invalid_pin';
}

// ---------------------------------------------------------------------------
// Punches
// ---------------------------------------------------------------------------

/** The user's open (active, not punched out) record, or null. Times in local time. */
function tc_open_punch(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, punched_in_at FROM punch_records
         WHERE user_id = ? AND punched_out_at IS NULL AND status = 'active'
         ORDER BY punched_in_at DESC LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($id, $inUtc);
    $row = $stmt->fetch() ? ['id' => (int) $id, 'punchedInAt' => tc_utc_to_local($inUtc)] : null;
    $stmt->close();
    return $row;
}

/**
 * Forgot-to-punch-out state for an open punch (times in local time).
 * Due 60 min after the scheduled shift end for the punch-in date, or 10 h after punch-in when nothing is
 * scheduled (or the day is off). A "Still working" snooze moves the alert to snoozedUntil if that is later.
 * Returns ['overdue' => bool, 'dueAt' => local, 'shiftEnd' => local|null, 'snoozedUntil' => local|null, 'lastAlertAt' => local|null].
 */
function tc_late_status(mysqli $conn, int $userId, int $punchId, string $punchedInLocal): array
{
    $tz = new DateTimeZone(TC_TZ);
    $in = new DateTimeImmutable($punchedInLocal, $tz);
    $date = $in->format('Y-m-d');

    $shiftEnd = null;
    $shift = tc_schedules($conn, [$userId], $date, $date)[$userId][$date] ?? null;
    if ($shift && !$shift['off'] && $shift['end'] !== '') {
        $shiftEnd = new DateTimeImmutable($date . ' ' . $shift['end'], $tz);
        if ($shift['end'] <= $shift['start']) {
            $shiftEnd = $shiftEnd->modify('+1 day'); // overnight
        }
    }
    $due = $shiftEnd
        ? $shiftEnd->modify('+' . TC_LATE_GRACE_MINUTES . ' minutes')
        : $in->modify('+' . TC_LATE_UNSCHEDULED_HOURS . ' hours');

    $stmt = $conn->prepare('SELECT snoozed_until, last_alert_at FROM punch_late_alerts WHERE punch_id = ? LIMIT 1');
    $stmt->bind_param('i', $punchId);
    $stmt->execute();
    $stmt->bind_result($snoozeUtc, $alertUtc);
    $found = $stmt->fetch();
    $stmt->close();

    $snoozed = $found && $snoozeUtc ? new DateTimeImmutable((string) tc_utc_to_local($snoozeUtc), $tz) : null;
    if ($snoozed && $snoozed > $due) {
        $due = $snoozed;
    }

    return [
        'overdue'      => new DateTimeImmutable('now', $tz) >= $due,
        'dueAt'        => $due->format('Y-m-d H:i:s'),
        'shiftEnd'     => $shiftEnd ? $shiftEnd->format('Y-m-d H:i:s') : null,
        'snoozedUntil' => $snoozed ? $snoozed->format('Y-m-d H:i:s') : null,
        'lastAlertAt'  => $found ? tc_utc_to_local($alertUtc) : null,
    ];
}

/** "Still working": push the forgot-to-punch-out alert for this punch TC_LATE_SNOOZE_MINUTES from now. */
function tc_late_snooze(mysqli $conn, int $punchId): void
{
    $stmt = $conn->prepare(
        'INSERT INTO punch_late_alerts (punch_id, snoozed_until, snooze_count)
         VALUES (?, UTC_TIMESTAMP() + INTERVAL ' . TC_LATE_SNOOZE_MINUTES . ' MINUTE, 1)
         ON DUPLICATE KEY UPDATE snoozed_until = VALUES(snoozed_until), snooze_count = snooze_count + 1'
    );
    $stmt->bind_param('i', $punchId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Active punch records for the given users that started in [fromLocal, toLocalExclusive).
 * Rows: id, user_id, in (local), out (local|null), break_minutes, source.
 */
function tc_fetch_punches(mysqli $conn, array $userIds, string $fromLocal, string $toLocalExclusive): array
{
    if (!$userIds) {
        return [];
    }
    $ids = implode(',', array_map('intval', $userIds));
    $stmt = $conn->prepare(
        "SELECT id, user_id, punched_in_at, punched_out_at, break_minutes, source
         FROM punch_records
         WHERE status = 'active' AND user_id IN ($ids)
           AND punched_in_at >= ? AND punched_in_at < ?
         ORDER BY punched_in_at"
    );
    $fromUtc = tc_local_to_utc($fromLocal);
    $toUtc = tc_local_to_utc($toLocalExclusive);
    $stmt->bind_param('ss', $fromUtc, $toUtc);
    $stmt->execute();
    $stmt->bind_result($id, $userId, $inUtc, $outUtc, $break, $source);
    $rows = [];
    while ($stmt->fetch()) {
        $rows[] = [
            'id'            => (int) $id,
            'user_id'       => (int) $userId,
            'in'            => tc_utc_to_local($inUtc),
            'out'           => tc_utc_to_local($outUtc),
            'break_minutes' => (int) $break,
            'source'        => (string) $source,
        ];
    }
    $stmt->close();
    return $rows;
}

// ---------------------------------------------------------------------------
// Schedules (work_schedules = usual week, schedule_overrides = one date).
// Times are wall-clock 'HH:MM:SS' in TC_TZ; end < start means overnight.
// ---------------------------------------------------------------------------

/** Planned paid minutes for a shift. */
function tc_shift_minutes(string $start, string $end, int $break): int
{
    $s = strtotime('1970-01-01 ' . $start);
    $e = strtotime('1970-01-01 ' . $end);
    if ($e <= $s) {
        $e += 86400; // overnight
    }
    return max(0, (int) round(($e - $s) / 60) - $break);
}

/**
 * Scheduled shift per user per date in [fromDate, toDate] (Y-m-d, inclusive).
 * Returns [userId][date] => ['start','end','break','minutes','off','note','source' => 'pattern'|'override'].
 * Dates with nothing scheduled are omitted.
 */
function tc_schedules(mysqli $conn, array $userIds, string $fromDate, string $toDate): array
{
    if (!$userIds) {
        return [];
    }
    $ids = implode(',', array_map('intval', $userIds));
    $out = [];

    // usual weeks overlapping the range
    $stmt = $conn->prepare(
        "SELECT user_id, weekday, start_time, end_time, break_minutes, effective_from, effective_to
         FROM work_schedules
         WHERE user_id IN ($ids) AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)"
    );
    $stmt->bind_param('ss', $toDate, $fromDate);
    $stmt->execute();
    $stmt->bind_result($uid, $wd, $st, $en, $br, $ef, $et);
    $patterns = [];
    while ($stmt->fetch()) {
        $patterns[] = [(int) $uid, (int) $wd, (string) $st, (string) $en, (int) $br, (string) $ef, $et === null ? null : (string) $et];
    }
    $stmt->close();

    for ($d = new DateTimeImmutable($fromDate), $last = new DateTimeImmutable($toDate); $d <= $last; $d = $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');
        $wday = (int) $d->format('w');
        foreach ($patterns as [$uid, $wd, $st, $en, $br, $ef, $et]) {
            if ($wd === $wday && $ef <= $date && ($et === null || $et >= $date)) {
                $out[$uid][$date] = [
                    'start' => substr($st, 0, 5), 'end' => substr($en, 0, 5), 'break' => $br,
                    'minutes' => tc_shift_minutes($st, $en, $br), 'off' => false, 'note' => '', 'source' => 'pattern',
                ];
            }
        }
    }

    // one-date changes win
    $stmt = $conn->prepare(
        "SELECT user_id, work_date, is_off, start_time, end_time, break_minutes, note
         FROM schedule_overrides WHERE user_id IN ($ids) AND work_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $fromDate, $toDate);
    $stmt->execute();
    $stmt->bind_result($uid, $date, $off, $st, $en, $br, $note);
    while ($stmt->fetch()) {
        $off = (int) $off === 1;
        $out[(int) $uid][(string) $date] = [
            'start'   => $off ? '' : substr((string) $st, 0, 5),
            'end'     => $off ? '' : substr((string) $en, 0, 5),
            'break'   => (int) $br,
            'minutes' => $off ? 0 : tc_shift_minutes((string) $st, (string) $en, (int) $br),
            'off'     => $off,
            'note'    => (string) ($note ?? ''),
            'source'  => 'override',
        ];
    }
    $stmt->close();
    return $out;
}

/** Usual week in effect on $onDate: [userId][weekday] => ['start','end','break']. */
function tc_current_patterns(mysqli $conn, array $userIds, string $onDate): array
{
    if (!$userIds) {
        return [];
    }
    $ids = implode(',', array_map('intval', $userIds));
    $stmt = $conn->prepare(
        "SELECT user_id, weekday, start_time, end_time, break_minutes FROM work_schedules
         WHERE user_id IN ($ids) AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)"
    );
    $stmt->bind_param('ss', $onDate, $onDate);
    $stmt->execute();
    $stmt->bind_result($uid, $wd, $st, $en, $br);
    $out = [];
    while ($stmt->fetch()) {
        $out[(int) $uid][(int) $wd] = ['start' => substr((string) $st, 0, 5), 'end' => substr((string) $en, 0, 5), 'break' => (int) $br];
    }
    $stmt->close();
    return $out;
}

/**
 * Worked/paid minutes for one record. Open shifts count up to now if they started today,
 * otherwise 0 (a forgotten punch-out shouldn't pile up hours).
 * Returns [spanMinutes, paidMinutes, breakMinutes, isOpen, isStale].
 */
function tc_punch_minutes(array $p): array
{
    $open = $p['out'] === null;
    $stale = $open && substr($p['in'], 0, 10) < date('Y-m-d');
    $end = $open ? ($stale ? strtotime($p['in']) : time()) : strtotime($p['out']);
    $span = max(0, (int) round(($end - strtotime($p['in'])) / 60));
    $break = min($p['break_minutes'], $span);
    return [$span, $span - $break, $break, $open && !$stale, $stale];
}
