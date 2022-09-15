<?php
class OneO_REST_DataController
{

  /**
   * Initiallize namespace variable and resourse name.
   */
  public function __construct()
  {
    $this->namespace = OOMP_NAMESPACE;
  }

  /**
   * Controller log function - if turned on
   */
  public static function set_controller_log($name = '', $logged = null)
  {
    global $oneOControllerLog;
    if ($logged !== null && OOMP_ERROR_LOG) {
      $oneOControllerLog[$name] = $logged;
    }
  }

  /**
   * Gets controller log
   */
  public function get_controller_log()
  {
    global $oneOControllerLog;
    return $oneOControllerLog;
  }

  public static function set_tax_amt($amt = 0, $id = '')
  {
    set_transient($id . '_taxamt', $amt, 60);
  }

  public static function get_tax_amt($id = '')
  {
    if ($id == '') {
      return false;
    }
    $transTax = get_transient($id . '_taxamt');
    if ($transTax === false || $transTax == '') {
      return false;
    }
    return $transTax;
  }

  public function process_controller_log()
  {
    global $oneOControllerLog;
    if (OOMP_ERROR_LOG && is_array($oneOControllerLog) && !empty($oneOControllerLog)) {
      error_log('controller log:' . "\n" . print_r($oneOControllerLog, true));
    }
  }

  /**
   * Returns current namespace used for 1o plugin.
   */
  public function get_namespace()
  {
    return $this->namespace;
  }

  /**
   * Register namespace Routes with WordPress for 1o Plugin to use.
   */
  public function register_routes()
  {
    register_rest_route($this->namespace, '/(?P<integrationId>[A-Za-z0-9\-]+)', array(
      array(
        'methods' => array('GET', 'POST'),
        'callback' => array($this, 'get_request'),
        'permission_callback' => array($this, 'get_request_permissions_check'),
      ),
      'schema' => array($this, 'get_request_schema'),
    ));
    /* temp - to create PASETOs on demand */
    register_rest_route($this->namespace . '-create', '/create-paseto', array(
      array(
        'methods' => array('GET'),
        'callback' => array($this, 'create_paseto_request'),
        'permission_callback' => array($this, 'get_request_permissions_check'),
      ),
      'schema' => array($this, 'get_request_schema'),
    ));
  }

  /**
   * Check permissions for the posts. Basic check for Bearer Token.
   *
   * @param WP_REST_Request $request Current request.
   */
  public function get_request_permissions_check($request)
  {
    $headers = OneO_REST_DataController::get_all_headers();
    if ((isset($headers['bearer']) || isset($headers['Bearer']))  && (!empty($headers['bearer']) || !empty($headers['Bearer']))) {
      return true;
    } else {
      $error = new WP_Error('Error-000', 'Not Allowed. No Bearer token found.', 'API Error');
      return false;
    }
  }

  /**
   * Get all Headers from request.
   * 
   * @return array $headers   :Array of all headers
   */
  public static function get_all_headers()
  {
    if (function_exists('getallheaders')) {
      return getallheaders();
    } else {
      $headers = [];
      foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
          $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
      }
      return $headers;
    }
  }

  /**
   * Get Authorization Header from headers for verifying Paseto token.
   * 
   * @param array $headers    :Array of all headers to process.
   * @return string $token    :'Authorization', '1o-bearer-token' or 'bearer' header value or false.
   */
  public static function get_token_from_headers($headers)
  {
    $token = '';
    if (is_array($headers) && !empty($headers)) {
      foreach ($headers as $name => $val) {
        if (in_array(strtolower($name), array('authorization', '1o-bearer-token', 'bearer'))) {
          $token = $val; #$token holds PASETO token to be parsed 
        }
      }
    }
    if ($token != '') {
      return $token;
    } else {
      return false;
    }
  }

  public function get_request($request)
  {
    $headers = OneO_REST_DataController::get_all_headers();
    $token = OneO_REST_DataController::get_token_from_headers($headers);
    $requestBody = $request->get_json_params();
    $directives = OneO_REST_DataController::get_directives($requestBody);
    OneO_REST_DataController::set_controller_log('Headers from 1o', print_r($headers, true));
    OneO_REST_DataController::set_controller_log('Body from 1o', print_r($request->get_json_params(), true));
    //echo '$directives:' . print_r($directives, true) . "\n";
    if (empty($directives) || !is_array($directives)) {
      /* Error response for 1o */
      $error = new WP_Error('Error-103', 'Payload Directives empty. You must have at least one Directive.', 'API Error');
      wp_send_json_error($error, 500);
    }

    if ($token === false || $token === '') {
      /* Error response for 1o */
      $error = new WP_Error('Error-100', 'No Token Provided', 'API Error');
      wp_send_json_error($error, 500);
    }

    $options = get_oneO_options();
    $apiEnd = isset($options['endpoint']) && $options['endpoint'] != '' ? $options['endpoint'] : false;
    $integrationId = $request['integrationId'];

    if (empty($integrationId)) {
      /* Error response for 1o */
      $error = new WP_Error('Error-102', 'No Integraition ID Provided', 'API Error');
      wp_send_json_error($error, 500);
    } else {
      $v2Token = str_replace('Bearer ', '', $token);
      $footer = process_paseto_footer($token);
      $footerString = get_paseto_footer_string($footer);
      $_secret = isset($options[$integrationId]->api_keys[$footerString]) ? $options[$integrationId]->api_keys[$footerString] : null;
      if ($_secret == '' || is_null($_secret)) {
        /* Error response for 1o */
        $error = new WP_Error('Error-200', 'Integraition ID does not match IDs on file.', 'API Error');
        wp_send_json_error($error, 500);
      } else {
        // key exists and can be used to decrypt.
        $res_Arr = array();
        $key = new \ParagonIE\Paseto\Keys\SymmetricKey(base64_decode($_secret));
        $decryptedToken = \ParagonIE\Paseto\Protocol\Version2::decrypt($v2Token, $key, $footer);
        $rawDecryptedToken = \json_decode($decryptedToken);
        if (check_if_paseto_expired($rawDecryptedToken)) {
          /* Error response for 1o */
          $error = new WP_Error('Error-300', 'PASETO Token is Expired.', 'API Error');
          wp_send_json_error($error, 500);
        } else {
          // valid - move on & process request
          if (!empty($directives) && is_array($directives)) {

            foreach ($directives as $d_key => $d_val) {
              OneO_REST_DataController::set_controller_log('=======' . $d_key . '======', print_r($d_key, true));
              $res_Arr[] = OneO_REST_DataController::process_directive($d_key, $d_val, $footer);
            }
          }
        }
      }
      $out = array("results" => $res_Arr, 'integration_id' => OneO_REST_DataController::get_stored_intid(), 'endpoint' => OneO_REST_DataController::get_stored_endpoint());
      $results = (object)$out;
      OneO_REST_DataController::set_controller_log('$results from request to 1o', print_r($results, true));
      self::process_controller_log();
      wp_send_json_success($results, 200);
    }
    // Return all of our post response data.
    return $res_Arr;
  }

  /**
   * Get array of options from a product.
   * 
   * @param object $product   :product object from WooCommerce
   * @return array $optGroup  :array of product options
   */
  public static function get_product_options($product)
  {
    if (!is_object($product) || !is_array($product)) {
      $options = $product->get_attributes('view');
      $optGroup = array();
      $optList = array();
      $optList2 = array();
      if (is_array($options) && !empty($options)) {
        foreach ($options as $opk => $opv) {
          $optArray = array();
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
              $optArray['options'][] = (object) array(
                "name" => $dovName,
                "position" => $pv,
              );
              $optList[$opk][$dovSlug] = $dovName;
              $optList2[$dovSlug] = $dovName;
              $pv++;
            }
            $optGroup[] = array(
              "name" => $optArray['name'],
              "position" => $optArray['position'],
              "options" => $optArray['options']
            );
          }
        }
      }
    }
    return array('group' => $optGroup, 'list' => $optList, 'names' => $optList2);
  }

  /**
   * Get array of images from a product.
   * 
   * @param object $product   :product object from WooCommerce
   * @return array $images    :array of images
   */
  public static function get_product_images($product, $productId = 0)
  {
    $gallImgs = array();
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

  /**
   * Convert string HTML into Markdown format
   * 
   * @param string $desc        :regular HTML markup
   * @return string $parsedHTML :converted HTML or empty string.
   */
  public static function concert_desc_to_markdown($desc = '')
  {
    require_once(OOMP_LOC_VENDOR_PATH . 'markdown-converter/Converter.php');
    $converter = new Markdownify\Converter;
    $parsedHTML = $converter->parseString($desc);
    if ($parsedHTML != '') {
      return $parsedHTML;
    } else {
      return '';
    }
  }

  public static function process_variants($variants = array(), $optionNames = array(), $productTitle = '', $currency = '', $currencySign = '')
  {
    $processedVariants = array();
    //TODO: check if is a variant ((bool)$variants['vatiants'], I think )
    if (is_array($variants) && !empty($variants)) {
      $pv = 0;
      foreach ($variants as $variant) {
        if (isset($variant['variation_is_active']) && (bool) $variant['variation_is_active'] && isset($variant['variation_is_visible'])  && (bool)$variant['variation_is_visible']) {
          $subtitleName = '';
          if (isset($variant['attributes']) && !empty($variant['attributes'])) {
            $tempSTN = array();
            foreach ($variant['attributes'] as $vav) {
              $tempSTN[] = $optionNames[$vav];
            }
            $subtitleName = implode("/", $tempSTN);
          }
          $pvtemp = array(
            //"title" => $productTitle, // Only needed if different than main product.
            "subtitle" => $subtitleName,
            "price" => round(($variant['display_price'] * 100), 0, PHP_ROUND_HALF_UP),
            "compare_at_price" => round(($variant['display_regular_price'] * 100), 0, PHP_ROUND_HALF_UP),
            "currency" => $currency,
            "currency_sign" => $currencySign,
            "external_id" => (string) $variant['variation_id'],
            "shop_url" => get_permalink($variant['variation_id']),
            "variant" => true,
            //'sku' => $variant['sku'], 
            //TODO: Add SKU to at 1o level.
            "images" => array(
              $variant['image']['url']
            )
          );
          $attribs = $variant['attributes'];
          if (is_array($attribs) && !empty($attribs)) {
            $np = 1;
            foreach ($attribs as $attK => $attV) {
              $attName = str_replace("attribute_", "", $attK);
              $pvtemp['option_' . $np . '_names_path'] = array(
                $optionNames[$attName],
                $optionNames[$attV]
              );
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

  public static function process_directive(string $d_key = '', $d_val = array(), $kid = '')
  {
    $return_arr = array();
    $processed = OneO_REST_DataController::process_directive_function($d_key, $d_val, $kid);
    $status = isset($processed->status) ? $processed->status : 'unknown';
    $order_id = isset($processed->order_id) ? $processed->order_id : null;
    if ($order_id != null) {
      $return_arr["order_id"] = $order_id; // order_id if present
    }
    $return_arr["status"] = $status; // ok or error or error message
    return (object)$return_arr;
  }

  public static function process_directive_function($directive, $args, $kid)
  {
    require_once(OOMP_LOC_CORE_INC . 'graphql-requests.php');
    $processed = null;
    $order_id = isset($args['order_id']) ? esc_attr($args['order_id']) : null;
    $args = !is_array($args) || $args == false ? array() : $args;
    $hasOrderId = true;

    /* other possible directives:  pricing, discounts, inventory checks, */
    switch (strtolower($directive)) {
      case '':
      default:
        $processed = 'Invalid or Missing Directive';
        break;
      case 'update_tax_amounts':
        $taxAmt = OneO_REST_DataController::get_tax_amt($order_id);
        if ($taxAmt === false) {
          // calculate 
          $args = OneO_REST_DataController::create_a_cart($order_id, $kid, 'tax_amt', $args);
        } else {
          $args['tax_amt'] = $taxAmt;
        }
        //echo '============-0==ddddd=======:[[' . $taxAmt . "]]\n";
        $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);
        $updateTax = new Oo_graphQLRequest('update_tax_amount', $order_id, $newPaseto, $args);
        OneO_REST_DataController::set_controller_log('$updateTax:---------------', print_r($updateTax, true));

        # Step 5: If ok response, then return finishing repsponse to initial request.
        if ($updateTax !== false) {
          $processed = 'ok';
        } else {
          $processed = 'error';
        }
      case 'health_check':
        $checkStatus = false;
        $checkMessage = '';

        # Step 1: create PASETO
        $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);

        # Step 2: Do Health Check Request
        $getHealthCheck = new Oo_graphQLRequest('health_check', $order_id, $newPaseto, $args);
        OneO_REST_DataController::set_controller_log('process_directive[ health_check ]:', print_r($getHealthCheck, true));

        # Step 3: Do something after check
        $oORequest = $getHealthCheck->get_request();
        if ($oORequest !== false && isset($oORequest->data->healthCheck)) {
          if ($oORequest->data->healthCheck == 'ok') {
            $checkStatus = true;
          } else {
            $checkMessage = $oORequest->data->healthCheck;
          }
        }

        # Step 4: If ok response, then return finishing repsponse to initial request.
        if ($checkStatus) {
          $processed = 'ok';
        } else {
          $processed = $checkMessage != '' ? $checkMessage : 'error';
        }
        break;
      case 'update_product_pricing':
        $processed = 'future';
        OneO_REST_DataController::set_controller_log('process_future_directive: update_product_pricing', '[$kid]:' . $kid . ' | [order_id]:' . $order_id);
        break;
      case 'inventory_check':
        $processed = 'future';
        OneO_REST_DataController::set_controller_log('process_future_directive: inventory_check', '[$kid]:' . $kid . ' | [order_id]:' . $order_id);
        break;
      case 'import_product_from_url':
        $oORequest = false;
        $hasOrderId = false;
        # Step 1: Create new Paseto for request.
        $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);

        # Step 2: Parse the product URL.
        $prodURL = isset($args['product_url']) && $args['product_url'] != '' ? esc_url_raw($args['product_url']) : false;

        # Step 3: If not empty, get product data for request.
        if ($prodURL !== false) {
          $productId = url_to_postid_1o($prodURL);
          $productTemp = new WC_Product_Factory();
          $productType = $productTemp->get_product_type($productId);
          $product = $productTemp->get_product($productId);
          $isDownloadable = $product->is_downloadable();
          $canProcess = false;
          $retArr = array();
          if (!$product->get_status() == 'publish') {
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
            $retArr["external_id"] = (string) $productId; //product ID
            $retArr["shop_url"] = $prodURL; //This is the PRODUCT URL (not really the shop URL)
            $retArr["images"] = OneO_REST_DataController::get_product_images($product, $productId);
            //$retArr['sku'] = $product->get_sku();
            //TODO: SKU needs to be adde on 1o end still.
            $options = OneO_REST_DataController::get_product_options($product);
            $retArr["option_names"] = $options['group'];
            $retArr["variant"] = false; //bool
            $retArr["variants"] = array(); //empty array (no variants)
            $retArr["available"] = $product->is_in_stock();
            $returnObj = (object) $retArr;
            $args['product_to_import'] = $returnObj;
          } elseif ($productType == 'variable' && !$isDownloadable) { //get variable product data (with variants)
            $canProcess = true;
            $retArr["name"] = $product->get_slug(); //slug
            $retArr["title"] = $product->get_name(); //title
            $retArr["currency"] = get_woocommerce_currency();
            $retArr["currency_sign"] = html_entity_decode(get_woocommerce_currency_symbol());
            $retArr["price"] = (number_format((float) $product->get_sale_price('view'), 2) * 100);
            $retArr["compare_at_price"] = (number_format((float) $product->get_regular_price('view'), 2) * 100);
            $prodDesc = $product->get_description();
            //$retArr["summary_md"] = OneO_REST_DataController::concert_desc_to_markdown($prodDesc);
            //Only use the Markdown or HTML, not both. Markdown takes precedence over HTML.
            $retArr["summary_html"] = $prodDesc;
            $retArr["external_id"] = (string) $productId;
            $retArr["shop_url"] = $prodURL;
            $retArr["images"] = OneO_REST_DataController::get_product_images($product);
            //$retArr['sku'] = $product->get_sku(); 
            //TODO: SKU needs to be added on 1o end still.
            $options = OneO_REST_DataController::get_product_options($product);
            $retArr["option_names"] = $options['group'];
            $retArr["variant"] = false; //bool
            $variants = $product->get_available_variations();
            $processedVariants = array();
            if (is_array($variants) && !empty($variants)) {
              $processedVariants = OneO_REST_DataController::process_variants($variants, $options['names'], $retArr["title"], $retArr["currency"], $retArr["currency_sign"]);
            }
            $retArr["variants"] = $processedVariants;
            $retArr["available"] = $product->is_in_stock();
            $returnObj = (object) $retArr;
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
        if ($canProcess) {
          $oORequest = new Oo_graphQLRequest('import_product', $args['product_url'], $newPaseto, $args);
          $resp = $oORequest->get_request();
          OneO_REST_DataController::set_controller_log('process_directive: import_product_from_url', print_r($oORequest, true));
        }
        if ($oORequest !== false && is_null($processed)) {
          $processed = 'ok';
        } elseif (is_null($processed)) {
          $processed = 'error';
        }
        break;
      case 'update_available_shipping_rates':
        OneO_REST_DataController::set_controller_log('process_directive: update_available_shipping_rates', '[$kid]:' . $kid . ' | [order_id]:' . $order_id);
        $args = OneO_REST_DataController::create_a_cart($order_id, $kid, '', $args);

        # Step 4: Update shipping rates on GraphQL.
        $newPaseto2 = OneO_REST_DataController::create_paseto_from_request($kid);
        $updateShipping = new Oo_graphQLRequest('update_ship_rates', $order_id, $newPaseto2, $args);
        OneO_REST_DataController::set_controller_log('update_ship_rates', print_r($updateShipping, true));

        # Step 5: If ok response, then return finishing repsponse to initial request.
        if ($updateShipping) {
          $processed = 'ok';
        } else {
          $processed = 'error';
        }
        break;
      case 'update_availability':
        OneO_REST_DataController::set_controller_log('process_directive: update_availability', '[$kid]:' . $kid . ' | [order_id]:' . $order_id);
        $args = OneO_REST_DataController::create_a_cart($order_id, $kid, 'items_avail', $args);

        # Update Availability on GraphQL.
        $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);
        $updateAvail = new Oo_graphQLRequest('update_availability', $order_id, $newPaseto, $args);
        OneO_REST_DataController::set_controller_log('update_availability', print_r($updateAvail, true));

        # If ok response, then return finishing response to initial request.
        if ($updateShipping) {
          $processed = 'ok';
        } else {
          $processed = 'error';
        }


        break;
      case 'complete_order':
        # Step 1: create PASETO
        $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);

        # Step 2: Get new order data from 1o - in case anything changed
        $getOrderData = new Oo_graphQLRequest('order_data', $order_id, $newPaseto, $args);
        OneO_REST_DataController::set_controller_log('process_directive: $getOrderData', print_r($getOrderData, true));

        # Step 3: prepare order data for Woo import
        if ($getOrderData) {
          $orderData = OneO_REST_DataController::process_order_data($getOrderData->get_request());
          // insert into Woo & grab Woo order ID 
          $newOrderID = oneO_addWooOrder($orderData, $order_id);
          if ($newOrderID === false) {
            return  $processed = 'exists';
          }
          # Step 3a: Create new Paseto for 1o request.
          $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);

          # Step 3b: Do request to graphql to complete order.
          // Pass Woo order ID in external data 
          $args['external-data'] = array('WooID' => $newOrderID);
          if ($newOrderID != '' && $newOrderID !== false) {
            $args['fulfilled-status'] = 'FULFILLED';
          } else {
            $args['fulfilled-status'] = 'unknown-error';
          }
          $oORequest = new Oo_graphQLRequest('complete_order', $order_id, $newPaseto, $args);
        }
        # Step 4: If ok response, then return finishing repsponse to initial request.
        if ($oORequest !== false) {
          $processed = 'ok';
        } else {
          $processed = 'error';
        }
        break;
    }
    $retArr['status'] = $processed;
    if ($hasOrderId)
      $retArr['order_id'] = $order_id;
    return (object) $retArr;
  }

  public static function create_a_cart($order_id, $kid, $type = '', $args)
  {
    # Step 1: Create new Paseto for request.
    $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);

    # Step 2: Do request to graphql to get line items.
    $getLineItems = new Oo_graphQLRequest('line_items', $order_id, $newPaseto, $args);
    OneO_REST_DataController::set_controller_log('process_request: line_items', print_r($getLineItems, true));

    // Do Something here to process line items??
    $linesRaw = $getLineItems->get_request();
    $lines = isset($linesRaw->data->order->lineItems) ? $linesRaw->data->order->lineItems : array();

    # Step 3: Get shipping rates & availability from Woo.
    /** 
     * Real Shipping Totals
     * Set up new cart to get real shipping total 
     * */
    $args['shipping-rates'] = array();
    $args['items_avail'] = array();

    if (!isset(WC()->cart)) {
      // initiallize the cart of not yet done.
      WC()->initialize_cart();
      if (!function_exists('wc_get_cart_item_data_hash')) {
        include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
      }
    }
    if (isset(WC()->customer)) {
      $countryArr = array(
        "United States" => 'US',
        "Canada" => 'CA',
      );
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
      foreach ($lines as $lk => $lv) {
        $product_id = $lv->productExternalId;
        $quantity = $lv->quantity;
        WC()->cart->add_to_cart($product_id, $quantity);

        $productTemp = new WC_Product_Factory();
        $product = $productTemp->get_product($product_id);
        $availability = $product->is_in_stock();
          $args['items_avail'][] = (object) array(
            "id" => $product_id,
            "availabile" => $availability,
          );
        }
      }

      if ($type == 'items_avail') {
        return $args['items_avail'];
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
            $rate_id     = $shipping_rate->get_id(); // same as $shipping_rate_id variable (combination of the shipping method and instance ID)
            $method_id   = $shipping_rate->get_method_id(); // The shipping method slug
            $instance_id = $shipping_rate->get_instance_id(); // The instance ID
            $label_name  = $shipping_rate->get_label(); // The label name of the method
            $cost        = $shipping_rate->get_cost(); // The cost without tax
            $tax_cost    = $shipping_rate->get_shipping_tax(); // The tax cost
            //$taxes       = $shipping_rate->get_taxes(); // The taxes details (array)
            $itemPriceEx = number_format($cost, 2, '.', '');
            $itemPriceIn = number_format($cost / 100 * 24 + $cost, 2, '.', '');
            //set up rates array for 1o
            $args['shipping-rates'][] = (object) array(
              "handle" => $method_id . '-' . $instance_id . '|' . (isset($itemPriceEx) ? ($itemPriceEx * 100) : '0') . '|' . str_replace(" ", "-", $label_name),
              "title" => $label_name,
              "amount" => $itemPriceEx * 100,
            );
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
  }
  /**
   * Process 1o order data for insert into easy array for insert into WC
   * 
   * @param array $orderData    :Array of order data from 1o
   * @return array $returnArr   :Array of processed order data or false if none.
   */
  public static function process_order_data($orderData)
  {
    $products = array();
    if (is_object($orderData) && !empty($orderData)) {
      $data = $orderData->data->order;
      $lineItems = isset($data->lineItems) ? $data->lineItems : array();
      $transactions = isset($data->transactions[0]) ? $data->transactions[0] : (object) array('id' => '', 'name' => '');
      if (!empty($lineItems)) {
        foreach ($lineItems as $k => $v) {
          $products[] = array(
            "id" => $v->productExternalId,
            "qty" => $v->quantity,
            'price' => $v->price,
            'currency' => $v->currency,
            'tax' => $v->tax,
            'total' => ($v->price * $v->quantity),
            'variantExternalId' => $v->variantExternalId,
          );
        }
      }
      $billing = array(
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
      );
      $shipping = array(
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
      );
      $customer = array(
        'email' => isset($data->customerEmail) && $data->customerEmail != '' ? $data->customerEmail : '',
        'name' => $data->customerName,
        'phone' => $data->customerPhone,
      );
      $order = array(
        'status' => $data->fulfillmentStatus,
        'total' => $data->total,
        'totalPrice' => $data->totalPrice,
        'totalShipping' => $data->totalShipping,
        'totalTax' => $data->totalTax,
        'chosenShipping' => $data->chosenShippingRateHandle,
        'currency' => $data->currency,
        'externalData' => ($data->externalData != '' ? json_decode($data->externalData) : (object) array()),
      );
      $transact = array(
        'id' => $transactions->id,
        'name' => $transactions->name
      );
      $retArr = array('products' => $products, 'order' => $order, 'customer' => $customer, 'billing' => $billing, 'shipping' => $shipping, 'transactions' => $transact);
      return $retArr;
    }
    return false;
  }

  /**
   * Creates PASETO Token for requests to GraphQL
   * Internal call only
   */
  public static function create_paseto()
  {
    // Note - need base 64 decode shared secret.
    require_once(OOMP_LOC_CORE_INC . 'create-paseto.php');
    $ss = OneO_REST_DataController::get_stored_secret();
    $pk = '{"kid":"' . OneO_REST_DataController::get_stored_public() . '"}';
    $expTime = OOMP_PASETO_EXP;
    $newPaseto = new Oo_create_paseto_token($ss, $pk, $expTime);
    $token = $newPaseto->get_signed_token();
    echo 'new token:' . $token . "\n";
    return $token;
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
    require_once(OOMP_LOC_CORE_INC . 'create-paseto.php');
    $ss = OneO_REST_DataController::get_stored_secret();
    $pk = '{"kid":"' . OneO_REST_DataController::get_stored_public() . '"}';
    $expTime = OOMP_PASETO_EXP;
    $newPaseto = new Oo_create_paseto_token($ss, $pk, $expTime);
    $token = $newPaseto->get_signed_token();
    if ($echo) {
      echo 'new token:' . $token . "\n";
      die();
    } else {
      return $token;
    }
  }

  /**
   * Creates PASETO Token FROM requests from 1o for return response.
   * Internal call only.
   * 
   * @param string $kid    : 'kid' variable from request.
   * @return string $token : token for response to 1o
   */
  private static function create_paseto_from_request($kid)
  {
    require_once(OOMP_LOC_CORE_INC . 'create-paseto.php');
    $ss = OneO_REST_DataController::get_stored_secret();
    $expTime = OOMP_PASETO_EXP;
    $newPaseto = new Oo_create_paseto_token($ss, $kid, $expTime);
    $token = $newPaseto->get_signed_token();
    return $token;
  }

  /**
   * Gets Directives
   *
   * @param $directives           :The Directives from the Request Body. May need to be sanitized.
   * @return array $directives    :array of directives or empty array.
   */
  public static function get_directives($requestBody)
  {
    if (is_array($requestBody) && !empty($requestBody)) {
      $directives = isset($requestBody['directives']) ? $requestBody['directives'] : false;
      $do_directives = array();
      $the_directives = array();
      if ($directives !== false && !empty($directives)) {
        foreach ($directives as $dkey => $directive) {
          $do_directives[$directive['directive']] = (isset($directive['args']) ? $directive['args'] : '');
        }
        if (is_array($do_directives) && !empty($do_directives)) {
          foreach ($do_directives as $k => $v) {
            $the_directives[$k] = $v;
          }
        }
        return $the_directives;
      } else {
        $error = new WP_Error('Error-103', 'Payload Directives empty. You must have at least one Directive.', 'API Error');
        wp_send_json_error($error, 500);
      }
    } else {
      $error = new WP_Error('Error-104', 'Payload Directives not found in Request. You must have at least one Directive.', 'API Error');
      wp_send_json_error($error, 500);
    }
    return $the_directives;
  }

  /**
   * Gets stored secret from settings DB.
   * 
   * @return string     : stored secret key
   */
  public static function get_stored_secret()
  {
    $oneO_settings_options = get_option('oneO_settings_option_name', array()); // Array of All Options
    $secret_key = isset($oneO_settings_options['secret_key']) && $oneO_settings_options['secret_key'] != '' ? base64_decode($oneO_settings_options['secret_key']) : ''; // Secret Key
    return $secret_key;
  }

  /**
   * Gets stored integration id from settings DB.
   * 
   * @return string     : stored integration ID
   */
  public static function get_stored_intid()
  {
    $oneO_settings_options = get_option('oneO_settings_option_name', array()); // Array of All Options
    $int_id = isset($oneO_settings_options['integration_id']) && $oneO_settings_options['integration_id'] != '' ? $oneO_settings_options['integration_id'] : ''; // Integration ID
    return $int_id;
  }

  /**
   * Gets stored public key from steeings DB.
   * 
   * @return string     : stored public key
   */
  public static function get_stored_public()
  {
    $oneO_settings_options = get_option('oneO_settings_option_name', array()); // Array of All Options
    $public_key = isset($oneO_settings_options['public_key']) && $oneO_settings_options['public_key'] != '' ? $oneO_settings_options['public_key'] : ''; // Public Key
    return $public_key;
  }

  /**
   * Gets generated 1o endpoint for the store
   * to be used in requests from 1o GraphQL.
   * 
   * @return string     : stored store endpoint
   */
  public static function get_stored_endpoint()
  {
    return get_rest_url(null, OOMP_NAMESPACE) . '/';
  }

  /**
   * Get our sample schema for a post.
   *
   * @return array The sample schema for a post
   */
  public function get_request_schema()
  {
    if ($this->schema) {
      // Since WordPress 5.3, the schema can be cached in the $schema property.
      return $this->schema;
    }

    $this->schema = array(
      // This tells the spec of JSON Schema we are using which is draft 4.
      '$schema' => 'http://json-schema.org/draft-04/schema#',
      // The title property marks the identity of the resource.
      'title' => '1oRequest',
      'type' => 'object',
      // In JSON Schema you can specify object properties in the properties attribute.
      'properties' => array(
        'directives' => array(
          'description' => esc_html__('Unique identifier for the object.', 'my-textdomain'),
          'type' => 'array',
          #'context' => array( 'view', 'edit', 'embed' ),
          #'readonly' => true,
        ),
        'content' => array(
          'description' => esc_html__('The content for the object.', 'my-textdomain'),
          'type' => 'string',
        ),
        'title' => array(
          'description' => esc_html__('The title for the object.', 'my-textdomain'),
          'type' => 'string',
        ),
      ),
    );
    return $this->schema;
  }
}

/**
 * Hook to register our new routes from the controller with WordPress.
 */
function prefix_register_my_rest_routes()
{
  global $oneO_controller;
  $oneO_controller = new OneO_REST_DataController();
  $oneO_controller->register_routes();
}

add_action('rest_api_init', 'prefix_register_my_rest_routes');

/**
 * Helper function to validate exp date of token
 * 
 * @param string $rawDecryptedToken   : raw string from decrypted token.
 * @return bool true (default)        : true if expired or not valid signature.
 *                                    : false if not expired and valid signature.
 */
function check_if_paseto_expired($rawDecryptedToken)
{
  if (is_object($rawDecryptedToken) && isset($rawDecryptedToken->exp)) {
    $checkTime = new DateTime('NOW');
    $checkTime = $checkTime->format(\DateTime::ATOM);
    $tokenExp = new DateTime($rawDecryptedToken->exp);
    $tokenExp = $tokenExp->format(\DateTime::ATOM);
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
 * @param string $token   : Bearer token from authorization header (PASETO)
 * @return bool false     : false on empty, failure or wrong size
 * @return string $token  : token for processing
 */
function process_paseto_footer($token)
{
  if ($token == '') {
    return false;
  }
  if (strpos($token, 'Bearer ') !== false) {
    $token = str_replace('Bearer ', '', $token);
  }
  $pieces = explode('.', $token);
  $count = count($pieces);
  if ($count < 3 || $count > 4) {
    return false;
  }
  return $count > 3 && $pieces[3] != '' ? ParagonIE\ConstantTime\Base64UrlSafe::decode($pieces[3]) : false;
}

/**
 * Helper Functions to get Token Footer
 * 
 * @param string $footer : footer string from paseto token
 * @return bool false    : False on empty or invalid footer
 * @return string $kid   : KID from footer if present
 */
function get_paseto_footer_string($footer)
{
  if (!empty($footer)) {
    if (strpos($footer, '{') === false) {
      return $footer;
    } else {
      $jd_footer = json_decode($footer);
      if (is_object($jd_footer) && isset($jd_footer->kid)) {
        return $jd_footer->kid;
      }
    }
  }
  return false;
}

/**
 * Helper function to get stored shared secret key for store.
 */
function oneO_get_stored_secret()
{
  return OneO_REST_DataController::get_stored_secret();
}

/**
 * Helper function to get stored integration ID for store.
 */
function oneO_get_stored_intid()
{
  return OneO_REST_DataController::get_stored_intid();
}

/**
 * Helper function to get store public key for store.
 */
function oneO_get_stored_public()
{
  return OneO_REST_DataController::get_stored_public();
}

/**
 * Helper function to get endpoint for store.
 */
function oneO_get_stored_endpoint()
{
  return OneO_REST_DataController::get_stored_endpoint();
}

/**
 * Get the 1o Options and parse for use.
 */
function get_oneO_options()
{
  /* Get the options */
  $oneO_settings_options = get_option('oneO_settings_option_name', array()); // Array of All Options
  $public_key = isset($oneO_settings_options['public_key']) && $oneO_settings_options['public_key'] != '' ? $oneO_settings_options['public_key'] : ''; // Public Key
  $secret_key = isset($oneO_settings_options['secret_key']) && $oneO_settings_options['secret_key'] != '' ? $oneO_settings_options['secret_key'] : ''; // Secret Key
  $int_id = isset($oneO_settings_options['integration_id']) && $oneO_settings_options['integration_id'] != '' ? $oneO_settings_options['integration_id'] : ''; // Integration ID
  if (!empty($public_key) && !empty($secret_key) && !empty($int_id)) {
    $tempDB_IntegrationID_Call = array($int_id => (object)array('api_keys' => array($public_key => $secret_key)), 'endpoint' => get_rest_url(null, OOMP_NAMESPACE) . '/');
  } else {
    $tempDB_IntegrationID_Call = array();
  }
  return $tempDB_IntegrationID_Call;
}

/* Initialize the settings page object */
if (is_admin())
  $oneO_settings = new oneO_Settings();

/**
 * Set order data and add it to WooCommerce
 * 
 * @param array $orderData  :Array of order data to process.
 * @param int $orderid      :Order ID from 1o.
 * @return int $orderKey    :Order Key ID after insert into WC orders.
 */
function oneO_addWooOrder($orderData, $orderid)
{
  $email = $orderData['customer']['email'];
  $externalData = $orderData['order']['externalData'];
  $wooOrderkey = isset($externalData->WooID) && $externalData->WooID != '' ? $externalData->WooID : false;
  OneO_REST_DataController::set_controller_log('externalData', print_r($externalData, true));
  if ($wooOrderkey !== false) {
    $checkKey = oneO_order_key_exists("_order_key", $wooOrderkey);
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
  $args = array(
    'customer_id'   => $user->ID,
    'customer_note' => 'Created via 1o Merchant Plugin',
    'created_via'   => '1o API',
  );
  $order =   wc_create_order($args);
  if (!empty($products)) {
    foreach ($products as $product) {
      $args = array();
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
      }
      $order->add_product($prod, $product['qty'], $args);
    }
  }

  /* Billing Data */
  $bName =  $orderData['billing']['billName'];
  $nameSplit = oneO_doSplitName($bName);
  $bFName =  $nameSplit['first'];
  $bLName = $nameSplit['last'];
  $bEmail = $orderData['billing']['billEmail'];
  $bPhone =  $orderData['billing']['billPhone'];
  $bAddress_1 = $orderData['billing']['billAddress1'];
  $bAddress_2 = $orderData['billing']['billAddress2'];
  $bCity =  $orderData['billing']['billCity'];
  $bZip =  $orderData['billing']['billZip'];
  $bCountry =  $orderData['billing']['billCountry'];
  $bCountryC =  $orderData['billing']['billCountryCode'];
  $bState =  $orderData['billing']['billState'];
  $bStateC =  $orderData['billing']['billStateCode'];

  /* Shipping Data */
  $sName = $orderData['shipping']['shipName'];
  $nameSplit = oneO_doSplitName($sName);
  $sFName =  $nameSplit['first'];
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
  $shipSlug = isset($shippingCostArr[0]) ?  $shippingCostArr[0] : '';

  $order->set_currency($currency);
  $order->set_payment_method($transName);
  $newOrderID = $order->get_id();
  $order_item_id = wc_add_order_item($newOrderID, array('order_item_name' => $shipName, 'order_item_type' => 'shipping'));
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
  $order->set_discount_total(0);
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
 * @param string $name  :String to split into pieces.
 * @return array        :Array of split pieces.
 */
function oneO_doSplitName($name)
{
  $results = array();
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
 * Add 1o Order Column to order list page.
 * 
 * @param array $columns  Array of culumns (from WP hook)
 * @return array $columns 
 */
add_filter('manage_edit-shop_order_columns', function ($columns) {
  $columns['oneo_order_type'] = '1o Order';
  return $columns;
});

/**
 * Add data to 1o Order Column on order list page.
 * 
 * @param string $column  Name of current column processing (from WP hook)
 * @echo string column data
 */
add_action('manage_shop_order_posts_custom_column', function ($column) {
  global $post;
  if ('oneo_order_type' === $column) {
    $order = wc_get_order($post->ID);
    $isOneO = $order->get_meta('_is-1o-order', true, 'view') != '' ? (bool) $order->get_meta('_is-1o-order', true, 'view') : false;
    if ($isOneO) {
      $oneOID = $order->get_meta('_1o-order-number', true, 'view') != '' ? esc_attr($order->get_meta('_1o-order-number', true, 'view')) : '';
      echo $oneOID;
    } else {
      echo '';
    }
  }
});

/**
 * Check if order key exists in database
 * 
 * @param string $key
 * @param string $orderKey
 * @return bool
 */
function oneO_order_key_exists($key = "_order_key", $orderKey = '')
{
  if ($orderKey == '') {
    return false;
  }
  global $wpdb;
  $orderQuery = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->postmeta WHERE `meta_key` = '%s' AND `meta_value` = '%s' LIMIT 1;", array($key, $orderKey)));
  OneO_REST_DataController::set_controller_log('orderQuery', print_r($orderQuery, true));

  if (isset($orderQuery[0])) {
    return true;
  }
  return false;
}

function url_to_postid_1o($url)
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
      $tempUrl      = $url_split[0];
      $url_query = explode('&', $tempUrl);
      $tempUrl       = $url_query[0];
      $url_query = explode('?', $tempUrl);
      if (isset($url_query[1]) && !empty($url_query[1]) && strpos($url_query[1], '=')) {
        $url_query = explode('=', $url_query[1]);
        if (isset($url_query[0]) && isset($url_query[1])) {
          $args = array(
            'name'      => $url_query[1],
            'post_type' => $url_query[0],
            'showposts' => 1,
          );
          if ($post = get_posts($args)) {
            return $post[0]->ID;
          }
        }
      }
      foreach ($GLOBALS['wp_post_types'] as $key => $value) {
        if (isset($_GET[$key]) && !empty($_GET[$key])) {
          $args = array(
            'name'      => $_GET[$key],
            'post_type' => $key,
            'showposts' => 1,
          );
          if ($post = get_posts($args)) {
            return $post[0]->ID;
          }
        }
      }
    }
  }
  $url_split = explode('#', $url);
  $url       = $url_split[0];
  $url_query = explode('?', $url);
  $url       = $url_query[0];
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
    $url       = str_replace($home_path, '', $url);
  }
  $url = trim($url, '/');
  $request = $url;
  if (empty($request) && (!isset($_GET) || empty($_GET))) {
    return get_option('page_on_front');
  }
  $request_match = $request;
  foreach ((array) $rewrite as $match => $query) {
    if (!empty($url) && ($url != $request) && (strpos($match, $url) === 0)) {
      $request_match = $url . '/' . $request;
    }
    if (preg_match("!^$match!", $request_match, $matches)) {
      $query = preg_replace("!^.+\?!", '', $query);
      $query = addslashes(WP_MatchesMapRegex::apply($query, $matches));
      global $wp;
      parse_str($query, $query_vars);
      $query = array();
      foreach ((array) $query_vars as $key => $value) {
        if (in_array($key, $wp->public_query_vars)) {
          $query[$key] = $value;
        }
      }
      $custom_post_type = false;
      $post_types = array();
      foreach ($rewrite as $key => $value) {
        if (preg_match('/post_type=([^&]+)/i', $value, $matched)) {
          if (isset($matched[1]) && !in_array($matched[1], $post_types)) {
            $post_types[] = $matched[1];
          }
        }
      }

      foreach ((array) $query_vars as $key => $value) {
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
            $query[$wpvar] = (string) $query[$wpvar];
          } else {
            foreach ($query[$wpvar] as $vkey => $v) {
              if (!is_object($v)) {
                $query[$wpvar][$vkey] = (string) $v;
              }
            }
          }
          if (isset($post_type_query_vars[$wpvar])) {
            $query['post_type'] = $post_type_query_vars[$wpvar];
            $query['name']      = $query[$wpvar];
          }
        }
      }
      if (isset($query['pagename']) && !empty($query['pagename'])) {
        $args = array(
          'name'      => $query['pagename'],
          'post_type' => array('post', 'page'), // Added post for custom permalink eg postname
          'showposts' => 1,
        );
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
                $args = array(
                  'name'      => $query_vars[$matched[1]],
                  'post_type' => $matched[1],
                  'showposts' => 1,
                );
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
