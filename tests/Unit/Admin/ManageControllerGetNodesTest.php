<?php

namespace Tests\Unit\Admin;

use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatServer;
use App\Models\User;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

class ManageControllerGetNodesTest extends TestCase
{
    use RefreshDatabase;

    private string $securePath;

    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();

        // Bind a Setting instance backed by the array cache store
        // because Redis is not available locally.
        $setting = new Setting();
        $cacheProp = new ReflectionProperty(Setting::class, 'cache');
        $cacheProp->setValue($setting, Cache::store('array'));
        $this->app->instance(Setting::class, $setting);

        // Routes are registered during application boot (before setUp), so the
        // secure_path is already resolved to hash('crc32b', config('app.key')).
        // We must use that same path in our HTTP requests for the route to match.
        $this->securePath = hash('crc32b', config('app.key'));

        // Create an admin user and authenticate via Sanctum.
        $admin = User::create([
            'email' => 'admin@test.local',
            'password' => bcrypt('secret'),
            'is_admin' => true,
            'uuid' => 'admin-test-uuid',
            'token' => 'admin-test-token',
        ]);

        $this->actingAs($admin, 'sanctum');
    }

    private function url(string $path): string
    {
        return '/api/v2/' . $this->securePath . $path;
    }

    public function test_get_nodes_includes_all_expected_fields(): void
    {
        $groupA = ServerGroup::create(['name' => 'Group A']);
        $groupB = ServerGroup::create(['name' => 'Group B']);
        $parent = $this->makeServer([
            'name' => 'parent-node',
            'host' => '192.168.1.1',
        ]);
        $child = $this->makeServer([
            'name' => 'child-node',
            'host' => '192.168.1.2',
            'group_ids' => [$groupA->id, $groupB->id],
            'parent_id' => $parent->id,
        ]);

        $response = $this->getJson($this->url('/server/manage/getNodes'));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        $childData = collect($data)->firstWhere('id', $child->id);
        $this->assertNotNull($childData);

        // Verify groups field exists and contains the assigned groups.
        $this->assertArrayHasKey('groups', $childData);
        $this->assertCount(2, $childData['groups']);
        $groupNames = collect($childData['groups'])->pluck('name')->all();
        $this->assertContains('Group A', $groupNames);
        $this->assertContains('Group B', $groupNames);

        // Verify parent field exists with parent node info.
        $this->assertArrayHasKey('parent', $childData);
        $this->assertNotNull($childData['parent']);
        $this->assertSame($parent->id, $childData['parent']['id']);

        // Verify traffic_stats fields.
        $this->assertArrayHasKey('traffic_stats', $childData);
        $this->assertArrayHasKey('today', $childData['traffic_stats']);
        $this->assertArrayHasKey('yesterday', $childData['traffic_stats']);
        $this->assertArrayHasKey('month', $childData['traffic_stats']);
        $this->assertArrayHasKey('total', $childData['traffic_stats']);

        // Verify traffic_limit_snapshot exists.
        $this->assertArrayHasKey('traffic_limit_snapshot', $childData);

        // Verify gfw_check exists.
        $this->assertArrayHasKey('gfw_check', $childData);
    }

    public function test_get_nodes_does_not_trigger_n_plus_one_for_groups(): void
    {
        $groups = [];
        for ($i = 0; $i < 5; $i++) {
            $groups[] = ServerGroup::create(['name' => "Group {$i}"]);
        }

        $groupIds = collect($groups)->pluck('id')->all();
        for ($i = 0; $i < 5; $i++) {
            $this->makeServer([
                'name' => "n1-server-{$i}",
                'host' => "10.0.0.{$i}",
                'group_ids' => $groupIds,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson($this->url('/server/manage/getNodes'));
        $response->assertOk();

        $queries = collect(DB::getQueryLog());

        $groupQueries = $queries->filter(
            fn (array $log) => isset($log['query'])
                && str_contains((string) $log['query'], 'v2_server_group')
        )->count();

        // Batch lookup should use at most 1 group query regardless of server count.
        $this->assertLessThanOrEqual(1, $groupQueries);

        $data = $response->json('data');
        foreach ($data as $node) {
            $this->assertCount(5, $node['groups'] ?? []);
        }
    }

    public function test_get_nodes_does_not_trigger_n_plus_one_for_parent(): void
    {
        $parent = $this->makeServer([
            'name' => 'shared-parent',
            'host' => '10.0.0.1',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->makeServer([
                'name' => "child-{$i}",
                'host' => '10.0.0.' . ($i + 2),
                'parent_id' => $parent->id,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson($this->url('/server/manage/getNodes'));
        $response->assertOk();

        $queries = collect(DB::getQueryLog());

        // Count queries against v2_server that are NOT the initial getAllServers query.
        // The parent batch-load uses a WHERE IN clause; lazy-loading would use individual queries.
        $parentQueries = $queries->filter(
            fn (array $log) => isset($log['query'])
                && str_contains((string) $log['query'], '"v2_server"')
        )->count();

        // getAllServers + batch-parent + append attributes may trigger a few
        // extra queries, but the count must not scale linearly with child count.
        $this->assertLessThanOrEqual(10, $parentQueries);

        $data = $response->json('data');
        $children = collect($data)->filter(fn (array $n) => isset($n['parent_id']) && $n['parent_id'] === $parent->id);
        foreach ($children as $child) {
            $this->assertNotNull($child['parent']);
            $this->assertSame($parent->id, $child['parent']['id']);
        }
    }

    public function test_get_nodes_traffic_stats_aggregate_by_server_id(): void
    {
        $server = $this->makeServer([
            'name' => 'traffic-node',
            'host' => '203.0.113.10',
        ]);

        $todayStart = Carbon::now(config('app.timezone'))->startOfDay()->timestamp;
        StatServer::create([
            'server_id' => $server->id,
            'server_type' => $server->type,
            'record_type' => 'd',
            'record_at' => $todayStart,
            'u' => 100,
            'd' => 50,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $response = $this->getJson($this->url('/server/manage/getNodes'));
        $response->assertOk();

        $data = collect($response->json('data'))->firstWhere('id', $server->id);
        $this->assertNotNull($data);
        $this->assertSame(100, $data['traffic_stats']['today']['upload']);
        $this->assertSame(50, $data['traffic_stats']['today']['download']);
        $this->assertSame(150, $data['traffic_stats']['today']['total']);
    }

    public function test_get_nodes_handles_empty_servers_gracefully(): void
    {
        $response = $this->getJson($this->url('/server/manage/getNodes'));

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
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
            'show' => true,
            'auto_online' => false,
            'gfw_check_enabled' => false,
            'gfw_auto_hidden' => false,
            'parent_auto_hidden' => false,
            'enabled' => true,
            'u' => 0,
            'd' => 0,
        ], $attributes));
    }
}
