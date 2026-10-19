<?php
/**
 * Plugin Name: PayRetry Pro
 * Plugin URI:  https://payretry.babkin-alexey-1805.workers.dev/
 * Description: Revenue recovery reports for PayRetry — how many failed payments came back and how much money your reminders earned. Add-on for PayRetry.
 * Version:     0.1.0
 * Author:      Alexey Babkin <babkin.alexey.1805@gmail.com>
 * Text Domain: payretry-pro
 * Domain Path: /languages
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires Plugins: payretry
 * Requires at least: 6.0
 * WC requires at least: 8.0
 * WC tested up to: 9.9
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure analysis: classify orders into reminder/recovery metrics.
 *
 * @param array $failed  orders with a failed status (each exposes get_meta()).
 * @param array $paid    paid orders with coupon items (each exposes get_items()/get_total()).
 * @return array{sent:int, recovered:int, revenue:float, rate:float}
 */
function wprp_collect(array $failed, array $paid): array
{
    $sent = 0;
    foreach ($failed as $order) {
        if ((int) $order->get_meta('_wpr_stage') > 0) {
            $sent++;
        }
    }
    $recovered = 0;
    $revenue = 0.0;
    foreach ($paid as $order) {
        foreach ($order->get_items('coupon') as $item) {
            if (str_starts_with(strtolower((string) $item->get_code()), 'recover-')) {
                $recovered++;
                $revenue += (float) $order->get_total();
                break;
            }
        }
    }
    return [
        'sent'      => $sent,
        'recovered' => $recovered,
        'revenue'   => $revenue,
        'rate'      => count($failed) > 0 ? round($recovered / count($failed) * 100, 1) : 0.0,
    ];
}

function wprp_summary(int $days): array
{
    $since = time() - $days * DAY_IN_SECONDS;
    return wprp_collect(
        wc_get_orders(['status' => 'failed', 'date_created' => '>' . $since, 'limit' => -1]),
        wc_get_orders(['status' => ['processing', 'completed'], 'date_created' => '>' . $since, 'limit' => -1])
    );
}

function wprp_recent_recovered(int $days, int $limit = 10): array
{
    $since = time() - $days * DAY_IN_SECONDS;
    $out = [];
    foreach (wc_get_orders(['status' => ['processing', 'completed'], 'date_created' => '>' . $since, 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC']) as $order) {
        foreach ($order->get_items('coupon') as $item) {
            if (str_starts_with(strtolower((string) $item->get_code()), 'recover-')) {
                $out[] = $order;
                break;
            }
        }
    }
    return $out;
}

function wprp_render_report_page(): void
{
    $days  = max(1, min(365, (int) ($_GET['days'] ?? 30)));
    $s     = wprp_summary($days);
    $recent = wprp_recent_recovered($days);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('PayRetry Pro — Recovery Report', 'payretry-pro'); ?></h1>
        <p>
            <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $label) : ?>
                <a class="button <?php echo $d === $days ? 'button-primary' : ''; ?>"
                   href="<?php echo esc_url(add_query_arg('days', $d, admin_url('admin.php?page=wpr-pro'))); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </p>
        <table class="widefat striped" style="max-width:720px">
            <tr><th><?php esc_html_e('Metric', 'payretry-pro'); ?></th><th><?php esc_html_e('Value', 'payretry-pro'); ?></th></tr>
            <tr><td><?php esc_html_e('Failed payments with reminders sent', 'payretry-pro'); ?></td><td><?php echo esc_html((string) $s['sent']); ?></td></tr>
            <tr><td><?php esc_html_e('Recovered orders (via RECOVER coupon)', 'payretry-pro'); ?></td><td><?php echo esc_html((string) $s['recovered']); ?></td></tr>
            <tr><td><?php esc_html_e('Recovered revenue', 'payretry-pro'); ?></td><td><strong><?php echo esc_html( wp_strip_all_tags( number_format_i18n( $s['revenue'], 2 ) ) ); ?></strong> <?php echo esc_html(get_woocommerce_currency_symbol()); ?></td></tr>
            <tr><td><?php esc_html_e('Recovery rate', 'payretry-pro'); ?></td><td><?php echo esc_html((string) $s['rate']); ?>%</td></tr>
        </table>
        <h2><?php esc_html_e('Recent recovered orders', 'payretry-pro'); ?></h2>
        <?php if (!$recent) : ?>
            <p><?php esc_html_e('No recovered orders in this period yet.', 'payretry-pro'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:720px">
                <tr><th><?php esc_html_e('Order', 'payretry-pro'); ?></th><th><?php esc_html_e('Date', 'payretry-pro'); ?></th><th><?php esc_html_e('Total', 'payretry-pro'); ?></th></tr>
                <?php foreach ($recent as $o) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url(admin_url('post.php?post=' . $o->get_id() . '&action=edit')); ?>">#<?php echo esc_html($o->get_order_number()); ?></a></td>
                        <td><?php echo esc_html($o->get_date_created() ? $o->get_date_created()->date_i18n('Y-m-d') : ''); ?></td>
                        <td><?php echo esc_html($o->get_formatted_order_total()); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('A recovered order is a paid order that used a RECOVER-* coupon created by the PayRetry plugin.', 'payretry-pro'); ?></p>
    </div>
    <?php
}

add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        __('PayRetry Pro', 'payretry-pro'),
        __('PayRetry Pro', 'payretry-pro'),
        'manage_woocommerce',
        'wpr-pro',
        'wprp_render_report_page'
    );
});
