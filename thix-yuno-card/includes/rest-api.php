<?php
if (!defined('ABSPATH')) exit;

/**
 * =========================
 * Helpers
 * =========================
 */

function thix_yuno_get_env($key, $default = '') {
  $v = getenv($key);
  if ($v !== false && $v !== null && $v !== '') return $v;

  if (defined($key) && constant($key) !== '') return constant($key);

  return $default;
}

function thix_yuno_api_url_from_public_key($publicKey) {
  $prefix = explode('_', (string)$publicKey)[0] ?? '';

  $map = [
    'dev'     => '-dev',
    'staging' => '-staging',
    'sandbox' => '-sandbox',
    'prod'    => '',
  ];

  $suffix = $map[$prefix] ?? '-sandbox';
  return "https://api{$suffix}.y.uno";
}

function thix_yuno_json($data, $status = 200) {
  return new WP_REST_Response($data, $status);
}

function thix_yuno_wp_remote_json($method, $url, $headers = [], $body = null, $timeout = 30) {
  $args = [
    'method'  => strtoupper($method),
    'headers' => $headers,
    'timeout' => $timeout,
  ];

  if ($body !== null) {
    $args['body'] = is_string($body) ? $body : wp_json_encode($body);
  }

  $resp = wp_remote_request($url, $args);

  if (is_wp_error($resp)) {
    return [
      'ok' => false,
      'status' => 0,
      'error' => $resp->get_error_message(),
      'raw' => null,
    ];
  }

  $status = wp_remote_retrieve_response_code($resp);
  $rawBody = wp_remote_retrieve_body($resp);
  $json = json_decode($rawBody, true);

  return [
    'ok' => ($status >= 200 && $status < 300),
    'status' => $status,
    'error' => ($status >= 200 && $status < 300) ? null : $rawBody,
    'raw' => $json ?? $rawBody,
  ];
}

/**
 * =========================
 * Routes
 * =========================
 */

add_action('rest_api_init', function () {

  register_rest_route('thix-yuno/v1', '/public-api-key', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function () {
      $publicApiKey = thix_yuno_get_env('PUBLIC_API_KEY', '');
      return thix_yuno_json(['publicApiKey' => $publicApiKey], 200);
    },
  ]);

  register_rest_route('thix-yuno/v1', '/checkout-session', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => 'thix_yuno_create_checkout_session',
  ]);

  register_rest_route('thix-yuno/v1', '/payments', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => 'thix_yuno_create_payment',
  ]);
});

/**
 * =========================
 * Create Checkout Session (MVP from cart)
 * POST /thix-yuno/v1/checkout-session
 * =========================
 */
function thix_yuno_create_checkout_session(WP_REST_Request $request) {
  $accountCode = thix_yuno_get_env('ACCOUNT_CODE', '');
  $publicKey   = thix_yuno_get_env('PUBLIC_API_KEY', '');
  $secretKey   = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

  if (!$accountCode || !$publicKey || !$secretKey) {
    return thix_yuno_json([
      'error' => 'Missing required env vars',
      'has_ACCOUNT_CODE' => (bool)$accountCode,
      'has_PUBLIC_API_KEY' => (bool)$publicKey,
      'has_PRIVATE_SECRET_KEY' => (bool)$secretKey,
    ], 400);
  }

  $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

  $country  = 'CO';
  $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'COP';

  // Cart total
  $total = 2000;
  if (function_exists('WC') && WC()->cart) {
    $raw = WC()->cart->get_total('edit'); // string (may come formatted)
    $total = (float) $raw;
    if ($total <= 0) $total = 2000;
  }

  // For COP usually no cents
  $value = (int) round($total);

  $payload = [
    'account_id' => $accountCode,
    'merchant_order_id' => (string) time(),
    'payment_description' => 'WooCommerce Cart Payment',
    'country' => $country,
    'amount' => [
      'currency' => $currency,
      'value' => $value,
    ],
  ];

  $res = thix_yuno_wp_remote_json(
    'POST',
    "{$apiUrl}/v1/checkout/sessions",
    [
      'public-api-key' => $publicKey,
      'private-secret-key' => $secretKey,
      'Content-Type' => 'application/json',
    ],
    $payload,
    30
  );

  if (!$res['ok']) {
    return thix_yuno_json([
      'error' => 'Yuno create checkout session failed',
      'status' => $res['status'],
      'apiUrl' => $apiUrl,
      'payload' => $payload,
      'response' => $res['raw'],
    ], 400);
  }

  $checkoutSession = $res['raw']['checkout_session'] ?? $res['raw']['id'] ?? null;

  if (!$checkoutSession) {
    return thix_yuno_json([
      'error' => 'Yuno response did not include checkout_session/id',
      'response' => $res['raw'],
    ], 400);
  }

  return thix_yuno_json([
    'checkout_session' => $checkoutSession,
    'country' => $country,
  ], 200);
}

/**
 * =========================
 * Create Payment (MVP)
 * POST /thix-yuno/v1/payments
 * body: { oneTimeToken, checkoutSession }
 * =========================
 */
function thix_yuno_create_payment(WP_REST_Request $request) {
  $accountCode = thix_yuno_get_env('ACCOUNT_CODE', '');
  $publicKey   = thix_yuno_get_env('PUBLIC_API_KEY', '');
  $secretKey   = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

  if (!$accountCode || !$publicKey || !$secretKey) {
    return thix_yuno_json(['error' => 'Missing required env vars'], 400);
  }

  $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

  $params = $request->get_json_params();
  $oneTimeToken    = $params['oneTimeToken'] ?? null;
  $checkoutSession = $params['checkoutSession'] ?? null;

  if (!$oneTimeToken || !$checkoutSession) {
    return thix_yuno_json([
      'error' => 'Missing oneTimeToken or checkoutSession',
      'hasOneTimeToken' => (bool)$oneTimeToken,
      'hasCheckoutSession' => (bool)$checkoutSession,
    ], 400);
  }

  $country  = 'CO';
  $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'COP';

  $total = 2000;
  if (function_exists('WC') && WC()->cart) {
    $raw = WC()->cart->get_total('edit');
    $total = (float) $raw;
    if ($total <= 0) $total = 2000;
  }

  $value = (int) round($total);

  $payload = [
    'description' => 'WooCommerce Cart Payment',
    'account_id' => $accountCode,
    'merchant_order_id' => (string) time(),
    'country' => $country,
    'amount' => [
      'currency' => $currency,
      'value' => $value,
    ],
    'checkout' => [
      'session' => $checkoutSession,
    ],
    'payment_method' => [
      'token' => $oneTimeToken,
      'vaulted_token' => null,
    ],
  ];

  $res = thix_yuno_wp_remote_json(
    'POST',
    "{$apiUrl}/v1/payments",
    [
      'public-api-key' => $publicKey,
      'private-secret-key' => $secretKey,
      'X-idempotency-key' => wp_generate_uuid4(),
      'Content-Type' => 'application/json',
    ],
    $payload,
    30
  );

  if (!$res['ok']) {
    return thix_yuno_json([
      'error' => 'Yuno create payment failed',
      'status' => $res['status'],
      'apiUrl' => $apiUrl,
      'payload' => $payload,
      'response' => $res['raw'],
    ], 400);
  }

  return thix_yuno_json([
    'ok' => true,
    'response' => $res['raw'],
  ], 200);
}
