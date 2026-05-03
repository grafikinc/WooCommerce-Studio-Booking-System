<?php
/**
 * WooCommerce Integration Class
 * Handles product booking metadata, cart integration, and checkout
 */

if (!defined('ABSPATH')) {
    exit;
}

class SB_WooCommerce {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Add booking data to cart
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_booking_cart_item_data'), 10, 2);
        
        // Display booking info in cart
        add_filter('woocommerce_get_item_data', array($this, 'display_booking_cart_item_data'), 10, 2);
        
        // Save booking to order meta
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_booking_order_item_meta'), 10, 4);
        
        // Create booking after order payment
        add_action('woocommerce_payment_complete', array($this, 'create_booking_from_order'));
        add_action('woocommerce_order_status_processing', array($this, 'create_booking_from_order'));
        add_action('woocommerce_order_status_completed', array($this, 'create_booking_from_order'));
        
        // Cancel booking when order is cancelled
        add_action('woocommerce_order_status_cancelled', array($this, 'cancel_booking_from_order'));
        add_action('woocommerce_order_status_refunded', array($this, 'cancel_booking_from_order'));
        
        // Validate cart before checkout
        add_action('woocommerce_check_cart_items', array($this, 'validate_cart_bookings'));
        
        // Add deposit handling
        add_filter('woocommerce_product_get_price', array($this, 'apply_deposit_pricing'), 10, 2);
        
        // Prevent duplicate bookings
        add_filter('woocommerce_add_to_cart_validation', array($this, 'prevent_duplicate_time_slots'), 10, 3);
    }
    
    /**
     * Add booking data when product is added to cart
     */
    public function add_booking_cart_item_data($cart_item_data, $product_id) {
        if (isset($_POST['booking_date']) && isset($_POST['booking_time'])) {
            $cart_item_data['booking_date'] = sanitize_text_field($_POST['booking_date']);
            $cart_item_data['booking_time'] = sanitize_text_field($_POST['booking_time']);
            $cart_item_data['booking_duration'] = isset($_POST['booking_duration']) ? intval($_POST['booking_duration']) : 60;
            $cart_item_data['booking_end_time'] = $this->calculate_end_time($_POST['booking_time'], $cart_item_data['booking_duration']);
            
            // Make each booking unique in cart
            $cart_item_data['unique_key'] = md5(microtime() . rand());
        }
        
        return $cart_item_data;
    }
    
    /**
     * Display booking info in cart and checkout
     */
    public function display_booking_cart_item_data($item_data, $cart_item) {
        if (isset($cart_item['booking_date'])) {
            $item_data[] = array(
                'name'  => __('Booking Date', 'snowball-booking'),
                'value' => date_i18n(get_option('date_format'), strtotime($cart_item['booking_date']))
            );
        }
        
        if (isset($cart_item['booking_time'])) {
            $time_display = $cart_item['booking_time'];
            if (isset($cart_item['booking_end_time'])) {
                $time_display .= ' - ' . $cart_item['booking_end_time'];
            }
            
            $item_data[] = array(
                'name'  => __('Time Slot', 'snowball-booking'),
                'value' => $time_display
            );
        }
        
        if (isset($cart_item['booking_duration'])) {
            $hours = floor($cart_item['booking_duration'] / 60);
            $minutes = $cart_item['booking_duration'] % 60;
            
            $duration_text = '';
            if ($hours > 0) {
                $duration_text .= $hours . ' ' . ($hours > 1 ? __('hours', 'snowball-booking') : __('hour', 'snowball-booking'));
            }
            if ($minutes > 0) {
                if ($hours > 0) $duration_text .= ' ';
                $duration_text .= $minutes . ' ' . __('minutes', 'snowball-booking');
            }
            
            $item_data[] = array(
                'name'  => __('Duration', 'snowball-booking'),
                'value' => $duration_text
            );
        }
        
        if (isset($cart_item['engineer_preference']) && $cart_item['engineer_preference'] !== 'No preference') {
            $item_data[] = array(
                'name'  => __('Preferred Engineer', 'snowball-booking'),
                'value' => $cart_item['engineer_preference']
            );
        }
        
        return $item_data;
    }
    
    /**
     * Save booking data to order meta
     */
    public function add_booking_order_item_meta($item, $cart_item_key, $values, $order) {
        if (isset($values['booking_date'])) {
            $item->add_meta_data('_booking_date', $values['booking_date']);
        }
        if (isset($values['booking_time'])) {
            $item->add_meta_data('_booking_time', $values['booking_time']);
        }
        if (isset($values['booking_end_time'])) {
            $item->add_meta_data('_booking_end_time', $values['booking_end_time']);
        }
        if (isset($values['booking_duration'])) {
            $item->add_meta_data('_booking_duration', $values['booking_duration']);
        }
        if (isset($values['engineer_preference'])) {
            $item->add_meta_data('_engineer_preference', $values['engineer_preference']);
        }
    }
    
    /**
     * Create booking entries in database after successful payment
     */
    public function create_booking_from_order($order_id) {
        global $wpdb;
        
        // Check if bookings already created
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings WHERE order_id = %d",
            $order_id
        ));
        
        if ($existing > 0) {
            return; // Already created
        }
        
        $order = wc_get_order($order_id);
        
        foreach ($order->get_items() as $item_id => $item) {
            $booking_date = $item->get_meta('_booking_date');
            $booking_time = $item->get_meta('_booking_time');
            $booking_end_time = $item->get_meta('_booking_end_time');
            $booking_duration = $item->get_meta('_booking_duration');
            
            if ($booking_date && $booking_time) {
                $wpdb->insert(
                    $wpdb->prefix . 'sb_bookings',
                    array(
                        'order_id'       => $order_id,
                        'product_id'     => $item->get_product_id(),
                        'booking_date'   => $booking_date,
                        'start_time'     => $booking_time,
                        'end_time'       => $booking_end_time,
                        'duration'       => $booking_duration,
                        'status'         => 'confirmed',
                        'customer_id'    => $order->get_customer_id(),
                        'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'customer_email' => $order->get_billing_email(),
                        'customer_phone' => $order->get_billing_phone(),
                        'created_at'     => current_time('mysql'),
                        'updated_at'     => current_time('mysql'),
                    ),
                    array('%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
                );
                
                // Send confirmation email
                $this->send_booking_confirmation_email($order, $wpdb->insert_id);
            }
        }
    }
    
    /**
     * Cancel bookings when order is cancelled
     */
    public function cancel_booking_from_order($order_id) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'sb_bookings',
            array(
                'status' => 'cancelled',
                'updated_at' => current_time('mysql')
            ),
            array('order_id' => $order_id),
            array('%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Validate cart bookings before checkout
     */
    public function validate_cart_bookings() {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['booking_date']) && isset($cart_item['booking_time'])) {
                // Check if time slot is still available
                if (!$this->is_time_slot_available(
                    $cart_item['product_id'],
                    $cart_item['booking_date'],
                    $cart_item['booking_time'],
                    $cart_item['booking_duration']
                )) {
                    wc_add_notice(
                        sprintf(
                            __('Sorry, the time slot for %s on %s at %s is no longer available. Please select a different time.', 'snowball-booking'),
                            get_the_title($cart_item['product_id']),
                            $cart_item['booking_date'],
                            $cart_item['booking_time']
                        ),
                        'error'
                    );
                    
                    WC()->cart->remove_cart_item($cart_item_key);
                }
            }
        }
    }
    
    /**
     * Check if time slot is available
     */
    private function is_time_slot_available($product_id, $date, $time, $duration) {
        global $wpdb;
        
        $end_time = $this->calculate_end_time($time, $duration);
        
        $conflicts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings 
            WHERE product_id = %d 
            AND booking_date = %s 
            AND status IN ('confirmed', 'pending')
            AND (
                (start_time <= %s AND end_time > %s) OR
                (start_time < %s AND end_time >= %s) OR
                (start_time >= %s AND end_time <= %s)
            )",
            $product_id,
            $date,
            $time, $time,
            $end_time, $end_time,
            $time, $end_time
        ));
        
        return $conflicts == 0;
    }
    
    /**
     * Calculate end time based on start time and duration
     */
    private function calculate_end_time($start_time, $duration_minutes) {
        $start = strtotime($start_time);
        $end = $start + ($duration_minutes * 60);
        return date('H:i', $end);
    }
    
    /**
     * Apply deposit pricing (70% deposit)
     */
    public function apply_deposit_pricing($price, $product) {
        // Check if product requires deposit
        $requires_deposit = get_post_meta($product->get_id(), '_requires_deposit', true);
        
        if ($requires_deposit === 'yes' && isset($_POST['deposit_payment']) && $_POST['deposit_payment'] === 'yes') {
            return $price * (intval(get_option('sb_deposit_percent', 70)) / 100); // Deposit
        }
        
        return $price;
    }
    
    /**
     * Prevent adding duplicate time slots to cart
     */
    public function prevent_duplicate_time_slots($valid, $product_id, $quantity) {
        if (!isset($_POST['booking_date']) || !isset($_POST['booking_time'])) {
            return $valid;
        }
        
        $booking_date = sanitize_text_field($_POST['booking_date']);
        $booking_time = sanitize_text_field($_POST['booking_time']);
        
        // Check cart for duplicates
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] == $product_id &&
                isset($cart_item['booking_date']) &&
                $cart_item['booking_date'] == $booking_date &&
                isset($cart_item['booking_time']) &&
                $cart_item['booking_time'] == $booking_time) {
                
                wc_add_notice(__('This time slot is already in your cart.', 'snowball-booking'), 'error');
                return false;
            }
        }
        
        return $valid;
    }
    
    /**
     * Send booking confirmation email
     */
    private function send_booking_confirmation_email($order, $booking_id) {
        // Email will be implemented separately
        // This hooks into WooCommerce's email system
        do_action('snowball_booking_confirmed', $booking_id, $order);
    }
}