<?php
/**
 * Plugin Name:       Yuno Payment Gateway
 * Plugin URI:        https://www.y.uno/
 * Description:       Accept payments through Yuno's payment orchestration platform.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Yuno
 * Author URI:        https://www.y.uno/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yuno-payment-gateway
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      9.5
 */

if (!defined('ABSPATH')) exit;

define('YUNO_WC_VERSION', '1.0.0');
define('YUNO_GATEWAY_ID', 'yuno');
define('YUNO_STATUS_SUCCESS', ['SUCCEEDED', 'VERIFIED', 'APPROVED', 'PAYED']);
define('YUNO_STATUS_FAILURE', ['REJECTED', 'DECLINED', 'CANCELLED', 'ERROR', 'EXPIRED', 'FAILED']);
define('YUNO_STATUS_PENDING', ['PENDING', 'PROCESSING', 'REQUIRES_ACTION']);
define('YUNO_DEFAULT_COUNTRY', 'CO');
define('YUNO_PLUGIN_FILE', __FILE__);
define('YUNO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YUNO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Declare WooCommerce feature compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

// Register block checkout payment method
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once YUNO_PLUGIN_DIR . 'includes/class-wc-gateway-yuno-blocks.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function ($registry) {
            $registry->register(new WC_Gateway_Yuno_Blocks_Support());
        }
    );
});

// Ensure WooCommerce is active
add_action('plugins_loaded', function () {
  if (!class_exists('WooCommerce')) {
    return;
  }

  // Load the gateway class
  require_once YUNO_PLUGIN_DIR . 'includes/class-wc-gateway-yuno.php';
  require_once YUNO_PLUGIN_DIR . 'includes/rest-api.php';

  // Payment Gateway Registration
  add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_Yuno';
    return $gateways;
  });

  // Determine post-payment order status: physical → "processing", non-physical → "completed"
  // A product is considered physical only if it needs shipping AND is not downloadable.
  // Downloadable-only products (even if not marked virtual) go straight to "completed".
  // Priority 999 runs AFTER theme/plugin filters that may force incorrect status
  add_filter('woocommerce_payment_complete_order_status', function ($status, $order_id, $order) {
    if ($order && $order->get_payment_method() === YUNO_GATEWAY_ID) {
      $has_physical = false;
      foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->needs_shipping() && !$product->is_downloadable()) {
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
