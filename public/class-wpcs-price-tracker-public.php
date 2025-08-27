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

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Register the stylesheets and scripts for the public-facing side of the site.
     */
    public function enqueue_assets() {
        if ( is_singular() ) {
            global $post;
            $post_slug = $post->post_name;
            $all_data = get_transient( 'wpcs_price_tracker_data' );

            if ( $all_data && isset( $all_data[ $post_slug ] ) ) {
                // Enqueue our new local stylesheet
                wp_enqueue_style( 
                    $this->plugin_name, 
                    plugin_dir_url( __FILE__ ) . 'css/wpcs-price-tracker-public.css', 
                    array(), 
                    $this->version, 
                    'all' 
                );
                
                wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true );
                
                wp_add_inline_script( 'chart-js', $this->get_chart_js() );

                $price_history = $this->get_price_history( $post_slug );
                wp_localize_script( 'chart-js', 'wpcsPriceTrackerData', array(
                    'history' => $price_history
                ));
            }
        }
    }

    /**
     * Append the price tracker display to the post content.
     */
    public function display_price_tracker( $content ) {
        if ( is_singular() && in_the_loop() && is_main_query() ) {
            global $post;
            $post_slug = $post->post_name;
            $all_data = get_transient( 'wpcs_price_tracker_data' );

            if ( $all_data && isset( $all_data[ $post_slug ] ) ) {
                $product_data = $all_data[ $post_slug ];
                $price_history = $this->get_price_history( $post_slug );
                
                $content .= $this->render_price_tracker_html( $post, $product_data, $price_history );
            }
        }
        return $content;
    }

    private function get_price_history( $product_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpcs_price_history';
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT price, date_recorded FROM $table_name WHERE product_id = %s ORDER BY date_recorded ASC",
            $product_id
        ) );
        return $results;
    }

    private function render_price_tracker_html( $post, $product_data, $price_history ) {
        $prices = wp_list_pluck( $price_history, 'price' );
        $highest = !empty($prices) ? max($prices) : 0;
        $lowest = !empty($prices) ? min($prices) : 0;
        $average = !empty($prices) ? round(array_sum($prices) / count($prices)) : 0;
        $current_price = !empty($prices) ? end($prices) : 0;
        $primary_store_url = '#';
        foreach($product_data['stores'] as $store) {
            if($store['StoreName'] === $product_data['primary_store']) {
                $primary_store_url = $store['ProductURL'];
                break;
            }
        }
        $meter_position = ($highest > $lowest) ? (($current_price - $lowest) / ($highest - $lowest)) * 100 : 50;
        $meter_position = max(0, min(100, $meter_position));

        ob_start();
        ?>
        <div class="wpcs-container">
            <main style="display: grid; gap: 2rem;">
                <!-- Header Section -->
                <div class="wpcs-card wpcs-header">
                    <?php if ( has_post_thumbnail( $post->ID ) ) : ?>
                        <div class="wpcs-header-img-container">
                            <?php echo get_the_post_thumbnail( $post->ID, 'medium', array( 'class' => 'wpcs-header-img' ) ); ?>
                        </div>
                    <?php endif; ?>
                    <div class="wpcs-header-content">
                        <h1 class="wpcs-header-title"><?php echo esc_html( get_the_title($post->ID) ); ?></h1>
                        <a href="<?php echo esc_url($primary_store_url); ?>" class="wpcs-header-link">View on <?php echo esc_html($product_data['primary_store']); ?> &rarr;</a>
                        <div class="wpcs-grid wpcs-grid-cols-sm-3" style="margin-top: 1.25rem;">
                            <div class="wpcs-stats-card"><p class="wpcs-stats-label">Highest</p><p class="wpcs-stats-value highest">NPR <?php echo number_format($highest); ?></p></div>
                            <div class="wpcs-stats-card"><p class="wpcs-stats-label">Lowest</p><p class="wpcs-stats-value lowest">NPR <?php echo number_format($lowest); ?></p></div>
                            <div class="wpcs-stats-card"><p class="wpcs-stats-label">Average</p><p class="wpcs-stats-value average">NPR <?php echo number_format($average); ?></p></div>
                        </div>
                    </div>
                </div>

                <!-- Available Platforms Section -->
                <div>
                    <h2 style="font-size: 1.25rem; margin-bottom: 0.75rem;">Available Platforms</h2>
                    <div class="wpcs-grid wpcs-grid-cols-md-3">
                        <?php foreach($product_data['stores'] as $store): ?>
                            <a href="<?php echo esc_url($store['ProductURL']); ?>" target="_blank" rel="noopener nofollow" class="wpcs-platform-card <?php echo ($store['StoreName'] === $product_data['primary_store']) ? 'primary' : ''; ?>">
                                <h3 class="wpcs-platform-name">Buy on <?php echo esc_html($store['StoreName']); ?></h3>
                                <p class="wpcs-platform-price">NPR <?php echo number_format(preg_replace('/[^\d.]/', '', $store['CurrentPrice'])); ?></p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Price Analysis Section -->
                <div class="wpcs-card">
                    <p style="font-size: 1.25rem; font-weight: 600;">Price Analysis</p>
                    <p style="color: #6b7280; margin-bottom: 1.5rem;">The current price is shown on the meter below, relative to its historical high and low.</p>
                    <div style="padding-top: 0.25rem;">
                        <div class="wpcs-meter-track">
                            <div class="wpcs-meter-marker" style="left: <?php echo esc_attr($meter_position); ?>%;"></div>
                        </div>
                        <div class="wpcs-meter-labels">
                            <span>Lowest: NPR <?php echo number_format($lowest); ?></span>
                            <span>Highest: NPR <?php echo number_format($highest); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Price History Chart Section -->
                <div class="wpcs-card">
                    <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem;">Price History</h2>
                    <div class="wpcs-chart-container"><canvas id="wpcsPriceChart"></canvas></div>
                </div>
            </main>
        </div>
        <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <?php
        return ob_get_clean();
    }
    
    private function get_chart_js() {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart === 'undefined' || typeof wpcsPriceTrackerData === 'undefined' || !wpcsPriceTrackerData.history) return;
            const ctx = document.getElementById('wpcsPriceChart');
            if (!ctx) return;
            const labels = wpcsPriceTrackerData.history.map(item => item.date_recorded);
            const data = wpcsPriceTrackerData.history.map(item => item.price);
            const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(37, 99, 235, 0.3)');
            gradient.addColorStop(1, 'rgba(37, 99, 235, 0)');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Price (NPR)',
                        data: data,
                        backgroundColor: gradient,
                        borderColor: '#2563EB',
                        borderWidth: 2.5,
                        pointRadius: 0,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: '#2563EB',
                        pointHoverBorderColor: '#FFF',
                        tension: 0.4,
                        fill: true,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { ticks: { callback: function(value) { return 'NPR ' + (value / 1000) + 'k'; } } },
                        x: { grid: { display: false } }
                    }
                }
            });
        });
        ";
    }
}
