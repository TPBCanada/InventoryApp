<?php
/**
 * Plugin Name: Inventory App
 * Description: WordPress-based warehouse inventory system with shortcode listings and REST endpoints.
 * Version: 1.0.0
 * Author: Steven
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const INVAPP_PLUGIN_VERSION = '1.0.0';

/**
 * Retrieve the database configuration from constants, environment variables, or filters.
 *
 * @return array{
 *     host: string,
 *     user: string,
 *     password: string,
 *     name: string,
 *     port: int,
 *     charset: string
 * }
 */
function invapp_get_db_config(): array {
    $config = [
        'host'    => defined('INVAPP_DB_HOST') ? constant('INVAPP_DB_HOST') : '',
        'user'    => defined('INVAPP_DB_USER') ? constant('INVAPP_DB_USER') : '',
        'password'=> defined('INVAPP_DB_PASSWORD') ? constant('INVAPP_DB_PASSWORD') : '',
        'name'    => defined('INVAPP_DB_NAME') ? constant('INVAPP_DB_NAME') : '',
        'port'    => defined('INVAPP_DB_PORT') ? (int) constant('INVAPP_DB_PORT') : 3306,
        'charset' => defined('INVAPP_DB_CHARSET') ? constant('INVAPP_DB_CHARSET') : 'utf8mb4',
    ];

    /** Allow filters to override if needed **/
    return apply_filters( 'invapp_db_config', $config );
}


/**
 * Establish a PDO connection to the external warehouse database.
 *
 * @return PDO|WP_Error
 */
function invapp_get_connection() {
    if ( ! extension_loaded( 'pdo_mysql' ) ) {
        return new WP_Error(
            'invapp_missing_pdo',
            __( 'The pdo_mysql extension is required for the Inventory App plugin.', 'invapp' )
        );
    }

    $config = invapp_get_db_config();

    foreach ( [ 'host', 'user', 'password', 'name' ] as $key ) {
        if ( empty( $config[ $key ] ) ) {
            return new WP_Error(
                'invapp_missing_config',
                __( 'Inventory App database credentials are not configured.', 'invapp' )
            );
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['name'],
        $config['charset']
    );

    try {
        $pdo = new PDO(
            $dsn,
            $config['user'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch ( PDOException $exception ) {
        return new WP_Error(
            'invapp_db_connection_failed',
            sprintf(
                /* translators: %s: Database connection error message */
                __( 'Database connection failed: %s', 'invapp' ),
                $exception->getMessage()
            )
        );
    }

    return $pdo;
}

/**
 * Close a PDO connection by reference.
 *
 * @param PDO|null $conn Connection instance.
 */
function invapp_close_connection( ?PDO &$conn ): void {
    if ( $conn instanceof PDO ) {
        $conn = null;
    }
}

/**
 * Execute a PDO query with consistent error handling.
 *
 * @param PDO    $conn   Connection instance.
 * @param string $sql    SQL query with placeholders.
 * @param array  $params Parameters to bind.
 *
 * @return PDOStatement|WP_Error
 */
function invapp_db_query( PDO $conn, string $sql, array $params = [] ) {
    try {
        $statement = $conn->prepare( $sql );
        $statement->execute( $params );

        return $statement;
    } catch ( PDOException $exception ) {
        return new WP_Error(
            'invapp_query_error',
            sprintf(
                /* translators: %s: Database error message */
                __( 'Database error: %s', 'invapp' ),
                $exception->getMessage()
            ),
            [ 'status' => 500 ]
        );
    }
}

/**
 * Register front-end assets.
 */
function invapp_enqueue_assets(): void {
    wp_enqueue_style(
        'invapp-style',
        plugin_dir_url( __FILE__ ) . 'style.css',
        [],
        INVAPP_PLUGIN_VERSION
    );
}
add_action( 'wp_enqueue_scripts', 'invapp_enqueue_assets' );

/**
 * Ensure the guided locator script is enqueued only once.
 */
function invapp_enqueue_guided_locator_script(): void {
    static $script_enqueued = false;

    if ( $script_enqueued ) {
        return;
    }

    $script_enqueued = true;

    wp_enqueue_script(
        'invapp-guided-locator',
        plugin_dir_url( __FILE__ ) . 'assets/guided-locator.js',
        [],
        INVAPP_PLUGIN_VERSION,
        true
    );

    wp_localize_script(
        'invapp-guided-locator',
        'InvAppGuided',
        [
            'apiBase'    => esc_url_raw( rest_url( 'inventory/v1' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'rows'       => [ 'R10', 'R11' ],
            'bays'       => range( 1, 30 ),
            'levels'     => [ '11', '12', '51', '52', '61', '62' ],
            'sidesByRow' => [
                'R10' => [ 'FRONT', 'BACK' ],
                'R11' => [ 'FRONT' ],
            ],
            'i18n'       => [
                'loading'        => __( 'Loading...', 'invapp' ),
                'noSkus'         => __( 'No SKUs found at this location.', 'invapp' ),
                'error'          => __( 'Error fetching data.', 'invapp' ),
                'movementSaved'  => __( 'Movement recorded successfully.', 'invapp' ),
                'quantityLabel'  => __( 'Quantity', 'invapp' ),
                'qtyPlaceholder' => __( 'Qty', 'invapp' ),
                'takeLabel'      => __( 'Take', 'invapp' ),
                'addLabel'       => __( 'Add', 'invapp' ),
            ],
        ]
    );
}

/**
 * Render a friendly error message.
 *
 * @param string $message Message text.
 * @return string
 */
function invapp_render_error( string $message ): string {
    return '<div class="invapp-notice invapp-notice-error">' . esc_html( $message ) . '</div>';
}

/**
 * Shortcode: [show_skus]
 *
 * @return string
 */
function invapp_show_skus(): string {
    $conn = invapp_get_connection();

    if ( is_wp_error( $conn ) ) {
        return invapp_render_error( $conn->get_error_message() );
    }

    $statement = invapp_db_query(
        $conn,
        'SELECT sku_num, `desc` AS description, status FROM sku ORDER BY sku_num ASC'
    );

    if ( is_wp_error( $statement ) ) {
        invapp_close_connection( $conn );

        return invapp_render_error( $statement->get_error_message() );
    }

    $rows = $statement->fetchAll();
    invapp_close_connection( $conn );

    if ( empty( $rows ) ) {
        return '<p>' . esc_html__( 'No SKUs found.', 'invapp' ) . '</p>';
    }

    ob_start();
    ?>
    <table class="invapp-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'SKU', 'invapp' ); ?></th>
                <th><?php esc_html_e( 'Description', 'invapp' ); ?></th>
                <th><?php esc_html_e( 'Status', 'invapp' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html( $row['sku_num'] ); ?></td>
                <td><?php echo esc_html( $row['description'] ); ?></td>
                <td><?php echo esc_html( $row['status'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php

    return ob_get_clean();
}
add_shortcode( 'show_skus', 'invapp_show_skus' );

/**
 * Shortcode: [show_inventory]
 *
 * @return string
 */
function invapp_show_inventory(): string {
    $conn = invapp_get_connection();

    if ( is_wp_error( $conn ) ) {
        return invapp_render_error( $conn->get_error_message() );
    }

    $statement = invapp_db_query(
        $conn,
        'SELECT s.sku_num, s.`desc` AS description, l.bin_code, i.quantity, i.last_updated
         FROM sku s
         INNER JOIN inventory i ON s.id = i.sku_id
         INNER JOIN location l ON l.loc_id = i.loc_id
         ORDER BY s.sku_num ASC'
    );

    if ( is_wp_error( $statement ) ) {
        invapp_close_connection( $conn );

        return invapp_render_error( $statement->get_error_message() );
    }

    $rows = $statement->fetchAll();
    invapp_close_connection( $conn );

    if ( empty( $rows ) ) {
        return '<p>' . esc_html__( 'No inventory data found.', 'invapp' ) . '</p>';
    }

    ob_start();
    ?>
    <table class="invapp-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'SKU', 'invapp' ); ?></th>
                <th><?php esc_html_e( 'Description', 'invapp' ); ?></th>
                <th><?php esc_html_e( 'Bin', 'invapp' ); ?></th>
                <th><?php esc_html_e( 'Quantity', 'invapp' ); ?></th>
                <th><?php esc_html_e( 'Last Updated', 'invapp' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html( $row['sku_num'] ); ?></td>
                <td><?php echo esc_html( $row['description'] ); ?></td>
                <td><?php echo esc_html( $row['bin_code'] ); ?></td>
                <td><?php echo esc_html( $row['quantity'] ); ?></td>
                <td><?php echo esc_html( $row['last_updated'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php

    return ob_get_clean();
}
add_shortcode( 'show_inventory', 'invapp_show_inventory' );

/**
 * Shortcode: [show_locations]
 *
 * @return string
 */
function invapp_show_locations(): string {
    $conn = invapp_get_connection();

    if ( is_wp_error( $conn ) ) {
        return invapp_render_error( $conn->get_error_message() );
    }

    $statement = invapp_db_query(
        $conn,
        'SELECT loc_id, bin_code, created_at FROM location ORDER BY bin_code ASC'
    );

    if ( is_wp_error( $statement ) ) {
        invapp_close_connection( $conn );

        return invapp_render_error( $statement->get_error_message() );
    }

    $rows = $statement->fetchAll();
    invapp_close_connection( $conn );

    if ( empty( $rows ) ) {
        return '<p>' . esc_html__( 'No locations found.', 'invapp' ) . '</p>';
    }

    ob_start();
    ?>
    <table class="invapp-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Location ID', 'invapp' ); ?></th>
                <th><?php esc_html_e( 'Bin Code', 'invapp' ); ?></th>
                <th><?php esc_html_e( 'Created At', 'invapp' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $rows as $row ) : ?>
            <tr>
                <td><?php echo esc_html( $row['loc_id'] ); ?></td>
                <td><?php echo esc_html( $row['bin_code'] ); ?></td>
                <td><?php echo esc_html( $row['created_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php

    return ob_get_clean();
}
add_shortcode( 'show_locations', 'invapp_show_locations' );

/**
 * Shortcode: [guided_locator]
 *
 * @return string
 */
function invapp_guided_locator(): string {
    invapp_enqueue_guided_locator_script();

    ob_start();
    ?>
    <div class="invapp-guided" data-invapp-guided>
        <div class="invapp-guided__toast" data-invapp-toast hidden></div>

        <div class="invapp-guided__step" data-step="row">
            <h2><?php esc_html_e( 'Select Row', 'invapp' ); ?></h2>
            <div class="invapp-guided__options" data-role="row-options"></div>
        </div>

        <div class="invapp-guided__step" data-step="bay" hidden>
            <h2><?php esc_html_e( 'Select Bay', 'invapp' ); ?></h2>
            <div class="invapp-guided__options" data-role="bay-options"></div>
        </div>

        <div class="invapp-guided__step" data-step="level" hidden>
            <h2><?php esc_html_e( 'Select Level', 'invapp' ); ?></h2>
            <div class="invapp-guided__options" data-role="level-options"></div>
        </div>

        <div class="invapp-guided__step" data-step="side" hidden>
            <h2><?php esc_html_e( 'Select Side', 'invapp' ); ?></h2>
            <div class="invapp-guided__options" data-role="side-options"></div>
        </div>

        <div class="invapp-guided__summary" data-step="summary" hidden>
            <div class="invapp-guided__summary-header">
                <p>
                    <strong><?php esc_html_e( 'Bin:', 'invapp' ); ?></strong>
                    <span data-role="bin-code"></span>
                </p>
                <button type="button" class="invapp-guided__reset" data-role="start-over">
                    <?php esc_html_e( 'Start Over', 'invapp' ); ?>
                </button>
            </div>
            <div data-role="sku-results" class="invapp-guided__results"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'guided_locator', 'invapp_guided_locator' );

/**
 * Register the Inventory App admin menu page.
 */
function invapp_register_admin_page(): void {
    add_menu_page(
        __( 'Inventory App', 'invapp' ),
        __( 'Inventory', 'invapp' ),
        'manage_options',
        'invapp-dashboard',
        'invapp_render_admin_page',
        'dashicons-clipboard',
        26
    );
}
add_action( 'admin_menu', 'invapp_register_admin_page' );

/**
 * Render a metric tile on the admin dashboard.
 *
 * @param string $label Metric label.
 * @param string $value Metric value.
 */
function invapp_admin_metric( string $label, string $value ): void {
    ?>
    <div class="invapp-admin__metric">
        <h3><?php echo esc_html( $label ); ?></h3>
        <p><?php echo esc_html( $value ); ?></p>
    </div>
    <?php
}

/**
 * Render the admin dashboard page.
 */
function invapp_render_admin_page(): void {
    $conn = invapp_get_connection();

    if ( is_wp_error( $conn ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html( $conn->get_error_message() ) . '</p></div>';

        return;
    }

    $errors = [];
    $totals = [
        'skus'      => 0,
        'locations' => 0,
        'inventory' => 0,
    ];

    $statement = invapp_db_query( $conn, 'SELECT COUNT(*) AS total FROM sku' );
    if ( is_wp_error( $statement ) ) {
        $errors[] = $statement->get_error_message();
    } else {
        $row            = $statement->fetch();
        $totals['skus'] = $row ? (int) $row['total'] : 0;
    }

    $statement = invapp_db_query( $conn, 'SELECT COUNT(*) AS total FROM location' );
    if ( is_wp_error( $statement ) ) {
        $errors[] = $statement->get_error_message();
    } else {
        $row                  = $statement->fetch();
        $totals['locations']  = $row ? (int) $row['total'] : 0;
    }

    $statement = invapp_db_query( $conn, 'SELECT SUM(quantity) AS total_qty FROM inventory' );
    if ( is_wp_error( $statement ) ) {
        $errors[] = $statement->get_error_message();
    } else {
        $row                 = $statement->fetch();
        $totals['inventory'] = $row && isset( $row['total_qty'] ) ? (int) $row['total_qty'] : 0;
    }

    $movements = [];
    $statement = invapp_db_query(
        $conn,
        'SELECT im.id, s.sku_num, l.bin_code, im.quantity_change, im.timestamp
         FROM inventory_movements im
         INNER JOIN sku s ON s.id = im.sku_id
         INNER JOIN location l ON l.loc_id = im.loc_id
         ORDER BY im.timestamp DESC
         LIMIT 20'
    );

    if ( is_wp_error( $statement ) ) {
        $errors[] = $statement->get_error_message();
    } else {
        $movements = $statement->fetchAll();
    }

    invapp_close_connection( $conn );
    ?>
    <div class="wrap invapp-admin">
        <h1><?php esc_html_e( 'Inventory App', 'invapp' ); ?></h1>

        <?php foreach ( $errors as $error_message ) : ?>
            <div class="notice notice-warning"><p><?php echo esc_html( $error_message ); ?></p></div>
        <?php endforeach; ?>

        <div class="invapp-admin__metrics">
            <?php
            invapp_admin_metric( __( 'Total SKUs', 'invapp' ), number_format_i18n( $totals['skus'] ) );
            invapp_admin_metric( __( 'Total Locations', 'invapp' ), number_format_i18n( $totals['locations'] ) );
            invapp_admin_metric( __( 'Total On-Hand', 'invapp' ), number_format_i18n( $totals['inventory'] ) );
            ?>
        </div>

        <h2><?php esc_html_e( 'Recent Movements', 'invapp' ); ?></h2>
        <?php if ( empty( $movements ) ) : ?>
            <p><?php esc_html_e( 'No recent inventory movements found.', 'invapp' ); ?></p>
        <?php else : ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'SKU', 'invapp' ); ?></th>
                        <th><?php esc_html_e( 'Bin', 'invapp' ); ?></th>
                        <th><?php esc_html_e( 'Change', 'invapp' ); ?></th>
                        <th><?php esc_html_e( 'Timestamp', 'invapp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $movements as $movement ) : ?>
                    <tr>
                        <td><?php echo esc_html( $movement['sku_num'] ); ?></td>
                        <td><?php echo esc_html( $movement['bin_code'] ); ?></td>
                        <td><?php echo esc_html( $movement['quantity_change'] ); ?></td>
                        <td><?php echo esc_html( $movement['timestamp'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * REST API: GET /inventory/v1/skus
 *
 * @param WP_REST_Request $request Request instance.
 *
 * @return array|WP_Error
 */
function invapp_rest_get_skus( WP_REST_Request $request ) {
    $bin = sanitize_text_field( $request->get_param( 'bin' ) );

    if ( empty( $bin ) ) {
        return new WP_Error( 'invapp_missing_bin', __( 'Missing bin parameter.', 'invapp' ), [ 'status' => 400 ] );
    }

    $conn = invapp_get_connection();

    if ( is_wp_error( $conn ) ) {
        return $conn;
    }

    $statement = invapp_db_query(
        $conn,
        'SELECT s.sku_num, s.`desc` AS description, COALESCE(i.quantity, 0) AS quantity
         FROM sku s
         INNER JOIN inventory i ON s.id = i.sku_id
         INNER JOIN location l ON l.loc_id = i.loc_id
         WHERE l.bin_code = :bin
         ORDER BY s.sku_num ASC',
        [ ':bin' => $bin ]
    );

    if ( is_wp_error( $statement ) ) {
        invapp_close_connection( $conn );

        return $statement;
    }

    $rows = $statement->fetchAll();
    invapp_close_connection( $conn );

    return $rows;
}

/**
 * REST API: POST /inventory/v1/movements
 *
 * @param WP_REST_Request $request Request instance.
 *
 * @return array|WP_Error
 */
function invapp_rest_record_movement( WP_REST_Request $request ) {
    $params          = $request->get_json_params();
    $sku_num         = isset( $params['sku_num'] ) ? sanitize_text_field( $params['sku_num'] ) : '';
    $bin_code        = isset( $params['bin_code'] ) ? sanitize_text_field( $params['bin_code'] ) : '';
    $quantity_change = isset( $params['quantity_change'] ) ? (int) $params['quantity_change'] : 0;

    if ( empty( $sku_num ) || empty( $bin_code ) || 0 === $quantity_change ) {
        return new WP_Error(
            'invapp_missing_fields',
            __( 'Missing required fields (sku_num, bin_code, quantity_change).', 'invapp' ),
            [ 'status' => 400 ]
        );
    }

    $conn = invapp_get_connection();

    if ( is_wp_error( $conn ) ) {
        return $conn;
    }

    try {
        $conn->beginTransaction();

        $sku_statement = invapp_db_query(
            $conn,
            'SELECT id FROM sku WHERE sku_num = :sku LIMIT 1',
            [ ':sku' => $sku_num ]
        );

        if ( is_wp_error( $sku_statement ) ) {
            throw new RuntimeException( $sku_statement->get_error_message(), 500 );
        }

        $sku_row = $sku_statement->fetch();

        if ( ! $sku_row ) {
            throw new RuntimeException( sprintf( __( 'SKU not found: %s', 'invapp' ), $sku_num ), 404 );
        }

        $sku_id = (int) $sku_row['id'];

        $loc_statement = invapp_db_query(
            $conn,
            'SELECT loc_id FROM location WHERE bin_code = :bin LIMIT 1',
            [ ':bin' => $bin_code ]
        );

        if ( is_wp_error( $loc_statement ) ) {
            throw new RuntimeException( $loc_statement->get_error_message(), 500 );
        }

        $loc_row = $loc_statement->fetch();

        if ( ! $loc_row ) {
            throw new RuntimeException( sprintf( __( 'Location not found: %s', 'invapp' ), $bin_code ), 404 );
        }

        $loc_id = (int) $loc_row['loc_id'];

        $inventory_statement = invapp_db_query(
            $conn,
            'SELECT id, quantity FROM inventory WHERE sku_id = :sku_id AND loc_id = :loc_id LIMIT 1',
            [
                ':sku_id' => $sku_id,
                ':loc_id' => $loc_id,
            ]
        );

        if ( is_wp_error( $inventory_statement ) ) {
            throw new RuntimeException( $inventory_statement->get_error_message(), 500 );
        }

        $inventory_row = $inventory_statement->fetch();

        if ( $inventory_row ) {
            $new_quantity = (int) $inventory_row['quantity'] + $quantity_change;

            if ( $new_quantity < 0 ) {
                throw new RuntimeException( __( 'Cannot reduce quantity below zero.', 'invapp' ), 400 );
            }

            $update_statement = invapp_db_query(
                $conn,
                'UPDATE inventory SET quantity = :quantity, last_updated = NOW() WHERE id = :id',
                [
                    ':quantity' => $new_quantity,
                    ':id'       => (int) $inventory_row['id'],
                ]
            );

            if ( is_wp_error( $update_statement ) ) {
                throw new RuntimeException( $update_statement->get_error_message(), 500 );
            }
        } else {
            if ( $quantity_change < 0 ) {
                throw new RuntimeException(
                    __( 'Cannot remove quantity from a location that has no inventory record.', 'invapp' ),
                    400
                );
            }

            $new_quantity = $quantity_change;

            $insert_statement = invapp_db_query(
                $conn,
                'INSERT INTO inventory (sku_id, loc_id, quantity, last_updated) VALUES (:sku_id, :loc_id, :quantity, NOW())',
                [
                    ':sku_id'  => $sku_id,
                    ':loc_id'  => $loc_id,
                    ':quantity'=> $new_quantity,
                ]
            );

            if ( is_wp_error( $insert_statement ) ) {
                throw new RuntimeException( $insert_statement->get_error_message(), 500 );
            }
        }

        $log_statement = invapp_db_query(
            $conn,
            'INSERT INTO inventory_movements (sku_id, loc_id, quantity_change, timestamp) VALUES (:sku_id, :loc_id, :change, NOW())',
            [
                ':sku_id' => $sku_id,
                ':loc_id' => $loc_id,
                ':change' => $quantity_change,
            ]
        );

        if ( is_wp_error( $log_statement ) ) {
            throw new RuntimeException( $log_statement->get_error_message(), 500 );
        }

        $conn->commit();
        invapp_close_connection( $conn );

        return [
            'success'  => true,
            'message'  => __( 'Movement recorded successfully.', 'invapp' ),
            'quantity' => $new_quantity,
        ];
    } catch ( RuntimeException $exception ) {
        if ( $conn instanceof PDO && $conn->inTransaction() ) {
            $conn->rollBack();
        }

        invapp_close_connection( $conn );

        $code = $exception->getCode();
        if ( $code < 100 || $code > 599 ) {
            $code = 400;
        }

        return new WP_Error( 'invapp_movement_error', $exception->getMessage(), [ 'status' => $code ] );
    } catch ( Throwable $throwable ) {
        if ( $conn instanceof PDO && $conn->inTransaction() ) {
            $conn->rollBack();
        }

        invapp_close_connection( $conn );

        return new WP_Error(
            'invapp_db_error',
            __( 'Database error while recording movement.', 'invapp' ),
            [
                'status' => 500,
                'detail' => $throwable->getMessage(),
            ]
        );
    }
}

add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'inventory/v1',
            '/skus',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => 'invapp_rest_get_skus',
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'inventory/v1',
            '/movements',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => 'invapp_rest_record_movement',
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]
        );
    } 
);  
