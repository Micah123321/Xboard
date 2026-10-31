<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            HookManager::call('payment.notify.verified', $verify);
            if (($verify['paid'] ?? null) === false) {
                return $verify['custom_result'] ?? 'success';
            }
            if (!$this->handle($verify, $method)) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    /**
     * @param array<string, mixed> $verify
     */
    private function handle(array $verify, string $method = ''): bool
    {
        $tradeNo = (string) ($verify['trade_no'] ?? '');
        $callbackNo = (string) ($verify['callback_no'] ?? '');
        if ($tradeNo === '') {
            return false;
        }
        if (str_starts_with($tradeNo, 'AIT')) {
            return app(\App\Services\AiTopup\AiTopupService::class)->paid($verify, $method);
        }
        $userId = Order::where('trade_no', $tradeNo)->value('user_id');
        return DB::transaction(function () use ($verify, $method, $tradeNo, $callbackNo, $userId) {
            // Match fulfillment and cancellation's user-before-order lock order.
            if (!$userId || !User::lockForUpdate()->find($userId)) {
                return false;
            }
            $order = Order::where('trade_no', $tradeNo)->lockForUpdate()->first();
            if (!$order || (int) $order->user_id !== (int) $userId
                || (isset($verify['payment_id']) && (int) $order->payment_id !== (int) $verify['payment_id'])) {
                return false;
            }
            // Recheck BEpusdt's signed quote under the lock in case checkout changed the fee.
            if ($method === 'BEpusdt' && ($order->payment?->payment !== 'BEpusdt'
                || (int) $order->total_amount + (int) $order->handling_amount !== ($verify['expected_amount'] ?? null))) {
                return false;
            }
            if (!in_array((int) $order->status, [Order::STATUS_PENDING, Order::STATUS_CANCELLED], true)) {
                return true;
            }
            if (!(new OrderService($order))->paid($callbackNo, $verify)) {
                if ($method === 'BEpusdt') {
                    throw new \RuntimeException('BEpusdt order fulfillment failed');
                }
                return false;
            }
            DB::afterCommit(function () use ($order) {
                try {
                    HookManager::call('payment.notify.success', $order);
                } catch (\Exception $exception) {
                    Log::error('Payment success hook failed after commit', ['order_id' => $order->id, 'exception' => $exception]);
                }
            });
            return true;
        });
    }
}
