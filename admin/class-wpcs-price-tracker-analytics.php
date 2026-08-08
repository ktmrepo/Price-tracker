<?php
/**
 * The analytics functionality of the plugin.
 *
 * @link       https://wpcarestudio.com/
 * @since      2.4.0
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/admin
 */

class WPCS_Price_Tracker_Analytics {

    public static function render_page() {
        $data = self::get_analytics_data();
        ?>
        <div class="wrap">
            <h1>Tracker Analytics</h1>

            <!-- KPIs -->
            <div class="wpcs-admin-dashboard-grid" style="margin-top: 24px;">
                <div class="wpcs-admin-stat-card">
                    <h3>Total Active Users</h3>
                    <p class="value"><?php echo number_format($data['kpis']['total_active_users']); ?></p>
                </div>
                <div class="wpcs-admin-stat-card">
                    <h3>Total Watchlist Items</h3>
                    <p class="value"><?php echo number_format($data['kpis']['total_watchlist_items']); ?></p>
                </div>
                <div class="wpcs-admin-stat-card">
                    <h3>Emails Sent (Last 7 Days)</h3>
                    <p class="value"><?php echo number_format($data['kpis']['emails_sent_7_days']); ?></p>
                </div>
            </div>

            <div class="wpcs-analytics-grid">
                <!-- Product Analytics -->
                <div class="wpcs-analytics-card">
                    <h2>Product Analytics</h2>
                    <div class="wpcs-analytics-section">
                        <h3>Most Watched Products</h3>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th>Product Title</th>
                                    <th style="width: 120px; text-align: center;">Watchlist Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['products']['most_watched'] as $product) : ?>
                                    <tr>
                                        <td><a href="<?php echo esc_url(get_permalink($product->post_id)); ?>"><?php echo esc_html($product->post_title); ?></a></td>
                                        <td style="text-align: center;"><?php echo number_format($product->watch_count); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="wpcs-analytics-section">
                        <h3>Most Frequent Price Drops (Last 30 Days)</h3>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th>Product Title</th>
                                    <th style="width: 120px; text-align: center;">Drop Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($data['products']['most_drops'])) : ?>
                                    <?php foreach ($data['products']['most_drops'] as $product) : ?>
                                        <tr>
                                            <td><a href="<?php echo esc_url(get_permalink($product->post_id)); ?>"><?php echo esc_html($product->post_title); ?></a></td>
                                            <td style="text-align: center;"><?php echo number_format($product->drop_count); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2">No price drops recorded in the last 30 days.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                     <div class="wpcs-analytics-section">
                        <h3>Most Frequent Price Increases (Last 30 Days)</h3>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th>Product Title</th>
                                    <th style="width: 120px; text-align: center;">Increase Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($data['products']['most_increases'])) : ?>
                                    <?php foreach ($data['products']['most_increases'] as $product) : ?>
                                        <tr>
                                            <td><a href="<?php echo esc_url(get_permalink($product->post_id)); ?>"><?php echo esc_html($product->post_title); ?></a></td>
                                            <td style="text-align: center;"><?php echo number_format($product->increase_count); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2">No price increases recorded in the last 30 days.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- User Engagement -->
                <div class="wpcs-analytics-card">
                    <h2>User Engagement</h2>
                    <div class="wpcs-analytics-section">
                        <h3>New Users (Last 30 Days)</h3>
                        <p class="wpcs-analytics-large-stat"><?php echo number_format($data['users']['new_users_30_days']); ?></p>
                    </div>
                     <div class="wpcs-analytics-section">
                        <h3>Top Users by Watchlist Size</h3>
                         <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th>User Name</th>
                                    <th style="width: 120px; text-align: center;">Watchlist Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['users']['top_users'] as $user) : ?>
                                    <tr>
                                        <td><?php echo esc_html($user->display_name); ?></td>
                                        <td style="text-align: center;"><?php echo number_format($user->watchlist_size); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    private static function get_analytics_data() {
        global $wpdb;
        $data = [
            'kpis' => [
                'total_active_users' => 0,
                'total_watchlist_items' => 0,
                'emails_sent_7_days' => 0,
            ],
            'products' => [
                'most_watched' => [],
                'most_drops' => [],
                'most_increases' => [],
            ],
            'users' => [
                'new_users_30_days' => 0,
                'top_users' => [],
            ]
        ];

        // --- KPIs ---
        $data['kpis']['total_active_users'] = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = 'wpcs_watchlist'");
        
        $watchlist_data = $wpdb->get_results("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'wpcs_watchlist'");
        $total_items = 0;
        foreach ($watchlist_data as $row) {
            $unserialized = maybe_unserialize($row->meta_value);
            if (is_array($unserialized)) {
                $total_items += count($unserialized);
            }
        }
        $data['kpis']['total_watchlist_items'] = $total_items;
        
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        $email_logs = $wpdb->get_col($wpdb->prepare("SELECT message FROM {$wpdb->prefix}wpcs_log WHERE status = 'Success' AND message LIKE 'Notification process finished. Scheduled%%' AND log_time >= %s", $seven_days_ago));
        $total_emails = 0;
        foreach ($email_logs as $log_message) {
            preg_match('/Scheduled (\d+) emails/', $log_message, $matches);
            if (isset($matches[1])) {
                $total_emails += (int) $matches[1];
            }
        }
        $data['kpis']['emails_sent_7_days'] = $total_emails;


        // --- Product Analytics ---
        $all_watchlist_items = [];
        foreach ($watchlist_data as $row) {
            $unserialized = maybe_unserialize($row->meta_value);
            if (is_array($unserialized)) {
                $all_watchlist_items = array_merge($all_watchlist_items, $unserialized);
            }
        }
        $product_counts = array_count_values($all_watchlist_items);
        arsort($product_counts);
        $top_product_slugs = array_slice(array_keys($product_counts), 0, 10, true);

        if (!empty($top_product_slugs)) {
            $placeholders = implode(', ', array_fill(0, count($top_product_slugs), '%s'));
            $most_watched_data = $wpdb->get_results($wpdb->prepare(
                "SELECT ID as post_id, post_title, post_name FROM {$wpdb->posts} WHERE post_name IN ($placeholders) AND post_type = 'wpcs_product' AND post_status = 'publish'",
                $top_product_slugs
            ));
            
            foreach ($most_watched_data as $product) {
                $product->watch_count = $product_counts[$product->post_name];
            }
            usort($most_watched_data, function($a, $b) { return $b->watch_count <=> $a->watch_count; });
            $data['products']['most_watched'] = $most_watched_data;
        }

        $thirty_days_ago_sql = date('Y-m-d', strtotime('-30 days'));
        $price_drops_query = "
            SELECT product_id, COUNT(*) as drop_count
            FROM (
                SELECT
                    product_id,
                    price,
                    LAG(price, 1) OVER (PARTITION BY product_id ORDER BY date_recorded) as prev_price
                FROM {$wpdb->prefix}wpcs_price_history
                WHERE date_recorded >= %s
            ) as price_changes
            WHERE price < prev_price
            GROUP BY product_id
            ORDER BY drop_count DESC
            LIMIT 10
        ";
        $price_drops = $wpdb->get_results($wpdb->prepare($price_drops_query, $thirty_days_ago_sql));

        if (!empty($price_drops)) {
            $drop_slugs = wp_list_pluck($price_drops, 'product_id');
            $placeholders = implode(', ', array_fill(0, count($drop_slugs), '%s'));
            $product_info = $wpdb->get_results($wpdb->prepare(
                "SELECT post_name, ID as post_id, post_title FROM {$wpdb->posts} WHERE post_name IN ($placeholders) AND post_type = 'wpcs_product' AND post_status = 'publish'",
                $drop_slugs
            ), OBJECT_K);
            
            foreach($price_drops as $drop) {
                if (isset($product_info[$drop->product_id])) {
                    $drop->post_id = $product_info[$drop->product_id]->post_id;
                    $drop->post_title = $product_info[$drop->product_id]->post_title;
                } else {
                    $drop->post_id = 0; 
                    $drop->post_title = 'Product not found';
                }
            }
            $data['products']['most_drops'] = $price_drops;
        }

        $price_increases_query = "
            SELECT product_id, COUNT(*) as increase_count
            FROM (
                SELECT
                    product_id,
                    price,
                    LAG(price, 1) OVER (PARTITION BY product_id ORDER BY date_recorded) as prev_price
                FROM {$wpdb->prefix}wpcs_price_history
                WHERE date_recorded >= %s
            ) as price_changes
            WHERE price > prev_price
            GROUP BY product_id
            ORDER BY increase_count DESC
            LIMIT 10
        ";
        $price_increases = $wpdb->get_results($wpdb->prepare($price_increases_query, $thirty_days_ago_sql));

        if (!empty($price_increases)) {
            $increase_slugs = wp_list_pluck($price_increases, 'product_id');
            $placeholders = implode(', ', array_fill(0, count($increase_slugs), '%s'));
            $product_info_inc = $wpdb->get_results($wpdb->prepare(
                "SELECT post_name, ID as post_id, post_title FROM {$wpdb->posts} WHERE post_name IN ($placeholders) AND post_type = 'wpcs_product' AND post_status = 'publish'",
                $increase_slugs
            ), OBJECT_K);
            
            foreach($price_increases as $increase) {
                if (isset($product_info_inc[$increase->product_id])) {
                    $increase->post_id = $product_info_inc[$increase->product_id]->post_id;
                    $increase->post_title = $product_info_inc[$increase->product_id]->post_title;
                } else {
                    $increase->post_id = 0; 
                    $increase->post_title = 'Product not found';
                }
            }
            $data['products']['most_increases'] = $price_increases;
        }


        // --- User Engagement ---
        $thirty_days_ago_users = date('Y-m-d H:i:s', strtotime('-30 days'));
        $data['users']['new_users_30_days'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) FROM {$wpdb->users} WHERE user_registered >= %s", $thirty_days_ago_users));
        
        $top_users = [];
        foreach ($watchlist_data as $row) {
            $unserialized = maybe_unserialize($row->meta_value);
            if (is_array($unserialized)) {
                $top_users[$row->user_id] = count($unserialized);
            }
        }
        arsort($top_users);
        $top_user_ids = array_slice(array_keys($top_users), 0, 5);

        if (!empty($top_user_ids)) {
             $user_placeholders = implode(', ', array_fill(0, count($top_user_ids), '%d'));
             $top_users_data = $wpdb->get_results($wpdb->prepare(
                 "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($user_placeholders)", 
                 $top_user_ids
             ));
             
             foreach ($top_users_data as $user) {
                 $user->watchlist_size = $top_users[$user->ID];
             }
             usort($top_users_data, function($a, $b) { return $b->watchlist_size <=> $a->watchlist_size; });
             $data['users']['top_users'] = $top_users_data;
        }

        return $data;
    }
}

