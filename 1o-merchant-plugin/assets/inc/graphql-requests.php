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
      'complete_order',
      'health_check',
      'import_product',
      'line_items',
      'order_data',
      'update_ship_rates',
      'update_tax_amount',
      'update_availability',
    );

    /**
     * Missing Data / Error Checks - Do these first as there is no sense of continuing if there is missing items.
     */
    if (OOMP_GRAPHQL_URL == '') {
      // no endpoint set for requests - cannot continue.
      /* Error response for 1o */
      $error = new WP_Error('Plugin Error-400', 'Cannot Process Directive - User needs to set proper GraphQL Endpoint.', 'API Error');
      wp_send_json_error($error, 500);
    }

    if (!isset($requestType) || $requestType == '') {
      /* Error response for 1o */
      $error = new WP_Error('Plugin Error-401', 'Cannot Process Directive - Blank or Missing Directive.', 'API Error');
      wp_send_json_error($error, 500);
    }

    if (!in_array($requestType, $allowedRequests)) {
      /* Error response for 1o */
      $error = new WP_Error('Plugin Error-402', 'Cannot Process Directive - improper request type.', 'API Error');
      wp_send_json_error($error, 500);
    }

    if ($orderId == '' && $requestType != 'health_check') {
      /* Error response for 1o */
      $error = new WP_Error('Plugin Error-403', 'Cannot Process Directive - Order Id is blank but required for ' . $requestType . ' directive.', 'API Error');
      wp_send_json_error($error, 500);
    }

    /**
     * End Missing Data / Error Checks 
     */

    /* $queryURL is dynamic based on store keys */
    $queryURL = OOMP_GRAPHQL_URL;
    $data = '';
    $variables = '';
    $contentType = 'application/graphql';

    switch ($requestType) {
      case 'update_ship_rates_old':
        $data = 'mutation M($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id shippingRates{handle amount title}}}';
        $shippingRates = isset($args['shipping-rates']) ? $args['shipping-rates'] : '';
        $variables = ((object) array("id" => $orderId, "shippingRates" => $shippingRates));
        $contentType = 'application/json';
        break;
      case 'complete_order_old':
        $data = 'mutation CompleteOrder($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id fulfillmentStatus externalData}}';
        $fulfillStatus = isset($args['fulfilled-status']) ? $args['fulfilled-status'] : 'unknown';
        $externalData = isset($args['external-data']) ? $args['external-data'] : '';
        $externalData = json_encode($externalData);
        $variables = ((object) array("id" => $orderId, "fulfillmentStatus" => $fulfillStatus, "externalData" => $externalData));
        $contentType = 'application/json';
        break;
      case 'health_check':
        $data = 'query {healthCheck}';
        break;
      case 'line_items':
        $data = 'query Q {order(id: "' . $orderId . '") {shippingAddressLine_1 shippingAddressLine_2 shippingAddressCity shippingAddressSubdivision shippingAddressSubdivisionCode shippingAddressCountry shippingAddressCountryCode shippingAddressZip lineItems { quantity price tax currency productExternalId variantExternalId}}}';
        break;
      case 'order_data':
        $data = 'query Q {order( id: "' . $orderId . '" ) {externalData billingName billingPhone billingEmail billingAddressCity billingAddressSubdivision billingAddressSubdivisionCode billingAddressLine_1 billingAddressLine_2 billingAddressCountry billingAddressCountryCode billingAddressZip chosenShippingRateHandle currency customerName customerEmail customerPhone fulfillmentStatus lineItems{quantity price tax currency productExternalId variantExternalId} merchantName paymentStatus shippingName shippingPhone shippingEmail shippingAddressLine_1 shippingAddressLine_2 shippingAddressCity shippingAddressSubdivision shippingAddressSubdivisionCode shippingAddressCountry shippingAddressCountryCode shippingAddressZip totalPrice totalShipping total totalTax transactions{id name}}}';
        break;
      case 'update_ship_rates':
        $totalTax = (int) (isset($args['tax_amt']) ? str_replace('.', '', $args['tax_amt']) : '0');
        $data = 'mutation M($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id shippingRates{handle amount title} totalTax}}';
        $shippingRates = isset($args['shipping-rates']) ? $args['shipping-rates'] : '';
        $variables = ((object) array("id" => $orderId, "input" => (object) array("shippingRates" => $shippingRates, "totalTax" => $totalTax)));
        $contentType = 'application/json';
        break;
      case 'update_availability':
        $data = 'mutation UpdateAvailability($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id lineItems{id available}}}';
        $lineItems = isset($args['items_avail']) ? $args['items_avail'] : '';
        $variables = ((object) array("id" => $orderId, "input" => (object) array("lineitems" => $lineItems)));
        $contentType = 'application/json';
        break;
      case 'complete_order':
        $data = 'mutation CompleteOrder($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id fulfillmentStatus externalData}}';
        $fulfillStatus = isset($args['fulfilled-status']) ? $args['fulfilled-status'] : 'unknown';
        $externalData = isset($args['external-data']) ? $args['external-data'] : '';
        $externalData = json_encode($externalData);
        $variables = ((object) array("id" => $orderId, "input" => (object) array("fulfillmentStatus" => $fulfillStatus, "externalData" => $externalData)));
        $contentType = 'application/json';
        break;
      case 'import_product':
        $data = 'mutation CP($input: ProductInput!) {CreateProduct(input: $input) {id}}';
        $prodImportUrl = isset($args['product_url']) ? $args['product_url'] : '';
        $prodData = isset($args['product_to_import']) ? $args['product_to_import'] : '';
        $variables = ((object) array("input" => $prodData));
        $contentType = 'application/json';
        break;
      case 'update_tax_amount':
        $totalTax = (int) (isset($args['tax_amt']) ? str_replace('.', '', $args['tax_amt']) : '0');
        OneO_REST_DataController::set_controller_log('TEST:$totalTax', print_r($totalTax, true));
        $lineTax = isset($args['tax_amt_lines']) ? $args['tax_amt_lines'] : false;
        $data = 'mutation UpdateTaxAmounts($id: ID!, $input: OrderInput!) {updateOrder(id: $id, input: $input) {totalTax}}';
        OneO_REST_DataController::set_controller_log('TEST:$data', print_r($data, true));
        $variables = ((object) array("id" => $orderId, "input" => (object) array("totalTax" => $totalTax)));
        OneO_REST_DataController::set_controller_log('TEST:$variables', print_r($variables, true));
        $contentType = 'application/json';
        break;
    }

    /**
     * Empty Data - Error
     */
    if ($data == '') {
      /* Error response for 1o */
      $error = new WP_Error('Plugin Error-404', 'Cannot Process Directive - Response Data is Blank.', 'API Error');
      wp_send_json_error($error, 500);
    }

    /* Process out the body for request from or response to GraphQL */
    if ($variables != '') {
      $data = json_encode((object) array("query" => $data, "variables" => $variables));
      OneO_REST_DataController::set_controller_log('{type: ' . $requestType . '}+variables', print_r($data, true));
    }

    /**
     * Main Request - Using native wp_remote_get
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
    OneO_REST_DataController::set_controller_log('TEST:wp_remote_get{$orderId:' . $orderId . '}' . $queryURL . ':' . print_r($data, true));

    /* Set Request variable and return request success (bool) */
    if (!is_wp_error($response) && ($response['response']['code'] === 200 || $response['response']['code'] === 201)) {
      $body = wp_remote_retrieve_body($response);
      $this->theRequest = json_decode($body);
      OneO_REST_DataController::set_controller_log('TEST:response[type: ' . $requestType . ']', print_r($this->theRequest, true));
      return true;
    } else {
      OneO_REST_DataController::set_controller_log('ERROR:response[type: ' . $requestType . ']', print_r($response, true));
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
