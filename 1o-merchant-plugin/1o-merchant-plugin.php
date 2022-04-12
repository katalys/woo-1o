<?php
/*
Plugin Name: 1o Merchant Plugin
URI: https://fischercreativemedia.com
Description: Plugin to add functionality for 1o cart connection.
Version: 1.0.8
Author: 1o | Don Fischer
Author URI: https://fischercreativemedia.com
Text Domain: 1o-merchant-plugin
*/

// If this file is called directly, abort. //
if (!defined('WPINC')) {
  die;
}
/* defined constant for error logging */
define('OOMP_ERROR_LOG', true);
if (OOMP_ERROR_LOG) {
  ini_set('display_errors', 0);
  ini_set('log_errors', 1);
  ini_set('error_log', dirname(__FILE__) . '/error-log.txt');
  error_reporting(E_ALL  & ~E_NOTICE & ~E_WARNING);
}

/**
 * If plugin is added to WordPress Repository, set to false.
 * 
 * If usung External Update process set to true so there can
 * be a way to update the plugin easily.
 */
$useExternalUpdate = true;
if ($useExternalUpdate) {
  //Required for plugin update
  require_once(plugin_dir_path(__FILE__) . '/updates/plugin-update-checker.php');
  new FCMPluginUpdateChecker_1_7('https://graphiccaffeine.com/wp-admin/admin-ajax.php', __FILE__);

  // Auto update variables fpr plugin
  add_filter('all_plugins', function ($plugins) {
    if (isset($plugins['1o-merchant-plugin/1o-merchant-plugin.php'])) {
      $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['update-supported'] = true;
      $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['id'] = 'gc.com/plugins/1omerchantplugin';
      $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['slug'] = '1o-merchant-plugin';
      $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['plugin'] = '1o-merchant-plugin/1o-merchant-plugin.php';
      $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['url'] = 'https://graphiccaffeine.com/fcm-custom-plugins/1o-merchant-plugin/';
    }
    return $plugins;
  }, 100);
}

/**
 * Let's Initialize Everything
 * 
 * If you are not using one of these files, 
 * set to false to keep it from loading.
 * 
 * */
global $oomp_load_items;
$oomp_load_items = array(
  /* if you want to use these files, set to true and then populate the files in the folders they reside. */
  'functions' => true, // load additional core funstion
  'ajax' => false, // load ajax funsctions
  'vendors' => true, // load GraphQL & PASETO Libraries.    
  'admin_js' => false,  // load admin js
  'admin_css' => true, // load admin css
  'js' => false, // load core plugin frontend js
  'css' => false, // load core plugin frontend css
  'settings' => true, // load settings page
);

/* global for error loggin */
global $oneOControllerLog;
$oneOControllerLog = array();

/* Define Some Plugin items */
define('OOMP_VER_NUM', '1.0.8'); // same as plugin version up top.
define('OOMP_NAMESPACE', '/1o-to-store-api'); // namespace for endpoint.
define('OOMP_PASETO_EXP', 'P01Y'); // Paseto Expiry time. Set to PT05M for production
define('OOMP_LOC_CORE', dirname(__FILE__) . '/');
define('OOMP_LOC_CORE_INC', dirname(__FILE__) . '/assets/inc/');
define('OOMP_LOC_CORE_IMG', plugins_url('assets/img/', __FILE__));
define('OOMP_LOC_CORE_CSS', plugins_url('assets/css/', __FILE__));
define('OOMP_LOC_CORE_JS', plugins_url('assets/js/', __FILE__));
define('OOMP_LOC_VENDOR_PATH', dirname(__FILE__) . '/vendor/');
define('OOMP_LOC_VENDOR_URL', plugins_url('vendor/', __FILE__));

/* add the global to the admin menu for use */
add_action('admin_menu', function () {
  global $oomp_load_items;
}, 999);

// Front End Scripts
add_action('wp_enqueue_scripts', function () {
  global $oomp_load_items;
  if ($oomp_load_items['css'])
    wp_enqueue_style('1o-merchant-plugin-core-css', OOMP_LOC_CORE_CSS . '1o-merchant-plugin-core.css', null, time(), 'all');
  if ($oomp_load_items['js'])
    wp_enqueue_script('1o-merchant-plugin-core-js', OOMP_LOC_CORE_JS . '1o-merchant-plugin-core.js', array('jquery'), time(), true);
});

// Admin Scripts
add_action('admin_enqueue_scripts', function () {
  global $oomp_load_items;
  if ($oomp_load_items['admin_css'])
    wp_enqueue_style('1o-merchant-plugin-admin-css', OOMP_LOC_CORE_CSS . '1o-merchant-plugin-admin.css', null, time(), 'all');
  if ($oomp_load_items['admin_js'])
    wp_enqueue_script('1o-merchant-plugin-admin-js', OOMP_LOC_CORE_JS . '1o-merchant-plugin-admin.js', array('jquery'), time(), true);
});

// Include the Settings Page Class
if (file_exists(OOMP_LOC_CORE_INC . 'settings-page.php') && $oomp_load_items['settings']) {
  require_once(OOMP_LOC_CORE_INC . 'settings-page.php');
  define('OOMP_GRAPHQL_URL', oneO_Settings::get_oneO_settings_options('graphql_endpoint')); // https://playground.1o.io/graphql // GraphQL URL for 1o
} else {
  //if you are not using a settings page, then you need to add this manually!
  define('OOMP_GRAPHQL_URL', ''); //GraphQL URL for 1o
}

// Functions
if (file_exists(OOMP_LOC_CORE_INC . '1o-merchant-plugin-core-functions.php') && $oomp_load_items['functions']) {
  require_once OOMP_LOC_CORE_INC . '1o-merchant-plugin-core-functions.php';
}

// Ajax
if (file_exists(OOMP_LOC_CORE_INC . '1o-merchant-plugin-ajax-request.php') && $oomp_load_items['ajax']) {
  require_once OOMP_LOC_CORE_INC . '1o-merchant-plugin-ajax-request.php';
}

// Include the PASETO & GRAPHQL Libraries
if (file_exists(OOMP_LOC_VENDOR_PATH . 'autoload.php') && $oomp_load_items['vendors']) {
  include_once(OOMP_LOC_VENDOR_PATH . 'autoload.php');
}
