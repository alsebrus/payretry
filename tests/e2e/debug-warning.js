// Throwaway: is the PHP deprecation on order-pay caused by our coupon hook?
const { chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');

const exe = [process.env.CHROME_PATH, '/home/kiber/.nix-profile/bin/google-chrome', '/usr/bin/google-chrome'].find((p) => p && fs.existsSync(p));

(async () => {
  const out = execFileSync('npx', ['-y', '@wordpress/env@11.17.0', 'run', 'cli', 'wp', 'eval',
    '$o = wc_get_orders(["status"=>"failed","limit"=>1,"orderby"=>"date","order"=>"DESC"])[0] ?? null; echo $o ? $o->get_checkout_payment_url() : "none";'],
    { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024, cwd: require('path').join(__dirname, '..', '..') });
  const url = out.match(/http\S*order-pay\S*/)[0];
  const b = await chromium.launch({ executablePath: exe });
  for (const variant of [url, url + '&coupon=RECOVER-1']) {
    const p = await b.newPage();
    await p.goto(variant).catch(() => {});
    await p.waitForSelector('#order_review').catch(() => {});
    const txt = (await p.locator('body').innerText()).replace(/\s+/g, ' ');
    const has = txt.includes('Deprecated');
    console.log(variant.includes('coupon') ? 'WITH coupon:' : 'WITHOUT coupon:', has ? 'WARNING PRESENT' : 'no warning');
    if (has) console.log('  ctx:', txt.slice(Math.max(0, txt.indexOf('Deprecated') - 60), txt.indexOf('Deprecated') + 180));
    await p.close();
  }
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
