<?php

namespace Plugin\TokenPay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Curl\Curl;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const FIXED_RETURN_URL = 'https://www.spkun.com/dashboard/finance/orders';
    private const PAYMENT_AMOUNT_KEYS = ['ActualAmount', 'PayAmount', 'Amount', 'Money', 'OrderMoney'];
    private const PAYMENT_METHOD_KEYS = ['PayTypeName', 'PayType', 'Method', 'Channel', 'Currency'];
    private const PAYMENT_IP_KEYS = ['PayIp', 'PayIP', 'IP', 'Ip', 'ClientIp', 'ClientIP'];

    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['TokenPay'] = [
                    'name' => $this->getConfig('display_name', 'TokenPay'),
                    'icon' => $this->getConfig('icon', '🪙'),
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
            'token_pay_url' => [
                'label' => 'API 地址',
                'type' => 'string',
                'required' => true,
                'description' => '您的 TokenPay API 接口地址，例如：https://token-pay.xxx.com'
            ],
            'token_pay_apitoken' => [
                'label' => 'API Token',
                'type' => 'string',
                'required' => true,
                'description' => '您的 TokenPay API Token'
            ],
            'token_pay_currency' => [
                'label' => '币种',
                'type' => 'string',
                'required' => true,
                'description' => '您的 TokenPay 币种，如 USDT_TRC20、TRX'
            ]
        ];
    }

    public function pay($order): array
    {
        $params = [
            'ActualAmount' => $order['total_amount'] / 100,
            'OutOrderId' => $order['trade_no'],
            'OrderUserKey' => (string) $order['user_id'],
            'Currency' => $this->getConfig('token_pay_currency'),
            'RedirectUrl' => self::FIXED_RETURN_URL,
            'NotifyUrl' => $order['notify_url'],
        ];

        ksort($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->getConfig('token_pay_apitoken');
        $params['Signature'] = md5($str);

        $curl = new Curl();
        $curl->setUserAgent('TokenPay');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->setOpt(CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $curl->post(rtrim((string) $this->getConfig('token_pay_url'), '/') . '/CreateOrder', json_encode($params));

        $result = $curl->response;
        $error = $curl->error ? ($curl->errorMessage ?: 'TokenPay request failed') : null;
        $curl->close();

        if ($error) {
            throw new ApiException($error);
        }

        if (!isset($result->success) || !$result->success) {
            $message = isset($result->message) ? (string) $result->message : 'Failed to create TokenPay order';
            throw new ApiException($message);
        }

        if (!isset($result->data) || !is_string($result->data) || $result->data === '') {
            throw new ApiException('TokenPay payment url is invalid');
        }

        return [
            'type' => 1,
            'data' => $result->data
        ];
    }

    public function notify($params): array|bool
    {
        if (!isset($params['Signature'])) {
            return false;
        }

        $sign = $params['Signature'];
        unset($params['Signature']);
        ksort($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->getConfig('token_pay_apitoken');

        if ($sign !== md5($str)) {
            return false;
        }

        if (!isset($params['Status']) || (int) $params['Status'] !== 1) {
            return false;
        }

        return [
            'trade_no' => $params['OutOrderId'] ?? '',
            'callback_no' => $params['Id'] ?? '',
            'payment_channel' => $this->getConfig('display_name', 'TokenPay'),
            'payment_method' => $this->firstFilledValue($params, self::PAYMENT_METHOD_KEYS) ?? $this->getConfig('token_pay_currency'),
            'payment_amount' => $this->firstFilledValue($params, self::PAYMENT_AMOUNT_KEYS),
            'payment_ip' => $this->firstFilledValue($params, self::PAYMENT_IP_KEYS),
            'custom_result' => 'ok'
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, string> $keys
     */
    private function firstFilledValue(array $params, array $keys): string|null
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $params) || !is_scalar($params[$key])) {
                continue;
            }

            $value = trim((string) $params[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
