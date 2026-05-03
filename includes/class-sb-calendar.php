<?php
/**
 * Calendar functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class SB_Calendar {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Calendar will be rendered via shortcode
    }
    
    /**
     * Get available dates for a product
     */
    public function get_available_dates($product_id, $months_ahead = 3) {
        $available_dates = array();
        $start_date = current_time('Y-m-d');
        $end_date = date('Y-m-d', strtotime('+' . $months_ahead . ' months'));
        
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            
            // Skip dates in the past
            if ($current < strtotime('today')) {
                $current = strtotime('+1 day', $current);
                continue;
            }
            
            // Check if date has available slots
            if ($this->has_available_slots($product_id, $date)) {
                $available_dates[] = $date;
            }
            
            $current = strtotime('+1 day', $current);
        }
        
        return $available_dates;
    }
    
    /**
     * Check if a date has available slots
     */
    private function has_available_slots($product_id, $date) {
        $time_slots = SB_Time_Slots::get_instance()->get_time_slots();
        
        foreach ($time_slots as $start => $end) {
            if ($this->is_slot_available($product_id, $date, $start)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if specific slot is available
     */
    private function is_slot_available($product_id, $date, $time) {
        global $wpdb;
        
        $conflicts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings 
            WHERE product_id = %d 
            AND booking_date = %s 
            AND start_time = %s
            AND status IN ('confirmed', 'pending')",
            $product_id,
            $date,
            $time
        ));
        
        return $conflicts == 0;
    }
    
    /**
     * Get bookings for calendar display
     */
    public function get_calendar_events($product_id = null, $start_date = null, $end_date = null) {
        global $wpdb;
        
        $where = "WHERE status IN ('confirmed', 'pending')";
        $params = array();
        
        if ($product_id) {
            $where .= " AND product_id = %d";
            $params[] = $product_id;
        }
        
        if ($start_date) {
            $where .= " AND booking_date >= %s";
            $params[] = $start_date;
        }
        
        if ($end_date) {
            $where .= " AND booking_date <= %s";
            $params[] = $end_date;
        }
        
        $query = "SELECT * FROM {$wpdb->prefix}sb_bookings $where ORDER BY booking_date, start_time";
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }
        
        return $wpdb->get_results($query);
    }
}
