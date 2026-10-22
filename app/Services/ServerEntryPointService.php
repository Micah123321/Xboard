<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class ServerEntryPointService
{
    public const MAX_EXTRA_ENTRIES = 20;
    public const HEALTH_TTL_SECONDS = 180;

    public static function normalizeHost(string $host): string
    {
        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')
            && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return inet_ntop(inet_pton($host));
        }
        return strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);
    }

    public static function validHost(string $host): bool
    {
        $host = self::normalizeHost($host);
        return $host !== '' && strlen($host) <= 253 && (
            filter_var($host, FILTER_VALIDATE_IP) !== false
            || (!preg_match('/^[0-9.]+$/', $host)
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false)
        );
    }

    public static function validPort(mixed $port): bool
    {
        return (is_string($port) || is_int($port))
            && preg_match('/^[0-9]+$/D', (string) $port)
            && (int) $port >= 1 && (int) $port <= 65535;
    }

    /** Return complete pairs so addresses and ports are never sampled independently. */
    public function candidates(Server $server): array
    {
        $entries = [['host' => $server->host, 'port' => $server->port]];
        if ($server->entry_points_enabled) {
            $entries = array_merge($entries, $server->entry_points ?? []);
        }
        $candidates = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['host'] ?? null)
                || !self::validHost($entry['host']) || !self::validPort($entry['port'] ?? null)) {
                continue;
            }
            $entry = ['host' => self::normalizeHost($entry['host']), 'port' => (int) $entry['port']];
            $candidates[$this->entryKey($entry)] = $entry;
        }
        return array_values($candidates);
    }

    public function selectForSubscription(Server $server): ?array
    {
        if (!$server->entry_points_enabled) {
            return ['host' => $server->host, 'port' => $server->port];
        }
        $candidates = $this->candidates($server);
        if ($server->tcp_check_enabled && (int) $server->parent_id > 0) {
            $health = Cache::get($this->healthCacheKey($server), []);
            $checkedAt = (int) ($health['checked_at'] ?? 0);
            // Unknown, expired and mismatched configurations must not revive failed entries.
            if ($checkedAt <= time() - self::HEALTH_TTL_SECONDS) {
                return null;
            }
            $online = array_fill_keys($health['online'] ?? [], true);
            $candidates = array_values(array_filter($candidates,
                fn (array $entry): bool => isset($online[$this->entryKey($entry)])));
        }
        return $candidates ? $candidates[random_int(0, count($candidates) - 1)] : null;
    }

    public function recordHealth(Server $server, array $results): void
    {
        $online = [];
        foreach ($results as $entry) {
            if (($entry['online'] ?? false) === true) {
                $online[] = $this->entryKey($entry);
            }
        }
        Cache::put($this->healthCacheKey($server), [
            'checked_at' => time(), 'online' => $online,
        ], self::HEALTH_TTL_SECONDS);
    }

    private function entryKey(array $entry): string
    {
        return json_encode([self::normalizeHost($entry['host']), (int) $entry['port']]);
    }

    private function healthCacheKey(Server $server): string
    {
        $configuration = [
            (int) $server->parent_id, (bool) $server->entry_points_enabled, $this->candidates($server),
        ];
        if ($server->type === Server::TYPE_SHADOWSOCKS) {
            $configuration[] = ['ss-https-v1', $server->protocol_settings, $server->parent?->type,
                $server->parent?->protocol_settings, $server->parent?->created_at, config('entry_probe')];
        }
        $fingerprint = hash('sha256', json_encode($configuration));
        return 'server_entry_health:' . $server->id . ':' . $fingerprint;
    }
}
