<?php
declare(strict_types=1);

/**
 * HTML Escape Helper (alias for htmlspecialchars)
 *
 * @param mixed $v The value to escape.
 * @return string The escaped string.
 */
function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

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

/**
 * Finds the ID of a location given its components.
 *
 * @param mysqli $conn The database connection.
 * @param string $row The row code.
 * @param string $bay The bay number.
 * @param string $lvl The level code.
 * @param string $side The side (A or B).
 * @return int|null The location ID or null if not found.
 */
function find_loc_id(mysqli $conn, string $row, string $bay, string $lvl, string $side): ?int
{
    $sql = "
        SELECT id
        FROM location
        WHERE row_code=? AND CAST(bay_num AS UNSIGNED)=CAST(? AS UNSIGNED)
          AND level_code=? AND side=?
        LIMIT 1
    ";

    $st = $conn->prepare($sql);
    $st->bind_param('ssss', $row, $bay, $lvl, $side);
    $st->execute();

    // Portable fetch (works with/without mysqlnd)
    $res = function_exists('mysqli_stmt_get_result')
        ? mysqli_stmt_get_result($st)
        : false;

    $id = null;

    if ($res instanceof mysqli_result) {
        if ($r = $res->fetch_assoc()) {
            $id = (int)$r['id'];
        }
        $res->free();
    } else {
        $st->bind_result($idTmp);
        if ($st->fetch()) {
            $id = (int)$idTmp;
        }
    }

    $st->close();
    return $id ?: null;
}


/**
 * Ensures a location exists in the database. Inserts it if not found.
 *
 * @param mysqli $conn The database connection.
 * @param string $row The row code.
 * @param string $bay The bay number.
 * @param string $lvl The level code.
 * @param string $side The side (A or B).
 * @return int The location ID. Returns 0 on failure (should not happen with find/insert logic).
 */
 
function ensure_location(mysqli $conn, string $row, string $bay, string $lvl, string $side): int
{
    if ($id = find_loc_id($conn, $row, $bay, $lvl, $side)) {
        return $id;
    }

    $sql = "
        INSERT INTO location (row_code, bay_num, level_code, side)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE row_code=VALUES(row_code)
    ";
    $st = $conn->prepare($sql);
    $st->bind_param('ssss', $row, $bay, $lvl, $side);
    $st->execute();
    $st->close();

    // Now guaranteed to exist
    return (int)(find_loc_id($conn, $row, $bay, $lvl, $side) ?: 0);
}


/**
 * Fetches the live on-hand stock for a specific location (hiding 0/NULL quantities).
 * Used by AJAX and post-transfer display.
 *
 * @param mysqli $conn The database connection.
 * @param string $row The row code.
 * @param string $bay The bay number.
 * @param string $lvl The level code.
 * @param string $side The side (A or B).
 * @return array An array of associative arrays: [{'sku_num': 'ABC', 'quantity': 10}]. Empty array on error or no stock.
 */
function fetch_stock(mysqli $conn, string $row, string $bay, string $lvl, string $side): array
{
    if (!$row || !$bay || !$lvl || !$side)
        return [];
    $sql = "
        SELECT S.sku_num, SUM(COALESCE(I.quantity,0)) AS quantity
        FROM location L
        JOIN inventory I ON I.loc_id = L.id
        JOIN sku S       ON S.id      = I.sku_id
        WHERE L.row_code = ?
          AND CAST(L.bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
          AND L.level_code = ?
          AND L.side = ?
        GROUP BY S.sku_num
        HAVING SUM(COALESCE(I.quantity,0)) > 0
        ORDER BY S.sku_num
    ";
    try {
    $st = $conn->prepare($sql);
    $st->bind_param('ssss', $row, $bay, $lvl, $side);
    $st->execute();
    $out = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $st->close();
return $out;
    } catch (\Throwable $e) {
        error_log('[fetch_stock] ' . $e->getMessage());
        return [];
    }
}


/**
 * Merges an array of extra query parameters into the current $_GET array,
 * and generates a new URL query string.
 *
 * @param array $extra An associative array of keys (string) => values (string|int|null) to update.
 * If a value is null, the key is removed.
 * @return string The resulting URL query string.
 */
function qs_with(array $extra): string
{
    $base = $_GET;
    foreach ($extra as $k => $v) {
        if ($v === null) {
            unset($base[$k]);
        } else {
            $base[$k] = $v;
        }
    }
    // Ensure 'page' is removed if we are just changing filters, so it defaults to 1
    if (!isset($extra['page'])) {
        unset($base['page']);
    }
    return http_build_query($base);
}

/**
 * Loads and structures all existing location data for use in dynamic location dropdowns.
 *
 * @param mysqli $conn The database connection.
 * @return array An associative array containing structured location lists.
 * Keys: 'rows_list', 'baysByRow', 'levelsByRow', 'sidesByRow'.
 */
function load_location_dropdown_data(mysqli $conn): array
{
    $rows_list = [];
    $baysByRow = [];
    $levelsByRow = [];
    $sidesByRow = [];

    try {
        $q = "
            SELECT row_code, bay_num, level_code, side
            FROM location
            ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side
        ";
        if ($res = $conn->query($q)) {
            while ($r = $res->fetch_assoc()) {
                $row = $r['row_code'];
                $bay = $r['bay_num'];
                $lvl = $r['level_code'];
                $side = $r['side'];

                if (!in_array($row, $rows_list, true))
                    $rows_list[] = $row;

                $baysByRow[$row] = $baysByRow[$row] ?? [];
                $levelsByRow[$row] = $levelsByRow[$row] ?? [];
                $levelsByRow[$row][$bay] = $levelsByRow[$row][$bay] ?? [];
                $sidesByRow[$row] = $sidesByRow[$row] ?? [];
                $sidesByRow[$row][$bay] = $sidesByRow[$row][$bay] ?? [];
                $sidesByRow[$row][$bay][$lvl] = $sidesByRow[$row][$bay][$lvl] ?? [];

                if (!in_array($bay, $baysByRow[$row], true))
                    $baysByRow[$row][] = $bay;
                if (!in_array($lvl, $levelsByRow[$row][$bay], true))
                    $levelsByRow[$row][$bay][] = $lvl;
                if (!in_array($side, $sidesByRow[$row][$bay][$lvl], true))
                    $sidesByRow[$row][$bay][$lvl][] = $side;
            }
            $res->free();
        }
    } catch (\Throwable $_) {
        // non-fatal
    }

    return [
        'rows_list' => $rows_list,
        'baysByRow' => $baysByRow,
        'levelsByRow' => $levelsByRow,
        'sidesByRow' => $sidesByRow,
    ];
}

/**
 * Detects the primary key column used for the location table (either 'id' or 'loc_id').
 * This is used to ensure queries involving the location table use the correct column name.
 *
 * @param mysqli $conn The database connection.
 * @return string The detected primary key column name ('id' or 'loc_id').
 */
function detect_location_pk(mysqli $conn): string
{
    $db = get_db_name($conn);
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'location' AND COLUMN_KEY='PRI'
        LIMIT 1
    ";
    $st = $conn->prepare($sql);
    $st->bind_param('s', $db);
    $st->execute();
    $res = stmt_get_result_safe($st);
    $pk = 'id';
    if ($res instanceof mysqli_result) {
        if ($r = $res->fetch_assoc()) {
            $pk = $r['COLUMN_NAME'] ?: 'id';
        }
        $res->free();
    }
    $st->close();
    return $pk;
}


/**
 * Normalizes location URL parameters and resolves them to a verified loc_id from the database.
 * This is tolerant of bay numbering (e.g., '01' vs '1') and side abbreviations (e.g., 'F' vs 'Front').
 *
 * @param mysqli $conn The database connection.
 * @param string $row_code The row code (e.g., 'A').
 * @param string $bay_num The bay number (e.g., '03').
 * @param string $level_code The level code (e.g., 'L1').
 * @param string $side The side (e.g., 'F' or 'Front').
 * @return array{loc_id: ?int, loc_label: string, normalized_params: array<string, string>}
 */
function resolve_location_from_params(
    mysqli $conn,
    string $row_code,
    string $bay_num,
    string $level_code,
    string $side
): array {
    $loc_id = null;
    $loc_label = '';

    // 1. Normalize parameters for comparison/query
    $norm_row = $row_code === '' ? '' : strtoupper($row_code);
    $norm_bay = $bay_num === '' ? '' : ltrim($bay_num, '0'); // "01" -> "1"
    if ($norm_bay === '' && $bay_num !== '')
        $norm_bay = '0';   // edge case: actual "0"
    $norm_level = $level_code === '' ? '' : strtoupper($level_code);
    $norm_side = $side === '' ? '' : strtoupper($side);

    if ($norm_row && $norm_bay !== '' && $norm_level && $norm_side) {
        $stmt = $conn->prepare(
            "SELECT id, row_code, bay_num, level_code, side
             FROM location
             WHERE UPPER(row_code) = ?
               AND CAST(bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
               AND UPPER(level_code) = ?
               AND LEFT(UPPER(side), 1) = LEFT(?, 1)
             LIMIT 1"
        );
        $stmt->bind_param('ssss', $norm_row, $norm_bay, $norm_level, $norm_side);
        $stmt->execute();
        $rs = $stmt->get_result();

        if ($rec = $rs->fetch_assoc()) {
            $loc_id = (int) $rec['id'];
            $loc_label = "{$rec['row_code']}-{$rec['bay_num']}-{$rec['level_code']}-{$rec['side']}";
        }
        $stmt->close();
    }

    return [
        'loc_id' => $loc_id,
        'loc_label' => $loc_label,
        'normalized_params' => [
            'row_code' => $norm_row,
            'bay_num' => $norm_bay,
            'level_code' => $norm_level,
            'side' => $norm_side,
        ],
    ];
}


// ---------- Database Statement/Result Helpers ----------

/**
 * Safe wrapper for mysqli_stmt::get_result.
 * Returns the mysqli_result object if the mysqlnd driver is present, otherwise returns false.
 *
 * @param mysqli_stmt $stmt The prepared statement object.
 * @return mysqli_result|false The result object or false.
 */
function stmt_get_result_safe(mysqli_stmt $stmt): mysqli_result|false
{
    // procedural wrapper; only if mysqlnd is present
    if (function_exists('mysqli_stmt_get_result')) {
        return mysqli_stmt_get_result($stmt);
    }
    return false;
}

/**
 * Fetches all rows from a prepared statement as an associative array.
 * Works with or without the mysqlnd driver.
 *
 * @param mysqli_stmt $stmt The executed prepared statement.
 * @return array<int, array<string, mixed>> An indexed array of associative arrays (rows).
 */
function stmt_fetch_all_assoc(mysqli_stmt $stmt): array
{
    $res = stmt_get_result_safe($stmt);

    // Path 1: Using mysqlnd (preferred and faster)
    if ($res instanceof mysqli_result) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
        return $rows ?: [];
    }

    // Path 2: Fallback without mysqlnd
    $meta = $stmt->result_metadata();
    if (!$meta) {
        return [];
    }

    $row = [];
    $bind = [];
    while ($field = $meta->fetch_field()) {
        $row[$field->name] = null;
        $bind[] = &$row[$field->name]; // bind references to local variables
    }

    call_user_func_array([$stmt, 'bind_result'], $bind);
    $out = [];
    while ($stmt->fetch()) {
        // Copy fetched data to prevent references from changing on next fetch
        $out[] = array_map(static fn($v) => $v, $row);
    }
    return $out;
}


// ---------- Database Schema Helpers (INFORMATION_SCHEMA based) ----------

/**
 * Retrieves the current database name for the connection.
 *
 * @param mysqli $conn The database connection.
 * @return string The current database name.
 */
function get_db_schema(mysqli $conn, int $ttl): array
{
    $ckey = 'schema_v1';
    $f = cache_path($ckey);

    if (@is_file($f) && (time() - (int)@filemtime($f) < $ttl)) {
        $contents = @file_get_contents($f);
        if ($contents !== false) {
            $decoded = json_read($contents);
            if (is_array($decoded)) return $decoded;
        }
    }

    $schema = [];
    $tables = [];

    if ($res = $conn->query("SHOW TABLES")) {
        while ($row = $res->fetch_array(MYSQLI_NUM)) {
            $tables[] = $row[0];
        }
        $res->free();
    }

    foreach ($tables as $t) {
        // Use INFORMATION_SCHEMA for safety/portability
        $cols = [];
        $stmt = $conn->prepare("
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ");
        $dbName = get_db_name($conn);
        $stmt->bind_param('ss', $dbName, $t);
        $stmt->execute();
        $r = stmt_get_result_safe($stmt);
        if ($r instanceof mysqli_result) {
            while ($c = $r->fetch_assoc()) {
                $cols[] = [
                    'name' => $c['COLUMN_NAME'],
                    'type' => $c['COLUMN_TYPE'],
                    'null' => $c['IS_NULLABLE'],
                    'key'  => $c['COLUMN_KEY'],
                ];
            }
            $r->free();
        }
        $stmt->close();

        $schema[$t] = $cols;
    }

    @file_put_contents($f, json_write($schema));
    return $schema;
}


/**
 * Checks if a specific table exists in the connected database.
 * Uses a prepared statement for safe table name check against the information schema.
 *
 * @param mysqli $conn The database connection.
 * @param string $table The table name.
 * @return bool True if the table exists, false otherwise.
 */
function table_exists(mysqli $conn, string $table): bool
{
    // Get the current database name to limit the search
    $dbName = get_db_name($conn);

    if ($dbName === '')
        return false;

    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $dbName, $table);
        $stmt->execute();
        $res = stmt_get_result_safe($stmt);
        $exists = $res instanceof mysqli_result && $res->num_rows > 0;
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        $stmt->close();
        return $exists;
    }
    return false;
}

/**
 * Checks if a specific column exists in a given table within a database schema.
 * This is useful for schema-tolerant operations.
 *
 * @param mysqli $conn The database connection.
 * @param string $db The name of the database schema to check.
 * @param string $table The table name.
 * @param string $col The column name.
 * @return bool True if the column exists, false otherwise.
 */
function has_table_column(mysqli $conn, string $db, string $table, string $col): bool
{
    // Using INFORMATION_SCHEMA is a standard way to check schema details safely.
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('sss', $db, $table, $col);
        $stmt->execute();
        $res = stmt_get_result_safe($stmt);
        // Uses the existing stmt_get_result_safe helper
        $exists = $res instanceof mysqli_result && $res->num_rows > 0;
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        $stmt->close();
        return $exists;
    }
    return false;
}

/**
 * Helper function used by get_db_schema for determining the cache file path.
 *
 * @param string $key Unique identifier for the cache entry.
 * @return string The full path to the cache file.
 */
function cache_path(string $key): string
{
    // Uses the system's temporary directory for caching
    return sys_get_temp_dir() . '/invapp_' . md5($key) . '.json';
}

/**
 * Fetches the database schema (table and column definitions) and caches it
 * in the temporary directory for a specified duration.
 *
 * @param mysqli $conn The database connection.
 * @param int $ttl The time-to-live for the cache in seconds.
 * @return array<string, array<int, array<string, string>>> The cached schema data.
 */
function get_db_schema(mysqli $conn, int $ttl): array
{
    // Cache key for schema version 1
    $ckey = 'schema_v1';
    $f = cache_path($ckey);

    // Check cache existence and freshness
    if (@is_file($f) && (time() - filemtime($f) < $ttl)) {
        // Using the json_read helper
        $contents = @file_get_contents($f);
        if ($contents !== false) {
            return json_read($contents);
        }
    }

    $schema = [];
    $tables = [];

    // 1. Get all table names
    $res = $conn->query("SHOW TABLES");
    if ($res) {
        while ($row = $res->fetch_array()) {
            $tables[] = $row[0];
        }
        $res->free();
    }

    // 2. Get columns for each table
    foreach ($tables as $t) {
        $cols = [];
        // Using SHOW FULL COLUMNS for maximum detail
        $r = $conn->query("SHOW FULL COLUMNS FROM `" . $conn->real_escape_string($t) . "`");
        if ($r) {
            while ($c = $r->fetch_assoc()) {
                $cols[] = ['name' => $c['Field'], 'type' => $c['Type'], 'null' => $c['Null'], 'key' => $c['Key']];
            }
            $r->free();
            $schema[$t] = $cols;
        }
    }

    // 3. Write to cache file
    file_put_contents($f, json_write($schema));
    return $schema;
}


// ---------- JSON and HTTP Helpers ----------

/**
 * Safely decodes a JSON string into a PHP value.
 *
 * @param string $s The JSON string.
 * @param bool $assoc When true, returned objects are converted to associative arrays.
 * @return mixed The decoded value, or an empty array/null if decoding failed.
 */
function json_read(string $s, bool $assoc = true)
{
    $d = json_decode($s, $assoc);
    // Returns an empty array if decoding fails to avoid errors when iterating.
    return $d === null ? [] : $d;
}

/**
 * Encodes a PHP value into a JSON string with readability flags.
 *
 * @param mixed $v The value to encode.
 * @return string The JSON string.
 */
function json_write($v): string
{
    // Use flags for better readability and web compatibility
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Executes an HTTP POST request using cURL and returns the response details.
 *
 * @param string $url The API endpoint URL.
 * @param array $headers Additional headers (e.g., Authorization).
 * @param array $payload The data payload to send (will be JSON encoded).
 * @return array{0: int, 1: string, 2: string, 3: string} [$http_code, $raw_response_body, $curl_error, $request_body]
 */
function ai_http(string $url, array $headers, array $payload): array
{
    $ch = curl_init($url);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_HTTPHEADER      => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS      => $body,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 60,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_USERAGENT       => 'TPBC-InvApp/1.0',
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = null;
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
    }

    return [$code, (string)$raw, (string)$err, $body, $decoded];
}


// Inside helpers.php, after the fetch_stock function

/**
 * Removes a specified quantity of a SKU from a specific location and logs the movement.
 *
 * @param mysqli $conn The database connection object.
 * @param int $loc_id The ID of the location to remove inventory from.
 * @param int $sku_id The ID of the SKU to remove.
 * @param int $quantity The amount to remove. Must be > 0.
 * @param int $user_id The ID of the user performing the removal.
 * @param string $movement_type The type of movement (e.g., 'OUT_ADJUSTMENT').
 * @return bool True on successful removal and logging, false on failure (e.g., insufficient stock).
 */
// Inside helpers.php
/**
 * Removes a specified quantity of a SKU from a specific location and logs the movement.
 *
 * @param mysqli $conn The database connection object.
 * @param int $loc_id The ID of the location to remove inventory from.
 * @param int $sku_id The ID of the SKU to remove.
 * @param int $quantity The amount to remove. Must be > 0.
 * @param int $user_id The ID of the user performing the removal.
 * @return bool True on successful removal and logging, false on failure (e.g., insufficient stock).
 */
function removeInventoryFromLocation(
    mysqli $conn,
    int $loc_id,
    int $sku_id,
    int $quantity,
    int $user_id
): bool {
    if ($quantity <= 0) {
        return false;
    }

    try {
        // Start Transaction
        $conn->begin_transaction();

        // 1. Check current quantity and lock the row
        $stmt_check = $conn->prepare("SELECT quantity FROM inventory WHERE loc_id = ? AND sku_id = ? FOR UPDATE");
        $stmt_check->bind_param('ii', $loc_id, $sku_id);
        $stmt_check->execute();
        $res = $stmt_check->get_result();
        $inventory_record = $res->fetch_assoc();
        $stmt_check->close();

        // Insufficient stock check
        if (!$inventory_record || (int) $inventory_record['quantity'] < $quantity) {
            $conn->rollback();
            // Log for debugging:
            error_log("Removal failure: Insufficient stock for SKU {$sku_id} at location {$loc_id}. Requested: {$quantity}, Available: " . (int) ($inventory_record['quantity'] ?? 0));
            return false;
        }

        // 2. Update inventory (decrement quantity)
        $stmt_update = $conn->prepare("
            UPDATE inventory
            SET quantity = quantity - ?
            WHERE loc_id = ? AND sku_id = ? AND quantity >= ?
        ");
        $stmt_update->bind_param('iiii', $quantity, $loc_id, $sku_id, $quantity);
        $stmt_update->execute();

        if ($stmt_update->affected_rows === 0) {
            $conn->rollback();
            return false;
        }
        $stmt_update->close();

        // 3. Log movement (negative quantity for removal)
        $log_quantity = -$quantity;
        // CORRECTED: Changed 'quantity' to 'quantity_change' and hardcoded movement_type to 'ADJUST'
        $movement_type = 'ADJUST';
        $stmt_log = $conn->prepare("
            INSERT INTO inventory_movements (loc_id, sku_id, quantity_change, user_id, movement_type)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt_log->bind_param('iiiss', $loc_id, $sku_id, $log_quantity, $user_id, $movement_type);
        $stmt_log->execute();
        $stmt_log->close();

        // Commit Transaction
        $conn->commit();
        return true;

    } catch (\Throwable $e) {
        $conn->rollback();
        // Check your PHP error log for the output of this line for specific errors
        error_log("Inventory removal failed (DB EXCEPTION): " . $e->getMessage() .
            " SQLSTATE: " . ($conn->sqlstate ?? 'N/A') .
            " SKU: {$sku_id}, Loc: {$loc_id}");
        return false;
    }
}


// utils/helpers.php (or similar)

/**
 * Looks up SKU details and current stock level at a given location.
 *
 * @param mysqli $conn The database connection.
 * @param string $sku_num The SKU number to look up.
 * @param int $loc_id The ID of the location to check inventory against.
 * @return array|null An array of SKU details including on_hand quantity, or null if not found.
 */
function get_sku_details_with_inventory(mysqli $conn, string $sku_num, int $loc_id): ?array
{
    $sku_num = trim($sku_num);
    if (empty($sku_num) || $loc_id === 0) {
        return null;
    }

    $sql = "
        SELECT 
            s.id AS sku_id,
            s.sku_num AS sku,
            s.`desc`,
            IFNULL(i.quantity, 0) AS on_hand
        FROM sku s
        LEFT JOIN inventory i ON i.sku_id = s.id AND i.loc_id = ?
        WHERE s.sku_num = ? AND s.status = 'ACTIVE'
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $loc_id, $sku_num);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($r = $res->fetch_assoc()) {
        return [
            'sku_id' => (int) $r['sku_id'],
            'sku' => (string) $r['sku'],
            'desc' => (string) $r['desc'],
            'on_hand' => (int) $r['on_hand']
        ];
    }

    $stmt->close();
    return null;
}

// EOF