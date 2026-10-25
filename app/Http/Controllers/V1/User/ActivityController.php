<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MarketingActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ActivityController extends Controller
{
    public function __construct(private MarketingActivityService $service) {}

    public function fetch(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user instanceof User) throw new ApiException('请先登录', 401);
            $response = $this->success(['activities' => $this->service->fetchForUser($user),
                'server_time' => Carbon::now()->getTimestamp()]);
        } catch (ApiException $e) {
            $response = $this->fail([$e->getCode(), $e->getMessage()], null, $e->errors());
        }
        return $response->header('Cache-Control', 'no-store')->header('Vary', 'Authorization');
    }
}
