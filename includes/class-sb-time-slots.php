<?php
if (!defined('ABSPATH')) exit;
class SB_Time_Slots {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    public function get_time_slots() {
        return get_option('sb_time_slots', array());
    }
    public function get_available_slots($product_id, $date) {
        global $wpdb;
        $all_slots = $this->get_time_slots();
        $booked = $wpdb->get_results($wpdb->prepare(
            "SELECT start_time FROM {$wpdb->prefix}sb_bookings 
            WHERE product_id = %d AND booking_date = %s AND status IN ('confirmed', 'pending')",
            $product_id, $date
        ), ARRAY_A);
        $booked_times = array_column($booked, 'start_time');
        $available = array();
        foreach ($all_slots as $start => $end) {
            if (!in_array($start, $booked_times)) {
                $available[$start] = $end;
            }
        }
        return $available;
    }
}
