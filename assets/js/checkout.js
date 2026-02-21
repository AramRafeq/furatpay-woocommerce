jQuery(function($) {
    'use strict';

    // Create and show the payment waiting overlay
    function showPaymentWaitingOverlay() {
                const overlay = $('<div/>', {
            class: 'furatpay-payment-overlay',
            html: `
                <div class="furatpay-payment-status">
                    <h2>${furatpayData.i18n.waiting}</h2>
                    <p>${furatpayData.i18n.complete_payment}</p>
                    <div class="furatpay-spinner"></div>
                </div>
            `
        });
        $('body').append(overlay);
        $('body').addClass('furatpay-waiting');
    }

    // Remove the payment waiting overlay
    function removePaymentWaitingOverlay() {
                $('.furatpay-payment-overlay').remove();
        $('body').removeClass('furatpay-waiting');
    }

    // Function to open payment window with permission handling
    function openPaymentWindow(paymentUrl) {
                
        // First try to open a small test window to trigger permission request
        const testWindow = window.open('about:blank', '_blank', 'width=1,height=1');
        
        if (!testWindow) {
                        return false;
        }
        
        // Close the test window
        testWindow.close();
        
        // Now try to open the actual payment window
        const paymentWindow = window.open(paymentUrl, '_blank');
        
        if (!paymentWindow) {
                        return false;
        }
        
                
        // Focus the payment window
        try {
            paymentWindow.focus();
        } catch (e) {
            // Ignore focus errors
        }
        
        return true;
    }

    // Handle form submission for both classic and block checkout
    function handleCheckoutSubmit(e) {
                const selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (selectedPaymentMethod === 'furatpay') {
            const selectedService = $('input[name="furatpay_service"]:checked').val();

            if (!selectedService) {
                e.preventDefault();
                e.stopPropagation();
                $('.woocommerce-notices-wrapper').html(
                    `<div class="woocommerce-error">${furatpayData.i18n.selectService}</div>`
                );
                return false;
            }
            
            // For classic checkout, show overlay immediately
            if (!$('.wc-block-checkout').length) {
                showPaymentWaitingOverlay();
            }
        }
    }

    // Bind to classic checkout form
    $('form.woocommerce-checkout').on('submit', function(e) {
                return handleCheckoutSubmit(e);
    });

    // Bind to block checkout button
    $(document.body).on('click', '.wc-block-components-checkout-place-order-button', function(e) {
                return handleCheckoutSubmit(e);
    });

    // Bind to classic checkout button
    $(document.body).on('click', '#place_order', function(e) {
                return handleCheckoutSubmit(e);
    });

    // Listen for successful order creation
    $(document.body).on('checkout_error', function() {
                removePaymentWaitingOverlay();
    });

    // Handle the checkout response
    $(document).ajaxComplete(function(event, xhr, settings) {
        
        // Only process checkout-related AJAX responses
        if (!settings.url || (!settings.url.includes('?wc-ajax=checkout') && !settings.url.includes('/?wc-ajax=checkout'))) {
            return;
        }

        let response;
        try {
            response = JSON.parse(xhr.responseText);
        } catch (e) {
            return;
        }

        // Check if this is our payment method
        const isOurPayment = response.payment_method === 'furatpay' ||
                           (response.data && response.data.payment_method === 'furatpay');

        if (!isOurPayment) {
            return;
        }

        // Handle successful checkout
        if (response.result === 'success') {
            // Extract payment URL
            const paymentUrl = response.furatpay_payment_url ||
                             (response.data && response.data.furatpay_payment_url);

            if (!paymentUrl) {
                $('.woocommerce-notices-wrapper').html(`
                    <div class="woocommerce-error">
                        Payment URL not found. Please try again or contact support.
                    </div>
                `);
                return;
            }

            // Show waiting overlay if not already shown
            if (!$('.furatpay-payment-overlay').length) {
                showPaymentWaitingOverlay();
            }
                
            // Try to open the payment window
            const windowOpened = openPaymentWindow(paymentUrl);

            if (!windowOpened) {
                removePaymentWaitingOverlay();
                $('.woocommerce-notices-wrapper').html(`
                    <div class="woocommerce-notice">
                        <p>${furatpayData.i18n.popupBlocked}</p>
                        <p>
                            <a href="${paymentUrl}" 
                               target="_blank" 
                               class="button" 
                               onclick="window.furatpayShowOverlay()">
                                ${furatpayData.i18n.openPayment}
                            </a>
                        </p>
                    </div>
                `);
                
                window.furatpayShowOverlay = function() {
                    showPaymentWaitingOverlay();
                };
            }

            // Get order ID
            const orderId = response.order_id || (response.data && response.data.order_id);

            if (orderId) {
                pollPaymentStatus(orderId);
            }
        } else {
            removePaymentWaitingOverlay();
        }
    });

    // Poll for payment status
    function pollPaymentStatus(orderId) {
        const checkStatus = () => {
            
            $.ajax({
                url: furatpayData.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'furatpay_check_payment_status',
                    nonce: furatpayData.nonce,
                    order_id: orderId
                }
            })
            .done(function(response) {
                if (response.success) {
                    if (response.data.status === 'completed') {
                        window.location = response.data.redirect_url;
                    } else if (response.data.status === 'failed') {
                        removePaymentWaitingOverlay();
                        $('.woocommerce-notices-wrapper').html(
                            `<div class="woocommerce-error">${response.data.message}</div>`
                        );
                    } else {
                        setTimeout(checkStatus, 5000);
                    }
                }
            })
            .fail(function(error) {
                setTimeout(checkStatus, 5000);
            });
        };

        // Start polling
        checkStatus();
    }
}); 