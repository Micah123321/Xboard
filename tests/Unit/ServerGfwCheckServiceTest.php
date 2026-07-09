<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Models\ServerGfwCheck;
use App\Services\ServerGfwCheckService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServerGfwCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_automatic_checks_only_enqueues_enabled_parent_nodes_without_active_task(): void
    {
        $eligible = $this->makeServer(['name' => 'eligible-parent']);
        $zeroParent = $this->makeServer([
            'name' => 'zero-parent',
            'parent_id' => 0,
        ]);
        $active = $this->makeServer(['name' => 'active-parent']);
        $stale = $this->makeServer(['name' => 'stale-parent']);
        $this->makeServer([
            'name' => 'disabled-parent',
            'gfw_check_enabled' => false,
        ]);
        $this->makeServer([
            'name' => 'child-node',
            'parent_id' => $eligible->id,
        ]);

        ServerGfwCheck::create([
            'server_id' => $active->id,
            'status' => ServerGfwCheck::STATUS_PENDING,
        ]);
        $staleCheck = ServerGfwCheck::create([
            'server_id' => $stale->id,
            'status' => ServerGfwCheck::STATUS_PENDING,
        ]);
        $staleCheck->forceFill([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        $result = app(ServerGfwCheckService::class)->startAutomaticChecks();

        $this->assertSame(4, $result['total']);
        $this->assertSame(1, $result['active']);
        $this->assertSame(1, $result['expired']);
        $this->assertSame([$eligible->id, $zeroParent->id, $stale->id], array_column($result['started'], 'id'));
        $this->assertCount(1, $result['skipped']);
        $this->assertDatabaseHas('server_gfw_checks', [
            'server_id' => $eligible->id,
            'status' => ServerGfwCheck::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('server_gfw_checks', [
            'server_id' => $zeroParent->id,
            'status' => ServerGfwCheck::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('server_gfw_checks', [
            'id' => $staleCheck->id,
            'status' => ServerGfwCheck::STATUS_FAILED,
            'error_message' => '墙检测任务超时：节点端未领取或未上报结果',
        ]);
    }

    public function test_report_result_updates_only_source_node_visibility(): void
    {
        $parent = $this->makeServer([
            'name' => 'parent',
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
        $legacyGfwHiddenChild = $this->makeServer([
            'name' => 'legacy-gfw-hidden-child',
            'parent_id' => $parent->id,
            'show' => false,
            'gfw_auto_hidden' => true,
            'gfw_auto_action_at' => 123456,
        ]);
        $legacyParentHiddenChild = $this->makeServer([
            'name' => 'legacy-parent-hidden-child',
            'parent_id' => $parent->id,
            'show' => false,
            'parent_auto_hidden' => true,
            'parent_auto_action_at' => 123456,
        ]);

        $service = app(ServerGfwCheckService::class);
        $blockedCheck = ServerGfwCheck::create([
            'server_id' => $parent->id,
            'status' => ServerGfwCheck::STATUS_PENDING,
        ]);

        $this->assertTrue($service->reportResult($parent, [
            'check_id' => $blockedCheck->id,
            'operator_summary' => $this->blockedOperators(),
        ]));

        $this->assertFalse($parent->fresh()->show);
        $this->assertTrue($parent->fresh()->gfw_auto_hidden);
        $this->assertTrue($visibleChild->fresh()->show);
        $this->assertFalse($visibleChild->fresh()->gfw_auto_hidden);
        $this->assertFalse($manualHiddenChild->fresh()->show);
        $this->assertFalse($manualHiddenChild->fresh()->gfw_auto_hidden);
        $this->assertTrue($legacyGfwHiddenChild->fresh()->show);
        $this->assertFalse($legacyGfwHiddenChild->fresh()->gfw_auto_hidden);
        $this->assertTrue($legacyParentHiddenChild->fresh()->show);
        $this->assertFalse($legacyParentHiddenChild->fresh()->parent_auto_hidden);

        $normalCheck = ServerGfwCheck::create([
            'server_id' => $parent->id,
            'status' => ServerGfwCheck::STATUS_PENDING,
        ]);

        $this->assertTrue($service->reportResult($parent->fresh(), [
            'check_id' => $normalCheck->id,
            'operator_summary' => $this->normalOperators(),
        ]));

        $this->assertTrue($parent->fresh()->show);
        $this->assertFalse($parent->fresh()->gfw_auto_hidden);
        $this->assertTrue($visibleChild->fresh()->show);
        $this->assertFalse($visibleChild->fresh()->gfw_auto_hidden);
        $this->assertFalse($manualHiddenChild->fresh()->show);
        $this->assertFalse($manualHiddenChild->fresh()->gfw_auto_hidden);
        $this->assertTrue($legacyGfwHiddenChild->fresh()->show);
        $this->assertFalse($legacyGfwHiddenChild->fresh()->gfw_auto_hidden);
        $this->assertTrue($legacyParentHiddenChild->fresh()->show);
        $this->assertFalse($legacyParentHiddenChild->fresh()->parent_auto_hidden);
    }

    public function test_normal_report_does_not_force_show_offline_auto_online_node(): void
    {
        $server = $this->makeServer([
            'name' => 'offline-auto-online',
            'show' => false,
            'auto_online' => true,
            'gfw_auto_hidden' => true,
            'gfw_auto_action_at' => 123456,
        ]);

        $check = ServerGfwCheck::create([
            'server_id' => $server->id,
            'status' => ServerGfwCheck::STATUS_PENDING,
        ]);

        $this->assertTrue(app(ServerGfwCheckService::class)->reportResult($server, [
            'check_id' => $check->id,
            'operator_summary' => $this->normalOperators(),
        ]));

        $fresh = $server->fresh();
        $this->assertFalse($fresh->show);
        $this->assertFalse($fresh->gfw_auto_hidden);
        $this->assertSame(Server::STATUS_OFFLINE, $fresh->available_status);
    }

    public function test_normal_report_restores_online_auto_online_node(): void
    {
        $server = $this->makeServer([
            'name' => 'online-auto-online',
            'show' => false,
            'auto_online' => true,
            'gfw_auto_hidden' => true,
            'gfw_auto_action_at' => 123456,
        ]);

        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($server->type) . '_LAST_CHECK_AT', $server->id),
            time(),
            3600
        );

        $check = ServerGfwCheck::create([
            'server_id' => $server->id,
            'status' => ServerGfwCheck::STATUS_PENDING,
        ]);

        $this->assertTrue(app(ServerGfwCheckService::class)->reportResult($server, [
            'check_id' => $check->id,
            'operator_summary' => $this->normalOperators(),
        ]));

        $fresh = $server->fresh();
        $this->assertTrue($fresh->show);
        $this->assertFalse($fresh->gfw_auto_hidden);
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

    private function blockedOperators(): array
    {
        return [
            'ct' => ['total' => 2, 'success' => 0],
            'cu' => ['total' => 2, 'success' => 0],
            'cm' => ['total' => 2, 'success' => 0],
        ];
    }

    private function normalOperators(): array
    {
        return [
            'ct' => ['total' => 2, 'success' => 2],
            'cu' => ['total' => 2, 'success' => 2],
            'cm' => ['total' => 2, 'success' => 2],
        ];
    }
}
