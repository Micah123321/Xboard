<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Models\ServerGfwCheck;
use App\Services\ServerAutoOnlineService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServerAutoOnlineServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_updates_only_nodes_with_auto_online_enabled(): void
    {
        $managedOffline = $this->makeServer([
            'name' => 'managed-offline',
            'show' => true,
            'auto_online' => true,
        ]);
        $managedOnline = $this->makeServer([
            'name' => 'managed-online',
            'show' => false,
            'auto_online' => true,
        ]);
        $manualOffline = $this->makeServer([
            'name' => 'manual-offline',
            'show' => true,
            'auto_online' => false,
        ]);

        $this->markNodeOnline($managedOnline);

        $result = app(ServerAutoOnlineService::class)->sync();

        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(1, $result['shown']);
        $this->assertSame(1, $result['hidden']);

        $this->assertFalse($managedOffline->fresh()->show);
        $this->assertTrue($managedOnline->fresh()->show);
        $this->assertTrue($manualOffline->fresh()->show);
    }

    public function test_sync_keeps_gfw_blocked_auto_online_node_hidden(): void
    {
        $managedOnline = $this->makeServer([
            'name' => 'managed-gfw-blocked',
            'show' => true,
            'auto_online' => true,
            'gfw_check_enabled' => true,
        ]);

        $this->markNodeOnline($managedOnline);
        ServerGfwCheck::create([
            'server_id' => $managedOnline->id,
            'status' => ServerGfwCheck::STATUS_BLOCKED,
            'checked_at' => time(),
        ]);

        $result = app(ServerAutoOnlineService::class)->sync();

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['shown']);
        $this->assertSame(1, $result['hidden']);
        $this->assertFalse($managedOnline->fresh()->show);
        $this->assertTrue($managedOnline->fresh()->gfw_auto_hidden);
    }

    public function test_sync_ignores_blocked_status_when_gfw_check_is_disabled(): void
    {
        $managedOnline = $this->makeServer([
            'name' => 'managed-gfw-disabled',
            'show' => false,
            'auto_online' => true,
            'gfw_check_enabled' => false,
        ]);

        $this->markNodeOnline($managedOnline);
        ServerGfwCheck::create([
            'server_id' => $managedOnline->id,
            'status' => ServerGfwCheck::STATUS_BLOCKED,
            'checked_at' => time(),
        ]);

        $result = app(ServerAutoOnlineService::class)->sync();

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['shown']);
        $this->assertTrue($managedOnline->fresh()->show);
    }

    public function test_sync_server_updates_single_auto_online_node(): void
    {
        $managedOnline = $this->makeServer([
            'name' => 'single-managed-online',
            'show' => false,
            'auto_online' => true,
        ]);

        $this->markNodeOnline($managedOnline);

        $result = app(ServerAutoOnlineService::class)->syncServer($managedOnline);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['shown']);
        $this->assertTrue($managedOnline->fresh()->show);
    }

    public function test_touch_node_syncs_auto_online_node_immediately(): void
    {
        $managedOnline = $this->makeServer([
            'name' => 'heartbeat-managed-online',
            'show' => false,
            'auto_online' => true,
        ]);

        ServerService::touchNode($managedOnline);

        $this->assertTrue($managedOnline->fresh()->show);
    }

    private function makeServer(array $attributes = []): Server
    {
        return Server::create(array_merge([
            'name' => 'test-node',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => false,
            'auto_online' => false,
            'gfw_check_enabled' => true,
            'gfw_auto_hidden' => false,
            'enabled' => true,
        ], $attributes));
    }

    private function markNodeOnline(Server $server): void
    {
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($server->type) . '_LAST_CHECK_AT', $server->id),
            time(),
            3600
        );
    }
}
