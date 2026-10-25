<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\User\ActivityController as UserActivityController;
use App\Http\Controllers\V2\Admin\ActivityController as AdminActivityController;
use App\Models\AiCreditSetting;
use App\Models\AiTopupSetting;
use App\Models\MarketingActivity;
use App\Models\User;
use App\Services\MarketingActivityService;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class MarketingActivityServiceTest extends TestCase
{
    private Container $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousNow;
    private array $previousColumns;
    private Manager $database;
    private MarketingActivityService $service;
    private User $user;
    private const NOW = 1792936800;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousNow = Carbon::getTestNow();
        $columns = new ReflectionProperty(Model::class, 'guardableColumns');
        $this->previousColumns = $columns->getValue();
        $columns->setValue(null, []);
        $app = new Container();
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::unsetEventDispatcher();
        $app->instance('app', $app);
        $app->instance('config', new Repository(['app' => ['timezone' => 'UTC'],
            'cache' => ['default' => 'redis', 'stores' => ['redis' => ['driver' => 'array']]]]));
        $app->instance('cache', new CacheManager($app));
        $app->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $app));
        $this->database = new Manager($app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $app->instance('db', $this->database->getDatabaseManager());
        $app->instance('db.schema', $this->schema());
        $this->schema()->create('v2_settings', function ($table) {
            $table->id(); $table->string('name')->unique(); $table->text('value')->nullable();
            $table->integer('created_at'); $table->integer('updated_at');
        });
        $this->migrate();
        Carbon::setTestNow(Carbon::createFromTimestampUTC(self::NOW));
        $this->service = new MarketingActivityService();
        $this->user = new User(['commission_rate' => 0]);
        admin_setting(['invite_commission' => 15]);
        $responses = $this->createMock(ResponseFactory::class);
        $responses->method('json')->willReturnCallback(fn ($data = [], $status = 200, $headers = [], $options = 0)
            => new JsonResponse($data, $status, $headers, $options));
        $app->instance(ResponseFactory::class, $responses);
        $app->instance(HttpFactory::class, new HttpFactory());
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        $this->previousResolver ? Model::setConnectionResolver($this->previousResolver) : Model::unsetConnectionResolver();
        $this->previousDispatcher ? Model::setEventDispatcher($this->previousDispatcher) : Model::unsetEventDispatcher();
        (new ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, $this->previousColumns);
        Carbon::setTestNow($this->previousNow);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
    }

    private function schema()
    {
        return $this->database->getConnection()->getSchemaBuilder();
    }

    private function migrate(): void
    {
        (require __DIR__ . '/../../database/migrations/2026_10_25_000003_create_marketing_activities_table.php')->up();
    }

    private function input(array $extra = []): array
    {
        return array_replace(['title' => 'Activity', 'summary' => 'Description', 'image_url' => '/activities/example.webp',
            'target_url' => '/dashboard/finance/invite', 'button_text' => 'Open'], $extra);
    }

    private function assertApiError(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected ApiException ' . $status);
        } catch (ApiException $e) {
            $this->assertSame($status, $e->getCode(), $e->getMessage());
        }
    }

    private function enableSources(): AiCreditSetting
    {
        $this->schema()->create('v2_order', fn ($table) => $table->id());
        (require __DIR__ . '/../../database/migrations/2026_10_25_000001_create_ai_credit_tables.php')->up();
        (require __DIR__ . '/../../database/migrations/2026_10_25_000002_create_ai_topup_tables.php')->up();
        $provider = AiCreditSetting::create(['id' => 1, 'instance_id' => 'fixture-credit',
            'settings' => ['enabled' => true, 'bridge_url' => 'https://provider.example.test'],
            'bridge_token' => 'synthetic-activity-test-provider-value']);
        AiTopupSetting::create(['id' => 1, 'instance_id' => 'fixture-topup',
            'settings' => ['enabled' => true, 'bonus_rate_bps' => 275]]);
        return $provider;
    }

    public function testMigrationHasNoAutomaticSeedsAndMissingTableIsGraceful(): void
    {
        $this->assertSame(0, MarketingActivity::count());
        $this->assertSame([], $this->service->fetchForUser($this->user));
        $this->schema()->drop('v2_marketing_activities');
        $this->assertSame([], $this->service->fetchForUser($this->user));
        $this->assertSame(['records' => [], 'total' => 0, 'current' => 1, 'page_size' => 20], $this->service->records([]));
        $this->assertApiError(422, fn () => $this->service->save($this->input()));
        $this->migrate();
        $this->assertSame(0, MarketingActivity::count());
    }

    public function testDraftFutureStartBoundaryEndBoundaryExpiredAndUnpublished(): void
    {
        $this->service->save($this->input(['code' => 'draft']));
        $this->service->save($this->input(['code' => 'future', 'published' => true, 'starts_at' => self::NOW + 1]));
        $start = $this->service->save($this->input(['code' => 'start', 'published' => true,
            'starts_at' => self::NOW, 'ends_at' => self::NOW + 1, 'sort' => 10]));
        $this->service->save($this->input(['code' => 'end', 'published' => true, 'ends_at' => self::NOW]));
        $this->service->save($this->input(['code' => 'expired', 'published' => true, 'ends_at' => self::NOW - 1]));
        $zero = $this->service->save($this->input(['code' => 'zero', 'published' => true, 'starts_at' => 0, 'sort' => 5]));
        $this->assertSame([$zero->id, $start->id], array_column($this->service->fetchForUser($this->user), 'id'));
        $statuses = array_column($this->service->records([])['records'], 'status', 'code');
        $this->assertSame(['zero' => 'active', 'start' => 'active', 'draft' => 'draft',
            'future' => 'scheduled', 'end' => 'ended', 'expired' => 'ended'], array_replace([
                'zero' => '', 'start' => '', 'draft' => '', 'future' => '', 'end' => '', 'expired' => '',
            ], $statuses));
        $this->service->publish($zero->id, 1, false);
        Carbon::setTestNow(Carbon::createFromTimestampUTC(self::NOW + 1));
        $this->assertSame(['future'], array_column($this->service->fetchForUser($this->user), 'code'));
    }

    public function testZeroEndTimestampIsNotTreatedAsMissing(): void
    {
        $model = $this->service->save($this->input(['published' => true, 'ends_at' => 0]));
        $this->assertSame(0, $model->ends_at);
        $this->assertSame('ended', $this->service->serialize($model)['status']);
        $this->assertSame([], $this->service->fetchForUser($this->user));
        $this->assertApiError(422, fn () => $this->service->save($this->input(['starts_at' => 0, 'ends_at' => 0])));
    }

    public function testSourcesUseRealSettingsAndNeverCallProvider(): void
    {
        $this->service->seedDefaults();
        $this->assertSame(['invite'], array_column($this->service->fetchForUser($this->user), 'source'));
        $this->assertSame('unavailable', $this->service->records([])['records'][1]['status']);
        $provider = $this->enableSources();
        $feed = $this->service->fetchForUser($this->user);
        $this->assertSame(['invite', 'ai_credit', 'ai_topup'], array_column($feed, 'source'));
        $this->assertStringContainsString('2.75%', $feed[2]['summary']);
        $this->assertStringNotContainsString('synthetic-activity', json_encode($feed));
        $provider->update(['settings' => ['enabled' => false, 'bridge_url' => 'https://provider.example.test']]);
        $this->assertSame(['invite', 'ai_topup'], array_column($this->service->fetchForUser($this->user), 'source'));
        AiTopupSetting::find(1)->update(['settings' => ['enabled' => false]]);
        $this->assertSame(['invite'], array_column($this->service->fetchForUser($this->user), 'source'));
        $provider->update(['settings' => ['enabled' => true, 'bridge_url' => 'https://provider.example.test'], 'bridge_token' => null]);
        $this->assertSame(['invite'], array_column($this->service->fetchForUser($this->user), 'source'));
        $provider->update(['bridge_token' => 'synthetic-activity-test-provider-value']);
        $this->schema()->drop('v2_ai_credit_grants');
        $this->assertSame(['invite'], array_column($this->service->fetchForUser($this->user), 'source'));
        $this->schema()->drop('v2_ai_credit_settings');
        $this->assertSame(['invite'], array_column($this->service->fetchForUser($this->user), 'source'));
        Http::assertNothingSent();
    }

    public function testGlobalAndPerUserCommissionAreDynamicAndStoredTemplatesStayLiteral(): void
    {
        $model = $this->service->save($this->input(['source' => 'invite', 'published' => true,
            'title' => 'Reward {invite_commission_percent}%', 'summary' => 'Traffic {invite_traffic_percent}%',
            'benefits' => ['Commission {invite_commission_percent}%']]));
        $this->assertSame('Reward 15%', $this->service->fetchForUser($this->user)[0]['title']);
        $other = new User(['commission_rate' => 27.5]);
        $this->assertSame('Reward 27.5%', $this->service->fetchForUser($other)[0]['title']);
        admin_setting(['invite_commission' => 18]);
        $feed = $this->service->fetchForUser($this->user)[0];
        $this->assertSame('Reward 18%', $feed['title']);
        $this->assertSame('Traffic 10%', $feed['summary']);
        $this->assertSame(['Commission 18%'], $feed['benefits']);
        $this->assertSame('Reward {invite_commission_percent}%', $model->fresh()->title);
        $this->assertSame('Reward {invite_commission_percent}%', $this->service->records([])['records'][0]['title']);
    }

    public function testSavePublishDeleteUseOptimisticVersionsAndPreserveFields(): void
    {
        $model = $this->service->save($this->input());
        $this->assertFalse($model->published);
        $this->assertSame(1, $model->version);
        $this->assertApiError(422, fn () => $this->service->save(['id' => $model->id, 'title' => 'Missing version']));
        $updated = $this->service->save(['id' => $model->id, 'version' => 1, 'title' => 'Updated', 'benefits' => ['Benefit']]);
        $this->assertSame(2, $updated->version);
        $this->assertSame('Description', $updated->summary);
        $this->assertSame(['Benefit'], $updated->benefits);
        $this->assertApiError(409, fn () => $this->service->save(['id' => $model->id, 'version' => 1, 'title' => 'Stale']));
        $this->assertApiError(409, fn () => $this->service->publish($model->id, 1, true));
        $this->assertApiError(409, fn () => $this->service->delete($model->id, 1));
        $published = $this->service->publish($model->id, 2, true);
        $this->assertSame(3, $published->version);
        $this->assertSame('Updated', $this->service->fetchForUser($this->user)[0]['title']);
        $unpublished = $this->service->publish($model->id, 3, false);
        $this->assertSame(4, $unpublished->version);
        $this->assertSame([], $this->service->fetchForUser($this->user));
        $this->service->delete($model->id, 4);
        $this->assertSame(0, MarketingActivity::count());
        $this->assertApiError(409, fn () => $this->service->save(['id' => $model->id, 'version' => 4, 'title' => 'Deleted']));
    }

    public function testAtomicVersionPredicateRejectsAWriteAfterTheRead(): void
    {
        $model = $this->service->save($this->input());
        $connection = $this->database->getConnection();
        $changed = false;
        $connection->beforeExecuting(function ($query) use (&$changed, $model, $connection) {
            if (!$changed && str_starts_with($query, 'update "v2_marketing_activities"')) {
                $changed = true;
                $connection->table('v2_marketing_activities')->where('id', $model->id)->update(['version' => 2, 'title' => 'Concurrent']);
            }
        });
        $this->assertApiError(409, fn () => $this->service->save(['id' => $model->id, 'version' => 1, 'title' => 'Overwrite']));
        $this->assertSame('Concurrent', $model->fresh()->title);
        $this->assertSame(2, $model->fresh()->version);
    }

    public function testCodeIsUniqueAndNullableAndSeedIsIdempotentWithoutOverwritingEdits(): void
    {
        $first = $this->service->seedDefaults();
        $this->assertCount(3, $first);
        $this->assertSame(['invite-friends', 'ai-credit-gift', 'ai-topup-traffic'], array_column(array_map(fn ($m) => $m->toArray(), $first), 'code'));
        $this->service->save(['id' => $first[0]->id, 'version' => 1, 'title' => 'Edited by admin', 'published' => false]);
        $second = $this->service->seedDefaults();
        $this->assertSame(3, MarketingActivity::count());
        $this->assertSame($first[0]->id, $second[0]->id);
        $this->assertSame('Edited by admin', $second[0]->title);
        $this->assertFalse($second[0]->published);
        $this->assertSame(2, $second[0]->version);
        $this->assertApiError(422, fn () => $this->service->save($this->input(['code' => 'invite-friends'])));
        $this->service->save($this->input(['code' => null]));
        $this->service->save($this->input(['code' => null]));
        $this->assertSame(5, MarketingActivity::count());
    }

    public static function invalidUrls(): array
    {
        $urls = ['//evil.example/a', 'javascript:alert(1)', 'data:image/svg+xml,x', 'http://example.test/a',
            'https://user@example.test/a', 'https://user:pass@example.test/a', 'https://@example.test/a',
            'https://example.test/../a', 'https://example.test/./a', 'https://example.test//a',
            'https://example.test/%2e%2e/a', 'https://example.test/%252e%252e/a',
            'https://example.test/%2fa', 'https://example.test/%5ca', 'https://example.test/%00',
            'https://example.test/%0A', 'https://example.test/%7f', 'https://example.test/%c2%85',
            'https://example.test/%E2%80%AE', 'https://example.test/%zz', 'https://example.test/a b',
            "https://example.test/a\n", 'https://example.test/"x', 'https:\\example.test/a',
            '/activities/../a', '/dashboard/../a', '/activities/%2e%2e/a', '/dashboard/%252f%252fevil',
            '/dashboard/finance/invite?next=//evil.example', '/activities//a.webp'];
        $cases = [];
        foreach (['image_url', 'target_url'] as $field) {
            foreach ($urls as $url) $cases[$field . ':' . $url] = [$field, $url];
        }
        $cases['wrong-image-root'] = ['image_url', '/dashboard/finance/invite'];
        $cases['wrong-target-root'] = ['target_url', '/activities/example.webp'];
        return $cases;
    }

    #[DataProvider('invalidUrls')]
    public function testRejectsUnsafeUrls(string $field, string $url): void
    {
        $this->assertApiError(422, fn () => $this->service->save($this->input([$field => $url])));
        $this->assertSame(0, MarketingActivity::count());
    }

    public function testAllowsCanonicalRelativeAndHttpsUrls(): void
    {
        $model = $this->service->save($this->input(['image_url' => 'https://images.example.test/hero.webp?v=2',
            'target_url' => 'https://www.example.test/campaign?offer=2#join']));
        $this->assertSame('https://www.example.test/campaign?offer=2#join', $model->target_url);
        $this->service->save($this->input(['image_url' => '/activities/season/hero-2.webp', 'target_url' => '/dashboard/']));
        $this->assertSame(2, MarketingActivity::count());
    }

    public static function invalidFields(): array
    {
        return [
            [['code' => 'Bad Code']], [['code' => '']], [['source' => 'other']], [['source' => null]],
            [['theme' => 'red']], [['published' => 'true']], [['sort' => 1.2]], [['sort' => 10001]],
            [['title' => '<script>alert(1)</script>']], [['badge' => '<img src=x onerror=alert(1)>']],
            [['summary' => '&lt;script&gt;alert(1)&lt;/script&gt;']], [['title' => "bad\x00text"]],
            [['button_text' => "bad\ntext"]], [['summary' => "bad\u{202e}text"]],
            [['title' => '{unknown}']], [['title' => '{invite_commission_percent}']],
            [['source' => 'invite', 'title' => '{ai_topup_bonus_percent}']],
            [['source' => 'invite', 'title' => '{{invite_commission_percent}}']],
            [['source' => 'invite', 'title' => '{invite_commission_percent']],
            [['title' => str_repeat('a', 81)]], [['badge' => str_repeat('a', 31)]],
            [['summary' => str_repeat('a', 241)]], [['button_text' => str_repeat('a', 31)]],
            [['benefits' => array_fill(0, 7, 'Benefit')]], [['benefits' => ['key' => 'Benefit']]],
            [['benefits' => [['nested']]]], [['benefits' => ['<svg onload=alert(1)>']]],
            [['benefits' => [str_repeat('a', 181)]]], [['starts_at' => -1]],
            [['starts_at' => 10, 'ends_at' => 10]], [['starts_at' => 20, 'ends_at' => 10]],
            [['id' => null]], [['id' => 1.0, 'version' => 1]], [['version' => 2]],
            [['created_at' => 123]], [['version' => null]], [['version' => true]],
        ];
    }

    #[DataProvider('invalidFields')]
    public function testRejectsInvalidFieldsWithoutWriting(array $extra): void
    {
        $this->assertApiError(422, fn () => $this->service->save($this->input($extra)));
        $this->assertSame(0, MarketingActivity::count());
    }

    public function testPaginationIsBoundedAndStable(): void
    {
        for ($i = 0; $i < 3; $i++) $this->service->save($this->input(['sort' => 2 - $i, 'title' => (string) $i]));
        $page = $this->service->records(['current' => '2', 'page_size' => '2']);
        $this->assertSame(3, $page['total']);
        $this->assertSame(2, $page['current']);
        $this->assertSame(2, $page['page_size']);
        $this->assertSame(['0'], array_column($page['records'], 'title'));
        $this->assertApiError(422, fn () => $this->service->records(['page_size' => 101]));
        $this->assertApiError(422, fn () => $this->service->records(['current' => 0]));
    }

    public function testUserControllerRequiresAUserAndReturnsExactFeedEnvelopeWithNoStore(): void
    {
        $controller = new UserActivityController($this->service);
        $request = Request::create('/api/v1/user/activities/fetch');
        $response = $controller->fetch($request);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $request->setUserResolver(fn () => $this->user);
        $this->service->save($this->input(['published' => true]));
        $response = $controller->fetch($request);
        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertSame(['activities', 'server_time'], array_keys($response->getData(true)['data']));
        $this->assertSame(self::NOW, $response->getData(true)['data']['server_time']);
        $this->assertCount(1, $response->getData(true)['data']['activities']);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('Authorization', $response->headers->get('Vary'));
    }

    public function testAdminControllerReturnsRecordsAnd422409WithNoStore(): void
    {
        $controller = new AdminActivityController($this->service);
        $save = $controller->save(Request::create('/', 'POST', $this->input()));
        $this->assertSame(200, $save->getStatusCode());
        $id = $save->getData(true)['data']['id'];
        $bad = $controller->publish(Request::create('/', 'POST', ['id' => $id, 'version' => 1, 'published' => 'false']));
        $this->assertSame(422, $bad->getStatusCode());
        $this->assertTrue($bad->headers->hasCacheControlDirective('no-store'));
        $publish = $controller->publish(Request::create('/', 'POST', ['id' => $id, 'version' => 1, 'published' => true]));
        $this->assertSame(2, $publish->getData(true)['data']['version']);
        $conflict = $controller->delete(Request::create('/', 'POST', ['id' => $id, 'version' => 1]));
        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertSame('fail', $conflict->getData(true)['status']);
        $this->assertTrue($conflict->headers->hasCacheControlDirective('no-store'));
        $list = $controller->fetch(Request::create('/', 'GET', ['current' => 1, 'page_size' => 100]));
        $this->assertSame(1, $list->getData(true)['data']['total']);
        $this->assertSame('active', $list->getData(true)['data']['records'][0]['status']);
        $delete = $controller->delete(Request::create('/', 'POST', ['id' => $id, 'version' => 2]));
        $this->assertTrue($delete->getData(true)['data']);
    }
}
