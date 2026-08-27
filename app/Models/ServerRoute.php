<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerRoute extends Model
{
    public const FETCH_CACHE_KEY = 'admin_server_routes_fetch';

    protected $table = 'v2_server_route';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'match' => 'array'
    ];
}
