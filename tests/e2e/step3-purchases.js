// Step-by-step manual check: one successful purchase, one declined purchase.
// Run: node tests/e2e/step3-purchases.js  (prints a JSON report)
const { chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const BASE = 'http://localhost:9080';
const exe = [process.env.CHROME_PATH, '/home/kiber/.nix-profile/bin/google-chrome', '/usr/bin/google-chrome'].find((p) => p && fs.existsSync(p));

function wp(args) {
  return execFileSync('npx', ['-y', '@wordpress/env@11.17.0', 'run', 'cli', 'wp', ...args], {
    cwd: ROOT, encoding: 'utf8', maxBuffer: 10 * 1024 * 1024,
  });
}
function wpEval(code) {
  return wp(['eval', code]);
}

async function fillBilling(page) {
  await page.locator('#billing_first_name').fill('Step');
  await page.locator('#billing_last_name').fill('Tester');
  await page.locator('#billing_country').selectOption('US');
  await page.locator('#billing_address_1').fill('1 Main Street');
  await page.locator('#billing_city').fill('Springfield');
  await page.locator('#billing_state').selectOption('CA');
  await page.locator('#billing_postcode').fill('90210');
  await page.locator('#billing_phone').fill('5551234567');
  await page.locator('#billing_email').fill('step@example.com');
}

(async () => {
  const productId = wpEval("echo get_page_by_path('e2e-recovery-product', OBJECT, 'product')->ID;").trim();
  const browser = await chromium.launch({ executablePath: exe });
  const report = {};

  // ---------- A. successful purchase (cash on delivery) ----------
  let page = await browser.newPage();
  await page.goto(`${BASE}/?add-to-cart=${productId}`);
  await page.goto(`${BASE}/checkout/`);
  await fillBilling(page);
  await page.locator('#payment_method_cod').check();
  await page.locator('#place_order').click();
  const thankyouVisible = await page.locator('.woocommerce-order-received, .woocommerce-thankyou-order-received').first()
    .waitFor({ timeout: 30000 }).then(() => true).catch(() => false);
  report.successful = {
    thankyou_page: thankyouVisible,
    order: JSON.parse(wpEval(
      `$o = wc_get_orders(['billing_email' => 'step@example.com', 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC'])[0] ?? null;`
      + ` echo $o ? json_encode(['id' => $o->get_id(), 'status' => $o->get_status(), 'total' => $o->get_total()]) : 'none';`
    ).trim()),
  };
  await page.close();

  // ---------- B. declined purchase (test gateway) ----------
  page = await browser.newPage();
  await page.goto(`${BASE}/?add-to-cart=${productId}`);
  await page.goto(`${BASE}/checkout/`);
  await fillBilling(page);
  await page.locator('#payment_method_wpr_test_failing').check();
  await page.locator('#place_order').click();
  const declineNotice = await page.getByText('declined by the test gateway').first()
    .waitFor({ timeout: 30000 }).then(() => true).catch(() => false);
  report.declined = {
    decline_notice_shown: declineNotice,
    order: JSON.parse(wpEval(
      `$o = wc_get_orders(['billing_email' => 'step@example.com', 'status' => 'failed', 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC'])[0] ?? null;`
      + ` echo $o ? json_encode(['id' => $o->get_id(), 'status' => $o->get_status(), 'total' => $o->get_total(), 'stage' => $o->get_meta('_wpr_stage')]) : 'none';`
    ).trim()),
  };
  await page.close();

  await browser.close();
  console.log(JSON.stringify(report, null, 2));
})().catch((e) => { console.error(e); process.exit(1); });
