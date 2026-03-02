<?php
/**
 * Plugin Name: Yuno WooCommerce Gateway
 * Description: Accept payments with Yuno - cards, wallets, and local payment methods.
 * Version: 0.5.2
 */

if (!defined('ABSPATH')) exit;

// Define plugin version constant for asset versioning
define('YUNO_WC_VERSION', '0.5.2');

// Ensure WooCommerce is active
add_action('plugins_loaded', function () {
  if (!class_exists('WooCommerce')) {
    return;
  }

  // Load the gateway class
  require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-yuno-card.php';
  require_once plugin_dir_path(__FILE__) . 'includes/rest-api.php';

  // Payment Gateway Registration
  add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_Yuno_Card';
    return $gateways;
  });

  // Determine post-payment order status: physical → "processing", all-virtual → "completed"
  // Uses per-product needs_shipping() instead of order-level needs_processing()
  // (needs_processing requires virtual AND downloadable; needs_shipping only checks virtual)
  // Priority 999 runs AFTER theme/plugin filters that may force incorrect status
  add_filter('woocommerce_payment_complete_order_status', function ($status, $order_id, $order) {
    if ($order && $order->get_payment_method() === 'yuno_card') {
      $has_physical = false;
      foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->needs_shipping()) {
          $has_physical = true;
          break;
        }
      }
      $result = $has_physical ? 'processing' : 'completed';

      if (function_exists('yuno_log')) {
        yuno_log('info', 'Order status filter applied', [
          'order_id'      => $order_id,
          'has_physical'  => $has_physical ? 'YES' : 'NO',
          'input_status'  => $status,
          'output_status' => $result,
        ]);
      }

      return $result;
    }
    return $status;
  }, 999, 3);
});
