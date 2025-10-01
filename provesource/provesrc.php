<?php

/**
 * @package: provesrc-plugin
 */

/**
 * Plugin Name: ProveSource
 * Description: ProveSource is a social proof marketing platform that works with your Wordpress and WooCommerce websites out of the box
 * Version: 3.1.1
 * Author: ProveSource LTD
 * Author URI: https://provesrc.com
 * License: GPLv3 or later
 * Text Domain: provesrc-plugin
 * 
 * WC requires at least: 3.0
 * WC tested up to: 10.1
 */

if (!defined('ABSPATH')) {
    die;
}

/** constants */
define('PROVESRC_HOST', 'https://api.provesrc.com');
define('PROVESRC_VERSION', '3.1.1');
define('PROVESRC_OPTIONS_GROUP', 'provesrc_options');

// Current option keys
define('PROVESRC_OPTION_API_KEY', 'provesrc_api_key');
define('PROVESRC_OPTION_DEBUG_KEY', 'provesrc_debug');
define('PROVESRC_OPTION_EVENTS_KEY', 'provesrc_events');
define('PROVESRC_OPTION_TOS_KEY', 'provesrc_tos_accepted');
define('PROVESRC_OPTION_ANALYTICS_KEY', 'provesrc_analytics_consent');

// Legacy option keys for backward compatibility
define('PROVESRC_LEGACY_OPTION_API_KEY', 'api_key');
define('PROVESRC_LEGACY2_OPTION_API_KEY', 'ps_api_key');
define('PROVESRC_LEGACY_OPTION_DEBUG_KEY', 'ps_debug');
define('PROVESRC_LEGACY_OPTION_EVENTS_KEY', 'ps_events');
define('PROVESRC_LEGACY_OPTION_TOS_KEY', 'ps_tos_accepted');
define('PROVESRC_LEGACY_OPTION_ANALYTICS_KEY', 'ps_analytics_consent');

/* hooks */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
add_action('admin_menu', 'provesrc_admin_menu'); //1.5.0
add_action('admin_init', 'provesrc_admin_init'); //2.5.0
add_action('admin_notices', 'provesrc_admin_notice_html');
add_action('wp_head', 'provesrc_inject_code'); //1.2.0

// WooCommerce hooks
add_action('woocommerce_new_order', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_thankyou', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_checkout_create_order', 'provesrc_woocommerce_hook_handler', 999, 2);
add_action('woocommerce_checkout_order_processed', 'provesrc_woocommerce_hook_handler', 999, 3);
add_action('woocommerce_order_status_pending', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_order_status_processing', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_order_status_completed', 'provesrc_woocommerce_hook_handler', 999, 1);
add_action('woocommerce_payment_complete', 'provesrc_woocommerce_hook_handler', 999, 1);

register_uninstall_hook(__FILE__, 'provesrc_uninstall_hook');
register_activation_hook(__FILE__, 'provesrc_activation_hook');
register_deactivation_hook(__FILE__, 'provesrc_deactivation_hook');
add_action('update_option_' . PROVESRC_OPTION_API_KEY, 'provesrc_api_key_updated', 999, 0);
add_action('add_option_' . PROVESRC_OPTION_API_KEY, 'provesrc_api_key_updated', 999, 0);
add_action('update_option_' . PROVESRC_OPTION_EVENTS_KEY, 'provesrc_hook_updated', 999, 3);

// Legacy option hooks for backward compatibility
add_action('update_option_' . PROVESRC_LEGACY_OPTION_API_KEY, 'provesrc_api_key_updated', 999, 0);
add_action('add_option_' . PROVESRC_LEGACY_OPTION_API_KEY, 'provesrc_api_key_updated', 999, 0);
add_action('update_option_' . PROVESRC_LEGACY2_OPTION_API_KEY, 'provesrc_api_key_updated', 999, 0);
add_action('add_option_' . PROVESRC_LEGACY2_OPTION_API_KEY, 'provesrc_api_key_updated', 999, 0);
add_action('update_option_' . PROVESRC_LEGACY_OPTION_EVENTS_KEY, 'provesrc_hook_updated', 999, 3);

add_action('wp_ajax_provesrc_import_orders', 'provesrc_import_orders');
add_action('wp_ajax_provesrc_debug_log', 'provesrc_debug_log');

function provesrc_admin_menu()
{
    add_menu_page('ProveSource Settings', 'ProveSource', 'manage_options', 'provesrc', 'provesrc_admin_menu_page_html', 'dashicons-provesrc');
}

function provesrc_admin_init()
{
    wp_enqueue_style('provesrc_admin_style', plugin_dir_url(__FILE__) . 'style.css', array(), PROVESRC_VERSION);
    // Register all settings first
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_OPTION_API_KEY, array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_LEGACY_OPTION_API_KEY, array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_LEGACY2_OPTION_API_KEY, array(
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
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_OPTION_ANALYTICS_KEY, array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
    
    // Register legacy option keys for backward compatibility
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_LEGACY_OPTION_DEBUG_KEY, array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_LEGACY_OPTION_EVENTS_KEY, array(
        'type' => 'array',
        'sanitize_callback' => 'provesrc_sanitize_events_array',
    ));
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_LEGACY_OPTION_TOS_KEY, array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
    register_setting(PROVESRC_OPTIONS_GROUP, PROVESRC_LEGACY_OPTION_ANALYTICS_KEY, array(
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
    wp_enqueue_style('dashicons-provesrc', plugin_dir_url(__FILE__) . '/assets/css/dashicons-provesrc.css', array(), PROVESRC_VERSION);
    
    // Run one-time migration AFTER all settings are registered
    provesrc_run_one_time_migration();

    if (isset($_POST['option_page']) && sanitize_text_field(wp_unslash($_POST['option_page'])) === PROVESRC_OPTIONS_GROUP) {
        // Verify nonce for settings form submission
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), PROVESRC_OPTIONS_GROUP . '-options')) {
            wp_die('Security check failed. Please try again.');
        }
        $optionKey = PROVESRC_OPTION_API_KEY;
        $apiKey = get_option($optionKey);
        $submitted = isset($_POST[$optionKey]) ? sanitize_text_field(wp_unslash($_POST[$optionKey])) : '';
        $tosKey = PROVESRC_OPTION_TOS_KEY;
        $tosSubmitted = isset($_POST[$tosKey]) ? rest_sanitize_boolean(sanitize_text_field(wp_unslash($_POST[$tosKey]))) : false;
        
        // Terms of Service is always required, regardless of API key
        if (!$tosSubmitted) {
            add_settings_error(
                $optionKey,
                'tos_not_accepted',
                'You must accept the Terms of Service to use ProveSource.',
                'error'
            );
            return;
        }

        // Only run API key update if there's a valid API key
        if ($apiKey === $submitted && !empty($submitted)) {
            provesrc_log('api key not changed, but running update');
            provesrc_api_key_updated();
        }
    }
}

function provesrc_inject_code()
{
    // global $wp;
    // $url = home_url($wp->request);
    // if(strpos($url, 'exclude1') > -1 || strpos($url, 'exclude2') > -1) {
    //     return;
    // }
    $version = PROVESRC_VERSION;
    $apiKey = provesrc_get_api_key(); ?>

    <!-- Start of Async ProveSource Code (Wordpress / Woocommerce v<?php echo esc_html($version); ?>) --><script>!function(o,i){window.provesrc&&window.console&&console.error&&console.error("ProveSource is included twice in this page."),provesrc=window.provesrc={dq:[],display:function(){this.dq.push(arguments)}},o._provesrcAsyncInit=function(){provesrc.init({apiKey:"<?php echo esc_html($apiKey); ?>",v:"0.0.4"})};var r=i.createElement("script");r.async=!0,r["ch"+"ar"+"set"]="UTF-8",r.src="https://cdn.provesrc.com/provesrc.js";var e=i.getElementsByTagName("script")[0];e.parentNode.insertBefore(r,e)}(window,document);</script><!-- End of Async ProveSource Code -->
<?php
}

function provesrc_woocommerce_hook_handler($arg1, $arg2 = null, $arg3 = null)
{
    $selectedEvents = provesrc_get_option_with_fallback(
        PROVESRC_OPTION_EVENTS_KEY,
        PROVESRC_LEGACY_OPTION_EVENTS_KEY,
        []
    );
    $currentEvent = current_filter();
    if (!$selectedEvents) {
        $selectedEvents = ['woocommerce_checkout_order_processed', 'woocommerce_order_status_completed'];
    }
    if (!in_array($currentEvent, (array)$selectedEvents)) {
        provesrc_log('order handler skipping event', ['current' => $currentEvent, 'selected' => $selectedEvents]);
        return;
    }
    try {
        switch ($currentEvent) {
            case 'woocommerce_checkout_create_order':
                provesrc_order_created_hook($arg1, $arg2);
                break;
            case 'woocommerce_checkout_order_processed':
                provesrc_order_processed($arg1, $arg2, $arg3);
                break;
            default:
                provesrc_order_id_hook($arg1);
                break;
        }
    } catch (Exception $err) {
        provesrc_handle_error('Failed to process order from event: ' . $currentEvent, $err, ['arg1' => $arg1, 'arg2' => $arg2, 'arg3' => $arg3]);
    }
}

function provesrc_order_created_hook($order, $data)
{
    try {
        provesrc_log('woocommerce order created', ['order' => $order]);
        provesrc_send_webhook($order);
    } catch (Exception $err) {
        provesrc_handle_error('failed to process order created', $err, ['order' => $order]);
    }
}

function provesrc_order_id_hook($id)
{
    try {
        $order = wc_get_order($id);
        provesrc_log('woocommerce order complete', ['id' => $id, 'order' => $order]);
        provesrc_send_webhook($order);
    } catch (Exception $err) {
        provesrc_handle_error('failed to process order complete', $err, ['orderId' => $id]);
    }
}

function provesrc_order_processed($id, $data, $order)
{
    try {
        if (!isset($id) || $id < 1) {
            provesrc_log('woocommerce order event (no id)', $order);
            provesrc_send_webhook($order);
        } else {
            provesrc_log('woocommerce order event (with id)', ['id' => $id, 'order' => $order]);
            provesrc_send_webhook(wc_get_order($id));
        }
    } catch (Exception $err) {
        provesrc_handle_error('failed to process order', $err, ['orderId' => $id]);
    }
}

function provesrc_uninstall_hook()
{
    if (!current_user_can('activate_plugins')) {
        return;
    }
    $apiKey = provesrc_get_api_key();
    if (!$apiKey) {
        return;
    }
}

function provesrc_activation_hook()
{
    if (!current_user_can('activate_plugins')) {
        return;
    }
    $apiKey = provesrc_get_api_key();
    if (!$apiKey) {
        return;
    }
}

function provesrc_deactivation_hook()
{
    if (!current_user_can('activate_plugins')) {
        return;
    }
    $apiKey = provesrc_get_api_key();
    if (!$apiKey) {
        return;
    }
}

function provesrc_api_key_updated()
{
    try {
        $apiKey = provesrc_get_api_key();
        if ($apiKey == null) {
            provesrc_log('bad api key update');
            return;
        }
        provesrc_log('api key updated');

        $orders = [];
        if (provesrc_has_woocommerce()) {
            $wcOrders = wc_get_orders(array(
                'limit' => 30,
                'orderby' => 'date',
                'order' => 'DESC'
            ));
            foreach ($wcOrders as $wco) {
                array_push($orders, provesrc_get_order_payload($wco, false));
            }
        }
        $data = array(
            'secret' => 'simple-secret',
            'woocommerce' => provesrc_has_woocommerce(),
            'siteUrl' => get_site_url(),
            'siteName' => get_bloginfo('name'),
            'multisite' => is_multisite(),
            'description' => get_bloginfo('description'),
            'orders' => $orders
        );
        provesrc_log('sending setup data ' . '(' . count($orders) . ' orders)');
        $res = provesrc_send_request('/wp/setup', $data);
        $response_code = wp_remote_retrieve_response_code($res);
        $response_body = wp_remote_retrieve_body($res);
        $response_data = json_decode($response_body, true);
        if ($response_code != 200) {
            if (isset($response_data['error'])) {
                $error_message = $response_data['error'];
            } else {
                $error_message = 'unexpected error ' . $response_code;
            }
            provesrc_log('/wp/setup failed: ' . $error_message);
            set_transient('provesrc_api_error', $error_message);
        } else {
            if (isset($response_data['successMessage'])) {
                set_transient('provesrc_success_message', $response_data['successMessage']);
            }
            provesrc_log('/wp/setup complete: ' . $response_data['successMessage'] . $response_data['message']);
            delete_transient('provesrc_api_error');
        }
    } catch (Exception $err) {
        provesrc_handle_error('failed updating api key', $err);
    }
}

function provesrc_hook_updated()
{
    try {
        $optionKey = PROVESRC_OPTION_EVENTS_KEY;
        
        // Verify nonce for events update
        if (isset($_POST[$optionKey]) && (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), PROVESRC_OPTIONS_GROUP . '-options'))) {
            provesrc_log('nonce verification failed for events update');
            return;
        }
        
        $apiKey = provesrc_get_api_key();
        if ($apiKey == null) {
            provesrc_log('bad api key, selected events update not sent');
            return;
        }
        $selectedEvents = isset($_POST[$optionKey]) ? array_map('sanitize_text_field', wp_unslash($_POST[$optionKey])) : [];
        update_option($optionKey, $selectedEvents);

        $data = array(
            'secret' => 'simple-secret',
            'woocommerce' => provesrc_has_woocommerce(),
            'siteUrl' => get_site_url(),
            'siteName' => get_bloginfo('name'),
            'multisite' => is_multisite(),
            'description' => get_bloginfo('description'),
            'selectedEvents' => $selectedEvents,
        );
        provesrc_log('sending selected events update', $data);
        provesrc_send_request('/wp/setup', $data);
    } catch (Exception $err) {
        provesrc_handle_error('failed updating selected events', $err);
    }
}

/** hooks - END */

/** helpers */

function provesrc_run_one_time_migration()
{
    // Check if migration has already been completed
    $migration_completed = get_option('provesrc_migration_3_1_0_completed', false);
    if ($migration_completed) {
        return; // Migration already done
    }
    
    // Only run for users who can manage options (admin_init already ensures admin context)
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $migrations_performed = [];
    
    // Migrate API key
    $current_api_key = get_option(PROVESRC_OPTION_API_KEY);
    if (!provesrc_isvalid_api_key($current_api_key)) {
        // Check legacy2 key first (ps_api_key)
        $legacy2_key = get_option(PROVESRC_LEGACY2_OPTION_API_KEY);
        if (provesrc_isvalid_api_key($legacy2_key)) {
            update_option(PROVESRC_OPTION_API_KEY, $legacy2_key);
            $migrations_performed[] = PROVESRC_LEGACY2_OPTION_API_KEY . ' -> ' . PROVESRC_OPTION_API_KEY;
        } else {
            // Check oldest legacy key (api_key)
            $legacy_key = get_option(PROVESRC_LEGACY_OPTION_API_KEY);
            if (provesrc_isvalid_api_key($legacy_key)) {
                update_option(PROVESRC_OPTION_API_KEY, $legacy_key);
                $migrations_performed[] = PROVESRC_LEGACY_OPTION_API_KEY . ' -> ' . PROVESRC_OPTION_API_KEY;
            }
        }
    }
    
    // Migrate other options
    $option_migrations = [
        [PROVESRC_LEGACY_OPTION_DEBUG_KEY, PROVESRC_OPTION_DEBUG_KEY],
        [PROVESRC_LEGACY_OPTION_EVENTS_KEY, PROVESRC_OPTION_EVENTS_KEY],
        [PROVESRC_LEGACY_OPTION_TOS_KEY, PROVESRC_OPTION_TOS_KEY],
        [PROVESRC_LEGACY_OPTION_ANALYTICS_KEY, PROVESRC_OPTION_ANALYTICS_KEY],
    ];
    
    foreach ($option_migrations as $migration) {
        $old_key = $migration[0];
        $new_key = $migration[1];
        
        // Only migrate if new option doesn't exist but old one does
        $new_value = get_option($new_key, null);
        if ($new_value === null) {
            $old_value = get_option($old_key, null);
            if ($old_value !== null) {
                update_option($new_key, $old_value);
                $migrations_performed[] = $old_key . ' -> ' . $new_key;
            }
        }
    }
    
    // Mark migration as completed
    update_option('provesrc_migration_3_1_0_completed', true);
    
    if (!empty($migrations_performed)) {
        provesrc_log('One-time migration completed. Migrated: ' . implode(', ', $migrations_performed));
    } else {
        provesrc_log('One-time migration completed. No migrations needed.');
    }
}

function provesrc_get_option_with_fallback($new_key, $old_key, $default = false)
{
    // Try new option first
    $value = get_option($new_key, null);
    if ($value !== null) {
        return $value;
    }
    
    // Fall back to old option (migration happens separately on admin_init)
    $old_value = get_option($old_key, null);
    if ($old_value !== null) {
        return $old_value;
    }
    
    return $default;
}

function provesrc_send_webhook($order)
{
    try {
        $data = provesrc_get_order_payload($order);
        return provesrc_send_request('/webhooks/track/woocommerce', $data);
    } catch (Exception $err) {
        provesrc_handle_error('failed to send webhook', $err, $order);
    }
}

function provesrc_get_order_payload($order, $userInitiated = false)
{
    if (is_a($order, 'WC_Order_Refund')) {
        $order = wc_get_order($order->get_parent_id());
    }
    if (!is_a($order, 'WC_Order')) {
        return array();
    }
    $ip = $order->get_customer_ip_address();
    $ips = provesrc_get_ips();
    $location = null;
    if ($userInitiated) {
        $ips = [$ip];
        if (class_exists('WC_Geolocation')) {
            $geo = new WC_Geolocation();
            $userip = $geo->get_ip_address();
            $location = $geo->geolocate_ip($userip);
            $location['ip'] = $userip;
        }
    }
    if (!in_array($ip, $ips)) {
        array_unshift($ips, $ip);
    }
    $payload = array(
        'orderId' => $order->get_id(),
        'firstName' => $order->get_billing_first_name(),
        'lastName' => $order->get_billing_last_name(),
        'email' => $order->get_billing_email(),
        'ip' => $ips[0],
        'ips' => $ips,
        'siteUrl' => get_site_url(),
        'total' => (int) $order->get_total(),
        'currency' => $order->get_currency(),
        'products' => provesrc_get_products_array($order),
        'billingAddress' => $order->get_address('billing'),
        'shippingAddress' => $order->get_address('shipping'),
    );
    if ($location) {
        $payload['wooLocation'] = $location;
    }
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
    if (method_exists($order, 'get_date_created')) {
        $date = $order->get_date_created();
        if (!empty($date) && method_exists($date, 'getTimestamp')) {
            $payload['date'] = $order->get_date_created()->getTimestamp() * 1000;
        }
    }
    return $payload;
}

function provesrc_get_products_array($order)
{
    $items = $order->get_items();
    $products = array();
    foreach ($items as $item) {
        try {
            $quantity = $item->get_quantity();
            $product = $item->get_product();
            if (!is_object($product)) {
                $p = array(
                    'id' => $item->get_id(),
                    'name' => $item->get_name(),
                );
            } else {
                $images_arr = wp_get_attachment_image_src($product->get_image_id(), array('72', '72'), false);
                $image = null;
                if ($images_arr !== null && $images_arr[0] !== null) {
                    $image = $images_arr[0];
                    if (is_ssl()) {
                        $image = str_replace('http', 'https', $image);
                    }
                }
                $p = array(
                    'id' => $product->get_id(),
                    'quantity' => (int) $quantity,
                    'price' => (int) $product->get_price(),
                    'name' => $product->get_title(),
                    'link' => get_permalink($product->get_id()),
                    'image' => $image,
                );
            }
            array_push($products, $p);
        } catch (Exception $err) {
            provesrc_log('failed processing line item', $err);
        }
    }
    return $products;
}

function provesrc_send_error($message, $err, $data = null)
{
    try {
        $payload = array(
            'message' => $message,
            'err' => provesrc_encode_exception($err),
            'data' => $data,
        );
        $apiKey = provesrc_get_api_key();
        $headers = array(
            'Content-Type' => 'application/json',
            'x-plugin-version' => PROVESRC_VERSION,
            'x-site-url' => get_site_url(),
            'Authorization' => "Bearer $apiKey"
        );
        return wp_remote_post(PROVESRC_HOST . '/webhooks/wp-error', array(
            'headers' => $headers,
            'body' => json_encode($payload),
        ));
    } catch (Exception $err) {
        provesrc_log('failed sending error', $err);
    }
}

function provesrc_send_request($path, $data, $ignoreAuth = false)
{
    try {
        $headers = array(
            'Content-Type' => 'application/json',
            'x-plugin-version' => PROVESRC_VERSION,
            'x-site-url' => get_site_url(),
            'x-wp-version' => get_bloginfo('version'),
        );

        $apiKey = provesrc_get_api_key();
        if (!$ignoreAuth && $apiKey == null) {
            return;
        } else if (!empty($apiKey)) {
            $headers['authorization'] = "Bearer $apiKey";
        }

        if (provesrc_has_woocommerce()) {
            $headers['x-woo-version'] = WC()->version;
        }

        $url = PROVESRC_HOST . $path;
        $data = array(
            'headers' => $headers,
            'body' => json_encode($data),
        );
        provesrc_log('sending request', ['url' => $url]);
        $res = wp_remote_post($url, $data);
        provesrc_log('got response ' . $url, $res);
        return $res;
    } catch (Exception $err) {
        provesrc_handle_error('failed sending request', $err, $data);
    }
}

function provesrc_handle_error($message, $err, $data = null)
{
    provesrc_log($message, $err);
    provesrc_send_error($message, $err, $data);
}

function provesrc_get_api_key()
{
    // Check current key first
    $apiKey = get_option(PROVESRC_OPTION_API_KEY);
    if (provesrc_isvalid_api_key($apiKey)) {
        return $apiKey;
    }
    
    // Check legacy2 key (ps_api_key) - more recent legacy
    $legacy2Key = get_option(PROVESRC_LEGACY2_OPTION_API_KEY);
    if (provesrc_isvalid_api_key($legacy2Key)) {
        return $legacy2Key;
    }
    
    // Check legacy key (api_key) - oldest legacy
    $legacyKey = get_option(PROVESRC_LEGACY_OPTION_API_KEY);
    if (provesrc_isvalid_api_key($legacyKey)) {
        return $legacyKey;
    }
    
    return null;
}

function provesrc_get_debug()
{
    return provesrc_get_option_with_fallback(
        PROVESRC_OPTION_DEBUG_KEY,
        PROVESRC_LEGACY_OPTION_DEBUG_KEY,
        false
    );
}

function provesrc_get_tos_accepted()
{
    return provesrc_get_option_with_fallback(
        PROVESRC_OPTION_TOS_KEY,
        PROVESRC_LEGACY_OPTION_TOS_KEY,
        false
    );
}

function provesrc_get_analytics_consent()
{
    return provesrc_get_option_with_fallback(
        PROVESRC_OPTION_ANALYTICS_KEY,
        PROVESRC_LEGACY_OPTION_ANALYTICS_KEY,
        false
    );
}

function provesrc_isvalid_api_key($apiKey)
{
    if (isset($apiKey) && strlen($apiKey) > 30) {
        $start = strpos($apiKey, '.');
        $end = strpos($apiKey, '.', $start + 1);
        $substr = substr($apiKey, $start + 1, $end - $start - 1);
        $json = json_decode(base64_decode($substr));

        if (is_object($json) && isset($json->accountId)) {
            return true;
        }
    }
    return false;
}

function provesrc_get_ips()
{
    $ips = [];
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        array_push($ips, filter_var(wp_unslash($_SERVER['HTTP_CLIENT_IP']), FILTER_VALIDATE_IP));
    } else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        array_push($ips, filter_var(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']), FILTER_VALIDATE_IP));
    } else if (isset($_SERVER['HTTP_X_FORWARDED'])) {
        array_push($ips, filter_var(wp_unslash($_SERVER['HTTP_X_FORWARDED']), FILTER_VALIDATE_IP));
    } else if (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
        array_push($ips, filter_var(wp_unslash($_SERVER['HTTP_FORWARDED_FOR']), FILTER_VALIDATE_IP));
    } else if (isset($_SERVER['HTTP_FORWARDED'])) {
        array_push($ips, filter_var(wp_unslash($_SERVER['HTTP_FORWARDED']), FILTER_VALIDATE_IP));
    } else if (isset($_SERVER['REMOTE_ADDR'])) {
        array_push($ips, filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP));
    } else if (isset($_SERVER['HTTP_X_REAL_IP'])) {
        array_push($ips, filter_var(wp_unslash($_SERVER['HTTP_X_REAL_IP']), FILTER_VALIDATE_IP));
    }
    return $ips;
}

function provesrc_import_orders()
{
    if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'provesrc_import_orders_nonce')) {
        wp_send_json_error('Invalid request');
        return;
    }

    $transient_key = 'provesrc_last_import_time';
    $rate_limit_seconds = 60;
    $last_import_time = get_transient($transient_key);
    if ($last_import_time) {
        $current_time = current_time('timestamp');
        $time_since_last_import = $current_time - $last_import_time;
        if ($time_since_last_import < $rate_limit_seconds) {
            wp_send_json_error('Importing past orders can only be triggered once per minute');
            return;
        }
    }

    $orders = array();
    if (provesrc_has_woocommerce()) {
        $wcOrders = wc_get_orders(array(
            'limit' => 30,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        foreach ($wcOrders as $wco) {
            $orders[] = provesrc_get_order_payload($wco, false);
        }
    }

    $data = array(
        'secret' => 'simple-secret',
        'woocommerce' => provesrc_has_woocommerce(),
        'siteUrl' => get_site_url(),
        'siteName' => get_bloginfo('name'),
        'multisite' => is_multisite(),
        'description' => get_bloginfo('description'),
        'orders' => $orders,
    );

    provesrc_log('importing last orders manually ' . '(' . count($orders) . ' orders)');

    $res = provesrc_send_request('/wp/setup', $data);
    $response_code = wp_remote_retrieve_response_code($res);
    if ($response_code != 200) {
        $response_body = wp_remote_retrieve_body($res);
        $response_data = json_decode($response_body, true);
        if (isset($response_data['error'])) {
            $error_message = $response_data['error'];
        } else {
            $error_message = 'unexpected error ' . $response_code;
        }
        wp_send_json_error('Failed to import orders: ' . $error_message, $response_code);
        set_transient('provesrc_error_notice', $error_message, 60);
        provesrc_handle_error('failed sending request', $error_message);
    } else {
        set_transient($transient_key, current_time('timestamp'), $rate_limit_seconds);
        wp_send_json_success('Import orders completed');
    }
}

function provesrc_debug_log()
{
    if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'provesrc_debug_log_nonce')) {
        wp_send_json_error('Invalid request');
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    $log_file = plugin_dir_path(__FILE__) . 'debug.log';

    if (file_exists($log_file)) {
        $log_content = file_get_contents($log_file);
        wp_send_json_success($log_content);
    } else {
        wp_send_json_error('Debug log file not found');
    }
}


function provesrc_has_woocommerce()
{
    return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
}

function provesrc_admin_menu_page_html()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $apiKey = provesrc_get_api_key(); 
    $selectedEvents = provesrc_get_option_with_fallback(
        PROVESRC_OPTION_EVENTS_KEY,
        PROVESRC_LEGACY_OPTION_EVENTS_KEY,
        []
    );
    $tosAccepted = $apiKey ? true : provesrc_get_tos_accepted();
    if (!$selectedEvents) {
        $selectedEvents = ['woocommerce_checkout_order_processed', 'woocommerce_order_status_completed'];
    }
    $woocommerce_hooks = [
        'woocommerce_order_status_completed' => 'Order Status Completed (Recommended)',
        'woocommerce_order_status_pending' => 'Order Status Pending Payment',
        'woocommerce_order_status_processing' => 'Order Status Processing',
        'woocommerce_checkout_create_order' => 'Checkout Order Created',
        'woocommerce_checkout_order_processed' => 'Checkout Order Processed (Recommended)',
        'woocommerce_payment_complete' => 'Payment Complete',
        'woocommerce_thankyou' => 'Thank You',
        'woocommerce_new_order' => 'New Order',
    ];
    ?>

    <div class="wrap" id="ps-settings">
        <!-- <h1><?php esc_html(get_admin_page_title()); ?></h1> -->
        <a href="https://provesrc.com">
            <img class="top-logo" src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/top-logo.png'); ?>">
        </a>
        <form action="options.php" method="post">
            <?php
            settings_fields(PROVESRC_OPTIONS_GROUP);
            do_settings_sections(PROVESRC_OPTIONS_GROUP);
            ?>
            <div class="ps-settings-container">
                <?php if ($apiKey != null) { ?>
                    <div class="ps-success">ProveSource is Installed</div>
                    <div class="ps-warning">
                        If you still see <strong>"waiting for data..."</strong> open your website in <strong>incognito</strong> or <strong>clear cache</strong>
                        <br>If you have <strong>cache or security plugins</strong>, please <a href="http://help.provesrc.com/en/articles/4206151-common-wordpress-woocommerce-issues">see this guide</a> about possible issues and how to solve them
                    </div>
                <?php } else { ?>
                    <div class="ps-red-warning">Add your API Key below</div>
                    <div class="account-link">If you don't have an account - <a href="https://console.provesrc.com/?utm_source=woocommerce&utm_medium=plugin&utm_campaign=woocommerce-signup#/signup" target="_blank">signup here!</a></div>
                <?php } ?>
                <div class="label">Your API Key: <span style="color: #dc3232;">*</span></div>
                    <input type="text" class="ps-apikey" placeholder="required" name="<?php echo esc_attr(PROVESRC_OPTION_API_KEY); ?>" value="<?php echo esc_attr($apiKey); ?>" />
                <div class="m-t"><a href="https://console.provesrc.com/#/settings" target="_blank">Where is my API Key?</a></div>
                <?php if (provesrc_has_woocommerce()) { ?>
                    <div class="m-t-2">
                        <label class="strong" for="woo_events">WooCommerce Events <span style="color: #dc3232;">*</span></label>
                        <p class="description">Select which WooCommerce order/checkout events ProveSource will track:</p>
                        <?php foreach ($woocommerce_hooks as $hook_value => $hook_label) { 
                            $isChecked = in_array($hook_value, (array) $selectedEvents);
                            ?>
                            <input id="woo_events" type="checkbox" 
                                name="<?php echo esc_attr(PROVESRC_OPTION_EVENTS_KEY . '[]'); ?>" 
                                value="<?php echo esc_attr($hook_value); ?>"
                                <?php checked($isChecked); ?> >
                                <?php echo esc_html($hook_label); ?>
                            </input>
                            <br>
                        <?php } ?>
                    </div>
                <?php } ?>
                <div style="overflow: auto; margin-top:10px">
                    <div style="display: flex;  align-items: center;">
                        <div>
                            <div class="d-inline-block m-r strong" style="height:25px; line-height: 2.4em">Enable Debug Mode:</div>
                            <div style="margin-top:-2px;">
                                <a href="#" id="download_debug_log" style="text-decoration: none; color: #0073aa;">Download Debug Log</a>
                            </div>
                        </div>
                        <div class="d-inline-block ps-toggle" style="float: left;margin-top:8px; margin-left:10px">
                            <input type="checkbox" class="ps-toggle-checkbox" id="ps-toggle" tabindex="0"
                                name="<?php echo esc_attr(PROVESRC_OPTION_DEBUG_KEY); ?>" <?php if (provesrc_get_debug()) { echo "checked"; } ?>>
                            <label class="ps-toggle-label" for="ps-toggle"></label>
                        </div>
                    </div>
                </div>
                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        $('#download_debug_log').on('click', function(e) {
                            e.preventDefault();
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'provesrc_debug_log',
                                    security: '<?php echo esc_js(wp_create_nonce("provesrc_debug_log_nonce")); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        var blob = new Blob([response.data], {
                                            type: 'text/plain'
                                        });
                                        var downloadUrl = URL.createObjectURL(blob);
                                        var a = document.createElement('a');
                                        a.href = downloadUrl;
                                        a.download = 'debug.log';
                                        document.body.appendChild(a);
                                        a.click();
                                        document.body.removeChild(a);
                                        URL.revokeObjectURL(downloadUrl);
                                    } else {
                                        alert('Failed to download debug log: ' + response.data);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    alert('An error occurred: ' + error);
                                }
                            });
                        });
                    });
                </script>
            </div>
            <div class="m-t-2">
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(PROVESRC_OPTION_ANALYTICS_KEY); ?>" value="1" <?php checked(provesrc_get_analytics_consent()); ?> id="analytics_checkbox">
                    Allow analytics data about plugin activity and website data (optional)
                </label>
            </div>
            <div class="m-t-1">
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(PROVESRC_OPTION_TOS_KEY); ?>" value="1" <?php checked($tosAccepted); ?> required id="tos_checkbox">
                    By using the ProveSource plugin, you agree to our <a href="https://provesrc.com/terms/" target="_blank">Terms of Service</a><span style="color: #dc3232;"> *</span><br>
                    <span style="margin-left: 23px; font-size: 0.9em;">(ProveSource will add provesrc.js to your website and automatically retrieve website name, description, URL and recent orders for initial setup).</span>
                </label>
            </div>
            <style>
                .ps-spinner {
                    display: inline-block;
                    width: 16px;
                    height: 16px;
                    border: 2px solid #f3f3f3;
                    border-top: 2px solid #0073aa;
                    border-radius: 50%;
                    animation: ps-spin 1s linear infinite;
                    margin-right: 8px;
                }
                @keyframes ps-spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .ps-loading {
                    opacity: 0.6;
                    pointer-events: none;
                }
            </style>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    function toggleButtons() {
                        var tosChecked = $('#tos_checkbox').is(':checked');
                        var apiKey = $('[name="<?php echo esc_js(PROVESRC_OPTION_API_KEY); ?>"]').val();
                        
                        // Save button is enabled if Terms of Service is checked (API key is optional)
                        if (tosChecked) {
                            $('#submit').prop('disabled', false);
                        } else {
                            $('#submit').prop('disabled', true);
                        }
                        
                        // Import button is only enabled if API key is valid
                        if (apiKey && tosChecked) {
                            $('#import_orders_button').prop('disabled', <?php echo esc_js(!provesrc_isvalid_api_key($apiKey) ? 'true' : 'false'); ?>);
                        } else {
                            $('#import_orders_button').prop('disabled', true);
                        }
                    }

                    // Add loading state to Save button
                    $('form').on('submit', function() {
                        var $submitBtn = $('#submit');
                        var originalText = $submitBtn.val();
                        
                        $submitBtn.addClass('ps-loading');
                        $submitBtn.val('Saving...');
                        $submitBtn.prepend('<span class="ps-spinner"></span>');
                        
                        // Re-enable after a delay in case of errors
                        setTimeout(function() {
                            $submitBtn.removeClass('ps-loading');
                            $submitBtn.val(originalText);
                            $submitBtn.find('.ps-spinner').remove();
                        }, 10000);
                    });

                    $('#tos_checkbox').on('change', toggleButtons);
                    $('[name="<?php echo esc_html(PROVESRC_OPTION_API_KEY); ?>"]').on('input', toggleButtons);
                    toggleButtons();
                });
            </script>
            <div style="display:flex; align-items:center">
                <div>
                    <?php submit_button('Save'); ?>
                </div>
                <div style="margin-top:7px; margin-left:20px; font-weight: bold">
                    <button
                        <?php echo esc_attr(!provesrc_isvalid_api_key($apiKey) ? 'disabled' : ''); ?>
                        type="button"
                        id="import_orders_button"
                        style=" padding-top:3px; padding-bottom:3px; background-color:#7825f3; border:none"
                        class="button button-primary ">
                        Re-import Last 30 Orders
                    </button>
                    <script type="text/javascript">
                        jQuery(document).ready(function($) {
                            $('#import_orders_button').on('click', function() {
                                var $btn = $(this);
                                var originalText = $btn.text();
                                
                                // Add loading state
                                $btn.addClass('ps-loading');
                                $btn.prop('disabled', true);
                                $btn.text('Importing...');
                                $btn.prepend('<span class="ps-spinner"></span>');
                                
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'provesrc_import_orders',
                                        security: '<?php echo esc_js(wp_create_nonce("provesrc_import_orders_nonce")); ?>'
                                    },
                                    success: function(response) {
                                        // Remove loading state
                                        $btn.removeClass('ps-loading');
                                        $btn.prop('disabled', false);
                                        $btn.text(originalText);
                                        $btn.find('.ps-spinner').remove();
                                        
                                        if (response.success) {
                                            alert('Orders imported successfully!');
                                        } else {
                                            alert(response.data);
                                        }
                                    },
                                    error: function(xhr, status, error) {
                                        // Remove loading state
                                        $btn.removeClass('ps-loading');
                                        $btn.prop('disabled', false);
                                        $btn.text(originalText);
                                        $btn.find('.ps-spinner').remove();
                                        
                                        if (xhr.responseJSON && xhr.responseJSON.data) {
                                            alert(xhr.responseJSON.data);
                                        } else {
                                            alert(error);
                                        }
                                    }
                                });
                            });
                        });
                    </script>
                </div>
            </div>
        </form>
        <p class="ps-version-text">ProveSource WordPress Plugin v<?php echo esc_html(PROVESRC_VERSION); ?></p>
    </div>

<?php
}
function provesrc_admin_notice_html()
{
    $apiKey = provesrc_get_api_key();
    $error_message = get_transient('provesrc_api_error');
    $success_message = get_transient('provesrc_success_message');

    if ($apiKey != null && !$error_message && !$success_message) {
        return;
    }

    // $screen = get_current_screen();
    // if($screen !== null && strpos($screen->id, 'provesrc') > 0) return;

?>
    <div class="notice is-dismissible <?php echo esc_attr($success_message ? 'notice-success' : 'notice-error'); ?>">
        <?php if ($apiKey == null): ?>
            <p class="ps-error">ProveSource is not configured! <a href="admin.php?page=provesrc">Click here</a> to set up your API key.</p>
        <?php elseif ($error_message): ?>
            <p class="ps-error"><a href="admin.php?page=provesrc">ProveSource</a> encountered an error (check your API key): <?php echo esc_html($error_message); ?></p>
        <?php elseif ($success_message): ?>
            <p class="ps-success"><?php echo esc_html($success_message); ?></p>
        <?php endif; ?>
    </div>
<?php
    if ($success_message) {
        delete_transient('provesrc_success_message');
    }
}

function provesrc_log($message, $data = null)
{
    $debug = provesrc_get_debug();
    if (!$debug) {
        return;
    }
    $log = current_time("Y-m-d\TH:i:s.u ");
    if (isset($data)) {
        $log .= "[ProveSource] " . $message . ": " . print_r($data, true);
    } else {
        $log .= "[ProveSource] " . $message;
    }
    $log .= "\n";
    error_log($log);

    $pluginlog = plugin_dir_path(__FILE__) . 'debug.log';
    error_log($log, 3, $pluginlog);
}

function provesrc_var_dump_str($data)
{
    ob_start();
    var_dump($data);

    return ob_get_clean();
}

function provesrc_encode_exception($err)
{
    if (!isset($err) || is_null($err)) {
        return [];
    }
    return [
        'message' => $err->getMessage(),
        'code' => $err->getCode(),
        'file' => $err->getFile() . ':' . $err->getLine(),
        'trace' => substr($err->getTraceAsString(), 0, 500),
    ];
}

/**
 * Sanitization callback for events array field
 * @param mixed $value The value to sanitize
 * @return array Sanitized array of event names
 */
function provesrc_sanitize_events_array($value)
{
    if (!is_array($value)) {
        return array();
    }
    
    // Define allowed event names for validation
    $allowed_events = array(
        'woocommerce_order_status_completed',
        'woocommerce_order_status_pending',
        'woocommerce_order_status_processing',
        'woocommerce_checkout_create_order',
        'woocommerce_checkout_order_processed',
        'woocommerce_payment_complete',
        'woocommerce_thankyou',
        'woocommerce_new_order'
    );
    
    $sanitized = array();
    for ($i = 0; $i < count($value); $i++) {
        $event = sanitize_key($value[$i]);
        if (in_array($event, $allowed_events)) {
            $sanitized[] = $event;
        }
    }
    
    return $sanitized;
}

/* helpers - END */

?>