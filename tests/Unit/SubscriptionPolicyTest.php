<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\V2\Admin\SubscriptionPolicyController;
use App\Http\Routes\V2\AdminRoute;
use App\Models\Server;
use App\Models\Setting;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Surge;
use App\Protocols\Surfboard;
use App\Services\SubscriptionPolicyService;
use App\Services\ServerService;
use App\Support\ProtocolManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Yaml\Yaml;

class SubscriptionPolicyTest extends TestCase
{
    private Container $app;
    private Manager $db;
    private SubscriptionPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Container();
        Container::setInstance($this->app);
        $this->app->instance('app', $this->app);
        $this->app->instance('config', new Repository([
            'app' => ['timezone' => 'UTC', 'key' => 'fixture'],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array'], 'redis' => ['driver' => 'array']]],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('cache', new CacheManager($this->app));
        Cache::store('redis')->forever('admin_settings', ['secure_path' => 'fixture']);
        $this->app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $this->app));
        $this->app->instance(ResponseFactoryContract::class, new ResponseFactory(Mockery::mock(ViewFactory::class), Mockery::mock(Redirector::class)));
        $request = Request::create('https://panel.example.test/s/fixture-token');
        $this->app->instance('request', $request);
        $routes = new \Illuminate\Routing\RouteCollection();
        $routes->add((new \Illuminate\Routing\Route('GET', 's/{token}', fn () => null))->name('client.subscribe'));
        $this->app->instance('url', new \Illuminate\Routing\UrlGenerator($routes, $request));
        $this->app->alias('url', \Illuminate\Contracts\Routing\UrlGenerator::class);
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        Model::clearBootedModels();
        $this->db->schema()->create('v2_settings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Cache::store('redis')->forever('subscribe_template:clashmeta', "proxies: []\nproxy-groups:\n  - name: Auto\n    type: select\n    proxies: []\nrules: []\n");
        Cache::store('redis')->forever('subscribe_template:surge', "#!MANAGED-CONFIG \$subs_link interval=43200\n[Proxy]\n\$proxies\n[Proxy Group]\nAuto = select,\$proxy_group\n");
        $manager = new ProtocolManager($this->app);
        (new ReflectionProperty($manager, 'protocolClasses'))->setValue($manager, [ClashMeta::class, Surge::class, Surfboard::class, General::class]);
        $this->app->instance('protocols.manager', $manager);
        $this->app->instance('protocols.flags', $manager->getAllFlags());
        $this->service = new SubscriptionPolicyService();
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

    public function test_defaults_require_explicit_snell_opt_in_only(): void
    {
        $settings = $this->service->settings();
        self::assertSame(Server::VALID_TYPES, array_column($settings['rules'], 'protocol'));
        foreach ($settings['rules'] as $rule) {
            self::assertSame($rule['protocol'] !== 'snell', $rule['default_enabled']);
            self::assertTrue($rule['allow_override']);
        }
        self::assertSame(['SOCKS Node'], array_column($this->service->filter($this->nodes(), Request::create('/')), 'name'));
    }

    public function test_only_explicit_query_values_override_default_distribution(): void
    {
        foreach ([[], ['snell' => 'true'], ['snell' => 'false'], ['snell' => ''], ['snell' => ['1']], ['snell' => 'yes']] as $query) {
            self::assertCount(1, $this->service->filter($this->nodes(), Request::create('/', 'GET', $query)));
        }
        self::assertCount(2, $this->service->filter($this->nodes(), Request::create('/', 'GET', ['snell' => '1'])));
        self::assertSame(['Snell Node'], array_column($this->service->filter($this->nodes(), Request::create('/', 'GET', ['snell' => '1', 'socks' => '0'])), 'name'));
        self::assertCount(1, $this->service->filter($this->nodes(), Request::create('/', 'POST', ['snell' => '1'])));
    }

    public function test_saved_rules_persist_and_new_parameter_replaces_old_one(): void
    {
        $rules = $this->payload();
        $rules[$this->index('snell')]['parameter'] = 'modern_snell';
        $saved = $this->service->save(['rules' => $rules]);
        self::assertSame('modern_snell', $saved['rules'][$this->index('snell')]['parameter']);
        self::assertCount(1, $this->service->filter($this->nodes(), Request::create('/', 'GET', ['snell' => '1'])));
        self::assertCount(2, (new SubscriptionPolicyService())->filter($this->nodes(), Request::create('/', 'GET', ['modern_snell' => '1'])));
        self::assertNotNull(Setting::where('name', SubscriptionPolicyService::SETTING_KEY)->first());
    }

    public function test_disabling_override_enforces_default_for_include_and_exclude(): void
    {
        $rules = $this->payload();
        $rules[$this->index('snell')]['allow_override'] = false;
        $rules[$this->index('socks')]['allow_override'] = false;
        $this->service->save(['rules' => $rules]);
        $filtered = $this->service->filter($this->nodes(), Request::create('/', 'GET', ['snell' => '1', 'socks' => '0']));
        self::assertSame(['SOCKS Node'], array_column($filtered, 'name'));
        $rules[$this->index('snell')]['default_enabled'] = true;
        $this->service->save(['rules' => $rules]);
        self::assertCount(2, $this->service->filter($this->nodes(), Request::create('/', 'GET', ['snell' => '0'])));
    }

    public function test_invalid_rules_do_not_change_persisted_policy(): void
    {
        $valid = $this->payload();
        $this->service->save(['rules' => $valid]);
        $invalid = [];
        $invalid[] = array_slice($valid, 1);
        $duplicate = $valid;
        $duplicate[0]['protocol'] = $duplicate[1]['protocol'];
        $invalid[] = $duplicate;
        foreach (['token', 'flag', 'types', 'filter', 'bad-name', 'Upper', '0name', '', str_repeat('a', 33), 'socks'] as $parameter) {
            $rules = $valid;
            $rules[$this->index('snell')]['parameter'] = $parameter;
            $invalid[] = $rules;
        }
        $unknown = $valid;
        $unknown[0]['protocol'] = 'unknown';
        $invalid[] = $unknown;
        $badBoolean = $valid;
        $badBoolean[0]['default_enabled'] = 'yes';
        $invalid[] = $badBoolean;
        foreach ($invalid as $rules) {
            try {
                $this->service->save(['rules' => $rules]);
                self::fail('Expected policy validation failure');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
            self::assertSame($valid, $this->payload());
        }
    }

    public function test_real_subscription_defaults_off_and_opt_in_preserves_other_nodes(): void
    {
        self::assertSame(['SOCKS Node'], $this->yamlNames($this->subscribe([], 'mihomo/1.19.26')));
        $config = Yaml::parse($this->subscribe(['snell' => '1'], 'mihomo/1.19.26'));
        self::assertSame(['Snell Node', 'SOCKS Node'], array_column($config['proxies'], 'name'));
        self::assertSame(['Snell Node', 'SOCKS Node'], $config['proxy-groups'][0]['proxies']);
        self::assertSame('fixture-uuid', $config['proxies'][0]['psk']);
        self::assertSame(['SOCKS Node'], $this->yamlNames($this->subscribe(['snell' => '0'], 'mihomo/1.19.26')));
    }

    public function test_old_kernel_is_filtered_even_when_query_selects_newer_format_flag(): void
    {
        foreach ([[], ['flag' => 'meta'], ['flag' => 'mihomo/1.20.0']] as $query) {
            self::assertNotContains('Snell Node', $this->yamlNames($this->subscribe($query + ['snell' => '1'], 'mihomo/1.19.25')));
        }
        self::assertContains('Snell Node', $this->yamlNames($this->subscribe(['snell' => '1', 'flag' => 'meta'], 'mihomo/1.19.26')));
    }

    public function test_protocol_filter_does_not_override_explicit_opt_in_or_expand_empty_selection(): void
    {
        self::assertSame([], $this->yamlNames($this->subscribe(['types' => 'snell'], 'mihomo/1.19.26')));
        self::assertSame(['过滤掉1条线路', 'Snell Node'], $this->yamlNames($this->subscribe(['types' => 'snell', 'snell' => '1'], 'mihomo/1.19.26')));
        self::assertSame([], $this->yamlNames($this->subscribe(['types' => 'unknown', 'snell' => '1'], 'mihomo/1.19.26')));
        self::assertNotContains('Snell Node', $this->yamlNames($this->subscribe(['types' => 'socks', 'snell' => '1'], 'mihomo/1.19.26')));
    }

    public function test_surge_still_needs_opt_in_and_prefixes_match_group_references(): void
    {
        $without = $this->subscribe([], 'surge/3000');
        self::assertStringNotContainsString(' = snell,', $without);
        admin_setting(['show_protocol_to_server_enable' => true]);
        $with = $this->subscribe(['snell' => '1'], 'surge/3000');
        self::assertStringContainsString('https://panel.example.test/s/fixture-token?snell=1', $with);
        self::assertStringContainsString('[snell]Snell Node = snell,', $with);
        self::assertStringContainsString('Auto = select,[snell]Snell Node', $with);
        $yaml = Yaml::parse($this->subscribe(['snell' => '1'], 'mihomo/1.19.26'));
        self::assertSame('[snell]Snell Node', $yaml['proxies'][0]['name']);
        self::assertContains('[snell]Snell Node', $yaml['proxy-groups'][0]['proxies']);
    }

    public function test_surfboard_managed_refresh_preserves_protocol_exclusion(): void
    {
        Cache::store('redis')->forever('subscribe_template:surfboard', "#!MANAGED-CONFIG \$subs_link interval=43200\n[Proxy]\n\$proxies\n");
        $content = $this->subscribe(['socks' => '0', 'flag' => 'surfboard'], 'surfboard/1.0');
        self::assertStringContainsString('/s/fixture-token?flag=surfboard&socks=0', $content);
    }

    public function test_refresh_url_preserves_active_custom_parameters_and_omits_unrelated_input(): void
    {
        $rules = $this->payload();
        $rules[$this->index('snell')]['parameter'] = 'new_snell';
        $this->service->save(['rules' => $rules]);
        $query = ['flag' => 'surge', 'new_snell' => '1', 'socks' => '0', 'token' => 'ignored', 'secret' => 'ignored', 'snell' => '1', 'http' => ['1']];
        self::assertSame(['flag' => 'surge', 'socks' => '0', 'new_snell' => '1'], $this->service->queryForRefresh(Request::create('/', 'GET', $query)));
        $surge = $this->subscribe(['new_snell' => '1', 'flag' => 'surge', 'types' => 'snell'], 'surge/3000');
        self::assertStringContainsString('flag=surge&types=snell&new_snell=1', $surge);
    }

    public function test_opt_in_does_not_create_nodes_missing_from_authorized_candidates(): void
    {
        $onlyOrdinary = [$this->nodes()[1]];
        $filtered = $this->service->filter($onlyOrdinary, Request::create('/', 'GET', ['snell' => '1']));
        self::assertSame($onlyOrdinary, $filtered);
    }

    public function test_management_controller_returns_persisted_rules_and_routes_require_admin(): void
    {
        $controller = new SubscriptionPolicyController();
        self::assertCount(count(Server::VALID_TYPES), $controller->fetch($this->service)->getData(true)['data']['rules']);
        $rules = $this->payload();
        $rules[$this->index('snell')]['parameter'] = 'opt_snell';
        $response = $controller->save(Request::create('/', 'POST', ['rules' => $rules]), $this->service);
        self::assertSame('opt_snell', $response->getData(true)['data']['rules'][$this->index('snell')]['parameter']);
        $router = new Router(new Dispatcher($this->app), $this->app);
        (new AdminRoute())->map($router);
        $found = 0;
        foreach ($router->getRoutes() as $route) {
            if (str_contains($route->uri(), 'subscribe-policy/')) {
                self::assertContains('admin', $route->middleware());
                $found++;
            }
        }
        self::assertSame(2, $found);
    }

    public static function rateNameCases(): array
    {
        return [
            'integer' => ['Node', 4, '4x Node'],
            'decimal' => ['Node', 1.5, '1.5x Node'],
            'fraction' => ['Node', 0.25, '0.25x Node'],
            'unit rate' => ['Node', 1, '1x Node'],
            'zero rate' => ['Node', 0, '0x Node'],
            'trailing zeros' => ['Node', '4.00', '4x Node'],
            'existing integer' => ['4x Node', 1.5, '4x Node'],
            'existing decimal' => ['1.25x Node', 4, '1.25x Node'],
            'existing uppercase' => ['1.5X Node', 4, '1.5X Node'],
            'existing spacing' => [' 1.5 x Node', 4, ' 1.5 x Node'],
            'existing fraction' => ['.5x Node', 4, '.5x Node'],
            'embedded rate' => ['Node 4x', 1.5, '1.5x Node 4x'],
            'numeric name' => ['2026 Node', 4, '4x 2026 Node'],
            'missing rate' => ['Node', null, 'Node'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rateNameCases')]
    public function test_subscription_adds_rate_prefix_and_preserves_existing_prefixes(string $name, mixed $rate, string $expected): void
    {
        $node = array_merge($this->nodes()[1], ['name' => $name, 'rate' => $rate]);
        $general = base64_decode($this->subscribe([], 'general', [$node]), true);
        self::assertSame($expected, rawurldecode(parse_url(trim($general), PHP_URL_FRAGMENT)));

        $yaml = Yaml::parse($this->subscribe([], 'mihomo/1.19.26', [$node]));
        self::assertSame([$expected], array_column($yaml['proxies'], 'name'));
        self::assertSame([$expected], $yaml['proxy-groups'][0]['proxies']);

        $surge = $this->subscribe([], 'surge/3000', [$node]);
        self::assertStringContainsString($expected . ' = ', $surge);
        self::assertStringContainsString('Auto = select,' . $expected, $surge);
    }

    public function test_rate_prefix_keeps_protocol_prefixes_and_filter_notices_consistent(): void
    {
        $nodes = $this->nodes();
        $nodes[0]['rate'] = 4;
        $nodes[1]['rate'] = 1.5;
        admin_setting(['show_protocol_to_server_enable' => true]);

        $yaml = Yaml::parse($this->subscribe(['snell' => '1', 'types' => 'socks'], 'mihomo/1.19.26', $nodes));
        $expected = ['[socks]过滤掉1条线路', '[socks]1.5x SOCKS Node'];
        self::assertSame($expected, array_column($yaml['proxies'], 'name'));
        self::assertSame($expected, $yaml['proxy-groups'][0]['proxies']);
    }

    public static function subscriptionLabelNameCases(): array
    {
        return [
            'requested format' => ['台湾mhy 京粤闽苏专线', 4, '高级', '4x (高级) 台湾mhy 京粤闽苏专线'],
            'fractional rate' => ['台湾专线', 0.25, '高级', '0.25x (高级) 台湾专线'],
            'existing rate' => ['4x 台湾专线', 1.5, '高级', '4x (高级) 台湾专线'],
            'existing annotation' => ['4x (高级) 台湾专线', 4, '高级', '4x (高级) 台湾专线'],
            'existing fullwidth annotation' => ['（高级）台湾专线', 4, '高级', '4x （高级）台湾专线'],
            'preserve other note' => ['(台湾) 专线', 4, '高级', '4x (高级) (台湾) 专线'],
            'no rate' => ['台湾专线', null, '高级', '(高级) 台湾专线'],
            'blank label' => ['台湾专线', 4, ' ', '4x 台湾专线'],
            'normalize whitespace' => ['台湾专线', 4, "高级\n用户", '4x (高级 用户) 台湾专线'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('subscriptionLabelNameCases')]
    public function test_subscription_label_names_match_proxy_and_group_references(string $name, mixed $rate, string $label, string $expected): void
    {
        $node = array_merge($this->nodes()[1], ['name' => $name, 'rate' => $rate,
            'subscription_label_id' => 2, 'subscription_label' => ['id' => 2, 'name' => $label]]);
        $formatted = ServerService::formatSubscriptionServerNames([$node]);
        self::assertSame($expected, $formatted[0]['name']);
        self::assertSame($formatted, ServerService::formatSubscriptionServerNames($formatted));
        self::assertSame($name, $node['name']);
        $general = base64_decode($this->subscribe([], 'general', [$node]), true);
        self::assertSame($expected, rawurldecode(parse_url(trim($general), PHP_URL_FRAGMENT)));
        $yaml = Yaml::parse($this->subscribe([], 'mihomo/1.19.26', [$node]));
        self::assertSame([$expected], array_column($yaml['proxies'], 'name'));
        self::assertSame([$expected], $yaml['proxy-groups'][0]['proxies']);
        $surge = $this->subscribe([], 'surge/3000', [$node]);
        self::assertStringContainsString($expected . ' = ', $surge);
        self::assertStringContainsString('Auto = select,' . $expected, $surge);
    }

    public function test_label_rename_clear_and_protocol_prefix_do_not_duplicate_or_reuse_stale_annotations(): void
    {
        $node = array_merge($this->nodes()[1], ['name' => '台湾专线', 'rate' => 4,
            'subscription_label_id' => 2, 'subscription_label' => ['id' => 2, 'name' => '高级']]);
        self::assertSame('4x (高级) 台湾专线', ServerService::formatSubscriptionServerNames([$node])[0]['name']);
        $node['subscription_label']['name'] = '旗舰';
        admin_setting(['show_protocol_to_server_enable' => true]);
        $yaml = Yaml::parse($this->subscribe([], 'mihomo/1.19.26', [$node]));
        self::assertSame('[socks]4x (旗舰) 台湾专线', $yaml['proxies'][0]['name']);
        self::assertSame(['[socks]4x (旗舰) 台湾专线'], $yaml['proxy-groups'][0]['proxies']);
        $node['subscription_label_id'] = null;
        self::assertSame('4x 台湾专线', ServerService::formatSubscriptionServerNames([$node])[0]['name']);
        $node['subscription_label_id'] = 999;
        self::assertSame('4x 台湾专线', ServerService::formatSubscriptionServerNames([$node])[0]['name']);
        $node['subscription_label'] = null;
        self::assertSame('4x 台湾专线', ServerService::formatSubscriptionServerNames([$node])[0]['name']);
    }

    private function index(string $protocol): int
    {
        return array_search($protocol, Server::VALID_TYPES, true);
    }

    private function payload(): array
    {
        return array_map(fn ($rule) => array_intersect_key($rule, array_flip(['protocol', 'default_enabled', 'allow_override', 'parameter'])), $this->service->settings()['rules']);
    }

    private function nodes(): array
    {
        return [
            ['type' => 'snell', 'name' => 'Snell Node', 'host' => 'snell.example.test', 'port' => 8443, 'password' => 'fixture-uuid', 'protocol_settings' => ['version' => 4, 'obfs' => 'off', 'obfs_host' => '', 'udp' => false]],
            ['type' => 'socks', 'name' => 'SOCKS Node', 'host' => 'socks.example.test', 'port' => 1080, 'password' => 'fixture-uuid', 'protocol_settings' => ['tls' => 0]],
        ];
    }

    private function subscribe(array $query, string $userAgent, ?array $servers = null): string
    {
        $request = Request::create('https://panel.example.test/s/fixture-token', 'GET', $query, [], [], ['HTTP_USER_AGENT' => $userAgent]);
        $this->app->instance('request', $request);
        $user = ['uuid' => 'fixture-uuid', 'token' => 'fixture-token', 'u' => 0, 'd' => 0, 'transfer_enable' => 1024, 'expired_at' => null];
        return (new ClientController())->doSubscribe($request, $user, $servers ?? $this->nodes())->getContent();
    }

    private function yamlNames(string $content): array
    {
        return array_column(Yaml::parse($content)['proxies'], 'name');
    }
}
