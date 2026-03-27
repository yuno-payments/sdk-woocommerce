<?php
if (!defined('ABSPATH')) exit;

/**
 * =========================
 * Routes
 * =========================
 *
 * Security model: Customer-facing endpoints use WP REST nonce verification
 * (yuno_verify_rest_nonce) as the permission_callback. This validates the
 * X-WP-Nonce header to provide CSRF protection while allowing guest checkout
 * — WordPress generates valid nonces for anonymous sessions via cookies.
 *
 * In addition, each handler calls yuno_get_order_from_request() which requires
 * a valid order_key parameter. Only the order owner (who received the key via
 * checkout redirect) can access their order's payment endpoints.
 *
 * The webhook endpoint uses HMAC signature verification instead (server-to-server).
 */

/**
 * Verify WP REST nonce for customer-facing endpoints.
 *
 * Validates the X-WP-Nonce header sent by the frontend (api.js).
 * Works for both logged-in users and guests — WordPress generates
 * valid nonces for anonymous sessions using cookies.
 *
 * @param WP_REST_Request $request The incoming REST request.
 * @return true|WP_Error True if nonce is valid, WP_Error otherwise.
 */
function yuno_verify_rest_nonce( WP_REST_Request $request ) {
    $nonce = $request->get_header( 'X-WP-Nonce' );
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
        return new WP_Error(
            'rest_forbidden',
            __( 'Nonce verification failed.', 'yuno-payment-gateway' ),
            array( 'status' => 403 )
        );
    }
    return true;
}

add_action('rest_api_init', function () {

    // Public API key (fallback — key is also injected server-side via wp_localize_script).
    // Nonce-protected to prevent unauthenticated scraping.
    register_rest_route('yuno/v1', '/public-api-key', [
        'methods'             => 'GET',
        'permission_callback' => 'yuno_verify_rest_nonce',
        'callback'            => function () {
            return yuno_json(['publicApiKey' => yuno_get_env('PUBLIC_API_KEY', '')], 200);
        },
    ]);

    // Checkout session creation. Nonce + order_key validation in handler.
    register_rest_route('yuno/v1', '/checkout-session', [
        'methods'             => 'POST',
        'permission_callback' => 'yuno_verify_rest_nonce',
        'callback'            => 'yuno_create_checkout_session',
    ]);

    // Customer creation. Nonce + order_key validation in handler.
    register_rest_route('yuno/v1', '/customer', [
        'methods'             => 'POST',
        'permission_callback' => 'yuno_verify_rest_nonce',
        'callback'            => 'yuno_create_customer_endpoint',
    ]);

    // Payment confirmation. Nonce + order_key + server-side Yuno API verification in handler.
    register_rest_route('yuno/v1', '/confirm', [
        'methods'             => 'POST',
        'permission_callback' => 'yuno_verify_rest_nonce',
        'callback'            => 'yuno_confirm_order_payment',
    ]);

    // Order status check (preflight). Nonce + order_key validation in handler.
    register_rest_route('yuno/v1', '/check-order-status', [
        'methods'             => 'POST',
        'permission_callback' => 'yuno_verify_rest_nonce',
        'callback'            => 'yuno_check_order_status',
    ]);

    // Duplicate order creation (retry after failure). Nonce + order_key validation in handler.
    register_rest_route('yuno/v1', '/duplicate-order', [
        'methods'             => 'POST',
        'permission_callback' => 'yuno_verify_rest_nonce',
        'callback'            => 'yuno_duplicate_order',
    ]);

    // Payment creation. Nonce + order_key + transient lock in handler.
    register_rest_route('yuno/v1', '/payments', [
        'methods'             => 'POST',
        'permission_callback' => 'yuno_verify_rest_nonce',
        'callback'            => 'yuno_create_payment',
    ]);

    // Webhook (server-to-server). Uses HMAC + API key + secret verification.
    register_rest_route('yuno/v1', '/webhook', [
        'methods'             => 'POST',
        'permission_callback' => 'yuno_verify_webhook_signature',
        'callback'            => 'yuno_handle_webhook',
    ]);
});

/**
 * =========================
 * Helpers
 * =========================
 */

function yuno_get_env($key, $default = '') {
    static $settings = null;
    static $map = [
        'ACCOUNT_ID'               => 'account_id',
        'PUBLIC_API_KEY'           => 'public_api_key',
        'PRIVATE_SECRET_KEY'       => 'private_secret_key',
        'DEBUG'                    => 'debug',
        'SPLIT_ENABLED'            => 'split_enabled',
        'YUNO_RECIPIENT_ID'        => 'yuno_recipient_id',
        'SPLIT_FIXED_AMOUNT'       => 'split_fixed_amount',
        'SPLIT_COMMISSION_PERCENT' => 'split_commission_percent',
        'WEBHOOK_HMAC_SECRET'      => 'webhook_hmac_secret',
        'WEBHOOK_API_KEY'          => 'webhook_api_key',
        'WEBHOOK_X_SECRET'         => 'webhook_x_secret',
    ];

    if ($settings === null) {
        $settings = get_option('woocommerce_' . YUNO_GATEWAY_ID . '_settings', []);
        if (!is_array($settings)) $settings = [];
    }

    if (isset($map[$key])) {
        $option_key = $map[$key];
        if (array_key_exists($option_key, $settings) && $settings[$option_key] !== '') {
            return $settings[$option_key];
        }
    }

    $env_value = getenv($key);
    if ($env_value !== false && $env_value !== null && $env_value !== '') return $env_value;

    if (defined($key) && constant($key) !== '') return constant($key);

    return $default;
}

function yuno_api_url_from_public_key($public_api_key) {
    $prefix = explode('_', (string)$public_api_key)[0] ?? '';
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

    return [
        'ok'     => ($status >= 200 && $status < 300),
        'status' => $status,
        'raw'    => $json ?? $rawBody,
    ];
}

/**
 * Check if the site is running on localhost.
 * Used to omit callback_url which Yuno API rejects on local URLs.
 */
function yuno_is_localhost() {
    $host = wp_parse_url(site_url(), PHP_URL_HOST);
    if (!$host) return false;
    $local_patterns = ['localhost', '127.0.0.1', '::1'];
    if (in_array($host, $local_patterns, true)) return true;
    if (preg_match('/\.(local|test)$/', $host)) return true;
    return false;
}

/**
 * Woo decimals as source of truth (store setting).
 * If merchant sets 0 decimals for COP, this returns 0.
 */
function yuno_get_wc_price_decimals() {
    if (function_exists('wc_get_price_decimals')) {
        $decimal_places = (int) wc_get_price_decimals();
    } else {
        $decimal_places = (int) get_option('woocommerce_price_num_decimals', 2);
    }
    if ($decimal_places < 0) $decimal_places = 0;
    if ($decimal_places > 6) $decimal_places = 6;
    return $decimal_places;
}

/**
 * Build a Yuno-formatted address array from a WooCommerce order.
 *
 * @param WC_Order $order      The WooCommerce order
 * @param string   $type       'billing' or 'shipping'
 * @param string|null $fallback_type  Optional fallback address type (e.g. 'billing' for shipping fallback)
 * @return array Yuno-formatted address (may be empty if all fields are empty)
 */
function yuno_build_address_from_order($order, $type, $fallback_type = null) {
    $prefix = ($type === 'shipping') ? 'get_shipping_' : 'get_billing_';
    $fallback_prefix = $fallback_type ? (($fallback_type === 'shipping') ? 'get_shipping_' : 'get_billing_') : null;

    $fields = [
        'country'        => 'country',
        'state'          => 'state',
        'city'           => 'city',
        'zip_code'       => 'postcode',
        'address_line_1' => 'address_1',
        'address_line_2' => 'address_2',
    ];

    $address = [];
    foreach ($fields as $yuno_key => $wc_suffix) {
        $val = $order->{$prefix . $wc_suffix}();
        if (empty($val) && $fallback_prefix) {
            $val = $order->{$fallback_prefix . $wc_suffix}();
        }
        if (!empty($val)) {
            $address[$yuno_key] = $val;
        }
    }
    return $address;
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

    $order_id  = absint($params['order_id'] ?? $params['orderId'] ?? 0);
    $order_key = wc_clean(wp_unslash($params['order_key'] ?? $params['orderKey'] ?? ''));

    if (!$order_id) {
        return [null, yuno_json(['error' => 'Missing order_id'], 400)];
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return [null, yuno_json(['error' => 'Order not found', 'order_id' => $order_id], 404)];
    }

    if (!$order_key) {
        return [null, yuno_json(['error' => 'Missing order_key'], 400)];
    }
    if (method_exists($order, 'get_order_key') && $order->get_order_key() !== $order_key) {
        return [null, yuno_json(['error' => 'Invalid order_key'], 403)];
    }

    return [$order, null];
}

function yuno_debug_enabled() {
    static $enabled = null;
    if ($enabled === null) {
        $dbg = (string) yuno_get_env('DEBUG', 'no');
        $enabled = in_array($dbg, ['yes','1','true'], true);
    }
    return $enabled;
}

function yuno_logger() {
    static $logger = null;
    if ($logger === null && function_exists('wc_get_logger')) $logger = wc_get_logger();
    return $logger;
}

function yuno_log($level, $message, $context = []) {
    // Always log error/warning levels regardless of debug setting (LOG-1)
    $always_log = ['emergency', 'alert', 'critical', 'error', 'warning'];
    if (!yuno_debug_enabled() && !in_array($level, $always_log, true)) return;
    $logger = yuno_logger();
    if (!$logger) return;
    $logger->log($level, $message . ' ' . wp_json_encode($context), ['source' => 'yuno']);
}

/**
 * Create a Yuno checkout session for a WooCommerce order
 *
 * This endpoint is called by the frontend to initialize the Yuno SDK.
 * It creates a fresh checkout session for each request (never reuses sessions)
 * to prevent INVALID_CUSTOMER_FOR_TOKEN errors.
 *
 * Also creates or retrieves a Yuno customer for the order using per-order strategy.
 *
 * @param WP_REST_Request $request Must include orderId and orderKey
 * @return WP_REST_Response JSON with checkout_session, country, and customer_id
 */
function yuno_create_checkout_session(WP_REST_Request $request) {
    $account_id = yuno_get_env('ACCOUNT_ID', '');
    $public_key = yuno_get_env('PUBLIC_API_KEY', '');
    $secret_key = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$account_id || !$public_key || !$secret_key) {
        return yuno_json(['error' => 'Missing required keys'], 400);
    }

    [$order, $err] = yuno_get_order_from_request($request);
    if ($err) return $err;

    $params       = (array) $request->get_json_params();
    $customer_id  = isset($params['customer_id']) && is_string($params['customer_id'])
        ? sanitize_text_field($params['customer_id'])
        : null;

    if ($order->is_paid()) {
        return yuno_json([
            'error'    => 'Order already paid',
            'redirect' => $order->get_checkout_order_received_url(),
        ], 409);
    }

    $api_url = yuno_api_url_from_public_key($public_key);

    $billing_country = $order->get_billing_country();
    $country = $billing_country ?: YUNO_DEFAULT_COUNTRY;

    // Simplified payload for Full SDK (SDK_CHECKOUT is the default workflow)
    // Payment-specific data (amount, addresses, split, items) moved to /payments endpoint
    $payload = [
        'account_id'          => $account_id,
        'merchant_order_id'   => 'WC-' . $order->get_id(),
        'payment_description' => 'WooCommerce Order #' . $order->get_id(),
        'country'             => $country,
        'customer_id'         => $customer_id ?: null,
        'amount'              => [
            'currency' => $order->get_currency() ?: 'COP',
            'value'    => (float) number_format((float) $order->get_total(), yuno_get_wc_price_decimals(), '.', ''),
        ],
    ];

    if (!yuno_is_localhost()) {
        $callback_url = $order->get_checkout_payment_url(true);
        $callback_url = add_query_arg('yuno_3ds_return', '1', $callback_url);
        $payload['callback_url'] = wp_specialchars_decode($callback_url);
    }

    yuno_log('info', '[CHECKOUT SESSION]Final payload before API call', [
        'order_id'        => $order->get_id(),
        'has_customer_id' => !empty($customer_id),
        'country'         => $country,
        'callback_url' => yuno_is_localhost() ? '(omitted - localhost)' : (isset($callback_url) ? wp_specialchars_decode($callback_url) : '(not set)')
    ]);

    $res = yuno_wp_remote_json(
        'POST',
        "{$api_url}/v1/checkout/sessions",
        [
            'public-api-key'     => $public_key,
            'private-secret-key' => $secret_key,
            'Content-Type'       => 'application/json',
        ],
        $payload,
        30
    );

    if (!$res['ok']) {
        //Handle CUSTOMER_NOT_FOUND error (occurs when API keys are changed)
        // When merchant changes Yuno API keys to a different account, cached customer_ids
        // from the old account don't exist in the new account
        $error_code = is_array($res['raw']) ? ($res['raw']['code'] ?? '') : '';

        if ($error_code === 'CUSTOMER_NOT_FOUND' && !empty($customer_id)) {
            yuno_log('warning', '[CHECKOUT SESSION]CUSTOMER_NOT_FOUND - clearing cached customer and retrying', [
                'order_id'            => $order->get_id(),
                'old_customer_id'     => $customer_id,
                'user_id'             => $order->get_user_id(),
            ]);

            // Clear the cached customer_id from user_meta
            $user_id = $order->get_user_id();
            if ($user_id) {
                delete_user_meta($user_id, '_yuno_customer_id');
                yuno_log('info', '[CHECKOUT SESSION]Cleared cached customer_id for user', [
                    'user_id' => $user_id,
                ]);
            }

            // Clear from order meta as well
            $order->delete_meta_data('_yuno_customer_id');
            $order->save();

            // Retry customer creation (will create a new customer in the new account)
            yuno_log('info', '[CHECKOUT SESSION]Retrying customer creation', [
                'order_id' => $order->get_id(),
            ]);

            $new_customer_id = yuno_get_or_create_customer($order);

            if (!empty($new_customer_id)) {
                $payload['customer_id'] = $new_customer_id;

                yuno_log('info', '[CHECKOUT SESSION]Created new customer, retrying checkout session', [
                    'order_id'         => $order->get_id(),
                    'new_customer_id'  => $new_customer_id,
                ]);

                // Retry checkout session creation with new customer_id
                $res = yuno_wp_remote_json(
                    'POST',
                    "{$api_url}/v1/checkout/sessions",
                    [
                        'public-api-key'     => $public_key,
                        'private-secret-key' => $secret_key,
                        'Content-Type'       => 'application/json',
                    ],
                    $payload,
                    30
                );

                // If retry also fails, return the error below
                if (!$res['ok']) {
                    yuno_log('error', '[CHECKOUT SESSION]Retry failed after customer recreation', [
                        'order_id'    => $order->get_id(),
                        'http_status' => $res['status'],
                        'error_code'  => is_array($res['raw']) ? ($res['raw']['code'] ?? null) : null,
                    ]);

                    return yuno_json([
                        'error'    => 'Yuno create checkout session failed after retry',
                        'status'   => $res['status'],
                    ], 400);
                }

                // Retry succeeded, continue with normal flow below
                yuno_log('info', '[CHECKOUT SESSION]Retry succeeded', [
                    'order_id' => $order->get_id(),
                ]);
            } else {
                yuno_log('error', '[CHECKOUT SESSION] Failed to create new customer on retry', [
                    'order_id' => $order->get_id(),
                ]);

                return yuno_json([
                    'error'    => 'Failed to create customer after cache clear',
                    'status'   => $res['status'],
                ], 400);
            }
        } else {
            yuno_log('error', '[CHECKOUT SESSION]API call failed', [
                'order_id'    => $order->get_id(),
                'http_status' => $res['status'],
                'error_code'  => is_array($res['raw']) ? ($res['raw']['code'] ?? null) : null,
            ]);
            return yuno_json([
                'error'    => 'Yuno create checkout session failed',
                'status'   => $res['status'],
            ], 400);
        }
    }

    $checkout_session_id = is_array($res['raw'])
        ? ($res['raw']['checkout_session'] ?? $res['raw']['id'] ?? null)
        : null;

    if (!$checkout_session_id) {
        yuno_log('error', '[CHECKOUT SESSION] Response missing checkout_session/id', [
            'order_id'    => $order->get_id(),
            'http_status' => $res['status'],
            'has_raw'     => !empty($res['raw']),
        ]);
        return yuno_json(['error' => 'Yuno response missing checkout_session/id'], 400);
    }

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $checkout_session_id)) {
        yuno_log('error', '[CHECKOUT SESSION] Invalid session ID format from API', [
            'order_id' => $order->get_id(),
            'session'  => $checkout_session_id,
        ]);
        return yuno_json(['error' => 'Invalid checkout session format from API'], 400);
    }

    yuno_log('info', 'Yuno checkout session response', [
        'order_id'         => $order->get_id(),
        'checkout_session' => $checkout_session_id,
        'country'          => $res['raw']['country'] ?? null,
        'amount'           => $res['raw']['amount'] ?? null,
        'currency'         => $res['raw']['currency'] ?? null,
    ]);

    $order->update_meta_data('_yuno_checkout_session', $checkout_session_id);
    $order->save();

    return yuno_json([
        'checkout_session' => $checkout_session_id,
        'country'          => $country,
        'order_id'         => $order->get_id(),
        'reused'           => false,
    ], 200);
}

function yuno_create_customer_endpoint(WP_REST_Request $request) {
    [$order, $err] = yuno_get_order_from_request($request);
    if ($err) return $err;

    $customer_id = yuno_get_or_create_customer($order);

    return yuno_json([
        'customer_id' => $customer_id,   // null if creation failed (graceful)
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

    $country_config = isset($country_codes[$country]) ? $country_codes[$country] : null;

    // If we don't have a country code mapping, return null (fail gracefully)
    if (!$country_config) {
        yuno_log('warning', '[PHONE]Country code not mapped', [
            'country'    => $country,
            'phone_last4'=> substr(preg_replace('/[^\d]/', '', $phone), -4),
        ]);
        return null;
    }

    // If phone starts with +, extract country code and number
    if (strpos($cleaned, '+') === 0) {
        $cleaned = substr($cleaned, 1);

        // Extract country code by matching against our mapping
        $country_code_prefix = $country_config['code'];
        if (strpos($cleaned, $country_code_prefix) === 0) {
            $number = substr($cleaned, strlen($country_code_prefix));

            if (strlen($number) < $country_config['min_length']) {
                yuno_log('warning', '[PHONE]Phone number too short after extracting country code', [
                    'country'      => $country,
                    'phone_last4'  => substr($number, -4),
                    'length'       => strlen($number),
                    'min_required' => $country_config['min_length'],
                ]);
                return null;
            }

            $result = [
                'country_code' => $country_code_prefix,
                'number'       => $number,
            ];

            yuno_log('info', '[PHONE]Formatted phone from international format', [
                'country'      => $country,
                'country_code' => $result['country_code'],
                'phone_last4'  => substr($result['number'], -4),
            ]);

            return $result;
        }
    }

    // Validate minimum length for local number
    if (strlen($cleaned) < $country_config['min_length']) {
        yuno_log('warning', '[PHONE]Phone number too short', [
            'country'      => $country,
            'phone_last4'  => substr($cleaned, -4),
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

    yuno_log('info', '[PHONE]Formatted phone number', [
        'country'      => $country,
        'country_code' => $result['country_code'],
        'phone_last4'  => substr($result['number'], -4),
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
    yuno_log('info', '[CUSTOMER]Function called', [
        'order_id' => $order->get_id(),
    ]);

    $existing_customer_id = $order->get_meta('_yuno_customer_id');
    if (!empty($existing_customer_id)) {
        yuno_log('info', '[CUSTOMER]Reusing existing customer_id from order meta', [
            'order_id'    => $order->get_id(),
            'customer_id' => $existing_customer_id,
        ]);
        return $existing_customer_id;
    }

    $secret_key = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (empty($secret_key)) {
        yuno_log('warning', '[CUSTOMER]missing PRIVATE_SECRET_KEY, skipping customer creation');
        return null; // Graceful degradation - continues without customer
    }

    yuno_log('info', '[CUSTOMER]PRIVATE_SECRET_KEY found', [
        'key_length' => strlen($secret_key),
    ]);

    $user_id = $order->get_user_id();
    yuno_log('info', '[CUSTOMER]User ID check', [
        'user_id'  => $user_id,
        'is_guest' => empty($user_id) ? 'YES' : 'NO',
    ]);

    $billing_first  = $order->get_billing_first_name();
    $billing_last   = $order->get_billing_last_name();
    $billing_email  = $order->get_billing_email();
    $billing_phone  = $order->get_billing_phone();

    $billing_country  = $order->get_billing_country();

    //Option A: Always use order ID for merchant_customer_id (unique per order, never reuse)
    $merchant_customer_id = 'woo_order_' . $order->get_id();

    // Build payload according to Yuno Customer API specification
    $payload = [
        'email'                              => $billing_email,
        'merchant_customer_id'               => $merchant_customer_id,
        'organization_customer_external_id'  => $merchant_customer_id,
    ];

    // Add first_name and last_name (separate fields, not combined "name")
    if (!empty($billing_first)) {
        $payload['first_name'] = $billing_first;
    }
    if (!empty($billing_last)) {
        $payload['last_name'] = $billing_last;
    }

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

    $billing_address = yuno_build_address_from_order($order, 'billing');
    if (!empty($billing_address)) {
        $payload['billing_address'] = $billing_address;
    }

    $shipping_address = yuno_build_address_from_order($order, 'shipping', 'billing');
    if (!empty($shipping_address)) {
        $payload['shipping_address'] = $shipping_address;
    }

    $public_key = yuno_get_env('PUBLIC_API_KEY', '');
    $api_url = yuno_api_url_from_public_key($public_key);

    //Always CREATE new customer (no reuse, no update logic)
    yuno_log('info', '[CUSTOMER]Creating new customer (always fresh per order)', [
        'order_id'             => $order->get_id(),
        'merchant_customer_id' => $merchant_customer_id,
        'has_email'            => !empty($billing_email),
        'has_phone'            => !empty($billing_phone),
        'has_billing_address'  => !empty($billing_address),
        'has_shipping_address' => !empty($shipping_address),
        'country'              => $billing_country,
    ]);

    // Call Yuno Create Customer API
    $res = yuno_wp_remote_json(
        'POST',
        "{$api_url}/v1/customers",
        [
            'public-api-key'     => $public_key,
            'private-secret-key' => $secret_key,
            'Content-Type'       => 'application/json',
        ],
        $payload,
        20
    );

    yuno_log('info', '[CUSTOMER]API response received', [
        'order_id'    => $order->get_id(),
        'ok'          => $res['ok'],
        'http_status' => $res['status'] ?? null,
        'customer_id' => is_array($res['raw']) ? ($res['raw']['id'] ?? null) : null,
    ]);

    if (!$res['ok']) {
        $response_code = is_array($res['raw']) ? ($res['raw']['code'] ?? '') : '';

        if ($response_code === 'CUSTOMER_ID_DUPLICATED') {
            // A customer with this merchant_customer_id already exists in Yuno
            // (e.g. previous checkout attempt for the same order). Search for it.
            yuno_log('info', '[CUSTOMER]CUSTOMER_ID_DUPLICATED — searching for existing customer', [
                'order_id'             => $order->get_id(),
                'merchant_customer_id' => $merchant_customer_id,
            ]);

            $search_res = yuno_wp_remote_json(
                'GET',
                "{$api_url}/v1/customers?merchant_customer_id=" . urlencode($merchant_customer_id),
                [
                    'public-api-key'     => $public_key,
                    'private-secret-key' => $secret_key,
                ],
                null,
                30
            );

            $found_id = null;
            if ($search_res['ok'] && is_array($search_res['raw'])) {
                // Handle both single-object and list/paginated responses
                if (!empty($search_res['raw']['id'])) {
                    $found_id = $search_res['raw']['id'];
                } elseif (!empty($search_res['raw'][0]['id'])) {
                    $found_id = $search_res['raw'][0]['id'];
                } elseif (!empty($search_res['raw']['data'][0]['id'])) {
                    $found_id = $search_res['raw']['data'][0]['id'];
                }
            }

            if (!empty($found_id)) {
                yuno_log('info', '[CUSTOMER]Found existing customer after duplicate', [
                    'order_id'    => $order->get_id(),
                    'customer_id' => $found_id,
                ]);
                $order->update_meta_data('_yuno_customer_id', $found_id);
                $order->save();
                return $found_id;
            }

            yuno_log('warning', '[CUSTOMER]Could not retrieve existing customer after CUSTOMER_ID_DUPLICATED, continuing without customer', [
                'order_id'   => $order->get_id(),
                'search_ok'  => $search_res['ok'] ? 'TRUE' : 'FALSE',
                'search_res' => $search_res['raw'],
            ]);
            return null;
        }

        yuno_log('warning', '[CUSTOMER]API call failed, continuing without customer', [
            'order_id'    => $order->get_id(),
            'http_status' => $res['status'],
            'error_code'  => is_array($res['raw']) ? ($res['raw']['code'] ?? null) : null,
        ]);
        return null; // Graceful degradation - continues without customer
    }

    $customer_id = is_array($res['raw']) ? ($res['raw']['id'] ?? null) : null;

    yuno_log('info', '[CUSTOMER]Extracting customer_id from response', [
        'order_id'    => $order->get_id(),
        'customer_id' => $customer_id ?: 'NOT FOUND',
        'raw_type'    => gettype($res['raw']),
    ]);

    if (empty($customer_id)) {
        yuno_log('warning', '[CUSTOMER]Missing id in response, continuing without customer', [
            'order_id'    => $order->get_id(),
            'http_status' => $res['status'],
        ]);
        return null; // Graceful degradation
    }

    yuno_log('info', '[CUSTOMER] Customer created successfully', [
        'order_id'    => $order->get_id(),
        'customer_id' => $customer_id,
        'user_id'     => $user_id ?: 'GUEST',
    ]);

    // Save to order meta for reference (no user_meta caching since we don't reuse customers)
    $order->update_meta_data('_yuno_customer_id', $customer_id);
    $order->save();

    yuno_log('info', '[CUSTOMER] Returning customer_id', [
        'order_id'    => $order->get_id(),
        'customer_id' => $customer_id,
    ]);

    return $customer_id;
}

/**
 * Confirm and finalize a payment by verifying status with Yuno API
 *
 * Called by frontend after SDK payment flow completes (yunoPaymentResult callback).
 * Performs server-side verification by querying Yuno API for payment status.
 * Updates WooCommerce order status based on verified payment status.
 * Handles PENDING payments (3DS) by not redirecting until webhook confirms.
 *
 * @param WP_REST_Request $request Must include orderId, orderKey, and payment_id
 * @return WP_REST_Response JSON with order status, redirect URL, and payment details
 */
function yuno_confirm_order_payment(WP_REST_Request $request) {
    [$order, $err] = yuno_get_order_from_request($request);
    if ($err) return $err;

    $params = (array) $request->get_json_params();

    // Client-reported status hint (debug only — server always re-verifies via Yuno API)
    $client_status = isset($params['payment_status'])
        ? sanitize_text_field($params['payment_status'])
        : null;

    yuno_log('info', '[CONFIRM] Client-reported status', [
        'order_id'      => $order->get_id(),
        'client_status' => $client_status,
    ]);

    // Verify via checkout session (payment status verified server-side via Yuno API)
    $checkout_session = (string) $order->get_meta('_yuno_checkout_session');

    if (!$checkout_session) {
        yuno_log('error', '[CONFIRM] Missing checkout session', ['order_id' => $order->get_id()]);
        return yuno_json(['error' => 'Missing checkout session'], 400);
    }

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $checkout_session)) {
        yuno_log('error', '[CONFIRM] Invalid checkout session format', [
            'order_id' => $order->get_id(),
            'session'  => $checkout_session,
        ]);
        return yuno_json(['error' => 'Invalid checkout session format'], 400);
    }

    // Check if order is already paid (idempotency)
    if ($order->is_paid()) {
        yuno_log('info', 'Confirm: order already paid', [
            'order_id'   => $order->get_id(),
            'session_id' => $checkout_session,
        ]);
        return yuno_json([
            'ok'          => true,
            'order_id'    => $order->get_id(),
            'new_status'  => $order->get_status(),
            'redirect'    => $order->get_checkout_order_received_url(),
            'already_paid'=> true,
        ], 200);
    }

    $public_key = yuno_get_env('PUBLIC_API_KEY', '');
    $secret_key = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$public_key || !$secret_key) {
        yuno_log('error', 'Confirm: missing API keys', ['order_id' => $order->get_id()]);
        return yuno_json(['error' => 'Missing API keys'], 500);
    }

    $api_url = yuno_api_url_from_public_key($public_key);

    yuno_log('info', '[CONFIRM] Verifying via checkout session', [
        'order_id'   => $order->get_id(),
        'session_id' => $checkout_session,
    ]);

    $res = yuno_wp_remote_json(
        'GET',
        "{$api_url}/v1/checkout/sessions/{$checkout_session}/payment",
        [
            'public-api-key'     => $public_key,
            'private-secret-key' => $secret_key,
        ],
        null,
        30
    );

    if (!$res['ok']) {
        yuno_log('error', '[CONFIRM] Failed to verify payment via checkout session', [
            'order_id'   => $order->get_id(),
            'session_id' => $checkout_session,
            'http_status' => $res['status'],
        ]);
        $order->add_order_note('Yuno verification failed (HTTP ' . $res['status'] . '). session=' . $checkout_session);
        return yuno_json(['error' => 'Could not verify payment status with Yuno', 'retry' => true], 500);
    }

    $verified_status = strtoupper(trim((string) ($res['raw']['status'] ?? 'UNKNOWN')));

    yuno_log('info', '[CONFIRM] Payment status from checkout session', [
        'order_id'        => $order->get_id(),
        'session_id'      => $checkout_session,
        'verified_status' => $verified_status,
        'client_status'   => $client_status,
        'payment_id'      => $res['raw']['transactions']['id'] ?? null,
        'payment_method'  => $res['raw']['transactions']['payment_method_type'] ?? null,
    ]);

    // Handle verified status
    if (in_array($verified_status, YUNO_STATUS_SUCCESS, true)) {
        // Debug diagnostics: only run if debug mode is enabled
        if (yuno_debug_enabled()) {
            $order_items_debug = [];
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product) {
                    $order_items_debug[] = [
                        'name'            => $product->get_name(),
                        'is_virtual'      => $product->is_virtual() ? 'YES' : 'NO',
                        'is_downloadable' => $product->is_downloadable() ? 'YES' : 'NO',
                        'needs_shipping'  => $product->needs_shipping() ? 'YES' : 'NO',
                    ];
                }
            }

            global $wp_filter;
            $active_filters = [];
            if (isset($wp_filter['woocommerce_payment_complete_order_status'])) {
                $active_filters['payment_complete_order_status'] = 'ACTIVE';
            }
            if (isset($wp_filter['woocommerce_order_is_paid_statuses'])) {
                $active_filters['order_is_paid_statuses'] = 'ACTIVE';
            }

            yuno_log('info', 'Confirm: order product analysis BEFORE payment_complete()', [
                'order_id'         => $order->get_id(),
                'needs_shipping'   => $order->needs_shipping_address() ? 'YES' : 'NO',
                'needs_processing' => $order->needs_processing() ? 'YES' : 'NO',
                'has_downloadable' => $order->has_downloadable_item() ? 'YES' : 'NO',
                'products'         => $order_items_debug,
                'current_status'   => $order->get_status(),
                'active_filters'   => $active_filters ?: 'NONE',
            ]);
        }

        $order->payment_complete();
        $order->add_order_note('Yuno payment approved. status=' . $verified_status . ' session=' . $checkout_session);

        // Debug diagnostics: only run if debug mode is enabled
        if (yuno_debug_enabled()) {
            $status_after_payment_complete = $order->get_status();

            $has_physical = false;
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product && $product->needs_shipping()) {
                    $has_physical = true;
                    break;
                }
            }

            yuno_log('info', 'Confirm: order marked as paid AFTER payment_complete()', [
                'order_id'                      => $order->get_id(),
                'session_id'                    => $checkout_session,
                'status_after_payment_complete' => $status_after_payment_complete,
                'has_physical'                  => $has_physical ? 'YES' : 'NO',
                'expected'                      => $has_physical ? 'processing' : 'completed',
            ]);
        }

        return yuno_json([
            'ok'        => true,
            'order_id'  => $order->get_id(),
            'new_status'=> $order->get_status(),
            'redirect'  => $order->get_checkout_order_received_url(),
        ], 200);
    }

    if (in_array($verified_status, YUNO_STATUS_FAILURE, true)) {
        $order->update_status('failed', 'Yuno payment rejected (verified): ' . $verified_status);
        $order->add_order_note('Yuno payment rejected. status=' . $verified_status . ' session=' . $checkout_session);

        yuno_log('warning', 'Confirm: order marked as failed', [
            'order_id'   => $order->get_id(),
            'session_id' => $checkout_session,
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

    // PENDING/PROCESSING/REQUIRES_ACTION — check sub_status to determine if payment is authorized
    // AUTHORIZED sub_status means payment succeeded (auto-capture), treat as successful.
    // Other sub_statuses (WAITING_ADDITIONAL_STEP, IN_PROCESS, etc.) mean payment is still in progress.
    if (in_array($verified_status, YUNO_STATUS_PENDING, true)) {
        $sub_status = strtoupper(trim((string) ($res['raw']['sub_status'] ?? '')));

        yuno_log('info', 'Confirm: PENDING status with sub_status', [
            'order_id'   => $order->get_id(),
            'session_id' => $checkout_session,
            'status'     => $verified_status,
            'sub_status' => $sub_status,
        ]);

        // AUTHORIZED sub_status: payment is authorized, capture happens automatically
        if ($sub_status === 'AUTHORIZED') {
            if (yuno_debug_enabled()) {
                $order_items_debug = [];
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if ($product) {
                        $order_items_debug[] = [
                            'name'            => $product->get_name(),
                            'is_virtual'      => $product->is_virtual() ? 'YES' : 'NO',
                            'is_downloadable' => $product->is_downloadable() ? 'YES' : 'NO',
                            'needs_shipping'  => $product->needs_shipping() ? 'YES' : 'NO',
                        ];
                    }
                }

                yuno_log('info', 'Confirm: order product analysis BEFORE payment_complete() [PENDING/AUTHORIZED]', [
                    'order_id'         => $order->get_id(),
                    'needs_shipping'   => $order->needs_shipping_address() ? 'YES' : 'NO',
                    'needs_processing' => $order->needs_processing() ? 'YES' : 'NO',
                    'has_downloadable' => $order->has_downloadable_item() ? 'YES' : 'NO',
                    'products'         => $order_items_debug,
                    'current_status'   => $order->get_status(),
                ]);
            }

            $order->payment_complete();
            $order->add_order_note('Yuno payment authorized (pending capture). status=' . $verified_status . ' sub_status=' . $sub_status . ' session=' . $checkout_session);

            if (yuno_debug_enabled()) {
                yuno_log('info', 'Confirm: order marked as paid AFTER payment_complete() [PENDING/AUTHORIZED]', [
                    'order_id'                      => $order->get_id(),
                    'session_id'                    => $checkout_session,
                    'status_after_payment_complete' => $order->get_status(),
                ]);
            }

            return yuno_json([
                'ok'        => true,
                'order_id'  => $order->get_id(),
                'new_status'=> $order->get_status(),
                'redirect'  => $order->get_checkout_order_received_url(),
            ], 200);
        }

        // Non-AUTHORIZED sub_status: payment still in progress (3DS, OTP, fraud review, etc.)
        // Do NOT call payment_complete() — webhook will finalize the order later
        $order->add_order_note('Yuno payment pending additional steps. status=' . $verified_status . ' sub_status=' . $sub_status . ' session=' . $checkout_session);

        return yuno_json([
            'ok'                 => true,
            'pending'            => true,
            'order_id'           => $order->get_id(),
            'new_status'         => $order->get_status(),
            'verified_status'    => $verified_status,
            'verified_sub_status'=> $sub_status,
        ], 200);
    }
}

/**
 * Check order status to prevent double payment
 * Used when user reloads the order-pay page
 *
 * SECURITY: This endpoint verifies with Yuno API if payment was already processed
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

    // Validate order key (mandatory for authentication)
    if (!$order_key) {
        return yuno_json(['error' => 'Missing order_key'], 400);
    }
    if ($order->get_order_key() !== $order_key) {
        yuno_log('error', 'Check order status: order_key mismatch', [
            'order_id'      => $order_id,
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

    // AUTO-DUPLICATE: If order is in failed state, signal frontend to duplicate
    // This handles the reload case where user refreshes a failed order
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

    // CRITICAL: Check if a checkout session exists (payment was initiated)
    // If it exists, verify with Yuno API to prevent double-payment race condition
    // Checkout session is the authoritative identifier for payment verification
    $checkout_session = (string) $order->get_meta('_yuno_checkout_session');

    if (!$checkout_session) {
        // No session started yet, safe to proceed
        yuno_log('info', 'Check order status: no checkout session found, allowing payment', [
            'order_id' => $order_id,
        ]);

        return yuno_json([
            'is_paid' => false,
            'status'  => $status,
            'message' => 'Order ready for payment',
        ], 200);
    }

    // 4. Session exists — verify current payment status with Yuno API
    yuno_log('info', 'Check order status: checkout session found, verifying with Yuno', [
        'order_id'   => $order_id,
        'session_id' => $checkout_session,
    ]);

    $public_key = yuno_get_env('PUBLIC_API_KEY', '');
    $secret_key = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$public_key || !$secret_key) {
        // Can't verify, but safer to allow than silently block
        yuno_log('warning', 'Check order status: missing API keys', [
            'order_id' => $order_id,
        ]);

        return yuno_json([
            'is_paid' => false,
            'status'  => $status,
            'message' => 'Order ready for payment',
            'warning' => 'Could not verify payment status',
        ], 200);
    }

    $api_url = yuno_api_url_from_public_key($public_key);

    $res = yuno_wp_remote_json(
        'GET',
        "{$api_url}/v1/checkout/sessions/{$checkout_session}/payment",
        [
            'public-api-key'     => $public_key,
            'private-secret-key' => $secret_key,
        ],
        null,
        10
    );

    if (!$res['ok']) {
        // API call failed, allow payment (but log the issue)
        yuno_log('warning', 'Check order status: Yuno API verification failed', [
            'order_id'   => $order_id,
            'session_id' => $checkout_session,
            'status'     => $res['status'],
        ]);

        return yuno_json([
            'is_paid'             => false,
            'status'              => $status,
            'has_checkout_session'=> !empty($checkout_session),
            'message'             => 'Order ready for payment',
        ], 200);
    }

    // Extract status from Yuno response (same approach as yuno_confirm_order_payment)
    $verified_status = strtoupper(trim((string) ($res['raw']['status'] ?? 'UNKNOWN')));

    yuno_log('info', 'Check order status: Yuno verification result', [
        'order_id'        => $order_id,
        'session_id'      => $checkout_session,
        'verified_status' => $verified_status
    ]);

    // 5. If payment is SUCCEEDED in Yuno, mark order as paid NOW
    if (in_array($verified_status, YUNO_STATUS_SUCCESS, true)) {
        yuno_log('info', 'Check order status: Payment succeeded in Yuno, marking order as paid', [
            'order_id'   => $order_id,
            'session_id' => $checkout_session,
            'status'     => $verified_status,
        ]);

        // Mark order as paid
        $order->payment_complete();
        $order->add_order_note('Yuno payment confirmed via check-order-status (page reload). status=' . $verified_status . ' session=' . $checkout_session);

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
    if (in_array($verified_status, YUNO_STATUS_FAILURE, true)) {
        yuno_log('warning', 'Check order status: Payment failed in Yuno, marking as failed', [
            'order_id'   => $order_id,
            'session_id' => $checkout_session,
            'status'     => $verified_status,
        ]);

        //Sync Yuno status with WooCommerce: mark as failed
        if ($status !== 'failed') {
            $order->update_status('failed', 'Yuno payment failed (check-order-status): ' . $verified_status);
            $order->add_order_note('Yuno payment failed. status=' . $verified_status . ' session=' . $checkout_session);
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

    // 7. Payment is PENDING — check sub_status to determine behavior
    if (in_array($verified_status, YUNO_STATUS_PENDING, true)) {
        $sub_status = strtoupper(trim((string) ($res['raw']['sub_status'] ?? '')));

        yuno_log('info', 'Check order status: PENDING with sub_status', [
            'order_id'   => $order_id,
            'session_id' => $checkout_session,
            'status'     => $verified_status,
            'sub_status' => $sub_status,
        ]);

        // AUTHORIZED: payment succeeded, treat same as SUCCESS
        if ($sub_status === 'AUTHORIZED') {
            $order->payment_complete();
            $order->add_order_note('Yuno payment confirmed via check-order-status (PENDING/AUTHORIZED). status=' . $verified_status . ' sub_status=' . $sub_status . ' session=' . $checkout_session);

            $redirect = $order->get_checkout_order_received_url();

            return yuno_json([
                'is_paid'              => true,
                'status'               => $order->get_status(),
                'redirect'             => $redirect,
                'message'              => 'Payment already processed',
                'verified_by'          => 'yuno_api',
                'verified_status'      => $verified_status,
                'verified_sub_status'  => $sub_status,
            ], 200);
        }

        // Non-AUTHORIZED: payment still in progress, don't redirect
        return yuno_json([
            'is_paid'              => false,
            'is_pending'           => true,
            'status'               => $status,
            'message'              => 'Payment is being processed',
            'verified_status'      => $verified_status,
            'verified_sub_status'  => $sub_status,
        ], 200);
    }

    // 8. UNKNOWN/other status — allow SDK init for retry
    yuno_log('info', 'Check order status: Payment in unknown state, allowing retry', [
        'order_id'   => $order_id,
        'session_id' => $checkout_session,
        'status'     => $verified_status,
    ]);

    return yuno_json([
        'is_paid'             => false,
        'status'              => $status,
        'has_checkout_session'=> !empty($checkout_session),
        'message'             => 'Order ready for payment',
        'verified_status'     => $verified_status,
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
    foreach ($order->get_items() as $item) {
        $cloned_item = clone $item;
        $cloned_item->set_id(0); // Reset ID so WooCommerce creates a new item
        $new_order->add_item($cloned_item);
    }

    foreach ($order->get_items('shipping') as $item) {
        $cloned_item = clone $item;
        $cloned_item->set_id(0); // Reset ID so WooCommerce creates a new item
        $new_order->add_item($cloned_item);
    }

    foreach ($order->get_items('fee') as $item) {
        $cloned_item = clone $item;
        $cloned_item->set_id(0); // Reset ID so WooCommerce creates a new item
        $new_order->add_item($cloned_item);
    }

    // Copy coupons (CRITICAL: preserve discounts from original order)
    foreach ($order->get_items('coupon') as $item) {
        $cloned_item = clone $item;
        $cloned_item->set_id(0); // Reset ID so WooCommerce creates a new item
        $new_order->add_item($cloned_item);
    }

    $new_order->set_customer_id($order->get_customer_id());

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

    $new_order->set_payment_method($order->get_payment_method());
    $new_order->set_payment_method_title($order->get_payment_method_title());

    $new_order->set_currency($order->get_currency());

    // Copy totals from original order (do NOT recalculate)
    // Using calculate_totals() would apply current tax rates/coupons, potentially changing amounts
    $new_order->set_discount_total($order->get_discount_total());
    $new_order->set_discount_tax($order->get_discount_tax());
    $new_order->set_shipping_total($order->get_shipping_total());
    $new_order->set_shipping_tax($order->get_shipping_tax());
    $new_order->set_cart_tax($order->get_cart_tax());
    $new_order->set_total($order->get_total());

    $new_order->set_status('pending', 'Order created from failed order #' . $order->get_id());

    $new_order->save();

    // Store metadata linking failed order to duplicate (prevents creating multiple duplicates)
    $order->update_meta_data('_yuno_duplicate_order_id', $new_order->get_id());
    $order->add_order_note('New order #' . $new_order->get_id() . ' created for payment retry.');
    $order->save();

    // Store reference to original order in duplicate
    $new_order->update_meta_data('_yuno_original_order_id', $order->get_id());
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
        //FIX: Check if webhook already created a duplicate order
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
            yuno_log('error', 'Duplicate order: creation returned WP_Error', [
                'original_order_id' => $order->get_id(),
                'error_message'     => $new_order->get_error_message(),
            ]);
            return yuno_json([
                'error' => 'Failed to create new order',
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
            'error' => 'Failed to create new order',
        ], 500);
    }
}

/**
 * Create a payment via Yuno API (Full SDK / SDK_CHECKOUT workflow)
 *
 * Called by the frontend `yunoCreatePayment` callback after the SDK generates a one-time token.
 * The application is responsible for creating the payment in Full SDK mode (unlike Seamless SDK
 * where the SDK handles payment creation internally).
 *
 * Includes split_marketplace data when split payments are enabled.
 *
 * @param WP_REST_Request $request Must include one_time_token, checkout_session, order_id, order_key
 * @return WP_REST_Response JSON with payment_id and sdk_action_required flag
 */
function yuno_create_payment(WP_REST_Request $request) {
    $account_id = yuno_get_env('ACCOUNT_ID', '');
    $public_key = yuno_get_env('PUBLIC_API_KEY', '');
    $secret_key = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$account_id || !$public_key || !$secret_key) {
        return yuno_json(['error' => 'Missing required keys'], 400);
    }

    [$order, $err] = yuno_get_order_from_request($request);
    if ($err) return $err;

    $params = (array) $request->get_json_params();

    $one_time_token   = isset($params['one_time_token']) ? sanitize_text_field($params['one_time_token']) : '';
    $checkout_session = isset($params['checkout_session']) ? sanitize_text_field($params['checkout_session']) : '';

    if (empty($one_time_token)) {
        return yuno_json(['error' => 'Missing one_time_token'], 400);
    }

    if (empty($checkout_session)) {
        return yuno_json(['error' => 'Missing checkout_session'], 400);
    }

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $checkout_session)) {
        return yuno_json(['error' => 'Invalid checkout_session format'], 400);
    }

    if ($order->is_paid()) {
        return yuno_json([
            'error'    => 'Order already paid',
            'redirect' => $order->get_checkout_order_received_url(),
        ], 409);
    }

    // Transient lock to prevent double-charge
    $lock_key = 'yuno_pay_lock_' . $order->get_id();
    if (get_transient($lock_key)) {
        yuno_log('warning', '[PAYMENT] Duplicate payment attempt blocked by transient lock', [
            'order_id' => $order->get_id(),
        ]);
        return yuno_json(['error' => 'Payment already in progress'], 409);
    }
    set_transient($lock_key, true, 30);

    $api_url = yuno_api_url_from_public_key($public_key);

    // Get or create customer
    $customer_id = yuno_get_or_create_customer($order);

    $billing_country = $order->get_billing_country();
    $country  = $billing_country ?: YUNO_DEFAULT_COUNTRY;
    $currency = $order->get_currency() ?: 'COP';
    $decimals = yuno_get_wc_price_decimals();
    $total_major  = (float) $order->get_total();
    $amount_value = (float) number_format($total_major, $decimals, '.', '');

    // Build customer_payer with addresses
    $customer_payer = [
        'first_name' => $order->get_billing_first_name(),
        'last_name'  => $order->get_billing_last_name(),
        'email'      => $order->get_billing_email(),
        'country'    => $country,
    ];

    if (!empty($customer_id)) {
        $customer_payer['id'] = $customer_id;
    }

    $billing_phone = $order->get_billing_phone();
    if (!empty($billing_phone)) {
        $formatted_phone = yuno_format_phone_number($billing_phone, $country);
        if (!empty($formatted_phone) && is_array($formatted_phone)) {
            $customer_payer['phone'] = $formatted_phone;
        }
    }

    $billing_address = yuno_build_address_from_order($order, 'billing');
    if (!empty($billing_address)) {
        $customer_payer['billing_address'] = $billing_address;
    }

    $shipping_address = yuno_build_address_from_order($order, 'shipping', 'billing');
    if (!empty($shipping_address)) {
        $customer_payer['shipping_address'] = $shipping_address;
    }

    // Build payment payload
    $payload = [
        'account_id'       => $account_id,
        'description'      => 'WooCommerce Order #' . $order->get_id(),
        'merchant_order_id'=> 'WC-' . $order->get_id(),
        'country'          => $country,
        'amount'           => [
            'currency' => $currency,
            'value'    => $amount_value,
        ],
        'customer_payer'   => $customer_payer,
        'checkout'         => [
            'session' => $checkout_session,
        ],
        'payment_method'   => [
            'token'         => $one_time_token,
            'vaulted_token' => null,
        ],
    ];

    // Order items for risk assessment
    $items = [];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product) {
            $items[] = [
                'brand'                   => '',
                'category'                => '',
                'id'                      => (string) $product->get_id(),
                'manufacture_part_number' => '',
                'name'                    => $item->get_name(),
                'quantity'                => (int) $item->get_quantity(),
                'sku_code'                => $product->get_sku() ?: (string) $product->get_id(),
                'unit_amount'             => (float) $item->get_subtotal() / $item->get_quantity(),
            ];
        }
    }
    if (!empty($items)) {
        $payload['additional_data'] = [
            'order' => [
                'items'           => $items,
                'shipping_amount' => (float) $order->get_shipping_total(),
            ],
        ];
    }

    // Split marketplace
    $split_enabled_setting = (string) yuno_get_env('SPLIT_ENABLED', 'no');
    $is_split_enabled      = in_array($split_enabled_setting, ['yes', '1', 'true'], true);

    if ($is_split_enabled) {
        $recipient_id = trim((string) yuno_get_env('YUNO_RECIPIENT_ID', ''));

        if ($recipient_id === '') {
            delete_transient($lock_key);
            return yuno_json(['error' => 'Split is enabled but Yuno Recipient ID is missing'], 400);
        }

        $pct_raw     = trim((string) yuno_get_env('SPLIT_COMMISSION_PERCENT', ''));
        $fixed_raw   = trim((string) yuno_get_env('SPLIT_FIXED_AMOUNT', ''));
        $fixed_minor = ($fixed_raw !== '' && ctype_digit($fixed_raw)) ? (int) $fixed_raw : null;

        $commission_amount = 0.0;

        if ($pct_raw !== '') {
            $pct = (float) str_replace(',', '.', $pct_raw);
            if ($pct < 0 || $pct > 100) {
                delete_transient($lock_key);
                return yuno_json(['error' => 'Split commission percent must be between 0 and 100'], 400);
            }
            $commission_amount = round($amount_value * ($pct / 100.0), $decimals);
        } elseif ($fixed_minor !== null) {
            if ($fixed_minor < 0) {
                delete_transient($lock_key);
                return yuno_json(['error' => 'Split fixed amount must be >= 0 (minor units)'], 400);
            }
            $factor            = pow(10, $decimals);
            $commission_amount = round($fixed_minor / $factor, $decimals);
        }

        if ($commission_amount < 0 || $commission_amount > $amount_value) {
            delete_transient($lock_key);
            return yuno_json([
                'error'              => 'Split commission must be between 0 and order total',
                'commission_amount'  => $commission_amount,
                'order_total_amount' => $amount_value,
            ], 400);
        }

        $seller_amount = round($amount_value - $commission_amount, $decimals);

        $payload['split_marketplace'] = [
            [
                'recipient_id' => $recipient_id,
                'type'         => 'PURCHASE',
                'amount'       => ['value' => $seller_amount, 'currency' => $currency],
            ],
        ];

        if ($commission_amount > 0) {
            $payload['split_marketplace'][] = [
                'type'   => 'COMMISSION',
                'amount' => ['value' => $commission_amount, 'currency' => $currency],
            ];
        }

        yuno_log('info', '[PAYMENT] Including split_marketplace', [
            'order_id'          => $order->get_id(),
            'seller_amount'     => $seller_amount,
            'commission_amount' => $commission_amount,
            'recipient_id'      => $recipient_id,
        ]);
    }

    // Idempotency key to prevent duplicate payments
    $idempotency_key = 'wc-' . $order->get_id() . '-' . substr(md5($checkout_session . $one_time_token), 0, 12);

    yuno_log('info', '[PAYMENT] Creating payment via Yuno API', [
        'order_id'         => $order->get_id(),
        'checkout_session' => $checkout_session,
        'has_customer'     => !empty($customer_id),
        'amount'           => $amount_value,
        'currency'         => $currency,
        'country'          => $country,
        'has_split'        => $is_split_enabled,
    ]);

    $res = yuno_wp_remote_json(
        'POST',
        "{$api_url}/v1/payments",
        [
            'public-api-key'     => $public_key,
            'private-secret-key' => $secret_key,
            'Content-Type'       => 'application/json',
            'x-idempotency-key'  => $idempotency_key,
        ],
        $payload,
        30
    );

    if (!$res['ok']) {
        delete_transient($lock_key);
        yuno_log('error', '[PAYMENT] Yuno API call failed', [
            'order_id'    => $order->get_id(),
            'http_status' => $res['status'],
            'error_code'  => is_array($res['raw']) ? ($res['raw']['code'] ?? null) : null
        ]);
        return yuno_json([
            'error'  => 'Payment creation failed',
            'status' => $res['status'],
        ], 400);
    }

    $payment_id = is_array($res['raw']) ? ($res['raw']['id'] ?? null) : null;
    $sdk_action_required = is_array($res['raw']) ? ($res['raw']['sdk_action_required'] ?? false) : false;

    if ($payment_id) {
        $order->update_meta_data('_yuno_payment_id', $payment_id);
        $order->save();
    }

    yuno_log('info', '[PAYMENT] Payment created successfully', [
        'order_id'            => $order->get_id(),
        'payment_id'          => $payment_id,
        'sdk_action_required' => $sdk_action_required,
    ]);

    delete_transient($lock_key);

    return yuno_json([
        'payment_id'          => $payment_id,
        'sdk_action_required' => $sdk_action_required,
    ], 200);
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
    $expected_api_key = (string) yuno_get_env('WEBHOOK_API_KEY', '');
    $expected_x_secret = (string) yuno_get_env('WEBHOOK_X_SECRET', '');

    $received_api_key = (string) $request->get_header('x-api-key');
    $received_x_secret = (string) $request->get_header('x-secret');

    if ($expected_api_key === '' || $expected_x_secret === '') {
        yuno_log('error', '[WEBHOOK]WEBHOOK_API_KEY/WEBHOOK_X_SECRET not configured', []);
        return false;
    }

    if (!hash_equals($expected_api_key, $received_api_key)) {
        yuno_log('error', '[WEBHOOK]x-api-key mismatch', [
            'received' => $received_api_key !== '' ? substr($received_api_key, 0, 8) . '...' : '(empty)',
        ]);
        return false;
    }

    if (!hash_equals($expected_x_secret, $received_x_secret)) {
        yuno_log('error', '[WEBHOOK]x-secret mismatch', [
            'received' => $received_x_secret !== '' ? substr($received_x_secret, 0, 8) . '...' : '(empty)',
        ]);
        return false;
    }

    // 2) Validate HMAC signature (Client Secret Key from Yuno Dashboard)
    $received_sig = trim((string) $request->get_header('x-hmac-signature'));

    if ($received_sig === '') {
        yuno_log('warning', '[WEBHOOK]missing x-hmac-signature header', []);
        return false;
    }

    //CRITICAL FIX: Use WEBHOOK_HMAC_SECRET (not PRIVATE_SECRET_KEY)
    $hmac_secret = (string) yuno_get_env('WEBHOOK_HMAC_SECRET', '');

    if ($hmac_secret === '') {
        yuno_log('error', '[WEBHOOK]WEBHOOK_HMAC_SECRET not configured', []);
        return false;
    }

    $body = (string) $request->get_body();

    if ($body === '') {
        yuno_log('warning', '[WEBHOOK]empty body', []);
        return false;
    }

    // Remove "sha256=" prefix if present
    $received_sig = preg_replace('/^sha256=/i', '', $received_sig);

    // Compute HMAC in both hex and base64 formats (Yuno might send either)
    $computed_hex = hash_hmac('sha256', $body, $hmac_secret);
    $computed_b64 = base64_encode(hash_hmac('sha256', $body, $hmac_secret, true));

    $is_valid = hash_equals($computed_hex, $received_sig) || hash_equals($computed_b64, $received_sig);

    if (!$is_valid) {
        yuno_log('error', '[WEBHOOK]HMAC signature mismatch', [
            'received_prefix' => substr($received_sig, 0, 16) . '...',
            'computed_hex'    => substr($computed_hex, 0, 16) . '...',
            'computed_b64'    => substr($computed_b64, 0, 16) . '...',
            'body_length'     => strlen($body),
        ]);
        return false;
    }

    yuno_log('info', '[WEBHOOK]HMAC signature verified successfully', [
        'signature' => substr($received_sig, 0, 16) . '...',
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
        yuno_log('error', '[WEBHOOK]empty payload', []);
        // Return 200 to prevent Yuno retries (invalid payload won't be fixed by retry)
        return yuno_json(['received' => true, 'error' => 'Empty payload'], 200);
    }

    yuno_log('info', '[WEBHOOK]received event', [
        'account_id' => $payload['account_id'] ?? null,
        'type'       => $payload['type'] ?? null,
        'type_event' => $payload['type_event'] ?? null,
        'version'    => $payload['version'] ?? null,
        'retry'      => $payload['retry'] ?? 0,
    ]);

    // Extract event type and data
    // Support two webhook formats:
    // Format 1: { "type_event": "payment.succeeded", "data": { "payment": {...} } }
    // Format 2: { "payment": {...} } (infer event from payment.status)
    $event_type = $payload['type_event'] ?? '';
    $event_data = $payload['data'] ?? [];

    // Normalize Format 1: $event_data is { "payment": {...} }, unwrap to get payment object
    if (isset($event_data['payment']) && is_array($event_data['payment'])) {
        $event_data = $event_data['payment'];
    }

    // If no type_event but payment object exists, infer event from status
    if (empty($event_type) && isset($payload['payment'])) {
        $payment_status = $payload['payment']['status'] ?? '';
        $payment_sub_status = $payload['payment']['sub_status'] ?? '';
        $event_data = $payload['payment'];

        // Check sub_status first for special cases
        if (strtoupper($payment_sub_status) === 'PARTIALLY_REFUNDED') {
            // SUCCEEDED + PARTIALLY_REFUNDED = partial refund
            $event_type = 'payment.refund';
        } elseif (strtoupper($payment_status) === 'REFUNDED') {
            // REFUNDED status = full or partial refund (check sub_status in handler)
            $event_type = 'payment.refund';
        } else {
            // Map payment status to event type
            switch ($payment_status) {
                case 'SUCCEEDED':
                case 'APPROVED':
                case 'PAYED':
                    $event_type = 'payment.succeeded';
                    break;
                case 'REJECTED':
                case 'DECLINED':
                case 'FAILED':
                    $event_type = 'payment.failed';
                    break;
                case 'CANCELLED':
                case 'EXPIRED':
                    $event_type = 'payment.declined';
                    break;
                case 'PENDING':
                    $event_type = 'payment.pending';
                    break;
                default:
                    yuno_log('warning', '[WEBHOOK]unknown payment status', [
                        'status' => $payment_status,
                        'sub_status' => $payment_sub_status,
                        'payment_id' => $payload['payment']['id'] ?? null,
                    ]);
                    $event_type = 'payment.unknown';
            }
        }

        yuno_log('info', '[WEBHOOK]inferred event type from payment status', [
            'payment_status' => $payment_status,
            'payment_sub_status' => $payment_sub_status,
            'inferred_event' => $event_type,
        ]);
    }

    if (empty($event_type)) {
        yuno_log('error', '[WEBHOOK]missing type_event and unable to infer from payload', ['payload' => $payload]);
        return yuno_json(['received' => true, 'error' => 'Missing type_event'], 200);
    }

    // Extract checkout session (primary identifier for order lookup)
    $checkout_session = $event_data['checkout']['session'] ?? null;

    if (empty($checkout_session)) {
        yuno_log('error', '[WEBHOOK]missing checkout session in event data', [
            'event_type' => $event_type,
            'data_keys'  => array_keys($event_data),
            'full_data'  => yuno_debug_enabled() ? $event_data : null,
        ]);
        return yuno_json(['received' => true, 'error' => 'Missing checkout session'], 200);
    }

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $checkout_session)) {
        yuno_log('error', '[WEBHOOK]invalid checkout session format', ['session' => $checkout_session]);
        return yuno_json(['received' => true, 'error' => 'Invalid checkout session'], 200);
    }

    yuno_log('info', '[WEBHOOK]processing event', [
        'event_type'       => $event_type,
        'checkout_session' => $checkout_session,
    ]);

    // Find order by checkout session
    $order = yuno_find_order_by_checkout_session_id($checkout_session, $event_data);

    if (!$order) {
        yuno_log('warning', '[WEBHOOK]order not found for checkout session', [
            'event_type'       => $event_type,
            'checkout_session' => $checkout_session,
        ]);
        // Return 200 to prevent retries (order might not exist yet or session not saved)
        return yuno_json(['received' => true, 'error' => 'Order not found'], 200);
    }

    yuno_log('info', '[WEBHOOK]order found', [
        'event_type'       => $event_type,
        'checkout_session' => $checkout_session,
        'order_id'         => $order->get_id(),
        'order_status'     => $order->get_status(),
    ]);

    // Process event based on type
    switch ($event_type) {
        case 'payment.purchase':
        case 'payment.succeeded':
            return yuno_webhook_handle_payment_succeeded($order, $checkout_session, $event_data);

        case 'payment.failed':
        case 'payment.rejected':
        case 'payment.declined':
            return yuno_webhook_handle_payment_failed($order, $checkout_session, $event_data);

        case 'payment.chargeback':
            return yuno_webhook_handle_chargeback($order, $checkout_session, $event_data);

        case 'payment.refunds':
        case 'payment.refund':
        case 'refunds':
            return yuno_webhook_handle_refund($order, $checkout_session, $event_data);

        case 'payment.pending':
            $payment_sub_status = $event_data['sub_status'] ?? 'UNKNOWN';

            yuno_log('info', '[WEBHOOK]payment pending', [
                'event_type'       => $event_type,
                'order_id'         => $order->get_id(),
                'checkout_session' => $checkout_session,
                'sub_status'       => $payment_sub_status,
            ]);

            $order->add_order_note('Yuno webhook: ' . $event_type . ' (session=' . $checkout_session . ', sub_status=' . $payment_sub_status . ')');
            $order->save();

            return yuno_json(['received' => true, 'event_type' => $event_type], 200);

        default:
            yuno_log('info', '[WEBHOOK]unhandled event type', [
                'event_type'       => $event_type,
                'order_id'         => $order->get_id(),
                'checkout_session' => $checkout_session,
            ]);

            // Add note for unhandled events (for debugging)
            $order->add_order_note('Yuno webhook: ' . $event_type . ' (session=' . $checkout_session . ')');
            $order->save();

            return yuno_json(['received' => true, 'event_type' => $event_type], 200);
    }
}
/**
 * Find WooCommerce order by Yuno checkout session ID
 * HPOS-compatible: uses wc_get_orders() instead of direct DB query
 *
 * @param string $checkout_session Yuno checkout session ID
 * @param array $event_data Optional event data for fallback (merchant_order_id)
 * @return WC_Order|null
 */
function yuno_find_order_by_checkout_session_id($checkout_session, $event_data = []) {
    // Primary lookup: match stored checkout session meta
    $orders = wc_get_orders([
        'limit'      => 1,
        'meta_key'   => '_yuno_checkout_session',
        'meta_value' => $checkout_session,
        'return'     => 'objects',
    ]);

    if (!empty($orders)) {
        yuno_log('info', '[WEBHOOK]found order by checkout_session', [
            'checkout_session' => $checkout_session,
            'order_id'         => $orders[0]->get_id(),
        ]);
        return $orders[0];
    }

    // Fallback: extract order_id from merchant_order_id (format: WC-{order_id})
    $merchant_order_id = $event_data['merchant_order_id'] ?? null;

    if (!empty($merchant_order_id) && preg_match('/^WC-(\d+)$/', $merchant_order_id, $matches)) {
        $order_id = intval($matches[1]);
        $order = wc_get_order($order_id);

        if ($order) {
            yuno_log('info', '[WEBHOOK]found order via merchant_order_id fallback', [
                'checkout_session'  => $checkout_session,
                'merchant_order_id' => $merchant_order_id,
                'order_id'          => $order_id,
            ]);
            return $order;
        }
    }

    yuno_log('warning', '[WEBHOOK]order not found', [
        'checkout_session'  => $checkout_session,
        'merchant_order_id' => $merchant_order_id ?? null,
    ]);

    return null;
}

/**
 * Handle payment.succeeded / payment.purchase webhook
 *
 * @param WC_Order $order
 * @param string $checkout_session Yuno checkout session ID
 * @param array $event_data Normalized payment object
 * @return WP_REST_Response
 */
function yuno_webhook_handle_payment_succeeded($order, $checkout_session, $event_data) {
    $order_id = $order->get_id();

    // Use lock to prevent race condition with frontend confirmation
    $lock_key = 'yuno_webhook_lock_' . $order_id;

    if (get_transient($lock_key)) {
        yuno_log('info', '[WEBHOOK]payment.succeeded - already processing', [
            'order_id'         => $order_id,
            'checkout_session' => $checkout_session,
        ]);
        return yuno_json(['received' => true, 'skipped' => 'already_processing'], 200);
    }

    set_transient($lock_key, 1, 30);

    try {
        // Check if already paid (idempotency)
        if ($order->is_paid()) {
            yuno_log('info', '[WEBHOOK]payment.succeeded - order already paid', [
                'order_id'         => $order_id,
                'checkout_session' => $checkout_session,
                'order_status'     => $order->get_status(),
            ]);

            delete_transient($lock_key);
            return yuno_json(['received' => true, 'skipped' => 'already_paid'], 200);
        }

        // CRITICAL: Verify payment status with Yuno API before marking as paid
        // Don't trust webhook event alone - always verify with API
        $public_key = yuno_get_env('PUBLIC_API_KEY', '');
        $secret_key = yuno_get_env('PRIVATE_SECRET_KEY', '');

        if ($public_key && $secret_key) {
            $api_url = yuno_api_url_from_public_key($public_key);

            yuno_log('info', '[WEBHOOK]payment.succeeded - verifying with Yuno API', [
                'order_id'         => $order_id,
                'checkout_session' => $checkout_session,
            ]);

            $res = yuno_wp_remote_json(
                'GET',
                "{$api_url}/v1/checkout/sessions/{$checkout_session}/payment",
                [
                    'public-api-key'     => $public_key,
                    'private-secret-key' => $secret_key,
                ],
                null,
                30
            );

            if ($res['ok']) {
                $verified_status = yuno_extract_payment_status($res['raw']);

                yuno_log('info', '[WEBHOOK]payment.succeeded - verified status from API', [
                    'order_id'         => $order_id,
                    'checkout_session' => $checkout_session,
                    'verified_status'  => $verified_status,
                ]);

                // If payment is NOT actually succeeded, mark as failed instead
                if (in_array($verified_status, YUNO_STATUS_FAILURE, true)) {
                    yuno_log('warning', '[WEBHOOK]payment.succeeded event but API shows FAILED - marking as failed', [
                        'order_id'         => $order_id,
                        'checkout_session' => $checkout_session,
                        'verified_status'  => $verified_status,
                    ]);

                    $order->update_status('failed', 'Yuno webhook: Payment succeeded event received but API verification shows: ' . $verified_status);
                    $order->add_order_note('Yuno webhook: Payment succeeded event received but verification failed. status=' . $verified_status . ' session=' . $checkout_session);

                    delete_transient($lock_key);

                    // Do NOT duplicate here: duplication is frontend-driven (customer must be present).
                    // If the customer is still on the page the SDK will fire a failure callback.
                    // If they are not, runPreflightChecks() will detect the failed status on their
                    // next visit and redirect them to a fresh order at that point.

                    return yuno_json(['received' => true, 'order_updated' => true, 'status' => 'failed'], 200);
                }

                // If payment is not confirmed as succeeded, don't mark as paid yet
                if (!in_array($verified_status, YUNO_STATUS_SUCCESS, true)) {
                    yuno_log('warning', '[WEBHOOK]payment.succeeded event but status not confirmed', [
                        'order_id'         => $order_id,
                        'checkout_session' => $checkout_session,
                        'verified_status'  => $verified_status,
                    ]);

                    $order->add_order_note('Yuno webhook: Payment succeeded event received but status is: ' . $verified_status . ' (session=' . $checkout_session . ')');
                    $order->save();

                    delete_transient($lock_key);
                    return yuno_json(['received' => true, 'status' => 'pending_confirmation'], 200);
                }
            }
        }

        // Mark order as paid (only if verified or no public key to verify)
        $order->payment_complete($checkout_session);
        $order->add_order_note('Yuno webhook: Payment succeeded (session=' . $checkout_session . ')');

        yuno_log('info', '[WEBHOOK]payment.succeeded - order marked as paid', [
            'order_id'         => $order_id,
            'checkout_session' => $checkout_session,
            'order_status'     => $order->get_status(),
        ]);

        delete_transient($lock_key);

        return yuno_json(['received' => true, 'order_updated' => true], 200);

    } catch (Exception $e) {
        yuno_log('error', '[WEBHOOK]payment.succeeded - error', [
            'order_id'         => $order_id,
            'checkout_session' => $checkout_session,
            'error'            => $e->getMessage(),
        ]);

        delete_transient($lock_key);

        // Return 200 to prevent retries (will log error for manual review)
        return yuno_json(['received' => true, 'error' => 'Internal processing error'], 200);
    }
}

/**
 * Handle payment.failed / payment.rejected webhook
 *
 * @param WC_Order $order
 * @param string $checkout_session Yuno checkout session ID
 * @param array $event_data Normalized payment object
 * @return WP_REST_Response
 */
function yuno_webhook_handle_payment_failed($order, $checkout_session, $event_data) {
    $order_id = $order->get_id();

    // Extract status from event data
    $status = yuno_extract_payment_status($event_data);

    yuno_log('info', '[WEBHOOK]payment.failed', [
        'order_id'         => $order_id,
        'checkout_session' => $checkout_session,
        'status'           => $status,
        'order_status'     => $order->get_status(),
    ]);

    // Don't update if already failed
    if ($order->get_status() === 'failed') {
        yuno_log('info', '[WEBHOOK]payment.failed - order already failed', [
            'order_id'         => $order_id,
            'checkout_session' => $checkout_session,
        ]);
        return yuno_json(['received' => true, 'skipped' => 'already_failed'], 200);
    }

    // Don't update if already paid (webhook might be out of order)
    if ($order->is_paid()) {
        yuno_log('warning', '[WEBHOOK]payment.failed - order is already paid, ignoring', [
            'order_id'         => $order_id,
            'checkout_session' => $checkout_session,
            'order_status'     => $order->get_status(),
        ]);
        return yuno_json(['received' => true, 'skipped' => 'already_paid'], 200);
    }

    // Mark as failed
    $order->update_status('failed', 'Yuno webhook: Payment failed - ' . $status);
    $order->add_order_note('Yuno webhook: Payment failed (session=' . $checkout_session . ', status=' . $status . ')');

    yuno_log('info', '[WEBHOOK]payment.failed - order marked as failed', [
        'order_id'         => $order_id,
        'checkout_session' => $checkout_session,
    ]);

    // Do NOT duplicate here: duplication is frontend-driven (customer must be present).
    // If the customer is on the page the SDK fires PAYMENT_RETRY / yunoPaymentResult(FAILURE)
    // and the frontend creates the duplicate. If they are not, runPreflightChecks() detects
    // the failed status on their next visit and redirects them to a fresh order at that point.

    return yuno_json(['received' => true, 'order_updated' => true], 200);
}

/**
 * Handle payment.chargeback webhook
 *
 * @param WC_Order $order
 * @param string $checkout_session Yuno checkout session ID
 * @param array $event_data Normalized payment object
 * @return WP_REST_Response
 */
function yuno_webhook_handle_chargeback($order, $checkout_session, $event_data) {
    $order_id = $order->get_id();

    yuno_log('warning', '[WEBHOOK]payment.chargeback', [
        'order_id'         => $order_id,
        'checkout_session' => $checkout_session,
        'order_status'     => $order->get_status(),
        'event_data'       => $event_data,
    ]);

    // Add chargeback note
    $order->add_order_note('⚠️ CHARGEBACK: Yuno reported a chargeback (session=' . $checkout_session . '). Please review.');

    // Optionally update status to on-hold or refunded
    // We don't auto-refund because chargebacks need manual review
    if (!in_array($order->get_status(), ['cancelled', 'refunded'], true)) {
        $order->update_status('on-hold', 'Yuno webhook: Chargeback received - requires manual review');
    }

    $order->save();

    yuno_log('info', '[WEBHOOK]payment.chargeback - order updated', [
        'order_id'         => $order_id,
        'checkout_session' => $checkout_session,
        'order_status'     => $order->get_status(),
    ]);

    return yuno_json(['received' => true, 'order_updated' => true], 200);
}

/**
 * Handle payment.refunds webhook
 *
 * Refund determination based on status, sub_status AND amount:
 * 1. Full refund: (status=REFUNDED AND sub_status=REFUNDED) OR total_refunded >= order_total
 *    → Order status changed to 'refunded'
 * 2. Partial refund: any other case (e.g. status=SUCCEEDED, sub_status=PARTIALLY_REFUNDED)
 *    → Order status remains unchanged, refund object created, note added
 *
 * @param WC_Order $order
 * @param string $checkout_session Yuno checkout session ID
 * @param array $event_data Normalized payment object
 * @return WP_REST_Response
 */
function yuno_webhook_handle_refund($order, $checkout_session, $event_data) {
    $order_id = $order->get_id();
    $order_total = (float) $order->get_total();

    // Extract status and sub_status to determine refund type
    $status = strtoupper($event_data['status'] ?? '');
    $sub_status = strtoupper($event_data['sub_status'] ?? '');

    yuno_log('info', '[WEBHOOK]payment.refunds', [
        'order_id'         => $order_id,
        'checkout_session' => $checkout_session,
        'order_status'     => $order->get_status(),
        'order_total'      => $order_total,
        'yuno_status'      => $status,
        'yuno_sub_status'  => $sub_status,
        'event_data'       => $event_data,
    ]);

    // Extract refund amount from the transaction, not the order total
    // Priority: transactions.amount (this specific refund) > amount.refunded (total refunded)
    $refund_amount = null;
    if (isset($event_data['transactions']['amount'])) {
        // This is the amount of THIS specific refund transaction
        $refund_amount = (float) $event_data['transactions']['amount'];
    } elseif (isset($event_data['amount']['refunded'])) {
        // Fallback: use the total refunded amount from Yuno
        $refund_amount = (float) $event_data['amount']['refunded'];
    }

    if ($refund_amount === null || $refund_amount <= 0) {
        yuno_log('warning', '[WEBHOOK]refund without valid amount', [
            'order_id'         => $order_id,
            'checkout_session' => $checkout_session,
            'status'           => $status,
            'sub_status'       => $sub_status,
        ]);

        $order->add_order_note(sprintf(
            'Yuno webhook: Refund event received but amount not specified (status=%s, sub_status=%s, session=%s)',
            $status,
            $sub_status,
            $checkout_session
        ));
        $order->save();

        return yuno_json(['received' => true, 'warning' => 'No refund amount'], 200);
    }

    $already_refunded = $order->get_total_refunded();
    $new_total_refunded = $already_refunded + $refund_amount;

    // Determine if this is partial or full refund by checking BOTH status fields AND amounts
    // This protects against inconsistencies (e.g. status says partial but amount is 100%)
    $amount_is_full = ($new_total_refunded >= ($order_total - 0.01)); // 0.01 margin for float precision
    $status_indicates_full = ($status === 'REFUNDED' && $sub_status === 'REFUNDED');

    // Full refund if EITHER:
    // 1. Both status fields indicate full refund (REFUNDED + REFUNDED), OR
    // 2. Total refunded amount equals or exceeds order total (regardless of what status says)
    $is_full_refund = $status_indicates_full || $amount_is_full;

    yuno_log('info', '[WEBHOOK]refund analysis', [
        'order_id'             => $order_id,
        'order_total'          => $order_total,
        'refund_amount'        => $refund_amount,
        'already_refunded'     => $already_refunded,
        'new_total_refunded'   => $new_total_refunded,
        'status'               => $status,
        'sub_status'           => $sub_status,
        'amount_is_full'       => $amount_is_full,
        'status_indicates_full'=> $status_indicates_full,
        'is_full_refund'       => $is_full_refund,
    ]);

    try {
        $refund = wc_create_refund([
            'amount'         => $refund_amount,
            'reason'         => sprintf('Yuno %s refund (session=%s)',
                                       $is_full_refund ? 'full' : 'partial',
                                       $checkout_session),
            'order_id'       => $order_id,
            'line_items'     => [], // Empty = refund without specific items
            'refund_payment' => false, // Already processed in Yuno
            'restock_items'  => false, // No automatic restock
        ]);

        if (is_wp_error($refund)) {
            yuno_log('error', '[WEBHOOK]failed to create WC refund object', [
                'order_id'   => $order_id,
                'error'      => $refund->get_error_message(),
            ]);

            $note = sprintf(
                'Yuno webhook: %s refund processed externally (session=%s) - Amount: %s',
                $is_full_refund ? 'Full' : 'Partial',
                $checkout_session,
                wc_price($refund_amount, ['currency' => $order->get_currency()])
            );
            $order->add_order_note($note);
        } else {
            yuno_log('info', '[WEBHOOK]WC refund object created', [
                'order_id'   => $order_id,
                'refund_id'  => $refund->get_id(),
                'amount'     => $refund_amount,
                'type'       => $is_full_refund ? 'full' : 'partial',
            ]);
        }
    } catch (Exception $e) {
        yuno_log('error', '[WEBHOOK]exception creating refund', [
            'order_id' => $order_id,
            'error'    => $e->getMessage(),
        ]);

        $order->add_order_note(sprintf(
            'Yuno webhook: Refund error - %s (session=%s, amount=%s)',
            $e->getMessage(),
            $checkout_session,
            wc_price($refund_amount, ['currency' => $order->get_currency()])
        ));
    }

    if ($is_full_refund) {
        if (!in_array($order->get_status(), ['refunded', 'cancelled'], true)) {
            $order->update_status('refunded', sprintf(
                'Yuno webhook: Order fully refunded (total: %s)',
                wc_price($new_total_refunded, ['currency' => $order->get_currency()])
            ));

            yuno_log('info', '[WEBHOOK]order status changed to refunded', [
                'order_id'          => $order_id,
                'total_refunded'    => $new_total_refunded,
                'order_total'       => $order_total,
            ]);
        }
    } else {
        $note = sprintf(
            'Yuno webhook: Partial refund of %s (session=%s). Total refunded: %s of %s (%.1f%%)',
            wc_price($refund_amount, ['currency' => $order->get_currency()]),
            $checkout_session,
            wc_price($new_total_refunded, ['currency' => $order->get_currency()]),
            wc_price($order_total, ['currency' => $order->get_currency()]),
            ($new_total_refunded / $order_total) * 100
        );
        $order->add_order_note($note);

        yuno_log('info', '[WEBHOOK]partial refund processed, status unchanged', [
            'order_id'          => $order_id,
            'refund_amount'     => $refund_amount,
            'total_refunded'    => $new_total_refunded,
            'order_total'       => $order_total,
            'remaining'         => $order_total - $new_total_refunded,
            'refund_percentage' => ($new_total_refunded / $order_total) * 100,
        ]);
    }

    $order->save();

    return yuno_json([
        'received'       => true,
        'order_updated'  => true,
        'refund_type'    => $is_full_refund ? 'full' : 'partial',
        'refund_amount'  => $refund_amount,
        'total_refunded' => $new_total_refunded,
        'order_status'   => $order->get_status(),
    ], 200);
}
