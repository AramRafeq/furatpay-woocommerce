<?php
defined('ABSPATH') || exit;

use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

/**
 * Extend WooCommerce Store API to accept FuratPay payment data
 */
class FuratPay_Store_API_Extension {

    public function __construct() {
        add_action('woocommerce_blocks_loaded', array($this, 'register_extension'));
    }

    public function register_extension() {
        if (!class_exists('\Automattic\WooCommerce\StoreApi\StoreApi') || !class_exists('\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema')) {
            return;
        }

        try {
            $extend = StoreApi::container()->get(ExtendSchema::class);

            // Register extension data
            $extend->register_endpoint_data(
                array(
                    'endpoint' => CheckoutSchema::IDENTIFIER,
                    'namespace' => 'furatpay',
                    'data_callback' => array($this, 'data_callback'),
                    'schema_callback' => array($this, 'schema_callback'),
                    'schema_type' => ARRAY_A,
                )
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FuratPay: Store API extension registered successfully');
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FuratPay: Failed to register Store API extension: ' . $e->getMessage());
            }
        }
    }

    /**
     * Define the schema for our custom data
     */
    public function schema_callback() {
        return array(
            'furatpay_service' => array(
                'description' => __('Selected payment service ID', 'furatpay'),
                'type' => array('string', 'null'),
                'readonly' => false,
            ),
            'furatpay_service_name' => array(
                'description' => __('Selected payment service name', 'furatpay'),
                'type' => array('string', 'null'),
                'readonly' => false,
            ),
        );
    }

    /**
     * Get data callback - retrieve extension data from request and save to order
     */
    public function data_callback($data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FuratPay: Store API data_callback called with data: ' . print_r($data, true));
        }

        // Data will be available during checkout processing
        // We'll use the woocommerce_store_api_checkout_update_order_from_request hook to save it
        return array(
            'furatpay_service' => '',
            'furatpay_service_name' => '',
        );
    }
}

// Initialize the extension
new FuratPay_Store_API_Extension();
