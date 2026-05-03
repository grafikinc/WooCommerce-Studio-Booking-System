<?php
/**
 * Automatic Package Discounts
 * 
 * Calculates discounts based on what's in the cart:
 * - Multiple rooms booked = X% off rooms
 * - Full day booking (8+ hours) = X% off
 * - Room + Engineer combo = X% off engineer
 * 
 * Users don't "create" packages - the cart figures it out automatically.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SB_Packages {
    
    private static $instance = null;
    
    // Product category slugs - these must match your WooCommerce categories
    private $room_categories = array('live-rooms', 'podcasts');
    private $engineer_category = 'backline-packages'; // Engineer add-ons live here
    private $equipment_categories = array('audio-equipment', 'instruments', 'lighting', 'video-equipment');
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Apply discounts at cart calculation
        add_action('woocommerce_cart_calculate_fees', array($this, 'calculate_discounts'));
        
        // Show discount info on cart page
        add_action('woocommerce_before_cart', array($this, 'show_discount_info'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'), 60);
        
        SB_Debug::log('Packages class initialized');
    }
    
    /**
     * Get discount settings
     */
    private function get_settings() {
        return get_option('sb_discount_settings', array(
            'multi_room_enabled'    => true,
            'multi_room_percent'    => 10,
            'multi_room_min_items'  => 2,
            'full_day_enabled'      => true,
            'full_day_percent'      => 15,
            'full_day_min_hours'    => 8,
            'engineer_addon_discount_enabled' => true,
            'engineer_addon_discount_percent' => 5,
        ));
    }
    
    /**
     * Analyze what's in the cart
     */
    private function analyze_cart($cart_items) {
        $analysis = array(
            'rooms' => array(),
            'engineers' => array(),
            'equipment' => array(),
            'other' => array(),
            'room_total' => 0,
            'engineer_total' => 0,
            'equipment_total' => 0,
            'total_hours' => 0,
            'dates' => array(),
        );
        
        foreach ($cart_items as $cart_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'slugs'));
            $line_total = $cart_item['line_total'];
            $sku = $product->get_sku();
            
            // Get booking duration if set
            $duration = isset($cart_item['booking_duration']) ? intval($cart_item['booking_duration']) : 0;
            $booking_date = isset($cart_item['booking_date']) ? $cart_item['booking_date'] : '';
            
            // Track dates
            if ($booking_date) {
                if (!isset($analysis['dates'][$booking_date])) {
                    $analysis['dates'][$booking_date] = 0;
                }
                $analysis['dates'][$booking_date] += $duration;
            }
            
            // Categorize cart item
            $is_room = !empty(array_intersect($categories, $this->room_categories));
            $is_engineer = (strpos($sku, 'BP-ENGINEER') !== false);
            $is_equipment = !empty(array_intersect($categories, $this->equipment_categories));
            
            if ($is_room) {
                $analysis['rooms'][] = array(
                    'key' => $cart_key,
                    'name' => $product->get_name(),
                    'total' => $line_total,
                    'duration' => $duration,
                    'date' => $booking_date,
                );
                $analysis['room_total'] += $line_total;
                $analysis['total_hours'] += ($duration / 60);
            } elseif ($is_engineer) {
                $analysis['engineers'][] = array(
                    'key' => $cart_key,
                    'name' => $product->get_name(),
                    'total' => $line_total,
                );
                $analysis['engineer_total'] += $line_total;
            } elseif ($is_equipment) {
                $analysis['equipment'][] = array(
                    'key' => $cart_key,
                    'name' => $product->get_name(),
                    'total' => $line_total,
                );
                $analysis['equipment_total'] += $line_total;
            } else {
                $analysis['other'][] = array(
                    'key' => $cart_key,
                    'name' => $product->get_name(),
                    'total' => $line_total,
                );
            }
        }
        
        return $analysis;
    }
    
    /**
     * Calculate and apply discounts
     */
    public function calculate_discounts($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        $settings = $this->get_settings();
        $cart_items = $cart->get_cart();
        
        if (empty($cart_items)) {
            return;
        }
        
        $analysis = $this->analyze_cart($cart_items);
        
        SB_Debug::log('Cart analysis: ' . count($analysis['rooms']) . ' rooms, ' . 
                       count($analysis['engineers']) . ' engineers, ' . 
                       count($analysis['equipment']) . ' equipment items');
        
        // DISCOUNT 1: Multiple rooms
        if ($settings['multi_room_enabled'] && 
            count($analysis['rooms']) >= $settings['multi_room_min_items']) {
            
            $discount = $analysis['room_total'] * ($settings['multi_room_percent'] / 100);
            
            if ($discount > 0) {
                $cart->add_fee(
                    sprintf('Multi-Room Discount (%d%% off %d rooms)', 
                        $settings['multi_room_percent'], 
                        count($analysis['rooms'])
                    ),
                    -$discount
                );
                
                SB_Debug::log("Applied multi-room discount: KES {$discount}");
            }
        }
        
        // DISCOUNT 2: Full day booking (8+ hours on same date)
        if ($settings['full_day_enabled']) {
            foreach ($analysis['dates'] as $date => $total_minutes) {
                $total_hours = $total_minutes / 60;
                
                if ($total_hours >= $settings['full_day_min_hours']) {
                    // Calculate discount on rooms booked for this date
                    $date_room_total = 0;
                    foreach ($analysis['rooms'] as $room) {
                        if ($room['date'] === $date) {
                            $date_room_total += $room['total'];
                        }
                    }
                    
                    $discount = $date_room_total * ($settings['full_day_percent'] / 100);
                    
                    if ($discount > 0) {
                        $display_date = date_i18n(get_option('date_format'), strtotime($date));
                        $cart->add_fee(
                            sprintf('Full Day Discount (%d%% off - %s)', 
                                $settings['full_day_percent'],
                                $display_date
                            ),
                            -$discount
                        );
                        
                        SB_Debug::log("Applied full day discount for {$date}: KES {$discount}");
                    }
                }
            }
        }
        
        // DISCOUNT 3: Room + Engineer combo
        if ($settings['engineer_addon_discount_enabled'] && 
            count($analysis['rooms']) > 0 && 
            count($analysis['engineers']) > 0) {
            
            $discount = $analysis['engineer_total'] * ($settings['engineer_addon_discount_percent'] / 100);
            
            if ($discount > 0) {
                $cart->add_fee(
                    sprintf('Room + Engineer Bundle (%d%% off engineer)', 
                        $settings['engineer_addon_discount_percent']
                    ),
                    -$discount
                );
                
                SB_Debug::log("Applied engineer bundle discount: KES {$discount}");
            }
        }
    }
    
    /**
     * Show discount info banner on cart page
     */
    public function show_discount_info() {
        $settings = $this->get_settings();
        $cart_items = WC()->cart->get_cart();
        $analysis = $this->analyze_cart($cart_items);
        
        $tips = array();
        
        // Suggest multi-room if only 1 room
        if (count($analysis['rooms']) === 1 && $settings['multi_room_enabled']) {
            $tips[] = sprintf(
                'Add another room to get <strong>%d%% off</strong> all rooms!',
                $settings['multi_room_percent']
            );
        }
        
        // Suggest full day if close
        if ($settings['full_day_enabled']) {
            foreach ($analysis['dates'] as $date => $minutes) {
                $hours = $minutes / 60;
                $needed = $settings['full_day_min_hours'] - $hours;
                if ($needed > 0 && $needed <= 3) {
                    $tips[] = sprintf(
                        'Book %d more hour(s) on %s to unlock <strong>%d%% Full Day Discount</strong>!',
                        ceil($needed),
                        date_i18n('M j', strtotime($date)),
                        $settings['full_day_percent']
                    );
                }
            }
        }
        
        // Suggest adding engineer
        if (count($analysis['rooms']) > 0 && count($analysis['engineers']) === 0 && $settings['engineer_addon_discount_enabled']) {
            $tips[] = sprintf(
                'Add a Sound Engineer and get <strong>%d%% off</strong> the engineer fee!',
                $settings['engineer_addon_discount_percent']
            );
        }
        
        if (!empty($tips)) {
            echo '<div class="sb-discount-tips" style="background:#e8f5e9;border:1px solid #4CAF50;padding:15px;margin-bottom:20px;border-radius:5px;">';
            echo '<h4 style="margin:0 0 8px 0;color:#2e7d32;">💰 Save More 2014 Bundle Discounts!</h4>';
            echo '<ul style="margin:0;padding-left:20px;">';
            foreach ($tips as $tip) {
                echo '<li style="margin-bottom:5px;">' . $tip . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }
    
    /**
     * Admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'snowball-booking',
            __('Discount Rules', 'snowball-booking'),
            __('Packages', 'snowball-booking'),
            'manage_options',
            'sb-packages',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Render packages admin page
     */
    public function render_admin_page() {
        // Handle save
        if (isset($_POST['sb_save_discounts']) && check_admin_referer('sb_discounts_nonce')) {
            $settings = array(
                'multi_room_enabled'    => isset($_POST['multi_room_enabled']),
                'multi_room_percent'    => intval($_POST['multi_room_percent']),
                'multi_room_min_items'  => intval($_POST['multi_room_min_items']),
                'full_day_enabled'      => isset($_POST['full_day_enabled']),
                'full_day_percent'      => intval($_POST['full_day_percent']),
                'full_day_min_hours'    => intval($_POST['full_day_min_hours']),
                'engineer_addon_discount_enabled' => isset($_POST['engineer_addon_discount_enabled']),
                'engineer_addon_discount_percent' => intval($_POST['engineer_addon_discount_percent']),
            );
            
            update_option('sb_discount_settings', $settings);
            SB_Debug::log('Discount settings saved: ' . print_r($settings, true));
            echo '<div class="notice notice-success"><p>Discount settings saved!</p></div>';
        }
        
        $s = $this->get_settings();
        
        ?>
        <div class="wrap">
            <h1>Automatic Discount Rules</h1>
            <p>Discounts are calculated automatically based on what the customer adds to their cart. No need to create separate package products.</p>
            
            <form method="post">
                <?php wp_nonce_field('sb_discounts_nonce'); ?>
                
                <h2>1. Multiple Rooms Discount</h2>
                <p class="description">When a customer books multiple rooms, they get a percentage off all rooms.</p>
                <table class="form-table">
                    <tr>
                        <th>Enabled</th>
                        <td><input type="checkbox" name="multi_room_enabled" <?php checked($s['multi_room_enabled']); ?> /></td>
                    </tr>
                    <tr>
                        <th>Discount %</th>
                        <td><input type="number" name="multi_room_percent" value="<?php echo esc_attr($s['multi_room_percent']); ?>" min="1" max="50" /> %</td>
                    </tr>
                    <tr>
                        <th>Minimum rooms</th>
                        <td><input type="number" name="multi_room_min_items" value="<?php echo esc_attr($s['multi_room_min_items']); ?>" min="2" max="10" /></td>
                    </tr>
                </table>
                
                <hr />
                
                <h2>2. Full Day Discount</h2>
                <p class="description">When a customer books 8+ hours on the same day, they get a percentage off.</p>
                <table class="form-table">
                    <tr>
                        <th>Enabled</th>
                        <td><input type="checkbox" name="full_day_enabled" <?php checked($s['full_day_enabled']); ?> /></td>
                    </tr>
                    <tr>
                        <th>Discount %</th>
                        <td><input type="number" name="full_day_percent" value="<?php echo esc_attr($s['full_day_percent']); ?>" min="1" max="50" /> %</td>
                    </tr>
                    <tr>
                        <th>Minimum hours</th>
                        <td><input type="number" name="full_day_min_hours" value="<?php echo esc_attr($s['full_day_min_hours']); ?>" min="4" max="14" /> hours</td>
                    </tr>
                </table>
                
                <hr />
                
                <h2>3. Room + Engineer Bundle</h2>
                <p class="description">When a customer books a room AND adds a sound engineer, they get a discount on the engineer fee.</p>
                <table class="form-table">
                    <tr>
                        <th>Enabled</th>
                        <td><input type="checkbox" name="engineer_addon_discount_enabled" <?php checked($s['engineer_addon_discount_enabled']); ?> /></td>
                    </tr>
                    <tr>
                        <th>Discount %</th>
                        <td><input type="number" name="engineer_addon_discount_percent" value="<?php echo esc_attr($s['engineer_addon_discount_percent']); ?>" min="1" max="50" /> % off engineer fee</td>
                    </tr>
                </table>
                
                <hr />
                
                <h2>How It Works</h2>
                <p>Discounts stack! A customer who books 2 rooms + engineer for a full day gets ALL applicable discounts:</p>
                <ol>
                    <li>Multi-room discount on the room fees</li>
                    <li>Full day discount on the room fees</li>
                    <li>Bundle discount on the engineer fee</li>
                </ol>
                <p>Customers see savings tips on the cart page suggesting ways to unlock discounts.</p>
                
                <?php submit_button('Save Discount Rules', 'primary', 'sb_save_discounts'); ?>
            </form>
        </div>
        <?php
    }
}
