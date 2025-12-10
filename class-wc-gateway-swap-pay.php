<?php

defined("ABSPATH") or die("SwapPay Wordpress Restricted Access");

if (class_exists('WC_Payment_Gateway') && !class_exists('WC_Swap_Pay')) {

    class WC_Swap_Pay extends WC_Payment_Gateway
    {
        private $api_key;
        private $username;
        private $network;
        private $ttl;
        private $underPaidCoveragePercent;
        private $author;
        private $success_massage;
        private $failed_massage;
        private $debug;
        private $logger;

        public function __construct()
        {
            $this->author = 'swapwallet.app';

            $this->id = 'WC_Swap_Pay';
            $this->icon = 'https://swapwallet.app/media/public/assets/wallets/swapwallet.png';
            $this->has_fields = false;
            $this->method_title = 'سواپ‌ولت';
            $this->method_description = 'پرداخت کریپتویی با درگاه سواپ‌ولت';

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->network = strtoupper($this->get_option('network'));
            $this->username = $this->get_option('username');
            $ttl_minutes = (int)$this->get_option('ttl');
            // API expects ttl between 300s and 21600s
            $ttl_minutes = max(5, min(360, $ttl_minutes));
            $this->ttl = $ttl_minutes * 60;
            $this->underPaidCoveragePercent = $this->get_option('underPaidCoveragePercent');
            $this->api_key = $this->get_option('api_key');
            $this->failed_massage = $this->get_option('failed_massage');
            $this->success_massage = $this->get_option('success_massage');
            $this->debug = $this->get_option('debug', 'no') === 'yes';
            $this->logger = function_exists('wc_get_logger') ? wc_get_logger() : null;

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action(
                    'woocommerce_update_options_payment_gateways_' . $this->id,
                    [$this, 'process_admin_options']
                );
            } else {
                add_action(
                    'woocommerce_update_options_payment_gateways',
                    [$this, 'process_admin_options']
                );
            }

            add_action('woocommerce_api_' . strtolower(get_class($this)) . '', [$this, 'Return_from_swap_pay_Gateway']);

            add_action('admin_notices', [$this, 'admin_notice_missing_api_key']);
            add_action('admin_notices', [$this, 'admin_notice_missing_username']);
        }

        public function admin_options()
        {
            parent::admin_options();
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'فعال/غیرفعال کردن',
                    'type' => 'checkbox',
                    'label' => 'فعال‌سازی درگاه سواپ‌ولت',
                    'default' => 'yes'
                ],
                'title' => [
                    'title' => 'عنوان',
                    'type' => 'text',
                    'default' => 'پرداخت با سواپ‌ولت'
                ],
                'description' => [
                    'title' => 'توضیحات',
                    'type' => 'text',
                    'default' => 'درگاه پرداخت سواپ‌ولت روشی امن و سریع برای پرداخت‌های کریپتویی است.'
                ],
                'network' => [
                    'title' => 'شبکه فعال جهت پرداخت دلاری (TON, BSC, TRON)',
                    'type' => 'text',
                    'default' => 'BSC'
                ],
                'username' => [
                    'title' => 'نام کاربری درگاه سواپ‌ولت',
                    'type' => 'text',
                ],
                'ttl' => [
                    'title' => 'مدت زمان اعتبار فاکتور پرداخت (دقیقه)',
                    'type' => 'number',
                    'default' => 60
                ],
                'underPaidCoveragePercent' => [
                    'title' => 'درصد پوشش پرداخت کمتر از حد',
                    'type' => 'number',
                    'default' => 0.0
                ],
                'api_key' => [
                    'title' => 'API Key',
                    'type' => 'text',
                    'default' => 'apikey-aaaabbbbccccdddd'
                ],
                'debug' => [
                    'title' => 'عیب‌یابی (Debug)',
                    'label' => 'فعال کردن لاگ‌برداری',
                    'type' => 'checkbox',
                    'default' => 'no',
                    'description' => 'در صورت فعال بودن، رویدادهای درگاه در لاگ ووکامرس ثبت می‌شوند.',
                ],
                'success_massage' => [
                    'title' => 'پیام پرداخت موفق',
                    'type' => 'textarea',
                    'description' => 'متن پیامی که میخواهید بعد از پرداخت موفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {support_code} برای نمایش کد رهگیری (کد تراکنش سواپ‌ولت) استفاده نمایید .',
                    'default' => 'با تشکر از شما . سفارش شما با موفقیت پرداخت شد .',
                ],
                'failed_massage' => [
                    'title' => 'پیام پرداخت ناموفق',
                    'type' => 'textarea',
                    'description' => 'متن پیامی که میخواهید بعد از پرداخت ناموفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {fault} برای نمایش دلیل خطای رخ داده استفاده نمایید . این دلیل خطا از سایت سواپ‌ولت ارسال میگردد .',
                    'default' => 'پرداخت شما ناموفق بوده است . لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید .',
                ]
            ];
        }

        private function get_correct_price($order)
        {
            $price = $order->get_total();
            $currency = $order->get_currency();

            if (strtolower($currency) === strtolower('IRR')) {
                $price /= 10;
                $currency = 'IRT';
            } else if (strtolower($currency) === strtolower('IRHT')) {
                $price *= 1000;
                $currency = 'IRT';
            } else if (strtolower($currency) === strtolower('IRHR')) {
                $price *= 100;
                $currency = 'IRT';
            }

            return [$price, $currency];
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            [$price, $currency] = $this->get_correct_price($order);

            $CallbackURL = add_query_arg('wc_order', $order_id, WC()->api_request_url('WC_Swap_Pay'));
            $this->log('Creating invoice', [
                'order_id' => $order_id,
                'amount' => $price,
                'currency' => $currency,
                'callback' => $CallbackURL,
            ]);

            $response = wp_remote_post('https://swapwallet.app/api/v2/payment/' . $this->username . '/invoices', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json'
                ],
                'body' => wp_json_encode([
                    'amount' => [
                        'number' => $price,
                        'unit' => $currency,
                    ],
                    'allowedTokens' => [
                        [
                            'token' => 'USDT',
                            'network' => $this->network,
                        ],
                    ],
                    'feePayer' => 'USER',
                    'ttl' => $this->ttl,
                    'underPaidCoveragePercent' => $this->underPaidCoveragePercent,
                    'returnUrl' => $CallbackURL,
                    'orderId' => $order_id,
                    'webhookUrl' => site_url('/wp-json/swap-pay/v1/webhook'),
                    'customData' => null,
                ])
            ]);

            if (is_wp_error($response)) {
                $this->log('Invoice creation failed (http error)', ['order_id' => $order_id, 'error' => $response->get_error_message()], 'error');
                wc_add_notice('خطای ارتباط با درگاه سواپ‌ولت. لطفاً بعداً تلاش کنید.', 'error');
                return [
                    'result' => 'failure',
                    'error' => $response->get_error_message(),
                ];
            }

            $response = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($response) || ($response['status'] ?? null) !== 'OK') {
                $errorCodeString = $response['error']['codeString'] ?? null;
                $this->log('Invoice creation failed (api error)', [
                    'order_id' => $order_id,
                    'error_code' => $errorCodeString,
                    'response' => $response,
                ], 'warning');

                $result = $this->handle_duplicated_order_id($order, $order_id, $errorCodeString);
                if ($result !== null) {
                    return $result;
                }

                $errorMessage = $response['error']['localizedMessage'] ?? 'خطایی رخ داد';
                wc_add_notice('خطای پرداخت: ' . $errorMessage, 'error');
                return [
                    'result' => 'failure',
                    'error' => $errorMessage,
                ];
            }

            $paymentUrl = $response['result']['paymentUrl'] ?? null;
            if (!$paymentUrl) {
                $this->log('Missing paymentUrl in invoice response', ['order_id' => $order_id, 'response' => $response], 'warning');
                wc_add_notice('آدرس پرداخت از درگاه دریافت نشد.', 'error');
                return [
                    'result' => 'failure',
                    'error' => 'missing payment url',
                ];
            }

            $this->log('Invoice created', ['order_id' => $order_id, 'payment_url' => $paymentUrl]);
            return [
                'result' => 'success',
                'redirect' => $paymentUrl,
            ];
        }

        private function handle_duplicated_order_id($order, $order_id, $errorCodeString)
        {
            if ($errorCodeString === "DUPLICATE_EXTERNAL_ID") {
                $invoice_response = $this->get_invoice($order_id);
                if (is_wp_error($invoice_response) || ($invoice_response['status'] ?? null) !== 'OK') {
                    $this->log('Duplicate order id but invoice fetch failed', ['order_id' => $order_id, 'response' => $invoice_response], 'warning');
                    return null;
                }

                $invoice = $invoice_response['result'] ?? [];
                $invoice_status = $invoice['status'] ?? null;
                $this->log('Duplicate order id invoice status', ['order_id' => $order_id, 'status' => $invoice_status]);

                if ($invoice_status === 'PAID') {
                    $order->payment_complete();
                    $order->add_order_note('پرداخت با موفقیت انجام شد از طریق سواپ‌ولت.');
                    return [
                        'result' => 'success',
                        'redirect' => $order->get_checkout_order_received_url()
                    ];
                } else if ($invoice_status === 'EXPIRED') {
                    $order->update_status('expired', 'سفارش منقضی شد به دلیل اتمام زمان/عدم پرداخت.');
                    return [
                        'result' => 'failure',
                        'error' => 'سفارش منقضی شد به دلیل اتمام زمان/عدم پرداخت.'
                    ];
                } else if ($invoice_status === 'ACTIVE') {
                    $invoice_url = $invoice['paymentUrl'] ?? ($invoice['paymentLinks'][0]['url'] ?? null);
                    if ($invoice_url) {
                        return [
                            'result' => 'success',
                            'redirect' => $invoice_url
                        ];
                    }
                }
            }

            return null;
        }

        private function get_invoice($order_id)
        {
            $url = "https://swapwallet.app/api/v2/payment/$this->username/invoices/with-order-id/" . $order_id;
            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => "application/json"
                ],
                'timeout' => 10,
            ]);

            if (is_wp_error($response)) {
                $this->log('Invoice fetch failed (http error)', ['order_id' => $order_id, 'error' => $response->get_error_message()], 'error');
                return $response;
            }

            $body = wp_remote_retrieve_body($response);
            $response = json_decode($body, true);
            $this->log('Invoice fetch response', ['order_id' => $order_id, 'response' => $response]);

            return $response;
        }

        public function Return_from_swap_pay_Gateway()
        {
            $order_id = isset($_GET['wc_order']) ? absint($_GET['wc_order']) : 0;
            if (!$order_id) {
                $this->add_failure_notice('شماره سفارش وجود ندارد .');
                $this->log('Return: missing order id', $_GET, 'warning');
                wp_redirect(wc_get_checkout_url());
                exit;
            }

            $response = $this->get_invoice($order_id);
            if (is_wp_error($response) || !is_array($response)) {
                $this->add_failure_notice('عدم دریافت اطلاعات تراکنش از سواپ‌ولت.');
                $this->log('Return: invoice fetch failed', ['order_id' => $order_id, 'error' => $response], 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }

            $order = wc_get_order($order_id);
            $data = $response['result'] ?? null;
            $status = $data['status'] ?? null;
            $support_code = $data['supportCode'] ?? '';
            $payment_url = $data['paymentUrl'] ?? ($data['paymentLinks'][0]['url'] ?? null);

            if (!$order || ($response['status'] ?? null) !== 'OK' || !$status) {
                $this->add_failure_notice('اطلاعات سفارش یا وضعیت تراکنش نامعتبر است.');
                $this->log('Return: invalid response', ['order_id' => $order_id, 'response' => $response], 'warning');
                wp_redirect(wc_get_checkout_url());
                exit;
            }

            $this->log('Return: handling status', ['order_id' => $order_id, 'status' => $status]);
            switch ($status) {
                case 'PAID':
                    $order->payment_complete();
                    $order->add_order_note('پرداخت با موفقیت انجام شد از طریق سواپ‌ولت.');
                    $Note = sprintf('پرداخت موفقیت آمیز بود .<br/> کد رهگیری : %s', $support_code);
                    $order->add_order_note($Note);

                    $Notice = wpautop(wptexturize($this->success_massage));
                    $Notice = str_replace("{support_code}", $support_code, $Notice);
                    wc_add_notice($Notice, 'success');

                    wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                    exit;

                case 'EXPIRED':
                    $Message = 'فاکتور پرداخت منقضی شده است.';
                    $order->update_status('expired', $Message);
                    break;

                case 'ACTIVE':
                    if ($payment_url) {
                        wp_redirect($payment_url);
                        exit;
                    }
                    $Message = 'آدرس پرداخت در دسترس نیست.';
                    break;

                default:
                    $Message = 'وضعیت تراکنش نامعلوم است.';
                    break;
            }

            $sc_id = $support_code ? '<br/>کد تراکنش : ' . $support_code : '';
            $Note = sprintf('خطا در هنگام بازگشت از درگاه : %s %s', $Message, $sc_id);
            $order->add_order_note($Note, 1);

            $Notice = wpautop(wptexturize($this->failed_massage));
            $Notice = str_replace("{support_code}", $support_code, $Notice);
            $Notice = str_replace("{fault}", $Message, $Notice);
            wc_add_notice($Notice, 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        private function add_failure_notice($message)
        {
            $Notice = wpautop(wptexturize($this->failed_massage));
            $Notice = str_replace("{fault}", $message, $Notice);
            wc_add_notice($Notice, 'error');
        }

        private function log($message, $context = [], $level = 'info')
        {
            if (!$this->debug || !$this->logger) {
                return;
            }

            if (is_array($context)) {
                unset($context['api_key'], $context['Authorization'], $context['authorization']);
            }

            $this->logger->log($level, '[SwapPay] ' . $message, [
                'source' => $this->id,
                'context' => $context,
            ]);
        }


        public function admin_notice_missing_api_key()
        {
            $api_key = $this->get_option('api_key');
            if (empty($api_key) && 'yes' === $this->get_option('enabled')) {
                $message = sprintf(
                    'کد درگاه سواپ‌ولت خالی است. برای تکمیل مورد مربوطه به تنظیمات درگاه <a href="%s">اینجا</a> مراجعه کنید.',
                    admin_url('admin.php?page=wc-settings&tab=checkout&section=WC_Swap_Pay')
                );
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p>' . $message . '</p>';
                echo '</div>';
            }
        }

        public function admin_notice_missing_username()
        {
            $username = $this->get_option('username');
            if (empty($username) && 'yes' === $this->get_option('enabled')) {
                $message = sprintf(
                    'نام کاربری درگاه سواپ‌ولت خالی است. برای تکمیل مورد مربوطه به تنظیمات درگاه <a href="%s">اینجا</a> مراجعه کنید.',
                    admin_url('admin.php?page=wc-settings&tab=checkout&section=WC_Swap_Pay')
                );
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p>' . $message . '</p>';
                echo '</div>';
            }
        }
    }
}