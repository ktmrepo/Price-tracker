<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://wpcarestudio.com/
 * @since      1.5.0
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/public
 */

class WPCS_Price_Tracker_Public {

    private $plugin_name;
    private $version;
    
    // Static cache to prevent duplicate DB queries when multiple shortcodes are used on one page
    private static $view_data_cache = [];

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action( 'wp_ajax_wpcs_toggle_watchlist', array( $this, 'handle_toggle_watchlist' ) );
        
        // Register Shortcodes for Modular Layouts (e.g. Elementor)
        add_shortcode('wpcs_header', array($this, 'shortcode_header'));
        add_shortcode('wpcs_store_list', array($this, 'shortcode_store_list'));
        add_shortcode('wpcs_price_meter', array($this, 'shortcode_price_meter'));
        add_shortcode('wpcs_price_stats', array($this, 'shortcode_price_stats'));
        add_shortcode('wpcs_price_graph', array($this, 'shortcode_price_graph'));
        add_shortcode('wpcs_category_badge', array($this, 'shortcode_category_badge'));
        add_shortcode('wpcs_related_products', array($this, 'shortcode_related_products'));
    }

    /**
     * Add custom body classes for our CPT single pages.
     */
    public function add_body_classes( $classes ) {
        if ( is_singular( 'wpcs_product' ) ) {
            $classes[] = 'full-width-content';
            $classes[] = 'page-template-default';
        }
        return $classes;
    }

    /**
     * Register the stylesheets and scripts for the public-facing side of the site.
     */
    public function enqueue_assets() {
        global $post;
        // We check for shortcodes or the post type to load assets
        $has_shortcode = is_a($post, 'WP_Post') && (
            has_shortcode($post->post_content, 'wpcs_dashboard') || 
            has_shortcode($post->post_content, 'wpcs_price_graph') ||
            has_shortcode($post->post_content, 'wpcs_price_meter') ||
            has_shortcode($post->post_content, 'wpcs_header') ||
            has_shortcode($post->post_content, 'wpcs_store_list') ||
            has_shortcode($post->post_content, 'wpcs_price_stats') ||
            has_shortcode($post->post_content, 'wpcs_related_products')
        );

        if ( is_singular( 'wpcs_product' ) || $has_shortcode ) {
            wp_enqueue_style( 
                $this->plugin_name, 
                plugin_dir_url( __FILE__ ) . 'css/wpcs-price-tracker-public.css', 
                array(), 
                $this->version, 
                'all' 
            );
            
            wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
            wp_enqueue_script( 'dayjs', 'https://cdn.jsdelivr.net/npm/dayjs@1.11.7/dayjs.min.js', array(), '1.11.7', true );

            // Always load the chart logic if we are on a product page or using the graph shortcode
            if ( is_a($post, 'WP_Post') ) {
                wp_add_inline_script( 'chart-js', $this->get_chart_js() );

                // Fetch data to localize for the chart
                $view_data = $this->get_view_data($post->ID);
                if ($view_data) {
                    wp_localize_script( 'chart-js', 'wpcsPriceTrackerData', array(
                        'history' => $view_data['history']
                    ));
                }
            }
            
            $product_slug = is_a($post, 'WP_Post') ? $post->post_name : '';
            wp_enqueue_script(
                $this->plugin_name . '-watchlist',
                '#', // We are adding the script inline
                array('jquery'),
                $this->version,
                true
            );
            wp_add_inline_script( $this->plugin_name . '-watchlist', $this->get_watchlist_js() );

            wp_localize_script($this->plugin_name . '-watchlist', 'wpcsWatchlist', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wpcs_watchlist_nonce'),
                'isLoggedIn' => is_user_logged_in(),
                'productSlug' => $product_slug,
                'loginUrl' => wp_login_url( get_permalink() )
            ));
        }
    }

    /**
     * Centralized Data Fetching with Static Cache.
     * Accepts either a post ID or a specific slug.
     */
    private function get_view_data($post_id_or_slug = null) {
        $post_id = null;
        $product_slug = null;

        if ( empty($post_id_or_slug) ) {
            // Fallback to current global post
            $post_id = get_the_ID();
        } elseif ( is_numeric($post_id_or_slug) ) {
            $post_id = $post_id_or_slug;
        } elseif ( is_string($post_id_or_slug) ) {
            // It's a slug, find the ID
            $found_post = get_page_by_path($post_id_or_slug, OBJECT, 'wpcs_product');
            if ($found_post) {
                $post_id = $found_post->ID;
            } else {
                return null; // Slug not found
            }
        }

        if ( !$post_id ) return null;

        // Check cache
        if (isset(self::$view_data_cache[$post_id])) {
            return self::$view_data_cache[$post_id];
        }

        $post = get_post($post_id);
        if (!$post) return null;

        $product_slug = $post->post_name;
        $product_data = $this->get_product_data($product_slug);

        if (empty($product_data)) {
            return null;
        }

        $price_history = $this->get_price_history($product_slug, $product_data['primary_store']);

        // Logic for Price Stats
        $today = current_time('Y-m-d');
        $live_current_price = null;
        $primary_store_url = '#';

        foreach ($product_data['stores'] as $store) {
            if ($store['StoreName'] === $product_data['primary_store']) {
                $primary_store_url = $store['ProductURL'];
                if (!empty($store['CurrentPrice'])) {
                    $live_current_price = preg_replace('/[^\d.]/', '', $store['CurrentPrice']);
                }
                break;
            }
        }
        
        // Merge live price into history for chart continuity
        $history_dates = wp_list_pluck($price_history, 'date_recorded');
        if (!in_array($today, $history_dates) && !is_null($live_current_price) && is_numeric($live_current_price)) {
            $today_obj = new stdClass();
            $today_obj->price = $live_current_price;
            $today_obj->date_recorded = $today;
            $price_history[] = $today_obj;
        }
        
        $prices = wp_list_pluck($price_history, 'price');
        $highest = !empty($prices) ? max($prices) : 0;
        $lowest = !empty($prices) ? min($prices) : 0;
        $current_price = !empty($prices) ? end($prices) : 0;
        
        // Meter Rotation Logic
        $percentage = ($highest > $lowest) ? (($current_price - $lowest) / ($highest - $lowest)) * 100 : 50;
        $percentage = max(0, min(100, $percentage));
        $rotation = ($percentage * 1.8) - 90;

        if ($percentage <= 33) {
            $status_text = 'Lower Range';
            $status_class = 'status-lowest';
            $status_desc = 'An excellent time to consider buying.';
        } elseif ($percentage <= 66) {
            $status_text = 'Average Range';
            $status_class = 'status-average';
            $status_desc = 'The price is within its typical range.';
        } else {
            $status_text = 'Highest Range';
            $status_class = 'status-highest';
            $status_desc = 'You might want to wait for a better deal.';
        }

        $user_id = get_current_user_id();
        $watchlist = get_user_meta($user_id, 'wpcs_watchlist', true);
        $is_on_watchlist = !empty($watchlist) && in_array($product_slug, $watchlist);

        // Categories logic (New)
        $categories = get_the_terms($post_id, 'wpcs_category');
        $cat_list = [];
        if ($categories && !is_wp_error($categories)) {
            foreach($categories as $cat) {
                $cat_list[] = [
                    'name' => $cat->name,
                    'link' => get_term_link($cat)
                ];
                if(count($cat_list) >= 2) break; // Limit to 2
            }
        }

        $data = [
            'post_id' => $post_id,
            'post_title' => get_the_title($post_id),
            'product_slug' => $product_slug,
            'product_data' => $product_data,
            'history' => $price_history,
            'primary_url' => $primary_store_url,
            'categories' => $cat_list, // New
            'stats' => [
                'highest' => $highest,
                'lowest' => $lowest,
                'current' => $current_price,
                'rotation' => $rotation,
                'status_text' => $status_text,
                'status_class' => $status_class,
                'status_desc' => $status_desc
            ],
            'is_watched' => $is_on_watchlist
        ];

        self::$view_data_cache[$post_id] = $data;
        return $data;
    }

    /**
     * Append the price tracker display to the post content (Legacy Mode).
     * UPDATED: Checks for 'Disable Auto Display' meta before rendering.
     */
    public function display_price_tracker( $content ) {
        if ( is_singular( 'wpcs_product' ) && in_the_loop() && is_main_query() ) {
            global $post;
            
            // Check if auto-display is disabled for this product
            $disable_auto = get_post_meta( $post->ID, '_wpcs_disable_auto_display', true );
            if ( $disable_auto === '1' ) {
                return $content; // Do nothing, user will use shortcodes
            }

            $data = $this->get_view_data($post->ID);

            if ( $data ) {
                ob_start();
                echo '<div class="wpcs-container"><main class="wpcs-main-content">';
                echo $this->render_header($data);
                echo $this->render_store_list($data);
                echo '<div class="wpcs-analysis-stats-grid">';
                echo $this->render_price_meter($data);
                echo $this->render_price_stats($data);
                echo '</div>';
                echo $this->render_price_graph($data);
                
                // Auto-append related products if not hidden
                $hide_related = get_post_meta( $post->ID, '_wpcs_hide_related_products', true );
                if( $hide_related !== '1' ) {
                    echo $this->render_related_products($data);
                }

                echo '</main></div>';
                echo $this->render_login_modal();
                $content .= ob_get_clean();
            }
        }
        return $content;
    }

    // --- SHORTCODE HANDLERS ---

    private function resolve_shortcode_data($atts) {
        $atts = shortcode_atts(['slug' => '', 'id' => ''], $atts);
        if (!empty($atts['slug'])) {
            return $this->get_view_data($atts['slug']);
        } elseif (!empty($atts['id'])) {
            return $this->get_view_data($atts['id']);
        }
        return $this->get_view_data(get_the_ID());
    }

    public function shortcode_header($atts) {
        $data = $this->resolve_shortcode_data($atts);
        return $data ? '<div class="wpcs-container">' . $this->render_header($data) . $this->render_login_modal() . '</div>' : '';
    }

    public function shortcode_store_list($atts) {
        $data = $this->resolve_shortcode_data($atts);
        return $data ? '<div class="wpcs-container">' . $this->render_store_list($data) . '</div>' : '';
    }

    public function shortcode_price_meter($atts) {
        $data = $this->resolve_shortcode_data($atts);
        return $data ? '<div class="wpcs-container">' . $this->render_price_meter($data) . '</div>' : '';
    }

    public function shortcode_price_stats($atts) {
        $data = $this->resolve_shortcode_data($atts);
        return $data ? '<div class="wpcs-container">' . $this->render_price_stats($data) . '</div>' : '';
    }

    public function shortcode_price_graph($atts) {
        $data = $this->resolve_shortcode_data($atts);
        return $data ? '<div class="wpcs-container">' . $this->render_price_graph($data) . '</div>' : '';
    }

    public function shortcode_category_badge($atts) {
        $data = $this->resolve_shortcode_data($atts);
        // Just return badges div
        if(!$data || empty($data['categories'])) return '';
        $html = '<div class="wpcs-category-badges">';
        foreach($data['categories'] as $cat) {
            $html .= '<a href="' . esc_url($cat['link']) . '" class="wpcs-cat-badge">' . esc_html($cat['name']) . '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    public function shortcode_related_products($atts) {
        $data = $this->resolve_shortcode_data($atts);
        return $data ? '<div class="wpcs-container">' . $this->render_related_products($data) . '</div>' : '';
    }

    // --- RENDER FUNCTIONS (Reusable) ---

    private function render_header($data) {
        $active_class = $data['is_watched'] ? 'active' : '';
        $thumb = has_post_thumbnail($data['post_id']) ? get_the_post_thumbnail($data['post_id'], 'medium', ['class' => 'wpcs-header-img']) : '';
        
        $img_html = '';
        if ($thumb) {
            $img_html = '<div class="wpcs-header-img-container">' . $thumb . '</div>';
        }

        // Category badges logic
        $cat_html = '';
        if (!empty($data['categories'])) {
            $cat_html = '<div class="wpcs-category-badges">';
            foreach($data['categories'] as $cat) {
                $cat_html .= '<a href="' . esc_url($cat['link']) . '" class="wpcs-cat-badge">' . esc_html($cat['name']) . '</a>';
            }
            $cat_html .= '</div>';
        }

        return '
        <div class="wpcs-card wpcs-header">
            ' . $img_html . '
            <div class="wpcs-header-content">
                ' . $cat_html . '
                <h1 class="wpcs-header-title">' . esc_html($data['post_title']) . '</h1>
                <a href="' . esc_url($data['primary_url']) . '" class="wpcs-header-link">View on ' . esc_html($data['product_data']['primary_store']) . ' &rarr;</a>
            </div>
            <button class="wpcs-watchlist-icon-button ' . esc_attr($active_class) . '" title="Add to Watchlist" data-product-slug="' . esc_attr($data['product_slug']) . '">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
            </button>
        </div>';
    }

    private function render_store_list($data) {
        $html = '<div class="wpcs-card"><h2 class="wpcs-section-title">Available Platforms</h2><div class="wpcs-platforms-grid">';
        
        $index = 0; 
        foreach($data['product_data']['stores'] as $store) {
            $is_primary = ($store['StoreName'] === $data['product_data']['primary_store']);
            $card_class = 'wpcs-platform-card' . ($is_primary ? ' primary' : '');
            $logo_html = !empty($store['StoreLogoURL']) ? '<img src="' . esc_url($store['StoreLogoURL']) . '" alt="' . esc_attr($store['StoreName']) . '" class="wpcs-platform-logo">' : '';
            
            $price = (float)$store['CurrentPrice'];
            
            $btn_text = 'Buy Now';
            $btn_link = esc_url($store['ProductURL']);
            $btn_class = 'wpcs-platform-buy-button';
            $target_attr = 'target="_blank" rel="noopener nofollow"';

            if ($index < 3 && $price <= 0) {
                $btn_text = 'Out of Stock';
                $btn_link = '#';
                $btn_class .= ' is-disabled';
                $target_attr = ''; 
            }

            $price_display = ($price > 0) ? 'NPR ' . number_format($price) : '';

            $html .= '
            <a href="' . $btn_link . '" ' . $target_attr . ' class="' . $card_class . '">
                <div class="wpcs-platform-logo-container">' . $logo_html . '</div>
                <div class="wpcs-platform-details">
                    <h3 class="wpcs-platform-name">' . esc_html($store['StoreName']) . '</h3>
                    <p class="wpcs-platform-price">' . esc_html($price_display) . '</p>
                </div>
                <div class="' . $btn_class . '">' . $btn_text . '</div>
            </a>';
            
            $index++;
        }
        $html .= '</div></div>';
        return $html;
    }

    private function render_price_meter($data) {
        $s = $data['stats'];
        $current_display = ($s['current'] > 0) ? 'NPR ' . number_format($s['current']) : 'N/A';

        return '
        <div class="wpcs-card wpcs-analysis-card">
            <h2 class="wpcs-analysis-title">Price Analysis</h2>
            <p class="wpcs-analysis-subtitle">The current price relative to its historical high and low.</p>
            <div class="wpcs-gauge-wrapper">
                <div class="wpcs-gauge-container">
                    <div class="wpcs-gauge-arc"></div>
                    <div class="wpcs-gauge-cover"></div>
                    <div class="wpcs-gauge-needle" style="transform: rotate(' . esc_attr($s['rotation']) . 'deg);"></div>
                    <div class="wpcs-gauge-needle-knob"></div>
                </div>
                <div class="wpcs-gauge-price-display">
                    <p class="wpcs-gauge-value">' . $current_display . '</p>
                    <p class="wpcs-gauge-status ' . esc_attr($s['status_class']) . '">' . esc_html($s['status_text']) . '</p>
                    <p class="wpcs-gauge-description">' . esc_html($s['status_desc']) . '</p>
                </div>
            </div>
        </div>';
    }

    private function render_price_stats($data) {
        $s = $data['stats'];
        $high_display = ($s['highest'] > 0) ? 'NPR ' . number_format($s['highest']) : 'N/A';
        $low_display = ($s['lowest'] > 0) ? 'NPR ' . number_format($s['lowest']) : 'N/A';
        $curr_display = ($s['current'] > 0) ? 'NPR ' . number_format($s['current']) : 'N/A';

        return '
        <div class="wpcs-card wpcs-stats-column">
            <h2 class="wpcs-section-title">Price Statistics</h2>
            <div class="wpcs-stats-item highest">
                <p class="wpcs-stats-label">Highest Price</p>
                <p class="wpcs-stats-value">' . $high_display . '</p>
            </div>
            <div class="wpcs-stats-item lowest">
                <p class="wpcs-stats-label">Lowest Price</p>
                <p class="wpcs-stats-value">' . $low_display . '</p>
            </div>
            <div class="wpcs-stats-item current">
                <p class="wpcs-stats-label">Current Price</p>
                <p class="wpcs-stats-value">' . $curr_display . '</p>
            </div>
        </div>';
    }

    private function render_price_graph($data) {
        $unique_id = 'wpcsPriceChart_' . $data['post_id'] . '_' . wp_rand(1000, 9999);
        $history_json = htmlspecialchars(json_encode($data['history']), ENT_QUOTES, 'UTF-8');

        return '
        <div class="wpcs-card wpcs-chart-wrapper" id="wrapper_' . esc_attr($unique_id) . '">
            <div class="wpcs-chart-header">
                <h2 class="wpcs-chart-title">Price History</h2>
                <div class="wpcs-chart-filters">
                    <button class="wpcs-chart-filter-btn" data-days="30" data-target="' . esc_attr($unique_id) . '">30D</button>
                    <button class="wpcs-chart-filter-btn" data-days="60" data-target="' . esc_attr($unique_id) . '">60D</button>
                    <button class="wpcs-chart-filter-btn" data-days="90" data-target="' . esc_attr($unique_id) . '">90D</button>
                    <button class="wpcs-chart-filter-btn active" data-days="all" data-target="' . esc_attr($unique_id) . '">All</button>
                </div>
            </div>
            <div class="wpcs-chart-container">
                <canvas id="' . esc_attr($unique_id) . '" class="wpcs-chart-canvas" data-history="' . $history_json . '"></canvas>
            </div>
        </div>';
    }

    private function render_related_products($data) {
        // 1. Get terms
        $terms = get_the_terms($data['post_id'], 'wpcs_category');
        if (empty($terms) || is_wp_error($terms)) {
            return ''; // No categories, nothing to show
        }
        $term_ids = wp_list_pluck($terms, 'term_id');

        // 2. Query related
        $args = array(
            'post_type' => 'wpcs_product',
            'posts_per_page' => 6,
            'post__not_in' => array($data['post_id']),
            'orderby' => 'rand',
            'tax_query' => array(
                array(
                    'taxonomy' => 'wpcs_category',
                    'field' => 'term_id',
                    'terms' => $term_ids,
                ),
            ),
        );
        $related = new WP_Query($args);

        if (!$related->have_posts()) {
            wp_reset_postdata();
            return '';
        }

        // 3. Render
        $html = '<div class="wpcs-card"><h2 class="wpcs-section-title">Related Products</h2><div class="wpcs-related-grid">';
        while ($related->have_posts()) {
            $related->the_post();
            $thumb = get_the_post_thumbnail(get_the_ID(), 'thumbnail', ['class' => 'wpcs-related-img']);
            $link = get_permalink();
            $title = get_the_title();
            
            // Try to get price from cache
            $r_data = $this->get_view_data(get_the_ID());
            $price_text = 'View Price';
            if($r_data && $r_data['stats']['current'] > 0) {
                $price_text = 'NPR ' . number_format($r_data['stats']['current']);
            }

            $html .= '
            <a href="' . esc_url($link) . '" class="wpcs-related-card">
                <div class="wpcs-related-img-wrap">' . $thumb . '</div>
                <div class="wpcs-related-info">
                    <h4 class="wpcs-related-title">' . esc_html($title) . '</h4>
                    <span class="wpcs-related-price">' . esc_html($price_text) . '</span>
                </div>
            </a>';
        }
        wp_reset_postdata();
        $html .= '</div></div>';
        return $html;
    }

    private function render_login_modal() {
        return '
        <div class="wpcs-modal-overlay" id="wpcs-login-modal" style="display: none;">
            <div class="wpcs-modal-content">
                <button class="wpcs-modal-close-btn">&times;</button>
                <h3>Login Required</h3>
                <p>Please log in to add this item to your watchlist.</p>
                <a href="' . esc_url(wp_login_url(get_permalink())) . '" class="wpcs-modal-login-btn">Login Now</a>
            </div>
        </div>';
    }

    // --- DATA FETCHING HELPERS ---

    private function get_product_data( $product_id ) {
        $cached_data = get_transient( 'wpcs_price_tracker_data' );
        if ( isset( $cached_data[ $product_id ] ) ) {
            return $cached_data[ $product_id ];
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'wpcs_product_data';
        $db_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE product_id = %s", $product_id ), ARRAY_A );

        if ( ! $db_data ) {
            return null;
        }

        $structured_data = [
            'title' => $db_data['product_title'],
            'primary_store' => $db_data['primary_store'],
            'stores' => json_decode( $db_data['stores_data'], true )
        ];

        $new_cache = is_array($cached_data) ? $cached_data : [];
        $new_cache[$product_id] = $structured_data;
        set_transient( 'wpcs_price_tracker_data', $new_cache, 12 * HOUR_IN_SECONDS );

        return $structured_data;
    }

    private function get_price_history( $product_id, $store_name ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpcs_price_history';
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT price, date_recorded FROM $table_name WHERE product_id = %s AND store_name = %s ORDER BY date_recorded ASC",
            $product_id, $store_name
        ) );
        return $results;
    }
    
    /**
     * AJAX handler for toggling an item in the watchlist.
     */
    public function handle_toggle_watchlist() {
        check_ajax_referer( 'wpcs_watchlist_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in.' ) );
        }

        $product_slug = sanitize_text_field( $_POST['product_slug'] );
        $user_id = get_current_user_id();
        $watchlist = get_user_meta( $user_id, 'wpcs_watchlist', true );
        if ( ! is_array( $watchlist ) ) {
            $watchlist = array();
        }

        if ( in_array( $product_slug, $watchlist ) ) {
            $watchlist = array_diff( $watchlist, array( $product_slug ) );
            $action = 'removed';
        } else {
            $watchlist[] = $product_slug;
            $action = 'added';
        }

        update_user_meta( $user_id, 'wpcs_watchlist', $watchlist );
        wp_send_json_success( array( 'action' => $action ) );
    }

    private function get_watchlist_js() {
        return "
        jQuery(document).ready(function($) {
            const modal = $('#wpcs-login-modal');
            const closeModal = $('.wpcs-modal-close-btn');

            function showModal() { modal.fadeIn(200); }
            function hideModal() { modal.fadeOut(200); }

            closeModal.on('click', hideModal);
            modal.on('click', function(e) { if (e.target === this) hideModal(); });

            $(document).on('click', '.wpcs-watchlist-icon-button', function(e) {
                e.preventDefault();
                const button = $(this);

                if (!wpcsWatchlist.isLoggedIn) {
                    showModal();
                    return;
                }

                $.ajax({
                    type: 'POST',
                    url: wpcsWatchlist.ajaxUrl,
                    data: {
                        action: 'wpcs_toggle_watchlist',
                        nonce: wpcsWatchlist.nonce,
                        product_slug: button.data('product-slug')
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.action === 'added') {
                                button.addClass('active');
                            } else {
                                button.removeClass('active');
                            }
                        }
                    }
                });
            });
        });
        ";
    }

    private function get_chart_js() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart === 'undefined' || typeof dayjs === 'undefined') return;
            
            const canvases = document.querySelectorAll('.wpcs-chart-canvas');
            
            canvases.forEach(function(ctx) {
                try {
                    const rawHistory = ctx.dataset.history;
                    if (!rawHistory) return;
                    
                    const fullHistory = JSON.parse(rawHistory);
                    if (!fullHistory || fullHistory.length === 0) return;

                    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, 'rgba(37, 99, 235, 0.3)');
                    gradient.addColorStop(1, 'rgba(37, 99, 235, 0)');

                    const priceChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: fullHistory.map(item => item.date_recorded),
                            datasets: [{
                                label: 'Price',
                                data: fullHistory.map(item => item.price),
                                backgroundColor: gradient,
                                borderColor: '#2563EB',
                                borderWidth: 2.5,
                                pointRadius: 0,
                                pointHoverRadius: 6,
                                pointHitRadius: 10,
                                pointHoverBackgroundColor: '#2563EB',
                                pointHoverBorderColor: '#FFF',
                                tension: 0.4,
                                fill: true,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { 
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        title: function(context) { return 'Date: ' + context[0].label; },
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) label += ': ';
                                            if (context.parsed.y !== null) {
                                                const formattedPrice = new Intl.NumberFormat('en-US').format(context.parsed.y);
                                                label += 'NPR ' + formattedPrice;
                                            }
                                            return label;
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: { ticks: { callback: function(value) { return 'NPR ' + (value / 1000) + 'k'; } } },
                                x: { grid: { display: false } }
                            }
                        }
                    });

                    const uniqueId = ctx.id;
                    const wrapper = document.querySelector('#wrapper_' + uniqueId);
                    
                    if (wrapper) {
                        const filterButtons = wrapper.querySelectorAll('.wpcs-chart-filter-btn');
                        
                        filterButtons.forEach(button => {
                            button.addEventListener('click', function() {
                                filterButtons.forEach(btn => btn.classList.remove('active'));
                                this.classList.add('active');
                                
                                const days = this.dataset.days;
                                let filteredData;
                                
                                if (days === 'all') {
                                    filteredData = fullHistory;
                                } else {
                                    const startDate = dayjs().subtract(days, 'day');
                                    filteredData = fullHistory.filter(item => dayjs(item.date_recorded).isAfter(startDate));
                                }

                                priceChart.data.labels = filteredData.map(item => item.date_recorded);
                                priceChart.data.datasets[0].data = filteredData.map(item => item.price);
                                priceChart.update();
                            });
                        });
                    }

                } catch (e) {
                    console.error('WPCS Chart Error:', e);
                }
            });
        });
        ";
    }
}