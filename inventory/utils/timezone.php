<?php

function app_apply_timezone(?mysqli $conn = null): void
{
    date_default_timezone_set('America/New_York');

    if (!$conn instanceof mysqli) {
        return;
    }

    $tz = new DateTimeZone('America/New_York');
    $offset = (new DateTimeImmutable('now', $tz))->format('P');

    try {
        $conn->query("SET time_zone = '{$offset}'");
    } catch (Throwable $e) {
        error_log('Failed to set MySQL session timezone: ' . $e->getMessage());
    }
}
