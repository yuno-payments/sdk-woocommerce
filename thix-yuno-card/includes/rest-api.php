<?php
if (!defined('ABSPATH')) exit;

/**
 * =========================
 * Helpers
 * =========================
 */

function thix_yuno_get_env($key, $default = '') {

  // 0) Woo settings
  $settings = get_option('woocommerce_thix_yuno_card_settings', []);
  if (is_array($settings)) {
    $map = [
      'ACCOUNT_CODE'        => 'account_code',
      'PUBLIC_API_KEY'      => 'public_api_key',
      'PRIVATE_SECRET_KEY'  => 'private_secret_key',
      'ENVIRONMENT'         => 'environment',
      'DEBUG'               => 'debug',
    ];
    if (isset($map[$key])) {
      $k = $map[$key];
      if (!empty($settings[$k])) return $settings[$k];
    }
  }

  // 1) getenv
  $v = getenv($key);
  if ($v !== false && $v !== null && $v !== '') return $v;

  // 2) wp-config.php define()
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
      'raw' => $resp->get_error_message(),
    ];
  }

  $status = wp_remote_retrieve_response_code($resp);
  $rawBody = wp_remote_retrieve_body($resp);
  $json = json_decode($rawBody, true);

  return [
    'ok' => ($status >= 200 && $status < 300),
    'status' => $status,
    'raw' => $json ?? $rawBody,
  ];
}

function thix_yuno_amount_minor_units($total, $currency) {
  $currency = strtoupper((string)$currency);
  $total = (float)$total;

  $zero_decimal = ['COP', 'CLP', 'JPY'];
  if (in_array($currency, $zero_decimal, true)) {
    return (int) round($total);
  }
  return (int) round($total * 100);
}

function thix_yuno_get_order_from_request(WP_REST_Request $request) {
  if (!class_exists('WooCommerce')) {
    return [null, thix_yuno_json(['error' => 'WooCommerce not active'], 500)];
  }

  $params = $request->get_json_params();
  $order_id  = isset($params['order_id']) ? absint($params['order_id']) : 0;
  $order_key = isset($params['order_key']) ? wc_clean(wp_unslash($params['order_key'])) : '';

  if (!$order_id) {
    return [null, thix_yuno_json(['error' => 'Missing order_id'], 400)];
  }

  $order = wc_get_order($order_id);
  if (!$order) {
    return [null, thix_yuno_json(['error' => 'Order not found', 'order_id' => $order_id], 404)];
  }

  // Validate order_key (recommended)
  if ($order_key && method_exists($order, 'get_order_key')) {
    if ($order->get_order_key() !== $order_key) {
      return [null, thix_yuno_json(['error' => 'Invalid order_key'], 403)];
    }
  }

  return [$order, null];
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

  register_rest_route('thix-yuno/v1', '/confirm', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => 'thix_yuno_confirm_order_payment',
  ]);
});

/**
 * =========================
 * Create Checkout Session (order-based)
 * body: { order_id, order_key? }
 * =========================
 */
function thix_yuno_create_checkout_session(WP_REST_Request $request) {
  $accountCode = thix_yuno_get_env('ACCOUNT_CODE', '');
  $publicKey   = thix_yuno_get_env('PUBLIC_API_KEY', '');
  $secretKey   = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

  if (!$accountCode || !$publicKey || !$secretKey) {
    return thix_yuno_json([
      'error' => 'Missing required keys',
      'has_ACCOUNT_CODE' => (bool)$accountCode,
      'has_PUBLIC_API_KEY' => (bool)$publicKey,
      'has_PRIVATE_SECRET_KEY' => (bool)$secretKey,
    ], 400);
  }

  [$order, $err] = thix_yuno_get_order_from_request($request);
  if ($err) return $err;

  $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

  $country  = $order->get_billing_country() ?: 'CO';
  $currency = $order->get_currency() ?: 'COP';
  $total    = (float) $order->get_total();
  $value    = thix_yuno_amount_minor_units($total, $currency);

  $payload = [
    'account_id' => $accountCode,
    'merchant_order_id' => 'WC-' . $order->get_id(),
    'payment_description' => 'WooCommerce Order #' . $order->get_id(),
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

  $checkoutSession = is_array($res['raw'])
    ? ($res['raw']['checkout_session'] ?? $res['raw']['id'] ?? null)
    : null;

  if (!$checkoutSession) {
    return thix_yuno_json([
      'error' => 'Yuno response missing checkout_session/id',
      'response' => $res['raw'],
    ], 400);
  }

  $order->update_meta_data('_thix_yuno_checkout_session', $checkoutSession);
  $order->save();

  return thix_yuno_json([
    'checkout_session' => $checkoutSession,
    'country' => $country,
    'order_id' => $order->get_id(),
  ], 200);
}

/**
 * =========================
 * Create Payment
 * body: { oneTimeToken, checkoutSession, order_id, order_key? }
 * =========================
 */
function thix_yuno_create_payment(WP_REST_Request $request) {
  $accountCode = thix_yuno_get_env('ACCOUNT_CODE', '');
  $publicKey   = thix_yuno_get_env('PUBLIC_API_KEY', '');
  $secretKey   = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

  if (!$accountCode || !$publicKey || !$secretKey) {
    return thix_yuno_json(['error' => 'Missing required keys'], 400);
  }

  [$order, $err] = thix_yuno_get_order_from_request($request);
  if ($err) return $err;

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

  $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

  $country  = $order->get_billing_country() ?: 'CO';
  $currency = $order->get_currency() ?: 'COP';
  $total    = (float) $order->get_total();
  $value    = thix_yuno_amount_minor_units($total, $currency);

  $payload = [
    'description' => 'WooCommerce Payment #' . $order->get_id(),
    'account_id' => $accountCode,
    'merchant_order_id' => 'WC-' . $order->get_id(),
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
    $order->add_order_note('Yuno payment failed: ' . (is_string($res['raw']) ? $res['raw'] : wp_json_encode($res['raw'])));
    $order->update_status('failed');
    $order->save();

    return thix_yuno_json([
      'error' => 'Yuno create payment failed',
      'status' => $res['status'],
      'response' => $res['raw'],
    ], 400);
  }

  $payment_id = is_array($res['raw'])
    ? ($res['raw']['id'] ?? $res['raw']['payment_id'] ?? null)
    : null;

  if ($payment_id) $order->update_meta_data('_thix_yuno_payment_id', $payment_id);
  $order->update_meta_data('_thix_yuno_payment_raw', $res['raw']);
  $order->save();

  return thix_yuno_json([
    'ok' => true,
    'payment_id' => $payment_id,
    'response' => $res['raw'],
  ], 200);
}

/**
 * =========================
 * Confirm (update Woo order status)
 * body: { order_id, order_key?, status, payment_id? }
 * =========================
 */
function thix_yuno_confirm_order_payment(WP_REST_Request $request) {
  [$order, $err] = thix_yuno_get_order_from_request($request);
  if ($err) return $err;

  $params = $request->get_json_params();
  $status = isset($params['status']) ? strtoupper((string)$params['status']) : 'UNKNOWN';
  $payment_id = $params['payment_id'] ?? $order->get_meta('_thix_yuno_payment_id');

  if (in_array($status, ['SUCCEEDED','VERIFIED'], true)) {
    $order->payment_complete($payment_id ?: '');
    $order->add_order_note('Yuno approved. status=' . $status . ' payment_id=' . ($payment_id ?: 'N/A'));
    $order->save();

    return thix_yuno_json([
      'ok' => true,
      'order_id' => $order->get_id(),
      'new_status' => $order->get_status(),
      'redirect' => $order->get_checkout_order_received_url(),
    ], 200);
  }

  if (in_array($status, ['REJECTED','DECLINED','CANCELLED','ERROR','EXPIRED'], true)) {
    $order->update_status('failed', 'Yuno: ' . $status);
    $order->add_order_note('Yuno rejected. status=' . $status . ' payment_id=' . ($payment_id ?: 'N/A'));
    $order->save();

    return thix_yuno_json([
      'ok' => false,
      'order_id' => $order->get_id(),
      'new_status' => $order->get_status(),
    ], 200);
  }

  $order->add_order_note('Yuno status: ' . $status);
  $order->save();

  return thix_yuno_json([
    'ok' => true,
    'order_id' => $order->get_id(),
    'status' => $status,
  ], 200);
}
