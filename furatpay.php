<?php
/**
 * Plugin Name: FuratPay for WooCommerce
 * Plugin URI: https://furatpay.com/plugins/woocommerce
 * Description: Integrates all popular Iraqi payment gateways with WooCommerce. Currently supports ZainCash, FIB, FastPay, and more.
 * Version: 1.0.0
 * Author: FuratPay
 * Author URI: https://furatpay.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: furatpay
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit; // Prevent direct access

// Define constants
define('FURATPAY_VERSION', '1.0.0');
define('FURATPAY_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FURATPAY_PLUGIN_URL', plugin_dir_url(__FILE__));

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'furatpay_activate');
register_deactivation_hook(__FILE__, 'furatpay_deactivate');
register_activation_hook(__FILE__, 'furatpay_activation_check');

// Allow both classic and block checkout - blocks integration is available

function furatpay_activate() {
    // Clear transients on activation
    delete_transient('wc_payment_methods');
    WC_Cache_Helper::get_transient_version('shipping', true);
    WC_Cache_Helper::get_transient_version('payment_gateways', true);
}

function furatpay_deactivate() {
    // Cleanup on deactivation
}

function furatpay_activation_check() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('FuratPay requires WooCommerce to be installed and active.', 'furatpay'),
            esc_html__('Plugin dependency error', 'furatpay'),
            ['back_link' => true]
        );
    }
}

// Initialize the gateway
add_action('plugins_loaded', 'furatpay_init', 0);

function furatpay_init() {
    // Check if WooCommerce is active
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    // Load required files
    require_once FURATPAY_PLUGIN_PATH . 'includes/class-furatpay-api-handler.php';
    require_once FURATPAY_PLUGIN_PATH . 'includes/class-furatpay-ipn-handler.php';
    require_once FURATPAY_PLUGIN_PATH . 'includes/class-furatpay-gateway.php';

    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'furatpay_add_gateway');

    // Register AJAX handlers
    add_action('wp_ajax_furatpay_get_payment_services', 'furatpay_ajax_get_payment_services');
    add_action('wp_ajax_nopriv_furatpay_get_payment_services', 'furatpay_ajax_get_payment_services');
    add_action('wp_ajax_furatpay_check_payment_status', 'furatpay_ajax_check_payment_status');
    add_action('wp_ajax_nopriv_furatpay_check_payment_status', 'furatpay_ajax_check_payment_status');
}

function furatpay_add_gateway($gateways) {
    if (!class_exists('FuratPay_Gateway')) {
        return $gateways;
    }

    if (!in_array('FuratPay_Gateway', $gateways)) {
        $gateways[] = 'FuratPay_Gateway';
    }

    return $gateways;
}

/**
 * AJAX handler to get payment services
 */
function furatpay_ajax_get_payment_services() {
    check_ajax_referer('furatpay-nonce', 'nonce');

    try {
        // Get gateway instance
        $gateways = WC()->payment_gateways->payment_gateways();
        if (!isset($gateways['furatpay'])) {
            throw new Exception(esc_html__('Payment gateway not found', 'furatpay'));
        }

        $gateway = $gateways['furatpay'];
        $api_url = $gateway->get_option('api_url');
        $api_key = $gateway->get_option('api_key');

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug('AJAX: Getting payment services. API URL: ' . $api_url, array('source' => 'furatpay'));
        }

        $services = FuratPay_API_Handler::get_payment_services($api_url, $api_key);

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug('AJAX: Found ' . count($services) . ' payment services', array('source' => 'furatpay'));
        }

        wp_send_json_success($services);
    } catch (Exception $e) {
        // Log the error
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->error('AJAX Error: ' . $e->getMessage(), array('source' => 'furatpay'));
        }

        wp_send_json_error(array(
            'message' => $e->getMessage()
        ), 400);
    }
}

/**
 * AJAX handler to check payment status
 */
function furatpay_ajax_check_payment_status() {
    check_ajax_referer('furatpay-nonce', 'nonce');

    try {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            throw new Exception(esc_html__('Invalid order ID', 'furatpay'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception(esc_html__('Order not found', 'furatpay'));
        }

        // Get gateway instance
        $gateways = WC()->payment_gateways->payment_gateways();
        if (!isset($gateways['furatpay'])) {
            throw new Exception(esc_html__('Payment gateway not found', 'furatpay'));
        }

        $gateway = $gateways['furatpay'];
        $api_url = $gateway->get_option('api_url');
        $api_key = $gateway->get_option('api_key');

        $invoice_id = $order->get_meta('_furatpay_invoice_id');
        if (!$invoice_id) {
            throw new Exception(esc_html__('Invoice ID not found', 'furatpay'));
        }

        $status = FuratPay_API_Handler::check_payment_status($api_url, $api_key, $invoice_id);

        // Map status to response
        if ($status === 'paid') {
            $order->payment_complete();
            wp_send_json_success(array(
                'status' => 'completed',
                'redirect_url' => $gateway->get_return_url($order)
            ));
        } elseif ($status === 'failed') {
            wp_send_json_success(array(
                'status' => 'failed',
                'message' => esc_html__('Payment failed', 'furatpay')
            ));
        } else {
            wp_send_json_success(array(
                'status' => 'pending'
            ));
        }
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
}

// Add admin notice checks
add_action('admin_notices', 'furatpay_admin_notices');
function furatpay_admin_notices() {
    $settings = get_option('woocommerce_furatpay_settings', []);
    
    if (empty($settings['enabled']) || 'yes' !== $settings['enabled']) {
        return;
    }
    
    $errors = [];
    
    if (empty($settings['api_url'])) {
        $errors[] = __('FuratPay API URL is required.', 'furatpay');
    }
    
    if (empty($settings['api_key'])) {
        $errors[] = __('FuratPay JWT Token is required.', 'furatpay');
    }
    
    if (!empty($errors)) {
        echo '<div class="error notice">';
        echo '<p><strong>' . esc_html__('FuratPay is misconfigured:', 'furatpay') . '</strong></p>';
        echo '<ul style="list-style: inside; padding-left: 15px;">';
        foreach ($errors as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul>';
        echo '<p>';
        printf(
            /* translators: %1$s: opening anchor tag, %2$s: closing anchor tag */
            esc_html__('Please configure in %1$sWooCommerce Settings%2$s.', 'furatpay'),
            '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=furatpay')) . '">',
            '</a>'
        );
        echo '</p>';
        echo '</div>';
    }
}

