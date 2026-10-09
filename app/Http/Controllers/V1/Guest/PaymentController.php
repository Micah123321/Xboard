<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
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

    private function handleBepusdt(array $verify): bool
    {
        return DB::transaction(function () use ($verify) {
            $order = Order::where('trade_no', $verify['trade_no'])->lockForUpdate()->first();
            // Recheck under the lock: checkout may have changed the channel or fee.
            if (!$order || (int) $order->payment_id !== $verify['payment_id']
                || $order->payment?->payment !== 'BEpusdt'
                || (int) $order->total_amount + (int) $order->handling_amount !== $verify['expected_amount']) {
                return false;
            }
            if (in_array((int) $order->status, [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED], true)) {
                return true;
            }
            if ((int) $order->status !== Order::STATUS_PENDING) {
                return false;
            }
            if (!(new OrderService($order))->paid($verify['callback_no'], $verify)) {
                // paid() catches dispatch failures; throwing here restores the pending order for retry.
                throw new \RuntimeException('BEpusdt order fulfillment failed');
            }
            HookManager::call('payment.notify.success', $order);
            return true;
        });
    }

    /**
     * @param array<string, mixed> $verify
     */
    private function handle(array $verify, string $method = '')
    {
        if ($method === 'BEpusdt') {
            return $this->handleBepusdt($verify);
        }
        $tradeNo = (string) ($verify['trade_no'] ?? '');
        $callbackNo = (string) ($verify['callback_no'] ?? '');
        if ($tradeNo === '') {
            return false;
        }

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return $this->fail([400202, 'order is not found']);
        }
        if ($order->status !== Order::STATUS_PENDING)
            return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo, $verify)) {
            return false;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }
}
