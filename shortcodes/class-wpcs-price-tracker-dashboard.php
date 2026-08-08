<?php
/**
 * The User Dashboard functionality of the plugin.
 *
 * @link       https://wpcarestudio.com/
 * @since      1.9.5
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/shortcodes
 */

class WPCS_Price_Tracker_Dashboard {

    public function __construct() {
        add_shortcode( 'wpcs_dashboard', array( $this, 'render_dashboard' ) );
        add_action( 'wp_ajax_wpcs_remove_from_watchlist', array( $this, 'handle_remove_from_watchlist' ) );
        add_action( 'wp_ajax_wpcs_save_notification_prefs', array( $this, 'handle_save_notification_prefs' ) );
        add_action( 'wp_ajax_wpcs_delete_account', array( $this, 'handle_delete_account' ) );
        add_action( 'wp_ajax_wpcs_avatar_upload', array( $this, 'handle_avatar_upload' ) );
    }

    public function render_dashboard() {
        if ( ! is_user_logged_in() ) {
            return $this->render_login_prompt();
        }

        $current_user = wp_get_current_user();
        $watchlist_items = $this->get_dashboard_watchlist_items();
        $notification_prefs = $this->get_notification_preferences( $current_user->ID );
        $overview_stats = $this->get_dashboard_overview_stats( $watchlist_items );
        $avatar_url = $this->get_custom_avatar_url( $current_user->ID );

        ob_start();
        ?>
        <div class="wpcs-dashboard-wrapper">
            <!-- Left Navigation Panel -->
            <aside class="wpcs-dashboard-nav">
                <div class="wpcs-user-profile">
                    <img src="<?php echo esc_url($avatar_url); ?>" alt="User Avatar" class="wpcs-user-avatar">
                    <div>
                        <p class="wpcs-user-name"><?php echo esc_html( $current_user->display_name ); ?></p>
                    </div>
                </div>
                <ul class="wpcs-nav-list">
                    <li><a class="wpcs-nav-link active" data-target="overview">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h7.5" /></svg>
                        <span>Overview</span>
                    </a></li>
                    <li><a class="wpcs-nav-link" data-target="watchlist">
                        <svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                        <span>My Watchlist</span>
                    </a></li>
                    <li><a class="wpcs-nav-link" data-target="notifications">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                        <span>Notifications</span>
                    </a></li>
                    <li><a class="wpcs-nav-link" data-target="account">
                        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <span>Account Settings</span>
                    </a></li>
                </ul>
                <a href="<?php echo wp_logout_url( get_permalink() ); ?>" class="wpcs-nav-link wpcs-logout-btn">
                    <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    <span>Logout</span>
                </a>
            </aside>

            <!-- Right Content Panel -->
            <main class="wpcs-dashboard-content">
                <!-- Overview Panel -->
                <div id="wpcs-panel-overview" class="wpcs-dashboard-panel active">
                    <h1 class="wpcs-dashboard-header">Overview</h1>
                    <div class="wpcs-overview-stats-grid">
                        <div class="wpcs-stat-card">
                            <p class="wpcs-stat-value"><?php echo esc_html($overview_stats['total_items']); ?></p>
                            <p class="wpcs-stat-label">Products on Watchlist</p>
                        </div>
                        <div class="wpcs-stat-card">
                            <p class="wpcs-stat-value"><?php echo esc_html($overview_stats['items_with_drops']); ?></p>
                            <p class="wpcs-stat-label">Products with Price Drops</p>
                        </div>
                        <div class="wpcs-stat-card">
                            <p class="wpcs-stat-value">NPR <?php echo number_format($overview_stats['biggest_saving_amount']); ?></p>
                            <p class="wpcs-stat-label">Biggest Potential Saving on "<?php echo esc_html($overview_stats['biggest_saving_item']); ?>"</p>
                        </div>
                    </div>
                </div>

                <!-- Watchlist Panel -->
                <div id="wpcs-panel-watchlist" class="wpcs-dashboard-panel">
                    <div class="wpcs-dashboard-toolbar">
                        <h1 class="wpcs-dashboard-header">My Watchlist</h1>
                        <div class="wpcs-sort-container">
                            <label for="wpcs-sort-watchlist" class="wpcs-sort-label">Sort by:</label>
                            <select id="wpcs-sort-watchlist" class="wpcs-sort-select">
                                <option value="date_added">Date Added</option>
                                <option value="price_asc">Price: Low to High</option>
                                <option value="price_desc">Price: High to Low</option>
                                <option value="price_drop">Biggest Price Drop</option>
                            </select>
                        </div>
                    </div>

                    <?php if ( ! empty( $watchlist_items ) ) : ?>
                        <div class="wpcs-dashboard-list">
                            <?php foreach ( $watchlist_items as $index => $item ) : ?>
                                <div class="wpcs-dashboard-item" 
                                     id="watchlist-item-<?php echo esc_attr($item->product_id); ?>"
                                     data-price="<?php echo esc_attr($item->current_price); ?>"
                                     data-price-change="<?php echo esc_attr($item->price_change); ?>"
                                     data-date-added="<?php echo esc_attr($index); ?>">
                                    <img src="<?php echo esc_url( $item->image_url ? $item->image_url : 'https://placehold.co/80x80/E0E7FF/3730A3?text=N/A' ); ?>" alt="<?php echo esc_attr( $item->post_title ); ?>" class="wpcs-dashboard-item-img">
                                    <div class="wpcs-dashboard-item-details">
                                        <a href="<?php echo esc_url( get_permalink( $item->post_id ) ); ?>" class="wpcs-dashboard-item-title"><?php echo esc_html( $item->post_title ); ?></a>
                                        <p class="wpcs-dashboard-item-price">
                                            NPR <?php echo number_format($item->current_price); ?>
                                            <?php if ( $item->price_change < 0 ) : ?>
                                                <span class="wpcs-price-drop"><?php echo abs(round($item->price_change)); ?>%</span>
                                            <?php elseif ( $item->price_change > 0 ) : ?>
                                                <span class="wpcs-price-increase"><?php echo abs(round($item->price_change)); ?>%</span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="wpcs-dashboard-item-timestamp">Checked: <?php echo esc_html( $item->last_checked ); ?></p>
                                    </div>
                                    <div class="wpcs-sparkline-container">
                                        <canvas class="wpcs-sparkline-canvas" data-sparkline="<?php echo esc_attr(json_encode($item->sparkline_data)); ?>"></canvas>
                                    </div>
                                    <button class="wpcs-dashboard-remove-btn" data-product-id="<?php echo esc_attr($item->product_id); ?>" title="Remove from Watchlist">
                                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="wpcs-dashboard-empty">
                            <p>Your watchlist is empty.</p>
                            <span>Add products to your watchlist to track their prices here.</span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Notification Settings Panel -->
                <div id="wpcs-panel-notifications" class="wpcs-dashboard-panel">
                    <h1 class="wpcs-dashboard-header">Notification Settings</h1>
                    <form id="wpcs-notifications-form">
                        <div class="wpcs-dashboard-section">
                            <h2 class="wpcs-section-header">Email Frequency</h2>
                            <div class="wpcs-notification-options">
                                <label class="wpcs-radio-label">
                                    <input type="radio" name="email_frequency" value="daily" <?php checked($notification_prefs['frequency'], 'daily'); ?>>
                                    <div class="wpcs-radio-details">
                                        <p class="wpcs-radio-title">Daily Digest</p>
                                        <p class="wpcs-radio-description">Send one email per day summarizing all price drops.</p>
                                    </div>
                                </label>
                                <label class="wpcs-radio-label">
                                    <input type="radio" name="email_frequency" value="instant" <?php checked($notification_prefs['frequency'], 'instant'); ?>>
                                    <div class="wpcs-radio-details">
                                        <p class="wpcs-radio-title">Instant Alerts</p>
                                        <p class="wpcs-radio-description">Send an email as soon as a price drops.</p>
                                    </div>
                                </label>
                                <label class="wpcs-radio-label">
                                    <input type="radio" name="email_frequency" value="none" <?php checked($notification_prefs['frequency'], 'none'); ?>>
                                    <div class="wpcs-radio-details">
                                        <p class="wpcs-radio-title">No Emails</p>
                                        <p class="wpcs-radio-description">I will check the dashboard manually.</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="wpcs-dashboard-section">
                            <h2 class="wpcs-section-header">Target Price Alerts</h2>
                            <div class="wpcs-target-price-list">
                                <?php if ( ! empty( $watchlist_items ) ) : ?>
                                    <?php foreach ( $watchlist_items as $item ) : 
                                        $target_price = $notification_prefs['targets'][$item->product_id] ?? '';
                                    ?>
                                        <div class="wpcs-target-price-item">
                                            <img src="<?php echo esc_url( $item->image_url ? $item->image_url : 'https://placehold.co/48x48/E0E7FF/3730A3?text=N/A' ); ?>" alt="<?php echo esc_attr( $item->post_title ); ?>" class="wpcs-target-price-img">
                                            <p class="wpcs-target-price-title"><?php echo esc_html( $item->post_title ); ?></p>
                                            <input type="number" name="target_prices[<?php echo esc_attr($item->product_id); ?>]" class="wpcs-target-price-input" placeholder="e.g., <?php echo round($item->current_price * 0.9); ?>" value="<?php echo esc_attr($target_price); ?>">
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>Add items to your watchlist to set target prices.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <button type="submit" class="wpcs-form-button">Save Preferences</button>
                        <span class="wpcs-prefs-saved-msg" style="display:none; margin-left: 16px; font-size: 14px; color: #16a34a; font-weight: 500;">Preferences Saved!</span>
                    </form>
                </div>


                <!-- Account Settings Panel -->
                <div id="wpcs-panel-account" class="wpcs-dashboard-panel">
                    <h1 class="wpcs-dashboard-header">Account Settings</h1>
                    <div class="wpcs-account-form">
                        <div class="wpcs-form-group">
                            <label class="wpcs-form-label">Profile Picture</label>
                            <div class="wpcs-avatar-uploader">
                                <img src="<?php echo esc_url($avatar_url); ?>" alt="User Avatar" class="wpcs-user-avatar-large">
                                <button type="button" id="wpcs-change-avatar-btn" class="wpcs-form-button">Change Photo</button>
                                <input type="file" id="wpcs-avatar-file-input" hidden accept="image/jpeg, image/png, image/gif">
                            </div>
                        </div>
                        <div class="wpcs-form-group">
                            <label class="wpcs-form-label" for="full_name">Full Name</label>
                            <input class="wpcs-form-input" type="text" id="full_name" value="<?php echo esc_attr($current_user->display_name); ?>">
                        </div>
                        <div class="wpcs-form-group">
                            <label class="wpcs-form-label" for="email">Email Address</label>
                            <input class="wpcs-form-input" type="email" id="email" value="<?php echo esc_attr($current_user->user_email); ?>" disabled>
                        </div>
                        <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" class="wpcs-form-button">Change Password</a>
                    </div>

                    <div class="wpcs-delete-account-section">
                        <h2 class="wpcs-section-header">Delete Account</h2>
                        <p>Permanently delete your account and all associated data. This action cannot be undone.</p>
                        <button id="wpcs-delete-account-btn" class="wpcs-delete-button">Delete My Account</button>
                    </div>
                </div>
            </main>
        </div>
        <script>
            <?php echo $this->get_dashboard_js(); ?>
        </script>
        <?php
        return ob_get_clean();
    }


    private function get_dashboard_overview_stats( $watchlist_items ) {
        $stats = [
            'total_items' => count($watchlist_items),
            'items_with_drops' => 0,
            'biggest_saving_item' => 'N/A',
            'biggest_saving_amount' => 0,
        ];

        if (empty($watchlist_items)) {
            return $stats;
        }

        $biggest_saving = 0;

        foreach ($watchlist_items as $item) {
            if ($item->price_change < 0) {
                $stats['items_with_drops']++;
                
                if (isset($item->previous_price) && isset($item->current_price)) {
                    $saving_amount = $item->previous_price - $item->current_price;
                    if ($saving_amount > $biggest_saving) {
                        $biggest_saving = $saving_amount;
                        $stats['biggest_saving_item'] = $item->post_title;
                        $stats['biggest_saving_amount'] = $saving_amount;
                    }
                }
            }
        }

        return $stats;
    }

    private function get_dashboard_watchlist_items() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return array();

        $watchlist = get_user_meta( $user_id, 'wpcs_watchlist', true );
        if ( empty( $watchlist ) || ! is_array( $watchlist ) ) return array();

        global $wpdb;
        $price_history_table = $wpdb->prefix . 'wpcs_price_history';
        $postmeta_table = $wpdb->prefix . 'postmeta';
        $posts_table = $wpdb->prefix . 'posts';
        $product_data_table = $wpdb->prefix . 'wpcs_product_data';
        
        $results = array();
        
        foreach($watchlist as $slug) {
            $sql = $wpdb->prepare( "
                SELECT 
                    p.ID as post_id, 
                    p.post_title,
                    pd.last_updated,
                    (SELECT pm.meta_value FROM {$postmeta_table} pm WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' LIMIT 1) as thumbnail_id,
                    (SELECT price FROM {$price_history_table} ph WHERE ph.product_id = %s ORDER BY ph.date_recorded DESC LIMIT 1) as current_price,
                    (SELECT price FROM {$price_history_table} ph WHERE ph.product_id = %s AND ph.date_recorded < CURDATE() ORDER BY ph.date_recorded DESC LIMIT 1) as previous_price
                FROM {$posts_table} p
                JOIN {$product_data_table} pd ON p.post_name = pd.product_id
                WHERE p.post_name = %s AND p.post_type = 'wpcs_product' AND p.post_status = 'publish'
            ", $slug, $slug, $slug);

            $item = $wpdb->get_row($sql);
            
            if ($item) {
                $item->product_id = $slug;
                $item->image_url = $item->thumbnail_id ? wp_get_attachment_url($item->thumbnail_id) : null;
                $item->price_change = 0;
                $item->last_checked = 'N/A';

                if ($item->last_updated) {
                    $item->last_checked = human_time_diff( strtotime( $item->last_updated ), current_time( 'timestamp' ) ) . ' ago';
                }

                if ($item->current_price && $item->previous_price && $item->previous_price > 0) {
                    $item->price_change = (($item->current_price - $item->previous_price) / $item->previous_price) * 100;
                }
                
                $sparkline_prices = $wpdb->get_col($wpdb->prepare("SELECT price FROM {$price_history_table} WHERE product_id = %s AND date_recorded >= %s ORDER BY date_recorded ASC", $slug, date('Y-m-d', strtotime('-30 days'))));
                $item->sparkline_data = $sparkline_prices;

                $results[] = $item;
            }
        }
        return $results;
    }

    private function render_login_prompt() {
        return '<div class="wpcs-dashboard-login"><p>You must be logged in to view your dashboard.</p><a href="' . esc_url(wp_login_url(get_permalink())) . '" class="wpcs-dashboard-login-btn">Login or Register</a></div>';
    }

    private function get_notification_preferences($user_id) {
        $frequency = get_user_meta( $user_id, 'wpcs_notification_frequency', true );
        $targets = get_user_meta( $user_id, 'wpcs_target_prices', true );

        return [
            'frequency' => $frequency ? $frequency : 'daily',
            'targets'   => is_array($targets) ? $targets : [],
        ];
    }

    public function handle_save_notification_prefs() {
        check_ajax_referer( 'wpcs_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error();
        }

        $user_id = get_current_user_id();

        if ( isset($_POST['email_frequency']) ) {
            $frequency = sanitize_text_field($_POST['email_frequency']);
            update_user_meta($user_id, 'wpcs_notification_frequency', $frequency);
        }

        if ( isset($_POST['target_prices']) && is_array($_POST['target_prices']) ) {
            $sanitized_targets = [];
            foreach($_POST['target_prices'] as $product_id => $price) {
                $sanitized_id = sanitize_text_field($product_id);
                $sanitized_price = !empty($price) ? floatval($price) : '';
                $sanitized_targets[$sanitized_id] = $sanitized_price;
            }
            update_user_meta($user_id, 'wpcs_target_prices', $sanitized_targets);
        }

        wp_send_json_success();
    }


        public function handle_avatar_upload() {
        check_ajax_referer('wpcs_dashboard_nonce', 'nonce');
    
        if ( ! is_user_logged_in() || ! isset($_FILES['avatar']) ) {
            wp_send_json_error();
        }
    
        if ( ! function_exists('wp_handle_upload') ) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
    
        $uploaded_file = $_FILES['avatar'];
        $upload_overrides = array('test_form' => false);
        $movefile = wp_handle_upload($uploaded_file, $upload_overrides);
    
        if ( $movefile && ! isset($movefile['error']) ) {
            $filename = basename($movefile['url']);
            $attachment = array(
                'guid'           => $movefile['url'],
                'post_mime_type' => $movefile['type'],
                'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
                'post_content'   => '',
                'post_status'    => 'inherit'
            );
    
            $attach_id = wp_insert_attachment($attachment, $movefile['file']);
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);
    
            update_user_meta(get_current_user_id(), 'wpcs_profile_picture_id', $attach_id);
    
            wp_send_json_success(array('url' => wp_get_attachment_image_url($attach_id, 'thumbnail')));
        } else {
            wp_send_json_error(array('error' => $movefile['error']));
        }
    }




    public function handle_delete_account() {
        check_ajax_referer( 'wpcs_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array('message' => 'Not logged in.') );
        }

        require_once(ABSPATH.'wp-admin/includes/user.php');
        
        $user_id = get_current_user_id();
        
        delete_user_meta($user_id, 'wpcs_watchlist');
        delete_user_meta($user_id, 'wpcs_notification_frequency');
        delete_user_meta($user_id, 'wpcs_target_prices');

        wp_delete_user( $user_id );

        wp_send_json_success( array('redirect' => home_url()) );
    }

    public function handle_remove_from_watchlist() {
        check_ajax_referer( 'wpcs_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ) );
        }

        $product_id = sanitize_text_field( $_POST['product_id'] );
        $user_id = get_current_user_id();
        $watchlist = get_user_meta( $user_id, 'wpcs_watchlist', true );

        if ( ! empty( $watchlist ) && is_array( $watchlist ) ) {
            $watchlist = array_diff( $watchlist, array( $product_id ) );
            update_user_meta( $user_id, 'wpcs_watchlist', $watchlist );
        }

        wp_send_json_success();
    }
    
    private function get_custom_avatar_url($user_id, $size = 96) {
        $attachment_id = get_user_meta($user_id, 'wpcs_profile_picture_id', true);
        if ($attachment_id) {
            $url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            if ($url) return $url;
        }
        return get_avatar_url($user_id, ['size' => $size]);
    }

    private function get_dashboard_js() {
        $nonce = wp_create_nonce('wpcs_dashboard_nonce');
        return "
        document.addEventListener('DOMContentLoaded', function() {
            // --- Tab Switching ---
            const navLinks = document.querySelectorAll('.wpcs-nav-link');
            const panels = document.querySelectorAll('.wpcs-dashboard-panel');
            navLinks.forEach(link => {
                if (link.dataset.target) {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        const targetId = 'wpcs-panel-' + this.dataset.target;
                        navLinks.forEach(l => {
                            if (l.dataset.target) l.classList.remove('active');
                        });
                        this.classList.add('active');
                        panels.forEach(p => p.classList.remove('active'));
                        document.getElementById(targetId).classList.add('active');
                    });
                }
            });

            // --- Remove from Watchlist ---
            const removeButtons = document.querySelectorAll('.wpcs-dashboard-remove-btn');
            removeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const productId = this.dataset.productId;
                    const itemElement = document.getElementById('watchlist-item-' + productId);
                    if (confirm('Are you sure you want to remove this item from your watchlist?')) {
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', '" . admin_url('admin-ajax.php') . "');
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onload = function() {
                            if (xhr.status === 200) {
                                const response = JSON.parse(xhr.responseText);
                                if(response.success) {
                                    itemElement.style.transition = 'opacity 0.5s ease';
                                    itemElement.style.opacity = '0';
                                    setTimeout(() => {
                                        itemElement.remove();
                                        if (document.querySelectorAll('.wpcs-dashboard-item').length === 0) {
                                           location.reload();
                                        }
                                    }, 500);
                                }
                            }
                        };
                        xhr.send('action=wpcs_remove_from_watchlist&nonce=" . $nonce . "&product_id=' + productId);
                    }
                });
            });

            // --- Watchlist Sorting ---
            const sortSelect = document.getElementById('wpcs-sort-watchlist');
            const listContainer = document.querySelector('.wpcs-dashboard-list');
            if (sortSelect && listContainer) {
                sortSelect.addEventListener('change', function() {
                    const sortBy = this.value;
                    const items = Array.from(listContainer.querySelectorAll('.wpcs-dashboard-item'));

                    items.sort((a, b) => {
                        switch(sortBy) {
                            case 'price_asc':
                                return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
                            case 'price_desc':
                                return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
                            case 'price_drop':
                                return parseFloat(a.dataset.priceChange) - parseFloat(b.dataset.priceChange);
                            case 'date_added':
                            default:
                                return parseInt(a.dataset.dateAdded) - parseInt(b.dataset.dateAdded);
                        }
                    });

                    listContainer.innerHTML = '';
                    items.forEach(item => listContainer.appendChild(item));
                });
            }

            // --- Save Notification Preferences ---
            const prefsForm = document.getElementById('wpcs-notifications-form');
            if(prefsForm) {
                prefsForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const saveButton = this.querySelector('.wpcs-form-button');
                    const savedMsg = this.querySelector('.wpcs-prefs-saved-msg');
                    const formData = new FormData(this);
                    formData.append('action', 'wpcs_save_notification_prefs');
                    formData.append('nonce', '" . $nonce . "');

                    saveButton.textContent = 'Saving...';
                    saveButton.disabled = true;

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', '" . admin_url('admin-ajax.php') . "');
                    const params = new URLSearchParams(formData);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            saveButton.textContent = 'Save Preferences';
                            saveButton.disabled = false;
                            savedMsg.style.display = 'inline';
                            setTimeout(() => {
                                savedMsg.style.display = 'none';
                            }, 3000);
                        }
                    };
                    xhr.send(params);
                });
            }
            
            // --- Delete Account ---
            const deleteBtn = document.getElementById('wpcs-delete-account-btn');
            if(deleteBtn) {
                deleteBtn.addEventListener('click', function() {
                    if (confirm('Are you absolutely sure you want to delete your account? This will remove all your data and cannot be undone.')) {
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', '" . admin_url('admin-ajax.php') . "');
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onload = function() {
                           if (xhr.status === 200) {
                                const response = JSON.parse(xhr.responseText);
                                if(response.success && response.data.redirect) {
                                    window.location.href = response.data.redirect;
                                } else {
                                    alert('Could not delete account. Please try again.');
                                }
                            }
                        };
                        xhr.send('action=wpcs_delete_account&nonce=" . $nonce . "');
                    }
                });
            }


// --- NEW: Avatar Upload ---
            const changeAvatarBtn = document.getElementById('wpcs-change-avatar-btn');
            const avatarFileInput = document.getElementById('wpcs-avatar-file-input');
            const avatarImages = document.querySelectorAll('.wpcs-user-avatar, .wpcs-user-avatar-large');

            if (changeAvatarBtn && avatarFileInput) {
                changeAvatarBtn.addEventListener('click', function() {
                    avatarFileInput.click();
                });

                avatarFileInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const formData = new FormData();
                        formData.append('action', 'wpcs_avatar_upload');
                        formData.append('nonce', '" . $nonce . "');
                        formData.append('avatar', this.files[0]);

                        changeAvatarBtn.textContent = 'Uploading...';
                        changeAvatarBtn.disabled = true;

                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', '" . admin_url('admin-ajax.php') . "');
                        xhr.onload = function() {
                            changeAvatarBtn.textContent = 'Change Photo';
                            changeAvatarBtn.disabled = false;
                            if (xhr.status === 200) {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    avatarImages.forEach(img => img.src = response.data.url);
                                } else {
                                    alert('Upload failed. Please try another image.');
                                }
                            }
                        };
                        xhr.send(formData);
                    }
                });
            }



            // --- Render Sparklines ---
            const sparklineCanvases = document.querySelectorAll('.wpcs-sparkline-canvas');
            sparklineCanvases.forEach(canvas => {
                try {
                    const data = JSON.parse(canvas.dataset.sparkline);
                    if (data && data.length > 1) {
                        new Chart(canvas, {
                            type: 'line',
                            data: {
                                labels: Array(data.length).fill(''),
                                datasets: [{
                                    data: data,
                                    borderColor: data[0] > data[data.length - 1] ? '#16a34a' : '#dc2626',
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    tension: 0.4,
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                                scales: { x: { display: false }, y: { display: false } }
                            }
                        });
                    }
                } catch (e) {
                    console.error('Could not parse sparkline data', e);
                }
            });
        });
        ";
    }
}

