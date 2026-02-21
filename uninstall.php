<?php
/**
 * FuratPay Uninstall
 *
 * Uninstalling FuratPay deletes plugin options and cleans up data.
 *
 * @package FuratPay
 */

// Exit if accessed directly or not uninstalling
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('woocommerce_furatpay_settings');

// Delete any transients we've created
global $wpdb;

// Delete FuratPay transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_furatpay_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_furatpay_%'");

// Delete payment cache transients
delete_transient('wc_payment_methods');

// Clean up order meta data (optional - uncomment if you want to remove all FuratPay order meta)
// $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_furatpay_%'");

// Clear any cached data
wp_cache_flush();
