<?php
/**
 * Plugin Name:       WPCS Price Tracker
 * Plugin URI:        https://hamroreviews.com/
 * Description:       Tracks product prices from a Google Sheet and displays them on product pages.
 * Version:           2.7.0
 * Author:            WPCS
 * Author URI:        https://wpcarestudio.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcs-price-tracker
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'WPCS_PRICE_TRACKER_VERSION', '2.7.0' );
define( 'WPCS_PRICE_TRACKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

class WPCS_Price_Tracker {

    protected $plugin_name;
    protected $version;

    public function __construct() {
        if ( ! defined('WPCS_PRICE_TRACKER_VERSION') ) {
            define( 'WPCS_PRICE_TRACKER_VERSION', '2.7.0' );
        }
        $this->plugin_name = 'wpcs-price-tracker';
        $this->version = WPCS_PRICE_TRACKER_VERSION;

        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_cron_hooks();
        $this->initialize_shortcodes();
    }

    private function load_dependencies() {
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'vendor/autoload.php';
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'admin/class-wpcs-price-tracker-analytics.php';
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'includes/class-wpcs-price-tracker-data.php';
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'admin/class-wpcs-price-tracker-admin.php';
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'public/class-wpcs-price-tracker-public.php';
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'shortcodes/class-wpcs-price-tracker-dashboard.php';
        require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'includes/class-wpcs-price-tracker-notifications.php';
    }

    private function define_admin_hooks() {
        $plugin_admin = new WPCS_Price_Tracker_Admin( $this->get_plugin_name(), $this->get_version() );
        add_action( 'admin_menu', array( $plugin_admin, 'create_admin_menu' ) );
        add_action( 'admin_init', array( $plugin_admin, 'register_settings' ) );
        add_action( 'admin_post_wpcs_price_tracker_sync_data', array( $plugin_admin, 'handle_manual_sync' ) );
        add_action( 'admin_notices', array( $plugin_admin, 'display_sync_notices' ) );
        
        // CPT & Taxonomy Registration
        add_action( 'init', array( $plugin_admin, 'register_cpt_product' ) );
        add_action( 'init', array( $this, 'register_product_taxonomy' ) ); // New Taxonomy

        add_action( 'add_meta_boxes', array( $plugin_admin, 'add_product_data_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_wpcs_test_gapi_connection', array( $plugin_admin, 'handle_test_gapi_connection' ) );
        add_action( 'wp_ajax_wpcs_send_test_email', array( $plugin_admin, 'handle_send_test_email' ) );
        add_action( 'wp_ajax_wpcs_save_product_data', array( $plugin_admin, 'handle_save_product_data' ) );
    }

    // Register Custom Taxonomy for Product Categories
    public function register_product_taxonomy() {
        $labels = array(
            'name'              => _x( 'Product Categories', 'taxonomy general name', 'wpcs-price-tracker' ),
            'singular_name'     => _x( 'Product Category', 'taxonomy singular name', 'wpcs-price-tracker' ),
            'search_items'      => __( 'Search Categories', 'wpcs-price-tracker' ),
            'all_items'         => __( 'All Categories', 'wpcs-price-tracker' ),
            'parent_item'       => __( 'Parent Category', 'wpcs-price-tracker' ),
            'parent_item_colon' => __( 'Parent Category:', 'wpcs-price-tracker' ),
            'edit_item'         => __( 'Edit Category', 'wpcs-price-tracker' ),
            'update_item'       => __( 'Update Category', 'wpcs-price-tracker' ),
            'add_new_item'      => __( 'Add New Category', 'wpcs-price-tracker' ),
            'new_item_name'     => __( 'New Category Name', 'wpcs-price-tracker' ),
            'menu_name'         => __( 'Categories', 'wpcs-price-tracker' ),
        );

        $args = array(
            'hierarchical'      => true, // Behave like Categories (Checkbox), not Tags
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'product-category' ),
            'show_in_rest'      => true, // Enables Gutenberg editor support
        );

        register_taxonomy( 'wpcs_category', array( 'wpcs_product' ), $args );
    }

    private function define_public_hooks() {
        $plugin_public = new WPCS_Price_Tracker_Public( $this->get_plugin_name(), $this->get_version() );
        add_filter( 'the_content', array( $plugin_public, 'display_price_tracker' ) );
        add_action( 'wp_enqueue_scripts', array( $plugin_public, 'enqueue_assets' ) );
        add_filter( 'body_class', array( $plugin_public, 'add_body_classes' ) );
    }
    
    private function initialize_shortcodes() {
        new WPCS_Price_Tracker_Dashboard();
    }

    private function define_cron_hooks() {
        add_action( 'wpcs_price_tracker_daily_sync', array( 'WPCS_Price_Tracker_Data', 'fetch_and_process_data' ) );
        add_action( 'wpcs_send_single_notification_email', array( 'WPCS_Price_Tracker_Notifications', 'handle_scheduled_email'), 10, 2 );
        add_action( 'wpcs_prune_price_history', array('WPCS_Price_Tracker_Data', 'prune_old_price_history') );
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_version() {
        return $this->version;
    }
}

function wpcs_run_price_tracker() {
    new WPCS_Price_Tracker();
}

register_activation_hook( __FILE__, 'wpcs_activate_price_tracker' );
register_deactivation_hook( __FILE__, 'wpcs_deactivate_price_tracker' );

function wpcs_activate_price_tracker() {
    if ( ! wp_next_scheduled( 'wpcs_price_tracker_daily_sync' ) ) {
        wp_schedule_event( time(), 'daily', 'wpcs_price_tracker_daily_sync' );
    }
    if ( ! wp_next_scheduled( 'wpcs_prune_price_history' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpcs_prune_price_history' );
    }

    // Explicitly register taxonomy here during activation to ensure flush rules works
    // Duplicating logic is generally bad, but safe here for activation context.
    // Kept consistent with the main class registration.
    $labels = array(
        'name' => _x( 'Product Categories', 'taxonomy general name', 'wpcs-price-tracker' ),
    );
    register_taxonomy( 'wpcs_category', array( 'wpcs_product' ), array(
        'hierarchical' => true,
        'labels' => $labels,
        'rewrite' => array( 'slug' => 'product-category' ),
    ));

    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $table_name_history = $wpdb->prefix . 'wpcs_price_history';
    $sql_history = "CREATE TABLE $table_name_history (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        product_id varchar(255) NOT NULL,
        store_name varchar(255) NOT NULL,
        price decimal(10, 2) NOT NULL,
        date_recorded date NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_product_store_date (product_id(191), store_name(191), date_recorded)
    ) $charset_collate;";
    dbDelta( $sql_history );

    $table_name_data = $wpdb->prefix . 'wpcs_product_data';
    $sql_data = "CREATE TABLE $table_name_data (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        product_id varchar(255) NOT NULL,
        product_title varchar(255) NOT NULL,
        primary_store varchar(255) NOT NULL,
        stores_data longtext NOT NULL,
        retention_days INT DEFAULT NULL,
        last_updated datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY product_id (product_id)
    ) $charset_collate;";
    dbDelta( $sql_data );

    $table_name_log = $wpdb->prefix . 'wpcs_log';
    $sql_log = "CREATE TABLE $table_name_log (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        log_time datetime NOT NULL,
        status varchar(20) NOT NULL,
        message text NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta( $sql_log );
    
    flush_rewrite_rules();
}

function wpcs_deactivate_price_tracker() {
    wp_clear_scheduled_hook( 'wpcs_price_tracker_daily_sync' );
    wp_clear_scheduled_hook( 'wpcs_prune_price_history' );
    flush_rewrite_rules();
}

wpcs_run_price_tracker();