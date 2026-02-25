<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrafficLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $serverId = (int) data_get($this->resource, 'server_id', 0);
        $serverType = strtolower((string) data_get($this->resource, 'server_type', ''));
        $displayAt = $this->resolveDisplayAt();
        $serverName = data_get($this->resource, 'server_name')
            ?: data_get($this->resource, 'node_name')
            ?: data_get($this->resource, 'server.name');

        if (!$serverName && $serverId > 0) {
            $serverName = "Node #{$serverId}";
        }

        $deviceIps = data_get($this->resource, 'device_ips', []);
        if (!is_array($deviceIps)) {
            $deviceIps = [];
        }

        $deviceName = data_get($this->resource, 'device_name');
        if (!$deviceName) {
            $deviceName = $deviceIps[0] ?? 'Unknown';
        }

        $data = [
            'id' => data_get($this->resource, 'id'),
            'd' => (int) data_get($this->resource, 'd', 0),
            'u' => (int) data_get($this->resource, 'u', 0),
            'record_at' => (int) data_get($this->resource, 'record_at', 0),
            'display_at' => $displayAt,
            'record_type' => data_get($this->resource, 'record_type'),
            'server_rate' => (float) data_get($this->resource, 'server_rate', 1),
            'server_id' => $serverId > 0 ? $serverId : null,
            'server_type' => $serverType !== '' ? $serverType : null,
            'server_name' => $serverName,
            'node_name' => $serverName,
            'node_key' => $serverId > 0 && $serverType !== '' ? "{$serverType}{$serverId}" : null,
            'device_name' => $deviceName,
            'device_ips' => $deviceIps,
            'device_count' => (int) data_get($this->resource, 'device_count', count($deviceIps)),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];

        if (!config('hidden_features.enable_exposed_user_count_fix')) {
            $data['user_id'] = (int) data_get($this->resource, 'user_id', 0);
        }

        return $data;
    }

    private function resolveDisplayAt(): int
    {
        foreach (['updated_at', 'created_at'] as $field) {
            $value = data_get($this->resource, $field);
            if ($value instanceof \DateTimeInterface) {
                return $value->getTimestamp();
            }

            if (is_numeric($value)) {
                return (int) $value;
            }

            if (is_string($value) && trim($value) !== '') {
                $timestamp = strtotime($value);
                if ($timestamp !== false) {
                    return $timestamp;
                }
            }
        }

        return (int) data_get($this->resource, 'record_at', 0);
    }
}
