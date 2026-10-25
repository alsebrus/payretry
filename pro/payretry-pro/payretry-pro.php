<?php
/**
 * Plugin Name: PayRetry Pro
 * Plugin URI:  https://payretry-woo.oreviq.com/
 * Description: Revenue recovery reports for PayRetry — how many failed payments came back and how much money your reminders earned. Add-on for PayRetry.
 * Version:     0.1.3
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

// Freemius SDK (Pro build only: guarded so the add-on stays inert in dev
// environments and the free WordPress.org build ships no SDK at all).
if (!isset($pp_fs) && file_exists(dirname(__FILE__) . '/vendor/freemius/start.php')) {
    require_once dirname(__FILE__) . '/vendor/freemius/start.php';
    $pp_fs = fs_dynamic_init(array(
        'id'                => '42120',
        'slug'              => 'payretry-pro',
        'type'              => 'plugin',
        'public_key'        => 'pk_b2bd8df65e9e5a06e30596e24b409',
        'is_premium'        => true,
        'is_premium_only'   => true,
        'has_addons'        => false,
        'has_paid_plans'    => true,
        'is_org_compliant'  => true,
        'menu'              => array(
            'slug'     => 'payretry-pro',
            'account'  => false,
            'network'  => false,
            'priority' => 57,
        ),
    ));
}

/**
 * Pure analysis: classify orders into reminder/recovery metrics.
 *
 * The anchor is _payretry_start, written by `woocommerce_payment_failed` the moment
 * a payment is declined. An order paid on the first try never gets it, so a
 * recovered order is simply "was failed, is paid now" — no coupon required
 * (retry can happen from the first reminder, which carries no discount).
 *
 * @param array $orders all orders of the period (recovered ones included).
 * @param array $paid   paid orders of the period.
 * @return array{failed:int, recovered:int, still_unpaid:int, revenue:float, rate:float}
 */
function payretry_pro_collect(array $orders, array $paid): array
{
    $failed_count = 0;
    $still_unpaid = 0;
    foreach ($orders as $order) {
        if ((int) $order->get_meta('_payretry_start') > 0) {
            $failed_count++;
            if ('failed' === $order->get_status()) {
                $still_unpaid++;
            }
        }
    }
    $recovered = 0;
    $revenue = 0.0;
    foreach ($paid as $order) {
        if ((int) $order->get_meta('_payretry_start') > 0) {
            $recovered++;
            $revenue += (float) $order->get_total();
        }
    }
    return [
        'failed'       => $failed_count,
        'recovered'    => $recovered,
        'still_unpaid' => $still_unpaid,
        'revenue'      => $revenue,
        'rate'         => $failed_count > 0 ? round($recovered / $failed_count * 100, 1) : 0.0,
    ];
}

function payretry_pro_summary(int $days): array
{
    $since = time() - $days * DAY_IN_SECONDS;
    return payretry_pro_collect(
        wc_get_orders(['date_created' => '>' . $since, 'limit' => -1]),
        wc_get_orders(['status' => ['processing', 'completed'], 'date_created' => '>' . $since, 'limit' => -1])
    );
}

function payretry_pro_recent_recovered(int $days, int $limit = 10): array
{
    $since = time() - $days * DAY_IN_SECONDS;
    $out = [];
    foreach (wc_get_orders(['status' => ['processing', 'completed'], 'date_created' => '>' . $since, 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC']) as $order) {
        if ((int) $order->get_meta('_payretry_start') > 0) {
            $out[] = $order;
        }
    }
    return $out;
}

function payretry_pro_render_report_page(): void
{
    $days  = max(1, min(365, (int) ($_GET['days'] ?? 30)));
    $s     = payretry_pro_summary($days);
    $recent = payretry_pro_recent_recovered($days);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('PayRetry Pro — Recovery Report', 'payretry-pro'); ?></h1>
        <p>
            <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $label) : ?>
                <a class="button <?php echo $d === $days ? 'button-primary' : ''; ?>"
                   href="<?php echo esc_url(add_query_arg('days', $d, admin_url('admin.php?page=payretry-pro'))); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </p>
        <table class="widefat striped" style="max-width:720px">
            <tr><th><?php esc_html_e('Metric', 'payretry-pro'); ?></th><th><?php esc_html_e('Value', 'payretry-pro'); ?></th></tr>
            <tr><td><?php esc_html_e('Failed payments (period)', 'payretry-pro'); ?></td><td><?php echo esc_html((string) $s['failed']); ?></td></tr>
            <tr><td><?php esc_html_e('Recovered orders (paid after failure)', 'payretry-pro'); ?></td><td><?php echo esc_html((string) $s['recovered']); ?></td></tr>
            <tr><td><?php esc_html_e('Still unpaid (failed)', 'payretry-pro'); ?></td><td><?php echo esc_html((string) $s['still_unpaid']); ?></td></tr>
            <tr><td><?php esc_html_e('Recovered revenue', 'payretry-pro'); ?></td><td><strong><?php echo wp_kses_post(wc_price($s['revenue'])); ?></strong></td></tr>
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
                        <td><?php echo wp_kses_post($o->get_formatted_order_total()); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('A recovered order is a payment that failed earlier and was paid later, with or without a discount coupon.', 'payretry-pro'); ?></p>
    </div>
    <?php
}

add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        __('PayRetry Pro', 'payretry-pro'),
        __('PayRetry Pro', 'payretry-pro'),
        'manage_woocommerce',
        'payretry-pro',
        'payretry_pro_render_report_page'
    );
});
