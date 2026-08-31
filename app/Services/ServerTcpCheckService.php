<?php

namespace App\Services;

use App\Models\Server;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class ServerTcpCheckService
{
    private const CONNECT_TIMEOUT_SECONDS = 2.0;

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

        foreach ($servers as $server) {
            $this->checkServer($server, $result);
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

        $this->checkServer($server, $result);

        return $result;
    }

    private function checkServer(Server $server, array &$result): void
    {
        $result['checked']++;
        $online = $this->isReachable((string) $server->host, (int) $server->server_port);

        if ($online) {
            ServerService::touchNode($server);
            $result['online']++;
            return;
        }

        $this->forgetOwnHeartbeat($server);
        if ((bool) $server->auto_online) {
            app(ServerAutoOnlineService::class)->syncServer($server->fresh() ?? $server);
        }
        $result['offline']++;
    }

    private function isReachable(string $host, int $port): bool
    {
        $address = $this->tcpAddress($host, $port);
        if ($address === null) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($address, $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket);
        return true;
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
