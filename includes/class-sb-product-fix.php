<?php
/**
 * Ensures all bookable products are correctly configured:
 * - Virtual (no shipping)
 * - In Stock (always - booking system handles availability)
 * - Stock management OFF
 */
if (!defined('ABSPATH')) exit;

class SB_Product_Fix {
    
    private static $instance = null;
    
    private $our_categories = array(
        'live-rooms', 'podcasts', 'post-production', 'voice-over',
        'video-equipment', 'lighting', 'video-editing',
        'backline-packages', 'audio-equipment', 'instruments'
    );
    
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    
    private function __construct() {
        add_filter('woocommerce_is_purchasable', array($this, 'make_purchasable'), 10, 2);
        add_filter('woocommerce_product_is_in_stock', array($this, 'always_in_stock'), 10, 2);
        add_action('admin_menu', array($this, 'add_menu'), 70);
        SB_Debug::log('Product fix class loaded');
    }
    
    public function make_purchasable($purchasable, $product) {
        if ($product->get_price() !== '' && $product->get_price() > 0) {
            return true;
        }
        return $purchasable;
    }
    
    public function always_in_stock($in_stock, $product) {
        $cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'slugs'));
        if (!empty(array_intersect($cats, $this->our_categories))) {
            return true;
        }
        return $in_stock;
    }
    
    public function fix_all_products() {
        $fixed = 0;
        foreach ($this->our_categories as $slug) {
            $products = wc_get_products(array('category'=>array($slug),'status'=>'publish','limit'=>-1));
            foreach ($products as $p) {
                try {
                    $p->set_virtual(true);
                    $p->set_stock_status('instock');
                    $p->set_manage_stock(false);
                    $p->set_catalog_visibility('visible');
                    $price = $p->get_regular_price();
                    if (empty($price)) {
                        $meta = get_post_meta($p->get_id(), '_regular_price', true);
                        if (!empty($meta)) {
                            $p->set_price($meta);
                            $p->set_regular_price($meta);
                        }
                    }
                    $p->save();
                    $fixed++;
                } catch (Exception $e) {
                    SB_Debug::error("Fix failed for {$p->get_id()}: " . $e->getMessage());
                }
            }
        }
        return $fixed;
    }
    
    public function add_menu() {
        add_submenu_page('snowball-booking','Fix Products','Fix Products','manage_options','sb-fix-products',array($this,'render_page'));
    }
    
    public function render_page() {
        $result = null;
        if (isset($_POST['sb_fix_products']) && check_admin_referer('sb_fix_nonce')) {
            $result = $this->fix_all_products();
        }
        
        $total = 0; $oos = 0; $noprice = 0; $notvirt = 0;
        foreach ($this->our_categories as $slug) {
            $products = wc_get_products(array('category'=>array($slug),'status'=>'publish','limit'=>-1));
            foreach ($products as $p) {
                $total++;
                if (!$p->is_in_stock()) $oos++;
                if (empty($p->get_price())) $noprice++;
                if (!$p->is_virtual()) $notvirt++;
            }
        }
        ?>
        <div class="wrap">
            <h1>Fix Booking Products</h1>
            <?php if ($result !== null): ?>
                <div class="notice notice-success"><p><strong><?php echo $result; ?> products fixed!</strong></p></div>
            <?php endif; ?>
            
            <h2>Current Status</h2>
            <table class="widefat striped" style="max-width:500px;">
                <tr><td>Total products</td><td><strong><?php echo $total; ?></strong></td></tr>
                <tr><td>Out of stock</td><td><?php echo $oos > 0 ? "<span style='color:red;font-weight:bold;'>{$oos} — needs fix</span>" : "<span style='color:green;'>0 — OK</span>"; ?></td></tr>
                <tr><td>No price set</td><td><?php echo $noprice > 0 ? "<span style='color:red;font-weight:bold;'>{$noprice} — needs fix</span>" : "<span style='color:green;'>0 — OK</span>"; ?></td></tr>
                <tr><td>Not virtual</td><td><?php echo $notvirt > 0 ? "<span style='color:orange;font-weight:bold;'>{$notvirt} — should be virtual</span>" : "<span style='color:green;'>0 — OK</span>"; ?></td></tr>
            </table>
            
            <h2>What this does</h2>
            <p>Sets all booking products to: Virtual (no shipping), In Stock, No inventory management, Visible and purchasable.</p>
            
            <form method="post">
                <?php wp_nonce_field('sb_fix_nonce'); ?>
                <button type="submit" name="sb_fix_products" class="button button-primary button-hero" style="margin-top:15px;">Fix All Products Now</button>
            </form>
        </div>
        <?php
    }
}
