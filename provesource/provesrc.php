<?php

/**
 * Plugin Name: ProveSource Social Proof
 * Description: ProveSource is a social proof marketing platform that works with your WordPress and WooCommerce websites out of the box
 * Version: 5.0.0
 * Author: ProveSource
 * Author URI: https://provesrc.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: provesource
 * Requires at least: 4.7
 * Requires PHP: 7.4
 *
 * WC requires at least: 3.0
 * WC tested up to: 11.1
 *
 * @package provesource
 */

if (!defined('ABSPATH')) {
    die;
}

// both can be set in wp-config.php to test against a local ProveSource
if (!defined('PROVESRC_HOST')) {
    define('PROVESRC_HOST', 'https://api.provesrc.com');
}
if (!defined('PROVESRC_CONSOLE')) {
    define('PROVESRC_CONSOLE', 'https://console.provesrc.com');
}
define('PROVESRC_VERSION', '5.0.0');
define('PROVESRC_OPTIONS_GROUP', 'provesrc_options');

define('PROVESRC_OPTION_API_KEY', 'provesrc_api_key');
define('PROVESRC_OPTION_WEBHOOK_SECRET', 'provesrc_webhook_secret');
define('PROVESRC_OPTION_DEBUG_KEY', 'provesrc_debug');
define('PROVESRC_OPTION_EVENTS_KEY', 'provesrc_events');
define('PROVESRC_OPTION_TOS_KEY', 'provesrc_tos_accepted');
define('PROVESRC_OPTION_VERSION_KEY', 'provesrc_version');
define('PROVESRC_OPTION_LOG_KEY', 'provesrc_debug_log');

define('PROVESRC_LOG_MAX_LINES', 500);
define('PROVESRC_ORDER_SENT_META', '_provesrc_sent');
define('PROVESRC_SEND_ORDER_ACTION', 'provesrc_send_order');
define('PROVESRC_ACTION_GROUP', 'provesrc');

require_once __DIR__ . '/includes/reviews.php';

/** hooks */

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});
add_action('plugins_loaded', 'provesrc_maybe_upgrade');
add_action('admin_menu', 'provesrc_admin_menu');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'provesrc_plugin_action_links');
add_action('admin_init', 'provesrc_admin_init');
add_action('admin_enqueue_scripts', 'provesrc_admin_enqueue_scripts');
add_action('admin_notices', 'provesrc_admin_notice_html');
add_action('wp_head', 'provesrc_inject_code');

// every order event is hooked, the handler only queues the order when that event is selected in the settings
add_action('woocommerce_new_order', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_thankyou', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_checkout_order_processed', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_store_api_checkout_order_processed', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_order_status_pending', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_order_status_processing', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_order_status_completed', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_payment_complete', 'provesrc_woocommerce_hook_handler', 999, 1);

add_action(PROVESRC_SEND_ORDER_ACTION, 'provesrc_send_order', 10, 1);

add_action('wp_ajax_provesrc_import_orders', 'provesrc_import_orders');
add_action('wp_ajax_provesrc_debug_log', 'provesrc_debug_log');
add_action('admin_post_provesrc_connect', 'provesrc_connect_start');

function provesrc_admin_menu()
{
    add_menu_page('ProveSource Settings', 'ProveSource', 'manage_options', 'provesrc', 'provesrc_admin_menu_page_html', 'dashicons-provesrc');
}

function provesrc_admin_enqueue_scripts($hookSuffix)
{
    // the menu icon and the admin notices show on every admin page
    wp_enqueue_style('dashicons-provesrc', plugin_dir_url(__FILE__) . 'assets/css/dashicons-provesrc.css', array(), PROVESRC_VERSION);
    wp_add_inline_style('dashicons-provesrc', '.ps-error { color: red; }');
    if ($hookSuffix !== 'toplevel_page_provesrc') {
        return;
    }
    wp_enqueue_style('provesrc_admin_style', plugin_dir_url(__FILE__) . 'style.css', array(), PROVESRC_VERSION);
    wp_enqueue_script('provesrc_admin', plugin_dir_url(__FILE__) . 'assets/js/admin.js', array('jquery'), PROVESRC_VERSION, true);
    wp_localize_script('provesrc_admin', 'provesrcAdmin', array(
        'debugLogNonce' => wp_create_nonce('provesrc_debug_log_nonce'),
        'importOrdersNonce' => wp_create_nonce('provesrc_import_orders_nonce'),
        'importReviewsNonce' => wp_create_nonce('provesrc_import_reviews_nonce'),
        'validApiKey' => provesrc_isvalid_api_key(provesrc_get_api_key()),
    ));
}

function provesrc_admin_init()
{
    provesrc_connect_callback();

    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_OPTION_API_KEY, array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_OPTION_WEBHOOK_SECRET, array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_OPTION_DEBUG_KEY, array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_OPTION_EVENTS_KEY, array(
        'type' => 'array',
        'sanitize_callback' => 'provesrc_sanitize_events_array',
    ));
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_OPTION_TOS_KEY, array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));

    // the settings form posts to options.php, run the ProveSource setup before WordPress saves the new values
    if (!isset($_POST['option_page']) || sanitize_text_field(wp_unslash($_POST['option_page'])) !== PROVESRC_OPTIONS_GROUP) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), PROVESRC_OPTIONS_GROUP . '-options')) {
        wp_die('Security check failed. Please try again.');
    }

    $apiKey = isset($_POST[PROVESRC_OPTION_API_KEY]) ? sanitize_text_field(wp_unslash($_POST[PROVESRC_OPTION_API_KEY])) : '';
    $webhookSecret = isset($_POST[PROVESRC_OPTION_WEBHOOK_SECRET]) ? sanitize_text_field(wp_unslash($_POST[PROVESRC_OPTION_WEBHOOK_SECRET])) : '';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- provesrc_sanitize_events_array() keeps only known event names
    $events = isset($_POST[PROVESRC_OPTION_EVENTS_KEY]) ? provesrc_sanitize_events_array(wp_unslash($_POST[PROVESRC_OPTION_EVENTS_KEY])) : array();
    $tosAccepted = isset($_POST[PROVESRC_OPTION_TOS_KEY]) && rest_sanitize_boolean(sanitize_text_field(wp_unslash($_POST[PROVESRC_OPTION_TOS_KEY])));

    if (!$tosAccepted) {
        add_settings_error(
            PROVESRC_OPTION_API_KEY,
            'tos_not_accepted',
            'You must accept the Terms of Service to use ProveSource.',
            'error'
        );
        return;
    }
    if (empty($apiKey) || empty($webhookSecret)) {
        return;
    }

    // the recent orders are imported only when the account changes
    $sendOrders = provesrc_get_api_key() !== $apiKey || provesrc_get_webhook_secret() !== $webhookSecret;
    provesrc_api_key_updated($apiKey, $webhookSecret, $sendOrders, $events);
}

/**
 * One-time data migrations, runs once after the plugin is installed or updated.
 */
function provesrc_maybe_upgrade()
{
    $installedVersion = get_option(PROVESRC_OPTION_VERSION_KEY);
    if ($installedVersion === PROVESRC_VERSION) {
        return;
    }
    if (!$installedVersion || version_compare($installedVersion, '5.0.0', '<')) {
        $selectedEvents = get_option(PROVESRC_OPTION_EVENTS_KEY);
        if (is_array($selectedEvents) && $selectedEvents) {
            $sortedEvents = array_values(array_unique($selectedEvents));
            sort($sortedEvents);
            if ($sortedEvents === array('woocommerce_checkout_order_processed', 'woocommerce_order_status_completed')) {
                // the 4.x default missed orders from checkouts that skip the checkout hooks, move it to the new default
                update_option(PROVESRC_OPTION_EVENTS_KEY, provesrc_default_events());
            } elseif (in_array('woocommerce_checkout_create_order', $selectedEvents, true)) {
                // "Checkout Order Created" fired before the order was saved, "Checkout Order Processed" replaces it
                $selectedEvents = array_diff($selectedEvents, array('woocommerce_checkout_create_order'));
                $selectedEvents[] = 'woocommerce_checkout_order_processed';
                update_option(PROVESRC_OPTION_EVENTS_KEY, array_values(array_unique($selectedEvents)));
            }
        }
        // the analytics consent option was never used
        delete_option('provesrc_analytics_consent');
        // 4.x wrote a publicly readable debug.log into the plugin folder
        $legacyLog = plugin_dir_path(__FILE__) . 'debug.log';
        if (file_exists($legacyLog)) {
            wp_delete_file($legacyLog);
        }
    }
    update_option(PROVESRC_OPTION_VERSION_KEY, PROVESRC_VERSION);
}

function provesrc_inject_code()
{
    $apiKey = provesrc_get_api_key();
    if (empty($apiKey)) {
        return;
    }
    ?>

    <!-- Start of Async ProveSource Code (Wordpress / Woocommerce v<?php echo esc_html(PROVESRC_VERSION); ?>) --><script>!function(o,i){window.provesrc&&window.console&&console.error&&console.error("ProveSource is included twice in this page."),provesrc=window.provesrc={dq:[],display:function(){this.dq.push(arguments)}},o._provesrcAsyncInit=function(){provesrc.init({apiKey:"<?php echo esc_js($apiKey); ?>",v:"0.0.4"})};var r=i.createElement("script");r.async=!0,r["ch"+"ar"+"set"]="UTF-8",r.src="https://cdn.provesrc.com/provesrc.js";var e=i.getElementsByTagName("script")[0];e.parentNode.insertBefore(r,e)}(window,document);</script><!-- End of Async ProveSource Code -->
    <?php
}

function provesrc_woocommerce_hook_handler($orderOrId)
{
    $currentEvent = current_filter();
    // the block checkout (Store API) has its own hook, the "Checkout Order Processed" setting covers both checkouts
    $settingEvent = $currentEvent === 'woocommerce_store_api_checkout_order_processed' ? 'woocommerce_checkout_order_processed' : $currentEvent;
    $selectedEvents = provesrc_get_selected_events();
    if (!in_array($settingEvent, $selectedEvents, true)) {
        provesrc_log('order handler skipping event', array('current' => $currentEvent, 'selected' => $selectedEvents));
        return;
    }
    $orderId = is_a($orderOrId, 'WC_Abstract_Order') ? $orderOrId->get_id() : absint($orderOrId);
    try {
        provesrc_queue_order($orderId, $currentEvent);
    } catch (Throwable $err) {
        provesrc_handle_error('Failed to process order from event: ' . $currentEvent, $err, $orderId);
    }
}

/**
 * Queues an order to be sent in the background. An order is sent once: the first selected event queues it, and
 * later events skip it once the order meta PROVESRC_ORDER_SENT_META marks it as sent.
 */
function provesrc_queue_order($orderId, $event)
{
    static $queuedInRequest = array();
    if ($orderId < 1 || isset($queuedInRequest[$orderId]) || !provesrc_is_connected()) {
        return;
    }
    $order = wc_get_order($orderId);
    if (!is_a($order, 'WC_Order')) {
        return;
    }
    if ($order->get_meta(PROVESRC_ORDER_SENT_META)) {
        provesrc_log('order already sent', array('orderId' => $orderId, 'event' => $event));
        return;
    }
    if (!provesrc_can_send_order($order)) {
        provesrc_log('skipping order', array('orderId' => $orderId, 'event' => $event, 'status' => $order->get_status()));
        return;
    }
    $queuedInRequest[$orderId] = true;

    $args = array($orderId);
    if (function_exists('as_enqueue_async_action')) {
        if (provesrc_is_action_queued(PROVESRC_SEND_ORDER_ACTION, $args)) {
            provesrc_log('order already queued', array('orderId' => $orderId, 'event' => $event));
            return;
        }
        as_enqueue_async_action(PROVESRC_SEND_ORDER_ACTION, $args, PROVESRC_ACTION_GROUP);
        provesrc_log('order queued', array('orderId' => $orderId, 'event' => $event));
        return;
    }

    // Action Scheduler ships with WooCommerce 3.5+, older stores send during the request
    provesrc_log('action scheduler unavailable, sending order now', array('orderId' => $orderId, 'event' => $event));
    try {
        provesrc_send_order($orderId);
    } catch (Exception $err) {
        // already logged by provesrc_send_order, never break the checkout
    }
}

/**
 * Sends one order to ProveSource. Runs as an Action Scheduler job (or inline when it is unavailable).
 * A failed request throws so the job is marked failed, the order stays unsent and the next selected
 * event for it queues it again.
 */
function provesrc_send_order($orderId)
{
    $order = wc_get_order($orderId);
    if (!is_a($order, 'WC_Order')) {
        provesrc_log('queued order not found', array('orderId' => $orderId));
        return;
    }
    if ($order->get_meta(PROVESRC_ORDER_SENT_META)) {
        provesrc_log('order already sent', array('orderId' => $orderId));
        return;
    }
    // the status can change between queueing and sending, a declined payment turns a pending order to failed
    if (!provesrc_can_send_order($order)) {
        provesrc_log('order not sent', array('orderId' => $orderId, 'status' => $order->get_status()));
        return;
    }
    try {
        $data = provesrc_get_order_payload($order);
    } catch (Throwable $err) {
        provesrc_handle_error('failed to build order payload', $err, $orderId);
        return;
    }
    if (empty($data['products'])) {
        provesrc_log('order has no products, not sent', array('orderId' => $orderId));
        return;
    }

    $res = provesrc_send_request('/webhooks/track/woocommerce', $data);
    $responseCode = (int) wp_remote_retrieve_response_code($res);
    if (is_wp_error($res) || $responseCode < 200 || $responseCode >= 300) {
        $reason = is_wp_error($res) ? $res->get_error_message() : 'status ' . $responseCode;
        provesrc_log('order webhook failed', array('orderId' => $orderId, 'reason' => $reason));
        throw new Exception('ProveSource webhook failed for order ' . absint($orderId) . ': ' . esc_html($reason));
    }

    provesrc_mark_order_sent($order);
    provesrc_log('order sent', array('orderId' => $orderId, 'status' => $responseCode));
}

function provesrc_mark_order_sent($order)
{
    $order->update_meta_data(PROVESRC_ORDER_SENT_META, time());
    $order->save_meta_data();
}

/**
 * Drafts are block checkouts still in progress, the rest are unpaid or reversed orders.
 */
function provesrc_can_send_order($order)
{
    return !in_array($order->get_status(), array('checkout-draft', 'draft', 'auto-draft', 'failed', 'cancelled', 'refunded', 'trash'), true);
}

/**
 * Whether a job with these args is waiting to run. The $unique flag of as_enqueue_async_action() is not used for
 * this: it matches the hook and group only, so it would drop every order queued while another one is pending.
 */
function provesrc_is_action_queued($hook, $args)
{
    if (function_exists('as_has_scheduled_action')) {
        return as_has_scheduled_action($hook, $args, PROVESRC_ACTION_GROUP);
    }
    return as_next_scheduled_action($hook, $args, PROVESRC_ACTION_GROUP) !== false;
}

/**
 * Registers the site with ProveSource (/wp/setup) and optionally imports the last 30 orders. Used by the settings
 * form, the Connect flow and the "Re-import Last 30 Orders" button.
 */
function provesrc_api_key_updated($apiKey = null, $webhookSecret = null, $sendOrders = true, $selectedEvents = null)
{
    try {
        delete_transient('provesrc_api_error');
        delete_transient('provesrc_success_message');

        if ($apiKey === null) {
            $apiKey = provesrc_get_api_key();
        }
        if ($webhookSecret === null) {
            $webhookSecret = provesrc_get_webhook_secret();
        }
        if ($selectedEvents === null) {
            $selectedEvents = get_option(PROVESRC_OPTION_EVENTS_KEY, array());
        }

        if (empty($apiKey)) {
            provesrc_log('bad api key, settings update not sent');
            return array('success' => false, 'error' => 'Invalid API key');
        }
        if (empty($webhookSecret)) {
            provesrc_log('bad webhook secret, settings update not sent');
            return array('success' => false, 'error' => 'Invalid webhook secret');
        }

        $data = array(
            'secret' => 'simple-secret',
            'woocommerce' => provesrc_has_woocommerce(),
            'siteUrl' => get_site_url(),
            'siteName' => get_bloginfo('name'),
            'multisite' => is_multisite(),
            'description' => get_bloginfo('description'),
        );
        $importedOrders = array();
        if ($sendOrders) {
            $orders = array();
            if (provesrc_has_woocommerce()) {
                $wcOrders = wc_get_orders(array(
                    'type' => 'shop_order',
                    // orders a customer placed or paid for, custom statuses included
                    'status' => array_diff(
                        array_keys(wc_get_order_statuses()),
                        array('wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'wc-checkout-draft')
                    ),
                    'limit' => 30,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ));
                foreach ($wcOrders as $wcOrder) {
                    $payload = provesrc_get_order_payload($wcOrder);
                    if (!empty($payload['products'])) {
                        $orders[] = $payload;
                        $importedOrders[] = $wcOrder;
                    }
                }
            }
            $data['orders'] = $orders;
        }
        $data['selectedEvents'] = $selectedEvents;

        $res = provesrc_send_request('/wp/setup', $data, $apiKey, $webhookSecret, 30);
        $responseCode = (int) wp_remote_retrieve_response_code($res);
        $responseData = json_decode(wp_remote_retrieve_body($res), true);
        if (is_wp_error($res) || $responseCode < 200 || $responseCode >= 300) {
            if (is_wp_error($res)) {
                $errorMessage = $res->get_error_message();
            } elseif (isset($responseData['error'])) {
                $errorMessage = $responseData['error'];
            } else {
                $errorMessage = 'unexpected error ' . $responseCode;
            }
            provesrc_log('/wp/setup failed', array('status' => $responseCode, 'error' => $errorMessage));
            set_transient('provesrc_api_error', $errorMessage);
            return array('success' => false, 'error' => $errorMessage, 'response_code' => $responseCode);
        }

        // imported orders are already on the server, do not send them again from order events
        foreach ($importedOrders as $importedOrder) {
            provesrc_mark_order_sent($importedOrder);
        }
        $successMessage = isset($responseData['successMessage']) ? $responseData['successMessage'] : 'Setup completed successfully';
        set_transient('provesrc_success_message', $successMessage);
        provesrc_log('/wp/setup complete', array('status' => $responseCode, 'orders' => count($importedOrders)));
        provesrc_reviews_queue_import();
        return array('success' => true, 'message' => $successMessage);
    } catch (Throwable $err) {
        provesrc_handle_error('failed updating settings', $err);
        return array('success' => false, 'error' => 'Exception: ' . $err->getMessage());
    }
}

/**
 * A page of the ProveSource dashboard, e.g. 'notifications/new', tagged as coming from the plugin.
 */
function provesrc_console_url($path = '')
{
    return PROVESRC_CONSOLE . '/?utm_source=wordpress&utm_medium=plugin&utm_campaign=settings#/' . $path;
}

function provesrc_plugin_action_links($links)
{
    array_unshift(
        $links,
        '<a href="' . esc_url(admin_url('admin.php?page=provesrc')) . '">Settings</a>',
        '<a href="' . esc_url(provesrc_console_url()) . '" target="_blank" rel="noopener">Dashboard</a>'
    );
    return $links;
}

/**
 * The Connect button, step one: send the admin to the ProveSource console to approve this site. The console sends
 * the browser back to the settings page with a single-use code, which provesrc_connect_callback() trades for the
 * api key and webhook secret server to server. The verifier stays here; only its hash travels, so a code that
 * leaks from the url cannot be redeemed anywhere else.
 */
function provesrc_connect_start()
{
    if (!current_user_can('manage_options')) {
        wp_die('You are not allowed to connect ProveSource.');
    }
    check_admin_referer('provesrc_connect');

    $state = wp_generate_password(32, false);
    $verifier = bin2hex(random_bytes(32));
    set_transient('provesrc_connect_' . $state, $verifier, 15 * MINUTE_IN_SECONDS);

    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $url = PROVESRC_CONSOLE . '/?utm_source=wordpress&utm_medium=plugin&utm_campaign=connect#/connect/wordpress'
        . '?site=' . rawurlencode(get_site_url())
        . '&return=' . rawurlencode(admin_url('admin.php?page=provesrc'))
        . '&state=' . rawurlencode($state)
        . '&challenge=' . rawurlencode($challenge);
    provesrc_log('connect started');
    // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the console is on another host
    wp_redirect($url);
    exit;
}

/**
 * The Connect button, step two: the console sent the admin back with a code. Trade it for the credentials, save
 * them and run the usual setup (snippet, recent orders), then drop the code from the address bar.
 */
function provesrc_connect_callback()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a redirect from the console carries no nonce, the state must match the transient set by provesrc_connect_start()
    if (!isset($_GET['page'], $_GET['provesrc_code'], $_GET['provesrc_state']) || $_GET['page'] !== 'provesrc') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    $code = sanitize_text_field(wp_unslash($_GET['provesrc_code']));
    $state = sanitize_text_field(wp_unslash($_GET['provesrc_state']));
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    $verifier = get_transient('provesrc_connect_' . $state);
    delete_transient('provesrc_connect_' . $state);
    delete_transient('provesrc_api_error');
    delete_transient('provesrc_success_message');

    if (empty($verifier)) {
        set_transient('provesrc_api_error', 'The connection took too long or was already used. Click Connect again.');
    } else {
        $res = wp_remote_post(PROVESRC_HOST . '/wp/connect/exchange', array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json', 'x-plugin-version' => PROVESRC_VERSION),
            'body' => wp_json_encode(array('code' => $code, 'verifier' => $verifier, 'siteUrl' => get_site_url())),
        ));
        $status = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (is_wp_error($res) || $status < 200 || $status >= 300 || empty($body['apiKey']) || empty($body['webhookSecret'])) {
            $error = is_wp_error($res) ? $res->get_error_message() : (isset($body['error']) ? $body['error'] : 'unexpected error ' . $status);
            provesrc_log('connect exchange failed', array('status' => $status, 'error' => $error));
            set_transient('provesrc_api_error', 'Could not connect to ProveSource: ' . $error);
        } else {
            $apiKey = sanitize_text_field($body['apiKey']);
            $webhookSecret = sanitize_text_field($body['webhookSecret']);
            $sendOrders = provesrc_get_api_key() !== $apiKey || provesrc_get_webhook_secret() !== $webhookSecret;
            update_option(PROVESRC_OPTION_API_KEY, $apiKey);
            update_option(PROVESRC_OPTION_WEBHOOK_SECRET, $webhookSecret);
            // approving the site in the console is accepting the terms linked next to the Connect button
            update_option(PROVESRC_OPTION_TOS_KEY, true);
            provesrc_log('connect complete');
            provesrc_api_key_updated($apiKey, $webhookSecret, $sendOrders, provesrc_get_selected_events());
        }
    }
    wp_safe_redirect(admin_url('admin.php?page=provesrc'));
    exit;
}

function provesrc_import_orders()
{
    if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'provesrc_import_orders_nonce')) {
        wp_send_json_error('Invalid request');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    $transientKey = 'provesrc_last_import_time';
    if (get_transient($transientKey)) {
        wp_send_json_error('Importing past orders can only be triggered once per minute');
    }

    provesrc_log('importing last orders manually');
    $result = provesrc_api_key_updated(null, null, true);
    if (!$result['success']) {
        $responseCode = !empty($result['response_code']) ? $result['response_code'] : 500;
        wp_send_json_error('Failed to import orders: ' . $result['error'], $responseCode);
    }
    set_transient($transientKey, time(), MINUTE_IN_SECONDS);
    wp_send_json_success('Import orders completed');
}

function provesrc_debug_log()
{
    if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'provesrc_debug_log_nonce')) {
        wp_send_json_error('Invalid request');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    $lines = get_option(PROVESRC_OPTION_LOG_KEY, array());
    if (empty($lines) || !is_array($lines)) {
        wp_send_json_error('Debug log is empty, enable debug mode and try again');
    }
    wp_send_json_success(implode("\n", $lines) . "\n");
}

/** hooks - END */

/** helpers */

/**
 * Order data sent to ProveSource. Only what the notification needs: first/last name, email (the
 * server uses it for the gravatar image and name fallback), city/state/country, products, total,
 * currency and date. The customer IP is only sent when the order has no city or country, the server
 * uses it for geolocation in that case.
 */
function provesrc_get_order_payload($order)
{
    if (!is_a($order, 'WC_Order')) {
        return array();
    }
    $payload = array(
        'orderId' => $order->get_id(),
        'firstName' => $order->get_billing_first_name(),
        'lastName' => $order->get_billing_last_name(),
        'email' => $order->get_billing_email(),
        'siteUrl' => get_site_url(),
        'total' => provesrc_format_price($order->get_total()),
        'currency' => $order->get_currency(),
        'products' => provesrc_get_products_array($order),
    );
    $countryCode = $order->get_billing_country();
    if (empty($countryCode)) {
        $countryCode = $order->get_shipping_country();
    }
    $city = $order->get_billing_city();
    if (empty($city)) {
        $city = $order->get_shipping_city();
    }
    $stateCode = $order->get_billing_state();
    if (empty($stateCode)) {
        $stateCode = $order->get_shipping_state();
    }
    $payload['location'] = array(
        'countryCode' => $countryCode,
        'stateCode' => $stateCode,
        'city' => $city,
    );
    $ip = $order->get_customer_ip_address();
    if ((empty($countryCode) || empty($city)) && filter_var($ip, FILTER_VALIDATE_IP)) {
        $payload['ip'] = $ip;
    }
    $date = $order->get_date_created();
    if ($date) {
        $payload['date'] = $date->getTimestamp() * 1000;
    }
    return $payload;
}

function provesrc_get_products_array($order)
{
    $products = array();
    $httpsSite = is_ssl() || wp_parse_url(home_url(), PHP_URL_SCHEME) === 'https';
    foreach ($order->get_items() as $item) {
        try {
            $product = $item->get_product();
            if (!is_object($product)) {
                $products[] = array(
                    'id' => $item->get_id(),
                    'name' => $item->get_name(),
                );
                continue;
            }
            $image = wp_get_attachment_image_src($product->get_image_id(), array(72, 72));
            $imageUrl = null;
            if (is_array($image) && !empty($image[0])) {
                $imageUrl = $httpsSite ? set_url_scheme($image[0], 'https') : $image[0];
            }
            $products[] = array(
                'id' => $product->get_id(),
                'quantity' => (int) $item->get_quantity(),
                'price' => provesrc_format_price($product->get_price()),
                'name' => $product->get_title(),
                'link' => get_permalink($product->get_id()),
                'image' => $imageUrl,
            );
        } catch (Throwable $err) {
            provesrc_log('failed processing line item', array('orderId' => $order->get_id(), 'error' => $err));
        }
    }
    return $products;
}

function provesrc_format_price($amount)
{
    return round((float) $amount, wc_get_price_decimals());
}

/**
 * Reports a plugin error to ProveSource. Sends the message, a short trace without arguments,
 * versions and the order id, never order or customer data.
 */
function provesrc_send_error($message, $err = null, $orderId = null)
{
    try {
        $payload = array(
            'message' => $message,
            'data' => array(
                'orderId' => $orderId,
                'pluginVersion' => PROVESRC_VERSION,
                'wpVersion' => get_bloginfo('version'),
                'wcVersion' => defined('WC_VERSION') ? WC_VERSION : null,
                'phpVersion' => PHP_VERSION,
            ),
        );
        if ($err instanceof Throwable) {
            $payload['err'] = provesrc_encode_exception($err);
        }
        $headers = array(
            'Content-Type' => 'application/json',
            'x-plugin-version' => PROVESRC_VERSION,
            'x-site-url' => get_site_url(),
        );
        $apiKey = provesrc_get_api_key();
        if (!empty($apiKey)) {
            $headers['Authorization'] = "Bearer $apiKey";
        }
        wp_remote_post(PROVESRC_HOST . '/webhooks/wp-error', array(
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 5,
            'blocking' => false,
        ));
    } catch (Throwable $sendErr) {
        provesrc_log('failed sending error', array('error' => $sendErr));
    }
}

function provesrc_send_request($path, $data, $apiKey = null, $webhookSecret = null, $timeout = 15)
{
    if ($apiKey === null) {
        $apiKey = provesrc_get_api_key();
    }
    if ($webhookSecret === null) {
        $webhookSecret = provesrc_get_webhook_secret();
    }
    if (empty($apiKey) || empty($webhookSecret)) {
        return new WP_Error('provesrc_not_connected', 'ProveSource API key or webhook secret is missing');
    }

    $headers = array(
        'Content-Type' => 'application/json',
        'x-plugin-version' => PROVESRC_VERSION,
        'x-site-url' => get_site_url(),
        'x-wp-version' => get_bloginfo('version'),
        'x-api-version' => 2,
        'authorization' => "Bearer $apiKey",
        'x-webhook-secret' => $webhookSecret,
    );
    if (provesrc_has_woocommerce()) {
        $headers['x-woo-version'] = WC()->version;
    }

    provesrc_log('sending request', array('path' => $path));
    $res = wp_remote_post(PROVESRC_HOST . $path, array(
        'headers' => $headers,
        'body' => wp_json_encode($data),
        'timeout' => $timeout,
    ));
    provesrc_log('got response', array(
        'path' => $path,
        'status' => is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res),
    ));
    return $res;
}

function provesrc_handle_error($message, $err = null, $orderId = null)
{
    provesrc_log($message, array('orderId' => $orderId, 'error' => $err));
    provesrc_send_error($message, $err, $orderId);
}

function provesrc_get_api_key()
{
    return get_option(PROVESRC_OPTION_API_KEY);
}

function provesrc_get_webhook_secret()
{
    return get_option(PROVESRC_OPTION_WEBHOOK_SECRET);
}

function provesrc_is_connected()
{
    return !empty(provesrc_get_api_key()) && !empty(provesrc_get_webhook_secret());
}

function provesrc_get_debug()
{
    return get_option(PROVESRC_OPTION_DEBUG_KEY, false);
}

function provesrc_get_tos_accepted()
{
    return get_option(PROVESRC_OPTION_TOS_KEY, false);
}

/**
 * The order events a merchant can pick, in the order they are listed on the settings page.
 */
function provesrc_event_options()
{
    return array(
        'woocommerce_checkout_order_processed' => 'Checkout Order Processed, classic and block checkout (Recommended)',
        'woocommerce_order_status_processing' => 'Order Status Processing (Recommended)',
        'woocommerce_order_status_completed' => 'Order Status Completed (Recommended)',
        'woocommerce_payment_complete' => 'Payment Complete (Recommended)',
        'woocommerce_order_status_pending' => 'Order Status Pending Payment',
        'woocommerce_thankyou' => 'Thank You Page',
        'woocommerce_new_order' => 'New Order',
    );
}

/**
 * Each order is sent once, on whichever of these fires first. Together they catch paid orders from checkouts that
 * skip the checkout hooks: one-page and funnel checkouts, express payment buttons, subscription renewals and orders
 * created in the admin.
 */
function provesrc_default_events()
{
    return array(
        'woocommerce_checkout_order_processed',
        'woocommerce_order_status_processing',
        'woocommerce_order_status_completed',
        'woocommerce_payment_complete',
    );
}

function provesrc_get_selected_events()
{
    $selectedEvents = get_option(PROVESRC_OPTION_EVENTS_KEY, array());
    if (!$selectedEvents) {
        return provesrc_default_events();
    }
    return (array) $selectedEvents;
}

function provesrc_sanitize_events_array($value)
{
    if (!is_array($value)) {
        return array();
    }
    $events = array();
    foreach ($value as $event) {
        $event = sanitize_key($event);
        if (array_key_exists($event, provesrc_event_options())) {
            $events[] = $event;
        }
    }
    return array_values(array_unique($events));
}

function provesrc_isvalid_api_key($apiKey)
{
    if (!is_string($apiKey) || strlen($apiKey) <= 30) {
        return false;
    }
    // a JWT, the account id is in the payload
    $parts = explode('.', $apiKey);
    $json = isset($parts[1]) ? json_decode(base64_decode(strtr($parts[1], '-_', '+/'))) : null;
    return is_object($json) && isset($json->accountId);
}

function provesrc_has_woocommerce()
{
    // true for site and network activated WooCommerce, valid from plugins_loaded on
    return class_exists('WooCommerce');
}

/**
 * Number of ProveSource jobs queued over an hour ago that have not run. Action Scheduler runs from WP-Cron, so a site
 * with WP-Cron disabled and no server cron never sends them. Async jobs have no scheduled date, their age is the
 * date of their first log entry ("action created").
 */
function provesrc_count_stuck_jobs()
{
    if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler')) {
        return 0;
    }
    $ids = as_get_scheduled_actions(array(
        'group' => PROVESRC_ACTION_GROUP,
        'status' => 'pending',
        'per_page' => 50,
    ), 'ids');
    $stuck = 0;
    foreach ($ids as $id) {
        $logs = ActionScheduler::logger()->get_logs($id);
        if ($logs && $logs[0]->get_date()->getTimestamp() < time() - HOUR_IN_SECONDS) {
            $stuck++;
        }
    }
    return $stuck;
}

function provesrc_admin_menu_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $apiKey = provesrc_get_api_key();
    $webhookSecret = provesrc_get_webhook_secret();
    $selectedEvents = provesrc_get_selected_events();
    $tosAccepted = $apiKey ? true : provesrc_get_tos_accepted();
    $connectUrl = wp_nonce_url(admin_url('admin-post.php?action=provesrc_connect'), 'provesrc_connect');
    $stuckJobs = provesrc_is_connected() ? provesrc_count_stuck_jobs() : 0;
    ?>

    <div class="wrap" id="ps-settings">
        <a href="https://provesrc.com">
            <img class="top-logo" src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/top-logo.png'); ?>" alt="ProveSource">
        </a>
        <h1 class="screen-reader-text">ProveSource Settings</h1>
        <hr class="wp-header-end">
        <?php if ($stuckJobs > 0) { ?>
            <div class="notice notice-warning inline">
                <p>
                    <?php echo esc_html(number_format_i18n($stuckJobs)); ?> ProveSource background jobs (orders or reviews to send) have been waiting for over an hour.
                    WooCommerce runs them with WP-Cron, which is probably disabled on this site without a server cron job replacing it.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler&status=pending&s=provesrc')); ?>">See the waiting jobs</a>
                </p>
            </div>
        <?php } ?>
        <form action="options.php" method="post">
            <?php
            settings_fields(PROVESRC_OPTIONS_GROUP);
            do_settings_sections(PROVESRC_OPTIONS_GROUP);
            ?>
            <div class="ps-settings-container">
                <?php if ($apiKey && $webhookSecret) { ?>
                    <div class="ps-success">ProveSource is Active</div>
                    <div class="ps-dashboard">
                        <div>
                            <strong>Manage your notifications in the ProveSource dashboard</strong>
                            <p class="description">Create and edit notifications, see how they perform, and manage your plan.</p>
                        </div>
                        <div class="ps-dashboard-actions">
                            <a class="button button-primary" href="<?php echo esc_url(provesrc_console_url()); ?>" target="_blank" rel="noopener">Manage notifications</a>
                            <a class="button" href="<?php echo esc_url(provesrc_console_url('notifications/new')); ?>" target="_blank" rel="noopener">Create a notification</a>
                        </div>
                    </div>
                    <div class="account-link">Wrong account? <a href="<?php echo esc_url($connectUrl); ?>">Connect again</a> with the right one.</div>
                    <div class="ps-warning">
                        If you still see <strong>"waiting for data..."</strong> in the ProveSource dashboard open your website in <strong>incognito</strong> or <strong>clear cache</strong>
                        <br>If you have <strong>cache or security plugins</strong>, please <a href="https://help.provesrc.com/en/articles/4206151-common-wordpress-woocommerce-issues">see this guide</a> about possible issues and how to solve them
                    </div>
                <?php } else { ?>
                    <div class="ps-connect">
                        <a class="button button-primary button-hero" href="<?php echo esc_url($connectUrl); ?>">Connect to ProveSource</a>
                        <p class="description">
                            Log in or sign up, approve this site, and you are done. By connecting you agree to the
                            <a href="https://provesrc.com/terms/" target="_blank">Terms of Service</a>.
                        </p>
                    </div>
                    <div class="account-link">Or paste your API Key and Webhook Secret from the <a href="<?php echo esc_url(provesrc_console_url('settings')); ?>" target="_blank">ProveSource settings</a> below.</div>
                <?php } ?>

                <table class="form-table ps-settings-table">
                    <tr>
                        <th scope="row">
                            <label for="provesrc_api_key">Your API Key <span style="color: #dc3232;">*</span></label>
                            <p class="description">Get your <a href="<?php echo esc_url(provesrc_console_url('settings')); ?>" target="_blank">API Key here</a></p>
                        </th>
                        <td>
                            <input type="text" id="provesrc_api_key" class="ps-apikey regular-text" placeholder="eyJhbG..." name="<?php echo esc_attr(PROVESRC_OPTION_API_KEY); ?>" value="<?php echo esc_attr($apiKey); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="provesrc_webhook_secret">Webhook Secret <span style="color: #dc3232;">*</span></label>
                            <p class="description">Get your <a href="<?php echo esc_url(provesrc_console_url('settings')); ?>" target="_blank">Webhook Secret here</a></p>
                        </th>
                        <td>
                            <input type="text" id="provesrc_webhook_secret" class="ps-apikey regular-text" placeholder="550e8400-e29b..." name="<?php echo esc_attr(PROVESRC_OPTION_WEBHOOK_SECRET); ?>" value="<?php echo esc_attr($webhookSecret); ?>" />
                        </td>
                    </tr>
                    <?php if (provesrc_has_woocommerce()) { ?>
                    <tr>
                        <th scope="row">
                            WooCommerce Events <span style="color: #dc3232;">*</span>
                            <p class="description">
                                Each order is sent once, on the first of the selected events. The recommended events catch paid orders
                                from any checkout, including one-page and funnel checkouts, express payment buttons, subscription renewals
                                and orders created in the admin. Failed, cancelled and refunded orders are never sent.
                            </p>
                        </th>
                        <td>
                            <fieldset>
                                <?php foreach (provesrc_event_options() as $event => $label) { ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox"
                                            name="<?php echo esc_attr(PROVESRC_OPTION_EVENTS_KEY . '[]'); ?>"
                                            value="<?php echo esc_attr($event); ?>"
                                            <?php checked(in_array($event, $selectedEvents, true)); ?> />
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php } ?>
                            </fieldset>
                        </td>
                    </tr>
                    <?php } ?>
                    <tr>
                        <th scope="row">
                            <label for="ps-toggle">Enable Debug Mode</label>
                            <p class="description"><a href="#" id="download_debug_log">Download Debug Log</a></p>
                        </th>
                        <td>
                            <div class="ps-toggle">
                                <input type="checkbox" class="ps-toggle-checkbox" id="ps-toggle" tabindex="0"
                                    name="<?php echo esc_attr(PROVESRC_OPTION_DEBUG_KEY); ?>" <?php checked((bool) provesrc_get_debug()); ?>>
                                <label class="ps-toggle-label" for="ps-toggle"></label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tos_checkbox">Terms of Service <span style="color: #dc3232;">*</span></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(PROVESRC_OPTION_TOS_KEY); ?>" value="1" <?php checked($tosAccepted); ?> required id="tos_checkbox">
                                By using the ProveSource plugin, you agree to our <a href="https://provesrc.com/terms/" target="_blank">Terms of Service</a>
                                and <a href="https://provesrc.com/privacy/" target="_blank">Privacy Policy</a>
                            </label>
                            <p class="description">(ProveSource will add provesrc.js to your website and automatically retrieve website name, description, URL and recent orders for initial setup).</p>
                        </td>
                    </tr>
                </table>
            </div>
            <div style="display:flex; align-items:center">
                <div>
                    <?php submit_button('Save'); ?>
                </div>
                <div style="margin-top:7px; margin-left:20px; font-weight: bold">
                    <button
                        type="button"
                        id="import_orders_button"
                        style="padding-top:3px; padding-bottom:3px; background-color:#7825f3; border:none"
                        class="button button-primary"
                        <?php disabled(!provesrc_isvalid_api_key($apiKey)); ?>>
                        Re-import Last 30 Orders
                    </button>
                </div>
            </div>
        </form>
        <?php provesrc_reviews_settings_section(); ?>
        <p class="ps-version-text">ProveSource WordPress Plugin v<?php echo esc_html(PROVESRC_VERSION); ?></p>
    </div>

    <?php
}

function provesrc_admin_notice_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $apiKey = provesrc_get_api_key();
    $webhookSecret = provesrc_get_webhook_secret();
    $errorMessage = get_transient('provesrc_api_error');
    $successMessage = get_transient('provesrc_success_message');
    if ($apiKey && $webhookSecret && !$errorMessage && !$successMessage) {
        return;
    }
    $settingsUrl = admin_url('admin.php?page=provesrc');
    ?>
    <div class="notice is-dismissible <?php echo esc_attr($successMessage ? 'notice-success' : 'notice-error'); ?>">
        <?php if ($successMessage) { ?>
            <p class="ps-success"><?php echo esc_html($successMessage); ?></p>
        <?php } elseif (!$apiKey) { ?>
            <p class="ps-error">ProveSource API Key is missing! <a href="<?php echo esc_url($settingsUrl); ?>">Click here</a> to configure your API key.</p>
        <?php } elseif (!$webhookSecret) { ?>
            <p class="ps-error">ProveSource Webhook Secret is missing! <a href="<?php echo esc_url($settingsUrl); ?>">Click here</a> to configure your webhook secret.</p>
        <?php } else { ?>
            <p class="ps-error"><a href="<?php echo esc_url($settingsUrl); ?>">ProveSource</a> encountered an error: <?php echo esc_html($errorMessage); ?></p>
        <?php } ?>
    </div>
    <?php
    if ($successMessage) {
        delete_transient('provesrc_success_message');
    }
}

/**
 * Debug log, written only while debug mode is on. Kept as a capped list of lines in a non-autoloaded
 * option so it is never a public file. Context must be small scalar values (order id, status codes),
 * objects and nested data are replaced by their type so order and customer data never reach the log.
 */
function provesrc_log($message, $context = array())
{
    if (!provesrc_get_debug()) {
        return;
    }
    $line = current_time('Y-m-d\TH:i:s') . ' [ProveSource] ' . $message;
    if (!empty($context)) {
        $line .= ' ' . wp_json_encode(provesrc_log_context($context));
    }
    $lines = get_option(PROVESRC_OPTION_LOG_KEY, array());
    if (!is_array($lines)) {
        $lines = array();
    }
    $lines[] = substr($line, 0, 1000);
    if (count($lines) > PROVESRC_LOG_MAX_LINES) {
        $lines = array_slice($lines, -PROVESRC_LOG_MAX_LINES);
    }
    update_option(PROVESRC_OPTION_LOG_KEY, $lines, false);
}

function provesrc_log_context($context)
{
    $clean = array();
    foreach ((array) $context as $key => $value) {
        if ($value instanceof Throwable) {
            $clean[$key] = $value->getMessage() . ' (' . basename($value->getFile()) . ':' . $value->getLine() . ')';
        } elseif (is_wp_error($value)) {
            $clean[$key] = $value->get_error_message();
        } elseif (is_scalar($value) || $value === null) {
            $clean[$key] = $value;
        } elseif (is_array($value) && count(array_filter($value, 'is_scalar')) === count($value)) {
            $clean[$key] = $value;
        } else {
            $clean[$key] = '[' . (is_object($value) ? get_class($value) : gettype($value)) . ']';
        }
    }
    return $clean;
}

function provesrc_encode_exception($err)
{
    // a short trace without call arguments, arguments can hold order data
    $trace = array();
    foreach (array_slice($err->getTrace(), 0, 8) as $frame) {
        $location = isset($frame['file']) ? basename($frame['file']) . ':' . (isset($frame['line']) ? $frame['line'] : '') . ' ' : '';
        $caller = isset($frame['class']) ? $frame['class'] . $frame['type'] : '';
        $trace[] = $location . $caller . $frame['function'];
    }
    return array(
        'message' => $err->getMessage(),
        'code' => $err->getCode(),
        'file' => basename($err->getFile()) . ':' . $err->getLine(),
        'trace' => implode("\n", $trace),
    );
}

/** helpers - END */
