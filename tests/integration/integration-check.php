<?php
// Integration check: real WordPress + WooCommerce + real DB + real wp_mail filter.
// Run: wp eval-file tests/integration/integration-check.php
// Exits non-zero on the first failed assertion.

function ok($cond, $msg)
{
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        exit(1);
    }
}

delete_option('wpr_mail_log');

function wpr_make_failed_order(int $daysAgoStart, bool $withMeta = true): int
{
    $order = wc_create_order();
    $order->set_billing_email('itest@example.com');
    $order->set_billing_first_name('Integration');
    if ($withMeta) {
        $order->update_meta_data('_wpr_start', time() - $daysAgoStart * DAY_IN_SECONDS);
        $order->update_meta_data('_wpr_stage', 0);
    }
    $order->calculate_totals();
    $order->update_status('failed');
    $order->save();
    return $order->get_id();
}

// 1. Fresh failure: no reminder yet.
wpr_make_failed_order(0);
wpr_tick();
ok(count(get_option('wpr_mail_log', [])) === 0, 'no reminder for a fresh failure');
$fresh = wc_get_orders(['status' => 'failed', 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC'])[0];
ok((int) $fresh->get_meta('_wpr_stage') === 0, 'fresh failure stays at stage 0');

// 2. Aged failure escalates across three ticks, then stops.
$id = wpr_make_failed_order(8);
wpr_tick();
wpr_tick();
wpr_tick();
$log = get_option('wpr_mail_log', []);
ok(count($log) === 3, 'three reminders after three ticks, got ' . count($log));
ok(str_contains($log[0]['subject'], 'did not go through'), 'day-1 subject');
ok(str_contains($log[1]['subject'], '5% off'), 'day-3 subject carries 5%');
ok(str_contains($log[2]['subject'], '10% off'), 'day-7 subject carries 10%');
ok(str_contains($log[2]['body'], 'coupon=RECOVER-' . $id), 'day-7 link carries coupon');
ok(str_contains($log[2]['body'], '/order-pay/'), 'retry link points at order-pay');

// 3. Coupon exists in DB with the escalated amount.
$coupon = new WC_Coupon('RECOVER-' . $id);
ok($coupon->get_id() > 0, 'coupon persisted');
ok($coupon->get_discount_type() === 'percent', 'coupon is percentage');
ok(abs($coupon->get_amount() - 10) < 0.01, 'coupon amount is 10%, got ' . $coupon->get_amount());

// 4. Stage recorded, nothing more is sent.
$order = wc_get_order($id);
ok((int) $order->get_meta('_wpr_stage') === 7, 'stage recorded as 7');
wpr_tick();
ok(count(get_option('wpr_mail_log', [])) === 3, 'no reminders after day 7');

// 5. Saved settings actually drive the schedule (and can be reset to defaults).
update_option('wpr_settings', wpr_sanitize_settings([
    'enabled' => 'yes',
    'days' => '2,5',
    'discounts' => '0,7',
    'template' => "Hi {name}\n{link}\n{coupon}",
]));
$s = wpr_schedule();
ok(array_keys($s) === [2, 5], 'settings drive reminder days');
ok($s[5][1] === 7, 'settings drive discounts');
delete_option('wpr_settings');
ok(wpr_schedule() === [1 => [__('your payment did not go through', 'wc-pay-recovery'), 0], 3 => [__('your payment did not go through', 'wc-pay-recovery') . ' + 5% off', 5], 7 => [__('your payment did not go through', 'wc-pay-recovery') . ' + 10% off', 10]], 'defaults restored after delete');

// 6. Hook wiring: cron event registered on plugin load.
ok(wp_next_scheduled('wpr_check') !== false, 'daily wpr_check cron is scheduled');

echo "INTEGRATION OK\n";
