<?php

namespace App\Services;

use App\Models\Server;

class ServerAutoOnlineService
{
    public function sync(): array
    {
        $servers = Server::query()
            ->where('auto_online', true)
            ->get();

        $result = [
            'total' => $servers->count(),
            'updated' => 0,
            'shown' => 0,
            'hidden' => 0,
            'unchanged' => 0,
        ];

        foreach ($servers as $server) {
            $shouldShow = (int) $server->available_status !== Server::STATUS_OFFLINE;

            if ((bool) $server->show === $shouldShow) {
                $result['unchanged']++;
                continue;
            }

            $server->show = $shouldShow;
            $server->save();

            $result['updated']++;
            $shouldShow ? $result['shown']++ : $result['hidden']++;
        }

        return $result;
    }
}
