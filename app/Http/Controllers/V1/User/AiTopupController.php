<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\AiTopup\AiTopupService;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class AiTopupController extends Controller
{
    public function __construct(private AiTopupService $service) {}

    public function overview(Request $request)
    {
        return $this->success($this->service->overview($request->user()))->header('Cache-Control', 'no-store');
    }

    public function quote(Request $request)
    {
        $input = $request->validate(['amount_usd' => 'required|integer|min:1|max:1000000']);
        return $this->success($this->service->quote((int) $input['amount_usd']))->header('Cache-Control', 'no-store');
    }

    public function create(Request $request)
    {
        $input = $request->validate([
            'amount_usd' => 'required|integer|min:1|max:1000000',
            'idempotency_key' => 'required|uuid',
        ]);
        $order = $this->service->create($request->user(), (int) $input['amount_usd'], $input['idempotency_key']);
        return $this->success($this->service->serialize($order))->header('Cache-Control', 'no-store');
    }

    public function paymentMethods()
    {
        $enabled = array_intersect(PaymentService::getAllPaymentMethodNames(), \App\Services\AiTopup\AiTopupPolicy::PAYMENT_METHODS);
        $methods = Payment::where('enable', true)->whereIn('payment', $enabled)->orderBy('sort')
            ->get(['id', 'name', 'payment', 'icon', 'handling_fee_fixed', 'handling_fee_percent', 'config'])
            ->filter(fn ($method) => ($method->payment !== 'BEpusdt' || ($method->config['fiat'] ?? 'CNY') === 'CNY')
                && ($method->payment !== 'CoinPayments' || ($method->config['coinpayments_currency'] ?? '') === 'CNY'))
            ->values()->map(fn ($method) => $method->only(['id', 'name', 'payment', 'icon', 'handling_fee_fixed', 'handling_fee_percent']));
        return $this->success($methods)->header('Cache-Control', 'no-store');
    }

    public function checkout(Request $request)
    {
        $input = $request->validate([
            'trade_no' => 'required|string|max:64', 'method' => 'required|integer|exists:v2_payment,id',
            'token' => 'nullable|string|max:2048',
        ]);
        $payment = Payment::findOrFail($input['method']);
        $result = $this->service->checkoutPayment($request->user(), $input['trade_no'], $payment, fn ($order) => (new PaymentService($payment->payment, $payment->id))->pay([
            'trade_no' => $order->trade_no,
            'total_amount' => (int) $order->total_amount + (int) $order->handling_amount,
            'user_id' => $order->user_id,
            'stripe_token' => $input['token'] ?? null,
            'return_url' => source_base_url('/dashboard/finance/ai-topup?trade_no=' . rawurlencode($order->trade_no)),
        ]));
        return response()->json(['type' => $result['type'], 'data' => $result['data']])->header('Cache-Control', 'no-store');
    }

    public function records(Request $request)
    {
        $input = $request->validate([
            'current' => 'sometimes|integer|min:1', 'page_size' => 'sometimes|integer|min:1|max:100',
            'status' => 'nullable|in:pending,cancelled,paid',
            'issue_status' => 'nullable|in:pending,generating,reconciling,issued,redeemed,revoked',
        ]);
        return $this->success($this->service->records($request->user(), $input))->header('Cache-Control', 'no-store');
    }

    public function detail(Request $request)
    {
        $input = $request->validate(['trade_no' => 'required|string|max:64']);
        return $this->success($this->service->detail($request->user(), $input['trade_no']))->header('Cache-Control', 'no-store');
    }

    public function cancel(Request $request)
    {
        $input = $request->validate(['trade_no' => 'required|string|max:64']);
        return $this->success($this->service->cancel($request->user(), $input['trade_no']))->header('Cache-Control', 'no-store');
    }

    public function activateTraffic(Request $request)
    {
        $input = $request->validate(['trade_no' => 'required|string|max:64']);
        return $this->success($this->service->activateTraffic($request->user(), $input['trade_no']))->header('Cache-Control', 'no-store');
    }
}
