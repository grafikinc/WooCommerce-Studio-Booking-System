<?php
if (!defined('ABSPATH')) exit;
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}
class SB_Bookings_List extends WP_List_Table {
    public function __construct() {
        parent::__construct(array(
            'singular' => 'booking',
            'plural' => 'bookings',
            'ajax' => false
        ));
    }
    public function get_columns() {
        return array(
            'cb' => '<input type="checkbox" />',
            'id' => __('ID', 'snowball-booking'),
            'customer' => __('Customer', 'snowball-booking'),
            'product' => __('Product', 'snowball-booking'),
            'date' => __('Date', 'snowball-booking'),
            'time' => __('Time', 'snowball-booking'),
            'status' => __('Status', 'snowball-booking'),
        );
    }
    public function prepare_items() {
        global $wpdb;
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings");
        $this->items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sb_bookings ORDER BY booking_date DESC, start_time DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page' => $per_page,
        ));
        $this->_column_headers = array($this->get_columns(), array(), array());
    }
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id': return $item->id;
            case 'customer': return $item->customer_name;
            case 'product': return get_the_title($item->product_id);
            case 'date': return date_i18n(get_option('date_format'), strtotime($item->booking_date));
            case 'time': return $item->start_time . ' - ' . $item->end_time;
            case 'status': return '<span class="status-' . $item->status . '">' . ucfirst($item->status) . '</span>';
            default: return '';
        }
    }
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="booking[]" value="%s" />', $item->id);
    }
}
