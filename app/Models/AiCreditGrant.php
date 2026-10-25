<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCreditGrant extends Model
{
    protected $table = 'v2_ai_credit_grants';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['code', 'external_ref', 'lease_token', 'bridge_url', 'policy_snapshot'];
    protected $casts = [
        'policy_snapshot' => 'array', 'code' => 'encrypted',
        'traffic_gb' => 'integer', 'paid_amount_cents' => 'integer', 'amount_usd_cents' => 'integer',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        'issued_at' => 'integer', 'redeemed_at' => 'integer', 'lease_until' => 'integer',
        'next_attempt_at' => 'integer', 'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
