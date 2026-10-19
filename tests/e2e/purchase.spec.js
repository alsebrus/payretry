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

async function fillBilling(page) {
  await page.locator('#billing_first_name').fill('E2E');
  await page.locator('#billing_last_name').fill('Shopper');
  await page.locator('#billing_country').selectOption('US');
  await page.locator('#billing_address_1').fill('1 Main Street');
  await page.locator('#billing_city').fill('Springfield');
  await page.locator('#billing_state').selectOption('CA');
  await page.locator('#billing_postcode').fill('90210');
  await page.locator('#billing_phone').fill('5551234567');
  await page.locator('#billing_email').fill('e2e@example.com');
}

test.describe('purchase & recovery scenarios', () => {
  let productId;

  test.beforeAll(() => {
    const out = execFileSync(WP_ENV, [...WP_ENV_ARGS, 'run', 'cli', 'wp', 'eval-file', '/var/www/html/wp-content/plugins/payretry/tests/e2e/seed.php'], {
      cwd: PROJECT_ROOT,
      encoding: 'utf8',
      maxBuffer: 10 * 1024 * 1024,
    });
    expect(out).toContain('SEED OK');
    productId = out.match(/product=(\d+)/)[1];
  });

  test('successful purchase via cash-on-delivery produces a paid order and no recovery mail', async ({ page }) => {
    await page.goto(`/?add-to-cart=${productId}`);
    await page.goto('/checkout/');
    await fillBilling(page);
    await page.locator('#payment_method_cod').check();
    await page.locator('#place_order').click();

    // Order-received page confirms a real, non-failed order.
    await expect(page.locator('.woocommerce-order-received, .woocommerce-thankyou-order-received').first()).toBeVisible({ timeout: 30_000 });

    const status = wp([
      'eval',
      `$o = wc_get_orders(['status' => 'failed', 'billing_email' => 'e2e@example.com', 'limit' => 1])[0] ?? null;`
      + ` $p = wc_get_orders(['status' => ['processing','completed','on-hold'], 'billing_email' => 'e2e@example.com', 'limit' => 1])[0] ?? null;`
      + ` echo json_encode(['failed' => $o ? $o->get_id() : 0, 'paid' => $p ? $p->get_status() : 'none']);`,
    ]).trim();
    const r = JSON.parse(status);
    expect(r.failed, 'COD purchase must not create a failed order').toBe(0);
    expect(['processing', 'completed', 'on-hold']).toContain(r.paid);

    // The cron must not mail a healthy order.
    const mails = wp(['eval', 'wpr_tick(); echo count(get_option("wpr_mail_log", []));']).trim();
    expect(Number(mails)).toBe(0);
  });

  test('retry after decline pays out and shows up in the Pro recovery report', async ({ page }) => {
    // 1. Declined purchase → failed order.
    await page.goto(`/?add-to-cart=${productId}`);
    await page.goto('/checkout/');
    await fillBilling(page);
    await page.locator('#payment_method_wpr_test_failing').check();
    await page.locator('#place_order').click();
    await expect(page.locator('.woocommerce-NoticeGroup').first()).toBeVisible({ timeout: 30_000 });

    const orderId = Number(wp([
      'eval',
      `echo wc_get_orders(['status' => 'failed', 'billing_email' => 'e2e@example.com', 'limit' => 1])[0]->get_id();`,
    ]).trim());

    // 2. Fast-forward so the day-7 reminder (with coupon) is due, then send it.
    wp(['eval', `$o = wc_get_order(${orderId}); $o->update_meta_data('_wpr_start', time() - 8 * DAY_IN_SECONDS); $o->update_meta_data('_wpr_stage', 0); $o->save(); wpr_tick(); wpr_tick(); wpr_tick();`]);

    const mail = JSON.parse(wp([
      'eval',
      `$log = array_values(array_filter(get_option('wpr_mail_log', []), fn($m) => str_starts_with($m['subject'], 'Order #' . ${orderId} . ':'))); echo json_encode($log[count($log) - 1]);`,
    ]).trim());
    const retryUrl = mail.body.match(/https?:\/\/\S*\/checkout\/order-pay\/\S+/)[0];
    expect(retryUrl).toContain('coupon=RECOVER-');

    // 3. The shopper retries — this time the gateway approves.
    wp(['eval', "update_option('wpr_test_gate_mode', 'success');"]);
    await page.goto(retryUrl.replace(/&amp;/g, '&'));
    await page.locator('#payment_method_wpr_test_failing').check();
    await page.locator('form button[type="submit"], button[type="submit"]').first().click();
    await expect(page.locator('.woocommerce-order-received, .woocommerce-thankyou-order-received').first()).toBeVisible({ timeout: 30_000 });

    // 4. The order is paid and carries the RECOVER coupon (that is what makes it countable).
    const paid = JSON.parse(wp([
      'eval',
      `$o = wc_get_order(${orderId}); $coupons = []; foreach ($o->get_items('coupon') as $i) { $coupons[] = $i->get_code(); }`
      + ` echo json_encode(['status' => $o->get_status(), 'coupons' => $coupons, 'total' => $o->get_total()]);`,
    ]).trim());
    expect(['processing', 'completed']).toContain(paid.status);
    expect(paid.coupons.map(c => c.toLowerCase())).toContain(`recover-${orderId}`);
    expect(Number(paid.total)).toBeLessThan(10), 'the 10% coupon must actually discount the order';

    // 5-6. The Pro report counts it — only when the Pro add-on is installed
    //      (step-by-step runs may check the free plugin alone).
    const proInstalled = wp(['eval', 'echo function_exists("wprp_summary") ? "1" : "0";']).trim() === '1';
    if (proInstalled) {
      const summary = JSON.parse(wp([
        'eval',
        `$s = wprp_summary(30); echo json_encode(['recovered' => $s['recovered'], 'revenue' => $s['revenue']]);`,
      ]).trim());
      expect(summary.recovered).toBeGreaterThanOrEqual(1);
      expect(summary.revenue).toBeGreaterThan(0);

      await page.goto('/wp-login.php');
      await page.locator('#user_login').fill('admin');
      await page.locator('#user_pass').fill('password');
      await page.locator('#wp-submit').click();
      await page.goto('/wp-admin/admin.php?page=wpr-pro');
      await expect(page.locator('h1', { hasText: 'Recovery Report' })).toBeVisible({ timeout: 30_000 });
      await expect(page.locator('h2:has-text("Recent recovered orders") + table')).toContainText('#' + orderId);
    } else {
      console.log('[skip] Pro add-on not installed — skipping Pro report assertions');
    }

    // The gate goes back to declining so later runs see deterministic state.
    wp(['eval', "update_option('wpr_test_gate_mode', 'fail');"]);
  });

  test('a retry that declines again stays failed with no extra reminders', async ({ page }) => {
    // 1. Decline, age, and burn through all three reminders (stage 7).
    await page.goto(`/?add-to-cart=${productId}`);
    await page.goto('/checkout/');
    await fillBilling(page);
    await page.locator('#payment_method_wpr_test_failing').check();
    await page.locator('#place_order').click();
    await expect(page.locator('.woocommerce-NoticeGroup').first()).toBeVisible({ timeout: 30_000 });

    const orderId = Number(wp([
      'eval',
      `echo wc_get_orders(['status' => 'failed', 'billing_email' => 'e2e@example.com', 'limit' => 1])[0]->get_id();`,
    ]).trim());
    wp(['eval', `$o = wc_get_order(${orderId}); $o->update_meta_data('_wpr_start', time() - 8 * DAY_IN_SECONDS); $o->update_meta_data('_wpr_stage', 0); $o->save(); wpr_tick(); wpr_tick(); wpr_tick();`]);
    const before = Number(wp(['eval', `$log = array_filter(get_option('wpr_mail_log', []), fn($m) => str_starts_with($m['subject'], 'Order #' . ${orderId} . ':')); echo count($log);`]).trim());
    expect(before).toBe(3);

    // 2. The retry attempt is declined again (gate stays in fail mode).
    const mail = JSON.parse(wp([
      'eval',
      `$log = array_values(array_filter(get_option('wpr_mail_log', []), fn($m) => str_starts_with($m['subject'], 'Order #' . ${orderId} . ':'))); echo json_encode($log[count($log) - 1]);`,
    ]).trim());
    const retryUrl = mail.body.match(/https?:\/\/\S*\/checkout\/order-pay\/\S+/)[0];
    await page.goto(retryUrl.replace(/&amp;/g, '&'));
    await page.locator('#payment_method_wpr_test_failing').check();
    await page.locator('form button[type="submit"], button[type="submit"]').first().click();
    // block themes print notices outside .woocommerce-NoticeGroup — assert the text
    await expect(page.getByText('declined by the test gateway')).toBeVisible({ timeout: 30_000 });

    // 3. Still failed, coupon intact, and no fourth reminder is ever sent.
    const after = JSON.parse(wp([
      'eval',
      `$o = wc_get_order(${orderId}); wpr_tick();`
      + ` $log = array_filter(get_option('wpr_mail_log', []), fn($m) => str_starts_with($m['subject'], 'Order #' . ${orderId} . ':'));`
      + ` echo json_encode(['status' => $o->get_status(), 'stage' => $o->get_meta('_wpr_stage'), 'mails' => count($log), 'coupon' => wc_get_coupon_id_by_code('RECOVER-${orderId}')]);`,
    ]).trim());
    expect(after.status).toBe('failed');
    expect(Number(after.stage)).toBe(0), 'a declined retry restarts the reminder cycle (fresh _wpr_start)';
    expect(Number(after.mails)).toBe(before, 'no extra reminder right after a declined retry');
    expect(Number(after.coupon)).toBeGreaterThan(0);
  });
});
