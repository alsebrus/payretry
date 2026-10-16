=== WC Pay Recovery ===
Contributors: alsebrus
Tags: woocommerce, payment recovery, dunning, failed payments, checkout
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later

Automatic payment-failed reminder emails with a retry link and escalating discount.

== Description ==

When a WooCommerce payment fails, WC Pay Recovery automatically emails the
customer a reminder with a one-click retry link on day 1, 3 and 7 (configurable),
adding an escalating discount coupon (5% then 10% by default) to win the order
back. Failed payments are silent revenue loss — this plugin brings it back.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it from the WordPress repository.
2. Activate the plugin through the 'Plugins' menu.
3. Go to WooCommerce → Payment Recovery to configure the reminder schedule.

== Changelog ==

= 0.2.0 =
* Admin settings page (schedule, discounts, email template), i18n, uninstall routine, Freemius licensing.

= 0.1.0 =
* Initial release: automatic payment-failed reminders with retry link and escalating coupon.
