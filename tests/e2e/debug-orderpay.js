// Throwaway: reproduce the order-pay decline submit and log what the server does.
const { chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PROJECT_ROOT = path.join(__dirname, '..', '..');
function wp(args) {
  return execFileSync('npx', ['-y', '@wordpress/env@11.17.0', 'run', 'cli', 'wp', ...args], {
    cwd: PROJECT_ROOT, encoding: 'utf8', maxBuffer: 10 * 1024 * 1024,
  });
}
const executablePath = [process.env.CHROME_PATH, '/home/kiber/.nix-profile/bin/google-chrome', '/usr/bin/google-chrome'].find((p) => p && fs.existsSync(p));

(async () => {
  const url = wp(['eval',
    `$o = wc_get_orders(['status'=>'failed','limit'=>1,'orderby'=>'date','order'=>'DESC'])[0] ?? null;`
    + ` echo $o ? $o->get_checkout_payment_url() . '&coupon=RECOVER-' . $o->get_id() : 'none';`]).trim();
  console.log('pay url:', url.slice(0, 130));

  const browser = await chromium.launch({ executablePath });
  const page = await browser.newPage();
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') console.log('[console]', m.type(), m.text().slice(0, 200)); });
  page.on('response', (r) => { if (r.request().method() === 'POST') console.log('[POST]', r.status(), r.url().slice(0, 130), '->', (r.headers()['location'] || '').slice(0, 130)); });
  page.on('pageerror', (e) => console.log('[pageerror]', String(e).slice(0, 300)));

  await page.goto(url);
  await page.waitForSelector('#order_review');
  const radio = await page.locator('#payment_method_payretry_test_failing').count();
  console.log('gateway radio present:', radio);
  await page.locator('#payment_method_payretry_test_failing').check();
  const submit = page.locator('form button[type="submit"], button[type="submit"]').first();
  console.log('submit text:', await submit.innerText());
  await submit.click();
  await page.waitForTimeout(6000);
  console.log('final url:', page.url());
  const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  console.log('notice group:', await page.locator('.woocommerce-NoticeGroup').count());
  console.log('body head:', body.slice(0, 400));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
