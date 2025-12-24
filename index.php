<?php
/*
Plugin Name: درگاه پرداخت سواپ‌ولت | SwapWallet Payment Gateway
Description: درگاه کریپتویی سواپ‌ولت برای ووکامرس (پرداخت رمزارزی) | SwapWallet crypto payment gateway for WooCommerce.
Author: SwapWallet
Author URI: https://swapwallet.app
Version: 1.1.4
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: swapwallet-swappay
*/

if (!defined('SWAPPAY_VERSION')) {
    define('SWAPPAY_VERSION', '1.1.4');
}

defined('ABSPATH') or die("SwapPay Wordpress Restricted Access");

add_action('plugins_loaded', 'swap_pay_init');

function swap_pay_init()
{
    if (!class_exists('WC_Payment_Gateway'))
        return;

    require_once dirname(__FILE__) . '/class-wc-gateway-swap-pay.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        // Register the gateway class so WooCommerce renders it in the Payments tab
        $gateways[] = 'SwapPay_WC_Gateway';
        return $gateways;
    });
}

add_action('rest_api_init', function () {
    register_rest_route('swap-pay/v1', '/webhook', [
        'methods' => 'POST',
        'callback' => 'swap_pay_handle_webhook',
        'permission_callback' => '__return_true'
    ]);
});

function swap_pay_handle_webhook(WP_REST_Request $request)
{
    // Ensure payment gateways are initialized in REST context
    $payment_gateways = WC()->payment_gateways();
    if (!$payment_gateways) {
        return new WP_REST_Response(['error' => 'payment gateways not initialized'], 500);
    }

    $gateways = $payment_gateways->payment_gateways();
    $gateway = $gateways['SwapPay_WC_Gateway'] ?? null;
    if (!$gateway) {
        return new WP_REST_Response(['error' => 'gateway not found'], 500);
    }

    $logger = function_exists('wc_get_logger') ? wc_get_logger() : null;
    $debug_enabled = $gateway->get_option('debug', 'no') === 'yes';
    $raw_body = $request->get_body();

    $payload = $request->get_json_params();
    $hmac = $payload['hmac'] ?? $request->get_header('x-swapwallet-signature');

    if (!$gateway->verify_webhook_signature($raw_body, (string) $hmac)) {
        if ($logger && $debug_enabled) {
            $logger->warning('[SwapPay] Webhook: invalid HMAC', [
                'source' => 'SwapPay_WC_Gateway',
                'context' => ['raw_body' => $raw_body],
            ]);
        }
        return new WP_REST_Response(['error' => 'invalid signature'], 401);
    }

    // Support both legacy {result:{...}} and newer {event:{invoice:{...}}} payloads
    $event = $payload['event'] ?? [];
    $invoice = $event['invoice'] ?? [];
    $data = $payload['result'] ?? $invoice;

    $status = $invoice['status'] ?? $data['status'] ?? $payload['status'] ?? null;

    $order_id = $invoice['orderId'] ?? $data['orderId'] ?? ($payload['orderId'] ?? null);
    if (!$order_id) {
        return new WP_REST_Response(['error' => 'missing order_id'], 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        if ($logger && $debug_enabled) {
            $logger->warning('[SwapPay] Webhook: order not found', ['source' => 'SwapPay_WC_Gateway', 'context' => ['order_id' => $order_id]]);
        }
        return new WP_REST_Response(['error' => 'order not found'], 404);
    }

    if ($logger && $debug_enabled) {
        $logger->info('[SwapPay] Webhook received', [
            'source' => 'SwapPay_WC_Gateway',
            'context' => [
                'order_id' => $order_id,
                'status' => $status,
                'support_code' => $invoice['supportCode'] ?? null,
            ],
        ]);
    }

    if ($status === 'PAID') {
        $order->payment_complete();
        $order->add_order_note('پرداخت با موفقیت انجام شد از طریق سواپ‌ولت (وبهوک).');
        if ($logger && $debug_enabled) {
            $logger->info('[SwapPay] Webhook: order paid', ['source' => 'SwapPay_WC_Gateway', 'context' => ['order_id' => $order_id]]);
        }
        return new WP_REST_Response(['success' => true], 200);
    } elseif ($status === 'EXPIRED') {
        $order->update_status('expired', 'سفارش به دلیل انقضای فاکتور در سواپ‌ولت منقضی شد (وبهوک).');
        if ($logger && $debug_enabled) {
            $logger->warning('[SwapPay] Webhook: order expired', ['source' => 'SwapPay_WC_Gateway', 'context' => ['order_id' => $order_id]]);
        }
        return new WP_REST_Response(['expired' => true], 200);
    }

    if ($logger && $debug_enabled) {
        $logger->info('[SwapPay] Webhook: status ignored', ['source' => 'SwapPay_WC_Gateway', 'context' => ['order_id' => $order_id, 'status' => $status]]);
    }
    return new WP_REST_Response(['received' => true], 200);
}

function swap_pay_declare_cart_checkout_blocks_compatibility()
{

    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

add_action('woocommerce_blocks_loaded', 'swap_pay_register_order_approval_payment_method_type');
add_action('before_woocommerce_init', 'swap_pay_declare_cart_checkout_blocks_compatibility');

function swap_pay_register_order_approval_payment_method_type()
{

    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . '/class-block.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new Swap_Pay_Gateway_Blocks);
        }
    );
}

