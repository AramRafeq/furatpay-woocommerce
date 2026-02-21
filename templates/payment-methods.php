<?php
/**
 * FuratPay payment methods template
 */

defined('ABSPATH') || exit;

?>
<div class="furatpay-payment-methods" id="furatpay-payment-form">
    <?php
    // Filter out disabled payment methods
    $furatpay_active_services = array_filter($payment_services, function($furatpay_service) {
        return isset($furatpay_service['status']) && $furatpay_service['status'] === 'active';
    });

    if (empty($furatpay_active_services)): ?>
        <p><?php esc_html_e('No payment methods available.', 'furatpay'); ?></p>
    <?php else: ?>
        <!-- Hidden input for WooCommerce -->
        <input type="hidden" name="payment_method" value="furatpay">
        <input type="hidden" name="wc-furatpay-payment-token" value="new">

        <ul class="furatpay-method-list">
            <?php foreach ($furatpay_active_services as $furatpay_service): ?>
                <li class="furatpay-method-item">
                    <label>
                        <?php
                        $furatpay_is_checked = false;
                        if (isset($_POST['furatpay_service'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                            $furatpay_is_checked = (int) $_POST['furatpay_service'] === $furatpay_service['id']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                        }
                        ?>
                        <input
                            type="radio"
                            name="furatpay_service"
                            value="<?php echo esc_attr($furatpay_service['id']); ?>"
                            <?php checked($furatpay_is_checked); ?>
                            required="required"
                            class="furatpay-service-radio"
                            data-service-id="<?php echo esc_attr($furatpay_service['id']); ?>"
                            data-service-name="<?php echo esc_attr($furatpay_service['name']); ?>"
                        >
                        <?php if (!empty($furatpay_service['logo'])): ?>
                            <img
                                src="<?php echo esc_url($furatpay_service['logo']); ?>"
                                alt="<?php echo esc_attr($furatpay_service['name']); ?>"
                                class="furatpay-method-logo"
                            >
                        <?php endif; ?>
                        <span class="furatpay-method-name">
                            <?php echo esc_html($furatpay_service['name']); ?>
                        </span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<script type="text/javascript">
jQuery(function($) {
    var $form = $('#furatpay-payment-form');
    var $radios = $('.furatpay-service-radio');
    
    // Handle radio button changes
    $radios.on('change', function() {
        var $selected = $(this);
        var serviceId = $selected.data('service-id');
        var serviceName = $selected.data('service-name');
        
        // Update hidden fields
        $form.find('input[name="furatpay_service"]').val(serviceId);
        
        // Trigger WooCommerce events
        $('body').trigger('payment_method_selected');
        $(document.body).trigger('update_checkout');
    });
    
    // Handle form submission
    $(document.body).on('checkout_place_order_furatpay', function() {
        var selectedService = $radios.filter(':checked').val();
        if (!selectedService) {
            alert('<?php echo esc_js(__('Please select a payment service.', 'furatpay')); ?>');
            return false;
        }
        return true;
    });
});</script>