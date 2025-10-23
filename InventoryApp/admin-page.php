<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_menu', function() {
    add_menu_page(
        'Inventory Movements',
        'Inventory Movements',
        'manage_options',
        'invapp-movements',
        'invapp_movements_page',
        'dashicons-randomize',
        25
    );
});

function invapp_movements_page() {
    global $wpdb;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invapp_nonce']) && wp_verify_nonce($_POST['invapp_nonce'], 'invapp_add_movement')) {
        $sku_num = sanitize_text_field($_POST['sku_num']);
        $bin_code = sanitize_text_field($_POST['bin_code']);
        $quantity_change = intval($_POST['quantity_change']);

        $sku_table = $wpdb->prefix . 'sku';
        $loc_table = $wpdb->prefix . 'location';
        $mov_table = $wpdb->prefix . 'inventory_movements';
        $inv_table = $wpdb->prefix . 'inventory';

        // Find IDs
        $sku_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $sku_table WHERE sku_num = %s", $sku_num));
        $loc_id = $wpdb->get_var($wpdb->prepare("SELECT loc_id FROM $loc_table WHERE bin_code = %s", $bin_code));

        if ($sku_id && $loc_id) {
            // Update quantity
            $wpdb->query($wpdb->prepare(
                "UPDATE $inv_table SET quantity = quantity + %d, last_updated = NOW() WHERE sku_id = %d AND loc_id = %d",
                $quantity_change, $sku_id, $loc_id
            ));

            // Log movement
            $wpdb->insert($mov_table, [
                'sku_id' => $sku_id,
                'from_loc' => $loc_id,
                'to_loc' => $loc_id,
                'quantity_change' => $quantity_change,
                'moved_at' => current_time('mysql')
            ]);

            echo '<div class="notice notice-success"><p>Movement recorded successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Invalid SKU or Bin Code.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Record Inventory Movement</h1>
        <form method="post">
            <?php wp_nonce_field('invapp_add_movement', 'invapp_nonce'); ?>
            <table class="form-table">
                <tr><th><label for="sku_num">SKU</label></th><td><input name="sku_num" id="sku_num" required></td></tr>
                <tr><th><label for="bin_code">Bin Code</label></th><td><input name="bin_code" id="bin_code" required></td></tr>
                <tr><th><label for="quantity_change">Quantity Change</label></th><td><input type="number" name="quantity_change" id="quantity_change" required></td></tr>
            </table>
            <p><input type="submit" class="button-primary" value="Record Movement"></p>
        </form>
    </div>
    <?php
}
