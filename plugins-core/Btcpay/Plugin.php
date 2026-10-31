<?php

namespace Plugin\Btcpay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['BTCPay'] = [
                    'name' => $this->getConfig('display_name', 'BTCPay'),
                    'icon' => $this->getConfig('icon', '₿'),
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
            'btcpay_url' => [
                'label' => 'API接口所在网址',
                'type' => 'string',
                'required' => true,
                'description' => '包含最后的斜杠，例如：https://your-btcpay.com/'
            ],
            'btcpay_storeId' => [
                'label' => 'Store ID',
                'type' => 'string',
                'required' => true,
                'description' => 'BTCPay商店标识符'
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => '个人设置中的API KEY(非商店设置中的)'
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook通知密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);
        $ret_raw = $this->curlPost($this->getConfig('btcpay_url') . 'api/v1/stores/' . $this->getConfig('btcpay_storeId') . '/invoices', $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['checkoutLink'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params): array|bool
    {
        $payload = request()->getContent();
        $signatureHeader = request()->header('Btcpay-Sig', '');
        $computedSignature = 'sha256=' . \hash_hmac('sha256', $payload, $this->getConfig('btcpay_webhook_key'));
        if (!$this->hashEqual($signatureHeader, $computedSignature)) {
            throw new ApiException('HMAC signature does not match', 400);
        }

        $json_param = json_decode($payload, true);
        $invoiceId = $json_param['invoiceId'] ?? null;
        if (!is_string($invoiceId) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $invoiceId)) {
            throw new ApiException('Invalid invoice ID', 400);
        }
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            throw new ApiException('Invoice verification requires committed state', 409);
        }
        // Build the lookup from trusted configuration, never a webhook URL.
        $url = rtrim($this->getConfig('btcpay_url'), '/') . '/api/v1/stores/'
            . rawurlencode($this->getConfig('btcpay_storeId')) . '/invoices/' . rawurlencode($invoiceId);
        $invoiceDetail = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'token ' . $this->getConfig('btcpay_api_key'),
        ])->connectTimeout(5)->timeout(15)->withoutRedirecting()->get($url)->throw()->json();

        $out_trade_no = $invoiceDetail['metadata']['orderId'] ?? null;
        if (!is_string($out_trade_no) || $out_trade_no === '') {
            throw new ApiException('Missing invoice order ID', 400);
        }
        $result = ['trade_no' => $out_trade_no, 'callback_no' => $invoiceId];
        if (!str_starts_with($out_trade_no, 'AIT')) return $result;

        $result += ['paid' => false, 'custom_result' => 'success'];
        if (($invoiceDetail['id'] ?? null) !== $invoiceId) {
            throw new ApiException('Invoice ID does not match', 400);
        }
        if (($json_param['type'] ?? null) !== 'InvoiceSettled'
            || ($invoiceDetail['status'] ?? null) !== 'Settled') return $result;

        $cents = \App\Services\AiTopup\AiTopupPolicy::callbackCents($invoiceDetail['amount'] ?? null);
        if (($invoiceDetail['currency'] ?? null) !== 'CNY' || $cents === null || $cents <= 0) {
            throw new ApiException('Invalid CNY payment evidence', 400);
        }
        $result['paid'] = true;
        $result['payment_amount'] = $invoiceDetail['amount'];
        return $result;
    }

    private function curlPost($url, $params = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization:' . 'token ' . $this->getConfig('btcpay_api_key'), 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function hashEqual($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
} 