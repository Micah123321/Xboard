<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrafficLogResource;
use App\Models\StatUser;
use App\Services\UserOnlineService;
use Illuminate\Http\Request;

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        $startDate = now()->startOfMonth()->timestamp;
        $userId = (int) $request->user()->id;

        $records = StatUser::query()
            ->with(['server:id,name'])
            ->where('user_id', $userId)
            ->where('record_at', '>=', $startDate)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->orderByDesc('record_at')
            ->get();

        $deviceMap = $this->buildNodeDeviceMap($userId);
        $records->each(function (StatUser $record) use ($deviceMap): void {
            $serverType = strtolower((string) $record->server_type);
            $serverId = (int) $record->server_id;
            $nodeKey = $serverType !== '' && $serverId > 0 ? "{$serverType}{$serverId}" : null;
            $deviceIps = $nodeKey ? ($deviceMap[$nodeKey] ?? []) : [];

            $record->setAttribute('server_name', $record->server?->name);
            $record->setAttribute('node_name', $record->server?->name);
            $record->setAttribute('device_ips', $deviceIps);
            $record->setAttribute('device_count', count($deviceIps));
            $record->setAttribute('device_name', $deviceIps[0] ?? 'Unknown');
        });

        $data = TrafficLogResource::collection(collect($records));
        return $this->success($data);
    }

    private function buildNodeDeviceMap(int $userId): array
    {
        $devices = UserOnlineService::getUserDevices($userId);
        $deviceList = data_get($devices, 'devices', []);

        return collect($deviceList)
            ->filter(fn($item): bool => is_array($item) && !empty($item['ip']))
            ->map(function (array $item): array {
                $nodeKey = strtolower((string) ($item['node_key'] ?? ''));
                if ($nodeKey === '') {
                    $nodeType = strtolower((string) ($item['node_type'] ?? ''));
                    $nodeId = (int) ($item['node_id'] ?? 0);
                    $nodeKey = ($nodeType !== '' && $nodeId > 0) ? "{$nodeType}{$nodeId}" : $nodeType;
                }

                return [
                    'node_key' => $nodeKey,
                    'ip' => (string) ($item['ip'] ?? ''),
                ];
            })
            ->filter(fn(array $item): bool => $item['node_key'] !== '' && $item['ip'] !== '')
            ->groupBy(fn(array $item): string => $item['node_key'])
            ->map(fn($items): array => collect($items)
                ->pluck('ip')
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->all();
    }
}
