<?php
namespace KatalysMerchantPlugin;
use ParagonIE\Paseto\Keys\SymmetricKey;
use ParagonIE\Paseto\Protocol\Version2;
use WP_Error;

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
            'permission_callback' => '__return_true',
        ],
        'schema' => [$self, 'get_request_schema'],
    ]);
    /* temp - to create PASETOs on demand */
    register_rest_route($namespace . '-create', '/create-paseto', [
        [
            'methods' => ['GET'],
            'callback' => [$self, 'handle_paseto_request'],
            'permission_callback' => '__return_true',
        ],
        'schema' => [$self, 'get_request_schema'],
    ]);
  }

  /**
   * Get Authorization Header from headers for verifying Paseto token.
   *
   * @return array(string, string) Token and decoded footer
   */
  private static function get_token_from_headers()
  {
    $token = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
      $token = $_SERVER["HTTP_AUTHORIZATION"];
    } elseif (isset($_SERVER['Authorization'])) {
      $token = $_SERVER["Authorization"];
    } elseif (function_exists('apache_request_headers')) {
      $requestHeaders = apache_request_headers();
      $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));

      if (isset($requestHeaders['Authorization'])) {
        $token = $requestHeaders['Authorization'];
      }
    } else {
      //todo look for '1o-bearer-token', 'bearer' headers?
    }

    if (!$token) {
      return ['', ''];
    }
    $token = sanitize_text_field($token);
    $token = trim($token);
    if (stripos($token, 'Bearer ') === 0) {
      $token = substr($token, 7);
    }
    $footer = paseto_decode_footer($token);
    return [$token, $footer];
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
    list($token, $footer) = self::get_token_from_headers();
    if (!$token) {
      return new WP_Error('Error-100', 'No token provided', ['status' => 403]);
    }
    if (!$footer) {
      return new WP_Error('Error-200', 'Invalid token footer', ['status' => 400]);
    }

    $options = oneO_options();
    $footerString = paseto_footer_kid($footer);
    $integrationId = $request->get_param('integrationId');
    if (!$footerString) {
      return new WP_Error('Error-200', 'No KID extracted from token', ['status' => 400]);
    }
    if ($options->publicKey != $footerString) {
      return new WP_Error('Error-200', 'PublicKey does not match IDs on file.', ['status' => 403]);
    }
    if (empty($integrationId)) {
      return new WP_Error('Error-102', 'No Integration ID Provided', ['status' => 400]);
    }
    if ($options->integrationId != $integrationId) {
      return new WP_Error('Error-200', 'IntegrationID does not match IDs on file.', ['status' => 403]);
    }
    if (!$options->secretKey) {
      return new WP_Error('Error-200', 'SecretID is empty', ['status' => 403]);
    }

    $requestBody = $request->get_json_params();
    if (!$requestBody || !is_array($requestBody)) {
      return new WP_Error('Error-103', 'Payload Directives not found in Request. You must have at least one Directive.', ['status' => 400]);
    }
    if (empty($requestBody['directives'])) {
      return new WP_Error('Error-104', 'Payload Directives empty. You must have at least one Directive.', ['status' => 400]);
    }
    $directives = $requestBody['directives'];
    log_debug('directives in get_directives()', $directives);

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
      try {
        $resultsArray[] = self::process_directive($directive, $footer);
      } catch (\Throwable $e) {
        $resultsArray[] = [
            'source_id' => $directive['id'], // directive id
            'source_directive' => $directive['directive'], // directive name
            'status' => 'failed', // "failed" means exception/crash, while "error" means business-logic problem
            'data' => [
                'message' => $e->getMessage(),
                'details' => $e->getTraceAsString(),
            ],
        ];
      }
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
    $result = isset($processed->result) ? $processed->result : null;
    $error = isset($processed->error) ? $processed->error : null;

    $return_arr = [
        'source_id' => $directive['id'], // directive id
        'source_directive' => $directive['directive'], // directive name
        'status' => $status, // ok or error message
    ];
    if ($order_id != null) {
      $return_arr["order_id"] = $order_id; // order_id if present
    }
    if ($data != null) {
      $return_arr["data"] = $data;
    }
    if ($result != null) {
      $return_arr["result"] = $result;
    }
    if ($error != null) {
      $return_arr["error"] = $error;
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
