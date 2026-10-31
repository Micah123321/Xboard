<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiTopupSetting extends Model
{
    protected $table = 'v2_ai_topup_settings';
    protected $dateFormat = 'U';
    protected $guarded = [];
    public $incrementing = false;
    protected $hidden = ['instance_id'];
    protected $casts = ['settings' => 'array', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
