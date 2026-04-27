<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerGfwCheck extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CHECKING = 'checking';
    public const STATUS_NORMAL = 'normal';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const FINAL_STATUSES = [
        self::STATUS_NORMAL,
        self::STATUS_BLOCKED,
        self::STATUS_PARTIAL,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    protected $table = 'server_gfw_checks';

    protected $guarded = ['id'];

    protected $casts = [
        'summary' => 'array',
        'operator_summary' => 'array',
        'raw_result' => 'array',
        'checked_at' => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id', 'id');
    }
}
