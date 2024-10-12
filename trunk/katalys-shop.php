<?php
/*
Plugin Name: Katalys
URI: https://katalys.com/
Description: Katalys tracking to reward influencers and driving customers + Katalys embeddebale Shops.
Version: 1.1.23
Author: Katalys
Author URI: https://katalys.com/
Text Domain: katalys-shop
*/
namespace KatalysMerchantPlugin;

const OOMP_VER_NUM = '1.1.23'; // version, should match "Version:" above
const OOMP_TEXT_DOMAIN = 'katalys-shop'; // filename for the plugin, should match "Text Domain:" above
const OOMP_NAMESPACE = 'katalys-to-store-api'; // namespace for API endpoint
const OOMP_PASETO_EXP = 'PT05M'; // Paseto Expiry time, use 'PT05M' for production, 'P01Y' for dev

/**
 * @const string
 */
const KATALYS_COUPON_FREE_SHIPPING = 'KS_';

define('OOMP_LOC_URL', plugins_url('assets', __FILE__)); // absolute URL path

// If this file is called directly, abort
if (!defined('WPINC')) {
  die;
}

/**
 * @return bool
 */
function checkPluginRevoffersExist()
{
  $activePlugins = get_option('active_plugins');
  if (in_array('revoffers-advertiser-integration/revoffers-advertiser-integration.php', $activePlugins)) {
    return true;
  }
  return false;
}

// Include the Settings Page Class
if (!checkPluginRevoffersExist()) {
  require_once __DIR__ . '/assets/inc/AdvertiserIntegration/advertiser-integration.php';
}
require_once __DIR__ . '/assets/inc/SettingsPage.php';

// Functions
require_once __DIR__ . '/assets/inc/functions.php';
require_once __DIR__ . '/assets/inc/GraphQLRequest.php';

// REST endpoint
require_once __DIR__ . '/assets/inc/ApiController.php';
require_once __DIR__ . '/assets/inc/ApiDirectives.php';
add_action('rest_api_init', [ApiController::class, 'register_routes']);
add_action('rest_api_init', function () {
  ApiController::register_routes('1o-to-store-api');
});

// use Composer for PASETO library
include_once __DIR__ . '/vendor/autoload.php';

// Setup admin page
if (is_admin()) {
  new SettingsPage();
}

/**
 * Add 1o Order Column to order list page.
 *
 * @param array $columns Array of columns (from WP hook)
 * @return array $columns
 */
add_filter('manage_edit-shop_order_columns', function ($columns) {
  $columns['oneo_order_type'] = 'Katalys Order';
  return $columns;
});

add_filter( 'woocommerce_coupon_error', function ($err, $err_code, $object) {
  if (!function_exists('wc_add_notice')) {
    return '';
  }
  return $err;
}, 10, 3);

add_filter( 'woocommerce_coupon_message', function ($msg, $msg_code, $object) {
  if (!function_exists('wc_add_notice')) {
    return '';
  }
  return $msg;
}, 10, 3);

validateCouponKatalys();

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
    if ($order->get_meta('_is-1o-order', true, 'view')) {
      $isOneO = $order->get_meta('_is-1o-order', true, 'view');
    } else {
      $isOneO = $order->get_meta('_is-katalys-order', true, 'view');
    }
    if ($isOneO) {
      if ($order->get_meta('_1o-order-number', true, 'view')) {
        echo esc_attr($order->get_meta('_1o-order-number', true, 'view'));
      } else {
        echo esc_attr($order->get_meta('_katalys-order-number', true, 'view'));
      }
    }
  }
});
