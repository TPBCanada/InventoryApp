<?php
declare(strict_types=1);

// Authorization is handled by the supplies access_control.php file.
// Reuse shared declarations so both helper paths can be loaded safely.
require_once __DIR__ . '/../../utils/helpers.php';

if (!function_exists('fetch_all')) {
    /**
     * Executes an SQL query (without bound parameters) and returns all results
     * as an indexed array of associative arrays. Returns empty array on failure.
     *
     * @param mysqli $conn The database connection (non-prepared).
     * @param string $sql The SQL query string.
     * @return array
     */
    function fetch_all(mysqli $conn, string $sql): array
    {
        $res = mysqli_query($conn, $sql);
        if (!$res)
            return [];
        $rows = [];
        while ($r = mysqli_fetch_assoc($res))
            $rows[] = $r;
        return $rows;
    }
}
