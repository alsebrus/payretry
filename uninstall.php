<?php
// Exit when accessed directly.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpr_settings');
wp_clear_scheduled_hook('wpr_check');

// Remove the plugin's generated coupons (RECOVER-<order_id>).
$ids = get_posts(['post_type' => 'shop_coupon', 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any']);
foreach ($ids as $id) {
    if (str_starts_with(strtolower(get_the_title($id)), 'recover-')) {
        wp_delete_post($id, true);
    }
}
