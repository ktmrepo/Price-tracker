<?php
/**
 * The data handling functionality of the plugin.
 *
 * @link       https://wpcarestudio.com/
 * @since      1.6.0
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/includes
 */

require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'vendor/google/Client.php';
require_once WPCS_PRICE_TRACKER_PLUGIN_DIR . 'vendor/google/Service/Sheets.php';

class WPCS_Price_Tracker_Data {

    public static function test_gapi_connection() {
        try {
            self::get_gapi_client(true); // true to force read-only scope for test
        } catch (Exception $e) {
            return new WP_Error('gapi_connection_failed', $e->getMessage());
        }
        return true;
    }

    private static function get_gapi_client($read_only = false) {
        $settings = get_option('wpcs_price_tracker_gapi_settings');
        if (empty($settings['service_account_json']) || empty($settings['spreadsheet_id'])) {
            throw new Exception('Service Account JSON or Spreadsheet ID is not configured.');
        }
        $auth_config = json_decode($settings['service_account_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('The Service Account JSON is not valid JSON.');
        }

        $client = new Google_Client();
        $client->setAuthConfig($auth_config);
        $scope = $read_only ? 'https://www.googleapis.com/auth/spreadsheets.readonly' : 'https://www.googleapis.com/auth/spreadsheets';
        $client->setScopes($scope);
        $client->fetchAccessTokenWithAssertion();
        return $client;
    }

    public static function fetch_and_process_data() {
        global $wpdb;

        try {
            $client = self::get_gapi_client(true);
            $service = new Google_Service_Sheets($client);
            $settings = get_option('wpcs_price_tracker_gapi_settings');
            $spreadsheet_id = $settings['spreadsheet_id'];
            $products_sheet_name = $settings['products_sheet_name'] ?? 'Products';
            $stores_sheet_name = $settings['stores_sheet_name'] ?? 'Stores';
            
            // 1. Fetch Store Logos (Lookup Table)
            // Assumes tab name is 'Store_Logos'
            $logos_range = 'Store_Logos!A:B';
            $logos_response = $service->spreadsheets_values->get($spreadsheet_id, $logos_range);
            $logos_values = $logos_response->getValues();
            
            // Build Logo Map: ['Daraz' => 'http...', 'Oliz' => 'http...']
            $logo_map = [];
            if (!empty($logos_values) && count($logos_values) > 1) {
                // Skip header
                array_shift($logos_values);
                foreach ($logos_values as $row) {
                    if (!empty($row[0])) {
                        $logo_map[trim($row[0])] = $row[1] ?? '';
                    }
                }
            }

            // 2. Products Sheet Range (Standard)
            $products_range = $products_sheet_name . '!A:D'; 
            $products_response = $service->spreadsheets_values->get($spreadsheet_id, $products_range);
            $products_values = $products_response->getValues();
            if (empty($products_values) || count($products_values) < 2) throw new Exception('Products sheet is empty.');

            // 3. Stores Sheet Range (Wide Format - A to Z covers 6 stores easily)
            $stores_range = $stores_sheet_name . '!A:Z';
            $stores_response = $service->spreadsheets_values->get($spreadsheet_id, $stores_range);
            $stores_values = $stores_response->getValues();
            if (empty($stores_values) || count($stores_values) < 2) throw new Exception('Stores sheet is empty.');

        } catch (Exception $e) {
            $error = new WP_Error('gapi_fetch_failed', $e->getMessage());
            self::log_sync_event('Error', $error->get_error_message());
            return $error;
        }
        
        $products_data = self::parse_sheet_data($products_values);
        $stores_data = self::parse_sheet_data($stores_values);

        $structured_data = [];

        // Loop 1: Process Products (Using Standard Headers)
        foreach ($products_data as $product) {
            if (empty($product['ProductID'])) continue;
            
            $structured_data[$product['ProductID']] = [
                'title' => $product['Title'] ?? '',
                'primary_store' => $product['PrimaryStoreForGraph'] ?? '',
                'retention_days' => isset($product['RetentionDays']) && is_numeric($product['RetentionDays']) ? (int)$product['RetentionDays'] : null,
                'stores' => [],
            ];
        }

        // Loop 2: Process Stores (WIDE Format Logic)
        // Structure: A=GProductID, B=GProductName, 
        // C=Store1Name, D=Store1URL, E=Store1Sale, F=Store1Normal
        // G=Store2Name... etc.
        foreach ($stores_data as $row) {
            $g_product_id = $row['GProductID'] ?? '';
            
            if (!empty($g_product_id) && isset($structured_data[$g_product_id])) {
                
                // Iterate through 6 potential store slots
                for ($i = 1; $i <= 6; $i++) {
                    $name_key = "Gstore{$i} Name";
                    $url_key  = "Gstore{$i} URL";
                    $price_key = "Gstore{$i} Sale Price";
                    
                    // If Store Name exists in this slot, process it
                    if (!empty($row[$name_key])) {
                        
                        $store_name = $row[$name_key];
                        $raw_price = $row[$price_key] ?? '';
                        
                        // Price Cleaning Logic
                        $price_no_commas = str_replace(',', '', $raw_price);
                        $clean_price = '';
                        if (preg_match('/(\d+(\.\d+)?)/', $price_no_commas, $matches)) {
                            $clean_price = $matches[0];
                        }

                        // Logo Lookup
                        $store_logo = $logo_map[$store_name] ?? '';

                        $structured_data[$g_product_id]['stores'][] = [
                            'StoreName'    => $store_name,
                            'ProductURL'   => $row[$url_key] ?? '',
                            'CurrentPrice' => $clean_price,
                            'StoreLogoURL' => $store_logo,
                        ];
                    }
                }
            }
        }
        
        $price_history_table = $wpdb->prefix . 'wpcs_price_history';
        $product_data_table = $wpdb->prefix . 'wpcs_product_data';
        $today = current_time('Y-m-d');
        $now = current_time('mysql');
        
        $price_changes = [];

        foreach ($structured_data as $product_id => $data) {
            // Save Product Data
            $wpdb->replace(
                $product_data_table,
                [
                    'product_id' => $product_id,
                    'product_title' => $data['title'],
                    'primary_store' => $data['primary_store'],
                    'stores_data' => wp_json_encode($data['stores']), 
                    'retention_days' => $data['retention_days'],
                    'last_updated' => $now,
                ],
                ['%s', '%s', '%s', '%s', '%d', '%s']
            );

            $old_price_sql = $wpdb->prepare(
                "SELECT price FROM {$price_history_table} WHERE product_id = %s AND store_name = %s ORDER BY date_recorded DESC LIMIT 1",
                $product_id, $data['primary_store']
            );
            $old_price = $wpdb->get_var($old_price_sql);
            $primary_price = null;

            foreach ($data['stores'] as $store) {
                $store_name_val = $store['StoreName'];
                $current_price = $store['CurrentPrice']; // Already clean
                
                $is_primary = ($store_name_val === $data['primary_store']);
                $track_history = $is_primary; 

                if ($track_history && is_numeric($current_price)) {
                    $wpdb->replace(
                        $price_history_table,
                        [
                            'product_id' => $product_id,
                            'store_name' => $store_name_val,
                            'price' => $current_price,
                            'date_recorded' => $today,
                        ],
                        ['%s', '%s', '%f', '%s']
                    );
                    
                    if ($is_primary) {
                       $primary_price = $current_price;
                    }
                }
            }
            
            if ($old_price && $primary_price && $primary_price < $old_price) {
                $price_changes[$product_id] = ['old' => $old_price, 'new' => $primary_price];
            }
        }
        
        if (!empty($price_changes)) {
            WPCS_Price_Tracker_Notifications::process_and_send_alerts($price_changes);
        }

        set_transient('wpcs_price_tracker_data', $structured_data, 12 * HOUR_IN_SECONDS);
        self::log_sync_event('Success', 'Processed ' . count($products_data) . ' products using Wide Data format.');
        return ['products' => count($products_data), 'stores' => 'N/A (Wide Format)'];
    }

    private static function parse_sheet_data($values) {
        $data = [];
        $headers = array_map('trim', array_shift($values));
        foreach ($values as $row) {
            // Pad row to match header count to prevent offset issues
            $data[] = array_combine($headers, array_pad($row, count($headers), ''));
        }
        return $data;
    }
    
    public static function update_sheet_from_wp($product_slug, $data) {
         try {
            $client = self::get_gapi_client();
            $service = new Google_Service_Sheets($client);
            $settings = get_option('wpcs_price_tracker_gapi_settings');
            $spreadsheet_id = $settings['spreadsheet_id'];
            $products_sheet_name = $settings['products_sheet_name'] ?? 'Products';
            $stores_sheet_name = $settings['stores_sheet_name'] ?? 'Stores';

            // 1. Find Row in Products Sheet (Standard Layout: ID in Col A)
            $products_response = $service->spreadsheets_values->get($spreadsheet_id, $products_sheet_name . '!A:A');
            $product_ids = $products_response->getValues();
            $product_row_index = -1;
            if ($product_ids) {
                foreach($product_ids as $index => $row) {
                    if (isset($row[0]) && $row[0] === $product_slug) {
                        $product_row_index = $index + 1;
                        break;
                    }
                }
            }
            if ($product_row_index === -1) throw new Exception('Could not find ProductID in the Products sheet.');
            
            // 2. Find Row in Stores Sheet (Wide Layout: GProductID in Col A)
            $stores_response = $service->spreadsheets_values->get($spreadsheet_id, $stores_sheet_name . '!A:Z');
            $stores_values = $stores_response->getValues();
            
            $store_row_index = -1;
            $target_row_data = [];
            
            // Locate the row
            if ($stores_values) {
                foreach($stores_values as $index => $row) {
                    if (isset($row[0]) && $row[0] === $product_slug) {
                        $store_row_index = $index + 1;
                        $target_row_data = $row;
                        break;
                    }
                }
            }
            
            if ($store_row_index === -1) throw new Exception('Could not find GProductID in the Stores sheet.');

            $update_requests = [];

            // Update Products Sheet: Title (B), PrimaryStore (C)
            $update_requests[] = new Google_Service_Sheets_ValueRange([
                'range' => "{$products_sheet_name}!B{$product_row_index}:C{$product_row_index}",
                'values' => [[$data['title'], $data['primary_store']]]
            ]);

            // Update Stores Sheet: Dynamic Wide Scanning
            // Mappings for Store Name columns (0-indexed):
            // Store 1: C (2) -> URL=D(3), Price=E(4)
            // Store 2: G (6) -> URL=H(7), Price=I(8)
            // Store 3: K (10) -> URL=L(11), Price=M(12)
            // Store 4: O (14) -> URL=P(15), Price=Q(16)
            // Store 5: S (18) -> URL=T(19), Price=U(20)
            // Store 6: W (22) -> URL=X(23), Price=Y(24)
            
            $store_slots = [
                1 => ['name_col' => 2, 'url_col_char' => 'D', 'price_col_char' => 'E'],
                2 => ['name_col' => 6, 'url_col_char' => 'H', 'price_col_char' => 'I'],
                3 => ['name_col' => 10, 'url_col_char' => 'L', 'price_col_char' => 'M'],
                4 => ['name_col' => 14, 'url_col_char' => 'P', 'price_col_char' => 'Q'],
                5 => ['name_col' => 18, 'url_col_char' => 'T', 'price_col_char' => 'U'],
                6 => ['name_col' => 22, 'url_col_char' => 'X', 'price_col_char' => 'Y'],
            ];

            foreach($data['stores'] as $wp_store) {
                // Scan the row to find which slot holds this store's name
                foreach ($store_slots as $slot) {
                    $sheet_store_name = $target_row_data[$slot['name_col']] ?? '';
                    
                    if ($sheet_store_name === $wp_store['name']) {
                        // Match Found! Update URL and Price in this slot
                        
                        // Update URL
                        $update_requests[] = new Google_Service_Sheets_ValueRange([
                            'range' => "{$stores_sheet_name}!{$slot['url_col_char']}{$store_row_index}",
                            'values' => [[$wp_store['url']]]
                        ]);

                        // Update Price
                        $update_requests[] = new Google_Service_Sheets_ValueRange([
                            'range' => "{$stores_sheet_name}!{$slot['price_col_char']}{$store_row_index}",
                            'values' => [[$wp_store['price']]]
                        ]);
                        
                        break; // Stop scanning slots for this store
                    }
                }
            }

            if (empty($update_requests)) throw new Exception('No matching stores found in the sheet row to update.');

            $batch_update_request = new Google_Service_Sheets_BatchUpdateValuesRequest([
                'data' => $update_requests, 'valueInputOption' => 'USER_ENTERED'
            ]);
            $service->spreadsheets_values->batchUpdate($spreadsheet_id, $batch_update_request);

            self::fetch_and_process_data();

        } catch (Exception $e) {
            return new WP_Error('gapi_update_failed', $e->getMessage());
        }
        return true;
    }

    public static function prune_old_price_history() {
        global $wpdb;
        $settings = get_option('wpcs_price_tracker_gapi_settings');
        $default_retention_days = absint($settings['data_retention'] ?? 365);
        
        $history_table = $wpdb->prefix . 'wpcs_price_history';
        $product_table = $wpdb->prefix . 'wpcs_product_data';

        $products_with_custom_retention = $wpdb->get_results(
            "SELECT product_id, retention_days FROM {$product_table} WHERE retention_days IS NOT NULL AND retention_days > 0"
        );

        $deleted_rows = 0;

        foreach ($products_with_custom_retention as $product) {
            $retention_days = absint($product->retention_days);
            $cutoff_date = date('Y-m-d', strtotime("-{$retention_days} days"));
            $deleted_rows += $wpdb->query($wpdb->prepare(
                "DELETE FROM {$history_table} WHERE product_id = %s AND date_recorded < %s",
                $product->product_id, $cutoff_date
            ));
        }

        $product_slugs_with_custom = wp_list_pluck($products_with_custom_retention, 'product_id');
        $cutoff_date_default = date('Y-m-d', strtotime("-{$default_retention_days} days"));

        if (!empty($product_slugs_with_custom)) {
             $placeholders = implode(', ', array_fill(0, count($product_slugs_with_custom), '%s'));
             $query = $wpdb->prepare(
                "DELETE FROM {$history_table} WHERE product_id NOT IN ($placeholders) AND date_recorded < %s",
                array_merge($product_slugs_with_custom, [$cutoff_date_default])
            );
             $deleted_rows += $wpdb->query($query);
        } else {
            $deleted_rows += $wpdb->query($wpdb->prepare(
                "DELETE FROM {$history_table} WHERE date_recorded < %s", $cutoff_date_default
            ));
        }

        if ($deleted_rows > 0) {
            self::log_sync_event('Info', "Data pruning complete. Deleted {$deleted_rows} old price history records.");
        } else {
            self::log_sync_event('Info', 'Data pruning ran. No old records needed to be deleted.');
        }
    }

    public static function log_sync_event($status, $message) {
        global $wpdb;
        $log_table = $wpdb->prefix . 'wpcs_log';
        $wpdb->insert(
            $log_table,
            ['log_time' => current_time('mysql'), 'status' => $status, 'message' => $message],
            ['%s', '%s', '%s']
        );
    }
}