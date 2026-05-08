<?php

namespace Tests\Unit\Orders;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OrderServiceTemporaryTrafficTest extends TestCase
{
    public function test_buy_by_period_writes_plan_transfer_and_clears_temporary_transfer(): void
    {
        $order = $this->makeOrder([
            'type' => Order::TYPE_RENEWAL,
            'period' => Plan::PERIOD_MONTHLY,
        ]);

        $plan = $this->makePlan([
            'id' => 9,
            'group_id' => 3,
            'transfer_enable' => 800,
            'speed_limit' => 100,
            'device_limit' => 5,
        ]);

        $user = $this->makeUser([
            'plan_id' => 1,
            'group_id' => 1,
            'transfer_enable' => 550 * 1024 ** 3,
            'temporary_transfer_enable' => 50 * 1024 ** 3,
            'expired_at' => time() + 86400,
        ]);

        $service = new OrderService($order);
        $service->user = $user;

        $method = new ReflectionMethod(OrderService::class, 'buyByPeriod');
        $method->setAccessible(true);
        $method->invoke($service, $order, $plan);

        $this->assertSame(800 * 1024 ** 3, $user->transfer_enable);
        $this->assertSame(0, $user->temporary_transfer_enable);
        $this->assertSame(9, $user->plan_id);
        $this->assertSame(3, $user->group_id);
    }

    private function makeOrder(array $attributes = []): Order
    {
        $order = new Order();
        $order->setRawAttributes($attributes, true);

        return $order;
    }

    private function makePlan(array $attributes = []): Plan
    {
        $plan = new Plan();
        $plan->setRawAttributes($attributes, true);

        return $plan;
    }

    private function makeUser(array $attributes = []): User
    {
        $user = new User();
        $user->setRawAttributes($attributes, true);

        return $user;
    }
}
