// Step 5: the customer follows the retry link from the email and pays.
// Run: node tests/e2e/step5-retry-payment.js  (prints a JSON report)
const { chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const BASE = 'http://localhost:9080';
const exe = [process.env.CHROME_PATH, '/home/kiber/.nix-profile/bin/google-chrome', '/usr/bin/google-chrome'].find((p) => p && fs.existsSync(p));

function wpEval(code) {
  return execFileSync('npx', ['-y', '@wordpress/env@11.17.0', 'run', 'cli', 'wp', 'eval', code], {
    cwd: ROOT, encoding: 'utf8', maxBuffer: 10 * 1024 * 1024,
  });
}

(async () => {
  const report = { order: 196 };

  // the exact link from the last email
  const mail = JSON.parse(wpEval(
    `$log = array_filter(get_option('wpr_mail_log', []), fn($m) => str_contains($m['subject'], 'Order #196'));`
    + `$log = array_values($log); echo json_encode($log[count($log) - 1]);`
  ).trim());
  report.email_link = mail.body.match(/https?:\/\/\S+/)[0].replace(/&amp;/g, '&');

  // this time the gateway approves (the customer's card works on retry)
  wpEval("update_option('wpr_test_gate_mode', 'success');");

  const browser = await chromium.launch({ executablePath: exe });
  const page = await browser.newPage();
  await page.goto(report.email_link);

  // WooCommerce asks a guest to confirm the order's email when >10 min old
  const verifyField = page.locator('input[name="email"], input#email').first();
  if (await verifyField.isVisible({ timeout: 5000 }).catch(() => false)) {
    report.saw_email_verification = true;
    await verifyField.fill('step@example.com');
    const form = page.locator('form').filter({ has: page.locator('input[name="email"]') }).first();
    await form.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForSelector('#order_review', { timeout: 20000 });
  } else {
    report.saw_email_verification = false;
  }

  await page.waitForSelector('#order_review', { timeout: 20000 });
  report.total_before_pay = (await page.locator('table.woocommerce-table--order-details, table').first().innerText())
    .replace(/\s+/g, ' ').match(/Total:.{0,40}/)?.[0] ?? null;

  await page.locator('#payment_method_wpr_test_failing').check();
  await page.locator('form button[type="submit"], button[type="submit"]').first().click();
  report.thankyou_page = await page.locator('.woocommerce-order-received, .woocommerce-thankyou-order-received').first()
    .waitFor({ timeout: 30000 }).then(() => true).catch(() => false);

  await browser.close();

  // final order state
  report.final = JSON.parse(wpEval(
    `$o = wc_get_order(196); $coupons = [];`
    + ` foreach ($o->get_items('coupon') as $i) { $coupons[] = $i->get_code(); }`
    + ` echo json_encode(['status' => $o->get_status(), 'total' => $o->get_total(), 'coupons' => $coupons, 'stage' => $o->get_meta('_wpr_stage')]);`
  ).trim());
  report.recovery_report = JSON.parse(wpEval(
    `$s = wprp_summary(30); echo json_encode(['recovered' => $s['recovered'], 'revenue' => $s['revenue'], 'rate' => $s['rate']]);`
  ).trim());

  // deterministic state for whatever comes next
  wpEval("update_option('wpr_test_gate_mode', 'fail');");

  console.log(JSON.stringify(report, null, 2));
})().catch(async (e) => {
  console.error(e);
  process.exit(1);
});
