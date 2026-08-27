<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\Server;
use App\Models\ServerGfwCheck;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StatControllerGfwStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_get_stats_includes_current_gfw_distribution_and_recent_recoveries(): void
    {
        $now = time();
        $blockedVmess = $this->makeServer(['name' => 'blocked-vmess', 'type' => Server::TYPE_VMESS]);
        $blockedTrojan = $this->makeServer(['name' => 'blocked-trojan', 'type' => Server::TYPE_TROJAN]);
        $recentRecovered = $this->makeServer(['name' => 'recent-recovered', 'type' => Server::TYPE_VLESS]);
        $oldRecovered = $this->makeServer(['name' => 'old-recovered', 'type' => Server::TYPE_HYSTERIA]);
        $disabledBlocked = $this->makeServer([
            'name' => 'disabled-blocked',
            'type' => Server::TYPE_TUIC,
            'gfw_check_enabled' => false,
        ]);

        $this->makeCheck($blockedVmess, ServerGfwCheck::STATUS_NORMAL, $now - 7200);
        $this->makeCheck($blockedVmess, ServerGfwCheck::STATUS_BLOCKED, $now - 1800);
        $this->makeCheck($blockedTrojan, ServerGfwCheck::STATUS_BLOCKED, $now - 900);
        $this->makeCheck($recentRecovered, ServerGfwCheck::STATUS_BLOCKED, $now - 3600);
        $this->makeCheck($recentRecovered, ServerGfwCheck::STATUS_NORMAL, $now - 600);
        $this->makeCheck($oldRecovered, ServerGfwCheck::STATUS_BLOCKED, $now - 172800);
        $this->makeCheck($oldRecovered, ServerGfwCheck::STATUS_NORMAL, $now - 90000);
        $this->makeCheck($disabledBlocked, ServerGfwCheck::STATUS_BLOCKED, $now - 300);

        $controller = (new \ReflectionClass(StatController::class))->newInstanceWithoutConstructor();
        $stats = $controller->getStats()['data']['nodeGfwStats'];
        $distribution = collect($stats['blockedProtocolDistribution'])->keyBy('type');

        $this->assertSame(2, $stats['blockedNodes']);
        $this->assertSame(1, $stats['recentRecoveredNodes']);
        $this->assertSame(86400, $stats['recoveryWindowSeconds']);
        $this->assertSame(1, $distribution->get(Server::TYPE_VMESS)['count']);
        $this->assertSame(1, $distribution->get(Server::TYPE_TROJAN)['count']);
        $this->assertFalse($distribution->has(Server::TYPE_TUIC));
    }

    public function test_get_stats_counts_online_nodes_from_batched_runtime_cache(): void
    {
        $now = time();
        $parent = $this->makeServer(['name' => 'online-parent', 'type' => Server::TYPE_VMESS]);
        $this->makeServer([
            'name' => 'inherited-online-child',
            'type' => Server::TYPE_VLESS,
            'parent_id' => $parent->id,
        ]);
        $offline = $this->makeServer(['name' => 'offline-node', 'type' => Server::TYPE_TROJAN]);

        Cache::put(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $parent->id), $now, 60);
        Cache::put(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $offline->id), $now - Server::CHECK_INTERVAL - 1, 60);

        $controller = (new \ReflectionClass(StatController::class))->newInstanceWithoutConstructor();
        $stats = $controller->getStats()['data'];

        $this->assertSame(2, $stats['onlineNodes']);
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
            'show' => true,
            'auto_online' => false,
            'gfw_check_enabled' => true,
            'gfw_auto_hidden' => false,
            'parent_auto_hidden' => false,
            'enabled' => true,
        ], $attributes));
    }

    private function makeCheck(Server $server, string $status, int $checkedAt): ServerGfwCheck
    {
        return ServerGfwCheck::create([
            'server_id' => $server->id,
            'status' => $status,
            'checked_at' => $checkedAt,
        ]);
    }
}
