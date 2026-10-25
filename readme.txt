=== PayRetry - Failed Payment Recovery for WooCommerce ===
Contributors: alsebrus
Tags: woocommerce, payment recovery, dunning, failed payments, checkout
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.4.1
License: GPLv2 or later

Automatic payment-failed reminder emails with a retry link and escalating discount.

== Description ==

When a WooCommerce payment fails, PayRetry automatically emails the customer a
reminder with a one-click retry link on day 1, 3 and 7, adding an escalating
discount coupon (5% then 10% by default) to win the order back. Failed payments
are silent revenue loss — this plugin brings it back.

Everything works out of the box with sensible defaults; no configuration or
external service is required. To customize it, go to WooCommerce -> Payment
Recovery in your wp-admin:

* Enable or disable the reminders.
* Set the reminder days (comma-separated, e.g. 1, 3, 7).
* Set the discount percentage per reminder (e.g. 0, 5, 10); a coupon for that
  percent is created automatically and appended to the retry link.
* Edit the email template. Placeholders: {name}, {link} (retry link),
  {coupon} (discount line).

The plugin works with any WooCommerce payment gateway and sends its emails
through wp_mail.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it from the WordPress repository.
2. Activate the plugin through the 'Plugins' menu.
3. Go to WooCommerce → Payment Recovery to configure the reminder schedule.

== Changelog ==

= 0.4.1 =
* Renamed all functions, hooks, options and meta keys to the payretry_ prefix (plugin review: the previous wpr prefix was too short).
* The recovery coupon is now single-use (one redemption, bound to its order).
* Apply the recovery coupon on the order-pay page itself: WooCommerce ignores ?coupon= there, so the discount promised in the reminder email now really lands.
* The Pro upsell link points at https://payretry-woo.oreviq.com/ and hides itself while the Pro add-on is active.

= 0.4.0 =
* All settings are now available to every user; removed license gating from the WordPress.org build.
* Removed the Freemius SDK and its background calls from this build; the upsell is a plain link.
* Sanitize the email template setting with sanitize_textarea_field().
* Unified the display name between readme and plugin header; dropped dev dependencies from the distribution.

= 0.3.1 =
* Renamed to PayRetry for the WordPress.org directory (WC is a reserved trademark).

= 0.3.0 =
* Upgrade banner for free users with in-dashboard checkout.

= 0.2.0 =
* Admin settings page (schedule, discounts, email template), i18n, uninstall routine, Freemius licensing.

= 0.1.0 =
* Initial release: automatic payment-failed reminders with retry link and escalating coupon.
