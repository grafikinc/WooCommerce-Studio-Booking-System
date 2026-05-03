<?php
if (!defined('ABSPATH')) exit;

class SB_Admin {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        SB_Debug::log('Admin class loaded');
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Studio Booking',
            'Bookings',
            'manage_options',
            'snowball-booking',
            array($this, 'render_bookings_page'),
            'dashicons-calendar-alt',
            56
        );
        
        add_submenu_page(
            'snowball-booking',
            'All Bookings',
            'All Bookings',
            'manage_options',
            'snowball-booking',
            array($this, 'render_bookings_page')
        );
        
        add_submenu_page(
            'snowball-booking',
            'Settings',
            'Settings',
            'manage_options',
            'sb-settings',
            array($this, 'render_settings_page')
        );
        
        // Debug page
        add_submenu_page(
            'snowball-booking',
            'Debug & Health Check',
            'Debug',
            'manage_options',
            'sb-debug',
            array($this, 'render_debug_page')
        );
    }
    
    /**
     * Bookings list page
     */
    public function render_bookings_page() {
        $list = new SB_Bookings_List();
        $list->prepare_items();
        echo '<div class="wrap">';
        echo '<h1>Studio Bookings</h1>';
        $list->display();
        echo '</div>';
    }
    
    /**
     * Settings page
     */
    public function render_settings_page() {
        if (isset($_POST['sb_save_settings']) && check_admin_referer('sb_settings_nonce')) {
            update_option('sb_booking_lead_time', intval($_POST['sb_booking_lead_time']));
            update_option('sb_max_booking_advance', intval($_POST['sb_max_booking_advance']));
            update_option('sb_deposit_percent', intval($_POST['sb_deposit_percent']));
            update_option('sb_payment_instructions', wp_kses_post($_POST['sb_payment_instructions']));
            update_option('sb_studio_address', sanitize_text_field($_POST['sb_studio_address']));
            SB_Debug::log('Settings saved');
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $lead_time = get_option('sb_booking_lead_time', 24);
        $max_advance = get_option('sb_max_booking_advance', 90);
        $deposit_pct = get_option('sb_deposit_percent', 70);
        $payment_info = get_option('sb_payment_instructions', "M-PESA PayBill: 7165791");
        $studio_address = get_option('sb_studio_address', 'Nairobi, Kenya');
        ?>
        <div class="wrap">
            <h1>Booking Settings</h1>
            
            <form method="post">
                <?php wp_nonce_field('sb_settings_nonce'); ?>
                
                <h2>Scheduling</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="sb_booking_lead_time">Minimum Booking Lead Time</label></th>
                        <td>
                            <input type="number" id="sb_booking_lead_time" name="sb_booking_lead_time" 
                                   value="<?php echo esc_attr($lead_time); ?>" min="1" max="168" />
                            <p class="description">Hours in advance a customer must book (default: 24)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sb_max_booking_advance">Max Booking Advance</label></th>
                        <td>
                            <input type="number" id="sb_max_booking_advance" name="sb_max_booking_advance" 
                                   value="<?php echo esc_attr($max_advance); ?>" min="7" max="365" />
                            <p class="description">Days in advance a customer can book (default: 90)</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Deposit &amp; Payment</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="sb_deposit_percent">Deposit Percentage</label></th>
                        <td>
                            <input type="number" id="sb_deposit_percent" name="sb_deposit_percent" 
                                   value="<?php echo esc_attr($deposit_pct); ?>" min="0" max="100" style="width:80px;" /> %
                            <p class="description">Required deposit when booking (default: 70%). Set to 0 for no deposit, 100 for full payment.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sb_payment_instructions">Payment Instructions</label></th>
                        <td>
                            <textarea id="sb_payment_instructions" name="sb_payment_instructions" rows="4" class="large-text"><?php echo esc_textarea($payment_info); ?></textarea>
                            <p class="description">Shown on Purchase Orders and the booking review step. Use one line per payment method.<br>
                            Examples: <code>M-PESA PayBill: 7165791</code> or <code>PayPal: payments@studio.com</code> or <code>Bank: Account 12345, Standard Chartered</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sb_studio_address">Studio Address</label></th>
                        <td>
                            <input type="text" id="sb_studio_address" name="sb_studio_address" 
                                   value="<?php echo esc_attr($studio_address); ?>" class="large-text" />
                            <p class="description">Shown on Purchase Orders (default: Nairobi, Kenya)</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Settings', 'primary', 'sb_save_settings'); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Debug & Health Check page
     */
    public function render_debug_page() {
        $checks = SB_Debug::health_check();
        $logs = SB_Debug::get_recent_logs(100);
        
        ?>
        <div class="wrap">
            <h1>🔧 Debug & Health Check</h1>
            
            <!-- HEALTH CHECK -->
            <h2>System Health Check</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Check</th>
                        <th>Value</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $key => $check): ?>
                        <?php 
                        $icon = '✅';
                        if ($check['status'] === 'error') $icon = '❌';
                        if ($check['status'] === 'warn') $icon = '⚠️';
                        if ($check['status'] === 'info') $icon = 'ℹ️';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($check['label']); ?></strong></td>
                            <td><code><?php echo esc_html($check['value']); ?></code></td>
                            <td><?php echo $icon; ?></td>
                            <td><?php echo esc_html($check['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- WOOCOMMERCE FEATURES -->
            <h2 style="margin-top:30px;">WooCommerce Feature Compatibility</h2>
            <p>This shows which WooCommerce features are enabled and whether our plugin declared compatibility.</p>
            <?php
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                try {
                    $all_features = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_features(true);
                    $plugin_file = plugin_basename(SB_PLUGIN_FILE);
                    
                    echo '<table class="widefat striped">';
                    echo '<thead><tr><th>Feature</th><th>Enabled</th><th>Our Plugin Compatibility</th></tr></thead>';
                    echo '<tbody>';
                    
                    foreach ($all_features as $feature_id => $feature) {
                        $is_enabled = isset($feature['is_enabled']) ? $feature['is_enabled'] : false;
                        $compatible_plugins = isset($feature['plugins_compatible']) ? $feature['plugins_compatible'] : array();
                        $incompatible_plugins = isset($feature['plugins_incompatible']) ? $feature['plugins_incompatible'] : array();
                        
                        $our_status = '❓ Unknown';
                        if (is_array($compatible_plugins) && in_array($plugin_file, $compatible_plugins)) {
                            $our_status = '✅ Compatible';
                        } elseif (is_array($incompatible_plugins) && in_array($plugin_file, $incompatible_plugins)) {
                            $our_status = '❌ Incompatible';
                        } elseif (!$is_enabled) {
                            $our_status = '➖ Feature not active';
                        }
                        
                        echo '<tr>';
                        echo '<td><strong>' . esc_html($feature_id) . '</strong></td>';
                        echo '<td>' . ($is_enabled ? '✅ Yes' : '❌ No') . '</td>';
                        echo '<td>' . $our_status . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody></table>';
                    
                    // Show ALL incompatible plugins across all features
                    echo '<h3 style="margin-top:20px;">Plugins Flagged as Incompatible (across all features)</h3>';
                    $all_incompatible = array();
                    foreach ($all_features as $feature_id => $feature) {
                        if (!empty($feature['plugins_incompatible'])) {
                            foreach ($feature['plugins_incompatible'] as $plugin) {
                                $all_incompatible[$plugin][] = $feature_id;
                            }
                        }
                    }
                    
                    if (empty($all_incompatible)) {
                        echo '<p style="color:green;">✅ No plugins are flagged as incompatible!</p>';
                    } else {
                        echo '<table class="widefat striped">';
                        echo '<thead><tr><th>Plugin</th><th>Incompatible With Features</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($all_incompatible as $plugin => $features) {
                            $highlight = (strpos($plugin, 'snowball') !== false) ? 'style="background:#fff3cd;"' : '';
                            echo '<tr ' . $highlight . '>';
                            echo '<td><code>' . esc_html($plugin) . '</code></td>';
                            echo '<td>' . esc_html(implode(', ', $features)) . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }
                    
                } catch (\Exception $e) {
                    echo '<p>Could not read WooCommerce features: ' . esc_html($e->getMessage()) . '</p>';
                }
            } else {
                echo '<p>WooCommerce FeaturesUtil not available. WooCommerce may need updating.</p>';
            }
            ?>
            
            <!-- DEBUG LOG -->
            <h2 style="margin-top:30px;">Recent Debug Log</h2>
            <p>Log file location: <code><?php echo esc_html(wp_upload_dir()['basedir'] . '/snowball-logs/'); ?></code></p>
            
            <div style="background:#1e1e1e;color:#d4d4d4;padding:15px;border-radius:5px;max-height:500px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.6;">
                <?php 
                if (!empty($logs)) {
                    foreach ($logs as $line) {
                        $line = esc_html(trim($line));
                        // Color code by level
                        if (strpos($line, '[ERROR]') !== false) {
                            echo '<div style="color:#f44336;">' . $line . '</div>';
                        } elseif (strpos($line, '[WARN]') !== false) {
                            echo '<div style="color:#ff9800;">' . $line . '</div>';
                        } else {
                            echo '<div>' . $line . '</div>';
                        }
                    }
                } else {
                    echo '<div>No log entries yet. Navigate around the plugin to generate logs.</div>';
                }
                ?>
            </div>
            
            <!-- QUICK ACTIONS -->
            <h2 style="margin-top:30px;">Quick Actions</h2>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('sb_debug_action'); ?>
                <input type="hidden" name="sb_debug_action" value="rebuild_table" />
                <button type="submit" class="button">Rebuild Database Table</button>
            </form>
            
            <form method="post" style="display:inline;margin-left:10px;">
                <?php wp_nonce_field('sb_debug_action'); ?>
                <input type="hidden" name="sb_debug_action" value="clear_logs" />
                <button type="submit" class="button">Clear Logs</button>
            </form>
            
            <form method="post" style="display:inline;margin-left:10px;">
                <?php wp_nonce_field('sb_debug_action'); ?>
                <input type="hidden" name="sb_debug_action" value="test_log" />
                <button type="submit" class="button button-primary">Write Test Log Entry</button>
            </form>
            
            <?php
            // Handle quick actions
            if (isset($_POST['sb_debug_action']) && check_admin_referer('sb_debug_action')) {
                switch ($_POST['sb_debug_action']) {
                    case 'rebuild_table':
                        Snowball_Booking_System::get_instance()->activate();
                        echo '<div class="notice notice-success"><p>Database table rebuilt!</p></div>';
                        break;
                    case 'clear_logs':
                        $log_files = SB_Debug::get_log_files();
                        foreach ($log_files as $file) {
                            unlink($file);
                        }
                        echo '<div class="notice notice-success"><p>Logs cleared!</p></div>';
                        break;
                    case 'test_log':
                        SB_Debug::log('Test log entry - everything is working!');
                        SB_Debug::warn('Test warning entry');
                        SB_Debug::error('Test error entry');
                        echo '<div class="notice notice-success"><p>Test entries written! Refresh to see them below.</p></div>';
                        break;
                }
            }
            ?>
        </div>
        <?php
    }
}