<?php
if (!defined('ABSPATH')) exit;

class WC_Gateway_Thix_Yuno_Card extends WC_Payment_Gateway {

  public function __construct() {
    $this->id                 = 'thix_yuno_card';
    $this->method_title       = 'Card (Yuno)';
    $this->method_description = 'Card payment using Yuno';
    $this->has_fields         = true;

    $this->supports = ['products'];

    $this->init_form_fields();
    $this->init_settings();

    $this->title       = $this->get_option('title', 'Yuno Card');
    $this->enabled     = $this->get_option('enabled', 'yes');

    add_action(
      'woocommerce_update_options_payment_gateways_' . $this->id,
      [$this, 'process_admin_options']
    );

    add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
  }

  public function is_available() {
    if ('yes' !== $this->enabled) return false;
    return true;
  }

  public function init_form_fields() {
    $this->form_fields = [
      'enabled' => [
        'title'   => 'Enable',
        'type'    => 'checkbox',
        'label'   => 'Enable Card (Yuno)',
        'default' => 'yes'
      ],
      'title' => [
        'title'   => 'Checkout Title',
        'type'    => 'text',
        'default' => 'Yuno Card'
      ],
    ];
  }

  /**
   * UI displayed when the payment method is selected.
   * NOTE: Woo may re-render this (AJAX), so JS uses event delegation.
   */
  public function payment_fields() {
    $order_id  = 0;
    $order_key = '';
    $pay_for_order = false;

    // Detect order-pay context
    if (is_wc_endpoint_url('order-pay')) {
      $pay_for_order = true;
      $order_id = absint(get_query_var('order-pay'));
      $order_key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
    }

    echo '<div id="loader" style="display:none; margin:8px 0;">Loading...</div>';
    echo '<div id="root" style="min-height:40px;"></div>';
    echo '<div id="form-element"></div>';
    echo '<div id="action-form-element"></div>';

    // So JS always knows the current order
    echo '<input type="hidden" id="thix_yuno_order_id" value="' . esc_attr($order_id) . '" />';
    echo '<input type="hidden" id="thix_yuno_order_key" value="' . esc_attr($order_key) . '" />';
    echo '<input type="hidden" id="thix_yuno_pay_for_order" value="' . esc_attr($pay_for_order ? '1' : '0') . '" />';

    echo '<button type="button" id="button-pay" style="margin-top:12px;">Pay Now</button>';

    echo '<p style="margin-top:10px; font-size: 12px; opacity: .8;">
      Note: first pay with Yuno, then complete the order.
    </p>';
  }

  /**
   * Woo calls this when the user clicks "Place order".
   * Here we should NOT mark the order as paid.
   * We create/ensure pending status and redirect to order-pay.
   */
  public function process_payment($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
      return [
        'result'   => 'failure',
        'redirect' => '',
      ];
    }

    // Ensure "pending" status while paying with Yuno
    if (!$order->has_status(['pending', 'on-hold'])) {
      $order->update_status('pending', 'Awaiting Yuno payment');
    }

    // This sends the user to the order-pay endpoint
    $redirect = $order->get_checkout_payment_url(true);

    return [
      'result'   => 'success',
      'redirect' => $redirect,
    ];
  }

  /**
   * Scripts: only on checkout and order-pay pages.
   */
  public function enqueue_scripts() {
    static $enqueued = false;
    if ($enqueued) return;

    // Normal checkout or pay order (order-pay)
    $is_checkout_or_pay = (function_exists('is_checkout') && is_checkout()) || is_wc_endpoint_url('order-pay');
    if (!$is_checkout_or_pay) return;

    $enqueued = true;

    // 1) SDK Yuno
    wp_enqueue_script(
      'yuno-sdk',
      'https://sdk-web.y.uno/v1.5/main.js',
      [],
      null,
      true
    );

    // 2) api.js (module)
    wp_enqueue_script(
      'thix-yuno-api',
      plugin_dir_url(__DIR__) . 'assets/js/api.js',
      [],
      '0.1.0',
      true
    );

    // 3) checkout.js (module)
    wp_enqueue_script(
      'thix-yuno-checkout',
      plugin_dir_url(__DIR__) . 'assets/js/checkout.js',
      ['thix-yuno-api', 'yuno-sdk'],
      '0.1.0',
      true
    );

    // Force type="module"
    add_filter('script_loader_tag', function ($tag, $handle, $src) {
      if (in_array($handle, ['thix-yuno-api', 'thix-yuno-checkout'], true)) {
        return '<script type="module" src="' . esc_url($src) . '"></script>';
      }
      return $tag;
    }, 10, 3);

    // Detect order-pay params for JS (more reliable than DOM)
    $order_id = 0;
    $order_key = '';
    $pay_for_order = false;

    if (is_wc_endpoint_url('order-pay')) {
      $pay_for_order = true;
      $order_id = absint(get_query_var('order-pay'));
      $order_key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
    }

    wp_localize_script('thix-yuno-checkout', 'THIX_YUNO_WC', [
      'restBase'     => esc_url_raw(rest_url('thix-yuno/v1')),
      'nonce'        => wp_create_nonce('wp_rest'),
      'orderId'      => $order_id,
      'orderKey'     => $order_key,
      'payForOrder'  => $pay_for_order ? 1 : 0,
    ]);
  }
}
