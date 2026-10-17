<?php

namespace Tests\Feature\User;

use App\Jobs\ChildNodeTrafficBillingJob;
use App\Models\Server;
use App\Models\User;
use App\Services\ChildNodeRateService;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

class ChildNodeRateIntegrationTest extends TestCase
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
        config()->set('queue.default', 'sync');
        Cache::flush();
        $redis = \Mockery::mock();
        $redis->shouldReceive('publish')->andReturn(1);
        $redis->shouldReceive('sadd')->andReturn(1);
        \Illuminate\Support\Facades\Redis::swap($redis);
        $setting = new Setting();
        (new ReflectionProperty(Setting::class, 'cache'))->setValue($setting, Cache::store('array'));
        $this->app->instance(Setting::class, $setting);
        admin_setting(['server_token' => 'child-rate-fixture', 'show_info_to_server_enable' => false,
            'show_protocol_to_server_enable' => false]);
        Model::unguard();
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function node(array $attributes = []): Server
    {
        return Server::create(array_replace([
            'name' => 'fixture-node', 'type' => Server::TYPE_HYSTERIA, 'host' => '127.0.0.1',
            'port' => '30433', 'server_port' => 30433, 'group_ids' => [1], 'rate' => 4,
            'enabled' => true, 'show' => true, 'protocol_settings' => ['version' => 2],
            'gfw_check_enabled' => false, 'bandwidth_check_enabled' => false,
        ], $attributes))->refresh();
    }

    private function user(): User
    {
        return User::create([
            'email' => 'child-rate@example.test', 'password' => bcrypt('fixture'),
            'uuid' => '11111111-1111-4111-8111-111111111111', 'token' => 'child-rate-user',
            'group_id' => 1, 'transfer_enable' => 10737418240, 'expired_at' => null, 'u' => 0, 'd' => 0,
        ]);
    }

    private function users(Server $parent, bool $capable = true)
    {
        return $this->withHeaders($capable ? ['X-Mi-Node-Child-Rate' => '1'] : [])
            ->getJson('/api/v2/server/user?' . http_build_query([
                'token' => 'child-rate-fixture', 'node_id' => $parent->id,
            ]));
    }

    public function test_admin_switch_is_saved_and_legacy_node_activation_is_rejected(): void
    {
        $parent = $this->node();
        $child = $this->node(['parent_id' => $parent->id]);
        $admin = $this->user();
        $admin->update(['is_admin' => true]);
        $this->actingAs($admin, 'sanctum');
        $url = '/api/v2/' . hash('crc32b', config('app.key')) . '/server/manage/save';
        $payload = array_replace($child->toArray(), ['id' => $child->id, 'child_rate_enabled' => true, 'rate' => 3, 'allowed_plan_ids' => []]);
        $this->postJson($url, $payload)->assertStatus(422)->assertJsonValidationErrors('child_rate_enabled');
        self::assertFalse($child->fresh()->child_rate_enabled);
        $this->users($parent)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        self::assertTrue($child->fresh()->child_rate_enabled);
        self::assertSame(3.0, (float) $child->fresh()->rate);
        $other = $this->node(['name' => 'other-root']);
        $parentPayload = array_replace($parent->toArray(), ['id' => $parent->id,
            'parent_id' => $other->id, 'allowed_plan_ids' => [], 'child_rate_enabled' => false]);
        $this->postJson($url, $parentPayload)->assertStatus(422)->assertJsonValidationErrors('child_rate_enabled');
        self::assertNull($parent->fresh()->parent_id);
    }

    public function test_http_subscription_authentication_and_mixed_report_bill_separately_once(): void
    {
        $parent = $this->node();
        $user = $this->user();
        $this->users($parent)->assertOk();
        $child = $this->node(['name' => 'g-forward', 'parent_id' => $parent->id, 'child_rate_enabled' => true, 'rate' => 3]);
        $users = $this->users($parent)->assertOk()->json('users');
        self::assertCount(2, $users);
        $alias = collect($users)->firstWhere('billing_node_id', $child->id);
        self::assertNotNull($alias);
        self::assertSame($user->id, $alias['billing_user_id']);
        $response = $this->get('/api/v1/client/subscribe?' . http_build_query([
            'token' => $user->token, 'flag' => 'general',
        ]))->assertOk();
        $links = preg_split('/\r?\n/', trim(base64_decode($response->getContent(), true)));
        $childLink = array_values(array_filter($links, fn ($link) => str_contains($link, 'g-forward')))[0];
        self::assertSame($alias['uuid'], parse_url($childLink, PHP_URL_USER));
        self::assertSame(30433, parse_url($childLink, PHP_URL_PORT));

        Bus::fake([ChildNodeTrafficBillingJob::class]);
        $report = ['token' => 'child-rate-fixture', 'node_id' => $parent->id, 'report_id' => 'fixture-mixed',
            'traffic' => [$user->id => [100, 200], $alias['id'] => [100, 200]]];
        $this->withHeader('X-Mi-Node-Child-Rate', '1')->postJson('/api/v2/server/report', $report)->assertOk();
        Bus::assertDispatched(ChildNodeTrafficBillingJob::class);
        $job = Bus::dispatched(ChildNodeTrafficBillingJob::class)->first();
        // RefreshDatabase wraps the test in a transaction; settle against the same isolated database.
        $job->handle();
        unserialize(serialize($job))->handle();
        self::assertSame(700, (int) $user->fresh()->u);
        self::assertSame(1400, (int) $user->fresh()->d);
        self::assertSame(100, (int) $parent->fresh()->u);
        self::assertSame(100, (int) $child->fresh()->u);
        self::assertSame(2, DB::table('v2_traffic_report')->count());
    }
}
