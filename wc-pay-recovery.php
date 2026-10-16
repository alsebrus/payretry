<?php
/**
 * Plugin Name: WC Pay Recovery
 * Plugin URI:  https://example.com/wc-pay-recovery
 * Description: Automatic payment-failed reminder emails with a retry link and escalating discount.
 * Version:     0.2.0
 * Author:      kiber
 * Text Domain: wc-pay-recovery
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * WC requires at least: 8.0
 * WC tested up to: 9.9
 */

// day => [email subject suffix, discount %]. Escalating: pay later, bigger incentive.
function wpr_default_settings(): array
{
    return [
        'enabled'   => 'yes',
        'days'      => [1, 3, 7],
        'discounts' => [0, 5, 10],
        'template'  => "Hi {name},\n\nYour payment did not go through. Retry here: {link}\n{coupon}\nThanks!\n",
    ];
}

function wpr_get_settings(): array
{
    return wp_parse_args(get_option('wpr_settings', []), wpr_default_settings());
}

function wpr_sanitize_settings($input): array
{
    $out = get_option('wpr_settings', []);
    $out['enabled'] = (isset($input['enabled']) && $input['enabled'] === 'yes') ? 'yes' : 'no';

    $days = array_values(array_filter(array_map('intval', explode(',', (string) ($input['days'] ?? ''))), fn ($d) => $d > 0));
    $discounts = array_values(array_filter(array_map('intval', explode(',', (string) ($input['discounts'] ?? ''))), fn ($d) => $d >= 0));
    if ($days && count($days) === count($discounts)) {
        $out['days'] = $days;
        $out['discounts'] = $discounts;
    }

    $template = (string) ($input['template'] ?? '');
    if (trim($template) !== '' && str_contains($template, '{link}')) {
        $out['template'] = $template;
    }
    return $out;
}

function wpr_schedule(): array
{
    $s = wpr_get_settings();
    $out = [];
    foreach ($s['days'] as $i => $day) {
        $discount = $s['discounts'][$i] ?? 0;
        $out[(int) $day] = [
            __('your payment did not go through', 'wc-pay-recovery') . ($discount ? sprintf(__(' + %d%% off', 'wc-pay-recovery'), $discount) : ''),
            $discount,
        ];
    }
    ksort($out);
    return $out;
}

function wpr_tick(): void
{
    if (wpr_get_settings()['enabled'] !== 'yes') {
        return;
    }
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
            $body = strtr(wpr_get_settings()['template'], [
                '{name}'   => $order->get_billing_first_name(),
                '{link}'   => $link,
                '{coupon}' => $code ? sprintf(__('Discount code (%d%%): %s', 'wc-pay-recovery'), $discount, $code) : '',
            ]);
            wp_mail(
                $order->get_billing_email(),
                sprintf('Order #%s: %s', $order->get_order_number(), $subject),
                $body
            );
            $order->update_meta_data('_wpr_stage', $day);
            $order->save();
            break; // one email per run
        }
    }
}

function wpr_render_settings_page(): void
{
    $s = wpr_get_settings();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Payment Recovery', 'wc-pay-recovery'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('wpr'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable reminders', 'wc-pay-recovery'); ?></th>
                    <td><label><input type="checkbox" name="wpr_settings[enabled]" value="yes" <?php checked($s['enabled'], 'yes'); ?>> <?php esc_html_e('Send reminder emails for failed payments', 'wc-pay-recovery'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Reminder days', 'wc-pay-recovery'); ?></th>
                    <td>
                        <input type="text" name="wpr_settings[days]" value="<?php echo esc_attr(implode(', ', $s['days'])); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Comma-separated days after the failed payment, e.g. 1, 3, 7', 'wc-pay-recovery'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Discount per reminder (%)', 'wc-pay-recovery'); ?></th>
                    <td>
                        <input type="text" name="wpr_settings[discounts]" value="<?php echo esc_attr(implode(', ', $s['discounts'])); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('One value per reminder day; 0 = no discount, e.g. 0, 5, 10', 'wc-pay-recovery'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Email template', 'wc-pay-recovery'); ?></th>
                    <td>
                        <textarea name="wpr_settings[template]" rows="7" class="large-text code"><?php echo esc_textarea($s['template']); ?></textarea>
                        <p class="description"><?php esc_html_e('Placeholders: {name}, {link} (required, retry link), {coupon} (discount line)', 'wc-pay-recovery'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

add_action('woocommerce_payment_failed', function ($order) {
    $order->update_meta_data('_wpr_start', time());
    $order->update_meta_data('_wpr_stage', 0);
    $order->save();
});
add_action('wpr_check', 'wpr_tick');
if (!wp_next_scheduled('wpr_check')) {
    wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpr_check');
}

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', __('Payment Recovery', 'wc-pay-recovery'), __('Payment Recovery', 'wc-pay-recovery'), 'manage_woocommerce', 'wpr', 'wpr_render_settings_page');
});
add_action('admin_init', function () {
    register_setting('wpr', 'wpr_settings', ['sanitize_callback' => 'wpr_sanitize_settings']);
});
