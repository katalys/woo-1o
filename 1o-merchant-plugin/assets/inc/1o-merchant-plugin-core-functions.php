<?php

class OneO_REST_DataController {

  // Here initialize our namespace and resource name.
  public function __construct() {
    $this->namespace = OOMP_NAMESPACE;
  }

  public function get_namespace() {
    return $this->namespace;
  }

  // Register our routes.
  public function register_routes() {
    register_rest_route( $this->namespace, '/(?P<integrationId>[A-Za-z0-9\-]+)', array(
      array(
        'methods' => array( 'GET', 'POST' ),
        'callback' => array( $this, 'get_request' ),
        'permission_callback' => array( $this, 'get_request_permissions_check' ),
      ),
      'schema' => array( $this, 'get_request_schema' ),
    ) );
    register_rest_route( $this->namespace . '-create', '/create-paseto', array(
      array(
        'methods' => array( 'GET' ),
        'callback' => array( $this, 'create_paseto_request' ),
        'permission_callback' => array( $this, 'get_request_permissions_check' ),
      ),
      'schema' => array( $this, 'get_request_schema' ),
    ) );
    register_rest_route( $this->namespace . '-test', '/test', array(
      array(
        'methods' => array( 'GET', 'POST' ),
        'callback' => array( $this, 'test_request' ),
        'permission_callback' => array( $this, 'get_request_permissions_check' ),
      ),
      'schema' => array( $this, 'get_request_schema' ),
    ) );
  }
    
  public function test_request() {
    require_once( OOMP_LOC_CORE_INC . 'graphql-requests.php' );
    $headers = OneO_REST_DataController::get_all_headers();
    #$token = OneO_REST_DataController::get_token_from_headers( $headers );
    $orderId = 'bbeabc14-8a99-420c-8b0c-f873fefc0c9e';
    $newPaseto = $this->create_paseto_request( false );
    $args = array();
    /*  
    $zones = WC_Shipping_Zones::get_zones();
    $methods = array_column( $zones, 'shipping_methods' );
    foreach ( $methods[0] as $key => $class ) {
      $item = [
        "id" => $class->method_title,
        "name" => $class->title
      ];
      if ( isset( $class->instance_settings[ "cost" ] ) && $class->instance_settings[ "cost" ] > 0 ) {
        $item[ "price_excl" ] = number_format( $class->instance_settings[ "cost" ], 2, '.', '' );
        $item[ "price_incl" ] = number_format( $class->instance_settings[ "cost" ] / 100 * 24 + $class->instance_settings[ "cost" ], 2, '.', '' );
      }
      if ( isset( $class->min_amount ) && $class->min_amount > 0 ) $item[ "minimum" ] = ( float )$class->min_amount;
      $data[] = $item;
    }
    print_r( $data );
    die();
    */  
    $getLineItems = new Oo_graphQLRequest( 'line_items', $orderId, $newPaseto, $args );
  }
    
  /**
   * Check permissions for the posts.
   *
   * @param WP_REST_Request $request Current request.
*/
  public function get_request_permissions_check( $request ) {
    $headers = OneO_REST_DataController::get_all_headers();
    if ( ( isset( $headers[ 'bearer' ]) || isset( $headers[ 'Bearer' ]) )  && ( !empty( $headers[ 'bearer' ]) || !empty( $headers[ 'Bearer' ]) ) ) {
      return true;
    }else{
      $error = new WP_Error( 'Error-000', 'Not Allowed. No Bearer token found.', 'API Error' );
      return false;
    }
  }

  public static function get_all_headers() {
    if ( function_exists( 'getallheaders' ) ) {
      return getallheaders();
    } else {
      $headers = [];
      foreach ( $_SERVER as $name => $value ) {
        if ( substr( $name, 0, 5 ) == 'HTTP_' ) {
          $headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
        }
      }
      return $headers;
    }
  }
 
  public static function get_token_from_headers( $headers ) {
    $token = '';
    if ( is_array( $headers ) && !empty( $headers ) ) {
      foreach ( $headers as $name => $val ) {
        if ( in_array( strtolower( $name ), array( 'authorization', '1o-bearer-token', 'bearer' ) ) ) {
          $token = $val; #$token holds PASETO token to be parsed 
        }
      }
    }
    if ( $token != '' ) {
      return $token;
    } else {
      return false;
    }
  }
    
  public function get_request( $request ) {
    $headers = OneO_REST_DataController::get_all_headers();
    $token = OneO_REST_DataController::get_token_from_headers( $headers );
    $requestBody = $request->get_json_params();
    $directives = OneO_REST_DataController::get_directives( $requestBody );
    error_log( '$headers: ' . print_r( $headers, true ) );
    error_log( "body:" . print_r( $request->get_json_params(), true ) );
      
    if ( empty( $directives ) || !is_array( $directives ) ) {
      $error = new WP_Error( 'Error-103', 'Payload Directives empty. You must have at least one Directive.', 'API Error' );
      wp_send_json_error( $error, 403 );
    }
      
    if ( $token === false || $token === '' ) {
      $error = new WP_Error( 'Error-100', 'No Token Provided', 'API Error' );
      wp_send_json_error( $error, 403 );
    }
      
    $options = get_oneO_options();
    $apiEnd = isset( $options[ 'endpoint' ] ) && $options[ 'endpoint' ] != '' ? $options[ 'endpoint' ] : false;
    $integrationId = $request[ 'integrationId' ];

    if ( empty( $integrationId ) ) {
      $error = new WP_Error( 'Error-102', 'No Integraition ID Provided', 'API Error' );
      wp_send_json_error( $error, 403 );
    } else {
      $v2Token = str_replace( 'Bearer ', '', $token );
      $footer = process_paseto_footer( $token );
      $footerString = get_paseto_footer_string( $footer );
      $_secret = isset( $options[ $integrationId ]->api_keys[ $footerString ] ) ? $options[ $integrationId ]->api_keys[ $footerString ] : null;
      if ( $_secret == '' || is_null( $_secret ) ) {
        $error = new WP_Error( 'Error-200', 'Integraition ID does not match IDs on file.', 'API Error' );
        wp_send_json_error( $error, 403 );
      } else {
        // key exists and can be used to decrypt.
        $res_Arr = array();
        $key = new \ParagonIE\Paseto\Keys\SymmetricKey( base64_decode( $_secret ) );
        $decryptedToken = \ParagonIE\Paseto\Protocol\Version2::decrypt( $v2Token, $key, $footer );
        $rawDecryptedToken = \json_decode( $decryptedToken );
        if ( check_if_paseto_expired( $rawDecryptedToken ) ) {
          $error = new WP_Error( 'Error-300', 'PASETO Token is Expired.', 'API Error' );
          wp_send_json_error( $error, 403 );
        } else {
          // valid - move on & process request
          if ( !empty( $directives ) && is_array( $directives ) ) {
            foreach ( $directives as $d_key => $d_val ) {
              $res_Arr[] = OneO_REST_DataController::process_directive( $d_key, $d_val, $footer );
            }
          }
        }
      }
      $out = array( "results" => $res_Arr, 'integration_id' => OneO_REST_DataController::get_stored_intid(), 'endpoint' => OneO_REST_DataController::get_stored_endpoint() );
      $results = ( object )$out;
      error_log( '$results: ' . print_r( $results, true ) );
      wp_send_json_success( $results, 200 );
    }
    // Return all of our post response data.
    return $res_Arr;
  }

  public static function process_directive( string $d_key = '', $d_val = array(), $kid = '' ) {
    $return_arr = array();
    $processed = OneO_REST_DataController::process_directive_function( $d_key, $d_val, $kid );
    $status = $processed->status;
    $order_id = $processed->order_id;
    $return_arr[ "in_response_to" ] = $d_key; // key
    if ( $order_id != null ) {
      $return_arr[ "order_id" ] = $order_id; // order_id if present
    }
    $return_arr[ "status" ] = $status; // ok or error
    return ( object )$return_arr;
  }

  public static function process_directive_function( $directive, $args, $kid ) {
    require_once( OOMP_LOC_CORE_INC . 'graphql-requests.php' );
    $processed = null;
    $order_id = isset( $args[ 'order_id' ] ) ? esc_attr( $args[ 'order_id' ] ) : null;
    $args = array();

    /* possible directives:  taxes, pricing, discounts, inventory checks, */
    switch ( strtolower( $directive ) ) {
      case '':
      default:
        $processed = 'error';
        break;
      case 'update_available_shipping_rates_don':
        $zones = WC_Shipping_Zones::get_zones();
        $methods = array_column( $zones, 'shipping_methods' );
        foreach ( $methods[0] as $key => $class ) {
            //echo print_r($class, true);
          $item = [
            "slug" => $class->id,
            "id" => $class->method_title,
            "name" => $class->title
          ];
          if ( isset( $class->instance_settings[ "cost" ] ) && $class->instance_settings[ "cost" ] > 0 ) {
            $item[ "price_excl" ] = number_format( $class->instance_settings[ "cost" ], 2, '.', '' );
            $item[ "price_incl" ] = number_format( $class->instance_settings[ "cost" ] / 100 * 24 + $class->instance_settings[ "cost" ], 2, '.', '' );
          }
          if ( isset( $class->min_amount ) && $class->min_amount > 0 )$item[ "minimum" ] = ( float )$class->min_amount;
          $data[] = $item;
        }
        if(!empty($data)){
            foreach($data as $dkey => $dval){
              $args[ 'shipping-rates' ][] = (object) array(
                "handle" => $dval['slug'].'-'.$dval['price_excl'], //"economy-international-4.50",
                "title" => $dval['name'],
                "amount" => $dval['price_excl']*100,//450
              );  
            }
        }
            /*
        $args[ 'shipping-rates' ] = array(
          ( object )array(
            "handle" => "economy-international-4.50",
            "title" => "Economy International",
            "amount" => 450
          ),
          ( object )array(
            "handle" => "express-international-15.0",
            "title" => "Express International",
            "amount" => 1500
          )
        );
        */
        echo print_r($args,true);
        break;
      case 'update_available_shipping_rates':
        error_log('update_available_shipping_rates: $kid:'.$kid.' | order_id:'.$order_id);
        # Step 1: Create new Paseto for request.
        $newPaseto = OneO_REST_DataController::create_paseto_from_request( $kid );
        # Step 2: Do request to graphql to get line items.
        $getLineItems = new Oo_graphQLRequest( 'line_items', $order_id, $newPaseto, $args );
        # Step 3: Process the items to find shipping rates.
        error_log( '$getLineItems: ' . print_r( $getLineItems, true ) ). "\n";
        // Do Something here to process line items.
        # Step 4a: Get shipping rates from Woo.
        $zones = WC_Shipping_Zones::get_zones();
        $methods = array_column( $zones, 'shipping_methods' );
        foreach ( $methods[0] as $key => $class ) {
          $item = [
            "slug" => $class->id,
            "id" => $class->method_title,
            "name" => $class->title
          ];
          if ( isset( $class->instance_settings[ "cost" ] ) && $class->instance_settings[ "cost" ] > 0 ) {
            $item[ "price_excl" ] = number_format( $class->instance_settings[ "cost" ], 2, '.', '' );
            $item[ "price_incl" ] = number_format( $class->instance_settings[ "cost" ] / 100 * 24 + $class->instance_settings[ "cost" ], 2, '.', '' );
          }
          if ( isset( $class->min_amount ) && $class->min_amount > 0 )$item[ "minimum" ] = ( float )$class->min_amount;
          $data[] = $item;
        }
            /*
        $args[ 'shipping-rates' ] = array(
          ( object )array(
            "handle" => "economy-international-4.50",
            "title" => "Economy International",
            "amount" => 450
          ),
          ( object )array(
            "handle" => "express-international-15.0",
            "title" => "Express International",
            "amount" => 1500
          )
        );*/
       $args[ 'shipping-rates' ] = array();
       if(!empty($data)){
            foreach($data as $dkey => $dval){
              $args[ 'shipping-rates' ][] = (object) array(
                "handle" => $dval['slug'].'-'.$dval['price_excl'], //"economy-international-4.50",
                "title" => $dval['name'],
                "amount" => $dval['price_excl']*100,//450
              );  
            }
        }

        # Step 4b: Update shipping rates on GraphQL.
        $newPaseto2 = OneO_REST_DataController::create_paseto_from_request( $kid );
        $updateShipping = new Oo_graphQLRequest( 'update_ship_rates', $order_id, $newPaseto2, $args );
        error_log( '$updateShipping:  ' . print_r( $updateShipping, true ) ). "\n";
        # Step 5: If ok response, then return finishing repsponse to initial request.
        $processed = 'ok';
        break;
      case 'i_made_up_another_directive':
        #do_something_here;
        $processed = 'ok';
        break;
      case 'complete_order':
        # Step 1: Insert order into Woo
            // grab order data from GraphQL
            /*
            use this to query data:
            {order(id: "008aef35-d31b-4340-a0ee-b25a3718a672") {paymentStatus fulfillmentStatus customerName customerEmail customerPhone totalPrice totalTax totalShipping total lineItems {quantity price tax total currency productExternalId variantExternalId }}
            */
            $products = array(); // parse this from response
            
            // create order array
            // this is for testing right now - need to create from 1o response
            $products = array(
                "id" => '14',
                "qty" => 1
            );
            // insert into Woo & grab Woo order ID 
            $newOrderID = oneO_addWooOrder( $products, $email, $order_id );
        // Pass Woo order ID in external data 
        # Step 2: Create new Paseto for request.
        $newPaseto = OneO_REST_DataController::create_paseto_from_request( $kid );
        # Step 3: Do request to graphql to complete order.
        $args['external-data'] = array( 'WooID' => $newOrderID );
        if($newOrderID != '' && $newOrderID !== false){
            $args['fulfilled-status'] = 'FULFILLED';
        }else{
            $args['fulfilled-status'] = 'unknown-error';
        }
        $oORequest = new Oo_graphQLRequest( 'complete_order', $order_id, $newPaseto, $args );
        #echo '$oORequest: '. print_r($oORequest, true) . "\n";
        # Step 4: If ok response, then return finishing repsponse to initial request.
        if($oORequest !== false){
            $processed = 'ok';
        }else{
            $processed = 'error';
        }
        break;
    }
    $retArr[ 'status' ] = $processed;
    $retArr[ 'order_id' ] = $order_id;
    return ( object )$retArr;
  }

  /**
   * Creates PASETO Token for requests to GraphQL
   */
  public static function create_paseto() {
    // base 64 decode ss
    require_once( OOMP_LOC_CORE_INC . 'create-paseto.php' );
    $ss = OneO_REST_DataController::get_stored_secret();
    $pk = '{"kid":"' . OneO_REST_DataController::get_stored_public() . '"}';
    //echo $ss ."\n";
    $newPaseto = new Oo_create_paseto_token( $ss, $pk, 'P01Y' );
    $token = $newPaseto->get_signed_token();
    echo 'new token:' . $token . "\n";
    return $token;
  }

  public function create_paseto_request( $echo = true ) {
    require_once( OOMP_LOC_CORE_INC . 'create-paseto.php' );
    $ss = OneO_REST_DataController::get_stored_secret();
    $pk = '{"kid":"' . OneO_REST_DataController::get_stored_public() . '"}';
    $newPaseto = new Oo_create_paseto_token( $ss, $pk, 'P01Y' );
    $token = $newPaseto->get_signed_token();
    if ( $echo ) {
      echo 'new token:' . $token . "\n";
      die();
    } else {
      return $token;
    }
  }

  private function create_paseto_from_request( $kid ) {
    require_once( OOMP_LOC_CORE_INC . 'create-paseto.php' );
    $ss = OneO_REST_DataController::get_stored_secret();
    $newPaseto = new Oo_create_paseto_token( $ss, $kid, 'P01Y' );
    $token = $newPaseto->get_signed_token();
    return $token;
  }

  /**
   * Gets Directives
   *
   * @param $directives Get the Directives from the Request Body. May need to be sanitized.
   */
  public static function get_directives( $requestBody ) {
    if ( \is_array( $requestBody ) && !empty( $requestBody ) ) {
      $directives = isset( $requestBody[ 'directives' ] ) ? $requestBody[ 'directives' ] : false;
      $do_directives = array();
      $the_directives = array();
      if ( $directives !== false && !empty( $directives ) ) {
        foreach ( $directives as $dkey => $directive ) {
          $do_directives[ $directive[ 'directive' ] ] = $directive[ 'args' ];
        }
        if ( is_array( $do_directives ) && !empty( $do_directives ) ) {
          foreach ( $do_directives as $k => $v ) {
            $the_directives[ $k ] = $v;
          }
        }
        return $the_directives;
      } else {
        $error = new WP_Error( 'Error-103', 'Payload Directives empty. You must have at least one Directive.', 'API Error' );
        wp_send_json_error( $error, 403 );
      }
    } else {
      $error = new WP_Error( 'Error-104', 'Payload Directives not found in Request. You must have at least one Directive.', 'API Error' );
      wp_send_json_error( $error, 403 );
    }
  }

  public static function get_stored_secret() {
    $oneO_settings_options = get_option( 'oneO_settings_option_name', array() ); // Array of All Options
    $secret_key = isset( $oneO_settings_options[ 'secret_key' ] ) && $oneO_settings_options[ 'secret_key' ] != '' ? base64_decode( $oneO_settings_options[ 'secret_key' ] ) : ''; // Secret Key
    return $secret_key;
  }

  public static function get_stored_intid() {
    $oneO_settings_options = get_option( 'oneO_settings_option_name', array() ); // Array of All Options
    $int_id = isset( $oneO_settings_options[ 'integration_id' ] ) && $oneO_settings_options[ 'integration_id' ] != '' ? $oneO_settings_options[ 'integration_id' ] : ''; // Integration ID
    return $int_id;
  }

  public static function get_stored_public() {
    $oneO_settings_options = get_option( 'oneO_settings_option_name', array() ); // Array of All Options
    $public_key = isset( $oneO_settings_options[ 'public_key' ] ) && $oneO_settings_options[ 'public_key' ] != '' ? $oneO_settings_options[ 'public_key' ] : ''; // Public Key
    return $public_key;
  }

  public static function get_stored_endpoint() {
    return get_rest_url( null, OOMP_NAMESPACE ) . '/';
  }

  /**
   * Get our sample schema for a post.
   *
   * @return array The sample schema for a post
   */
  public function get_request_schema() {
    if ( $this->schema ) {
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
          'description' => esc_html__( 'Unique identifier for the object.', 'my-textdomain' ),
          'type' => 'array',
          #'context' => array( 'view', 'edit', 'embed' ),
          #'readonly' => true,
        ),
        'content' => array(
          'description' => esc_html__( 'The content for the object.', 'my-textdomain' ),
          'type' => 'string',
        ),
        'title' => array(
          'description' => esc_html__( 'The title for the object.', 'my-textdomain' ),
          'type' => 'string',
        ),
      ),
    );
    return $this->schema;
  }

}

// Function to register our new routes from the controller.
function prefix_register_my_rest_routes() {
  global $oneO_controller;
  $oneO_controller = new OneO_REST_DataController();
  $oneO_controller->register_routes();
}

add_action( 'rest_api_init', 'prefix_register_my_rest_routes' );

/* Helper function to validate exp of token */
function check_if_paseto_expired( $rawDecryptedToken ) {
  if ( \is_object( $rawDecryptedToken ) && isset( $rawDecryptedToken->exp ) ) {
    $checkTime = new\ DateTime( 'NOW' );
    $checkTime = $checkTime->format( \DateTime::ATOM );
    $tokenExp = new\ DateTime( $rawDecryptedToken->exp );
    $tokenExp = $tokenExp->format( \DateTime::ATOM );
    if ( $checkTime > $tokenExp ) {
      return true; // expired - do nothing else!!
    } else {
      return false; // trust token - not expired and has valid signature.
    }
  } else {
    return true;
  }
}

/* Helper Functions for Processing Token Footer */
function process_paseto_footer( $token ) {
  if ( $token == '' ) {
    return false;
  }
  if ( strpos( $token, 'Bearer ' ) !== false ) {
    $token = str_replace( 'Bearer ', '', $token );
  }
  $pieces = \explode( '.', $token );
  $count = \count( $pieces );
  if ( $count < 3 || $count > 4 ) {
    return false;
  }
  return $count > 3 && $pieces[ 3 ] != '' ? ParagonIE\ConstantTime\Base64UrlSafe::decode( $pieces[ 3 ] ) : false;
}

function get_paseto_footer_string( $footer ) {
  if ( !empty( $footer ) ) {
    if ( strpos( $footer, '{' ) === false ) {
      return $footer;
    } else {
      $jd_footer = json_decode( $footer );
      if ( is_object( $jd_footer ) && isset( $jd_footer->kid ) ) {
        return $jd_footer->kid;
      }
    }
  }
  return false;
}

/* other helper funsctions */
function oneO_get_stored_secret() {
  return OneO_REST_DataController::get_stored_secret();
}

function oneO_get_stored_intid() {
  return OneO_REST_DataController::get_stored_intid();
}

function oneO_get_stored_public() {
  return OneO_REST_DataController::get_stored_public();
}

function oneO_get_stored_endpoint() {
  return OneO_REST_DataController::get_stored_endpoint();
}

function get_oneO_options() {
  /* Get the options */
  $oneO_settings_options = get_option( 'oneO_settings_option_name', array() ); // Array of All Options
  $public_key = isset( $oneO_settings_options[ 'public_key' ] ) && $oneO_settings_options[ 'public_key' ] != '' ? $oneO_settings_options[ 'public_key' ] : ''; // Public Key
  $secret_key = isset( $oneO_settings_options[ 'secret_key' ] ) && $oneO_settings_options[ 'secret_key' ] != '' ? $oneO_settings_options[ 'secret_key' ] : ''; // Secret Key
  $int_id = isset( $oneO_settings_options[ 'integration_id' ] ) && $oneO_settings_options[ 'integration_id' ] != '' ? $oneO_settings_options[ 'integration_id' ] : ''; // Integration ID
  if ( !empty( $public_key ) && !empty( $secret_key ) && !empty( $int_id ) ) {
    $tempDB_IntegrationID_Call = array( $int_id => ( object )array( 'api_keys' => array( $public_key => $secret_key ) ), 'endpoint' => get_rest_url( null, OOMP_NAMESPACE ) . '/' );
  } else {
    $tempDB_IntegrationID_Call = array();
  }
  return $tempDB_IntegrationID_Call;
}

/*** SETTINGS PAGE  ***/

class oneO_Settings {

  private $oneO_settings_options;

  /**
   * Tell the plugin to creat a stand alone menu or an options submenu.
   *
   * @value 'stand-alone' - A separate settings menus in the Admin Menu.
   * @value 'options' - A submenu of "Settings" menu (default).
   */
  private $menu_type = 'options';

  public function __construct() {
    $this->menu_type = 'stand-alone';
    add_action( 'admin_menu', array( $this, 'oneO_settings_add_plugin_page' ) );
    add_action( 'admin_init', array( $this, 'oneO_settings_page_init' ) );
  }

  public function oneO_settings_add_plugin_page() {
    if ( 'stand-alone' === $this->menu_type ) {
      add_menu_page(
        '1o Settings',
        '1o Settings',
        'manage_options',
        '1o-settings',
        array( $this, 'oneO_settings_create_admin_page' ),
        OOMP_LOC_CORE_IMG . '1o-docs-logo.svg',
        80 // position
      );
    } else {
      add_options_page(
        '1o Settings',
        '1o Settings',
        'manage_options',
        '1o-settings',
        array( $this, 'oneO_settings_create_admin_page' )
      );
    }
  }

  public function oneO_settings_create_admin_page() {
    $this->oneO_settings_options = get_option( 'oneO_settings_option_name', array() );
    ?>
  <style>
.settings_unset .api_endpoint {
    display: none;
}
.settings-1o-nav {
    border-width: 1px 0;
    border-style: solid;
    border-color: #c3c4c7;
}
.settings-1o-nav li {
    display: inline-block;
    padding: .5rem;
    margin: 0;
    color: #1d2327;
    text-align: center;
}
.settings-1o-nav li.nav-1o-vesion-num {
    float: right;
    text-align: right;
    color: #9aa9b2;
}
.settings-1o-nav li a {
    text-decoration: none;
    color: #1d2327;
}
.settings-1o-nav li a:hover {
    text-decoration: underline;
}
.settings-form-1o h2 {
    display: none;
}
#endpoint_copy {
    text-decoration: none;
}
#endpoint_copy:hover {
    text-decoration: underline;
}
#secret_key-toggle {
    margin-left: -27px;
    color: #2271b1;
    cursor: pointer;
    margin-top: 4px;
}
.medium-text-input {
    width: 30em;
}
@media screen and (max-width:600px) {
.settings-1o-nav li {
    width: 49%;
    box-sizing: border-box;
}
.settings-1o-nav li.nav-1o-vesion-num {
    float: none !important;
    width: 100% !important;
    box-sizing: border-box;
    text-align: right;
}
}

@media screen and (max-width:400px) {
.settings-1o-nav li {
    width: 100%;
}
}
@media screen and (max-width: 782px){
.form-table input.regular-text.medium-text-input {
    width: 86%;
    display: inline-block;
}
.settings-1o-nav li{
    text-align:left;
}
}
</style>
  
<div class="wrap">
  <h2>1o Settings Page</h2>
  <?php settings_errors(); ?>
  <?php
  if ( isset( $_GET[ 'tab' ] ) ) {
    $active_tab = $_GET[ 'tab' ];
  }
  ?>
  <h2 class="nav-tab-wrapper"> <a href="?page=1o-settings&tab=setting" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a> <a href="?page=1o-settings&tab=getting_started" class="nav-tab <?php echo $active_tab == 'getting_started' ? 'nav-tab-active' : ''; ?>">Getting Started</a> </h2>
  <?php
  if ( $active_tab === 'getting_started' ) {
    ?>
  <p>Getting Started</p>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <?php
  } else {
    $pKey = isset( $this->oneO_settings_options[ 'public_key' ] ) && $this->oneO_settings_options[ 'public_key' ] != '' ? true : false;
    $ssKey = isset( $this->oneO_settings_options[ 'secret_key' ] ) && $this->oneO_settings_options[ 'secret_key' ] != '' ? true : false;
    $intId = isset( $this->oneO_settings_options[ 'integration_id' ] ) && $this->oneO_settings_options[ 'integration_id' ] != '' ? true : false;
    $setting_class = $pKey && $ssKey && $intId ? ' settings_set' : ' settings_unset';
    ?>
  <p>Enter your <strong>Integration ID</strong>, <strong>API Key</strong> and <strong>Shared Secret</strong> in the fields below. Log in to your 1o Admin console > Settings > Apps & Integrations, select Platforms tab, click WooCommerce and follow the instructions.</p>
  <form method="post" action="options.php" class="settings-form-1o<?php echo $setting_class; ?>">
    <?php settings_fields('oneO_settings_option_group'); ?>
    <?php do_settings_sections('oneO-settings-admin'); ?>
    <?php do_settings_sections('oneO-settings-admin-two'); ?>
    <?php submit_button(__('Save 1o Settings')); ?>
  </form>
  <?php
  }
  ?>
  <nav>
    <ul class="settings-1o-nav">
      <li><a href="#">Merchant Login</a></li>
      <li><a href="#">About 1o</a></li>
      <li><a href="#">Help Center</a></li>
      <li><a href="#">Terms</a></li>
      <li><a href="#">Privacy</a></li>
      <li><a href="#">Get In Touch</a></li>
      <li class="nav-1o-vesion-num">Version <?php echo OOMP_VER_NUM; ?></li>
    </ul>
  </nav>
  <?php #include_once(OOMP_LOC_CORE_INC . 'test.php'); ?>
</div>
<?php
}

  public function oneO_settings_page_init() {
  register_setting(
    'oneO_settings_option_group', // option_group
    'oneO_settings_option_name', // option_name
    array( $this, 'oneO_settings_sanitize' ) // sanitize_callback
  );

  add_settings_section(
    'oneO_settings_setting_section', // id
    'Settings', // title
    array( $this, 'oneO_settings_section_info' ), // callback
    'oneO-settings-admin' // page
  );
  add_settings_section(
    'oneO_settings_endpoint_section', // id
    'Endpoint', // title
    array( $this, 'oneO_settings_section_endpoint' ), // callback
    'oneO-settings-admin-two' // page
  );

  add_settings_field(
    'integration_id', // id
    'Integration ID', // title
    array( $this, 'integration_id_callback' ), // callback
    'oneO-settings-admin', // page
    'oneO_settings_setting_section' // section
  );

  add_settings_field(
    'public_key', // id
    'API Key', // title
    array( $this, 'public_key_callback' ), // callback
    'oneO-settings-admin', // page
    'oneO_settings_setting_section' // section
  );

  add_settings_field(
    'secret_key', // id
    'Shared Secret', // title
    array( $this, 'secret_key_callback' ), // callback
    'oneO-settings-admin', // page
    'oneO_settings_setting_section' // section
  );

  add_settings_field(
    'api_endpoint', // id
    'Store API Endpoint', // title
    array( $this, 'api_endpoint_callback' ), // callback
    'oneO-settings-admin-two', // page
    'oneO_settings_endpoint_section', // section
    array( 'class' => 'api_endpoint' ) // args
  );
}

  public function oneO_settings_sanitize( $input ) {
  $sanitary_values = array();
  if ( isset( $input[ 'integration_id' ] ) ) {
    $sanitary_values[ 'integration_id' ] = sanitize_text_field( $input[ 'integration_id' ] );
  }
  if ( isset( $input[ 'public_key' ] ) ) {
    $sanitary_values[ 'public_key' ] = sanitize_text_field( $input[ 'public_key' ] );
  }
  if ( isset( $input[ 'api_endpoint' ] ) ) {
    $sanitary_values[ 'api_endpoint' ] = sanitize_text_field( $input[ 'api_endpoint' ] );
  }
  if ( isset( $input[ 'secret_key' ] ) ) {
    $sanitary_values[ 'secret_key' ] = sanitize_text_field( $input[ 'secret_key' ] );
  }

  return $sanitary_values;
}

  public function oneO_settings_section_info() {}

  public function oneO_settings_section_endpoint() {}

  public function public_key_callback() {
  printf(
    '<input class="regular-text medium-text-input" type="text" autocomplete="1o-public-key" name="oneO_settings_option_name[public_key]" id="public_key" value="%s">',
    isset( $this->oneO_settings_options[ 'public_key' ] ) ? esc_attr( $this->oneO_settings_options[ 'public_key' ] ) : ''
  );
}

  public function secret_key_callback() {
  printf(
    '<input class="regular-text medium-text-input" type="password" autocomplete="1o-shared-secret" name="oneO_settings_option_name[secret_key]" id="secret_key" value="%s"><span id="secret_key-toggle" class="dashicons dashicons-visibility"></span>',
    isset( $this->oneO_settings_options[ 'secret_key' ] ) ? esc_attr( $this->oneO_settings_options[ 'secret_key' ] ) : ''
  );
  ?>
<script>
      const oneO_el = document.querySelector('#secret_key-toggle');
      const oneO_field = document.querySelector('#secret_key');
      const handleToggle = (event) => {
        const type = oneO_field.getAttribute("type") === "password" ? "text" : "password";
        const eye = oneO_el.getAttribute("class") == 'dashicons dashicons-visibility' ? 'dashicons dashicons-hidden' : 'dashicons dashicons-visibility';
        oneO_field.setAttribute("type", type);
        oneO_el.setAttribute("class", eye);
        event.preventDefault();
      }
      oneO_el.onclick = (event) => handleToggle(event);
      oneO_el.addEventListener('keyup', (event) => {
        if (event.keyCode === 13 || event.keyCode === 32) {
          handleToggle(event);
        }
      });
    </script>
<?php
}

  public function integration_id_callback() {
  printf(
    '<input class="regular-text medium-text-input" type="text" autocomplete="1o-integration-id" name="oneO_settings_option_name[integration_id]" id="integration_id" value="%s">',
    isset( $this->oneO_settings_options[ 'integration_id' ] ) ? esc_attr( $this->oneO_settings_options[ 'integration_id' ] ) : ''
  );
}
    
  public function api_endpoint_callback() {
  $endpoint = isset( $this->oneO_settings_options[ 'api_endpoint' ] ) ? esc_attr( $this->oneO_settings_options[ 'api_endpoint' ] ) : '';
  $endpoint = $endpoint != '' ? $endpoint : get_rest_url( null, OOMP_NAMESPACE );
  echo '<input class="regular-text medium-text-input" type="text" autocomplete="none" name="oneO_settings_option_name[api_endpoint]" id="api_endpoint" value="' . $endpoint . '" disabled>&nbsp;&nbsp;<a href="#" id="endpoint_copy">Copy</a>';
  echo '<p class="description" id="api_endpoint-description">Copy this URL to your account integration settings on 1o.</p>';
  echo '<script>';
  echo "document.querySelector('#endpoint_copy').addEventListener('click', function(e){ 
      var copyText = document.querySelector('#api_endpoint');
      var copyLink = document.querySelector('#endpoint_copy');
      copyText.disabled = false;
      copyText.focus();
      copyText.select();
      document.execCommand('copy');
      copyText.blur();
      copyText.disabled = true;
      copyLink.innerHTML = 'Copied!';
      console.log(copyText.textContent);
      e.preventDefault();
   });";
  echo '</script>';
}
}

if ( is_admin() )
  $oneO_settings = new oneO_Settings();

/* 
 * Retrieve this value with:
 * $oneO_settings_options = get_option( 'oneO_settings_option_name' ); // Array of All Options
 * $public_key = $oneO_settings_options['public_key']; // Public Key
 * $secret_key = $oneO_settings_options['secret_key']; // Secret Key
 * $integration_id = $oneO_settings_options['integration_id']; // Integration ID
 */


function oneO_addWooOrder( $products, $email, $orderid ) {
    global $current_user;
    $random_password = wp_generate_password( $length = 12, $include_standard_special_chars = false ); 
    $user = email_exists( $user_email ) !== false ? get_user_by('login', $email) : wp_create_user( $email, $random_password, $email );
    $order =   wc_create_order();
    foreach($products as $product){
        $prod = get_product($product['id']);
        $order->add_product($prod, $product['qty']);
    }
    /*
    $order->add_product( $product, $quantity, [
        'subtotal'     => $custom_price_for_this_order, // e.g. 32.95
        'total'        => $custom_price_for_this_order, // e.g. 32.95
    ] );
    */
    $order->calculate_totals();
    /*
    $fname          =    get_user_meta( $current_user->ID, 'first_name', true );
    $lname          =    get_user_meta( $current_user->ID, 'last_name', true );
    $email          =    $current_user->user_email;
    $address_1      =    get_user_meta( $current_user->ID, 'billing_address_1', true );
    $address_2      =    get_user_meta( $current_user->ID, 'billing_address_2', true );
    $city           =    get_user_meta( $current_user->ID, 'billing_city', true );
    $postcode       =    get_user_meta( $current_user->ID, 'billing_postcode', true );
    $country        =    get_user_meta( $current_user->ID, 'billing_country', true );
    $state          =    get_user_meta( $current_user->ID, 'billing_state', true );

    $billing_address    =   array(
        'first_name' => $fname,
        'last_name'  => $lname,
        'email'      => $email,
        'address_1'  => $address_1,
        'address_2'  => $address_2,
        'city'       => $city,
        'state'      => $state,
        'postcode'   => $postcode,
        'country'    => $country,
    );
    $address = array(
        'first_name' => $fname,
        'last_name'  => $lname,
        'email'      => $email,
        'address_1'  => $address_1,
        'address_2'  => $address_2,
        'city'       => $city,
        'state'      => $state,
        'postcode'   => $postcode,
        'country'    => $country,
    );

    $shipping_cost = 5;
    $shipping_method = 'Fedex';
    $order->add_shipping($shipping_cost);
    $order->set_address($billing_address,'billing');
    $order->set_address($address,'shipping');
    $order->set_payment_method('check');//
    $order->shipping_method_title = $shipping_method;
    $order->calculate_totals();
    */
    $order->update_status('completed', 'added by 1o - order:'.$orderid);
    $order->save();
    return $order->get_id();
}
