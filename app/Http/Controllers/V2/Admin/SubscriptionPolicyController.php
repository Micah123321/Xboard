<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionPolicyController extends Controller
{
    public function fetch(SubscriptionPolicyService $service): JsonResponse
    {
        return response()->json(['data' => $service->settings()]);
    }

    public function save(Request $request, SubscriptionPolicyService $service): JsonResponse
    {
        return response()->json(['data' => $service->save($request->all())]);
    }
}
