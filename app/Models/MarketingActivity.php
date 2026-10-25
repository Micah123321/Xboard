<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingActivity extends Model
{
    protected $table = 'v2_marketing_activities';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'id' => 'integer', 'benefits' => 'array', 'sort' => 'integer',
        'published' => 'boolean', 'starts_at' => 'integer', 'ends_at' => 'integer',
        'version' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp',
    ];
}
