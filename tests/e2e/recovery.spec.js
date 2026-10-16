const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const PROJECT_ROOT = path.join(__dirname, '..', '..');
const WP_ENV = 'npx';
const WP_ENV_ARGS = ['-y', '@wordpress/env@11.17.0']; // pinned so start and run always share one version

function wp(args) {
  return execFileSync(WP_ENV, [...WP_ENV_ARGS, 'run', 'cli', 'wp', ...args], {
    cwd: PROJECT_ROOT,
    encoding: 'utf8',
    maxBuffer: 10 * 1024 * 1024,
  });
}

test.describe('payment recovery end-to-end', () => {
  let productId;

  test.beforeAll(() => {
    const out = execFileSync(WP_ENV, [...WP_ENV_ARGS, 'run', 'cli', 'wp', 'eval-file', '/var/www/html/wp-content/plugins/wc-pay-recovery/tests/e2e/seed.php'], {
      cwd: PROJECT_ROOT,
      encoding: 'utf8',
      maxBuffer: 10 * 1024 * 1024,
    });
    expect(out).toContain('SEED OK');
    productId = out.match(/product=(\d+)/)[1];
  });

  test('declined payment creates a failed order, reminders escalate with coupon', async ({ page }) => {
    // 1. Shopper buys and gets declined by the test gateway.
    await page.goto(`/?add-to-cart=${productId}`);
    await page.goto('/checkout/');
    await page.locator('#billing_first_name').fill('E2E');
    await page.locator('#billing_last_name').fill('Shopper');
    await page.locator('#billing_country').selectOption('US');
    await page.locator('#billing_address_1').fill('1 Main Street');
    await page.locator('#billing_city').fill('Springfield');
    await page.locator('#billing_state').selectOption('CA');
    await page.locator('#billing_postcode').fill('90210');
    await page.locator('#billing_phone').fill('5551234567');
    await page.locator('#billing_email').fill('e2e@example.com');
    await page.locator('#payment_method_wpr_test_failing').check();
    await page.locator('#place_order').click();

    // 2. Checkout stays on the page with a payment error notice.
    await expect(page.locator('.woocommerce-NoticeGroup').first()).toBeVisible({ timeout: 30_000 });

    // 3. A failed order exists with the plugin's meta set by the hook.
    const orderJson = wp([
      'eval',
      `$o = wc_get_orders(['status' => 'failed', 'billing_email' => 'e2e@example.com', 'limit' => 1])[0] ?? null;`
      + ` echo $o ? json_encode(['id' => $o->get_id(), 'start' => $o->get_meta('_wpr_start'), 'stage' => $o->get_meta('_wpr_stage')]) : 'none';`,
    ]).trim();
    const order = JSON.parse(orderJson);
    expect(order.id).toBeTruthy();
    expect(Number(order.start)).toBeGreaterThan(0);
    expect(Number(order.stage)).toBe(0);

    // 4. Fast-forward 8 days and run the daily cron handler three times.
    wp(['eval', `$o = wc_get_order(${order.id}); $o->update_meta_data('_wpr_start', time() - 8 * DAY_IN_SECONDS); $o->update_meta_data('_wpr_stage', 0); $o->save(); wpr_tick(); wpr_tick(); wpr_tick();`]);

    // 5. Three reminder mails, escalating subject, coupon in the retry link.
    const mailsJson = wp([
      'eval',
      `$log = array_values(array_filter(get_option('wpr_mail_log', []), fn($m) => $m['to'] === 'e2e@example.com')); echo json_encode($log);`,
    ]).trim();
    const mails = JSON.parse(mailsJson);
    expect(mails).toHaveLength(3);
    expect(mails[0].subject).toContain('did not go through');
    expect(mails[1].subject).toContain('5% off');
    expect(mails[2].subject).toContain('10% off');
    expect(mails[2].body).toContain(`coupon=RECOVER-${order.id}`);
    expect(mails[2].body).toContain('/checkout/order-pay/');

    // 6. The coupon really exists in the store, at 10%.
    const couponId = wp(['eval', `echo wc_get_coupon_id_by_code('RECOVER-${order.id}');`]).trim();
    expect(Number(couponId)).toBeGreaterThan(0);
    const amount = wp(['eval', `echo (new WC_Coupon('RECOVER-${order.id}'))->get_amount();`]).trim();
    expect(Number(amount)).toBe(10);

    // 7. A fourth tick stays silent.
    wp(['eval', 'wpr_tick();']);
    const afterJson = wp([
      'eval',
      `$log = array_values(array_filter(get_option('wpr_mail_log', []), fn($m) => $m['to'] === 'e2e@example.com')); echo count($log);`,
    ]).trim();
    expect(Number(afterJson)).toBe(3);
  });
});
