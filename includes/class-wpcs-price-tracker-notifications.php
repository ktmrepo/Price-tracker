<?php
/**
 * Handles all email notification logic for the plugin.
 *
 * @link       https://wpcarestudio.com/
 * @since      2.1.0
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/includes
 */

class WPCS_Price_Tracker_Notifications {

    public static function process_and_send_alerts(array $price_drops) {
        if (empty($price_drops)) {
            return;
        }

        $notification_queue = self::get_notification_queue($price_drops);

        if (empty($notification_queue)) {
            self::log_notification_event('Info', 'Notification process ran, but no users met the criteria for alerts.');
            return;
        }

        $delay_seconds = 30;
        $time = time();

        foreach ($notification_queue as $user_id => $products) {
            wp_schedule_single_event($time, 'wpcs_send_single_notification_email', [
                'user_id' => $user_id,
                'products' => $products,
            ]);
            $time += $delay_seconds;
        }

        self::log_notification_event('Success', 'Notification process finished. Scheduled ' . count($notification_queue) . ' emails to be sent.');
    }
    
    public static function handle_scheduled_email($user_id, $products) {
        $user = get_userdata($user_id);
        if (!$user) {
            self::log_notification_event('Error', "Could not send email. User with ID {$user_id} not found.");
            return;
        }

        $sent = self::send_price_drop_email($user, $products);

        if ($sent) {
            // Success, no need to log every single send to avoid clutter.
        } else {
            $message = sprintf('Failed to send email to %s (User ID: %d).', $user->user_email, $user_id);
            WPCS_Price_Tracker_Data::log_sync_event('Error', $message);
        }
    }


    private static function get_notification_queue(array $price_drops) {
        global $wpdb;
        $queue = [];
        $product_slugs = array_keys($price_drops);

        // This is a complex query to efficiently get all relevant users and their preferences in one go.
        $sql = "
            SELECT 
                um1.user_id, 
                um1.meta_value AS watchlist, 
                um2.meta_value AS frequency, 
                um3.meta_value AS target_prices
            FROM {$wpdb->usermeta} um1
            LEFT JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id AND um2.meta_key = 'wpcs_notification_frequency'
            LEFT JOIN {$wpdb->usermeta} um3 ON um1.user_id = um3.user_id AND um3.meta_key = 'wpcs_target_prices'
            WHERE um1.meta_key = 'wpcs_watchlist'
        ";

        $users_data = $wpdb->get_results($sql);

        foreach ($users_data as $user_data) {
            $watchlist = maybe_unserialize($user_data->watchlist);
            if (empty($watchlist) || !is_array($watchlist)) continue;

            $frequency = $user_data->frequency ?: 'daily';
            if ($frequency === 'none') continue;

            $target_prices = maybe_unserialize($user_data->target_prices) ?: [];

            foreach ($watchlist as $slug) {
                if (isset($price_drops[$slug])) { // If this product from their watchlist had a price drop
                    $new_price = $price_drops[$slug]['new'];
                    $target_price = isset($target_prices[$slug]) ? (float) $target_prices[$slug] : 0;
                    
                    if ($target_price <= 0 || $new_price <= $target_price) {
                        if (!isset($queue[$user_data->user_id])) {
                            $queue[$user_data->user_id] = [];
                        }
                        $post = get_page_by_path($slug, OBJECT, 'wpcs_product');
                        if ($post) {
                             $queue[$user_data->user_id][] = [
                                'title' => get_the_title($post),
                                'url' => get_permalink($post),
                                'image_url' => get_the_post_thumbnail_url($post, 'thumbnail'),
                                'old_price' => $price_drops[$slug]['old'],
                                'new_price' => $new_price,
                            ];
                        }
                    }
                }
            }
        }
        return $queue;
    }

    public static function send_price_drop_email($user, $products, $is_test = false) {
        $email_settings = get_option('wpcs_price_tracker_email_settings', self::get_default_email_settings());
        
        $subject = str_replace('[user_name]', $user->display_name, $email_settings['email_subject']);
        $body = self::generate_email_html($user, $products, $email_settings);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if ($is_test) {
            self::log_notification_event('Info', 'Sending test email to ' . $user->user_email);
        }

        return wp_mail($user->user_email, $subject, $body, $headers);
    }
    
    private static function log_notification_event($status, $message) {
        // A dedicated logging function for notifications to avoid confusion with sync logs if needed in the future.
        WPCS_Price_Tracker_Data::log_sync_event($status, $message);
    }

    public static function get_default_email_settings() {
        return [
            'email_logo_url' => '',
            'email_subject' => 'Price Drop Alert!',
            'email_heading' => 'Good News, [user_name]!',
            'email_body'    => '<p>One or more items on your watchlist have dropped in price. Check out the deals below!</p>[product_list]<p>Happy shopping!</p>',
        ];
    }
    
    private static function generate_email_html($user, $products, $settings) {
        $dashboard_page = get_page_by_path('dashboard'); // Assuming your dashboard page slug is 'dashboard'
        $dashboard_link = $dashboard_page ? get_permalink($dashboard_page) : home_url();

        $logo_html = '';
        if (!empty($settings['email_logo_url'])) {
            $logo_html = '<img src="' . esc_url($settings['email_logo_url']) . '" alt="Site Logo" style="max-width: 200px; max-height: 50px; margin-bottom: 20px;">';
        }

        $product_list_html = '<table border="0" cellpadding="10" cellspacing="0" width="100%" style="border-collapse: collapse; margin: 20px 0;">';
        foreach ($products as $product) {
            $image_html = $product['image_url'] ? '<img src="' . esc_url($product['image_url']) . '" width="100" style="width: 100px; height: auto; border-radius: 8px;">' : '';
            $saving = $product['old_price'] - $product['new_price'];

            $product_list_html .= '<tr>
                <td style="padding: 15px 0; border-bottom: 1px solid #eeeeee;">
                    <table border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                            <td width="110" valign="top">' . $image_html . '</td>
                            <td style="padding-left: 20px;" valign="top">
                                <a href="' . esc_url($product['url']) . '" style="font-size: 18px; font-weight: bold; color: #333; text-decoration: none;">' . esc_html($product['title']) . '</a>
                                <p style="margin: 5px 0 0 0; font-size: 16px; color: #666;">
                                    <span style="text-decoration: line-through;">NPR ' . number_format($product['old_price']) . '</span> &rarr; <strong style="color: #2563eb;">NPR ' . number_format($product['new_price']) . '</strong>
                                </p>
                                <p style="margin: 5px 0 0 0; font-size: 14px; color: #16a34a; font-weight: bold;">You save NPR ' . number_format($saving) . '!</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>';
        }
        $product_list_html .= '</table>';

        $body_content = str_replace(
            ['[user_name]', '[product_list]', '[dashboard_link]'],
            [$user->display_name, $product_list_html, esc_url($dashboard_link)],
            wpautop($settings['email_body'])
        );
        $heading = str_replace('[user_name]', $user->display_name, $settings['email_heading']);

        $html = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>
        <body style="margin:0;padding:0;background-color:#f4f4f7;font-family: Arial, sans-serif;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                    <td align="center" style="padding: 40px 0;">
                        <table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                            <tr>
                                <td align="center" style="padding: 40px 40px 30px; background-color: #2563eb; color: #ffffff; border-top-left-radius: 8px; border-top-right-radius: 8px;">
                                    ' . $logo_html . '
                                    <h1 style="font-size: 28px; margin: 0;">' . esc_html($heading) . '</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 30px 40px; color: #333333; font-size: 16px; line-height: 1.6;">
                                    ' . $body_content . '
                                    <div style="text-align: center; margin-top: 30px;">
                                        <a href="' . esc_url($dashboard_link) . '" style="background-color: #2563eb; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;">View All Deals</a>
                                    </div>
                                </td>
                            </tr>
                             <tr>
                                <td style="padding: 20px 40px; text-align: center; font-size: 12px; color: #999999;">
                                    <p>You are receiving this email because you are watching items on ' . get_bloginfo('name') . '. <a href="' . esc_url($dashboard_link) . '" style="color: #999;">Manage your notifications</a>.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body></html>';

        return $html;
    }
}

