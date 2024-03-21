<?php
/*
Plugin Name: RevOffers Advertiser Integration
Description: Integrate RevOffers tracking snippet to reward influencers driving customers to your site.
*/

namespace revoffers_embed;

use WC_DateTime;
use WC_Order;
use WP_Error;
const TRACK_COOKIE_NAME = 'revoffers_affil';
const META_KEY = '_revoffers_visitor_lookup';
const DOMAIN = 'db.revoffers.com';
const SQL_TABLE_NAME = 'plugin_revoffers_queue';
const CRON_HOOK = 'revoffers_cron_trigger';

if (defined('ABSPATH')) {
  init();
}

function init()
{
  $ns = __NAMESPACE__ . "\\";

  // Called when plugin is first activated
  register_activation_hook(__FILE__, "{$ns}init_plugin");
  register_deactivation_hook(__FILE__, "{$ns}deinit");

  // Register REST endpoints for reporting integration
  add_action('rest_api_init', "{$ns}init_rest");

  // Add RevOffersJs to the bottom of each page
  add_action('wp_footer', "{$ns}printJs");

  // Store VID info within order
  add_action('woocommerce_checkout_create_order', "{$ns}onOrderCreated");
  add_filter('woocommerce_rest_pre_insert_shop_order_object', "{$ns}onOrderCreated");

  // Report Order details
  // @see https://docs.woocommerce.com/wc-apidocs/hook-docs.html
  // @see https://squelchdesign.com/web-design-newbury/woocommerce-detecting-order-complete-on-order-completion/
  add_action('woocommerce_order_status_processing', "{$ns}onOrderProcessing");
  // Keep this around for legacy/dummy-check
  add_action('woocommerce_order_status_completed', "{$ns}onOrderCompleted");
  // Expect status-change to be the "master" event
  add_action('woocommerce_order_status_changed', "{$ns}onOrderChanged", 10, 3);
//    add_action('woocommerce_order_status_refunded', "{$ns}onOrderChanged");
//    add_action('woocommerce_order_status_canceled', "{$ns}onOrderChanged");
//    add_action('woocommerce_order_status_failed', "{$ns}onOrderChanged");
//    add_action('woocommerce_order_status_pending', "{$ns}onOrderChanged");

  // Associate conversion via thank-you hit
  add_action('woocommerce_thankyou', "{$ns}onThankYou");

  // Custom hook for the onCron handler
  add_action(CRON_HOOK, "{$ns}onCron");

  // Setup admin page
  if (is_admin()) {
    include_once __DIR__ . '/admin_func.php';
  }
}

function init_rest()
{
  include_once __DIR__ . '/rest_api.php';
}

function init_plugin()
{
  //update_option('revoffers_company_key', '');
  if (!function_exists('curl_init')) {
    throw new \RuntimeException("PHP cURL extension must be installed to use RevOffers");
  }
  if (!function_exists('json_encode')) {
    throw new \RuntimeException("PHP JSON extension must be installed to use RevOffers");
  }
  if (class_exists('WC_Order') && !function_exists('wc_get_order')) { // wc_get_order() was added in v3
    throw new \RuntimeException("Only WooCommerce 3 (or higher) is compatible with RevOffers integration");
  }

  init_db();

  sendNotification('install');

  // needed in case of upgrade where is_admin() might not have been called
  include_once __DIR__ . '/admin_func.php';
}

function init_db()
{
  // Setup the database
  $expectedVersion = '1';
  $curVersion = get_option('revoffers_db_version');
  if ($curVersion != $expectedVersion) {
    global $wpdb;

    $tableName = $wpdb->prefix . SQL_TABLE_NAME;
    $sql = "CREATE TABLE IF NOT EXISTS `$tableName` (
          _id bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY,
		  order_id varchar(150) NOT NULL,
		  event varchar(50),
		  added timestamp DEFAULT CURRENT_TIMESTAMP
	    );";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('revoffers_db_version', $expectedVersion);
  }
}

function deinit()
{
  sendNotification('uninstall');

  // Remove any still-running crons
  $timestamp = wp_next_scheduled(CRON_HOOK);
  wp_unschedule_event($timestamp, CRON_HOOK);

  // Remove tables created by this plugin
  global $wpdb;
  $tableName = $wpdb->prefix . SQL_TABLE_NAME;
  $wpdb->query("DROP TABLE IF EXISTS `$tableName`");
  delete_option('revoffers_db_version');
}

/**
 * Includes the RevOffers primary tracking script
 * and order details if was not recorded offline.
 */
function printJs()
{
  $siteId = getSiteId();
  if (hasCustomSiteId()) {
    // Print params object used by _track.js
    echo "\n<script type=\"text/javascript\">\n";
    echo "_revoffers_track = window._revoffers_track||{};\n";
    echo "_revoffers_track.site_id = ", json_encode($siteId), ";\n";
    echo "</script>";
  }

  $src = "https://" . DOMAIN . "/js/$siteId.js";
  echo "\n<script type=\"text/javascript\" src=\"$src\" async></script>\n";
}

/**
 * @param WC_Order $order
 */
function onOrderCreated($order/*, $data*/)
{
  $useCookie = !empty($_COOKIE[TRACK_COOKIE_NAME]);

  if ($useCookie && is_admin() && !wp_doing_ajax()) {
    $useCookie = false;
  }

  if ($useCookie) {
    $post_type_object = get_post_type_object('shop_order');
    if ($post_type_object) { // ensure Woocommerce is installed
      $permission = current_user_can($post_type_object->cap->edit_others_posts);
      if ($permission) {
        // is admin -- ignore IP address
        $useCookie = false;
      }
    }
  }

  if ($useCookie) {
    // Store end-user information as a meta key so we can tie click/user-session to order
    $data = null;
    $raw = $_COOKIE[TRACK_COOKIE_NAME];
    if (strpos($raw, '{') === 0) $data = json_decode($raw, true);
    if (!$data) parse_str($raw, $data);

    if (!$data) {
      $data = [];
      trigger_error(__METHOD__ . ": Unable to parse revoffers cookie: $raw", E_USER_NOTICE);
    }
    $data['client_ip'] = getClientIp();
    $data['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    $data = http_build_query($data, null, '&');

    $order->add_meta_data(META_KEY, $data, true);
  }

  // $order->save(); << will be executed after this hook

  return $order; // be compatible with add_filter()
}

/**
 * @param int $order_id
 * @param string $oldStatus
 * @param string $newStatus
 */
function onOrderChanged($order_id, $oldStatus = null, $newStatus = null)
{
  scheduleOrRun($order_id, $newStatus);
}

/**
 * @param int $order_id
 */
function onOrderProcessing($order_id)
{
  scheduleOrRun($order_id);
}

/**
 * @param int $order_id
 */
function onOrderCompleted($order_id)
{
  // Order should have already been recorded once
  // This just updates the order-status into "archived/completed" state
  scheduleOrRun($order_id);
}

/**
 * @param int $order_id
 */
function onThankYou($order_id)
{
  $params = getOrderSafe($order_id, false);
  $value = isset($params['subtotal_amount']) ? $params['subtotal_amount']
    : (isset($params['sale_amount']) ? $params['sale_amount'] : null);
  $orderTime = $params['order_time'];
  if (substr($orderTime, 0, 1) === "@") {
    $orderTime = +substr($orderTime, 1);
  } else {
    $orderTime = strtotime($orderTime);
  }

  echo "<script type=\"text/javascript\">";
  echo "\n_revoffers_track = window._revoffers_track||{};";
  echo "\n_revoffers_track.email_address = " . json_encode($params['email_address']) . ";";
  // report the order for 5 days
  if (time() - (60 * 60 * 24 * 5) < $orderTime) {
    echo "\n_revoffers_track.action = \"thank_you\";";
    echo "\n_revoffers_track.order_id = " . json_encode($order_id) . ";";
    echo "\n_revoffers_track.order_time = " . json_encode('@' . $orderTime) . ";";
    echo "\n_revoffers_track.order_status = " . json_encode($params['order_status']) . ";";
    echo $value === null ? ""
      // will be used in v3 and revads.js
      : "\n_revoffers_track.order_value = " . json_encode($value) . ";";
  } else {
    echo "\n_revoffers_track.action = \"order_status\";";
  }
  echo "\n</script>\n";
}

/**
 * Called during a cron-execution.
 * Use wp_schedule_event() or wp_schedule_single_event() to schedule execution.
 *
 * @param string $order_id Optional, if using wp_schedule_single_event(time(), funcName, orderId)
 */
function onCron($order_id = null)
{
  if ($order_id) {
    // might be called via wp_schedule_single_event()
    recordOrderId($order_id);
  }

  init_db();

  $maxPerCron = 50;
  $numInFlight = 0;

  foreach (iterateWaitingOrders($maxPerCron) as $result) {
    $id = $result->_id;
    $res = recordOrderId($result->order_id, $result->event);

    $onComplete = function ($output, $info) use ($id) {
      if (!in_array($info['http_code'], [200, 204])) {
        // leave in the database for next time
        return;
      }

      global $wpdb;
      $tableName = $wpdb->prefix . SQL_TABLE_NAME;
      $res = $wpdb->query($wpdb->prepare("DELETE FROM `$tableName` WHERE _id=%s", [$id]));
      if ($res === false) {
        trigger_error(__METHOD__ . ": Could not delete _id=$id", E_USER_WARNING);
        // nothing more to do
      }
    };

    if (!$res) {
      // order is invalid or irrelevant, remove from db immediately
      $onComplete(null, ['http_code' => 204]);
    } else {
      $res->callback = $onComplete;
      $numInFlight++;
    }
  }

  if ($numInFlight) {
    // wait for all requests to finish
    $rollingCurlInstance = http\getDefault();
    $rollingCurlInstance->finish();
  }
}

/**
 * Adds an "every_minute" schedule.
 * Called via add_filter('cron_schedules', "{$ns}onCronSchedules");
 *
 * @param array $schedules
 * @return array
 */
function onCronSchedules($schedules)
{
  if (isset($schedules['every_minute'])) {
    if ($schedules['every_minute']['interval'] == 60) {
      // trigger matches, nothing to do
      return $schedules;
    }
    trigger_error(__METHOD__ . ": overriding invalid 'every_minute' interval", E_USER_WARNING);
  }

  // $schedules stores all recurrence schedules within WordPress
  $schedules['every_minute'] = [
    'interval'    => 60,
    'display'    => 'Once Every Minute',
  ];

  return $schedules;
}

/**
 * Schedule an order for reporting, or report in-line if cannot perform scheduling.
 *
 * @param string $order_id
 * @param string $event
 */
function scheduleOrRun($order_id, $event = null)
{
  $shouldUseCron = shouldUseCron();

  if ($shouldUseCron) {
    init_db();

    global $wpdb;
    $res = $wpdb->insert($wpdb->prefix . SQL_TABLE_NAME, [
      'order_id' => $order_id,
      'event' => $event,
    ]);

    if (!$res) {
      trigger_error(__METHOD__ . ": could not access MySQL table! Must fallback to in-line processing", E_USER_WARNING);
      $shouldUseCron = false;
    }
  }

  if ($shouldUseCron) {
    // Required when scheduling jobs in the future
    add_filter('cron_schedules', __NAMESPACE__ . "\\onCronSchedules");

    if (wp_next_scheduled(CRON_HOOK)) {
      return; // success!
    }

    // the "every_minute" schedule is defined in onCronSchedules()
    wp_schedule_event(time(), 'every_minute', CRON_HOOK);
    $res = wp_next_scheduled(CRON_HOOK);
    if (!$res) {
      trigger_error(__METHOD__ . ": wp_schedule_event() failed, falling back to less-efficient schedule_single_event", E_USER_WARNING);
      wp_schedule_single_event(60, CRON_HOOK);
      $res = wp_next_scheduled(CRON_HOOK);
    }
    if ($res) {
      return; // success!
    }

    trigger_error(__METHOD__ . ": could not use cron! Something might be wrong with your WP install", E_USER_WARNING);
  }

  // fallback to running in-line
  recordOrderId($order_id, $event);
}

/**
 * Check if cron seems like it should be used.
 *
 * @param bool $useOption Whether to use get_option() or just inspect the system
 * @return bool
 */
function shouldUseCron($useOption = true)
{
  if (defined('DOING_CRON') && DOING_CRON) {
    return false; // never re-execute cron from a cron job
  } elseif ($useOption && strlen($cronSetting = get_option('revoffers_use_cron'))) {
    return !!$cronSetting; // use setting if explicitly defined
  } elseif (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
    return false; // in "auto" mode, skip cron if it's disabled
  } else {
    return false; // default value
  }
}

/**
 * Iterate over waiting order-reporting tasks up until $maxPerCron entries.
 *
 * @param int $maxResults
 * @return \Generator
 */
function iterateWaitingOrders($maxResults = 50)
{
  global $wpdb;
  $tableName = $wpdb->prefix . SQL_TABLE_NAME;
  $offset = 0;
  $limit = 20;

  get_more:

  // Sort by most-recent first
  // This puts re-tries at the end of the queue
  $sql = "SELECT _id, order_id, event
        FROM `$tableName`
        ORDER BY _id DESC
        LIMIT $offset,$limit";

  $results = $wpdb->get_results($sql);
  foreach ($results as $result) {
    yield $result;
  }

  $numResults = count($results);
  $offset += $numResults;
  if ($numResults == $limit && $offset < $maxResults) {
    goto get_more;
  }
}

/**
 * POST data via cURL to collector endpoint.
 *
 * @param array $params
 * @return http\RollingCurlRequest
 */
function curl($params)
{
  static $rollingCurlInstance;
  if (!$params) return null;

  // Determine server state
  $isHttps = !empty($_SERVER['HTTPS']) || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0);
  $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST']
    : parse_url(get_site_url(), PHP_URL_HOST);
  $uri = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/<offline_processing>';

  // Populate params with session info
  $params['request_uri'] = ($isHttps ? 'https' : 'http') . "://" . $host . $uri;
  $params['site_id'] = getSiteId();
  if (!empty($_SERVER['HTTP_REFERER'])) {
    $params['referrer'] = $_SERVER['HTTP_REFERER'];
  }
  $params['document_title'] = '<WooCommerce Offline Tracking>';

  if (!$rollingCurlInstance) {
    include_once __DIR__ . '/curl_wrapper.php';
    $rollingCurlInstance = http\getDefault();
  }

  $request = new http\RollingCurlRequest("https://" . DOMAIN . "/v2/_tr", 'POST', http_build_query($params, null, '&'));
  $rollingCurlInstance->add($request);
  $rollingCurlInstance->start();

  return $request;
}

/**
 * Notify lifecycle-event handler that a new site has been setup.
 *
 * @param string $event
 * @return http\RollingCurlRequest
 */
function sendNotification($event)
{
  // Determine server state
  $isHttps = !empty($_SERVER['HTTPS']) || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0);
  $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST']
    : parse_url(get_site_url(), PHP_URL_HOST);
  $uri = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/<offline_processing>';

  // Populate params with reporting data
  $params = [];
  $params['integration'] = 'WooCommerce';
  $params['user'] = getClientIp();
  $params['domain'] = $host;
  $params['url'] = ($isHttps ? 'https' : 'http') . "://" . $host . $uri;
  $params['site_id'] = getSiteId();
  $params['plugin_version'] = \KatalysMerchantPlugin\OOMP_VER_NUM;

  $user = wp_get_current_user();
  if ($user) {
    $params['user_email'] = $user->user_email;
    $params['display_name'] = $user->display_name;
  }

  include_once __DIR__ . '/curl_wrapper.php';
  $request = new http\RollingCurlRequest("https://" . DOMAIN . "/v3/event/$event", 'POST', http_build_query($params, null, '&'));
  $rollingCurlInstance = http\getDefault();
  $rollingCurlInstance->add($request);
  $rollingCurlInstance->start();

  return $request;
}

/**
 * Return the site_id value.
 *
 * @param bool $useSetting
 * @return string|null
 */
function getSiteId($useSetting = true)
{
  if ($useSetting) {
    $setting = get_option('revoffers_site_id');
    if ($setting) return $setting;
  }

  $site_host = parse_url(get_site_url(), PHP_URL_HOST);
  if ($site_host) return $site_host;

  if (!empty($_SERVER['HTTP_HOST'])) return $_SERVER['HTTP_HOST'];
  return null;
}

/**
 * Return false if HTTP_HOST value is the same value as the siteId.
 *
 * @return bool
 */
function hasCustomSiteId()
{
  if (empty($_SERVER['HTTP_HOST'])) {
    return true; // nothing to compare
  }

  $sanitize = function ($val) {
    $val = trim($val);
    if (strpos($val, "://") !== false) {
      $val = parse_url($val, PHP_URL_HOST) ?: $val;
    }
    $val = preg_replace('#:\d*$#', '', $val);
    // reduce to first-level domain
    $val = preg_replace('#^(?:store|shop|www)\.(\w.*\.\w+)\.?$#i', '$1', $val);
    return $val;
  };

  return $sanitize($_SERVER['HTTP_HOST']) !== $sanitize(getSiteId());
}

/**
 * Get the IP address of the end client, filtering out invalid ranges.
 *
 * @return string|null
 */
function getClientIp()
{
  $ip = null;
  $filter = function ($ip) {
    $ip = trim($ip);
    if (!$ip) return null;
    if (strpos($ip, "127.0.") === 0) return null;
    if (strpos($ip, "192.168.") === 0) return null;
    if (strpos($ip, "169.254.") === 0) return null;
    if (preg_match('#^10\.[0-9]\.#', $ip)) return null;
    if (preg_match('#^172\.(1[6789]|2[0-9]|3[01])\.#', $ip)) return null;
    if (strpos($ip, "fc00:") === 0) return null;
    if (strpos($ip, "fe80:") === 0) return null;
    if (strpos($ip, "::1") === 0) return null;
    return $ip;
  };
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $_ = array_filter(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']), $filter);
    if ($_) {
      $ip = $_[0];
    }
  }
  if (!$ip && !empty($_SERVER['HTTP_CLIENT_IP'])) {
    $ip = $filter($_SERVER['HTTP_CLIENT_IP']);
  }
  if (!$ip && !empty($_SERVER['SERVER_ADDR'])) {
    $ip = $filter($_SERVER['SERVER_ADDR']);
  }
  return $ip;
}

/**
 * Uses cURL to send to RevOffers.
 *
 * @param string $order_id
 * @param string $type
 * @param \Exception $lastError
 * @return http\RollingCurlRequest
 */
function recordOrderId($order_id, $type = null, &$lastError = null)
{
  $params = getOrderSafe($order_id, true, $lastError);
  if (!$params) return null;

  $type = $type ?: 'offline_conv';
  $params['action'] = $type; // override type when coming from WooCommerce
  // Send tracking data
  return curl($params);
}

/**
 * @param int $order_id
 * @param bool $includeMeta
 * @param \Exception $lastError
 * @return array
 */
function getOrderSafe($order_id, $includeMeta = true, &$lastError = null)
{
  $params = null;
  try {
    if (!$order_id) return null;
    if (function_exists('wc_get_order')) {
      // WooCommerce 3.x and up
      $order = wc_get_order($order_id);
    } else {
      // WooCommerce up to 2.x
      $order = new WC_Order($order_id);
    }
    if (!$order || $order instanceof WP_Error) return null;
    $params = [];
    getOrder($order, $params);

    foreach ($params as $k => $v) {
      if ($v === null) unset($params[$k]);
    }

    if ($includeMeta) {
      // Attach custom meta info
      $visitorInfo = $order->get_meta(META_KEY)
        // Apr-2019: backwards-compat key lookup
        ?: $order->get_meta('_revoffers_visitor');
      if ($visitorInfo) {
        $data = null;
        if (strpos($visitorInfo, '{') === 0) $data = json_decode($visitorInfo, true);
        if (!$data) parse_str($visitorInfo, $data);
        if ($data) {
          $data = array_merge($data, $params);
          if ($data) {
            $params = $data;
          }
        }
      }

      if (empty($params['client_ip'])) {
        $clientIp = get_post_meta($order_id, '_customer_ip_address', true);
        if ($clientIp) {
          $params['client_ip'] = $clientIp;
        }
      }
    }

    $params['action'] = 'convert';
    return $params;
  } catch (\Exception $e) {
    $lastError = $e;
    trigger_error(__METHOD__ . ": Getting RevOffers tracking info failed: $e", E_USER_WARNING);
    return $params;
  }
}

/**
 * Fill $params object with info about the order.
 *
 * @param WC_Order $order
 * @param array $params
 */
function getOrder($order, &$params)
{
  $orderAttr = makeSafeExtract($order);

  //
  // General Order Info
  // @see https://stackoverflow.com/questions/39401393/how-to-get-woocommerce-order-details
  //
  $order_id = $orderAttr('id');
  $order_email = $orderAttr('billing_email') ?: $orderAttr('email');
  $params['order_id'] = $order_id;
  $params['email_address'] = $order_email;
  $params['order_key'] = $orderAttr('order_key');
  $params['user_agent'] = $orderAttr('customer_user_agent');
  $params['client_ip'] = $orderAttr('customer_ip_address');

  // SKIP RECORDING PII (name, address, etc)
  // don't need customer info: $params['customer_id'] = $order->get_customer_id();

  //$order_parent_id = $order_data['parent_id'];
  $orderStatus = $orderAttr('status');
  $params['order_status'] = $orderStatus;
  $params['payment_status'] = $orderStatus;

  // Options: Pending, Processing, On-Hold, Completed, Cancelled, Refunded, Failed, Trash
  if ($orderStatus == "failed") {
    $params['order_status'] = "rejected";
  } elseif ($orderStatus == "cancelled") {
    $params['order_status'] = "cancelled";
  } elseif ($orderStatus == "refunded") {
    $params['order_status'] = "refunded";
  } elseif ($orderStatus == "wfocu-pri-order") {
    $params['order_status'] = "";
  }

  //$order_date_modified = $order_data['date_modified']->date('Y-m-d H:i:s');
  $date = $orderAttr('date_created');
  if ($date instanceof WC_DateTime) {
    $date = "@" . $date->getTimestamp();
  }
  if ($date) {
    $params['order_time'] = $date;
  }

  // Money
  $params['currency'] = $orderAttr('currency');
  //$order_discount_total = $order_data['discount_total'];
  //$order_discount_tax = $order_data['discount_tax'];
  $params['shipping_amount'] = $orderAttr('shipping_total');
  //$order_shipping_tax = $order_data['shipping_tax'];
  $params['sale_amount'] = $orderAttr('total');
  $params['sale_amount_with_currency'] = $orderAttr('total') . ' ' . $orderAttr('currency');
  $params['tax_amount'] = $orderAttr('total_tax');
  $params['subtotal_amount'] = $orderAttr('subtotal');

  // Address (basic geo ONLY, for risk assessment/fraud detection)
  $params['billing_city'] = $orderAttr('billing.city');
  $params['billing_state'] = $orderAttr('billing.state');
  $params['billing_postal'] = $orderAttr('billing.postcode');
  $params['shipping_city'] = $orderAttr('shipping.city');
  $params['shipping_state'] = $orderAttr('shipping.state');
  $params['shipping_postal'] = $orderAttr('shipping.postcode');

  //
  // Product Line Items
  //
  $i = -1;
  foreach ($order->get_items() as $itemId => $item) :
    /** @var WC_Product $product */
    $product = null;
    if (is_callable([$item, 'get_data'])) {
      $product = $item->get_product();
      $item = $item->get_data();
    }
    //if (!($item instanceof WC_Order_Item_Product)) continue;
    $i++;

    $params["line_item_{$i}_title"] = attr($item, 'name');
    $params["line_item_{$i}_var"] = attr($item, 'variation_id');
    $params["line_item_{$i}_qty"] = attr($item, 'quantity');
    $params["line_item_{$i}_sku"] = attr($product, 'sku');
    $params["line_item_{$i}_price"] = attr($product, 'price');

    $categories = function_exists("wc_get_product_category_list")
      ? wc_get_product_category_list(attr($product, 'id'), ",")
      : attr($product, 'categories');
    if ($categories) {
      $categories = trim(strip_tags($categories));
      $params["line_item_{$i}_categories"] = $categories;
    }

    /*
        if ($categories) {
            $catArr = [];
            foreach ($categories as $category):
                $var = attr($category, 'name');
                if ($var) {
                    $catArr[] = $var;
                }
            endforeach;
            $params["line_item_{$i}_categories"] = implode(",", $catArr);
        }
        elseif (function_exists("wc_get_product_category_list")) {
            $categories = wc_get_product_category_list(attr($product, 'id'), ","); // RETURNS A STRING
            $params["line_item_{$i}_categories"] = $categories;
        }
        */

  endforeach;

  //
  // Fee Line Items
  //
  if (is_callable([$order, "get_fees"])) {
    foreach ($order->get_fees() as $fee) :
      $i++;

      $name = attr($fee, 'name');
      $total = attr($fee, 'total');
      $params["line_item_{$i}_title"] = "Fee: $name";
      $params["line_item_{$i}_price"] = $total;
      $params["line_item_{$i}_categories"] = "WooCommerce_Fee";

      // Compatible with RouteApp, https://wordpress.org/plugins/routeapp/
      if (
        // Routeapp_Public::$ROUTE_LABEL
        // https://plugins.trac.wordpress.org/browser/routeapp/trunk/public/class-routeapp-public.php
        $name == 'Route Shipping Protection'
      ) {
        $params["line_item_{$i}_sku"] = "FEE_ROUTE_INSURANCE";
        // add to shipping-cost if >$0
        if ($total > 0) {
          $params["shipping_amount"] += $total;
        }
      }

    endforeach;
  }

  //
  // Coupon Line Items
  //
  // @see https://stackoverflow.com/questions/44977174/get-coupon-discount-type-and-amount-in-woocommerce-orders
  // @see https://docs.woocommerce.com/wc-apidocs/source-class-WC_Abstract_Order.html#680
  $i = -1;
  foreach ($order->get_items('coupon') as $item) :
    $item = makeSafeExtract($item);
    //if (!($item instanceof WC_Order_Item_Coupon)) continue;
    $i++;

    // ?: if (!$item->is_type('cash_back_fixed') && !$item->is_type('cash_back_percentage')) continue;

    $params["discount_{$i}_code"] = $item('code');
    $params["discount_{$i}_amt"] = $item('discount');
  endforeach;

  //
  // Meta Data Line Items
  // Would massively increase data collection, but potentially provides more datapoints such as WholeSale Order delineation
  // Possibly interesting, keeping code around for now
  /*
  if (is_callable([$order, "get_meta_data"])) {
      foreach ($order->get_meta_data() as $meta):
          $i++;

          $key = attr($meta, 'key');
          $value = attr($meta, 'value');
          $params["order_meta_{$i}_key"] = $key;
          $params["order_meta_{$i}_value"] = $value;

      endforeach;
  }
  */

  // Lastly... (in case apply_filters() causes problems)
  // WooCommerce offers custom filter in API docs
  $orderIdFiltered = apply_filters('woocommerce_order_number', $order_id, $order);
  if ($orderIdFiltered && $order_id !== $orderIdFiltered) {
    $params['order_alias'] = $orderIdFiltered;
  }

  //
  // Previous Order Information (for customer exclusion if required)
  //
  //    if (defined('DOING_CRON') && DOING_CRON) {
  //        // This query is far-superior to the wc_get_orders() function
  //        // but it's still slow against massive tables.
  //        // Only execute this query if we're executing inside a cron job.
  //        global $wpdb;
  //        $sql = "
  //            SELECT count(p.ID) as `count`, min(p.post_date) as first_date, max(p.post_date) as recent_date
  //            FROM {$wpdb->prefix}posts as p FORCE INDEX(`type_status_date`)
  //            INNER JOIN {$wpdb->prefix}postmeta as m ON p.ID = m.post_id AND m.meta_key = '_billing_email'
  //            WHERE p.ID <> %d
  //              AND p.post_type = 'shop_order'
  //              AND p.post_status = 'wc-completed'
  //              AND p.post_date BETWEEN %s AND %s
  //              AND m.meta_value = %s
  //        ";
  //        $orderTime = strtotime($date) ?: time();
  //        $sqlVals = [
  //            $order_id,
  //            date('Y-m-d', $orderTime - 60*60*24*90/* 90 days ago */),
  //            date('Y-m-d', $orderTime),
  //            $order_email,
  //        ];
  //
  //        $query = $wpdb->prepare($sql, $sqlVals);
  //        $row = $wpdb->get_row($query);
  //
  //        if ($row && $row->count) {
  //            $params['order_count'] = 1 + $row->count;
  //            $params['last_order_date'] = explode(' ', $row->recent_date)[0];
  //            $params['first_order_date'] = explode(' ', $row->first_date)[0];
  //        }
  //    }
}

/**
 * Create callable that can extract data from an order.
 *
 * @param WC_Order $obj
 * @return \Closure
 */
function makeSafeExtract($obj)
{
  $data = is_callable([$obj, 'get_data']) ? $obj->get_data() : [];
  return function ($path) use ($obj, $data) {
    $val = attr($obj, $path);
    if ($val === null) $val = attr($data, $path);
    return $val;
  };
}

/**
 * Safely retrieve an attribute from the src collection.
 *
 * @param mixed $src
 * @param string $path
 * @return string|null
 */
function attr($src, $path)
{
  if (class_exists('Throwable')) {
    // PHP 7
    try {
      return _attr($src, $path);
    } catch (\Throwable $e) {
      trigger_error("Failure getting attribute '$src': " . $e->getMessage(), E_USER_NOTICE);
      return null;
    }
  } else {
    // PHP 5.x
    try {
      return _attr($src, $path);
    } catch (\Exception $e) {
      trigger_error("Failure getting attribute '$src': " . $e->getMessage(), E_USER_NOTICE);
      return null;
    }
  }
}

function _attr($src, $path)
{
  foreach (explode('.', $path) as $part) {
    if (is_string($part) && !strlen($part)) {
      continue;
    } elseif (!$src) {
      $src = null;
    } elseif (is_array($src)) {
      $src = isset($src[$part]) ? $src[$part] : null;
    } elseif (is_callable([$src, 'get_' . $part])) {
      $src = $src->{'get_' . $part}();
    } elseif ($src instanceof \ArrayAccess) { // Deprecated in v4.4.0
      $src = $src[$part];
      // DEPRECATED as of WooCommerce v3.0
      //        } elseif (is_callable([$val, '__get'])) {
      //            $val = $val->$part;
    } elseif (is_object($src)) {
      $src = isset($src->$part) ? $src->$part : null;
    } else {
      $src = null;
    }
  }
  return $src;
}
