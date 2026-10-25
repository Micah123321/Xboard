<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\MarketingActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ActivityController extends Controller
{
    public function __construct(private MarketingActivityService $service) {}

    public function fetch(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->records($request->all()));
    }

    public function save(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->serialize($this->service->save($request->all())));
    }

    public function publish(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $input = $this->actionInput($request, true);
            return $this->service->serialize($this->service->publish((int) $input['id'],
                (int) $input['version'], (bool) $input['published']));
        });
    }

    public function delete(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $input = $this->actionInput($request, false);
            $this->service->delete((int) $input['id'], (int) $input['version']);
            return true;
        });
    }

    private function actionInput(Request $request, bool $publish): array
    {
        $input = $request->all();
        $rules = ['id' => 'required|integer|min:1', 'version' => 'required|integer|min:1|max:4294967294'];
        if ($publish) $rules['published'] = 'required|boolean';
        if (array_diff(array_keys($input), array_keys($rules))) throw new ApiException('活动参数包含未知字段', 422);
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) throw new ApiException('活动参数有误', 422, $validator->errors()->toArray());
        foreach (['id', 'version'] as $key) {
            if (!is_int($input[$key]) && !(is_string($input[$key]) && preg_match('/\A[1-9][0-9]*\z/', $input[$key]))) {
                throw new ApiException('活动整数参数有误', 422);
            }
        }
        return $input;
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            $response = $this->success($operation());
        } catch (ApiException $e) {
            $response = $this->fail([$e->getCode(), $e->getMessage()], null, $e->errors());
        }
        return $response->header('Cache-Control', 'no-store')->header('Vary', 'Authorization');
    }
}
