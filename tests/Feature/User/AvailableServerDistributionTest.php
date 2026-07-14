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

    private function makeServer(string $name, int $sort, string $type = Server::TYPE_VMESS): Server
    {
        return Server::create([
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
        ]);
    }

    private function putRuntimeCache(Server $server, string $name, int $value): void
    {
        Cache::put($this->runtimeCacheKey($server, $name), $value, 3600);
    }

    private function forgetRuntimeCache(Server $server, string $name): void
    {
        Cache::forget($this->runtimeCacheKey($server, $name));
    }

    private function runtimeCacheKey(Server $server, string $name): string
    {
        return CacheKey::get(
            'SERVER_' . strtoupper($server->type) . '_' . $name,
            $server->id
        );
    }
}
