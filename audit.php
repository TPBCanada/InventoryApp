<?php
// Start the session, necessary for checking user authorization status
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- DATABASE CONNECTION CONFIGURATION (LOADED FROM dbinv.php) ---
// IMPORTANT: Database connection details are now loaded from 'dbinv.php'.
include 'dbinv.php'; // Includes database connection details and establishes the $conn object.

// --- AUTHORIZATION AND ACCESS CONTROL ---
if (!isset($_SESSION['username'])) {
    // Redirect unauthenticated users
    header('Location: login.php');
    exit;
}

// Reconciliation logs are highly sensitive and typically visible only to Admin (1) and Supervisor (2).
$allowed_roles = [1, 2];
if (!in_array((int)($_SESSION['role_id'] ?? 0), $allowed_roles, true)) {
    die("<p style='color:red; text-align:center; font-size:18px; margin-top:50px;'>Access denied. You do not have permission to view the Reconciliation Report.</p>");
}

$username = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];
$role_id  = $_SESSION['role_id'] ?? 0;

require_once __DIR__ . '/templates/access_control.php';


$history_table = 'inventory_movements'; 

// Enable error reporting for procedural MySQL functions used in fetch_all
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Set initial connection status based on the $conn object created in dbinv.php
$conn_error = !($conn instanceof mysqli) || ($conn instanceof mysqli && $conn->connect_error);
$error_message = '';

if ($conn_error) {
    $error_message = "Database connection failed. User **" . htmlspecialchars($username) . "** encountered a connection error. Please ensure the credentials in **dbinv.php** are correct.";
    if (!($conn instanceof mysqli)) {
        $error_message .= " (Connection object is invalid/null, likely due to file path error or failed connection attempt).";
    }
}

// ----------------------------------------------------
// --- General Utility Helpers ---
// ----------------------------------------------------

// Utility function to safely output HTML data
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Function to fetch all results from a query
function fetch_all(mysqli $conn, string $sql): array {
    if (!$conn || $conn->connect_error) return [];
    $rows = [];
    try {
        $res = mysqli_query($conn, $sql); 
        if (!$res) return [];
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    } catch (\mysqli_sql_exception $e) {
        error_log("SQL Error in fetch_all: " . $e->getMessage() . " SQL: " . $sql);
        // Do not die, but return empty and let the UI show an error.
    }
    return $rows;
}

/**
 * Checks if a specific table exists in the connected database.
 */
function table_exists(mysqli $conn, string $table): bool {
    if (!$conn || $conn->connect_error) return false;
    $t = mysqli_real_escape_string($conn, $table);
    $res = @mysqli_query($conn, "SHOW TABLES LIKE '$t'"); 
    return $res && mysqli_num_rows($res) > 0;
}

/**
 * Checks if a specific column exists in a given table.
 */
function column_exists(mysqli $conn, string $table, string $column): bool {
    if (!$conn || $conn->connect_error) return false;
    $sql = "SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    $st = $conn->prepare($sql);
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : ['c'=>0];
    return ((int)$row['c']) > 0;
}


// ----------------------------------------------------
// --- Reconciliation Logic ---
// ----------------------------------------------------

$has_signed_qty_change = true; // assume signed
// If you know your data stores only absolute values in quantity_change,
// set $has_signed_qty_change=false; or detect by looking for negative rows:
if (!$conn_error) {
    // Suppress warnings in case the table doesn't exist yet, which is handled below.
    $probe = @$conn->query("SELECT 1 FROM {$history_table} WHERE quantity_change < 0 LIMIT 1");
    if ($probe && $probe->num_rows === 0) { $has_signed_qty_change = false; }
}

// Build movement sum expression
if ($has_signed_qty_change) {
    $mov_expr = "SUM(h.quantity_change)";
} else {
    // sign by movement_type
    $mov_expr = "SUM(CASE
        WHEN h.movement_type IN ('IN','RECEIVE','TRANSFER_IN','ADJUST_UP') THEN h.quantity_change
        WHEN h.movement_type IN ('OUT','SHIP','DAMAGE','TRANSFER_OUT','ADJUST_DOWN') THEN -h.quantity_change
        ELSE 0 END)";
}

// We’ll compute movement sums per (sku_id, loc_id); if your movements don’t store location,
// you must infer via from/to ids. Adjust the join below to your schema.
// This version reconciles against current inventory table per (sku_id, loc_id).

$has_loc_table = table_exists($conn, 'location');
$loc_name = $has_loc_table
    ? "CONCAT_WS('-', L.row_code, LPAD(L.bay_num,2,'0'), L.level_code, L.side)"
    : "CAST(inv.loc_id AS CHAR)";

$per_loc_sql = "
    SELECT
        s.sku_num,
        inv.sku_id,
        inv.loc_id,
        {$loc_name} AS location_code,
        inv.quantity                AS inventory_qty,
        COALESCE(m.mov_qty, 0)     AS ledger_qty,
        (COALESCE(m.mov_qty,0) - inv.quantity) AS diff,
        m.last_move_at
    FROM inventory inv
    JOIN sku s ON s.id = inv.sku_id
    " . ($has_loc_table ? "LEFT JOIN location L ON L.id = inv.loc_id" : "") . "
    LEFT JOIN (
        SELECT
            h.sku_id,
            /* If your movements store the location in to/from columns, change this to the correct loc_id:
                e.g., COALESCE(h.to_loc_id, h.from_loc_id) AS loc_id */
            h.loc_id,
            {$mov_expr} AS mov_qty,
            MAX(h.created_at) AS last_move_at
        FROM {$history_table} h
        WHERE h.sku_id IS NOT NULL
          AND h.loc_id IS NOT NULL
        GROUP BY h.sku_id, h.loc_id
    ) m ON m.sku_id = inv.sku_id AND m.loc_id = inv.loc_id
    WHERE inv.quantity <> COALESCE(m.mov_qty, 0)
    ORDER BY s.sku_num, location_code
    LIMIT 500
";

// Per-SKU totals (only if sku.quantity column exists)
$has_sku_qty = column_exists($conn, 'sku', 'quantity');
$per_sku_sql = $has_sku_qty ? "
    WITH inv_tot AS (
        SELECT sku_id, SUM(quantity) AS inv_total
        FROM inventory
        GROUP BY sku_id
    ),
    mov_tot AS (
        SELECT h.sku_id, {$mov_expr} AS mov_total
        FROM {$history_table} h
        WHERE h.sku_id IS NOT NULL
        GROUP BY h.sku_id
    )
    SELECT
        s.sku_num,
        s.id AS sku_id,
        COALESCE(i.inv_total,0) AS inventory_total,
        COALESCE(m.mov_total,0) AS ledger_total,
        s.quantity,
        (COALESCE(m.mov_total,0) - COALESCE(i.inv_total,0)) AS diff
    FROM sku s
    LEFT JOIN inv_tot i ON i.sku_id = s.id
    LEFT JOIN mov_tot m ON m.sku_id = s.id
    WHERE s.quantity IS NOT NULL
        AND (COALESCE(m.mov_total,0) <> COALESCE(i.inv_total,0)
             OR COALESCE(i.inv_total,0) <> s.quantity)
    ORDER BY s.sku_num
    LIMIT 500
" : null;

$per_loc_rows = [];
$per_sku_rows = [];
if (!$conn_error) {
    // Only attempt queries if essential tables exist (e.g., inventory, sku, history_table)
    if (table_exists($conn, 'inventory') && table_exists($conn, 'sku') && table_exists($conn, $history_table)) {
        $per_loc_rows = fetch_all($conn, $per_loc_sql);
        if ($has_sku_qty) $per_sku_rows = fetch_all($conn, $per_sku_sql);
    } else {
        $error_message .= "<br>Missing one or more required tables (inventory, sku, or $history_table). Reconciliation skipped.";
    }
    
    if ($conn instanceof mysqli) $conn->close();
}


$title = 'Inventory Reconciliation';
// Start output buffering to capture the main HTML content
ob_start();
?>

<!-- Include Tailwind CSS via CDN for styling -->
<script src="https://cdn.tailwindcss.com"></script>
<style>
    .container {
        font-family: 'Inter', sans-serif;
    }
    .rounded-xl {
        border-radius: 1rem;
    }
    /* Basic sortable table styling for visual feedback */
    .sortable th {
        cursor: pointer;
        user-select: none;
        transition: background-color 0.15s ease-in-out;
    }
    .sortable th:hover {
        background-color: #e5e7eb;
    }
    .sorttable_sorted::after, .sorttable_sorted_reverse::after {
        content: ' \25B2'; /* Up arrow */
        font-size: 0.8rem;
    }
    .sorttable_sorted_reverse::after {
        content: ' \25BC'; /* Down arrow */
        font-size: 0.8rem;
    }
</style>


<div class="container mx-auto p-4 max-w-7xl">
    <h1 class="text-3xl font-bold border-b pb-2 mb-6 text-gray-800">Inventory Reconciliation</h1>

    <?php if (!empty($error_message)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded-lg shadow-md">
            <p class="font-bold">Database Status</p>
            <p><?= $error_message ?></p>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-xl shadow-lg mb-8 overflow-x-auto">
        <h2 class="text-xl font-semibold mb-3 text-gray-700">Mismatches by SKU &amp; Location</h2>
        <p class="text-sm text-gray-600 mb-4">
            Shows rows where the physical inventory quantity (<code>inventory.quantity</code>) does not match the calculated ledger sum of movements (<code>inventory_movements</code>) for the same SKU and location.
        </p>
        <?php if (empty($per_loc_rows)): ?>
            <div class="text-sm text-green-700 bg-green-50 rounded-lg p-3 font-medium">✅ No location-specific mismatches found.</div>
        <?php else: ?>
            <table class="min-w-full divide-y divide-gray-200 sortable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Inventory Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ledger Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Diff (Ledger - Inv)</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Movement</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($per_loc_rows as $r): ?>
                        <tr class="hover:bg-indigo-50 transition duration-150 ease-in-out">
                            <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900"><?= h($r['sku_num'] ?? 'N/A') ?></td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700"><?= h($r['location_code'] ?? (string)($r['loc_id'] ?? '')) ?></td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-right" data-sort="<?= (float)($r['inventory_qty'] ?? 0) ?>"><?= h(number_format((float)($r['inventory_qty'] ?? 0))) ?></td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-right" data-sort="<?= (float)($r['ledger_qty'] ?? 0) ?>"><?= h(number_format((float)($r['ledger_qty'] ?? 0))) ?></td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-right <?= ((float)($r['diff'] ?? 0)) !== 0.0 ? 'text-red-600 font-semibold' : 'text-gray-700' ?>" data-sort="<?= (float)($r['diff'] ?? 0) ?>">
                                <?= h(number_format((float)($r['diff'] ?? 0))) ?>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-600"><?= h($r['last_move_at'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="text-sm text-gray-500 mt-4">Showing up to 500 location-based reconciliation records.</p>
        <?php endif; ?>
    </div>

    <?php if ($has_sku_qty): ?>
    <div class="bg-white p-6 rounded-xl shadow-lg overflow-x-auto">
        <h2 class="text-xl font-semibold mb-3 text-gray-700">Mismatches by SKU Total</h2>
        <p class="text-sm text-gray-600 mb-4">
            Compares the total of all physical inventory locations (<code>SUM(inventory.quantity)</code>) and the total historical ledger (<code>SUM(inventory_movements)</code>) to the value stored in the SKU master record (<code>sku.quantity</code>).
        </p>
        <?php if (empty($per_sku_rows)): ?>
            <div class="text-sm text-green-700 bg-green-50 rounded-lg p-3 font-medium">✅ No SKU total mismatches found.</div>
        <?php else: ?>
            <table class="min-w-full divide-y divide-gray-200 sortable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Inventory Total</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ledger Total</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">SKU Master Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Diff (Ledger - Inv)</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($per_sku_rows as $r): ?>
                        <tr class="hover:bg-indigo-50 transition duration-150 ease-in-out">
                            <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900"><?= h($r['sku_num'] ?? 'N/A') ?></td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-right" data-sort="<?= (float)($r['inventory_total'] ?? 0) ?>"><?= h(number_format((float)($r['inventory_total'] ?? 0))) ?></td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-right" data-sort="<?= (float)($r['ledger_total'] ?? 0) ?>"><?= h(number_format((float)($r['ledger_total'] ?? 0))) ?></td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-right" data-sort="<?= (float)($r['quantity'] ?? 0) ?>"><?= h(number_format((float)($r['quantity'] ?? 0))) ?></td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-right <?= ((float)($r['diff'] ?? 0)) !== 0.0 ? 'text-red-600 font-semibold' : 'text-gray-700' ?>" data-sort="<?= (float)($r['diff'] ?? 0) ?>">
                                <?= h(number_format((float)($r['diff'] ?? 0))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="text-sm text-gray-500 mt-4">Showing up to 500 SKU total reconciliation records.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
// Capture the output buffer content and assign it to $content
$content = ob_get_clean();

// --- FOOTER JAVASCRIPT ---
$footer_js = <<<HTML
    <!-- Load a simple client-side table sorting library -->
    <script src="https://cdn.jsdelivr.net/npm/sorttable@1.0.0/sorttable.js"></script>
    <script>
        console.log("Reconciliation tables made sortable.");
    </script>
HTML;

// Include the layout file, passing the $title, $content, and $footer_js variables
include 'templates/layout.php';
?>
