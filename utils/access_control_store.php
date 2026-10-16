<?php
// utils/access_control_store.php
// Shared helpers for loading/saving page access rules.

if (!function_exists('ac_normalize_roles')) {
    function ac_normalize_roles(array $roles): array
    {
        $roles = array_values(array_unique(array_map('intval', $roles)));
        sort($roles);
        return $roles;
    }
}

if (!function_exists('ac_is_dev_request')) {
    function ac_is_dev_request(?string $scriptName = null): bool
    {
        $scriptName = $scriptName ?? ($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptName = str_replace('\\', '/', $scriptName);
        return stripos($scriptName, '/dev/') !== false;
    }
}

if (!function_exists('ac_normalize_page_key')) {
    /**
     * Returns a stable page key relative to /dev when applicable
     * (e.g., "inventory/import.php").
     */
    function ac_normalize_page_key(?string $scriptName = null): string
    {
        $scriptName = $scriptName ?? ($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptName = str_replace('\\', '/', $scriptName);
        $scriptName = strtok($scriptName, '?') ?: $scriptName;
        $pos = stripos($scriptName, '/dev/');
        if ($pos !== false) {
            $key = substr($scriptName, $pos + 5);
        } else {
            $key = ltrim($scriptName, '/');
        }
        return $key !== '' ? $key : basename($scriptName);
    }
}

if (!function_exists('ac_can_access_page')) {
    /**
     * Checks if a role can access a page using canonical key and basename fallback.
     */
    function ac_can_access_page(int $roleId, string $pageKey, array $map): bool
    {
        $allowed = $map[$pageKey] ?? $map[basename($pageKey)] ?? [];
        return in_array($roleId, $allowed, true);
    }
}

if (!function_exists('ac_access_table_name')) {
    function ac_access_table_name(?string $scriptName = null): string
    {
        return ac_is_dev_request($scriptName) ? 'page_access_dev' : 'page_access';
    }
}

if (!function_exists('ac_sanitize_access_table_name')) {
    function ac_sanitize_access_table_name(?string $tableName = null): string
    {
        $tableName = $tableName ?? ac_access_table_name();
        return $tableName === 'page_access_dev' ? 'page_access_dev' : 'page_access';
    }
}

if (!function_exists('ac_get_user_db')) {
    /**
     * Builds (and caches) a connection to the users database without overwriting
     * $conn in callers.
     */
    function ac_get_user_db(): ?mysqli
    {
        static $cached = null;
        if ($cached instanceof mysqli) {
            return $cached;
        }

        $conn = null;
        include __DIR__ . '/../dbusers.php';
        if ($conn instanceof mysqli && !$conn->connect_errno) {
            $cached = $conn;
            return $cached;
        }
        return null;
    }
}

if (!function_exists('ac_ensure_access_table')) {
    function ac_ensure_access_table(mysqli $db, ?string $tableName = null): void
    {
        $tableName = ac_sanitize_access_table_name($tableName);
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$tableName}` (
  page_key VARCHAR(255) NOT NULL,
  role_id INT NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (page_key, role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->query($sql);
    }
}

if (!function_exists('ac_table_has_rows')) {
    function ac_table_has_rows(mysqli $db, string $tableName): bool
    {
        $tableName = ac_sanitize_access_table_name($tableName);
        $res = $db->query("SELECT 1 FROM `{$tableName}` LIMIT 1");
        if (!$res instanceof mysqli_result) {
            return false;
        }
        $hasRows = $res->num_rows > 0;
        $res->free();
        return $hasRows;
    }
}

if (!function_exists('ac_bootstrap_access_table')) {
    function ac_bootstrap_access_table(mysqli $db, ?string $tableName = null): void
    {
        $tableName = ac_sanitize_access_table_name($tableName);
        ac_ensure_access_table($db, $tableName);

        if ($tableName !== 'page_access_dev' || ac_table_has_rows($db, $tableName)) {
            return;
        }

        ac_ensure_access_table($db, 'page_access');
        if (!ac_table_has_rows($db, 'page_access')) {
            return;
        }

        $db->begin_transaction();
        $db->query("TRUNCATE TABLE `{$tableName}`");
        $db->query(
            "INSERT INTO `{$tableName}` (page_key, role_id, allowed)
             SELECT page_key, role_id, allowed
             FROM `page_access`"
        );
        $db->commit();
    }
}

if (!function_exists('ac_fetch_access_overrides')) {
    /**
     * @return array<string, array> Map of page_key => allowed role ids
     * (empty array means explicitly blocked)
     */
    function ac_fetch_access_overrides(): array
    {
        $db = ac_get_user_db();
        if (!$db instanceof mysqli) {
            return [];
        }

        $tableName = ac_access_table_name();
        ac_bootstrap_access_table($db, $tableName);

        $overrides = [];
        $res = $db->query("SELECT page_key, role_id, allowed FROM `{$tableName}`");
        while ($res && ($row = $res->fetch_assoc())) {
            $key = trim($row['page_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $allowed = (int) ($row['allowed'] ?? 0) === 1;
            $roleId = (int) ($row['role_id'] ?? 0);
            if (!$allowed && $roleId === 0) {
                $overrides[$key] = [];
                continue;
            }
            $overrides[$key][] = $roleId;
        }
        if ($res instanceof mysqli_result) {
            $res->free();
        }

        foreach ($overrides as $key => $roles) {
            $overrides[$key] = ac_normalize_roles($roles);
        }
        return $overrides;
    }
}

if (!function_exists('ac_save_access_overrides')) {
    /**
     * Persists the provided access matrix as the new override set.
     *
     * @param array<string, array<int>> $matrix
     */
    function ac_save_access_overrides(array $matrix): bool
    {
        $db = ac_get_user_db();
        if (!$db instanceof mysqli) {
            return false;
        }

        $tableName = ac_access_table_name();
        ac_bootstrap_access_table($db, $tableName);

        $db->begin_transaction();
        $db->query("TRUNCATE TABLE `{$tableName}`");

        $stmt = $db->prepare("INSERT INTO `{$tableName}` (page_key, role_id, allowed) VALUES (?, ?, ?)");
        if (!$stmt) {
            $db->rollback();
            return false;
        }

        foreach ($matrix as $pageKey => $roles) {
            $roles = ac_normalize_roles($roles);
            if (empty($roles)) {
                $zero = 0;
                $allowed = 0;
                $stmt->bind_param('sii', $pageKey, $zero, $allowed);
                $stmt->execute();
                continue;
            }
            foreach ($roles as $roleId) {
                $allowed = 1;
                $stmt->bind_param('sii', $pageKey, $roleId, $allowed);
                $stmt->execute();
            }
        }

        $stmt->close();
        $db->commit();
        return true;
    }
}

if (!function_exists('ac_build_permissions')) {
    /**
     * Combines defaults with saved overrides; overrides win (including explicit deny).
     */
    function ac_build_permissions(array $defaults, string $modulePrefix = ''): array
    {
        $map = $defaults;
        $overrides = ac_fetch_access_overrides();

        foreach ($overrides as $pageKey => $roles) {
            $normalizedKey = $pageKey;
            if ($modulePrefix !== '' && strpos($pageKey, $modulePrefix) === 0) {
                $normalizedKey = substr($pageKey, strlen($modulePrefix));
            } elseif ($modulePrefix !== '' && strpos($pageKey, '/') !== false) {
                continue;
            }
            $map[$normalizedKey] = ac_normalize_roles($roles);
        }

        foreach ($map as $key => $roles) {
            $map[$key] = ac_normalize_roles($roles);
        }
        return $map;
    }
}
