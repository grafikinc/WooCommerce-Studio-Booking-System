<?php
if (!defined('ABSPATH')) exit;

class SB_Ajax {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        // Get available time slots
        add_action('wp_ajax_sb_get_available_slots', array($this, 'get_available_slots'));
        add_action('wp_ajax_nopriv_sb_get_available_slots', array($this, 'get_available_slots'));
        
        // Add to cart from booking page
        add_action('wp_ajax_sb_add_to_cart', array($this, 'add_to_cart'));
        add_action('wp_ajax_nopriv_sb_add_to_cart', array($this, 'add_to_cart'));
        
        SB_Debug::log('Ajax handlers registered');
    }
    
    /**
     * Return available time slots for a product on a date
     */
    public function get_available_slots() {
        check_ajax_referer('sb_booking_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $date = sanitize_text_field($_POST['date']);
        
        SB_Debug::log("Getting slots for product {$product_id} on {$date}");
        
        if (!$product_id || !$date) {
            wp_send_json_error('Missing product ID or date');
        }
        
        // Check Google Calendar first (if connected and mapped)
        $gcal = SB_Google_Calendar::get_instance();
        if ($gcal->is_connected()) {
            $slots = $gcal->get_available_slots($product_id, $date);
            SB_Debug::log("Google Calendar returned " . count($slots) . " available slots");
        } else {
            // Fall back to database-only check
            $slots = SB_Time_Slots::get_instance()->get_available_slots($product_id, $date);
            SB_Debug::log("Database returned " . count($slots) . " available slots");
        }
        
        wp_send_json_success($slots);
    }
    
    /**
     * Add booking to WooCommerce cart
     */
    public function add_to_cart() {
        check_ajax_referer('sb_booking_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $date = sanitize_text_field($_POST['booking_date']);
        $time = sanitize_text_field($_POST['booking_time']);
        $duration = intval($_POST['booking_duration']);
        $quantity = max(1, intval($_POST['quantity']));
        
        SB_Debug::log("Add to cart: product={$product_id}, date={$date}, time={$time}, dur={$duration}, qty={$quantity}");
        
        if (!$product_id || !$date || !$time) {
            wp_send_json_error('Please select a date and time.');
        }
        
        // Calculate end time
        $end_time = date('H:i', strtotime($time) + ($duration * 60));
        
        // Cart item data for booking
        $cart_item_data = array(
            'booking_date' => $date,
            'booking_time' => $time,
            'booking_end_time' => $end_time,
            'booking_duration' => $duration,
            'engineer_preference' => isset($_POST['engineer_preference']) ? sanitize_text_field($_POST['engineer_preference']) : 'No preference',
            'unique_key' => md5($product_id . $date . $time . microtime()),
        );
        
        // Add main product to cart
        $added = WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_item_data);
        
        if (!$added) {
            SB_Debug::error("Failed to add product {$product_id} to cart");
            wp_send_json_error('Could not add to cart. Product may be unavailable.');
        }
        
        SB_Debug::log("Added product {$product_id} to cart: {$added}");
        
        // Add engineer if selected
        if (!empty($_POST['engineer_id'])) {
            $eng_id = intval($_POST['engineer_id']);
            $eng_hours = isset($_POST['engineer_hours']) ? max(1, intval($_POST['engineer_hours'])) : 1;
            $eng_data = array(
                'booking_date' => $date,
                'unique_key' => md5($eng_id . $date . microtime()),
            );
            $eng_added = WC()->cart->add_to_cart($eng_id, $eng_hours, 0, array(), $eng_data);
            SB_Debug::log("Added engineer {$eng_id} x{$eng_hours} hours to cart: " . ($eng_added ? 'yes' : 'no'));
        }
        
        // Add setup if selected
        if (!empty($_POST['setup_id'])) {
            $setup_id = intval($_POST['setup_id']);
            $setup_data = array(
                'booking_date' => $date,
                'unique_key' => md5($setup_id . $date . microtime()),
            );
            $setup_added = WC()->cart->add_to_cart($setup_id, 1, 0, array(), $setup_data);
            SB_Debug::log("Added setup {$setup_id} to cart: " . ($setup_added ? 'yes' : 'no'));
        }
        
        wp_send_json_success(array(
            'message' => 'Added to cart!',
            'cart_url' => wc_get_cart_url(),
            'cart_count' => WC()->cart->get_cart_contents_count(),
        ));
    }
}
