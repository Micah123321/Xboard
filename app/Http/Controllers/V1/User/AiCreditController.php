<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\AiCredit\AiCreditService;
use Illuminate\Http\Request;

class AiCreditController extends Controller
{
    public function __construct(private AiCreditService $service) {}

    public function overview(Request $request)
    {
        return $this->success($this->service->overview($request->user()))->header('Cache-Control', 'no-store');
    }

    public function records(Request $request)
    {
        $input = $request->validate([
            'current' => 'sometimes|integer|min:1', 'page_size' => 'sometimes|integer|min:1|max:100',
            'kind' => 'nullable|in:available,history', 'status' => 'nullable|in:eligible,generating,reconciling,issued,redeemed,revoked,failed',
        ]);
        return $this->success($this->service->userRecords($request->user(), (int) ($input['current'] ?? 1), (int) ($input['page_size'] ?? 20), $input['kind'] ?? null, $input['status'] ?? null))->header('Cache-Control', 'no-store');
    }

    public function refresh(Request $request)
    {
        $this->service->prepareHistorical($request->user());
        return $this->success($this->service->overview($request->user()))->header('Cache-Control', 'no-store');
    }

    public function claim(Request $request)
    {
        $input = $request->validate(['grant_id' => 'required|integer|min:1']);
        return $this->success($this->service->claim((int) $input['grant_id'], $request->user()))->header('Cache-Control', 'no-store');
    }

    public function detail(Request $request)
    {
        $input = $request->validate(['grant_id' => 'required|integer|min:1']);
        return $this->success($this->service->detail((int) $input['grant_id'], $request->user()))->header('Cache-Control', 'no-store');
    }
}
