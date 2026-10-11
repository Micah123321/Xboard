<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerBandwidthHealth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServerBandwidthService
{
    public const CHUNK_BYTES = 262144;
    public const MAX_BYTES = 10000000;
    public const PROBE_METHOD = 'cloudflare_download_v1';
    public const DOWNLOAD_URL = 'https://speed.cloudflare.com/__down?bytes=10000000';
    private const LEASE_SECONDS = 600;
    private const CONFIRMATIONS = 3;

    private ?Collection $nodes = null;
    private ?Collection $health = null;

    public function policy(): array
    {
        $policy = [
            'interval' => max(60, min(86400, (int) admin_setting('server_bandwidth_check_interval', 3600))),
            'threshold_mbps' => max(0.1, min(100, (float) admin_setting('server_bandwidth_check_threshold_mbps', 8))),
            'retry_interval' => 60,
            'recovery_interval' => 600,
            'probe_method' => self::PROBE_METHOD,
            'download_url' => self::DOWNLOAD_URL,
            'sample_seconds' => 8,
            'chunk_bytes' => self::CHUNK_BYTES,
            'max_bytes' => self::MAX_BYTES,
            'timeout_seconds' => 120,
        ];
        $policy['policy_revision'] = sha1(json_encode($policy));
        return $policy;
    }

    private function scopePolicy(string $scope): array
    {
        $policy = $this->policy();
        $policy['policy_revision'] = sha1(json_encode([
            $policy['policy_revision'], $scope, $this->memberIds($scope)->sort()->values()->all(),
        ]));
        return $policy;
    }

    private function nodes(): Collection
    {
        return $this->nodes ??= Server::query()->get([
            'id', 'parent_id', 'machine_id', 'bandwidth_check_enabled', 'enabled',
        ])->keyBy('id');
    }

    public function scopeKey(Server $server): string
    {
        $seen = [];
        $current = $server;
        while ($current) {
            if ((int) $current->machine_id > 0) {
                return 'machine:' . (int) $current->machine_id;
            }
            $id = (int) $current->id;
            if (isset($seen[$id]) || !(int) $current->parent_id) {
                return 'node:' . $id;
            }
            $seen[$id] = true;
            $parent = $this->nodes()->get((int) $current->parent_id);
            if (!$parent) {
                return 'node:' . $id;
            }
            $current = $parent;
        }
        return 'node:' . (int) $server->id;
    }

    private function memberIds(string $scope): Collection
    {
        return $this->nodes()->filter(fn (Server $node) => $node->bandwidth_check_enabled
            && $node->enabled !== false && $this->scopeKey($node) === $scope)->keys();
    }

    public function snapshot(Server $server): ?array
    {
        if (!$server->bandwidth_check_enabled) {
            return null;
        }
        if ($this->health === null) {
            $scopes = $this->nodes()->filter(fn ($node) => $node->bandwidth_check_enabled)
                ->map(fn ($node) => $this->scopeKey($node))->unique()->values();
            $this->health = ServerBandwidthHealth::whereIn('scope_key', $scopes)->get()->keyBy('scope_key');
        }
        $state = $this->health->get($this->scopeKey($server));
        // Upload samples include panel RTT and are invalid for the new download policy.
        if (!$state || $state->probe_method !== self::PROBE_METHOD) {
            return ['status' => 'unknown', 'suspended' => false, 'mbps' => null,
                'checked_at' => null, 'error' => null, 'low_count' => 0, 'success_count' => 0];
        }
        return $state->only(['status', 'suspended', 'mbps', 'checked_at', 'error', 'low_count', 'success_count']);
    }

    public function isSuspended(Server $server): bool
    {
        return (bool) ($this->snapshot($server)['suspended'] ?? false);
    }

    public function filterAvailable(Collection $servers): Collection
    {
        return $servers->filter(fn (Server $server) => !$this->isSuspended($server))->values();
    }

    public function decorateServers(Collection $servers): Collection
    {
        return $servers->each(function (Server $server) {
            $server->setAttribute('bandwidth_health', $this->snapshot($server));
        });
    }

    public function configuration(string $scope, ?Server $node = null): array
    {
        $policy = $this->scopePolicy($scope);
        $enabled = $this->memberIds($scope)->isNotEmpty();
        $response = $policy + ['enabled' => $enabled, 'suspended' => false,
            'block_service' => false, 'next_check_at' => 0, 'probe_id' => ''];
        if (!$enabled) {
            return $response;
        }
        // A persisted lease prevents different processes from testing one machine twice.
        ServerBandwidthHealth::firstOrCreate(['scope_key' => $scope]);
        $released = false;
        $response = DB::transaction(function () use ($scope, $node, $policy, $response, &$released) {
            $state = ServerBandwidthHealth::where('scope_key', $scope)->lockForUpdate()->firstOrFail();
            if ($state->probe_method !== self::PROBE_METHOD) {
                $released = $state->suspended;
                $state->probe_method = self::PROBE_METHOD;
                $state->suspended = false;
                $state->status = 'unknown';
                $state->mbps = $state->checked_at = $state->error = null;
                $state->last_probe_id = null;
                $state->policy_revision = null;
            }
            if ($state->policy_revision !== $policy['policy_revision']) {
                $state->policy_revision = $policy['policy_revision'];
                $state->low_count = $state->success_count = 0;
                $state->next_check_at = 0;
                $state->pending_probe_id = null;
                $state->probe_expires_at = 0;
            }
            if ($state->next_check_at <= time() && $state->probe_expires_at <= time()) {
                $probeId = (string) Str::uuid();
                Cache::put($this->ticketKey($probeId), [
                    'scope' => $scope, 'revision' => $policy['policy_revision'],
                    'method' => self::PROBE_METHOD,
                    'bytes' => 0, 'warmup_bytes' => 0,
                ], self::LEASE_SECONDS);
                $state->pending_probe_id = $probeId;
                $state->probe_expires_at = time() + self::LEASE_SECONDS;
                $response['probe_id'] = $probeId;
            }
            $state->save();
            $response['suspended'] = $state->suspended;
            $response['block_service'] = (bool) ($node?->bandwidth_check_enabled && $state->suspended);
            $response['next_check_at'] = $state->next_check_at;
            return $response;
        });
        $this->health = null;
        if ($released) {
            $this->syncScope($scope);
        }
        return $response;
    }

    private function ticketKey(string $probeId): string
    {
        return 'server_bandwidth:probe:' . $probeId;
    }

    private function ticket(string $scope, string $probeId): array
    {
        $ticket = Cache::get($this->ticketKey($probeId));
        $pending = ServerBandwidthHealth::where('scope_key', $scope)
            ->where('pending_probe_id', $probeId)->where('probe_expires_at', '>', time())->exists();
        abort_unless(is_array($ticket) && ($ticket['scope'] ?? null) === $scope && $pending, 409, 'Probe expired or not assigned');
        abort_unless($ticket['revision'] === $this->scopePolicy($scope)['policy_revision'], 409, 'Probe policy changed');
        return $ticket;
    }

    public function acknowledgeUpload(string $scope, string $probeId, int $bytes, bool $warmup): void
    {
        abort_unless($bytes > 0 && $bytes <= self::CHUNK_BYTES, 413, 'Invalid probe chunk size');
        Cache::lock($this->ticketKey($probeId) . ':lock', 10)->block(3, function () use ($scope, $probeId, $bytes, $warmup) {
            $ticket = $this->ticket($scope, $probeId);
            abort_if(($ticket['method'] ?? null) === self::PROBE_METHOD, 409, 'Probe requires a public download');
            $key = $warmup ? 'warmup_bytes' : 'bytes';
            $limit = $warmup ? self::CHUNK_BYTES : self::MAX_BYTES;
            abort_if($ticket[$key] + $bytes > $limit, 413, 'Probe byte budget exceeded');
            $ticket[$key] += $bytes;
            Cache::put($this->ticketKey($probeId), $ticket, self::LEASE_SECONDS);
        });
    }

    public function report(string $scope, array $result, ?Server $node = null): array
    {
        abort_unless(($result['probe_method'] ?? null) === self::PROBE_METHOD, 409, 'Probe method changed');
        $probeId = $result['probe_id'];
        $error = trim((string) ($result['error'] ?? ''));
        $policy = $this->policy();
        $changed = false;
        $state = DB::transaction(function () use ($scope, $probeId, $result, $policy, $error, &$changed) {
            $state = ServerBandwidthHealth::where('scope_key', $scope)->lockForUpdate()->firstOrFail();
            if ($state->last_probe_id === $probeId) {
                return $state;
            }
            $ticket = $this->ticket($scope, $probeId);
            abort_unless($result['policy_revision'] === $ticket['revision'], 409, 'Probe policy changed');
            if ($error === '' && ((float) $result['duration_ms'] <= 0
                || !is_finite((float) $result['duration_ms'])
                || (float) $result['duration_ms'] > $policy['timeout_seconds'] * 1000)) {
                throw ValidationException::withMessages(['duration_ms' => 'Successful measurement must fit the probe time budget']);
            }
            abort_unless(($ticket['method'] ?? null) === self::PROBE_METHOD && $state->probe_method === self::PROBE_METHOD, 409, 'Probe method changed');
            // The authenticated node measures the public stream; the panel never receives its body.
            if ($error === '' && ((int) $result['bytes_received'] < 16384
                || (int) $result['bytes_received'] > self::MAX_BYTES - self::CHUNK_BYTES)) {
                throw ValidationException::withMessages(['bytes_received' => 'Invalid public download sample size']);
            }
            abort_unless($state->pending_probe_id === $probeId && $state->probe_expires_at > time(), 409, 'Probe expired');
            $wasSuspended = $state->suspended;
            $state->checked_at = time();
            $state->last_probe_id = $probeId;
            $state->pending_probe_id = null;
            $state->probe_expires_at = 0;
            if ($error !== '') {
                $state->status = 'unknown';
                $state->mbps = null;
                $state->error = substr(preg_replace('/(token|password|key)=[^&\s]+/i', '$1=[redacted]', $error), 0, 512);
                $state->low_count = $state->success_count = 0;
            } else {
                $state->error = null;
                $mbps = (int) $result['bytes_received'] * 8 / (float) $result['duration_ms'] / 1000;
                $state->mbps = round($mbps, 3);
                if ($mbps < $policy['threshold_mbps']) {
                    $state->low_count = min(self::CONFIRMATIONS, $state->low_count + 1);
                    $state->success_count = 0;
                    $state->suspended = $state->suspended || $state->low_count >= self::CONFIRMATIONS;
                    $state->status = $state->suspended ? 'suspended' : 'suspect';
                } else {
                    $state->low_count = 0;
                    $state->success_count = min(self::CONFIRMATIONS, $state->success_count + 1);
                    if ($state->success_count >= self::CONFIRMATIONS) {
                        $state->suspended = false;
                    }
                    $state->status = $state->suspended ? 'suspended' : 'healthy';
                }
            }
            $retry = $state->suspended ? $policy['recovery_interval']
                : ($state->status === 'healthy' ? $policy['interval'] : $policy['retry_interval']);
            $state->next_check_at = time() + $retry;
            $state->save();
            $changed = $wasSuspended !== $state->suspended;
            return $state;
        });
        Cache::forget($this->ticketKey($probeId));
        $this->health = null;
        if ($changed) {
            $this->syncScope($scope);
        }
        return $this->verdict($state, $node);
    }

    private function syncScope(string $scope): void
    {
        $members = Server::whereIn('id', $this->memberIds($scope))->get();
        foreach ($members as $member) {
            app(ServerAutoOnlineService::class)->syncServer($member);
        }
        if (str_starts_with($scope, 'machine:')) {
            NodeSyncService::notifyMachineNodesChanged((int) substr($scope, 8));
        }
    }

    private function verdict(ServerBandwidthHealth $state, ?Server $node): array
    {
        return ['suspended' => $state->suspended,
            'block_service' => (bool) ($node?->bandwidth_check_enabled && $state->suspended),
            'next_check_at' => $state->next_check_at];
    }
}
