<?php
namespace KatalysMerchantPlugin;
use ParagonIE\Paseto\Keys\SymmetricKey;
use ParagonIE\Paseto\Protocol\Version2;

/**
 * Class that routes incoming REST calls to functions.
 */
class ApiController
{
  /**
   * Register namespace Routes with WordPress for 1o Plugin to use.
   */
  public static function register_routes($namespace = null)
  {
    if (!$namespace || !is_string($namespace)) {
      $namespace = OOMP_NAMESPACE;
    }
    $self = new static();
    register_rest_route($namespace, '/(?P<integrationId>[A-Za-z0-9\-]+)', [
        [
            'methods' => ['GET', 'POST'],
            'callback' => [$self, 'handle_request'],
            'permission_callback' => [$self, 'handle_request_permissions_check'],
        ],
        'schema' => [$self, 'get_request_schema'],
    ]);
    /* temp - to create PASETOs on demand */
    register_rest_route($namespace . '-create', '/create-paseto', [
        [
            'methods' => ['GET'],
            'callback' => [$self, 'handle_paseto_request'],
            'permission_callback' => [$self, 'handle_request_permissions_check'],
        ],
        'schema' => [$self, 'get_request_schema'],
    ]);
  }

  /**
   * Check permissions for the posts. Basic check for Bearer Token.
   * Called by REST router.
   *
   * @return true|WP_Error
   */
  public function handle_request_permissions_check()
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
  private static function get_token_from_headers()
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

  /**
   * Handle incoming REST request.
   * Called by WordPress REST router.
   *
   * @param WP_REST_Request $request
   * @return array[]|WP_Error
   * @throws \ParagonIE\Paseto\Exception\PasetoException
   * @throws \SodiumException
   */
  public function handle_request($request)
  {
    $token = self::get_token_from_headers();
    if (!$token) {
      return new WP_Error('Error-100', 'No Token Provided', ['status' => 403]);
    }

    $requestBody = $request->get_json_params();
    if (!$requestBody || !is_array($requestBody)) {
      return new WP_Error('Error-104', 'Payload Directives not found in Request. You must have at least one Directive.', ['status' => 400]);
    }
    if (empty($requestBody['integrationId'])) {
      return new WP_Error('Error-102', 'No Integration ID Provided', ['status' => 400]);
    }
    if (empty($requestBody['directives'])) {
      return new WP_Error('Error-103', 'Payload Directives empty. You must have at least one Directive.', ['status' => 400]);
    }

    $directives = $requestBody['directives'];
    $integrationId = $requestBody['integrationId'];
    log_debug('directives in get_directives()', $directives);

    $options = oneO_options();
    $footer = paseto_decode_footer($token);
    $footerString = paseto_footer_kid($footer);
    if ($options->publicKey != $footerString) {
      return new WP_Error('Error-200', 'PublicKey does not match IDs on file.', ['status' => 403]);
    }
    if ($options->integrationId != $integrationId) {
      return new WP_Error('Error-200', 'IntegrationID does not match IDs on file.', ['status' => 403]);
    }
    if (!$options->secretKey) {
      return new WP_Error('Error-200', 'SecretID is empty', ['status' => 403]);
    }

    // key exists and can be used to decrypt.
    $key = new SymmetricKey(base64_decode($options->secretKey));
    $decryptedToken = Version2::decrypt($token, $key, $footer);
    $rawDecryptedToken = json_decode($decryptedToken);
    if (paseto_is_expired($rawDecryptedToken)) {
      return new WP_Error('Error-300', 'PASETO Token is Expired.', ['status' => 403]);
    }

    // valid - move on & process request
    $resultsArray = [];
    foreach ($directives as $directive) {
      log_debug("======= $directive ======");
      $resultsArray[] = self::process_directive($directive, $footer);
    }

    log_debug('$results from request to 1o', $resultsArray);
    return ['results' => $resultsArray];
  }

  /**
   * Creates PASETO Token for requests to GraphQL
   * Called by WordPress REST Router.
   */
  public function handle_paseto_request()
  {
    $ss = base64_decode(oneO_options()->secretKey);
    $pk = json_encode(['kid' => oneO_options()->publicKey], JSON_UNESCAPED_SLASHES);
    return [
        'token' => paseto_create_token($ss, $pk),
    ];
  }

  private static function process_directive($directive = [], $kid = '')
  {
    $runner = new ApiDirectives($kid);
    $processed = (object)$runner->_process($directive['directive'], $directive['args']);

    $status = isset($processed->status) ? $processed->status : 'unknown';
    $order_id = isset($processed->order_id) ? $processed->order_id : null;
    $data = isset($processed->data) ? $processed->data : null;

    $return_arr = [
        'integration_id' => oneO_options()->integrationId,
        'endpoint' => oneO_options()->endpoint,
        'source_id' => $directive['id'], // directive id
        'source_directive' => $directive['directive'], // directive name
        'status' => $status, // ok or error or error message
    ];
    if ($order_id != null) {
      $return_arr["order_id"] = $order_id; // order_id if present
    }
    if ($data != null) {
      $return_arr["data"] = $data;
    }

    return $return_arr;
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
