<?php
/*
 * Punch clock endpoint (JSON) for js/punch-clock.js.
 *
 * GET   -> { ok, status: 'in'|'out', record: {id, punchedInAt} | null, hasPin: bool, late: {...} | null }
 * POST  { action: 'punch_in',  pin, location: {lat, lng, accuracy} }
 *       { action: 'punch_out', pin }
 *       { action: 'snooze' }        "Still working" on the forgot-to-punch-out banner (no PIN)
 * POSTs need the session CSRF token in X-CSRF-Token.
 *
 * PIN is checked against users.punch_pin_hash (with lockout); punches are stored in
 * punch_records (UTC). Punch-in must be inside the active timeclock_sites geofence.
 * Schema: sql/2026_10_14_create_timeclock.sql, sql/2026_10_15_create_punch_late_alerts.sql
 */

ob_start();

require_once __DIR__ . '/../lib/security.php'; // shared session name/cookie, so the login is visible
require_once __DIR__ . '/../dbusers.php';      // $conn -> xserqhmy_users
require_once __DIR__ . '/timeclock.php';
require_once __DIR__ . '/geofence_store.php';

ob_clean();
header('Content-Type: application/json');

const PUNCH_GEOFENCE_MAX_ACCURACY = 500; // reject fixes vaguer than this (e.g. desktop IP-based location)

/** Forgot-to-punch-out state for the open punch (see tc_late_status), or null. */
function punch_late(mysqli $conn, int $userId, ?array $open): ?array
{
    if (!$open) {
        return null;
    }
    try {
        return tc_late_status($conn, $userId, $open['id'], $open['punchedInAt']);
    } catch (mysqli_sql_exception $e) {
        error_log('[punch_api] late status: ' . $e->getMessage()); // e.g. punch_late_alerts not created yet
        return null;
    }
}

function punch_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

/** Great-circle distance in metres (haversine). */
function punch_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r    = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

/**
 * Validate the browser-supplied location against the geofence.
 * Returns ['ok' => true, 'location' => [...], 'siteId' => int] or ['ok' => false, 'error' => code, ...].
 */
function punch_check_geofence(mysqli $conn, $loc): array
{
    $fence = geofence_get($conn);
    if ($fence['lat'] === null || $fence['lng'] === null) {
        return ['ok' => false, 'error' => 'geofence_not_configured'];
    }
    if (!is_array($loc) || !is_numeric($loc['lat'] ?? null) || !is_numeric($loc['lng'] ?? null)) {
        return ['ok' => false, 'error' => 'location_required'];
    }

    $lat      = (float) $loc['lat'];
    $lng      = (float) $loc['lng'];
    $accuracy = is_numeric($loc['accuracy'] ?? null) ? (float) $loc['accuracy'] : PHP_FLOAT_MAX;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return ['ok' => false, 'error' => 'location_required'];
    }
    if ($accuracy > PUNCH_GEOFENCE_MAX_ACCURACY) {
        return ['ok' => false, 'error' => 'location_inaccurate'];
    }

    $distance = punch_distance_m($lat, $lng, $fence['lat'], $fence['lng']);
    if ($distance > $fence['radius']) {
        return ['ok' => false, 'error' => 'outside_geofence', 'distance' => (int) round($distance), 'radius' => $fence['radius']];
    }
    return [
        'ok'       => true,
        'siteId'   => $fence['id'],
        'location' => [
            'lat'      => round($lat, 6),
            'lng'      => round($lng, 6),
            'accuracy' => (int) round($accuracy),
            'distance' => (int) round($distance),
        ],
    ];
}

if (!isset($_SESSION['username'])) {
    punch_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

try {
    $userId = tc_session_user_id($conn);
    if ($userId <= 0) {
        punch_json(['ok' => false, 'error' => 'Unauthorized'], 401);
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $open = tc_open_punch($conn, $userId);
        punch_json([
            'ok'     => true,
            'status' => $open ? 'in' : 'out',
            'record' => $open,
            'hasPin' => tc_user_has_pin($conn, $userId),
            'late'   => punch_late($conn, $userId, $open),
        ]);
    }

    if ($method !== 'POST') {
        punch_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }
    app_require_csrf();

    $json = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($json)) {
        punch_json(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $action = $json['action'] ?? '';

    if ($action === 'snooze') {
        $open = tc_open_punch($conn, $userId);
        if (!$open) {
            punch_json(['ok' => false, 'error' => 'Not punched in']);
        }
        tc_late_snooze($conn, $open['id']);
        punch_json(['ok' => true, 'late' => punch_late($conn, $userId, $open)]);
    }

    $pin    = trim((string) ($json['pin'] ?? ''));

    if (!preg_match('/^\d{4}$/', $pin)) {
        punch_json(['ok' => false, 'error' => 'invalid_pin']);
    }
    $pinResult = tc_verify_pin($conn, $userId, $pin);
    if ($pinResult !== 'ok') {
        punch_json(['ok' => false, 'error' => $pinResult]);
    }

    if ($action === 'punch_in') {
        if (tc_open_punch($conn, $userId)) {
            punch_json(['ok' => false, 'error' => 'Already punched in']);
        }
        $fence = punch_check_geofence($conn, $json['location'] ?? null);
        if (!$fence['ok']) {
            punch_json($fence);
        }

        $loc = $fence['location'];
        $siteId = $fence['siteId'];
        $stmt = $conn->prepare(
            "INSERT INTO punch_records (user_id, punched_in_at, source, site_id, in_lat, in_lng, in_accuracy_m, in_distance_m)
             VALUES (?, UTC_TIMESTAMP(), 'clock', ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iiddii', $userId, $siteId, $loc['lat'], $loc['lng'], $loc['accuracy'], $loc['distance']);
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) { // uq_punch_one_open: punched in from another tab/device
                punch_json(['ok' => false, 'error' => 'Already punched in']);
            }
            throw $e;
        }
        $stmt->close();

        punch_json(['ok' => true, 'status' => 'in', 'record' => tc_open_punch($conn, $userId)]);
    }

    if ($action === 'punch_out') {
        $stmt = $conn->prepare(
            "UPDATE punch_records SET punched_out_at = UTC_TIMESTAMP()
             WHERE user_id = ? AND punched_out_at IS NULL AND status = 'active'"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
        punch_json(['ok' => true, 'status' => 'out', 'record' => null]);
    }

    punch_json(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (mysqli_sql_exception $e) {
    error_log('[punch_api] ' . $e->getMessage());
    punch_json(['ok' => false, 'error' => 'db_error'], 500);
}
