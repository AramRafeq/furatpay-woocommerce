<?php
defined('ABSPATH') || exit;

class FuratPay_Gateway extends WC_Payment_Gateway
{
    /**
     * @var string
     */
    protected $api_url;

    /**
     * @var string
     */
    protected $api_key;

    /**
     * @var string
     */
    protected $webhook_secret;

    public function __construct()
    {
        // Basic gateway setup
        $this->id = 'furatpay';
        $this->has_fields = true;
        $this->method_title = __('FuratPay', 'furatpay');
        $this->method_description = __('Accept payments through FuratPay payment gateway', 'furatpay');
        $this->supports = array('products');

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings values
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_url = $this->get_option('api_url');
        $this->api_key = $this->get_option('api_key');
        $this->webhook_secret = $this->get_option('webhook_secret');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));
        
        // Add payment form display hooks
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_review_order_before_payment', array($this, 'payment_fields_before'));
        add_action('woocommerce_review_order_after_payment', array($this, 'payment_fields_after'));
        
        add_action('init', function() {
            if (class_exists('FuratPay_IPN_Handler')) {
                new FuratPay_IPN_Handler(
                    $this->api_url, 
                    $this->api_key,
                    $this->webhook_secret
                );
            }
        });

        // Blocks integration is now loaded from furatpay.php to ensure proper timing

        // Add AJAX endpoints
        add_action('wp_ajax_furatpay_get_payment_services', array($this, 'ajax_get_payment_services'));
        add_action('wp_ajax_nopriv_furatpay_get_payment_services', array($this, 'ajax_get_payment_services'));
        add_action('wp_ajax_furatpay_initiate_payment', array($this, 'ajax_initiate_payment'));
        add_action('wp_ajax_nopriv_furatpay_initiate_payment', array($this, 'ajax_initiate_payment'));
        add_action('wp_ajax_furatpay_check_payment_status', array($this, 'check_payment_status'));
        add_action('wp_ajax_nopriv_furatpay_check_payment_status', array($this, 'check_payment_status'));

        // Debug hooks
        add_action('woocommerce_checkout_before_customer_details', array($this, 'debug_before_customer_details'));
        add_action('woocommerce_checkout_after_customer_details', array($this, 'debug_after_customer_details'));
        add_action('woocommerce_checkout_before_order_review', array($this, 'debug_before_order_review'));
        add_action('woocommerce_checkout_after_order_review', array($this, 'debug_after_order_review'));
        add_action('woocommerce_payment_methods_list', array($this, 'debug_payment_methods_list'), 10, 2);

        // Add API endpoint
        add_action('woocommerce_api_furatpay_pay', array($this, 'handle_payment_page'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'furatpay'),
                'type' => 'checkbox',
                'label' => __('Enable FuratPay', 'furatpay'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'furatpay'),
                'type' => 'text',
                'description' => __('Payment method title', 'furatpay'),
                'default' => __('FuratPay', 'furatpay'),
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description', 'furatpay'),
                'type' => 'textarea',
                'description' => __('Payment method description', 'furatpay'),
                'default' => __('Pay via FuratPay', 'furatpay'),
                'desc_tip' => true
            ),
            'api_url' => array(
                'title' => __('API URL', 'furatpay'),
                'type' => 'text',
                'description' => __('FuratPay API endpoint URL', 'furatpay'),
                'default' => '',
                'placeholder' => 'https://api.furatpay.com/v1'
            ),
            'api_key' => array(
                'title' => __('FuratPay API Key', 'furatpay'),
                'type' => 'password',
                'description' => __('Your FuratPay API key', 'furatpay'),
                'default' => ''
            ),
            'webhook_secret' => array(
                'title' => __('Webhook Secret', 'furatpay'),
                'type' => 'password',
                'description' => __('Your FuratPay webhook secret key for verifying webhook signatures', 'furatpay'),
                'default' => '',
                'desc_tip' => true
            )
        );
    }

    public function process_admin_options()
    {
        $result = parent::process_admin_options();

        // Get settings after save
        $this->init_settings();
        $saved_settings = $this->settings;

        // Make sure we have the latest values
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_url = $this->get_option('api_url');
        $this->api_key = $this->get_option('api_key');
        $this->webhook_secret = $this->get_option('webhook_secret');

        return $result;
    }

    public function debug_before_customer_details() {
    }

    public function debug_after_customer_details() {
    }

    public function debug_before_order_review() {
    }

    public function debug_after_order_review() {
    }

    public function debug_payment_methods_list($list, $order_id = null) {
        $gateway_ids = array_keys($list);
        return $list;
    }

    public function payment_fields_before() {
        echo '<div id="furatpay-payment-form-wrapper">';
    }

    public function payment_fields_after() {
        echo '</div>';
    }

    public function payment_fields()
    {
        try {
            echo '<div class="furatpay-payment-form">';
            
            if ($description = $this->get_description()) {
                echo wp_kses_post(wpautop(wptexturize($description)));
            }

            if (empty($this->api_url) || empty($this->api_key)) {
                throw new Exception(__('Payment method is not properly configured.', 'furatpay'));
            }

            $payment_services = FuratPay_API_Handler::get_payment_services($this->api_url, $this->api_key);
            
            if (empty($payment_services)) {
                throw new Exception(__('No payment methods available.', 'furatpay'));
            }

            // Filter active services
            $active_services = $payment_services;

            if (empty($active_services)) {
                throw new Exception(__('No active payment methods available.', 'furatpay'));
            }

            echo '<div class="furatpay-services-wrapper">';
            echo '<h4>' . esc_html__('Select a payment service:', 'furatpay') . '</h4>';
            echo '<ul class="furatpay-method-list">';
            
            foreach ($active_services as $service) {
                echo '<li class="furatpay-method-item">';
                echo '<label>';
                $is_checked = false;
                if (isset($_POST['furatpay_service'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    $is_checked = (int) $_POST['furatpay_service'] === $service['id']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
                }
                echo '<input
                    type="radio"
                    name="furatpay_service"
                    value="' . esc_attr($service['id']) . '"
                    ' . checked($is_checked, true, false) . '
                    required="required"
                    class="furatpay-service-radio"
                />';
                
                if (!empty($service['logo'])) {
                    echo '<img 
                        src="' . esc_url($service['logo']) . '" 
                        alt="' . esc_attr($service['name']) . '"
                        class="furatpay-method-logo"
                    />';
                }
                
                echo '<span class="furatpay-method-name">' . esc_html($service['name']) . '</span>';
                echo '</label>';
                echo '</li>';
            }
            
            echo '</ul>';
            echo '</div>';
            
            ?>
            <script type="text/javascript">
            jQuery(function($) {
                $('.furatpay-service-radio').on('change', function() {
                    $('body').trigger('update_checkout');
                });
            });
            </script>
            <?php
            
            echo '</div>';
            
        } catch (Exception $e) {
            echo '<div class="woocommerce-error">' . esc_html($e->getMessage()) . '</div>';
        }
    }

    public function enqueue_checkout_scripts()
    {
        if (!is_checkout() && !has_block('woocommerce/checkout')) {
            return;
        }

        $version = FURATPAY_VERSION;

        wp_enqueue_script('jquery');

        wp_enqueue_script(
            'furatpay-checkout',
            FURATPAY_PLUGIN_URL . 'assets/js/checkout.js',
            array('jquery', 'wc-checkout'),
            $version,
            true
        );

        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('furatpay-nonce'),
            'title' => $this->title,
            'description' => $this->description,
            'icon' => apply_filters('furatpay_payment_icon', ''),
            'supports' => $this->supports,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'i18n' => array(
                'processing' => __('Processing payment, please wait...', 'furatpay'),
                'redirect' => __('Redirecting to payment service...', 'furatpay'),
                'waiting' => __('Waiting for Payment', 'furatpay'),
                'complete_payment' => __('Payment window has been opened in a new tab. Please complete your payment there. This page will update automatically once payment is confirmed.', 'furatpay'),
                'selectService' => __('Please select a payment service.', 'furatpay'),
                'noServices' => __('No payment services available.', 'furatpay'),
                'popupBlocked' => __('Popup was blocked. Please click the button below to open the payment window:', 'furatpay'),
                'openPayment' => __('Open Payment Window', 'furatpay')
            )
        );

        wp_localize_script('furatpay-checkout', 'furatpayData', $script_data);

        wp_enqueue_style(
            'furatpay-checkout',
            FURATPAY_PLUGIN_URL . 'assets/css/checkout.css',
            array(),
            $version
        );
    }

    /**
     * Check if this gateway is available for use
     *
     * @return bool
     */
    public function is_available()
    {
        $parent_available = parent::is_available();
        $has_api_url = !empty($this->api_url);
        $has_api_key = !empty($this->api_key);

        $is_available = $parent_available && $has_api_url && $has_api_key;

        return $is_available;
    }

    /**
     * Process the payment
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id)
    {
        try {
            
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found', 'furatpay'));
            }

            // Get selected payment service
            $payment_service_id = 0;

            // For classic checkout
            if (isset($_POST['furatpay_service'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $payment_service_id = intval($_POST['furatpay_service']); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }

            // For blocks checkout - check multiple possible locations
            if (!$payment_service_id) {
                // Check if payment_data is available (WooCommerce Blocks Store API)
                $payment_data = null;

                // Try to get from order meta (set by blocks)
                $payment_service_id = intval($order->get_meta('_furatpay_service_id'));

                // If not in meta, try POST data variations
                if (!$payment_service_id && isset($_POST['payment_data'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    $payment_data = is_array($_POST['payment_data']) ? $_POST['payment_data'] : json_decode(wp_unslash($_POST['payment_data']), true); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
                }

                if (!$payment_service_id && isset($_POST['payment_method_data'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    $payment_data = json_decode(wp_unslash($_POST['payment_method_data']), true); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                }

                if ($payment_data && isset($payment_data['furatpay_service'])) {
                    $payment_service_id = intval($payment_data['furatpay_service']);
                }
            }

            if (!$payment_service_id) {
                throw new Exception(__('Please select a payment service', 'furatpay'));
            }

            // Store service ID in order meta for reference
            $order->update_meta_data('_furatpay_service_id', $payment_service_id);
            $order->save();

            // Create invoice - always use customer data from order
            $invoice_id = FuratPay_API_Handler::create_invoice(
                $this->api_url,
                $this->api_key,
                $order,
                null
            );

            // Create payment URL
            $payment_url = FuratPay_API_Handler::create_payment(
                $this->api_url,
                $this->api_key,
                $invoice_id,
                $payment_service_id
            );

            // Store payment details in order meta
            $order->update_meta_data('_furatpay_payment_url', $payment_url);
            $order->update_meta_data('_furatpay_payment_service_id', $payment_service_id);
            $order->update_meta_data('_furatpay_invoice_id', $invoice_id);
            
            // Update order status to pending
            $order->update_status('pending', __('Awaiting FuratPay payment confirmation', 'furatpay'));
            $order->save();

            // Empty cart
            WC()->cart->empty_cart();

            // Return success with payment URL and status page URL
            return array(
                'result' => 'success',
                'redirect' => add_query_arg(
                    array(
                        'order_id' => $order->get_id(),
                        'key' => $order->get_order_key(),
                    ),
                    WC()->api_request_url('furatpay_pay')
                ),
                'messages' => '<script type="text/javascript">
                    (function($) {
                        var paymentWindow = window.open("' . esc_js($payment_url) . '", "FuratPayment");
                        if (!paymentWindow || paymentWindow.closed) {
                            // If popup is blocked, we\'ll handle it on the status page
                            return;
                        }
                        paymentWindow.focus();
                    })(jQuery);
                </script>'
            );

        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }

    /**
     * Handle the payment page
     */
    public function handle_payment_page() {
        if (!isset($_GET['order_id']) || !isset($_GET['key'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_die(esc_html__('Invalid payment request', 'furatpay'));
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_die(esc_html__('Invalid order', 'furatpay'));
        }

        $payment_url = $order->get_meta('_furatpay_payment_url');
        if (!$payment_url) {
            wp_die(esc_html__('Payment URL not found', 'furatpay'));
        }

        // Enqueue required scripts
        wp_enqueue_script('jquery');
        wp_enqueue_script('wc-checkout');
        
        // Add our script data
        $script_data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('furatpay-nonce'),
            'i18n' => array(
                'popupBlocked' => __('Popup was blocked. Please try again.', 'furatpay'),
                'paymentFailed' => __('Payment failed. Please try again.', 'furatpay')
            )
        );
        wp_localize_script('jquery', 'furatpayData', $script_data);

        // Display payment page template
        wc_get_template(
            'payment.php',
            array(
                'order' => $order,
                'payment_url' => $payment_url,
                'return_url' => $this->get_return_url($order),
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('furatpay-nonce')
            ),
            '',
            FURATPAY_PLUGIN_PATH . 'templates/'
        );
        exit;
    }

    public function check_payment_status()
    {
        try {
            // Verify nonce
            check_ajax_referer('furatpay-nonce', 'nonce');

            // Get and validate order_id
            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            if (!$order_id) {
                throw new Exception(__('Invalid order ID', 'furatpay'));
            }

            // Get order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found', 'furatpay'));
            }

            // Get invoice ID
            $invoice_id = $order->get_meta('_furatpay_invoice_id');
            if (!$invoice_id) {
                throw new Exception(__('Invoice ID not found', 'furatpay'));
            }

            // Check payment status with FuratPay API
            $status = FuratPay_API_Handler::check_payment_status(
                $this->api_url,
                $this->api_key,
                $invoice_id
            );


            switch ($status) {
                case 'paid':
                    $order->payment_complete();
                    wp_send_json_success([
                        'status' => 'completed',
                        'redirect_url' => $order->get_checkout_order_received_url()
                    ]);
                    break;

                case 'failed':
                    $order->update_status('failed', __('Payment failed or was declined', 'furatpay'));
                    wp_send_json_success([
                        'status' => 'failed',
                        'message' => __('Payment failed or was declined. Please try again.', 'furatpay')
                    ]);
                    break;

                default:
                    wp_send_json_success([
                        'status' => 'pending'
                    ]);
                    break;
            }

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }

        // Ensure we always exit after sending JSON response
        wp_die();
    }

    public function validate_fields()
    {
        // For block checkout, skip validation here as it happens in process_payment
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        // For FSE themes / block checkout
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            return true;
        }

        if (!isset($_POST['furatpay_service']) || empty($_POST['furatpay_service'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            wc_add_notice(__('Please select a payment service.', 'furatpay'), 'error');
            return false;
        }

        try {
            $payment_services = FuratPay_API_Handler::get_payment_services($this->api_url, $this->api_key);
            $service_ids = array_column($payment_services, 'id');

            $selected_service = isset($_POST['furatpay_service']) ? intval($_POST['furatpay_service']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if (!in_array($selected_service, $service_ids, true)) {
                wc_add_notice(__('Invalid payment service selected.', 'furatpay'), 'error');
                return false;
            }
        } catch (Exception $e) {
            wc_add_notice(__('Unable to validate payment service. Please try again.', 'furatpay'), 'error');
            return false;
        }

        return true;
    }

    /**
     * AJAX endpoint to get payment services
     */
    public function ajax_get_payment_services() {
        check_ajax_referer('furatpay-nonce', 'nonce');

        try {
            $services = FuratPay_API_Handler::get_payment_services($this->api_url, $this->api_key);

            wp_send_json_success($services);
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ), 400);
        }
    }

    public function ajax_initiate_payment() {
        check_ajax_referer('furatpay-nonce', 'nonce');

        try {
            // Get form data
            $form_data_raw = isset($_POST['form_data']) ? wp_unslash($_POST['form_data']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            parse_str($form_data_raw, $form_data);
            
            // Create temporary order data
            $order_data = array(
                'total' => WC()->cart->get_total('edit'),
                'currency' => get_woocommerce_currency(),
            );
            
            // Note: This ajax method appears to be legacy/unused code
            // It passes array instead of WC_Order which won't work with current implementation
            throw new Exception(__('This payment method is not available. Please use standard checkout.', 'furatpay'));

            // Get payment URL
            $payment_service = isset($_POST['payment_service']) ? intval($_POST['payment_service']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $payment_url = FuratPay_API_Handler::create_payment(
                $this->api_url,
                $this->api_key,
                $invoice_id,
                $payment_service
            );

            // Store invoice ID in session for later use when webhook arrives
            WC()->session->set('furatpay_pending_invoice', $invoice_id);

            wp_send_json_success(array(
                'payment_url' => $payment_url
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
}