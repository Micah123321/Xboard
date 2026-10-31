<?php

namespace Plugin\CoinPayments;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['CoinPayments'] = [
                    'name' => $this->getConfig('display_name', 'CoinPayments'),
                    'icon' => $this->getConfig('icon', '💰'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'coinpayments_merchant_id' => [
                'label' => 'Merchant ID',
                'type' => 'string',
                'required' => true,
                'description' => '商户 ID，填写您在 Account Settings 中得到的 ID'
            ],
            'coinpayments_ipn_secret' => [
                'label' => 'IPN Secret',
                'type' => 'string',
                'required' => true,
                'description' => '通知密钥，填写您在 Merchant Settings 中自行设置的值'
            ],
            'coinpayments_currency' => [
                'label' => '货币代码',
                'type' => 'string',
                'required' => true,
                'description' => '填写您的货币代码（大写），建议与 Merchant Settings 中的值相同'
            ]
        ];
    }

    public function pay($order): array
    {
        $parseUrl = parse_url($order['return_url']);
        $port = isset($parseUrl['port']) ? ":{$parseUrl['port']}" : '';
        $successUrl = "{$parseUrl['scheme']}://{$parseUrl['host']}{$port}";

        $params = [
            'cmd' => '_pay_simple',
            'reset' => 1,
            'merchant' => $this->getConfig('coinpayments_merchant_id'),
            'item_name' => $order['trade_no'],
            'item_number' => $order['trade_no'],
            'want_shipping' => 0,
            'currency' => str_starts_with((string) $order['trade_no'], 'AIT') ? 'CNY' : $this->getConfig('coinpayments_currency'),
            'amountf' => sprintf('%.2f', $order['total_amount'] / 100),
            'success_url' => str_starts_with((string) $order['trade_no'], 'AIT') ? $order['return_url'] : $successUrl,
            'cancel_url' => $order['return_url'],
            'ipn_url' => $order['notify_url']
        ];

        $params_string = http_build_query($params);

        return [
            'type' => 1,
            'data' => 'https://www.coinpayments.net/index.php?' . $params_string
        ];
    }

    public function notify($params): array|string
    {
        $raw = request()->getContent();
        parse_str($raw, $signedParams);
        $isTopup = (is_string($params['item_number'] ?? null) && str_starts_with($params['item_number'], 'AIT'))
            || (is_string($signedParams['item_number'] ?? null) && str_starts_with($signedParams['item_number'], 'AIT'));
        if ($isTopup) {
            // Bind every top-up field to the exact bytes authenticated by CoinPayments.
            $params = $signedParams;
            $request = $raw;
        } else {
            ksort($params);
            reset($params);
            $request = stripslashes(http_build_query($params));
        }
        if (!isset($params['merchant']) || $params['merchant'] != trim($this->getConfig('coinpayments_merchant_id'))) {
            throw new ApiException('No or incorrect Merchant ID passed');
        }

        $signHeader = request()->header('Hmac', '');
        $hmac = hash_hmac('sha512', $request, trim($this->getConfig('coinpayments_ipn_secret')));
        if (!hash_equals($hmac, $signHeader)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        $status = $params['status'] ?? null;
        if ($isTopup) {
            if (!is_string($params['item_number'] ?? null) || !str_starts_with($params['item_number'], 'AIT')) {
                throw new ApiException('Invalid top-up reference', 400);
            }
            $result = ['trade_no' => $params['item_number'], 'callback_no' => $params['txn_id'] ?? '',
                'paid' => false, 'custom_result' => 'IPN OK'];
            if ((!is_string($status) && !is_int($status))
                || !preg_match('/\A-?[0-9]{1,10}\z/D', (string) $status)) {
                throw new ApiException('Invalid payment status', 400);
            }
            if (!($status >= 100 || $status == 2)) return $result;
            $cents = \App\Services\AiTopup\AiTopupPolicy::callbackCents($params['amount1'] ?? null);
            if (($params['currency1'] ?? null) !== 'CNY' || $cents === null || $cents <= 0) {
                throw new ApiException('Invalid CNY payment evidence', 400);
            }
            $result['paid'] = true;
            $result['payment_amount'] = $params['amount1'];
            return $result;
        }
        if ($status >= 100 || $status == 2) {
            return [
                'trade_no' => $params['item_number'],
                'callback_no' => $params['txn_id'],
                'custom_result' => 'IPN OK'
            ];
        } else if ($status < 0) {
            throw new ApiException('Payment Timed Out or Error');
        } else {
            return 'IPN OK: pending';
        }
    }
} 