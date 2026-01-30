<?php
/**
 * Plugin Name: Yuno SDK for WooCommerce
 * Description: Accept card payments via Yuno with server-side verification and split marketplace support.
 * Version: 0.1.0
 * Author: Yuno
 * Author URI: https://y.uno
 * Text Domain: yuno-sdk-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 8.0
 * WC requires at least: 5.0
 * WC tested up to: 8.0
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
