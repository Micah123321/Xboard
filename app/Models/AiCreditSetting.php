<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCreditSetting extends Model
{
    protected $table = 'v2_ai_credit_settings';
    protected $dateFormat = 'U';
    protected $guarded = [];
    public $incrementing = false;
    protected $hidden = ['bridge_token'];
    protected $casts = ['settings' => 'array', 'bridge_token' => 'encrypted'];
}
