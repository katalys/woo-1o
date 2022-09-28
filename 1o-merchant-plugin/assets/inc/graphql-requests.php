<?php
namespace KatalysMerchantPlugin;
use Exception;

/**
 * API proxy for GraphQL requests to the 1o system.
 */
class Oo_graphQLRequest
{
  /** @var string */
  private $authCode;

  /**
   * @param $kid
   * @return Oo_graphQLRequest
   * @throws \ParagonIE\Paseto\Exception\InvalidKeyException
   * @throws \ParagonIE\Paseto\Exception\InvalidPurposeException
   * @throws \ParagonIE\Paseto\Exception\PasetoException
   */
  static function fromKid($kid)
  {
    $ss = base64_decode(get_oneO_options()->secretKey);
    $token = paseto_create_token($ss, $kid, OOMP_PASETO_EXP);
    return new self($token);
  }

  /**
   * @param string $authCode
   */
  public function __construct($authCode)
  {
    $this->authCode = $authCode;
  }

  /**
   * @param array $request
   * @return mixed
   * @throws GraphQLException
   */
  public function rawGraphQl(array $request)
  {
    // https://playground.1o.io/graphql // GraphQL URL for 1o
    $endpoint = get_oneO_options()->graphqlEndpoint;

    if (!$endpoint) {
      throw new GraphQLException('Cannot Process Directive - Admin must set GraphQL Endpoint', 400);
    }
    if (empty($request['query'])) {
      throw new GraphQLException("Unknown request", 401);
    }
    // many methods require an ID field -- if defined but empty, this is an error condition
    if (isset($request['variables']) && array_key_exists('id', $request['variables']) && !$request['variables']['id']) {
      throw new GraphQLException("Cannot Process Directive - Order Id is blank but required", 402);
    }

    /**
     * Main Request - Using native wp_remote_get
     */
    $response = wp_remote_get(
        $endpoint,
        [
            'method' => 'POST',
            'headers' => [
                'content-type' => 'application/json',
                'user-agent' => '1o WordPress API: ' . get_bloginfo('url'),
                'authorization' => "Bearer {$this->authCode}",
            ],
            'body' => $request,
        ]
    );

    if (is_wp_error($response)) {
      throw new GraphQLException($response->get_error_message(), 404);
    }
    $responseCode = $response['response']['code'];
    if ($responseCode != 200 && $responseCode != 201) {
      throw new GraphQLException("Response code: $responseCode", 405);
    }

    $body = wp_remote_retrieve_body($response);
    return json_decode($body);
  }

  /**
   * @throws GraphQLException
   */
  public function api_health_check()
  {
    return $this->rawGraphQl([
        'query' => 'query {healthCheck}'
    ]);
  }

  public function api_line_items($orderId)
  {
    return $this->rawGraphQL([
        'query' => 'query Q {order(id: ' . json_encode("$orderId", JSON_UNESCAPED_SLASHES) . ') {'
            . 'shippingAddressLine_1 shippingAddressLine_2 shippingAddressCity shippingAddressSubdivision shippingAddressSubdivisionCode shippingAddressCountry shippingAddressCountryCode shippingAddressZip'
            . ' lineItems { id quantity price tax currency productExternalId variantExternalId }'
            . ' }}',
    ]);
  }

  public function api_order_data($orderId)
  {
    return $this->rawGraphQL([
        'query' => 'query Q {order(id: ' . json_encode("$orderId", JSON_UNESCAPED_SLASHES) . ') {'
            . 'externalData billingName billingPhone billingEmail billingAddressCity billingAddressSubdivision billingAddressSubdivisionCode billingAddressLine_1 billingAddressLine_2 billingAddressCountry billingAddressCountryCode billingAddressZip chosenShippingRateHandle currency customerName customerEmail customerPhone fulfillmentStatus'
            . ' lineItems{quantity price tax currency productExternalId variantExternalId}'
            . ' merchantName paymentStatus shippingName shippingPhone shippingEmail shippingAddressLine_1 shippingAddressLine_2 shippingAddressCity shippingAddressSubdivision shippingAddressSubdivisionCode shippingAddressCountry shippingAddressCountryCode shippingAddressZip totalPrice totalShipping total totalTax'
            . ' transactions{id name}'
            . ' }}',
    ]);
  }

  public function api_update_ship_rates($orderId, array $args)
  {
    return $this->rawGraphQL([
        'query' => 'mutation M($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id shippingRates{handle amount title} totalTax}}',
        'variables' => [
            'id' => $orderId,
            'input' => [
                'shippingRates' => isset($args['shipping-rates']) ? $args['shipping-rates'] : '',
                'totalTax' => isset($args['tax_amt']) ? (int)str_replace('.', '', $args['tax_amt']) : 0,
            ],
        ],
    ]);
  }

  public function api_update_availability($orderId, array $args)
  {
    return $this->rawGraphQL([
        'query' => 'mutation UpdateAvailability($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id lineItems{id available}}}',
        'variables' => [
            'id' => $orderId,
            'input' => [
                'lineItems' => isset($args['items_avail']) ? $args['items_avail'] : '',
            ],
        ],
    ]);
  }

  /**
   * @param string $orderId
   * @param array $args
   * @return mixed
   * @throws GraphQLException
   */
  public function api_complete_order($orderId, array $args)
  {
    $fulfillStatus = isset($args['fulfilled-status']) ? $args['fulfilled-status'] : 'unknown';
    $externalData = isset($args['external-data']) ? $args['external-data'] : '';
    return $this->rawGraphQL([
        'query' => 'mutation CompleteOrder($id: ID!, $input: OrderInput!){updateOrder(id: $id, input: $input){id fulfillmentStatus externalData}}',
        'variables' => [
            'id' => $orderId,
            'input' => [
                'fulfillmentStatus' => $fulfillStatus,
                'externalData' => json_encode($externalData, JSON_UNESCAPED_SLASHES),
            ],
        ],
    ]);
  }

  /**
   * @param array $args
   * @return mixed
   * @throws GraphQLException
   */
  public function api_import_product(array $args)
  {
//    $prodImportUrl = isset($args['product_url']) ? $args['product_url'] : '';
    $prodData = isset($args['product_to_import']) ? $args['product_to_import'] : '';
    return $this->rawGraphQL([
        'query' => 'mutation CP($input: ProductInput!) {CreateProduct(input: $input) {id}}',
        'variables' => [
            "input" => $prodData,
        ],
    ]);
  }

  public function api_update_tax_amount($orderId, array $args)
  {
    $totalTax = isset($args['tax_amt']) ? str_replace('.', '', $args['tax_amt']) : 0;
//    $lineTax = isset($args['tax_amt_lines']) ? $args['tax_amt_lines'] : false;
    return $this->rawGraphQL([
        'query' => 'mutation UpdateTaxAmounts($id: ID!, $input: OrderInput!) {updateOrder(id: $id, input: $input) {totalTax}}',
        'variables' => [
            'id' => $orderId,
            'input' => [
                "totalTax" => (int)$totalTax,
            ],
        ],
    ]);
  }
}

/**
 * Subclass exception for GraphQL requests.
 */
class GraphQLException extends Exception
{

}