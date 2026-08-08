<?php
/**
 * Handles CSV imports for historical price data.
 *
 * @link       https://wpcarestudio.com/
 * @since      2.6.0
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/admin
 */

class WPCS_Price_Tracker_Importer {

    public function __construct() {
        add_action( 'wp_ajax_wpcs_import_price_history', array( $this, 'handle_import_price_history' ) );
    }

    public function handle_import_price_history() {
        // 1. Security Checks
        if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'wpcs_save_product_data_nonce') || ! current_user_can('edit_posts') ) {
            wp_send_json_error(['message' => 'Security check failed.']);
        }

        if (empty($_FILES['file']) || empty($_POST['product_slug'])) {
            wp_send_json_error(['message' => 'Missing file or product ID.']);
        }

        $file = $_FILES['file'];
        $product_slug = sanitize_text_field($_POST['product_slug']);
        
        // 2. Validate CSV File
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'csv' && $file['type'] !== 'text/csv') {
            wp_send_json_error(['message' => 'Please upload a valid CSV file.']);
        }

        // 3. Get Primary Store Target
        $all_data = get_transient('wpcs_price_tracker_data');
        // Attempt refresh if transient is empty
        if (!$all_data) {
            WPCS_Price_Tracker_Data::fetch_and_process_data();
            $all_data = get_transient('wpcs_price_tracker_data');
        }

        if (!isset($all_data[$product_slug])) {
             wp_send_json_error(['message' => 'Product data not found. Please run a sync first to establish the product record.']);
        }
        $primary_store = $all_data[$product_slug]['primary_store'];
        
        if (empty($primary_store)) {
            wp_send_json_error(['message' => 'No Primary Store defined for this product in the Google Sheet.']);
        }

        // 4. Read File
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            wp_send_json_error(['message' => 'Could not read file.']);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wpcs_price_history';
        
        $new_records = 0;
        $skipped_records = 0;
        $total_rows = 0;

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            if (count($data) < 2) continue; // Skip incomplete rows

            $date = trim($data[0]); // Expected YYYY-MM-DD
            $price = preg_replace('/[^\d.]/', '', $data[1]); // Clean price

            // Validate Date (YYYY-MM-DD) & Price
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($price)) {
                continue;
            }
            
            $total_rows++;

            // 5. "Fill the Gaps" Logic (INSERT IGNORE)
            // This will insert the row ONLY if the unique key (product + store + date) doesn't exist.
            // If it exists, it silently skips (returns 0 rows affected).
            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO $table_name (product_id, store_name, price, date_recorded) VALUES (%s, %s, %f, %s)",
                    $product_slug,
                    $primary_store,
                    $price,
                    $date
                )
            );

            if ($result === 1) {
                $new_records++;
            } else {
                $skipped_records++;
            }
        }
        fclose($handle);

        // 6. Return Detailed Feedback
        if ($new_records > 0 || $skipped_records > 0) {
            $msg = "Import Complete for <strong>{$primary_store}</strong>:<br>";
            $msg .= "<span style='color:green'>✔ Added {$new_records} new records.</span><br>";
            if ($skipped_records > 0) {
                $msg .= "<span style='color:orange'>⚠ Skipped {$skipped_records} existing dates (safeguard active).</span>";
            }
            wp_send_json_success(['message' => $msg]);
        } else {
            wp_send_json_error(['message' => 'No valid data found. Check format (YYYY-MM-DD, Price).']);
        }
    }
}