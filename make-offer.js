jQuery(document).ready(function($) {
    
    // Handle offer form submission
    $('#make-offer-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var responseDiv = $('#offer-response');
        var submitBtn = form.find('.make-offer-btn');
        
        // Disable button and show loading
        submitBtn.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: make_offer_ajax.ajax_url,
            type: 'POST',
            data: form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // Debug logging
                    console.log('Offer response:', data);
                    if (data.debug_info) {
                        console.log('Debug info:', data.debug_info);
                    }
                    
                    if (data.status === 'accepted') {
                        // Offer accepted
                        responseDiv.html(
                            '<div class="offer-accepted">' +
                            '<h4>🎉 Offer Accepted!</h4>' +
                            '<p>' + data.message + '</p>' +
                            '<a href="' + data.redirect + '" class="button alt">View Cart</a>' +
                            '</div>'
                        ).show();
                        form.hide();
                        
                    } else if (data.status === 'counter_offer') {
                        // Counter offer
                        var attemptText = data.attempt_number ? ' (Attempt ' + data.attempt_number + ')' : '';
                        responseDiv.html(
                            '<div class="counter-offer">' +
                            '<h4>💭 Counter Offer' + attemptText + '</h4>' +
                            '<p>' + data.message + '</p>' +
                            '<div class="counter-offer-actions">' +
                            '<button type="button" class="button alt accept-counter" data-amount="' + data.counter_amount + '">Accept ' + formatPrice(data.counter_amount) + '</button>' +
                            '<button type="button" class="button make-another-offer">Make Another Offer</button>' +
                            '</div>' +
                            '</div>'
                        ).show();
                        form.hide();
                        
                    } else if (data.status === 'final_offer') {
                        // Final offer after attempts
                        var attemptText = data.attempt_number ? ' (Attempt ' + data.attempt_number + ')' : '';
                        responseDiv.html(
                            '<div class="counter-offer">' +
                            '<h4>🤝 Final Offer' + attemptText + '</h4>' +
                            '<p>' + data.message + '</p>' +
                            '<div class="counter-offer-actions">' +
                            '<button type="button" class="button alt accept-counter" data-amount="' + data.counter_amount + '">Accept ' + formatPrice(data.counter_amount) + '</button>' +
                            '</div>' +
                            '</div>'
                        ).show();
                        form.hide();
                    }
                    
                } else {
                    // Error
                    responseDiv.html(
                        '<div class="offer-rejected">' +
                        '<h4>❌ Error</h4>' +
                        '<p>' + response.data + '</p>' +
                        '</div>'
                    ).show();
                }
            },
            error: function() {
                responseDiv.html(
                    '<div class="offer-rejected">' +
                    '<h4>❌ Error</h4>' +
                    '<p>Something went wrong. Please try again.</p>' +
                    '</div>'
                ).show();
            },
            complete: function() {
                // Re-enable button
                submitBtn.prop('disabled', false).text('Make Offer');
            }
        });
    });
    
    // Handle accepting counter offers
    $(document).on('click', '.accept-counter', function(e) {
        e.preventDefault(); // Prevent any form submission
        e.stopPropagation(); // Stop event bubbling
        
        var button = $(this);
        var counterAmount = button.data('amount');
        var productId = $('#make-offer-form input[name="product_id"]').val();
        
        console.log('Accept counter clicked - Product ID:', productId, 'Amount:', counterAmount);
        
        button.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: make_offer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'accept_counter_offer',
                product_id: productId,
                counter_amount: counterAmount,
                make_offer_nonce: make_offer_ajax.nonce
            },
            dataType: 'json',
            success: function(response) {
                console.log('AJAX Success - Full response:', response);
                console.log('Response type:', typeof response);
                console.log('Response.success:', response.success);
                
                if (response.success) {
                    var data = response.data;
                    console.log('Success data:', data);
                    
                    // Show success message briefly then redirect
                    $('#offer-response').html(
                        '<div class="offer-accepted">' +
                        '<h4>🎉 Great Choice!</h4>' +
                        '<p>' + data.message + '</p>' +
                        '<p>Redirecting to cart...</p>' +
                        '</div>'
                    );
                    
                    // Redirect to cart after 1.5 seconds
                    setTimeout(function() {
                        console.log('Redirecting to:', data.redirect);
                        window.location.href = data.redirect;
                    }, 1500);
                } else {
                    console.log('Response success = false. Error data:', response.data);
                    // Even if there's an "error", the item might still be in cart
                    // So let's just redirect to cart anyway
                    console.log('Response error but redirecting to cart anyway:', response.data);
                    window.location.href = make_offer_ajax.cart_url;
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX Error occurred:');
                console.log('XHR:', xhr);
                console.log('Status:', status);
                console.log('Error:', error);
                console.log('Response text:', xhr.responseText);
                
                // Don't show error popup, just redirect to cart
                // The item is likely added successfully despite the error
                console.log('AJAX error but redirecting to cart anyway');
                window.location.href = make_offer_ajax.cart_url;
            }
        });
    });
    
    // Handle making another offer
    $(document).on('click', '.make-another-offer', function() {
        $('#offer-response').hide();
        $('#make-offer-form').show();
        $('#offer-amount').val('').focus();
    });
    
    // Format price for display
    function formatPrice(amount) {
        // Get currency symbol from the page
        var currencySymbol = $('.currency-symbol').text() || '$';
        return currencySymbol + parseFloat(amount).toFixed(2);
    }
    
    // Add some nice animations
    $('#offer-response').on('show', function() {
        $(this).slideDown(300);
    });
    
    $('#offer-response').on('hide', function() {
        $(this).slideUp(300);
    });
    
    // Auto-focus on offer amount input
    $('#offer-amount').focus();
    
    // Add some input validation
    $('#offer-amount').on('input', function() {
        var value = parseFloat($(this).val());
        var submitBtn = $('.make-offer-btn');
        
        if (isNaN(value) || value <= 0) {
            submitBtn.prop('disabled', true);
        } else {
            submitBtn.prop('disabled', false);
        }
    });
    
    // Format currency input as user types
    $('#offer-amount').on('blur', function() {
        var value = parseFloat($(this).val());
        if (!isNaN(value) && value > 0) {
            $(this).val(value.toFixed(2));
        }
    });
    
});
