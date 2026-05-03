jQuery(document).ready(function($) {
    var productId = $('input[name="product_id"]').val();
    
    $('#sb_booking_date').on('change', function() {
        var date = $(this).val();
        if (!date) return;
        
        $.ajax({
            url: sbData.ajaxurl,
            type: 'POST',
            data: {
                action: 'sb_get_available_slots',
                nonce: sbData.nonce,
                product_id: productId,
                date: date
            },
            beforeSend: function() {
                $('#sb_booking_time').html('<option value="">Loading...</option>');
            },
            success: function(response) {
                if (response.success) {
                    var slots = response.data;
                    var options = '<option value="">Select a time slot</option>';
                    $.each(slots, function(start, end) {
                        options += '<option value="' + start + '">' + start + ' - ' + end + '</option>';
                    });
                    $('#sb_booking_time').html(options);
                }
            }
        });
    });
    
    $('form.cart').on('submit', function(e) {
        if ($('.sb-booking-fields').length > 0) {
            if (!$('#sb_booking_date').val() || !$('#sb_booking_time').val()) {
                e.preventDefault();
                alert('Please select a date and time slot.');
                return false;
            }
        }
    });
});
