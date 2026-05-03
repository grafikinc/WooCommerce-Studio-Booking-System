<?php
if (!defined('ABSPATH')) exit;
class SB_Booking {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    public function create_booking($data) {
        global $wpdb;
        $defaults = array(
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
        $data = wp_parse_args($data, $defaults);
        return $wpdb->insert($wpdb->prefix . 'sb_bookings', $data);
    }
    public function update_booking($booking_id, $data) {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        return $wpdb->update(
            $wpdb->prefix . 'sb_bookings',
            $data,
            array('id' => $booking_id)
        );
    }
    public function get_booking($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sb_bookings WHERE id = %d",
            $booking_id
        ));
    }
}
