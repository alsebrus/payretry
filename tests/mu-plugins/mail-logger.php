<?php
/**
 * Plugin Name: WPR Mail Logger
 * Description: Test-only mu-plugin: records this plugin's reminder mails (subject "Order #N: ...") into an option.
 */

add_filter('wp_mail', function ($args) {
    if (preg_match('/Order #\d+: /', $args['subject'] ?? '')) {
        $log = get_option('wpr_mail_log', []);
        $log[] = [
            'to' => $args['to'],
            'subject' => $args['subject'],
            'body' => $args['message'] ?? '',
        ];
        update_option('wpr_mail_log', $log, false);
    }
    return $args;
});
