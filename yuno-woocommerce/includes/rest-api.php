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

    register_rest_route('yuno/v1', '/customer', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'yuno_create_customer_endpoint',
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
    $accountId = yuno_get_env('ACCOUNT_ID', '');
    $publicKey = yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$accountId || !$publicKey || !$secretKey) {
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

    //FIX: ALWAYS create new checkout session (never reuse)
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

    $payload = [
        'account_id'         => $accountId,
        'merchant_order_id'  => 'WC-' . $order->get_id(),
        'merchant_reference' => 'WC-' . $order->get_id(),
        'payment_description'=> 'WooCommerce Order #' . $order->get_id(),
        'country'            => $country,
        'amount'             => [
            'currency' => $currency,
            'value'    => $amount_value,   // major units
        ],
        'workflow'           => 'SDK_SEAMLESS',
        'customer_id'        => $customer_id ?: null,
        'payment_method'     => [
            'detail'          => [
                'card' => ['capture' => true],
            ],
            'vault_on_success' => false,
        ],
    ];

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
        yuno_log('info', '[CHECKOUT SESSION]Including billing_address', [
            'order_id' => $order->get_id(),
            'billing'  => $billing_address,
        ]);
    }

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
        yuno_log('info', '[CHECKOUT SESSION]Including shipping_address', [
            'order_id' => $order->get_id(),
            'shipping' => $shipping_address,
        ]);
    }

    // additional_data: order items for risk assessment
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

    // split_marketplace: moved from /payments endpoint to checkout session
    $split_enabled_setting = (string) yuno_get_env('SPLIT_ENABLED', 'no');
    $split_enabled         = in_array($split_enabled_setting, ['yes','1','true'], true);

    if ($split_enabled) {
        $recipient_id = trim((string) yuno_get_env('YUNO_RECIPIENT_ID', ''));

        if ($recipient_id === '') {
            return yuno_json(['error' => 'Split is enabled but Yuno Recipient ID is missing'], 400);
        }

        $pct_raw     = trim((string) yuno_get_env('SPLIT_COMMISSION_PERCENT', ''));
        $fixed_raw   = trim((string) yuno_get_env('SPLIT_FIXED_AMOUNT', ''));
        $fixed_minor = ($fixed_raw !== '' && ctype_digit($fixed_raw)) ? (int) $fixed_raw : null;

        $commission_amount = 0.0;
        $decimals_split    = yuno_get_wc_price_decimals();

        if ($pct_raw !== '') {
            $pct = (float) str_replace(',', '.', $pct_raw);
            if ($pct < 0 || $pct > 100) {
                return yuno_json(['error' => 'Split commission percent must be between 0 and 100'], 400);
            }
            $commission_amount = round($amount_value * ($pct / 100.0), $decimals_split);
        } elseif ($fixed_minor !== null) {
            if ($fixed_minor < 0) {
                return yuno_json(['error' => 'Split fixed amount must be >= 0 (minor units)'], 400);
            }
            $factor            = pow(10, $decimals_split);
            $commission_amount = round($fixed_minor / $factor, $decimals_split);
        }

        if ($commission_amount < 0 || $commission_amount > $amount_value) {
            return yuno_json([
                'error'              => 'Split commission must be between 0 and order total',
                'commission_amount'  => $commission_amount,
                'order_total_amount' => $amount_value,
            ], 400);
        }

        $seller_amount = round($amount_value - $commission_amount, $decimals_split);

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

        yuno_log('info', '[CHECKOUT SESSION]Including split_marketplace', [
            'order_id'         => $order->get_id(),
            'seller_amount'    => $seller_amount,
            'commission_amount'=> $commission_amount,
            'recipient_id'     => $recipient_id,
        ]);
    }

    yuno_log('info', '[CHECKOUT SESSION]Final payload before API call', [
        'order_id'             => $order->get_id(),
        'has_customer_id'      => !empty($customer_id) ? 'YES' : 'NO',
        'has_additional_data'  => isset($payload['additional_data']) ? 'YES' : 'NO',
        'has_split_marketplace'=> isset($payload['split_marketplace']) ? 'YES' : 'NO',
        'payload'              => $payload,
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
                    yuno_log('error', '[CHECKOUT SESSION]Retry failed after customer recreation', [
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
                    'response' => $res['raw'],
                ], 400);
            }
        } else {
            yuno_log('error', '[CHECKOUT SESSION]API call failed', [
                'order_id' => $order->get_id(),
                'status'   => $res['status'],
                'response' => $res['raw'],
            ]);
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
            'country' => $country,
            'phone'   => $phone,
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

            yuno_log('info', '[PHONE]Formatted phone from international format', [
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
        yuno_log('warning', '[PHONE]Phone number too short', [
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

    yuno_log('info', '[PHONE]Formatted phone number', [
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
    yuno_log('info', '[CUSTOMER]Function called', [
        'order_id' => $order->get_id(),
    ]);

    $secretKey = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (empty($secretKey)) {
        yuno_log('warning', '[CUSTOMER]missing PRIVATE_SECRET_KEY, skipping customer creation');
        return null; // Graceful degradation - continues without customer
    }

    yuno_log('info', '[CUSTOMER]PRIVATE_SECRET_KEY found', [
        'key_length' => strlen($secretKey),
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

    //Always CREATE new customer (no reuse, no update logic)
    yuno_log('info', '[CUSTOMER]Creating new customer (always fresh per order)', [
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

    yuno_log('info', '[CUSTOMER]API response received', [
        'order_id' => $order->get_id(),
        'ok'       => $res['ok'] ? 'TRUE' : 'FALSE',
        'status'   => $res['status'] ?? 'N/A',
        'response' => $res['raw'], // Log complete response
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
                "{$apiUrl}/v1/customers?merchant_customer_id=" . urlencode($merchant_customer_id),
                [
                    'public-api-key'     => $publicKey,
                    'private-secret-key' => $secretKey,
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
            'order_id'  => $order->get_id(),
            'status'    => $res['status'],
            'response'  => $res['raw'],
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
            'order_id' => $order->get_id(),
            'response' => $res['raw'],
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

    // Verify via checkout session (SDK_SEAMLESS flow — payment_id is never provided by frontend)
    $checkout_session = (string) $order->get_meta('_yuno_checkout_session');

    if (!$checkout_session) {
        yuno_log('error', '[CONFIRM] Missing checkout session', ['order_id' => $order->get_id()]);
        return yuno_json(['error' => 'Missing checkout session'], 400);
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

    $publicKey = yuno_get_env('PUBLIC_API_KEY', '');
    $secretKey = yuno_get_env('PRIVATE_SECRET_KEY', '');

    if (!$publicKey || !$secretKey) {
        yuno_log('error', 'Confirm: missing API keys', ['order_id' => $order->get_id()]);
        return yuno_json(['error' => 'Missing API keys'], 500);
    }

    $apiUrl = yuno_api_url_from_public_key($publicKey);

    yuno_log('info', '[CONFIRM] Verifying via checkout session', [
        'order_id'   => $order->get_id(),
        'session_id' => $checkout_session,
    ]);

    $res = yuno_wp_remote_json(
        'GET',
        "{$apiUrl}/v1/checkout/sessions/{$checkout_session}/payment",
        ['public-api-key' => $publicKey],
        null,
        30
    );

    if (!$res['ok']) {
        yuno_log('error', '[CONFIRM] Failed to verify payment via checkout session', [
            'order_id'   => $order->get_id(),
            'session_id' => $checkout_session,
            'status'     => $res['status'],
            'response'   => $res['raw'],
        ]);
        $order->add_order_note('Yuno verification failed (HTTP ' . $res['status'] . '). session=' . $checkout_session);
        $order->save();
        return yuno_json(['error' => 'Could not verify payment status with Yuno', 'retry' => true], 500);
    }

    $verified_status = strtoupper(trim((string) ($res['raw']['status'] ?? 'UNKNOWN')));

    yuno_log('info', '[CONFIRM] Payment status from checkout session', [
        'order_id'        => $order->get_id(),
        'session_id'      => $checkout_session,
        'verified_status' => $verified_status,
        'client_status'   => $client_status,
        'full_response'   => yuno_debug_enabled() ? $res['raw'] : null,
    ]);

    // Handle verified status
    if (in_array($verified_status, ['SUCCEEDED', 'VERIFIED', 'APPROVED', 'PAYED'], true)) {
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
                'order_id'       => $order->get_id(),
                'needs_shipping' => $order->needs_shipping_address() ? 'YES' : 'NO',
                'has_downloadable' => $order->has_downloadable_item() ? 'YES' : 'NO',
                'products'       => $order_items_debug,
                'current_status' => $order->get_status(),
                'active_filters' => $active_filters ?: 'NONE',
            ]);
        }

        $order->payment_complete();

        $order->add_order_note('Yuno payment approved. status=' . $verified_status . ' session=' . $checkout_session);
        $order->save();

        // Debug diagnostics: only run if debug mode is enabled
        if (yuno_debug_enabled()) {
            $status_after_payment_complete = $order->get_status();

            yuno_log('info', 'Confirm: order marked as paid AFTER payment_complete()', [
                'order_id'                      => $order->get_id(),
                'session_id'                    => $checkout_session,
                'status_after_payment_complete' => $status_after_payment_complete,
                'needs_shipping'                => $order->needs_shipping_address() ? 'YES' : 'NO',
                'expected'                      => $order->needs_shipping_address() ? 'processing' : 'completed',
            ]);
        }

        return yuno_json([
            'ok'        => true,
            'order_id'  => $order->get_id(),
            'new_status'=> $order->get_status(),
            'redirect'  => $order->get_checkout_order_received_url(),
        ], 200);
    }

    if (in_array($verified_status, ['REJECTED', 'DECLINED', 'CANCELLED', 'ERROR', 'EXPIRED', 'FAILED'], true)) {
        $order->update_status('failed', 'Yuno payment rejected (verified): ' . $verified_status);
        $order->add_order_note('Yuno payment rejected. status=' . $verified_status . ' session=' . $checkout_session);
        $order->save();

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

    //Intermediate states (PENDING, PROCESSING, REQUIRES_ACTION, etc.)
    // Change order status to 'on-hold' to indicate payment is processing
    // WooCommerce status meanings:
    // - 'pending' = Waiting for payment (order created but no payment initiated)
    // - 'on-hold' = Payment received/processing, waiting for confirmation
    // - 'processing' = Payment confirmed, order being fulfilled
    if (in_array($verified_status, ['PENDING', 'PROCESSING', 'REQUIRES_ACTION'], true)) {
        $order->update_status('on-hold', 'Yuno payment pending confirmation: ' . $verified_status);
    }

    $order->add_order_note('Yuno payment status: ' . $verified_status . ' session=' . $checkout_session);
    $order->save();

    yuno_log('info', 'Confirm: payment in intermediate state, order set to on-hold', [
        'order_id'     => $order->get_id(),
        'session_id'   => $checkout_session,
        'yuno_status'  => $verified_status,
        'order_status' => $order->get_status(),
    ]);

    //For PENDING payments (3DS, etc.), DON'T redirect to order-received
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

    // AUTO-DUPLICATE: If order is in failed state, signal frontend to duplicate
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

    // CRITICAL: Check if payment_id exists (payment was initiated)
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

        //Sync Yuno status with WooCommerce: mark as failed
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

    foreach ($order->get_items('shipping') as $item_id => $item) {
        $cloned_item = clone $item;
        $cloned_item->set_id(0); // Reset ID so WooCommerce creates a new item
        $new_order->add_item($cloned_item);
    }

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
    $order->save();

    // Store reference to original order in duplicate
    $new_order->update_meta_data('_yuno_original_order_id', $order->get_id());
    $new_order->save();

    $order->add_order_note('New order #' . $new_order->get_id() . ' created for payment retry.');
    $order->save();

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

    //CRITICAL FIX: Use WEBHOOK_HMAC_SECRET (not PRIVATE_SECRET_KEY)
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

    // Extract event type and data
    // Support two webhook formats:
    // Format 1: { "type_event": "payment.succeeded", "data": {...} }
    // Format 2: { "payment": {...} } (infer event from payment.status)
    $event_type = $payload['type_event'] ?? '';
    $event_data = $payload['data'] ?? [];

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
                default:
                    yuno_log('warning', 'Webhook: unknown payment status', [
                        'status' => $payment_status,
                        'sub_status' => $payment_sub_status,
                        'payment_id' => $payload['payment']['id'] ?? null,
                    ]);
                    $event_type = 'payment.unknown';
            }
        }

        yuno_log('info', 'Webhook: inferred event type from payment status', [
            'payment_status' => $payment_status,
            'payment_sub_status' => $payment_sub_status,
            'inferred_event' => $event_type,
        ]);
    }

    if (empty($event_type)) {
        yuno_log('error', 'Webhook: missing type_event and unable to infer from payload', ['payload' => $payload]);
        return yuno_json(['received' => true, 'error' => 'Missing type_event'], 200);
    }

    // Extract payment_id from event data (try multiple possible locations)
    $payment_id = $event_data['id']
        ?? $event_data['payment_id']
        ?? $event_data['code']
        ?? $payload['payment']['id']
        ?? $payload['payment']['code']
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
 * HPOS-compatible: uses wc_get_orders() instead of direct DB query
 *
 * @param string $payment_id
 * @param array $event_data Optional event data for fallback (merchant_order_id)
 * @return WC_Order|null
 */
function yuno_find_order_by_payment_id($payment_id, $event_data = []) {
    // Use wc_get_orders() with meta_query for HPOS compatibility
    // This works with both traditional posts and HPOS (WC 8.2+)
    $orders = wc_get_orders([
        'limit'      => 1,
        'meta_key'   => '_yuno_payment_id',
        'meta_value' => $payment_id,
        'return'     => 'objects',
    ]);

    if (!empty($orders)) {
        yuno_log('info', 'Webhook: found order by payment_id', [
            'payment_id' => $payment_id,
            'order_id'   => $orders[0]->get_id(),
        ]);
        return $orders[0];
    }

    // Fallback: extract order_id from merchant_order_id (try multiple locations)
    $merchant_order_id = $event_data['merchant_order_id']
        ?? $event_data['payment']['merchant_order_id']
        ?? null;

    if (!empty($merchant_order_id)) {
        // Extract order_id from merchant_order_id (format: WC-{order_id})
        if (preg_match('/^WC-(\d+)$/', $merchant_order_id, $matches)) {
            $order_id = intval($matches[1]);
            $order = wc_get_order($order_id);

            if ($order) {
                yuno_log('info', 'Webhook: found order via merchant_order_id fallback', [
                    'payment_id'        => $payment_id,
                    'merchant_order_id' => $merchant_order_id,
                    'order_id'          => $order_id,
                ]);
                return $order;
            }
        }
    }

    yuno_log('warning', 'Webhook: order not found', [
        'payment_id'        => $payment_id,
        'merchant_order_id' => $merchant_order_id ?? null,
    ]);

    return null;
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

        //CRITICAL: Verify payment status with Yuno API before marking as paid
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

    //AUTO-CREATE NEW ORDER: Create duplicate order for retry (same as frontend behavior)
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
 * Refund determination based on status, sub_status AND amount:
 * 1. Full refund: (status=REFUNDED AND sub_status=REFUNDED) OR total_refunded >= order_total
 *    → Order status changed to 'refunded'
 * 2. Partial refund: any other case (e.g. status=SUCCEEDED, sub_status=PARTIALLY_REFUNDED)
 *    → Order status remains unchanged, refund object created, note added
 *
 * @param WC_Order $order
 * @param string $payment_id
 * @param array $event_data
 * @return WP_REST_Response
 */
function yuno_webhook_handle_refund($order, $payment_id, $event_data) {
    $order_id = $order->get_id();
    $order_total = (float) $order->get_total();

    // Extract status and sub_status to determine refund type
    $status = strtoupper($event_data['status'] ?? '');
    $sub_status = strtoupper($event_data['sub_status'] ?? '');

    yuno_log('info', 'Webhook: payment.refunds', [
        'order_id'     => $order_id,
        'payment_id'   => $payment_id,
        'order_status' => $order->get_status(),
        'order_total'  => $order_total,
        'yuno_status'  => $status,
        'yuno_sub_status' => $sub_status,
        'event_data'   => $event_data,
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
        yuno_log('warning', 'Webhook: refund without valid amount', [
            'order_id'   => $order_id,
            'payment_id' => $payment_id,
            'status'     => $status,
            'sub_status' => $sub_status,
        ]);

        $order->add_order_note(sprintf(
            'Yuno webhook: Refund event received but amount not specified (status=%s, sub_status=%s, payment_id=%s)',
            $status,
            $sub_status,
            $payment_id
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

    yuno_log('info', 'Webhook: refund analysis', [
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
            'reason'         => sprintf('Yuno %s refund (payment_id=%s)',
                                       $is_full_refund ? 'full' : 'partial',
                                       $payment_id),
            'order_id'       => $order_id,
            'line_items'     => [], // Empty = refund without specific items
            'refund_payment' => false, // Already processed in Yuno
            'restock_items'  => false, // No automatic restock
        ]);

        if (is_wp_error($refund)) {
            yuno_log('error', 'Webhook: failed to create WC refund object', [
                'order_id'   => $order_id,
                'error'      => $refund->get_error_message(),
            ]);

            $note = sprintf(
                'Yuno webhook: %s refund processed externally (payment_id=%s) - Amount: %s',
                $is_full_refund ? 'Full' : 'Partial',
                $payment_id,
                wc_price($refund_amount, ['currency' => $order->get_currency()])
            );
            $order->add_order_note($note);
        } else {
            yuno_log('info', 'Webhook: WC refund object created', [
                'order_id'   => $order_id,
                'refund_id'  => $refund->get_id(),
                'amount'     => $refund_amount,
                'type'       => $is_full_refund ? 'full' : 'partial',
            ]);
        }
    } catch (Exception $e) {
        yuno_log('error', 'Webhook: exception creating refund', [
            'order_id' => $order_id,
            'error'    => $e->getMessage(),
        ]);

        $order->add_order_note(sprintf(
            'Yuno webhook: Refund error - %s (payment_id=%s, amount=%s)',
            $e->getMessage(),
            $payment_id,
            wc_price($refund_amount, ['currency' => $order->get_currency()])
        ));
    }

    if ($is_full_refund) {
        if (!in_array($order->get_status(), ['refunded', 'cancelled'], true)) {
            $order->update_status('refunded', sprintf(
                'Yuno webhook: Order fully refunded (total: %s)',
                wc_price($new_total_refunded, ['currency' => $order->get_currency()])
            ));

            yuno_log('info', 'Webhook: order status changed to refunded', [
                'order_id'          => $order_id,
                'total_refunded'    => $new_total_refunded,
                'order_total'       => $order_total,
            ]);
        }
    } else {
        $note = sprintf(
            'Yuno webhook: Partial refund of %s (payment_id=%s). Total refunded: %s of %s (%.1f%%)',
            wc_price($refund_amount, ['currency' => $order->get_currency()]),
            $payment_id,
            wc_price($new_total_refunded, ['currency' => $order->get_currency()]),
            wc_price($order_total, ['currency' => $order->get_currency()]),
            ($new_total_refunded / $order_total) * 100
        );
        $order->add_order_note($note);

        yuno_log('info', 'Webhook: partial refund processed, status unchanged', [
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
