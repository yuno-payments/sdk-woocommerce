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

      // ✅ Split
      'SPLIT_ENABLED'       => 'split_enabled',
      'YUNO_RECIPIENT_ID'   => 'yuno_recipient_id',
      'SPLIT_FIXED_AMOUNT'  => 'split_fixed_amount',
    ];
    if (isset($map[$key])) {
      $k = $map[$key];
      // ojo: en checkbox el valor suele ser 'yes'/'no'. Permitimos 'no' como válido.
      if (array_key_exists($k, $settings) && $settings[$k] !== '') return $settings[$k];
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

/**
 * ✅ WooCommerce is source of truth for decimals.
 * Uses Woo setting: WooCommerce → Settings → General → Number of decimals
 */
function thix_yuno_get_wc_price_decimals() {
  $d = get_option('woocommerce_price_num_decimals', 2);
  $d = is_numeric($d) ? (int) $d : 2;
  if ($d < 0) $d = 0;
  if ($d > 6) $d = 6; // safety cap
  return $d;
}

function thix_yuno_amount_minor_units($total, $currency) {
  // currency kept for future; decimals come from Woo settings
  $total = (float) $total;
  $decimals = thix_yuno_get_wc_price_decimals();
  $mult = pow(10, $decimals);
  return (int) round($total * $mult);
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
 * Build a STABLE idempotency key per order + checkout session.
 * Prevents duplicate charges even if frontend retries.
 */
function thix_yuno_build_idempotency_key($order_id, $checkout_session) {
  $order_id = (int) $order_id;
  $checkout_session = (string) ($checkout_session ?? '');

  $base = $order_id . '|' . $checkout_session . '|' . get_site_url();
  $hash = wp_hash($base, 'auth');

  return 'wc-' . $order_id . '-yuno-' . substr($hash, 0, 24);
}

function thix_yuno_debug_enabled() {
  $dbg = (string) thix_yuno_get_env('DEBUG', 'no');
  return ($dbg === 'yes' || $dbg === '1' || $dbg === 'true');
}

/**
 * ✅ WooCommerce logger helpers
 * WooCommerce → Status → Logs → source: thix-yuno
 */
function thix_yuno_logger() {
  static $logger = null;
  if ($logger === null && function_exists('wc_get_logger')) {
    $logger = wc_get_logger();
  }
  return $logger;
}

function thix_yuno_log($level, $message, $context = []) {
  if (!thix_yuno_debug_enabled()) return;
  $logger = thix_yuno_logger();
  if (!$logger) return;

  $ctx = array_merge(['source' => 'thix-yuno'], (array)$context);
  $logger->log($level, $message . ' ' . wp_json_encode($ctx), ['source' => 'thix-yuno']);
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

  // Guardrail: if already paid, do not create sessions again
  if ($order->is_paid()) {
    return thix_yuno_json([
      'error' => 'Order already paid',
      'order_id' => $order->get_id(),
      'status' => $order->get_status(),
      'redirect' => $order->get_checkout_order_received_url(),
    ], 409);
  }

  // Reuse checkout session if already created for this order
  $existingSession = (string) $order->get_meta('_thix_yuno_checkout_session');
  if (!empty($existingSession)) {
    $country = $order->get_billing_country() ?: 'CO';

    return thix_yuno_json([
      'checkout_session' => $existingSession,
      'country' => $country,
      'order_id' => $order->get_id(),
      'reused' => true,
    ], 200);
  }

  $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

  $country  = $order->get_billing_country() ?: 'CO';
  $currency = $order->get_currency() ?: 'COP';
  $total    = (float) $order->get_total();
  $value    = thix_yuno_amount_minor_units($total, $currency);

  // ✅ LOG: decimals + computed minor units
  thix_yuno_log('info', 'checkout-session computed amount', [
    'order_id' => $order->get_id(),
    'currency' => $currency,
    'wc_decimals' => thix_yuno_get_wc_price_decimals(),
    'order_total_raw' => $order->get_total(),
    'order_total_float' => $total,
    'minor_units_value' => $value,
  ]);

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
    thix_yuno_log('error', 'checkout-session failed', [
      'order_id' => $order->get_id(),
      'status' => $res['status'],
      'response' => $res['raw'],
    ]);

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
    thix_yuno_log('error', 'checkout-session response missing checkout_session/id', [
      'order_id' => $order->get_id(),
      'response' => $res['raw'],
    ]);

    return thix_yuno_json([
      'error' => 'Yuno response missing checkout_session/id',
      'response' => $res['raw'],
    ], 400);
  }

  $order->update_meta_data('_thix_yuno_checkout_session', $checkoutSession);
  $order->save();

  thix_yuno_log('info', 'checkout-session created', [
    'order_id' => $order->get_id(),
    'checkout_session' => (string)$checkoutSession,
  ]);

  return thix_yuno_json([
    'checkout_session' => $checkoutSession,
    'country' => $country,
    'order_id' => $order->get_id(),
    'reused' => false,
  ], 200);
}

/**
 * =========================
 * Create Payment (Guardrails + stable idempotency + Split Marketplace FIX)
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

  // ✅ Basic guardrails
  if ($order->is_paid()) {
    return thix_yuno_json([
      'handled' => true,
      'error' => 'Order already paid',
      'order_id' => $order->get_id(),
      'status' => $order->get_status(),
      'redirect' => $order->get_checkout_order_received_url(),
    ], 409);
  }

  if ($order->get_status() !== 'pending') {
    return thix_yuno_json([
      'handled' => true,
      'error' => 'Order is not pending',
      'order_id' => $order->get_id(),
      'status' => $order->get_status(),
    ], 409);
  }

  $existingPaymentId = (string) $order->get_meta('_thix_yuno_payment_id');
  if (!empty($existingPaymentId)) {
    return thix_yuno_json([
      'handled' => true,
      'error' => 'Payment already created for this order',
      'order_id' => $order->get_id(),
      'payment_id' => $existingPaymentId,
    ], 409);
  }

  // ✅ transient lock to prevent double-tap requests in parallel
  $lockKey = 'thix_yuno_pay_lock_' . (int)$order->get_id();
  if (get_transient($lockKey)) {
    return thix_yuno_json([
      'handled' => true,
      'error' => 'Payment creation is already in progress for this order',
      'order_id' => $order->get_id(),
    ], 409);
  }
  set_transient($lockKey, 1, 30);

  $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

  $country  = $order->get_billing_country() ?: 'CO';
  $currency = $order->get_currency() ?: 'COP';
  $total    = (float) $order->get_total();
  $value    = thix_yuno_amount_minor_units($total, $currency);

  // ✅ stable idempotency per order+session
  $idempotencyKey = thix_yuno_build_idempotency_key($order->get_id(), $checkoutSession);

  // =========================
  // ✅ Split settings (Phase 1)
  // =========================
  $split_enabled_setting = (string) thix_yuno_get_env('SPLIT_ENABLED', 'no');
  $split_enabled = ($split_enabled_setting === 'yes' || $split_enabled_setting === '1' || $split_enabled_setting === 'true');

  $recipient_id = trim((string) thix_yuno_get_env('YUNO_RECIPIENT_ID', ''));
  $fixed_amount_raw = trim((string) thix_yuno_get_env('SPLIT_FIXED_AMOUNT', ''));
  $fixed_amount = (ctype_digit($fixed_amount_raw) ? (int) $fixed_amount_raw : 0);

  // ✅ LOG: computed amount + split summary (before payload)
  thix_yuno_log('info', 'payments computed amount', [
    'order_id' => $order->get_id(),
    'order_status' => $order->get_status(),
    'currency' => $currency,
    'wc_decimals' => thix_yuno_get_wc_price_decimals(),
    'order_total_raw' => $order->get_total(),
    'order_total_float' => $total,
    'minor_units_value' => $value,
    'split_enabled' => $split_enabled,
    'split_fixed_amount' => ($split_enabled ? $fixed_amount : null),
    'split_remainder' => ($split_enabled ? ($value - $fixed_amount) : null),
    'idempotency_key' => $idempotencyKey,
    'checkout_session' => (string) $checkoutSession,
  ]);

  // Validate split config only if enabled
  if ($split_enabled) {
    if ($recipient_id === '') {
      delete_transient($lockKey);
      return thix_yuno_json([
        'error' => 'Split is enabled but Yuno Recipient ID is missing',
      ], 400);
    }

    if ($fixed_amount <= 0) {
      delete_transient($lockKey);
      return thix_yuno_json([
        'error' => 'Split is enabled but Fixed Split Amount is invalid (must be > 0, minor units)',
      ], 400);
    }

    if ($fixed_amount >= $value) {
      delete_transient($lockKey);
      return thix_yuno_json([
        'error' => 'Split is enabled but Fixed Split Amount must be less than order total',
        'fixed_amount' => $fixed_amount,
        'order_total_minor_units' => $value,
      ], 400);
    }
  }

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

  /**
   * ✅ Split Marketplace payload (as you already had in your "FIX" version)
   * NOTE: this structure must match Yuno expectations for split_marketplace.
   * If Yuno expects a different structure (recipients object), we’ll adjust based on their response.
   */
  if ($split_enabled) {
    $remainder = $value - $fixed_amount;

    $payload['split_marketplace'] = [
      [
        'recipient_id' => $recipient_id,
        'type' => 'PURCHASE',
        'amount' => [
          'value' => $fixed_amount,
          'currency' => $currency,
        ],
      ],
      [
        'type' => 'COMMISSION',
        'amount' => [
          'value' => $remainder,
          'currency' => $currency,
        ],
      ],
    ];
  }

  // ✅ LOG: payload sanitized
  $payload_for_log = $payload;
  if (isset($payload_for_log['payment_method']['token'])) {
    $payload_for_log['payment_method']['token'] = '[REDACTED]';
  }
  thix_yuno_log('debug', 'payments payload (sanitized)', [
    'order_id' => $order->get_id(),
    'payload' => $payload_for_log,
  ]);

  $res = thix_yuno_wp_remote_json(
    'POST',
    "{$apiUrl}/v1/payments",
    [
      'public-api-key' => $publicKey,
      'private-secret-key' => $secretKey,
      'X-idempotency-key' => $idempotencyKey,
      'Content-Type' => 'application/json',
    ],
    $payload,
    30
  );

  // always release lock
  delete_transient($lockKey);

  // ✅ LOG: response
  thix_yuno_log('info', 'payments response received', [
    'order_id' => $order->get_id(),
    'ok' => $res['ok'],
    'status' => $res['status'],
    'response' => $res['raw'],
  ]);

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
    'idempotency_key' => $idempotencyKey,
    'split' => [
      'enabled' => $split_enabled,
      'recipient_id' => $split_enabled ? $recipient_id : null,
      'fixed_amount' => $split_enabled ? $fixed_amount : null,
      'currency' => $currency,
    ],
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
