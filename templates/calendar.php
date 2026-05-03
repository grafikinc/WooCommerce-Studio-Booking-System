<?php
/**
 * Studio Booking Page
 * Step 0: Studio or Live → Step 1: Pick service → Step 2: Date & Time → Step 3: Add-ons → Step 4: Review
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('wc_get_products')) {
    echo '<p>WooCommerce is required for the booking system.</p>';
    return;
}

// Categories grouped by mode
$studio_categories = array(
    'Live Rooms & Studio Time' => 'live-rooms',
    'Podcasts' => 'podcasts',
    'Post Production' => 'post-production',
    'Voice-over' => 'voice-over',
    'Video Editing' => 'video-editing',
);

$live_categories = array(
    'Backline Packages' => 'backline-packages',
    'Audio Equipment' => 'audio-equipment',
    'Instruments' => 'instruments',
    'Lighting' => 'lighting',
    'Video Equipment' => 'video-equipment',
);

$hourly_cats = array('live-rooms', 'podcasts', 'video-editing');

function sb_fmt_price($price) {
    if (empty($price) || !is_numeric($price)) return '0';
    return number_format(floatval($price));
}

// Preload engineer product (single product)
$eng_product = null;
$eng_price = 0;
try {
    // Search by SKU first, then by name
    $eng_found = wc_get_products(array('s' => 'Sound Engineer', 'status' => 'publish', 'limit' => 5));
    // Filter to only actual engineer products (not rooms)
    foreach ($eng_found as $ef) {
        $cats = wp_get_post_terms($ef->get_id(), 'product_cat', array('fields' => 'slugs'));
        if (in_array('backline-packages', $cats) || strpos(strtolower($ef->get_sku()), 'engineer') !== false) {
            $eng_product = $ef;
            $ep = $ef->get_price();
            $eng_price = (!empty($ep) && is_numeric($ep)) ? floatval($ep) : 0;
            break;
        }
    }
} catch (Exception $e) {}

// Setup product
$setup_product = null;
try {
    $sp = wc_get_products(array('sku' => 'PP-VIDEO-AUDIO-SETUP', 'status' => 'publish', 'limit' => 1));
    if (!empty($sp)) $setup_product = $sp[0];
} catch (Exception $e) {}
?>

<div id="sb-app">

<!-- STEP 0: STUDIO or LIVE -->
<div class="sb-step sb-active" id="sb-step-0">
    <div class="sb-step-head"><h2>What are you looking for?</h2></div>
    <div class="sb-mode-grid">
        <div class="sb-mode-card" id="sb-mode-studio">
            <div class="sb-mode-icon"></div>
            <h3>Studio</h3>
            <p>Recording, mixing, podcasts, voice-over & video editing</p>
            <ul>
                <li>Live rooms & studio time</li>
                <li>Podcast recording</li>
                <li>Post production</li>
                <li>Voice-over sessions</li>
                <li>Video editing suites</li>
            </ul>
        </div>
        <div class="sb-mode-card" id="sb-mode-live">
            <div class="sb-mode-icon"></div>
            <h3>Live Sound & Rentals</h3>
            <p>Backline, PA systems, instruments & equipment hire</p>
            <ul>
                <li>PA system packages</li>
                <li>Backline packages</li>
                <li>Instrument rentals</li>
                <li>Audio equipment</li>
                <li>Lighting & video gear</li>
            </ul>
        </div>
    </div>
</div>

<!-- STEP 1: SELECT SERVICE -->
<div class="sb-step" id="sb-step-1" style="display:none;">
    <div class="sb-step-head">
        <button type="button" class="sb-back" onclick="sbGo(0)">← Back</button>
        <span class="sb-num">1</span>
        <h2 id="sb-step1-title">Select a service</h2>
    </div>
    
    <!-- STUDIO SERVICES -->
    <div id="sb-studio-services" style="display:none;">
        <?php foreach ($studio_categories as $label => $slug):
            $products = array();
            try {
                $products = wc_get_products(array('category'=>array($slug),'status'=>'publish','limit'=>-1,'orderby'=>'price','order'=>'ASC'));
            } catch (Exception $e) { continue; }
            if (empty($products)) continue;
        ?>
        <div class="sb-cat">
            <h3 class="sb-cat-title" data-cat="<?php echo esc_attr($slug); ?>">
                <?php echo esc_html($label); ?> <span class="sb-cnt">(<?php echo count($products); ?>)</span> <span class="sb-arrow">▸</span>
            </h3>
            <div class="sb-cat-items" id="cat-<?php echo esc_attr($slug); ?>" style="display:none;">
                <?php foreach ($products as $p):
                    $hourly = in_array($slug, $hourly_cats);
                    $price = $p->get_price();
                    $price = (!empty($price) && is_numeric($price)) ? floatval($price) : 0;
                ?>
                <div class="sb-item" data-id="<?php echo esc_attr($p->get_id()); ?>" data-name="<?php echo esc_attr($p->get_name()); ?>" data-price="<?php echo esc_attr($price); ?>" data-hourly="<?php echo $hourly?'1':'0'; ?>" data-cat="<?php echo esc_attr($slug); ?>">
                    <div>
                        <strong><?php echo esc_html($p->get_name()); ?></strong>
                        <?php $desc = wp_strip_all_tags($p->get_short_description()); if (!empty($desc)): ?>
                        <p><?php echo esc_html($desc); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="sb-item-price">KES <?php echo sb_fmt_price($price); ?><?php echo $hourly?'<small>/hr</small>':''; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- LIVE SOUND SERVICES -->
    <div id="sb-live-services" style="display:none;">
        <?php foreach ($live_categories as $label => $slug):
            $products = array();
            try {
                $products = wc_get_products(array('category'=>array($slug),'status'=>'publish','limit'=>-1,'orderby'=>'price','order'=>'ASC'));
            } catch (Exception $e) { continue; }
            if (empty($products)) continue;
        ?>
        <div class="sb-cat">
            <h3 class="sb-cat-title" data-cat="<?php echo esc_attr($slug); ?>">
                <?php echo esc_html($label); ?> <span class="sb-cnt">(<?php echo count($products); ?>)</span> <span class="sb-arrow">▸</span>
            </h3>
            <div class="sb-cat-items" id="cat-<?php echo esc_attr($slug); ?>" style="display:none;">
                <?php foreach ($products as $p):
                    $price = $p->get_price();
                    $price = (!empty($price) && is_numeric($price)) ? floatval($price) : 0;
                ?>
                <div class="sb-item" data-id="<?php echo esc_attr($p->get_id()); ?>" data-name="<?php echo esc_attr($p->get_name()); ?>" data-price="<?php echo esc_attr($price); ?>" data-hourly="0" data-cat="<?php echo esc_attr($slug); ?>">
                    <div>
                        <strong><?php echo esc_html($p->get_name()); ?></strong>
                        <?php $desc = wp_strip_all_tags($p->get_short_description()); if (!empty($desc)): ?>
                        <p><?php echo esc_html($desc); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="sb-item-price">KES <?php echo sb_fmt_price($price); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- STEP 2: DATE & TIME -->
<div class="sb-step" id="sb-step-2" style="display:none;">
    <div class="sb-step-head">
        <button type="button" class="sb-back" onclick="sbGo(1)">← Back</button>
        <span class="sb-num">2</span><h2>Pick date & time</h2>
    </div>
    <div class="sb-chosen" id="sb-chosen"></div>
    <div class="sb-field">
        <label>Date</label>
        <input type="date" id="sb-date" min="<?php echo esc_attr(date('Y-m-d',strtotime('+1 day'))); ?>" max="<?php echo esc_attr(date('Y-m-d',strtotime('+90 days'))); ?>" />
    </div>
    <div class="sb-field" id="sb-times-wrap" style="display:none;">
        <label>Available Times</label>
        <div id="sb-times"></div>
    </div>
    <div class="sb-field" id="sb-dur-wrap" style="display:none;">
        <label>Duration</label>
        <select id="sb-dur">
            <option value="60">1 Hour</option>
            <option value="120">2 Hours</option>
            <option value="180">3 Hours</option>
            <option value="240">4 Hours</option>
            <option value="300">5 Hours</option>
            <option value="360">6 Hours</option>
            <option value="420">7 Hours</option>
            <option value="480">8 Hours (Full Day)</option>
        </select>
    </div>
    <button type="button" class="sb-next" id="sb-next-3" style="display:none;" onclick="sbGo(3)">Continue →</button>
</div>

<!-- STEP 3: ADD-ONS -->
<div class="sb-step" id="sb-step-3" style="display:none;">
    <div class="sb-step-head">
        <button type="button" class="sb-back" onclick="sbGo(2)">← Back</button>
        <span class="sb-num">3</span><h2>Add extras <small>(optional)</small></h2>
    </div>
    
    <!-- STUDIO ADD-ONS -->
    <div id="sb-addons-studio" class="sb-addons" style="display:none;">
        <div class="sb-field">
            <label for="sb-eng-pref">Preferred Engineer</label>
            <select id="sb-eng-pref" class="sb-select">
                <option value="No preference">No preference</option>
                <option value="Rick">Rick</option>
                <option value="Earnest">Earnest</option>
                <option value="Beth">Beth</option>
            </select>
            <p class="sb-help-text">All studio sessions include an engineer. This is a preference, not a guarantee.</p>
        </div>
        
        <?php if ($setup_product):
            $sp_price = $setup_product->get_price();
            $sp_price = (!empty($sp_price) && is_numeric($sp_price)) ? floatval($sp_price) : 0;
        ?>
        <label class="sb-addon">
            <input type="checkbox" id="sb-add-setup" data-id="<?php echo esc_attr($setup_product->get_id()); ?>" data-price="<?php echo esc_attr($sp_price); ?>" />
            <div>
                <strong>Video & Audio Setup</strong>
                <p>KES <?php echo sb_fmt_price($sp_price); ?></p>
            </div>
        </label>
        <?php endif; ?>
    </div>
    
    <!-- LIVE SOUND ADD-ONS -->
    <div id="sb-addons-live" class="sb-addons" style="display:none;">
        <?php if ($eng_product): ?>
        <label class="sb-addon">
            <input type="checkbox" id="sb-add-eng" 
                   data-id="<?php echo esc_attr($eng_product->get_id()); ?>" 
                   data-price="<?php echo esc_attr($eng_price); ?>" />
            <div>
                <strong>Sound Engineer</strong>
                <p>KES <?php echo sb_fmt_price($eng_price); ?>/hr — Professional live sound engineer for your event</p>
            </div>
        </label>
        <div id="sb-eng-hours-wrap" style="display:none;" class="sb-eng-hours-wrap">
            <label for="sb-eng-hours" class="sb-label">How many hours?</label>
            <select id="sb-eng-hours" class="sb-select">
                <?php for ($h = 1; $h <= 12; $h++): ?>
                <option value="<?php echo $h; ?>"><?php echo $h; ?> hour<?php echo $h > 1 ? 's' : ''; ?> — KES <?php echo sb_fmt_price($eng_price * $h); ?></option>
                <?php endfor; ?>
            </select>
        </div>
        
        <div class="sb-field">
            <label for="sb-eng-pref-live">Preferred Engineer</label>
            <select id="sb-eng-pref-live" class="sb-select">
                <option value="No preference">No preference</option>
                <option value="Rick">Rick</option>
                <option value="Earnest">Earnest</option>
                <option value="Beth">Beth</option>
            </select>
        </div>
        <?php endif; ?>
    </div>
    
    <p class="sb-help-text">No extras needed? Just continue.</p>
    <button type="button" class="sb-next" onclick="sbReview()">Review Booking →</button>
</div>

<!-- STEP 4: REVIEW & CART -->
<div class="sb-step" id="sb-step-4" style="display:none;">
    <div class="sb-step-head">
        <button type="button" class="sb-back" onclick="sbGo(3)">← Back</button>
        <span class="sb-num">4</span><h2>Review & book</h2>
    </div>
    <table class="sb-review">
        <tr><td>Service</td><td id="rv-svc"></td></tr>
        <tr><td>Date</td><td id="rv-date"></td></tr>
        <tr><td>Time</td><td id="rv-time"></td></tr>
        <tr><td>Duration</td><td id="rv-dur"></td></tr>
        <tr id="rv-eng-row"><td>Engineer</td><td id="rv-eng"></td></tr>
        <tr id="rv-addons-row" style="display:none;"><td>Extras</td><td id="rv-addons"></td></tr>
        <tr class="sb-total"><td><strong>Estimated Total</strong></td><td><strong id="rv-total"></strong></td></tr>
        <?php $dep_pct = get_option('sb_deposit_percent', 70); if ($dep_pct > 0 && $dep_pct < 100): ?>
        <tr><td>Deposit (<?php echo intval($dep_pct); ?>%)</td><td id="rv-dep" class="sb-deposit-amount"></td></tr>
        <?php endif; ?>
    </table>
    <div class="sb-payment-notice">
        <?php if ($dep_pct > 0 && $dep_pct < 100): ?>
        <p><?php echo intval($dep_pct); ?>% deposit required to confirm. Balance due on session day.</p>
        <?php endif; ?>
        <?php $pay_info = get_option('sb_payment_instructions', ''); if ($pay_info): ?>
        <p><strong><?php echo nl2br(esc_html($pay_info)); ?></strong></p>
        <?php endif; ?>
    </div>
    <button type="button" class="sb-cart-btn" id="sb-cart-btn" onclick="sbAddToCart()">Add to Cart & Book Now</button>
</div>

</div>

<style>
#sb-app{max-width:800px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,sans-serif;}
.sb-step{background:#fff;padding:25px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:20px;}
.sb-step-head{display:flex;align-items:center;gap:12px;margin-bottom:20px;}
.sb-step-head h2{margin:0;font-size:20px;}
.sb-num{background:#000;color:#fff;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:14px;flex-shrink:0;}
.sb-back{background:none;border:none;cursor:pointer;font-size:14px;color:#666;padding:0;}
.sb-back:hover{color:#000;}

/* Mode selection cards */
.sb-mode-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;}
@media(max-width:600px){.sb-mode-grid{grid-template-columns:1fr;}}
.sb-mode-card{border:2px solid #eee;border-radius:8px;padding:25px;cursor:pointer;transition:all .2s;text-align:center;}
.sb-mode-card:hover{border-color:#fdd835;background:#fffde7;transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1);}
.sb-mode-icon{font-size:48px;margin-bottom:10px;}
.sb-mode-card h3{margin:0 0 8px;font-size:20px;}
.sb-mode-card p{color:#666;margin:0 0 12px;font-size:14px;}
.sb-mode-card ul{list-style:none;padding:0;margin:0;text-align:left;}
.sb-mode-card ul li{padding:4px 0;color:#888;font-size:13px;}
.sb-mode-card ul li::before{content:"✓ ";color:#4CAF50;}

/* Categories */
.sb-cat-title{cursor:pointer;padding:12px 15px;background:#f5f5f5;margin:0 0 2px;border-radius:4px;display:flex;align-items:center;gap:8px;font-size:15px;}
.sb-cat-title:hover{background:#eee;}
.sb-cnt{color:#999;font-weight:normal;font-size:13px;}
.sb-arrow{margin-left:auto;color:#999;}

/* Product items */
.sb-item{display:flex;justify-content:space-between;align-items:center;padding:15px;border:2px solid #eee;border-radius:6px;margin:8px 0;cursor:pointer;transition:all .15s;}
.sb-item:hover{border-color:#fdd835;background:#fffde7;}
.sb-item.selected{border-color:#fdd835;background:#fff9c4;}
.sb-item p{margin:3px 0 0;color:#888;font-size:13px;}
.sb-item-price{font-weight:bold;font-size:16px;white-space:nowrap;}
.sb-item-price small{font-weight:normal;color:#888;}

/* Form elements */
.sb-chosen{background:#f5f5f5;padding:10px 15px;border-radius:5px;margin-bottom:15px;font-weight:500;}
.sb-field{margin-bottom:18px;}
.sb-field label{display:block;font-weight:600;margin-bottom:6px;font-size:14px;}
.sb-field input[type="date"],.sb-field select{width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;font-size:15px;box-sizing:border-box;}

/* Time slots */
#sb-times{display:flex;flex-wrap:wrap;gap:8px;}
.sb-slot{padding:10px 16px;border:2px solid #ddd;border-radius:5px;cursor:pointer;background:#fff;font-size:14px;font-weight:500;}
.sb-slot:hover{border-color:#fdd835;background:#fffde7;}
.sb-slot.picked{border-color:#fdd835;background:#fdd835;color:#000;}
.sb-no-slots{color:#d32f2f;font-style:italic;}

/* Buttons */
.sb-next,.sb-cart-btn{display:block;width:100%;padding:14px;background:#000;color:#fdd835;border:none;border-radius:6px;font-size:16px;font-weight:bold;cursor:pointer;margin-top:20px;text-transform:uppercase;letter-spacing:1px;}
.sb-next:hover{background:#222;}
.sb-cart-btn{background:#fdd835;color:#000;}
.sb-cart-btn:hover{background:#fbc02d;}

/* Add-ons */
.sb-addon{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #eee;border-radius:6px;margin-bottom:8px;cursor:pointer;}
.sb-addon:hover{background:#fafafa;}
.sb-addon input[type="checkbox"]{margin-top:4px;}
.sb-addon strong{font-size:15px;}
.sb-addon p{margin:3px 0 0;color:#888;font-size:13px;}

/* Review table */
.sb-review{width:100%;border-collapse:collapse;margin-bottom:15px;}
.sb-review td{padding:10px 12px;border-bottom:1px solid #eee;}
.sb-review td:first-child{color:#888;width:35%;}
.sb-total td{border-top:2px solid #000;border-bottom:2px solid #000;font-size:17px;}
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
    
    var B = {id:null, name:'', price:0, hourly:false, date:'', time:'', dur:60, cat:'', mode:''};
    
    window.sbGo = function(s) {
        $('.sb-step').hide();
        $('#sb-step-' + s).fadeIn(200);
        $('html,body').animate({scrollTop: $('#sb-app').offset().top - 50}, 200);
    };
    
    // STEP 0: Mode selection
    $('#sb-mode-studio').on('click', function() {
        B.mode = 'studio';
        $('#sb-studio-services').show();
        $('#sb-live-services').hide();
        $('#sb-step1-title').text('Select a studio service');
        sbGo(1);
    });
    
    $('#sb-mode-live').on('click', function() {
        B.mode = 'live';
        $('#sb-studio-services').hide();
        $('#sb-live-services').show();
        $('#sb-step1-title').text('Select equipment or package');
        sbGo(1);
    });
    
    // Category toggle
    $('.sb-cat-title').on('click', function() {
        var slug = $(this).data('cat');
        var el = $('#cat-' + slug);
        // Close other categories in the same mode
        $(this).closest('[id^="sb-"][id$="-services"]').find('.sb-cat-items').not(el).slideUp(150);
        $(this).closest('[id^="sb-"][id$="-services"]').find('.sb-arrow').text('\u25B8');
        el.slideToggle(150);
        $(this).find('.sb-arrow').text(el.is(':visible') ? '\u25BE' : '\u25B8');
    });
    
    // Select product
    $('.sb-item').on('click', function() {
        $('.sb-item').removeClass('selected');
        $(this).addClass('selected');
        B.id = $(this).data('id');
        B.name = $(this).data('name');
        B.price = parseFloat($(this).data('price')) || 0;
        B.hourly = ($(this).data('hourly') == 1);
        B.cat = $(this).data('cat');
        
        $('#sb-chosen').text(B.name + ' \u2014 KES ' + fmt(B.price) + (B.hourly ? '/hr' : ''));
        B.hourly ? $('#sb-dur-wrap').show() : $('#sb-dur-wrap').hide();
        
        // Reset
        B.time = '';
        B.date = '';
        $('#sb-date').val('');
        $('#sb-times-wrap').hide();
        $('#sb-next-3').hide();
        
        sbGo(2);
    });
    
    // Date change
    $('#sb-date').on('change', function() {
        B.date = $(this).val();
        if (!B.date || !B.id) return;
        
        $('#sb-times').html('<p>Loading available times...</p>');
        $('#sb-times-wrap').show();
        
        $.ajax({
            url: sbData.ajaxurl,
            type: 'POST',
            data: {
                action: 'sb_get_available_slots',
                nonce: sbData.nonce,
                product_id: B.id,
                date: B.date
            },
            success: function(r) {
                if (r.success && r.data) {
                    var h = '';
                    $.each(r.data, function(start, end) {
                        h += '<button type="button" class="sb-slot" data-t="' + start + '">' + start + '</button>';
                    });
                    if (h === '') {
                        h = '<p class="sb-no-slots">No slots available on this date. Try another day.</p>';
                    }
                    $('#sb-times').html(h);
                } else {
                    $('#sb-times').html('<p class="sb-no-slots">Could not load time slots.</p>');
                }
            },
            error: function() {
                $('#sb-times').html('<p class="sb-no-slots">Error loading time slots. Please try again.</p>');
            }
        });
    });
    
    // Pick time
    $(document).on('click', '.sb-slot', function() {
        $('.sb-slot').removeClass('picked');
        $(this).addClass('picked');
        B.time = $(this).data('t');
        $('#sb-next-3').show();
    });
    
    // Duration
    $('#sb-dur').on('change', function() {
        B.dur = parseInt($(this).val()) || 60;
    });
    
    // Show correct add-ons when entering step 3
    var origSbGo = window.sbGo;
    window.sbGo = function(s) {
        if (s === 3) {
            if (B.mode === 'studio') {
                $('#sb-addons-studio').show();
                $('#sb-addons-live').hide();
            } else {
                $('#sb-addons-studio').hide();
                $('#sb-addons-live').show();
            }
        }
        $('.sb-step').hide();
        $('#sb-step-' + s).fadeIn(200);
        $('html,body').animate({scrollTop: $('#sb-app').offset().top - 50}, 200);
    };
    
    // Engineer toggle (live mode)
    $('#sb-add-eng').on('change', function() {
        $(this).is(':checked') ? $('#sb-eng-hours-wrap').slideDown(150) : $('#sb-eng-hours-wrap').slideUp(150);
    });
    
    // Review
    window.sbReview = function() {
        var hrs = Math.floor(B.dur / 60);
        var total = B.hourly ? (B.price * hrs) : B.price;
        var addons = [];
        var engPref = 'No preference';
        
        if (B.mode === 'studio') {
            // Studio: engineer included, just preference
            engPref = $('#sb-eng-pref').val();
            
            if ($('#sb-add-setup').length && $('#sb-add-setup').is(':checked')) {
                var sp = parseFloat($('#sb-add-setup').data('price')) || 0;
                total += sp;
                addons.push('Video & Audio Setup (KES ' + fmt(sp) + ')');
            }
            
            $('#rv-eng').text(engPref + ' (included)');
            
        } else {
            // Live: engineer is a paid add-on (per hour)
            engPref = $('#sb-eng-pref-live').val();
            
            if ($('#sb-add-eng').is(':checked')) {
                var engHours = parseInt($('#sb-eng-hours').val()) || 1;
                var engRate = parseFloat($('#sb-add-eng').data('price')) || 0;
                var engTotal = engRate * engHours;
                total += engTotal;
                addons.push('Sound Engineer (' + engHours + 'hr' + (engHours > 1 ? 's' : '') + ' @ KES ' + fmt(engRate) + '/hr = KES ' + fmt(engTotal) + ')');
                $('#rv-eng').text(engPref);
            } else {
                $('#rv-eng').text('None');
            }
        }
        
        $('#rv-svc').text(B.name);
        $('#rv-date').text(fmtDate(B.date));
        $('#rv-time').text(B.time);
        $('#rv-dur').text(hrs + ' hour' + (hrs > 1 ? 's' : ''));
        $('#rv-total').text('KES ' + fmt(total));
        var depPct = <?php echo intval(get_option('sb_deposit_percent', 70)); ?>;
        if (depPct > 0 && depPct < 100) {
            $('#rv-dep').text('KES ' + fmt(Math.ceil(total * depPct / 100)));
        }
        
        if (addons.length) {
            $('#rv-addons').text(addons.join(', '));
            $('#rv-addons-row').show();
        } else {
            $('#rv-addons-row').hide();
        }
        
        sbGo(4);
    };
    
    // Add to cart
    window.sbAddToCart = function() {
        var btn = $('#sb-cart-btn');
        btn.text('Adding...').prop('disabled', true);
        
        var engPref = B.mode === 'studio' ? $('#sb-eng-pref').val() : $('#sb-eng-pref-live').val();
        
        var data = {
            action: 'sb_add_to_cart',
            nonce: sbData.nonce,
            product_id: B.id,
            booking_date: B.date,
            booking_time: B.time,
            booking_duration: B.dur,
            quantity: B.hourly ? Math.floor(B.dur / 60) : 1,
            engineer_preference: engPref,
            booking_mode: B.mode
        };
        
        if (B.mode === 'live' && $('#sb-add-eng').length && $('#sb-add-eng').is(':checked')) {
            data.engineer_id = $('#sb-add-eng').data('id');
            data.engineer_hours = parseInt($('#sb-eng-hours').val()) || 1;
        }
        if ($('#sb-add-setup').length && $('#sb-add-setup').is(':checked')) {
            data.setup_id = $('#sb-add-setup').data('id');
        }
        
        $.ajax({
            url: sbData.ajaxurl,
            type: 'POST',
            data: data,
            success: function(r) {
                if (r.success) {
                    window.location.href = r.data.cart_url || '/cart/';
                } else {
                    btn.text('Add to Cart & Book Now').prop('disabled', false);
                    alert(r.data || 'Error adding to cart.');
                }
            },
            error: function() {
                btn.text('Add to Cart & Book Now').prop('disabled', false);
                alert('Connection error. Please try again.');
            }
        });
    };
    
    function fmt(n) {
        return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    
    function fmtDate(d) {
        try {
            return new Date(d + 'T00:00:00').toLocaleDateString('en-KE', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
            });
        } catch(e) {
            return d;
        }
    }
    
});
</script>