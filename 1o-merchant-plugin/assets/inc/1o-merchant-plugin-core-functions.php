<?php
namespace KatalysMerchantPlugin;

use DateInterval;
use DateTime;
use ParagonIE\ConstantTime\Base64UrlSafe;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Exception\InvalidKeyException;
use ParagonIE\Paseto\Exception\InvalidPurposeException;
use ParagonIE\Paseto\Exception\PasetoException;
use ParagonIE\Paseto\Keys\SymmetricKey;

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
 * @param string $sharedKey
 * @param string $footer
 * @param string $exp
 * @return string
 * @throws InvalidKeyException
 * @throws InvalidPurposeException
 * @throws PasetoException
 * @throws \Exception
 */
function paseto_create_token($sharedKey, $footer = '', $exp = 'P01D') {
  $sharedKey = new SymmetricKey($sharedKey);
  return Builder::getLocal($sharedKey)
      ->setIssuedAt()
      ->setNotBefore()
      ->setExpiration((new DateTime())->add(new DateInterval($exp)))
      ->setFooter($footer);
}

/**
 * Helper function to validate exp date of token
 *
 * @param string $rawDecryptedToken : raw string from decrypted token.
 * @return bool true (default)        : true if expired or not valid signature.
 *                                    : false if not expired and valid signature.
 */
function paseto_is_expired($rawDecryptedToken)
{
  if (is_object($rawDecryptedToken) && isset($rawDecryptedToken->exp)) {
    $checkTime = new DateTime('NOW');
    $checkTime = $checkTime->format(DateTime::ATOM);
    $tokenExp = new DateTime($rawDecryptedToken->exp);
    $tokenExp = $tokenExp->format(DateTime::ATOM);
    if ($checkTime > $tokenExp) {
      return true; // expired - do nothing else!!
    } else {
      return false; // trust token - not expired and has valid signature.
    }
  } else {
    return true;
  }
}

/**
 * Helper Functions for Processing Token Footer
 *
 * @param string $token : Bearer token from authorization header (PASETO)
 * @return bool false     : false on empty, failure or wrong size
 * @return string|false $token  : token for processing
 */
function paseto_decode_footer($token)
{
  $pos = stripos($token, "Bearer ");
  if ($pos !== false) {
    $token = substr($token, $pos + 7);
  }
  $pieces = explode('.', $token);
  return (count($pieces) === 4 && $pieces[3])
      ? Base64UrlSafe::decode($pieces[3])
      : false;
}

/**
 * Helper Functions to get Token Footer
 *
 * @param string $footer : footer string from paseto token
 * @return bool false    : False on empty or invalid footer
 * @return string|false $kid   : KID from footer if present
 */
function paseto_footer_kid($footer)
{
  if ($footer) {
    if (strpos($footer, '{') === false) {
      return $footer;
    }
    $jd_footer = json_decode($footer);
    if (is_object($jd_footer) && isset($jd_footer->kid)) {
      return $jd_footer->kid;
    }
  }
  return false;
}

/**
 * Controller log function - if turned on
 * @param string $name
 * @param mixed $logged
 */
function log_debug($name, $logged = null)
{
  if (error_reporting() === E_ALL) {
    error_log("1o-merchant-plugin DEBUG: $name");
    if ($logged != null) {
      error_log("1o-merchant-plugin DEBUG context: " . print_r($logged, true));
    }
  }
}

/**
 * Get the 1o Options and parse for use.
 */
function get_oneO_options()
{
  $oneO_settings_options = get_option('oneO_settings_option_name', []); // Array of All Options
  $public_key = !empty($oneO_settings_options['public_key']) ? $oneO_settings_options['public_key'] : '';
  $secret_key = !empty($oneO_settings_options['secret_key']) ? $oneO_settings_options['secret_key'] : '';
  $int_id = !empty($oneO_settings_options['integration_id']) ? $oneO_settings_options['integration_id'] : '';
  return (empty($public_key) || empty($secret_key) || empty($int_id))
      ? []
      : [
          $int_id => (object)[
              'api_keys' => [
                  $public_key => $secret_key,
              ],
          ],
          'endpoint' => get_rest_url(null, OOMP_NAMESPACE) . '/',
      ];
}

/**
 * Set order data and add it to WooCommerce
 *
 * @param array $orderData :Array of order data to process.
 * @param int $orderid :Order ID from 1o.
 * @return int $orderKey    :Order Key ID after insert into WC orders.
 */
function oneO_addWooOrder($orderData, $orderid)
{
  $email = $orderData['customer']['email'];
  $externalData = $orderData['order']['externalData'];
  $wooOrderkey = isset($externalData->WooID) && $externalData->WooID != '' ? $externalData->WooID : false;
  log_debug('externalData', $externalData);
  if ($wooOrderkey !== false) {
    $checkKey = oneO_order_key_exists($wooOrderkey);
    // if order key exists, order has already been porcessed.
    if ($checkKey) {
      /**
       * ?? FUTURE ??: Maybe we need to add a way to update an order?
       * If so this is where it should go.
       * */
      return false;
    }
  }
  /* Customer */
  $name = $orderData['customer']['name'];
  $phone = $orderData['customer']['phone'];
  // ?? FUTURE ??: Maybe update user meta with name and phone and addresses if new user is created?

  $products = $orderData['products'];
  $random_password = wp_generate_password(12, false);
  $user = email_exists($email) !== false ? get_user_by('email', $email) : wp_create_user($email, $random_password, $email);
  $args = [
      'customer_id' => $user->ID,
      'customer_note' => 'Created via 1o Merchant Plugin',
      'created_via' => '1o API',
  ];
  $order = wc_create_order($args);
  if (!empty($products)) {
    foreach ($products as $product) {
      $args = [];
      $prod = wc_get_product($product['id']);
      /* possible args to override:
          'name' => 'product name',
          'tax_class' => 'tax class',
          'product_id' => (int),
          'variation_id' => (int),
          'variation' => 'variation name',
          'subtotal' => $custom_price_for_this_order, // e.g. 32.95
          'total' => $custom_price_for_this_order, // e.g. 32.95
          'quantity' => qty (int)
      */
      if ($prod->get_price() != ($product['price'] / 100)) {
        $args['subtotal'] = ($product['price'] / 100);
        $args['total'] = ($product['total'] / 100);
        $discount = ($product['price'] / 100) - $prod->get_price();
        $discount_string = "katalys.com discount";
        $order->add_coupon($discount_string, $discount, 0);
      }
      $order->add_product($prod, $product['qty'], $args);
    }
  }

  /* Billing Data */
  $bName = $orderData['billing']['billName'];
  $nameSplit = oneO_doSplitName($bName);
  $bFName = $nameSplit['first'];
  $bLName = $nameSplit['last'];
  $bEmail = $orderData['billing']['billEmail'];
  $bPhone = $orderData['billing']['billPhone'];
  $bAddress_1 = $orderData['billing']['billAddress1'];
  $bAddress_2 = $orderData['billing']['billAddress2'];
  $bCity = $orderData['billing']['billCity'];
  $bZip = $orderData['billing']['billZip'];
  $bCountry = $orderData['billing']['billCountry'];
  $bCountryC = $orderData['billing']['billCountryCode'];
  $bState = $orderData['billing']['billState'];
  $bStateC = $orderData['billing']['billStateCode'];

  /* Shipping Data */
  $sName = $orderData['shipping']['shipName'];
  $nameSplit = oneO_doSplitName($sName);
  $sFName = $nameSplit['first'];
  $sLName = $nameSplit['last'];
  $sEmail = $orderData['shipping']['shipEmail'];
  $sPhone = $orderData['shipping']['shipPhone'];
  $sAddress_1 = $orderData['shipping']['shipAddress1'];
  $sAddress_2 = $orderData['shipping']['shipAddress2'];
  $sCity = $orderData['shipping']['shipCity'];
  $sState = $orderData['shipping']['shipState'];
  $sStateC = $orderData['shipping']['shipStateCode'];
  $sZip = $orderData['shipping']['shipZip'];
  $sCountry = $orderData['shipping']['shipCountry'];
  $sCountryC = $orderData['shipping']['shipCountryCode'];

  /* Set billing address in order */
  $order->set_billing_first_name($bFName);
  $order->set_billing_last_name($bLName);
  $order->set_billing_company(''); // not really used, so set to empty string.
  $order->set_billing_address_1($bAddress_1);
  $order->set_billing_address_2($bAddress_2);
  $order->set_billing_city($bCity);
  $order->set_billing_state($bState);
  $order->set_billing_postcode($bZip);
  $order->set_billing_country($bCountry);
  $order->set_billing_phone($bPhone);
  $order->set_billing_email($bEmail);

  /* Set shipping address in order */
  $order->set_shipping_first_name($sFName);
  $order->set_shipping_last_name($sLName);
  $order->set_shipping_company(''); // not really used, so set to empty string.
  $order->set_shipping_address_1($sAddress_1);
  $order->set_shipping_address_2($sAddress_2);
  $order->set_shipping_city($sCity);
  $order->set_shipping_state($sState);
  $order->set_shipping_postcode($sZip);
  $order->set_shipping_country($sCountry);
  $order->set_shipping_phone($sPhone);
  /**
   *
   * OTHER DATA IN $OrderData NOT USED :
   * [order][status] => FULFILLED // 1o status
   * [order][totalPrice] => 2200 // (calculated on order after other items are added.)
   */
  $orderTotal = $orderData['order']['total'];
  $taxPaid = $orderData['order']['totalTax'];

  $currency = $orderData['order']['currency'];
  $transID = $orderData['transactions']['id']; // ?? might not be needed ??
  $transName = $orderData['transactions']['name'];
  $shippingCost = $orderData['order']['totalShipping'] / 100;
  $chosenShipping = $orderData['order']['chosenShipping'];
  $shippingCostArr = explode("|", $chosenShipping);
  $shipName = isset($shippingCostArr[2]) ? str_replace('-', " ", $shippingCostArr[2]) : '';
  $shipSlug = isset($shippingCostArr[0]) ? $shippingCostArr[0] : '';

  $order->set_currency($currency);
  $order->set_payment_method($transName);
  $newOrderID = $order->get_id();
  $order_item_id = wc_add_order_item($newOrderID, ['order_item_name' => $shipName, 'order_item_type' => 'shipping']);
  wc_add_order_item_meta($order_item_id, 'cost', $shippingCost, true);
  $order->shipping_method_title = $shipSlug;

  /**
   * Items that might be used in the future.
   *
   * $shipping_taxes = WC_Tax::calc_shipping_tax('10', WC_Tax::get_shipping_tax_rates());
   * $rate = new WC_Shipping_Rate('flat_rate_shipping', 'Flat rate shipping', '10', $shipping_taxes, 'flat_rate');
   * $item = new WC_Order_Item_Shipping();
   * $item->set_props(array('method_title' => $rate->label, 'method_id' => $rate->id, 'total' => wc_format_decimal($rate->cost), 'taxes' => $rate->taxes, 'meta_data' => $rate->get_meta_data() ));
   * $order->add_item($item);
   *
   * ** Set payment gateway **
   * $payment_gateways = WC()->payment_gateways->payment_gateways();
   * $order->set_payment_method($payment_gateways['bacs']);
   */

  // Set totals in order
  $order->set_shipping_total($shippingCost / 100);
  $order->set_discount_total($discount);
  $order->set_discount_tax(0);
  $order->set_cart_tax($taxPaid / 100);
  //$order->set_shipping_tax(0);
  $order->set_total($orderTotal / 100);
  $order->calculate_totals();
  $order->update_status('completed', 'added by 1o - order:' . $orderid);
  $order->update_meta_data('_is-1o-order', '1');
  $order->update_meta_data('_1o-order-number', $orderid);
  $orderKey = $order->get_order_key();
  $order->save();
  return $orderKey;
}

/**
 * Splits single name string into salutation, first, last, suffix
 *
 * @param string $name :String to split into pieces.
 * @return array        :Array of split pieces.
 */
function oneO_doSplitName($name)
{
  $results = [];
  $r = explode(' ', $name);
  $size = count($r);
  //check first for period, assume salutation if so
  if (mb_strpos($r[0], '.') === false) {
    $results['salutation'] = '';
    $results['first'] = $r[0];
  } else {
    $results['salutation'] = $r[0];
    $results['first'] = $r[1];
  }

  //check last for period, assume suffix if so
  if (mb_strpos($r[$size - 1], '.') === false) {
    $results['suffix'] = '';
  } else {
    $results['suffix'] = $r[$size - 1];
  }

  //combine remains into last
  $start = ($results['salutation']) ? 2 : 1;
  $end = ($results['suffix']) ? $size - 2 : $size - 1;
  $last = '';
  for ($i = $start; $i <= $end; $i++) {
    $last .= ' ' . $r[$i];
  }
  $results['last'] = trim($last);
  return $results;
}
/**
 * Check if order key exists in database
 *
 * @param string $orderKey Key to lookup
 * @param string $key Name of the meta_key that will contain the value
 * @return bool
 */
function oneO_order_key_exists($orderKey, $key = "_order_key")
{
  if (!$orderKey) {
    return false;
  }
  global $wpdb;
  $orderQuery = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->postmeta WHERE `meta_key` = '%s' AND `meta_value` = '%s' LIMIT 1;", [$key, $orderKey]));
  log_debug('orderQuery', $orderQuery);

  return isset($orderQuery[0]);
}


/**
 * @param string $url
 * @return int
 */
function url_to_postId($url)
{
  global $wp_rewrite;
  if (isset($_GET['post']) && !empty($_GET['post']) && is_numeric($_GET['post'])) {
    return $_GET['post'];
  }
  if (preg_match('#[?&](p|post|page_id|attachment_id)=(\d+)#', $url, $values)) {
    $id = absint($values[2]);
    if ($id) {
      return $id;
    }
  }
  if (isset($wp_rewrite)) {
    $rewrite = $wp_rewrite->wp_rewrite_rules();
  }
  if (empty($rewrite)) {
    if (isset($_GET) && !empty($_GET)) {
      $tempUrl = $url;
      $url_split = explode('#', $tempUrl);
      $tempUrl = $url_split[0];
      $url_query = explode('&', $tempUrl);
      $tempUrl = $url_query[0];
      $url_query = explode('?', $tempUrl);
      if (isset($url_query[1]) && !empty($url_query[1]) && strpos($url_query[1], '=')) {
        $url_query = explode('=', $url_query[1]);
        if (isset($url_query[0]) && isset($url_query[1])) {
          $args = [
              'name' => $url_query[1],
              'post_type' => $url_query[0],
              'showposts' => 1,
          ];
          if ($post = get_posts($args)) {
            return $post[0]->ID;
          }
        }
      }
      foreach ($GLOBALS['wp_post_types'] as $key => $value) {
        if (isset($_GET[$key]) && !empty($_GET[$key])) {
          $args = [
              'name' => $_GET[$key],
              'post_type' => $key,
              'showposts' => 1,
          ];
          if ($post = get_posts($args)) {
            return $post[0]->ID;
          }
        }
      }
    }
  }
  $url_split = explode('#', $url);
  $url = $url_split[0];
  $url_query = explode('?', $url);
  $url = $url_query[0];
  if (false !== strpos(home_url(), '://www.') && false === strpos($url, '://www.')) {
    $url = str_replace('://', '://www.', $url);
  }
  if (false === strpos(home_url(), '://www.')) {
    $url = str_replace('://www.', '://', $url);
  }
  if (isset($wp_rewrite) && !$wp_rewrite->using_index_permalinks()) {
    $url = str_replace('index.php/', '', $url);
  }
  if (false !== strpos($url, home_url())) {
    $url = str_replace(home_url(), '', $url);
  } else {
    $home_path = parse_url(home_url());
    $home_path = isset($home_path['path']) ? $home_path['path'] : '';
    $url = str_replace($home_path, '', $url);
  }
  $url = trim($url, '/');
  $request = $url;
  if (empty($request) && (!isset($_GET) || empty($_GET))) {
    return get_option('page_on_front');
  }
  $request_match = $url;
  foreach ((array)$rewrite as $match => $query) {
//    if (!empty($url) && ($url != $request) && (strpos($match, $url) === 0)) {
//      $request_match = $url . '/' . $request;
//    }
    if (preg_match("!^$match!", $request_match, $matches)) {
      $query = preg_replace("!^.+\?!", '', $query);
      $query = addslashes(WP_MatchesMapRegex::apply($query, $matches));
      global $wp;
      parse_str($query, $query_vars);
      $query = [];
      foreach ((array)$query_vars as $key => $value) {
        if (in_array($key, $wp->public_query_vars)) {
          $query[$key] = $value;
        }
      }
      $custom_post_type = false;
      $post_types = [];
      foreach ($rewrite as $key => $value) {
        if (preg_match('/post_type=([^&]+)/i', $value, $matched)) {
          if (isset($matched[1]) && !in_array($matched[1], $post_types)) {
            $post_types[] = $matched[1];
          }
        }
      }

      foreach ((array)$query_vars as $key => $value) {
        if (in_array($key, $post_types)) {

          $custom_post_type = true;

          $query['post_type'] = $key;
          $query['postname'] = $value;
        }
      }
      foreach ($GLOBALS['wp_post_types'] as $post_type => $t) {
        if ($t->query_var) {
          $post_type_query_vars[$t->query_var] = $post_type;
        }
      }
      foreach ($wp->public_query_vars as $wpvar) {
        if (isset($wp->extra_query_vars[$wpvar])) {
          $query[$wpvar] = $wp->extra_query_vars[$wpvar];
        } elseif (isset($_POST[$wpvar])) {
          $query[$wpvar] = $_POST[$wpvar];
        } elseif (isset($_GET[$wpvar])) {
          $query[$wpvar] = $_GET[$wpvar];
        } elseif (isset($query_vars[$wpvar])) {
          $query[$wpvar] = $query_vars[$wpvar];
        }
        if (!empty($query[$wpvar])) {
          if (!is_array($query[$wpvar])) {
            $query[$wpvar] = (string)$query[$wpvar];
          } else {
            foreach ($query[$wpvar] as $vkey => $v) {
              if (!is_object($v)) {
                $query[$wpvar][$vkey] = (string)$v;
              }
            }
          }
          if (isset($post_type_query_vars[$wpvar])) {
            $query['post_type'] = $post_type_query_vars[$wpvar];
            $query['name'] = $query[$wpvar];
          }
        }
      }
      if (isset($query['pagename']) && !empty($query['pagename'])) {
        $args = [
            'name' => $query['pagename'],
            'post_type' => ['post', 'page'], // Added post for custom permalink eg postname
            'showposts' => 1,
        ];
        if ($post = get_posts($args)) {
          return $post[0]->ID;
        }
      }
      $query = new WP_Query($query);
      if (!empty($query->posts) && $query->is_singular) {
        return $query->post->ID;
      } else {
        if (!empty($query->posts) && isset($query->post->ID) && $custom_post_type == true) {
          return $query->post->ID;
        }
        if (isset($post_types)) {
          foreach ($rewrite as $key => $value) {
            if (preg_match('/\?([^&]+)=([^&]+)/i', $value, $matched)) {
              if (isset($matched[1]) && !in_array($matched[1], $post_types) && array_key_exists($matched[1], $query_vars)) {
                $post_types[] = $matched[1];
                $args = [
                    'name' => $query_vars[$matched[1]],
                    'post_type' => $matched[1],
                    'showposts' => 1,
                ];
                if ($post = get_posts($args)) {
                  return $post[0]->ID;
                }
              }
            }
          }
        }
        return 0;
      }
    }
  }
  return 0;
}
