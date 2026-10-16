<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionLabel extends Model
{
    protected $table = 'v2_subscription_labels';
    protected $fillable = ['name', 'plan_ids'];
    protected $casts = ['plan_ids' => 'array'];

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'subscription_label_id');
    }

    public function setPlanIdsAttribute(array $ids): void
    {
        $this->attributes['plan_ids'] = json_encode(array_values(array_unique(array_map('intval', $ids))));
    }
}
