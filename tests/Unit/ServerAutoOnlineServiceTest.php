<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\ServerAutoOnlineService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        ServerService::touchNode($managedOnline);

        $result = app(ServerAutoOnlineService::class)->sync();

        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(1, $result['shown']);
        $this->assertSame(1, $result['hidden']);

        $this->assertFalse($managedOffline->fresh()->show);
        $this->assertTrue($managedOnline->fresh()->show);
        $this->assertTrue($manualOffline->fresh()->show);
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
            'enabled' => true,
        ], $attributes));
    }
}
