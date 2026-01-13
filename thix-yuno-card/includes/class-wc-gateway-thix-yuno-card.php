<?php
if (!defined('ABSPATH')) exit;

class WC_Gateway_Thix_Yuno_Card extends WC_Payment_Gateway {

  public function __construct() {
    $this->id = 'thix_yuno_card';
    $this->method_title = 'Card (Yuno)';
    $this->method_description = 'Card payment using Yuno';
    $this->has_fields = true;

    $this->supports = ['products'];

    $this->init_form_fields();
    $this->init_settings();

    // Settings
    $this->title = $this->get_option('title', 'Card');
    $this->enabled = $this->get_option('enabled', 'no');

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
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

      'environment' => [
        'title'       => 'Environment',
        'type'        => 'select',
        'description' => 'Select the Yuno environment.',
        'default'     => 'sandbox',
        'desc_tip'    => true,
        'options'     => [
          'sandbox' => 'Sandbox',
          'prod'    => 'Production',
          'staging' => 'Staging',
          'dev'     => 'Dev',
        ],
      ],

      'account_code' => [
        'title'       => 'ACCOUNT_CODE',
        'type'        => 'text',
        'description' => 'Yuno account code.',
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

      'debug' => [
        'title'       => 'Debug',
        'type'        => 'checkbox',
        'label'       => 'Enable debug logs',
        'default'     => 'no',
        'description' => 'Log events using WooCommerce logger.',
      ],
    ];
  }

  /**
   * Helper so rest-api.php can also read settings from Woo:
   * get_option('woocommerce_thix_yuno_card_settings')
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

    // Optional: if no keys, don't show gateway
    $account = $this->get_option('account_code', '');
    $pub = $this->get_option('public_api_key', '');
    $priv = $this->get_option('private_secret_key', '');

    if (!$account || !$pub || !$priv) return false;

    return true;
  }

  public function payment_fields() {
    echo '<div id="loader" style="display:none; margin:8px 0;">Loading...</div>';
    echo '<div id="root"></div>';
    echo '<div id="form-element"></div>';
    echo '<div id="action-form-element"></div>';
    echo '<button type="button" id="button-pay" style="margin-top:12px;">Pay Now</button>';
    echo '<p style="margin-top:8px; opacity:.7;">Note: first pay with Yuno, then complete the order.</p>';
  }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);

    // MVP: Do NOT complete here (if orderId is not yet linked)
    // In the orderId/confirmation phase: set status to pending + redirect to "order-pay" or received.
    $order->payment_complete();
    $order->add_order_note('Simulated payment with Yuno (MVP).');

    return [
      'result'   => 'success',
      'redirect' => $this->get_return_url($order),
    ];
  }

  public function enqueue_scripts() {
    static $enqueued = false;
    if ($enqueued) return;
    $enqueued = true;

    if (!is_checkout()) return;

    // SDK Yuno
    wp_enqueue_script(
      'yuno-sdk',
      'https://sdk-web.y.uno/v1.5/main.js',
      [],
      null,
      true
    );

    // api.js
    wp_enqueue_script(
      'thix-yuno-api',
      plugin_dir_url(__DIR__) . 'assets/js/api.js',
      [],
      '0.1.0',
      true
    );

    // checkout.js
    wp_enqueue_script(
      'thix-yuno-checkout',
      plugin_dir_url(__DIR__) . 'assets/js/checkout.js',
      ['thix-yuno-api', 'yuno-sdk'],
      '0.1.0',
      true
    );

    add_filter('script_loader_tag', function ($tag, $handle, $src) {
      if (in_array($handle, ['thix-yuno-api', 'thix-yuno-checkout'], true)) {
        return '<script type="module" src="' . esc_url($src) . '"></script>';
      }
      return $tag;
    }, 10, 3);

    wp_localize_script('thix-yuno-checkout', 'THIX_YUNO_WC', [
      'restBase' => esc_url_raw(rest_url('thix-yuno/v1')),
      'nonce'    => wp_create_nonce('wp_rest'),
      // Useful for debug:
      'debug'    => (self::get_setting('debug', 'no') === 'yes'),
    ]);
  }
}
