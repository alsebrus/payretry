// Step 6: buyer journey — upsell link in free settings -> landing -> sandbox checkout.
// Run: node tests/e2e/step6-buy-pro.js  (prints a JSON report)
const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = 'http://localhost:9080';
const LANDING = 'https://payretry-woo.oreviq.com/';
const exe = [process.env.CHROME_PATH, '/home/kiber/.nix-profile/bin/google-chrome', '/usr/bin/google-chrome'].find((p) => p && fs.existsSync(p));

(async () => {
  const browser = await chromium.launch({ executablePath: exe });
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const report = {};

  // ---- 1. admin: free settings page, the upsell link ----
  await page.goto(`${BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('password');
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/);
  await page.goto(`${BASE}/wp-admin/admin.php?page=wpr`);
  await page.waitForSelector('form');
  const upsell = page.locator('a:has-text("PayRetry Pro")').first();
  report.upsell_link_visible = await upsell.isVisible().catch(() => false);
  report.upsell_href = report.upsell_link_visible ? (await upsell.getAttribute('href')) : null;

  // ---- 2. follow it to the landing ----
  await upsell.click();
  await page.waitForURL(/oreviq\.com/, { timeout: 20000 });
  await page.waitForLoadState('networkidle').catch(() => {});
  report.landed_on = page.url();

  // ---- 3. click the Pro annual CTA ----
  const buy = page.locator('a:has-text("Buy Annual")').first();
  report.buy_cta_visible = await buy.isVisible().catch(() => false);
  const buyHref = await buy.getAttribute('href');
  report.buy_href = buyHref;
  // same link a real buyer follows, switched into Freemius sandbox for the test payment
  const sandboxUrl = buyHref + (buyHref.includes('?') ? '&' : '?') + 'sandbox=true';
  await page.goto(sandboxUrl);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForSelector('#user_email', { timeout: 20000 });
  report.checkout_title = await page.title();

  // ---- 4. test payment via their sandbox prefill ----
  await page.locator('button:has-text("Prefill Form")').click().catch(() => {});
  await page.waitForTimeout(2500);
  const filled = await page.$$eval('#user_email, #billing_cc', (els) => els.map((e) => !!e.value));
  report.prefill_filled = filled.every(Boolean);
  await page.locator('button:has-text("Review Order"), input[value="Review Order"]').first().click();
  await page.waitForTimeout(4000);
  const payBtn = page.locator('button:visible:has-text("Pay & Subscribe"), input[value*="Pay"]:visible').first();
  await payBtn.click();
  await page.waitForSelector('text=Subscription was successful', { timeout: 30000 }).catch(() => {});
  report.payment_successful = (await page.locator('text=Subscription was successful').count()) > 0;

  await browser.close();
  console.log(JSON.stringify(report, null, 2));
})().catch((e) => { console.error(e); process.exit(1); });
