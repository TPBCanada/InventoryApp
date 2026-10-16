<?php
declare(strict_types=1);
/*
 * Timesheet data endpoint (JSON) for timesheet.php.
 *
 * GET  ?from=YYYY-MM-DD&to=YYYY-MM-DD   -> employees with time cards + computed hours
 *      (no range = current pay period). Managers see every active user; others see themselves.
 * POST {action:'add', username, date, in, out, breakMinutes}  (managers, X-CSRF-Token)
 *      -> inserts a manual punch_records row + punch_record_audit 'create' row.
 *
 * Data: punch_records / users / roles in xserqhmy_users (see sql/2026_10_14_create_timeclock.sql).
 */

require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../dbusers.php';   // $conn -> xserqhmy_users
require_once __DIR__ . '/timeclock.php';
header('Content-Type: application/json');

const TS_PAY_PERIOD_ANCHOR    = '2026-09-27'; // first day (Sunday) of a known bi-weekly pay period
const TS_PAY_PERIOD_DAYS      = 14;
const TS_OT_WEEKLY_MINUTES    = 44 * 60;      // Ontario ESA weekly overtime threshold
const TS_BREAK_REQUIRED_AFTER = 5 * 60;       // 30-min eating period due after 5 h worked
const TS_MAX_RANGE_DAYS       = 62;

function ts_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['username'])) {
    ts_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

/** Active users visible to the caller: id, username, name, role. */
function ts_employees(mysqli $conn, int $selfId, bool $isManager): array
{
    $sql = 'SELECT u.id, u.username, u.first_name, u.last_name, r.role
            FROM users u LEFT JOIN roles r ON r.id = u.role_id
            WHERE ' . ($isManager ? 'u.role_id <> ' . TC_INACTIVE_ROLE : 'u.id = ' . $selfId) . '
            ORDER BY u.first_name, u.last_name, u.username';
    $res = $conn->query($sql);
    $list = [];
    while ($row = $res->fetch_assoc()) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: (string) $row['username'];
        $list[] = [
            'id'       => (int) $row['id'],
            'username' => (string) $row['username'],
            'name'     => $name,
            'role'     => (string) ($row['role'] ?? ''),
        ];
    }
    return $list;
}

/**
 * Validate {date, in, out, breakMinutes} from the add/edit dialog (local time).
 * Returns [inUtc, outUtc|null, breakMinutes]; sends a 400 on bad input.
 */
function ts_card_times(array $body): array
{
    $date    = (string) ($body['date'] ?? '');
    $inTime  = (string) ($body['in'] ?? '');
    $outTime = (string) ($body['out'] ?? '');
    $break   = (int) ($body['breakMinutes'] ?? 0);

    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$day || !preg_match('/^\d{2}:\d{2}$/', $inTime) || ($outTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $outTime))) {
        ts_json(['ok' => false, 'error' => 'Enter a valid date and times'], 400);
    }
    if ($break < 0 || $break > 240) {
        ts_json(['ok' => false, 'error' => 'Break must be between 0 and 240 minutes'], 400);
    }

    $in  = new DateTimeImmutable("{$date} {$inTime}");
    $out = null;
    if ($outTime !== '') {
        $out = new DateTimeImmutable("{$date} {$outTime}");
        if ($out <= $in) {
            $out = $out->modify('+1 day'); // overnight shift
        }
    }
    if ($in > new DateTimeImmutable() || ($out && $out > new DateTimeImmutable())) {
        ts_json(['ok' => false, 'error' => 'Time cards can\'t be in the future'], 400);
    }

    return [
        tc_local_to_utc($in->format('Y-m-d H:i:s')),
        $out ? tc_local_to_utc($out->format('Y-m-d H:i:s')) : null,
        $break,
    ];
}

function ts_audit(mysqli $conn, int $punchId, string $action, int $by, string $reason, ?array $old, ?array $new): void
{
    $oldJson = $old === null ? null : json_encode($old);
    $newJson = $new === null ? null : json_encode($new);
    $stmt = $conn->prepare(
        'INSERT INTO punch_record_audit (punch_id, action, changed_by, reason, old_values, new_values) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isisss', $punchId, $action, $by, $reason, $oldJson, $newJson);
    $stmt->execute();
    $stmt->close();
}

/** Active employee by username (managers' list); sends a 400 if unknown. */
function ts_target(mysqli $conn, int $selfId, string $username): array
{
    foreach (ts_employees($conn, $selfId, true) as $emp) {
        if ($emp['username'] === $username) {
            return $emp;
        }
    }
    ts_json(['ok' => false, 'error' => 'Unknown employee'], 400);
}

/** Validate {start, end, break} 'HH:MM' shift input. Returns ['HH:MM:00', 'HH:MM:00', breakMinutes]. */
function ts_shift_input(array $d): array
{
    $st = (string) ($d['start'] ?? '');
    $en = (string) ($d['end'] ?? '');
    $br = (int) ($d['break'] ?? 0);
    if (!preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $en) || $st === $en) {
        ts_json(['ok' => false, 'error' => 'Enter a start and end time for each scheduled day.'], 400);
    }
    if ($br < 0 || $br > 240) {
        ts_json(['ok' => false, 'error' => 'Break must be between 0 and 240 minutes'], 400);
    }
    return [$st . ':00', $en . ':00', $br];
}

try {
    $selfId     = tc_session_user_id($conn);
    $is_manager = tc_is_manager();
    $today      = new DateTimeImmutable('today');

    // -----------------------------------------------------------------------
    // POST (managers): add / edit / void a time card. Every change is logged in
    // punch_record_audit with the old and new values and a reason.
    // -----------------------------------------------------------------------
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
            ts_json(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
        }
        if (!$is_manager) {
            ts_json(['ok' => false, 'error' => 'Only managers can change time cards'], 403);
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        $action = is_array($body) ? (string) ($body['action'] ?? '') : '';
        if (!in_array($action, ['add', 'edit', 'void', 'pattern', 'override', 'override_clear'], true)) {
            ts_json(['ok' => false, 'error' => 'Unknown action'], 400);
        }
        $reason = mb_substr(trim((string) ($body['reason'] ?? '')), 0, 255);

        if ($action === 'add') {
            $target = null;
            foreach (ts_employees($conn, $selfId, true) as $emp) {
                if ($emp['username'] === (string) ($body['username'] ?? '')) {
                    $target = $emp;
                    break;
                }
            }
            if (!$target) {
                ts_json(['ok' => false, 'error' => 'Unknown employee'], 400);
            }
            [$inUtc, $outUtc, $break] = ts_card_times($body);
            $new = ['user_id' => $target['id'], 'punched_in_at' => $inUtc, 'punched_out_at' => $outUtc, 'break_minutes' => $break];

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO punch_records (user_id, punched_in_at, punched_out_at, break_minutes, source, created_by)
                     VALUES (?, ?, ?, ?, 'manual', ?)"
                );
                $stmt->bind_param('issii', $target['id'], $inUtc, $outUtc, $break, $selfId);
                $stmt->execute();
                $punchId = $conn->insert_id;
                $stmt->close();
                ts_audit($conn, $punchId, 'create', $selfId, $reason ?: 'Added from timesheet', null, $new);
                $conn->commit();
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ($e->getCode() === 1062) {
                    ts_json(['ok' => false, 'error' => $target['name'] . ' already has an open shift. Add a clock-out time.'], 400);
                }
                throw $e;
            }
            ts_json(['ok' => true]);
        }

        // --- schedules ---------------------------------------------------
        if (in_array($action, ['pattern', 'override', 'override_clear'], true)) {
            $target = ts_target($conn, $selfId, (string) ($body['username'] ?? ''));

            if ($action === 'pattern') {
                // Replace the usual week from effectiveFrom onward; earlier weeks keep their rows.
                $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($body['effectiveFrom'] ?? ''));
                if (!$from) {
                    ts_json(['ok' => false, 'error' => 'Choose the date the schedule starts.'], 400);
                }
                $fromDate = $from->format('Y-m-d');
                $dayBefore = $from->modify('-1 day')->format('Y-m-d');
                $rows = [];
                foreach ((array) ($body['days'] ?? []) as $d) {
                    $wd = (int) ($d['weekday'] ?? -1);
                    [$st, $en, $br] = ts_shift_input($d);
                    if ($wd < 0 || $wd > 6) {
                        ts_json(['ok' => false, 'error' => 'Invalid weekday'], 400);
                    }
                    $rows[$wd] = [$st, $en, $br];
                }

                $conn->begin_transaction();
                try {
                    $uid = $target['id'];
                    $stmt = $conn->prepare('DELETE FROM work_schedules WHERE user_id = ? AND effective_from >= ?');
                    $stmt->bind_param('is', $uid, $fromDate);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare('UPDATE work_schedules SET effective_to = ? WHERE user_id = ? AND (effective_to IS NULL OR effective_to >= ?)');
                    $stmt->bind_param('sis', $dayBefore, $uid, $fromDate);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare(
                        'INSERT INTO work_schedules (user_id, weekday, start_time, end_time, break_minutes, effective_from, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    foreach ($rows as $wd => [$st, $en, $br]) {
                        $stmt->bind_param('iissisi', $uid, $wd, $st, $en, $br, $fromDate, $selfId);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $conn->commit();
                } catch (mysqli_sql_exception $e) {
                    $conn->rollback();
                    throw $e;
                }
                ts_json(['ok' => true]);
            }

            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($body['date'] ?? ''));
            if (!$date) {
                ts_json(['ok' => false, 'error' => 'Invalid date'], 400);
            }
            $dateStr = $date->format('Y-m-d');
            $uid = $target['id'];

            if ($action === 'override_clear') {
                $stmt = $conn->prepare('DELETE FROM schedule_overrides WHERE user_id = ? AND work_date = ?');
                $stmt->bind_param('is', $uid, $dateStr);
                $stmt->execute();
                $stmt->close();
                ts_json(['ok' => true]);
            }

            $off  = !empty($body['off']) ? 1 : 0;
            $note = mb_substr(trim((string) ($body['note'] ?? '')), 0, 255) ?: null;
            [$st, $en, $br] = $off ? [null, null, 0] : ts_shift_input($body);
            $stmt = $conn->prepare(
                'INSERT INTO schedule_overrides (user_id, work_date, is_off, start_time, end_time, break_minutes, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_off = VALUES(is_off), start_time = VALUES(start_time), end_time = VALUES(end_time),
                                         break_minutes = VALUES(break_minutes), note = VALUES(note), created_by = VALUES(created_by)'
            );
            $stmt->bind_param('isissisi', $uid, $dateStr, $off, $st, $en, $br, $note, $selfId);
            $stmt->execute();
            $stmt->close();
            ts_json(['ok' => true]);
        }

        // edit / void an existing card
        if ($reason === '') {
            ts_json(['ok' => false, 'error' => 'Enter a reason for the change.'], 400);
        }
        $punchId = (int) ($body['id'] ?? 0);
        $stmt = $conn->prepare("SELECT user_id, punched_in_at, punched_out_at, break_minutes FROM punch_records WHERE id = ? AND status = 'active'");
        $stmt->bind_param('i', $punchId);
        $stmt->execute();
        $stmt->bind_result($oUser, $oIn, $oOut, $oBreak);
        $found = $stmt->fetch();
        $stmt->close();
        if (!$found) {
            ts_json(['ok' => false, 'error' => 'That time card no longer exists. Refresh the page.'], 404);
        }
        $old = ['user_id' => (int) $oUser, 'punched_in_at' => $oIn, 'punched_out_at' => $oOut, 'break_minutes' => (int) $oBreak];

        $conn->begin_transaction();
        try {
            if ($action === 'void') {
                $stmt = $conn->prepare("UPDATE punch_records SET status = 'void' WHERE id = ?");
                $stmt->bind_param('i', $punchId);
                $new = $old + ['status' => 'void'];
            } else {
                [$inUtc, $outUtc, $break] = ts_card_times($body);
                $stmt = $conn->prepare('UPDATE punch_records SET punched_in_at = ?, punched_out_at = ?, break_minutes = ? WHERE id = ?');
                $stmt->bind_param('ssii', $inUtc, $outUtc, $break, $punchId);
                $new = ['user_id' => (int) $oUser, 'punched_in_at' => $inUtc, 'punched_out_at' => $outUtc, 'break_minutes' => $break];
            }
            $stmt->execute();
            $stmt->close();
            ts_audit($conn, $punchId, $action, $selfId, $reason, $old, $new);
            $conn->commit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            if ($e->getCode() === 1062) {
                ts_json(['ok' => false, 'error' => 'This person already has another open shift. Add a clock-out time.'], 400);
            }
            throw $e;
        }
        ts_json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // GET: resolve range
    // -----------------------------------------------------------------------
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['from'] ?? ''));
    $to   = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['to'] ?? ''));

    if (!$from || !$to) {
        $anchor  = new DateTimeImmutable(TS_PAY_PERIOD_ANCHOR);
        $elapsed = (int) $anchor->diff($today)->format('%r%a');
        $periods = (int) floor($elapsed / TS_PAY_PERIOD_DAYS);
        $from    = $anchor->modify(sprintf('%+d days', $periods * TS_PAY_PERIOD_DAYS));
        $to      = $from->modify('+' . (TS_PAY_PERIOD_DAYS - 1) . ' days');
    }
    if ($to < $from || $from->diff($to)->days > TS_MAX_RANGE_DAYS) {
        ts_json(['ok' => false, 'error' => 'Choose a range of up to ' . TS_MAX_RANGE_DAYS . ' days'], 400);
    }

    // Load from the start of from's week so weekly overtime is accurate for partial weeks.
    $loadFrom = $from->modify('-' . $from->format('w') . ' days');

    $employees = ts_employees($conn, $selfId, $is_manager);
    $byId = [];
    foreach ($employees as $emp) {
        $byId[$emp['id']] = $emp['username'];
    }
    $punches = tc_fetch_punches(
        $conn,
        array_keys($byId),
        $loadFrom->format('Y-m-d 00:00:00'),
        $to->modify('+1 day')->format('Y-m-d 00:00:00')
    );

    // -----------------------------------------------------------------------
    // Compute hours per card (paid = span - meal break; OT = weekly minutes over 44 h)
    // -----------------------------------------------------------------------
    $fromStr  = $from->format('Y-m-d');
    $toStr    = $to->format('Y-m-d');
    $byUser   = [];
    $weekPaid = [];

    foreach ($punches as $p) {
        [$span, $paid, $break, $open, $stale] = tc_punch_minutes($p);
        $date = substr($p['in'], 0, 10);

        $week    = $p['user_id'] . '|' . date('Y-m-d', strtotime($date . ' -' . date('w', strtotime($date)) . ' days'));
        $before  = $weekPaid[$week] ?? 0;
        $regular = max(0, min($paid, TS_OT_WEEKLY_MINUTES - $before));
        $weekPaid[$week] = $before + $paid;

        if ($date < $fromStr || $date > $toStr) {
            continue; // only loaded for the overtime calculation
        }

        $issues = [];
        if ($stale) {
            $issues[] = 'Missing punch out';
        }

        $byUser[$byId[$p['user_id']]][] = [
            'id'        => $p['id'],
            'date'      => $date,
            'in'        => $p['in'],
            'out'       => $p['out'],
            'open'      => $open,
            'source'    => $p['source'],
            'breakMinutes' => $p['break_minutes'], // stored (manual) break; 'break' below also adds punch-out gaps
            'scheduled' => 0, // TODO: no schedules table yet
            'actual'    => $paid,
            'paid'      => $paid,
            'regular'   => $regular,
            'ot'        => $paid - $regular,
            'break'     => $break,
            'issues'    => $issues,
        ];
    }

    // Breaks are taken by punching out and back in, so a break is the gap between one card's
    // punch-out and the next card's punch-in on the same day (shown on the card after the break).
    // A day with more than 5 h worked needs at least 30 min of breaks in total.
    foreach ($byUser as &$cards) {
        $days = [];
        foreach ($cards as $i => $card) {
            $days[$card['date']][] = $i; // cards are already in punch-in order
        }
        foreach ($days as $idx) {
            $worked = 0;
            $breaks = 0;
            $closed = true;
            foreach ($idx as $n => $i) {
                if ($n > 0) {
                    $prev = $cards[$idx[$n - 1]];
                    $gap  = $prev['out'] ? (int) round((strtotime($cards[$i]['in']) - strtotime($prev['out'])) / 60) : 0;
                    if ($gap > 0) {
                        $cards[$i]['break'] += $gap;
                    }
                }
                $worked += $cards[$i]['paid'];
                $breaks += $cards[$i]['break'];
                $closed  = $closed && $cards[$i]['out'] !== null;
            }
            if ($closed && $worked > TS_BREAK_REQUIRED_AFTER && $breaks < 30) {
                $cards[end($idx)]['issues'][] = 'Missing breaks';
            }
        }
    }
    unset($cards);

    // Schedules: scheduled minutes go on the first card of each day; a scheduled day with no
    // punches gets its own row (flagged once the day has passed).
    $schedules = tc_schedules($conn, array_keys($byId), $fromStr, $toStr);
    $patterns  = tc_current_patterns($conn, array_keys($byId), $today->format('Y-m-d'));
    $todayStr  = $today->format('Y-m-d');
    foreach ($schedules as $uid => $days) {
        $username = $byId[$uid];
        $cards = $byUser[$username] ?? [];
        foreach ($days as $date => $s) {
            if ($s['off']) {
                continue;
            }
            $first = null;
            foreach ($cards as $i => $card) {
                if ($card['date'] === $date) {
                    $first = $i;
                    break;
                }
            }
            if ($first !== null) {
                $cards[$first]['scheduled'] = $s['minutes'];
                continue;
            }
            $cards[] = [
                'id' => null, 'kind' => 'scheduled', 'date' => $date, 'in' => null, 'out' => null,
                'open' => false, 'source' => 'schedule', 'breakMinutes' => 0,
                'scheduled' => $s['minutes'], 'actual' => 0, 'paid' => 0, 'regular' => 0, 'ot' => 0, 'break' => 0,
                'issues' => $date < $todayStr ? ['No punches'] : [],
            ];
        }
        usort($cards, fn($a, $b) => [$a['date'], (string) $a['in']] <=> [$b['date'], (string) $b['in']]);
        $byUser[$username] = $cards;
    }

    $needsReview = 0;
    $out = [];
    foreach ($employees as $emp) {
        $empId  = $emp['id'];
        $cards  = $byUser[$emp['username']] ?? [];
        $totals = ['scheduled' => 0, 'actual' => 0, 'paid' => 0, 'regular' => 0, 'ot' => 0, 'break' => 0, 'issues' => 0];
        foreach ($cards as $card) {
            foreach (['scheduled', 'actual', 'paid', 'regular', 'ot', 'break'] as $k) {
                $totals[$k] += $card[$k];
            }
            if ($card['issues']) {
                $totals['issues']++;
            }
        }
        $needsReview += $totals['issues'];

        $parts    = preg_split('/\s+/', trim($emp['name'])) ?: [$emp['name']];
        $initials = strtoupper(substr($parts[0], 0, 1) . (count($parts) > 1 ? substr((string) end($parts), 0, 1) : ''));

        unset($emp['id']);
        $out[] = $emp + [
            'initials' => $initials,
            'cards'    => $cards,
            'totals'   => $totals,
            'schedule' => (object) ($schedules[$empId] ?? []),       // date => shift (incl. days off)
            'pattern'  => (object) ($patterns[$empId] ?? []),        // weekday => usual shift today
        ];
    }

    ts_json([
        'ok'          => true,
        'from'        => $fromStr,
        'to'          => $toStr,
        'isManager'   => $is_manager,
        'needsReview' => $needsReview,
        'employees'   => $out,
    ]);
} catch (mysqli_sql_exception $e) {
    error_log('[timesheet_data] ' . $e->getMessage());
    ts_json(['ok' => false, 'error' => 'Database error loading timesheets'], 500);
}
