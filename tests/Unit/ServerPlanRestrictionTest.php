<?php

namespace Tests\Unit;

use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Requests\Admin\PlanSave;
use App\Http\Requests\Admin\ServerSave;
use App\Jobs\NodeGroupUserSyncJob;
use App\Models\Server;
use App\Models\User;
use App\Observers\ServerObserver;
use App\Services\AccessTrafficService;
use App\Services\NodeSyncService;
use App\Services\ServerBandwidthService;
use App\Services\ServerService;
use App\Services\ServerTrafficLimitService;
use App\Utils\CacheKey;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Mockery;
use PHPUnit\Framework\TestCase;

class ServerPlanRestrictionTest extends TestCase
{
    private Container $app;
    private Manager $db;
    private array $messages = [];
    private array $jobs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('app', $this->app);
        $this->app->instance('config', new Config(['app' => ['timezone' => 'UTC']]));
        $this->app->instance('cache', new Repository(new ArrayStore()));
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $this->app->instance('db', $this->db->getDatabaseManager());
        $this->app->instance('db.schema', $this->db->schema());
        Model::clearBootedModels();
        $schema = $this->db->schema();
        $schema->create('v2_server', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type')->default('socks');
            $table->string('name')->default('node');
            $table->string('host')->default('127.0.0.1');
            $table->string('port')->default('1080');
            $table->integer('server_port')->default(1080);
            $table->text('group_ids')->nullable();
            $table->text('protocol_settings')->nullable();
            $table->boolean('show')->default(true);
            $table->integer('parent_id')->nullable();
            $table->integer('sort')->default(0);
            $table->float('rate')->default(1);
            $table->integer('u')->default(0);
            $table->integer('d')->default(0);
            $table->integer('transfer_enable')->default(0);
            $table->timestamps();
        });
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_15_000001_add_plan_restrictions_to_v2_server_table.php';
        $migration->up();
        $schema->create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id')->nullable();
            $table->integer('plan_id')->nullable();
            $table->string('uuid')->default('fixture-uuid');
            $table->integer('transfer_enable')->default(1024);
            $table->integer('temporary_transfer_enable')->default(0);
            $table->integer('u')->default(0);
            $table->integer('d')->default(0);
            $table->boolean('banned')->default(false);
            $table->integer('expired_at')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $schema->create('v2_plan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->default('plan');
            $table->integer('group_id')->default(1);
            $table->integer('transfer_enable')->default(1);
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $schema->create('v2_order', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('plan_id');
        });
        $this->db->table('v2_plan')->insert([['id' => 10], ['id' => 20], ['id' => 30]]);
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'), $this->app);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($this->db->getDatabaseManager()));
        $this->app->instance('validator', $validator);
        $redis = Mockery::mock();
        $redis->shouldReceive('publish')->andReturnUsing(function ($channel, $message): int {
            $this->messages[] = json_decode($message, true);
            return 1;
        });
        $this->app->instance('redis', $redis);
        $bandwidth = Mockery::mock(ServerBandwidthService::class);
        $bandwidth->shouldReceive('filterAvailable')->andReturnUsing(fn ($servers) => $servers);
        $this->app->instance(ServerBandwidthService::class, $bandwidth);
        $traffic = Mockery::mock(ServerTrafficLimitService::class);
        $traffic->shouldReceive('buildNodeConfig')->andReturn(['enabled' => false]);
        $this->app->instance(ServerTrafficLimitService::class, $traffic);
        $access = Mockery::mock(AccessTrafficService::class);
        $access->shouldReceive('nodeConfig')->andReturn(['enabled' => false]);
        $this->app->instance(AccessTrafficService::class, $access);
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function ($job) {
            $this->jobs[] = $job;
            return null;
        });
        $this->app->instance(BusDispatcher::class, $bus);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->db->getDatabaseManager()->disconnect();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_existing_nodes_remain_unrestricted_and_migration_is_reversible(): void
    {
        $node = $this->node();
        self::assertFalse($node->plan_restriction_enabled);
        self::assertTrue($node->allowsPlan(null));
        self::assertSame([$node->id], Server::forPlan(null)->pluck('id')->all());
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_15_000001_add_plan_restrictions_to_v2_server_table.php';
        $migration->up();
        $migration->down();
        self::assertFalse($this->db->schema()->hasColumn('v2_server', 'allowed_plan_ids'));
        self::assertSame(1, Server::count());
        $migration->up();
        self::assertFalse($node->fresh()->plan_restriction_enabled);
    }

    public function test_subscription_source_intersects_groups_and_multiple_plans(): void
    {
        $ordinary = $this->node();
        $premium = $this->node(['plan_restriction_enabled' => true, 'allowed_plan_ids' => ['20', 30]]);
        $this->node(['group_ids' => [2], 'plan_restriction_enabled' => true, 'allowed_plan_ids' => [20]]);
        $this->node(['plan_restriction_enabled' => true, 'allowed_plan_ids' => []]);
        self::assertSame([20, 30], $premium->allowed_plan_ids);
        self::assertSame([$ordinary->id], array_column(ServerService::getAvailableServers($this->user(10)), 'id'));
        foreach ([20, 30] as $planId) {
            self::assertSame([$ordinary->id, $premium->id], array_column(ServerService::getAvailableServers($this->user($planId)), 'id'));
        }
        self::assertFalse($premium->allowsPlan(null));
        self::assertFalse($premium->allowsPlan(10));
        self::assertTrue($premium->allowsPlan(20));
    }

    public function test_legacy_string_plan_ids_match_both_query_and_runtime_check(): void
    {
        $node = $this->node(['plan_restriction_enabled' => true]);
        $this->db->table('v2_server')->where('id', $node->id)->update(['allowed_plan_ids' => '["20","30"]']);
        self::assertSame([$node->id], Server::forPlan(20)->pluck('id')->all());
        self::assertTrue($node->fresh()->allowsPlan(20));
    }

    public function test_node_users_exclude_other_plans_groups_and_inactive_users(): void
    {
        $node = $this->node(['plan_restriction_enabled' => true, 'allowed_plan_ids' => [20, 30]]);
        $this->user(10);
        $allowedA = $this->user(20);
        $allowedB = $this->user(30);
        $this->user(20, ['group_id' => 2]);
        $this->user(20, ['banned' => true]);
        $this->user(20, ['expired_at' => time() - 60]);
        $this->user(20, ['u' => 1024]);
        self::assertSame([$allowedA->id, $allowedB->id], ServerService::getAvailableUsers($node)->pluck('id')->all());
        $node->update(['allowed_plan_ids' => []]);
        self::assertCount(0, ServerService::getAvailableUsers($node));
        $node->update(['plan_restriction_enabled' => false]);
        self::assertCount(3, ServerService::getAvailableUsers($node));
    }

    public function test_delta_grants_on_upgrade_and_removes_on_same_group_downgrade(): void
    {
        $ordinary = $this->node();
        $premium = $this->node(['plan_restriction_enabled' => true, 'allowed_plan_ids' => [20]]);
        $user = $this->user(20);
        NodeSyncService::notifyUserChanged($user);
        self::assertSame(['add', 'add'], array_column(array_column($this->messages, 'data'), 'action'));
        $this->messages = [];
        $user->update(['plan_id' => 10]);
        NodeSyncService::notifyUserChanged($user);
        self::assertSame([$ordinary->id, $premium->id], array_column($this->messages, 'node_id'));
        self::assertSame(['add', 'remove'], array_column(array_column($this->messages, 'data'), 'action'));
        $this->messages = [];
        $user->update(['plan_id' => 20]);
        NodeSyncService::notifyUserChanged($user);
        self::assertSame('add', $this->messages[1]['data']['action']);
    }

    public function test_whitelist_edit_and_switch_off_push_complete_user_set(): void
    {
        $node = $this->node(['plan_restriction_enabled' => true, 'allowed_plan_ids' => [20]]);
        $old = $this->user(20);
        $new = $this->user(30);
        $node->update(['allowed_plan_ids' => [30]]);
        (new ServerObserver())->updated($node);
        self::assertSame('sync.users', $this->messages[1]['event']);
        self::assertSame([$new->id], array_column($this->messages[1]['data']['users'], 'id'));
        $this->messages = [];
        $node->update(['plan_restriction_enabled' => false]);
        (new ServerObserver())->updated($node);
        self::assertSame([$old->id, $new->id], array_column($this->messages[1]['data']['users'], 'id'));
    }

    public function test_group_resync_uses_current_plan_whitelist_and_offline_nodes_are_skipped(): void
    {
        $node = $this->node(['plan_restriction_enabled' => true, 'allowed_plan_ids' => [20]]);
        $offline = $this->node();
        $this->app['cache']->forget('node_ws_alive:' . $offline->id);
        $this->user(10);
        $allowed = $this->user(20);
        (new NodeGroupUserSyncJob(1))->handle();
        self::assertCount(1, $this->messages);
        self::assertSame($node->id, $this->messages[0]['node_id']);
        self::assertSame([$allowed->id], array_column($this->messages[0]['data']['users'], 'id'));
    }

    public function test_validation_requires_nonempty_existing_distinct_plans_when_enabled(): void
    {
        foreach ([[], [999], [20, 20], [0], ['bad']] as $ids) {
            self::assertTrue($this->validator(['plan_restriction_enabled' => true, 'allowed_plan_ids' => $ids])->fails());
        }
        self::assertTrue($this->validator(['plan_restriction_enabled' => true])->fails());
        self::assertTrue($this->validator(['plan_restriction_enabled' => null])->fails());
        self::assertTrue($this->validator(['plan_restriction_enabled' => true, 'allowed_plan_ids' => [20, 30]])->passes());
        self::assertTrue($this->validator(['plan_restriction_enabled' => false, 'allowed_plan_ids' => []])->passes());
        self::assertTrue($this->validator(['plan_restriction_enabled' => false, 'allowed_plan_ids' => [999]])->passes());
    }

    public function test_old_clients_preserve_restrictions_and_partial_edits_validate_effective_state(): void
    {
        $node = $this->node(['plan_restriction_enabled' => true, 'allowed_plan_ids' => [20]]);
        $validator = $this->validator(['id' => $node->id]);
        self::assertTrue($validator->passes());
        $node->fill($validator->validated())->save();
        self::assertTrue($node->fresh()->plan_restriction_enabled);
        self::assertSame([20], $node->fresh()->allowed_plan_ids);
        self::assertTrue($this->validator(['id' => $node->id, 'allowed_plan_ids' => []])->fails());
        self::assertTrue($this->validator(['id' => $node->id, 'plan_restriction_enabled' => true])->passes());
    }

    public function test_plan_deletion_queues_one_after_commit_refresh_per_affected_group(): void
    {
        $this->user(20);
        $this->user(20);
        $this->user(20, ['group_id' => 2]);
        $response = $this->planController()->drop(new class([], ['id' => 20, 'replacement_plan_id' => 10], [], [], [], ['REQUEST_METHOD' => 'POST']) extends Request {
            public function validate(array $rules, ...$parameters): array { return $this->all(); }
        });
        self::assertSame('success', $response->getData(true)['status']);
        self::assertSame([10, 10, 10], User::pluck('plan_id')->all());
        self::assertSame([1, 2], array_column($this->jobs, 'groupId'));
        foreach ($this->jobs as $job) {
            self::assertInstanceOf(NodeGroupUserSyncJob::class, $job);
            self::assertTrue($job->afterCommit);
            self::assertSame('node_sync', $job->queue);
        }
    }

    public function test_forced_plan_update_refreshes_old_and_new_groups(): void
    {
        $this->user(20);
        $request = new class([], ['id' => 20, 'force_update' => true], [], [], [], ['REQUEST_METHOD' => 'POST']) extends PlanSave {
            public function validated($key = null, $default = null): array {
                return ['group_id' => 2, 'transfer_enable' => 2, 'speed_limit' => 5, 'device_limit' => 1];
            }
        };
        $response = $this->planController()->save($request);
        self::assertSame('success', $response->getData(true)['status']);
        self::assertSame([1, 2], array_column($this->jobs, 'groupId'));
        self::assertSame(2, User::value('group_id'));
    }

    private function node(array $attributes = []): Server
    {
        $node = Server::create(array_merge(['group_ids' => [1]], $attributes))->fresh();
        $this->app['cache']->put(CacheKey::get('SERVER_SOCKS_LAST_CHECK_AT', $node->id), time());
        $this->app['cache']->put('node_ws_alive:' . $node->id, true);
        return $node;
    }

    private function user(?int $planId, array $attributes = []): User
    {
        return User::create(array_merge(['plan_id' => $planId, 'group_id' => 1], $attributes))->fresh();
    }

    private function validator(array $attributes)
    {
        $request = new ServerSave([], array_merge([
            'type' => 'socks', 'name' => 'node', 'host' => '127.0.0.1',
            'port' => 1080, 'server_port' => 1080, 'rate' => 1,
        ], $attributes), [], [], [], ['REQUEST_METHOD' => 'POST']);
        $validator = $this->app['validator']->make($request->all(), $request->rules());
        $request->withValidator($validator);
        return $validator;
    }

    private function planController(): PlanController
    {
        return new class extends PlanController {
            public function success($data = null, $codeResponse = null): JsonResponse {
                return new JsonResponse(['status' => 'success', 'data' => $data]);
            }
            public function fail($codeResponse = null, $data = null, $error = null): JsonResponse {
                return new JsonResponse(['status' => 'fail', 'data' => $data]);
            }
        };
    }
}
