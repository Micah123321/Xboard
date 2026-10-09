<?php

namespace Plugin\Bepusdt;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            $methods['BEpusdt'] = [
                'name' => 'BEpusdt',
                'icon' => '',
                'plugin_code' => $this->getPluginCode(),
                'type' => 'plugin',
            ];
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'api_url' => ['label' => 'API 地址', 'type' => 'string', 'required' => true,
                'description' => 'BEpusdt 服务根地址，例如 https://pay.example.com'],
            'api_token' => ['label' => 'API Token', 'type' => 'string', 'required' => true],
            'fiat' => ['label' => '法币', 'type' => 'string', 'default' => 'CNY',
                'description' => 'CNY、USD、EUR、GBP、JPY；必须与本站订单计价币种一致，不执行换汇'],
            'currencies' => ['label' => '支付币种', 'type' => 'string', 'default' => 'USDT',
                'description' => '如 USDT,USDC；留空不限制；-ETH,-BNB 为排除列表'],
            'return_url' => ['label' => '付款返回地址', 'type' => 'string', 'required' => true,
                'description' => 'Micah 前端完整地址，例如 https://example.com/dashboard/finance/orders'],
        ];
    }

    public function pay($order): array
    {
        $apiUrl = rtrim((string) $this->getConfig('api_url'), '/');
        $returnUrl = (string) $this->getConfig('return_url');
        $fiat = (string) $this->getConfig('fiat', 'CNY');
        if (!$this->validUrl($apiUrl) || !$this->validUrl($returnUrl)
            || !in_array($fiat, ['CNY', 'USD', 'EUR', 'GBP', 'JPY'], true)
            || (string) $this->getConfig('api_token') === '') {
            throw new ApiException('BEpusdt configuration is invalid');
        }
        $cents = filter_var($order['total_amount'] ?? null, FILTER_VALIDATE_INT);
        if ($cents === false || $cents <= 0) {
            throw new ApiException('BEpusdt requires a positive order amount');
        }
        $params = [
            'order_id' => (string) $order['trade_no'],
            'amount' => $cents / 100,
            'fiat' => $fiat,
            'currencies' => (string) $this->getConfig('currencies', 'USDT'),
            'notify_url' => $order['notify_url'],
            'redirect_url' => $returnUrl,
        ];
        $params['signature'] = $this->sign($params);
        try {
            $response = Http::asJson()->acceptJson()->connectTimeout(10)->timeout(30)
                ->withOptions(['verify' => true, 'allow_redirects' => false])
                ->post($apiUrl . '/api/v1/order/create-order', $params);
        } catch (ConnectionException $e) {
            throw new ApiException('BEpusdt connection failed');
        }
        if (!$response->successful()) {
            throw new ApiException('BEpusdt HTTP error: ' . $response->status());
        }
        if ($response->json('status_code') !== 200) {
            throw new ApiException('BEpusdt rejected the order or returned an invalid response');
        }
        $paymentUrl = $response->json('data.payment_url');
        if (!is_string($paymentUrl) || !$this->validUrl($paymentUrl)) {
            throw new ApiException('BEpusdt payment URL is invalid');
        }
        return ['type' => 1, 'data' => $paymentUrl];
    }

    public function notify($params)
    {
        if (!is_array($params) || !is_string($params['signature'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $params['signature'])
            || (string) $this->getConfig('api_token') === '') {
            return false;
        }
        foreach ($params as $value) {
            if ($value !== null && !is_scalar($value)) {
                return false;
            }
        }
        if (!hash_equals($this->sign($params), $params['signature'])
            || !is_string($params['order_id'] ?? null) || $params['order_id'] === ''
            || !is_string($params['trade_id'] ?? null) || $params['trade_id'] === ''
            || !in_array($params['status'] ?? null, [1, 2, 3, '1', '2', '3'], true)) {
            return false;
        }
        $amount = self::amountInCents($params['amount'] ?? null);
        $order = Order::where('trade_no', $params['order_id'])->first();
        if (!$order || !$this->getConfig('id')
            || (int) $order->payment_id !== (int) $this->getConfig('id')
            || $order->payment?->payment !== 'BEpusdt'
            || $amount === null || $amount <= 0
            || $amount !== (int) $order->total_amount + (int) $order->handling_amount
            || (isset($params['fiat']) && $params['fiat'] !== $this->getConfig('fiat', 'CNY'))) {
            return false;
        }
        return [
            'trade_no' => $params['order_id'],
            'callback_no' => $params['trade_id'],
            'paid' => (int) $params['status'] === 2,
            'payment_id' => (int) $this->getConfig('id'),
            'payment_channel' => 'BEpusdt',
            'payment_method' => 'BEpusdt',
            'expected_amount' => $amount,
            // OrderService converts the fiat snapshot to cents.
            'payment_amount' => $amount / 100,
            'custom_result' => 'success',
        ];
    }

    public function sign(array $params): string
    {
        unset($params['signature']);
        ksort($params, SORT_STRING);
        $parts = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }
        return md5(implode('&', $parts) . (string) $this->getConfig('api_token'));
    }

    private static function amountInCents(mixed $amount): ?int
    {
        if ((!is_string($amount) && !is_int($amount) && !is_float($amount))
            || !preg_match('/^(\d{1,12})(?:\.(\d{1,2})0*)?$/D', (string) $amount, $matches)) {
            return null;
        }
        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function validUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
    }
}
