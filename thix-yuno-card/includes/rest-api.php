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

      // ✅ Split (Phase 1)
      'SPLIT_ENABLED'       => 'split_enabled',
      'YUNO_RECIPIENT_ID'   => 'yuno_recipient_id',
      'SPLIT_FIXED_AMOUNT'  => 'split_fixed_amount',
    ];
    if (isset($map[$key])) {
      $k = $map[$key];
      if (isset($settings[$k]) && $settings[$k] !== '') return $settings[$k];
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
 * ✅ Stable idempotency key per order + checkout session (prevents duplicates on retries)
 */
function thix_yuno_build_idempotency_key($order_id, $checkout_session) {
  $order_id = (int) $order_id;
  $checkout_session = (string) ($checkout_session ?? '');

  $base = $order_id . '|' . $checkout_session . '|' . get_site_url();
  $hash = wp_hash($base, 'auth');

  return 'wc-' . $order_id . '-yuno-' . substr($hash, 0, 24);
}

/**
 * ✅ Simple lock to prevent concurrent /payments calls
 */
function thix_yuno_payment_lock_key($order_id) {
  return 'thix_yuno_payment_lock_' . (int)$order_id;
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

  // ✅ If already paid, do not create/reuse sessions
  if ($order->is_paid()) {
    return thix_yuno_json([
      'error' => 'Order already paid',
      'order_id' => $order->get_id(),
      'status' => $order->get_status(),
      'redirect' => $order->get_checkout_order_received_url(),
    ], 409);
  }

  // ✅ Reuse existing checkout session
  $existingSession = (string) $order->get_meta('_thix_yuno_checkout_session');
  if ($existingSession !== '') {
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
    'reused' => false,
  ], 200);
}

/**
 * =========================
 * Create Payment (ANTI DOUBLE CHARGE + SPLIT)
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

  // =========================
  // ✅ Anti double charge guardrails
  // =========================

  // 1) If already paid -> block
  if ($order->is_paid()) {
    return thix_yuno_json([
      'error' => 'Order already paid',
      'order_id' => $order->get_id(),
      'status' => $order->get_status(),
      'redirect' => $order->get_checkout_order_received_url(),
    ], 409);
  }

  // 2) Only allow payment in pending (this is your POC rule)
  $status = (string) $order->get_status();
  if ($status !== 'pending') {
    return thix_yuno_json([
      'error' => 'Order is not payable in current status',
      'order_id' => $order->get_id(),
      'status' => $status,
    ], 409);
  }

  // 3) If we already have a payment_id stored, block duplicates
  $existingPaymentId = (string) $order->get_meta('_thix_yuno_payment_id');
  if ($existingPaymentId !== '') {
    return thix_yuno_json([
      'error' => 'Payment already created for this order',
      'order_id' => $order->get_id(),
      'payment_id' => $existingPaymentId,
      'status' => $status,
    ], 409);
  }

  // 4) Lock to avoid concurrent double-click requests
  $lock_key = thix_yuno_payment_lock_key($order->get_id());
  if (get_transient($lock_key)) {
    return thix_yuno_json([
      'error' => 'Payment creation already in progress',
      'order_id' => $order->get_id(),
    ], 409);
  }
  set_transient($lock_key, 1, 15); // 15 seconds lock

  try {
    $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

    $country  = $order->get_billing_country() ?: 'CO';
    $currency = $order->get_currency() ?: 'COP';
    $total    = (float) $order->get_total();
    $value    = thix_yuno_amount_minor_units($total, $currency);

    // =========================
    // ✅ Split settings (Phase 1)
    // =========================
    $split_enabled_setting = thix_yuno_get_env('SPLIT_ENABLED', 'no');
    $split_enabled = ($split_enabled_setting === 'yes' || $split_enabled_setting === '1' || $split_enabled_setting === 'true');

    $recipient_id = trim((string) thix_yuno_get_env('YUNO_RECIPIENT_ID', ''));
    $fixed_amount_raw = trim((string) thix_yuno_get_env('SPLIT_FIXED_AMOUNT', ''));
    $fixed_amount = (ctype_digit($fixed_amount_raw) ? (int) $fixed_amount_raw : 0);

    if ($split_enabled) {
      if ($recipient_id === '') {
        return thix_yuno_json([
          'error' => 'Split is enabled but Yuno Recipient ID is missing',
        ], 400);
      }

      if ($fixed_amount <= 0) {
        return thix_yuno_json([
          'error' => 'Split is enabled but Fixed Split Amount is invalid (must be > 0, minor units)',
        ], 400);
      }

      if ($fixed_amount >= $value) {
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

    if ($split_enabled) {
      $payload['split_marketplace'] = [
        'recipients' => [
          [
            'recipient_id' => $recipient_id,
            'amount' => $fixed_amount,
          ]
        ],
      ];
    }

    // ✅ Stable idempotency key (REAL fix vs UUID)
    $idempotency_key = thix_yuno_build_idempotency_key($order->get_id(), $checkoutSession);

    $res = thix_yuno_wp_remote_json(
      'POST',
      "{$apiUrl}/v1/payments",
      [
        'public-api-key' => $publicKey,
        'private-secret-key' => $secretKey,
        'X-idempotency-key' => $idempotency_key,
        'Content-Type' => 'application/json',
      ],
      $payload,
      30
    );

    if (!$res['ok']) {
      // ❗️IMPORTANT: do NOT set order to failed here. Keep pending to allow retry on order-pay.
      $order->add_order_note(
        'Yuno payment create failed. idempotency=' . $idempotency_key . ' response=' .
        (is_string($res['raw']) ? $res['raw'] : wp_json_encode($res['raw']))
      );
      $order->save();

      return thix_yuno_json([
        'error' => 'Yuno create payment failed',
        'status' => $res['status'],
        'idempotency_key' => $idempotency_key,
        'response' => $res['raw'],
      ], 400);
    }

    $payment_id = is_array($res['raw'])
      ? ($res['raw']['id'] ?? $res['raw']['payment_id'] ?? null)
      : null;

    if ($payment_id) {
      $order->update_meta_data('_thix_yuno_payment_id', $payment_id);
    }
    $order->update_meta_data('_thix_yuno_payment_raw', $res['raw']);
    $order->update_meta_data('_thix_yuno_idempotency_key', $idempotency_key);
    $order->save();

    return thix_yuno_json([
      'ok' => true,
      'payment_id' => $payment_id,
      'idempotency_key' => $idempotency_key,
      'split' => [
        'enabled' => $split_enabled,
        'recipient_id' => $split_enabled ? $recipient_id : null,
        'fixed_amount' => $split_enabled ? $fixed_amount : null,
      ],
      'response' => $res['raw'],
    ], 200);

  } finally {
    // Release lock always
    delete_transient($lock_key);
  }
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
    // It is valid to mark failed here (final payment result)
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
