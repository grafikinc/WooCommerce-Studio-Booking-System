<?php
/**
 * Plugin Name: Studio Booking System
 * Plugin URI: https://grafikinc.com
 * Description: Complete studio booking system with calendar, time slots, WooCommerce integration, and automatic package discounts
 * Version: 1.1.0
 * Author: GrafikInc
 * Author URI: https://grafikinc.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: studio-booking
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SB_VERSION', '1.1.0');
define('SB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SB_PLUGIN_FILE', __FILE__);

/**
 * Declare WooCommerce feature compatibility BEFORE the class loads.
 * This MUST run at the top level so WooCommerce sees it early.
 */
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare HPOS compatibility
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        // Declare Cart/Checkout Blocks compatibility
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
        // Declare Product Editor compatibility
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'product_block_editor',
            __FILE__,
            true
        );
        
        // Log that we declared compatibility
        SB_Debug::log('WooCommerce compatibility declared for HPOS, Blocks, and Product Editor');
    }
});

/**
 * Debug & Logging Class - loads first
 */
class SB_Debug {
    
    private static $log_file = null;
    
    /**
     * Get log file path
     */
    private static function get_log_file() {
        if (self::$log_file === null) {
            $upload_dir = wp_upload_dir();
            $log_dir = $upload_dir['basedir'] . '/snowball-logs';
            
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);
                // Protect log directory
                file_put_contents($log_dir . '/.htaccess', 'deny from all');
                file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
            }
            
            self::$log_file = $log_dir . '/debug-' . date('Y-m-d') . '.log';
        }
        return self::$log_file;
    }
    
    /**
     * Log a message
     */
    public static function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        error_log($entry, 3, self::get_log_file());
        
        // Also log to WordPress debug log if WP_DEBUG is on
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Studio Booking [{$level}]: {$message}");
        }
    }
    
    /**
     * Log an error
     */
    public static function error($message) {
        self::log($message, 'ERROR');
    }
    
    /**
     * Log a warning
     */
    public static function warn($message) {
        self::log($message, 'WARN');
    }
    
    /**
     * Get recent log entries
     */
    public static function get_recent_logs($lines = 50) {
        $log_file = self::get_log_file();
        
        if (!file_exists($log_file)) {
            return array('No log file found for today.');
        }
        
        $file = file($log_file);
        return array_slice($file, -$lines);
    }
    
    /**
     * Get all log files
     */
    public static function get_log_files() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/snowball-logs';
        
        if (!file_exists($log_dir)) {
            return array();
        }
        
        $files = glob($log_dir . '/debug-*.log');
        rsort($files);
        return $files;
    }
    
    /**
     * Run health check
     */
    public static function health_check() {
        $checks = array();
        
        // 1. PHP Version
        $checks['php_version'] = array(
            'label' => 'PHP Version',
            'value' => phpversion(),
            'status' => version_compare(phpversion(), '7.4', '>=') ? 'ok' : 'error',
            'message' => version_compare(phpversion(), '7.4', '>=') ? 'OK' : 'Requires PHP 7.4+',
        );
        
        // 2. WordPress Version
        $checks['wp_version'] = array(
            'label' => 'WordPress Version',
            'value' => get_bloginfo('version'),
            'status' => version_compare(get_bloginfo('version'), '5.8', '>=') ? 'ok' : 'error',
            'message' => 'OK',
        );
        
        // 3. WooCommerce Active
        $checks['woocommerce'] = array(
            'label' => 'WooCommerce',
            'value' => defined('WC_VERSION') ? WC_VERSION : 'Not installed',
            'status' => class_exists('WooCommerce') ? 'ok' : 'error',
            'message' => class_exists('WooCommerce') ? 'Active' : 'WooCommerce is required',
        );
        
        // 4. HPOS Compatibility
        $hpos_enabled = false;
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        $checks['hpos'] = array(
            'label' => 'WooCommerce HPOS',
            'value' => $hpos_enabled ? 'Enabled' : 'Disabled',
            'status' => 'ok',
            'message' => $hpos_enabled ? 'HPOS is active, plugin is compatible' : 'Using legacy order storage',
        );
        
        // 5. FeaturesUtil compatibility check
        $features_declared = false;
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            $features_declared = true;
        }
        $checks['features_util'] = array(
            'label' => 'WooCommerce FeaturesUtil',
            'value' => $features_declared ? 'Available' : 'Not available',
            'status' => 'ok',
            'message' => $features_declared ? 'Compatibility declarations working' : 'Older WooCommerce version',
        );
        
        // 6. Database Table
        global $wpdb;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}sb_bookings'");
        $checks['database'] = array(
            'label' => 'Bookings Table',
            'value' => $wpdb->prefix . 'sb_bookings',
            'status' => $table_exists ? 'ok' : 'error',
            'message' => $table_exists ? 'Table exists' : 'Table missing - deactivate and reactivate plugin',
        );
        
        // 7. Check for other incompatible plugins
        $checks['other_plugins'] = array(
            'label' => 'Other Active Plugins',
            'value' => count(get_option('active_plugins', array())) . ' active',
            'status' => 'info',
            'message' => 'Check if OTHER plugins are causing the WooCommerce warning',
        );
        
        // 8. List all active plugins for debugging
        $active_plugins = get_option('active_plugins', array());
        $plugin_list = array();
        foreach ($active_plugins as $plugin) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false);
            $plugin_list[] = $plugin_data['Name'] . ' v' . $plugin_data['Version'];
        }
        $checks['plugin_list'] = array(
            'label' => 'Active Plugin List',
            'value' => implode(', ', $plugin_list),
            'status' => 'info',
            'message' => 'Full list of active plugins for debugging',
        );
        
        // 9. Check WooCommerce feature compatibility status for THIS plugin
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            $plugin_file = plugin_basename(SB_PLUGIN_FILE);
            
            // Get all features and check our compatibility
            $features_to_check = array('custom_order_tables', 'cart_checkout_blocks', 'product_block_editor');
            foreach ($features_to_check as $feature) {
                $key = 'wc_compat_' . $feature;
                
                // Try to get the feature info
                try {
                    $all_features = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_features(true);
                    if (isset($all_features[$feature])) {
                        $feature_info = $all_features[$feature];
                        $is_enabled = isset($feature_info['is_enabled']) ? $feature_info['is_enabled'] : false;
                        
                        $checks[$key] = array(
                            'label' => 'WC Feature: ' . $feature,
                            'value' => $is_enabled ? 'Enabled' : 'Disabled',
                            'status' => 'info',
                            'message' => 'Feature status in WooCommerce',
                        );
                    }
                } catch (\Exception $e) {
                    $checks[$key] = array(
                        'label' => 'WC Feature: ' . $feature,
                        'value' => 'Could not check',
                        'status' => 'warn',
                        'message' => $e->getMessage(),
                    );
                }
            }
        }
        
        // 10. WooCommerce currency
        $checks['currency'] = array(
            'label' => 'WooCommerce Currency',
            'value' => get_woocommerce_currency(),
            'status' => get_woocommerce_currency() === 'KES' ? 'ok' : 'warn',
            'message' => get_woocommerce_currency() === 'KES' ? 'Correct (KES)' : 'Check currency matches your region',
        );
        
        return $checks;
    }
}

/**
 * Main Plugin Class
 */
class Snowball_Booking_System {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        SB_Debug::log('Plugin initializing...');
        
        $this->includes();
        $this->init_hooks();
        
        SB_Debug::log('Plugin initialized successfully');
    }
    
    private function includes() {
        $files = array(
            'includes/class-sb-calendar.php',
            'includes/class-sb-time-slots.php',
            'includes/class-sb-booking.php',
            'includes/class-sb-woocommerce.php',
            'includes/class-sb-packages.php',
            'includes/class-sb-product-fix.php',
            'includes/class-sb-google-calendar.php',
            'includes/class-sb-purchase-order.php',
            'includes/class-sb-ajax.php',
            'public/class-sb-frontend.php',
            'public/class-sb-shortcodes.php',
        );
        
        if (is_admin()) {
            $files[] = 'admin/class-sb-admin.php';
            $files[] = 'admin/class-sb-bookings-list.php';
        }
        
        foreach ($files as $file) {
            $path = SB_PLUGIN_DIR . $file;
            if (file_exists($path)) {
                require_once $path;
                SB_Debug::log("Loaded: {$file}");
            } else {
                SB_Debug::error("File missing: {$file}");
            }
        }
    }
    
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        register_activation_hook(SB_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(SB_PLUGIN_FILE, array($this, 'deactivate'));
    }
    
    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            SB_Debug::error('WooCommerce not found!');
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Studio Booking System requires WooCommerce.</p></div>';
            });
            return;
        }
        SB_Debug::log('WooCommerce found: v' . WC_VERSION);
    }
    
    public function init() {
        SB_Calendar::get_instance();
        SB_Time_Slots::get_instance();
        SB_Booking::get_instance();
        SB_WooCommerce::get_instance();
        SB_Packages::get_instance();
        SB_Product_Fix::get_instance();
        SB_Google_Calendar::get_instance();
        SB_Purchase_Order::get_instance();
        SB_Ajax::get_instance();
        SB_Frontend::get_instance();
        SB_Shortcodes::get_instance();
        
        if (is_admin()) {
            SB_Admin::get_instance();
        }
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('sb-booking', SB_PLUGIN_URL . 'assets/css/booking.css', array(), SB_VERSION);
        wp_enqueue_script('sb-booking', SB_PLUGIN_URL . 'assets/js/booking.js', array('jquery'), SB_VERSION, true);
        
        wp_localize_script('sb-booking', 'sbData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sb_booking_nonce'),
            'currency' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : 'KES',
        ));
    }
    
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'snowball-booking') === false && strpos($hook, 'sb-') === false) {
            return;
        }
        wp_enqueue_style('sb-admin', SB_PLUGIN_URL . 'assets/css/admin.css', array(), SB_VERSION);
    }
    
    public function activate() {
        SB_Debug::log('Plugin activating...');
        
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sb_bookings (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL DEFAULT 0,
            product_id bigint(20) NOT NULL,
            booking_date date NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            duration int(11) NOT NULL DEFAULT 60,
            status varchar(20) NOT NULL DEFAULT 'pending',
            customer_id bigint(20) DEFAULT NULL,
            customer_name varchar(255) DEFAULT NULL,
            customer_email varchar(255) DEFAULT NULL,
            customer_phone varchar(50) DEFAULT NULL,
            notes text,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY booking_date (booking_date),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        SB_Debug::log('Database table created/checked. Result: ' . print_r($result, true));
        
        // Default time slots
        if (!get_option('sb_time_slots')) {
            $slots = array();
            for ($h = 8; $h < 22; $h++) {
                $start = sprintf('%02d:00', $h);
                $end = sprintf('%02d:00', $h + 1);
                $slots[$start] = $end;
            }
            update_option('sb_time_slots', $slots);
            SB_Debug::log('Default time slots created');
        }
        
        // Default package discount settings
        if (!get_option('sb_discount_settings')) {
            update_option('sb_discount_settings', array(
                'multi_room_enabled'    => true,
                'multi_room_percent'    => 10,
                'multi_room_min_items'  => 2,
                'full_day_enabled'      => true,
                'full_day_percent'      => 15,
                'full_day_min_hours'    => 8,
                'engineer_addon_discount_enabled' => true,
                'engineer_addon_discount_percent' => 5,
            ));
            SB_Debug::log('Default discount settings created');
        }
        
        update_option('sb_booking_lead_time', 24);
        update_option('sb_max_booking_advance', 90);
        
        flush_rewrite_rules();
        SB_Debug::log('Plugin activated successfully');
    }
    
    public function deactivate() {
        SB_Debug::log('Plugin deactivated');
        flush_rewrite_rules();
    }
}

// Boot
snowball_booking_system();

function snowball_booking_system() {
    return Snowball_Booking_System::get_instance();
}
