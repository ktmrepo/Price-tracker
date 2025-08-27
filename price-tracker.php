<?php
/**
 * Plugin Name:       WPCS Price Tracker
 * Plugin URI:        https://hamroreviews.com/
 * Description:       Tracks product prices from a Google Sheet and displays them on product pages.
 * Version:           1.6.0
 * Author:            WPCS
 * Author URI:        https://wpcarestudio.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcs-price-tracker
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Define Constants
 */
define( 'WPCS_PRICE_TRACKER_VERSION', '1.6.0' );
define( 'WPCS_PRICE_TRACKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * The core plugin class.
 */
class WPCS_Price_Tracker {

    protected $plugin_name;
    protected $version;

    public function __construct() {
        $this->plugin_name = 'wpcs-price-tracker';
        $this->version = WPCS_PRICE_TRACKER_VERSION;

        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_cron_hooks();
    }

    private function load_dependencies() {
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'includes/class-wpcs-price-tracker-data.php';
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'admin/class-wpcs-price-tracker-admin.php';
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'public/class-wpcs-price-tracker-public.php';
    }

    private function define_admin_hooks() {
        $plugin_admin = new WPCS_Price_Tracker_Admin( $this->get_plugin_name(), $this->get_version() );
        add_action( 'admin_menu', array( $plugin_admin, 'add_settings_page' ) );
        add_action( 'admin_init', array( $plugin_admin, 'register_settings' ) );
        add_action( 'admin_post_wpcs_price_tracker_sync_data', array( $plugin_admin, 'handle_manual_sync' ) );
        add_action( 'admin_notices', array( $plugin_admin, 'display_sync_notices' ) );
    }

    private function define_public_hooks() {
        $plugin_public = new WPCS_Price_Tracker_Public( $this->get_plugin_name(), $this->get_version() );
        add_filter( 'the_content', array( $plugin_public, 'display_price_tracker' ) );
        add_action( 'wp_enqueue_scripts', array( $plugin_public, 'enqueue_assets' ) );
    }
    
    private function define_cron_hooks() {
        // Point the daily sync to our new dedicated data class method
        add_action( 'wpcs_price_tracker_daily_sync', array( 'WPCS_Price_Tracker_Data', 'fetch_and_process_data' ) );
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_version() {
        return $this->version;
    }
}

/**
 * Begins execution of the plugin.
 */
function wpcs_run_price_tracker() {
    new WPCS_Price_Tracker();
}

// --- Activation and Deactivation Hooks ---

register_activation_hook( __FILE__, 'wpcs_activate_price_tracker' );
register_deactivation_hook( __FILE__, 'wpcs_deactivate_price_tracker' );

function wpcs_activate_price_tracker() {
    if ( ! wp_next_scheduled( 'wpcs_price_tracker_daily_sync' ) ) {
        wp_schedule_event( time(), 'daily', 'wpcs_price_tracker_daily_sync' );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'wpcs_price_history';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name ( id bigint(20) NOT NULL AUTO_INCREMENT, product_id varchar(255) NOT NULL, price decimal(10, 2) NOT NULL, date_recorded date NOT NULL, PRIMARY KEY  (id), KEY product_id (product_id) ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    flush_rewrite_rules();
}

function wpcs_deactivate_price_tracker() {
    wp_clear_scheduled_hook( 'wpcs_price_tracker_daily_sync' );
    flush_rewrite_rules();
}

// Run the plugin
wpcs_run_price_tracker();
