// Throwaway: what does the order-pay form actually POST, and what comes back?
const { chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const exe = [process.env.CHROME_PATH, '/home/kiber/.nix-profile/bin/google-chrome', '/usr/bin/google-chrome'].find((p) => p && fs.existsSync(p));

(async () => {
  const out = execFileSync('npx', ['-y', '@wordpress/env@11.17.0', 'run', 'cli', 'wp', 'eval',
    '$o = wc_get_orders(["status"=>"failed","limit"=>1,"orderby"=>"date","order"=>"DESC"])[0] ?? null; echo $o ? $o->get_checkout_payment_url() . "|RECOVER-" . $o->get_id() : "none";'],
    { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024, cwd: path.join(__dirname, '..', '..') }).trim();
  const [url, coupon] = out.slice(out.indexOf('http')).split('|');
  // stay inside WooCommerce's 10-minute guest-verification grace period
  execFileSync('npx', ['-y', '@wordpress/env@11.17.0', 'run', 'cli', 'wp', 'eval',
    `$o = wc_get_order(${(url.match(/order-pay\/(\d+)/) || [])[1]}); $o->set_date_created(time()); $o->save(); echo 'fresh';`],
    { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024, cwd: path.join(__dirname, '..', '..') });

  const b = await chromium.launch({ executablePath: exe });
  const p = await b.newPage();
  let postBody = null;
  p.on('request', (r) => { if (r.method() === 'POST') postBody = r.postData(); });
  console.log('goto:', `${url}&coupon=${coupon}`);
  await p.goto(`${url}&coupon=${coupon}`);
  console.log('landed:', p.url());
  try {
    await p.waitForSelector('#order_review', { timeout: 15000 });
  } catch (e) {
    console.log('NO FORM. body head:', (await p.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 400));
    throw e;
  }
  await p.locator('#payment_method_payretry_test_failing').check();
  // log non-secret form field NAMES and payment selection
  const fields = await p.$$eval('#order_review input', (els) => els.map((e) => ({ name: e.name, type: e.type, checked: e.checked })).filter((x) => x.name && x.type !== 'hidden').concat(
    [...document.querySelectorAll('#order_review input[type=hidden]')].map((e) => ({ name: e.name, type: 'hidden', value: e.value ? '<set>' : '<empty>' }))
  )).catch((e) => String(e));
  console.log('visible/hidden fields:', JSON.stringify(fields));
  const submit = p.locator('form button[type="submit"], button[type="submit"]').first();
  await submit.click();
  await p.waitForTimeout(5000);
  console.log('POST keys:', postBody ? decodeURIComponent(postBody).split('&').map((kv) => kv.split('=')[0]).join(',') : 'none');
  const afterTxt = (await p.locator('body').innerText()).replace(/\s+/g, ' ');
  console.log('decline text present:', afterTxt.includes('declined by the test gateway'));
  console.log('notice-ish:', JSON.stringify(await p.$$eval('[class*=Notice], [class*=notice], [role=alert]', (els) => els.map((e) => ({ cls: (e.className || '').toString().slice(0, 70), txt: (e.innerText || '').replace(/\s+/g, ' ').slice(0, 80) })))));
  console.log('final url:', p.url());
  console.log('notice:', await p.locator('.woocommerce-NoticeGroup, .woocommerce-error').count());
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
