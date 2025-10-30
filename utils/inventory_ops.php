<?php
// inventory_ops.php - Inventory modification functions
declare(strict_types=1);

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

// In inventory_ops.php

function safe_inventory_change(mysqli $conn, int $sku_id, int $loc_id, int $quantity_change, string $movement_type, int $user_id, string $note = ''): bool
{
    // ... (1. START TRANSACTION) ...

    try {
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
            // Binding: i (sku_id), i (loc_id), i (quantity - for both INSERT and UPDATE)
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
            // Binding: i (neg change), i (sku_id), i (loc_id), i (pos check)
            $st_inv->bind_param('iiii', $quantity_change, $sku_id, $loc_id, $pos_qty_req);

        } else {
            // Quantity change is 0, do nothing but log
            $st_inv = null;
        }

        if ($st_inv) {
            if (!$st_inv->execute()) {
                throw new Exception("Inventory update failed: " . $st_inv->error);
            }

            if ($quantity_change < 0 && $st_inv->affected_rows === 0) {
                // Throw specific removal failure error if the guarded update failed
                throw new RuntimeException("Removal failed: Insufficient stock or SKU not found at location.");
            }
            $st_inv->close();
        }


        // --- 3. INSERT into inventory_movements TABLE (Log the ledger entry) ---
        // ... (This section remains the same as your last working version) ...
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
            $quantity_change, // Logs the signed change (positive for add, negative for remove)
            $movement_type,
            $user_id,
            $note
        );

        if (!$st_mov->execute()) {
            throw new Exception("Movement log insert failed: " . $st_mov->error);
        }
        $st_mov->close();


        // ... (4. COMMIT TRANSACTION and 5. CATCH/ROLLBACK) ...

        if (!$conn->commit()) {
            throw new Exception("Transaction commit failed.");
        }
        return true;

    } catch (\RuntimeException $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        throw $e;
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        error_log("Inventory transaction failed and rolled back: " . $e->getMessage());
        return false;
    }
}
function removeInventoryFromLocation(
    mysqli $conn,
    int $loc_id,
    int $sku_id,
    int $quantity,
    int $user_id,
    string $movement_type = 'OUT_ADJUSTMENT'
): bool {
    if ($quantity <= 0) {
        return false;
    }

    try {
        // Start Transaction
        $conn->begin_transaction();

        // 1. Check current quantity and lock the row to prevent race conditions
        $stmt_check = $conn->prepare("SELECT quantity FROM inventory WHERE loc_id = ? AND sku_id = ? FOR UPDATE");
        $stmt_check->bind_param('ii', $loc_id, $sku_id);
        $stmt_check->execute();
        $res = $stmt_check->get_result();
        $inventory_record = $res->fetch_assoc();
        $stmt_check->close();

        if (!$inventory_record || (int) $inventory_record['quantity'] < $quantity) {
            $conn->rollback();
            return false; // Insufficient stock
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
            return false; // Failed to update
        }
        $stmt_update->close();

        // CORRECTED code based on the DB structure dump:
        $log_quantity = -$quantity; // Quantity is negative for removal

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
        error_log("Inventory removal failed: " . $e->getMessage());
        return false;
    }
}

// In inventory_ops.php (or wherever safe_inventory_change() resides)

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
    string $movement_type = 'IN' // Use 'IN' for standard receipts
): bool {
    // 1. Basic validation: ensure quantity is positive
    if ($quantity <= 0) {
        error_log("Attempted to add non-positive quantity: {$quantity}.");
        return false;
    }

    // 2. Call the core transactional function with a positive quantity
    // safe_inventory_change expects quantity_change to be signed, so we pass $quantity directly.
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