<?php

namespace Tests\Unit\Orders;

use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OrderServicePaymentSnapshotTest extends TestCase
{
    public function test_build_payment_snapshot_prefers_callback_metadata_over_payment_defaults(): void
    {
        $payment = new Payment([
            'name' => 'TokenPay 收银台',
            'payment' => 'TokenPay',
            'config' => ['token_pay_currency' => 'USDT_TRC20'],
        ]);

        $order = new Order();
        $order->setRelation('payment', $payment);

        $payload = $this->invokeBuildPaymentSnapshot($order, [
            'payment_channel' => '链上收银台',
            'payment_method' => 'TRC20',
            'payment_amount' => '19.29',
            'payment_ip' => '117.132.7.238',
        ]);

        $this->assertSame('链上收银台', $payload['payment_channel']);
        $this->assertSame('TRC20', $payload['payment_method']);
        $this->assertSame(1929, $payload['payment_amount']);
        $this->assertSame('117.132.7.238', $payload['payment_ip']);
    }

    public function test_build_payment_snapshot_falls_back_to_payment_config_when_callback_method_missing(): void
    {
        $payment = new Payment([
            'name' => 'TokenPay 收银台',
            'payment' => 'TokenPay',
            'config' => ['token_pay_currency' => 'USDT_TRC20'],
        ]);

        $order = new Order();
        $order->setRelation('payment', $payment);

        $payload = $this->invokeBuildPaymentSnapshot($order, []);

        $this->assertSame('TokenPay 收银台', $payload['payment_channel']);
        $this->assertSame('USDT_TRC20', $payload['payment_method']);
        $this->assertArrayNotHasKey('payment_amount', $payload);
        $this->assertArrayNotHasKey('payment_ip', $payload);
    }

    public function test_build_payment_snapshot_ignores_invalid_amount_and_blank_ip(): void
    {
        $payment = new Payment([
            'payment' => 'TokenPay',
            'config' => [],
        ]);

        $order = new Order();
        $order->setRelation('payment', $payment);

        $payload = $this->invokeBuildPaymentSnapshot($order, [
            'payment_amount' => 'invalid',
            'payment_ip' => '   ',
        ]);

        $this->assertSame('TokenPay', $payload['payment_channel']);
        $this->assertSame('TokenPay', $payload['payment_method']);
        $this->assertArrayNotHasKey('payment_amount', $payload);
        $this->assertArrayNotHasKey('payment_ip', $payload);
    }

    /**
     * @param array<string, mixed> $paymentSnapshot
     * @return array<string, mixed>
     */
    private function invokeBuildPaymentSnapshot(Order $order, array $paymentSnapshot): array
    {
        $service = new OrderService($order);
        $method = new ReflectionMethod(OrderService::class, 'buildPaymentSnapshot');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($service, $paymentSnapshot);

        return $result;
    }
}
