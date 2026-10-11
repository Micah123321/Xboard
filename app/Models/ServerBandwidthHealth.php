<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerBandwidthHealth extends Model
{
    protected $table = 'server_bandwidth_health';

    protected $guarded = ['id'];

    protected $casts = [
        'suspended' => 'boolean',
        'mbps' => 'float',
        'low_count' => 'integer',
        'success_count' => 'integer',
        'checked_at' => 'integer',
        'next_check_at' => 'integer',
        'probe_expires_at' => 'integer',
    ];
}
