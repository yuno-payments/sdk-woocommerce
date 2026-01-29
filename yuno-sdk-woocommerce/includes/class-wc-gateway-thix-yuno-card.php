<?php
if (!defined('ABSPATH')) exit;

class WC_Gateway_Thix_Yuno_Card extends WC_Payment_Gateway {

  public function __construct() {
    $this->id                 = 'thix_yuno_card';
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
  }

  public function get_title() {
    $t = isset($this->title) ? (string) $this->title : '';
    return $t !== '' ? $t : 'Yuno Card';
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
        'description' => 'Public API key from Yuno. The key prefix (sandbox_, prod_, staging_, dev_) determines the environment automatically.',
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

      // ✅ Split (MVP)
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

      // ✅ New recommended: commission %
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
    $opt = get_option('woocommerce_thix_yuno_card_settings', []);
    return is_array($opt) ? $opt : [];
  }

  public static function get_setting($key, $default = '') {
    $s = self::get_settings_array();
    if (isset($s[$key]) && $s[$key] !== '') return $s[$key];
    return $default;
  }

  public function is_available() {
    if ('yes' !== $this->enabled) return false;

    $account = (string) $this->get_option('account_code', '');
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
      echo '<p>' . esc_html__('Order not found.', 'thix-yuno') . '</p>';
      return;
    }

    $order_number = (int) $order->get_id();
    $total_html   = wp_kses_post($order->get_formatted_order_total());

    echo '<div class="thix-yuno-receipt">';
    echo '<h3>' . esc_html__('Pay with Yuno', 'thix-yuno') . '</h3>';
    echo '<p>' . esc_html__('Order', 'thix-yuno') . ' #' . esc_html($order_number) . ' — ' .
         esc_html__('Total', 'thix-yuno') . ': <strong>' . $total_html . '</strong></p>';

    echo '<div id="loader" style="display:none; margin:12px 0;">' . esc_html__('Loading Yuno…', 'thix-yuno') . '</div>';
    echo '<div id="root"></div>';
    echo '<div id="form-element"></div>';
    echo '<div id="action-form-element"></div>';

    echo '<button type="button" id="button-pay" style="margin-top:12px; padding:10px 14px;">' .
         esc_html__('Pay Now', 'thix-yuno') .
         '</button>';

    echo '<p style="margin-top:10px; opacity:.7;">' .
         esc_html__('Do not close this page until the payment finishes.', 'thix-yuno') .
         '</p>';

    echo '</div>';
  }

  public function enqueue_scripts() {
    if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) return;

    global $wp;
    $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    if ($order->get_payment_method() !== $this->id) return;

    wp_enqueue_script(
      'yuno-sdk',
      'https://sdk-web.y.uno/v1.5/main.js',
      [],
      null,
      true
    );

    wp_enqueue_script(
      'thix-yuno-api',
      plugin_dir_url(__DIR__) . 'assets/js/api.js',
      [],
      '0.2.2',
      true
    );

    wp_enqueue_script(
      'thix-yuno-checkout',
      plugin_dir_url(__DIR__) . 'assets/js/checkout.js',
      ['thix-yuno-api', 'yuno-sdk'],
      '0.2.2',
      true
    );

    static $module_filter_added = false;
    if (!$module_filter_added) {
      $module_filter_added = true;
      add_filter('script_loader_tag', function ($tag, $handle, $src) {
        if (in_array($handle, ['thix-yuno-api', 'thix-yuno-checkout'], true)) {
          return '<script type="module" src="' . esc_url($src) . '"></script>';
        }
        return $tag;
      }, 10, 3);
    }

    $country = (string) ($order->get_billing_country() ?: 'CO');
    $email   = (string) ($order->get_billing_email() ?: '');

    wp_localize_script('thix-yuno-checkout', 'THIX_YUNO_WC', [
      'restBase' => esc_url_raw(rest_url('thix-yuno/v1')),
      'nonce'    => wp_create_nonce('wp_rest'),

      'payForOrder' => true,
      'orderId'   => (int) $order->get_id(),
      'orderKey'  => (string) $order->get_order_key(),
      'currency'  => (string) $order->get_currency(),
      'total'     => (float) $order->get_total(),
      'country'   => $country,
      'email'     => $email,

      'debug' => (self::get_setting('debug', 'no') === 'yes'),
    ]);
  }
}
