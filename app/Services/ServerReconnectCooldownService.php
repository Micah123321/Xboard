<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class ServerReconnectCooldownService
{
    private const WINDOW_SECONDS = 3600;
    private const COOLDOWN_SECONDS = 21600;
    private const TRANSITION_LIMIT = 10;
    private const CACHE_TTL_SECONDS = self::COOLDOWN_SECONDS + self::WINDOW_SECONDS;

    public function record(Server $server): ?int
    {
        if (!$this->isEnabled($server)) {
            $this->clear($server);
            return null;
        }

        $now = time();
        $suspendedUntil = $this->suspendedUntil($server, $now);
        if ($suspendedUntil !== null) {
            return $suspendedUntil;
        }

        $currentStatus = $this->statusKey((int) $server->available_status);
        $lastStatus = Cache::get($this->lastStatusKey($server));
        Cache::put($this->lastStatusKey($server), $currentStatus, self::CACHE_TTL_SECONDS);

        if ($lastStatus === null || $lastStatus === $currentStatus) {
            return null;
        }

        $transitions = $this->recentTransitions($server, $now);
        $transitions[] = $now;
        Cache::put($this->transitionsKey($server), $transitions, self::CACHE_TTL_SECONDS);

        if (count($transitions) <= self::TRANSITION_LIMIT) {
            return null;
        }

        return $this->suspend($server, $now);
    }

    public function isSuspended(Server $server, ?int $now = null): bool
    {
        if (!$this->isEnabled($server)) {
            return false;
        }

        return $this->suspendedUntil($server, $now ?? time()) !== null;
    }

    public function reset(Server $server): void
    {
        $this->clear($server);
    }

    private function isEnabled(Server $server): bool
    {
        return (bool) $server->auto_online
            && (bool) $server->auto_online_cooldown_enabled;
    }

    private function suspendedUntil(Server $server, int $now): ?int
    {
        $suspendedUntil = (int) Cache::get($this->suspendedUntilKey($server), 0);
        if ($suspendedUntil <= $now) {
            if ($suspendedUntil > 0) {
                Cache::forget($this->suspendedUntilKey($server));
            }
            return null;
        }

        return $suspendedUntil;
    }

    private function suspend(Server $server, int $now): int
    {
        $suspendedUntil = $now + self::COOLDOWN_SECONDS;
        Cache::put($this->suspendedUntilKey($server), $suspendedUntil, self::COOLDOWN_SECONDS);
        Cache::put($this->transitionsKey($server), [], self::CACHE_TTL_SECONDS);
        Cache::forget($this->lastStatusKey($server));

        return $suspendedUntil;
    }

    private function recentTransitions(Server $server, int $now): array
    {
        $transitions = Cache::get($this->transitionsKey($server), []);
        if (!is_array($transitions)) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', $transitions),
            fn (int $transitionAt) => $transitionAt > $now - self::WINDOW_SECONDS
        ));
    }

    private function clear(Server $server): void
    {
        Cache::forget($this->lastStatusKey($server));
        Cache::forget($this->transitionsKey($server));
        Cache::forget($this->suspendedUntilKey($server));
    }

    private function statusKey(int $availableStatus): string
    {
        return $availableStatus === Server::STATUS_OFFLINE ? 'offline' : 'online';
    }

    private function lastStatusKey(Server $server): string
    {
        return $this->prefix($server) . ':last_status';
    }

    private function transitionsKey(Server $server): string
    {
        return $this->prefix($server) . ':transitions';
    }

    private function suspendedUntilKey(Server $server): string
    {
        return $this->prefix($server) . ':suspended_until';
    }

    private function prefix(Server $server): string
    {
        return "server:{$server->id}:reconnect_cooldown";
    }
}
