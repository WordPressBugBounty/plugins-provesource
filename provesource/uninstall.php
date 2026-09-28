<?php

/**
 * Runs when the plugin is deleted: tells ProveSource the site is gone (best effort, it never blocks
 * the uninstall) and removes the plugin's options, transients, debug log and queued jobs.
 * The main plugin file is not loaded here, so option names are listed directly.
 *
 * @package provesource
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function provesrc_uninstall_site()
{
    $apiKey = get_option('provesrc_api_key');
    if (!empty($apiKey)) {
        $plugin = get_file_data(__DIR__ . '/provesrc.php', array('version' => 'Version'));
        wp_remote_post((defined('PROVESRC_HOST') ? PROVESRC_HOST : 'https://api.provesrc.com') . '/wp/uninstall', array(
            'timeout' => 3,
            'blocking' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'authorization' => "Bearer $apiKey",
                'x-webhook-secret' => (string) get_option('provesrc_webhook_secret'),
                'x-api-version' => 2,
                'x-plugin-version' => $plugin['version'],
            ),
            'body' => wp_json_encode(array(
                'siteUrl' => get_site_url(),
                'woocommerce' => class_exists('WooCommerce'),
            )),
        ));
    }

    $options = array(
        'provesrc_api_key',
        'provesrc_webhook_secret',
        'provesrc_debug',
        'provesrc_events',
        'provesrc_tos_accepted',
        'provesrc_version',
        'provesrc_debug_log',
        'provesrc_analytics_consent',
        'provesrc_reviews_import',
    );
    foreach ($options as $option) {
        delete_option($option);
    }
    $transients = array('provesrc_api_error', 'provesrc_success_message', 'provesrc_last_import_time', 'provesrc_last_reviews_import_time');
    foreach ($transients as $transient) {
        delete_transient($transient);
    }
    // orders and reviews still waiting to be sent
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('provesrc_send_order');
        as_unschedule_all_actions('provesrc_send_review');
        as_unschedule_all_actions('provesrc_import_reviews');
    }
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $provesrcSiteId) {
        switch_to_blog($provesrcSiteId);
        provesrc_uninstall_site();
        restore_current_blog();
    }
} else {
    provesrc_uninstall_site();
}

// 4.x kept its debug log in the plugin folder
$provesrcLegacyLog = __DIR__ . '/debug.log';
if (file_exists($provesrcLegacyLog)) {
    wp_delete_file($provesrcLegacyLog);
}
