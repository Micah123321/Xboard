<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrafficLogResource;
use App\Models\StatUser;
use Illuminate\Http\Request;

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        $clock = now(config('app.timezone', 'UTC'));
        $startDate = min($clock->copy()->startOfMonth()->timestamp, $clock->copy()->subDay()->startOfDay()->timestamp);
        $userId = (int) $request->user()->id;

        $records = StatUser::query()
            ->with(['server:id,name'])
            ->where('user_id', $userId)
            ->where('record_type', 'd')
            ->where('record_at', '>=', $startDate)
            ->where('record_at', '<', $clock->copy()->addDay()->startOfDay()->timestamp)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->orderByDesc('record_at')
            ->get();

        $data = TrafficLogResource::collection(collect($records));
        return $this->success($data);
    }

}
