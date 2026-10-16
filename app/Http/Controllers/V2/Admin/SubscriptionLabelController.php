<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionLabel;
use App\Services\SubscriptionLabelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionLabelController extends Controller
{
    public function fetch(): JsonResponse
    {
        return $this->success(SubscriptionLabel::withCount('servers')->orderBy('id')->get());
    }

    public function save(Request $request, SubscriptionLabelService $service): JsonResponse
    {
        return $this->success($service->save($request->all()));
    }

    public function drop(Request $request, SubscriptionLabelService $service): JsonResponse
    {
        $data = $request->validate(['id' => 'required|integer|min:1|exists:v2_subscription_labels,id']);
        $service->drop($data['id']);
        return $this->success(true);
    }
}
