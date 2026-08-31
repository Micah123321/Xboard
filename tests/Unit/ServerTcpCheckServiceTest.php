<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\ServerAutoOnlineService;
use App\Services\ServerTcpCheckService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServerTcpCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_tcp_checked_child_does_not_fall_back_to_parent_runtime_cache(): void
    {
        $parent = $this->makeServer(['name' => 'online-parent']);
        $child = $this->makeServer([
            'name' => 'tcp-checked-child',
            'parent_id' => $parent->id,
            'auto_online' => true,
            'show' => true,
            'tcp_check_enabled' => true,
        ]);

        $this->putRuntimeCache($parent, 'LAST_CHECK_AT', time());

        $freshChild = $child->fresh();
        $this->assertSame(Server::STATUS_OFFLINE, $freshChild->available_status);

        app(ServerAutoOnlineService::class)->syncServer($freshChild);

        $this->assertFalse($child->fresh()->show);
    }

    public function test_sync_server_marks_child_online_when_tcp_port_connects(): void
    {
        [$socket, $port] = $this->openTcpServer();
        try {
            $child = $this->makeServer([
                'name' => 'reachable-child',
                'host' => '127.0.0.1',
                'server_port' => $port,
                'parent_id' => $this->makeServer(['name' => 'parent'])->id,
                'auto_online' => true,
                'show' => false,
                'tcp_check_enabled' => true,
            ]);

            $result = app(ServerTcpCheckService::class)->syncServer($child);

            $this->assertSame(1, $result['checked']);
            $this->assertSame(1, $result['online']);
            $this->assertTrue($child->fresh()->show);
            $this->assertSame(Server::STATUS_ONLINE_NO_PUSH, $child->fresh()->available_status);
            $this->assertNotNull($this->getRuntimeCache($child, 'LAST_CHECK_AT'));
        } finally {
            fclose($socket);
        }
    }

    public function test_sync_server_hides_child_when_tcp_port_is_unreachable(): void
    {
        $port = $this->unusedTcpPort();
        $child = $this->makeServer([
            'name' => 'unreachable-child',
            'host' => '127.0.0.1',
            'server_port' => $port,
            'parent_id' => $this->makeServer(['name' => 'parent'])->id,
            'auto_online' => true,
            'show' => true,
            'tcp_check_enabled' => true,
        ]);
        $this->putRuntimeCache($child, 'LAST_CHECK_AT', time());
        $this->putRuntimeCache($child, 'LAST_PUSH_AT', time());

        $result = app(ServerTcpCheckService::class)->syncServer($child);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['offline']);
        $this->assertFalse($child->fresh()->show);
        $this->assertNull($this->getRuntimeCache($child, 'LAST_CHECK_AT'));
        $this->assertNull($this->getRuntimeCache($child, 'LAST_PUSH_AT'));
        $this->assertSame(Server::STATUS_OFFLINE, $child->fresh()->available_status);
    }

    private function makeServer(array $attributes = []): Server
    {
        return Server::create(array_merge([
            'name' => 'test-node',
            'type' => Server::TYPE_MIERU,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => false,
            'auto_online' => false,
            'gfw_check_enabled' => true,
            'tcp_check_enabled' => false,
            'gfw_auto_hidden' => false,
            'parent_auto_hidden' => false,
            'enabled' => true,
        ], $attributes));
    }

    /** @return array{0: resource, 1: int} */
    private function openTcpServer(): array
    {
        $errno = 0;
        $errstr = '';
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($socket, $errstr);
        $name = stream_socket_get_name($socket, false);
        $this->assertIsString($name);
        $port = (int) substr(strrchr($name, ':'), 1);
        $this->assertGreaterThan(0, $port);

        return [$socket, $port];
    }

    private function unusedTcpPort(): int
    {
        [$socket, $port] = $this->openTcpServer();
        fclose($socket);

        return $port;
    }

    private function putRuntimeCache(Server $server, string $name, mixed $value): void
    {
        Cache::put(CacheKey::get('SERVER_' . strtoupper($server->type) . '_' . $name, $server->id), $value, 3600);
    }

    private function getRuntimeCache(Server $server, string $name): mixed
    {
        return Cache::get(CacheKey::get('SERVER_' . strtoupper($server->type) . '_' . $name, $server->id));
    }
}
