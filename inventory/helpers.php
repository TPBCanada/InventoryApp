<?php
declare(strict_types=1);

// --- 1. HTML Helper ---

if (!function_exists('h')) {
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
}


// --- 2. Generic Database Helpers ---

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

// --- 3. Location Helpers ---

if (!function_exists('find_loc_id')) {
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
                $id = (int) $r['id'];
            }
            $res->free();
        } else {
            $st->bind_result($idTmp);
            if ($st->fetch()) {
                $id = (int) $idTmp;
            }
        }

        $st->close();
        return $id ?: null;
    }
}

if (!function_exists('ensure_location')) {
    /**
     * Ensures a location exists in the database and returns its ID.
     * Inserts the location if it does not exist, applying special handling
     * for rows defined in SPECIAL_ROWS to satisfy database constraints.
     *
     * @param mysqli $conn The database connection.
     * @param string $row_code Row code.
     * @param string $bay_num Bay number.
     * @param string $level_code Level code.
     * @param string $side Side (F/B).
     * @return int The ID of the location.
     * @throws mysqli_sql_exception If database operation fails (e.g., check constraint violation).
     */
    function ensure_location(mysqli $conn, string $row_code, string $bay_num, string $level_code, string $side): int
    {
        $row_code = strtoupper(trim($row_code));
        $bay_num = trim($bay_num);
        $level_code = trim($level_code);
        $side = trim($side);

        // --- FIX: Apply placeholder values for special rows if B/L/S are empty ---
        $is_special = in_array($row_code, SPECIAL_ROWS, true);

        if ($is_special) {
            // For special rows, force Bay to '0' (safe for numeric columns) and 
            // Level/Side to '-' (safe for string constraints) if they are empty.
            if ($bay_num === '') {
                $bay_num = '01';
            }
            if ($level_code === '') {
                $level_code = '11';
            }
            if ($side === '') {
                $side = 'F';
            }
        }
        // --------------------------------------------------------------------------

        // 1. Try to find the location
        $stmt_find = $conn->prepare("
            SELECT id
            FROM location
            WHERE row_code = ?
              AND CAST(bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
              AND level_code = ?
              AND side = ?
            LIMIT 1
        ");
        $stmt_find->bind_param('ssss', $row_code, $bay_num, $level_code, $side);
        $stmt_find->execute();
        $result = $stmt_find->get_result();

        if ($result->num_rows > 0) {
            return (int) $result->fetch_column();
        }

        // 2. Location not found, insert it
        $stmt_insert = $conn->prepare("
            INSERT INTO location (row_code, bay_num, level_code, side)
            VALUES (?, ?, ?, ?)
        ");
        $stmt_insert->bind_param('ssss', $row_code, $bay_num, $level_code, $side);
        $stmt_insert->execute();

        $insert_id = $conn->insert_id;
        $stmt_insert->close();

        return (int) $insert_id;
    }
}

if (!function_exists('qs_with')) {
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
}

if (!function_exists('load_location_dropdown_data')) {
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
}

if (!function_exists('detect_location_pk')) {
    /**
     * Detects the primary key column used for the location table (either 'id' or 'loc_id').
     * This is used to ensure queries involving the location table use the correct column name.
     *
     * @param mysqli $conn The database connection.
     * @return string The detected primary key column name ('id' or 'loc_id').
     */
    function detect_location_pk(mysqli $conn): string
    {
        // NOTE: This assumes get_db_name() is available elsewhere or implemented here.
        // I will assume get_db_name() is implemented later in the file or available.
        // For now, using a direct query or assuming a default for simplicity.
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
}

if (!function_exists('resolve_location_from_params')) {
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
            $norm_bay = '0';    // edge case: actual "0"
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
}

// --- 4. Database Statement/Result Helpers ---

if (!function_exists('fetch_stock_by_loc_id')) {
    // ... (fetch_stock_by_loc_id function) ...
}

if (!function_exists('fetch_stock')) {
    /**
     * Fetches the live on-hand stock for a specific location (hiding 0/NULL quantities).
     * Applies special row logic to use placeholder values for missing components 
     * before querying the database.
     *
     * @param mysqli $conn The database connection.
     * @param string $row The row code.
     * @param string $bay The bay number.
     * @param string $lvl The level code.
     * @param string $side The side (F or B).
     * @return array An array of associative arrays: [{'sku_num': 'ABC', 'quantity': 10}]. Empty array on error or no stock.
     */
    function fetch_stock(mysqli $conn, string $row, string $bay, string $lvl, string $side): array
    {
        // 1. Clean and standardize inputs
        $row = strtoupper(trim($row));
        $bay = trim($bay);
        $lvl = trim($lvl);
        $side = trim($side);

        if (!$row)
            return [];

        $is_special = in_array($row, SPECIAL_ROWS, true);

        // 2. APPLY SPECIAL ROW LOGIC: Substitute database placeholders if components are empty
        if ($is_special) {
            // If the row is special and the component is empty, substitute the placeholder values
            if ($bay === '') {
                $bay = '01';
            }
            if ($lvl === '') {
                $lvl = '11';
            }
            if ($side === '') {
                $side = 'F';
            }
        }

        // Standard check: for non-special rows, all components must be present
        if (!$is_special && (!$bay || !$lvl || !$side)) {
            return [];
        }

        // 3. Perform the query (pivot through pallets to respect pallet-based inventory)
        $sql = "
            SELECT S.sku_num, SUM(COALESCE(I.quantity,0)) AS quantity
            FROM location L
            JOIN pallet P   ON P.loc_id = L.id
            JOIN inventory I ON I.pallet_id = P.id
            JOIN sku S      ON S.id = I.sku_id
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
            // Use the potentially corrected $bay, $lvl, and $side variables
            $st->bind_param('ssss', $row, $bay, $lvl, $side);
            $st->execute();

            // Using the portable helper for results
            $out = stmt_fetch_all_assoc($st);

            $st->close();
            return $out;
        } catch (\Throwable $e) {
            error_log('[fetch_stock] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('log_inventory_movement')) {
    function log_inventory_movement(mysqli $conn, int $sku_id, int $loc_id, int $qty_change, string $movement_type, string $reference, int $user_id): void
    {
        $stmt = $conn->prepare('
            INSERT INTO inventory_movements (sku_id, loc_id, quantity_change, movement_type, reference, user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('iiissi', $sku_id, $loc_id, $qty_change, $movement_type, $reference, $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('stmt_get_result_safe')) {
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
}

if (!function_exists('stmt_fetch_all_assoc')) {
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
}


// --- 5. Database Schema Helpers (INFORMATION_SCHEMA based) ---

if (!function_exists('get_db_name')) {
    /**
     * Retrieves the current database name for the connection.
     *
     * @param mysqli $conn The database connection.
     * @return string The current database name.
     */
    function get_db_name(mysqli $conn): string
    {
        return $conn->current_database ?? '';
    }
}

if (!function_exists('table_exists')) {
    /**
     * Checks if a specific table exists in the connected database.
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
}

if (!function_exists('has_table_column')) {
    /**
     * Checks if a specific column exists in a given table within a database schema.
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
}

if (!function_exists('cache_path')) {
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
}

if (!function_exists('get_db_schema')) {
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
        try {
            $res = $conn->query("SHOW TABLES");
            if ($res) {
                while ($row = $res->fetch_array()) {
                    $tables[] = $row[0];
                }
                $res->free();
            }
        } catch (\mysqli_sql_exception $e) {
            error_log('[get_db_schema] SHOW TABLES failed: ' . $e->getMessage());
            return [];
        }

        // 2. Get columns for each table
        foreach ($tables as $t) {
            $cols = [];
            // Using SHOW FULL COLUMNS for maximum detail
            try {
                $r = $conn->query("SHOW FULL COLUMNS FROM `" . $conn->real_escape_string($t) . "`");
                if ($r) {
                    while ($c = $r->fetch_assoc()) {
                        $cols[] = ['name' => $c['Field'], 'type' => $c['Type'], 'null' => $c['Null'], 'key' => $c['Key']];
                    }
                    $r->free();
                    $schema[$t] = $cols;
                }
            } catch (\mysqli_sql_exception $e) {
                // Skip broken views/tables but continue building the rest of the schema.
                error_log('[get_db_schema] Skipping table ' . $t . ': ' . $e->getMessage());
                continue;
            }
        }

        // 3. Write to cache file
        file_put_contents($f, json_write($schema));
        return $schema;
    }
}


// --- 6. JSON and HTTP Helpers ---

if (!function_exists('json_read')) {
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
}

if (!function_exists('json_write')) {
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
}

if (!function_exists('ai_http')) {
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
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TPBC-InvApp/1.0',
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
        }

        return [$code, (string) $raw, (string) $err, $body, $decoded];
    }
}

// --- 7. SKU/Inventory Lookup Helper ---

if (!function_exists('get_sku_details_with_inventory')) {
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
}

// --- 8. Core Inventory Transaction Helper ---

if (!function_exists('safe_inventory_change')) {
    /**
     * Core transactional function for inventory changes (Add/Remove/Adjust).
     * Handles the logic for updating the inventory table and logging the movement.
     *
     * @param mysqli $conn The database connection object.
     * @param int $sku_id The ID of the SKU.
     * @param int $loc_id The ID of the location.
     * @param int $quantity_change The amount to change (positive for add, negative for remove). Must not be 0.
     * @param string $movement_type The type of movement (e.g., 'IN', 'OUT', 'ADJUST').
     * @param int $user_id The ID of the user performing the movement.
     * @param string $note Optional reference/note.
     * @return bool True on successful completion, false on soft failure (e.g., general exception/rollback).
     * @throws \RuntimeException on hard failure (e.g., insufficient stock for a removal).
     */
    function safe_inventory_change(mysqli $conn, int $sku_id, int $loc_id, int $quantity_change, string $movement_type, int $user_id, string $note = ''): bool
    {
        if ($quantity_change === 0) {
            return true; // No change requested.
        }

        // 1. Start Transaction
        if (!$conn->begin_transaction()) {
            error_log("Failed to start transaction.");
            return false;
        }

        try {
            $st_inv = null;
            // --- 2. UPDATE/INSERT inventory TABLE ---
            if ($quantity_change > 0) {
                // --- IN/ADD Movement (Insertion or Addition) ---
                $update_sql = "
                    INSERT INTO inventory (sku_id, loc_id, quantity) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    quantity = quantity + VALUES(quantity);
                ";
                $st_inv = $conn->prepare($update_sql);
                $st_inv->bind_param('iii', $sku_id, $loc_id, $quantity_change);

            } elseif ($quantity_change < 0) {
                // --- OUT/REMOVE Movement (Guarded Decrement) ---
                $pos_qty_req = abs($quantity_change);
                $update_sql = "
                    UPDATE inventory
                    SET quantity = quantity + ? 
                    WHERE sku_id = ? AND loc_id = ?
                      AND quantity >= ?;
                ";
                $st_inv = $conn->prepare($update_sql);
                $st_inv->bind_param('iiii', $quantity_change, $sku_id, $loc_id, $pos_qty_req);
            }

            if ($st_inv) {
                if (!$st_inv->execute()) {
                    throw new Exception("Inventory update failed: " . $st_inv->error);
                }

                if ($quantity_change < 0 && $st_inv->affected_rows === 0) {
                    // Hard failure: Insufficient stock or SKU not found at location.
                    throw new RuntimeException("Removal failed: Insufficient stock or SKU not found at location.");
                }
                $st_inv->close();
            }


            // --- 3. INSERT into inventory_movements TABLE (Log the ledger entry) ---
            $insert_sql = "
                INSERT INTO inventory_movements
                (sku_id, loc_id, quantity_change, movement_type, user_id, reference, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW());
            ";
            $st_mov = $conn->prepare($insert_sql);
            $st_mov->bind_param(
                'iiisis',
                $sku_id,
                $loc_id,
                $quantity_change, // Logs the signed change
                $movement_type,
                $user_id,
                $note
            );

            if (!$st_mov->execute()) {
                throw new Exception("Movement log insert failed: " . $st_mov->error);
            }
            $st_mov->close();


            // 4. COMMIT TRANSACTION
            if (!$conn->commit()) {
                throw new Exception("Transaction commit failed.");
            }
            return true;

            // 5. CATCH/ROLLBACK
        } catch (\RuntimeException $e) {
            if ($conn->inTransaction()) {
                $conn->rollback();
            }
            throw $e; // Rethrow RuntimeException for specific failure handling
        } catch (\Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollback();
            }
            error_log("Inventory transaction failed and rolled back: " . $e->getMessage());
            return false;
        }
    }
}

// --- 9. Inventory Wrapper Functions (from inventory_ops.php) ---

if (!function_exists('addInventory')) {
    /**
     * Adds a specified quantity of a SKU to a location.
     * This is a wrapper for safe_inventory_change() for positive quantity movements.
     *
     * @param mysqli $conn The database connection object.
     * @param int $sku_id The ID of the SKU to add.
     * @param int $loc_id The ID of the location to add inventory to.
     * @param int $quantity The amount to add (must be > 0).
     * @param int $user_id The ID of the user performing the action.
     * @param string $note Optional note/reference for the ledger.
     * @param string $movement_type The movement type (defaults to 'IN').
     * @return bool True on success, false on failure (or if quantity <= 0).
     */
    function addInventory(
        mysqli $conn,
        int $sku_id,
        int $loc_id,
        int $quantity,
        int $user_id,
        string $note = '',
        string $movement_type = 'IN'
    ): bool {
        if ($quantity <= 0) {
            error_log("Attempted to add non-positive quantity: {$quantity}.");
            return false;
        }

        return safe_inventory_change(
            $conn,
            $sku_id,
            $loc_id,
            $quantity, // Passed as positive integer
            $movement_type,
            $user_id,
            $note
        );
    }
}

if (!function_exists('removeInventory')) {
    /**
     * Removes a specified quantity of a SKU from a specific location and logs the movement.
     *
     * @param mysqli $conn The database connection object.
     * @param int $sku_id The ID of the SKU to remove.
     * @param int $loc_id The ID of the location to remove inventory from.
     * @param int $quantity The amount to remove (must be > 0).
     * @param int $user_id The ID of the user performing the action.
     * @param string $note Optional note/reference for the ledger.
     * @param string $movement_type The movement type (defaults to 'OUT').
     * @return bool True on success, false on failure.
     * @throws \RuntimeException If removal fails due to insufficient stock.
     */
    function removeInventory(
        mysqli $conn,
        int $sku_id,
        int $loc_id,
        int $quantity,
        int $user_id,
        string $note = '',
        string $movement_type = 'OUT'
    ): bool {
        if ($quantity <= 0) {
            error_log("Attempted to remove non-positive quantity: {$quantity}.");
            return false;
        }

        // Pass the quantity as a NEGATIVE integer to the core function
        return safe_inventory_change(
            $conn,
            $sku_id,
            $loc_id,
            -$quantity, // Passed as negative integer
            $movement_type,
            $user_id,
            $note
        );
    }
}

if (!function_exists('validate_select_only')) {
    function validate_select_only(string &$sql, array &$errors): bool
    {
        $sql = trim($sql);

        // 1. SANITIZATION: Remove trailing semicolon
        if (substr($sql, -1) === ';') {
            $sql = substr($sql, 0, -1);
            $sql = trim($sql);
        }

        // --- VALIDATION: Use an UPPERCASE COPY for checking keywords ONLY ---
        $sql_upper = strtoupper($sql);

        // 2. Must start with SELECT or WITH
        if (!str_starts_with($sql_upper, 'SELECT') && !str_starts_with($sql_upper, 'WITH')) {
            $errors[] = 'Query must start with SELECT or WITH.';
            return false;
        }

        // 3. Must not contain DDL/DML keywords (check against UPPERCASE copy)
        $dml_keywords = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'CREATE', 'REPLACE'];
        $pattern = '/\b(' . implode('|', $dml_keywords) . ')\b/';
        if (preg_match($pattern, $sql_upper, $matches)) {
            $errors[] = 'Disallowed keyword found: ' . $matches[1] . '.';
            return false;
        }

        // 4. FINAL SANITIZATION: Force ENTIRE query to lowercase for Linux MySQL
        // This line is CRITICAL and must be after validation.
        $sql = strtolower($sql); // THIS IS THE FIX.

        return true;
    }
}

if (!function_exists('wrap_with_limit')) {
    /**
     * Wraps a SELECT query to enforce LIMIT and OFFSET caps for safety.
     * It first removes any existing LIMIT/OFFSET to prevent syntax errors.
     *
     * @param string $sql The original SQL query.
     * @param int $limit The maximum number of rows.
     * @param int $offset The starting offset.
     * @return string The wrapped, capped SQL query.
     */
    function wrap_with_limit(string $sql, int $limit, int $offset): string
    {
        // 1. Remove any existing LIMIT and OFFSET clauses from the query.
        // This regex handles LIMIT/OFFSET in various casings, including the one generated by the AI.
        // It looks for "LIMIT [anything] (OFFSET [anything])?$"
        $sql_clean = preg_replace(
            '/\s+LIMIT\s+.*(\s+OFFSET\s+.*)?$/is',
            '',
            $sql
        );

        // 2. Append the enforced safety limit.
        return "{$sql_clean} LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
    }
}

class AiService
{
    private string $apiKey;
    private string $targetTable = 'sku';
    private string $model = 'gpt-3.5-turbo';

    public function __construct(string $apiKey)
    {
        if (empty($apiKey)) {
            throw new InvalidArgumentException('OpenAI API key cannot be empty.');
        }
        $this->apiKey = $apiKey;
    }

    /**
     * AI function to suggest the correct format for import using OpenAI API.
     * @return string The AI suggestion or an error message.
     */
    public function getFormatSuggestion(mysqli $conn, array $columns): string
    {
        try {
            // 1. Get the actual database column names
            $columnsRes = $conn->query("SHOW COLUMNS FROM {$this->targetTable}");
            if (!$columnsRes) {
                return "Could not read the structure of the '{$this->targetTable}' table.";
            }
            $dbColumns = [];
            while ($col = $columnsRes->fetch_assoc()) {
                $dbColumns[] = $col['Field'];
            }
            $columnsRes->free();

            // 2. Prepare the prompt for the AI
            $currentCols = implode(', ', array_map('trim', $columns));
            $requiredCols = implode(', ', $dbColumns);

            $prompt = "You are an expert data import assistant. The user is trying to import data into the '{$this->targetTable}' table with columns: {$requiredCols}. They have uploaded a file with the following column headers: {$currentCols}. Analyze the uploaded columns and suggest an ideal header row for their CSV/XLSX file to ensure successful import into '{$this->targetTable}'. The most important column for this import is 'sku_num'. Only output the suggested, comma-separated header row.";

            // 3. Make the API call (Using cURL or a dedicated library if available)
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    "Authorization: Bearer {$this->apiKey}",
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a precise data formatting expert.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.1,
                ]),
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($httpCode !== 200) {
                $errorData = json_decode($response ?: '', true);
                $errorMsg = $errorData['error']['message'] ?? 'Unknown API error.';
                return "AI Error (Code: {$httpCode}): {$errorMsg}";
            }

            $data = json_decode($response ?: '', true);
            $suggestion = trim($data['choices'][0]['message']['content'] ?? 'No suggestion provided.');
            return $suggestion;

        } catch (Throwable $e) {
            return "Internal Error: " . $e->getMessage();
        }
    }
}

if (!function_exists('getPalletLocationDisplay')) {
    /**
     * Creates a standard display string for a Pallet's physical location.
     * @param array $pallet_record An associative array containing pallet and location data.
     * @return string The formatted location code.
     */
    function getPalletLocationDisplay(array $pallet_record): string
    {
        $row_code = $pallet_record['row_code'] ?? '';

        // Check against special rows (assuming SPECIAL_ROWS is globally accessible or passed)
        if (defined('SPECIAL_ROWS') && in_array(strtoupper($row_code), SPECIAL_ROWS)) {
            return $row_code;
        }

        $bay_num = $pallet_record['bay_num'] ?? '';
        $level_code = $pallet_record['level_code'] ?? '';
        $side = $pallet_record['side'] ?? '';

        if ($row_code && $bay_num && $level_code && $side) {
            return "{$row_code}-{$bay_num}-{$level_code}-{$side}";
        }

        return "{$row_code}{$bay_num}{$level_code}{$side}";
    }
}

if (!function_exists('getPalletAndLocationIdByTag')) {
    /**
     * Looks up a Pallet ID and its current Location ID by its Pallet Tag.
     * @param mysqli $conn The database connection object.
     * @param string $pallet_tag The unique tag of the pallet (e.g., 'PAL-A01').
     * @return array|null An array containing ['pallet_id', 'loc_id'] or null if not found.
     */
    function getPalletAndLocationIdByTag(mysqli $conn, string $pallet_tag): ?array
    {
        if (empty($pallet_tag)) {
            return null;
        }

        $stmt = $conn->prepare("
        SELECT p.id AS pallet_id, p.loc_id 
        FROM pallet p 
        WHERE p.pallet_tag = ?
    ");
        $stmt->bind_param('s', $pallet_tag);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        if ($data) {
            return [
                'pallet_id' => (int) $data['pallet_id'],
                'loc_id' => (int) $data['loc_id'],
            ];
        }

        return null;
    }
}

/**
 * Fetches the detailed contents of a pallet and returns it as an HTML table.
 * * @param \mysqli $conn The active database connection object.
 * @param int $pallet_id The ID of the pallet to query.
 * @return string The HTML table containing SKU and quantity data, or an error message.
 */
function getPalletContentsHtml(\mysqli $conn, int $pallet_id): string
{
    // FINAL CORRECTED QUERY:
    // 1. SELECTs data from the 'sku' table (aliased as s)
    // 2. FROM the 'inventory' table (aliased as pi, for Pallet Inventory)
    // 3. INNER JOINs the two tables on the foreign key (pi.sku_id = s.id)
    $sql = "
        SELECT 
            s.sku_num AS sku,     -- Get the human-readable SKU number from the sku table
            pi.quantity,          -- Get the quantity from the inventory table
            s.desc AS description -- Get the description from the sku table
        FROM inventory pi         -- The table containing pallet contents is named 'inventory'
        INNER JOIN sku s ON pi.sku_id = s.id 
        WHERE pi.pallet_id = ?
        ORDER BY s.sku_num ASC
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $pallet_id);
        $stmt->execute();
        $result = $stmt->get_result();

    } catch (\mysqli_sql_exception $e) {
        // Switch back to a safe message after debugging is complete
        error_log("SQL Error fetching pallet contents: " . $e->getMessage());
        return '<p class="text-danger m-0 p-2 border-top">Database query failed. Check logs.</p>';
    }

    if ($result->num_rows === 0) {
        return '<p class="text-info m-0 p-2 border-top">No SKUs found for this pallet.</p>';
    }

    // 2. Build the HTML table (this part is already correct)
    $html = '<div class="table-responsive p-3 bg-light">';
    $html .= '<table class="table table-sm table-striped table-hover m-0">';
    $html .= '<thead class="table-dark"><tr><th>SKU</th><th>Quantity</th><th>Description</th></tr></thead>';
    $html .= '<tbody>';

    while ($row = $result->fetch_assoc()) {

        $sku_num = htmlspecialchars($row['sku'] ?? 'N/A');
        $encoded_sku = urlencode($row['sku'] ?? '');

        $html .= '<tr>';
        $html .= '<td>';
        if ($encoded_sku !== '') {
            $html .= '<a href="./invSku.php?q=' . $encoded_sku . '">' . $sku_num . '</a>';
        } else {
            $html .= $sku_num;
        }
        $html .= '</td>';
        $html .= '<td>' . number_format($row['quantity'] ?? 0) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['description'] ?? 'No Description') . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';

    $stmt->close();
    return $html;
}
/**
 * Fetches all users with birthdays and returns them as a clean array.
 */
function get_all_birthdays(mysqli $conn): array
{
    $sql = "
        SELECT
            CONCAT(first_name, ' ', last_name) AS name,
            DATE_FORMAT(birthday, '%Y-%m-%d') AS birthday
        FROM xserqhmy_users.users
        WHERE birthday IS NOT NULL
        ORDER BY MONTH(birthday), DAY(birthday), name
    ";

    $res = $conn->query($sql);
    if (!$res) return [];

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'name' => $row['name'],
            'birthday' => $row['birthday'],
        ];
    }
    return $out;
}
