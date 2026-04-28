<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerGfwCheck;
use Illuminate\Support\Collection;

class ServerAutoOnlineService
{
    public function sync(): array
    {
        $servers = Server::query()
            ->where('auto_online', true)
            ->get();

        return $this->syncServers($servers);
    }

    public function syncServer(Server $server): array
    {
        if (!(bool) $server->auto_online) {
            return $this->emptyResult();
        }

        return $this->syncServers(collect([$server]));
    }

    private function syncServers(Collection $servers): array
    {
        $gfwStatuses = app(ServerGfwCheckService::class)->getLatestStatusesForServers($servers);
        $result = $this->emptyResult($servers->count());

        foreach ($servers as $server) {
            $this->syncServerWithStatuses($server, $gfwStatuses, $result);
        }

        return $result;
    }

    private function syncServerWithStatuses(Server $server, array $gfwStatuses, array &$result): void
    {
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
            $this->syncChildrenForFinalState($server, $shouldShow, $result);
            $result['unchanged']++;
            return;
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
        $this->syncChildrenForFinalState($server, $shouldShow, $result);
    }

    private function syncChildrenForFinalState(Server $server, bool $shouldShow, array &$result): void
    {
        $childResult = app(ServerParentVisibilityService::class)
            ->syncChildrenForParent($server, $shouldShow);
        $hidden = (int) ($childResult['hidden'] ?? 0);
        $restored = (int) ($childResult['restored'] ?? 0);
        $childUpdates = $hidden + $restored;

        if ($childUpdates <= 0) {
            return;
        }

        $result['updated'] += $childUpdates;
        $result['hidden'] += $hidden;
        $result['shown'] += $restored;
    }

    private function emptyResult(int $total = 0): array
    {
        return [
            'total' => $total,
            'updated' => 0,
            'shown' => 0,
            'hidden' => 0,
            'unchanged' => 0,
        ];
    }
}
