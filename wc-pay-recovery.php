<?php
/**
 * Plugin Name: WC Pay Recovery
 * Description: Automatic payment-failed reminder emails with a retry link and escalating discount.
 * Version: 0.1.0
 */

// day => [email subject suffix, discount %]. Escalating: pay later, bigger incentive.
function wpr_schedule(): array
{
    return [
        1 => ['your payment did not go through', 0],
        3 => ['a gentle reminder + 5% off', 5],
        7 => ['last call + 10% off', 10],
    ];
}

function wpr_tick(): void
{
    $orders = wc_get_orders([
        'status'  => 'failed',
        'limit'   => 100,
        'date_created' => '>' . (time() - 14 * DAY_IN_SECONDS),
    ]);
    foreach ($orders as $order) {
        $start = (int) $order->get_meta('_wpr_start');
        if (!$start) { // failed before plugin activation: anchor to order date
            $start = $order->get_date_created()->getTimestamp();
            $order->update_meta_data('_wpr_start', $start);
            $order->update_meta_data('_wpr_stage', 0);
            $order->save();
        }
        $stage = (int) $order->get_meta('_wpr_stage');
        foreach (wpr_schedule() as $day => [$subject, $discount]) {
            if ($day <= $stage || (time() - $start) < $day * DAY_IN_SECONDS) {
                continue;
            }
            $link = $order->get_checkout_payment_url();
            $code = '';
            if ($discount) {
                $code = 'RECOVER-' . $order->get_id();
                // Re-sync amount: the same coupon escalates from 5% to 10%.
                $coupon = new WC_Coupon(wc_get_coupon_id_by_code($code) ?: 0);
                $coupon->set_code($code);
                $coupon->set_discount_type('percent');
                $coupon->set_amount($discount);
                $coupon->save();
                $link = add_query_arg('coupon', rawurlencode($code), $link);
            }
            wp_mail(
                $order->get_billing_email(),
                'Order #' . $order->get_order_number() . ': ' . $subject,
                "Hi {$order->get_billing_first_name()},\n\n"
                . "Your payment did not go through. Retry here: {$link}\n"
                . ($code ? "Discount code ({$discount}%): {$code}\n" : '')
                . "\nThanks!\n"
            );
            $order->update_meta_data('_wpr_stage', $day);
            $order->save();
            break; // one email per run
        }
    }
}

if (defined('ABSPATH')) {
    add_action('woocommerce_payment_failed', function ($order) {
        $order->update_meta_data('_wpr_start', time());
        $order->update_meta_data('_wpr_stage', 0);
        $order->save();
    });
    add_action('wpr_check', 'wpr_tick');
    if (!wp_next_scheduled('wpr_check')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpr_check');
    }
}
