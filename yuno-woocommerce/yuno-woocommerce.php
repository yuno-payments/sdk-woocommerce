<?php
/**
 * Plugin Name: Yuno WooCommerce Gateway
 * Description: Accept payments with Yuno - cards, wallets, and local payment methods.
 * Version: 0.5.0
 */

if (!defined('ABSPATH')) exit;

// Define plugin version constant for asset versioning
define('YUNO_WC_VERSION', '0.5.0');

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

  // Fix WooCommerce theme filter that forces all orders to "processing"
  // This filter ensures virtual/downloadable products go to "completed" status
  // Priority 999 ensures it runs AFTER theme/plugin filters (which typically use priority 10)
  add_filter('woocommerce_payment_complete_order_status', function ($status, $order_id, $order) {
    // Only apply to Yuno payment gateway orders
    if ($order && $order->get_payment_method() === 'yuno_card') {
      // If order doesn't need shipping (virtual/downloadable), set to completed
      if (!$order->needs_shipping_address()) {
        return 'completed';
      }
      // If order needs shipping (physical products), keep as processing
      return 'processing';
    }
    // For other payment gateways, return original status
    return $status;
  }, 999, 3);
});
