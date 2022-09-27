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

/* Define Some Plugin items */
const OOMP_VER_NUM = '1.1.0'; // same as plugin version up top.
const OOMP_NAMESPACE = '/1o-to-store-api'; // namespace for endpoint.
const OOMP_PASETO_EXP = 'P01Y'; // Paseto Expiry time. Set to PT05M for production
const OOMP_LOC_PATH = __DIR__ . '/assets/inc';
define('OOMP_LOC_URL', plugins_url('assets', __FILE__));

// If this file is called directly, abort
if (!defined('WPINC')) {
    die;
}

// Include the Settings Page Class
require_once OOMP_LOC_PATH . '/settings-page.php';
define('OOMP_GRAPHQL_URL', oneO_Settings::get_oneO_settings_options('graphql_endpoint')); // https://playground.1o.io/graphql // GraphQL URL for 1o

// Functions
require_once OOMP_LOC_PATH . '/1o-merchant-plugin-core-functions.php';

// Ajax
require_once OOMP_LOC_PATH . '/1o-merchant-plugin-ajax-request.php';

// Include the PASETO & GRAPHQL Libraries
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
    new oneO_Settings();

} else {
    // Front End Scripts
//    add_action('wp_enqueue_scripts', function () {
        // load admin CSS
        //wp_enqueue_style('1o-merchant-plugin-core-css', OOMP_LOC_URL . '/css/1o-merchant-plugin-core.css', null, time(), 'all');
        // load admin JS
        //wp_enqueue_script('1o-merchant-plugin-core-js', OOMP_LOC_URL . '/js/1o-merchant-plugin-core.js', ['jquery'], time(), true);
//    });
}

/**
 * Custom Update Mechanism
 *
 * Only use this if plugin is being managed manually (outside of WordPress Repository).
 */
//include __DIR__ . '/updates/register.php';
