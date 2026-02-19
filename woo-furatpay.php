<?php
/**
 * Plugin Name: FuratPay for WooCommerce
 * Plugin URI: https://furatpay.com/plugins/woocommerce
 * Description: Integrates all popular Iraqi payment gateways with WooCommerce. Currently supports ZainCash, FIB, FastPay, and more.
 * Version: 1.0.0
 * Author: FuratPay
 * Author URI: https://furatpay.com/
 * Text Domain: woo_furatpay
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
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FuratPay: Plugin activated');
    }

    // Clear transients on activation
    delete_transient('wc_payment_methods');
    WC_Cache_Helper::get_transient_version('shipping', true);
    WC_Cache_Helper::get_transient_version('payment_gateways', true);
}

function furatpay_deactivate() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FuratPay: Plugin deactivated');
    }
}

function furatpay_activation_check() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('FuratPay requires WooCommerce to be installed and active.', 'woo_furatpay'),
            __('Plugin dependency error', 'woo_furatpay'),
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

    // Load plugin textdomain
    load_plugin_textdomain('woo_furatpay', false, dirname(plugin_basename(__FILE__)) . '/languages');
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

// Add admin notice checks
add_action('admin_notices', 'furatpay_admin_notices');
function furatpay_admin_notices() {
    $settings = get_option('woocommerce_furatpay_settings', []);
    
    if (empty($settings['enabled']) || 'yes' !== $settings['enabled']) {
        return;
    }
    
    $errors = [];
    
    if (empty($settings['api_url'])) {
        $errors[] = __('FuratPay API URL is required.', 'woo_furatpay');
    }
    
    if (empty($settings['api_key'])) {
        $errors[] = __('FuratPay JWT Token is required.', 'woo_furatpay');
    }
    
    if (!empty($errors)) {
        echo '<div class="error notice">';
        echo '<p><strong>' . __('FuratPay is misconfigured:', 'woo_furatpay') . '</strong></p>';
        echo '<ul style="list-style: inside; padding-left: 15px;">';
        foreach ($errors as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul>';
        echo '<p>' . sprintf(
            __('Please configure in %sWooCommerce Settings%s.', 'woo_furatpay'),
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=furatpay') . '">',
            '</a>'
        ) . '</p>';
        echo '</div>';
    }
}

