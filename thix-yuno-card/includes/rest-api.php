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
            'ACCOUNT_CODE'           => 'account_code',
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
 * Legacy helper to convert to minor units (ya no se usa para amount.value enviado a Yuno).
 * Se mantiene por si lo quieres usar en logs o cálculos internos.
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
});

function thix_yuno_create_checkout_session(WP_REST_Request $request) {
    $accountCode = thix_yuno_get_env('ACCOUNT_CODE', '');
    $publicKey   = thix_yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey   = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$accountCode || !$publicKey || !$secretKey) {
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
        'account_id'         => $accountCode,
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
    $accountCode = thix_yuno_get_env('ACCOUNT_CODE', '');
    $publicKey   = thix_yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey   = thix_yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$accountCode || !$publicKey || !$secretKey) {
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
            // split enabled pero sin configuración de comisión -> 0 (passthrough)
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

    thix_yuno_log('info', 'payments amount (woo -> yuno)', [
        'order_id'         => $order->get_id(),
        'currency'         => $currency,
        'wc_decimals'      => $decimals,
        'total_major'      => $total_major,
        'amount_value'     => $amount_value,
        'split_enabled'    => $split_enabled,
        'commission_mode'  => $commission_mode,
        'commission_amount'=> ($split_enabled ? $commission_amount : null),
        'recipient_id'     => ($split_enabled ? $recipient_id : null),
        'idempotency_key'  => $idempotencyKey,
    ]);

    // Base payload
    $payload = [
        'description'        => 'WooCommerce Payment #' . $order->get_id(),
        'account_id'         => $accountCode,
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
        $seller_amount = round($amount_value - $commission_amount, $decimals);

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
        $order->add_order_note('Yuno payment failed: ' . (is_string($res['raw']) ? $res['raw'] : wp_json_encode($res['raw'])));
        $order->update_status('failed');
        $order->save();

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

    $params     = (array) $request->get_json_params();
    $status     = isset($params['status']) ? strtoupper((string)$params['status']) : 'UNKNOWN';
    $payment_id = $params['payment_id'] ?? $params['paymentId'] ?? $order->get_meta('_thix_yuno_payment_id');

    if (in_array($status, ['SUCCEEDED','VERIFIED'], true)) {
        $order->payment_complete($payment_id ?: '');
        $order->add_order_note('Yuno approved. status=' . $status . ' payment_id=' . ($payment_id ?: 'N/A'));
        $order->save();

        return thix_yuno_json([
            'ok'        => true,
            'order_id'  => $order->get_id(),
            'new_status'=> $order->get_status(),
            'redirect'  => $order->get_checkout_order_received_url(),
        ], 200);
    }

    if (in_array($status, ['REJECTED','DECLINED','CANCELLED','ERROR','EXPIRED'], true)) {
        $order->update_status('failed', 'Yuno: ' . $status);
        $order->add_order_note('Yuno rejected. status=' . $status . ' payment_id=' . ($payment_id ?: 'N/A'));
        $order->save();

        return thix_yuno_json([
            'ok'        => false,
            'order_id'  => $order->get_id(),
            'new_status'=> $order->get_status(),
        ], 200);
    }

    $order->add_order_note('Yuno status: ' . $status);
    $order->save();

    return thix_yuno_json([
        'ok'     => true,
        'order_id'=> $order->get_id(),
        'status' => $status,
    ], 200);
}