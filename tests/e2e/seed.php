<?php
// Seed a deterministic store for E2E: classic shortcode cart/checkout pages,
// guest checkout, a purchasable product. Run: wp eval-file tests/e2e/seed.php

$pages = [
    'cart' => ['Cart', '[woocommerce_cart]'],
    'checkout' => ['Checkout', '[woocommerce_checkout]'],
    'my-account' => ['My account', '[woocommerce_my_account]'],
];
foreach ($pages as $slug => [$title, $content]) {
    $existing = get_page_by_path($slug, OBJECT, 'page');
    $id = $existing ? $existing->ID : wp_insert_post([
        'post_title' => $title,
        'post_name' => $slug,
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => $content,
    ]);
    wp_update_post(['ID' => $id, 'post_content' => $content]); // strip any block markup
    update_option('woocommerce_' . ($slug === 'my-account' ? 'myaccount' : $slug) . '_page_id', $id);
}
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_store_pages_only', 'no');
update_option('woocommerce_enable_checkout_login_reminder', 'no');
update_option('woocommerce_enable_signup_and_login_from_checkout', 'no');
update_option('woocommerce_default_country', 'US:CA');

$product = get_page_by_path('e2e-recovery-product', OBJECT, 'product');
if (!$product) {
    $pid = wp_insert_post([
        'post_title' => 'E2E Recovery Product',
        'post_name' => 'e2e-recovery-product',
        'post_type' => 'product',
        'post_status' => 'publish',
    ]);
    wp_set_object_terms($pid, 'simple', 'product_type');
    update_post_meta($pid, '_regular_price', '10');
    update_post_meta($pid, '_price', '10');
    update_post_meta($pid, '_manage_stock', 'no');
    update_post_meta($pid, '_visibility', 'visible');
}

// A second, always-approving gateway so E2E can model a successful purchase.
update_option('woocommerce_cod_settings', [
    'enabled'       => 'yes',
    'title'         => 'Cash on delivery',
    'description'   => 'Pay when you receive the order.',
    'instructions'  => '',
]);
// Deterministic test-gateway behaviour for every run: start by declining.
update_option('wpr_test_gate_mode', 'fail');

// Reset state from previous runs.
delete_option('wpr_mail_log');
foreach (wc_get_orders(['billing_email' => 'e2e@example.com', 'limit' => -1]) as $o) {
    $o->delete(true);
}
echo "SEED OK product=" . get_page_by_path('e2e-recovery-product', OBJECT, 'product')->ID . "\n";
