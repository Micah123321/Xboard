<?php

namespace App\Services;

use App\Models\Server;
use App\Models\StatServer;
use App\Utils\CacheKey;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ServerTrafficLimitService
{
    /**
     * Build the traffic limit block sent to mi-node.
     */
    public function buildNodeConfig(Server $server): array
    {
        $snapshot = $this->buildTrafficLimitSnapshot($server);
        $enabled = (bool) $snapshot['enabled'];

        return [
            'enabled' => $enabled,
            'limit' => (int) $snapshot['limit'],
            'reset_day' => $enabled ? $this->normalizeResetDay($server->traffic_limit_reset_day) : 0,
            'reset_time' => $enabled ? $this->normalizeResetTime($server->traffic_limit_reset_time) : null,
            'timezone' => $enabled ? $this->normalizeTimezone($server->traffic_limit_timezone) : null,
            'current_used' => (int) $snapshot['used'],
            'last_reset_at' => (int) $snapshot['last_reset_at'],
            'next_reset_at' => (int) $snapshot['next_reset_at'],
            'suspended_at' => (int) $snapshot['suspended_at'],
            'status' => (string) $snapshot['status'],
        ];
    }

    /**
     * Build traffic limit snapshots for an already loaded server list.
     */
    public function buildSnapshotsForServers(Collection $servers, ?int $referenceTimestamp = null): array
    {
        $serversByScope = $servers->groupBy(fn (Server $server) => $this->trafficLimitScopeKey($server));
        $snapshots = [];

        foreach ($servers as $server) {
            $scopeServers = $serversByScope->get($this->trafficLimitScopeKey($server), collect([$server]));
            $snapshots[(int) $server->id] = $this->buildTrafficLimitSnapshot(
                $server,
                $scopeServers,
                $referenceTimestamp
            );
        }

        return $snapshots;
    }

    /**
     * Build a panel-side snapshot using the shared traffic limit scope.
     */
    public function buildTrafficLimitSnapshot(
        Server $server,
        ?Collection $scopeServers = null,
        ?int $referenceTimestamp = null
    ): array {
        $enabled = $this->isEnabled($server);
        $scopeServers = $this->resolveTrafficLimitScopeServers($server, $scopeServers);
        $limit = $enabled ? (int) $server->transfer_enable : 0;
        $trafficLimit = $server->getKey() ? $this->cachedTrafficLimitMetrics($server) : null;
        $used = $enabled ? $this->currentUsed($server, $trafficLimit, $scopeServers, $referenceTimestamp) : 0;
        $reportedSuspension = $this->scopeReportedSuspension($server, $trafficLimit, $scopeServers, $limit);
        $suspended = $enabled && $limit > 0 && (
            $used >= $limit
            || $reportedSuspension['suspended']
        );
        $reference = $this->referenceTime($server, $referenceTimestamp);
        $cycleStartAt = $enabled ? $this->calculateCurrentCycleStartAt($server, $reference) : null;
        $cycleStartTimestamp = $cycleStartAt ? $cycleStartAt->timestamp : 0;
        $nextResetAt = $enabled
            ? ($server->traffic_limit_next_reset_at ?: $this->calculateNextResetAt($server, $reference)?->timestamp)
            : 0;
        $lastResetAt = max(
            (int) ($server->traffic_limit_last_reset_at ?? 0),
            $cycleStartTimestamp
        );

        return [
            'enabled' => $enabled,
            'limit' => $limit,
            'used' => $used,
            'percent' => $limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 0,
            'suspended' => $suspended,
            'last_reset_at' => $enabled ? $lastResetAt : 0,
            'cycle_start_at' => $enabled ? $cycleStartTimestamp : 0,
            'next_reset_at' => (int) ($nextResetAt ?? 0),
            'suspended_at' => $suspended
                ? (int) ($reportedSuspension['suspended_at'] ?: ($server->traffic_limit_suspended_at ?? time()))
                : 0,
            'status' => $suspended
                ? Server::TRAFFIC_LIMIT_STATUS_SUSPENDED
                : Server::TRAFFIC_LIMIT_STATUS_NORMAL,
            'scope_key' => $this->trafficLimitScopeKey($server),
            'scope_node_ids' => $scopeServers
                ->pluck('id')
                ->filter(fn ($id) => (int) $id > 0)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * Refresh persisted schedule fields after admin edits node limit settings.
     */
    public function refreshSchedule(Server $server, bool $notifyNode = true): void
    {
        $values = $this->scheduleValues($server);
        $server->forceFill($values)->saveQuietly();
        $freshServer = $server->refresh();
        $this->syncCachedRuntimeMetrics($freshServer);
        $this->syncParentChildrenFromTrafficLimit($freshServer);

        if ($notifyNode) {
            NodeSyncService::notifyConfigUpdated((int) $server->id);
        }
    }

    /**
     * Reset panel-side node traffic and notify mi-node to clear local limiter state.
     */
    public function resetServer(Server $server, bool $notifyNode = true): void
    {
        $now = time();
        $server->forceFill([
            'u' => 0,
            'd' => 0,
            'traffic_limit_status' => Server::TRAFFIC_LIMIT_STATUS_NORMAL,
            'traffic_limit_last_reset_at' => $now,
            'traffic_limit_next_reset_at' => $this->isEnabled($server)
                ? $this->calculateNextResetAt($server, Carbon::createFromTimestamp($now + 1, $this->normalizeTimezone($server->traffic_limit_timezone)))?->timestamp
                : null,
            'traffic_limit_suspended_at' => null,
        ])->saveQuietly();
        $freshServer = $server->refresh();
        $this->syncCachedRuntimeMetrics($freshServer);
        $this->syncParentChildrenFromTrafficLimit($freshServer);

        if ($notifyNode) {
            NodeSyncService::notifyFullSync((int) $server->id);
        }
    }

    /**
     * Reset all nodes whose configured reset time has arrived.
     */
    public function resetDueServers(): array
    {
        $now = time();
        $processed = 0;
        $reset = 0;
        $errors = [];

        Server::query()
            ->where('traffic_limit_enabled', true)
            ->where('transfer_enable', '>', 0)
            ->whereNotNull('traffic_limit_next_reset_at')
            ->where('traffic_limit_next_reset_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($servers) use (&$processed, &$reset, &$errors) {
                foreach ($servers as $server) {
                    $processed++;
                    try {
                        $this->resetServer($server);
                        $reset++;
                    } catch (\Throwable $e) {
                        $errors[] = [
                            'server_id' => $server->id,
                            'error' => $e->getMessage(),
                        ];
                        Log::error('节点流量限额重置失败', [
                            'server_id' => $server->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return [
            'processed' => $processed,
            'reset' => $reset,
            'errors' => $errors,
        ];
    }

    /**
     * Calculate the next monthly reset time from a reference time.
     */
    public function calculateNextResetAt(Server $server, ?Carbon $from = null): ?Carbon
    {
        if (!$this->isEnabled($server)) {
            return null;
        }

        $timezone = $this->normalizeTimezone($server->traffic_limit_timezone);
        $from = ($from ?: Carbon::now($timezone))->copy()->timezone($timezone);
        [$hour, $minute] = $this->parseResetTime($server->traffic_limit_reset_time);

        $target = $this->targetForMonth(
            $from->year,
            $from->month,
            $this->normalizeResetDay($server->traffic_limit_reset_day),
            $hour,
            $minute,
            $timezone
        );

        if ($target->timestamp > $from->timestamp) {
            return $target;
        }

        $nextMonth = $from->copy()->startOfMonth()->addMonthNoOverflow();
        return $this->targetForMonth(
            $nextMonth->year,
            $nextMonth->month,
            $this->normalizeResetDay($server->traffic_limit_reset_day),
            $hour,
            $minute,
            $timezone
        );
    }

    /**
     * Calculate the reset boundary that starts the current billing cycle.
     */
    public function calculateCurrentCycleStartAt(Server $server, ?Carbon $from = null): ?Carbon
    {
        if (!$this->isEnabled($server)) {
            return null;
        }

        $timezone = $this->normalizeTimezone($server->traffic_limit_timezone);
        $from = ($from ?: Carbon::now($timezone))->copy()->timezone($timezone);
        [$hour, $minute] = $this->parseResetTime($server->traffic_limit_reset_time);

        $currentMonthTarget = $this->targetForMonth(
            $from->year,
            $from->month,
            $this->normalizeResetDay($server->traffic_limit_reset_day),
            $hour,
            $minute,
            $timezone
        );

        if ($currentMonthTarget->timestamp <= $from->timestamp) {
            return $currentMonthTarget;
        }

        $previousMonth = $from->copy()->startOfMonth()->subMonthNoOverflow();
        return $this->targetForMonth(
            $previousMonth->year,
            $previousMonth->month,
            $this->normalizeResetDay($server->traffic_limit_reset_day),
            $hour,
            $minute,
            $timezone
        );
    }

    /**
     * Apply limiter metrics from mi-node to panel-side runtime fields.
     */
    public function applyRuntimeMetrics(Server $server, array $trafficLimit): array
    {
        if (empty($trafficLimit)) {
            return [];
        }

        $snapshot = $this->runtimeSnapshot($server, $trafficLimit);
        $suspended = (bool) $snapshot['suspended'];
        $server->forceFill([
            'traffic_limit_status' => $suspended
                ? Server::TRAFFIC_LIMIT_STATUS_SUSPENDED
                : Server::TRAFFIC_LIMIT_STATUS_NORMAL,
            'traffic_limit_last_reset_at' => $this->nullableTimestamp($snapshot['last_reset_at']),
            'traffic_limit_next_reset_at' => $this->nullableTimestamp($snapshot['next_reset_at']),
            'traffic_limit_suspended_at' => $this->nullableTimestamp($snapshot['suspended_at']),
        ]);

        if ($server->isDirty()) {
            $server->saveQuietly();
        }

        $this->syncParentChildrenFromTrafficLimit($server->refresh());

        return $snapshot;
    }

    private function scheduleValues(Server $server): array
    {
        if (!$this->isEnabled($server)) {
            return [
                'traffic_limit_enabled' => false,
                'traffic_limit_status' => null,
                'traffic_limit_next_reset_at' => null,
                'traffic_limit_suspended_at' => null,
            ];
        }

        $currentUsed = $this->currentUsed($server);
        $suspended = $this->isSuspendedByUsage($server, $currentUsed);

        return [
            'traffic_limit_enabled' => true,
            'traffic_limit_reset_day' => $this->normalizeResetDay($server->traffic_limit_reset_day),
            'traffic_limit_reset_time' => $this->normalizeResetTime($server->traffic_limit_reset_time),
            'traffic_limit_timezone' => $this->normalizeTimezone($server->traffic_limit_timezone),
            'traffic_limit_status' => $suspended
                ? Server::TRAFFIC_LIMIT_STATUS_SUSPENDED
                : Server::TRAFFIC_LIMIT_STATUS_NORMAL,
            'traffic_limit_next_reset_at' => $this->calculateNextResetAt($server)?->timestamp,
            'traffic_limit_suspended_at' => $suspended
                ? ($server->traffic_limit_suspended_at ?: time())
                : null,
        ];
    }

    private function isEnabled(Server $server): bool
    {
        return (bool) $server->traffic_limit_enabled && (int) $server->transfer_enable > 0;
    }

    private function isSuspendedByUsage(Server $server, ?int $used = null): bool
    {
        return $this->isEnabled($server)
            && ($used ?? $this->currentUsed($server)) >= (int) $server->transfer_enable;
    }

    private function syncParentChildrenFromTrafficLimit(Server $server): void
    {
        if ((int) ($server->parent_id ?? 0) > 0 || (int) ($server->id ?? 0) <= 0) {
            return;
        }

        $suspended = $server->traffic_limit_status === Server::TRAFFIC_LIMIT_STATUS_SUSPENDED
            || $this->isSuspendedByUsage($server);

        app(ServerParentVisibilityService::class)->syncChildrenForParent(
            $server,
            !$suspended && (bool) $server->show
        );
    }

    private function currentUsed(
        Server $server,
        ?array $trafficLimit = null,
        ?Collection $scopeServers = null,
        ?int $referenceTimestamp = null
    ): int
    {
        $scopeServers = $this->resolveTrafficLimitScopeServers($server, $scopeServers);
        $cycleUsed = $this->currentCycleUsed($server, $scopeServers, $referenceTimestamp);
        $panelUsed = $this->panelUsed($scopeServers);
        $reportedUsed = $this->scopeReportedUsed($server, $trafficLimit, $scopeServers);

        return max($cycleUsed ?? $panelUsed, $reportedUsed);
    }

    private function runtimeSnapshot(Server $server, array $trafficLimit): array
    {
        $enabled = $this->isEnabled($server);
        $limit = $enabled ? (int) $server->transfer_enable : 0;
        $used = $this->currentUsed($server, $trafficLimit);
        $reportedLimit = (int) ($trafficLimit['limit'] ?? 0);
        $snapshotCurrent = $this->isRuntimeSnapshotCurrent($server, $trafficLimit);
        $reportedSuspended = $snapshotCurrent && (bool) ($trafficLimit['suspended'] ?? false);
        $sameLimitSnapshot = $reportedLimit === 0 || $reportedLimit === $limit;
        $metricNextResetAt = (int) ($trafficLimit['next_reset_at'] ?? 0);
        $serverNextResetAt = (int) ($server->traffic_limit_next_reset_at ?? 0);
        $suspended = $enabled && (
            $used >= $limit
            || ($reportedSuspended && $sameLimitSnapshot)
        );

        return [
            'enabled' => $enabled,
            'limit' => $limit,
            'used' => $used,
            'suspended' => $suspended,
            'last_reset_at' => max(
                (int) ($server->traffic_limit_last_reset_at ?? 0),
                (int) ($trafficLimit['last_reset_at'] ?? 0)
            ),
            'next_reset_at' => $snapshotCurrent && $sameLimitSnapshot && $metricNextResetAt > 0
                ? $metricNextResetAt
                : $serverNextResetAt,
            'suspended_at' => $suspended
                ? (int) ($trafficLimit['suspended_at'] ?? $server->traffic_limit_suspended_at ?? time())
                : 0,
            'status' => $suspended
                ? Server::TRAFFIC_LIMIT_STATUS_SUSPENDED
                : Server::TRAFFIC_LIMIT_STATUS_NORMAL,
        ];
    }

    private function syncCachedRuntimeMetrics(Server $server): void
    {
        $metrics = Cache::get($this->metricsCacheKey($server));
        if (!is_array($metrics)) {
            return;
        }

        $trafficLimit = $this->runtimeSnapshot(
            $server,
            is_array($metrics['traffic_limit'] ?? null) ? $metrics['traffic_limit'] : []
        );

        $metrics['traffic_limit'] = $trafficLimit;
        Cache::put(
            $this->metricsCacheKey($server),
            $metrics,
            max(300, (int) admin_setting('server_push_interval', 60) * 3)
        );
    }

    private function cachedTrafficLimitMetrics(Server $server): ?array
    {
        $metrics = Cache::get($this->metricsCacheKey($server));
        return is_array($metrics) && is_array($metrics['traffic_limit'] ?? null)
            ? $metrics['traffic_limit']
            : null;
    }

    private function scopeReportedUsed(Server $server, ?array $trafficLimit, Collection $scopeServers): int
    {
        $used = 0;

        foreach ($scopeServers as $scopeServer) {
            $scopeTrafficLimit = $this->scopeTrafficLimitMetrics($server, $trafficLimit, $scopeServer);
            if (!is_array($scopeTrafficLimit) || !$this->isRuntimeSnapshotCurrent($scopeServer, $scopeTrafficLimit)) {
                continue;
            }

            $used = max($used, max(0, (int) ($scopeTrafficLimit['used'] ?? 0)));
        }

        return $used;
    }

    private function scopeReportedSuspension(
        Server $server,
        ?array $trafficLimit,
        Collection $scopeServers,
        int $limit
    ): array {
        foreach ($scopeServers as $scopeServer) {
            $scopeTrafficLimit = $this->scopeTrafficLimitMetrics($server, $trafficLimit, $scopeServer);
            if (!is_array($scopeTrafficLimit) || !$this->isRuntimeSnapshotCurrent($scopeServer, $scopeTrafficLimit)) {
                continue;
            }

            $reportedLimit = (int) ($scopeTrafficLimit['limit'] ?? 0);
            $sameLimitSnapshot = $reportedLimit === 0 || $reportedLimit === $limit;
            if ($sameLimitSnapshot && (bool) ($scopeTrafficLimit['suspended'] ?? false)) {
                return [
                    'suspended' => true,
                    'suspended_at' => (int) ($scopeTrafficLimit['suspended_at'] ?? 0),
                ];
            }
        }

        return [
            'suspended' => false,
            'suspended_at' => 0,
        ];
    }

    private function scopeTrafficLimitMetrics(
        Server $server,
        ?array $trafficLimit,
        Server $scopeServer
    ): ?array {
        if (!$scopeServer->getKey()) {
            return null;
        }

        if ((int) $scopeServer->getKey() === (int) $server->getKey()) {
            return $trafficLimit ?? $this->cachedTrafficLimitMetrics($scopeServer);
        }

        return $this->cachedTrafficLimitMetrics($scopeServer);
    }

    private function isRuntimeSnapshotCurrent(Server $server, array $trafficLimit): bool
    {
        $serverLastResetAt = (int) ($server->traffic_limit_last_reset_at ?? 0);
        $snapshotLastResetAt = (int) ($trafficLimit['last_reset_at'] ?? 0);

        return $serverLastResetAt <= 0
            || ($snapshotLastResetAt > 0 && $snapshotLastResetAt >= $serverLastResetAt);
    }

    private function metricsCacheKey(Server $server): string
    {
        $serverId = $server->parent_id ?: $server->id;
        return CacheKey::get('SERVER_' . strtoupper((string) $server->type) . '_METRICS', $serverId);
    }

    private function resolveTrafficLimitScopeServers(Server $server, ?Collection $scopeServers = null): Collection
    {
        if ($scopeServers instanceof Collection && $scopeServers->isNotEmpty()) {
            return $scopeServers->values();
        }

        if (!$server->exists || !$server->getKey()) {
            return collect([$server]);
        }

        $machineId = (int) ($server->machine_id ?? 0);
        if ($machineId > 0) {
            return Server::query()
                ->where('machine_id', $machineId)
                ->get();
        }

        $host = $this->normalizeHost($server->host);
        if ($host === '') {
            return collect([$server]);
        }

        return Server::query()
            ->whereRaw('LOWER(TRIM(host)) = ?', [$host])
            ->get();
    }

    private function currentCycleUsed(
        Server $server,
        Collection $scopeServers,
        ?int $referenceTimestamp = null
    ): ?int {
        $serverIds = $scopeServers
            ->pluck('id')
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($serverIds->isEmpty()) {
            return null;
        }

        $reference = $this->referenceTime($server, $referenceTimestamp);
        $cycleStartAt = $this->calculateCurrentCycleStartAt($server, $reference);
        if (!$cycleStartAt) {
            return 0;
        }

        // v2_stat_server is stored at day granularity, so include the reset day.
        $startAt = $cycleStartAt->copy()->startOfDay()->timestamp;
        $endAt = $reference->copy()->addDay()->startOfDay()->timestamp;
        $row = StatServer::query()
            ->selectRaw('COUNT(*) as records, COALESCE(SUM(u), 0) as upload, COALESCE(SUM(d), 0) as download')
            ->whereIn('server_id', $serverIds->all())
            ->where('record_type', 'd')
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->first();

        if ((int) ($row->records ?? 0) <= 0) {
            return null;
        }

        return max(0, (int) ($row->upload ?? 0) + (int) ($row->download ?? 0));
    }

    private function panelUsed(Collection $scopeServers): int
    {
        return max(0, (int) $scopeServers->sum(
            fn (Server $server) => max(0, (int) $server->u + (int) $server->d)
        ));
    }

    private function trafficLimitScopeKey(Server $server): string
    {
        $machineId = (int) ($server->machine_id ?? 0);
        if ($machineId > 0) {
            return 'machine:' . $machineId;
        }

        $host = $this->normalizeHost($server->host);
        if ($host !== '') {
            return 'host:' . $host;
        }

        return 'server:' . (int) ($server->id ?? 0);
    }

    private function normalizeHost(?string $host): string
    {
        return strtolower(trim((string) $host));
    }

    private function referenceTime(Server $server, ?int $referenceTimestamp = null): Carbon
    {
        $timezone = $this->normalizeTimezone($server->traffic_limit_timezone);

        return $referenceTimestamp !== null
            ? Carbon::createFromTimestamp($referenceTimestamp, $timezone)
            : Carbon::now($timezone);
    }

    private function normalizeResetDay($day): int
    {
        $normalized = (int) ($day ?: 1);
        return max(1, min(31, $normalized));
    }

    private function normalizeResetTime(?string $time): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time)
            ? (string) $time
            : '00:00';
    }

    private function normalizeTimezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return config('app.timezone', 'UTC');
        }

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : config('app.timezone', 'UTC');
    }

    private function parseResetTime(?string $time): array
    {
        [$hour, $minute] = explode(':', $this->normalizeResetTime($time));
        return [(int) $hour, (int) $minute];
    }

    private function targetForMonth(int $year, int $month, int $day, int $hour, int $minute, string $timezone): Carbon
    {
        $firstDay = Carbon::create($year, $month, 1, $hour, $minute, 0, $timezone);
        $targetDay = min($day, $firstDay->copy()->endOfMonth()->day);

        return Carbon::create($year, $month, $targetDay, $hour, $minute, 0, $timezone);
    }

    private function nullableTimestamp($value): ?int
    {
        $timestamp = (int) ($value ?? 0);
        return $timestamp > 0 ? $timestamp : null;
    }
}
