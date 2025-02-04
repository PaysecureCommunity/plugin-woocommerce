<?php

require_once dirname(__FILE__) . '/PaySecureAPI.php';

class WC_PaySecure_Payments_Gateway extends WC_Payment_Gateway
{
    const PAYSECURE_MODULE_VERSION = 'v1.2.5';

    public $id = "paysecure";

    public $title = "PaySecure Payments";

    public $supports = array('products', 'refunds');

    private $cache;

    /**
     * @var string
     */
    private $debug;

    public function __construct()
    {
        // TODO: Set icon. Probably can be an external URL.
        $this->init_form_fields();
        $this->init_settings();

        $this->method_title = __('PaySecure Payments', 'woocommerce-paysecure-payments');
        $this->method_description = $this->define_method_description();

        $this->title = $this->get_option('title', __('PaySecure', 'woocommerce-paysecure-payments'));
        $this->description = $this->get_option('description', $this->method_description);

        $this->icon = null;//plugins_url('assets/images/paysecure.png', dirname(__FILE__));

        $this->debug = $this->get_option('debug', false);

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            array($this, 'process_admin_options')
        );

        str_replace(
            'https:',
            'http:',
            add_query_arg('wc-api', 'WC_PaySecure_Payments_Gateway', home_url('/'))
        );
        add_action(
            'woocommerce_api_wc_gateway_paysecure_payments',
            array($this, 'handle_callback')
        );
    }

    public function __set($key, $val)
    {
        $this->cache[$key] = $val;
    }

    public function __get($key)
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        } else if ($key == 'api') {
            $this->cache[$key] = new PaySecureAPI(
                $this->settings['private-key'],
                $this->settings['brand-id'],
                new WC_Logger(),
                $this->debug
            );

            return $this->cache[$key];
        }
    }

    private function define_method_description(): string
    {
        if (is_admin()) {
            return __(
                'Allow customers to securely pay via PaySecure (Credit/Debit Cards)',
                'woocommerce-paysecure-payments'
            );
        }

        return __(
            'Pay via PaySecure.',
            'woocommerce-paysecure-payments'
        );
    }

    private function log_order_info($msg, $o): void
    {
        $this->api->log_info($msg . ': ' . $o->get_order_number());
    }

    function handle_callback(): void
    {
        global $wpdb;

        $wpdb->get_results(
            "SELECT GET_LOCK('paysecure_payments', 15);"
        );

        if ($this->debug) {
            $this->api->log_info('Received Callback: ' . print_r($_GET, true));
        }

        if ($this->debug) {
            $this->api->log_info('Session Object : ' . print_r(WC()->session->get_session_data(), true));
        }

        $order = new WC_Order($_GET["id"]);

        if ($this->debug) {
            $this->api->log_info('Order Object : ' . print_r($order->get_data(), true));
        }

        $payment_id = WC()->session->get(
            'paysecure_payment_id_' . $_GET["id"]
        );

        if ($this->debug) {
            $this->api->log_info('Payment ID : ' . $payment_id);
        }

        if (!$payment_id) {
            $input = json_decode(file_get_contents('php://input'), true);
            $input1 = file_get_contents('php://input');
            $input2 = str_replace("body=", "", $input1);
            $input3 = json_decode(urldecode($input2), true);

            //    $payment_id = array_key_exists('id', $input) ? $input['id'] : '';

            if ($this->debug) {
                $this->api->log_info('$input from session test : ' . $input);
                $this->api->log_info('$input1 from session test1 : ' . $input1);
                $this->api->log_info('$input1 from session test2 : ' . $input2);
                $this->api->log_info('$input1 from session test3 : ' . $input3);
            }

            $payment_id = array_key_exists('purchaseId', $input3) ? $input3['purchaseId'] : '';
        }

        if ($this->api->was_payment_successful($payment_id)) {
            if ($this->debug) {
                $this->api->log_info('was_payment_successful: TRUE');
            }
            if (!$order->is_paid()) {
                if ($this->debug) {
                    $this->api->log_info('is_paid: FALSE');
                }
                $order->payment_complete($payment_id);
                $order->add_order_note(
                    sprintf(__('Payment Successful. Transaction ID: %s', 'woocommerce-paysecure-payments'), $payment_id)
                );
            }
            WC()->cart->empty_cart();
            $this->log_order_info('Payment Processed for Order #', $order);
        } else {
            if ($this->debug) {
                $this->api->log_info('was_payment_successful: FALSE');
            }
            if (!$order->is_paid()) {
                if ($this->debug) {
                    $this->api->log_info('is_paid: FALSE');
                }
                $order->update_status(
                    'wc-failed',
                    __('ERROR: Payment was received, but order verification failed.', 'woocommerce-paysecure-payments')
                );
                $this->log_order_info('payment not successful', $order);
            }
        }

        $wpdb->get_results(
            "SELECT RELEASE_LOCK('paysecure_payments');"
        );

        if ($this->debug) {
            $this->api->log_info("Location: " . $this->get_return_url($order));
        }

        header("Location: " . $this->get_return_url($order));

    }

    public function init_form_fields(): void
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce-paysecure-payments'),
                'label' => __('Enable PaySecure Payments', 'woocommerce-paysecure-payments'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 0,
            ),
            'title' => array(
                'title' => __('Payment method title', 'woocommerce-paysecure-payments'),
                'type' => 'text',
                'description' => __('If not set, "PaySecure" will be used. Ignored if payment method selection is enabled','woocommerce-paysecure-payments'),
                'default' => __('PaySecure','woocommerce-paysecure-payments')
            ),
            'description' => array(
                'title' => __('Payment method description', 'woocommerce-paysecure-payments'),
                'label' => '',
                'type' => 'text',
                'description' => __('If not set, "Pay via PaySecure" will be used'),
                'default' => __('Pay via PaySecure','woocommerce-paysecure-payments')
            ),
            'brand-id' => array(
                'title' => __('Brand ID', 'woocommerce-paysecure-payments'),
                'type' => 'text',
                'description' => __(
                    'Please enter your brand ID',
                    'woocommerce-paysecure-payments'
                ),
                'default' => '',
            ),
            'private-key' => array(
                'title' => __('Secret key', 'woocommerce-paysecure-payments'),
                'type' => 'text',
                'description' => __(
                    'Please enter your secret key',
                    'woocommerce-paysecure-payments'
                ),
                'default' => '',
            ),
            'debug' => array(
                'title' => __('Debug', 'woocommerce-paysecure-payments'),
                'type' => 'checkbox',
                'label' => __('Enable Debug Logging', 'woocommerce-paysecure-payments'),
                'default' => 0,
                'description' =>
                    sprintf(
                        __(
                            'Log events to <code>%s</code>',
                            'woocommerce-paysecure-payments'
                        ),
                        wc_get_log_file_path('paysecure')
                    ),
            ),
        );
    }

    public function payment_fields(): void
    {
        echo $this->description;
    }

    public function get_language()
    {
        if (defined('ICL_LANGUAGE_CODE')) {
            $ln = ICL_LANGUAGE_CODE;
        } else {
            $ln = get_locale();
        }

        switch ($ln) {
            case 'et_EE':
                $ln = 'et';
                break;
            case 'ru_RU':
                $ln = 'ru';
                break;
            case 'lt_LT':
                $ln = 'lt';
                break;
            case 'lv_LV':
                $ln = 'lv';
                break;
            case 'et':
            case 'lt':
            case 'lv':
            case 'ru':
                break;
            default:
                $ln = 'en';
        }

        return $ln;
    }

    public function process_payment($order_id)
    {
        $order = new WC_Order($order_id);
        $total = round($order->calculate_totals());
        $notes = $this->get_notes();
        $redirect_url = home_url() . "/?wc-api=wc_gateway_paysecure_payments&id={$order_id}&action=";
        $params = [
            'success_callback' => $redirect_url . "paid",
            'success_redirect' => $redirect_url . "paid",
            'failure_redirect' => $redirect_url . "cancel",
            'cancel_redirect' => $redirect_url . "cancel",
            'creator_agent' => 'PaySecure Payments for WooCommerce: ' . self::PAYSECURE_MODULE_VERSION,
            'reference' => (string)$order->get_order_number(),
            'platform' => 'WooCommerce',
            'purchase' => [
                "currency" => $order->get_currency(),
                "language" => $this->get_language(),
                "notes" => $notes,
                "products" => [
                    [
                        'name' => 'Payment',
                        'price' => $total,
                        'quantity' => 1,
                    ],
                ],
            ],
            'brand_id' => $this->settings['brand-id'],
            'client' => [
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'full_name' => $order->get_billing_first_name() . ' '
                    . $order->get_billing_last_name(),
                'street_address' => $order->get_billing_address_1() . ' '
                    . $order->get_billing_address_2(),
                'country' => $order->get_billing_country(),
                'city' => $order->get_billing_city(),
                'zip_code' => $order->get_billing_postcode(),
                'shipping_street_address' => $order->get_shipping_address_1()
                    . ' ' . $order->get_shipping_address_2(),
                'shipping_country' => $order->get_shipping_country(),
                'shipping_city' => $order->get_shipping_city(),
                'shipping_zip_code' => $order->get_shipping_postcode(),
                'stateCode' => $order->get_billing_state(),
                'shipping_stateCode' => $order->get_shipping_state(),
            ],
        ];

        $payment = $this->api->create_payment($params);

        sleep(5);

        if (!array_key_exists('purchaseId', $payment)) {
            wc_add_notice(__('Payment Error:', 'woocommerce-paysecure-payments') . $payment["message"], 'error');
            return ['result' => 'failure'];
        }

        WC()->session->set(
            'paysecure_payment_id_' . $order_id,
            $payment['purchaseId']
        );

        if ($this->debug) {
            $this->api->log_info('session object : ' . print_r(WC()->session, true));

            $this->api->log_info('paysecure_payment_id_' . $order_id . ' - ' . WC()->session->get('paysecure_payment_id_' . $order_id));
        }

        $this->log_order_info('got checkout url, redirecting', $order);

        $checkout_url = $payment['checkout_url'];

        if (array_key_exists("paysecure-payment-method", $_REQUEST)) {
            $checkout_url .= "?preferred=" . $_REQUEST["paysecure-payment-method"];
        }

        return array(
            'result' => 'success',
            'redirect' => $checkout_url,
        );
    }

    public function get_notes()
    {
        $cart = WC()->cart->get_cart();
        $nameString = '';
        foreach ($cart as $key => $cart_item) {
            $cart_product = $cart_item['data'];
            $name = method_exists($cart_product, 'get_name') === true ? $cart_product->get_name() : $cart_product->name;
            if (array_keys($cart)[0] == $key) {
                $nameString = $name;
            } else {
                $nameString = $nameString . ';' . $name;
            }
        }
        return $nameString;
    }

    public function can_refund_order($order): bool
    {
        $isApiEnabled = $this->get_option('enabled') && $this->get_option('private-key') && $this->get_option('brand-id');

        return $order && $order->get_transaction_id() && $isApiEnabled;
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        if (!$this->can_refund_order($order)) {
            $this->log_order_info('Cannot refund order', $order);
            return new WP_Error('error', __('Refund failed.', 'woocommerce-paysecure-payments'));
        }

        // Disallow Partial Refund
        if ($amount != $order->get_total()) {
            $this->log_order_info("Partial Refund Not Allowed. Order ID: {$order_id}, Order Total: {$order->get_total()}, Refund Requested: {$amount}", $order);
            return new WP_Error('error', sprintf(__('Partial Refund Not Allowed. Paid Total: %2$s, Refund Requested: %3$s', 'woocommerce-paysecure-payments'), $order_id, $order->get_total(), $amount));
        }

        $params = [
            'amount' => round($amount * 100)
        ];

        $result = $this->api->refund_payment($order->get_transaction_id(), $params);

        if (is_wp_error($result) || isset($result['message'])) {
            $this->api->log_error($result['message'] . ': ' . $order->get_order_number());

            return new WP_Error('error', var_export($result['message'], true));
        }

        $this->log_order_info('Refund Result: ' . wc_print_r($result, true), $order);

        if (strtolower($result['status']) == 'refunded') {
            $refund_amount = round($result['payment']['amount'], 2) . $result['payment']['currency'];

            $order->add_order_note(
            /* translators: 1: Refund amount, 2: Refund ID */
                sprintf(__('Refunded %1$s - Refund ID: %2$s', 'woocommerce-paysecure-payments'), $refund_amount, $result['reference_generated'])
            );
            return true;
        }

        return true;
    }
}
