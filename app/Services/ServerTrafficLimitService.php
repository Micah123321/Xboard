<?php

namespace App\Services;

use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ServerTrafficLimitService
{
    /**
     * Build the traffic limit block sent to mi-node.
     */
    public function buildNodeConfig(Server $server): array
    {
        $enabled = $this->isEnabled($server);
        $nextResetAt = $enabled
            ? ($server->traffic_limit_next_reset_at ?: $this->calculateNextResetAt($server)?->timestamp)
            : null;

        return [
            'enabled' => $enabled,
            'limit' => $enabled ? (int) $server->transfer_enable : 0,
            'reset_day' => $enabled ? $this->normalizeResetDay($server->traffic_limit_reset_day) : 0,
            'reset_time' => $enabled ? $this->normalizeResetTime($server->traffic_limit_reset_time) : null,
            'timezone' => $enabled ? $this->normalizeTimezone($server->traffic_limit_timezone) : null,
            'current_used' => max(0, (int) $server->u + (int) $server->d),
            'last_reset_at' => (int) ($server->traffic_limit_last_reset_at ?? 0),
            'next_reset_at' => (int) ($nextResetAt ?? 0),
            'suspended_at' => (int) ($server->traffic_limit_suspended_at ?? 0),
            'status' => $server->traffic_limit_status ?: Server::TRAFFIC_LIMIT_STATUS_NORMAL,
        ];
    }

    /**
     * Refresh persisted schedule fields after admin edits node limit settings.
     */
    public function refreshSchedule(Server $server, bool $notifyNode = true): void
    {
        $values = $this->scheduleValues($server);
        $server->forceFill($values)->saveQuietly();

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
     * Apply limiter metrics from mi-node to panel-side runtime fields.
     */
    public function applyRuntimeMetrics(Server $server, array $trafficLimit): void
    {
        if (empty($trafficLimit)) {
            return;
        }

        $suspended = (bool) ($trafficLimit['suspended'] ?? false);
        $server->forceFill([
            'traffic_limit_status' => $suspended
                ? Server::TRAFFIC_LIMIT_STATUS_SUSPENDED
                : Server::TRAFFIC_LIMIT_STATUS_NORMAL,
            'traffic_limit_last_reset_at' => $this->nullableTimestamp($trafficLimit['last_reset_at'] ?? null),
            'traffic_limit_next_reset_at' => $this->nullableTimestamp($trafficLimit['next_reset_at'] ?? null),
            'traffic_limit_suspended_at' => $suspended
                ? $this->nullableTimestamp($trafficLimit['suspended_at'] ?? null)
                : null,
        ]);

        if ($server->isDirty()) {
            $server->saveQuietly();
        }
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

        return [
            'traffic_limit_enabled' => true,
            'traffic_limit_reset_day' => $this->normalizeResetDay($server->traffic_limit_reset_day),
            'traffic_limit_reset_time' => $this->normalizeResetTime($server->traffic_limit_reset_time),
            'traffic_limit_timezone' => $this->normalizeTimezone($server->traffic_limit_timezone),
            'traffic_limit_status' => $server->traffic_limit_status ?: Server::TRAFFIC_LIMIT_STATUS_NORMAL,
            'traffic_limit_next_reset_at' => $this->calculateNextResetAt($server)?->timestamp,
        ];
    }

    private function isEnabled(Server $server): bool
    {
        return (bool) $server->traffic_limit_enabled && (int) $server->transfer_enable > 0;
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
