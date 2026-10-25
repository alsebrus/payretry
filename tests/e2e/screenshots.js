// Batch screenshots of every user-facing scenario, run AFTER e2e so the
// store holds real orders/mails. Not a test: node tests/e2e/screenshots.js
const { chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const PROJECT_ROOT = path.join(__dirname, '..', '..');
const OUT = path.join(__dirname, '..', '..', 'docs', 'screenshots');
fs.mkdirSync(OUT, { recursive: true });

const BASE = 'http://localhost:9080';

function wp(args) {
  return execFileSync('npx', ['-y', '@wordpress/env@11.17.0', 'run', 'cli', 'wp', ...args], {
    cwd: PROJECT_ROOT,
    encoding: 'utf8',
    maxBuffer: 10 * 1024 * 1024,
  });
}

const chromeCandidates = [
  process.env.CHROME_PATH,
  '/home/kiber/.nix-profile/bin/google-chrome',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
];
const executablePath = chromeCandidates.find((p) => p && fs.existsSync(p));

async function shot(page, name, opts = {}) {
  const file = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: file, fullPage: opts.fullPage ?? true });
  console.log('saved', file);
}

(async () => {
  const browser = await chromium.launch({ executablePath });

  // ---- 1. Landing (public) ----
  const pub = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const p1 = await pub.newPage();
  await p1.goto('https://payretry-woo.oreviq.com/', { waitUntil: 'networkidle' });
  await shot(p1, '1-landing-hero');
  await p1.locator('#pricing').scrollIntoViewIfNeeded();
  await shot(p1, '2-landing-free-vs-pro', { fullPage: false });

  // ---- 2. Retry link with the coupon (guest, unpaid order) ----
  const failed = JSON.parse(wp([
    'eval',
    // use a payable failed order; otherwise seed a clean one (zero-total
    // shells left by test runs have no pay form)
    `$o = null;`
    + ` foreach (wc_get_orders(['status' => 'failed', 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC']) as $cand) {`
    + `   if ($cand->needs_payment() && (float) $cand->get_total() > 0) { $o = $cand; break; }`
    + ` }`
    + ` if (!$o) {`
    + `   $pid = get_page_by_path('e2e-recovery-product', OBJECT, 'product')->ID;`
    + `   $o = wc_create_order(['billing_email' => 'e2e@example.com', 'billing_first_name' => 'E2E', 'billing_last_name' => 'Shopper']);`
    + `   $o->add_product(wc_get_product($pid), 1);`
    + `   $o->calculate_totals();`
    + `   $o->set_payment_method('payretry_test_failing');`
    + `   $o->update_status('failed', 'Screenshot seed decline.');`
    + `   $o->save();`
    + ` }`
    + ` echo json_encode(['id' => $o->get_id(), 'key' => $o->get_order_key(), 'coupon' => 'RECOVER-' . $o->get_id()]);`,
  ]).trim());
  if (failed.id) {
    // hold the order inside WC's 10-minute guest grace so the pay form (not
    // the email-verify screen) is what the screenshot shows, and make sure the
    // RECOVER coupon exists and is applied (the reminder flow would have done it)
    wp(['eval', `
      $o = wc_get_order(${failed.id});
      $o->set_date_created(time());
      $code = 'RECOVER-${failed.id}';
      if (!wc_get_coupon_id_by_code($code)) {
        $c = new WC_Coupon();
        $c->set_code($code);
        $c->set_discount_type('percent');
        $c->set_amount(10);
        $c->save();
      }
      if (!$o->get_coupon_codes()) { $o->apply_coupon($code); }
      $o->save();
      echo 'seeded';
    `]);
    const p2 = await pub.newPage();
    await p2.goto(`${BASE}/checkout/order-pay/${failed.id}/?pay_for_order=true&key=${failed.key}&coupon=${failed.coupon}`);
    try {
      await p2.waitForSelector('#order_review', { timeout: 20_000 });
    } catch (e) {
      console.log('NO FORM at', p2.url());
      console.log('body:', (await p2.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 300));
      throw e;
    }
    await shot(p2, '3-retry-link-with-coupon', { fullPage: false });
  }

  // ---- 3. Reminder email exactly as the shopper receives it ----
  const mails = JSON.parse(wp([
    'eval',
    `$log = array_values(get_option('payretry_mail_log', [])); echo json_encode($log[count($log) - 1] ?? null);`,
  ]).trim());
  if (mails) {
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const p3 = await pub.newPage();
    await p3.setContent(`<!doctype html><meta charset="utf-8">
      <body style="font:15px/1.6 system-ui;max-width:640px;margin:40px auto;color:#1a1a2e">
      <div style="border:1px solid #e4e4ec;border-radius:12px;overflow:hidden">
        <div style="background:#f7f7fa;padding:14px 20px;border-bottom:1px solid #e4e4ec;font-size:14px">
          <div><b>To:</b> ${esc(mails.to)}</div>
          <div><b>Subject:</b> ${esc(mails.subject)}</div>
        </div>
        <pre style="white-space:pre-wrap;padding:20px;margin:0;font:15px/1.6 system-ui">${esc(mails.body)}</pre>
      </div></body>`);
    await shot(p3, '4-reminder-email', { fullPage: false });
  }

  // ---- 4. Thank-you page of a recovered (paid, has items + RECOVER coupon) order ----
  const paid = JSON.parse(wp([
    'eval',
    `$o = null;`
    + ` foreach (wc_get_orders(['status' => ['processing', 'completed'], 'limit' => 30, 'orderby' => 'date', 'order' => 'DESC']) as $cand) {`
    + `   if (count($cand->get_items('line_items')) && count($cand->get_coupon_codes())) { $o = $cand; break; }`
    + ` }`
    + ` if (!$o) {`
    + `   $pid = get_page_by_path('e2e-recovery-product', OBJECT, 'product')->ID;`
    + `   $o = wc_create_order(['billing_email' => 'e2e@example.com', 'billing_first_name' => 'E2E', 'billing_last_name' => 'Shopper']);`
    + `   $o->add_product(wc_get_product($pid), 1);`
    + `   $o->calculate_totals();`
    + `   $code = 'RECOVER-' . $o->get_id();`
    + `   if (!wc_get_coupon_id_by_code($code)) { $c = new WC_Coupon(); $c->set_code($code); $c->set_discount_type('percent'); $c->set_amount(10); $c->save(); }`
    + `   $o->apply_coupon($code);`
    + `   $o->update_status('completed', 'Recovered via retry link.');`
    + `   $o->save();`
    + ` }`
    + ` echo json_encode(['id' => $o->get_id(), 'key' => $o->get_order_key()]);`,
  ]).trim());
  if (paid.id) {
    const p4 = await pub.newPage();
    await p4.goto(`${BASE}/checkout/order-received/${paid.id}/?key=${paid.key}`);
    await shot(p4, '5-order-received-recovery', { fullPage: false });
  }
  await pub.close();

  // ---- 5. Admin: settings page ----
  const adm = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const p5 = await adm.newPage();
  await p5.goto(`${BASE}/wp-login.php`);
  await p5.locator('#user_login').fill('admin');
  await p5.locator('#user_pass').fill('password');
  await p5.locator('#wp-submit').click();
  await p5.waitForURL(/wp-admin/);
  await p5.goto(`${BASE}/wp-admin/admin.php?page=payretry`);
  await p5.waitForSelector('form');
  await shot(p5, '6-admin-settings');

  // ---- 6. Admin: Pro recovery report ----
  await p5.goto(`${BASE}/wp-admin/admin.php?page=payretry-pro`);
  await p5.waitForSelector('table');
  await shot(p5, '7-admin-pro-report');

  await browser.close();
  console.log('SCREENSHOTS DONE');
})().catch((e) => { console.error(e); process.exit(1); });
