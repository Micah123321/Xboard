<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficReport extends Model
{
    protected $table = 'v2_traffic_report';
    protected $guarded = ['id'];
    public $timestamps = false;
}
