<?php

class OneO_REST_DataController
{

  // Here initialize our namespace and resource name.
  public function __construct()
  {
    $this->namespace = OOMP_NAMESPACE;
  }

  public function get_namespace()
  {
    return $this->namespace;
  }

  // Register our routes.
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

  /* Get all Headers */
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
    if (OOMP_ERROR_LOG) {
      error_log("\n" . '[Headers from 1o]: ' . print_r($headers, true) . "\n" . '[Body from 1o]:' . "\n" . print_r($request->get_json_params(), true));
    }

    if (empty($directives) || !is_array($directives)) {
      /* Error response for 1o */
      $error = new WP_Error('Error-103', 'Payload Directives empty. You must have at least one Directive.', 'API Error');
      wp_send_json_error($error, 403);
    }

    if ($token === false || $token === '') {
      /* Error response for 1o */
      $error = new WP_Error('Error-100', 'No Token Provided', 'API Error');
      wp_send_json_error($error, 403);
    }

    $options = get_oneO_options();
    $apiEnd = isset($options['endpoint']) && $options['endpoint'] != '' ? $options['endpoint'] : false;
    $integrationId = $request['integrationId'];

    if (empty($integrationId)) {
      /* Error response for 1o */
      $error = new WP_Error('Error-102', 'No Integraition ID Provided', 'API Error');
      wp_send_json_error($error, 403);
    } else {
      $v2Token = str_replace('Bearer ', '', $token);
      $footer = process_paseto_footer($token);
      $footerString = get_paseto_footer_string($footer);
      $_secret = isset($options[$integrationId]->api_keys[$footerString]) ? $options[$integrationId]->api_keys[$footerString] : null;
      if ($_secret == '' || is_null($_secret)) {
        /* Error response for 1o */
        $error = new WP_Error('Error-200', 'Integraition ID does not match IDs on file.', 'API Error');
        wp_send_json_error($error, 403);
      } else {
        // key exists and can be used to decrypt.
        $res_Arr = array();
        $key = new \ParagonIE\Paseto\Keys\SymmetricKey(base64_decode($_secret));
        $decryptedToken = \ParagonIE\Paseto\Protocol\Version2::decrypt($v2Token, $key, $footer);
        $rawDecryptedToken = \json_decode($decryptedToken);
        if (check_if_paseto_expired($rawDecryptedToken)) {
          /* Error response for 1o */
          $error = new WP_Error('Error-300', 'PASETO Token is Expired.', 'API Error');
          wp_send_json_error($error, 403);
        } else {
          // valid - move on & process request
          if (!empty($directives) && is_array($directives)) {
            foreach ($directives as $d_key => $d_val) {
              $res_Arr[] = OneO_REST_DataController::process_directive($d_key, $d_val, $footer);
            }
          }
        }
      }
      $out = array("results" => $res_Arr, 'integration_id' => OneO_REST_DataController::get_stored_intid(), 'endpoint' => OneO_REST_DataController::get_stored_endpoint());
      $results = (object)$out;

      if (OOMP_ERROR_LOG)
        error_log("\n" . '[$results from request to 1o]: ' . "\n" . print_r($results, true));
      wp_send_json_success($results, 200);
    }
    // Return all of our post response data.
    return $res_Arr;
  }

  public static function process_directive(string $d_key = '', $d_val = array(), $kid = '')
  {
    $return_arr = array();
    $processed = OneO_REST_DataController::process_directive_function($d_key, $d_val, $kid);
    $status = $processed->status;
    $order_id = $processed->order_id;
    $return_arr["in_response_to"] = $d_key; // key
    if ($order_id != null) {
      $return_arr["order_id"] = $order_id; // order_id if present
    }
    $return_arr["status"] = $status; // ok or error
    return (object)$return_arr;
  }

  public static function process_directive_function($directive, $args, $kid)
  {
    require_once(OOMP_LOC_CORE_INC . 'graphql-requests.php');
    $processed = null;
    $order_id = isset($args['order_id']) ? esc_attr($args['order_id']) : null;
    $args = array();

    /* possible directives:  taxes, pricing, discounts, inventory checks, */
    switch (strtolower($directive)) {
      case '':
      default:
        $processed = 'error';
        break;
      case 'update_available_shipping_rates':
        if (OOMP_ERROR_LOG)
          error_log("\n" . '[process_directive: update_available_shipping_rates]:' . "\n" . '[$kid]:' . $kid . ' | [order_id]:' . $order_id);

        # Step 1: Create new Paseto for request.
        $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);

        # Step 2: Do request to graphql to get line items.
        $getLineItems = new Oo_graphQLRequest('line_items', $order_id, $newPaseto, $args);
        if (OOMP_ERROR_LOG)
          error_log("\n" . '[process_directive: $getLineItems]: ' . "\n" . print_r($getLineItems, true)) . "\n";
        // Do Something here to process line items??

        # Step 3: Get shipping rates from Woo.
        $zones = WC_Shipping_Zones::get_zones();
        $methods = array_column($zones, 'shipping_methods');
        foreach ($methods[0] as $key => $class) {
          $item = [
            "slug" => $class->id,
            "id" => $class->method_title,
            "name" => $class->title
          ];
          if (isset($class->instance_settings["cost"]) && $class->instance_settings["cost"] > 0) {
            $item["price_excl"] = number_format($class->instance_settings["cost"], 2, '.', '');
            $item["price_incl"] = number_format($class->instance_settings["cost"] / 100 * 24 + $class->instance_settings["cost"], 2, '.', '');
          }
          if (isset($class->min_amount) && $class->min_amount > 0) $item["minimum"] = (float)$class->min_amount;
          $data[] = $item;
        }
        $args['shipping-rates'] = array();
        if (!empty($data)) {
          foreach ($data as $dkey => $dval) {
            $args['shipping-rates'][] = (object) array(
              "handle" => $dval['slug'] . '-' . (isset($dval['price_excl']) ? $dval['price_excl'] : '0'),
              "title" => $dval['name'],
              "amount" => $dval['price_excl'] * 100,
            );
          }
        }

        # Step 4: Update shipping rates on GraphQL.
        $newPaseto2 = OneO_REST_DataController::create_paseto_from_request($kid);
        $updateShipping = new Oo_graphQLRequest('update_ship_rates', $order_id, $newPaseto2, $args);
        if (OOMP_ERROR_LOG)
          error_log('$updateShipping:  ' . print_r($updateShipping, true)) . "\n";

        # Step 5: If ok response, then return finishing repsponse to initial request.
        $processed = 'ok';
        break;
      case 'complete_order':
        # Step 1: create PASETO
        $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);

        # Step 2: Get new order data from 1o - in case anything changed
        // grab order data from GraphQL
        $getOrderData = new Oo_graphQLRequest('order_data', $order_id, $newPaseto, $args);
        if (OOMP_ERROR_LOG)
          error_log("\n" . '[process_directive: $getOrderData]: ' . "\n" . print_r($getOrderData, true)) . "\n";

        # Step 3: prepare order data for Woo import
        if ($getOrderData) {
          $orderData = OneO_REST_DataController::process_order_data($getOrderData->get_request());
          // insert into Woo & grab Woo order ID 
          $newOrderID = oneO_addWooOrder($orderData, $order_id);
          //$newOrderID = oneO_addWooOrder($products, $email, $order_id);

          # Step 4: Create new Paseto for 1o request.
          $newPaseto = OneO_REST_DataController::create_paseto_from_request($kid);

          # Step 5: Do request to graphql to complete order.
          // Pass Woo order ID in external data 
          $args['external-data'] = array('WooID' => $newOrderID);
          if ($newOrderID != '' && $newOrderID !== false) {
            $args['fulfilled-status'] = 'FULFILLED';
          } else {
            $args['fulfilled-status'] = 'unknown-error';
          }
          $oORequest = new Oo_graphQLRequest('complete_order', $order_id, $newPaseto, $args);
        }
        # Step 6: If ok response, then return finishing repsponse to initial request.
        if ($oORequest !== false) {
          $processed = 'ok';
        } else {
          $processed = 'error';
        }
        break;
    }
    $retArr['status'] = $processed;
    $retArr['order_id'] = $order_id;
    return (object)$retArr;
  }

  /**
   * Process 1o order data for insert into Woo
   */
  public static function process_order_data($orderData)
  {
    $products = array(); // parse this from response
    // create order array : below is for testing right now - need to create from 1o response
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
            'total' => $v->total,
            'variantExternalId' => $v->variantExternalId,
          );
          // the fileds:
          //  $v->currency
          //  $v->price
          //  $v->productExternalId
          //  $v->quantity
          //  $v->tax
          //  $v->total
          //  $v->variantExternalId
        }
      }
      $billing = array(
        'billName' => $data->billingName,
        'billEmail' => $data->billingEmail,
        'billPhone' => $data->billingPhone,
        'billAddress1' => isset($data->billingAddressLine_1) ? $data->billingAddressLine_1 : '',
        'billAddress2' => isset($data->billingAddressLine_2) ? $data->billingAddressLine_2 : '',
        'billCity' => $data->billingAddressCity,
        'billState' => $data->billingAddressState,
        'billZip' => $data->billingAddressZip,
        'billCountry' => $data->billingAddressCountry,
      );
      $shipping = array(
        'shipName' => $data->shippingName,
        'shipEmail' => $data->shippingEmail,
        'shipPhone' => $data->shippingPhone,
        'shipAddress1' => isset($data->shippingAddressLine_1) ? $data->shippingAddressLine_1 : '',
        'shipAddress2' => isset($data->shippingAddressLine_2) ? $data->shippingAddressLine_2 : '',
        'shipCity' => $data->shippingAddressCity,
        'shipState' => $data->shippingAddressState,
        'shipZip' => $data->shippingAddressZip,
        'shipCountry' => $data->shippingAddressCountry,
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
   */
  public static function create_paseto()
  {
    // base 64 decode ss
    require_once(OOMP_LOC_CORE_INC . 'create-paseto.php');
    $ss = OneO_REST_DataController::get_stored_secret();
    $pk = '{"kid":"' . OneO_REST_DataController::get_stored_public() . '"}';
    $newPaseto = new Oo_create_paseto_token($ss, $pk, 'P01Y');
    $token = $newPaseto->get_signed_token();
    echo 'new token:' . $token . "\n";
    return $token;
  }

  public function create_paseto_request($echo = true)
  {
    require_once(OOMP_LOC_CORE_INC . 'create-paseto.php');
    $ss = OneO_REST_DataController::get_stored_secret();
    $pk = '{"kid":"' . OneO_REST_DataController::get_stored_public() . '"}';
    $newPaseto = new Oo_create_paseto_token($ss, $pk, 'P01Y');
    $token = $newPaseto->get_signed_token();
    if ($echo) {
      echo 'new token:' . $token . "\n";
      die();
    } else {
      return $token;
    }
  }

  private function create_paseto_from_request($kid)
  {
    require_once(OOMP_LOC_CORE_INC . 'create-paseto.php');
    $ss = OneO_REST_DataController::get_stored_secret();
    $newPaseto = new Oo_create_paseto_token($ss, $kid, 'P01Y');
    $token = $newPaseto->get_signed_token();
    return $token;
  }

  /**
   * Gets Directives
   *
   * @param $directives Get the Directives from the Request Body. May need to be sanitized.
   */
  public static function get_directives($requestBody)
  {
    if (is_array($requestBody) && !empty($requestBody)) {
      $directives = isset($requestBody['directives']) ? $requestBody['directives'] : false;
      $do_directives = array();
      $the_directives = array();
      if ($directives !== false && !empty($directives)) {
        foreach ($directives as $dkey => $directive) {
          $do_directives[$directive['directive']] = $directive['args'];
        }
        if (is_array($do_directives) && !empty($do_directives)) {
          foreach ($do_directives as $k => $v) {
            $the_directives[$k] = $v;
          }
        }
        return $the_directives;
      } else {
        $error = new WP_Error('Error-103', 'Payload Directives empty. You must have at least one Directive.', 'API Error');
        wp_send_json_error($error, 403);
      }
    } else {
      $error = new WP_Error('Error-104', 'Payload Directives not found in Request. You must have at least one Directive.', 'API Error');
      wp_send_json_error($error, 403);
    }
  }

  public static function get_stored_secret()
  {
    $oneO_settings_options = get_option('oneO_settings_option_name', array()); // Array of All Options
    $secret_key = isset($oneO_settings_options['secret_key']) && $oneO_settings_options['secret_key'] != '' ? base64_decode($oneO_settings_options['secret_key']) : ''; // Secret Key
    return $secret_key;
  }

  public static function get_stored_intid()
  {
    $oneO_settings_options = get_option('oneO_settings_option_name', array()); // Array of All Options
    $int_id = isset($oneO_settings_options['integration_id']) && $oneO_settings_options['integration_id'] != '' ? $oneO_settings_options['integration_id'] : ''; // Integration ID
    return $int_id;
  }

  public static function get_stored_public()
  {
    $oneO_settings_options = get_option('oneO_settings_option_name', array()); // Array of All Options
    $public_key = isset($oneO_settings_options['public_key']) && $oneO_settings_options['public_key'] != '' ? $oneO_settings_options['public_key'] : ''; // Public Key
    return $public_key;
  }

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

// Function to register our new routes from the controller.
function prefix_register_my_rest_routes()
{
  global $oneO_controller;
  $oneO_controller = new OneO_REST_DataController();
  $oneO_controller->register_routes();
}

add_action('rest_api_init', 'prefix_register_my_rest_routes');

/* Helper function to validate exp of token */
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

/* Helper Functions for Processing Token Footer */
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

/* Helper Functions to get Token Footer */
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

/* other helper funsctions */
function oneO_get_stored_secret()
{
  return OneO_REST_DataController::get_stored_secret();
}

function oneO_get_stored_intid()
{
  return OneO_REST_DataController::get_stored_intid();
}

function oneO_get_stored_public()
{
  return OneO_REST_DataController::get_stored_public();
}

function oneO_get_stored_endpoint()
{
  return OneO_REST_DataController::get_stored_endpoint();
}

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

function oneO_addWooOrder($orderData, $orderid)
{
  /* TO DO : Check to make sure the order has not already been added to Woo */

  $email = $orderData['customer']['email'];
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
      //echo print_r($prod);
      //exit;
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
  /* Customer */
  $name = $orderData['customer']['name'];
  $phone = $orderData['customer']['phone'];
  // TODO: Maybe update user meta with name and phone and addresses if new user is created?

  /* Billing */
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
  $bState =  $orderData['billing']['billState'];
  $billingAddress    =   array(
    'first_name' => $bFName,
    'last_name'  => $bLName,
    'phone'      => $bPhone,
    'email'      => $bEmail,
    'address_1'  => $bAddress_1,
    'address_2'  => $bAddress_2,
    'city'       => $bCity,
    'state'      => $bState,
    'postcode'   => $bZip,
    'country'    => $bCountry,
  );
  $order->set_address($billingAddress, 'billing');

  /* Shipping */
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
  $sZip = $orderData['shipping']['shipZip'];
  $sCountry = $orderData['shipping']['shipCountry'];
  $shippingAddress = array(
    /*
    SHIPPING META FILEDS FOR REFERENCE:
    _shipping_first_name
    _shipping_last_name
    _shipping_company
    _shipping_address_1
    _shipping_address_2
    _shipping_city
    _shipping_state
    _shipping_postcode
    _shipping_country
    _shipping_phone
    
    _order_currency
    _cart_discount
    _cart_discount_tax
    _order_shipping
    _order_shipping_tax
    _order_tax
    _order_total
    
    */
    'first_name' => $sFName,
    'last_name'  => $sLName,
    'email'      => $sEmail,
    'address_1'  => $sAddress_1,
    'address_2'  => $sAddress_2,
    'city'       => $sCity,
    'state'      => $sState,
    'postcode'   => $sZip,
    'phone'      => $sPhone,
    'country'    => $sCountry,
  );
  //$order->set_address($shippingAddress, 'shipping');
  // Set shipping address
  $order->set_shipping_first_name($sFName);
  $order->set_shipping_last_name($sLName);
  $order->set_shipping_company('');
  $order->set_shipping_address_1($sAddress_1);
  $order->set_shipping_address_2($sAddress_2);
  $order->set_shipping_city($sCity);
  $order->set_shipping_state($sState);
  $order->set_shipping_postcode($sZip);
  $order->set_shipping_country($sCountry);
  //$order->set_shipping_email($sEmail);
  $order->set_shipping_phone($sPhone);


  /*
  OTHER DATA IN $OrderData NOT USED :
    [order][status] => FULFILLED // 1o status
    [order][totalPrice] => 2200
    */
  $orderTotal = $orderData['order']['total'];
  $taxPaid = $orderData['order']['totalTax'];
  $currency = $orderData['order']['currency'];

  $order->set_currency($currency);
  $transID = $orderData['transactions']['id'];
  $transName = $orderData['transactions']['name'];
  $shippingCost = $orderData['order']['totalShipping'] / 100;
  $chosenShipping = $orderData['order']['chosenShipping'];

  $order->set_payment_method($transName);
  $shippingCostArr = explode("-", $chosenShipping);
  $newOrderID = $order->get_id();
  $order_item_id = wc_add_order_item($newOrderID, array('order_item_name' => $shippingCostArr[0], 'order_item_type' => 'shipping'));
  wc_add_order_item_meta($order_item_id, 'cost', $shippingCost, true);
  //$order_item_id2 = wc_add_order_item($newOrderID, array('order_item_name' => 'Tax', 'order_item_type' => 'tax'));
  //wc_add_order_item_meta($order_item_id2, 'cost', $taxPaid, true);
  $order->shipping_method_title = $shippingCostArr[0];

  /*
  $shipping_taxes = WC_Tax::calc_shipping_tax('10', WC_Tax::get_shipping_tax_rates());
  $rate = new WC_Shipping_Rate('flat_rate_shipping', 'Flat rate shipping', '10', $shipping_taxes, 'flat_rate');
  $item = new WC_Order_Item_Shipping();
  $item->set_props(array('method_title' => $rate->label, 'method_id' => $rate->id, 'total' => wc_format_decimal($rate->cost), 'taxes' => $rate->taxes, 'meta_data' => $rate->get_meta_data() ));
  $order->add_item($item);
  // Set payment gateway
  $payment_gateways = WC()->payment_gateways->payment_gateways();
  $order->set_payment_method($payment_gateways['bacs']);
  */

  // Set totals
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

  $order->save();

  return $order->get_id();
}

add_filter('manage_edit-shop_order_columns', function ($columns) {
  $columns['oneo_order_type'] = '1o Order';
  return $columns;
});

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
 * splits single name string into salutation, first, last, suffix
 * 
 * @param string $name
 * @return array
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
