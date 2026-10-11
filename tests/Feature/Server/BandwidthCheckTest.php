<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerBandwidthHealth;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerBandwidthService;
use App\Services\ServerService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use ReflectionProperty;
use Tests\TestCase;

class BandwidthCheckTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshConfiguredDatabase;
    }

    public function refreshDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        $this->refreshConfiguredDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Cache::flush();
        Redis::shouldReceive('publish')->andReturn(0);
        $setting = new Setting();
        (new ReflectionProperty(Setting::class, 'cache'))->setValue($setting, Cache::store('array'));
        $this->app->instance(Setting::class, $setting);
        admin_setting(['server_token' => 'bandwidth-test-node-token']);
    }

    public function test_detection_is_opt_in_and_defaults_to_one_hour_and_eight_mbps(): void
    {
        $server = $this->server(['bandwidth_check_enabled' => false]);
        $this->getJson($this->url($server, 'config'))->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.interval', 3600)
            ->assertJsonPath('data.threshold_mbps', 8)
            ->assertJsonPath('data.probe_id', '');
        $server->update(['bandwidth_check_enabled' => true]);
        admin_setting(['server_bandwidth_check_interval' => 7200, 'server_bandwidth_check_threshold_mbps' => 12]);
        $this->getJson($this->url($server, 'config'))->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.interval', 7200)
            ->assertJsonPath('data.threshold_mbps', 12);
    }

    public function test_admin_can_save_opt_in_and_null_is_rejected_before_database_write(): void
    {
        $server = $this->server(['bandwidth_check_enabled' => false]);
        $admin = $this->user();
        $admin->update(['is_admin' => true]);
        $this->actingAs($admin, 'sanctum');
        $base = '/api/v2/' . hash('crc32b', config('app.key')) . '/server/manage/';
        $payload = ['id' => $server->id, 'type' => 'vmess', 'name' => 'bandwidth-node',
            'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443, 'rate' => 1,
            'bandwidth_check_enabled' => true, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp']];
        $this->postJson($base . 'save', $payload)->assertOk();
        $this->assertTrue($server->fresh()->bandwidth_check_enabled);
        $payload['bandwidth_check_enabled'] = null;
        $this->postJson($base . 'save', $payload)->assertStatus(422);
        $this->assertTrue($server->fresh()->bandwidth_check_enabled);
        $this->postJson($base . 'update', ['id' => $server->id, 'bandwidth_check_enabled' => false])->assertOk();
        $this->assertFalse($server->fresh()->bandwidth_check_enabled);
    }

    public function test_legacy_server_token_and_an_error_before_any_upload_are_supported(): void
    {
        $server = $this->server();
        $url = '/api/v2/server/bandwidth/config?' . http_build_query([
            'node_id' => $server->id, 'server_token' => 'bandwidth-test-node-token',
        ]);
        $config = $this->getJson($url)->assertOk()->json('data');
        $this->postJson($this->url($server, 'report'), [
            'probe_id' => $config['probe_id'], 'policy_revision' => $config['policy_revision'],
            'bytes_received' => 0, 'duration_ms' => 0, 'error' => 'warmup timed out',
        ])->assertOk()->assertJsonPath('data.suspended', false);
        $this->assertSame('unknown', ServerBandwidthHealth::firstOrFail()->status);
    }

    public function test_three_low_results_hide_node_and_heartbeat_cannot_restore_it(): void
    {
        $server = $this->server(['auto_online' => true]);
        ServerService::touchNode($server);
        $this->probe($server, 1)->assertJsonPath('data.block_service', false);
        $this->probe($server, 1)->assertJsonPath('data.block_service', false);
        $this->probe($server, 1)->assertJsonPath('data.block_service', true);
        $state = ServerBandwidthHealth::firstOrFail();
        $this->assertSame('suspended', $state->status);
        $this->assertSame(3, $state->low_count);
        $this->assertFalse($server->fresh()->show);
        ServerService::touchNode($server->fresh());
        $this->assertFalse($server->fresh()->show);
        $user = $this->user();
        $this->assertSame([], ServerService::getAvailableServers($user));
        $this->get('/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => 'general']))
            ->assertOk();
    }

    public function test_exact_threshold_passes_and_recovery_requires_three_successes(): void
    {
        $server = $this->server(['auto_online' => true]);
        ServerService::touchNode($server);
        for ($i = 0; $i < 3; $i++) {
            $this->probe($server, 1);
        }
        $this->probe($server, 8)->assertJsonPath('data.block_service', true);
        $this->probe($server, 8)->assertJsonPath('data.block_service', true);
        $this->probe($server, 8)->assertJsonPath('data.block_service', false);
        $this->assertTrue($server->fresh()->show);
    }

    public function test_a_result_just_below_threshold_is_not_rounded_into_a_success(): void
    {
        $server = $this->server();
        $this->probe($server, 7.9999);
        $this->assertSame('suspect', ServerBandwidthHealth::firstOrFail()->status);
    }

    public function test_probe_error_is_unknown_and_preserves_existing_suspension(): void
    {
        $server = $this->server();
        $this->probe($server, 1);
        $this->probe($server, 1);
        $this->probe($server, 0, 'request timeout')->assertJsonPath('data.suspended', false);
        $this->assertSame(0, ServerBandwidthHealth::firstOrFail()->low_count);
        for ($i = 0; $i < 3; $i++) {
            $this->probe($server, 1);
        }
        $this->probe($server, 0, 'panel error')->assertJsonPath('data.suspended', true);
        $state = ServerBandwidthHealth::firstOrFail();
        $this->assertSame('unknown', $state->status);
        $this->assertNull($state->mbps);
    }

    public function test_shared_machine_stops_only_opted_in_nodes_and_retains_management_access(): void
    {
        $machine = ServerMachine::create(['name' => 'probe-machine', 'token' => 'test-machine-token', 'is_active' => true]);
        $checked = $this->server(['machine_id' => $machine->id]);
        $unchecked = $this->server(['machine_id' => $machine->id, 'bandwidth_check_enabled' => false]);
        $config = $this->getJson($this->url($checked, 'config'))->assertOk()->json('data');
        $this->getJson($this->url($unchecked, 'config'))->assertOk()->assertJsonPath('data.probe_id', '');
        $this->submit($checked, $config, 1);
        $this->probe($checked, 1);
        $this->probe($checked, 1);
        $credentials = ['machine_id' => $machine->id, 'token' => $machine->token];
        $this->postJson('/api/v2/server/machine/nodes', $credentials)->assertOk()
            ->assertJsonCount(1, 'nodes')->assertJsonPath('nodes.0.id', $unchecked->id);
        $this->getJson('/api/v2/server/bandwidth/config?' . http_build_query($credentials))->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.suspended', true);
        $this->probe($checked, 8);
        $this->probe($checked, 8);
        $this->probe($checked, 8);
        $this->postJson('/api/v2/server/machine/nodes', $credentials)->assertOk()->assertJsonCount(2, 'nodes');
    }

    public function test_child_opt_in_uses_parent_agent_without_blocking_unchecked_parent(): void
    {
        $parent = $this->server(['bandwidth_check_enabled' => false]);
        $child = $this->server(['parent_id' => $parent->id, 'enabled' => null]);
        for ($i = 0; $i < 3; $i++) {
            $this->probe($parent, 1)->assertJsonPath('data.block_service', false);
        }
        $service = app(ServerBandwidthService::class);
        $this->assertTrue($service->isSuspended($child));
        $this->assertFalse($service->isSuspended($parent));
        $child->update(['bandwidth_check_enabled' => false]);
        $this->assertFalse(app(ServerBandwidthService::class)->isSuspended($child->fresh()));
        $this->getJson($this->url($parent, 'config'))->assertOk()->assertJsonPath('data.enabled', false);
    }

    public function test_duplicate_report_is_idempotent_and_unacknowledged_bytes_are_rejected(): void
    {
        $server = $this->server();
        $config = $this->getJson($this->url($server, 'config'))->json('data');
        $payload = ['probe_id' => $config['probe_id'], 'policy_revision' => $config['policy_revision'],
            'bytes_received' => 16384, 'duration_ms' => 131.072, 'error' => null];
        $this->postJson($this->url($server, 'report'), $payload)->assertStatus(422);
        $this->submit($server, $config, 1);
        $this->postJson($this->url($server, 'report'), $payload)->assertOk();
        $this->assertSame(1, ServerBandwidthHealth::firstOrFail()->low_count);
    }

    public function test_policy_or_opt_in_changes_invalidate_an_in_flight_probe(): void
    {
        $server = $this->server();
        $config = $this->getJson($this->url($server, 'config'))->json('data');
        admin_setting(['server_bandwidth_check_threshold_mbps' => 9]);
        $payload = ['probe_id' => $config['probe_id'], 'policy_revision' => $config['policy_revision'],
            'bytes_received' => 0, 'duration_ms' => 0, 'error' => 'timeout'];
        $this->postJson($this->url($server, 'report'), $payload)->assertStatus(409);
        $newConfig = $this->getJson($this->url($server, 'config'))->assertOk()->json('data');
        $this->assertNotSame($config['policy_revision'], $newConfig['policy_revision']);
        $server->update(['bandwidth_check_enabled' => false]);
        $payload['probe_id'] = $newConfig['probe_id'];
        $payload['policy_revision'] = $newConfig['policy_revision'];
        $this->postJson($this->url($server, 'report'), $payload)->assertStatus(409);
        $this->assertSame(0, ServerBandwidthHealth::firstOrFail()->low_count);
    }

    public function test_upload_requires_auth_and_enforces_chunk_budget(): void
    {
        $server = $this->server();
        $this->getJson('/api/v2/server/bandwidth/config?node_id=' . $server->id)->assertStatus(422);
        $config = $this->getJson($this->url($server, 'config'))->json('data');
        $this->call('POST', $this->url($server, 'upload') . '&probe_id=' . $config['probe_id'], [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => ServerBandwidthService::CHUNK_BYTES + 1],
            str_repeat('x', ServerBandwidthService::CHUNK_BYTES + 1))->assertStatus(413);
    }

    public function test_recovery_does_not_clear_manual_visibility_or_traffic_quota(): void
    {
        $server = $this->server(['show' => false, 'auto_online' => false, 'traffic_limit_enabled' => true,
            'transfer_enable' => 1024, 'u' => 1024, 'traffic_limit_reset_day' => 1, 'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC']);
        for ($i = 0; $i < 3; $i++) {
            $this->probe($server, 1);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->probe($server, 8);
        }
        $this->assertFalse($server->fresh()->show);
        $this->assertSame([], ServerService::getAvailableServers($this->user()));
    }

    private function probe(Server $server, float $mbps, ?string $error = null)
    {
        ServerBandwidthHealth::query()->update(['next_check_at' => 0]);
        $config = $this->getJson($this->url($server, 'config'))->assertOk()->json('data');
        return $this->submit($server, $config, $mbps, $error);
    }

    private function submit(Server $server, array $config, float $mbps, ?string $error = null)
    {
        $bytes = 16384;
        if ($error === null) {
            $this->call('POST', $this->url($server, 'upload') . '&probe_id=' . $config['probe_id'], [], [], [],
                ['CONTENT_TYPE' => 'application/octet-stream'], str_repeat('x', $bytes))->assertOk()
                ->assertJsonPath('data.bytes_received', $bytes);
        }
        return $this->postJson($this->url($server, 'report'), [
            'probe_id' => $config['probe_id'], 'policy_revision' => $config['policy_revision'],
            'bytes_received' => $error === null ? $bytes : 0,
            'duration_ms' => $mbps > 0 ? $bytes * 8 / $mbps / 1000 : 1, 'error' => $error,
        ])->assertOk();
    }

    private function url(Server $server, string $action): string
    {
        return '/api/v2/server/bandwidth/' . $action . '?' . http_build_query([
            'node_id' => $server->id, 'token' => 'bandwidth-test-node-token',
        ]);
    }

    private function server(array $attributes = []): Server
    {
        return Server::create(array_merge(['name' => 'bandwidth-node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443, 'rate' => 1,
            'group_ids' => [1], 'show' => true, 'enabled' => true, 'bandwidth_check_enabled' => true,
            'auto_online' => false, 'protocol_settings' => []], $attributes));
    }

    private function user(): User
    {
        return User::create(['email' => 'bandwidth@example.test', 'password' => bcrypt('secret'),
            'uuid' => '11111111-1111-4111-8111-111111111111', 'token' => 'bandwidth-user-token',
            'group_id' => 1, 'transfer_enable' => 1024, 'u' => 0, 'd' => 0,
            'banned' => false, 'expired_at' => time() + 3600]);
    }
}
