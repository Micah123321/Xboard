<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ServerShadowsocksProbeService
{
    /** Each helper probes its pool concurrently; start the batch before waiting on any node. */
    public function probeServers(array $servers): array
    {
        $health = [];
        $running = [];
        $entries = app(ServerEntryPointService::class);
        $timeout = max(1, min(15, (int) config('entry_probe.timeout_seconds', 5)));
        foreach ($servers as $server) {
            $pool = $entries->candidates($server);
            $health[$server->id] = array_map(fn (array $entry): array => $entry + ['online' => false], $pool);
            try {
                $payload = $this->payload($server, $pool, $timeout);
                if ($payload === null || !$pool) {
                    continue;
                }
                // Credentials travel over stdin, never command arguments, files or logs.
                $running[$server->id] = Process::timeout((int) ceil(count($pool) / 8) * $timeout + 3)
                    ->input(json_encode($payload, JSON_THROW_ON_ERROR))
                    ->start([(string) config('entry_probe.binary')]);
            } catch (\Throwable) {
                $this->warn($server, 'helper_start_failed');
            }
        }
        foreach ($servers as $server) {
            if (!isset($running[$server->id])) {
                continue;
            }
            try {
                $result = $running[$server->id]->wait();
                if (!$result->successful()) {
                    $this->warn($server, 'helper_failed');
                    continue;
                }
                $decoded = json_decode($result->output(), true, 32, JSON_THROW_ON_ERROR);
                $results = $decoded['results'] ?? null;
                $expected = $health[$server->id];
                if (!is_array($results) || !array_is_list($results) || count($results) !== count($expected)) {
                    $this->warn($server, 'invalid_helper_response');
                    continue;
                }
                // Reject an incomplete or reordered response rather than applying health to the wrong pair.
                foreach ($expected as $index => $entry) {
                    $actual = $results[$index];
                    if (!is_array($actual) || ($actual['host'] ?? null) !== $entry['host']
                        || ($actual['port'] ?? null) !== $entry['port'] || !is_bool($actual['online'] ?? null)) {
                        throw new \UnexpectedValueException('Invalid entry health response.');
                    }
                }
                $health[$server->id] = $results;
            } catch (\Throwable) {
                $this->warn($server, 'helper_response_failed');
            }
        }
        return $health;
    }

    private function payload(Server $server, array $pool, int $timeout): ?array
    {
        $runtime = $server->parent_id ? $server->parent : $server;
        if (!$runtime || $runtime->type !== Server::TYPE_SHADOWSOCKS || !$runtime->enabled
            || data_get($runtime->protocol_settings, 'plugin') || data_get($server->protocol_settings, 'plugin')
            || data_get($runtime->protocol_settings, 'cipher') !== data_get($server->protocol_settings, 'cipher')) {
            $this->warn($server, 'incompatible_protocol');
            return null;
        }
        // Use a real runtime identity even when the child is auto-hidden; this also permits recovery.
        $users = ServerService::getAvailableUsers($runtime, false);
        $userId = (int) config('entry_probe.user_id', 0);
        $identity = $userId > 0 ? $users->first(fn ($user) => (int) data_get($user, 'id') === $userId)
            : ($users->first(fn ($user) => !(int) data_get($user, 'device_limit')) ?? $users->first());
        if (!$identity || !is_string(data_get($identity, 'uuid')) || data_get($identity, 'uuid') === '') {
            $this->warn($server, 'no_active_runtime_account');
            return null;
        }
        $user = (new User())->forceFill(['uuid' => data_get($identity, 'uuid')]);
        return [
            'method' => (string) data_get($runtime->protocol_settings, 'cipher'),
            'password' => $runtime->generateServerPassword($user),
            'entries' => $pool,
            'target_url' => (string) config('entry_probe.target_url'),
            'timeout_seconds' => $timeout,
        ];
    }

    private function warn(Server $server, string $reason): void
    {
        if (Cache::add('server_entry_probe_warning:' . $server->id . ':' . $reason, true, 60)) {
            // Process exception messages/output can contain sensitive input; log only a fixed reason.
            $message = match ($reason) {
                'no_active_runtime_account' => 'Shadowsocks entry probe requires an active parent-node account; configure a dedicated probe account. Pool excluded.',
                'incompatible_protocol' => 'Shadowsocks entry probe requires matching parent/child ciphers without plugins. Pool excluded.',
                default => 'Shadowsocks entry probe unavailable; pool excluded from subscriptions.',
            };
            Log::warning($message, [
                'server_id' => (int) $server->id, 'reason' => $reason,
            ]);
        }
    }
}
