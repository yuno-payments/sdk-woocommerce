<?php
/**
 * Plugin Name: 3thix | Yuno Card Gateway (Dev)
 * Description: Development environment for the gateway.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

// Ensure WooCommerce is active
add_action('plugins_loaded', function () {
  if (!class_exists('WooCommerce')) {
    return;
  }

  // Load the gateway class
  require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-thix-yuno-card.php';
  require_once plugin_dir_path(__FILE__) . 'includes/rest-api.php';


  // Payment Gateway Registration
  add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_Thix_Yuno_Card';
    return $gateways;
  });
});
