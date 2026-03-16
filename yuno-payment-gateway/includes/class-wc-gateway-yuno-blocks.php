<?php
if (!defined('ABSPATH')) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_Yuno_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'yuno';

    private $gateway;

    public function initialize() {
        $this->settings = get_option('woocommerce_' . YUNO_GATEWAY_ID . '_settings', []);
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = isset($gateways[YUNO_GATEWAY_ID]) ? $gateways[YUNO_GATEWAY_ID] : null;
    }

    public function is_active() {
        return $this->gateway instanceof WC_Gateway_Yuno
            && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        $asset_path = YUNO_PLUGIN_DIR . 'assets/js/blocks/yuno-blocks.asset.php';
        $asset      = file_exists($asset_path)
            ? require $asset_path
            : ['dependencies' => [], 'version' => YUNO_WC_VERSION];

        wp_register_script(
            'yuno-blocks-integration',
            YUNO_PLUGIN_URL . 'assets/js/blocks/yuno-blocks.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        return ['yuno-blocks-integration'];
    }

    public function get_payment_method_data() {
        $default_description = 'Select your preferred payment method on the next step';
        $plugin_url = YUNO_PLUGIN_URL;

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
