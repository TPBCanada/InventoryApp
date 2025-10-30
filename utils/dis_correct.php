<?php
// Start the session for authorization checks
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- DATABASE CONNECTION CONFIGURATION ---
// Requires dbinv.php to establish the $conn object.
include 'dbinv.php'; 

// --- AUTHORIZATION AND ACCESS CONTROL ---
// This is a highly sensitive action, restricted to Admin (1) only.
$allowed_roles = [1];
if (!isset($_SESSION['username']) || !in_array((int)($_SESSION['role_id'] ?? 0), $allowed_roles, true)) {
    die("<p style='color:red; text-align:center; font-size:18px; margin-top:50px;'>ACCESS DENIED. This script can only be run by an **Admin** user.</p>");
}

$per_loc_view = 'v_movement_sum_per_loc';
$has_sku_qty = false; // Will be set later
$summary = [];

// Enable error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn_error = !($conn instanceof mysqli) || ($conn instanceof mysqli && $conn->connect_error);
$db_check_ok = false;

if (!$conn_error) {
    $db_check_ok = true;
    try {
        // Function to check if a column exists (copied from reconciliation.php)
        $has_sku_qty = (function(mysqli $conn) {
            $sql = "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sku' AND COLUMN_NAME = 'quantity'";
            $res = mysqli_query($conn, $sql);
            $row = $res ? mysqli_fetch_assoc($res) : ['c' => 0];
            return ((int)$row['c']) > 0;
        })($conn);

    } catch (Throwable $e) {
        $db_check_ok = false;
        $error_message = "A database error occurred during setup: " . htmlspecialchars($e->getMessage());
    }
} else {
    $error_message = "Database connection failed. Check credentials in **dbinv.php**.";
}

// ----------------------------------------------------
// --- CORRECTION LOGIC ---
// ----------------------------------------------------

if ($db_check_ok) {
    try {
        // 1. LOCATION-LEVEL CORRECTION (Inventory table)
        // Set inventory.quantity = calculated ledger total (m.mov_qty) where they differ.
        $loc_correction_sql = "
            UPDATE inventory inv
            JOIN {$per_loc_view} m
                ON inv.sku_id = m.sku_id AND inv.loc_id = m.loc_id
            SET inv.quantity = m.mov_qty
            WHERE inv.quantity <> m.mov_qty
        ";

        $conn->begin_transaction();
        
        $conn->query($loc_correction_sql);
        $loc_rows_affected = $conn->affected_rows;
        $summary[] = "✅ **Location-Level Correction:** Corrected **{$loc_rows_affected}** inventory records to match their calculated ledger totals.";


        // 2. SKU MASTER CORRECTION (sku table)
        if ($has_sku_qty) {
            // Update sku.quantity to match the new sum of inventory quantities (which are now clean).
            $sku_correction_sql = "
                UPDATE sku s
                JOIN (
                    SELECT sku_id, SUM(quantity) as new_total
                    FROM inventory
                    GROUP BY sku_id
                ) AS inv_sums ON s.id = inv_sums.sku_id
                SET s.quantity = inv_sums.new_total
                WHERE s.quantity <> inv_sums.new_total
            ";

            $conn->query($sku_correction_sql);
            $sku_rows_affected = $conn->affected_rows;
            $summary[] = "✅ **SKU Master Correction:** Corrected **{$sku_rows_affected}** SKU master records (<code>sku.quantity</code>) to match the sum of their inventory locations.";
        } else {
            $summary[] = "ℹ️ **SKU Master Correction:** Skipped. The 'sku' table does not have a 'quantity' column.";
        }

        $conn->commit();
        $overall_status = "SUCCESS";

    } catch (Throwable $e) {
        $conn->rollback();
        $overall_status = "FAILED";
        $error_message = "An error occurred during the transaction. All changes were rolled back. Error: " . htmlspecialchars($e->getMessage());
        error_log("Inventory Correction Rollback: " . $e->getMessage());
    }

    if ($conn instanceof mysqli) $conn->close();
}


// ----------------------------------------------------
// --- HTML OUTPUT ---
// ----------------------------------------------------
?>
<script src="https://cdn.tailwindcss.com"></script>
<style>
    .container { font-family: 'Inter', sans-serif; }
    .card { border-radius: 1rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
</style>

<div class="container mx-auto p-8 max-w-2xl">
    <div class="card p-6 bg-white">
        <h1 class="text-3xl font-bold pb-2 mb-4 text-gray-800 border-b">
            Inventory Discrepancy Correction
        </h1>

        <?php if ($overall_status === "SUCCESS"): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-6 text-lg font-semibold">
                SYSTEM SYNCHRONIZED
            </div>
            <p class="mb-4 text-gray-700">The reconciliation process has successfully updated all identified inventory discrepancies to match the definitive ledger (movement history).</p>
        <?php elseif ($overall_status === "FAILED"): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 text-lg font-semibold">
                TRANSACTION FAILED & ROLLED BACK
            </div>
        <?php endif; ?>

        <div class="bg-gray-50 p-4 rounded-lg">
            <h2 class="text-xl font-semibold mb-2 text-gray-700">Execution Summary</h2>
            <ul class="list-disc pl-5 space-y-2 text-sm text-gray-600">
                <?php foreach ($summary as $item): ?>
                    <li><?= $item ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <?php if (!empty($error_message)): ?>
            <div class="mt-6 bg-red-50 p-4 rounded-lg border border-red-200">
                <p class="font-bold text-red-700">Error Details:</p>
                <code class="text-red-600 text-xs block whitespace-pre-wrap mt-1"><?= $error_message ?></code>
            </div>
        <?php endif; ?>
        
        <p class="mt-6 text-sm text-gray-500">
            **Action Performed:** <code>inventory.quantity</code> was set to <code>v_movement_sum_per_loc.mov_qty</code> where they were not equal.
        </p>

    </div>
</div>
