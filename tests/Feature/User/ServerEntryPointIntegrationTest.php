<?php

namespace Tests\Feature\User;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerEntryPointService;
use App\Services\ServerService;
use App\Services\ServerTcpCheckService;
use App\Support\Setting;
use App\Utils\CacheKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use Tests\TestCase;

class ServerEntryPointIntegrationTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshDatabaseWithConfiguredConnection;
    }

    public function refreshDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        $this->refreshDatabaseWithConfiguredConnection();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        config()->set('cache.stores.redis', ['driver' => 'array']);
        Cache::purge('redis');
        config()->set('queue.default', 'sync');
        Cache::flush();
        $redis = \Mockery::mock();
        $redis->shouldReceive('publish')->andReturn(1);
        $redis->shouldReceive('sadd')->andReturn(1);
        \Illuminate\Support\Facades\Redis::swap($redis);
        $setting = new Setting();
        (new ReflectionProperty(Setting::class, 'cache'))->setValue($setting, Cache::store('array'));
        $this->app->instance(Setting::class, $setting);
        admin_setting(['show_info_to_server_enable' => false, 'show_protocol_to_server_enable' => false]);
        Model::unguard();
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    public function test_random_selection_keeps_pairs_and_never_changes_persisted_or_parent_config(): void
    {
        $user = $this->user();
        $parent = $this->node(['host' => 'parent.example', 'show' => false]);
        $child = $this->node(['parent_id' => $parent->id, 'entry_points_enabled' => true,
            'entry_points' => [['host' => 'other.example', 'port' => 2443], ['host' => '2001:db8::2', 'port' => 3443]]]);
        $this->heartbeat($parent);
        $pairs = ['primary.example:1443', 'other.example:2443', '2001:db8::2:3443'];
        for ($i = 0; $i < 48; $i++) {
            $servers = ServerService::getAvailableServers($user);
            self::assertCount(1, $servers);
            self::assertContains($servers[0]['host'] . ':' . $servers[0]['port'], $pairs);
            self::assertSame($child->id, $servers[0]['id']);
            self::assertSame(9443, $servers[0]['server_port']);
            self::assertSame(4.0, (float) $servers[0]['rate']);
            self::assertArrayNotHasKey('entry_points', $servers[0]);
        }
        self::assertSame('primary.example', $child->fresh()->host);
        self::assertSame('parent.example', $parent->fresh()->host);
        self::assertCount(2, $child->fresh()->entry_points);
    }

    public function test_disabled_pool_and_legacy_port_ranges_keep_existing_behavior(): void
    {
        $node = $this->node(['port' => '20000-20010', 'entry_points_enabled' => false,
            'entry_points' => [['host' => 'unused.example', 'port' => 443]]]);
        $this->heartbeat($node);
        $server = ServerService::getAvailableServers($this->user())[0];
        self::assertSame('primary.example', $server['host']);
        self::assertGreaterThanOrEqual(20000, $server['port']);
        self::assertLessThanOrEqual(20010, $server['port']);
        self::assertSame('20000-20010', $server['ports']);
    }

    public function test_tcp_pool_fails_over_on_connection_port_and_recovers_after_all_entries_fail(): void
    {
        [$socket, $port] = $this->openTcpServer();
        $unused = $this->unusedTcpPort();
        $user = $this->user();
        $parent = $this->node(['show' => false]);
        $node = $this->node(['parent_id' => $parent->id, 'host' => '127.0.0.1', 'port' => (string) $unused,
            'server_port' => $unused, 'auto_online' => true, 'tcp_check_enabled' => true,
            'entry_points_enabled' => true, 'entry_points' => [['host' => '127.0.0.1', 'port' => $port]]]);
        $service = app(ServerTcpCheckService::class);
        try {
            self::assertSame(1, $service->syncServer($node)['online']);
            for ($i = 0; $i < 8; $i++) {
                $servers = ServerService::getAvailableServers($user);
                self::assertCount(1, $servers);
                self::assertSame($port, $servers[0]['port']);
                self::assertSame($unused, $servers[0]['server_port']);
            }
            $response = $this->get('/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => 'general']))->assertOk();
            self::assertStringContainsString('127.0.0.1:' . $port, base64_decode($response->getContent(), true));
        } finally {
            fclose($socket);
        }
        self::assertSame(1, $service->syncServer($node->fresh())['offline']);
        self::assertFalse($node->fresh()->show);
        self::assertSame([], ServerService::getAvailableServers($user));
        [$recoverySocket, $recoveryPort] = $this->openTcpServer();
        try {
            $node->update(['entry_points' => [['host' => '127.0.0.1', 'port' => $recoveryPort]]]);
            self::assertSame(1, $service->syncServer($node->fresh())['online']);
            self::assertTrue($node->fresh()->show);
            self::assertSame($recoveryPort, ServerService::getAvailableServers($user)[0]['port']);
        } finally {
            fclose($recoverySocket);
        }
    }

    public function test_shadowsocks_pool_uses_forwarding_results_instead_of_open_tcp_ports(): void
    {
        $user = $this->user();
        [$mainSocket, $mainPort] = $this->openTcpServer();
        [$backupSocket, $backupPort] = $this->openTcpServer();
        $parent = $this->node(['show' => false, 'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $node = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'host' => '127.0.0.1', 'port' => $mainPort, 'tcp_check_enabled' => true,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'], 'entry_points_enabled' => true,
            'entry_points' => [['host' => '127.0.0.1', 'port' => $backupPort]]]);
        Process::fake(fn () => Process::result(json_encode(['results' => [
            ['host' => '127.0.0.1', 'port' => $mainPort, 'online' => false],
            ['host' => '127.0.0.1', 'port' => $backupPort, 'online' => true],
        ]])));
        try {
            self::assertSame(1, app(ServerTcpCheckService::class)->syncServer($node)['online']);
            for ($i = 0; $i < 16; $i++) {
                $servers = ServerService::getAvailableServers($user);
                self::assertCount(1, $servers);
                self::assertSame($backupPort, $servers[0]['port']);
            }
            Process::assertRan(function ($process) use ($parent, $user) {
                $input = json_decode($process->input, true);
                return $process->command === [config('entry_probe.binary')]
                    && $input['password'] === $parent->generateServerPassword($user)
                    && $input['method'] === 'aes-128-gcm' && count($input['entries']) === 2;
            });
            foreach (['general', 'clash.meta', 'sing-box'] as $flag) {
                $response = $this->get('/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => $flag]))->assertOk();
                if ($flag === 'general') {
                    self::assertStringContainsString('127.0.0.1:' . $backupPort, base64_decode($response->getContent(), true));
                } elseif ($flag === 'clash.meta') {
                    $config = \Symfony\Component\Yaml\Yaml::parse($response->getContent());
                    self::assertCount(1, $config['proxies']);
                    self::assertSame($backupPort, $config['proxies'][0]['port']);
                } else {
                    $outbounds = array_values(array_filter(json_decode($response->getContent(), true)['outbounds'],
                        fn (array $outbound): bool => ($outbound['type'] ?? '') === 'shadowsocks'));
                    self::assertCount(1, $outbounds);
                    self::assertSame($backupPort, $outbounds[0]['server_port']);
                }
            }
            self::assertSame((string) $mainPort, $node->fresh()->port);
        } finally {
            fclose($mainSocket);
            fclose($backupSocket);
        }
    }

    public function test_real_helper_rejects_tcp_only_entries(): void
    {
        if (!is_file((string) config('entry_probe.binary'))) {
            $this->markTestSkipped('Build mi-entry-probe and set SERVER_ENTRY_PROBE_BINARY for the real process check.');
        }
        $this->user();
        config()->set('entry_probe.timeout_seconds', 1);
        [$socket, $port] = $this->openTcpServer();
        $parent = $this->node(['show' => false, 'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $node = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'host' => '127.0.0.1', 'port' => $port, 'tcp_check_enabled' => true,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'], 'entry_points_enabled' => true,
            'entry_points' => [['host' => '127.0.0.1', 'port' => $this->unusedTcpPort()]]]);
        Log::spy();
        try {
            self::assertSame(1, app(ServerTcpCheckService::class)->syncServer($node)['offline']);
            self::assertNull(app(ServerEntryPointService::class)->selectForSubscription($node));
            Log::shouldNotHaveReceived('warning');
            $connection = stream_socket_accept($socket, 0);
            self::assertIsResource($connection, 'The helper must actually connect to the TCP-only entry.');
            try {
                self::assertNotSame('', fread($connection, 4096), 'The helper must send an encrypted request.');
            } finally {
                fclose($connection);
            }
        } finally {
            fclose($socket);
        }
    }

    public function test_shadowsocks_pool_hides_on_failure_and_recovers_while_child_is_hidden(): void
    {
        $user = $this->user();
        $parent = $this->node(['show' => false, 'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => '2022-blake3-aes-128-gcm']]);
        $node = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => '2022-blake3-aes-128-gcm'], 'tcp_check_enabled' => true,
            'auto_online' => true, 'entry_points_enabled' => true,
            'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        $healthy = false;
        Process::fake(function ($process) use (&$healthy, $parent, $user) {
            $input = json_decode($process->input, true);
            self::assertSame($parent->generateServerPassword($user), $input['password']);
            return Process::result(json_encode(['results' => array_map(
                fn (array $entry): array => $entry + ['online' => $healthy && $entry['port'] === 2443], $input['entries'])]));
        });
        $service = app(ServerTcpCheckService::class);
        self::assertSame(1, $service->syncServer($node)['offline']);
        self::assertFalse($node->fresh()->show);
        self::assertSame([], ServerService::getAvailableServers($user));
        $healthy = true;
        self::assertSame(1, $service->syncServer($node->fresh())['online']);
        self::assertTrue($node->fresh()->show);
        self::assertSame(2443, ServerService::getAvailableServers($user)[0]['port']);
        $healthy = false;
        self::assertSame(1, $service->syncServer($node->fresh())['offline']);
        self::assertSame([], ServerService::getAvailableServers($user));
    }

    public function test_shadowsocks_helper_failure_cannot_reuse_previous_healthy_result(): void
    {
        $user = $this->user();
        $parent = $this->node(['show' => false, 'type' => Server::TYPE_SHADOWSOCKS]);
        $node = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'tcp_check_enabled' => true, 'entry_points_enabled' => true,
            'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        $entries = app(ServerEntryPointService::class);
        $entries->recordHealth($node, [['host' => 'backup.example', 'port' => 2443, 'online' => true]]);
        $this->heartbeat($node);
        self::assertCount(1, ServerService::getAvailableServers($user));
        Process::fake(fn () => Process::result('', 'helper unavailable', 1));
        self::assertSame(1, app(ServerTcpCheckService::class)->syncServer($node)['offline']);
        self::assertNull($entries->selectForSubscription($node));
        self::assertSame([], ServerService::getAvailableServers($user));
    }

    public function test_shadowsocks_probe_rejects_wrong_pairs_and_non_boolean_health(): void
    {
        $this->user();
        $parent = $this->node(['show' => false, 'type' => Server::TYPE_SHADOWSOCKS]);
        $node = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'tcp_check_enabled' => true, 'entry_points_enabled' => true,
            'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        foreach ([
            [['host' => 'primary.example', 'port' => 1443, 'online' => true]],
            [['host' => 'backup.example', 'port' => 2443, 'online' => true], ['host' => 'primary.example', 'port' => 1443, 'online' => true]],
            [['host' => 'primary.example', 'port' => 1443, 'online' => 'true'], ['host' => 'backup.example', 'port' => 2443, 'online' => true]],
        ] as $response) {
            Process::fake(fn () => Process::result(json_encode(['results' => $response])));
            self::assertSame(1, app(ServerTcpCheckService::class)->syncServer($node->fresh())['offline']);
            self::assertNull(app(ServerEntryPointService::class)->selectForSubscription($node));
        }
    }

    public function test_shadowsocks_probe_requires_an_active_runtime_account_and_never_falls_back_to_tcp(): void
    {
        $this->user()->update(['banned' => 1]);
        $parent = $this->node(['show' => false, 'type' => Server::TYPE_SHADOWSOCKS]);
        $node = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'tcp_check_enabled' => true, 'entry_points_enabled' => true,
            'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        Process::fake();
        self::assertSame(1, app(ServerTcpCheckService::class)->syncServer($node)['offline']);
        Process::assertNothingRan();
        self::assertNull(app(ServerEntryPointService::class)->selectForSubscription($node));
    }

    public function test_shadowsocks_probe_rejects_incompatible_subscription_protocol_and_missing_dedicated_account(): void
    {
        $this->user();
        $parent = $this->node(['show' => false, 'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => 'aes-256-gcm']]);
        $node = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'],
            'tcp_check_enabled' => true, 'entry_points_enabled' => true,
            'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        Process::fake();
        self::assertSame(1, app(ServerTcpCheckService::class)->syncServer($node)['offline']);
        Process::assertNothingRan();
        $node->update(['protocol_settings' => ['cipher' => 'aes-256-gcm']]);
        config()->set('entry_probe.user_id', 999999);
        self::assertSame(1, app(ServerTcpCheckService::class)->syncServer($node->fresh())['offline']);
        Process::assertNothingRan();
    }

    public function test_scheduled_mixed_pools_keep_forwarding_and_tcp_health_separate(): void
    {
        $this->user();
        [$socket, $port] = $this->openTcpServer();
        $parent = $this->node(['show' => false, 'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $working = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'], 'tcp_check_enabled' => true,
            'entry_points_enabled' => true, 'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        $tcp = $this->node(['parent_id' => $parent->id, 'host' => '127.0.0.1', 'port' => $port,
            'server_port' => $port, 'tcp_check_enabled' => true]);
        $failed = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'], 'tcp_check_enabled' => true,
            'entry_points_enabled' => true, 'entry_points' => [['host' => 'failed.example', 'port' => 3443]]]);
        Process::fake(function ($process) {
            $input = json_decode($process->input, true);
            return Process::result(json_encode(['results' => array_map(
                fn (array $entry): array => $entry + ['online' => $entry['port'] === 2443], $input['entries'])]));
        });
        try {
            $result = app(ServerTcpCheckService::class)->sync();
            self::assertSame(3, $result['checked']);
            self::assertSame(2, $result['online']);
            self::assertSame(1, $result['offline']);
            self::assertSame(2443, app(ServerEntryPointService::class)->selectForSubscription($working)['port']);
            self::assertNull(app(ServerEntryPointService::class)->selectForSubscription($failed));
            self::assertNotSame(Server::STATUS_OFFLINE, $tcp->fresh()->available_status);
        } finally {
            fclose($socket);
        }
    }

    public function test_shadowsocks_legacy_tcp_cache_and_changed_protocol_or_target_are_not_reused(): void
    {
        $parent = $this->node(['show' => false, 'type' => Server::TYPE_SHADOWSOCKS]);
        $node = $this->node(['parent_id' => $parent->id, 'type' => Server::TYPE_SHADOWSOCKS,
            'tcp_check_enabled' => true, 'entry_points_enabled' => true,
            'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        $entries = app(ServerEntryPointService::class);
        $legacyKey = 'server_entry_health:' . $node->id . ':' . hash('sha256', json_encode([
            (int) $node->parent_id, true, $entries->candidates($node),
        ]));
        Cache::put($legacyKey, ['checked_at' => time(), 'online' => [json_encode(['backup.example', 2443])]], 180);
        self::assertNull($entries->selectForSubscription($node));
        $entries->recordHealth($node, [['host' => 'backup.example', 'port' => 2443, 'online' => true]]);
        self::assertNotNull($entries->selectForSubscription($node));
        $parent->update(['protocol_settings' => ['cipher' => 'aes-256-gcm']]);
        self::assertNull($entries->selectForSubscription($node->fresh()));
        $entries->recordHealth($node->fresh(), [['host' => 'backup.example', 'port' => 2443, 'online' => true]]);
        config()->set('entry_probe.target_url', 'https://probe.example/generate_204');
        self::assertNull($entries->selectForSubscription($node->fresh()));
    }

    public function test_unknown_expired_and_changed_pool_health_never_reuses_failed_candidates(): void
    {
        $parent = $this->node(['show' => false]);
        $node = $this->node(['parent_id' => $parent->id, 'tcp_check_enabled' => true,
            'entry_points_enabled' => true, 'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        $entries = app(ServerEntryPointService::class);
        self::assertNull($entries->selectForSubscription($node));
        $entries->recordHealth($node, [['host' => 'backup.example', 'port' => 2443, 'online' => true]]);
        self::assertSame(['host' => 'backup.example', 'port' => 2443], $entries->selectForSubscription($node));
        $node->entry_points = [['host' => 'changed.example', 'port' => 3443]];
        self::assertNull($entries->selectForSubscription($node));
        $entries->recordHealth($node, [['host' => 'changed.example', 'port' => 3443, 'online' => true]]);
        $this->travel(ServerEntryPointService::HEALTH_TTL_SECONDS + 1)->seconds();
        self::assertNull($entries->selectForSubscription($node));
    }

    public function test_admin_validates_and_round_trips_pool_without_clearing_omitted_fields(): void
    {
        $admin = $this->user();
        $admin->update(['is_admin' => true]);
        $this->actingAs($admin, 'sanctum');
        $node = $this->node();
        $url = '/api/v2/' . hash('crc32b', config('app.key')) . '/server/manage/save';
        $payload = array_replace($node->toArray(), ['id' => $node->id, 'allowed_plan_ids' => [], 'entry_points_enabled' => true,
            'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        $this->postJson($url, $payload)->assertOk();
        self::assertTrue($node->fresh()->entry_points_enabled);
        self::assertSame($payload['entry_points'], $node->fresh()->entry_points);
        $list = '/api/v2/' . hash('crc32b', config('app.key')) . '/server/manage/getAllNodes';
        $this->getJson($list)->assertOk()->assertJsonPath('data.0.entry_points.0.port', 2443);
        $legacyPayload = $payload;
        unset($legacyPayload['entry_points_enabled'], $legacyPayload['entry_points']);
        $this->postJson($url, $legacyPayload)->assertOk();
        self::assertSame($payload['entry_points'], $node->fresh()->entry_points);
        foreach ([
            [[], 'entry_points'],
            [['named' => ['host' => 'backup.example', 'port' => 2443]], 'entry_points'],
            [[['host' => 'https://bad.example/path', 'port' => 443]], 'entry_points.0.host'],
            [[['host' => ['bad.example'], 'port' => 443]], 'entry_points.0.host'],
            [[['host' => '[bad.example]', 'port' => 443]], 'entry_points.0.host'],
            [[['host' => 'primary.example.', 'port' => 1443]], 'entry_points.0.host'],
            [[['host' => 'backup.example', 'port' => 65536]], 'entry_points.0.port'],
            [[['host' => 'primary.example', 'port' => 1443]], 'entry_points.0.host'],
            [[['host' => 'backup.example', 'port' => 443], ['host' => 'BACKUP.example', 'port' => 443]], 'entry_points.1.host'],
        ] as [$entryPoints, $field]) {
            $this->postJson($url, array_replace($payload, ['entry_points' => $entryPoints]))
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->postJson($url, array_replace($payload, ['port' => '10000-20000']))
            ->assertStatus(422)->assertJsonValidationErrors('port');
        $this->postJson($url, array_replace($payload, ['entry_points_enabled' => false]))->assertOk();
        self::assertFalse($node->fresh()->entry_points_enabled);
        self::assertSame($payload['entry_points'], $node->fresh()->entry_points);
    }

    public function test_each_subscription_format_emits_only_the_healthy_pair(): void
    {
        $user = $this->user();
        $parent = $this->node(['show' => false]);
        $node = $this->node(['parent_id' => $parent->id, 'tcp_check_enabled' => true,
            'entry_points_enabled' => true, 'entry_points' => [['host' => 'backup.example', 'port' => 2443]]]);
        $this->heartbeat($node);
        app(ServerEntryPointService::class)->recordHealth($node, [['host' => 'backup.example', 'port' => 2443, 'online' => true]]);
        foreach (['general', 'clash.meta', 'sing-box'] as $flag) {
            $response = $this->get('/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => $flag]))->assertOk();
            if ($flag === 'general') {
                $links = array_filter(explode("\n", trim(base64_decode($response->getContent(), true))));
                self::assertCount(1, $links);
                self::assertStringContainsString('backup.example:2443', reset($links));
            } elseif ($flag === 'clash.meta') {
                $config = \Symfony\Component\Yaml\Yaml::parse($response->getContent());
                self::assertCount(1, $config['proxies']);
                self::assertSame('backup.example', $config['proxies'][0]['server']);
                self::assertSame(2443, $config['proxies'][0]['port']);
            } else {
                $outbounds = array_values(array_filter(json_decode($response->getContent(), true)['outbounds'],
                    fn (array $outbound): bool => ($outbound['type'] ?? '') === 'socks'));
                self::assertCount(1, $outbounds);
                self::assertSame('backup.example', $outbounds[0]['server']);
                self::assertSame(2443, $outbounds[0]['server_port']);
            }
        }
    }

    public function test_scheduled_batch_checks_preserve_the_health_of_each_node(): void
    {
        $parent = $this->node(['show' => false]);
        $sockets = [];
        $expectedPorts = [];
        $unused = $this->unusedTcpPort();
        try {
            for ($i = 0; $i < 9; $i++) {
                [$socket, $port] = $this->openTcpServer();
                $sockets[] = $socket;
                $node = $this->node(['parent_id' => $parent->id, 'tcp_check_enabled' => true,
                    'host' => '127.0.0.1', 'port' => (string) $unused, 'entry_points_enabled' => true,
                    'entry_points' => [['host' => '127.0.0.1', 'port' => $port]]]);
                $expectedPorts[$node->id] = $port;
            }
            $result = app(ServerTcpCheckService::class)->sync();
            self::assertSame(9, $result['checked']);
            self::assertSame(9, $result['online']);
            self::assertSame(0, $result['offline']);
            $servers = ServerService::getAvailableServers($this->user());
            self::assertCount(9, $servers);
            foreach ($servers as $server) {
                self::assertSame($expectedPorts[$server['id']], $server['port']);
            }
        } finally {
            foreach ($sockets as $socket) {
                fclose($socket);
            }
        }
    }

    public function test_entry_migration_recovers_partial_ddl_and_repeated_up_and_down(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        $migration = require __DIR__ . '/../../../database/migrations/2026_10_18_000002_add_server_entry_points.php';
        $schema->table('v2_server', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->dropColumn('entry_points'));
        $migration->up();
        $migration->up();
        self::assertTrue($schema->hasColumns('v2_server', ['entry_points_enabled', 'entry_points']));
        $node = $this->node();
        self::assertFalse($node->entry_points_enabled);
        self::assertNull($node->entry_points);
        $migration->down();
        $migration->down();
        self::assertFalse($schema->hasColumn('v2_server', 'entry_points_enabled'));
        self::assertFalse($schema->hasColumn('v2_server', 'entry_points'));
        $migration->up();
    }

    private function node(array $attributes = []): Server
    {
        return Server::create(array_replace([
            'name' => 'entry-fixture', 'type' => Server::TYPE_SOCKS, 'host' => 'primary.example',
            'port' => '1443', 'server_port' => 9443, 'group_ids' => [1], 'rate' => 4,
            'enabled' => true, 'show' => true, 'protocol_settings' => ['tls' => 0],
            'gfw_check_enabled' => false, 'bandwidth_check_enabled' => false,
        ], $attributes))->refresh();
    }

    private function user(): User
    {
        return User::create([
            'email' => 'entry-fixture@example.test', 'password' => bcrypt('fixture'),
            'uuid' => '11111111-1111-4111-8111-111111111111', 'token' => 'entry-fixture-user',
            'group_id' => 1, 'transfer_enable' => 10737418240, 'expired_at' => null, 'u' => 0, 'd' => 0,
        ]);
    }

    private function heartbeat(Server $node): void
    {
        Cache::put(CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id), time(), 3600);
    }

    private function openTcpServer(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        return [$socket, (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1)];
    }

    private function unusedTcpPort(): int
    {
        [$socket, $port] = $this->openTcpServer();
        fclose($socket);
        return $port;
    }
}
