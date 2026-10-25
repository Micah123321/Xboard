<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\AiCredit\AiCreditService;
use Illuminate\Http\Request;

class AiCreditController extends Controller
{
    public function __construct(private AiCreditService $service) {}

    public function settings(Request $request)
    {
        return $this->success($request->isMethod('post') ? $this->service->saveSettings($request->all()) : $this->service->settings());
    }

    public function records(Request $request)
    {
        $input = $request->validate([
            'current' => 'sometimes|integer|min:1', 'page_size' => 'sometimes|integer|min:1|max:100',
            'user_id' => 'nullable|integer|min:1',
            'status' => 'nullable|in:eligible,generating,reconciling,issued,redeemed,revoked,failed',
        ]);
        return $this->success($this->service->records($input));
    }

    public function reconcile(Request $request)
    {
        $input = $request->validate(['grant_id' => 'required|integer|min:1']);
        return $this->success($this->service->reconcile((int) $input['grant_id']));
    }

    public function revoke(Request $request)
    {
        $input = $request->validate(['grant_id' => 'required|integer|min:1', 'reason' => 'required|string|min:2|max:255']);
        return $this->success($this->service->revoke((int) $input['grant_id'], $input['reason']));
    }

    public function preview(Request $request)
    {
        $input = $request->validate(['limit' => 'sometimes|integer|min:1|max:1000']);
        return $this->success($this->service->legacyPreview((int) ($input['limit'] ?? 200)));
    }

    public function backfill(Request $request)
    {
        $input = $request->validate(['limit' => 'required|integer|min:1|max:1000']);
        return $this->success($this->service->backfill((int) $input['limit']));
    }
}
