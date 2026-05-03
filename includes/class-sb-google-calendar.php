<?php
/**
 * Google Calendar Integration
 * Flow: Cart→PENDING(yellow,72hr) | Payment→CONFIRMED(green) | Cancel→Delete | Cron→Cleanup
 */
if (!defined('ABSPATH')) exit;

class SB_Google_Calendar {
    private static $instance = null;
    private $client_id, $client_secret, $redirect_uri, $token;
    
    const TOKEN_OPTION = 'sb_google_token';
    const SETTINGS_OPTION = 'sb_google_settings';
    const MAPPING_OPTION = 'sb_google_calendar_mapping';
    const PENDING_OPTION = 'sb_pending_gcal_events';
    const HOLD_HOURS = 72;
    const API_BASE = 'https://www.googleapis.com/calendar/v3';
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SCOPES = 'https://www.googleapis.com/auth/calendar';
    
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        $s = get_option(self::SETTINGS_OPTION, array());
        $this->client_id = isset($s['client_id']) ? $s['client_id'] : '';
        $this->client_secret = isset($s['client_secret']) ? $s['client_secret'] : '';
        $this->redirect_uri = admin_url('admin.php?page=sb-google-calendar&sb_google_callback=1');
        $this->token = get_option(self::TOKEN_OPTION, null);
        
        add_action('admin_menu', array($this, 'add_admin_menu'), 55);
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        
        // Cart hooks
        add_action('woocommerce_add_to_cart', array($this, 'on_add_to_cart'), 10, 6);
        add_action('woocommerce_cart_item_removed', array($this, 'on_cart_item_removed'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'transfer_pending_to_order'), 10, 4);
        
        // Order hooks
        add_action('woocommerce_checkout_order_processed', array($this, 'on_order_created'), 10, 3);
        add_action('woocommerce_order_status_processing', array($this, 'confirm_booking'));
        add_action('woocommerce_order_status_completed', array($this, 'confirm_booking'));
        add_action('woocommerce_payment_complete', array($this, 'confirm_booking'));
        add_action('woocommerce_order_status_cancelled', array($this, 'cancel_booking'));
        add_action('woocommerce_order_status_refunded', array($this, 'cancel_booking'));
        
        // Cleanup cron
        if (!wp_next_scheduled('sb_cleanup_pending_events')) {
            wp_schedule_event(time(), 'hourly', 'sb_cleanup_pending_events');
        }
        add_action('sb_cleanup_pending_events', array($this, 'cleanup_expired_pending'));
        
        SB_Debug::log('Google Calendar loaded. Connected: ' . ($this->is_connected() ? 'yes' : 'no'));
    }
    
    public function is_connected() { return !empty($this->token) && isset($this->token['refresh_token']); }
    public function is_configured() { return !empty($this->client_id) && !empty($this->client_secret); }
    
    // === OAUTH ===
    
    public function get_auth_url() {
        return self::AUTH_URL . '?' . http_build_query(array(
            'client_id'=>$this->client_id, 'redirect_uri'=>$this->redirect_uri,
            'response_type'=>'code', 'scope'=>self::SCOPES, 'access_type'=>'offline', 'prompt'=>'consent',
            'state'=>wp_create_nonce('sb_google_oauth'),
        ));
    }
    
    public function handle_oauth_callback() {
        if (!isset($_GET['sb_google_callback']) || !isset($_GET['code'])) return;
        if (!isset($_GET['state']) || !wp_verify_nonce($_GET['state'], 'sb_google_oauth')) return;
        
        $r = wp_remote_post(self::TOKEN_URL, array('body'=>array(
            'code'=>sanitize_text_field($_GET['code']), 'client_id'=>$this->client_id,
            'client_secret'=>$this->client_secret, 'redirect_uri'=>$this->redirect_uri, 'grant_type'=>'authorization_code',
        )));
        
        if (is_wp_error($r)) { wp_redirect(admin_url('admin.php?page=sb-google-calendar&error=failed')); exit; }
        $body = json_decode(wp_remote_retrieve_body($r), true);
        
        if (isset($body['access_token'])) {
            $body['created_at'] = time();
            update_option(self::TOKEN_OPTION, $body);
            $this->token = $body;
            wp_redirect(admin_url('admin.php?page=sb-google-calendar&connected=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=sb-google-calendar&error=' . urlencode($body['error_description'] ?? 'Unknown')));
        }
        exit;
    }
    
    public function disconnect() { delete_option(self::TOKEN_OPTION); $this->token = null; }
    
    private function get_access_token() {
        if (!$this->token || empty($this->token['access_token'])) return false;
        $exp = ($this->token['created_at'] ?? 0) + ($this->token['expires_in'] ?? 3600) - 60;
        if (time() > $exp && !$this->refresh_token()) return false;
        return $this->token['access_token'];
    }
    
    private function refresh_token() {
        if (empty($this->token['refresh_token'])) return false;
        $r = wp_remote_post(self::TOKEN_URL, array('body'=>array(
            'refresh_token'=>$this->token['refresh_token'], 'client_id'=>$this->client_id,
            'client_secret'=>$this->client_secret, 'grant_type'=>'refresh_token',
        )));
        if (is_wp_error($r)) return false;
        $body = json_decode(wp_remote_retrieve_body($r), true);
        if (isset($body['access_token'])) {
            $body['refresh_token'] = $this->token['refresh_token'];
            $body['created_at'] = time();
            $this->token = $body;
            update_option(self::TOKEN_OPTION, $this->token);
            return true;
        }
        return false;
    }
    
    // === API ===
    
    private function api_request($endpoint, $method = 'GET', $body = null) {
        $t = $this->get_access_token();
        if (!$t) return false;
        $args = array('method'=>$method, 'headers'=>array('Authorization'=>'Bearer '.$t, 'Content-Type'=>'application/json'), 'timeout'=>15);
        if ($body && in_array($method, array('POST','PUT','PATCH'))) $args['body'] = json_encode($body);
        $r = wp_remote_request(self::API_BASE . $endpoint, $args);
        if (is_wp_error($r)) { SB_Debug::error("API: ".$r->get_error_message()); return false; }
        $code = wp_remote_retrieve_response_code($r);
        $data = json_decode(wp_remote_retrieve_body($r), true);
        if ($code >= 400) { SB_Debug::error("API {$code}: ".($data['error']['message']??'')); return false; }
        return $data;
    }
    
    public function list_calendars() {
        $d = $this->api_request('/users/me/calendarList');
        if (!$d || !isset($d['items'])) return array();
        $out = array();
        foreach ($d['items'] as $c) $out[] = array('id'=>$c['id'], 'name'=>$c['summary'], 'color'=>$c['backgroundColor']??'#000');
        return $out;
    }
    
    public function get_events($cal_id, $date) {
        $ep = '/calendars/'.urlencode($cal_id).'/events?timeMin='.urlencode($date.'T00:00:00+03:00')
            .'&timeMax='.urlencode($date.'T23:59:59+03:00').'&singleEvents=true&orderBy=startTime';
        $d = $this->api_request($ep);
        return ($d && isset($d['items'])) ? $d['items'] : array();
    }
    
    public function is_slot_available($cal_id, $date, $start_time, $dur_min) {
        if (empty($cal_id)) return true;
        $events = $this->get_events($cal_id, $date);
        $tz = wp_timezone();
        $dt = new DateTime($date.' '.$start_time, $tz);
        $ss = $dt->getTimestamp();
        $se = $ss + ($dur_min * 60);
        foreach ($events as $ev) {
            if (!isset($ev['start']['dateTime']) && isset($ev['start']['date']) && $ev['start']['date']===$date) return false;
            if (!isset($ev['start']['dateTime'])) continue;
            if ($ss < strtotime($ev['end']['dateTime']) && $se > strtotime($ev['start']['dateTime'])) return false;
        }
        return true;
    }
    
    public function get_available_slots($pid, $date) {
        $map = get_option(self::MAPPING_OPTION, array());
        $cid = isset($map[$pid]) ? $map[$pid] : '';
        $slots = SB_Time_Slots::get_instance()->get_time_slots();
        if (empty($cid)) return $slots;
        $avail = array();
        foreach ($slots as $s => $e) { if ($this->is_slot_available($cid, $date, $s, 60)) $avail[$s] = $e; }
        return $avail;
    }
    
    // === EVENTS ===
    
    public function create_event($cal_id, $data) {
        if (empty($cal_id)) return false;
        $pending = (($data['status'] ?? 'confirmed') === 'pending');
        $name = $data['customer_name'] ?? 'Customer';
        $prod = $data['product_name'] ?? 'Booking';
        $eng = $data['engineer_pref'] ?? 'No preference';
        $notes = $data['notes'] ?? '';
        
        $title = $pending ? "PENDING - {$prod}" : "{$name} - {$prod}";
        $desc = $pending
            ? "PAYMENT PENDING - ".self::HOLD_HOURS."hr hold\nService: {$prod}\nPreferred Engineer: {$eng}\n{$notes}"
            : "Customer: {$name}\nPhone: ".($data['phone']??'')."\nEmail: ".($data['email']??'')."\nService: {$prod}\nPreferred Engineer: {$eng}\n{$notes}";
        
        $ev = array(
            'summary'=>$title, 'description'=>$desc,
            'start'=>array('dateTime'=>$data['date'].'T'.$data['start_time'].':00+03:00','timeZone'=>'Africa/Nairobi'),
            'end'=>array('dateTime'=>$data['date'].'T'.$data['end_time'].':00+03:00','timeZone'=>'Africa/Nairobi'),
            'colorId'=>$pending?'5':'10',
            'reminders'=>array('useDefault'=>false,'overrides'=>array(array('method'=>'popup','minutes'=>60))),
        );
        
        $res = $this->api_request('/calendars/'.urlencode($cal_id).'/events', 'POST', $ev);
        if ($res && isset($res['id'])) {
            SB_Debug::log("Created ".($pending?'pending':'confirmed')." event: {$res['id']}");
            return $res['id'];
        }
        return false;
    }
    
    public function confirm_event($cal_id, $event_id, $name, $phone, $email, $prod, $eng, $order_num, $notes='') {
        $desc = "Customer: {$name}\nPhone: {$phone}\nEmail: {$email}\nService: {$prod}\nPreferred Engineer: {$eng}\n"
            .($notes?"Notes: {$notes}\n":"")."\nOrder #{$order_num} - CONFIRMED";
        $this->api_request('/calendars/'.urlencode($cal_id).'/events/'.urlencode($event_id), 'PATCH', array(
            'summary'=>"{$name} - {$prod}", 'description'=>$desc, 'colorId'=>'10',
        ));
    }
    
    public function delete_event($cal_id, $event_id) {
        if (empty($cal_id)||empty($event_id)) return;
        $this->api_request('/calendars/'.urlencode($cal_id).'/events/'.urlencode($event_id), 'DELETE');
    }
    
    // === CART HOOKS ===
    
    public function on_add_to_cart($key, $pid, $qty, $vid, $var, $data) {
        if (!$this->is_connected()) return;
        if (empty($data['booking_date'])||empty($data['booking_time'])) return;
        $map = get_option(self::MAPPING_OPTION, array());
        $cid = isset($map[$pid]) ? $map[$pid] : '';
        if (empty($cid)) return;
        $p = wc_get_product($pid);
        if (!$p) return;
        $end = $data['booking_end_time'] ?? date('H:i', strtotime($data['booking_time'])+3600);
        
        $eid = $this->create_event($cid, array(
            'date'=>$data['booking_date'], 'start_time'=>$data['booking_time'], 'end_time'=>$end,
            'customer_name'=>'PENDING', 'product_name'=>$p->get_name(),
            'engineer_pref'=>$data['engineer_preference']??'', 'notes'=>'Pending payment - '.self::HOLD_HOURS.'hr hold',
            'status'=>'pending',
        ));
        if ($eid) {
            $pend = get_option(self::PENDING_OPTION, array());
            $pend[$key] = array('event_id'=>$eid, 'calendar_id'=>$cid, 'product_id'=>$pid, 'created_at'=>time());
            update_option(self::PENDING_OPTION, $pend);
        }
    }
    
    public function on_cart_item_removed($key, $cart) {
        if (!$this->is_connected()) return;
        $pend = get_option(self::PENDING_OPTION, array());
        if (isset($pend[$key])) {
            $this->delete_event($pend[$key]['calendar_id'], $pend[$key]['event_id']);
            unset($pend[$key]);
            update_option(self::PENDING_OPTION, $pend);
        }
    }
    
    public function transfer_pending_to_order($item, $key, $values, $order) {
        $pend = get_option(self::PENDING_OPTION, array());
        if (isset($pend[$key])) {
            $item->add_meta_data('_gcal_event_id', $pend[$key]['event_id']);
            $item->add_meta_data('_gcal_calendar_id', $pend[$key]['calendar_id']);
            unset($pend[$key]);
            update_option(self::PENDING_OPTION, $pend);
        }
    }
    
    // === ORDER HOOKS ===
    
    public function on_order_created($oid, $posted, $order) {
        if (!$this->is_connected()) return;
        $map = get_option(self::MAPPING_OPTION, array());
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_gcal_event_id')) continue;
            $pid = $item->get_product_id();
            $cid = $map[$pid] ?? '';
            if (empty($cid)) continue;
            $date = $item->get_meta('_booking_date');
            $time = $item->get_meta('_booking_time');
            if (!$date||!$time) continue;
            $end = $item->get_meta('_booking_end_time') ?: date('H:i', strtotime($time)+3600);
            $eid = $this->create_event($cid, array(
                'date'=>$date, 'start_time'=>$time, 'end_time'=>$end,
                'customer_name'=>$order->get_billing_first_name().' '.$order->get_billing_last_name(),
                'product_name'=>$item->get_name(), 'phone'=>$order->get_billing_phone(),
                'email'=>$order->get_billing_email(), 'engineer_pref'=>$item->get_meta('_engineer_preference'),
                'notes'=>'Order #'.$order->get_order_number().' - awaiting payment', 'status'=>'pending',
            ));
            if ($eid) {
                $item->add_meta_data('_gcal_event_id', $eid);
                $item->add_meta_data('_gcal_calendar_id', $cid);
                $item->save();
            }
        }
    }
    
    public function confirm_booking($oid) {
        if (!$this->is_connected()) return;
        $order = wc_get_order($oid);
        if (!$order || get_post_meta($oid, '_sb_gcal_confirmed', true)) return;
        $name = $order->get_billing_first_name().' '.$order->get_billing_last_name();
        foreach ($order->get_items() as $item) {
            $eid = $item->get_meta('_gcal_event_id');
            $cid = $item->get_meta('_gcal_calendar_id');
            if (!$eid||!$cid) continue;
            $this->confirm_event($cid, $eid, $name, $order->get_billing_phone(), $order->get_billing_email(),
                $item->get_name(), $item->get_meta('_engineer_preference')?:'No preference',
                $order->get_order_number(), $order->get_customer_note());
        }
        update_post_meta($oid, '_sb_gcal_confirmed', '1');
    }
    
    public function cancel_booking($oid) {
        if (!$this->is_connected()) return;
        $order = wc_get_order($oid);
        if (!$order) return;
        foreach ($order->get_items() as $item) {
            $eid = $item->get_meta('_gcal_event_id');
            $cid = $item->get_meta('_gcal_calendar_id');
            if ($eid && $cid) $this->delete_event($cid, $eid);
        }
    }
    
    public function cleanup_expired_pending() {
        if (!$this->is_connected()) return;
        $pend = get_option(self::PENDING_OPTION, array());
        $cut = time() - (self::HOLD_HOURS * 3600);
        $n = 0;
        foreach ($pend as $k => $p) {
            if ($p['created_at'] < $cut) {
                $this->delete_event($p['calendar_id'], $p['event_id']);
                unset($pend[$k]);
                $n++;
            }
        }
        if ($n) { update_option(self::PENDING_OPTION, $pend); SB_Debug::log("Cleaned {$n} expired pending events"); }
    }
    
    // === ADMIN PAGE ===
    
    public function add_admin_menu() {
        add_submenu_page('snowball-booking','Google Calendar','Google Calendar','manage_options','sb-google-calendar',array($this,'render_admin_page'));
    }
    
    public function render_admin_page() {
        if (isset($_POST['sb_save_google_settings']) && check_admin_referer('sb_google_settings')) {
            update_option(self::SETTINGS_OPTION, array(
                'client_id'=>sanitize_text_field($_POST['client_id']),
                'client_secret'=>sanitize_text_field($_POST['client_secret']),
            ));
            $s = get_option(self::SETTINGS_OPTION);
            $this->client_id = $s['client_id'];
            $this->client_secret = $s['client_secret'];
            echo '<div class="notice notice-success"><p>Saved!</p></div>';
        }
        if (isset($_POST['sb_disconnect']) && check_admin_referer('sb_google_disc')) {
            $this->disconnect();
            echo '<div class="notice notice-info"><p>Disconnected.</p></div>';
        }
        if (isset($_POST['sb_save_mapping']) && check_admin_referer('sb_google_mapping')) {
            $m = array();
            if (isset($_POST['calendar_map'])) foreach ($_POST['calendar_map'] as $pid=>$cid) $m[intval($pid)] = sanitize_text_field($cid);
            update_option(self::MAPPING_OPTION, $m);
            echo '<div class="notice notice-success"><p>Mapping saved!</p></div>';
        }
        $mapping = get_option(self::MAPPING_OPTION, array());
        ?>
        <div class="wrap">
            <h1>Google Calendar Integration</h1>
            
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:5px;margin-bottom:20px;">
                <h2>Step 1: API Credentials</h2>
                <?php if (!$this->is_configured()): ?>
                <div style="background:#e3f2fd;border:1px solid #90caf9;padding:15px;border-radius:5px;margin-bottom:15px;">
                    <h3 style="margin-top:0;">Setup Guide</h3>
                    <ol>
                        <li><a href="https://console.cloud.google.com/projectcreate" target="_blank">Google Cloud Console</a> - Create project</li>
                        <li>Enable <strong>Google Calendar API</strong></li>
                        <li>Create <strong>OAuth Client ID</strong> (Web application)</li>
                        <li>Redirect URI: <code><?php echo esc_html($this->redirect_uri); ?></code></li>
                    </ol>
                </div>
                <?php endif; ?>
                <form method="post">
                    <?php wp_nonce_field('sb_google_settings'); ?>
                    <table class="form-table">
                        <tr><th>Client ID</th><td><input type="text" name="client_id" value="<?php echo esc_attr($this->client_id); ?>" class="large-text"/></td></tr>
                        <tr><th>Client Secret</th><td><input type="password" name="client_secret" value="<?php echo esc_attr($this->client_secret); ?>" class="regular-text"/></td></tr>
                    </table>
                    <?php submit_button('Save', 'secondary', 'sb_save_google_settings'); ?>
                </form>
            </div>
            
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:5px;margin-bottom:20px;">
                <h2>Step 2: Connect</h2>
                <?php if ($this->is_connected()): ?>
                    <p style="color:green;font-size:16px;font-weight:bold;">Connected to Google Calendar</p>
                    <form method="post" style="display:inline;"><?php wp_nonce_field('sb_google_disc'); ?>
                        <button type="submit" name="sb_disconnect" class="button" onclick="return confirm('Disconnect?');">Disconnect</button>
                    </form>
                <?php elseif ($this->is_configured()): ?>
                    <a href="<?php echo esc_url($this->get_auth_url()); ?>" class="button button-primary button-hero" style="background:#4285f4;border-color:#4285f4;">Connect to Google Calendar</a>
                <?php else: ?>
                    <p style="color:#999;">Enter credentials first.</p>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?><div class="notice notice-error" style="margin-top:10px;"><p><?php echo esc_html(urldecode($_GET['error'])); ?></p></div><?php endif; ?>
                <?php if (isset($_GET['connected'])): ?><div class="notice notice-success" style="margin-top:10px;"><p>Connected!</p></div><?php endif; ?>
            </div>
            
            <?php if ($this->is_connected()):
                $cals = $this->list_calendars();
                $rooms = array();
                foreach (array('live-rooms','podcasts','video-editing') as $cat) {
                    $ps = wc_get_products(array('category'=>array($cat),'status'=>'publish','limit'=>-1));
                    foreach ($ps as $p) $rooms[] = $p;
                }
            ?>
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:5px;margin-bottom:20px;">
                <h2>Step 3: Map Rooms to Calendars</h2>
                <p>Each room gets its own Google Calendar. Bookings create events, and staff-blocked times show as unavailable on the website.</p>
                <?php if (empty($cals)): ?><p style="color:red;">Could not load calendars.</p>
                <?php else: ?>
                <form method="post"><?php wp_nonce_field('sb_google_mapping'); ?>
                    <table class="widefat striped">
                        <thead><tr><th>Room</th><th>Google Calendar</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($rooms as $r): $cur=$mapping[$r->get_id()]??''; ?>
                        <tr>
                            <td><strong><?php echo esc_html($r->get_name()); ?></strong></td>
                            <td><select name="calendar_map[<?php echo $r->get_id(); ?>]" style="min-width:300px;">
                                <option value="">-- Not mapped --</option>
                                <?php foreach ($cals as $c): ?>
                                <option value="<?php echo esc_attr($c['id']); ?>" <?php selected($cur,$c['id']); ?>><?php echo esc_html($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select></td>
                            <td><?php echo $cur?'<span style="color:green;">Mapped</span>':'<span style="color:#999;">Not mapped</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php submit_button('Save Calendar Mapping', 'primary', 'sb_save_mapping'); ?>
                </form>
                <?php endif; ?>
            </div>
            
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:5px;">
                <h2>How It Works</h2>
                <table class="widefat" style="max-width:700px;">
                    <tr><td><strong>Add to cart</strong></td><td>PENDING event (yellow) - <?php echo self::HOLD_HOURS; ?>hr hold</td></tr>
                    <tr><td><strong>Payment received</strong></td><td>Updated to CONFIRMED (green) with customer details</td></tr>
                    <tr><td><strong>Cart abandoned</strong></td><td>Pending event auto-deleted after <?php echo self::HOLD_HOURS; ?>hrs</td></tr>
                    <tr><td><strong>Staff blocks time</strong></td><td>Website shows unavailable</td></tr>
                    <tr><td><strong>Order cancelled</strong></td><td>Event deleted</td></tr>
                    <tr><td><strong>Timezone</strong></td><td>Africa/Nairobi (UTC+3)</td></tr>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}