<?php

namespace Tests\Feature\User;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AvailableServerDistributionTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshDatabaseWithConfiguredConnection;
    }

    public function refreshDatabase(): void
    {
        if (config('database.default') === 'sqlite') {
            config()->set('database.connections.sqlite.database', ':memory:');
            \Illuminate\Support\Facades\DB::purge('sqlite');
        }

        $this->refreshDatabaseWithConfiguredConnection();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }

    public function test_available_servers_exclude_offline_nodes_and_keep_both_online_states(): void
    {
        $user = $this->makeUser();
        $offline = $this->makeServer('offline-node', 1);
        $heartbeatOnly = $this->makeServer('heartbeat-only-node', 2);
        $heartbeatAndPush = $this->makeServer('heartbeat-and-push-node', 3);

        $this->putRuntimeCache($heartbeatOnly, 'LAST_CHECK_AT', time());
        $this->putRuntimeCache($heartbeatAndPush, 'LAST_CHECK_AT', time());
        $this->putRuntimeCache($heartbeatAndPush, 'LAST_PUSH_AT', time());

        $this->assertSame(Server::STATUS_OFFLINE, $offline->fresh()->available_status);
        $this->assertSame(Server::STATUS_ONLINE_NO_PUSH, $heartbeatOnly->fresh()->available_status);
        $this->assertSame(Server::STATUS_ONLINE, $heartbeatAndPush->fresh()->available_status);

        $availableIds = array_column(ServerService::getAvailableServers($user), 'id');

        $this->assertSame([$heartbeatOnly->id, $heartbeatAndPush->id], $availableIds);
    }

    public function test_subscription_excludes_offline_nodes(): void
    {
        $user = $this->makeUser();
        $this->makeServer('offline-subscription-node', 1, Server::TYPE_SOCKS);
        $online = $this->makeServer('online-subscription-node', 2, Server::TYPE_SOCKS);
        $this->putRuntimeCache($online, 'LAST_CHECK_AT', time());

        $response = $this->get('/api/v1/client/subscribe?' . http_build_query([
            'token' => $user->token,
            'flag' => 'general',
        ]));

        $response->assertOk();
        $subscription = base64_decode($response->getContent(), true);
        $this->assertNotFalse($subscription);
        $this->assertStringNotContainsString('offline-subscription-node', $subscription);
        $this->assertStringContainsString('online-subscription-node', $subscription);
    }

    public function test_subscription_excludes_traffic_limited_nodes(): void
    {
        $user = $this->makeUser();
        $limited = $this->makeServer('traffic-limited-subscription-node', 1, Server::TYPE_SOCKS, [
            'traffic_limit_enabled' => true,
            'transfer_enable' => 1024,
            'traffic_limit_reset_day' => 1,
            'traffic_limit_reset_time' => '00:00',
            'traffic_limit_timezone' => 'UTC',
            'u' => 0,
            'd' => 0,
        ]);
        $online = $this->makeServer('online-subscription-node', 2, Server::TYPE_SOCKS);
        $this->putRuntimeCache($limited, 'LAST_CHECK_AT', time());
        $this->putRuntimeCache($online, 'LAST_CHECK_AT', time());
        $this->putMetricsCache($limited, [
            'traffic_limit' => [
                'enabled' => true,
                'limit' => 1024,
                'used' => 1024,
                'suspended' => true,
                'last_reset_at' => 1,
                'next_reset_at' => time() + 3600,
                'suspended_at' => time(),
                'status' => Server::TRAFFIC_LIMIT_STATUS_SUSPENDED,
            ],
        ]);

        $response = $this->get('/api/v1/client/subscribe?' . http_build_query([
            'token' => $user->token,
            'flag' => 'general',
        ]));

        $response->assertOk();
        $subscription = base64_decode($response->getContent(), true);
        $this->assertNotFalse($subscription);
        $this->assertStringNotContainsString('traffic-limited-subscription-node', $subscription);
        $this->assertStringContainsString('online-subscription-node', $subscription);
    }

    public function test_fetch_updates_members_and_etag_when_node_goes_offline_and_recovers(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer('recovering-node', 1);
        $this->actingAs($user, 'sanctum');

        $this->putRuntimeCache($server, 'LAST_CHECK_AT', time());
        $this->putRuntimeCache($server, 'LAST_PUSH_AT', time());

        $onlineResponse = $this->getJson('/api/v1/user/server/fetch');
        $onlineResponse->assertOk()->assertJsonPath('data.0.id', $server->id);
        $onlineEtag = $onlineResponse->headers->get('ETag');
        $this->assertNotEmpty($onlineEtag);

        $this->forgetRuntimeCache($server, 'LAST_CHECK_AT');
        $this->forgetRuntimeCache($server, 'LAST_PUSH_AT');

        $offlineResponse = $this->withHeader('If-None-Match', $onlineEtag)
            ->getJson('/api/v1/user/server/fetch');
        $offlineResponse->assertOk()->assertJsonCount(0, 'data');
        $offlineEtag = $offlineResponse->headers->get('ETag');
        $this->assertNotSame($onlineEtag, $offlineEtag);

        $this->putRuntimeCache($server, 'LAST_CHECK_AT', time());

        $recoveredResponse = $this->withHeader('If-None-Match', $offlineEtag)
            ->getJson('/api/v1/user/server/fetch');
        $recoveredResponse->assertOk()->assertJsonPath('data.0.id', $server->id);
        $recoveredEtag = $recoveredResponse->headers->get('ETag');
        $this->assertNotSame($offlineEtag, $recoveredEtag);
        $this->assertSame($onlineEtag, $recoveredEtag);

        $this->withHeader('If-None-Match', $recoveredEtag)
            ->getJson('/api/v1/user/server/fetch')
            ->assertNotModified();
    }

    public function test_subscription_restricts_premium_nodes_to_selected_plans(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['plan_id' => 10])->saveQuietly();
        $ordinary = $this->makeServer('ordinary-plan-node', 1, Server::TYPE_SOCKS);
        $premium = $this->makeServer('premium-plan-node', 2, Server::TYPE_SOCKS, [
            'plan_restriction_enabled' => true,
            'allowed_plan_ids' => [20, 30],
        ]);
        $this->putRuntimeCache($ordinary, 'LAST_CHECK_AT', time());
        $this->putRuntimeCache($premium, 'LAST_CHECK_AT', time());
        $url = '/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => 'general']);
        $response = $this->get($url)->assertOk();
        $subscription = base64_decode($response->getContent(), true);
        $this->assertStringContainsString('ordinary-plan-node', $subscription);
        $this->assertStringNotContainsString('premium-plan-node', $subscription);
        $user->forceFill(['plan_id' => 20])->saveQuietly();
        $response = $this->get($url)->assertOk();
        $subscription = base64_decode($response->getContent(), true);
        $this->assertStringContainsString('ordinary-plan-node', $subscription);
        $this->assertStringContainsString('premium-plan-node', $subscription);
    }

    public function test_fetch_revokes_premium_node_and_changes_etag_after_same_group_downgrade(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['plan_id' => 20])->saveQuietly();
        $ordinary = $this->makeServer('ordinary-api-node', 1);
        $premium = $this->makeServer('premium-api-node', 2, Server::TYPE_VMESS, [
            'plan_restriction_enabled' => true,
            'allowed_plan_ids' => [20, 30],
        ]);
        $this->putRuntimeCache($ordinary, 'LAST_CHECK_AT', time());
        $this->putRuntimeCache($premium, 'LAST_CHECK_AT', time());
        $this->actingAs($user, 'sanctum');
        $before = $this->getJson('/api/v1/user/server/fetch')->assertOk()->assertJsonCount(2, 'data');
        $etag = $before->headers->get('ETag');
        $user->forceFill(['plan_id' => 10])->saveQuietly();
        $after = $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/user/server/fetch');
        $after->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ordinary->id);
        $this->assertNotSame($etag, $after->headers->get('ETag'));
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'distribution-test@example.com',
            'password' => bcrypt('secret'),
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'token' => 'distribution-test-user-token',
            'group_id' => 1,
            'transfer_enable' => 1024,
            'u' => 0,
            'd' => 0,
            'banned' => false,
            'expired_at' => time() + 3600,
        ]);
    }

    private function makeServer(string $name, int $sort, string $type = Server::TYPE_VMESS, array $attributes = []): Server
    {
        return Server::create(array_merge([
            'name' => $name,
            'type' => $type,
            'host' => '127.0.0.1',
            'port' => '443',
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'show' => true,
            'sort' => $sort,
            'enabled' => true,
        ], $attributes));
    }

    private function putRuntimeCache(Server $server, string $name, int $value): void
    {
        Cache::put($this->runtimeCacheKey($server, $name), $value, 3600);
    }

    private function forgetRuntimeCache(Server $server, string $name): void
    {
        Cache::forget($this->runtimeCacheKey($server, $name));
    }

    private function putMetricsCache(Server $server, array $metrics): void
    {
        Cache::put($this->runtimeCacheKey($server, 'METRICS'), $metrics, 3600);
    }

    private function runtimeCacheKey(Server $server, string $name): string
    {
        return CacheKey::get(
            'SERVER_' . strtoupper($server->type) . '_' . $name,
            $server->id
        );
    }
}
