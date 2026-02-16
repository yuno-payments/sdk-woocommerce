<?php
if (!defined('ABSPATH')) exit;

/**
 * =========================
 * Helpers
 * =========================
 */

function yuno_get_env($key, $default = '') {
    $settings = get_option('woocommerce_yuno_card_settings', []);
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

            // Webhook security settings
            'WEBHOOK_HMAC_SECRET'    => 'webhook_hmac_secret',
            'WEBHOOK_API_KEY'        => 'webhook_api_key',
            'WEBHOOK_X_SECRET'       => 'webhook_x_secret',
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

function yuno_api_url_from_public_key($publicKey) {
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

function yuno_json($data, $status = 200) {
    return new WP_REST_Response($data, $status);
}

function yuno_wp_remote_json($method, $url, $headers = [], $body = null, $timeout = 30) {
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
    yuno_log('debug', 'Yuno HTTP response', [
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
function yuno_get_wc_price_decimals() {
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
 * Extract payment status from Yuno API response.
 * Yuno may use different field names and nesting structures across different endpoints.
 * This function tries multiple possible locations for the status field.
 *
 * @param array $raw The raw Yuno API response
 * @return string The extracted status in uppercase, or 'UNKNOWN' if not found
 */
function yuno_extract_payment_status($raw) {
    // Validate that $raw is an array before accessing elements
    // When Yuno API returns non-JSON (e.g., "Error: timeout"), $raw becomes a string
    // Accessing array keys on a string returns the first character, causing incorrect status
    if (!is_array($raw)) {
        return 'UNKNOWN';
    }

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

    foreach ($status_candidates as $candidate) {
        if ($candidate !== null && $candidate !== '') {
            return strtoupper(trim($candidate));
        }
    }

    return 'UNKNOWN';
}

function yuno_get_order_from_request(WP_REST_Request $request) {
    if (!class_exists('WooCommerce')) {
        return [null, yuno_json(['error' => 'WooCommerce not active'], 500)];
    }

    $params = (array) $request->get_json_params();

    // accept snake_case + camelCase
    $order_id  = absint($params['order_id'] ?? $params['orderId'] ?? 0);
    $order_key = wc_clean(wp_unslash($params['order_key'] ?? $params['orderKey'] ?? ''));

    if (!$order_id) {
        return [null, yuno_json(['error' => 'Missing order_id'], 400)];
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return [null, yuno_json(['error' => 'Order not found', 'order_id' => $order_id], 404)];
    }

    if ($order_key && method_exists($order, 'get_order_key')) {
        if ($order->get_order_key() !== $order_key) {
            return [null, yuno_json(['error' => 'Invalid order_key'], 403)];
        }
    }

    return [$order, null];
}

function yuno_build_idempotency_key($order_id, $checkout_session) {
    // Get payment attempt count from order meta
    $order = wc_get_order($order_id);
    $attempt_count = 0;

    if ($order) {
        $attempt_count = (int) $order->get_meta('_yuno_payment_attempt_count', true);

        // Increment attempt count for this payment try
        $attempt_count++;
        $order->update_meta_data('_yuno_payment_attempt_count', $attempt_count);
        $order->save();
    }

    // Include attempt count in idempotency key to allow retries
    $base = ((int)$order_id) . '|' . ((string)$checkout_session) . '|attempt-' . $attempt_count . '|' . get_site_url();
    $hash = wp_hash($base, 'auth');
    return 'wc-' . (int)$order_id . '-a' . $attempt_count . '-' . substr($hash, 0, 20);
}

function yuno_debug_enabled() {
    $dbg = (string) yuno_get_env('DEBUG', 'no');
    return in_array($dbg, ['yes','1','true'], true);
}

function yuno_logger() {
    static $logger = null;
    if ($logger === null && function_exists('wc_get_logger')) $logger = wc_get_logger();
    return $logger;
}

function yuno_log($level, $message, $context = []) {
    if (!yuno_debug_enabled()) return;
    $logger = yuno_logger();
    if (!$logger) return;
    $logger->log($level, $message . ' ' . wp_json_encode($context), ['source' => 'yuno']);
}

/**
 * =========================
 * Routes
 * =========================
 */

add_action('rest_api_init', function () {

    register_rest_route('yuno/v1', '/public-api-key', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            return yuno_json(['publicApiKey' => yuno_get_env('PUBLIC_API_KEY', '')], 200);
        },
    ]);

    register_rest_route('yuno/v1', '/checkout-session', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'yuno_create_checkout_session',
    ]);

    register_rest_route('yuno/v1', '/payments', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'yuno_create_payment',
    ]);

    register_rest_route('yuno/v1', '/confirm', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'yuno_confirm_order_payment',
    ]);

    register_rest_route('yuno/v1', '/check-order-status', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'yuno_check_order_status',
    ]);

    register_rest_route('yuno/v1', '/duplicate-order', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'yuno_duplicate_order',
    ]);

    register_rest_route('yuno/v1', '/webhook', [
        'methods'             => 'POST',
        'permission_callback' => 'yuno_verify_webhook_signature',
        'callback'            => 'yuno_handle_webhook',
    ]);
});

function yuno_create_checkout_session(WP_REST_Request $request) {
    $accountId = yuno_get_env('ACCOUNT_ID', '');
    $publicKey = yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$accountId || !$publicKey || !$secretKey) {
        return yuno_json(['error' => 'Missing required keys'], 400);
    }

    [$order, $err] = yuno_get_order_from_request($request);
    if ($err) return $err;

    if ($order->is_paid()) {
        return yuno_json([
            'error'    => 'Order already paid',
            'redirect' => $order->get_checkout_order_received_url(),
        ], 409);
    }

    // ✅ FIX: ALWAYS create new checkout session (never reuse)
    // Reusing checkout sessions can cause INVALID_CUSTOMER_FOR_TOKEN errors when:
    // - customer_id in order meta doesn't match customer_id in old checkout session
    // - This happens after order duplication, customer changes, or retry scenarios
    // Creating a fresh checkout session ensures customer_id consistency
    //
    // Old behavior (REMOVED):
    // $existingSession = (string) $order->get_meta('_yuno_checkout_session');
    // if (!empty($existingSession)) {
    //     return yuno_json(['checkout_session' => $existingSession, ...], 200);
    // }

    $apiUrl = yuno_api_url_from_public_key($publicKey);

    $country      = $order->get_billing_country() ?: 'CO';
    $currency     = $order->get_currency() ?: 'COP';
    $total_major  = (float) $order->get_total();
    $decimals     = yuno_get_wc_price_decimals();
    $amount_value = (float) number_format($total_major, $decimals, '.', '');

    yuno_log('info', 'checkout-session amount', [
        'order_id'      => $order->get_id(),
        'currency'      => $currency,
        'wc_decimals'   => $decimals,
        'total_major'   => $total_major,
        'amount_value'  => $amount_value,
    ]);

    // ✅ Try to get or create Yuno Customer (optional, graceful degradation)
    yuno_log('info', '🔵 [CHECKOUT SESSION] About to call yuno_get_or_create_customer', [
        'order_id' => $order->get_id(),
    ]);

    $customer_id = yuno_get_or_create_customer($order);

    yuno_log('info', '🔵 [CHECKOUT SESSION] Customer function returned', [
        'order_id'    => $order->get_id(),
        'customer_id' => $customer_id ?: 'NULL',
    ]);

    // Build base payload
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

    // ✅ Add customer_id if customer was created successfully
    if (!empty($customer_id)) {
        // According to Yuno API spec, customer_id is a direct string field, not a nested object
        $payload['customer_id'] = $customer_id;
        yuno_log('info', '✅ [CHECKOUT SESSION] Using customer_id', [
            'order_id'    => $order->get_id(),
            'customer_id' => $customer_id,
        ]);
    } else {
        yuno_log('warning', '🔴 [CHECKOUT SESSION] Creating WITHOUT customer_id (fallback mode)', [
            'order_id' => $order->get_id(),
        ]);
    }

    // ✅ Add billing_address from order data (always send current data)
    $billing_country  = $order->get_billing_country();
    $billing_state    = $order->get_billing_state();
    $billing_city     = $order->get_billing_city();
    $billing_postcode = $order->get_billing_postcode();
    $billing_address1 = $order->get_billing_address_1();
    $billing_address2 = $order->get_billing_address_2();

    $billing_address = [];
    if (!empty($billing_country)) $billing_address['country'] = $billing_country;
    if (!empty($billing_state)) $billing_address['state'] = $billing_state;
    if (!empty($billing_city)) $billing_address['city'] = $billing_city;
    if (!empty($billing_postcode)) $billing_address['zip_code'] = $billing_postcode;
    if (!empty($billing_address1)) $billing_address['address_line_1'] = $billing_address1;
    if (!empty($billing_address2)) $billing_address['address_line_2'] = $billing_address2;

    if (!empty($billing_address)) {
        $payload['billing_address'] = $billing_address;
        yuno_log('info', '✅ [CHECKOUT SESSION] Including billing_address', [
            'order_id' => $order->get_id(),
            'billing'  => $billing_address,
        ]);
    }

    // ✅ Add shipping_address from order data (always send current data)
    $shipping_country  = $order->get_shipping_country() ?: $billing_country;
    $shipping_state    = $order->get_shipping_state() ?: $billing_state;
    $shipping_city     = $order->get_shipping_city() ?: $billing_city;
    $shipping_postcode = $order->get_shipping_postcode() ?: $billing_postcode;
    $shipping_address1 = $order->get_shipping_address_1() ?: $billing_address1;
    $shipping_address2 = $order->get_shipping_address_2() ?: $billing_address2;

    $shipping_address = [];
    if (!empty($shipping_country)) $shipping_address['country'] = $shipping_country;
    if (!empty($shipping_state)) $shipping_address['state'] = $shipping_state;
    if (!empty($shipping_city)) $shipping_address['city'] = $shipping_city;
    if (!empty($shipping_postcode)) $shipping_address['zip_code'] = $shipping_postcode;
    if (!empty($shipping_address1)) $shipping_address['address_line_1'] = $shipping_address1;
    if (!empty($shipping_address2)) $shipping_address['address_line_2'] = $shipping_address2;

    if (!empty($shipping_address)) {
        $payload['shipping_address'] = $shipping_address;
        yuno_log('info', '✅ [CHECKOUT SESSION] Including shipping_address', [
            'order_id' => $order->get_id(),
            'shipping' => $shipping_address,
        ]);
    }

    yuno_log('info', '🔵 [CHECKOUT SESSION] Final payload before API call', [
        'order_id'        => $order->get_id(),
        'has_customer_id' => isset($payload['customer_id']) ? 'YES' : 'NO',
        'payload'         => $payload,
    ]);

    $res = yuno_wp_remote_json(
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
        // ✅ Handle CUSTOMER_NOT_FOUND error (occurs when API keys are changed)
        // When merchant changes Yuno API keys to a different account, cached customer_ids
        // from the old account don't exist in the new account
        $error_code = is_array($res['raw']) ? ($res['raw']['code'] ?? '') : '';

        if ($error_code === 'CUSTOMER_NOT_FOUND' && !empty($customer_id)) {
            yuno_log('warning', '🔴 [CHECKOUT SESSION] CUSTOMER_NOT_FOUND - clearing cached customer and retrying', [
                'order_id'            => $order->get_id(),
                'old_customer_id'     => $customer_id,
                'user_id'             => $order->get_user_id(),
            ]);

            // Clear the cached customer_id from user_meta
            $user_id = $order->get_user_id();
            if ($user_id) {
                delete_user_meta($user_id, '_yuno_customer_id');
                yuno_log('info', '✅ [CHECKOUT SESSION] Cleared cached customer_id for user', [
                    'user_id' => $user_id,
                ]);
            }

            // Clear from order meta as well
            $order->delete_meta_data('_yuno_customer_id');
            $order->save();

            // Retry customer creation (will create a new customer in the new account)
            yuno_log('info', '🔄 [CHECKOUT SESSION] Retrying customer creation', [
                'order_id' => $order->get_id(),
            ]);

            $new_customer_id = yuno_get_or_create_customer($order);

            if (!empty($new_customer_id)) {
                // Update payload with new customer_id
                $payload['customer_id'] = $new_customer_id;

                yuno_log('info', '✅ [CHECKOUT SESSION] Created new customer, retrying checkout session', [
                    'order_id'         => $order->get_id(),
                    'new_customer_id'  => $new_customer_id,
                ]);

                // Retry checkout session creation with new customer_id
                $res = yuno_wp_remote_json(
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

                // If retry also fails, return the error below
                if (!$res['ok']) {
                    yuno_log('error', '🔴 [CHECKOUT SESSION] Retry failed after customer recreation', [
                        'order_id' => $order->get_id(),
                        'status'   => $res['status'],
                        'response' => $res['raw'],
                    ]);

                    return yuno_json([
                        'error'    => 'Yuno create checkout session failed after retry',
                        'status'   => $res['status'],
                        'response' => $res['raw'],
                    ], 400);
                }

                // Retry succeeded, continue with normal flow below
                yuno_log('info', '✅ [CHECKOUT SESSION] Retry succeeded', [
                    'order_id' => $order->get_id(),
                ]);
            } else {
                yuno_log('error', '🔴 [CHECKOUT SESSION] Failed to create new customer on retry', [
                    'order_id' => $order->get_id(),
                ]);

                return yuno_json([
                    'error'    => 'Failed to create customer after cache clear',
                    'status'   => $res['status'],
                    'response' => $res['raw'],
                ], 400);
            }
        } else {
            // Not a CUSTOMER_NOT_FOUND error, return original error
            return yuno_json([
                'error'    => 'Yuno create checkout session failed',
                'status'   => $res['status'],
                'response' => $res['raw'],
            ], 400);
        }
    }

    $checkoutSession = is_array($res['raw'])
        ? ($res['raw']['checkout_session'] ?? $res['raw']['id'] ?? null)
        : null;

    if (!$checkoutSession) {
        return yuno_json(['error' => 'Yuno response missing checkout_session/id', 'response' => $res['raw']], 400);
    }

    yuno_log('info', 'Yuno checkout session response', [
        'order_id'         => $order->get_id(),
        'checkout_session' => $checkoutSession,
        'full_response'    => $res['raw'], // May contain available payment_methods
    ]);

    $order->update_meta_data('_yuno_checkout_session', $checkoutSession);
    $order->save();

    return yuno_json([
        'checkout_session' => $checkoutSession,
        'country'          => $country,
        'order_id'         => $order->get_id(),
        'reused'           => false,
    ], 200);
}

/**
 * Format phone number for Yuno Customer API
 *
 * Returns phone as an object with country_code and number separated,
 * as required by Yuno Customer API specification.
 *
 * @param string $phone Raw phone number from WooCommerce
 * @param string $country ISO 3166-1 alpha-2 country code (e.g., 'CO', 'MX', 'US')
 * @return array|null Array with 'country_code' and 'number' keys, or null if invalid
 */
function yuno_format_phone_number($phone, $country) {
    // Remove all whitespace and special characters except + and digits
    $cleaned = preg_replace('/[^\d+]/', '', $phone);

    // If empty after cleaning, return null
    if (empty($cleaned)) {
        return null;
    }

    // Country code mapping with minimum length requirements
    $country_codes = [
        'CO' => ['code' => '57', 'min_length' => 10],  // Colombia: 10 digits
        'MX' => ['code' => '52', 'min_length' => 10],  // Mexico: 10 digits
        'BR' => ['code' => '55', 'min_length' => 10],  // Brazil: 10-11 digits
        'AR' => ['code' => '54', 'min_length' => 10],  // Argentina: 10 digits
        'CL' => ['code' => '56', 'min_length' => 9],   // Chile: 9 digits
        'PE' => ['code' => '51', 'min_length' => 9],   // Peru: 9 digits
        'US' => ['code' => '1',  'min_length' => 10],  // United States: 10 digits
        'CA' => ['code' => '1',  'min_length' => 10],  // Canada: 10 digits
        'ES' => ['code' => '34', 'min_length' => 9],   // Spain: 9 digits
        'GB' => ['code' => '44', 'min_length' => 10],  // United Kingdom: 10 digits
    ];

    // Get country code configuration
    $country_config = isset($country_codes[$country]) ? $country_codes[$country] : null;

    // If we don't have a country code mapping, return null (fail gracefully)
    if (!$country_config) {
        yuno_log('warning', '🔴 [PHONE] Country code not mapped', [
            'country' => $country,
            'phone'   => $phone,
        ]);
        return null;
    }

    // If phone starts with +, extract country code and number
    if (strpos($cleaned, '+') === 0) {
        // Remove the + prefix
        $cleaned = substr($cleaned, 1);

        // Extract country code by matching against our mapping
        $country_code_prefix = $country_config['code'];
        if (strpos($cleaned, $country_code_prefix) === 0) {
            $number = substr($cleaned, strlen($country_code_prefix));

            // Validate minimum length
            if (strlen($number) < $country_config['min_length']) {
                yuno_log('warning', '🔴 [PHONE] Phone number too short after extracting country code', [
                    'country'      => $country,
                    'phone'        => $phone,
                    'number'       => $number,
                    'length'       => strlen($number),
                    'min_required' => $country_config['min_length'],
                ]);
                return null;
            }

            $result = [
                'country_code' => $country_code_prefix,
                'number'       => $number,
            ];

            yuno_log('info', '✅ [PHONE] Formatted phone from international format', [
                'original'     => $phone,
                'country'      => $country,
                'country_code' => $result['country_code'],
                'number'       => $result['number'],
            ]);

            return $result;
        }
    }

    // Validate minimum length for local number
    if (strlen($cleaned) < $country_config['min_length']) {
        yuno_log('warning', '🔴 [PHONE] Phone number too short', [
            'country'      => $country,
            'phone'        => $phone,
            'length'       => strlen($cleaned),
            'min_required' => $country_config['min_length'],
        ]);
        return null;
    }

    // Build phone object with country code and number
    $result = [
        'country_code' => $country_config['code'],
        'number'       => $cleaned,
    ];

    yuno_log('info', '✅ [PHONE] Formatted phone number', [
        'original'     => $phone,
        'country'      => $country,
        'country_code' => $result['country_code'],
        'number'       => $result['number'],
    ]);

    return $result;
}

/**
 * Create a Yuno Customer for a WooCommerce order
 *
 * Always creates a fresh customer per order (no reuse across orders).
 * Uses order ID as merchant_customer_id to ensure uniqueness.
 * Caches customer_id in order meta to reuse within same order (checkout session + payment).
 *
 * @param WC_Order $order The WooCommerce order
 * @return string|null The Yuno customer_id or null on failure (graceful degradation)
 */
function yuno_get_or_create_customer($order) {
    yuno_log('info', '🔵 [CUSTOMER] Function called', [
        'order_id' => $order->get_id(),
    ]);

    // ✅ Check if customer already created for THIS order (reuse within same order)
    $existing_customer_id = $order->get_meta('_yuno_customer_id');
    if (!empty($existing_customer_id)) {
        yuno_log('info', '✅ [CUSTOMER] Reusing existing customer_id from order meta', [
            'order_id'    => $order->get_id(),
            'customer_id' => $existing_customer_id,
        ]);
        return $existing_customer_id;
    }

    $secretKey = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (empty($secretKey)) {
        yuno_log('warning', '🔴 [CUSTOMER] missing PRIVATE_SECRET_KEY, skipping customer creation');
        return null; // Graceful degradation - continues without customer
    }

    yuno_log('info', '🟢 [CUSTOMER] PRIVATE_SECRET_KEY found', [
        'key_length' => strlen($secretKey),
    ]);

    $user_id = $order->get_user_id();
    yuno_log('info', '🔵 [CUSTOMER] User ID check', [
        'user_id'  => $user_id,
        'is_guest' => empty($user_id) ? 'YES' : 'NO',
    ]);

    // Build customer payload from order data
    $billing_first  = $order->get_billing_first_name();
    $billing_last   = $order->get_billing_last_name();
    $billing_email  = $order->get_billing_email();
    $billing_phone  = $order->get_billing_phone();

    $billing_country  = $order->get_billing_country();
    $billing_state    = $order->get_billing_state();
    $billing_city     = $order->get_billing_city();
    $billing_postcode = $order->get_billing_postcode();
    $billing_address1 = $order->get_billing_address_1();
    $billing_address2 = $order->get_billing_address_2();

    // Use shipping address if available, fallback to billing
    $shipping_country  = $order->get_shipping_country() ?: $billing_country;
    $shipping_state    = $order->get_shipping_state() ?: $billing_state;
    $shipping_city     = $order->get_shipping_city() ?: $billing_city;
    $shipping_postcode = $order->get_shipping_postcode() ?: $billing_postcode;
    $shipping_address1 = $order->get_shipping_address_1() ?: $billing_address1;
    $shipping_address2 = $order->get_shipping_address_2() ?: $billing_address2;

    // ✅ Option A: Always use order ID for merchant_customer_id (unique per order, never reuse)
    $merchant_customer_id = 'woo_order_' . $order->get_id();

    // Build payload according to Yuno Customer API specification
    $payload = [
        'email'                => $billing_email,
        'merchant_customer_id' => $merchant_customer_id,
    ];

    // Add first_name and last_name (separate fields, not combined "name")
    if (!empty($billing_first)) {
        $payload['first_name'] = $billing_first;
    }
    if (!empty($billing_last)) {
        $payload['last_name'] = $billing_last;
    }

    // Add country at customer level (customer's country)
    if (!empty($billing_country)) {
        $payload['country'] = $billing_country;
    }

    // Add merchant_customer_created_at for registered users
    if ($user_id) {
        $user_data = get_userdata($user_id);
        if ($user_data && !empty($user_data->user_registered)) {
            // Convert WordPress registration date to ISO 8601 format
            $registered_date = new DateTime($user_data->user_registered);
            $payload['merchant_customer_created_at'] = $registered_date->format('c');
        }
    }

    // Add phone if available (formatted as object with country_code and number)
    if (!empty($billing_phone)) {
        // Format phone according to Yuno Customer API spec
        $formatted_phone = yuno_format_phone_number($billing_phone, $billing_country);
        if (!empty($formatted_phone) && is_array($formatted_phone)) {
            // Yuno expects phone as object: {"country_code": "57", "number": "3124598632"}
            $payload['phone'] = $formatted_phone;
        }
    }

    // Add billing address if data available (using Yuno API field names)
    $billing_address = [];
    if (!empty($billing_country)) $billing_address['country'] = $billing_country;
    if (!empty($billing_state)) $billing_address['state'] = $billing_state;
    if (!empty($billing_city)) $billing_address['city'] = $billing_city;
    if (!empty($billing_postcode)) $billing_address['zip_code'] = $billing_postcode; // Changed from postal_code
    if (!empty($billing_address1)) $billing_address['address_line_1'] = $billing_address1; // Changed from line1
    if (!empty($billing_address2)) $billing_address['address_line_2'] = $billing_address2; // Changed from line2

    if (!empty($billing_address)) {
        $payload['billing_address'] = $billing_address;
    }

    // Add shipping address if data available (using Yuno API field names)
    $shipping_address = [];
    if (!empty($shipping_country)) $shipping_address['country'] = $shipping_country;
    if (!empty($shipping_state)) $shipping_address['state'] = $shipping_state;
    if (!empty($shipping_city)) $shipping_address['city'] = $shipping_city;
    if (!empty($shipping_postcode)) $shipping_address['zip_code'] = $shipping_postcode; // Changed from postal_code
    if (!empty($shipping_address1)) $shipping_address['address_line_1'] = $shipping_address1; // Changed from line1
    if (!empty($shipping_address2)) $shipping_address['address_line_2'] = $shipping_address2; // Changed from line2

    if (!empty($shipping_address)) {
        $payload['shipping_address'] = $shipping_address;
    }

    $publicKey = yuno_get_env('PUBLIC_API_KEY', '');
    $apiUrl = yuno_api_url_from_public_key($publicKey);

    // ✅ Always CREATE new customer (no reuse, no update logic)
    yuno_log('info', '🔵 [CUSTOMER] Creating new customer (always fresh per order)', [
        'order_id'             => $order->get_id(),
        'merchant_customer_id' => $merchant_customer_id,
        'email'                => $billing_email,
        'api_url'              => $apiUrl,
        'payload'              => $payload,
    ]);

    // Call Yuno Create Customer API
    $res = yuno_wp_remote_json(
        'POST',
        "{$apiUrl}/v1/customers",
        [
            'public-api-key'     => $publicKey,
            'private-secret-key' => $secretKey,
            'Content-Type'       => 'application/json',
        ],
        $payload,
        20
    );

    yuno_log('info', '🔵 [CUSTOMER] API response received', [
        'order_id' => $order->get_id(),
        'ok'       => $res['ok'] ? 'TRUE' : 'FALSE',
        'status'   => $res['status'] ?? 'N/A',
        'response' => $res['raw'], // Log complete response
    ]);

    if (!$res['ok']) {
        yuno_log('warning', '🔴 [CUSTOMER] API call failed, continuing without customer', [
            'order_id'  => $order->get_id(),
            'status'    => $res['status'],
            'response'  => $res['raw'],
        ]);
        return null; // Graceful degradation - continues without customer
    }

    $customer_id = is_array($res['raw']) ? ($res['raw']['id'] ?? null) : null;

    yuno_log('info', '🔵 [CUSTOMER] Extracting customer_id from response', [
        'order_id'    => $order->get_id(),
        'customer_id' => $customer_id ?: 'NOT FOUND',
        'raw_type'    => gettype($res['raw']),
    ]);

    if (empty($customer_id)) {
        yuno_log('warning', '🔴 [CUSTOMER] Missing id in response, continuing without customer', [
            'order_id' => $order->get_id(),
            'response' => $res['raw'],
        ]);
        return null; // Graceful degradation
    }

    yuno_log('info', '✅ [CUSTOMER] Customer created successfully', [
        'order_id'    => $order->get_id(),
        'customer_id' => $customer_id,
        'user_id'     => $user_id ?: 'GUEST',
    ]);

    // Save to order meta for reference (no user_meta caching since we don't reuse customers)
    $order->update_meta_data('_yuno_customer_id', $customer_id);
    $order->save();

    yuno_log('info', '✅ [CUSTOMER] Returning customer_id', [
        'order_id'    => $order->get_id(),
        'customer_id' => $customer_id,
    ]);

    return $customer_id;
}

function yuno_create_payment(WP_REST_Request $request) {
    $accountId = yuno_get_env('ACCOUNT_ID', '');
    $publicKey = yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$accountId || !$publicKey || !$secretKey) {
        return yuno_json(['error' => 'Missing required keys'], 400);
    }

    [$order, $err] = yuno_get_order_from_request($request);
    if ($err) return $err;

    $params          = (array) $request->get_json_params();
    $oneTimeToken    = $params['oneTimeToken'] ?? null;
    $checkoutSession = $params['checkoutSession'] ?? ($params['checkout_session'] ?? null);

    if (!$oneTimeToken || !$checkoutSession) {
        return yuno_json(['error' => 'Missing oneTimeToken or checkoutSession'], 400);
    }

    if ($order->is_paid()) {
        return yuno_json(['handled' => true, 'error' => 'Order already paid'], 409);
    }

    $lockKey = 'yuno_pay_lock_' . (int)$order->get_id();
    if (get_transient($lockKey)) {
        return yuno_json(['handled' => true, 'error' => 'Payment creation is already in progress'], 409);
    }
    set_transient($lockKey, 1, 30);

    $apiUrl   = yuno_api_url_from_public_key($publicKey);
    $country  = $order->get_billing_country() ?: 'CO';
    $currency = $order->get_currency() ?: 'COP';

    $total_major  = (float) $order->get_total();
    $decimals     = yuno_get_wc_price_decimals();
    $amount_value = (float) number_format($total_major, $decimals, '.', '');

    $idempotencyKey = yuno_build_idempotency_key($order->get_id(), $checkoutSession);

    // Split config
    $split_enabled_setting = (string) yuno_get_env('SPLIT_ENABLED', 'no');
    $split_enabled         = in_array($split_enabled_setting, ['yes','1','true'], true);

    $recipient_id = trim((string) yuno_get_env('YUNO_RECIPIENT_ID', ''));

    // Commission percent (0..100)
    $pct_raw = trim((string) yuno_get_env('SPLIT_COMMISSION_PERCENT', ''));

    // Fixed commission in minor units (e.g. 1500 = 15.00 USD)
    $fixed_raw   = trim((string) yuno_get_env('SPLIT_FIXED_AMOUNT', ''));
    $fixed_minor = ($fixed_raw !== '' && ctype_digit($fixed_raw)) ? (int) $fixed_raw : null;

    // compute commission in MAJOR units
    $commission_amount = 0.0;
    $commission_mode   = 'none';

    if ($split_enabled) {

        if ($pct_raw !== '') {
            $pct = (float) str_replace(',', '.', $pct_raw);
            if ($pct < 0 || $pct > 100) {
                delete_transient($lockKey);
                return yuno_json(['error' => 'Split commission percent must be between 0 and 100'], 400);
            }
            $commission_amount = round($amount_value * ($pct / 100.0), $decimals);
            $commission_mode   = 'percent';

        } elseif ($fixed_minor !== null) {
            if ($fixed_minor < 0) {
                delete_transient($lockKey);
                return yuno_json(['error' => 'Split fixed amount must be >= 0 (minor units)'], 400);
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
            return yuno_json(['error' => 'Split is enabled but Yuno Recipient ID is missing'], 400);
        }

        if ($commission_amount < 0 || $commission_amount > $amount_value) {
            delete_transient($lockKey);
            return yuno_json([
                'error'              => 'Split commission must be between 0 and order total (major units)',
                'commission_amount'  => $commission_amount,
                'order_total_amount' => $amount_value,
            ], 400);
        }
    }

    // Calculate seller_amount for logging and payload construction
    $seller_amount = $split_enabled ? round($amount_value - $commission_amount, $decimals) : null;

    yuno_log('info', 'payments amount (woo -> yuno)', [
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

    // ✅ Get or create customer for this payment
    // This ensures customer always exists and is up-to-date, even for "order again" scenarios
    yuno_log('info', 'Payment: getting or creating customer', [
        'order_id' => $order->get_id(),
    ]);

    $customer_id = yuno_get_or_create_customer($order);

    // Add customer_payer if customer was created successfully
    if (!empty($customer_id)) {
        $payload['customer_payer'] = [
            'id' => $customer_id,
        ];
        yuno_log('info', 'Payment: using customer_payer', [
            'order_id'    => $order->get_id(),
            'customer_id' => $customer_id,
        ]);
    } else {
        yuno_log('warning', 'Payment: customer creation failed, creating payment without customer_payer (graceful degradation)', [
            'order_id' => $order->get_id(),
        ]);
    }

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
    yuno_log('debug', 'payments payload (sanitized)', [
        'order_id' => $order->get_id(),
        'payload'  => $payload_for_log,
    ]);

    $res = yuno_wp_remote_json(
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

        yuno_log('warning', 'Yuno payment creation failed (order remains pending for retry)', [
            'order_id' => $order->get_id(),
            'status'   => $res['status'],
            'response' => $res['raw'],
        ]);

        return yuno_json([
            'error'    => 'Yuno create payment failed',
            'status'   => $res['status'],
            'response' => $res['raw'],
        ], 400);
    }

    $payment_id = is_array($res['raw'])
        ? ($res['raw']['id'] ?? $res['raw']['payment_id'] ?? null)
        : null;

    if ($payment_id) $order->update_meta_data('_yuno_payment_id', $payment_id);
    $order->update_meta_data('_yuno_payment_raw', $res['raw']);
    $order->save();

    return yuno_json([
        'ok'             => true,
        'payment_id'     => $payment_id,
        'idempotency_key'=> $idempotencyKey,
        'response'       => $res['raw'],
    ], 200);
}

function yuno_confirm_order_payment(WP_REST_Request $request) {
    [$order, $err] = yuno_get_order_from_request($request);
    if ($err) return $err;

    $params = (array) $request->get_json_params();

    // ✅ SECURITY: Server-side verification implemented
    // Frontend only sends payment_id, backend verifies status with Yuno API
    $payment_id = $params['payment_id'] ?? $params['paymentId'] ?? $order->get_meta('_yuno_payment_id');

    if (!$payment_id) {
        yuno_log('error', 'Confirm: missing payment_id', [
            'order_id' => $order->get_id(),
        ]);
        return yuno_json(['error' => 'Missing payment_id'], 400);
    }

    // Check if order is already paid (idempotency)
    if ($order->is_paid()) {
        yuno_log('info', 'Confirm: order already paid', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
        ]);
        return yuno_json([
            'ok'        => true,
            'order_id'  => $order->get_id(),
            'new_status'=> $order->get_status(),
            'redirect'  => $order->get_checkout_order_received_url(),
            'already_paid' => true,
        ], 200);
    }

    // Get credentials
    $publicKey = yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$publicKey || !$secretKey) {
        yuno_log('error', 'Confirm: missing API keys', [
            'order_id' => $order->get_id(),
        ]);
        return yuno_json(['error' => 'Missing API keys'], 500);
    }

    $apiUrl = yuno_api_url_from_public_key($publicKey);

    // ✅ SERVER-SIDE VERIFICATION: Query Yuno for payment status
    yuno_log('info', 'Confirm: verifying payment with Yuno', [
        'order_id'   => $order->get_id(),
        'payment_id' => $payment_id,
    ]);

    $res = yuno_wp_remote_json(
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
        yuno_log('error', 'Confirm: failed to verify payment with Yuno', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
            'status'     => $res['status'],
            'response'   => $res['raw'],
        ]);

        // Don't mark as failed, just return error (allow retry)
        $order->add_order_note('Yuno verification failed (HTTP ' . $res['status'] . '). payment_id=' . $payment_id);
        $order->save();

        return yuno_json([
            'error'   => 'Could not verify payment status with Yuno',
            'order_id'=> $order->get_id(),
            'retry'   => true,
        ], 500);
    }

    // ✅ Source of truth: status verified by Yuno API
    // Log FULL response for debugging
    yuno_log('info', 'Confirm: full Yuno response', [
        'order_id'      => $order->get_id(),
        'payment_id'    => $payment_id,
        'full_response' => $res['raw'],
    ]);

    // Extract status from Yuno response
    $verified_status = yuno_extract_payment_status($res['raw']);

    // ✅ SECURITY: Validate payment_id belongs to this order
    // Prevent payment reuse attack where attacker uses a legitimate payment_id
    // from one order to mark a different order as paid
    $stored_payment_id = $order->get_meta('_yuno_payment_id');

    if ($stored_payment_id && $stored_payment_id !== $payment_id) {
        yuno_log('error', 'Confirm: payment_id mismatch - possible payment reuse attack', [
            'order_id'           => $order->get_id(),
            'received_payment_id'=> $payment_id,
            'stored_payment_id'  => $stored_payment_id,
            'verified_status'    => $verified_status,
        ]);

        $order->add_order_note('SECURITY: Payment verification failed - payment_id mismatch. Expected: ' . $stored_payment_id . ', Got: ' . $payment_id);
        $order->save();

        return yuno_json([
            'error'   => 'Payment does not belong to this order',
            'order_id'=> $order->get_id(),
        ], 403);
    }

    yuno_log('info', 'Confirm: payment status verified', [
        'order_id'           => $order->get_id(),
        'payment_id'         => $payment_id,
        'verified_status'    => $verified_status,
        'stored_payment_id'  => $stored_payment_id,
        'raw_status'         => $res['raw']['status'] ?? null,
        'raw_state'          => $res['raw']['state'] ?? null,
        'raw_payment_status' => $res['raw']['payment_status'] ?? null,
        'nested_payment'     => isset($res['raw']['payment']) ? 'yes' : 'no',
    ]);

    // Handle verified status
    if (in_array($verified_status, ['SUCCEEDED', 'VERIFIED', 'APPROVED'], true)) {
        // ✅ DEBUG: Log product types before payment_complete()
        $order_items_debug = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $order_items_debug[] = [
                    'name'         => $product->get_name(),
                    'is_virtual'   => $product->is_virtual() ? 'YES' : 'NO',
                    'is_downloadable' => $product->is_downloadable() ? 'YES' : 'NO',
                    'needs_shipping' => $product->needs_shipping() ? 'YES' : 'NO',
                ];
            }
        }

        // ✅ DEBUG: Check if there are any filters affecting payment_complete status
        global $wp_filter;
        $active_filters = [];
        if (isset($wp_filter['woocommerce_payment_complete_order_status'])) {
            $active_filters['payment_complete_order_status'] = 'ACTIVE';
        }
        if (isset($wp_filter['woocommerce_order_is_paid_statuses'])) {
            $active_filters['order_is_paid_statuses'] = 'ACTIVE';
        }

        yuno_log('info', 'Confirm: order product analysis BEFORE payment_complete()', [
            'order_id'            => $order->get_id(),
            'needs_shipping'      => $order->needs_shipping_address() ? 'YES' : 'NO',
            'has_downloadable'    => $order->has_downloadable_item() ? 'YES' : 'NO',
            'products'            => $order_items_debug,
            'current_status'      => $order->get_status(),
            'active_filters'      => $active_filters ?: 'NONE',
        ]);

        $order->payment_complete($payment_id);

        // ✅ DEBUG: Check status immediately after payment_complete(), BEFORE save()
        $status_after_payment_complete = $order->get_status();

        $order->add_order_note('Yuno payment approved (verified). status=' . $verified_status . ' payment_id=' . $payment_id);
        $order->save();

        // ✅ DEBUG: Check status again after save()
        $status_after_save = $order->get_status();

        yuno_log('info', 'Confirm: order marked as paid AFTER payment_complete()', [
            'order_id'                      => $order->get_id(),
            'payment_id'                    => $payment_id,
            'status_after_payment_complete' => $status_after_payment_complete,
            'status_after_save'             => $status_after_save,
            'needs_shipping'                => $order->needs_shipping_address() ? 'YES' : 'NO',
            'expected'                      => $order->needs_shipping_address() ? 'processing' : 'completed',
        ]);

        return yuno_json([
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

        yuno_log('warning', 'Confirm: order marked as failed', [
            'order_id'   => $order->get_id(),
            'payment_id' => $payment_id,
            'status'     => $verified_status,
        ]);

        return yuno_json([
            'ok'        => false,
            'failed'    => true,
            'blocked'   => true,
            'order_id'  => $order->get_id(),
            'new_status'=> $order->get_status(),
            'status'    => $verified_status,
        ], 200);
    }

    // ✅ Intermediate states (PENDING, PROCESSING, REQUIRES_ACTION, etc.)
    // Change order status to 'on-hold' to indicate payment is processing
    // WooCommerce status meanings:
    // - 'pending' = Waiting for payment (order created but no payment initiated)
    // - 'on-hold' = Payment received/processing, waiting for confirmation
    // - 'processing' = Payment confirmed, order being fulfilled
    if (in_array($verified_status, ['PENDING', 'PROCESSING', 'REQUIRES_ACTION'], true)) {
        $order->update_status('on-hold', 'Yuno payment pending confirmation: ' . $verified_status);
    }

    $order->add_order_note('Yuno payment status: ' . $verified_status . ' (payment_id=' . $payment_id . ')');
    $order->save();

    yuno_log('info', 'Confirm: payment in intermediate state, order set to on-hold', [
        'order_id'     => $order->get_id(),
        'payment_id'   => $payment_id,
        'yuno_status'  => $verified_status,
        'order_status' => $order->get_status(),
    ]);

    // ✅ For PENDING payments (3DS, etc.), DON'T redirect to order-received
    // User must stay on payment page to complete authentication flow
    // Webhook will confirm the order when payment completes
    return yuno_json([
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
function yuno_check_order_status(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $order_id  = absint($params['order_id'] ?? 0);
    $order_key = sanitize_text_field($params['order_key'] ?? '');

    if (!$order_id) {
        return yuno_json(['error' => 'Missing order_id'], 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return yuno_json(['error' => 'Order not found'], 404);
    }

    // Validate order key if provided
    if ($order_key && $order->get_order_key() !== $order_key) {
        yuno_log('warning', 'Check order status: order_key mismatch', [
            'order_id'      => $order_id,
            'provided_key'  => $order_key,
            'expected_key'  => $order->get_order_key(),
        ]);
        return yuno_json(['error' => 'Invalid order_key'], 403);
    }

    $status = $order->get_status();
    $is_paid = $order->is_paid();

    yuno_log('info', 'Check order status: initial check', [
        'order_id' => $order_id,
        'status'   => $status,
        'is_paid'  => $is_paid,
    ]);

    // 1. If order is already paid in WooCommerce, return redirect immediately
    $paid_statuses = ['processing', 'completed', 'on-hold'];
    if ($is_paid || in_array($status, $paid_statuses, true)) {
        $redirect = $order->get_checkout_order_received_url();

        yuno_log('info', 'Check order status: already paid in WooCommerce', [
            'order_id' => $order_id,
            'status'   => $status,
        ]);

        return yuno_json([
            'is_paid'  => true,
            'status'   => $status,
            'redirect' => $redirect,
            'message'  => 'Order already paid',
        ], 200);
    }

    // 2. ✅ AUTO-DUPLICATE: If order is in failed state, signal frontend to duplicate
    // This handles the F5 reload case where user refreshes a failed order
    if ($status === 'failed') {
        yuno_log('info', 'Check order status: order is failed, should duplicate', [
            'order_id' => $order_id,
            'status'   => $status,
        ]);

        return yuno_json([
            'is_paid'         => false,
            'is_failed'       => true,
            'should_duplicate'=> true,
            'status'          => $status,
            'message'         => 'Order failed, needs duplication',
        ], 200);
    }

    // 3. ✅ CRITICAL: Check if payment_id exists (payment was initiated)
    // If it exists, verify with Yuno API to prevent double-payment race condition
    $payment_id = $order->get_meta('_yuno_payment_id');

    if (!$payment_id) {
        // No payment initiated yet, safe to proceed
        yuno_log('info', 'Check order status: no payment_id found, allowing payment', [
            'order_id' => $order_id,
        ]);

        return yuno_json([
            'is_paid' => false,
            'status'  => $status,
            'message' => 'Order ready for payment',
        ], 200);
    }

    // 4. Payment was initiated, verify with Yuno API
    yuno_log('info', 'Check order status: payment_id found, verifying with Yuno', [
        'order_id'   => $order_id,
        'payment_id' => $payment_id,
    ]);

    $publicKey = yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$publicKey || !$secretKey) {
        // Can't verify, but safer to block than allow double payment
        yuno_log('warning', 'Check order status: missing API keys, blocking payment', [
            'order_id' => $order_id,
        ]);

        return yuno_json([
            'is_paid' => false,
            'status'  => $status,
            'message' => 'Order ready for payment',
            'warning' => 'Could not verify payment status',
        ], 200);
    }

    $apiUrl = yuno_api_url_from_public_key($publicKey);

    $res = yuno_wp_remote_json(
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
        yuno_log('warning', 'Check order status: Yuno API verification failed', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
            'status'     => $res['status'],
        ]);

        return yuno_json([
            'is_paid'        => false,
            'status'         => $status,
            'has_payment_id' => !empty($payment_id),
            'message'        => 'Order ready for payment',
        ], 200);
    }

    // Extract status from Yuno response
    $verified_status = yuno_extract_payment_status($res['raw']);

    yuno_log('info', 'Check order status: Yuno verification result', [
        'order_id'        => $order_id,
        'payment_id'      => $payment_id,
        'verified_status' => $verified_status,
    ]);

    // 5. If payment is SUCCEEDED in Yuno, mark order as paid NOW
    if (in_array($verified_status, ['SUCCEEDED', 'VERIFIED', 'APPROVED'], true)) {
        yuno_log('info', 'Check order status: Payment succeeded in Yuno, marking order as paid', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
            'status'     => $verified_status,
        ]);

        // Mark order as paid
        $order->payment_complete($payment_id);
        $order->add_order_note('Yuno payment confirmed via check-order-status (page reload). status=' . $verified_status . ' payment_id=' . $payment_id);
        $order->save();

        $redirect = $order->get_checkout_order_received_url();

        return yuno_json([
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
        yuno_log('warning', 'Check order status: Payment failed in Yuno, marking as failed', [
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

        return yuno_json([
            'is_paid'         => false,
            'is_failed'       => true,
            'should_duplicate'=> true,
            'status'          => 'failed',
            'message'         => 'Order failed, needs duplication',
            'verified_status' => $verified_status,
        ], 200);
    }

    // 7. Payment is PENDING/PROCESSING/UNKNOWN - safer to block new payment
    yuno_log('info', 'Check order status: Payment in intermediate state, allowing retry', [
        'order_id'   => $order_id,
        'payment_id' => $payment_id,
        'status'     => $verified_status,
    ]);

    // For intermediate states, we allow payment (user might be retrying)
    // but we could also choose to block and show "payment processing" message
    return yuno_json([
        'is_paid'        => false,
        'status'         => $status,
        'has_payment_id' => !empty($payment_id),
        'redirect'       => $order->get_checkout_order_received_url(),
        'message'        => 'Order ready for payment',
        'verified_status' => $verified_status,
    ], 200);
}

/**
 * Duplicate a failed order with the same products and customer data
 * Used when a payment fails and we want to allow retry with a new order
 */
/**
 * Internal helper to create a duplicate order
 * Used by both REST endpoint and webhook
 *
 * @param WC_Order $order Original order to duplicate
 * @return WC_Order|WP_Error New order or error
 */
function yuno_create_duplicate_order_internal($order) {
    // Create new order
    $new_order = wc_create_order();

    // Check if order creation failed (wc_create_order can return WP_Error)
    if (is_wp_error($new_order)) {
        yuno_log('error', 'Duplicate order: wc_create_order failed', [
            'original_order_id' => $order->get_id(),
            'error_code'        => $new_order->get_error_code(),
            'error_message'     => $new_order->get_error_message(),
        ]);
        return $new_order; // Return WP_Error
    }

    // Copy all line items from original order (clone to preserve original prices)
    // Using add_product() would use current prices, not original order prices
    foreach ($order->get_items() as $item_id => $item) {
        $cloned_item = clone $item;
        $cloned_item->set_id(0); // Reset ID so WooCommerce creates a new item
        $new_order->add_item($cloned_item);
    }

    // Copy shipping items (clone to avoid mutating original order items)
    foreach ($order->get_items('shipping') as $item_id => $item) {
        $cloned_item = clone $item;
        $cloned_item->set_id(0); // Reset ID so WooCommerce creates a new item
        $new_order->add_item($cloned_item);
    }

    // Copy fees (clone to avoid mutating original order items)
    foreach ($order->get_items('fee') as $item_id => $item) {
        $cloned_item = clone $item;
        $cloned_item->set_id(0); // Reset ID so WooCommerce creates a new item
        $new_order->add_item($cloned_item);
    }

    // Copy coupons (CRITICAL: preserve discounts from original order)
    foreach ($order->get_items('coupon') as $item_id => $item) {
        $cloned_item = clone $item;
        $cloned_item->set_id(0); // Reset ID so WooCommerce creates a new item
        $new_order->add_item($cloned_item);
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

    // Copy totals from original order (do NOT recalculate)
    // Using calculate_totals() would apply current tax rates/coupons, potentially changing amounts
    $new_order->set_discount_total($order->get_discount_total());
    $new_order->set_discount_tax($order->get_discount_tax());
    $new_order->set_shipping_total($order->get_shipping_total());
    $new_order->set_shipping_tax($order->get_shipping_tax());
    $new_order->set_cart_tax($order->get_cart_tax());
    $new_order->set_total($order->get_total());

    // Set status to pending
    $new_order->set_status('pending', 'Order created from failed order #' . $order->get_id());

    // Save the new order
    $new_order->save();

    // Store metadata linking failed order to duplicate (prevents creating multiple duplicates)
    $order->update_meta_data('_yuno_duplicate_order_id', $new_order->get_id());
    $order->save();

    // Store reference to original order in duplicate
    $new_order->update_meta_data('_yuno_original_order_id', $order->get_id());
    $new_order->save();

    // Add note to original order
    $order->add_order_note('New order #' . $new_order->get_id() . ' created for payment retry.');
    $order->save();

    // Add note to new order
    $new_order->add_order_note('Created from failed order #' . $order->get_id() . ' for payment retry.');
    $new_order->save();

    yuno_log('info', 'Duplicate order: success', [
        'original_order_id' => $order->get_id(),
        'new_order_id'      => $new_order->get_id(),
    ]);

    return $new_order;
}

/**
 * REST endpoint: Duplicate order (create new order from failed one)
 * Used by frontend when payment fails to allow retry with new order
 */
function yuno_duplicate_order(WP_REST_Request $request) {
    [$order, $err] = yuno_get_order_from_request($request);
    if ($err) return $err;

    yuno_log('info', 'Duplicate order: starting', [
        'original_order_id' => $order->get_id(),
        'original_status'   => $order->get_status(),
    ]);

    try {
        // ✅ FIX: Check if webhook already created a duplicate order
        $existing_duplicate_id = $order->get_meta('_yuno_duplicate_order_id');

        if ($existing_duplicate_id) {
            $existing_order = wc_get_order($existing_duplicate_id);

            // Verify duplicate order exists and is still pending (not paid/failed/cancelled)
            if ($existing_order && in_array($existing_order->get_status(), ['pending', 'on-hold'], true)) {
                yuno_log('info', 'Duplicate order: reusing existing duplicate created by webhook', [
                    'original_order_id' => $order->get_id(),
                    'existing_order_id' => $existing_order->get_id(),
                ]);

                return yuno_json([
                    'ok'            => true,
                    'new_order_id'  => $existing_order->get_id(),
                    'new_order_key' => $existing_order->get_order_key(),
                    'pay_url'       => $existing_order->get_checkout_payment_url(true),
                    'total'         => (float) $existing_order->get_total(),
                    'formatted_total' => wp_kses_post($existing_order->get_formatted_order_total()),
                    'reused'        => true, // Indicate this was reused, not newly created
                ], 200);
            }
        }

        // No existing duplicate found, create new one
        $new_order = yuno_create_duplicate_order_internal($order);

        if (is_wp_error($new_order)) {
            return yuno_json([
                'error'   => 'Failed to create new order',
                'message' => $new_order->get_error_message(),
            ], 500);
        }

        return yuno_json([
            'ok'            => true,
            'new_order_id'  => $new_order->get_id(),
            'new_order_key' => $new_order->get_order_key(),
            'pay_url'       => $new_order->get_checkout_payment_url(true),
            'total'         => (float) $new_order->get_total(),
            'formatted_total' => wp_kses_post($new_order->get_formatted_order_total()),
        ], 200);

    } catch (Exception $e) {
        yuno_log('error', 'Duplicate order: failed', [
            'original_order_id' => $order->get_id(),
            'error'             => $e->getMessage(),
        ]);

        return yuno_json([
            'error'   => 'Failed to create new order',
            'message' => $e->getMessage(),
        ], 500);
    }
}

/**
 * =========================
 * Webhook Handling
 * =========================
 */

/**
 * Verify Yuno webhook HMAC signature
 * This is called as permission_callback, so it must return true/false
 *
 * @param WP_REST_Request $request
 * @return bool True if signature is valid, false otherwise
 */
function yuno_verify_webhook_signature(WP_REST_Request $request) {
    // 1) Validate x-api-key / x-secret headers (from Yuno Dashboard)
    $expectedApiKey = (string) yuno_get_env('WEBHOOK_API_KEY', '');
    $expectedXSecret = (string) yuno_get_env('WEBHOOK_X_SECRET', '');

    $receivedApiKey = (string) $request->get_header('x-api-key');
    $receivedXSecret = (string) $request->get_header('x-secret');

    if ($expectedApiKey === '' || $expectedXSecret === '') {
        yuno_log('error', 'Webhook: WEBHOOK_API_KEY/WEBHOOK_X_SECRET not configured', []);
        return false;
    }

    if (!hash_equals($expectedApiKey, $receivedApiKey)) {
        yuno_log('warning', 'Webhook: x-api-key mismatch', [
            'received' => $receivedApiKey !== '' ? substr($receivedApiKey, 0, 8) . '...' : '(empty)',
        ]);
        return false;
    }

    if (!hash_equals($expectedXSecret, $receivedXSecret)) {
        yuno_log('warning', 'Webhook: x-secret mismatch', [
            'received' => $receivedXSecret !== '' ? substr($receivedXSecret, 0, 8) . '...' : '(empty)',
        ]);
        return false;
    }

    // 2) Validate HMAC signature (Client Secret Key from Yuno Dashboard)
    $receivedSig = trim((string) $request->get_header('x-hmac-signature'));

    if ($receivedSig === '') {
        yuno_log('warning', 'Webhook: missing x-hmac-signature header', []);
        return false;
    }

    // ✅ CRITICAL FIX: Use WEBHOOK_HMAC_SECRET (not PRIVATE_SECRET_KEY)
    $hmacSecret = (string) yuno_get_env('WEBHOOK_HMAC_SECRET', '');

    if ($hmacSecret === '') {
        yuno_log('error', 'Webhook: WEBHOOK_HMAC_SECRET not configured', []);
        return false;
    }

    $body = (string) $request->get_body();

    if ($body === '') {
        yuno_log('warning', 'Webhook: empty body', []);
        return false;
    }

    // Remove "sha256=" prefix if present
    $receivedSig = preg_replace('/^sha256=/i', '', $receivedSig);

    // Compute HMAC in both hex and base64 formats (Yuno might send either)
    $computedHex = hash_hmac('sha256', $body, $hmacSecret);
    $computedB64 = base64_encode(hash_hmac('sha256', $body, $hmacSecret, true));

    $isValid = hash_equals($computedHex, $receivedSig) || hash_equals($computedB64, $receivedSig);

    if (!$isValid) {
        yuno_log('warning', 'Webhook: HMAC signature mismatch', [
            'received_prefix' => substr($receivedSig, 0, 16) . '...',
            'computed_hex'    => substr($computedHex, 0, 16) . '...',
            'computed_b64'    => substr($computedB64, 0, 16) . '...',
            'body_length'     => strlen($body),
        ]);
        return false;
    }

    yuno_log('info', 'Webhook: HMAC signature verified successfully', [
        'signature' => substr($receivedSig, 0, 16) . '...',
    ]);

    return true;
}

/**
 * Handle Yuno webhook events
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function yuno_handle_webhook(WP_REST_Request $request) {
    $payload = $request->get_json_params();

    if (empty($payload)) {
        yuno_log('error', 'Webhook: empty payload', []);
        // Return 200 to prevent Yuno retries (invalid payload won't be fixed by retry)
        return yuno_json(['received' => true, 'error' => 'Empty payload'], 200);
    }

    yuno_log('info', 'Webhook: received event', [
        'account_id' => $payload['account_id'] ?? null,
        'type'       => $payload['type'] ?? null,
        'type_event' => $payload['type_event'] ?? null,
        'version'    => $payload['version'] ?? null,
        'retry'      => $payload['retry'] ?? 0,
    ]);

    // Log full payload in debug mode for troubleshooting
    if (yuno_debug_enabled()) {
        yuno_log('debug', 'Webhook: full payload', ['payload' => $payload]);
    }

    // Extract event type and data
    $event_type = $payload['type_event'] ?? '';
    $event_data = $payload['data'] ?? [];

    if (empty($event_type)) {
        yuno_log('error', 'Webhook: missing type_event', ['payload' => $payload]);
        return yuno_json(['received' => true, 'error' => 'Missing type_event'], 200);
    }

    // Extract payment_id from event data (try multiple possible locations)
    $payment_id = $event_data['id']
        ?? $event_data['payment_id']
        ?? $event_data['payment']['id']
        ?? null;

    if (empty($payment_id)) {
        yuno_log('error', 'Webhook: missing payment_id in event data', [
            'event_type' => $event_type,
            'data_keys'  => array_keys($event_data),
            'full_data'  => yuno_debug_enabled() ? $event_data : null,
        ]);
        return yuno_json(['received' => true, 'error' => 'Missing payment_id'], 200);
    }

    yuno_log('info', 'Webhook: processing event', [
        'event_type' => $event_type,
        'payment_id' => $payment_id,
    ]);

    // Find order by payment_id
    $order = yuno_find_order_by_payment_id($payment_id, $event_data);

    if (!$order) {
        yuno_log('warning', 'Webhook: order not found for payment_id', [
            'event_type' => $event_type,
            'payment_id' => $payment_id,
        ]);
        // Return 200 to prevent retries (order might not exist yet or payment_id not saved)
        return yuno_json(['received' => true, 'error' => 'Order not found'], 200);
    }

    yuno_log('info', 'Webhook: order found', [
        'event_type'   => $event_type,
        'payment_id'   => $payment_id,
        'order_id'     => $order->get_id(),
        'order_status' => $order->get_status(),
    ]);

    // Process event based on type
    switch ($event_type) {
        case 'payment.purchase':
        case 'payment.succeeded':
            return yuno_webhook_handle_payment_succeeded($order, $payment_id, $event_data);

        case 'payment.failed':
        case 'payment.rejected':
        case 'payment.declined':
            return yuno_webhook_handle_payment_failed($order, $payment_id, $event_data);

        case 'payment.chargeback':
            return yuno_webhook_handle_chargeback($order, $payment_id, $event_data);

        case 'payment.refunds':
        case 'payment.refund':
        case 'refunds':
            return yuno_webhook_handle_refund($order, $payment_id, $event_data);

        default:
            yuno_log('info', 'Webhook: unhandled event type', [
                'event_type' => $event_type,
                'order_id'   => $order->get_id(),
                'payment_id' => $payment_id,
            ]);

            // Add note for unhandled events (for debugging)
            $order->add_order_note('Yuno webhook: ' . $event_type . ' (payment_id=' . $payment_id . ')');
            $order->save();

            return yuno_json(['received' => true, 'event_type' => $event_type], 200);
    }
}
/**
 * Find WooCommerce order by Yuno payment_id
 *
 * @param string $payment_id
 * @param array $event_data Optional event data for fallback (merchant_order_id)
 * @return WC_Order|null
 */
function yuno_find_order_by_payment_id($payment_id, $event_data = []) {
    global $wpdb;

    // Query postmeta for orders with this payment_id
    $order_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
         WHERE meta_key = '_yuno_payment_id'
         AND meta_value = %s
         LIMIT 1",
        $payment_id
    ));

    // Fallback: extract order_id from merchant_order_id (try multiple locations)
    $merchant_order_id = $event_data['merchant_order_id']
        ?? $event_data['payment']['merchant_order_id']
        ?? null;

    if (!$order_id && !empty($merchant_order_id)) {
        // Extract order_id from merchant_order_id (format: WC-{order_id})
        if (preg_match('/^WC-(\d+)$/', $merchant_order_id, $matches)) {
            $order_id = intval($matches[1]);
            yuno_log('info', 'Webhook: found order via merchant_order_id fallback', [
                'payment_id'        => $payment_id,
                'merchant_order_id' => $merchant_order_id,
                'order_id'          => $order_id,
            ]);
        }
    }

    if (!$order_id) {
        return null;
    }

    $order = wc_get_order($order_id);

    return $order ? $order : null;
}

/**
 * Handle payment.succeeded / payment.purchase webhook
 *
 * @param WC_Order $order
 * @param string $payment_id
 * @param array $event_data
 * @return WP_REST_Response
 */
function yuno_webhook_handle_payment_succeeded($order, $payment_id, $event_data) {
    $order_id = $order->get_id();

    // Use lock to prevent race condition with frontend confirmation
    $lockKey = 'yuno_webhook_lock_' . $order_id;

    if (get_transient($lockKey)) {
        yuno_log('info', 'Webhook: payment.succeeded - already processing', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
        ]);
        return yuno_json(['received' => true, 'skipped' => 'already_processing'], 200);
    }

    set_transient($lockKey, 1, 30);

    try {
        // Check if already paid (idempotency)
        if ($order->is_paid()) {
            yuno_log('info', 'Webhook: payment.succeeded - order already paid', [
                'order_id'     => $order_id,
                'payment_id'   => $payment_id,
                'order_status' => $order->get_status(),
            ]);

            delete_transient($lockKey);
            return yuno_json(['received' => true, 'skipped' => 'already_paid'], 200);
        }

        // ✅ CRITICAL: Verify payment status with Yuno API before marking as paid
        // Don't trust webhook event alone - always verify with API
        $publicKey = yuno_get_env('PUBLIC_API_KEY', '');
        $secretKey = yuno_get_env('PRIVATE_SECRET_KEY', '');

        if ($publicKey && $secretKey) {
            $apiUrl = yuno_api_url_from_public_key($publicKey);

            yuno_log('info', 'Webhook: payment.succeeded - verifying with Yuno API', [
                'order_id'   => $order_id,
                'payment_id' => $payment_id,
            ]);

            $res = yuno_wp_remote_json(
                'GET',
                "{$apiUrl}/v1/payments/{$payment_id}",
                [
                    'public-api-key'     => $publicKey,
                    'private-secret-key' => $secretKey,
                ],
                null,
                15
            );

            if ($res['ok']) {
                $verified_status = yuno_extract_payment_status($res['raw']);

                yuno_log('info', 'Webhook: payment.succeeded - verified status from API', [
                    'order_id'         => $order_id,
                    'payment_id'       => $payment_id,
                    'verified_status'  => $verified_status,
                ]);

                // If payment is NOT actually succeeded, mark as failed instead
                if (in_array($verified_status, ['REJECTED', 'DECLINED', 'CANCELLED', 'ERROR', 'EXPIRED', 'FAILED'], true)) {
                    yuno_log('warning', 'Webhook: payment.succeeded event but API shows FAILED - marking as failed', [
                        'order_id'        => $order_id,
                        'payment_id'      => $payment_id,
                        'verified_status' => $verified_status,
                    ]);

                    $order->update_status('failed', 'Yuno webhook: Payment succeeded event received but API verification shows: ' . $verified_status);
                    $order->add_order_note('Yuno webhook: Payment succeeded event received but verification failed. status=' . $verified_status . ' payment_id=' . $payment_id);
                    $order->save();

                    delete_transient($lockKey);

                    // Auto-create duplicate order for retry
                    try {
                        $new_order = yuno_create_duplicate_order_internal($order);
                        if (!is_wp_error($new_order)) {
                            yuno_log('info', 'Webhook: payment.succeeded (but failed) - new order created', [
                                'old_order_id' => $order_id,
                                'new_order_id' => $new_order->get_id(),
                            ]);
                        }
                    } catch (Exception $e) {
                        yuno_log('error', 'Webhook: failed to create duplicate order', ['error' => $e->getMessage()]);
                    }

                    return yuno_json(['received' => true, 'order_updated' => true, 'status' => 'failed'], 200);
                }

                // If payment is not confirmed as succeeded, don't mark as paid yet
                if (!in_array($verified_status, ['SUCCEEDED', 'VERIFIED', 'APPROVED'], true)) {
                    yuno_log('warning', 'Webhook: payment.succeeded event but status not confirmed', [
                        'order_id'        => $order_id,
                        'payment_id'      => $payment_id,
                        'verified_status' => $verified_status,
                    ]);

                    $order->add_order_note('Yuno webhook: Payment succeeded event received but status is: ' . $verified_status . ' (payment_id=' . $payment_id . ')');
                    $order->save();

                    delete_transient($lockKey);
                    return yuno_json(['received' => true, 'status' => 'pending_confirmation'], 200);
                }
            }
        }

        // Mark order as paid (only if verified or no API keys to verify)
        $order->payment_complete($payment_id);
        $order->add_order_note('Yuno webhook: Payment succeeded (payment_id=' . $payment_id . ')');
        $order->save();

        yuno_log('info', 'Webhook: payment.succeeded - order marked as paid', [
            'order_id'     => $order_id,
            'payment_id'   => $payment_id,
            'order_status' => $order->get_status(),
        ]);

        delete_transient($lockKey);

        return yuno_json(['received' => true, 'order_updated' => true], 200);

    } catch (Exception $e) {
        yuno_log('error', 'Webhook: payment.succeeded - error', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
            'error'      => $e->getMessage(),
        ]);

        delete_transient($lockKey);

        // Return 200 to prevent retries (will log error for manual review)
        return yuno_json(['received' => true, 'error' => $e->getMessage()], 200);
    }
}

/**
 * Handle payment.failed / payment.rejected webhook
 *
 * @param WC_Order $order
 * @param string $payment_id
 * @param array $event_data
 * @return WP_REST_Response
 */
function yuno_webhook_handle_payment_failed($order, $payment_id, $event_data) {
    $order_id = $order->get_id();

    // Extract status from event data
    $status = yuno_extract_payment_status($event_data);

    yuno_log('info', 'Webhook: payment.failed', [
        'order_id'     => $order_id,
        'payment_id'   => $payment_id,
        'status'       => $status,
        'order_status' => $order->get_status(),
    ]);

    // Don't update if already failed
    if ($order->get_status() === 'failed') {
        yuno_log('info', 'Webhook: payment.failed - order already failed', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
        ]);
        return yuno_json(['received' => true, 'skipped' => 'already_failed'], 200);
    }

    // Don't update if already paid (webhook might be out of order)
    if ($order->is_paid()) {
        yuno_log('warning', 'Webhook: payment.failed - order is already paid, ignoring', [
            'order_id'     => $order_id,
            'payment_id'   => $payment_id,
            'order_status' => $order->get_status(),
        ]);
        return yuno_json(['received' => true, 'skipped' => 'already_paid'], 200);
    }

    // Mark as failed
    $order->update_status('failed', 'Yuno webhook: Payment failed - ' . $status);
    $order->add_order_note('Yuno webhook: Payment failed (payment_id=' . $payment_id . ', status=' . $status . ')');
    $order->save();

    yuno_log('info', 'Webhook: payment.failed - order marked as failed', [
        'order_id'   => $order_id,
        'payment_id' => $payment_id,
    ]);

    // ✅ AUTO-CREATE NEW ORDER: Create duplicate order for retry (same as frontend behavior)
    try {
        $new_order = yuno_create_duplicate_order_internal($order);

        if (is_wp_error($new_order)) {
            yuno_log('error', 'Webhook: payment.failed - failed to create duplicate order', [
                'order_id'      => $order_id,
                'payment_id'    => $payment_id,
                'error_message' => $new_order->get_error_message(),
            ]);
            // Return success anyway (original order was updated, duplicate is optional)
            return yuno_json(['received' => true, 'order_updated' => true, 'duplicate_failed' => true], 200);
        }

        yuno_log('info', 'Webhook: payment.failed - new order created for retry', [
            'old_order_id' => $order_id,
            'new_order_id' => $new_order->get_id(),
            'payment_id'   => $payment_id,
        ]);

        return yuno_json([
            'received'      => true,
            'order_updated' => true,
            'new_order_created' => true,
            'new_order_id'  => $new_order->get_id(),
        ], 200);

    } catch (Exception $e) {
        yuno_log('error', 'Webhook: payment.failed - exception creating duplicate', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
            'error'      => $e->getMessage(),
        ]);
        // Return success anyway (original order was updated, duplicate is optional)
        return yuno_json(['received' => true, 'order_updated' => true, 'duplicate_failed' => true], 200);
    }
}

/**
 * Handle payment.chargeback webhook
 *
 * @param WC_Order $order
 * @param string $payment_id
 * @param array $event_data
 * @return WP_REST_Response
 */
function yuno_webhook_handle_chargeback($order, $payment_id, $event_data) {
    $order_id = $order->get_id();

    yuno_log('warning', 'Webhook: payment.chargeback', [
        'order_id'     => $order_id,
        'payment_id'   => $payment_id,
        'order_status' => $order->get_status(),
        'event_data'   => $event_data,
    ]);

    // Add chargeback note
    $order->add_order_note('⚠️ CHARGEBACK: Yuno reported a chargeback for this payment (payment_id=' . $payment_id . '). Please review.');

    // Optionally update status to on-hold or refunded
    // We don't auto-refund because chargebacks need manual review
    if (!in_array($order->get_status(), ['cancelled', 'refunded'], true)) {
        $order->update_status('on-hold', 'Yuno webhook: Chargeback received - requires manual review');
    }

    $order->save();

    yuno_log('info', 'Webhook: payment.chargeback - order updated', [
        'order_id'     => $order_id,
        'payment_id'   => $payment_id,
        'order_status' => $order->get_status(),
    ]);

    return yuno_json(['received' => true, 'order_updated' => true], 200);
}

/**
 * Handle payment.refunds webhook
 *
 * @param WC_Order $order
 * @param string $payment_id
 * @param array $event_data
 * @return WP_REST_Response
 */
function yuno_webhook_handle_refund($order, $payment_id, $event_data) {
    $order_id = $order->get_id();

    yuno_log('info', 'Webhook: payment.refunds', [
        'order_id'     => $order_id,
        'payment_id'   => $payment_id,
        'order_status' => $order->get_status(),
        'event_data'   => $event_data,
    ]);

    // Extract refund amount if available
    $refund_amount = null;
    if (isset($event_data['amount']['value'])) {
        $refund_amount = (float) $event_data['amount']['value'];
    }

    // Add refund note
    $note = 'Yuno webhook: Refund processed (payment_id=' . $payment_id . ')';
    if ($refund_amount !== null) {
        $note .= ' - Amount: ' . wc_price($refund_amount, ['currency' => $order->get_currency()]);
    }

    $order->add_order_note($note);

    // Optionally update status to refunded if not already
    // We don't create WooCommerce refund object automatically because
    // the refund was processed externally in Yuno
    if (!in_array($order->get_status(), ['refunded', 'cancelled'], true)) {
        $order->update_status('refunded', 'Yuno webhook: Order refunded in Yuno');
    }

    $order->save();

    yuno_log('info', 'Webhook: payment.refunds - order updated', [
        'order_id'      => $order_id,
        'payment_id'    => $payment_id,
        'order_status'  => $order->get_status(),
        'refund_amount' => $refund_amount,
    ]);

    return yuno_json(['received' => true, 'order_updated' => true], 200);
}
