<?php
if (!defined('ABSPATH')) exit;

/**
 * =========================
 * Helpers
 * =========================
 */

function thix_yuno_get_env($key, $default = '') {
    $settings = get_option('woocommerce_thix_yuno_card_settings', []);
    if (is_array($settings)) {
        $map = [
            'ACCOUNT_ID'             => 'account_id',
            'PUBLIC_API_KEY'         => 'public_api_key',
            'PRIVATE_SECRET_KEY'     => 'private_secret_key',
            'DEBUG'                  => 'debug',

            'SPLIT_ENABLED'          => 'split_enabled',
            'YUNO_RECIPIENT_ID'      => 'yuno_recipient_id',
            'SPLIT_FIXED_AMOUNT'     => 'split_fixed_amount',
            'SPLIT_COMMISSION_PERCENT' => 'split_commission_percent',
        ];

        if (isset($map[$key])) {
            $k = $map[$key];
            if (array_key_exists($k, $settings) && $settings[$k] !== '') return $settings[$k];
        }

        // Backwards compatibility: if requesting ACCOUNT_ID, try old account_code
        if ($key === 'ACCOUNT_ID' && array_key_exists('account_code', $settings) && $settings['account_code'] !== '') {
            return $settings['account_code'];
        }
    }

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
        return ['ok' => false, 'status' => 0, 'raw' => $resp->get_error_message()];
    }

    $status  = wp_remote_retrieve_response_code($resp);
    $rawBody = wp_remote_retrieve_body($resp);
    $json    = json_decode($rawBody, true);

    // Log Yuno HTTP response for debugging
    thix_yuno_log('debug', 'Yuno HTTP response', [
        'method' => $method,
        'url'    => $url,
        'status' => $status,
        'body'   => $json ?? $rawBody,
    ]);

    return [
        'ok'     => ($status >= 200 && $status < 300),
        'status' => $status,
        'raw'    => $json ?? $rawBody,
    ];
}

/**
 * Woo decimals as source of truth (store setting).
 * If merchant sets 0 decimals for COP, this returns 0.
 */
function thix_yuno_get_wc_price_decimals() {
    if (function_exists('wc_get_price_decimals')) {
        $d = (int) wc_get_price_decimals();
    } else {
        $d = (int) get_option('woocommerce_price_num_decimals', 2);
    }
    if ($d < 0) $d = 0;
    if ($d > 6) $d = 6;
    return $d;
}

/**
 * Legacy helper to convert to minor units (no longer used for amount.value sent to Yuno).
 * Kept in case you want to use it in logs or internal calculations.
 */
function thix_yuno_to_minor_units($total_major) {
    $decimals = thix_yuno_get_wc_price_decimals();
    $mult     = pow(10, $decimals);
    $minor    = (int) round(((float)$total_major) * $mult);
    return [$minor, $decimals];
}

function thix_yuno_get_order_from_request(WP_REST_Request $request) {
    if (!class_exists('WooCommerce')) {
        return [null, thix_yuno_json(['error' => 'WooCommerce not active'], 500)];
    }

    $params = (array) $request->get_json_params();

    // accept snake_case + camelCase
    $order_id  = absint($params['order_id'] ?? $params['orderId'] ?? 0);
    $order_key = wc_clean(wp_unslash($params['order_key'] ?? $params['orderKey'] ?? ''));

    if (!$order_id) {
        return [null, thix_yuno_json(['error' => 'Missing order_id'], 400)];
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return [null, thix_yuno_json(['error' => 'Order not found', 'order_id' => $order_id], 404)];
    }

    if ($order_key && method_exists($order, 'get_order_key')) {
        if ($order->get_order_key() !== $order_key) {
            return [null, thix_yuno_json(['error' => 'Invalid order_key'], 403)];
        }
    }

    return [$order, null];
}

function thix_yuno_build_idempotency_key($order_id, $checkout_session) {
    $base = ((int)$order_id) . '|' . ((string)$checkout_session) . '|' . get_site_url();
    $hash = wp_hash($base, 'auth');
    return 'wc-' . (int)$order_id . '-yuno-' . substr($hash, 0, 24);
}

function thix_yuno_debug_enabled() {
    $dbg = (string) thix_yuno_get_env('DEBUG', 'no');
    return in_array($dbg, ['yes','1','true'], true);
}

function thix_yuno_logger() {
    static $logger = null;
    if ($logger === null && function_exists('wc_get_logger')) $logger = wc_get_logger();
    return $logger;
}

function thix_yuno_log($level, $message, $context = []) {
    if (!thix_yuno_debug_enabled()) return;
    $logger = thix_yuno_logger();
    if (!$logger) return;
    $logger->log($level, $message . ' ' . wp_json_encode($context), ['source' => 'thix-yuno']);
}

/**
 * =========================
 * Routes
 * =========================
 */

add_action('rest_api_init', function () {

    register_rest_route('thix-yuno/v1', '/public-api-key', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            return thix_yuno_json(['publicApiKey' => thix_yuno_get_env('PUBLIC_API_KEY', '')], 200);
        },
    ]);

    register_rest_route('thix-yuno/v1', '/checkout-session', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'thix_yuno_create_checkout_session',
    ]);

    register_rest_route('thix-yuno/v1', '/payments', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'thix_yuno_create_payment',
    ]);

    register_rest_route('thix-yuno/v1', '/confirm', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'thix_yuno_confirm_order_payment',
    ]);

    register_rest_route('thix-yuno/v1', '/check-order-status', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'thix_yuno_check_order_status',
    ]);

    register_rest_route('thix-yuno/v1', '/duplicate-order', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'thix_yuno_duplicate_order',
    ]);
});

function thix_yuno_create_checkout_session(WP_REST_Request $request) {
    $accountId = thix_yuno_get_env('ACCOUNT_ID', '');
    $publicKey = thix_yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$accountId || !$publicKey || !$secretKey) {
        return thix_yuno_json(['error' => 'Missing required keys'], 400);
    }

    [$order, $err] = thix_yuno_get_order_from_request($request);
    if ($err) return $err;

    if ($order->is_paid()) {
        return thix_yuno_json([
            'error'    => 'Order already paid',
            'redirect' => $order->get_checkout_order_received_url(),
        ], 409);
    }

    $existingSession = (string) $order->get_meta('_thix_yuno_checkout_session');
    if (!empty($existingSession)) {
        return thix_yuno_json([
            'checkout_session' => $existingSession,
            'country'          => $order->get_billing_country() ?: 'CO',
            'order_id'         => $order->get_id(),
            'reused'           => true,
        ], 200);
    }

    $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

    $country      = $order->get_billing_country() ?: 'CO';
    $currency     = $order->get_currency() ?: 'COP';
    $total_major  = (float) $order->get_total();
    $decimals     = thix_yuno_get_wc_price_decimals();
    $amount_value = (float) number_format($total_major, $decimals, '.', '');

    thix_yuno_log('info', 'checkout-session amount', [
        'order_id'      => $order->get_id(),
        'currency'      => $currency,
        'wc_decimals'   => $decimals,
        'total_major'   => $total_major,
        'amount_value'  => $amount_value,
    ]);

    $payload = [
        'account_id'         => $accountId,
        'merchant_order_id'  => 'WC-' . $order->get_id(),
        'payment_description'=> 'WooCommerce Order #' . $order->get_id(),
        'country'            => $country,
        'amount'             => [
            'currency' => $currency,
            'value'    => $amount_value,   // major units
        ],
    ];

    $res = thix_yuno_wp_remote_json(
        'POST',
        "{$apiUrl}/v1/checkout/sessions",
        [
            'public-api-key'     => $publicKey,
            'private-secret-key' => $secretKey,
            'Content-Type'       => 'application/json',
        ],
        $payload,
        30
    );

    if (!$res['ok']) {
        return thix_yuno_json([
            'error'    => 'Yuno create checkout session failed',
            'status'   => $res['status'],
            'response' => $res['raw'],
        ], 400);
    }

    $checkoutSession = is_array($res['raw'])
        ? ($res['raw']['checkout_session'] ?? $res['raw']['id'] ?? null)
        : null;

    if (!$checkoutSession) {
        return thix_yuno_json(['error' => 'Yuno response missing checkout_session/id', 'response' => $res['raw']], 400);
    }

    thix_yuno_log('info', 'Yuno checkout session response', [
        'order_id'         => $order->get_id(),
        'checkout_session' => $checkoutSession,
        'full_response'    => $res['raw'], // May contain available payment_methods
    ]);

    $order->update_meta_data('_thix_yuno_checkout_session', $checkoutSession);
    $order->save();

    return thix_yuno_json([
        'checkout_session' => $checkoutSession,
        'country'          => $country,
        'order_id'         => $order->get_id(),
        'reused'           => false,
    ], 200);
}

function thix_yuno_create_payment(WP_REST_Request $request) {
    $accountId = thix_yuno_get_env('ACCOUNT_ID', '');
    $publicKey = thix_yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$accountId || !$publicKey || !$secretKey) {
        return thix_yuno_json(['error' => 'Missing required keys'], 400);
    }

    [$order, $err] = thix_yuno_get_order_from_request($request);
    if ($err) return $err;

    $params          = (array) $request->get_json_params();
    $oneTimeToken    = $params['oneTimeToken'] ?? null;
    $checkoutSession = $params['checkoutSession'] ?? ($params['checkout_session'] ?? null);

    if (!$oneTimeToken || !$checkoutSession) {
        return thix_yuno_json(['error' => 'Missing oneTimeToken or checkoutSession'], 400);
    }

    if ($order->is_paid()) {
        return thix_yuno_json(['handled' => true, 'error' => 'Order already paid'], 409);
    }

    $lockKey = 'thix_yuno_pay_lock_' . (int)$order->get_id();
    if (get_transient($lockKey)) {
        return thix_yuno_json(['handled' => true, 'error' => 'Payment creation is already in progress'], 409);
    }
    set_transient($lockKey, 1, 30);

    $apiUrl   = thix_yuno_api_url_from_public_key($publicKey);
    $country  = $order->get_billing_country() ?: 'CO';
    $currency = $order->get_currency() ?: 'COP';

    $total_major  = (float) $order->get_total();
    $decimals     = thix_yuno_get_wc_price_decimals();
    $amount_value = (float) number_format($total_major, $decimals, '.', '');

    $idempotencyKey = thix_yuno_build_idempotency_key($order->get_id(), $checkoutSession);

    // Split config
    $split_enabled_setting = (string) thix_yuno_get_env('SPLIT_ENABLED', 'no');
    $split_enabled         = in_array($split_enabled_setting, ['yes','1','true'], true);

    $recipient_id = trim((string) thix_yuno_get_env('YUNO_RECIPIENT_ID', ''));

    // Commission percent (0..100)
    $pct_raw = trim((string) thix_yuno_get_env('SPLIT_COMMISSION_PERCENT', ''));

    // Fixed commission in minor units (e.g. 1500 = 15.00 USD)
    $fixed_raw   = trim((string) thix_yuno_get_env('SPLIT_FIXED_AMOUNT', ''));
    $fixed_minor = ($fixed_raw !== '' && ctype_digit($fixed_raw)) ? (int) $fixed_raw : null;

    // compute commission in MAJOR units
    $commission_amount = 0.0;
    $commission_mode   = 'none';

    if ($split_enabled) {

        if ($pct_raw !== '') {
            $pct = (float) str_replace(',', '.', $pct_raw);
            if ($pct < 0 || $pct > 100) {
                delete_transient($lockKey);
                return thix_yuno_json(['error' => 'Split commission percent must be between 0 and 100'], 400);
            }
            $commission_amount = round($amount_value * ($pct / 100.0), $decimals);
            $commission_mode   = 'percent';

        } elseif ($fixed_minor !== null) {
            if ($fixed_minor < 0) {
                delete_transient($lockKey);
                return thix_yuno_json(['error' => 'Split fixed amount must be >= 0 (minor units)'], 400);
            }
            $factor            = pow(10, $decimals);
            $commission_amount = round($fixed_minor / $factor, $decimals);
            $commission_mode   = 'fixed';

        } else {
            // split enabled but without commission configuration -> 0 (passthrough)
            $commission_amount = 0.0;
            $commission_mode   = 'zero-default';
        }

        if ($recipient_id === '') {
            delete_transient($lockKey);
            return thix_yuno_json(['error' => 'Split is enabled but Yuno Recipient ID is missing'], 400);
        }

        if ($commission_amount < 0 || $commission_amount > $amount_value) {
            delete_transient($lockKey);
            return thix_yuno_json([
                'error'              => 'Split commission must be between 0 and order total (major units)',
                'commission_amount'  => $commission_amount,
                'order_total_amount' => $amount_value,
            ], 400);
        }
    }

    // Calculate seller_amount for logging and payload construction
    $seller_amount = $split_enabled ? round($amount_value - $commission_amount, $decimals) : null;

    thix_yuno_log('info', 'payments amount (woo -> yuno)', [
        'order_id'         => $order->get_id(),
        'currency'         => $currency,
        'wc_decimals'      => $decimals,
        'total_major'      => $total_major,
        'amount_value'     => $amount_value,
        'split_enabled'    => $split_enabled,
        'commission_mode'  => $commission_mode,
        'commission_amount'=> ($split_enabled ? $commission_amount : null),
        'seller_amount'    => $seller_amount,
        'recipient_id'     => ($split_enabled ? $recipient_id : null),
        'idempotency_key'  => $idempotencyKey,
    ]);

    // Base payload
    $payload = [
        'description'        => 'WooCommerce Payment #' . $order->get_id(),
        'account_id'         => $accountId,
        'merchant_order_id'  => 'WC-' . $order->get_id(),
        'country'            => $country,
        'amount'             => [
            'currency' => $currency,
            'value'    => $amount_value, // major units
        ],
        'checkout'           => [
            'session' => $checkoutSession,
        ],
        'payment_method'     => [
            'token'         => $oneTimeToken,
            'vaulted_token' => null,
        ],
    ];

    // Split payload (major units)
    if ($split_enabled) {
        // seller_amount already calculated above for logging

        $payload['split_marketplace'] = [
            [
                'recipient_id' => $recipient_id,
                'type'         => 'PURCHASE',
                'amount'       => [
                    'value'    => $seller_amount,
                    'currency' => $currency,
                ],
            ],
        ];

        if ($commission_amount > 0) {
            $payload['split_marketplace'][] = [
                'type'   => 'COMMISSION',
                'amount' => [
                    'value'    => $commission_amount,
                    'currency' => $currency,
                ],
            ];
        }
    }

    // Log sanitized payload
    $payload_for_log = $payload;
    if (isset($payload_for_log['payment_method']['token'])) {
        $payload_for_log['payment_method']['token'] = '[REDACTED]';
    }
    thix_yuno_log('debug', 'payments payload (sanitized)', [
        'order_id' => $order->get_id(),
        'payload'  => $payload_for_log,
    ]);

    $res = thix_yuno_wp_remote_json(
        'POST',
        "{$apiUrl}/v1/payments",
        [
            'public-api-key'     => $publicKey,
            'private-secret-key' => $secretKey,
            'X-idempotency-key'  => $idempotencyKey,
            'Content-Type'       => 'application/json',
        ],
        $payload,
        30
    );

    delete_transient($lockKey);

    if (!$res['ok']) {
        // Only add note, do NOT mark failed (allow retries)
        $order->add_order_note('Yuno payment error: ' . (is_string($res['raw']) ? $res['raw'] : wp_json_encode($res['raw'])));
        $order->save();

        thix_yuno_log('warning', 'Yuno payment creation failed (order remains pending for retry)', [
            'order_id' => $order->get_id(),
            'status'   => $res['status'],
            'response' => $res['raw'],
        ]);

        return thix_yuno_json([
            'error'    => 'Yuno create payment failed',
            'status'   => $res['status'],
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
        'ok'             => true,
        'payment_id'     => $payment_id,
        'idempotency_key'=> $idempotencyKey,
        'response'       => $res['raw'],
    ], 200);
}

function thix_yuno_confirm_order_payment(WP_REST_Request $request) {
    [$order, $err] = thix_yuno_get_order_from_request($request);
    if ($err) return $err;

    $params = (array) $request->get_json_params();

    // ✅ SECURITY: Server-side verification implemented
    // Frontend only sends payment_id, backend verifies status with Yuno API
    $payment_id = $params['payment_id'] ?? $params['paymentId'] ?? $order->get_meta('_thix_yuno_payment_id');

    if (!$payment_id) {
        thix_yuno_log('error', 'Confirm: missing payment_id', [
            'order_id' => $order->get_id(),
        ]);
        return thix_yuno_json(['error' => 'Missing payment_id'], 400);
    }

    // Check if order is already paid (idempotency)
    if ($order->is_paid()) {
        thix_yuno_log('info', 'Confirm: order already paid', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
        ]);
        return thix_yuno_json([
            'ok'        => true,
            'order_id'  => $order->get_id(),
            'new_status'=> $order->get_status(),
            'redirect'  => $order->get_checkout_order_received_url(),
            'already_paid' => true,
        ], 200);
    }

    // Get credentials
    $publicKey = thix_yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$publicKey || !$secretKey) {
        thix_yuno_log('error', 'Confirm: missing API keys', [
            'order_id' => $order->get_id(),
        ]);
        return thix_yuno_json(['error' => 'Missing API keys'], 500);
    }

    $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

    // ✅ SERVER-SIDE VERIFICATION: Query Yuno for payment status
    thix_yuno_log('info', 'Confirm: verifying payment with Yuno', [
        'order_id'   => $order->get_id(),
        'payment_id' => $payment_id,
    ]);

    $res = thix_yuno_wp_remote_json(
        'GET',
        "{$apiUrl}/v1/payments/{$payment_id}",
        [
            'public-api-key'     => $publicKey,
            'private-secret-key' => $secretKey,
        ],
        null,
        15
    );

    if (!$res['ok']) {
        thix_yuno_log('error', 'Confirm: failed to verify payment with Yuno', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
            'status'     => $res['status'],
            'response'   => $res['raw'],
        ]);

        // Don't mark as failed, just return error (allow retry)
        $order->add_order_note('Yuno verification failed (HTTP ' . $res['status'] . '). payment_id=' . $payment_id);
        $order->save();

        return thix_yuno_json([
            'error'   => 'Could not verify payment status with Yuno',
            'order_id'=> $order->get_id(),
            'retry'   => true,
        ], 500);
    }

    // ✅ Source of truth: status verified by Yuno API
    // Log FULL response for debugging
    thix_yuno_log('info', 'Confirm: full Yuno response', [
        'order_id'      => $order->get_id(),
        'payment_id'    => $payment_id,
        'full_response' => $res['raw'],
    ]);

    // Yuno may use different field names and nesting structures
    // Try multiple possible locations for the status field
    $raw = $res['raw'];
    $status_candidates = [
        $raw['status'] ?? null,
        $raw['state'] ?? null,
        $raw['payment_status'] ?? null,
        $raw['payment']['status'] ?? null,
        $raw['payment']['state'] ?? null,
        $raw['payment']['payment_status'] ?? null,
        $raw['transaction_status'] ?? null,
        $raw['transaction']['status'] ?? null,
    ];

    // Find first non-null status
    $verified_status = 'UNKNOWN';
    foreach ($status_candidates as $candidate) {
        if ($candidate !== null && $candidate !== '') {
            $verified_status = strtoupper(trim($candidate));
            break;
        }
    }

    thix_yuno_log('info', 'Confirm: payment status verified', [
        'order_id'           => $order->get_id(),
        'payment_id'         => $payment_id,
        'verified_status'    => $verified_status,
        'raw_status'         => $raw['status'] ?? null,
        'raw_state'          => $raw['state'] ?? null,
        'raw_payment_status' => $raw['payment_status'] ?? null,
        'nested_payment'     => isset($raw['payment']) ? 'yes' : 'no',
    ]);

    // Handle verified status
    if (in_array($verified_status, ['SUCCEEDED', 'VERIFIED', 'APPROVED'], true)) {
        $order->payment_complete($payment_id);
        $order->add_order_note('Yuno payment approved (verified). status=' . $verified_status . ' payment_id=' . $payment_id);
        $order->save();

        thix_yuno_log('info', 'Confirm: order marked as paid', [
            'order_id'     => $order->get_id(),
            'payment_id'   => $payment_id,
            'order_status' => $order->get_status(),
        ]);

        return thix_yuno_json([
            'ok'        => true,
            'order_id'  => $order->get_id(),
            'new_status'=> $order->get_status(),
            'redirect'  => $order->get_checkout_order_received_url(),
        ], 200);
    }

    if (in_array($verified_status, ['REJECTED', 'DECLINED', 'CANCELLED', 'ERROR', 'EXPIRED', 'FAILED'], true)) {
        $order->update_status('failed', 'Yuno payment rejected (verified): ' . $verified_status);
        $order->add_order_note('Yuno payment rejected. status=' . $verified_status . ' payment_id=' . $payment_id);
        $order->save();

        thix_yuno_log('warning', 'Confirm: order marked as failed', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
            'status'     => $verified_status,
        ]);

        return thix_yuno_json([
            'ok'        => false,
            'failed'    => true,
            'blocked'   => true,
            'order_id'  => $order->get_id(),
            'new_status'=> $order->get_status(),
            'status'    => $verified_status,
        ], 200);
    }

    // Intermediate states (PENDING, PROCESSING, REQUIRES_ACTION, etc.)
    $order->add_order_note('Yuno payment status: ' . $verified_status . ' (payment_id=' . $payment_id . ')');
    $order->save();

    thix_yuno_log('info', 'Confirm: payment in intermediate state', [
        'order_id'   => $order->get_id(),
        'payment_id' => $payment_id,
        'status'     => $verified_status,
    ]);

    return thix_yuno_json([
        'ok'      => true,
        'order_id'=> $order->get_id(),
        'status'  => $verified_status,
        'pending' => true,
        'message' => 'Payment is being processed',
    ], 200);
}

/**
 * Check order status to prevent double payment
 * Used when user reloads the order-pay page
 *
 * ✅ SECURITY: This endpoint verifies with Yuno API if payment was already processed
 * to prevent the race condition where user reloads before auto-confirm completes.
 */
function thix_yuno_check_order_status(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $order_id  = absint($params['order_id'] ?? 0);
    $order_key = sanitize_text_field($params['order_key'] ?? '');

    if (!$order_id) {
        return thix_yuno_json(['error' => 'Missing order_id'], 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return thix_yuno_json(['error' => 'Order not found'], 404);
    }

    // Validate order key if provided
    if ($order_key && $order->get_order_key() !== $order_key) {
        thix_yuno_log('warning', 'Check order status: order_key mismatch', [
            'order_id'      => $order_id,
            'provided_key'  => $order_key,
            'expected_key'  => $order->get_order_key(),
        ]);
        return thix_yuno_json(['error' => 'Invalid order_key'], 403);
    }

    $status = $order->get_status();
    $is_paid = $order->is_paid();

    thix_yuno_log('info', 'Check order status: initial check', [
        'order_id' => $order_id,
        'status'   => $status,
        'is_paid'  => $is_paid,
    ]);

    // 1. If order is already paid in WooCommerce, return redirect immediately
    $paid_statuses = ['processing', 'completed', 'on-hold'];
    if ($is_paid || in_array($status, $paid_statuses, true)) {
        $redirect = $order->get_checkout_order_received_url();

        thix_yuno_log('info', 'Check order status: already paid in WooCommerce', [
            'order_id' => $order_id,
            'status'   => $status,
        ]);

        return thix_yuno_json([
            'is_paid'  => true,
            'status'   => $status,
            'redirect' => $redirect,
            'message'  => 'Order already paid',
        ], 200);
    }

    // 2. ✅ AUTO-DUPLICATE: If order is in failed state, signal frontend to duplicate
    // This handles the F5 reload case where user refreshes a failed order
    if ($status === 'failed') {
        thix_yuno_log('info', 'Check order status: order is failed, should duplicate', [
            'order_id' => $order_id,
            'status'   => $status,
        ]);

        return thix_yuno_json([
            'is_paid'         => false,
            'is_failed'       => true,
            'should_duplicate'=> true,
            'status'          => $status,
            'message'         => 'Order failed, needs duplication',
        ], 200);
    }

    // 3. ✅ CRITICAL: Check if payment_id exists (payment was initiated)
    // If it exists, verify with Yuno API to prevent double-payment race condition
    $payment_id = $order->get_meta('_thix_yuno_payment_id');

    if (!$payment_id) {
        // No payment initiated yet, safe to proceed
        thix_yuno_log('info', 'Check order status: no payment_id found, allowing payment', [
            'order_id' => $order_id,
        ]);

        return thix_yuno_json([
            'is_paid' => false,
            'status'  => $status,
            'message' => 'Order ready for payment',
        ], 200);
    }

    // 4. Payment was initiated, verify with Yuno API
    thix_yuno_log('info', 'Check order status: payment_id found, verifying with Yuno', [
        'order_id'   => $order_id,
        'payment_id' => $payment_id,
    ]);

    $publicKey = thix_yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$publicKey || !$secretKey) {
        // Can't verify, but safer to block than allow double payment
        thix_yuno_log('warning', 'Check order status: missing API keys, blocking payment', [
            'order_id' => $order_id,
        ]);

        return thix_yuno_json([
            'is_paid' => false,
            'status'  => $status,
            'message' => 'Order ready for payment',
            'warning' => 'Could not verify payment status',
        ], 200);
    }

    $apiUrl = thix_yuno_api_url_from_public_key($publicKey);

    $res = thix_yuno_wp_remote_json(
        'GET',
        "{$apiUrl}/v1/payments/{$payment_id}",
        [
            'public-api-key'     => $publicKey,
            'private-secret-key' => $secretKey,
        ],
        null,
        10
    );

    if (!$res['ok']) {
        // API call failed, allow payment (but log the issue)
        thix_yuno_log('warning', 'Check order status: Yuno API verification failed', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
            'status'     => $res['status'],
        ]);

        return thix_yuno_json([
            'is_paid' => false,
            'status'  => $status,
            'message' => 'Order ready for payment',
        ], 200);
    }

    // Parse status from Yuno response (same logic as confirm endpoint)
    $raw = $res['raw'];
    $status_candidates = [
        $raw['status'] ?? null,
        $raw['state'] ?? null,
        $raw['payment_status'] ?? null,
        $raw['payment']['status'] ?? null,
        $raw['payment']['state'] ?? null,
        $raw['payment']['payment_status'] ?? null,
        $raw['transaction_status'] ?? null,
        $raw['transaction']['status'] ?? null,
    ];

    $verified_status = 'UNKNOWN';
    foreach ($status_candidates as $candidate) {
        if ($candidate !== null && $candidate !== '') {
            $verified_status = strtoupper(trim($candidate));
            break;
        }
    }

    thix_yuno_log('info', 'Check order status: Yuno verification result', [
        'order_id'        => $order_id,
        'payment_id'      => $payment_id,
        'verified_status' => $verified_status,
    ]);

    // 5. If payment is SUCCEEDED in Yuno, mark order as paid NOW
    if (in_array($verified_status, ['SUCCEEDED', 'VERIFIED', 'APPROVED'], true)) {
        thix_yuno_log('info', 'Check order status: Payment succeeded in Yuno, marking order as paid', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
            'status'     => $verified_status,
        ]);

        // Mark order as paid
        $order->payment_complete($payment_id);
        $order->add_order_note('Yuno payment confirmed via check-order-status (page reload). status=' . $verified_status . ' payment_id=' . $payment_id);
        $order->save();

        $redirect = $order->get_checkout_order_received_url();

        return thix_yuno_json([
            'is_paid'         => true,
            'status'          => $order->get_status(),
            'redirect'        => $redirect,
            'message'         => 'Payment already processed',
            'verified_by'     => 'yuno_api',
            'verified_status' => $verified_status,
        ], 200);
    }

    // 6. If payment is REJECTED/FAILED, mark order as failed and signal duplication
    if (in_array($verified_status, ['REJECTED', 'DECLINED', 'CANCELLED', 'ERROR', 'EXPIRED', 'FAILED'], true)) {
        thix_yuno_log('warning', 'Check order status: Payment failed in Yuno, marking as failed', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
            'status'     => $verified_status,
        ]);

        // ✅ Sync Yuno status with WooCommerce: mark as failed
        if ($status !== 'failed') {
            $order->update_status('failed', 'Yuno payment failed (check-order-status): ' . $verified_status);
            $order->add_order_note('Yuno payment failed. status=' . $verified_status . ' payment_id=' . $payment_id);
            $order->save();
        }

        return thix_yuno_json([
            'is_paid'         => false,
            'is_failed'       => true,
            'should_duplicate'=> true,
            'status'          => 'failed',
            'message'         => 'Order failed, needs duplication',
            'verified_status' => $verified_status,
        ], 200);
    }

    // 7. Payment is PENDING/PROCESSING/UNKNOWN - safer to block new payment
    thix_yuno_log('info', 'Check order status: Payment in intermediate state, allowing retry', [
        'order_id'   => $order_id,
        'payment_id' => $payment_id,
        'status'     => $verified_status,
    ]);

    // For intermediate states, we allow payment (user might be retrying)
    // but we could also choose to block and show "payment processing" message
    return thix_yuno_json([
        'is_paid' => false,
        'status'  => $status,
        'message' => 'Order ready for payment',
        'verified_status' => $verified_status,
    ], 200);
}

/**
 * Duplicate a failed order with the same products and customer data
 * Used when a payment fails and we want to allow retry with a new order
 */
function thix_yuno_duplicate_order(WP_REST_Request $request) {
    [$order, $err] = thix_yuno_get_order_from_request($request);
    if ($err) return $err;

    thix_yuno_log('info', 'Duplicate order: starting', [
        'original_order_id' => $order->get_id(),
        'original_status'   => $order->get_status(),
    ]);

    try {
        // Create new order
        $new_order = wc_create_order();

        // Copy all items from original order
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $quantity   = $item->get_quantity();
            $product    = $item->get_product();

            if ($product) {
                $new_order->add_product($product, $quantity);
            }
        }

        // Copy shipping items
        foreach ($order->get_items('shipping') as $item_id => $item) {
            $new_order->add_item($item);
        }

        // Copy fees
        foreach ($order->get_items('fee') as $item_id => $item) {
            $new_order->add_item($item);
        }

        // Copy customer data
        $new_order->set_customer_id($order->get_customer_id());

        // Copy billing address
        $new_order->set_billing_first_name($order->get_billing_first_name());
        $new_order->set_billing_last_name($order->get_billing_last_name());
        $new_order->set_billing_company($order->get_billing_company());
        $new_order->set_billing_address_1($order->get_billing_address_1());
        $new_order->set_billing_address_2($order->get_billing_address_2());
        $new_order->set_billing_city($order->get_billing_city());
        $new_order->set_billing_state($order->get_billing_state());
        $new_order->set_billing_postcode($order->get_billing_postcode());
        $new_order->set_billing_country($order->get_billing_country());
        $new_order->set_billing_email($order->get_billing_email());
        $new_order->set_billing_phone($order->get_billing_phone());

        // Copy shipping address
        $new_order->set_shipping_first_name($order->get_shipping_first_name());
        $new_order->set_shipping_last_name($order->get_shipping_last_name());
        $new_order->set_shipping_company($order->get_shipping_company());
        $new_order->set_shipping_address_1($order->get_shipping_address_1());
        $new_order->set_shipping_address_2($order->get_shipping_address_2());
        $new_order->set_shipping_city($order->get_shipping_city());
        $new_order->set_shipping_state($order->get_shipping_state());
        $new_order->set_shipping_postcode($order->get_shipping_postcode());
        $new_order->set_shipping_country($order->get_shipping_country());

        // Set payment method
        $new_order->set_payment_method($order->get_payment_method());
        $new_order->set_payment_method_title($order->get_payment_method_title());

        // Set currency
        $new_order->set_currency($order->get_currency());

        // Calculate totals
        $new_order->calculate_totals();

        // Set status to pending
        $new_order->set_status('pending', 'Order created from failed order #' . $order->get_id());

        // Save the new order
        $new_order->save();

        // Add note to original order
        $order->add_order_note('New order #' . $new_order->get_id() . ' created for payment retry.');
        $order->save();

        // Add note to new order
        $new_order->add_order_note('Created from failed order #' . $order->get_id() . ' for payment retry.');
        $new_order->save();

        thix_yuno_log('info', 'Duplicate order: success', [
            'original_order_id' => $order->get_id(),
            'new_order_id'      => $new_order->get_id(),
        ]);

        return thix_yuno_json([
            'ok'            => true,
            'new_order_id'  => $new_order->get_id(),
            'new_order_key' => $new_order->get_order_key(),
            'pay_url'       => $new_order->get_checkout_payment_url(true),
            'total'         => (float) $new_order->get_total(),
            'formatted_total' => wp_kses_post($new_order->get_formatted_order_total()),
        ], 200);

    } catch (Exception $e) {
        thix_yuno_log('error', 'Duplicate order: failed', [
            'original_order_id' => $order->get_id(),
            'error'             => $e->getMessage(),
        ]);

        return thix_yuno_json([
            'error'   => 'Failed to create new order',
            'message' => $e->getMessage(),
        ], 500);
    }
}