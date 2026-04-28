<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\StatServer;
use App\Services\ServerTrafficLimitService;
use App\Utils\CacheKey;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServerTrafficLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_calculate_next_reset_at_clamps_day_to_short_month(): void
    {
        $server = new Server([
            'traffic_limit_enabled' => true,
            'transfer_enable' => 100,
            'traffic_limit_reset_day' => 31,
            'traffic_limit_reset_time' => '03:30',
            'traffic_limit_timezone' => 'UTC',
        ]);

        $nextReset = app(ServerTrafficLimitService::class)->calculateNextResetAt(
            $server,
            Carbon::create(2026, 2, 1, 0, 0, 0, 'UTC')
        );

        $this->assertSame('2026-02-28 03:30:00', $nextReset?->format('Y-m-d H:i:s'));
    }

    public function test_build_node_config_uses_transfer_enable_and_panel_usage(): void
    {
        $server = new Server([
            'traffic_limit_enabled' => true,
            'transfer_enable' => 1024,
            'traffic_limit_reset_day' => 1,
            'traffic_limit_reset_time' => '04:00',
            'traffic_limit_timezone' => 'Asia/Shanghai',
            'traffic_limit_next_reset_at' => 1774977600,
            'u' => 400,
            'd' => 600,
        ]);

        $config = app(ServerTrafficLimitService::class)->buildNodeConfig($server);

        $this->assertTrue($config['enabled']);
        $this->assertSame(1024, $config['limit']);
        $this->assertSame(1000, $config['current_used']);
        $this->assertSame(1, $config['reset_day']);
        $this->assertSame('04:00', $config['reset_time']);
        $this->assertSame('Asia/Shanghai', $config['timezone']);
        $this->assertSame(1774977600, $config['next_reset_at']);
        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_NORMAL, $config['status']);
    }

    public function test_current_cycle_start_uses_previous_reset_boundary(): void
    {
        $server = new Server([
            'traffic_limit_enabled' => true,
            'transfer_enable' => 1024,
            'traffic_limit_reset_day' => 18,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
        ]);

        $service = app(ServerTrafficLimitService::class);

        $beforeCurrentMonthReset = $service->calculateCurrentCycleStartAt(
            $server,
            Carbon::create(2026, 4, 17, 12, 0, 0, 'UTC')
        );
        $afterCurrentMonthReset = $service->calculateCurrentCycleStartAt(
            $server,
            Carbon::create(2026, 4, 29, 12, 0, 0, 'UTC')
        );

        $this->assertSame('2026-03-18 00:00:00', $beforeCurrentMonthReset?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-18 00:00:00', $afterCurrentMonthReset?->format('Y-m-d H:i:s'));
    }

    public function test_traffic_limit_snapshot_shares_cycle_usage_by_host(): void
    {
        $reference = Carbon::create(2026, 4, 17, 12, 0, 0, 'UTC')->timestamp;
        $limit = 1000 * 1024 * 1024;
        $first = $this->makeServer([
            'name' => 'same-host-a',
            'host' => '82.40.33.225',
            'traffic_limit_enabled' => true,
            'transfer_enable' => $limit,
            'traffic_limit_reset_day' => 18,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
        ]);
        $second = $this->makeServer([
            'name' => 'same-host-b',
            'host' => '82.40.33.225',
            'traffic_limit_enabled' => true,
            'transfer_enable' => $limit,
            'traffic_limit_reset_day' => 18,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
        ]);
        $other = $this->makeServer([
            'name' => 'other-host',
            'host' => '203.0.113.10',
            'traffic_limit_enabled' => true,
            'transfer_enable' => $limit,
            'traffic_limit_reset_day' => 18,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
        ]);

        $this->makeServerStat($first, '2026-03-17', 900, 0);
        $this->makeServerStat($first, '2026-03-18', 100, 40);
        $this->makeServerStat($second, '2026-04-01', 200, 60);
        $this->makeServerStat($other, '2026-04-01', 500, 0);

        $snapshots = app(ServerTrafficLimitService::class)->buildSnapshotsForServers(
            collect([$first, $second, $other]),
            $reference
        );

        $this->assertSame(400, $snapshots[$first->id]['used']);
        $this->assertSame(400, $snapshots[$second->id]['used']);
        $this->assertSame(500, $snapshots[$other->id]['used']);
        $this->assertSame([$first->id, $second->id], $snapshots[$first->id]['scope_node_ids']);
        $this->assertSame('host:82.40.33.225', $snapshots[$first->id]['scope_key']);
    }

    public function test_traffic_limit_snapshot_prefers_machine_scope_over_host(): void
    {
        $reference = Carbon::create(2026, 4, 17, 12, 0, 0, 'UTC')->timestamp;
        $limit = 1000 * 1024 * 1024;
        $machine = ServerMachine::create([
            'name' => 'shared-machine',
            'token' => ServerMachine::generateToken(),
        ]);
        $first = $this->makeServer([
            'name' => 'machine-a',
            'host' => '198.51.100.1',
            'machine_id' => $machine->id,
            'traffic_limit_enabled' => true,
            'transfer_enable' => $limit,
            'traffic_limit_reset_day' => 18,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
        ]);
        $second = $this->makeServer([
            'name' => 'machine-b',
            'host' => '198.51.100.2',
            'machine_id' => $machine->id,
            'traffic_limit_enabled' => true,
            'transfer_enable' => $limit,
            'traffic_limit_reset_day' => 18,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
        ]);
        $sameHostWithoutMachine = $this->makeServer([
            'name' => 'same-host-without-machine',
            'host' => '198.51.100.1',
            'traffic_limit_enabled' => true,
            'transfer_enable' => $limit,
            'traffic_limit_reset_day' => 18,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
        ]);

        $this->makeServerStat($first, '2026-03-18', 100, 0);
        $this->makeServerStat($second, '2026-03-18', 200, 0);
        $this->makeServerStat($sameHostWithoutMachine, '2026-03-18', 500, 0);

        $snapshots = app(ServerTrafficLimitService::class)->buildSnapshotsForServers(
            collect([$first, $second, $sameHostWithoutMachine]),
            $reference
        );

        $this->assertSame(300, $snapshots[$first->id]['used']);
        $this->assertSame(300, $snapshots[$second->id]['used']);
        $this->assertSame(500, $snapshots[$sameHostWithoutMachine->id]['used']);
        $this->assertSame('machine:' . $machine->id, $snapshots[$first->id]['scope_key']);
    }

    public function test_build_node_config_preserves_current_runtime_suspended_state(): void
    {
        $server = $this->makeServer([
            'traffic_limit_enabled' => true,
            'transfer_enable' => 1024,
            'traffic_limit_reset_day' => 1,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
            'traffic_limit_status' => Server::TRAFFIC_LIMIT_STATUS_SUSPENDED,
            'traffic_limit_suspended_at' => 123456,
            'u' => 100,
            'd' => 100,
        ]);

        Cache::put($this->metricsCacheKey($server), [
            'traffic_limit' => [
                'enabled' => true,
                'limit' => 1024,
                'used' => 400,
                'suspended' => true,
                'last_reset_at' => 0,
                'next_reset_at' => Carbon::now('UTC')->addDay()->timestamp,
                'suspended_at' => 123456,
                'status' => Server::TRAFFIC_LIMIT_STATUS_SUSPENDED,
            ],
        ], 300);

        $config = app(ServerTrafficLimitService::class)->buildNodeConfig($server);

        $this->assertSame(400, $config['current_used']);
        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_SUSPENDED, $config['status']);
        $this->assertSame(123456, $config['suspended_at']);
    }

    public function test_traffic_limit_snapshot_shares_runtime_metrics_by_scope(): void
    {
        $first = $this->makeServer([
            'name' => 'runtime-a',
            'host' => '82.40.33.225',
            'traffic_limit_enabled' => true,
            'transfer_enable' => 1024,
            'traffic_limit_reset_day' => 1,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
        ]);
        $second = $this->makeServer([
            'name' => 'runtime-b',
            'host' => '82.40.33.225',
            'traffic_limit_enabled' => true,
            'transfer_enable' => 1024,
            'traffic_limit_reset_day' => 1,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
        ]);

        Cache::put($this->metricsCacheKey($first), [
            'traffic_limit' => [
                'enabled' => true,
                'limit' => 1024,
                'used' => 700,
                'suspended' => true,
                'last_reset_at' => 0,
                'next_reset_at' => Carbon::now('UTC')->addDay()->timestamp,
                'suspended_at' => 123456,
                'status' => Server::TRAFFIC_LIMIT_STATUS_SUSPENDED,
            ],
        ], 300);

        $snapshots = app(ServerTrafficLimitService::class)->buildSnapshotsForServers(collect([$first, $second]));

        $this->assertSame(700, $snapshots[$first->id]['used']);
        $this->assertSame(700, $snapshots[$second->id]['used']);
        $this->assertTrue($snapshots[$first->id]['suspended']);
        $this->assertTrue($snapshots[$second->id]['suspended']);
        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_SUSPENDED, $snapshots[$second->id]['status']);
    }

    public function test_refresh_schedule_resumes_suspended_node_when_limit_increases_above_usage(): void
    {
        $oneGiB = 1024 * 1024 * 1024;
        $fiveHundredGiB = 500 * $oneGiB;
        $nextResetAt = Carbon::now('UTC')->addDay()->timestamp;
        $server = $this->makeServer([
            'traffic_limit_enabled' => true,
            'transfer_enable' => $fiveHundredGiB,
            'traffic_limit_reset_day' => 1,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
            'traffic_limit_status' => Server::TRAFFIC_LIMIT_STATUS_SUSPENDED,
            'traffic_limit_next_reset_at' => $nextResetAt,
            'traffic_limit_suspended_at' => 123456,
            'u' => $oneGiB,
            'd' => 0,
        ]);

        Cache::put($this->metricsCacheKey($server), [
            'traffic_limit' => [
                'enabled' => true,
                'limit' => $oneGiB,
                'used' => $oneGiB,
                'suspended' => true,
                'last_reset_at' => 0,
                'next_reset_at' => $nextResetAt,
                'suspended_at' => 123456,
                'status' => Server::TRAFFIC_LIMIT_STATUS_SUSPENDED,
            ],
        ], 300);

        $service = app(ServerTrafficLimitService::class);
        $service->refreshSchedule($server, false);

        $fresh = $server->fresh();
        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_NORMAL, $fresh->traffic_limit_status);
        $this->assertNull($fresh->traffic_limit_suspended_at);

        $config = $service->buildNodeConfig($fresh);
        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_NORMAL, $config['status']);
        $this->assertSame(0, $config['suspended_at']);

        $metrics = Cache::get($this->metricsCacheKey($fresh));
        $this->assertFalse($metrics['traffic_limit']['suspended']);
        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_NORMAL, $metrics['traffic_limit']['status']);
        $this->assertSame($fiveHundredGiB, $metrics['traffic_limit']['limit']);
    }

    public function test_stale_metrics_from_old_limit_do_not_re_suspend_after_limit_increase(): void
    {
        $oneGiB = 1024 * 1024 * 1024;
        $server = $this->makeServer([
            'traffic_limit_enabled' => true,
            'transfer_enable' => 500 * $oneGiB,
            'traffic_limit_reset_day' => 1,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
            'traffic_limit_status' => Server::TRAFFIC_LIMIT_STATUS_NORMAL,
            'u' => $oneGiB,
            'd' => 0,
        ]);

        $snapshot = app(ServerTrafficLimitService::class)->applyRuntimeMetrics($server, [
            'enabled' => true,
            'limit' => $oneGiB,
            'used' => $oneGiB,
            'suspended' => true,
            'last_reset_at' => 0,
            'next_reset_at' => Carbon::now('UTC')->addDay()->timestamp,
            'suspended_at' => 123456,
            'status' => Server::TRAFFIC_LIMIT_STATUS_SUSPENDED,
        ]);

        $this->assertFalse($snapshot['suspended']);
        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_NORMAL, $snapshot['status']);
        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_NORMAL, $server->fresh()->traffic_limit_status);
        $this->assertNull($server->fresh()->traffic_limit_suspended_at);
    }

    public function test_traffic_limit_suspension_hides_and_reset_restores_only_auto_hidden_children(): void
    {
        $parent = $this->makeServer([
            'name' => 'limited-parent',
            'traffic_limit_enabled' => true,
            'transfer_enable' => 1000,
            'traffic_limit_reset_day' => 1,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
            'show' => true,
        ]);
        $visibleChild = $this->makeServer([
            'name' => 'visible-child',
            'parent_id' => $parent->id,
            'show' => true,
        ]);
        $manualHiddenChild = $this->makeServer([
            'name' => 'manual-hidden-child',
            'parent_id' => $parent->id,
            'show' => false,
        ]);

        $service = app(ServerTrafficLimitService::class);
        $service->applyRuntimeMetrics($parent, [
            'enabled' => true,
            'limit' => 1000,
            'used' => 1000,
            'suspended' => true,
            'last_reset_at' => 0,
            'next_reset_at' => Carbon::now('UTC')->addDay()->timestamp,
            'suspended_at' => 123456,
            'status' => Server::TRAFFIC_LIMIT_STATUS_SUSPENDED,
        ]);

        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_SUSPENDED, $parent->fresh()->traffic_limit_status);
        $this->assertFalse($visibleChild->fresh()->show);
        $this->assertTrue($visibleChild->fresh()->parent_auto_hidden);
        $this->assertFalse($manualHiddenChild->fresh()->show);
        $this->assertFalse($manualHiddenChild->fresh()->parent_auto_hidden);

        $service->resetServer($parent->fresh(), false);

        $this->assertSame(Server::TRAFFIC_LIMIT_STATUS_NORMAL, $parent->fresh()->traffic_limit_status);
        $this->assertTrue($visibleChild->fresh()->show);
        $this->assertFalse($visibleChild->fresh()->parent_auto_hidden);
        $this->assertFalse($manualHiddenChild->fresh()->show);
        $this->assertFalse($manualHiddenChild->fresh()->parent_auto_hidden);
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
            'u' => 0,
            'd' => 0,
        ], $attributes));
    }

    private function metricsCacheKey(Server $server): string
    {
        return CacheKey::get('SERVER_' . strtoupper($server->type) . '_METRICS', $server->id);
    }

    private function makeServerStat(Server $server, string $date, int $upload, int $download): StatServer
    {
        return StatServer::create([
            'server_id' => $server->id,
            'server_type' => $server->type,
            'record_type' => 'd',
            'record_at' => Carbon::parse($date, 'UTC')->startOfDay()->timestamp,
            'u' => $upload,
            'd' => $download,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
