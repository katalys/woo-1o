<?php
/* Plugin Class for Graph QL requests */
class Oo_graphQLRequest
{
  /**
   * $args hold external data & shipping rates and anything else needed later on.
   */
  public function __construct($requestType, $orderId, $authCode, $args)
  {
    if ($orderId == '') {
      /* Error response for 1o */
      $error = new WP_Error('Error-401', 'Cannot Process Directive - Order Id is blank.', 'API Error');
      wp_send_json_error($error, 403);
    }
    $fulfillStatus = isset($args['fulfilled-status']) ? json_encode($args['fulfilled-status']) : 'unknown';
    $externalData = isset($args['external-data']) ? addslashes(json_encode($args['external-data'])) : '';
    $shippingRates = isset($args['shipping-rates']) ? json_encode($args['shipping-rates']) : '';

    /* Set up Query Array */
    $queryArray = array();
    $queryArray['line_items'] = 'query Q {order(id: "' . $orderId . '") {lineItems { quantity price tax total currency productExternalId variantExternalId}}}';
    $queryArray['update_ship_rates'] = 'mutation m($id: ID!, $shippingRates: [ShippingRateInput]) {updateOrder(id: $id, shippingRates: $shippingRates) {id __typename shippingRates {handle title amount}}}';
    $queryArray['complete_order'] = 'mutation M($id: ID!, $fulfillmentStatus: OrderFulfillment, $externalData: JsonString) {updateOrder(id: $id, fulfillmentStatus: $fulfillmentStatus, externalData: $externalData) {id fulfillmentStatus externalData}}';

    if ($requestType == '' || !isset($queryArray[$requestType])) {
      /* Error response for 1o */
      $error = new WP_Error('Error-403', 'Cannot Process Directive - Blank or not in allowed list.', 'API Error');
      wp_send_json_error($error, 403);
    }

    $data = $queryArray[$requestType];

    /* $queryURL is dynamic based on store keys */
    $queryURL = '';
    switch ($requestType) {
      case 'line_items':
        $queryURL = OOMP_GRAPHQL_URL;
        break;
      case 'update_ship_rates':
        $queryURL = OOMP_GRAPHQL_URL . '?variables={"id":"' . $orderId . '","shippingRates":' . $shippingRates . '}';
        break;
      case 'complete_order':
        $queryURL = OOMP_GRAPHQL_URL . '?variables={"id":"' . $orderId . '","fulfillmentStatus":"' . $fulfillStatus . '","externalData":"' . $externalData . '"}';
        break;
      default:
        $queryURL = OOMP_GRAPHQL_URL;
        break;
    }

    if (OOMP_ERROR_LOG)
      error_log('$queryURL[' . $requestType . ']:  ' . print_r($queryURL, true)) . "\n";

    $response = wp_remote_get(
      $queryURL,
      array(
        'method' => 'POST',
        'headers' => array(
          'Content-Type' => 'application/graphql',
          'user-agent' => '1o WordPress API: ' . get_bloginfo('url') . '|' . $orderId,
          'Authorization' => 'Bearer ' . $authCode,
        ),
        'body' => ($data),
      )
    );

    if (OOMP_ERROR_LOG)
      error_log('body: ' . print_r($response['body'], true)) . "\n";

    /* Response needs to be specific for this request */
    if ($requestType == 'update_ship_rates') {
      header("HTTP/1.1 200 OK");
      if (OOMP_ERROR_LOG)
        error_log('update_ship_rates: ' . print_r($shippingRates, true)) . "\n";
      echo $shippingRates;
      exit;
      return 'ok';
    } else {
      if (OOMP_ERROR_LOG)
        error_log('$response:  ' . print_r($response, true)) . "\n";
      if (!is_wp_error($response) && ($response['response']['code'] === 200 || $response['response']['code'] === 201)) {
        return json_encode($response['body']);
      } else {
        return json_encode($response);
      }
    }
  }
}
