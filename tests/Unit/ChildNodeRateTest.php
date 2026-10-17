<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ChildNodeRateService;
use App\Services\NodeSyncService;
use App\Services\ServerBandwidthService;
use App\Services\ServerService;
use App\Services\ServerTrafficLimitService;
use App\Services\AccessTrafficService;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\TestCase;

class ChildNodeRateTest extends TestCase
{
    private Container $app;
    private Manager $db;
    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('app', $this->app);
        $this->app->instance('config', new Repository([
            'app' => ['timezone' => 'UTC'],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array'], 'redis' => ['driver' => 'array']]],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('cache', new CacheManager($this->app));
        $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'en');
        $this->app->instance('validator', new \Illuminate\Validation\Factory($translator, $this->app));
        Cache::store('redis')->forever('admin_settings', []);
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setEventDispatcher(new Dispatcher($this->app));
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $this->app->instance('db', $this->db->getDatabaseManager());
        $this->app->instance('db.schema', $this->db->schema());
        Model::clearBootedModels();
        $schema = $this->db->schema();
        $schema->create('v2_server', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type')->default('hysteria');
            $table->string('name')->default('node');
            $table->string('host')->default('127.0.0.1');
            $table->string('port')->default('443');
            $table->integer('server_port')->default(443);
            $table->text('group_ids')->nullable();
            $table->text('protocol_settings')->nullable();
            $table->integer('parent_id')->nullable();
            $table->integer('machine_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('show')->default(true);
            $table->boolean('plan_restriction_enabled')->default(false);
            $table->text('allowed_plan_ids')->nullable();
            $table->integer('subscription_label_id')->nullable();
            $table->string('rate')->default('4');
            $table->integer('u')->default(0);
            $table->integer('d')->default(0);
            $table->integer('sort')->default(0);
            $table->integer('transfer_enable')->default(0);
            $table->string('traffic_limit_status')->nullable();
            $table->timestamps();
        });
        (require dirname(__DIR__, 2) . '/database/migrations/2026_10_18_000001_add_child_node_rate.php')->up();
        $schema->create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id')->default(1);
            $table->integer('plan_id')->nullable();
            $table->string('uuid');
            $table->integer('speed_limit')->default(10);
            $table->integer('device_limit')->default(2);
            $table->integer('transfer_enable')->default(1024);
            $table->integer('u')->default(0);
            $table->integer('d')->default(0);
            $table->boolean('banned')->default(false);
            $table->integer('expired_at')->nullable();
            $table->timestamps();
        });
        $schema->create('v2_subscription_labels', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('plan_ids')->nullable();
        });
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
    }

    protected function tearDown(): void
    {
        Model::reguard();
        Model::clearBootedModels();
        Model::unsetEventDispatcher();
        Mockery::close();
        $this->db->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function node(array $attributes = []): Server
    {
        return Server::create(array_replace([
            'type' => 'hysteria', 'group_ids' => [1], 'protocol_settings' => [
                'version' => 2, 'tls' => ['server_name' => 'node.example.test'],
                'bandwidth' => ['up' => 100, 'down' => 100], 'obfs' => ['open' => false, 'password' => ''],
            ],
        ], $attributes));
    }

    private function user(int $id = 1, array $attributes = []): User
    {
        $user = (new User())->forceFill(array_replace([
            'id' => $id, 'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id), 'group_id' => 1,
        ], $attributes));
        $user->save();
        return $user->refresh();
    }

    private function capable(Server $parent): void
    {
        ChildNodeRateService::recordCapability($parent, Request::create('/', 'GET', [], [], [], [
            'HTTP_X_MI_NODE_CHILD_RATE' => '1',
        ]));
    }

    public function test_switch_defaults_off_and_existing_credentials_stay_unchanged(): void
    {
        $parent = $this->node();
        $child = $this->node(['parent_id' => $parent->id]);
        $user = $this->user();
        self::assertFalse($child->refresh()->child_rate_enabled);
        self::assertSame($user->uuid, $child->generateServerPassword($user));
        self::assertSame([$user->id], ServerService::getAvailableUsers($parent)->pluck('id')->all());
    }

    public function test_enabling_requires_a_capable_parent(): void
    {
        $parent = $this->node();
        $this->expectException(ValidationException::class);
        $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true]);
    }

    public function test_root_node_cannot_enable_child_rate(): void
    {
        $this->expectException(ValidationException::class);
        $this->node(['child_rate_enabled' => true]);
    }

    public function test_cross_protocol_child_is_rejected(): void
    {
        $parent = $this->node();
        $this->capable($parent);
        $this->expectException(ValidationException::class);
        $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true, 'type' => 'vless']);
    }

    public function test_parent_and_child_hysteria_versions_must_match(): void
    {
        $parent = $this->node();
        $this->capable($parent);
        $this->expectException(ValidationException::class);
        $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true, 'protocol_settings' => ['version' => 1]]);
    }

    public function test_identity_is_stable_matches_subscription_and_keeps_account_permissions(): void
    {
        $parent = $this->node(['group_ids' => [1]]);
        $this->capable($parent);
        $child = $this->node(['parent_id' => $parent->id, 'group_ids' => [2],
            'child_rate_enabled' => true, 'rate' => 3, 'plan_restriction_enabled' => true, 'allowed_plan_ids' => [20]]);
        $ordinary = $this->user();
        $premium = $this->user(2, ['group_id' => 2, 'plan_id' => 20]);
        $this->user(3, ['group_id' => 2, 'plan_id' => 10]);
        $this->user(4, ['group_id' => 2, 'plan_id' => 20, 'banned' => true]);
        $users = ServerService::getAvailableUsers($parent);
        self::assertCount(2, $users);
        self::assertSame($ordinary->id, $users[0]->id);
        self::assertGreaterThan(ChildNodeRateService::AUTH_ID_BASE, $users[1]->id);
        self::assertSame($premium->id, $users[1]->billing_user_id);
        self::assertSame($child->id, $users[1]->billing_node_id);
        self::assertSame($child->generateServerPassword($premium), $users[1]->uuid);
        self::assertNotSame($premium->uuid, $users[1]->uuid);
        self::assertSame(10, $users[1]->speed_limit);
        self::assertSame(2, $users[1]->device_limit);
        self::assertSame($users->toJson(), ServerService::getAvailableUsers($parent)->toJson());
        self::assertSame([1, 2], ChildNodeRateService::accountIds($users));
        self::assertSame(1, DB::table('v2_server_child_identity')->count());
        self::assertSame($premium->uuid, $premium->refresh()->uuid);
    }

    public function test_parent_without_groups_still_accepts_authorized_child_identities(): void
    {
        $parent = $this->node(['group_ids' => []]);
        $this->capable($parent);
        $child = $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true]);
        $user = $this->user();
        $users = ServerService::getAvailableUsers($parent);
        self::assertCount(1, $users);
        self::assertSame($child->id, $users[0]->billing_node_id);
        self::assertSame($user->id, $users[0]->billing_user_id);
    }

    public function test_parent_with_active_child_identities_cannot_become_a_nested_child(): void
    {
        $parent = $this->node();
        $other = $this->node();
        $this->capable($parent);
        $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true]);
        $this->expectException(ValidationException::class);
        $parent->update(['parent_id' => $other->id]);
    }

    public function test_parent_protocol_change_checks_active_child_compatibility(): void
    {
        $parent = $this->node();
        $this->capable($parent);
        $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true]);
        $this->expectException(ValidationException::class);
        $parent->update(['protocol_settings' => ['version' => 1]]);
    }

    public function test_two_children_have_distinct_credentials_for_same_account(): void
    {
        $parent = $this->node();
        $this->capable($parent);
        $a = $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true]);
        $b = $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true]);
        $user = $this->user();
        $users = ServerService::getAvailableUsers($parent);
        self::assertCount(3, $users);
        self::assertNotSame($users[1]->id, $users[2]->id);
        self::assertNotSame($a->generateServerPassword($user), $b->generateServerPassword($user));
        self::assertSame($user->uuid, $parent->generateServerPassword($user));
        self::assertCount(1, ChildNodeRateService::accountIds($users));
    }

    public function test_disabling_removes_alias_and_reenabling_reuses_identity(): void
    {
        $parent = $this->node();
        $this->capable($parent);
        $child = $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true]);
        $this->user();
        $id = ServerService::getAvailableUsers($parent)[1]->id;
        $child->update(['child_rate_enabled' => false]);
        self::assertCount(1, ServerService::getAvailableUsers($parent));
        self::assertSame(1, DB::table('v2_server_child_identity')->count());
        $child->update(['child_rate_enabled' => true]);
        self::assertSame($id, ServerService::getAvailableUsers($parent)[1]->id);
    }

    public function test_legacy_node_never_receives_child_authentication_identities(): void
    {
        $parent = $this->node();
        $this->capable($parent);
        $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true]);
        $this->user();
        self::assertCount(2, ServerService::getAvailableUsers($parent));
        ChildNodeRateService::recordCapability($parent, Request::create('/'));
        self::assertCount(1, ServerService::getAvailableUsers($parent));
    }

    public function test_child_group_changes_refresh_parent_full_user_set(): void
    {
        $parent = $this->node(['group_ids' => [1]]);
        $this->capable($parent);
        $child = $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true, 'group_ids' => [2]]);
        $this->user(2, ['group_id' => 2]);
        Cache::put('node_ws_alive:' . $parent->id, true, 600);
        NodeSyncService::notifyUsersUpdatedByGroup(2);
        $sync = array_values(array_filter($this->messages, fn ($m) => $m['event'] === 'sync.users'));
        self::assertCount(1, $sync);
        self::assertSame($parent->id, $sync[0]['node_id']);
        self::assertSame($child->id, $sync[0]['data']['users'][0]['billing_node_id']);
    }

    public function test_hysteria_subscription_credentials_match_each_parent_authentication_entry(): void
    {
        $parent = $this->node();
        $this->capable($parent);
        $child = $this->node(['parent_id' => $parent->id, 'child_rate_enabled' => true]);
        $user = $this->user();
        $identities = ServerService::getAvailableUsers($parent);
        $childConfig = $child->refresh()->toArray();
        $childConfig['password'] = $child->generateServerPassword($user);
        $uri = \App\Protocols\General::buildHysteria($childConfig['password'], $childConfig);
        self::assertSame($identities[1]->uuid, parse_url($uri, PHP_URL_USER));
        $yaml = \App\Protocols\ClashMeta::buildHysteria($childConfig['password'], $childConfig, $user->toArray());
        self::assertSame($identities[1]->uuid, $yaml['password']);

        $protocol = (new \ReflectionClass(\App\Protocols\SingBox::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\App\Support\AbstractProtocol::class, 'user'))->setValue($protocol, ['uuid' => $user->uuid]);
        (new \ReflectionProperty(\App\Support\AbstractProtocol::class, 'servers'))->setValue($protocol, [$childConfig]);
        (new \ReflectionProperty(\App\Protocols\SingBox::class, 'config'))->setValue($protocol, ['outbounds' => []]);
        $outbounds = (new \ReflectionMethod(\App\Protocols\SingBox::class, 'buildOutbounds'))->invoke($protocol);
        self::assertSame($identities[1]->uuid, $outbounds[0]['password']);
        self::assertNotSame($user->uuid, $outbounds[0]['password']);
    }

    public function test_machine_discovery_does_not_start_child_a_second_time(): void
    {
        $parent = $this->node(['machine_id' => 1]);
        $this->capable($parent);
        $this->node(['machine_id' => 1, 'parent_id' => $parent->id, 'child_rate_enabled' => true]);
        $machine = (new ServerMachine())->forceFill(['id' => 1]);
        self::assertSame([$parent->id], ServerService::getMachineNodes($machine)->pluck('id')->all());
    }
}
