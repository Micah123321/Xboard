<?php

namespace App\Services;

use App\Jobs\SubscriptionLabelSyncJob;
use App\Models\Server;
use App\Models\SubscriptionLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionLabelService
{
    public function save(array $input): SubscriptionLabel
    {
        $data = Validator::make($input, [
            'id' => 'sometimes|required|integer|min:1|exists:v2_subscription_labels,id',
            'name' => ['required', 'string', 'max:64'],
            'plan_ids' => 'required|array|list|min:1',
            'plan_ids.*' => 'required|integer|min:1|distinct|exists:v2_plan,id',
        ])->validate();
        return DB::transaction(function () use ($data): SubscriptionLabel {
            $label = isset($data['id'])
                ? SubscriptionLabel::lockForUpdate()->findOrFail($data['id'])
                : new SubscriptionLabel();
            $name = trim($data['name']);
            Validator::make(['name' => $name], [
                'name' => ['required', Rule::unique('v2_subscription_labels', 'name')->ignore($label->id)],
            ])->validate();
            $label->fill(['name' => $name, 'plan_ids' => $data['plan_ids']]);
            $plansChanged = $label->exists && $label->isDirty('plan_ids');
            $label->saveOrFail();
            if ($plansChanged) {
                SubscriptionLabelSyncJob::dispatch($label->id);
            }
            return $label->loadCount('servers');
        });
    }

    public function drop(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $label = SubscriptionLabel::lockForUpdate()->findOrFail($id);
            if (Server::where('subscription_label_id', $id)->exists()) {
                throw ValidationException::withMessages(['id' => '该订阅标签仍有节点使用，请先为这些节点更换或清除标签']);
            }
            $label->delete();
        });
    }
}
