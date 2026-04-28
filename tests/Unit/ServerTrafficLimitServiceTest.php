<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\ServerTrafficLimitService;
use Carbon\Carbon;
use Tests\TestCase;

class ServerTrafficLimitServiceTest extends TestCase
{
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
}
