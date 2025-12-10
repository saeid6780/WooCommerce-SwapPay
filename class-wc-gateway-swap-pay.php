<?php

defined("ABSPATH") or die("SwapPay Wordpress Restricted Access");

if (class_exists('WC_Payment_Gateway') && !class_exists('SwapPay_WC_Gateway')) {

    class SwapPay_WC_Gateway extends WC_Payment_Gateway
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
        private $language;
        private $show_icon;
        private $temp_allowed_host;

        public function __construct()
        {
            $this->author = 'swapwallet.app';

            $this->id = 'WC_Swap_Pay';
            $this->icon = 'https://swapwallet.app/media/public/assets/wallets/swapwallet.png';
            $this->has_fields = false;

            $this->language = strtolower($this->detect_language());

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->network = strtoupper($this->get_option('network'));
            $this->username = $this->get_option('username');
            $ttl_minutes = (int) $this->get_option('ttl');
            // API expects ttl between 300s and 21600s
            $ttl_minutes = max(5, min(360, $ttl_minutes));
            $this->ttl = $ttl_minutes * 60;
            $this->underPaidCoveragePercent = $this->get_option('underPaidCoveragePercent');
            $this->api_key = $this->get_option('api_key');
            $this->failed_massage = $this->get_option('failed_massage');
            $this->success_massage = $this->get_option('success_massage');
            $this->debug = $this->get_option('debug', 'no') === 'yes';
            $this->logger = function_exists('wc_get_logger') ? wc_get_logger() : null;
            $this->language = strtolower($this->get_option('language', $this->language));
            $this->method_title = $this->get_option('title', $this->get_lang_text('method_title'));
            $this->method_description = $this->get_option('description', $this->get_lang_text('method_description'));
            $this->show_icon = $this->get_option('show_icon', 'yes') === 'yes';
            if (!$this->show_icon) {
                $this->icon = '';
            }

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

            add_action('woocommerce_api_' . strtolower($this->id) . '', [$this, 'Return_from_swap_pay_Gateway']);

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
                    'title' => $this->t('field_enable_title'),
                    'type' => 'checkbox',
                    'label' => $this->t('field_enable_label'),
                    'default' => 'yes'
                ],
                'title' => [
                    'title' => $this->t('field_title_title'),
                    'type' => 'text',
                    'default' => $this->get_lang_text('gateway_title')
                ],
                'description' => [
                    'title' => $this->t('field_description_title'),
                    'type' => 'text',
                    'default' => $this->get_lang_text('gateway_description')
                ],
                'network' => [
                    'title' => $this->t('field_network_title'),
                    'type' => 'text',
                    'default' => 'BSC'
                ],
                'username' => [
                    'title' => $this->t('field_username_title'),
                    'type' => 'text',
                ],
                'ttl' => [
                    'title' => $this->t('field_ttl_title'),
                    'type' => 'number',
                    'default' => 60
                ],
                'underPaidCoveragePercent' => [
                    'title' => $this->t('field_underpaid_title'),
                    'type' => 'number',
                    'default' => 0.0
                ],
                'api_key' => [
                    'title' => $this->t('field_api_key_title'),
                    'type' => 'text',
                    'default' => 'apikey-aaaabbbbccccdddd'
                ],
                'language' => [
                    'title' => $this->t('field_language_title'),
                    'type' => 'select',
                    'default' => $this->detect_language(),
                    'options' => [
                        'fa' => 'فارسی',
                        'en' => 'English',
                    ],
                    'description' => $this->t('field_language_description'),
                ],
                'debug' => [
                    'title' => $this->t('field_debug_title'),
                    'label' => $this->t('field_debug_label'),
                    'type' => 'checkbox',
                    'default' => 'no',
                    'description' => $this->t('field_debug_description'),
                ],
                'success_massage' => [
                    'title' => $this->t('field_success_title'),
                    'type' => 'textarea',
                    'description' => $this->t('field_success_description'),
                    'default' => $this->get_lang_text('success_message'),
                ],
                'failed_massage' => [
                    'title' => $this->t('field_failed_title'),
                    'type' => 'textarea',
                    'description' => $this->t('field_failed_description'),
                    'default' => $this->get_lang_text('failed_message'),
                ],
                'show_icon' => [
                    'title' => $this->t('field_show_icon_title'),
                    'label' => $this->t('field_show_icon_label'),
                    'type' => 'checkbox',
                    'default' => 'yes',
                    'description' => $this->t('field_show_icon_description'),
                ],
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
                    'userLanguage' => strtoupper($this->language),
                    'underPaidCoveragePercent' => $this->underPaidCoveragePercent,
                    'returnUrl' => $CallbackURL,
                    'orderId' => $order_id,
                    'webhookUrl' => site_url('/wp-json/swap-pay/v1/webhook'),
                    'customData' => null,
                ])
            ]);

            if (is_wp_error($response)) {
                $this->log('Invoice creation failed (http error)', ['order_id' => $order_id, 'error' => $response->get_error_message()], 'error');
                wc_add_notice($this->t('error_gateway_connect'), 'error');
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

                $errorMessage = $response['error']['localizedMessage'] ?? $this->t('generic_error');
                wc_add_notice($this->t('payment_error_prefix') . $errorMessage, 'error');
                return [
                    'result' => 'failure',
                    'error' => $errorMessage,
                ];
            }

            $paymentUrl = $response['result']['paymentUrl'] ?? null;
            if (!$paymentUrl) {
                $this->log('Missing paymentUrl in invoice response', ['order_id' => $order_id, 'response' => $response], 'warning');
                wc_add_notice($this->t('missing_payment_url'), 'error');
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
                    $order->add_order_note($this->t('order_note_paid'));
                    return [
                        'result' => 'success',
                        'redirect' => $order->get_checkout_order_received_url()
                    ];
                } else if ($invoice_status === 'EXPIRED') {
                    $order->update_status('expired', $this->t('duplicate_order_expired'));
                    return [
                        'result' => 'failure',
                        'error' => $this->t('duplicate_order_expired')
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
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!empty($nonce) && !wp_verify_nonce($nonce, 'swap_pay_return')) {
                $this->add_failure_notice($this->t('invalid_transaction'));
                $this->log('Return: invalid nonce', $_GET, 'warning');
                $this->safe_redirect(wc_get_checkout_url());
            }

            $order_id = isset($_GET['wc_order']) ? absint(wp_unslash($_GET['wc_order'])) : 0;
            if (!$order_id) {
                $this->add_failure_notice($this->t('missing_order'));
                $this->log('Return: missing order id', $_GET, 'warning');
                $this->safe_redirect(wc_get_checkout_url());
            }

            $response = $this->get_invoice($order_id);
            if (is_wp_error($response) || !is_array($response)) {
                $this->add_failure_notice($this->t('invoice_fetch_failed'));
                $this->log('Return: invoice fetch failed', ['order_id' => $order_id, 'error' => $response], 'error');
                $this->safe_redirect(wc_get_checkout_url());
            }

            $order = wc_get_order($order_id);
            $data = $response['result'] ?? null;
            $status = $data['status'] ?? null;
            $support_code = $data['supportCode'] ?? '';
            $payment_url = $data['paymentUrl'] ?? ($data['paymentLinks'][0]['url'] ?? null);

            if (!$order || ($response['status'] ?? null) !== 'OK' || !$status) {
                $this->add_failure_notice($this->t('invalid_transaction'));
                $this->log('Return: invalid response', ['order_id' => $order_id, 'response' => $response], 'warning');
                $this->safe_redirect(wc_get_checkout_url());
            }

            $this->log('Return: handling status', ['order_id' => $order_id, 'status' => $status]);
            switch ($status) {
                case 'PAID':
                    $order->payment_complete();
                    $order->add_order_note($this->t('order_note_paid'));
                    $Note = sprintf($this->t('order_note_paid_code'), $support_code);
                    $order->add_order_note($Note);

                    $Notice = wpautop(wptexturize($this->success_massage));
                    $Notice = str_replace("{support_code}", $support_code, $Notice);
                    wc_add_notice($Notice, 'success');

                    $this->safe_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));

                case 'EXPIRED':
                    $Message = $this->t('invoice_expired');
                    $order->update_status('expired', $Message);
                    break;

                case 'ACTIVE':
                    if ($payment_url) {
                        $this->safe_redirect($payment_url);
                    }
                    $Message = $this->t('payment_url_missing');
                    break;

                default:
                    $Message = $this->t('status_unknown');
                    break;
            }

            $sc_id = $support_code ? '<br/>' . $this->t('transaction_code_label') . $support_code : '';
            $Note = sprintf($this->t('return_error_note'), $Message, $sc_id);
            $order->add_order_note($Note, 1);

            $Notice = wpautop(wptexturize($this->failed_massage));
            $Notice = str_replace("{support_code}", $support_code, $Notice);
            $Notice = str_replace("{fault}", $Message, $Notice);
            wc_add_notice($Notice, 'error');
            $this->safe_redirect(wc_get_checkout_url());
        }

        private function add_failure_notice($message)
        {
            $Notice = wpautop(wptexturize($this->failed_massage));
            $Notice = str_replace("{fault}", $message, $Notice);
            wc_add_notice($Notice, 'error');
        }

        public function allow_temp_redirect_host($hosts)
        {
            if ($this->temp_allowed_host) {
                $hosts[] = $this->temp_allowed_host;
            }

            return array_unique($hosts);
        }

        private function safe_redirect($url)
        {
            $host = wp_parse_url($url, PHP_URL_HOST);
            if ($host) {
                $this->temp_allowed_host = $host;
                add_filter('allowed_redirect_hosts', [$this, 'allow_temp_redirect_host']);
            }

            wp_safe_redirect($url);
            exit;
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

        private function get_lang_text($key, $langOverride = null)
        {
            $texts = [
                'fa' => [
                    'method_title' => 'سواپ‌ولت',
                    'method_description' => 'پرداخت کریپتویی با درگاه سواپ‌ولت',
                    'gateway_title' => 'پرداخت با سواپ‌ولت',
                    'gateway_description' => 'درگاه پرداخت سواپ‌ولت روشی امن و سریع برای پرداخت‌های کریپتویی است.',
                    'success_message' => 'با تشکر از شما . سفارش شما با موفقیت پرداخت شد .',
                    'failed_message' => 'پرداخت شما ناموفق بوده است . لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید .',
                    'field_enable_title' => 'فعال/غیرفعال کردن',
                    'field_enable_label' => 'فعال‌سازی درگاه سواپ‌ولت',
                    'field_title_title' => 'عنوان',
                    'field_description_title' => 'توضیحات',
                    'field_network_title' => 'شبکه فعال جهت پرداخت دلاری (TON, BSC, TRON)',
                    'field_username_title' => 'نام کاربری درگاه سواپ‌ولت',
                    'field_ttl_title' => 'مدت زمان اعتبار فاکتور پرداخت (دقیقه)',
                    'field_underpaid_title' => 'درصد پوشش پرداخت کمتر از حد',
                    'field_api_key_title' => 'API Key',
                    'field_language_title' => 'زبان رابط کاربری',
                    'field_language_description' => 'متن‌های پیش‌فرض درگاه را به فارسی یا انگلیسی نمایش دهید.',
                    'field_debug_title' => 'عیب‌یابی (Debug)',
                    'field_debug_label' => 'فعال کردن لاگ‌برداری',
                    'field_debug_description' => 'در صورت فعال بودن، رویدادهای درگاه در لاگ ووکامرس ثبت می‌شوند.',
                    'field_success_title' => 'پیام پرداخت موفق',
                    'field_success_description' => 'متن پیامی که میخواهید بعد از پرداخت موفق نمایش داده شود. از {support_code} برای نمایش کد رهگیری استفاده کنید.',
                    'field_failed_title' => 'پیام پرداخت ناموفق',
                    'field_failed_description' => 'متن پیامی که میخواهید بعد از پرداخت ناموفق نمایش داده شود. از {fault} برای دلیل خطا و {support_code} برای کد رهگیری استفاده کنید.',
                    'field_show_icon_title' => 'نمایش آیکن در صفحه پرداخت',
                    'field_show_icon_label' => 'آیکن سواپ‌ولت نمایش داده شود',
                    'field_show_icon_description' => 'برای مخفی کردن آیکن در تسویه‌حساب کلاسیک و بلاک‌ها، تیک را بردارید.',
                    'error_gateway_connect' => 'خطای ارتباط با درگاه سواپ‌ولت. لطفاً بعداً تلاش کنید.',
                    'payment_error_prefix' => 'خطای پرداخت: ',
                    'generic_error' => 'خطایی رخ داد',
                    'missing_payment_url' => 'آدرس پرداخت از درگاه دریافت نشد.',
                    'duplicate_order_expired' => 'سفارش منقضی شد به دلیل اتمام زمان/عدم پرداخت.',
                    'missing_order' => 'شماره سفارش وجود ندارد .',
                    'invoice_fetch_failed' => 'عدم دریافت اطلاعات تراکنش از سواپ‌ولت.',
                    'invalid_transaction' => 'اطلاعات سفارش یا وضعیت تراکنش نامعتبر است.',
                    'order_note_paid' => 'پرداخت با موفقیت انجام شد از طریق سواپ‌ولت.',
                    'order_note_paid_code' => 'پرداخت موفقیت آمیز بود .<br/> کد رهگیری : %s',
                    'invoice_expired' => 'فاکتور پرداخت منقضی شده است.',
                    'payment_url_missing' => 'آدرس پرداخت در دسترس نیست.',
                    'status_unknown' => 'وضعیت تراکنش نامعلوم است.',
                    'transaction_code_label' => 'کد تراکنش : ',
                    'return_error_note' => 'خطا در هنگام بازگشت از درگاه : %s %s',
                ],
                'en' => [
                    'method_title' => 'Swap Wallet',
                    'method_description' => 'Pay with crypto via Swap Wallet gateway.',
                    'gateway_title' => 'Pay with Swap Wallet',
                    'gateway_description' => 'Swap Wallet provides a fast and secure crypto payment method.',
                    'success_message' => 'Thank you! Your order has been paid successfully.',
                    'failed_message' => 'Payment failed. Please try again or contact the site admin if the issue persists.',
                    'field_enable_title' => 'Enable/Disable',
                    'field_enable_label' => 'Enable SwapWallet gateway',
                    'field_title_title' => 'Title',
                    'field_description_title' => 'Description',
                    'field_network_title' => 'Active network for USD payments (TON, BSC, TRON)',
                    'field_username_title' => 'SwapWallet gateway username',
                    'field_ttl_title' => 'Invoice TTL (minutes)',
                    'field_underpaid_title' => 'Underpaid coverage percent',
                    'field_api_key_title' => 'API Key',
                    'field_language_title' => 'Interface language',
                    'field_language_description' => 'Show default gateway texts in Persian or English.',
                    'field_debug_title' => 'Debug logging',
                    'field_debug_label' => 'Enable logging',
                    'field_debug_description' => 'If enabled, gateway events are written to WooCommerce logs.',
                    'field_success_title' => 'Successful payment message',
                    'field_success_description' => 'Text shown after successful payment. Use {support_code} to show the tracking code.',
                    'field_failed_title' => 'Failed payment message',
                    'field_failed_description' => 'Text shown after failed payment. Use {fault} for the error reason and {support_code} for the tracking code.',
                    'field_show_icon_title' => 'Show icon on checkout',
                    'field_show_icon_label' => 'Display the SwapWallet icon on checkout',
                    'field_show_icon_description' => 'Uncheck to hide the icon in both classic and blocks checkout.',
                    'error_gateway_connect' => 'SwapWallet connection error. Please try again later.',
                    'payment_error_prefix' => 'Payment error: ',
                    'generic_error' => 'An error occurred',
                    'missing_payment_url' => 'Payment URL was not received from the gateway.',
                    'duplicate_order_expired' => 'Order expired due to timeout/non-payment.',
                    'missing_order' => 'Order id is missing.',
                    'invoice_fetch_failed' => 'Could not retrieve transaction info from SwapWallet.',
                    'invalid_transaction' => 'Order info or transaction status is invalid.',
                    'order_note_paid' => 'Payment completed via SwapWallet.',
                    'order_note_paid_code' => 'Payment was successful.<br/> Tracking code: %s',
                    'invoice_expired' => 'Payment invoice has expired.',
                    'payment_url_missing' => 'Payment URL is not available.',
                    'status_unknown' => 'Transaction status is unknown.',
                    'transaction_code_label' => 'Transaction code: ',
                    'return_error_note' => 'Error when returning from gateway: %s %s',
                ],
            ];

            $lang = $langOverride ?? $this->language;
            $lang = array_key_exists($lang, $texts) ? $lang : 'fa';
            return $texts[$lang][$key] ?? '';
        }

        private function detect_language()
        {
            $locale = function_exists('get_locale') ? get_locale() : 'fa_IR';
            return (stripos($locale, 'fa') === 0) ? 'fa' : 'en';
        }

        private function t($key)
        {
            $text = $this->get_lang_text($key);
            if ($text !== '') {
                return $text;
            }

            // fallback to English if missing
            $original_language = $this->language;
            $this->language = 'en';
            $fallback = $this->get_lang_text($key);
            $this->language = $original_language;

            return $fallback ?: $key;
        }

        public function get_text($key, $lang = null)
        {
            return $this->get_lang_text($key, $lang);
        }


        public function admin_notice_missing_api_key()
        {
            $api_key = $this->get_option('api_key');
            if (empty($api_key) && 'yes' === $this->get_option('enabled')) {
                $msg = ($this->language === 'en')
                    ? 'SwapWallet API key is empty. Go to gateway settings to complete it.'
                    : 'کد درگاه سواپ‌ولت خالی است. برای تکمیل مورد مربوطه به تنظیمات درگاه مراجعه کنید.';
                $settings_url = esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=WC_Swap_Pay'));
                $message = sprintf(
                    '%s <a href="%s">%s</a>',
                    esc_html($msg),
                    $settings_url,
                    esc_html(($this->language === 'en') ? 'Settings' : 'اینجا')
                );
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p>' . wp_kses_post($message) . '</p>';
                echo '</div>';
            }
        }

        public function admin_notice_missing_username()
        {
            $username = $this->get_option('username');
            if (empty($username) && 'yes' === $this->get_option('enabled')) {
                $msg = ($this->language === 'en')
                    ? 'SwapWallet username is empty. Go to gateway settings to complete it.'
                    : 'نام کاربری درگاه سواپ‌ولت خالی است. برای تکمیل مورد مربوطه به تنظیمات درگاه مراجعه کنید.';
                $settings_url = esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=WC_Swap_Pay'));
                $message = sprintf(
                    '%s <a href="%s">%s</a>',
                    esc_html($msg),
                    $settings_url,
                    esc_html(($this->language === 'en') ? 'Settings' : 'اینجا')
                );
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p>' . wp_kses_post($message) . '</p>';
                echo '</div>';
            }
        }

        public function get_language()
        {
            return $this->language;
        }

        public function get_title()
        {
            return $this->title;
        }

        public function get_description()
        {
            return $this->description;
        }
    }

    if (!class_exists('WC_Swap_Pay')) {
        class_alias(SwapPay_WC_Gateway::class, 'WC_Swap_Pay');
    }
}