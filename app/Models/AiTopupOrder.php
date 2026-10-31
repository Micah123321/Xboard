<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTopupOrder extends Model
{
    protected $table = 'v2_ai_topup_orders';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['code', 'bridge_token', 'bridge_url', 'external_ref', 'lease_token', 'policy_snapshot', 'client_request_id', 'callback_no', 'checkout_payload', 'checkout_token', 'payment_receipts'];
    protected $casts = [
        'code' => 'encrypted', 'bridge_token' => 'encrypted', 'policy_snapshot' => 'array',
        'checkout_payload' => 'encrypted:array', 'payment_receipts' => 'array', 'checkout_started_at' => 'integer',
        'amount_usd' => 'integer', 'total_amount' => 'integer', 'handling_amount' => 'integer',
        'gift_traffic_bytes' => 'integer', 'payment_id' => 'integer', 'attempts' => 'integer',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp', 'expires_at' => 'integer',
        'paid_at' => 'integer', 'cancelled_at' => 'integer', 'issued_at' => 'integer', 'redeemed_at' => 'integer',
        'traffic_activated_at' => 'integer', 'lease_until' => 'integer', 'next_attempt_at' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
