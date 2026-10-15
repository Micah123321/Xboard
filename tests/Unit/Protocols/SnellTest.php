<?php

namespace Tests\Unit\Protocols;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Controllers\V2\Server\ServerController;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\User;
use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use App\Protocols\Surge;
use App\Services\AccessTrafficService;
use App\Services\DeviceStateService;
use App\Services\ServerService;
use App\Services\ServerTrafficLimitService;
use App\Support\AbstractProtocol;
use App\Support\ProtocolManager;
use App\Utils\CacheKey;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Yaml\Yaml;

class SnellTest extends TestCase
{
    private Container $app;
    private Manager $db;

    protected function setUp(): void
    {
        parent::setUp();
        // No application bootstrap, environment files, migrations, or external services.
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('app', $this->app);
        $this->app->instance('config', new Repository([
            'app' => ['timezone' => 'UTC'],
            'cache' => ['default' => 'array', 'stores' => [
                'array' => ['driver' => 'array'], 'redis' => ['driver' => 'array'],
            ]],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('cache', new CacheManager($this->app));
        Cache::store('redis')->forever('admin_settings', []);
        $this->app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $this->app));
        $trafficLimit = Mockery::mock(ServerTrafficLimitService::class);
        $trafficLimit->shouldReceive('buildNodeConfig')->andReturn(['enabled' => false])->byDefault();
        $this->app->instance(ServerTrafficLimitService::class, $trafficLimit);
        $this->app->instance(ResponseFactoryContract::class, new ResponseFactory(
            Mockery::mock(ViewFactory::class), Mockery::mock(Redirector::class)
        ));
        $request = Request::create('https://panel.example.test/api/v1/client/subscribe');
        $this->app->instance('request', $request);
        $routes = new RouteCollection();
        $routes->add((new Route('GET', 'api/v1/client/subscribe', fn () => null))->name('client.subscribe'));
        $this->app->instance('url', new UrlGenerator($routes, $request));

        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setEventDispatcher(new Dispatcher($this->app));
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        Model::clearBootedModels();
        $this->db->schema()->create('v2_server', function (Blueprint $table): void {
            $table->increments('id');
            foreach (['type', 'name', 'host', 'port', 'server_port', 'protocol_settings', 'group_ids',
                'route_ids', 'custom_outbounds', 'custom_routes', 'cert_config'] as $field) {
                $table->text($field)->nullable();
            }
            $table->integer('machine_id')->nullable();
            $table->integer('parent_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('auto_online')->default(false);
            $table->boolean('auto_online_cooldown_enabled')->default(false);
            $table->boolean('tcp_check_enabled')->default(false);
            $table->decimal('rate', 8, 2)->default(1);
            $table->timestamps();
        });
        $this->db->schema()->create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('uuid');
            $table->integer('group_id');
            foreach (['u', 'd', 'banned'] as $field) {
                $table->integer($field)->default(0);
            }
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('transfer_enable')->default(1024);
            $table->integer('expired_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->db->getDatabaseManager()->disconnect();
        Model::clearBootedModels();
        Model::unsetEventDispatcher();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function payload(array $settings = []): array
    {
        return ['type' => Server::TYPE_SNELL, 'name' => 'Snell Node', 'host' => 'snell.example.test',
            'port' => 8443, 'server_port' => 1443, 'rate' => 1, 'protocol_settings' => $settings];
    }

    private function node(array $settings = []): Server
    {
        return new Server(array_diff_key($this->payload($settings), ['rate' => true]));
    }

    private function validate(array $payload): Validator
    {
        $request = ServerSave::create('/', 'POST', $payload);
        return $this->app->make('validator')->make($payload, $request->rules());
    }

    private function filtered(AbstractProtocol $protocol): array
    {
        return (new ReflectionProperty(AbstractProtocol::class, 'servers'))->getValue($protocol);
    }

    public function test_model_defaults_and_settings_survive_persistence(): void
    {
        self::assertTrue(Server::isValidType('snell'));
        self::assertSame($this->node()->protocol_settings, (new Server(['type' => Server::TYPE_SNELL]))->protocol_settings);
        self::assertSame(['version' => 4, 'obfs' => 'off', 'obfs_host' => '', 'udp' => false], $this->node()->protocol_settings);
        $node = $this->node(['version' => '4', 'obfs' => 'http', 'obfs_host' => 'cdn.example.test', 'udp' => 0]);
        $node->save();
        self::assertSame(['version' => 4, 'obfs' => 'http', 'obfs_host' => 'cdn.example.test', 'udp' => false], $node->fresh()->protocol_settings);
    }

    public function test_request_accepts_defaults_false_udp_and_forwarding_fields(): void
    {
        foreach ([[], ['version' => 4, 'obfs' => 'off', 'udp' => false],
            ['version' => '4', 'obfs' => 'http', 'obfs_host' => '', 'udp' => '0']] as $settings) {
            $payload = $this->payload($settings) + ['machine_id' => null, 'route_ids' => [],
                'custom_outbounds' => [], 'custom_routes' => [], 'cert_config' => null, 'parent_id' => 7];
            $validator = $this->validate($payload);
            self::assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
            self::assertSame(7, $validator->validated()['parent_id']);
        }
    }

    public static function invalidSettings(): array
    {
        $cases = [];
        foreach (['version' => [3, 5, null], 'obfs' => ['tls', '', null],
            'udp' => [true, 1, '1', null], 'obfs_host' => ["bad\nhost", 'host,udp-relay=true']] as $key => $values) {
            foreach ($values as $index => $value) {
                $cases[$key . '-' . $index] = [['protocol_settings' => [$key => $value]], 'protocol_settings.' . $key];
            }
        }
        foreach (['psk', 'password', 'shared_psk', 'server_key', 'tls', 'tls_settings', 'network',
            'network_settings', 'multiplex', 'routes', 'custom_routes', 'custom_outbounds', 'cert_config'] as $key) {
            $cases['settings-' . $key] = [['protocol_settings' => [$key => 'unsupported']], 'protocol_settings'];
        }
        foreach (['machine_id' => 1, 'route_ids' => [1], 'custom_outbounds' => [['type' => 'direct']],
            'custom_routes' => [['action' => 'direct']], 'cert_config' => ['mode' => 'http'],
            'psk' => 'shared', 'password' => 'shared', 'shared_psk' => 'shared', 'server_key' => 'shared'] as $key => $value) {
            $cases['root-' . $key] = [[$key => $value], $key];
        }
        return $cases;
    }

    #[DataProvider('invalidSettings')]
    public function test_request_rejects_unsupported_settings(array $override, string $error): void
    {
        $validator = $this->validate(array_replace($this->payload(), $override));
        self::assertFalse($validator->passes());
        self::assertTrue($validator->errors()->has($error), json_encode($validator->errors()->toArray()));
    }

    public function test_model_blocks_machine_binding_on_quick_update_and_type_conversion(): void
    {
        foreach ([Server::TYPE_SNELL, Server::TYPE_VMESS] as $type) {
            $node = $this->node();
            $node->type = $type;
            $node->save();
            $node->machine_id = 9;
            $node->type = Server::TYPE_SNELL;
            try {
                $node->save();
                self::fail('Machine binding must fail.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('machine_id', $e->errors());
                self::assertNull($node->fresh()->machine_id);
            }
        }
    }

    public function test_protocol_conversion_rejects_retained_unsupported_configuration(): void
    {
        foreach (['route_ids' => [1], 'custom_outbounds' => [['type' => 'direct']],
            'custom_routes' => [['action' => 'direct']], 'cert_config' => ['mode' => 'http']] as $field => $value) {
            $node = $this->node();
            $node->type = Server::TYPE_VMESS;
            $node->{$field} = $value;
            $node->save();
            $node->type = Server::TYPE_SNELL;
            try {
                $node->save();
                self::fail('Retained unsupported settings must fail.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors());
                self::assertSame(Server::TYPE_VMESS, $node->fresh()->type);
            }
        }
    }

    public function test_admin_conversion_clears_legacy_fields_after_request_validation(): void
    {
        $node = $this->node();
        $node->type = Server::TYPE_VMESS;
        $node->machine_id = 9;
        $node->route_ids = [1];
        $node->custom_outbounds = [['type' => 'direct']];
        $node->custom_routes = [['action' => 'direct']];
        $node->cert_config = ['mode' => 'http'];
        $node->save();
        $payload = $this->payload() + ['id' => $node->id, 'machine_id' => null, 'route_ids' => []];
        $request = ServerSave::create('/', 'POST', $payload);
        $request->setValidator($this->validate($payload));
        $cooldown = Mockery::mock(\App\Services\ServerReconnectCooldownService::class);
        $cooldown->shouldReceive('reset')->once();
        $this->app->instance(\App\Services\ServerReconnectCooldownService::class, $cooldown);
        $this->app->make(ServerTrafficLimitService::class)->shouldReceive('refreshSchedule')->once();
        $response = (new \App\Http\Controllers\V2\Admin\Server\ManageController())->save($request);
        self::assertSame(200, $response->getStatusCode());
        $node = $node->fresh();
        self::assertSame(Server::TYPE_SNELL, $node->type);
        foreach (['machine_id', 'route_ids', 'custom_outbounds', 'custom_routes', 'cert_config'] as $field) {
            self::assertTrue(blank($node->{$field}), $field . ' must be cleared');
        }
    }

    public function test_forwarding_keeps_own_ports_and_parent_runtime_status(): void
    {
        $parent = $this->node();
        $parent->save();
        $child = $this->node();
        $child->parent_id = $parent->id;
        $child->host = 'relay.example.test';
        $child->port = 9443;
        $child->server_port = 2443;
        $child->save();
        $child->setRelation('parent', $parent);
        Cache::put(CacheKey::get('SERVER_SNELL_LAST_CHECK_AT', $parent->id), time());
        Cache::put(CacheKey::get('SERVER_SNELL_LAST_PUSH_AT', $parent->id), time());
        self::assertSame(Server::STATUS_ONLINE, $child->available_status);
        self::assertSame(2443, ServerService::buildNodeConfig($child)['server_port']);
        self::assertSame('relay.example.test', ClashMeta::buildSnell('user-uuid', $child)['server']);
        self::assertSame(9443, ClashMeta::buildSnell('user-uuid', $child)['port']);
    }

    public function test_agent_config_and_uniproxy_base_config_and_etag(): void
    {
        $node = $this->node(['obfs' => 'http', 'obfs_host' => 'cdn.example.test']);
        $expected = ['protocol' => 'snell', 'listen_ip' => '0.0.0.0', 'server_port' => 1443,
            'version' => 4, 'obfs' => 'http', 'obfs_host' => '', 'udp' => false,
            'traffic_limit' => ['enabled' => false]];
        self::assertSame($expected, ServerService::buildNodeConfig($node));
        $request = Request::create('/api/v1/server/UniProxy/config');
        $request->attributes->set('node_info', $node);
        $controller = new UniProxyController(new DeviceStateService());
        $response = $controller->config($request);
        self::assertSame($expected + ['base_config' => ['push_interval' => 60, 'pull_interval' => 60]], json_decode($response->getContent(), true));
        $request->headers->set('If-None-Match', $response->headers->get('ETag'));
        self::assertSame(304, $controller->config($request)->getStatusCode());
    }

    public function test_snell_agent_receives_panel_quota_suspension(): void
    {
        $limit = ['enabled' => true, 'limit' => 1024, 'current_used' => 2048, 'status' => 'suspended'];
        $service = Mockery::mock(ServerTrafficLimitService::class);
        $service->shouldReceive('buildNodeConfig')->once()->andReturn($limit);
        $this->app->instance(ServerTrafficLimitService::class, $service);
        self::assertSame($limit, ServerService::buildNodeConfig($this->node())['traffic_limit']);
    }

    public function test_existing_user_endpoint_supplies_distinct_uuid_psks_and_heartbeat(): void
    {
        $node = $this->node();
        $node->group_ids = [7];
        $node->save();
        $uuids = ['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'];
        foreach ($uuids as $uuid) {
            $this->db->table('v2_user')->insert(['uuid' => $uuid, 'group_id' => 7]);
            self::assertSame($uuid, $node->generateServerPassword(new User(['uuid' => $uuid])));
        }
        $request = Request::create('/api/v1/server/UniProxy/user');
        $request->attributes->set('node_info', $node);
        $response = (new UniProxyController(new DeviceStateService()))->user($request);
        $users = json_decode($response->getContent(), true)['users'];
        self::assertSame($uuids, array_column($users, 'uuid'));
        self::assertSame([0, 0], array_column($users, 'speed_limit'));
        self::assertSame([0, 0], array_column($users, 'device_limit'));
        self::assertNotNull(Cache::get(CacheKey::get('SERVER_SNELL_LAST_CHECK_AT', $node->id)));
    }

    public function test_existing_report_endpoint_handles_snell_heartbeat_and_connections(): void
    {
        $node = $this->node();
        $node->save();
        $request = new class extends Request {
            public function validate(array $rules): array
            {
                return app('validator')->make($this->all(), $rules)->validate();
            }
        };
        $request->replace(['traffic' => [], 'online' => [1 => 3]]);
        $request->attributes->set('node_info', $node);
        $response = (new ServerController())->report($request);
        self::assertSame(['data' => true], $response->getData(true));
        self::assertNotNull(Cache::get(CacheKey::get('SERVER_SNELL_LAST_CHECK_AT', $node->id)));
        self::assertSame(3, Cache::get(CacheKey::get('USER_ONLINE_CONN_snell_' . $node->id, 1)));
    }

    public function test_client_routing_recognizes_mihomo_and_party_without_using_app_version(): void
    {
        Cache::store('redis')->forever('subscribe_template:clashmeta', "proxies: []\nproxy-groups: []\nrules: []\n");
        $manager = new ProtocolManager($this->app);
        (new ReflectionProperty(ProtocolManager::class, 'protocolClasses'))->setValue($manager, [Clash::class, ClashMeta::class, General::class, Surge::class]);
        $this->app->instance('protocols.manager', $manager);
        $this->app->instance('protocols.flags', $manager->getAllFlags());
        $user = ['uuid' => 'user-uuid', 'token' => 'test-token', 'u' => 0, 'd' => 0, 'transfer_enable' => 1024, 'expired_at' => null];
        $node = $this->payload(['version' => 4]);
        foreach (['mihomo/1.19.25' => 0, 'mihomo/1.19.26' => 1, 'Clash.Meta/1.19.26' => 1,
            'ClashParty/1.7.0' => 1, 'Clash Party/1.7.0 mihomo/1.19.25' => 0] as $ua => $count) {
            $request = Request::create('https://panel.example.test/api/v1/client/subscribe', 'GET', [], [], [], ['HTTP_USER_AGENT' => $ua]);
            $this->app->instance('request', $request);
            self::assertSame(ClashMeta::class, $manager->matchProtocolClassName($ua));
            $response = (new ClientController())->doSubscribe($request, $user, [$node]);
            self::assertCount($count, Yaml::parse($response->getContent())['proxies'], $ua);
        }
    }

    public function test_surge_unknown_version_keeps_existing_hysteria_behavior(): void
    {
        $hysteria = ['type' => Server::TYPE_HYSTERIA, 'protocol_settings' => ['version' => 2]];
        self::assertSame([$hysteria], $this->filtered(new Surge([], [$hysteria], 'surge')));
    }

    public function test_mihomo_and_surge_subscription_responses_use_user_uuid(): void
    {
        Cache::store('redis')->forever('subscribe_template:clashmeta', "proxies: []
proxy-groups:
  - name: Auto
    type: select
    proxies: []
rules: []
");
        Cache::store('redis')->forever('subscribe_template:surge', "[Proxy]\n\$proxies\n[Proxy Group]\nAuto = select,\$proxy_group\n");
        $node = $this->payload(['version' => 4, 'obfs' => 'http', 'obfs_host' => 'cdn.example.test']);
        $node['password'] = 'stale-shared-password';
        foreach (['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'] as $uuid) {
            $user = ['uuid' => $uuid, 'token' => 'test-token', 'u' => 0, 'd' => 0, 'transfer_enable' => 1024, 'expired_at' => null];
            $yaml = Yaml::parse((new ClashMeta($user, [$node], 'mihomo', '1.19.26', 'mihomo/1.19.26'))->handle()->getContent());
            self::assertSame($uuid, $yaml['proxies'][0]['psk']);
            self::assertSame(4, $yaml['proxies'][0]['version']);
            self::assertFalse($yaml['proxies'][0]['udp']);
            self::assertSame(['mode' => 'http', 'host' => 'cdn.example.test'], $yaml['proxies'][0]['obfs-opts']);
            self::assertContains('Snell Node', $yaml['proxy-groups'][0]['proxies']);
            $surge = (new Surge($user, [$node], 'surge', '3000'))->handle()->getContent();
            self::assertStringContainsString("Snell Node = snell,snell.example.test,8443,psk={$uuid},version=4,obfs=http,obfs-host=cdn.example.test", $surge);
            self::assertStringContainsString('Auto = select,Snell Node', $surge);
            self::assertStringNotContainsString('stale-shared-password', $surge);
        }
    }

    public function test_obfs_off_omits_options_and_empty_http_host_is_optional(): void
    {
        foreach (['off', 'http'] as $obfs) {
            $server = $this->payload(['version' => 4, 'obfs' => $obfs, 'obfs_host' => '']);
            $mihomo = ClashMeta::buildSnell('user-uuid', $server);
            $surge = Surge::buildSnell('user-uuid', $server);
            self::assertSame('user-uuid', $mihomo['psk']);
            self::assertFalse($mihomo['udp']);
            self::assertSame($obfs === 'http', isset($mihomo['obfs-opts']));
            self::assertStringNotContainsString('obfs-host=', $surge);
            self::assertStringNotContainsString('udp-relay=true', $surge);
            if ($obfs === 'off') self::assertStringNotContainsString('obfs=', $surge);
        }
    }

    public static function kernelVersions(): array
    {
        return [
            ['mihomo', '1.19.25', 'mihomo/1.19.25', false],
            ['meta', '1.19.26', 'Clash.Meta/v1.19.26', true],
            ['meta', '1.19.25', 'Clash.Meta/1.19.25', false],
            ['mihomo', '1.19.26', 'mihomo/1.19.26', true],
            ['mihomo', '1.19.26-alpha', 'mihomo/1.19.26-alpha', false],
            ['mihomo', '1.20.0', 'mihomo/1.20.0', true],
            ['meta', '1.19.25', null, false],
            ['clashparty', '1.7.0', 'ClashParty/1.7.0', true],
            ['meta', '1.7.0', 'Clash Party/1.7.0', true],
            ['clashparty', '9.0.0', 'ClashParty/9.0.0 mihomo/1.19.25', false],
            ['clashparty', '1.0.0', 'ClashParty/1.0.0 mihomo/1.19.26', true],
            ['verge', '1.6.0', 'Clash Verge/1.6.0', true],
            ['nekobox', '1.4.0', 'NekoBox/1.4.0', false],
            ['nekoray', '4.0.0', 'nekoray/4.0.0', false],
            [null, null, null, true],
        ];
    }

    #[DataProvider('kernelVersions')]
    public function test_only_known_kernel_versions_gate_snell(?string $name, ?string $version, ?string $ua, bool $expected): void
    {
        $servers = $this->filtered(new ClashMeta([], [$this->payload(['version' => 4])], $name, $version, $ua));
        self::assertCount($expected ? 1 : 0, $servers);
    }

    public function test_unsupported_clients_and_non_v4_nodes_are_filtered(): void
    {
        $node = $this->payload(['version' => 4]);
        foreach ([General::class, Clash::class, SingBox::class, Stash::class, Shadowrocket::class] as $class) {
            self::assertSame([], $this->filtered(new $class([], [$node])), $class);
        }
        foreach ([ClashMeta::class, Surge::class] as $class) {
            self::assertSame([], $this->filtered(new $class([], [$this->payload(['version' => 3])])));
        }
    }

    public static function existingProtocols(): array
    {
        return [
            ['shadowsocks', ['cipher' => 'aes-128-gcm'], ['cipher' => 'aes-128-gcm']],
            ['vmess', ['tls' => 0, 'network' => 'ws'], ['tls' => 0, 'network' => 'ws']],
            ['vless', ['tls' => 0], ['tls' => 0, 'decryption' => null]],
            ['trojan', ['tls' => 1], ['tls' => 1]],
            ['hysteria', ['version' => 2, 'tls' => [], 'bandwidth' => [], 'obfs' => []], ['version' => 2, 'obfs' => null]],
            ['tuic', ['tls' => []], ['version' => 5, 'congestion_control' => 'cubic']],
            ['anytls', ['tls' => []], ['server_name' => null]],
            ['socks', [], ['tls' => 0]],
            ['naive', [], ['tls' => 0]],
            ['http', [], ['tls' => 0]],
            ['mieru', ['transport' => 'TCP'], ['transport' => 'TCP']],
        ];
    }

    #[DataProvider('existingProtocols')]
    public function test_existing_protocol_agent_configs_are_preserved(string $type, array $settings, array $expected): void
    {
        $traffic = Mockery::mock(ServerTrafficLimitService::class);
        $traffic->shouldReceive('buildNodeConfig')->once()->andReturn(['enabled' => false]);
        $access = Mockery::mock(AccessTrafficService::class);
        $access->shouldReceive('nodeConfig')->once()->andReturn(['enabled' => false]);
        $this->app->instance(ServerTrafficLimitService::class, $traffic);
        $this->app->instance(AccessTrafficService::class, $access);
        $node = new Server(['type' => $type, 'host' => 'example.test', 'server_port' => 443, 'protocol_settings' => $settings]);
        $config = ServerService::buildNodeConfig($node);
        self::assertSame($type, $config['protocol']);
        self::assertSame(443, $config['server_port']);
        self::assertSame(['enabled' => false], $config['traffic_limit']);
        self::assertSame(['enabled' => false], $config['access_log']);
        foreach ($expected as $key => $value) self::assertSame($value, $config[$key], $type . '.' . $key);
    }
}
