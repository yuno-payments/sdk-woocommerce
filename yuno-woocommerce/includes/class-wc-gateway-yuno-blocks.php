<?php
if (!defined('ABSPATH')) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_Yuno_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'yuno_card';

    private $gateway;

    public function initialize() {
        $this->settings = get_option('woocommerce_yuno_card_settings', []);
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = isset($gateways['yuno_card']) ? $gateways['yuno_card'] : null;
    }

    public function is_active() {
        return $this->gateway instanceof WC_Gateway_Yuno_Card
            && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        $asset_path = plugin_dir_path(__DIR__) . 'assets/js/blocks/yuno-blocks.asset.php';
        $asset      = file_exists($asset_path)
            ? require $asset_path
            : ['dependencies' => [], 'version' => YUNO_WC_VERSION];

        wp_register_script(
            'yuno-blocks-integration',
            plugin_dir_url(__DIR__) . 'assets/js/blocks/yuno-blocks.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        return ['yuno-blocks-integration'];
    }

    public function get_payment_method_data() {
        $default_description = 'Pay with Visa, Mastercard, and more. Secure payment powered by Yuno.';
        $plugin_url = plugin_dir_url(__DIR__);

        return [
            'title'       => $this->gateway ? $this->gateway->get_title() : 'Yuno',
            'description' => $this->gateway
                ? $this->gateway->get_option('description', $default_description)
                : $default_description,
            'supports'    => $this->get_supported_features(),
            'icon'        => $plugin_url . 'assets/images/credit-card.svg',
            'cardIcons'   => [
                ['name' => 'visa',       'src' => $plugin_url . 'assets/images/visa.svg'],
                ['name' => 'mastercard', 'src' => $plugin_url . 'assets/images/mastercard.svg'],
                ['name' => 'amex',       'src' => $plugin_url . 'assets/images/amex.svg'],
                ['name' => 'diners',     'src' => $plugin_url . 'assets/images/diners.svg'],
                ['name' => 'discover',   'src' => $plugin_url . 'assets/images/discover.svg'],
            ],
        ];
    }
}
