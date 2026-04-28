<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;

class ServerParentVisibilityService
{
    public function syncChildrenForParent(Server $parent, bool $parentVisible): array
    {
        return $parentVisible
            ? $this->restoreChildrenForParent($parent)
            : $this->hideChildrenForParent($parent);
    }

    public function hideChildrenForParent(Server $parent): array
    {
        if (!$this->isParentNode($parent)) {
            return $this->emptyResult();
        }

        $result = $this->emptyResult();
        $now = time();

        $children = $this->childrenQuery($parent)
            ->where('show', true)
            ->get();

        foreach ($children as $child) {
            if (!$child instanceof Server) {
                continue;
            }

            $child->forceFill([
                'show' => false,
                'parent_auto_hidden' => true,
                'parent_auto_action_at' => $now,
            ])->save();
            $result['hidden']++;
        }

        return $result;
    }

    public function restoreChildrenForParent(Server $parent): array
    {
        if (!$this->isParentNode($parent)) {
            return $this->emptyResult();
        }

        $result = $this->emptyResult();
        $now = time();

        $children = $this->childrenQuery($parent)
            ->where('parent_auto_hidden', true)
            ->get();

        foreach ($children as $child) {
            if (!$child instanceof Server) {
                continue;
            }

            if ($this->hasBlockingAutoHide($child)) {
                $result['unchanged']++;
                continue;
            }

            $child->forceFill([
                'show' => true,
                'parent_auto_hidden' => false,
                'parent_auto_action_at' => $now,
            ])->save();
            $result['restored']++;
        }

        return $result;
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

    private function childrenQuery(Server $parent): Builder
    {
        return Server::query()->where('parent_id', (int) $parent->id);
    }

    private function hasBlockingAutoHide(Server $server): bool
    {
        return (bool) $server->gfw_auto_hidden;
    }

    private function emptyResult(): array
    {
        return [
            'hidden' => 0,
            'restored' => 0,
            'unchanged' => 0,
        ];
    }
}
