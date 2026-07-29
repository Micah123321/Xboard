<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->withCount([
                'users',
                'orders',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->get();

        return $this->success($plans);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }
            
            DB::beginTransaction();
            try {
                if ($request->input('force_update')) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'temporary_transfer_enable' => 0,
                        'speed_limit' => $params['speed_limit'],
                        'device_limit' => $params['device_limit'],
                    ]);
                }
                $plan->update($params);
                DB::commit();
                return $this->success(true);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }
        if (!Plan::create($params)) {
            return $this->fail([500, '创建失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'replacement_plan_id' => 'nullable|integer',
        ]);

        try {
            return DB::transaction(function () use ($params) {
                $plan = Plan::lockForUpdate()->find($params['id']);
                if (!$plan) {
                    return $this->fail([400202, '该订阅不存在']);
                }

                $relatedOrderIds = Order::where('plan_id', $plan->id)
                    ->lockForUpdate()
                    ->pluck('id');
                $relatedUserIds = User::where('plan_id', $plan->id)
                    ->lockForUpdate()
                    ->pluck('id');
                $hasRelatedRecords = $relatedOrderIds->isNotEmpty() || $relatedUserIds->isNotEmpty();

                if ($hasRelatedRecords) {
                    $replacementPlanId = isset($params['replacement_plan_id'])
                        ? (int) $params['replacement_plan_id']
                        : null;
                    if (!$replacementPlanId || $replacementPlanId === $plan->id) {
                        return $this->fail([400201, '请选择其他套餐作为替代套餐']);
                    }

                    $replacementPlan = Plan::lockForUpdate()->find($replacementPlanId);
                    if (!$replacementPlan) {
                        return $this->fail([400202, '替代套餐不存在']);
                    }

                    if ($relatedOrderIds->isNotEmpty()) {
                        Order::whereIn('id', $relatedOrderIds)->update(['plan_id' => $replacementPlan->id]);
                    }
                    if ($relatedUserIds->isNotEmpty()) {
                        User::whereIn('id', $relatedUserIds)->update(['plan_id' => $replacementPlan->id]);
                    }
                }

                return $this->success($plan->delete());
            });
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '删除失败']);
        }
    }

    public function copy(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
        ]);

        try {
            return DB::transaction(function () use ($params) {
                $plan = Plan::lockForUpdate()->find($params['id']);
                if (!$plan) {
                    return $this->fail([400202, '该订阅不存在']);
                }

                $lastPlan = Plan::lockForUpdate()
                    ->orderByDesc('sort')
                    ->orderByDesc('id')
                    ->first();
                $copy = $plan->replicate();
                $copy->name = "{$plan->name}--复制";
                $copy->sort = ((int) ($lastPlan?->sort ?? 0)) + 1;
                $copy->save();

                return $this->success(true);
            });
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '复制失败']);
        }
    }

    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }
}
