<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\PlanService;

class OrderService
{
    const STR_TO_TIME = [
        Plan::PERIOD_MONTHLY => 1,
        Plan::PERIOD_QUARTERLY => 3,
        Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12,
        Plan::PERIOD_TWO_YEARLY => 24,
        Plan::PERIOD_THREE_YEARLY => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Create an order from a request.
     *
     * @param User $user
     * @param Plan $plan
     * @param string $period
     * @param string|null $couponCode
     * @return Order
     * @throws ApiException
     */
    public static function createFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode = null,
    ): Order {
        $userService = app(UserService::class);
        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $period);
        HookManager::call('order.create.before', [$user, $plan, $period, $couponCode]);

        return DB::transaction(function () use ($user, $plan, $period, $couponCode, $userService) {
            $newPeriod = PlanService::getPeriodKey($period);

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $newPeriod,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => (int) (optional($plan->prices)[$newPeriod] * 100),
            ]);

            $orderService = new self($order);

            if ($couponCode) {
                $orderService->applyCoupon($couponCode);
            }

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user);
            $orderService->setInvite(user: $user);

            if ($user->balance && $order->total_amount > 0) {
                $orderService->handleUserBalance($user, $userService);
            }

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            HookManager::call('order.create.after', $order);
            // 兼容旧钩子
            HookManager::call('order.after_create', $order);

            return $order;
        });
    }

    public function open(): void
    {
        $order = $this->order;
        $opened = DB::transaction(function () use (&$order) {
            // Serialize purchases for the same user before checking their purchase history.
            $this->user = User::lockForUpdate()->findOrFail($order->user_id);
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->order = $order;
            if ((int) $order->status !== Order::STATUS_PROCESSING) {
                return false;
            }

            if ($order->period === Plan::PERIOD_RESET_TRAFFIC
                && (int) $this->user->plan_id !== (int) $order->plan_id) {
                throw new ApiException('当前订阅已变更，请联系客服处理该重置订单');
            }
            if (!$this->hasAvailableSurplusCredit($order)) {
                throw new ApiException('订单折抵来源已变更，请联系客服处理');
            }
            $plan = Plan::findOrFail($order->plan_id);
            $previousAiPlan = $this->user->plan_id ? Plan::find($this->user->plan_id) : null;
            app(\App\Services\AiCredit\AiCreditService::class)->prepareLegacy($this->user, true);
            HookManager::call('order.open.before', $order);

            // Legacy orders already priced with a monetary credit keep that quote without a second credit.
            $carryoverBytes = $this->user->plan_id !== null && (int) $order->surplus_amount === 0
                ? $this->user->getRemainingTraffic() : 0;
            $this->resetOrderTraffic();

            if ($order->refund_amount) {
                $this->user->balance += $order->refund_amount;
            }

            if ($order->surplus_order_ids) {
                Order::whereIn('id', $order->surplus_order_ids)
                    ->update(['status' => Order::STATUS_DISCOUNTED]);
            }

            match ((string) $order->period) {
                Plan::PERIOD_ONETIME => $this->buyByOneTime($plan),
                Plan::PERIOD_RESET_TRAFFIC => $this->user->transfer_enable = (int) ($plan->transfer_enable * 1073741824),
                default => $this->buyByPeriod($order, $plan),
            };

            $this->setSpeedLimit($plan->speed_limit);
            $this->setDeviceLimit($plan->device_limit);
            $this->user->setRelation('plan', $plan);
            $this->user->next_reset_at = app(TrafficResetService::class)
                ->calculateNextResetTime($this->user)?->timestamp;

            $eventId = match ((int) $order->type) {
                Order::TYPE_NEW_PURCHASE => admin_setting('new_order_event_id', 0),
                Order::TYPE_RENEWAL => admin_setting('renew_order_event_id', 0),
                Order::TYPE_UPGRADE => admin_setting('change_order_event_id', 0),
                default => 0,
            };

            if ($eventId) {
                if (!$this->user->save()) {
                    throw new \RuntimeException('用户信息保存失败');
                }
                $this->openEvent($eventId);
                $this->user->refresh();
            }

            // Restore the balance after configured reset events, then add any first-purchase bonus.
            if ($carryoverBytes > 0) {
                app(UserService::class)->addTemporaryTraffic($this->user, $carryoverBytes);
            }
            $this->grantInviteFirstPurchaseTraffic($order, $plan);
            if (!$this->user->save()) {
                throw new \RuntimeException('用户信息保存失败');
            }

            $order->status = Order::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \RuntimeException('订单信息保存失败');
            }
            app(\App\Services\AiCredit\AiCreditService::class)->onOrderCompleted(
                $order, $plan, $previousAiPlan ? ['id' => $previousAiPlan->id, 'traffic_gb' => $previousAiPlan->transfer_enable] : null
            );

            return true;
        });

        if ($opened) {
            DB::afterCommit(function () use ($order) {
                try {
                    HookManager::call('order.open.after', $order);
                } catch (\Exception $exception) {
                    Log::error('Order success hook failed after commit', ['order_id' => $order->id, 'exception' => $exception]);
                }
            });
        }
    }

    private function grantInviteFirstPurchaseTraffic(Order $order, Plan $plan): void
    {
        if (!$order->invite_user_id
            || (int) $order->invite_user_id === (int) $order->user_id
            || $order->period === Plan::PERIOD_RESET_TRAFFIC
            || (int) $order->total_amount + (int) $order->balance_amount <= 0
            || !User::whereKey($order->invite_user_id)->exists()) {
            return;
        }

        $hasPaidPurchase = Order::where('user_id', $order->user_id)
            ->where('id', '!=', $order->id)
            ->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
            ->where('period', '!=', Plan::PERIOD_RESET_TRAFFIC)
            ->where(function ($query) {
                $query->where('total_amount', '>', 0)->orWhere('balance_amount', '>', 0);
            })
            // A callback may already have a REPEATABLE READ snapshot; locking reads see the latest commit.
            ->lockForUpdate()
            ->first(['id']);
        if ($hasPaidPurchase) {
            return;
        }

        $bonusBytes = intdiv((int) ($plan->transfer_enable * 1073741824), 10);
        if ($bonusBytes > 0) {
            app(UserService::class)->addTemporaryTraffic($this->user, $bonusBytes);
        }
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === Plan::PERIOD_RESET_TRAFFIC) {
            $order->type = Order::TYPE_RESET_TRAFFIC;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int) admin_setting('plan_change_enable', 1))
                throw new ApiException('目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = Order::TYPE_UPGRADE;
            // Unused quota is carried on fulfillment, rather than redeemed again as money.
            $order->surplus_amount = 0;
            $order->refund_amount = 0;
            $order->surplus_order_ids = [];
        } else if (($user->expired_at === null || $user->expired_at > time()) && $order->plan_id == $user->plan_id) { // 用户订阅未过期或按流量订阅 且购买订阅与当前订阅相同 === 续费
            $order->type = Order::TYPE_RENEWAL;
        } else { // 新购
            $order->type = Order::TYPE_NEW_PURCHASE;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0))
            return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter)
            return;
        $commissionType = (int) $inviter->commission_type;
        if ($commissionType === User::COMMISSION_TYPE_SYSTEM) {
            $commissionType = (bool) admin_setting('commission_first_time_enable', true) ? User::COMMISSION_TYPE_ONETIME : User::COMMISSION_TYPE_PERIOD;
        }
        $isCommission = false;
        switch ($commissionType) {
            case User::COMMISSION_TYPE_PERIOD:
                $isCommission = true;
                break;
            case User::COMMISSION_TYPE_ONETIME:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission)
            return;
        if ($inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (admin_setting('invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user): Order|null
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $lastOneTimeOrder = Order::where('user_id', $user->id)
                ->where('period', Plan::PERIOD_ONETIME)
                ->where('status', Order::STATUS_COMPLETED)
                ->orderBy('id', 'DESC')
                ->first();
            if (!$lastOneTimeOrder)
                return;
            $nowUserTraffic = Helper::transferToGB($user->transfer_enable);
            if (!$nowUserTraffic)
                return;
            $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
            if (!$paidTotalAmount)
                return;
            $trafficUnitPrice = $paidTotalAmount / $nowUserTraffic;
            $notUsedTraffic = $nowUserTraffic - Helper::transferToGB($user->u + $user->d);
            $result = $trafficUnitPrice * $notUsedTraffic;
            $order->surplus_amount = (int) ($result > 0 ? $result : 0);
            $order->surplus_order_ids = Order::where('user_id', $user->id)
                ->where('period', '!=', Plan::PERIOD_RESET_TRAFFIC)
                ->where('status', Order::STATUS_COMPLETED)
                ->pluck('id')
                ->all();
        } else {
            $orders = Order::query()
                ->where('user_id', $user->id)
                ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC, Plan::PERIOD_ONETIME])
                ->where('status', Order::STATUS_COMPLETED)
                ->get();

            if ($orders->isEmpty()) {
                $order->surplus_amount = 0;
                $order->surplus_order_ids = [];
                return;
            }

            $orderAmountSum = $orders->sum(fn($item) => $item->total_amount + $item->balance_amount + $item->surplus_amount - $item->refund_amount);
            $orderMonthSum = $orders->sum(fn($item) => self::STR_TO_TIME[PlanService::getPeriodKey($item->period)] ?? 0);
            $firstOrderAt = $orders->min('created_at');
            $expiredAt = Carbon::createFromTimestamp($firstOrderAt)->addMonths($orderMonthSum);

            $now = now();
            $totalSeconds = $expiredAt->timestamp - $firstOrderAt;
            $remainSeconds = max(0, $expiredAt->timestamp - $now->timestamp);
            $cycleRatio = $totalSeconds > 0 ? $remainSeconds / $totalSeconds : 0;

            $plan = Plan::find($user->plan_id);
            $totalTraffic = $plan?->transfer_enable * $orderMonthSum;
            $usedTraffic = Helper::transferToGB($user->u + $user->d);
            $remainTraffic = max(0, $totalTraffic - $usedTraffic);
            $trafficRatio = $totalTraffic > 0 ? $remainTraffic / $totalTraffic : 0;

            $ratio = $cycleRatio;
            if (admin_setting('change_order_event_id', 0) == 1) {
                $ratio = min($cycleRatio, $trafficRatio);
            }


            $order->surplus_amount = (int) max(0, $orderAmountSum * $ratio);
            $order->surplus_order_ids = $orders->pluck('id')->all();
        }
    }

    public function paid(string $callbackNo, array $paymentSnapshot = []): bool
    {
        $originalOrder = $this->order;
        try {
            return DB::transaction(function () use ($originalOrder, $callbackNo, $paymentSnapshot) {
                // Cancellation, payment and fulfillment all lock the user before the order.
                $user = User::lockForUpdate()->findOrFail($originalOrder->user_id);
                $order = Order::lockForUpdate()->findOrFail($originalOrder->id);
                $this->order = $order;
                if ((int) $order->user_id !== (int) $user->id) {
                    return false;
                }
                if (!in_array((int) $order->status, [Order::STATUS_PENDING, Order::STATUS_CANCELLED], true)) {
                    return true;
                }
                if (($paymentSnapshot['paid'] ?? null) === false
                    || (isset($paymentSnapshot['trade_no']) && $paymentSnapshot['trade_no'] !== $order->trade_no)
                    || (isset($paymentSnapshot['payment_id']) && (int) $paymentSnapshot['payment_id'] !== (int) $order->payment_id)) {
                    return false;
                }

                if ((int) $order->status === Order::STATUS_CANCELLED) {
                    if (!$this->canRestoreCancelledPayment($order, $callbackNo, $paymentSnapshot)) {
                        return false;
                    }
                    // Keep the original quote, but classify against the current subscription.
                    $order->type = match (true) {
                        $order->period === Plan::PERIOD_RESET_TRAFFIC => Order::TYPE_RESET_TRAFFIC,
                        $user->plan_id === null || ($user->expired_at !== null && $user->expired_at <= time()) => Order::TYPE_NEW_PURCHASE,
                        (int) $user->plan_id === (int) $order->plan_id => Order::TYPE_RENEWAL,
                        default => Order::TYPE_UPGRADE,
                    };
                    // cancel() has returned this balance credit; reclaim it exactly once.
                    if ((int) $order->balance_amount > 0) {
                        if ((int) $user->balance < (int) $order->balance_amount) {
                            return false;
                        }
                        $user->balance -= (int) $order->balance_amount;
                        if (!$user->save()) {
                            throw new \RuntimeException('Failed to reclaim cancelled order balance.');
                        }
                    }
                }

                $order->fill([
                    'status' => Order::STATUS_PROCESSING,
                    'paid_at' => time(),
                    'callback_no' => $callbackNo,
                    ...$this->buildPaymentSnapshot($paymentSnapshot),
                ]);
                if (!$order->save()) {
                    throw new \RuntimeException('Failed to save paid order.');
                }
                // Keep receipt, balance and fulfillment atomic, including failed synchronous jobs.
                OrderHandleJob::dispatchSync($order->trade_no);
                return true;
            });
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        } finally {
            $originalOrder->refresh();
            $this->order = $originalOrder;
        }
    }

    private function canRestoreCancelledPayment(Order $order, string $callbackNo, array $paymentSnapshot): bool
    {
        $expectedAmount = (int) $order->total_amount + (int) $order->handling_amount;
        // Only signed callback amounts are evidence; never substitute the local order price.
        $amount = array_key_exists('expected_amount', $paymentSnapshot)
            ? (is_int($paymentSnapshot['expected_amount']) ? $paymentSnapshot['expected_amount'] : null)
            : $this->callbackAmountInCents($paymentSnapshot['payment_amount'] ?? null);
        if (($paymentSnapshot['trade_no'] ?? null) !== $order->trade_no
            || trim($callbackNo) === '' || $expectedAmount <= 0 || $amount !== $expectedAmount) {
            return false;
        }
        return true;
    }

    private function hasAvailableSurplusCredit(Order $order): bool
    {
        // Every fulfillment must claim legacy monetary credits only once, including pending orders.
        if ($order->surplus_order_ids) {
            $ids = array_unique($order->surplus_order_ids);
            $credits = Order::whereIn('id', $ids)->where('user_id', $order->user_id)
                ->where('status', Order::STATUS_COMPLETED)->lockForUpdate()->get(['id']);
            if ($credits->count() !== count($ids)) {
                return false;
            }
        }
        return true;
    }

    private function callbackAmountInCents(mixed $amount): ?int
    {
        if ((!is_string($amount) && !is_int($amount) && !is_float($amount))
            || !preg_match('/^([0-9]{1,12})(?:[.]([0-9]{1,2})0*)?$/D', (string) $amount, $matches)) {
            return null;
        }
        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    /**
     * @param array<string, mixed> $paymentSnapshot
     * @return array<string, mixed>
     */
    private function buildPaymentSnapshot(array $paymentSnapshot): array
    {
        /** @var Payment|null $payment */
        $payment = $this->order->relationLoaded('payment')
            ? $this->order->payment
            : $this->order->payment()->first();

        $channel = $this->normalizeSnapshotText(
            $paymentSnapshot['payment_channel'] ?? $payment?->name ?? $payment?->payment
        );
        $method = $this->normalizeSnapshotText(
            $paymentSnapshot['payment_method'] ?? $this->resolvePaymentMethod($payment)
        );
        $amount = $this->normalizeSnapshotAmount($paymentSnapshot['payment_amount'] ?? null);
        $ip = $this->normalizeSnapshotText($paymentSnapshot['payment_ip'] ?? null);

        return array_filter([
            'payment_channel' => $channel,
            'payment_method' => $method,
            'payment_amount' => $amount,
            'payment_ip' => $ip,
        ], static fn($value) => $value !== null && $value !== '');
    }

    private function resolvePaymentMethod(?Payment $payment): ?string
    {
        if (!$payment) {
            return null;
        }

        $config = $payment->config;
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($config)) {
            $config = [];
        }

        return $this->normalizeSnapshotText(
            data_get($config, 'token_pay_currency') ?? $payment->payment ?? $payment->name
        );
    }

    private function normalizeSnapshotText(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        return $text !== '' ? $text : null;
    }

    private function normalizeSnapshotAmount(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = is_string($value)
            ? str_replace(',', '', trim($value))
            : $value;

        if (!is_numeric($normalized)) {
            return null;
        }

        return (int) round(((float) $normalized) * 100);
    }

    public function cancel(): bool
    {
        $originalOrder = $this->order;
        try {
            return DB::transaction(function () use ($originalOrder) {
                $user = User::lockForUpdate()->findOrFail($originalOrder->user_id);
                $order = Order::lockForUpdate()->findOrFail($originalOrder->id);
                if ((int) $order->user_id !== (int) $user->id) {
                    return false;
                }
                if ((int) $order->status === Order::STATUS_CANCELLED) {
                    return true;
                }
                if ((int) $order->status !== Order::STATUS_PENDING) {
                    return false;
                }
                HookManager::call('order.cancel.before', $order);
                $order->status = Order::STATUS_CANCELLED;
                if (!$order->save()) {
                    throw new \RuntimeException('Failed to save order status.');
                }
                if ((int) $order->balance_amount > 0) {
                    $user->balance += (int) $order->balance_amount;
                    if (!$user->save()) {
                        throw new \RuntimeException('Failed to return order balance.');
                    }
                }
                DB::afterCommit(function () use ($order) {
                    try {
                        HookManager::call('order.cancel.after', $order);
                    } catch (\Exception $exception) {
                        Log::error('Order cancellation hook failed after commit', ['order_id' => $order->id, 'exception' => $exception]);
                    }
                });
                return true;
            });
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        } finally {
            $originalOrder->refresh();
        }
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function setDeviceLimit($deviceLimit)
    {
        $this->user->device_limit = $deviceLimit;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int) $order->type === Order::TYPE_UPGRADE) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->temporary_transfer_enable = 0;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Plan $plan)
    {
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->temporary_transfer_enable = 0;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    /**
     * 计算套餐到期时间
     * @param string $periodKey
     * @param int $timestamp
     * @return int
     * @throws ApiException
     */
    private function getTime(string $periodKey, ?int $timestamp = null): int
    {
        $timestamp = $timestamp < time() ? time() : $timestamp;
        $periodKey = PlanService::getPeriodKey($periodKey);

        if (isset(self::STR_TO_TIME[$periodKey])) {
            $months = self::STR_TO_TIME[$periodKey];
            return Carbon::createFromTimestamp($timestamp)->addMonths($months)->timestamp;
        }

        throw new ApiException('无效的套餐周期');
    }

    private function resetOrderTraffic(): void
    {
        if (!app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER)) {
            throw new \RuntimeException('Order traffic reset failed');
        }
        $this->user->refresh();
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->resetOrderTraffic();
                break;
        }
    }

    protected function applyCoupon(string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($this->order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $this->order->coupon_id = $couponService->getId();
    }

    /**
     * Summary of handleUserBalance
     * @param User $user
     * @param UserService $userService
     * @return void
     */
    protected function handleUserBalance(User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $this->order->total_amount;

        if ($remainingBalance >= 0) {
            if (!$userService->addBalance($this->order->user_id, -$this->order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $this->order->total_amount;
            $this->order->total_amount = 0;
        } else {
            if (!$userService->addBalance($this->order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $user->balance;
            $this->order->total_amount = $this->order->total_amount - $user->balance;
        }
    }
}
