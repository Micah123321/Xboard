<?php

namespace App\Observers;

use App\Models\Server;
use App\Models\ServerRoute;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\Cache;

class ServerRouteObserver
{
    public function created(ServerRoute $route): void
    {
        $this->forgetFetchCache();
    }

    public function updated(ServerRoute $route): void
    {
        $this->forgetFetchCache();
        $this->notifyAffectedNodes($route->id);
    }

    public function deleted(ServerRoute $route): void
    {
        $this->forgetFetchCache();
        $this->notifyAffectedNodes($route->id);
    }

    private function forgetFetchCache(): void
    {
        try {
            Cache::forget(ServerRoute::FETCH_CACHE_KEY);
        } catch (\Throwable) {
        }
    }

    private function notifyAffectedNodes(int $routeId): void
    {
        $servers = Server::where('show', 1)->get()->filter(
            fn ($s) => in_array($routeId, $s->route_ids ?? [])
        );

        foreach ($servers as $server) {
            NodeSyncService::notifyConfigUpdated($server->id);
        }
    }
}
