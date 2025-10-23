?php
/**
 * Plugin Name: Guided Locator Timestamp Fix
 * Description: Ensures the Guided Locator database table has a timestamp column and provides safeguards when the column is missing.
 * Version: 1.0.0
 * Author: Inventory App Contributors
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Guided_Locator_Timestamp_Fix' ) ) {
    final class Guided_Locator_Timestamp_Fix {
        private const OPTION_VERSION = 'guided_locator_timestamp_fix_version';
        private const VERSION = '1.0.0';

        public static function init(): void {
            register_activation_hook( __FILE__, [ self::class, 'on_activation' ] );
            add_action( 'plugins_loaded', [ self::class, 'maybe_upgrade' ] );
            add_filter( 'guided_locator_prepared_row', [ self::class, 'ensure_timestamp_value' ] );
        }

        public static function on_activation(): void {
            self::maybe_add_timestamp_column();
            update_option( self::OPTION_VERSION, self::VERSION );
        }

        public static function maybe_upgrade(): void {
            $stored_version = get_option( self::OPTION_VERSION );

            if ( self::VERSION !== $stored_version ) {
                self::maybe_add_timestamp_column();
                update_option( self::OPTION_VERSION, self::VERSION );
            }
        }

        /**
         * Adds the timestamp column if it does not already exist.
         */
        private static function maybe_add_timestamp_column(): void {
            global $wpdb;

            $table_name = apply_filters( 'guided_locator_timestamp_fix_table_name', $wpdb->prefix . 'guided_locator_locations' );

            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

            if ( $table_exists !== $table_name ) {
                return;
            }

            $column_exists = $wpdb->get_var( "SHOW COLUMNS FROM `{$table_name}` LIKE 'timestamp'" );

            if ( null !== $column_exists ) {
                return;
            }

            $wpdb->query( "ALTER TABLE `{$table_name}` ADD `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP" );
        }

        /**
         * Ensures that the data prepared for insert/update includes a valid timestamp field.
         *
         * @param array $row Existing row data that will be inserted/updated.
         *
         * @return array
         */
        public static function ensure_timestamp_value( array $row ): array {
            if ( isset( $row['timestamp'] ) && ! empty( $row['timestamp'] ) ) {
                return $row;
            }

            $row['timestamp'] = current_time( 'mysql', true );

            return $row;
        }
    }

    Guided_Locator_Timestamp_Fix::init();
}
