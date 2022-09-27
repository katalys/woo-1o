<?php
namespace KatalysMerchantPlugin;

use ParagonIE\Paseto\Keys\SymmetricKey;
use ParagonIE\Paseto\Protocol\Version2;

/**
 * Hook to register our new routes from the controller with WordPress.
 */
add_action('rest_api_init', function() {
    $oneO_controller = new OneO_REST_DataController();
    $oneO_controller->register_routes();
});

class OneO_REST_DataController
{
    private static $log = [];

    /**
     * Controller log function - if turned on
     */
    public static function set_controller_log($name = '', $logged = null)
    {
        if ($logged != null) {
            self::$log[$name] = $logged;
        }
    }

    public function process_controller_log()
    {
        if (!empty(self::$log)) {
            error_log('controller log:' . "\n" . print_r(self::$log, true));
            self::$log = [];
        }
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

    /**
     * Register namespace Routes with WordPress for 1o Plugin to use.
     */
    public function register_routes($namespace = OOMP_NAMESPACE)
    {
        register_rest_route($namespace, '/(?P<integrationId>[A-Za-z0-9\-]+)', array(
            array(
                'methods' => array('GET', 'POST'),
                'callback' => array($this, 'get_request'),
                'permission_callback' => array($this, 'get_request_permissions_check'),
            ),
            'schema' => array($this, 'get_request_schema'),
        ));
        /* temp - to create PASETOs on demand */
        register_rest_route($namespace . '-create', '/create-paseto', array(
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
        OneO_REST_DataController::set_controller_log('Body from 1o', print_r($requestBody, true));
        if (empty($directives) || !is_array($directives)) {
            /* Error response for 1o */
            $error = new WP_Error('Error-103', 'Payload Directives empty. You must have at least one Directive.', 'API Error');
            wp_send_json($error);
        }

        if ($token === false || $token === '') {
            /* Error response for 1o */
            $error = new WP_Error('Error-100', 'No Token Provided', 'API Error');
            wp_send_json($error);
        }

        $options = get_oneO_options();
        $apiEnd = isset($options['endpoint']) && $options['endpoint'] != '' ? $options['endpoint'] : false;
        $integrationId = $request['integrationId'];

        if (empty($integrationId)) {
            /* Error response for 1o */
            $error = new WP_Error('Error-102', 'No Integraition ID Provided', 'API Error');
            wp_send_json($error);
        } else {
            $v2Token = str_replace('Bearer ', '', $token);
            $footer = process_paseto_footer($token);
            $footerString = get_paseto_footer_string($footer);
            $_secret = isset($options[$integrationId]->api_keys[$footerString]) ? $options[$integrationId]->api_keys[$footerString] : null;
            if ($_secret == '' || is_null($_secret)) {
                /* Error response for 1o */
                $error = new WP_Error('Error-200', 'Integraition ID does not match IDs on file.', 'API Error');
                wp_send_json($error);
            } else {
                // key exists and can be used to decrypt.
                $res_Arr = array();
                $key = new SymmetricKey(base64_decode($_secret));
                $decryptedToken = Version2::decrypt($v2Token, $key, $footer);
                $rawDecryptedToken = json_decode($decryptedToken);
                if (check_if_paseto_expired($rawDecryptedToken)) {
                    /* Error response for 1o */
                    $error = new WP_Error('Error-300', 'PASETO Token is Expired.', 'API Error');
                    wp_send_json($error);
                } else {
                    // valid - move on & process request
                    if (!empty($directives) && is_array($directives)) {

                        foreach ($directives as $directive) {
                            OneO_REST_DataController::set_controller_log('=======' . $directive . '======', print_r($directive, true));
                            $res_Arr[] = OneO_REST_DataController::process_directive($directive, $footer);
                        }
                    }
                }
            }
            $out = array("results" => $res_Arr);
            $results = $out;
            OneO_REST_DataController::set_controller_log('$results from request to 1o', print_r($results, true));
            self::process_controller_log();
            wp_send_json($results);
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

    public static function process_directive($directive = array(), $kid = '')
    {
        $return_arr = array();
        $return_arr["integration_id"] = OneO_REST_DataController::get_stored_intid();
        $return_arr["endpoint"] = OneO_REST_DataController::get_stored_endpoint();
        $processed = OneO_REST_DataController::process_directive_function($directive['directive'], $directive['args'], $kid);
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

    public static function process_directive_function($directive, $args, $kid)
    {
        require_once OOMP_LOC_PATH . '/graphql-requests.php';
        $processed = null;
        $args = isset($args) ? $args : array();
        $order_id = isset($args['order_id']) ? esc_attr($args['order_id']) : null;
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
                    $args = OneO_REST_DataController::create_a_cart($order_id, $kid, $args, 'tax_amt');
                } else {
                    $args['tax_amt'] = $taxAmt;
                }
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
                    $retArr['data'] = (object) array(
                        'healthy' => true,
                        'internal_error' => null,
                        'public_error' => null,
                    );
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
                        $retArr["external_id"] = (string) $productId; //product ID
                        $retArr["shop_url"] = $prodURL; //This is the PRODUCT URL (not really the shop URL)
                        $retArr["images"] = OneO_REST_DataController::get_product_images($product, $productId);
                        //$retArr['sku'] = $product->get_sku();
                        //TODO: SKU needs to be added on 1o end still.
                        $options = OneO_REST_DataController::get_product_options($product);
                        $retArr["option_names"] = $options['group'];
                        $retArr["variant"] = false; //bool
                        $retArr["variants"] = array(); //empty array (no variants)
                        //$retArr["available"] = $product->is_in_stock();
                        //TODO: Product Availability Boolean needs to be added on 1o end still.
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
                        //$retArr["available"] = $product->is_in_stock();
                        //TODO: Product Availability Boolean needs to be added on 1o end still.
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
                $args = OneO_REST_DataController::create_a_cart($order_id, $kid, $args, '');

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
                $args = OneO_REST_DataController::create_a_cart($order_id, $kid, $args, 'items_avail');

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

    public static function create_a_cart($order_id, $kid, $args, $type = '')
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
            foreach ($lines as $line) {
                $product_id = $line->productExternalId;
                $quantity = $line->quantity;
                WC()->cart->add_to_cart($product_id, $quantity);

                $productTemp = new WC_Product_Factory();
                $product = $productTemp->get_product($product_id);
                $availability = $product->is_in_stock();
                $args['items_avail'][] = (object) array(
                    "id" => $line->id,
                    "available" => $availability,
                );
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
        require_once OOMP_LOC_PATH . '/create-paseto.php';
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
        require_once OOMP_LOC_PATH . '/create-paseto.php';
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
        require_once OOMP_LOC_PATH . '/create-paseto.php';
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
            OneO_REST_DataController::set_controller_log('directives in get_directives()', print_r($directives, true));
            if ($directives !== false && !empty($directives)) {
                return $directives;
            } else {
                $error = new WP_Error('Error-103', 'Payload Directives empty. You must have at least one Directive.', 'API Error');
                wp_send_json($error);
            }
        } else {
            $error = new WP_Error('Error-104', 'Payload Directives not found in Request. You must have at least one Directive.', 'API Error');
            wp_send_json($error);
        }
        return $directives;
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