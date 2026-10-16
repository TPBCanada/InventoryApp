<?php
declare(strict_types=1);
/*
 * Punch-clock geofence, stored in timeclock_sites (xserqhmy_users).
 * Edited from geofence.php; read by utils/punch_clock.php.
 * One active site for now; the table allows more later.
 */

const GEOFENCE_DEFAULT_RADIUS = 300;
const GEOFENCE_MIN_RADIUS     = 50;
const GEOFENCE_MAX_RADIUS     = 2000;
const GEOFENCE_EDITOR_ROLES   = [1, 2];

/** @return array{id: ?int, lat: ?float, lng: ?float, radius: int, label: string, updatedBy: string, updatedAt: string} */
function geofence_get(mysqli $conn): array
{
    $cfg = [
        'id'        => null,
        'lat'       => null,
        'lng'       => null,
        'radius'    => GEOFENCE_DEFAULT_RADIUS,
        'label'     => '',
        'updatedBy' => '',
        'updatedAt' => '',
    ];

    $res = $conn->query(
        'SELECT s.id, s.name, s.lat, s.lng, s.radius_m, s.updated_at, u.username
         FROM timeclock_sites s LEFT JOIN users u ON u.id = s.updated_by
         WHERE s.is_active = 1 ORDER BY s.id LIMIT 1'
    );
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) {
        return $cfg;
    }

    return [
        'id'        => (int) $row['id'],
        'lat'       => (float) $row['lat'],
        'lng'       => (float) $row['lng'],
        'radius'    => (int) $row['radius_m'],
        'label'     => (string) $row['name'],
        'updatedBy' => (string) ($row['username'] ?? ''),
        'updatedAt' => (string) $row['updated_at'],
    ];
}

/** Validates and saves the active site; returns an error message, or null on success. */
function geofence_save(mysqli $conn, $lat, $lng, $radius, string $label, int $userId): ?string
{
    if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180) {
        return 'Enter a valid latitude (-90 to 90) and longitude (-180 to 180).';
    }
    if (!is_numeric($radius) || (int) $radius < GEOFENCE_MIN_RADIUS || (int) $radius > GEOFENCE_MAX_RADIUS) {
        return 'Radius must be between ' . GEOFENCE_MIN_RADIUS . ' and ' . GEOFENCE_MAX_RADIUS . ' metres.';
    }

    $name = mb_substr(trim($label), 0, 120) ?: 'Main site';
    $lat = round((float) $lat, 6);
    $lng = round((float) $lng, 6);
    $radius = (int) $radius;
    $by = $userId > 0 ? $userId : null;
    $existing = geofence_get($conn)['id'];

    try {
        if ($existing) {
            $stmt = $conn->prepare('UPDATE timeclock_sites SET name = ?, lat = ?, lng = ?, radius_m = ?, updated_by = ? WHERE id = ?');
            $stmt->bind_param('sddiii', $name, $lat, $lng, $radius, $by, $existing);
        } else {
            $stmt = $conn->prepare('INSERT INTO timeclock_sites (name, lat, lng, radius_m, updated_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('sddii', $name, $lat, $lng, $radius, $by);
        }
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log('[geofence] save failed: ' . $e->getMessage());
        return 'Could not save the geofence (database error).';
    }
    return null;
}
