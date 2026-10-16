<?php
/**
 * Compatibility helpers for warehouse supplies inventory operations.
 *
 * This file replaces the missing dev/supplies/inventory_ops.php by exposing
 * thin wrappers around the shared inventory helpers (safe_inventory_change,
 * addInventory, removeInventory). Use these helpers instead of re‑implementing
 * stock adjustments in individual pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Core adjustment helper that routes to safe_inventory_change with a sensible
 * default movement type based on the sign of $delta_qty.
 */
function supply_adjust_stock(
    mysqli $conn,
    int $sku_id,
    int $loc_id,
    int $delta_qty,
    int $user_id,
    string $note = '',
    ?string $movement_type = null
): bool {
    if ($delta_qty === 0) {
        return true; // no-op adjustments succeed quietly
    }

    $movement = $movement_type ?? ($delta_qty > 0 ? 'IN' : 'OUT');
    return safe_inventory_change($conn, $sku_id, $loc_id, $delta_qty, $movement, $user_id, $note);
}

/**
 * Adds stock to a location (wrapper around addInventory).
 */
function supply_add_stock(
    mysqli $conn,
    int $sku_id,
    int $loc_id,
    int $quantity,
    int $user_id,
    string $note = '',
    string $movement_type = 'IN'
): bool {
    if ($quantity <= 0) {
        return false;
    }
    return addInventory($conn, $sku_id, $loc_id, $quantity, $user_id, $note, $movement_type);
}

/**
 * Removes stock from a location (wrapper around removeInventory).
 */
function supply_remove_stock(
    mysqli $conn,
    int $sku_id,
    int $loc_id,
    int $quantity,
    int $user_id,
    string $note = '',
    string $movement_type = 'OUT'
): bool {
    if ($quantity <= 0) {
        return false;
    }
    return removeInventory($conn, $sku_id, $loc_id, $quantity, $user_id, $note, $movement_type);
}

/**
 * Convenience aliases that mirror the original inventory_ops naming,
 * preserved for backward compatibility.
 */
if (!function_exists('inventory_add_stock')) {
    function inventory_add_stock(
        mysqli $conn,
        int $sku_id,
        int $loc_id,
        int $quantity,
        int $user_id,
        string $note = ''
    ): bool {
        return supply_add_stock($conn, $sku_id, $loc_id, $quantity, $user_id, $note, 'IN');
    }
}

if (!function_exists('inventory_remove_stock')) {
    function inventory_remove_stock(
        mysqli $conn,
        int $sku_id,
        int $loc_id,
        int $quantity,
        int $user_id,
        string $note = ''
    ): bool {
        return supply_remove_stock($conn, $sku_id, $loc_id, $quantity, $user_id, $note, 'OUT');
    }
}
