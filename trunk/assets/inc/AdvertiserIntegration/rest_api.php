<?php
/**
 * Expose public hooks via /wp-json/revoffers/v1/*
 *
 * All endpoints are protected via OpenSSL SHA256 signature headers. These
 * endpoints can ONLY BE TRIGGERED with requests signed by our servers!
 *
 * These are used to trigger reconciliation events as they happen within
 * Katalys. By being able to receive these events, we hope Advertisers
 * can see deeper into the RevOffers attribution model.
 */
namespace revoffers\rest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

include_once __DIR__ . '/admin_func.php';
include_once __DIR__ . '/curl_wrapper.php';

$routes = [
  /**
   * When the advertiser's server times out or has connectivity issues, this is
   * RevOffers "retry" methodology.
   */
  'send_order_id' => function (WP_REST_Request $request) {
    $orderId = $request->get_param('order_id');
    if (!$orderId) {
      return new WP_Error('missing_order_id', 'missing order id', ['status' => 400]);
    }
    try {
      $e = null;
      $request = \revoffers\recordOrderId($orderId, 'restapi_conv', $e);
      if ($e instanceof \Exception) throw $e;

      if (!$request) {
        return new WP_REST_Response(['success' => false, 'error' => "order_id $orderId is not found"], 404);
      }

      \revoffers\http\getDefault()->waitFor($request);
      $info = $request->info;
      $success = in_array($info['http_code'], [200,204]);
      return new WP_REST_Response(['success' => $success, 'info' => $info]);

    } catch (\Exception $e) {
      // By surfacing a stack-trace and message, we can inspect why the plugin is failing
      return new WP_REST_Response(['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
    }
  },

  /**
   * Katalys AD sometimes needs to be able to exclude/ignore existing customers or orders.
   * This endpoint enables us to automate those requests.
   */
  'send_order_dates' => function (WP_REST_Request $request) {
    $dateFrom = strtotime($request->get_param('from'));
    $dateTo = strtotime($request->get_param('to'));
    $timeout = ((int) $request->get_param('timeout')) ?: 55;
    $opts = [
      'offset' => (int) $request->get_param('offset'),
      'objects' => (boolean) $request->get_param('object_mode'),
    ];
    $minTime = strtotime('-1 year');
    if ($dateFrom < $minTime || $dateTo < $minTime || $dateFrom > $dateTo) {
      return new WP_Error('bad_range', 'invalid time range', ['status' => 400]);
    }

    $done = 0;
    $startTime = microtime(true);
    $breakFlag = false;
    $iterator = \revoffers\admin\iterateOrdersByDate([$dateFrom, $dateTo], $breakFlag, $opts);
    $map = [];

    foreach ($iterator as $orderOrId) {
      $orderId = $opts['objects'] ? $orderOrId->get_order_number() : $orderOrId;
      $map[$orderId] = '?';
      $request = \revoffers\recordOrderId($orderOrId, 'restapi_conv');
      $request->callback = function() use ($request, $orderId, &$map, &$done) {
        $done++;
        $map[$orderId] = in_array($request->info['http_code'], [200,204]);
      };
    }

    $rollingCurlInstance = \revoffers\http\getDefault();
    while ($rollingCurlInstance->tick()) {
      if ($startTime + $timeout < microtime(true)) {
        $breakFlag = true;
        break;
      }
    }

    return new WP_REST_Response([
      'success' => true,
      'sent' => $done,
      'timeout' => $breakFlag,
      'map' => $map,
    ]);
  },

  /**
   * When Katalys Attribution Engine converts an order, this endpoint will be used
   * to add a note to the order, making reconciliation easier.
   */
  'update_order_status' => function (WP_REST_Request $request) {
    $orderId = $request->get_param('order_id');
    $isConverted = $request->get_param('conversion_status');
    $conversionMessage = $request->get_param('conversion_message');
    if (!$orderId || !$isConverted) {
      return new WP_Error('missing_param', 'missing order_id or conversion_status', ['status' => 400]);
    }
    $order = wc_get_order($orderId);
    if (!$order) {
      return new WP_Error('missing_order', 'bad order_id', ['status' => 404]);
    }
    if (!$conversionMessage) {
      $conversionMessage = "RevOffers status change: $isConverted";
    }
    $success = $order->add_order_note($conversionMessage);
    return new WP_REST_Response(['success' => $success]);
  },

  /**
   * Katalys provides product catalogs to affiliates WHEN AUTHORIZED. This endpoint
   * enables us to automate the product export process.
   */
  'send_product_catalog' => function (WP_REST_Request $request) {
    $offset = (int) $request->get_param('offset');
    $timeout = ((int) $request->get_param('timeout')) ?: 55;
    $startTime = microtime(true);
    $iterator = \revoffers\admin\iterateProducts(['offset' => $offset], $breakFlag);
    $fp = fopen('php://temp', 'w+');

    foreach ($iterator as $product) {
      fwrite($fp, json_encode($product, JSON_UNESCAPED_SLASHES));
      fwrite($fp, "\n");

      if ($startTime + $timeout < microtime(true)) {
        $breakFlag = true;
      }
    }
    if ($breakFlag) {
      fwrite($fp, "TIMED OUT totalRecords=" . $iterator->getReturn() . "\n");
    }

    // prep temp file for upload
    $fileSize = ftell($fp);
    rewind($fp);

    if ($request->get_param('send')) {
      global $wp_version;
      global $woocommerce;
      $wooVersion = $woocommerce ? ($woocommerce->version ?: '<active_but_unknown>') : '<inactive>';
      $wpVersion = $wp_version ?: '<unknown>';
      $pluginVersion = \KatalysMerchantPlugin\OOMP_VER_NUM;

      $ch = curl_init('https://db.revoffers.com/v2/_tr');
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_SSL_VERIFYPEER => false,// safe-guard against bad SSL
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_INFILE => $fp,
        CURLOPT_INFILESIZE => $fileSize,
        CURLOPT_USERAGENT => "WordPress/$wpVersion WooCommerce/$wooVersion (RevOffers PHP plugin $pluginVersion)",
      ]);
      curl_exec($ch);
      $info = curl_getinfo($ch);
      curl_close($ch);

      return new WP_REST_Response(['success' => true, 'timeout' => $breakFlag, 'sent' => $info]);

    } else {
      header('Content-Type: application/json');
      header('Content-Length: ' . $fileSize);
      stream_copy_to_stream($fp, fopen('php://output', 'w'));
      die();
    }
  },
];

foreach ($routes as $r => $f) {
  register_rest_route("revoffers/v1", "/$r", [
    "methods" => "POST",
    "permission_callback" => "__return_true",
    "callback" => wrapHandler($f),
  ]);
}

/**
 * Verify package came from RevOffers servers.
 *
 * @param callable $handler
 * @return \Closure
 */
function wrapHandler($handler)
{
  return function (WP_REST_Request $request) use ($handler) {
    // Enforce the request-package needing a time that is close-enough to the local server time
    // If a single request gets hijacked, it will only work for an hour
    try {
      $d = new \DateTime($request->get_param('x-time'));
      if ($d->getTimestamp() < time() - 60*60) {
        throw new \Exception();
      }
    } catch (\Exception $e) {
      return new WP_Error('bad_time', 'invalid verification time', ['status' => 400]);
    }

    // Enforce a verification hash on the request itself
    $content = file_get_contents("php://input");
    $sig = $request->get_header('x-signature');
    if (!$sig) {
      return new WP_Error('missing_verification', 'no source verification provided', ['status' => 400]);
    }

    if (function_exists('openssl_verify')) {
      // MORE SECURE!
      $sig = base64_decode($sig);
      $key = file_get_contents(__DIR__ . "/rest_api.pubkey");
      $success = openssl_verify($content, $sig, $key, OPENSSL_ALGO_SHA256);

      /* Notes on encryption method:
      // On the server, create a private key
      // $ openssl req -nodes -newkey rsa:4096 -keyout source.key -out source.crt -x509
      // $ openssl rsa -in source.key -pubout -out source.pub
      $key = file_get_contents('source.key');
      // Use private key to create the signature
      var_dump(openssl_sign('foobar', $sig, $key, OPENSSL_ALGO_SHA256));
      var_dump($header = base64_encode($sig));
      // use the base64 version in the header...

      // ... on the client, read the header
      $sig = base64_decode($header);
      $pub = file_get_contents('source.pub');
      // Use the public key to verify the signature
      var_dump(openssl_verify('foobar', $sig, $pub, OPENSSL_ALGO_SHA256));
       */

    } elseif (function_exists('hash_hmac')) {
      // LESS SECURE!
      trigger_error('We recommend enabling the openssl_* functions in the php7-openssl package', E_USER_NOTICE);
      $hash = $request->get_header('x-hmac');
      // @-syntax forces UTC timezone
      $secret = 'revoffers' . (new \DateTime("@" . time()))->format('Y-m-d');
      $computedHmac = hash_hmac('sha256', $content, $secret);
      $success = hash_equals($computedHmac, $hash);

    } else {
      return new WP_Error('bad_php_config', 'Must have OpenSSL or hash/hmac functions enabled', ['status' => 500]);
    }

    if (!$success) {
      return new WP_Error('bad_verification', 'invalid verification provided', ['status' => 403]);
    }

    return $handler($request);
  };
}
