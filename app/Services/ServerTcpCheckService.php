<?php

namespace App\Services;

use App\Models\Server;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class ServerTcpCheckService
{
    private const CONNECT_TIMEOUT_SECONDS = 2.0;
    private const NODES_PER_BATCH = 8;

    public function sync(?int $limit = null): array
    {
        $query = Server::query()
            ->where('tcp_check_enabled', true)
            ->whereNotNull('parent_id')
            ->where('parent_id', '>', 0)
            ->where('enabled', true)
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $servers = $query->get();
        $result = $this->emptyResult($servers->count());

        foreach ($servers->chunk(self::NODES_PER_BATCH) as $batch) {
            $this->checkServers($batch->all(), $result);
        }

        return $result;
    }

    public function syncServer(Server $server): array
    {
        $result = $this->emptyResult(1);

        if (!(bool) $server->tcp_check_enabled || (int) ($server->parent_id ?? 0) <= 0 || !(bool) $server->enabled) {
            $result['skipped']++;
            return $result;
        }

        $this->checkServers([$server], $result);

        return $result;
    }

    private function checkServers(array $servers, array &$result): void
    {
        $entries = app(ServerEntryPointService::class);
        $candidates = [];
        $slices = [];
        foreach ($servers as $server) {
            if ($server->entry_points_enabled) {
                $pool = $entries->candidates($server);
            } else {
                // Keep the existing probe target unless the entry pool is explicitly enabled.
                $port = (int) $server->server_port;
                $pool = [['host' => (string) $server->host, 'port' => (int) $port]];
            }
            $slices[] = [count($candidates), count($pool)];
            $candidates = array_merge($candidates, $pool);
        }
        $health = $this->probeEntries($candidates);
        foreach (array_values($servers) as $index => $server) {
            $nodeHealth = array_slice($health, $slices[$index][0], $slices[$index][1]);
            if ($server->entry_points_enabled) {
                $entries->recordHealth($server, $nodeHealth);
            }
            $result['checked']++;
            if (in_array(true, array_column($nodeHealth, 'online'), true)) {
                ServerService::touchNode($server);
                $result['online']++;
                continue;
            }
            $this->forgetOwnHeartbeat($server);
            if ((bool) $server->auto_online) {
                app(ServerAutoOnlineService::class)->syncServer($server->fresh() ?? $server);
            }
            $result['offline']++;
        }
    }

    /** Probe the pool concurrently so failed entries share one connection deadline. */
    protected function probeEntries(array $entries): array
    {
        $results = array_map(fn (array $entry): array => $entry + ['online' => false], $entries);
        $pending = [];
        try {
            foreach ($entries as $index => $entry) {
                $address = $this->tcpAddress($entry['host'], $entry['port']);
                if ($address === null) {
                    continue;
                }
                $errno = 0;
                $errstr = '';
                $socket = @stream_socket_client($address, $errno, $errstr,
                    self::CONNECT_TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                if (is_resource($socket)) {
                    stream_set_blocking($socket, false);
                    $pending[$index] = $socket;
                }
            }
            // DNS resolution during socket creation must not consume other entries' connect window.
            $deadline = microtime(true) + self::CONNECT_TIMEOUT_SECONDS;
            while ($pending && ($remaining = $deadline - microtime(true)) > 0) {
                $read = null;
                $write = array_values($pending);
                $except = array_values($pending);
                $seconds = (int) $remaining;
                $selected = @stream_select($read, $write, $except, $seconds,
                    (int) (($remaining - $seconds) * 1000000));
                if ($selected === false || $selected === 0) {
                    break;
                }
                foreach ($pending as $index => $socket) {
                    if (!in_array($socket, $write, true) && !in_array($socket, $except, true)) {
                        continue;
                    }
                    $results[$index]['online'] = !in_array($socket, $except, true)
                        && @stream_socket_get_name($socket, true) !== false;
                    fclose($socket);
                    unset($pending[$index]);
                }
            }
        } finally {
            foreach ($pending as $socket) {
                fclose($socket);
            }
        }
        return $results;
    }

    private function tcpAddress(string $host, int $port): ?string
    {
        $host = trim($host);
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $normalizedHost = trim($host, '[]');
        if (filter_var($normalizedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return "tcp://[{$normalizedHost}]:{$port}";
        }

        return "tcp://{$host}:{$port}";
    }

    private function forgetOwnHeartbeat(Server $server): void
    {
        foreach (['LAST_CHECK_AT', 'LAST_PUSH_AT'] as $name) {
            Cache::forget(CacheKey::get('SERVER_' . strtoupper((string) $server->type) . '_' . $name, (int) $server->id));
        }
    }

    private function emptyResult(int $total = 0): array
    {
        return [
            'total' => $total,
            'checked' => 0,
            'online' => 0,
            'offline' => 0,
            'skipped' => 0,
        ];
    }
}
