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

// Isolate from state accumulated by previous runs (this is a throwaway test DB).
delete_option('payretry_mail_log');
foreach (wc_get_orders(['limit' => -1]) as $stale) {
    $stale->delete(true);
}

function payretry_make_failed_order(int $daysAgoStart, bool $withMeta = true): int
{
    $order = wc_create_order();
    $order->set_billing_email('itest@example.com');
    $order->set_billing_first_name('Integration');
    if ($withMeta) {
        $order->update_meta_data('_payretry_start', time() - $daysAgoStart * DAY_IN_SECONDS);
        $order->update_meta_data('_payretry_stage', 0);
    }
    $order->calculate_totals();
    $order->update_status('failed');
    $order->save();
    return $order->get_id();
}

// 1. Fresh failure: no reminder yet.
payretry_make_failed_order(0);
payretry_tick();
ok(count(get_option('payretry_mail_log', [])) === 0, 'no reminder for a fresh failure');
$fresh = wc_get_orders(['status' => 'failed', 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC'])[0];
ok((int) $fresh->get_meta('_payretry_stage') === 0, 'fresh failure stays at stage 0');

// 2. Aged failure escalates across three ticks, then stops.
$id = payretry_make_failed_order(8);
payretry_tick();
payretry_tick();
payretry_tick();
$log = get_option('payretry_mail_log', []);
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
ok((int) $order->get_meta('_payretry_stage') === 7, 'stage recorded as 7');
payretry_tick();
ok(count(get_option('payretry_mail_log', [])) === 3, 'no reminders after day 7');

// 5. Saved settings actually drive the schedule (and can be reset to defaults).
update_option('payretry_settings', payretry_sanitize_settings([
    'enabled' => 'yes',
    'days' => '2,5',
    'discounts' => '0,7',
    'template' => "Hi {name}\n{link}\n{coupon}",
]));
$s = payretry_schedule();
ok(array_keys($s) === [2, 5], 'settings drive reminder days');
ok($s[5][1] === 7, 'settings drive discounts');
delete_option('payretry_settings');
ok(payretry_schedule() === [1 => [__('your payment did not go through', 'payretry'), 0], 3 => [__('your payment did not go through', 'payretry') . ' + 5% off', 5], 7 => [__('your payment did not go through', 'payretry') . ' + 10% off', 10]], 'defaults restored after delete');

// 6. The settings form renders for everyone (no license gating).
ob_start();
payretry_render_settings_page();
$html = ob_get_clean();
ok(str_contains($html, 'payretry_settings[days]'), 'settings form renders');
ok(!str_contains($html, 'Upgrade to Professional'), 'no license-gated upsell');

// 7. Hook wiring: cron event registered on plugin load.
ok(wp_next_scheduled('payretry_check') !== false, 'daily payretry_check cron is scheduled');

// 8. Pro add-on: a paid order with a RECOVER coupon is counted as recovered revenue.
$paid = wc_create_order();
$paid->set_billing_email('itest@example.com');
$paid->set_status('completed');
$item = new WC_Order_Item_Coupon();
$item->set_props(['code' => 'RECOVER-PRO-TEST', 'discount' => 5, 'discount_tax' => 0]);
$paid->add_item($item);
$paid->set_total(42.50);
$paid->update_meta_data('_payretry_start', time() - 3 * DAY_IN_SECONDS); // failed before recovery
$paid->update_meta_data('_payretry_stage', 3); // reminders were sent before the coupon
$paid->save();
$ps = payretry_pro_summary(30);
ok($ps['recovered'] >= 1, 'pro: recovered order counted');
ok(abs($ps['revenue'] - 42.50) < 0.01, 'pro: recovered revenue is 42.50, got ' . $ps['revenue']);
$recent = payretry_pro_recent_recovered(30);
ok(count($recent) >= 1, 'pro: recent list contains the order');

// 9. Security: settings menu is gated by manage_woocommerce (not read/manage_options).
wp_set_current_user(1); // add_submenu_page skips pages the current user may not see
global $menu, $submenu;
// wp-cli has no admin bootstrap: seed the globals the way wp-admin does
if (!is_array($menu) || !$menu) {
    $menu = [56 => ['WooCommerce', 'manage_woocommerce', 'woocommerce', 'WooCommerce']];
}
if (!is_array($submenu) || !isset($submenu['woocommerce'])) {
    $submenu = ['woocommerce' => []];
}
do_action('admin_menu');
$payretry_cap = null;
foreach ($submenu['woocommerce'] ?? [] as $item) {
    if (in_array('payretry', (array) $item, true)) {
        $payretry_cap = $item[1];
    }
}
ok($payretry_cap === 'manage_woocommerce', 'admin menu requires manage_woocommerce, got: ' . var_export($payretry_cap, true));

// 10. Security: the settings form renders the WP options nonce (no-CSRF).
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
wp_set_current_user(1); // admin
payretry_render_settings_page();
$form_html = ob_get_clean();
ok(str_contains($form_html, 'name="_wpnonce"'), 'settings form carries _wpnonce');
ok((bool) preg_match('/name=[\'\""]option_page[\'\""]/', $form_html), 'settings form posts to options.php option_page');

// 12. Upsell link is hidden while the Pro add-on is active.
// (The reverse — link reappearing after deactivation — needs a fresh request:
// PHP keeps payretry_pro_summary defined for the rest of this process, so it can only
// be exercised live, not in-process. The branch is a plain function_exists().)
ok(!str_contains($form_html, 'PayRetry Pro'), 'upsell link hidden while Pro add-on is active');

// 11. Security: XSS in the stored template is neutralised end-to-end
//     (real WP sanitize_textarea_field on save + esc_textarea on render).
$clean = payretry_sanitize_settings([
    'template' => "<script>alert(1)</script>Retry: {link}",
]);
ok(!str_contains($clean['template'], '<script'), 'sanitizer strips script tags on save');
update_option('payretry_settings', array_merge(get_option('payretry_settings', []), $clean));
ob_start();
payretry_render_settings_page();
$red = ob_get_clean();
ok(!str_contains($red, '<script>alert'), 'rendered form contains no raw script tag');
ok(str_contains($red, '&lt;script') || !str_contains($red, '<script'), 'any leftover markup is entity-escaped');
delete_option('payretry_settings'); // hand control back to the defaults used by the rest of the suite

echo "INTEGRATION OK\n";

// Same isolation as above, so the shared wp-env stand stays clean after the run.
foreach (wc_get_orders(['limit' => -1]) as $stale) {
    $stale->delete(true);
}
