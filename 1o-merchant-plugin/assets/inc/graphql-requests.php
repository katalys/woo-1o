<?php
/* Plugin Class for Graph QL requests */
class Oo_graphQLRequest
{
  var $theRequest;
  /**
   * $args hold external data & shipping rates and anything else needed later on.
   */
  public function __construct($requestType, $orderId, $authCode, $args)
  {
    $allowedRequests = array(
      'line_items',
      'order_data',
      'update_ship_rates',
      'complete_order',
      'import_product',
    );
    if (!isset($requestType) || $requestType == '' || !in_array($requestType, $allowedRequests)) {
      /* Error response for 1o */
      $error = new WP_Error('Error-402', 'Cannot Process Directive - improper request type.', 'API Error');
      wp_send_json_error($error, 403);
    }
    if ($orderId == '') {
      /* Error response for 1o */
      $error = new WP_Error('Error-401', 'Cannot Process Directive - Order Id is blank.', 'API Error');
      wp_send_json_error($error, 403);
    }
    $fulfillStatus = isset($args['fulfilled-status']) ? $args['fulfilled-status'] : 'unknown';
    $externalData = isset($args['external-data']) ? $args['external-data'] : '';
    $shippingRates = isset($args['shipping-rates']) ? $args['shipping-rates'] : '';
    $prodImportData = isset($args['product_to_import']) ? $args['product_to_import'] : '';
    $prodImportUrl = isset($args['product_url']) ? $args['product_url'] : '';

    /* Set up Query Array */
    $queryArray = array();
    $queryArray['line_items'] = 'query Q {order(id: "' . $orderId . '") {shippingAddressLine_1 shippingAddressLine_2 shippingAddressCity shippingAddressSubdivision shippingAddressSubdivisionCode shippingAddressCountry shippingAddressCountryCode shippingAddressZip lineItems { quantity price tax total currency productExternalId variantExternalId}}}';
    $queryArray['order_data'] = 'query Q {order( id: "' . $orderId . '" ) {externalData billingName billingPhone billingEmail billingAddressCity billingAddressSubdivision billingAddressSubdivisionCode billingAddressLine_1 billingAddressLine_2 billingAddressCountry billingAddressCountryCode billingAddressZip chosenShippingRateHandle currency customerName customerEmail customerPhone fulfillmentStatus lineItems{quantity price tax total currency productExternalId variantExternalId} merchantName paymentStatus shippingName shippingPhone shippingEmail shippingAddressLine_1 shippingAddressLine_2 shippingAddressCity shippingAddressSubdivision shippingAddressSubdivisionCode shippingAddressCountry shippingAddressCountryCode shippingAddressZip total totalPrice totalShipping totalTax transactions{id name}}}';
    $queryArray['update_ship_rates'] = 'mutation M($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id shippingRates{handle amount title}}}';
    $queryArray['complete_order'] = 'mutation CompleteOrder($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id fulfillmentStatus externalData}}';
    $queryArray['import_product'] = 'mutation CP($input: ProductInput!) {CreateProduct(input: $input) {id}}';

    if ($requestType == '' || !isset($queryArray[$requestType])) {
      /* Error response for 1o */
      $error = new WP_Error('Error-403', 'Cannot Process Directive - Blank or not in allowed list.', 'API Error');
      wp_send_json_error($error, 403);
    }

    $data = $queryArray[$requestType];

    /* $queryURL is dynamic based on store keys */
    $queryURL = OOMP_GRAPHQL_URL;
    $variables = '';
    $contentType = 'application/graphql';
    switch ($requestType) {
      case 'update_ship_rates_old':
        $variables = ((object) array("id" => $orderId, "shippingRates" => $shippingRates));
        $contentType = 'application/json';
        break;
      case 'complete_order_old':
        $externalData = json_encode($externalData);
        $variables = ((object) array("id" => $orderId, "fulfillmentStatus" => $fulfillStatus, "externalData" => $externalData));
        $contentType = 'application/json';
        break;
      case 'update_ship_rates':
        $variables = ((object) array("id" => $orderId, "input" => (object) array("shippingRates" => $shippingRates)));
        $contentType = 'application/json';
        break;
      case 'complete_order':
        $externalData = json_encode($externalData);
        $variables = ((object) array("id" => $orderId, "input" => (object) array("fulfillmentStatus" => $fulfillStatus, "externalData" => $externalData)));
        $contentType = 'application/json';
        break;
      case 'import_product':
        $prodData = $prodImportData;
        $variables = ((object) array("input" => $prodData));
        $contentType = 'application/json';
        break;
    }

    /* Process out the body for request to GraphQL */
    if ($variables != '') {
      $data = json_encode((object) array("query" => $data, "variables" => $variables));
    }

    /**
     * Main Request
     * Using native wp_remote_get
     */
    $response = wp_remote_get(
      $queryURL,
      array(
        'method' => 'POST',
        'headers' => array(
          'Content-Type' => $contentType,
          'User-Agent' => '1o WordPress API: ' . get_bloginfo('url') . '|' . $orderId,
          'Authorization' => 'Bearer ' . $authCode,
        ),
        'body' => $data,
      )
    );

    /* Set Request variable and return request success (bool) */
    if (!is_wp_error($response) && ($response['response']['code'] === 200 || $response['response']['code'] === 201)) {
      $body = wp_remote_retrieve_body($response);
      $this->theRequest = json_decode($body);
      return true;
    } else {
      return false;
    }
  }

  /**
   * Get the request. This is the best way to do this and maintain the 
   * data integrity across different functions.
   */
  public function get_request()
  {
    return $this->theRequest;
  }
}
