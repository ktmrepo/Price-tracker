<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://wpcarestudio.com/
 * @since      1.5.0
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/admin
 */

class WPCS_Price_Tracker_Admin {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action( 'wp_ajax_wpcs_save_product_data', array( $this, 'handle_save_product_data' ) );
        
        // Load the separated Importer Class
        require_once plugin_dir_path( __FILE__ ) . 'class-wpcs-price-tracker-importer.php';
        new WPCS_Price_Tracker_Importer();

        // Display Settings (Sidebar Toggle)
        add_action( 'add_meta_boxes', array( $this, 'add_display_settings_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_display_settings' ) );
    }

    /**
     * Enqueue styles for the admin area.
     */
    public function enqueue_admin_assets($hook) {
        if ($hook === 'wpcs-tracker_page_wpcs-price-tracker-email-settings') {
             wp_enqueue_media();
        }

        if ( strpos($hook, 'wpcs-price-tracker') === false && $hook !== 'post.php' && $hook !== 'post-new.php' ) {
            return;
        }
        
        global $post;
        if ( is_object($post) && $post->post_type === 'wpcs_product') {
            wp_enqueue_script(
                $this->plugin_name . '-admin-product',
                '#', 
                array('jquery'),
                $this->version,
                true
            );
            wp_add_inline_script( $this->plugin_name . '-admin-product', $this->get_product_edit_js() );
        }

        wp_enqueue_style(
            $this->plugin_name . '-admin',
            plugin_dir_url( __FILE__ ) . 'css/wpcs-price-tracker-admin.css',
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * Creates the new centralized admin menu.
     */
    public function create_admin_menu() {
        add_menu_page(
            'WPCS Price Tracker',
            'WPCS Tracker',
            'manage_options',
            'wpcs-price-tracker-dashboard',
            array( $this, 'render_dashboard_page' ),
            'dashicons-chart-line',
            25
        );
        add_submenu_page(
            'wpcs-price-tracker-dashboard', // Parent slug
            'Categories',                   // Page Title
            'Categories',                   // Menu Title
            'manage_categories',            // Capability
            'edit-tags.php?taxonomy=wpcs_category&post_type=wpcs_product' // Menu slug (URL hack)
        );
        add_submenu_page(
            'wpcs-price-tracker-dashboard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'wpcs-price-tracker-dashboard'
        );
        
        add_submenu_page(
            'wpcs-price-tracker-dashboard',
            'Google Sheets Settings',
            'Google Sheets',
            'manage_options',
            'wpcs-price-tracker-utilities',
            array( $this, 'render_utilities_page' )
        );

        add_submenu_page(
            'wpcs-price-tracker-dashboard',
            'Email Settings',
            'Email Settings',
            'manage_options',
            'wpcs-price-tracker-email-settings',
            array( $this, 'render_email_settings_page' )
        );
        
        add_submenu_page(
            'wpcs-price-tracker-dashboard',
            'Analytics',
            'Analytics',
            'manage_options',
            'wpcs-price-tracker-analytics',
            array('WPCS_Price_Tracker_Analytics', 'render_page')
        );

        add_submenu_page(
            'wpcs-price-tracker-dashboard',
            'Logs',
            'Logs',
            'manage_options',
            'wpcs-price-tracker-logs',
            array( $this, 'render_logs_page' )
        );

        add_submenu_page(
            'wpcs-price-tracker-dashboard',
            'Help',
            'Help',
            'manage_options',
            'wpcs-price-tracker-help',
            array( $this, 'render_help_page' )
        );
    }

    public function register_cpt_product() {
        $labels = array(
            'name'                  => _x( 'Tracked Products', 'Post Type General Name', 'wpcs-price-tracker' ),
            'singular_name'         => _x( 'Tracked Product', 'Post Type Singular Name', 'wpcs-price-tracker' ),
            'menu_name'             => __( 'All Products', 'wpcs-price-tracker' ),
            'name_admin_bar'        => __( 'Tracked Product', 'wpcs-price-tracker' ),
            'all_items'             => __( 'All Products', 'wpcs-price-tracker' ),
            'add_new_item'          => __( 'Add New Product', 'wpcs-price-tracker' ),
            'add_new'               => __( 'Add New', 'wpcs-price-tracker' ),
            'edit_item'             => __( 'Edit Product', 'wpcs-price-tracker' ),
        );
        $args = array(
            'label'                 => __( 'Tracked Product', 'wpcs-price-tracker' ),
            'labels'                => $labels,
            'supports'              => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => 'wpcs-price-tracker-dashboard',
            'has_archive'           => true,
            'rewrite'               => array( 'slug' => 'products' ),
        );
        register_post_type( 'wpcs_product', $args );
    }

    public function render_dashboard_page() {
        $stats = $this->get_admin_dashboard_stats();
        ?>
        <div class="wrap">
            <h1>WPCS Tracker Dashboard</h1>
            <div id="wpcs-dashboard-wrapper">
                <div class="wpcs-main-content">
                    <div class="wpcs-admin-dashboard-grid">
                        <div class="wpcs-admin-stat-card">
                            <h3>Last Sync Time</h3>
                            <p class="value"><?php echo esc_html($stats['last_sync']); ?></p>
                        </div>
                        <div class="wpcs-admin-stat-card">
                            <h3>Tracked Products</h3>
                            <p class="value"><?php echo esc_html($stats['total_products']); ?></p>
                        </div>
                        <div class="wpcs-admin-stat-card">
                            <h3>Users with Watchlists</h3>
                            <p class="value"><?php echo esc_html($stats['total_users']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="wpcs-sidebar">
                     <div class="wpcs-admin-stat-card">
                        <h3>Manual Sync</h3>
                        <p>Click the button below to fetch the latest data from your Google Sheets immediately.</p>
                        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                            <input type="hidden" name="action" value="wpcs_price_tracker_sync_data">
                            <?php wp_nonce_field( 'wpcs_price_tracker_sync_nonce', 'wpcs_price_tracker_nonce' ); ?>
                            <?php submit_button( 'Sync Data Manually', 'secondary', 'submit', false ); ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function get_admin_dashboard_stats() {
        global $wpdb;
        $stats = [
            'last_sync' => 'Never',
            'total_products' => 0,
            'total_users' => 0,
        ];

        $last_sync_time = $wpdb->get_var("SELECT MAX(log_time) FROM {$wpdb->prefix}wpcs_log WHERE status = 'Success'");
        if ($last_sync_time) {
            $stats['last_sync'] = human_time_diff( strtotime( $last_sync_time ), current_time( 'timestamp' ) ) . ' ago';
        }

        $stats['total_products'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = 'wpcs_product' AND post_status = 'publish'");

        $stats['total_users'] = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}usermeta WHERE meta_key = 'wpcs_watchlist'");

        return $stats;
    }

    public function render_utilities_page() {
        ?>
        <div class="wrap">
            <h1>Google Sheets API Settings</h1>
            <div class="wpcs-settings-wrapper">
                <div class="wpcs-settings-main">
                    <form action="options.php" method="post">
                        <?php 
                        settings_fields( 'wpcs_price_tracker_gapi_options' ); 
                        do_settings_sections( 'wpcs-price-tracker-utilities' );
                        submit_button( 'Save Settings' ); 
                        ?>
                    </form>
                </div>
                <div class="wpcs-settings-sidebar">
                     <div class="wpcs-help-card">
                        <h2>Connection Status</h2>
                        <p>After saving your settings, click the button below to test the connection to your Google Sheet.</p>
                         <button id="wpcs-test-gapi-connection" class="button button-secondary">Test Connection</button>
                         <div id="wpcs-test-gapi-response" style="margin-top: 15px;"></div>
                    </div>
                </div>
            </div>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $('#wpcs-test-gapi-connection').on('click', function() {
                    var button = $(this);
                    var responseDiv = $('#wpcs-test-gapi-response');
                    responseDiv.html('<em>Testing...</em>').css('color', 'inherit');
                    button.prop('disabled', true);
                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: { action: 'wpcs_test_gapi_connection', nonce: '<?php echo wp_create_nonce('wpcs_test_gapi_nonce'); ?>' },
                        success: function(response) {
                            if (response.success) {
                                responseDiv.html('<strong>Success:</strong> Connection established successfully!').css('color', 'green');
                            } else {
                                responseDiv.html('<strong>Error:</strong> ' + response.data.message).css('color', 'red');
                            }
                        },
                        error: function() { responseDiv.html('<strong>Error:</strong> An unknown error occurred.').css('color', 'red'); },
                        complete: function() { button.prop('disabled', false); }
                    });
                });
            });
        </script>
        <?php
    }

    public function render_logs_page() {
        global $wpdb;
        $log_table = $wpdb->prefix . 'wpcs_log';
        $logs = $wpdb->get_results("SELECT * FROM $log_table ORDER BY log_time DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1>Sync Logs</h1>
            <p>Showing the last 100 sync events.</p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 200px;">Time</th>
                        <th style="width: 100px;">Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty($logs) ) : ?>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html($log->log_time); ?></td>
                                <td>
                                    <span class="wpcs-log-status <?php echo strtolower(esc_attr($log->status)); ?>">
                                        <?php echo esc_html($log->status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="3">No log entries found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_help_page() {
        ?>
        <div class="wrap">
            <h1>Help & Documentation</h1>
            <div id="wpcs-help-wrapper">
                <div class="wpcs-help-card">
                    <h2>Shortcode Usage</h2>
                    <p>To display the user dashboard on any page, use the following shortcode:</p>
                    <p><code>[wpcs_dashboard]</code></p>
                    <p>This will render the complete dashboard, including the watchlist, notification settings, and account management panels for logged-in users.</p>
                </div>
                <div class="wpcs-help-card">
                    <h2>Google Sheet API Setup</h2>
                    <p>To securely connect to a private Google Sheet, you need to create a Service Account in the Google Cloud Console.</p>
                    <ol>
                        <li>Create a Google Cloud Project and enable the Google Sheets API.</li>
                        <li>Navigate to <strong>IAM & Admin > Service Accounts</strong> and create a new service account.</li>
                        <li>From the service account's "Keys" tab, create and download a new JSON key file.</li>
                        <li>Open your Google Sheet and share it with the <code>client_email</code> address found in the JSON file, giving it "Viewer" access for reading, or "Editor" access for two-way sync.</li>
                        <li>Copy the contents of the JSON file and your Spreadsheet ID into the <a href="<?php echo admin_url('admin.php?page=wpcs-price-tracker-utilities'); ?>">Google Sheets settings page</a>.</li>
                    </ol>
                    <h3>Sheet Column Headers</h3>
                    <p>Ensure your column headers in both sheets match the following exactly:</p>
                    <ul>
                        <li><strong>Products Sheet:</strong> <code>ProductID</code>, <code>Title</code>, <code>PrimaryStoreForGraph</code>, <code>RetentionDays</code> (optional)</li>
                        <li><strong>Stores Sheet:</strong> <code>ProductID</code>, <code>StoreName</code>, <code>ProductURL</code>, <code>CurrentPrice</code>, <code>StoreLogoURL</code>, <code>TrackHistory</code> (optional)</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function add_product_data_meta_box() {
        add_meta_box(
            'wpcs_product_data_box',
            'Product Price Data (from Google Sheet)',
            array( $this, 'render_product_data_meta_box' ),
            'wpcs_product',
            'normal',
            'high'
        );
    }

    // --- Sidebar Display Settings Meta Box ---
    public function add_display_settings_meta_box() {
        add_meta_box(
            'wpcs_display_settings',
            'Display Settings',
            array( $this, 'render_display_settings_meta_box' ),
            'wpcs_product',
            'side',
            'default'
        );
    }

    public function render_display_settings_meta_box( $post ) {
        $disable_auto = get_post_meta( $post->ID, '_wpcs_disable_auto_display', true );
        $hide_related = get_post_meta( $post->ID, '_wpcs_hide_related_products', true );
        
        wp_nonce_field( 'wpcs_save_display_settings', 'wpcs_display_settings_nonce' );
        ?>
        <p>
            <label for="wpcs_disable_auto_display">
                <input type="checkbox" id="wpcs_disable_auto_display" name="wpcs_disable_auto_display" value="1" <?php checked( $disable_auto, '1' ); ?>>
                Disable Automatic Display
            </label>
            <br>
            <span class="description">Hide the default layout. Use shortcodes for manual placement.</span>
        </p>
        <p>
            <label for="wpcs_hide_related_products">
                <input type="checkbox" id="wpcs_hide_related_products" name="wpcs_hide_related_products" value="1" <?php checked( $hide_related, '1' ); ?>>
                Hide Related Products
            </label>
            <br>
            <span class="description">Do not show the related products section on this page.</span>
        </p>
        <?php
    }

    public function save_display_settings( $post_id ) {
        if ( ! isset( $_POST['wpcs_display_settings_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['wpcs_display_settings_nonce'], 'wpcs_save_display_settings' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Save "Disable Auto Display"
        if ( isset( $_POST['wpcs_disable_auto_display'] ) ) {
            update_post_meta( $post_id, '_wpcs_disable_auto_display', '1' );
        } else {
            delete_post_meta( $post_id, '_wpcs_disable_auto_display' );
        }

        // Save "Hide Related Products"
        if ( isset( $_POST['wpcs_hide_related_products'] ) ) {
            update_post_meta( $post_id, '_wpcs_hide_related_products', '1' );
        } else {
            delete_post_meta( $post_id, '_wpcs_hide_related_products' );
        }
    }

    public function render_product_data_meta_box( $post ) {
        $all_data = get_transient( 'wpcs_price_tracker_data' );
        $post_slug = $post->post_name;

        if ( ! $all_data ) {
            WPCS_Price_Tracker_Data::fetch_and_process_data();
            $all_data = get_transient( 'wpcs_price_tracker_data' );
        }

        if ( ! isset( $all_data[ $post_slug ] ) ) {
            echo '<p>No price data found for this product slug (<strong>' . esc_html($post_slug) . '</strong>). Please ensure the ProductID in your Google Sheet matches the slug and run a manual sync.</p>';
            return;
        }

        $product_data = $all_data[ $post_slug ];
        ?>
        <div id="wpcs-product-data-container">
            <table class="wpcs-meta-table">
                <tr>
                    <th>Product Title (from Sheet)</th>
                    <td><input type="text" id="wpcs_product_title" value="<?php echo esc_attr( $product_data['title'] ); ?>"></td>
                </tr>
                <tr>
                    <th>Product Slug</th>
                    <td><input type="text" value="<?php echo esc_attr( $post_slug ); ?>" readonly></td>
                </tr>
                <tr>
                    <th>Primary Store for Graph</th>
                    <td><input type="text" id="wpcs_primary_store" value="<?php echo esc_attr( $product_data['primary_store'] ); ?>"></td>
                </tr>
            </table>
            <h3 style="margin-top: 20px;">Store Information</h3>
            <table class="wpcs-meta-table" id="wpcs-stores-table">
                <thead>
                    <tr>
                        <th>Store Name</th>
                        <th>Store URL</th>
                        <th>Current Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $product_data['stores'] as $store ) : ?>
                    <tr>
                        <td><input type="text" class="wpcs-store-name" value="<?php echo esc_html( $store['StoreName'] ); ?>" readonly></td>
                        <td><input type="url" class="wpcs-store-url" value="<?php echo esc_attr( $store['ProductURL'] ); ?>"></td>
                        <td><input type="text" class="wpcs-store-price" value="<?php echo esc_attr( $store['CurrentPrice'] ); ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 15px;">
                <button id="wpcs-save-product-data" class="button button-primary">Save & Sync to Google Sheet</button>
                <span id="wpcs-sync-status"></span>
            </div>

            <hr style="margin: 25px 0;">
            <h3>Import Historical Data</h3>
            <p>Upload a CSV file to populate the price history chart for the Primary Store (<strong><?php echo esc_html( $product_data['primary_store'] ); ?></strong>).</p>
            <p style="font-size: 12px; color: #666;">Format: <code>YYYY-MM-DD, Price</code> (e.g., <code>2024-01-01, 15000</code>). No header row needed.</p>
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="file" id="wpcs-history-csv" accept=".csv">
                <button id="wpcs-import-history-btn" class="button button-secondary">Import History</button>
            </div>
            <div id="wpcs-import-status" style="margin-top: 10px;"></div>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting( 'wpcs_price_tracker_options', 'wpcs_price_tracker_settings', array( $this, 'sanitize_settings' ) );

        register_setting('wpcs_price_tracker_email_options', 'wpcs_price_tracker_email_settings', array($this, 'sanitize_email_settings'));
        add_settings_section('wpcs_email_template_section', 'Email Template', null, 'wpcs-price-tracker-email-settings');
        add_settings_field('email_logo_url', 'Email Logo', array($this, 'email_logo_callback'), 'wpcs-price-tracker-email-settings', 'wpcs_email_template_section');
        add_settings_field('email_subject', 'Email Subject', array($this, 'email_subject_callback'), 'wpcs-price-tracker-email-settings', 'wpcs_email_template_section');
        add_settings_field('email_heading', 'Email Heading', array($this, 'email_heading_callback'), 'wpcs-price-tracker-email-settings', 'wpcs_email_template_section');
        add_settings_field('email_body', 'Email Body', array($this, 'email_body_callback'), 'wpcs-price-tracker-email-settings', 'wpcs_email_template_section');
        
        register_setting('wpcs_price_tracker_gapi_options', 'wpcs_price_tracker_gapi_settings', array($this, 'sanitize_gapi_settings'));
        add_settings_section('wpcs_gapi_main_section', 'API Credentials & Details', array($this, 'gapi_section_callback'), 'wpcs-price-tracker-utilities');
        add_settings_field('spreadsheet_id', 'Spreadsheet ID', array($this, 'gapi_spreadsheet_id_callback'), 'wpcs-price-tracker-utilities', 'wpcs_gapi_main_section');
        add_settings_field('products_sheet_name', 'Products Sheet Name', array($this, 'gapi_products_sheet_name_callback'), 'wpcs-price-tracker-utilities', 'wpcs_gapi_main_section');
        add_settings_field('stores_sheet_name', 'Stores Sheet Name', array($this, 'gapi_stores_sheet_name_callback'), 'wpcs-price-tracker-utilities', 'wpcs_gapi_main_section');
        add_settings_field('service_account_json', 'Service Account JSON', array($this, 'gapi_service_account_json_callback'), 'wpcs-price-tracker-utilities', 'wpcs_gapi_main_section');
        add_settings_field('sync_timezone', 'Sync Timezone', array($this, 'gapi_sync_timezone_callback'), 'wpcs-price-tracker-utilities', 'wpcs_gapi_main_section');
        add_settings_field('sync_time', 'Time of Day', array($this, 'gapi_sync_time_callback'), 'wpcs-price-tracker-utilities', 'wpcs_gapi_main_section');
        add_settings_field('data_retention', 'Default Data Retention', array($this, 'gapi_data_retention_callback'), 'wpcs-price-tracker-utilities', 'wpcs_gapi_main_section');
    }
    
    public function gapi_section_callback() { echo '<p>Enter the details of your private Google Sheet below. You must share your sheet with the client_email from your Service Account JSON.</p>'; }
    public function gapi_spreadsheet_id_callback() { $opts = get_option('wpcs_price_tracker_gapi_settings'); $val = $opts['spreadsheet_id'] ?? ''; echo '<input type="text" name="wpcs_price_tracker_gapi_settings[spreadsheet_id]" value="' . esc_attr($val) . '" class="regular-text" placeholder="e.g., 1_AbcdeFGHiJKLMnOPqRs-tuvWXYZ12345678">'; }
    public function gapi_products_sheet_name_callback() { $opts = get_option('wpcs_price_tracker_gapi_settings'); $val = $opts['products_sheet_name'] ?? 'Products'; echo '<input type="text" name="wpcs_price_tracker_gapi_settings[products_sheet_name]" value="' . esc_attr($val) . '" class="regular-text">'; }
    public function gapi_stores_sheet_name_callback() { $opts = get_option('wpcs_price_tracker_gapi_settings'); $val = $opts['stores_sheet_name'] ?? 'Stores'; echo '<input type="text" name="wpcs_price_tracker_gapi_settings[stores_sheet_name]" value="' . esc_attr($val) . '" class="regular-text">'; }
    public function gapi_service_account_json_callback() { $opts = get_option('wpcs_price_tracker_gapi_settings'); $val = $opts['service_account_json'] ?? ''; echo '<textarea name="wpcs_price_tracker_gapi_settings[service_account_json]" rows="10" class="large-text">' . esc_textarea($val) . '</textarea><p class="description">Paste the entire contents of your JSON key file here.</p>'; }
    public function gapi_sync_timezone_callback() {
        $opts = get_option('wpcs_price_tracker_gapi_settings');
        $current_tz = $opts['sync_timezone'] ?? wp_timezone_string();
        echo '<select name="wpcs_price_tracker_gapi_settings[sync_timezone]">';
        echo wp_timezone_choice($current_tz);
        echo '</select>';
    }
    public function gapi_sync_time_callback() {
        $opts = get_option('wpcs_price_tracker_gapi_settings');
        $val = $opts['sync_time'] ?? '02:00';
        echo '<input type="time" name="wpcs_price_tracker_gapi_settings[sync_time]" value="' . esc_attr($val) . '">';
    }
     public function gapi_data_retention_callback() {
        $opts = get_option('wpcs_price_tracker_gapi_settings');
        $val = $opts['data_retention'] ?? '365';
        echo '<input type="number" name="wpcs_price_tracker_gapi_settings[data_retention]" value="' . esc_attr($val) . '" class="small-text"> days';
        echo '<p class="description">Automatically delete price history records older than this many days. This can be overridden on a per-product basis in the Google Sheet.</p>';
    }

    public function sanitize_gapi_settings($input) {
        $sanitized = [];
        $sanitized['spreadsheet_id'] = sanitize_text_field($input['spreadsheet_id'] ?? '');
        $sanitized['products_sheet_name'] = sanitize_text_field($input['products_sheet_name'] ?? '');
        $sanitized['stores_sheet_name'] = sanitize_text_field($input['stores_sheet_name'] ?? '');
        $sanitized['data_retention'] = absint($input['data_retention'] ?? 365);
        if ( ! empty($input['service_account_json']) && json_decode($input['service_account_json']) ) {
            $sanitized['service_account_json'] = $input['service_account_json'];
        }

        $old_settings = get_option('wpcs_price_tracker_gapi_settings');
        $old_tz = $old_settings['sync_timezone'] ?? wp_timezone_string();
        $old_time = $old_settings['sync_time'] ?? '02:00';

        $sanitized['sync_timezone'] = sanitize_text_field($input['sync_timezone'] ?? $old_tz);
        $sanitized['sync_time'] = sanitize_text_field($input['sync_time'] ?? $old_time);
        
        if ($sanitized['sync_timezone'] !== $old_tz || $sanitized['sync_time'] !== $old_time) {
            $timestamp = wp_next_scheduled('wpcs_price_tracker_daily_sync');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'wpcs_price_tracker_daily_sync');
            }
            try {
                $tz = new DateTimeZone($sanitized['sync_timezone']);
                $time = new DateTime('now', $tz);
                list($hour, $minute) = explode(':', $sanitized['sync_time']);
                $time->setTime($hour, $minute);
                
                $current_time = new DateTime('now', $tz);
                if ($time < $current_time) {
                    $time->modify('+1 day');
                }
                
                wp_schedule_event($time->getTimestamp(), 'daily', 'wpcs_price_tracker_daily_sync');
                WPCS_Price_Tracker_Data::log_sync_event('Info', 'Daily sync rescheduled to ' . $time->format('Y-m-d H:i:s T'));
            } catch (Exception $e) {
                 WPCS_Price_Tracker_Data::log_sync_event('Error', 'Failed to reschedule daily sync: ' . $e->getMessage());
            }
        }
        
        return $sanitized;
    }

    public function sanitize_settings( $input ) {
        $sanitized_input = array();
        if ( isset( $input['products_sheet_url'] ) ) $sanitized_input['products_sheet_url'] = esc_url_raw( $input['products_sheet_url'] );
        if ( isset( $input['stores_sheet_url'] ) ) $sanitized_input['stores_sheet_url'] = esc_url_raw( $input['stores_sheet_url'] );
        return $sanitized_input;
    }
    
    public function render_email_settings_page() {
        ?>
        <div class="wrap">
            <h1>Email Settings</h1>
            <div class="wpcs-settings-wrapper">
                <div class="wpcs-settings-main">
                    <form action="options.php" method="post">
                        <?php
                        settings_fields('wpcs_price_tracker_email_options');
                        do_settings_sections('wpcs-price-tracker-email-settings');
                        submit_button('Save Email Template');
                        ?>
                    </form>
                </div>
                <div class="wpcs-settings-sidebar">
                    <div class="wpcs-help-card">
                        <h2>Send Test Email</h2>
                        <p>Verify your SMTP settings and preview the email template.</p>
                        <label for="wpcs-test-email-address" style="font-weight: bold; display: block; margin-bottom: 5px;">Recipient Email Address:</label>
                        <input type="email" id="wpcs-test-email-address" class="regular-text" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                        <button id="wpcs-send-test-email" class="button button-secondary" style="margin-top: 10px;">Send Test</button>
                        <div id="wpcs-test-email-response" style="margin-top: 15px;"></div>
                    </div>
                     <div class="wpcs-help-card" style="margin-top: 20px;">
                        <h2>Available Placeholders</h2>
                        <p>Use these placeholders in the email body. They will be replaced with the correct content automatically.</p>
                        <ul>
                            <li><code>[user_name]</code> - The user's display name.</li>
                            <li><code>[product_list]</code> - The list of products with price drops.</li>
                            <li><code>[dashboard_link]</code> - A link to the user's dashboard page.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <script>
            jQuery(document).ready(function($) {
                // Email Logo Uploader
                $('#wpcs-upload-logo-button').click(function(e) {
                    e.preventDefault();
                    var mediaUploader = wp.media({
                        title: 'Choose Email Logo',
                        button: { text: 'Choose Logo' },
                        multiple: false
                    });
                    mediaUploader.on('select', function() {
                        var attachment = mediaUploader.state().get('selection').first().toJSON();
                        $('#wpcs_email_logo_url').val(attachment.url);
                        $('#wpcs-logo-preview').html('<img src="' + attachment.url + '" style="max-width:200px; max-height: 50px; border: 1px solid #ddd;">');
                    });
                    mediaUploader.open();
                });
                $('#wpcs-remove-logo-button').click(function(e) {
                    e.preventDefault();
                    $('#wpcs_email_logo_url').val('');
                    $('#wpcs-logo-preview').html('');
                });

                // Send Test Email
                $('#wpcs-send-test-email').on('click', function() {
                    var button = $(this);
                    var responseDiv = $('#wpcs-test-email-response');
                    var recipient = $('#wpcs-test-email-address').val();

                    responseDiv.html('<em>Sending...</em>').css('color', 'inherit');
                    button.prop('disabled', true);
                    
                    if (!recipient) {
                        responseDiv.html('<strong>Error:</strong> Please enter a recipient email address.').css('color', 'red');
                        button.prop('disabled', false);
                        return;
                    }

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpcs_send_test_email',
                            nonce: '<?php echo wp_create_nonce('wpcs_send_test_email_nonce'); ?>',
                            recipient: recipient
                        },
                        success: function(response) {
                            if (response.success) {
                                responseDiv.html('<strong>Success:</strong> Test email sent successfully!').css('color', 'green');
                            } else {
                                responseDiv.html('<strong>Error:</strong> ' + response.data.message).css('color', 'red');
                            }
                        },
                        error: function() {
                            responseDiv.html('<strong>Error:</strong> An unknown error occurred.').css('color', 'red');
                        },
                        complete: function() {
                            button.prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function sanitize_email_settings($input) {
        $sanitized = [];
        $sanitized['email_logo_url'] = isset($input['email_logo_url']) ? esc_url_raw($input['email_logo_url']) : '';
        $sanitized['email_subject'] = isset($input['email_subject']) ? sanitize_text_field($input['email_subject']) : '';
        $sanitized['email_heading'] = isset($input['email_heading']) ? sanitize_text_field($input['email_heading']) : '';
        if (isset($input['email_body'])) {
            $sanitized['email_body'] = wp_kses_post($input['email_body']);
        }
        return $sanitized;
    }

    public function email_logo_callback() {
        $options = get_option('wpcs_price_tracker_email_settings', WPCS_Price_Tracker_Notifications::get_default_email_settings());
        $logo_url = $options['email_logo_url'] ?? '';
        ?>
        <div id="wpcs-logo-preview">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" style="max-width:200px; max-height: 50px; border: 1px solid #ddd;">
            <?php endif; ?>
        </div>
        <input type="hidden" id="wpcs_email_logo_url" name="wpcs_price_tracker_email_settings[email_logo_url]" value="<?php echo esc_attr($logo_url); ?>">
        <button id="wpcs-upload-logo-button" class="button">Upload Logo</button>
        <button id="wpcs-remove-logo-button" class="button button-secondary">Remove Logo</button>
        <?php
    }
    public function email_subject_callback() { $opts = get_option('wpcs_price_tracker_email_settings', WPCS_Price_Tracker_Notifications::get_default_email_settings()); echo '<input type="text" name="wpcs_price_tracker_email_settings[email_subject]" value="' . esc_attr($opts['email_subject']) . '" class="regular-text">'; }
    public function email_heading_callback() { $opts = get_option('wpcs_price_tracker_email_settings', WPCS_Price_Tracker_Notifications::get_default_email_settings()); echo '<input type="text" name="wpcs_price_tracker_email_settings[email_heading]" value="' . esc_attr($opts['email_heading']) . '" class="regular-text">'; }
    public function email_body_callback() {
        $opts = get_option('wpcs_price_tracker_email_settings', WPCS_Price_Tracker_Notifications::get_default_email_settings());
        wp_editor($opts['email_body'], 'wpcs_email_body_editor', ['textarea_name' => 'wpcs_price_tracker_email_settings[email_body]']);
    }

    public function main_section_callback() { echo '<p>Go to your Google Sheet, click <strong>File > Share > Publish to the web</strong>. Publish the `Products` and `Stores` sheets as a Comma-separated values (.csv) file and paste the generated URLs below.</p>'; }
    public function products_sheet_url_callback() { $options = get_option( 'wpcs_price_tracker_settings' ); $url = isset( $options['products_sheet_url'] ) ? $options['products_sheet_url'] : ''; echo '<input type="url" id="products_sheet_url" name="wpcs_price_tracker_settings[products_sheet_url]" value="' . esc_attr( $url ) . '" class="regular-text" placeholder="https://docs.google.com/spreadsheets/d/e/.../pub?output=csv" />'; }
    public function stores_sheet_url_callback() { $options = get_option( 'wpcs_price_tracker_settings' ); $url = isset( $options['stores_sheet_url'] ) ? $options['stores_sheet_url'] : ''; echo '<input type="url" id="stores_sheet_url" name="wpcs_price_tracker_settings[stores_sheet_url]" value="' . esc_attr( $url ) . '" class="regular-text" placeholder="https://docs.google.com/spreadsheets/d/e/.../pub?output=csv" />'; }

    public function handle_manual_sync() {
        if ( ! isset( $_POST['wpcs_price_tracker_nonce'] ) || ! wp_verify_nonce( $_POST['wpcs_price_tracker_nonce'], 'wpcs_price_tracker_sync_nonce' ) ) wp_die( 'Security check failed.' );
        
        $result = WPCS_Price_Tracker_Data::fetch_and_process_data();
        
        if ( is_wp_error( $result ) ) {
            $message = 'Data sync failed: ' . $result->get_error_message();
            set_transient( 'wpcs_sync_notice', array( 'type' => 'error', 'message' => $message ), 30 );
        } else {
            $message = 'Data synced and cached successfully. Processed ' . $result['products'] . ' unique products and ' . $result['stores'] . ' store entries.';
            set_transient( 'wpcs_sync_notice', array( 'type' => 'success', 'message' => $message ), 30 );
        }

        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $redirect_url = admin_url( 'admin.php?page=wpcs-price-tracker-dashboard' );
        }
        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function display_sync_notices() {
        if ( $notice = get_transient( 'wpcs_sync_notice' ) ) {
            printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
            delete_transient( 'wpcs_sync_notice' );
        }
    }

    public function handle_send_test_email() {
        if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'wpcs_send_test_email_nonce') || ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }

        $recipient_email = isset($_POST['recipient']) ? sanitize_email($_POST['recipient']) : '';
        if (!is_email($recipient_email)) {
            wp_send_json_error(['message' => 'Invalid email address provided.']);
        }

        $user = new WP_User();
        $user->user_email = $recipient_email;
        $user->display_name = 'Test User';
        
        $sample_products = [
            ['title' => 'Sample Product 1', 'url' => '#', 'image_url' => 'https://placehold.co/100x100/E0E7FF/3730A3?text=Sample', 'old_price' => 15000, 'new_price' => 12500],
            ['title' => 'Another Sample Product', 'url' => '#', 'image_url' => 'https://placehold.co/100x100/E0E7FF/3730A3?text=Another', 'old_price' => 9000, 'new_price' => 8500],
        ];

        $sent = WPCS_Price_Tracker_Notifications::send_price_drop_email($user, $sample_products, true);

        if ($sent) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => 'The email could not be sent. Please check your SMTP settings and the plugin logs for more information.']);
        }
    }
    
    public function handle_test_gapi_connection() {
        if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'wpcs_test_gapi_nonce') || ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }
        
        $result = WPCS_Price_Tracker_Data::test_gapi_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success();
    }
    
    public function handle_save_product_data() {
        if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'wpcs_save_product_data_nonce') || ! current_user_can('edit_posts') ) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }

        $product_slug = sanitize_text_field($_POST['product_slug']);
        $data = json_decode(stripslashes($_POST['data']), true);

        $result = WPCS_Price_Tracker_Data::update_sheet_from_wp($product_slug, $data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success(['message' => 'Successfully synced to Google Sheet!']);
    }
    
    private function get_product_edit_js() {
        global $post;
        return "
            jQuery(document).ready(function($) {
                // 1. Existing Save Logic
                $('#wpcs-save-product-data').on('click', function() {
                    var button = $(this);
                    var statusDiv = $('#wpcs-sync-status');
                    statusDiv.html('<em>Syncing...</em>').css('color', 'inherit');
                    button.prop('disabled', true);

                    var data = {
                        title: $('#wpcs_product_title').val(),
                        primary_store: $('#wpcs_primary_store').val(),
                        stores: []
                    };

                    $('#wpcs-stores-table tbody tr').each(function() {
                        var row = $(this);
                        data.stores.push({
                            name: row.find('.wpcs-store-name').val(),
                            url: row.find('.wpcs-store-url').val(),
                            price: row.find('.wpcs-store-price').val()
                        });
                    });

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wpcs_save_product_data',
                            nonce: '" . wp_create_nonce('wpcs_save_product_data_nonce') . "',
                            product_slug: '" . $post->post_name . "',
                            data: JSON.stringify(data)
                        },
                        success: function(response) {
                            if (response.success) {
                                statusDiv.html('<strong>' + response.data.message + '</strong>').css('color', 'green');
                                location.reload();
                            } else {
                                statusDiv.html('<strong>Error:</strong> ' + response.data.message).css('color', 'red');
                            }
                        },
                        error: function() {
                            statusDiv.html('<strong>Error:</strong> An unknown error occurred.').css('color', 'red');
                        },
                        complete: function() {
                            button.prop('disabled', false);
                        }
                    });
                });

                // 2. NEW: CSV Import Logic
                $('#wpcs-import-history-btn').on('click', function(e) {
                    e.preventDefault();
                    var fileInput = $('#wpcs-history-csv')[0];
                    var statusDiv = $('#wpcs-import-status');
                    
                    if(fileInput.files.length === 0) {
                        alert('Please select a CSV file first.');
                        return;
                    }

                    var button = $(this);
                    button.prop('disabled', true).text('Importing...');
                    statusDiv.html('');

                    var formData = new FormData();
                    formData.append('action', 'wpcs_import_price_history');
                    formData.append('nonce', '" . wp_create_nonce('wpcs_save_product_data_nonce') . "');
                    formData.append('product_slug', '" . $post->post_name . "');
                    formData.append('file', fileInput.files[0]);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            if (response.success) {
                                statusDiv.html('<strong style=\"color:green\">' + response.data.message + '</strong>');
                                fileInput.value = ''; // Clear input
                            } else {
                                statusDiv.html('<strong style=\"color:red\">Error: ' + response.data.message + '</strong>');
                            }
                        },
                        error: function() {
                            statusDiv.html('<strong style=\"color:red\">Server Error. Check console.</strong>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Import History');
                        }
                    });
                });
            });
        ";
    }
}