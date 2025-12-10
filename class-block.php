<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Swap_Pay_Gateway_Blocks extends AbstractPaymentMethodType
{

    private $gateway;
    protected $name = 'WC_Swap_Pay';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_swap_pay_gateway_settings', []);
        $this->gateway = new WC_Swap_Pay();
    }

    public function is_active()
    {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {

        wp_register_script(
            'swap_pay_gateway-blocks-integration',
            plugin_dir_url(__FILE__) . 'assets/js/swap-pay-checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n'
            ],
            null,
            true
        );
        return ['swap_pay_gateway-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'icon' => 'https://swapwallet.app/media/public/assets/wallets/swapwallet.png',
        ];
    }

}
?>