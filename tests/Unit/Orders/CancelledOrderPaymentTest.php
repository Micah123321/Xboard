<?php

namespace Tests\Unit\Orders;

use App\Models\Order;
use App\Services\OrderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/../../../plugins-core/Epay/Plugin.php';
require_once __DIR__ . '/../../../plugins/TokenPay/Plugin.php';

class CancelledOrderPaymentTest extends TestCase
{
    public function test_epay_exports_signed_fiat_quote_and_rejects_non_success_or_tampered_amount(): void
    {
        $plugin = new \Plugin\Epay\Plugin('epay');
        $plugin->setConfig(['key' => 'fixture-signing-key']);
        $params = ['out_trade_no' => 'original-order', 'trade_no' => 'gateway-receipt',
            'money' => '10.25', 'trade_status' => 'TRADE_SUCCESS'];
        $signed = $this->signEpay($params);
        $receipt = $plugin->notify($signed);
        self::assertSame('original-order', $receipt['trade_no']);
        self::assertSame('10.25', $receipt['payment_amount']);
        $signed['money'] = '10.24';
        self::assertFalse($plugin->notify($signed));
        self::assertFalse($plugin->notify($this->signEpay(array_replace($params, ['trade_status' => 'WAIT_BUYER_PAY']))));
    }

    public function test_integer_gateway_receipt_must_match_original_fee_inclusive_price_and_order(): void
    {
        $order = (new Order())->forceFill(['trade_no' => 'original-order', 'total_amount' => 1000, 'handling_amount' => 25]);
        $service = new OrderService($order);
        $method = new ReflectionMethod(OrderService::class, 'canRestoreCancelledPayment');
        self::assertTrue($method->invoke($service, $order, 'receipt', ['trade_no' => 'original-order', 'expected_amount' => 1025]));
        self::assertFalse($method->invoke($service, $order, 'receipt', ['trade_no' => 'other-order', 'expected_amount' => 1025]));
        self::assertFalse($method->invoke($service, $order, '', ['trade_no' => 'original-order', 'expected_amount' => 1025]));
        self::assertFalse($method->invoke($service, $order, 'receipt', ['trade_no' => 'original-order', 'expected_amount' => 1024]));
        self::assertFalse($method->invoke($service, $order, 'receipt', ['trade_no' => 'original-order', 'expected_amount' => '1025']));
        self::assertFalse($method->invoke($service, $order, 'receipt', ['trade_no' => 'original-order']));
        $order->total_amount = 0;
        $order->handling_amount = 0;
        self::assertFalse($method->invoke($service, $order, 'receipt', ['trade_no' => 'original-order', 'expected_amount' => 0]));
    }

    private function signEpay(array $params): array
    {
        ksort($params);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'fixture-signing-key');
        $params['sign_type'] = 'MD5';
        return $params;
    }
}
