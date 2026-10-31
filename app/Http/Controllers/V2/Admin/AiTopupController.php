<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\AiTopup\AiTopupService;
use Illuminate\Http\Request;

class AiTopupController extends Controller
{
    public function __construct(private AiTopupService $service) {}

    public function settings(Request $request)
    {
        return $this->success($request->isMethod('post')
            ? $this->service->saveSettings($request->all()) : $this->service->settings())
            ->header('Cache-Control', 'no-store');
    }

    public function records(Request $request)
    {
        $input = $request->validate([
            'current' => 'sometimes|integer|min:1', 'page_size' => 'sometimes|integer|min:1|max:100',
            'user_id' => 'nullable|integer|min:1', 'trade_no' => 'nullable|string|max:64',
            'status' => 'nullable|in:pending,cancelled,paid',
            'issue_status' => 'nullable|in:pending,generating,reconciling,issued,redeemed,revoked',
        ]);
        return $this->success($this->service->records(null, $input))->header('Cache-Control', 'no-store');
    }

    public function reconcile(Request $request)
    {
        $input = $request->validate(['id' => 'required|integer|min:1']);
        return $this->success($this->service->serialize($this->service->fulfill((int) $input['id'], true), false, true))
            ->header('Cache-Control', 'no-store');
    }
}
