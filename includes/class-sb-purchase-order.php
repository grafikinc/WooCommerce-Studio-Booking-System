<?php
/**
 * Purchase Order - shows Print/Download PO on the order confirmation (thank you) page
 * Also changes "Place Order" button to "Complete Reservation"
 */
if (!defined('ABSPATH')) exit;

class SB_Purchase_Order {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        // Render PO page when ?sb_po= is set
        add_action('template_redirect', array($this, 'maybe_render_po'));
        
        // Show PO button on order confirmation page
        add_action('woocommerce_thankyou', array($this, 'show_po_on_thankyou'), 5);
        
        // Change "Place Order" to "Complete Reservation"
        add_filter('woocommerce_order_button_text', function() { return 'Complete Reservation'; });
        
        // Override purchase note to use correct deposit % from settings
        add_filter('woocommerce_product_get_purchase_note', function($note, $product) {
            $dep_pct = get_option('sb_deposit_percent', 70);
            if ($dep_pct > 0 && $dep_pct < 100) {
                return sprintf('Minimum %d%% deposit required', $dep_pct);
            }
            return '';
        }, 10, 2);
        
        SB_Debug::log('Purchase Order class loaded');
    }
    
    /**
     * Generate PO from a completed order
     */
    private function get_or_create_po_for_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return null;
        
        // Check if order already has a PO
        $existing = $order->get_meta('_sb_po_number');
        if ($existing) return $existing;
        
        // Generate new PO number
        $counter = get_option('sb_po_counter', 1000);
        $counter++;
        update_option('sb_po_counter', $counter);
        $po_number = 'PO-' . $counter;
        
        // Build PO data from order
        $items = array();
        $total = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $qty = $item->get_quantity();
            $line_total = $item->get_total();
            $total += $line_total;
            
            $items[] = array(
                'name'     => $item->get_name(),
                'qty'      => $qty,
                'price'    => $product ? $product->get_price() : ($line_total / max($qty, 1)),
                'total'    => $line_total,
                'date'     => $item->get_meta('_booking_date'),
                'time'     => $item->get_meta('_booking_time'),
                'end_time' => $item->get_meta('_booking_end_time'),
                'duration' => $item->get_meta('_booking_duration'),
                'engineer' => $item->get_meta('_engineer_preference'),
            );
        }
        
        $dep_pct = intval(get_option('sb_deposit_percent', 70));
        $deposit = ($dep_pct > 0 && $dep_pct < 100) ? ceil($total * $dep_pct / 100) : 0;
        
        $po_data = array(
            'po_number'  => $po_number,
            'order_id'   => $order_id,
            'order_num'  => $order->get_order_number(),
            'customer'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'items'      => $items,
            'total'      => $total,
            'deposit'    => $deposit,
            'balance'    => $total - $deposit,
            'created_at' => current_time('mysql'),
            'status'     => $order->get_status(),
        );
        
        // Save PO
        $all_pos = get_option('sb_purchase_orders', array());
        $all_pos[$po_number] = $po_data;
        update_option('sb_purchase_orders', $all_pos);
        
        // Save PO number to order meta
        $order->update_meta_data('_sb_po_number', $po_number);
        $order->save();
        
        return $po_number;
    }
    
    /**
     * Show PO button on the "Thank you" / order confirmation page
     */
    public function show_po_on_thankyou($order_id) {
        if (!$order_id) return;
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $has_booking = false;
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_booking_date')) { $has_booking = true; break; }
        }
        if (!$has_booking) return;
        
        $po_number = $this->get_or_create_po_for_order($order_id);
        if (!$po_number) return;
        
        $po_url = add_query_arg('sb_po', $po_number, home_url('/'));
        
        $all_pos = get_option('sb_purchase_orders', array());
        $po = isset($all_pos[$po_number]) ? $all_pos[$po_number] : null;
        $deposit = $po ? $po['deposit'] : 0;
        $dep_pct = get_option('sb_deposit_percent', 70);
        $pay_info = get_option('sb_payment_instructions', '');
        ?>
        <div class="sb-po-banner">
            <div class="sb-po-info">
                <strong class="sb-po-title">Purchase Order: <?php echo esc_html($po_number); ?></strong>
                <span class="sb-po-subtitle">Print or save as PDF to send for payment approval</span>
                <div class="sb-po-payment">
                    <?php if ($pay_info): ?>
                    <strong><?php echo nl2br(esc_html($pay_info)); ?></strong><br>
                    <?php endif; ?>
                    <strong>Account Reference:</strong> <?php echo esc_html($po_number); ?><br>
                    <?php if ($dep_pct > 0 && $dep_pct < 100): ?>
                    <strong class="sb-po-deposit-label">Deposit Due (<?php echo intval($dep_pct); ?>%):</strong>
                    <span class="sb-po-deposit-amount">KES <?php echo number_format($deposit); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="<?php echo esc_url($po_url); ?>" target="_blank" class="sb-po-download-btn">
                Print / Download PO
            </a>
        </div>
        <div class="sb-po-hold-notice">
            <p><strong>This booking is held for 72 hours.</strong> Complete payment to confirm your session, or the time slot will be released.</p>
        </div>
        <?php
    }
    
    /**
     * Render PO page if ?sb_po= is set
     */
    public function maybe_render_po() {
        if (!isset($_GET['sb_po'])) return;
        
        $po_number = sanitize_text_field($_GET['sb_po']);
        $all_pos = get_option('sb_purchase_orders', array());
        
        if (!isset($all_pos[$po_number])) {
            wp_die('Purchase Order not found.', 'Not Found', array('response' => 404));
        }
        
        $this->render_po_page($po_number, $all_pos[$po_number]);
        exit;
    }
    
    /**
     * Render printable PO page
     */
    private function render_po_page($po_number, $po) {
        $site_name = get_bloginfo('name');
        $logo_url = '';
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
        
        $created = '';
        try { $created = (new DateTime($po['created_at']))->format('j F Y, g:i A'); }
        catch (Exception $e) { $created = $po['created_at'] ?? ''; }
        
        $customer = $po['customer'] ?? '';
        $email = $po['email'] ?? '';
        $phone = $po['phone'] ?? '';
        $order_num = $po['order_num'] ?? '';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Purchase Order <?php echo esc_html($po_number); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#333;background:#f0f0f0;}
.po-page{max-width:800px;margin:20px auto;background:#fff;padding:50px;box-shadow:0 2px 10px rgba(0,0,0,.1);}
.po-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:40px;padding-bottom:20px;border-bottom:3px solid #000;}
.po-logo img{max-height:80px;max-width:250px;}
.po-logo-text{font-size:28px;font-weight:bold;}
.po-title{text-align:right;}
.po-title h1{font-size:28px;margin-bottom:5px;text-transform:uppercase;letter-spacing:2px;}
.po-title .po-num{font-size:18px;color:#666;}
.po-title .po-date{font-size:13px;color:#999;margin-top:5px;}
.po-status{display:inline-block;padding:4px 14px;border-radius:3px;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:1px;background:#FFF3E0;color:#E65100;border:1px solid #FFB74D;}
.po-info{display:flex;justify-content:space-between;margin-bottom:30px;}
.po-info-block h3{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;margin-bottom:5px;}
.po-info-block p{font-size:14px;line-height:1.6;}
table.po-table{width:100%;border-collapse:collapse;margin-bottom:30px;}
.po-table th{background:#000;color:#fff;padding:12px 15px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:1px;}
.po-table td{padding:12px 15px;border-bottom:1px solid #eee;font-size:14px;}
.po-table .r{text-align:right;}
.po-totals{display:flex;justify-content:flex-end;margin-bottom:30px;}
.po-totals table{width:300px;}
.po-totals td{padding:8px 15px;font-size:14px;}
.po-totals td:last-child{text-align:right;font-weight:500;}
.po-totals .dep{color:#E65100;font-weight:bold;font-size:16px;}
.po-totals .tot{font-size:18px;font-weight:bold;border-top:2px solid #000;}
.po-pay{background:#FFF8E1;border:1px solid #FFC107;border-radius:5px;padding:20px;margin-bottom:30px;}
.po-pay h3{font-size:14px;font-weight:bold;margin-bottom:10px;}
.po-pay p{font-size:13px;line-height:1.6;}
.po-pay .paybill{font-size:24px;font-weight:bold;}
.po-hold{background:#FFF3E0;border:1px solid #FFB74D;border-radius:5px;padding:15px;margin-bottom:30px;}
.po-hold p{font-size:13px;color:#E65100;}
.po-terms{border-top:1px solid #eee;padding-top:20px;margin-bottom:20px;}
.po-terms h3{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;margin-bottom:10px;}
.po-terms p{font-size:11px;color:#888;line-height:1.6;}
.po-footer{text-align:center;color:#bbb;font-size:11px;padding-top:15px;border-top:1px solid #eee;}
.po-actions{text-align:center;margin:20px auto;max-width:800px;}
.po-actions button,.po-actions a.btn{display:inline-block;padding:12px 30px;font-size:15px;font-weight:bold;border:none;border-radius:5px;cursor:pointer;margin:0 8px;text-decoration:none;}
.btn-print{background:#000;color:#FDD835;}
.btn-print:hover{background:#222;}
.btn-close{background:#eee;color:#333;}
.btn-close:hover{background:#ddd;}
@media print{body{background:#fff;}.po-page{box-shadow:none;margin:0;padding:30px;}.po-actions{display:none!important;}}
@media(max-width:600px){.po-page{padding:25px;margin:10px;}.po-header{flex-direction:column;gap:15px;}.po-title{text-align:left;}.po-info{flex-direction:column;gap:15px;}.po-totals{justify-content:flex-start;}.po-totals table{width:100%;}}
</style>
</head>
<body>
<div class="po-actions">
    <button class="btn-print" onclick="window.print()">Print / Save as PDF</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>

<div class="po-page">
    <div class="po-header">
        <div class="po-logo">
            <?php if ($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($site_name); ?>"/>
            <?php else: ?><div class="po-logo-text"><?php echo esc_html($site_name); ?></div><?php endif; ?>
        </div>
        <div class="po-title">
            <h1>Purchase Order</h1>
            <div class="po-num"><?php echo esc_html($po_number); ?></div>
            <div class="po-date">Issued: <?php echo esc_html($created); ?></div>
            <div style="margin-top:8px;"><span class="po-status">Pending Payment</span></div>
        </div>
    </div>
    
    <div class="po-info">
        <div class="po-info-block">
            <h3>From</h3>
            <p><strong><?php echo esc_html($site_name); ?></strong><br><?php echo esc_html(get_option('sb_studio_address', 'Nairobi, Kenya')); ?></p>
        </div>
        <div class="po-info-block">
            <h3>Customer</h3>
            <p>
                <?php if ($customer): ?><strong><?php echo esc_html($customer); ?></strong><br><?php endif; ?>
                <?php if ($email): ?><?php echo esc_html($email); ?><br><?php endif; ?>
                <?php if ($phone): ?><?php echo esc_html($phone); ?><br><?php endif; ?>
                <?php if ($order_num): ?>Order #<?php echo esc_html($order_num); ?><?php endif; ?>
            </p>
        </div>
        <div class="po-info-block">
            <h3>Reference</h3>
            <p><strong><?php echo esc_html($po_number); ?></strong><br>Valid for: 72 hours</p>
        </div>
    </div>
    
    <table class="po-table">
        <thead><tr><th>Service</th><th>Details</th><th class="r">Qty</th><th class="r">Amount (KES)</th></tr></thead>
        <tbody>
        <?php foreach ($po['items'] as $item):
            $details = array();
            if (!empty($item['date'])) {
                try { $details[] = (new DateTime($item['date']))->format('l, j F Y'); }
                catch (Exception $e) { $details[] = $item['date']; }
            }
            if (!empty($item['time'])) {
                $timestr = $item['time'];
                if (!empty($item['end_time'])) $timestr .= ' - ' . $item['end_time'];
                $details[] = $timestr;
            }
            if (!empty($item['duration'])) {
                $h = floor($item['duration']/60);
                if ($h > 0) $details[] = $h . ' hour' . ($h>1?'s':'');
            }
            if (!empty($item['engineer']) && $item['engineer'] !== 'No preference') $details[] = 'Engineer: ' . $item['engineer'];
        ?>
        <tr>
            <td><strong><?php echo esc_html($item['name']); ?></strong></td>
            <td style="color:#888;font-size:12px;"><?php echo esc_html(implode(' | ', $details)); ?></td>
            <td class="r"><?php echo intval($item['qty']); ?></td>
            <td class="r"><?php echo number_format($item['total']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="po-totals"><table>
        <tr><td>Subtotal</td><td>KES <?php echo number_format($po['total']); ?></td></tr>
        <?php $dep_pct = get_option('sb_deposit_percent', 70); ?>
        <tr class="dep"><td>Deposit Due (<?php echo intval($dep_pct); ?>%)</td><td>KES <?php echo number_format($po['deposit']); ?></td></tr>
        <tr><td>Balance on Session Day</td><td>KES <?php echo number_format($po['balance']); ?></td></tr>
        <tr class="tot"><td><strong>Total</strong></td><td><strong>KES <?php echo number_format($po['total']); ?></strong></td></tr>
    </table></div>
    
    <div class="po-pay">
        <h3>Payment Instructions</h3>
        <?php $pay_info = get_option('sb_payment_instructions', ''); if ($pay_info): ?>
        <p><strong><?php echo nl2br(esc_html($pay_info)); ?></strong><br>
        Account Reference: <strong><?php echo esc_html($po_number); ?></strong><br>
        Or pay online via the checkout page.</p>
        <?php else: ?>
        <p>Account Reference: <strong><?php echo esc_html($po_number); ?></strong><br>
        Pay online via the checkout page.</p>
        <?php endif; ?>
    </div>
    
    <div class="po-hold">
        <p><strong>This booking is held for 72 hours.</strong> Please complete payment within this period to confirm your session. After 72 hours the time slot will be released.</p>
    </div>
    
    <div class="po-terms">
        <h3>Terms &amp; Conditions</h3>
        <p>1. <?php echo intval(get_option('sb_deposit_percent', 70)); ?>% deposit required to confirm all bookings. Balance due on session day.<br>
        2. Cancellations 48+ hours before session: full deposit refund.<br>
        3. Cancellations under 48 hours: deposit forfeited.<br>
        4. No-shows charged full session amount.<br>
        5. Session times include setup and teardown.<br>
        6. Equipment damage during session is client's responsibility.<br>
        7. Engineer preferences are not guaranteed.</p>
    </div>
    
    <div class="po-footer">
        <p><?php echo esc_html($site_name); ?> &mdash; <?php echo esc_html($po_number); ?> &mdash; <?php echo esc_html($created); ?></p>
    </div>
</div>

<div class="po-actions" style="margin-bottom:40px;">
    <button class="btn-print" onclick="window.print()">Print / Save as PDF</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>
</body></html>
        <?php
    }
}