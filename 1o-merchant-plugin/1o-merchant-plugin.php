<?php
/*
Plugin Name: Katalys Merchant Plugin
URI: https://katalys.com/
Description: Merchant bridge to allow automatic order fulfillment within Katalys + 1o Shop cart connections.
Version: 1.1.0
Author: Katalys
Author URI: https://katalys.com/
Text Domain: 1o-merchant-plugin
*/
namespace KatalysMerchantPlugin;

const OOMP_VER_NUM = '1.1.0'; // same as plugin version up top
const OOMP_NAMESPACE = '/1o-to-store-api'; // namespace for API endpoint
const OOMP_PASETO_EXP = 'PT05M'; // Paseto Expiry time. Use 'PT05M' for production, 'P01Y' for dev
define('OOMP_LOC_URL', plugins_url('assets', __FILE__)); // absolute URL path

// If this file is called directly, abort
if (!defined('WPINC')) {
    die;
}

// Include the Settings Page Class
require_once __DIR__ . '/assets/inc/SettingsPage.php';

// Functions
require_once __DIR__ . '/assets/inc/1o-merchant-plugin-core-functions.php';
require_once __DIR__ . '/assets/inc/GraphQLRequest.php';

// Ajax
require_once __DIR__ . '/assets/inc/1o-merchant-plugin-ajax-request.php';

// use Composer for PASETO library
include_once __DIR__ . '/vendor/autoload.php';

// Setup admin page
if (is_admin()) {
    // Admin Scripts
    add_action('admin_enqueue_scripts', function () {
        // admin_css
        wp_enqueue_style('1o-merchant-plugin-admin-css', OOMP_LOC_URL . '/css/1o-merchant-plugin-admin.css', null, time(), 'all');
        // admin_js
        //wp_enqueue_script('1o-merchant-plugin-admin-js', OOMP_LOC_URL . '/js/1o-merchant-plugin-admin.js', ['jquery'], time(), true);
    });

    /* add the global to the admin menu for use */
    //add_action('admin_menu', function () {
    //}, 999);

    /* Initialize the settings page object */
    new SettingsPage();

} else {
    // Front End Scripts
    //add_action('wp_enqueue_scripts', function () {
        // load admin CSS
        //wp_enqueue_style('1o-merchant-plugin-core-css', OOMP_LOC_URL . '/css/1o-merchant-plugin-core.css', null, time(), 'all');
        // load admin JS
        //wp_enqueue_script('1o-merchant-plugin-core-js', OOMP_LOC_URL . '/js/1o-merchant-plugin-core.js', ['jquery'], time(), true);
    //});
}

/**
 * Add 1o Order Column to order list page.
 *
 * @param array $columns Array of columns (from WP hook)
 * @return array $columns
 */
add_filter('manage_edit-shop_order_columns', function ($columns) {
  $columns['oneo_order_type'] = '1o Order';
  return $columns;
});

/**
 * Add data to 1o Order Column on order list page.
 *
 * @param string $column Name of current column processing (from WP hook)
 * @echo string column data
 */
add_action('manage_shop_order_posts_custom_column', function ($column) {
  global $post;
  if ('oneo_order_type' === $column) {
    $order = wc_get_order($post->ID);
    $isOneO = $order->get_meta('_is-1o-order', true, 'view');
    if ($isOneO) {
      $oneOID = esc_attr($order->get_meta('_1o-order-number', true, 'view'));
      echo $oneOID;
    }
  }
});

/**
 * Custom Update Mechanism
 *
 * Only use this if plugin is being managed manually (outside of WordPress Repository).
 */
//include __DIR__ . '/updates/register.php';
