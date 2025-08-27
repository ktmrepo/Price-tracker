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
    }

    public function add_settings_page() {
        add_options_page('WPCS Price Tracker Settings', 'Price Tracker', 'manage_options', 'wpcs-price-tracker-settings', array( $this, 'render_settings_page' ));
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
                <div>
                    <form action="options.php" method="post">
                        <?php settings_fields( 'wpcs_price_tracker_options' ); do_settings_sections( 'wpcs-price-tracker-settings' ); submit_button( 'Save Settings' ); ?>
                    </form>
                </div>
                <div>
                    <div style="background: #fff; border: 1px solid #c3c4c7; padding: 1rem;">
                        <h2>Manual Sync</h2>
                        <p>Click the button below to fetch the latest data from your Google Sheets immediately.</p>
                        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                            <input type="hidden" name="action" value="wpcs_price_tracker_sync_data">
                            <?php wp_nonce_field( 'wpcs_price_tracker_sync_nonce', 'wpcs_price_tracker_nonce' ); ?>
                            <?php submit_button( 'Sync Data Manually', 'secondary' ); ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting( 'wpcs_price_tracker_options', 'wpcs_price_tracker_settings', array( $this, 'sanitize_settings' ) );
        add_settings_section('wpcs_price_tracker_main_section', 'Google Sheets Settings', array( $this, 'main_section_callback' ), 'wpcs-price-tracker-settings');
        add_settings_field('products_sheet_url', 'Products Sheet CSV URL', array( $this, 'products_sheet_url_callback' ), 'wpcs-price-tracker-settings', 'wpcs_price_tracker_main_section');
        add_settings_field('stores_sheet_url', 'Stores Sheet CSV URL', array( $this, 'stores_sheet_url_callback' ), 'wpcs-price-tracker-settings', 'wpcs_price_tracker_main_section');
    }

    public function sanitize_settings( $input ) {
        $sanitized_input = array();
        if ( isset( $input['products_sheet_url'] ) ) $sanitized_input['products_sheet_url'] = esc_url_raw( $input['products_sheet_url'] );
        if ( isset( $input['stores_sheet_url'] ) ) $sanitized_input['stores_sheet_url'] = esc_url_raw( $input['stores_sheet_url'] );
        return $sanitized_input;
    }

    public function main_section_callback() { echo '<p>Go to your Google Sheet, click <strong>File > Share > Publish to the web</strong>. Publish the `Products` and `Stores` sheets as a Comma-separated values (.csv) file and paste the generated URLs below.</p>'; }
    public function products_sheet_url_callback() { $options = get_option( 'wpcs_price_tracker_settings' ); $url = isset( $options['products_sheet_url'] ) ? $options['products_sheet_url'] : ''; echo '<input type="url" id="products_sheet_url" name="wpcs_price_tracker_settings[products_sheet_url]" value="' . esc_attr( $url ) . '" class="regular-text" placeholder="https://docs.google.com/spreadsheets/d/e/.../pub?output=csv" />'; }
    public function stores_sheet_url_callback() { $options = get_option( 'wpcs_price_tracker_settings' ); $url = isset( $options['stores_sheet_url'] ) ? $options['stores_sheet_url'] : ''; echo '<input type="url" id="stores_sheet_url" name="wpcs_price_tracker_settings[stores_sheet_url]" value="' . esc_attr( $url ) . '" class="regular-text" placeholder="https://docs.google.com/spreadsheets/d/e/.../pub?output=csv" />'; }

    public function handle_manual_sync() {
        if ( ! isset( $_POST['wpcs_price_tracker_nonce'] ) || ! wp_verify_nonce( $_POST['wpcs_price_tracker_nonce'], 'wpcs_price_tracker_sync_nonce' ) ) wp_die( 'Security check failed.' );
        
        // Call the static method from our new Data class
        $result = WPCS_Price_Tracker_Data::fetch_and_process_data();
        
        if ( is_wp_error( $result ) ) {
            $message = 'Data sync failed: ' . $result->get_error_message();
            set_transient( 'wpcs_sync_notice', array( 'type' => 'error', 'message' => $message ), 30 );
        } else {
            $message = 'Data synced and cached successfully. Processed ' . $result['products'] . ' unique products and ' . $result['stores'] . ' store entries.';
            set_transient( 'wpcs_sync_notice', array( 'type' => 'success', 'message' => $message ), 30 );
        }
        wp_safe_redirect( admin_url( 'options-general.php?page=wpcs-price-tracker-settings' ) );
        exit;
    }

    public function display_sync_notices() {
        if ( $notice = get_transient( 'wpcs_sync_notice' ) ) {
            printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
            delete_transient( 'wpcs_sync_notice' );
        }
    }
}
