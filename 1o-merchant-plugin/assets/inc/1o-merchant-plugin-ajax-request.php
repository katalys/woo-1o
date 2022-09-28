<?php
namespace KatalysMerchantPlugin;

use ParagonIE\Paseto\Keys\SymmetricKey;
use ParagonIE\Paseto\Protocol\Version2;

/**
 * Hook to register our new routes from the controller with WordPress.
 */
add_action('rest_api_init', [OneO_REST_DataController::class, 'register_routes']);

class OneO_REST_DataController
{
  /**
   * Register namespace Routes with WordPress for 1o Plugin to use.
   */
  public static function register_routes($namespace = OOMP_NAMESPACE)
  {
    $self = new static();
    register_rest_route($namespace, '/(?P<integrationId>[A-Za-z0-9\-]+)', [
        [
            'methods' => ['GET', 'POST'],
            'callback' => [$self, 'get_request'],
            'permission_callback' => [$self, 'get_request_permissions_check'],
        ],
        'schema' => [$self, 'get_request_schema'],
    ]);
    /* temp - to create PASETOs on demand */
    register_rest_route($namespace . '-create', '/create-paseto', [
        [
            'methods' => ['GET'],
            'callback' => [$self, 'create_paseto_request'],
            'permission_callback' => [$self, 'get_request_permissions_check'],
        ],
        'schema' => [$self, 'get_request_schema'],
    ]);
  }

  /**
   * Check permissions for the posts. Basic check for Bearer Token.
   *
   * @param WP_REST_Request $request Current request.
   * @return true|WP_Error
   */
  public function get_request_permissions_check($request)
  {
    $token = self::get_token_from_headers();
    if ($token) {
      return true;
    }
    return new WP_Error('Error-000', 'Not Allowed. No Bearer token found.', ['status' => 403]);
  }

  /**
   * Get Authorization Header from headers for verifying Paseto token.
   *
   * @return string $token
   */
  public static function get_token_from_headers()
  {
    $token = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
      $token = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (isset($_SERVER['Authorization'])) {
      $token = trim($_SERVER["Authorization"]);
    } elseif (function_exists('apache_request_headers')) {
      $requestHeaders = apache_request_headers();
      $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));

      if (isset($requestHeaders['Authorization'])) {
        $token = trim($requestHeaders['Authorization']);
      }
    } else {
      //todo look for '1o-bearer-token', 'bearer' headers?
    }

    if (stripos($token, 'bearer ') === 0) {
      $token = substr($token, 7);
    }
    return $token;
  }

  public function get_request($request)
  {
    $token = OneO_REST_DataController::get_token_from_headers();
    $requestBody = $request->get_json_params();
    $directives = OneO_REST_DataController::get_directives($requestBody);

    log_debug('Body from 1o', $requestBody);

    if (empty($directives) || !is_array($directives)) {
      /* Error response for 1o */
      return new WP_Error('Error-103', 'Payload Directives empty. You must have at least one Directive.', ['status' => 400]);
    }

    if (!$token) {
      /* Error response for 1o */
      return new WP_Error('Error-100', 'No Token Provided', ['status' => 400]);
    }

    $options = get_oneO_options();
    $integrationId = $request['integrationId'];

    if (empty($integrationId)) {
      return new WP_Error('Error-102', 'No Integration ID Provided', ['status' => 400]);
    }

    $v2Token = str_replace('Bearer ', '', $token);
    $footer = paseto_decode_footer($token);
    $footerString = paseto_footer_kid($footer);
    if ($options->publicKey != $footerString) {
      return new WP_Error('Error-200', 'PublicKey does not match IDs on file.');
    }
    if ($options->integrationId != $integrationId) {
      return new WP_Error('Error-200', 'IntegrationID does not match IDs on file.');
    }
    if (!$options->secretKey) {
      return new WP_Error('Error-200', 'SecretID is empty');
    }
    $_secret = $options->secretKey;

    // key exists and can be used to decrypt.
    $res_Arr = [];
    $key = new SymmetricKey(base64_decode($_secret));
    $decryptedToken = Version2::decrypt($v2Token, $key, $footer);
    $rawDecryptedToken = json_decode($decryptedToken);
    if (paseto_is_expired($rawDecryptedToken)) {
      /* Error response for 1o */
      $error = new WP_Error('Error-300', 'PASETO Token is Expired.', 'API Error');
      wp_send_json($error);
    } else {
      // valid - move on & process request
      if (!empty($directives) && is_array($directives)) {

        foreach ($directives as $directive) {
          log_debug('=======' . $directive . '======', $directive);
          $res_Arr[] = OneO_REST_DataController::process_directive($directive, $footer);
        }
      }
    }

    $results = ["results" => $res_Arr];
    log_debug('$results from request to 1o', $results);
    return $results;
  }


  public static function set_tax_amt($amt = 0, $id = '')
  {
    set_transient($id . '_taxamt', $amt, 60);
  }

  public static function get_tax_amt($id = '')
  {
    if (!$id) {
      return false;
    }
    return get_transient($id . '_taxamt') ?: false;
  }

  /**
   * Get array of options from a product.
   *
   * @param object $product :product object from WooCommerce
   * @return array $optGroup  :array of product options
   */
  public static function get_product_options($product)
  {
    if (!is_object($product) || !is_array($product)) {
      $options = $product->get_attributes('view');
      $optGroup = [];
      $optList = [];
      $optList2 = [];
      if (is_array($options) && !empty($options)) {
        foreach ($options as $opk => $opv) {
          $optArray = [];
          $data = $opv->get_data();
          $optArrName = $opv->get_taxonomy_object()->attribute_label;
          $optArray['name'] = $optArrName;
          $optArray['position'] = $data['position'] + 1;
          $optList2[$opk] = $optArrName;
          if (is_array($data['options']) && !empty($data['options'])) {
            $pv = 1;
            foreach ($data['options'] as $dok => $dov) {
              $dovName = get_term($dov)->name;
              $dovSlug = get_term($dov)->slug;
              $optArray['options'][] = (object)[
                  "name" => $dovName,
                  "position" => $pv,
              ];
              $optList[$opk][$dovSlug] = $dovName;
              $optList2[$dovSlug] = $dovName;
              $pv++;
            }
            $optGroup[] = [
                "name" => $optArray['name'],
                "position" => $optArray['position'],
                "options" => $optArray['options'],
            ];
          }
        }
      }
    }
    return ['group' => $optGroup, 'list' => $optList, 'names' => $optList2];
  }

  /**
   * Get array of images from a product.
   *
   * @param object $product :product object from WooCommerce
   * @return array $images    :array of images
   */
  public static function get_product_images($product, $productId = 0)
  {
    $gallImgs = [];
    if (is_object($product)) {
      $imgIds = $product->get_gallery_image_ids('view');
      if (!empty($imgIds) && is_array($imgIds)) {
        foreach ($imgIds as $ikey => $ival) {
          $tempUrl = wp_get_attachment_image_url($ival, 'full');
          if ($tempUrl) {
            $gallImgs[] = $tempUrl;
          }
        }
      } else {
        $imgId = $product->get_image_id('view');
        if ($imgId != '') {
          $gallImgs[] = wp_get_attachment_image_url($imgId, 'full');
        }
      }
    }
    return $gallImgs;
  }

//  /**
//   * Convert string HTML into Markdown format
//   *
//   * @param string $desc        :regular HTML markup
//   * @return string $parsedHTML :converted HTML or empty string.
//   */
//  public static function concert_desc_to_markdown($desc = '')
//  {
//    require_once(OOMP_LOC_VENDOR_PATH . 'markdown-converter/Converter.php');
//    $converter = new Markdownify\Converter;
//    $parsedHTML = $converter->parseString($desc);
//    if ($parsedHTML != '') {
//      return $parsedHTML;
//    } else {
//      return '';
//    }
//  }

  public static function process_variants($variants = [], $optionNames = [], $productTitle = '', $currency = '', $currencySign = '')
  {
    $processedVariants = [];
    //TODO: check if is a variant ((bool)$variants['vatiants'], I think )
    if (is_array($variants) && !empty($variants)) {
      $pv = 0;
      foreach ($variants as $variant) {
        if (isset($variant['variation_is_active']) && (bool)$variant['variation_is_active'] && isset($variant['variation_is_visible']) && (bool)$variant['variation_is_visible']) {
          $subtitleName = '';
          if (isset($variant['attributes']) && !empty($variant['attributes'])) {
            $tempSTN = [];
            foreach ($variant['attributes'] as $vav) {
              $tempSTN[] = $optionNames[$vav];
            }
            $subtitleName = implode("/", $tempSTN);
          }
          $pvtemp = [
            //"title" => $productTitle, // Only needed if different than main product.
              "subtitle" => $subtitleName,
              "price" => round(($variant['display_price'] * 100), 0, PHP_ROUND_HALF_UP),
              "compare_at_price" => round(($variant['display_regular_price'] * 100), 0, PHP_ROUND_HALF_UP),
              "currency" => $currency,
              "currency_sign" => $currencySign,
              "external_id" => (string)$variant['variation_id'],
              "shop_url" => get_permalink($variant['variation_id']),
              "variant" => true,
            //'sku' => $variant['sku'],
            //TODO: Add SKU to at 1o level.
              "images" => [
                  $variant['image']['url'],
              ],
          ];
          $attribs = $variant['attributes'];
          if (is_array($attribs) && !empty($attribs)) {
            $np = 1;
            foreach ($attribs as $attK => $attV) {
              $attName = str_replace("attribute_", "", $attK);
              $pvtemp['option_' . $np . '_names_path'] = [
                  $optionNames[$attName],
                  $optionNames[$attV],
              ];
              $np++;
            }
          }
          $processedVariants[] = (object)$pvtemp;
        }
        $pv++;
      }
    }
    return $processedVariants;
  }

  public static function process_directive($directive = [], $kid = '')
  {
    $return_arr = [];
    $return_arr["integration_id"] = get_oneO_options()->integrationId;
    $return_arr["endpoint"] = get_oneO_options()->endpoint;

    try {
      $runner = new DirectiveRunner($kid);
      $processed = $runner->_process($directive['directive'], $directive['args']);
    } catch (\Exception $e) {
      //todo
    }
    $status = isset($processed->status) ? $processed->status : 'unknown';
    $order_id = isset($processed->order_id) ? $processed->order_id : null;
    $data = isset($processed->data) ? $processed->data : null;
    if ($order_id != null) {
      $return_arr["order_id"] = $order_id; // order_id if present
    }
    if ($data != null) {
      $return_arr["data"] = $data;
    }
    $return_arr["source_id"] = $directive['id']; // directive id
    $return_arr["source_directive"] = $directive['directive']; // directive name
    $return_arr["status"] = $status; // ok or error or error message
    return (object)$return_arr;
  }

  public static function create_a_cart($order_id, $kid, $args, $type = '')
  {
    # Step 1: Create new Paseto for request.
    $ss = OneO_REST_DataController::get_stored_secret();
    $newPaseto = paseto_create_token($ss, $kid, OOMP_PASETO_EXP);

    # Step 2: Do request to graphql to get line items.
    $getLineItems = new Oo_graphQLRequest($newPaseto);
    $linesRaw = $getLineItems->api_line_items($order_id);
    log_debug('process_request: line_items', $getLineItems);

    // Do Something here to process line items??
    $lines = isset($linesRaw->data->order->lineItems) ? $linesRaw->data->order->lineItems : [];

    # Step 3: Get shipping rates & availability from Woo.
    /**
     * Real Shipping Totals
     * Set up new cart to get real shipping total
     * */
    $args['shipping-rates'] = [];
    $args['items_avail'] = [];

    if (!isset(WC()->cart)) {
      // initiallize the cart of not yet done.
      WC()->initialize_cart();
      if (!function_exists('wc_get_cart_item_data_hash')) {
        include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
      }
    }
    if (isset(WC()->customer)) {
      $countryArr = [
          "United States" => 'US',
          "Canada" => 'CA',
      ];
      $sCountry = isset($linesRaw->data->order->shippingAddressCountry) ? $linesRaw->data->order->shippingAddressCountry : null;
      $sCountryC = isset($linesRaw->data->order->shippingAddressCountryCode) ? $linesRaw->data->order->shippingAddressCountryCode : null;
      $sCity = isset($linesRaw->data->order->shippingAddressCity) ? $linesRaw->data->order->shippingAddressCity : null;
      $sState = isset($linesRaw->data->order->shippingAddressSubdivision) ? $linesRaw->data->order->shippingAddressSubdivision : null;
      $sStateC = isset($linesRaw->data->order->shippingAddressSubdivisionCode) ? $linesRaw->data->order->shippingAddressSubdivisionCode : null;
      $sZip = isset($linesRaw->data->order->shippingAddressZip) ? $linesRaw->data->order->shippingAddressZip : null;
      $sAddress1 = isset($linesRaw->data->order->shippingAddressLine_1) ? $linesRaw->data->order->shippingAddressLine_1 : null;
      $sAddress2 = isset($linesRaw->data->order->shippingAddressLine_2) ? $linesRaw->data->order->shippingAddressLine_2 : null;
      $sCountry = is_null($sCountryC) || strlen($sCountryC) > 2 ? $countryArr[$sCountry] : $sCountryC;
      WC()->customer->set_shipping_country($sCountry);
      WC()->customer->set_shipping_state($sState);
      WC()->customer->set_shipping_postcode($sZip);
      WC()->customer->set_shipping_city($sCity);
      WC()->customer->set_shipping_address($sAddress1);
      WC()->customer->set_shipping_address_1($sAddress1);
      WC()->customer->set_shipping_address_2($sAddress2);
    }
    if (!empty($lines)) {
      foreach ($lines as $line) {
        $product_id = $line->productExternalId;
        $quantity = $line->quantity;
        WC()->cart->add_to_cart($product_id, $quantity);

        $productTemp = new WC_Product_Factory();
        $product = $productTemp->get_product($product_id);
        $availability = $product->is_in_stock();
        $args['items_avail'][] = (object)[
            "id" => $line->id,
            "available" => $availability,
        ];
      }
    }

    WC()->cart->maybe_set_cart_cookies();
    WC()->cart->calculate_shipping();
    WC()->cart->calculate_totals();
    $countCart = WC()->cart->get_cart_contents_count();
    foreach (WC()->cart->get_shipping_packages() as $package_id => $package) {
      // Check if a shipping for the current package exist
      if (WC()->session->__isset('shipping_for_package_' . $package_id)) {
        // Loop through shipping rates for the current package
        foreach (WC()->session->get('shipping_for_package_' . $package_id)['rates'] as $shipping_rate_id => $shipping_rate) {
          $rate_id = $shipping_rate->get_id(); // same as $shipping_rate_id variable (combination of the shipping method and instance ID)
          $method_id = $shipping_rate->get_method_id(); // The shipping method slug
          $instance_id = $shipping_rate->get_instance_id(); // The instance ID
          $label_name = $shipping_rate->get_label(); // The label name of the method
          $cost = $shipping_rate->get_cost(); // The cost without tax
          $tax_cost = $shipping_rate->get_shipping_tax(); // The tax cost
          //$taxes       = $shipping_rate->get_taxes(); // The taxes details (array)
          $itemPriceEx = number_format($cost, 2, '.', '');
          $itemPriceIn = number_format($cost / 100 * 24 + $cost, 2, '.', '');
          //set up rates array for 1o
          $args['shipping-rates'][] = (object)[
              "handle" => $method_id . '-' . $instance_id . '|' . (isset($itemPriceEx) ? ($itemPriceEx * 100) : '0') . '|' . str_replace(" ", "-", $label_name),
              "title" => $label_name,
              "amount" => $itemPriceEx * 100,
          ];
        }
      }
    }
    $taxesArray = WC()->cart->get_taxes();
    $taxTotal = 0;
    if (is_array($taxesArray) && !empty($taxesArray)) {
      foreach ($taxesArray as $taxCode => $taxAmt) {
        $taxTotal = $taxTotal + $taxAmt;
      }
    }
    OneO_REST_DataController::set_tax_amt($taxTotal, $order_id);
    $args['tax_amt'] = $taxTotal;
    WC()->cart->empty_cart();
    if ($type == 'tax_amt') {
      return $args['tax_amt'];
    } elseif ($type == 'shipping_rates') {
      return $args['shipping-rates'];
    }
    return $args;
  }

  /**
   * Process 1o order data for insert into easy array for insert into WC
   *
   * @param array $orderData :Array of order data from 1o
   * @return array $returnArr   :Array of processed order data or false if none.
   */
  public static function process_order_data($orderData)
  {
    $products = [];
    if (is_object($orderData) && !empty($orderData)) {
      $data = $orderData->data->order;
      $lineItems = isset($data->lineItems) ? $data->lineItems : [];
      $transactions = isset($data->transactions[0]) ? $data->transactions[0] : (object)['id' => '', 'name' => ''];
      if (!empty($lineItems)) {
        foreach ($lineItems as $k => $v) {
          $products[] = [
              "id" => $v->productExternalId,
              "qty" => $v->quantity,
              'price' => $v->price,
              'currency' => $v->currency,
              'tax' => $v->tax,
              'total' => ($v->price * $v->quantity),
              'variantExternalId' => $v->variantExternalId,
          ];
        }
      }
      $billing = [
          'billName' => $data->billingName,
          'billEmail' => $data->billingEmail,
          'billPhone' => $data->billingPhone,
          'billAddress1' => isset($data->billingAddressLine_1) ? $data->billingAddressLine_1 : '',
          'billAddress2' => isset($data->billingAddressLine_2) ? $data->billingAddressLine_2 : '',
          'billCity' => $data->billingAddressCity,
          'billState' => $data->billingAddressSubdivision,
          'billStateCode' => $data->billingAddressSubdivisionCode,
          'billZip' => $data->billingAddressZip,
          'billCountry' => $data->billingAddressCountry,
          'billCountryCode' => $data->billingAddressCountryCode,
      ];
      $shipping = [
          'shipName' => $data->shippingName,
          'shipEmail' => $data->shippingEmail,
          'shipPhone' => $data->shippingPhone,
          'shipAddress1' => isset($data->shippingAddressLine_1) ? $data->shippingAddressLine_1 : '',
          'shipAddress2' => isset($data->shippingAddressLine_2) ? $data->shippingAddressLine_2 : '',
          'shipCity' => $data->shippingAddressCity,
          'shipState' => $data->shippingAddressSubdivision,
          'shipStateCode' => $data->shippingAddressSubdivisionCode,
          'shipZip' => $data->shippingAddressZip,
          'shipCountry' => $data->shippingAddressCountry,
          'shipCountryCode' => $data->shippingAddressCountryCode,
      ];
      $customer = [
          'email' => isset($data->customerEmail) && $data->customerEmail != '' ? $data->customerEmail : '',
          'name' => $data->customerName,
          'phone' => $data->customerPhone,
      ];
      $order = [
          'status' => $data->fulfillmentStatus,
          'total' => $data->total,
          'totalPrice' => $data->totalPrice,
          'totalShipping' => $data->totalShipping,
          'totalTax' => $data->totalTax,
          'chosenShipping' => $data->chosenShippingRateHandle,
          'currency' => $data->currency,
          'externalData' => ($data->externalData != '' ? json_decode($data->externalData) : (object)[]),
      ];
      $transact = [
          'id' => $transactions->id,
          'name' => $transactions->name,
      ];
      $retArr = ['products' => $products, 'order' => $order, 'customer' => $customer, 'billing' => $billing, 'shipping' => $shipping, 'transactions' => $transact];
      return $retArr;
    }
    return false;
  }

  /**
   * Creates PASETO Token for requests to GraphQL
   * Can be created with an external call
   *
   * @param $echo (bool)
   * @return $token or echo $token & die
   */
  public function create_paseto_request($echo = true)
  {
    $ss = OneO_REST_DataController::get_stored_secret();
    $pk = json_encode(['kid' => get_oneO_options()->publicKey], JSON_UNESCAPED_SLASHES);
    $token = paseto_create_token($ss, $pk, OOMP_PASETO_EXP);
    if ($echo) {
      echo 'new token:' . $token . "\n";
      die();
    } else {
      return $token;
    }
  }

  /**
   * Gets Directives
   *
   * @param $directives :The Directives from the Request Body. May need to be sanitized.
   * @return array|WP_Error $directives    :array of directives or empty array.
   */
  public static function get_directives($requestBody)
  {
    if (is_array($requestBody) && !empty($requestBody)) {
      $directives = isset($requestBody['directives']) ? $requestBody['directives'] : false;
      log_debug('directives in get_directives()', $directives);
      if ($directives !== false && !empty($directives)) {
        return $directives;
      } else {
        return new WP_Error('Error-103', 'Payload Directives empty. You must have at least one Directive.', ['status' => 400]);
      }
    }
    return new WP_Error('Error-104', 'Payload Directives not found in Request. You must have at least one Directive.', ['status' => 400]);
  }

  /**
   * Gets stored secret from settings DB.
   *
   * @return string stored secret key
   */
  public static function get_stored_secret()
  {
    $key = get_oneO_options()->secretKey;
    return $key ? base64_decode($key) : '';
  }

  /**
   * Get our sample schema for a post.
   *
   * @return array The sample schema for a post
   */
  public function get_request_schema()
  {
    return [
      // This tells the spec of JSON Schema we are using which is draft 4.
        '$schema' => 'http://json-schema.org/draft-04/schema#',
      // The title property marks the identity of the resource.
        'title' => '1oRequest',
        'type' => 'object',
      // In JSON Schema you can specify object properties in the properties attribute.
        'properties' => [
            'directives' => [
                'description' => esc_html__('Unique identifier for the object.', 'my-textdomain'),
                'type' => 'array',
              #'context' => array( 'view', 'edit', 'embed' ),
              #'readonly' => true,
            ],
            'content' => [
                'description' => esc_html__('The content for the object.', 'my-textdomain'),
                'type' => 'string',
            ],
            'title' => [
                'description' => esc_html__('The title for the object.', 'my-textdomain'),
                'type' => 'string',
            ],
        ],
    ];
  }
}

class DirectiveRunner
{
  const OK = 'ok';
  const ERROR = 'error';

  private $kid;
  private $order_id;
  private $args;

  public function __construct($kid = '')
  {
    $this->kid = $kid;
  }

  /**
   * Creates PASETO Token FROM requests from 1o for return response.
   * Internal call only.
   * @return string
   */
  private function _createPasetoToken()
  {
    $ss = OneO_REST_DataController::get_stored_secret();
    return paseto_create_token($ss, $this->kid, OOMP_PASETO_EXP);
  }

  /**
   * @param string $directive
   * @param array $args
   * @return object
   */
  public function _process($directive, $args)
  {
    require_once OOMP_LOC_PATH . '/graphql-requests.php';

    $this->args = $args ?: [];
    $this->order_id = !empty($args['order_id']) ? $args['order_id'] : null;

    $methodName = 'directive__' . strtolower($directive);
    if (!preg_match('#^\w+$#', $methodName) || !method_exists($this, $methodName)) {
      throw new \Exception("Bad directive name: $methodName");
    }

    $ret = $this->$methodName($args);
    if (!is_array($ret)) {
      $ret = [
          'status' => $ret === null ? self::OK : $ret,
      ];
      if (isset($args['order_id'])) {
        $ret['order_id'] = $args['order_id'];
      }
    }
    return (object)$ret;
  }

  public function directive__update_tax_amounts()
  {
    $taxAmt = OneO_REST_DataController::get_tax_amt($this->order_id);
    if ($taxAmt === false) {
      // calculate
      $args = OneO_REST_DataController::create_a_cart($this->order_id, $this->kid, $this->args, 'tax_amt');
    } else {
      $args = $this->args;
      $args['tax_amt'] = $taxAmt;
    }
    $newPaseto = $this->_createPasetoToken();
    $updateTax = new Oo_graphQLRequest($newPaseto);
    $updateTax->api_update_tax_amount($this->order_id, $args);
  }

  public function directive__health_check()
  {
    # Step 1: create PASETO
    $newPaseto = $this->_createPasetoToken();

    # Step 2: Do Health Check Request
    $getHealthCheck = new Oo_graphQLRequest($newPaseto);
    $oORequest = $getHealthCheck->api_health_check();
    if ($oORequest
        && isset($oORequest->data->healthCheck)
        && $oORequest->data->healthCheck == 'ok'
    ) {
      return [
          'status' => self::OK,
          'data' => (object)[
              'healthy' => true,
              'internal_error' => null,
              'public_error' => null,
          ],
      ];
    }

    $checkMessage = $oORequest->data->healthCheck;
    if ($checkMessage) {
      return $checkMessage;
    }
    return self::OK;
  }

  public function directive__update_product_pricing()
  {
    log_debug('process_future_directive: update_product_pricing', '[$kid]:' . $this->kid . ' | [order_id]:' . $this->order_id);
    //todo
    return 'future';
  }

  public function directive__inventory_check()
  {
    log_debug('process_future_directive: inventory_check', '[$kid]:' . $this->kid . ' | [order_id]:' . $this->order_id);
    //todo
    return 'future';
  }

  public function directive__import_product_from_url()
  {
    $args = $this->args;
    $retArr = [];

    # Step 1: Create new Paseto for request.
    $newPaseto = $this->_createPasetoToken();

    # Step 2: Parse the product URL.
    $prodURL = isset($args['product_url']) && $args['product_url'] != '' ? esc_url_raw($args['product_url']) : false;
    $processed = null;
    $canProcess = false;

    # Step 3: If not empty, get product data for request.
    if ($prodURL) {
      $productId = url_to_postId($prodURL);
      $productTemp = new WC_Product_Factory();
      $productType = $productTemp->get_product_type($productId);
      $product = $productTemp->get_product($productId);
      $isDownloadable = $product->is_downloadable();

      if ($product->get_status() != 'publish') {
        $args['product_to_import'] = 'not published';
        $processed = "Product must be set to Published to be imported.";
      } elseif ($productType == 'simple' && !$isDownloadable) { //get regular product data (no variants)
        $canProcess = true;
        $retArr["name"] = $product->get_slug(); //slug
        $retArr["title"] = $product->get_name(); //title
        $retArr["currency"] = get_woocommerce_currency();
        $retArr["currency_sign"] = html_entity_decode(get_woocommerce_currency_symbol());
        $retArr["price"] = round(($product->get_sale_price('view') * 100), 0, PHP_ROUND_HALF_UP);
        $retArr["compare_at_price"] = round(($product->get_regular_price('view') * 100), 0, PHP_ROUND_HALF_UP);
        $prodDesc = $product->get_description();
        //$retArr["summary_md"] = OneO_REST_DataController::concert_desc_to_markdown($prodDesc);
        //Only use the Markdown or HTML, not both. Markdown takes precedence over HTML.
        $retArr["summary_html"] = $prodDesc; // HTML description
        $retArr["external_id"] = (string)$productId; //product ID
        $retArr["shop_url"] = $prodURL; //This is the PRODUCT URL (not really the shop URL)
        $retArr["images"] = OneO_REST_DataController::get_product_images($product, $productId);
        //$retArr['sku'] = $product->get_sku();
        //TODO: SKU needs to be added on 1o end still.
        $options = OneO_REST_DataController::get_product_options($product);
        $retArr["option_names"] = $options['group'];
        $retArr["variant"] = false; //bool
        $retArr["variants"] = []; //empty array (no variants)
        //$retArr["available"] = $product->is_in_stock();
        //TODO: Product Availability Boolean needs to be added on 1o end still.
        $returnObj = (object)$retArr;
        $args['product_to_import'] = $returnObj;
      } elseif ($productType == 'variable' && !$isDownloadable) { //get variable product data (with variants)
        $canProcess = true;
        $retArr["name"] = $product->get_slug(); //slug
        $retArr["title"] = $product->get_name(); //title
        $retArr["currency"] = get_woocommerce_currency();
        $retArr["currency_sign"] = html_entity_decode(get_woocommerce_currency_symbol());
        $retArr["price"] = (number_format((float)$product->get_sale_price('view'), 2) * 100);
        $retArr["compare_at_price"] = (number_format((float)$product->get_regular_price('view'), 2) * 100);
        $prodDesc = $product->get_description();
        //$retArr["summary_md"] = OneO_REST_DataController::concert_desc_to_markdown($prodDesc);
        //Only use the Markdown or HTML, not both. Markdown takes precedence over HTML.
        $retArr["summary_html"] = $prodDesc;
        $retArr["external_id"] = (string)$productId;
        $retArr["shop_url"] = $prodURL;
        $retArr["images"] = OneO_REST_DataController::get_product_images($product);
        //$retArr['sku'] = $product->get_sku();
        //TODO: SKU needs to be added on 1o end still.
        $options = OneO_REST_DataController::get_product_options($product);
        $retArr["option_names"] = $options['group'];
        $retArr["variant"] = false; //bool
        $variants = $product->get_available_variations();
        $processedVariants = [];
        if (is_array($variants) && !empty($variants)) {
          $processedVariants = OneO_REST_DataController::process_variants($variants, $options['names'], $retArr["title"], $retArr["currency"], $retArr["currency_sign"]);
        }
        $retArr["variants"] = $processedVariants;
        //$retArr["available"] = $product->is_in_stock();
        //TODO: Product Availability Boolean needs to be added on 1o end still.
        $returnObj = (object)$retArr;
        $args['product_to_import'] = $returnObj;
      } elseif ($productType == 'downloadable' || $isDownloadable) {
        $processed = 'Product type "Downloadable" not accepted.';
      } else {
        $processed = 'Product type "' . $productType . '" not accepted.';
      }
      // Acceptable Types: Simple, Variable;
      // Other types: grouped, virtual, downloadable, external/affiliate
      // There could also be these types if using a plugin: subscription, bookable, mempership, bundled, auction
    }

    $oORequest = false;
    if ($canProcess) {
      $req = new Oo_graphQLRequest($newPaseto);
      $oORequest = $req->api_import_product(/*$args['product_url'],*/ $args);
      log_debug('process_directive: import_product_from_url', $oORequest);
    }

    if ($oORequest && !$processed) {
      $retArr['status'] = self::OK;
    } elseif (is_null($processed)) {
      $retArr['status'] = self::ERROR;
    }
    return $retArr;
  }

  public function directive__update_available_shipping_rates()
  {
    log_debug('process_directive: update_available_shipping_rates', '[$kid]:' . $this->kid . ' | [order_id]:' . $this->order_id);
    $args = OneO_REST_DataController::create_a_cart($this->order_id, $this->kid, $this->args, '');

    # Step 4: Update shipping rates on GraphQL.
    $newPaseto2 = $this->_createPasetoToken();
    $updateShipping = new Oo_graphQLRequest($newPaseto2);
    $updateShipping->api_update_ship_rates($this->order_id, $args);
    log_debug('update_ship_rates', $updateShipping);
  }

  public function directive__update_availability()
  {
    log_debug('process_directive: update_availability', '[$kid]:' . $this->kid . ' | [order_id]:' . $this->order_id);
    $args = OneO_REST_DataController::create_a_cart($this->order_id, $this->kid, $this->args, 'items_avail');

    # Update Availability on GraphQL.
    $newPaseto = $this->_createPasetoToken();
    $req = new Oo_graphQLRequest($newPaseto);
    $req->api_update_availability($this->order_id, $args);
    log_debug('update_availability', $req);
  }

  public function directive__complete_order()
  {
    # Step 1: create PASETO
    $newPaseto = $this->_createPasetoToken();

    # Step 2: Get new order data from 1o - in case anything changed
    $args = $this->args;
    $getOrderData = new Oo_graphQLRequest($newPaseto);
    $result = $getOrderData->api_order_data($this->order_id);
    log_debug('process_directive: $getOrderData', $getOrderData);

    # Step 3: prepare order data for Woo import
    $orderData = OneO_REST_DataController::process_order_data($result);
    // insert into Woo & grab Woo order ID
    $newOrderID = oneO_addWooOrder($orderData, $this->order_id);
    if ($newOrderID === false) {
      return 'exists';
    }

    # Step 3a: Create new Paseto for 1o request.
    $newPaseto = $this->_createPasetoToken();

    # Step 3b: Do request to graphql to complete order.
    // Pass Woo order ID in external data
    $args['external-data'] = ['WooID' => $newOrderID];
    if ($newOrderID != '' && $newOrderID !== false) {
      $args['fulfilled-status'] = 'FULFILLED';
    } else {
      $args['fulfilled-status'] = 'unknown-error';
    }
    $req = new Oo_graphQLRequest($newPaseto);
    $req->api_complete_order($this->order_id, $args);
  }
}