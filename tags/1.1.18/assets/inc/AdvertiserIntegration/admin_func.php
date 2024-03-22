<?php
namespace revoffers_embed\admin;

if (defined('ABSPATH')) {
  init_admin();
}

function init_admin()
{
  add_action('admin_init', function () {
    // Register site_id
    register_setting('revoffers', 'revoffers_site_id', [
      'type' => 'string',
      'description' => 'Site ID used internally to Katalys for audience correlation',
      'sanitize_callback' => function ($input) {
        foreach ($input as $k => $v) {
          if ($v !== null) {
            if (preg_match('#\s#', $v)) $v = null;
            else $v = trim(strtolower(strip_tags(stripslashes($v))));
            $input[$k] = $v;
          }
        }
        return $input;
      },
    ]);

    // Register use_cron
    register_setting('revoffers', 'revoffers_use_cron', [
      'type' => 'boolean',
      'description' => 'Whether to force Katalys plugin to use the cron subsystem even if WP_DISABLE_CRON is activated',
    ]);
  });

  // API endpoint for saving/exporting order information
  add_action('wp_ajax_revoffers_orders_debug', __NAMESPACE__ . '\\api_get_orders');

  // Add link to card on Plugins page
  add_filter('plugin_action_links_' . pluginName() . '/revoffers-advertiser-integration.php', function ($links) {
    // Add links to the plugin card on the Plugins List admin page
    $settingsPage = 'options-general.php?page=' . pluginName();
    $settingsUrl = esc_url(get_admin_url(null, $settingsPage));
    array_unshift($links, '<a href="' . $settingsUrl . '">Settings</a>');
    return $links;
  });
}

function pluginName()
{
  return explode(DIRECTORY_SEPARATOR, plugin_basename(__FILE__), 2)[0];
}

function admin_options_page()
{
  include __DIR__ . '/admin_page.php';
}

/**
 * Called with /wp-admin/admin-ajax.php?action=revoffers_orders_debug
 * Optional params:
 * - timeout = number of seconds to allow iteration to continue
 * - asText = whether to print directly to the screen to attempt partial results
 */
function api_get_orders()
{
  $fp = $tempFile = null;
  try {
    $timeout = isset($_GET['timeout']) ? (int)$_GET['timeout']
      : (((int)ini_get('max_execution_time')) ?: 55);
    $isGz = isset($_GET['asText']) ? !$_GET['asText'] : true;
    $startDate = isset($_GET['startDate']) ? strtotime($_GET['startDate']) : 0;
    $endDate = isset($_GET['endDate']) ? strtotime($_GET['endDate']) : 0;

    if (!$startDate) $startDate = strtotime('-1 year');
    if (!$endDate) $endDate = time();

    // Prep file handles
    if ($isGz) {
      $tempFile = tempnam(sys_get_temp_dir(), 'revoffers-export-');
      if ($tempFile) $fp = fopen("compress.zlib://$tempFile", 'w');
      $isGz = !!$fp;
    }
    if (!$fp) {
      $fp = fopen('php://output', 'w');
    }

    header('Content-Type: application/' . ($isGz ? 'gzip' : 'json') . '; charset=utf-8', true);
    header('Content-Disposition: attachment; filename=revoffers_orders_export.json'
      . ($isGz ? '.gz' : null), true);

    $startTime = microtime(true);
    $breakFlag = false;
    $orderIterator = iterateOrdersByDate([$startDate, $endDate], $breakFlag);
    $i = -1;
    foreach (printOrdersToStream($orderIterator, $fp) as $i => $_) {
      if ($startTime + $timeout < microtime(true)) {
        $breakFlag = true;
        $i = $i + 1;// offset starts @ 0
        break;
      }
    }

    $numTotal = $orderIterator->getReturn();
    header("X-Total-Records: $numTotal");
    if ($breakFlag) {
      header('X-Timed-Out: true');
      fwrite($fp, "TIMED OUT, lines=$i total=$numTotal\n");
    }

    fclose($fp);// will write GZ header info
    $fp = null;
    if ($isGz) {
      // If you want Chrome to auto de-compress:
      //header('Content-Encoding: gzip', true);
      readfile($tempFile);// send to STDOUT
    }

  } catch (\Exception $e) {
    @header('Content-Type: text/plain; charset=utf-8', true);
    echo "Failed: $e";
  }

  if ($fp) fclose($fp);
  if ($tempFile && file_exists($tempFile)) unlink($tempFile);

  wp_die();
}

/**
 * Iterate over orders matching $params.
 *
 * @param array $params Passed to wc_get_orders()
 * @param boolean $breakFlag
 * @return \Generator
 */
function iterateOrders(array $params, &$breakFlag = null)
{
  $batchSize = isset($params['limit']) ? (int) $params['limit'] : 200;
  $offset = isset($params['offset']) ? (int) $params['offset'] : 0;
  $getObjects = isset($params['objects']) ? !!$params['objects'] : false;
  $totalRows = null;

  get_more:
  $list = wc_get_orders(array_merge($params, [
    'type' => 'shop_order',
    'status' => ['processing', 'completed'],
    'limit' => $batchSize,
    'offset' => $offset,
    'orderby' => 'date',
    'order' => 'DESC',
    'paginate' => $totalRows === null,
    'return' => $getObjects ? 'objects' : 'ids',
  ]));
  if ($totalRows === null) {
    $totalRows = $list->total === null ? -1 : $list->total;
    $list = $list->orders;
  }

  $offset += $batchSize;
  foreach ($list as $orderOrId) {
    yield $orderOrId;
    if ($breakFlag) {
      return $totalRows;
    }
  }

  if (count($list) >= $batchSize) {
    goto get_more;
  }
  return $totalRows;
}

/**
 * Iterate over orders only filtered by date.
 *
 * @param string|string[] $dates
 * @param boolean $breakFlag
 * @param array $params
 * @return \Generator
 */
function iterateOrdersByDate($dates, &$breakFlag = null, $params = null)
{
  list($startDate, $endDate) = validateDates($dates);
  if ($endDate) {
    if ($startDate === $endDate) {
      $dates = date('Y-m-d', $startDate);
    } else {
      $dates = "$startDate...$endDate";
    }
  } else {
    $dates = ">$startDate";
  }
  $params = $params ?: [];
  $params['date_created'] = $dates;
  return iterateOrders($params, $breakFlag);
}

/**
 * @param string|string[] $dates
 * @return int[]
 */
function validateDates($dates)
{
  $dates = (array) $dates;
  $startDate = is_int($dates[0]) ? (int) $dates[0] : strtotime($dates[0]);
  $endDate = null;
  if ($startDate < 100) {
    throw new \InvalidArgumentException("Invalid start date: {$dates[0]}");
  }
  if (isset($dates[1])) {
    $endDate = is_int($dates[1]) ? (int) $dates[1] : strtotime($dates[1]);
    if ($endDate < 100) {
      throw new \InvalidArgumentException("Invalid end date: {$dates[1]}");
    }
  }
  return [$startDate, $endDate];
}

/**
 * Count orders completed since given date.
 *
 * @param string|string[] $dates
 * @return int
 */
function countOrders($dates)
{
  global $wpdb;
  list($startDate, $endDate) = validateDates($dates);
  if ($endDate) {
    $endDate = "AND p.post_date_gmt <= FROM_UNIXTIME('$endDate')";
  }
  return $wpdb->get_var("
        SELECT count(p.ID) FROM {$wpdb->prefix}posts AS p
        WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed'/*,'wc-processing'*/)
        AND p.post_date_gmt >= FROM_UNIXTIME('$startDate') $endDate
    ");
}

/**
 * Print JSON-formatted order details to stream.
 *
 * @param iterable $iterator
 * @param resource $fp
 * @return \Generator
 */
function printOrdersToStream($iterator, $fp)
{
  $jsonOpt = 0;
  if (defined('JSON_UNESCAPED_SLASHES')) $jsonOpt |= JSON_UNESCAPED_SLASHES;
  if (defined('JSON_UNESCAPED_UNICODE')) $jsonOpt |= JSON_UNESCAPED_UNICODE;
  if (defined('JSON_PARTIAL_OUTPUT_ON_FAILURE')) $jsonOpt |= JSON_PARTIAL_OUTPUT_ON_FAILURE;

  foreach ($iterator as $i => $orderId) {
    yield $i => $orderId;// delegate to caller to print status

    $e = null;
    $params = \revoffers_embed\getOrderSafe($orderId, true, $e);
    if ($e) {
      fwrite($fp, "$e\n");
    }
    if (!$params) continue;

    $str = json_encode($params, $jsonOpt);
    fwrite($fp, $str);
    fwrite($fp, "\n");
  }
}

/**
 * Iterate over products matching $params.
 *
 * @param array $params Passed to wc_get_orders()
 * @param boolean $breakFlag
 * @return \Generator
 */
function iterateProducts(array $params, &$breakFlag = null)
{
  $batchSize = (isset($params['limit']) && $params['limit'] > 1) ? (int) $params['limit'] : 200;
  $offset = (isset($params['offset']) && $params['offset'] > 0) ? (int) $params['offset'] : 0;
  $getObjects = isset($params['objects']) ? !!$params['objects'] : true;
  $totalRows = null;

  get_more:
  $list = wc_get_products([
    'orderby' => 'ID',
    'order' => 'ASC',
    'offset' => $offset,
    'limit' => $batchSize,
    'paginate' => $totalRows === null,
    'return' => $getObjects ? 'objects' : 'ids',
  ]);
  if ($totalRows === null) {
    $totalRows = $list->total === null ? -1 : $list->total;
    $list = $list->products;
  }

  foreach ($list as $productOrId) {
    // Get Product details
    $product = getProduct($productOrId);
    if (!$product) continue;

    // Filter out Variations that don't have a Parent Product that exists
    if ($product['type'] === 'variation') {
      $parent = $product['parent_id'] ? wc_get_product($product['parent_id']) : false;
      if (!$parent) continue;
    }

    yield $product;

    if ($breakFlag) return $totalRows;
  }
  $offset += $batchSize;
  if (count($list) >= $batchSize) {
    goto get_more;
  }

  return $totalRows;
}

/**
 * @param int $product_id
 * @return array|null
 */
function getProduct($product_id)
{
  // @see https://plugins.trac.wordpress.org/browser/woocommerce-exporter/trunk/includes/product.php
//    $product = get_post($product_id);
//    $product->description = $product->post_content;
//    $product->excerpt = $product->post_excerpt;
//    $product->regular_price = get_post_meta($product_id, '_regular_price', true);

  if (is_object($product_id)) {
    $_product = $product_id;
    $product_id = $_product->get_id();
  } else {
    $_product = wc_get_product($product_id);
    if (!$_product) return null;
  }

  $prodAttr = \revoffers_embed\makeSafeExtract($_product);
  $prod = [
    'id' => $product_id,
  ];

  $fields = [
    'name',
    'type',
    'status',
    'description',
    'short_description',
    'sku',
    'parent_id',

    'date_created',
    'date_modified',

    'featured',
    'catalog_visibility',

    'price',
    'regular_price',
    'sale_price',
    'weight',
    'permalink',
    'image',
    //'image_id',
    //'gallery_image_ids',
  ];
  foreach ($fields as $f) {
    $prod[$f] = $prodAttr($f);
  }

  foreach (['price','regular_price'] as $k) {
    if ($prod[$k] && function_exists('wc_format_localized_price')) {
      $prod[$k] = wc_format_localized_price($prod[$k]);
    }
  }
  foreach (['date_created','date_modified'] as $k) {
    if ($prod[$k] instanceof \WC_DateTime) {
      $prod[$k] = $prod[$k] ->date_i18n();
    }
  }

  if ($prod['type'] === 'variation' && $prod['parent_id']) {
    $prod['parent_sku'] = get_post_meta($prod['parent_id'], '_sku', true);
  }
  if ($prod['weight']) {
    $unit = get_option('woocommerce_weight_unit');
    if ($unit) $prod['weight'] .= " $unit";
  }

  $cats = wp_get_object_terms($prod['parent_id'] ?: $prod['id'], 'product_cat');
  if ($cats && !is_wp_error($cats)) {
    foreach ($cats as $cat) {
      $path = [$cat->name];
      $p = $cat;
      for ($i = 0; $i < 2; $i++) {
        if (!$p->parent) {
          break;
        }
        $p = get_term($p->parent, 'product_cat');
        array_unshift($path, $p->name);
      }
      $prod['categories'][] = implode(' >> ', $path);
    }
  }

  $tags = wp_get_object_terms( $product_id, 'product_tag');
  if( $tags  && !is_wp_error( $tags ) ) {
    foreach ($tags as $tag) {
      $prod['tags'][] = $tag->name;
    }
  }

  return $prod;
}
