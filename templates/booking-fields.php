<div class="sb-booking-fields">
    <h3><?php _e('Select Date & Time', 'snowball-booking'); ?></h3>
    
    <div class="sb-field">
        <label for="sb_booking_date"><?php _e('Booking Date', 'snowball-booking'); ?></label>
        <input type="date" id="sb_booking_date" name="booking_date" required min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" />
    </div>
    
    <div class="sb-field">
        <label for="sb_booking_time"><?php _e('Time Slot', 'snowball-booking'); ?></label>
        <select id="sb_booking_time" name="booking_time" required>
            <option value=""><?php _e('Select a date first', 'snowball-booking'); ?></option>
        </select>
    </div>
    
    <div class="sb-field">
        <label for="sb_booking_duration"><?php _e('Duration', 'snowball-booking'); ?></label>
        <select id="sb_booking_duration" name="booking_duration">
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
    
    <input type="hidden" name="product_id" value="<?php echo esc_attr($product->get_id()); ?>" />
    
    <div class="sb-deposit-notice">
        <p><strong><?php _e('Note:', 'snowball-booking'); ?></strong> <?php printf(__('All bookings require a %d%% deposit upon booking.', 'snowball-booking'), intval(get_option('sb_deposit_percent', 70))); ?></p>
    </div>
</div>