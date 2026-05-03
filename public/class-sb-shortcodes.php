<?php
if (!defined('ABSPATH')) exit;
class SB_Shortcodes {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_shortcode('snowball_booking_calendar', array($this, 'render_calendar'));
        add_shortcode('snowball_booking_form', array($this, 'render_booking_form'));
    }
    public function render_calendar($atts) {
        $atts = shortcode_atts(array(
            'product_id' => null,
        ), $atts);
        ob_start();
        include SB_PLUGIN_DIR . 'templates/calendar.php';
        return ob_get_clean();
    }
    public function render_booking_form($atts) {
        $atts = shortcode_atts(array(
            'product_id' => get_the_ID(),
        ), $atts);
        ob_start();
        include SB_PLUGIN_DIR . 'templates/booking-form.php';
        return ob_get_clean();
    }
}
