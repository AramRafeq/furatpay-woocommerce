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
        $is_available = $this->gateway->is_available();

        return $is_available;
    }

    public function get_payment_method_script_handles() {
        $asset_path = FURATPAY_PLUGIN_PATH . 'build/blocks.asset.php';
        $version = file_exists($asset_path) ? include($asset_path) : ['version' => time()];

        wp_register_script(
            'furatpay-blocks',
            FURATPAY_PLUGIN_URL . 'build/blocks.js',
            array_merge(
                ['wc-blocks-registry', 'wp-element', 'wp-components', 'wp-html-entities', 'wp-i18n'],
                isset($version['dependencies']) ? $version['dependencies'] : []
            ),
            $version['version'],
            true
        );

        wp_localize_script('furatpay-blocks', 'furatpayData', [
            'title' => $this->gateway->get_option('title'),
            'description' => $this->gateway->get_option('description'),
            'icon' => apply_filters('furatpay_payment_icon', ''),
            'supports' => array('products'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('furatpay-nonce')
        ]);

        return ['furatpay-blocks'];
    }

    public function get_payment_method_data() {
        // Ensure gateway is available
        if (!$this->gateway->is_available()) {
            return [
                'title' => $this->gateway->get_option('title'),
                'description' => $this->gateway->get_option('description'),
                'supports' => ['products']
            ];
        }

        $data = [
            'title' => $this->gateway->get_option('title'),
            'description' => $this->gateway->get_option('description'),
            'supports' => $this->gateway->supports
        ];

        return $data;
    }
}

// Hook to validate and ensure furatpay is set as payment method
add_filter('woocommerce_store_api_validate_add_to_cart', function($errors, $request) {
    return $errors;
}, 10, 2);

// Hook into checkout processing BEFORE validation
add_action('woocommerce_store_api_checkout_order_processed', function($order) {
}, 10, 1);

// CRITICAL FIX: Hook into Store API validation to inject payment method
add_action('woocommerce_store_api_checkout_order_processed', function($order) {
    // If no payment method is set, check if we should use furatpay
    if (empty($order->get_payment_method())) {
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();

        if (isset($available_gateways['furatpay']) && count($available_gateways) === 1) {
            $order->set_payment_method('furatpay');
            $order->set_payment_method_title($available_gateways['furatpay']->get_title());
        }
    }
}, 5, 1);

// Hook into Store API to capture payment data before order is created
add_action('woocommerce_store_api_checkout_update_order_from_request', function($order, $request) {
    $payment_method = $order->get_payment_method();

    // If no payment method set but we have furatpay data OR it's the only gateway, set it
    if (empty($payment_method)) {
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();

        if (isset($available_gateways['furatpay'])) {
            $payment_data = $request->get_param('payment_data');
            $should_set_furatpay = false;

            // Check if we have furatpay data
            if (is_array($payment_data)) {
                foreach ($payment_data as $item) {
                    if (isset($item['key']) && ($item['key'] === 'furatpay_service' || strpos($item['key'], 'furatpay') !== false)) {
                        $should_set_furatpay = true;
                        break;
                    }
                }
            }

            // Or if it's the only available gateway
            if (!$should_set_furatpay && count($available_gateways) === 1) {
                $should_set_furatpay = true;
            }

            if ($should_set_furatpay) {
                $order->set_payment_method('furatpay');
                $order->set_payment_method_title($available_gateways['furatpay']->get_title());
                $payment_method = 'furatpay';
            }
        }
    }

    if ('furatpay' !== $payment_method) {
        return;
    }

    // Get extension data from request
    $extensions = $request->get_param('extensions');

    // Check if our furatpay extension data exists
    if (!empty($extensions) && isset($extensions['furatpay'])) {
        $furatpay_data = $extensions['furatpay'];

        if (isset($furatpay_data['furatpay_service'])) {
            $service_id = intval($furatpay_data['furatpay_service']);
            $order->update_meta_data('_furatpay_service_id', $service_id);
            $order->save();

            if (isset($furatpay_data['furatpay_service_name'])) {
                $order->update_meta_data('_furatpay_service_name', sanitize_text_field($furatpay_data['furatpay_service_name']));
                $order->save();
            }
        }
    }
}, 10, 2);

add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
    $registry->register(new FuratPay_Blocks());
});

// Critical: Hook into checkout processing to inject payment method
add_action('woocommerce_rest_checkout_process_payment_with_context', function($context, $result) {
}, 10, 2);

// Hook into the REST API request EARLY to inject payment method
add_filter('rest_pre_echo_response', function($result, $server, $request) {
    return $result;
}, 10, 3);

// Most direct approach: Hook into the request parsing itself
add_action('rest_api_init', function() {
    // Add a custom REST route handler for Store API
    add_filter('rest_request_after_callbacks', function($response, $handler, $request) {
        $route = $request->get_route();

        if (strpos($route, 'wc/store') === false) {
            return $response;
        }

        return $response;
    }, 10, 3);

    // Hook to modify request parameters before validation
    add_filter('rest_post_dispatch', function($result, $server, $request) {
        return $result;
    }, 10, 3);
}, 1);

// Ensure the gateway is available for Store API requests
add_filter('woocommerce_payment_gateways', function($gateways) {
    return $gateways;
}, 999);