<?php
/**
 * Plugin Name: WPR Test Gateway
 * Description: Test-only mu-plugin: a payment gateway that always declines, so tests can produce a real failed order.
 */

// mu-plugins load before WooCommerce, so the class must be declared after it exists.
add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WPR_Test_Failing_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'wpr_test_failing';
            $this->method_title = 'Test Failing Card';
            $this->method_description = 'Always declines. For automated tests only.';
            $this->description = 'Always declines. For automated tests only.';
            $this->enabled = 'yes';
            $this->has_fields = false;
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (get_option('wpr_test_gate_mode', 'fail') === 'success') {
                $order->payment_complete('TEST-APPROVED-' . $order_id);
                return ['result' => 'success', 'redirect' => $order->get_checkout_order_received_url()];
            }
                // Real gateways surface the decline themselves: the order-pay
            // handler renders no notice for a plain 'failure' result.
            wc_add_notice('Your card was declined by the test gateway. Please try again.', 'error');
        // WC only flips the order to "failed" for an uncaught exception;
            // a plain failure array leaves it pending, so do it explicitly.
            $order->update_status('failed', 'Test gateway declined the payment.');
            do_action('woocommerce_payment_failed', $order);
            return ['result' => 'failure', 'redirect' => ''];
        }
    }

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = WPR_Test_Failing_Gateway::class;
        return $gateways;
    });
}, 20);
