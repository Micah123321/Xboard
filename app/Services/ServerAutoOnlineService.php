<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerGfwCheck;

class ServerAutoOnlineService
{
    public function sync(): array
    {
        $servers = Server::query()
            ->where('auto_online', true)
            ->get();
        $gfwStatuses = app(ServerGfwCheckService::class)->getLatestStatusesForServers($servers);

        $result = [
            'total' => $servers->count(),
            'updated' => 0,
            'shown' => 0,
            'hidden' => 0,
            'unchanged' => 0,
        ];

        foreach ($servers as $server) {
            $sourceNodeId = (int) ($server->parent_id ?: $server->id);
            $gfwStatus = $gfwStatuses[$sourceNodeId] ?? null;
            $isGfwManaged = (bool) ($server->gfw_check_enabled ?? true) && $gfwStatus !== null;
            $isGfwBlocked = $isGfwManaged && $gfwStatus === ServerGfwCheck::STATUS_BLOCKED;
            $isGfwHeld = $isGfwManaged
                && (bool) $server->gfw_auto_hidden
                && $gfwStatus !== ServerGfwCheck::STATUS_NORMAL;
            $shouldShow = !$isGfwBlocked && !$isGfwHeld && (int) $server->available_status !== Server::STATUS_OFFLINE;
            $shouldClearGfwAutoHidden = $gfwStatus === ServerGfwCheck::STATUS_NORMAL
                && (bool) $server->gfw_auto_hidden;
            $wasShown = (bool) $server->show;

            if ($wasShown === $shouldShow && !$shouldClearGfwAutoHidden) {
                $result['unchanged']++;
                continue;
            }

            $server->show = $shouldShow;
            if ($isGfwBlocked) {
                $server->gfw_auto_hidden = true;
                $server->gfw_auto_action_at = time();
            } elseif ($shouldClearGfwAutoHidden) {
                $server->gfw_auto_hidden = false;
                $server->gfw_auto_action_at = time();
            }
            $server->save();

            $result['updated']++;
            if ($wasShown !== $shouldShow) {
                $shouldShow ? $result['shown']++ : $result['hidden']++;
            }
        }

        return $result;
    }
}
