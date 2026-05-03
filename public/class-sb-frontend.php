<?php
if (!defined('ABSPATH')) exit;
class SB_Frontend {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('woocommerce_before_add_to_cart_button', array($this, 'add_booking_fields'));
    }
    public function add_booking_fields() {
        global $product;
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
        $bookable_categories = array('Live Rooms', 'Podcasts', 'Video Editing');
        if (array_intersect($categories, $bookable_categories)) {
            include SB_PLUGIN_DIR . 'templates/booking-fields.php';
        }
    }
}
