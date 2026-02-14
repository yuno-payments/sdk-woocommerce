<?php
/**
 * Plugin Name: Yuno WooCommerce Gateway
 * Description: Yuno payment gateway integration for WooCommerce.
 * Version: 0.3.1
 */

if (!defined('ABSPATH')) exit;

// Define plugin version constant for asset versioning
define('YUNO_WC_VERSION', '0.3.1');

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
});
