<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * @link       http://recompal.com
 * @since      1.0.0
 *
 * @package    Recompal
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Delete plugin options
delete_option('recompal_token');
delete_option('recompal_api_consumer_key');

// Delete WooCommerce API key
$consumer_key = get_option('recompal_api_consumer_key');
if ($consumer_key) {
	global $wpdb;
	$wpdb->delete(
		$wpdb->prefix . 'woocommerce_api_keys',
		array('consumer_key' => $consumer_key),
		array('%s')
	);
}

// Clean up any transients
delete_transient('recompal_activation_redirect');

// Optional: Notify Recompal API that plugin was uninstalled
// Uncomment if you want to track uninstalls
/*
$token = get_option('recompal_token');
if ($token) {
	$api_url = 'https://api.recompal.com'; // Use production URL
	wp_remote_post($api_url . '/woocommerce/uninstall', array(
		'method' => 'POST',
		'timeout' => 5,
		'headers' => array(
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . $token,
		),
		'body' => wp_json_encode(array(
			'host' => parse_url(home_url(), PHP_URL_HOST),
		)),
		'blocking' => false,
	));
}
*/