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

class WPCS_Price_Tracker_Data {

    public static function fetch_and_process_data() {
        global $wpdb;
        $options = get_option( 'wpcs_price_tracker_settings' );
        $products_url = $options['products_sheet_url'] ?? '';
        $stores_url = $options['stores_sheet_url'] ?? '';
        if ( empty( $products_url ) || empty( $stores_url ) ) return new WP_Error( 'missing_urls', 'Please provide URLs for both Products and Stores sheets.' );
        
        $products_data = self::fetch_and_parse_csv( $products_url );
        if ( is_wp_error( $products_data ) ) return $products_data;
        if ( empty($products_data) ) return new WP_Error( 'empty_products_sheet', 'The Products sheet was fetched but appears to be empty or incorrectly formatted.' );

        $stores_data = self::fetch_and_parse_csv( $stores_url );
        if ( is_wp_error( $stores_data ) ) return $stores_data;
        if ( empty($stores_data) ) return new WP_Error( 'empty_stores_sheet', 'The Stores sheet was fetched but appears to be empty or incorrectly formatted.' );

        $structured_data = array();
        foreach ( $products_data as $product ) {
            if ( empty( $product['ProductID'] ) ) continue;
            $structured_data[ $product['ProductID'] ] = ['title' => $product['Title'], 'primary_store' => $product['PrimaryStoreForGraph'], 'stores' => []];
        }
        foreach ( $stores_data as $store ) {
            if ( isset( $structured_data[ $store['ProductID'] ] ) ) $structured_data[ $store['ProductID'] ]['stores'][] = $store;
        }
        $price_history_table = $wpdb->prefix . 'wpcs_price_history';
        $today = current_time( 'Y-m-d' );
        foreach ( $structured_data as $product_id => $data ) {
            $primary_price = null;
            foreach ( $data['stores'] as $store ) {
                if ( $store['StoreName'] === $data['primary_store'] && ! empty( $store['CurrentPrice'] ) ) {
                    $primary_price = preg_replace('/[^\d.]/', '', $store['CurrentPrice']);
                    break;
                }
            }
            if ( !empty($primary_price) ) $wpdb->replace($price_history_table, ['product_id' => $product_id, 'price' => $primary_price, 'date_recorded' => $today], ['%s', '%f', '%s']);
        }
        set_transient( 'wpcs_price_tracker_data', $structured_data, 12 * HOUR_IN_SECONDS );
        return ['products' => count($products_data), 'stores' => count($stores_data)];
    }

    private static function fetch_and_parse_csv( $url ) {
        $response = wp_remote_get( $url, array( 'timeout' => 20 ) );
        if ( is_wp_error( $response ) ) return new WP_Error( 'fetch_failed', 'Could not fetch data from the URL.' );
        $body = wp_remote_retrieve_body( $response );
        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code !== 200 ) return new WP_Error( 'invalid_response', "Server returned HTTP code $http_code." );
        
        $body = preg_replace('/^\x{EF}\x{BB}\x{BF}/', '', $body);

        $lines = explode( "\n", trim( $body ) );
        $data = [];
        $headers = array_map('trim', str_getcsv( array_shift( $lines ) ) );
        foreach ( $lines as $line ) {
            if ( empty( trim( $line ) ) ) continue;
            $row = [];
            $values = str_getcsv( $line );
            foreach ( $headers as $index => $header ) $row[ $header ] = isset( $values[ $index ] ) ? trim($values[ $index ]) : '';
            $data[] = $row;
        }
        return $data;
    }
}
