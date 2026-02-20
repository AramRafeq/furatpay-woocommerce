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

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug('Blocks: is_active called - returning: ' . ($is_available ? 'true' : 'false'), array('source' => 'furatpay'));
            $logger->debug('Blocks: Gateway settings - API URL: ' . $this->gateway->get_option('api_url') . ', API Key: ' . (empty($this->gateway->get_option('api_key')) ? 'empty' : 'set'), array('source' => 'furatpay'));
        }

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

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug('Blocks: get_payment_method_data called: ' . print_r($data, true), array('source' => 'furatpay'));
        }

        return $data;
    }
}

// Hook to validate and ensure furatpay is set as payment method
add_filter('woocommerce_store_api_validate_add_to_cart', function($errors, $request) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $logger->debug('Store API: validate_add_to_cart hook called', array('source' => 'furatpay'));
    }
    return $errors;
}, 10, 2);

// Hook into checkout processing BEFORE validation
add_action('woocommerce_store_api_checkout_order_processed', function($order) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $logger->debug('Store API: Order processed - Payment method: ' . $order->get_payment_method(), array('source' => 'furatpay'));
    }
}, 10, 1);

// CRITICAL FIX: Hook into Store API validation to inject payment method
add_action('woocommerce_store_api_checkout_order_processed', function($order) {
    // If no payment method is set, check if we should use furatpay
    if (empty($order->get_payment_method())) {
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();

        if (isset($available_gateways['furatpay']) && count($available_gateways) === 1) {
            $order->set_payment_method('furatpay');
            $order->set_payment_method_title($available_gateways['furatpay']->get_title());

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $logger = wc_get_logger();
                $logger->debug('Store API: Auto-set payment method to furatpay for order', array('source' => 'furatpay'));
            }
        }
    }
}, 5, 1);

// Hook to log when Store API hooks are called
add_action('woocommerce_store_api_checkout_update_order_meta', function($order) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FuratPay: Hook checkout_update_order_meta called');
    }
}, 10, 1);

add_action('woocommerce_rest_checkout_process_payment_with_context', function($context, $result) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FuratPay: Hook process_payment_with_context called - payment method: ' . (isset($context->payment_method) ? $context->payment_method : 'NOT SET'));
    }
}, 10, 2);

// Hook into Store API to capture payment data before order is created
add_action('woocommerce_store_api_checkout_update_order_from_request', function($order, $request) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FuratPay: Hook update_order_from_request CALLED');
    }

    // Debug log the entire request
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $params = $request->get_params();
        $logger->debug('Store API: Full request params: ' . print_r($params, true), array('source' => 'furatpay'));
        $logger->debug('Store API: Payment method from request: ' . (isset($params['payment_method']) ? $params['payment_method'] : 'NOT SET'), array('source' => 'furatpay'));
        error_log('FuratPay: Order payment method: ' . $order->get_payment_method());
    }

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

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $logger = wc_get_logger();
                    $logger->debug('Store API: Force set payment method to furatpay', array('source' => 'furatpay'));
                }
            }
        }
    }

    if ('furatpay' !== $payment_method) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug('Store API: Payment method is not furatpay, it is: ' . $payment_method, array('source' => 'furatpay'));
        }
        return;
    }

    // Get extension data from request
    $extensions = $request->get_param('extensions');

    // Debug log
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $logger->debug('Blocks: Full extensions data: ' . print_r($extensions, true), array('source' => 'furatpay'));
    }

    // Check if our furatpay extension data exists
    if (!empty($extensions) && isset($extensions['furatpay'])) {
        $furatpay_data = $extensions['furatpay'];

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug('Blocks: FuratPay extension data: ' . print_r($furatpay_data, true), array('source' => 'furatpay'));
        }

        if (isset($furatpay_data['furatpay_service'])) {
            $service_id = intval($furatpay_data['furatpay_service']);
            $order->update_meta_data('_furatpay_service_id', $service_id);
            $order->save();

            if (isset($furatpay_data['furatpay_service_name'])) {
                $order->update_meta_data('_furatpay_service_name', sanitize_text_field($furatpay_data['furatpay_service_name']));
                $order->save();
            }

            // Debug log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $logger = wc_get_logger();
                $logger->debug('Blocks: Stored service ID ' . $service_id . ' from extensions in order meta', array('source' => 'furatpay'));
            }
        }
    } else {
        // If no extension data found, log error
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug('Blocks: NO furatpay extension data found in request!', array('source' => 'furatpay'));
        }
    }
}, 10, 2);

add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $logger->debug('Blocks: Registering FuratPay_Blocks with registry', array('source' => 'furatpay'));
    }
    $registry->register(new FuratPay_Blocks());
});

// Critical: Hook into checkout processing to inject payment method
add_action('woocommerce_rest_checkout_process_payment_with_context', function($context, $result) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $logger->debug('REST: Process payment with context called', array('source' => 'furatpay'));
        $logger->debug('REST: Payment method from context: ' . (isset($context->payment_method) ? $context->payment_method : 'NOT SET'), array('source' => 'furatpay'));
    }
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

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug('REST: After callbacks - route: ' . $route, array('source' => 'furatpay'));
            if ($response instanceof WP_Error) {
                $logger->debug('REST: Response is WP_Error: ' . $response->get_error_message(), array('source' => 'furatpay'));
            }
        }

        return $response;
    }, 10, 3);

    // Hook to modify request parameters before validation
    add_filter('rest_post_dispatch', function($result, $server, $request) {
        if (defined('WP_DEBUG') && WP_DEBUG && strpos($request->get_route(), 'checkout') !== false) {
            $logger = wc_get_logger();
            $logger->debug('REST: Post dispatch for checkout', array('source' => 'furatpay'));
        }
        return $result;
    }, 10, 3);
}, 1);

// Ensure the gateway is available for Store API requests
add_filter('woocommerce_payment_gateways', function($gateways) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $logger->debug('Blocks: woocommerce_payment_gateways filter called with ' . count($gateways) . ' gateways', array('source' => 'furatpay'));
    }
    return $gateways;
}, 999);

// Debug: Log when Store API validates payment method
add_action('woocommerce_rest_checkout_process_payment_with_context', function($context, $result) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $logger->debug('Blocks: Store API processing payment - Context: ' . print_r($context, true), array('source' => 'furatpay'));
        $logger->debug('Blocks: Store API processing payment - Result: ' . print_r($result, true), array('source' => 'furatpay'));
    }
}, 10, 2);

// Debug: Log available payment gateways during REST request
add_action('rest_api_init', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $logger = wc_get_logger();
        $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $gateway_ids = array_keys($available_gateways);
        $logger->debug('Blocks: REST API init - Available gateways: ' . implode(', ', $gateway_ids), array('source' => 'furatpay'));

        if (isset($available_gateways['furatpay'])) {
            $logger->debug('Blocks: FuratPay gateway IS available', array('source' => 'furatpay'));
        } else {
            $logger->debug('Blocks: FuratPay gateway IS NOT available', array('source' => 'furatpay'));
        }
    }
});