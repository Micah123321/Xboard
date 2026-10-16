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

    public function test_subscription_policy_applies_to_path_and_legacy_urls_without_bypassing_plan_access(): void
    {
        config()->set('cache.stores.redis', ['driver' => 'array']);
        app('cache')->purge('redis');
        app()->forgetInstance(\App\Support\Setting::class);
        Cache::store('redis')->forever('admin_settings', []);
        Cache::store('redis')->forever('subscribe_template:clashmeta', "proxies: []\nproxy-groups: []\nrules: []\n");
        $user = $this->makeUser();
        $user->forceFill(['plan_id' => 10])->saveQuietly();
        $ordinary = $this->makeServer('ordinary-policy-node', 1, Server::TYPE_SOCKS);
        $snell = $this->makeServer('premium-snell-policy-node', 2, Server::TYPE_SNELL, [
            'plan_restriction_enabled' => true, 'allowed_plan_ids' => [20],
        ]);
        $this->putRuntimeCache($ordinary, 'LAST_CHECK_AT', time());
        $this->putRuntimeCache($snell, 'LAST_CHECK_AT', time());
        $path = route('client.subscribe', ['token' => $user->token], false);
        $this->withHeader('User-Agent', 'mihomo/1.19.26');
        $denied = $this->get($path . '?snell=1')->assertOk();
        $this->assertStringNotContainsString('premium-snell-policy-node', $denied->getContent());
        $user->forceFill(['plan_id' => 20])->saveQuietly();
        foreach ([$path . '?flag=meta', '/api/v1/client/subscribe?token=' . $user->token . '&flag=meta'] as $url) {
            $default = $this->get($url)->assertOk();
            $this->assertStringContainsString('ordinary-policy-node', $default->getContent());
            $this->assertStringNotContainsString('premium-snell-policy-node', $default->getContent());
            $enabled = $this->get($url . '&snell=1')->assertOk();
            $this->assertStringContainsString('premium-snell-policy-node', $enabled->getContent());
            $this->assertStringContainsString('ordinary-policy-node', $enabled->getContent());
            $disabled = $this->get($url . '&snell=0')->assertOk();
            $this->assertStringNotContainsString('premium-snell-policy-node', $disabled->getContent());
        }
        $old = $this->withHeader('User-Agent', 'mihomo/1.19.25')->get($path . '?flag=meta&snell=1')->assertOk();
        $this->assertStringNotContainsString('premium-snell-policy-node', $old->getContent());
    }

    public function test_policy_admin_api_checks_identity_and_saves_custom_query_parameters(): void
    {
        config()->set('cache.stores.redis', ['driver' => 'array']);
        app('cache')->purge('redis');
        app()->forgetInstance(\App\Support\Setting::class);
        Cache::store('redis')->forever('admin_settings', []);
        $fetch = null;
        foreach (app('router')->getRoutes() as $route) {
            if (str_ends_with($route->uri(), 'subscribe-policy/fetch')) {
                $fetch = '/' . $route->uri();
                break;
            }
        }
        $this->assertNotNull($fetch);
        $save = substr($fetch, 0, -strlen('fetch')) . 'save';
        $this->getJson($fetch)->assertForbidden();
        $user = $this->makeUser();
        $this->actingAs($user, 'sanctum');
        $this->getJson($fetch)->assertForbidden();
        $this->postJson($save, ['rules' => []])->assertForbidden();
        $user->forceFill(['is_admin' => true])->saveQuietly();
        $response = $this->getJson($fetch)->assertOk();
        $rules = array_map(fn ($rule) => array_intersect_key($rule, array_flip(['protocol', 'default_enabled', 'allow_override', 'parameter'])), $response->json('data.rules'));
        foreach ($rules as &$rule) {
            if ($rule['protocol'] === 'snell') $rule['parameter'] = 'include_snell';
        }
        unset($rule);
        $this->postJson($save, ['rules' => $rules])->assertOk();
        $saved = $this->getJson($fetch)->assertOk()->json('data.rules');
        $snell = array_values(array_filter($saved, fn ($rule) => $rule['protocol'] === 'snell'))[0];
        $this->assertSame('include_snell', $snell['parameter']);
        $this->assertFalse($snell['default_enabled']);
        $this->postJson($save, ['rules' => []])->assertUnprocessable();
    }

    public function test_subscription_labels_share_membership_across_nodes_and_refresh_visibility_after_edit(): void
    {
        config()->set('cache.stores.redis', ['driver' => 'array']);
        app('cache')->purge('redis');
        app()->forgetInstance(\App\Support\Setting::class);
        Cache::store('redis')->forever('admin_settings', []);
        $label = \App\Models\SubscriptionLabel::create(['name' => 'Premium', 'plan_ids' => [20, 30]]);
        $user = $this->makeUser();
        $user->forceFill(['plan_id' => 10])->saveQuietly();
        $ordinary = $this->makeServer('ordinary-label-node', 1, Server::TYPE_SOCKS);
        $premium = $this->makeServer('premium-label-node', 2, Server::TYPE_SOCKS, ['subscription_label_id' => $label->id]);
        $second = $this->makeServer('second-label-node', 3, Server::TYPE_SOCKS, ['subscription_label_id' => $label->id]);
        foreach ([$ordinary, $premium, $second] as $node) $this->putRuntimeCache($node, 'LAST_CHECK_AT', time());
        $path = route('client.subscribe', ['token' => $user->token], false) . '?flag=general';
        $response = $this->get($path)->assertOk();
        $this->assertStringNotContainsString('premium-label-node', base64_decode($response->getContent(), true));
        $user->forceFill(['plan_id' => 20])->saveQuietly();
        $response = $this->get($path)->assertOk();
        $this->assertStringContainsString('premium-label-node', base64_decode($response->getContent(), true));
        $this->assertStringContainsString('second-label-node', base64_decode($response->getContent(), true));
        $this->actingAs($user, 'sanctum');
        $before = $this->getJson('/api/v1/user/server/fetch')->assertOk()->assertJsonCount(3, 'data');
        $label->update(['plan_ids' => [30]]);
        $after = $this->withHeader('If-None-Match', $before->headers->get('ETag'))->getJson('/api/v1/user/server/fetch')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotSame($before->headers->get('ETag'), $after->headers->get('ETag'));
        $response = $this->get($path)->assertOk();
        $this->assertStringNotContainsString('premium-label-node', base64_decode($response->getContent(), true));
        $user->forceFill(['plan_id' => 30])->saveQuietly();
        $this->withHeader('If-None-Match', '')->getJson('/api/v1/user/server/fetch')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_admin_subscription_label_crud_and_node_batch_binding_preserve_existing_rules(): void
    {
        config()->set('cache.stores.redis', ['driver' => 'array']);
        app('cache')->purge('redis');
        app()->forgetInstance(\App\Support\Setting::class);
        Cache::store('redis')->forever('admin_settings', []);
        \Illuminate\Support\Facades\Queue::fake();
        $fetch = null;
        foreach (app('router')->getRoutes() as $route) {
            if (str_ends_with($route->uri(), 'subscription-label/fetch')) {
                $fetch = '/' . $route->uri();
                break;
            }
        }
        $this->assertNotNull($fetch);
        $base = substr($fetch, 0, -strlen('subscription-label/fetch'));
        $save = $base . 'subscription-label/save';
        $drop = $base . 'subscription-label/drop';
        $this->getJson($fetch)->assertForbidden();
        $user = $this->makeUser();
        $this->actingAs($user, 'sanctum');
        $this->postJson($save, ['name' => 'Premium', 'plan_ids' => [20]])->assertForbidden();
        $user->forceFill(['is_admin' => true])->saveQuietly();
        \App\Models\Plan::forceCreate(['id' => 20, 'name' => 'Premium plan', 'group_id' => 1, 'transfer_enable' => 10, 'show' => false]);
        \App\Models\Plan::forceCreate(['id' => 30, 'name' => 'Annual plan', 'group_id' => 1, 'transfer_enable' => 10]);
        $created = $this->postJson($save, ['name' => 'Premium', 'plan_ids' => [20, 30]])->assertOk();
        $id = $created->json('data.id');
        $this->getJson($fetch)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.plan_ids', [20, 30]);
        $node = $this->makeServer('label-admin-node', 1, Server::TYPE_SOCKS, ['plan_restriction_enabled' => true, 'allowed_plan_ids' => [20]]);
        $other = $this->makeServer('not-selected-node', 2, Server::TYPE_SOCKS);
        $payload = ['id' => $node->id, 'type' => 'socks', 'name' => $node->name, 'host' => $node->host, 'port' => 443, 'server_port' => 443, 'rate' => 1];
        $this->postJson($base . 'server/manage/save', $payload + ['subscription_label_id' => $id])->assertOk();
        $this->assertSame($id, $node->fresh()->subscription_label_id);
        $this->assertSame([20], $node->fresh()->allowed_plan_ids);
        $this->postJson($base . 'server/manage/save', $payload)->assertOk();
        $this->assertSame($id, $node->fresh()->subscription_label_id);
        $this->postJson($drop, ['id' => $id])->assertUnprocessable();
        $this->postJson($base . 'server/manage/batchUpdate', ['ids' => [$node->id], 'subscription_label_id' => null])->assertOk();
        $this->assertNull($node->fresh()->subscription_label_id);
        $this->assertNull($other->fresh()->subscription_label_id);
        $this->assertTrue($node->fresh()->plan_restriction_enabled);
        $this->postJson($base . 'server/manage/batchUpdate', ['ids' => [$node->id, $other->id], 'subscription_label_id' => $id])->assertOk();
        $this->getJson($fetch)->assertOk()->assertJsonPath('data.0.servers_count', 2);
        $this->getJson($base . 'server/manage/getAllNodes')->assertOk()
            ->assertJsonPath('data.0.subscription_label_id', $id)->assertJsonPath('data.0.subscription_label.name', 'Premium');
        $this->getJson($base . 'server/manage/getNodesPaginated')->assertOk()
            ->assertJsonPath('data.0.subscription_label_id', $id)->assertJsonPath('data.0.subscription_label.plan_ids', [20, 30]);
        $this->postJson($save, ['id' => $id, 'name' => 'Premium changed', 'plan_ids' => [30]])->assertOk();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SubscriptionLabelSyncJob::class, fn ($job) => $job->labelId === $id && $job->afterCommit);
        $this->postJson($base . 'server/manage/batchUpdate', ['ids' => [$node->id], 'subscription_label_id' => 99999])->assertUnprocessable();
        $this->postJson($save, ['name' => 'Empty', 'plan_ids' => []])->assertUnprocessable();
        $this->postJson($base . 'server/manage/batchUpdate', ['ids' => [$node->id, $other->id], 'subscription_label_id' => null])->assertOk();
        $this->postJson($drop, ['id' => $id])->assertOk();
        $this->assertDatabaseMissing('v2_subscription_labels', ['id' => $id]);
    }

    public function test_subscription_names_use_current_rate_and_label_without_overwriting_node_name(): void
    {
        config()->set('cache.stores.redis', ['driver' => 'array']);
        app('cache')->purge('redis');
        app()->forgetInstance(\App\Support\Setting::class);
        Cache::store('redis')->forever('admin_settings', []);
        $label = \App\Models\SubscriptionLabel::create(['name' => '高级', 'plan_ids' => [20]]);
        $user = $this->makeUser();
        $user->forceFill(['plan_id' => 20])->saveQuietly();
        $name = '台湾mhy 京粤闽苏专线';
        $node = $this->makeServer($name, 1, Server::TYPE_SOCKS, ['subscription_label_id' => $label->id, 'rate' => 4]);
        $this->putRuntimeCache($node, 'LAST_CHECK_AT', time());
        $url = route('client.subscribe', ['token' => $user->token], false) . '?flag=general';
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString(rawurlencode('4x (高级) ' . $name), base64_decode($response->getContent(), true));
        $this->assertSame($name, $node->fresh()->name);
        $label->update(['name' => '旗舰']);
        $node->update(['rate_time_enable' => true, 'rate_time_ranges' => [['start' => '00:00', 'end' => '23:59', 'rate' => 1.5]]]);
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString(rawurlencode('1.5x (旗舰) ' . $name), base64_decode($response->getContent(), true));
        $this->assertSame($name, $node->fresh()->name);
        $vmess = $this->makeServer('App 专线', 2, Server::TYPE_VMESS, ['subscription_label_id' => $label->id, 'rate' => 4]);
        $this->putRuntimeCache($vmess, 'LAST_CHECK_AT', time());
        $appResponse = $this->get('/api/v1/client/app/getConfig?token=' . $user->token)->assertOk();
        $appConfig = \Symfony\Component\Yaml\Yaml::parse($appResponse->getContent());
        $this->assertContains('4x (旗舰) App 专线', array_column($appConfig['proxies'], 'name'));
        $this->assertContains('4x (旗舰) App 专线', array_merge(...array_column($appConfig['proxy-groups'], 'proxies')));
        $node->update(['subscription_label_id' => null]);
        $response = $this->get($url)->assertOk();
        $content = base64_decode($response->getContent(), true);
        $this->assertStringContainsString(rawurlencode('1.5x ' . $name), $content);
        $this->assertStringNotContainsString(rawurlencode('1.5x (旗舰) ' . $name), $content);
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
