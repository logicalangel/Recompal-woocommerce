<?php
/**
 * Plugin Name: Recompal
 * Plugin URI: https://recompal.com/
 * Description: Your AI Sales Assistant for WooCommerce Stores
 * Version: 1.0.0
 * Author: Recompal
 * Author URI: https://recompal.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: recompal
 * Domain Path: /languages
 * Requires at least: 6.6
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Recompal
 */

// Restrict code to only run in Wordpress
defined('ABSPATH') || exit;

// Configuration Constants
define('RECOMPAL_API_URL', 'https://api.recompal.com');
define('RECOMPAL_APP_URL', 'https://app.recompal.com');

// Load plugin text domain for translations
add_action('plugins_loaded', function() {
    load_plugin_textdomain('recompal', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Widget Loader
add_action('wp_enqueue_scripts', function () {

    // wp_enqueue_style(
    //     'my-chat-widget-style',
    //     plugins_url('assets/css/chat-widget.css', __FILE__),
    //     array(),
    //     '1.0'
    // );

    wp_enqueue_script(
        'recompal-bundle',
        plugins_url('assets/js/loader.js', __FILE__),
        array(),
        '1.0',
        true
    );
});

add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if ('recompal-bundle' === $handle) {
        $current_user = wp_get_current_user();
        $customerID = intval($current_user->ID) ?? 1;
        $name = esc_js($current_user->display_name ?? "Customer");
        $firstname = esc_js($current_user->first_name ?? "Customer");
        $lastname = esc_js($current_user->last_name ?? "");
        $email = esc_js($current_user->user_email ?? "");
        $site_url = parse_url(home_url(), PHP_URL_HOST);
        $site_id = abs(crc32($site_url));

        $tag = str_replace(
            "<script",
            "<script onload=\"initRecompal('$site_id', $customerID, '$name', '$firstname', '$lastname', '$email')\"",
            $tag
        );
    }
    return $tag;
}, 10, 3);

// API Proxy to avoid CORS issues
add_action('rest_api_init', function () {
    register_rest_route('recompal/v1', '/proxy(?P<path>.*)', array(
        'methods' => array('GET', 'POST', 'PUT', 'DELETE', 'PATCH'),
        'callback' => 'recompal_api_proxy',
        'permission_callback' => function() {
            // Allow logged-in users with read capability
            return current_user_can('read');
        }
    ));
});

function recompal_api_proxy($request) {
    // Get endpoint from URL path or request body
    $endpoint = $request->get_param('path') ?: '';
    
    // If endpoint is not in URL, check request body
    if (empty($endpoint)) {
        $params = $request->get_json_params();
        $endpoint = $params['endpoint'] ?? '';
    }
    
    // Get method from request or body
    $method = $request->get_method();
    if ($method === 'POST') {
        $params = $request->get_json_params();
        $method = $params['method'] ?? $method;
        $body_data = $params['data'] ?? $request->get_json_params();
    } else {
        $body_data = $request->get_json_params() ?: array();
    }
    
    if (empty($endpoint)) {
        return new WP_Error('missing_endpoint', 'Endpoint is required', array('status' => 400));
    }

    // Build the full URL
    $base_url = RECOMPAL_API_URL;
    $url = $base_url . $endpoint;
    
    $jwt_token = get_option('recompal_token');
    
    $args = array(
        'method' => strtoupper($method),
        'timeout' => 30,
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $jwt_token,
        ),
    );
    
    if (!empty($body_data) && in_array(strtoupper($method), array('POST', 'PUT', 'PATCH'))) {
        $args['body'] = wp_json_encode($body_data);
    }
    
    $response = wp_remote_request($url, $args);
    
    if (is_wp_error($response)) {
        return new WP_Error('proxy_error', $response->get_error_message(), array('status' => 500));
    }
    
    $body = wp_remote_retrieve_body($response);
    $status_code = wp_remote_retrieve_response_code($response);
    
    return new WP_REST_Response(json_decode($body), $status_code);
}

// Product Sync Webhooks
function recompal_send_product_webhook($product_id, $action) {
    $product = wc_get_product($product_id);
    
    if (!$product) {
        return;
    }

    $jwt_token = get_option('recompal_token');
    $site_url = parse_url(home_url(), PHP_URL_HOST);

    $product_data = array(
        'id' => $product->get_id(),
        'name' => $product->get_name(),
        'slug' => $product->get_slug(),
        'type' => $product->get_type(),
        'status' => $product->get_status(),
        'description' => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'sku' => $product->get_sku(),
        'price' => $product->get_price(),
        'regular_price' => $product->get_regular_price(),
        'sale_price' => $product->get_sale_price(),
        'stock_quantity' => $product->get_stock_quantity(),
        'stock_status' => $product->get_stock_status(),
        'categories' => wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names')),
        'tags' => wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names')),
        'images' => array_map(function($image_id) {
            return wp_get_attachment_url($image_id);
        }, $product->get_gallery_image_ids()),
        'featured_image' => wp_get_attachment_url($product->get_image_id()),
        'permalink' => get_permalink($product_id),
        'action' => $action, // 'created', 'updated', 'deleted'
    );

    $body = array(
        'host' => $site_url,
        'token' => $jwt_token,
        'product' => $product_data,
    );

    wp_remote_post(RECOMPAL_API_URL . '/woocommerce/webhook', array(
        'method' => 'POST',
        'timeout' => 10,
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode($body),
        'blocking' => false, // Don't wait for response to avoid slowing down WooCommerce
    ));
}

// Hook into product created
add_action('woocommerce_new_product', function($product_id) {
    recompal_send_product_webhook($product_id, 'created');
}, 10, 1);

// Hook into product updated
add_action('woocommerce_update_product', function($product_id) {
    recompal_send_product_webhook($product_id, 'updated');
}, 10, 1);

// Hook into product deleted
add_action('before_delete_post', function($product_id) {
    $post = get_post($product_id);
    if ($post && $post->post_type === 'product') {
        recompal_send_product_webhook($product_id, 'deleted');
    }
}, 10, 1);

// Install Function
register_activation_hook(__FILE__, function () {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('Recompal requires WooCommerce to be installed and active. Please install and activate WooCommerce first.', 'recompal'),
            __('Plugin Activation Error', 'recompal'),
            array('back_link' => true)
        );
    }

    $site_url = get_site_url();
    $admin_email = get_option('admin_email');

    $consumer_key = 'ck_' . wc_rand_hash();
    $consumer_secret = 'cs_' . wc_rand_hash();
    $data = array(
        'user_id' => 1,
        'description' => sanitize_text_field('Recompal Plugin Key'),
        'permissions' => 'read',
        'consumer_key' => $consumer_key,
        'consumer_secret' => $consumer_secret,
        'truncated_key' => substr($consumer_key, -7),
    );

    // Insert the key into the database
    global $wpdb;
    $inserted = $wpdb->insert(
        $wpdb->prefix . 'woocommerce_api_keys',
        $data,
        array('%d', '%s', '%s', '%s', '%s', '%s')
    );

    if (false === $inserted) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('Failed to create WooCommerce API key. Please try again or contact support.', 'recompal'),
            __('Database Error', 'recompal'),
            array('back_link' => true)
        );
    }

    $site_url = parse_url(home_url(), PHP_URL_HOST);
    $site_id = abs(crc32($site_url));

    $body = [
        'host' => $site_url,
        'email' => $admin_email,
        'plugin_version' => '1.0.0',
        'key' => $consumer_key,
        'secret' => $consumer_secret,
        'meta' => [
            'id' => $site_id,
            'name' => get_bloginfo('name'),
            'email' => get_option('admin_email'),
            'domain' => parse_url(home_url(), PHP_URL_HOST),
            'country' => get_option('woocommerce_default_country'),
            'address1' => get_option('woocommerce_store_address'),
            'address2' => get_option('woocommerce_store_address_2'),
            'city' => get_option('woocommerce_store_city'),
            'zip' => get_option('woocommerce_store_postcode'),
            'phone' => get_option('woocommerce_store_phone') ?: '',
            'currency' => get_woocommerce_currency(),
            'timezone' => wp_timezone_string(),
            'source' => "woocommerce",

            'shop_owner' => get_user_by('ID', 1) ? get_user_by('ID', 1)->display_name : '',
        ]
    ];

    $response = wp_remote_post(RECOMPAL_API_URL . '/woocommerce/install', [
        'method' => 'POST',
        'timeout' => 10,
        'redirection' => 5,
        'httpversion' => '1.1',
        'blocking' => true,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        // Delete the API key we created since registration failed
        $wpdb->delete(
            $wpdb->prefix . 'woocommerce_api_keys',
            array('consumer_key' => $consumer_key),
            array('%s')
        );
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                __('Cannot register shop with Recompal: %s. Please check your internet connection and try again.', 'recompal'),
                esc_html($error_message)
            ),
            __('Registration Error', 'recompal'),
            array('back_link' => true)
        );
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200 && $response_code !== 201) {
        // Delete the API key we created since registration failed
        $wpdb->delete(
            $wpdb->prefix . 'woocommerce_api_keys',
            array('consumer_key' => $consumer_key),
            array('%s')
        );
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                __('Recompal server returned error (code: %d). Please try again later or contact support.', 'recompal'),
                $response_code
            ),
            __('Registration Error', 'recompal'),
            array('back_link' => true)
        );
    }
    
    $body = wp_remote_retrieve_body($response);
    $bodyJson = json_decode($body);
    
    if (!$bodyJson || !isset($bodyJson->token)) {
        // Delete the API key we created since registration failed
        $wpdb->delete(
            $wpdb->prefix . 'woocommerce_api_keys',
            array('consumer_key' => $consumer_key),
            array('%s')
        );
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('Invalid response from Recompal server. Please try again later or contact support.', 'recompal'),
            __('Registration Error', 'recompal'),
            array('back_link' => true)
        );
    }
    
    add_option('recompal_token', sanitize_text_field($bodyJson->token));
    add_option('recompal_api_consumer_key', sanitize_text_field($consumer_key));

});

// Settings Page
add_action('admin_menu', function () {
    add_menu_page(
        __('Recompal', "recompal"),
        __('Recompal', "recompal"),
        'manage_options',
        'recompal-settings',
        function () {
            // Verify user has permission
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'recompal'));
            }
            
            $jwt_token = get_option('recompal_token');
            $iframe_url = RECOMPAL_APP_URL . '/woocommerce?source=woocommerce&host=' . parse_url(home_url(), PHP_URL_HOST) . "&token=$jwt_token";
            ?>
<div class="recompal-dashboard">
    <iframe src="<?php echo esc_url($iframe_url); ?>" style="width: 100%; height: 100vh; border: none;"
        title="React Dashboard"
        allow="clipboard-read; clipboard-write; geolocation; microphone; camera; payment; autoplay; encrypted-media; fullscreen"
        sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation allow-modals"></iframe>
</div>
<script>
// Listen for messages from iframe
window.addEventListener('message', function(event) {
    // Verify the origin in production
    var allowedOrigin = '<?php echo esc_js(RECOMPAL_APP_URL); ?>';
    if (event.origin !== allowedOrigin) {
        console.warn('Message from unauthorized origin:', event.origin);
        return;
    }

    if (event.data.action === 'navigate') {
        window.location.href = event.data.url;
    }
});
</script>
<?php
        },
        plugins_url('recompal/assets/img/favicon-16x16.png'),
    );

    // Add submenu pages
    add_submenu_page(
        'recompal-settings',                    // Parent slug
        __('Appearance', 'recompal'),           // Page title
        __('Appearance', 'recompal'),           // Menu title
        'manage_options',                       // Capability
        'recompal-appearance',                  // Menu slug
        function () {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'recompal'));
            }
            $jwt_token = get_option('recompal_token');
            $iframe_url = RECOMPAL_APP_URL . '/woocommerce/appearance?source=woocommerce&host=' . parse_url(home_url(), PHP_URL_HOST) . "&token=$jwt_token";
            ?>
<div class="recompal-appearance">
    <iframe src="<?php echo esc_url($iframe_url); ?>" style="width: 100%; height: 100vh; border: none;"
        title="Recompal Appearance" allow="clipboard-read; clipboard-write"
        sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation allow-modals"></iframe>
</div>
<?php
        }
    );

    add_submenu_page(
        'recompal-settings',                    // Parent slug
        __('Conversation', 'recompal'),            // Page title
        __('Conversation', 'recompal'),            // Menu title
        'manage_options',                       // Capability
        'recompal-conversation',          
        function () {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'recompal'));
            }
            $jwt_token = get_option('recompal_token');
            $iframe_url = RECOMPAL_APP_URL . '/woocommerce/conversation?source=woocommerce&host=' . parse_url(home_url(), PHP_URL_HOST) . "&token=$jwt_token";
            ?>
<div class="recompal-dashboard">
    <iframe src="<?php echo esc_url($iframe_url); ?>" style="width: 100%; height: 100vh; border: none;"
        title="React Conversation" allow="clipboard-read; clipboard-write"
        sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation allow-modals"></iframe>
</div>
<?php
        }
    );

    add_submenu_page(
        'recompal-settings',                    // Parent slug
        __('DataSource', 'recompal'),            // Page title
        __('DataSource', 'recompal'),            // Menu title
        'manage_options',                       // Capability
        'recompal-analytics',                   // Menu slug
        function () {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'recompal'));
            }
            $jwt_token = get_option('recompal_token');
            $iframe_url = RECOMPAL_APP_URL . '/woocommerce/datasource?source=woocommerce&host=' . parse_url(home_url(), PHP_URL_HOST) . "&token=$jwt_token";
            ?>
<div class="recompal-datasource">
    <iframe src="<?php echo esc_url($iframe_url); ?>" style="width: 100%; height: 100vh; border: none;"
        title="Recompal DataSource" allow="clipboard-read; clipboard-write"
        sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation allow-modals"></iframe>
</div>
<?php
        }
    );

    add_submenu_page(
        'recompal-settings',                    // Parent slug
        __('Billing & Plan', 'recompal'),             // Page title
        __('Billing & Plan', 'recompal'),             // Menu title
        'manage_options',                       // Capability
        'recompal-config',                      // Menu slug
        function () {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'recompal'));
            }
            $jwt_token = get_option('recompal_token');
            $iframe_url = RECOMPAL_APP_URL . '/woocommerce/subscription?source=woocommerce&host=' . parse_url(home_url(), PHP_URL_HOST) . "&token=$jwt_token";
            ?>
<div class="recompal-billing">
    <iframe src="<?php echo esc_url($iframe_url); ?>" style="width: 100%; height: 100vh; border: none;"
        title="Recompal Billing & Plan" allow="clipboard-read; clipboard-write; payment"
        sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation allow-modals"></iframe>
</div>
<?php
        }
    );

    add_submenu_page(
        'recompal-settings',                    // Parent slug
        __('Help & Support', 'recompal'),       // Page title
        __('Help & Support', 'recompal'),       // Menu title
        'manage_options',                       // Capability
        'recompal-support',                     // Menu slug
        function () {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'recompal'));
            }
            $jwt_token = get_option('recompal_token');
            $iframe_url = RECOMPAL_APP_URL . '/woocommerce/help?source=woocommerce&host=' . parse_url(home_url(), PHP_URL_HOST) . "&token=$jwt_token";
            ?>
<div class="recompal-support">
    <iframe src="<?php echo esc_url($iframe_url); ?>" style="width: 100%; height: 100vh; border: none;"
        title="Recompal Support" allow="clipboard-read; clipboard-write"
        sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-top-navigation allow-modals"></iframe>
</div>
<?php
        }
    );
});