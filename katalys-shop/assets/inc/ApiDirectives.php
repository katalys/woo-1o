<?php
namespace KatalysMerchantPlugin;

/**
 * Class holding execution and routing for all available directives.
 */
class ApiDirectives
{
  /** @var string Default response */
  const OK = 'ok';

  private $kid;
  private $order_id;
  private $args;

  public function __construct($kid = '')
  {
    $this->kid = $kid;
  }

  /**
   * @param string $directive
   * @param array $args
   * @return array
   */
  public function _process($directive, $args)
  {
    $this->args = $args ?: [];
    $this->order_id = !empty($args['order_id']) ? $args['order_id'] : null;

    $methodName = 'directive__' . strtolower($directive);
    if (!preg_match('#^\w+$#', $methodName)) {
      throw new \Exception("Invalid methodName: $methodName");
    }
    if (!method_exists($this, $methodName)) {
      throw new \Exception("No directive handler for: $directive");
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
    return $ret;
  }

  /**
   * Shortcut to create a GraphQL Request object.
   * @return GraphQLRequest
   */
  private function _gqlRequest()
  {
    return GraphQLRequest::fromKid($this->kid);
  }

  public function directive__update_tax_amounts()
  {
    $taxAmt = get_transient($this->order_id . '_taxamt');
    if ($taxAmt == '') {
      // calculate
      $args = oneO_create_cart($this->order_id, $this->kid, $this->args, 'tax_amt');
    } else {
      $args = $this->args;
      $args['tax_amt'] = $taxAmt;
    }
    $this->_gqlRequest()->api_update_tax_amount($this->order_id, $args);
  }

  public function directive__health_check()
  {
    # Step 2: Do Health Check Request
    $oORequest = $this->_gqlRequest()->api_health_check();
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
        $retArr["images"] = self::import__get_product_images($product);
        //$retArr['sku'] = $product->get_sku();
        //TODO: SKU needs to be added on 1o end still.
        $options = self::import__product_options($product);
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
        $retArr["images"] = self::import__get_product_images($product);
        //$retArr['sku'] = $product->get_sku();
        //TODO: SKU needs to be added on 1o end still.
        $options = self::import__product_options($product);
        $retArr["option_names"] = $options['group'];
        $retArr["variant"] = false; //bool
        $variants = $product->get_available_variations();
        $processedVariants = [];
        if (is_array($variants) && !empty($variants)) {
          $processedVariants = self::import__process_variants($variants, $options['names'], $retArr["title"], $retArr["currency"], $retArr["currency_sign"]);
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
      $oORequest = $this->_gqlRequest()->api_import_product(/*$args['product_url'],*/ $args);
      log_debug('process_directive: import_product_from_url', $oORequest);
    }

    if ($oORequest && !$processed) {
      $retArr['status'] = self::OK;
    } elseif (is_null($processed)) {
      $retArr['status'] = 'error';
      $retArr['error'] = ''; //TODO << message to show to user on the screen
    }
    return $retArr;
  }


  /**
   * Get array of options from a product.
   *
   * @param object $product :product object from WooCommerce
   * @return array $optGroup  :array of product options
   */
  private static function import__product_options($product)
  {
    $optGroup = [];
    $optList = [];
    $optList2 = [];

    if (is_object($product)) {
      $options = $product->get_attributes('view');
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
    return [
        'group' => $optGroup,
        'list' => $optList,
        'names' => $optList2,
    ];
  }

  /**
   * Get array of images from a product.
   *
   * @param object $product :product object from WooCommerce
   * @return array $images    :array of images
   */
  private static function import__get_product_images($product)
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

  private static function import__process_variants($variants = [], $optionNames = [], $productTitle = '', $currency = '', $currencySign = '')
  {
    $processedVariants = [];
    //TODO: check if is a variant ((bool)$variants['vatiants'], I think )
    if (is_array($variants) && !empty($variants)) {
      $pv = 0;
      foreach ($variants as $variant) {
        if (isset($variant['variation_is_active']) && $variant['variation_is_active'] && isset($variant['variation_is_visible']) && $variant['variation_is_visible']) {
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


  public function directive__update_available_shipping_rates()
  {
    log_debug('process_directive: update_available_shipping_rates', '[$kid]:' . $this->kid . ' | [order_id]:' . $this->order_id);
    $args = oneO_create_cart($this->order_id, $this->kid, $this->args);

    # Step 4: Update shipping rates on GraphQL.
    $req = $this->_gqlRequest();
    $req->api_update_ship_rates($this->order_id, $args);
    log_debug('update_ship_rates', $req);
  }

  public function directive__update_availability()
  {
    log_debug('process_directive: update_availability', '[$kid]:' . $this->kid . ' | [order_id]:' . $this->order_id);
    $args = oneO_create_cart($this->order_id, $this->kid, $this->args, 'items_avail');

    # Update Availability on GraphQL.
    $req = $this->_gqlRequest();
    $req->api_update_availability($this->order_id, $args);
    log_debug('update_availability', $req);
  }

  public function directive__complete_order()
  {
    # Step 2: Get new order data from 1o - in case anything changed
    $args = $this->args;
    $req = $this->_gqlRequest();
    $result = $req->api_order_data($this->order_id);
    log_debug('process_directive: $getOrderData', $req);

    # Step 3: prepare order data for Woo import
    $orderData = self::process_order_data($result);
    // insert into Woo & grab Woo order ID
    $newOrderID = oneO_addWooOrder($orderData, $this->order_id);
    if ($newOrderID === false) {
      return 'exists';
    }

    # Step 3b: Do request to graphql to complete order.
    // Pass Woo order ID in external data
    $args['external-data'] = ['WooID' => $newOrderID];
    if ($newOrderID) {
      $args['fulfilled-status'] = 'FULFILLED';
    } else {
      $args['fulfilled-status'] = 'unknown-error';
    }
    $req->api_complete_order($this->order_id, $args);

    return self::OK;
  }

  /**
   * Process 1o order data for insert into easy array for insert into WC
   *
   * @param array $orderData :Array of order data from 1o
   * @return array $returnArr   :Array of processed order data or false if none.
   */
  private static function process_order_data($orderData)
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
      return [
          'products' => $products,
          'order' => $order,
          'customer' => $customer,
          'billing' => $billing,
          'shipping' => $shipping,
          'transactions' => $transact,
      ];
    }
    return false;
  }
}
