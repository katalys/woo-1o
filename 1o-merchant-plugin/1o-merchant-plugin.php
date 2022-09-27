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

// If this file is called directly, abort
if (!defined('WPINC')) {
  die;
}

/* Define Some Plugin items */
const OOMP_VER_NUM = '1.1.0'; // same as plugin version up top.
const OOMP_NAMESPACE = '/1o-to-store-api'; // namespace for endpoint.
const OOMP_PASETO_EXP = 'P01Y'; // Paseto Expiry time. Set to PT05M for production
const OOMP_LOC_CORE = __DIR__ . '/assets/';
const OOMP_LOC_VENDOR_PATH = __DIR__ . '/vendor/';
define('OOMP_LOC_CORE_URL', plugins_url('assets/', __FILE__));

/* add the global to the admin menu for use */
//add_action('admin_menu', function () {
//}, 999);

// Include the Settings Page Class
require_once OOMP_LOC_CORE . '/inc/settings-page.php';
define('OOMP_GRAPHQL_URL', oneO_Settings::get_oneO_settings_options('graphql_endpoint')); // https://playground.1o.io/graphql // GraphQL URL for 1o


// Setup admin page
if (is_admin()) {

    // Admin Scripts
    add_action('admin_enqueue_scripts', function () {
        // admin_css
        wp_enqueue_style('1o-merchant-plugin-admin-css', OOMP_LOC_CORE_URL . '1o-merchant-plugin-admin.css', null, time(), 'all');
        // admin_js
        //wp_enqueue_script('1o-merchant-plugin-admin-js', OOMP_LOC_CORE_URL . '1o-merchant-plugin-admin.js', ['jquery'], time(), true);
    });

} else {

    // Front End Scripts
//    add_action('wp_enqueue_scripts', function () {
        // load admin CSS
        //wp_enqueue_style('1o-merchant-plugin-core-css', OOMP_LOC_CORE_URL . 'css/1o-merchant-plugin-core.css', null, time(), 'all');
        // load admin JS
        //wp_enqueue_script('1o-merchant-plugin-core-js', OOMP_LOC_CORE_URL . 'js/1o-merchant-plugin-core.js', ['jquery'], time(), true);
//    });
}

// Functions
require_once OOMP_LOC_CORE . '/inc/1o-merchant-plugin-core-functions.php';


/**
 * Hook to register our new routes from the controller with WordPress.
 */
add_action('rest_api_init', function() {
    $oneO_controller = new OneO_REST_DataController();
    $oneO_controller->register_routes();
});

/* Initialize the settings page object */
if (is_admin()) {
    new oneO_Settings();
}


// Ajax
//require_once OOMP_LOC_CORE . '/inc/1o-merchant-plugin-ajax-request.php';

// Include the PASETO & GRAPHQL Libraries
if (file_exists(OOMP_LOC_VENDOR_PATH . 'autoload.php')) {
  include_once OOMP_LOC_VENDOR_PATH . 'autoload.php';
}

/**
 * Custom Update Mechanism
 *
 * Only use this if plugin is being managed manually (outside of WordPress Repository).
 */
//include __DIR__ . '/updates/register.php';
