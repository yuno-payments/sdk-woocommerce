<?php
if (!defined('ABSPATH')) exit;

class WC_Gateway_Yuno_Card extends WC_Payment_Gateway {

  public function __construct() {
    $this->id                 = 'yuno_card';
    $this->method_title       = 'Card (Yuno)';
    $this->method_description = 'Card payment using Yuno';

    $this->has_fields = false;
    $this->supports = ['products'];

    $this->init_form_fields();
    $this->init_settings();

    $title = $this->get_option('title', 'Yuno Card');
    $this->title = (is_string($title) && $title !== '') ? $title : 'Yuno Card';

    $enabled = $this->get_option('enabled', 'no');
    $this->enabled = ($enabled === 'yes') ? 'yes' : 'no';

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

    // Early redirect hook: intercept BEFORE headers are sent
    add_action('template_redirect', [$this, 'early_redirect_paid_orders']);
  }

  public function get_title() {
    $t = isset($this->title) ? (string) $this->title : '';
    return $t !== '' ? $t : 'Yuno Card';
  }

  /**
   * Early redirect for paid orders (before headers are sent)
   * Provides instant redirect without rendering the payment page
   */
  public function early_redirect_paid_orders() {
    // Only run on order-pay page
    if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
      return;
    }

    global $wp;
    $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;

    if (!$order_id) {
      return;
    }

    $order = wc_get_order($order_id);

    if (!$order) {
      return;
    }

    // Only redirect if this is our payment method
    if ($order->get_payment_method() !== $this->id) {
      return;
    }

    // Check if order is already paid
    $paid_statuses = ['processing', 'completed', 'on-hold'];

    if ($order->is_paid() || in_array($order->get_status(), $paid_statuses, true)) {
      yuno_log('info', 'Early redirect: order already paid', [
        'order_id' => $order_id,
        'status'   => $order->get_status(),
      ]);

      wp_safe_redirect($order->get_checkout_order_received_url());
      exit;
    }
  }

  public function init_form_fields() {
    $this->form_fields = [
      'enabled' => [
        'title'   => 'Enable',
        'type'    => 'checkbox',
        'label'   => 'Enable Card (Yuno)',
        'default' => 'no',
      ],
      'title' => [
        'title'       => 'Checkout Title',
        'type'        => 'text',
        'description' => 'Name the user will see at checkout.',
        'default'     => 'Yuno Card',
        'desc_tip'    => true,
      ],
      'account_id' => [
        'title'       => 'ACCOUNT_ID',
        'type'        => 'text',
        'description' => 'Yuno account ID.',
        'default'     => '',
        'desc_tip'    => true,
      ],
      'public_api_key' => [
        'title'       => 'PUBLIC_API_KEY',
        'type'        => 'text',
        'description' => 'Public API key (used in frontend to initialize the SDK).',
        'default'     => '',
        'desc_tip'    => true,
      ],
      'private_secret_key' => [
        'title'       => 'PRIVATE_SECRET_KEY',
        'type'        => 'password',
        'description' => 'Private secret key (backend only).',
        'default'     => '',
        'desc_tip'    => true,
      ],

      'split_enabled' => [
        'title'       => 'Split Payments',
        'type'        => 'checkbox',
        'label'       => 'Enable marketplace split',
        'default'     => 'no',
        'description' => 'If enabled, the plugin will include split_marketplace data when creating the payment.',
      ],
      'yuno_recipient_id' => [
        'title'       => 'Yuno Recipient ID (Seller)',
        'type'        => 'text',
        'description' => 'Recipient ID created in Yuno for the seller/publisher (NOT provider recipient ID). Required when Split is enabled.',
        'default'     => '',
        'desc_tip'    => true,
      ],

      'split_commission_percent' => [
        'title'       => 'Commission % (Platform)',
        'type'        => 'text',
        'description' => 'Commission percentage over the FINAL order total (incl. taxes/shipping). Example: 15 or 15.5. Use 0 for passthrough (100% seller). If set, it overrides Fixed Commission Amount.',
        'default'     => '',
        'desc_tip'    => true,
      ],

      // Legacy/MVP: fixed commission minor units
      'split_fixed_amount' => [
        'title'       => 'Fixed Commission Amount (minor units)',
        'type'        => 'text',
        'description' => 'Platform commission in minor units. Example: COP=1000 means $1.000 COP commission. Use 0 for passthrough (100% seller). Ignored if Commission % is set.',
        'default'     => '',
        'desc_tip'    => true,
      ],

      'debug' => [
        'title'       => 'Debug',
        'type'        => 'checkbox',
        'label'       => 'Enable debug logs',
        'default'     => 'no',
        'description' => 'Log events using WooCommerce logger.',
      ],

      // Webhook security settings
      'webhook_hmac_secret' => [
        'title'       => 'Webhook HMAC Secret',
        'type'        => 'password',
        'description' => 'Client Secret Key from Yuno Dashboard (Webhooks section). Used to verify webhook signatures.',
        'default'     => '',
        'desc_tip'    => true,
      ],
      'webhook_api_key' => [
        'title'       => 'Webhook API Key',
        'type'        => 'password',
        'description' => 'x-api-key header value configured in Yuno Dashboard webhooks.',
        'default'     => '',
        'desc_tip'    => true,
      ],
      'webhook_x_secret' => [
        'title'       => 'Webhook X-Secret',
        'type'        => 'password',
        'description' => 'x-secret header value configured in Yuno Dashboard webhooks.',
        'default'     => '',
        'desc_tip'    => true,
      ],
    ];
  }

  public function process_admin_options() {
    $saved = parent::process_admin_options();

    $split_enabled = $this->get_option('split_enabled', 'no') === 'yes';
    $recipient_id  = trim((string) $this->get_option('yuno_recipient_id', ''));

    $pct_raw = trim((string) $this->get_option('split_commission_percent', ''));
    $fixed_raw = trim((string) $this->get_option('split_fixed_amount', ''));

    if ($split_enabled) {
      $errors = [];

      if ($recipient_id === '') {
        $errors[] = 'Yuno Recipient ID is required when Split is enabled.';
      }

      $has_pct = ($pct_raw !== '');
      $has_fixed = ($fixed_raw !== '');

      if (!$has_pct && !$has_fixed) {
        $errors[] = 'When Split is enabled you must set either Commission % or Fixed Commission Amount.';
      }

      if ($has_pct) {
        $pct = (float) str_replace(',', '.', $pct_raw);
        if ($pct < 0 || $pct > 100) {
          $errors[] = 'Commission % must be between 0 and 100.';
        }
      }

      if (!$has_pct && $has_fixed) {
        // fixed must be integer >= 0
        if (!ctype_digit($fixed_raw)) {
          $errors[] = 'Fixed Commission Amount must be an integer (minor units).';
        } else if ((int)$fixed_raw < 0) {
          $errors[] = 'Fixed Commission Amount must be >= 0.';
        }
      }

      if (!empty($errors)) {
        foreach ($errors as $msg) {
          WC_Admin_Settings::add_error($msg);
        }

        // Fail-safe: disable split to avoid leaving invalid config active
        $this->update_option('split_enabled', 'no');
        WC_Admin_Settings::add_error('Split was automatically disabled due to invalid configuration.');

        $this->init_settings();
      }
    }

    return $saved;
  }

  /**
   * Settings helpers for rest-api.php
   */
  public static function get_settings_array() {
    $opt = get_option('woocommerce_yuno_card_settings', []);
    return is_array($opt) ? $opt : [];
  }

  public static function get_setting($key, $default = '') {
    $s = self::get_settings_array();
    if (isset($s[$key]) && $s[$key] !== '') return $s[$key];

    return $default;
  }

  public function is_available() {
    if ('yes' !== $this->enabled) return false;

    $account = (string) $this->get_option('account_id', '');
    $pub     = (string) $this->get_option('public_api_key', '');
    $priv    = (string) $this->get_option('private_secret_key', '');

    if ($account === '' || $pub === '' || $priv === '') return false;

    return true;
  }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);

    if (!$order) {
      return [
        'result'   => 'fail',
        'redirect' => wc_get_checkout_url(),
      ];
    }

    if ($order->get_status() !== 'pending') {
      $order->update_status('pending', 'Awaiting Yuno payment');
    } else {
      $order->add_order_note('Awaiting Yuno payment');
    }

    wc_reduce_stock_levels($order_id);

    return [
      'result'   => 'success',
      'redirect' => $order->get_checkout_payment_url(true),
    ];
  }

  public function receipt_page($order_id) {
    $order = wc_get_order($order_id);

    if (!$order) {
      echo '<p>' . esc_html__('Order not found.', 'yuno') . '</p>';
      return;
    }

    // If order is already paid, redirect immediately to order-received page
    // This prevents showing the payment page again when user refreshes or goes back
    $order_status = $order->get_status();

    yuno_log('debug', 'receipt_page - checking order status', [
      'order_id' => $order->get_id(),
      'status'   => $order_status,
    ]);

    if (in_array($order_status, ['processing', 'completed', 'on-hold'], true)) {
      yuno_log('info', 'receipt_page - redirecting to order-received (order already paid)', [
        'order_id' => $order->get_id(),
        'status'   => $order_status,
      ]);

      // Show minimal loader with instant redirect
      // This provides better UX than showing the full payment page and then redirecting
      $redirect_url = esc_url($order->get_checkout_order_received_url());
      ?>
      <!DOCTYPE html>
      <html <?php language_attributes(); ?>>
      <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="refresh" content="0;url=<?php echo $redirect_url; ?>">
        <title><?php echo esc_html__('Redirecting...', 'yuno'); ?></title>
        <style>
          * { margin: 0; padding: 0; box-sizing: border-box; }
          body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #f7f7f7;
            color: #333;
          }
          .redirect-container {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 400px;
          }
          .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #000;
            border-radius: 50%;
            width: 48px;
            height: 48px;
            animation: spin 1s linear infinite;
            margin: 0 auto 24px;
          }
          @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
          }
          h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #000;
          }
          p {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
          }
          .success-icon {
            font-size: 48px;
            margin-bottom: 16px;
          }
        </style>
        <script>
          // Immediate redirect (faster than meta refresh)
          window.location.href = <?php echo wp_json_encode($redirect_url); ?>;
        </script>
      </head>
      <body>
        <div class="redirect-container">
          <div class="success-icon">✓</div>
          <h2><?php echo esc_html__('Payment Successful!', 'yuno'); ?></h2>
          <p><?php echo esc_html__('Redirecting to your order confirmation...', 'yuno'); ?></p>
          <div class="spinner"></div>
        </div>
      </body>
      </html>
      <?php
      exit;
    }

    yuno_log('debug', 'receipt_page - showing payment page', [
      'order_id' => $order->get_id(),
      'status'   => $order_status,
    ]);

    $order_number = (int) $order->get_id();
    $total_html   = wp_kses_post($order->get_formatted_order_total());
    $order_date   = $order->get_date_created() ? $order->get_date_created()->date_i18n(wc_date_format()) : '';
    $payment_method_title = $this->get_title();

    echo '<div class="yuno-receipt">';
    echo '<h2 class="yuno-page-title">' . esc_html__('Complete your payment', 'yuno') . '</h2>';
    echo '<div class="yuno-order-summary">';

    echo '<div class="yuno-order-item">';
    echo '<span class="yuno-order-label">' . esc_html__('Order', 'yuno') . '</span>';
    echo '<span class="yuno-order-value" id="yuno-order-number">#' . esc_html($order_number) . '</span>';
    echo '</div>';

    echo '<div class="yuno-order-item">';
    echo '<span class="yuno-order-label">' . esc_html__('Date', 'yuno') . '</span>';
    echo '<span class="yuno-order-value">' . esc_html($order_date) . '</span>';
    echo '</div>';

    echo '<div class="yuno-order-item">';
    echo '<span class="yuno-order-label">' . esc_html__('Total', 'yuno') . '</span>';
    echo '<span class="yuno-order-value yuno-order-total" id="yuno-order-total">' . $total_html . '</span>';
    echo '</div>';

    echo '<div class="yuno-order-item">';
    echo '<span class="yuno-order-label">' . esc_html__('Payment Method', 'yuno') . '</span>';
    echo '<span class="yuno-order-value">' . esc_html($payment_method_title) . '</span>';
    echo '</div>';

    echo '</div>';

    echo '<div id="yuno-loader" class="yuno-loader" style="display:none;">' . esc_html__('Loading payment…', 'yuno') . '</div>';
    echo '<div id="yuno-root"></div>';
    echo '<div id="yuno-apm-form"></div>';
    echo '<div id="yuno-action-form"></div>';

    echo '<button type="button" id="yuno-button-pay" class="yuno-pay-button" style="display:none;">' .
         esc_html__('Pay', 'yuno') .
         '</button>';

    echo '</div>'; // .yuno-receipt
  }

  public function enqueue_scripts() {
    // Load CSS on checkout page (for payment method styling)
    if (function_exists('is_checkout') && is_checkout() && !is_order_received_page() && !is_checkout_pay_page()) {
      wp_enqueue_style(
        'yuno-checkout',
        plugin_dir_url(__DIR__) . 'assets/css/checkout.css',
        [],
        filemtime(plugin_dir_path(__DIR__) . 'assets/css/checkout.css')
      );
      return;
    }

    // Load full scripts and SDK on order-pay page
    if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) return;

    global $wp;
    $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    if ($order->get_payment_method() !== $this->id) return;

    // Enqueue scoped CSS to prevent theme conflicts (double borders, focus rings)
    wp_enqueue_style(
      'yuno-checkout',
      plugin_dir_url(__DIR__) . 'assets/css/checkout.css',
      [],
      filemtime(plugin_dir_path(__DIR__) . 'assets/css/checkout.css')
    );

    wp_enqueue_script(
      'yuno-sdk',
      'https://sdk-web.y.uno/v1.5/main.js',
      [],
      null,
      true
    );

    wp_enqueue_script(
      'yuno-api',
      plugin_dir_url(__DIR__) . 'assets/js/api.js',
      [],
      filemtime(plugin_dir_path(__DIR__) . 'assets/js/api.js'),
      true
    );

    wp_enqueue_script(
      'yuno-checkout',
      plugin_dir_url(__DIR__) . 'assets/js/checkout.js',
      ['yuno-api', 'yuno-sdk'],
      filemtime(plugin_dir_path(__DIR__) . 'assets/js/checkout.js'),
      true
    );

    $country = (string) ($order->get_billing_country() ?: 'CO');
    $email   = (string) ($order->get_billing_email() ?: '');

    // Get WordPress locale and convert to ISO 639-1 format (es, en, pt)
    $wp_locale = get_locale(); // e.g., es_ES, en_US, pt_BR
    $language = substr($wp_locale, 0, 2); // Extract first 2 chars: es, en, pt

    wp_localize_script('yuno-checkout', 'YUNO_WC', [
      'restBase' => esc_url_raw(rest_url('yuno/v1')),
      'nonce'    => wp_create_nonce('wp_rest'),

      'payForOrder' => true,
      'orderId'   => (int) $order->get_id(),
      'orderKey'  => (string) $order->get_order_key(),
      'currency'  => (string) $order->get_currency(),
      'total'     => (float) $order->get_total(),
      'country'   => $country,
      'email'     => $email,
      'language'  => $language,

      'debug' => (self::get_setting('debug', 'no') === 'yes'),
    ]);
  }
}
