# WC Pay Recovery

WooCommerce plugin: when a payment fails, the customer gets reminder emails on
day 1, 3 and 7 with a retry link; day 3 and 7 escalate the incentive with an
automatic 5% / 10% coupon (`RECOVER-<order_id>`).

## Layout

```
wc-pay-recovery.php        the plugin (single file)
tests/unit/                PHPUnit, no WordPress needed (WP/WC stubs in bootstrap.php)
tests/integration/         runs inside a real WP+WooCommerce (wp-env + wp-cli)
tests/mu-plugins/          test-only: a gateway that always declines + wp_mail logger
tests/e2e/                 Playwright: real browser → real checkout → real DB
```

## Running the tests

### Unit (fast, no infra)

```
composer install
composer test:unit
```

### Integration (real WP + WooCommerce in Docker)

```
npx -y @wordpress/env@11.17.0 start     # pinned; first run downloads WP + WooCommerce
npx -y @wordpress/env@11.17.0 run cli wp eval-file /var/www/html/wp-content/plugins/wc-pay-recovery/tests/integration/integration-check.php
```

Exits non-zero on the first failed assertion; prints `INTEGRATION OK` on success.
Site: http://localhost:9080 (8888/8899 are taken on this machine by a Tailscale service — see `testsPort` in `.wp-env.json`).

### E2E (Playwright, real browser)

```
cd tests/e2e && npm install
npm run e2e
```

The spec seeds the store itself (classic cart/checkout pages, guest checkout,
a $10 product, the always-declining test gateway), places an order in Chrome,
gets declined, then fast-forwards 8 days and asserts the full reminder
escalation against the database. On NixOS it drives the system Google Chrome
(auto-detected in `playwright.config.js`, override with `CHROME_PATH`).

## Notes

- Always run wp-env commands from the project root — it locates `.wp-env.json`
  in the cwd only.
- `tests/mu-plugins/` is test scaffolding: never ship it in the release zip.
- Reminder emails are sent by the daily `wpr_check` cron; the tests call
  `wpr_tick()` directly instead of waiting for cron.
