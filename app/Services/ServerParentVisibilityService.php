<?php

namespace App\Services;

use App\Models\Server;

class ServerParentVisibilityService
{
    public function releaseLegacyParentAutoHiddenChildren(Server $parent): array
    {
        if (!$this->isParentNode($parent)) {
            return ['restored' => 0];
        }

        $restored = 0;
        $now = time();
        $children = Server::query()
            ->where('parent_id', (int) $parent->id)
            ->where('parent_auto_hidden', true)
            ->get();

        foreach ($children as $child) {
            if (!$child instanceof Server) {
                continue;
            }

            $wasShown = (bool) $child->show;
            $child->forceFill([
                'parent_auto_hidden' => false,
                'parent_auto_action_at' => $now,
            ])->save();

            // 自动上线子节点不能被历史父节点联动直接强制显示。
            if ((bool) $child->auto_online) {
                app(ServerAutoOnlineService::class)->syncServer($child->fresh() ?? $child);
                if ((bool) ($child->fresh()?->show) && !$wasShown) {
                    $restored++;
                }
                continue;
            }

            if ($wasShown) {
                continue;
            }

            $child->forceFill([
                'show' => true,
                'parent_auto_action_at' => $now,
            ])->save();
            $restored++;
        }

        return ['restored' => $restored];
    }

    public function clearParentAutoHidden(Server $server): void
    {
        if (!(bool) $server->parent_auto_hidden && $server->parent_auto_action_at === null) {
            return;
        }

        $server->parent_auto_hidden = false;
        $server->parent_auto_action_at = null;
    }

    private function isParentNode(Server $server): bool
    {
        return (int) ($server->parent_id ?? 0) <= 0 && (int) ($server->id ?? 0) > 0;
    }
}
