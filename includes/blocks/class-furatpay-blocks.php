<?php
defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class FuratPay_Blocks extends AbstractPaymentMethodType {
    private $gateway;
    
    protected $name = 'furatpay';

    public function initialize() {
        $this->gateway = new FuratPay_Gateway();
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        $asset_path = FURATPAY_PLUGIN_PATH . 'build/blocks.asset.php';
        $version = file_exists($asset_path) ? include($asset_path) : ['version' => time()];

        wp_register_script(
            'furatpay-blocks',
            FURATPAY_PLUGIN_URL . 'build/blocks.js',
            ['wc-blocks-registry', 'wp-element', 'wp-components', 'wp-html-entities', 'wp-i18n'],
            $version['version'],
            true
        );

        wp_localize_script('furatpay-blocks', 'furatpayData', [
            'title' => $this->gateway->get_option('title'),
            'description' => $this->gateway->get_option('description'),
            'icon' => apply_filters('furatpay_payment_icon', ''),
            'supports' => $this->gateway->supports,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('furatpay-nonce')
        ]);

        return ['furatpay-blocks'];
    }

    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->get_option('title'),
            'description' => $this->gateway->get_option('description'),
            'supports' => $this->gateway->supports,
            'showSavedCards' => false,
            'canMakePayment' => true,
            'icons' => [
                'id' => $this->gateway->id,
                'src' => FURATPAY_PLUGIN_URL . 'assets/images/icon.png',
                'alt' => 'FuratPay'
            ]
        ];
    }
}

// Hook into Store API to capture payment data before order is created
add_action('woocommerce_store_api_checkout_update_order_from_request', function($order, $request) {
    $payment_method = $order->get_payment_method();

    if ('furatpay' !== $payment_method) {
        return;
    }

    // Get payment data from request
    $payment_data_raw = $request->get_param('payment_data');

    // Debug log
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $logger->debug('Blocks: Raw payment data: ' . print_r($payment_data_raw, true), array('source' => 'furatpay'));
    }

    // Convert array of key/value objects to associative array
    $payment_data = array();
    if (!empty($payment_data_raw) && is_array($payment_data_raw)) {
        foreach ($payment_data_raw as $item) {
            if (isset($item['key']) && isset($item['value'])) {
                $payment_data[$item['key']] = $item['value'];
            }
        }
    }

    // Debug log
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $logger->debug('Blocks: Parsed payment data: ' . print_r($payment_data, true), array('source' => 'furatpay'));
    }

    if (!empty($payment_data) && isset($payment_data['furatpay_service'])) {
        $service_id = intval($payment_data['furatpay_service']);
        $order->update_meta_data('_furatpay_service_id', $service_id);

        if (isset($payment_data['furatpay_service_name'])) {
            $order->update_meta_data('_furatpay_service_name', sanitize_text_field($payment_data['furatpay_service_name']));
        }

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug('Blocks: Stored service ID ' . $service_id . ' in order meta', array('source' => 'furatpay'));
        }
    }
}, 10, 2);

add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
    $registry->register(new FuratPay_Blocks());
});