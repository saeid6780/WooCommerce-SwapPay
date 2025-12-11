<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Swap_Pay_Gateway_Blocks extends AbstractPaymentMethodType
{

    private $gateway;
    protected $name = 'SWAPPAY_WC_GATEWAY';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_swap_pay_gateway_settings', []);
        $this->gateway = new SWAPPAY_WC_GATEWAY();
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
            defined('SWAPPAY_VERSION') ? SWAPPAY_VERSION : '1.0.2',
            true
        );
        return ['swap_pay_gateway-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        $title = method_exists($this->gateway, 'get_title') ? $this->gateway->get_title() : ($this->gateway->title ?? '');
        $description = method_exists($this->gateway, 'get_description') ? $this->gateway->get_description() : ($this->gateway->description ?? '');
        $language = method_exists($this->gateway, 'get_language') ? $this->gateway->get_language() : 'fa';
        $show_icon = $this->gateway->get_option('show_icon', 'yes') === 'yes';

        return [
            'title' => $title,
            'description' => $description,
            'icon' => $show_icon ? ($this->gateway->icon ?? '') : '',
            'language' => $language,
            'show_icon' => $show_icon,
            'fallbacks' => [
                'label_fa' => $this->gateway->get_text('gateway_title', 'fa'),
                'label_en' => $this->gateway->get_text('gateway_title', 'en'),
                'desc_fa' => $this->gateway->get_text('gateway_description', 'fa'),
                'desc_en' => $this->gateway->get_text('gateway_description', 'en'),
            ],
        ];
    }

}
?>