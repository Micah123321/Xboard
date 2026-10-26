<?php

namespace Tests\Unit\AiCredit;

use App\Exceptions\ApiException;
use App\Models\AiCreditGrant;
use App\Models\AiCreditSetting;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\AiCredit\AiCreditClient;
use App\Services\AiCredit\AiCreditPolicy;
use App\Services\AiCredit\AiCreditService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AiCreditServiceTest extends TestCase
{
    private Container $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private array $previousColumns;
    private Manager $database;
    private AiCreditService $service;
    private FakeAiCreditClient $client;
    private User $user;
    private Plan $plan;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $columns = new \ReflectionProperty(Model::class, 'guardableColumns');
        $this->previousColumns = $columns->getValue(); $columns->setValue(null, []);
        $container = new Container(); Container::setInstance($container);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
        $container->instance('app', $container);
        $container->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $container->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $translator = new Translator(new ArrayLoader(), 'en');
        $container->instance('translator', $translator);
        $events = new Dispatcher($container); $container->instance('events', $events);
        $this->database = new Manager($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent(); Model::setEventDispatcher($events);
        $this->database->getConnection()->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
        $container->instance('db', $this->database->getDatabaseManager());
        $schema = $this->database->getConnection()->getSchemaBuilder(); $container->instance('db.schema', $schema);
        $validator = new Factory($translator, $container);
        $validator->setPresenceVerifier(new DatabasePresenceVerifier($this->database->getDatabaseManager()));
        $container->instance('validator', $validator);
        $schema->create('v2_user', function (Blueprint $table) {
            $table->increments('id'); $table->string('email'); $table->integer('plan_id')->nullable();
            $table->boolean('banned')->default(false); $table->integer('expired_at')->nullable();
            $table->bigInteger('transfer_enable')->default(0); $table->integer('created_at'); $table->integer('updated_at');
        });
        $schema->create('v2_plan', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->integer('transfer_enable');
            $table->integer('created_at'); $table->integer('updated_at');
        });
        $schema->create('v2_order', function (Blueprint $table) {
            $table->increments('id'); $table->integer('user_id'); $table->integer('plan_id');
            $table->string('trade_no')->unique(); $table->string('period'); $table->integer('type'); $table->integer('status');
            $table->integer('total_amount'); $table->integer('balance_amount')->default(0);
            $table->integer('handling_amount')->nullable(); $table->integer('payment_amount')->nullable();
            $table->integer('payment_id')->nullable(); $table->string('callback_no')->nullable();
            $table->integer('paid_at')->nullable(); $table->integer('created_at'); $table->integer('updated_at');
        });
        (require __DIR__ . '/../../../database/migrations/2026_10_25_000001_create_ai_credit_tables.php')->up();
        $this->client = new FakeAiCreditClient(); $this->service = new AiCreditService($this->client);
        $this->plan = Plan::create(['name' => '200GB', 'transfer_enable' => 200]);
        $this->user = User::create(['email' => 'fixture@example.test', 'plan_id' => $this->plan->id, 'expired_at' => time() + 86400]);
        $this->enable();
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        $this->previousResolver ? Model::setConnectionResolver($this->previousResolver) : Model::unsetConnectionResolver();
        $this->previousDispatcher ? Model::setEventDispatcher($this->previousDispatcher) : Model::unsetEventDispatcher();
        (new \ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, $this->previousColumns);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacade); Container::setInstance($this->previousContainer);
    }

    private function enable(array $extra = []): void
    {
        $this->service->saveSettings(array_replace([
            'enabled' => true, 'bridge_url' => 'https://bridge.example.test', 'bridge_token' => str_repeat('t', 32),
        ], $extra));
    }

    private function order(array $extra = []): Order
    {
        return Order::create(array_replace([
            'user_id' => $this->user->id, 'plan_id' => $this->plan->id, 'trade_no' => 'order-' . bin2hex(random_bytes(8)),
            'period' => Plan::PERIOD_MONTHLY, 'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_COMPLETED,
            'total_amount' => 1990, 'payment_id' => 1, 'callback_no' => 'paid-online', 'paid_at' => time(),
        ], $extra));
    }

    private function grant(array $extra = []): AiCreditGrant
    {
        return $this->service->onOrderCompleted($this->order($extra), $this->plan);
    }

    public function test_real_order_fulfillment_preserves_legacy_and_creates_renewal_once(): void
    {
        $this->database->getConnection()->getSchemaBuilder()->table('v2_user', function (Blueprint $table) {
            $table->integer('group_id')->nullable(); $table->integer('speed_limit')->nullable(); $table->integer('device_limit')->nullable();
            $table->bigInteger('temporary_transfer_enable')->default(0); $table->bigInteger('u')->default(0); $table->bigInteger('d')->default(0);
            $table->integer('next_reset_at')->nullable();
        });
        $reset = $this->createMock(\App\Services\TrafficResetService::class);
        $reset->method('performReset')->willReturn(true); $reset->method('calculateNextResetTime')->willReturn(null);
        Container::getInstance()->instance(\App\Services\TrafficResetService::class, $reset);
        Container::getInstance()->instance(\App\Support\Setting::class, new class extends \App\Support\Setting {
            public function __construct() {}
            public function get(string $key, mixed $default = null): mixed { return $default; }
        });
        $old = $this->order(['paid_at' => AiCreditService::ordersSinceAt() - 100]); $this->allowLegacy($old);
        $renewal = $this->order(['status' => Order::STATUS_PROCESSING, 'type' => Order::TYPE_RENEWAL]);
        $service = new \App\Services\OrderService($renewal); $service->open();
        self::assertSame(Order::STATUS_COMPLETED, $renewal->fresh()->status);
        self::assertSame(2, AiCreditGrant::count());
        self::assertSame('legacy', AiCreditGrant::where('order_id', $old->id)->value('source'));
        self::assertSame('order', AiCreditGrant::where('order_id', $renewal->id)->value('source'));
        $service->open(); self::assertSame(2, AiCreditGrant::count());
    }

    public function test_recreating_local_qualification_reuses_the_same_upstream_business_key(): void
    {
        $order = $this->order(); $grant = $this->service->onOrderCompleted($order, $this->plan);
        $ref = $grant->external_ref; $code = $this->service->claim($grant->id, $this->user)['code'];
        $grant->delete();
        $rebuilt = $this->service->onOrderCompleted($order, $this->plan);
        self::assertSame($ref, $rebuilt->external_ref);
        self::assertSame($code, $this->service->claim($rebuilt->id, $this->user)['code']);
        self::assertSame(1, $this->client->created);
    }

    public function test_gift_amounts_and_rounding_use_integer_money(): void
    {
        foreach ([200 => '10.00', 500 => '25.00', 1000 => '50.00', 3000 => '150.00', 8000 => '400.00'] as $gb => $value) {
            self::assertSame($value, AiCreditPolicy::money(AiCreditPolicy::amountCents($gb, '0.05')));
        }
        self::assertSame(1, AiCreditPolicy::amountCents(1, '0.005'));
    }

    public function test_strict_payment_boundary_excludes_fees_and_balance_by_default(): void
    {
        foreach ([0, 1499, 1500] as $amount) {
            self::assertNull($this->service->onOrderCompleted($this->order(['total_amount' => $amount, 'handling_amount' => 1000, 'balance_amount' => 2000]), $this->plan));
        }
        self::assertSame(1501, $this->grant(['total_amount' => 1501])->paid_amount_cents);
    }

    public function test_balance_is_counted_only_after_explicit_configuration(): void
    {
        $order = $this->order(['total_amount' => 0, 'balance_amount' => 1990, 'payment_id' => null, 'callback_no' => 'balance-checkout']);
        self::assertNull($this->service->onOrderCompleted($order, $this->plan));
        $this->enable(['include_balance' => true]);
        self::assertSame(1990, $this->service->onOrderCompleted($order, $this->plan)->paid_amount_cents);
    }

    public function test_each_order_is_unique_but_renewal_and_higher_plan_have_new_qualifications(): void
    {
        $order = $this->order(); $first = $this->service->onOrderCompleted($order, $this->plan);
        self::assertSame($first->id, $this->service->onOrderCompleted($order, $this->plan)->id);
        self::assertNotSame($first->id, $this->grant(['type' => Order::TYPE_RENEWAL])->id);
        $higher = Plan::create(['name' => '500GB', 'transfer_enable' => 500]);
        $upgrade = $this->order(['plan_id' => $higher->id, 'type' => Order::TYPE_UPGRADE]);
        self::assertNull($this->service->onOrderCompleted($upgrade, $higher, ['id' => 999, 'traffic_gb' => 8000]));
        self::assertSame(2500, $this->service->onOrderCompleted($upgrade, $higher, ['id' => $this->plan->id, 'traffic_gb' => 200])->amount_usd_cents);
        self::assertSame(3, AiCreditGrant::count());
    }

    public function test_free_manual_reset_and_incomplete_orders_are_excluded(): void
    {
        foreach ([['total_amount' => 0], ['callback_no' => 'manual_operation'], ['paid_at' => null], ['payment_id' => null], ['period' => Plan::PERIOD_RESET_TRAFFIC], ['status' => Order::STATUS_PROCESSING]] as $extra) {
            self::assertNull($this->service->onOrderCompleted($this->order($extra), $this->plan));
        }
    }

    public function test_annual_purchase_and_user_carryover_do_not_multiply_gift(): void
    {
        $this->user->update(['transfer_enable' => 9000 * 1073741824]);
        $grant = $this->grant(['period' => Plan::PERIOD_YEARLY]);
        self::assertSame(200, $grant->traffic_gb); self::assertSame(1000, $grant->amount_usd_cents);
        $this->plan->update(['transfer_enable' => 8000]); $this->enable(['rate_usd_per_gb' => '1']);
        self::assertSame('10.00', $this->service->overview($this->user)['grants'][0]['amount_usd']);
    }

    public function test_first_activation_and_legacy_cutoff_survive_settings_changes(): void
    {
        $before = $this->service->settings(); $this->enable(['enabled' => false]); $this->enable();
        $after = $this->service->settings();
        self::assertSame($before['activated_at'], $after['activated_at']);
        self::assertSame($before['legacy_max_order_id'], $after['legacy_max_order_id']);
        self::assertGreaterThan($before['version'], $after['version']);
    }

    private function allowLegacy(Order $order): void
    {
        $model = AiCreditSetting::findOrFail(1); $settings = $model->settings;
        $settings['activated_at'] = time(); $settings['legacy_max_order_id'] = $order->id;
        $model->update(['settings' => $settings]);
    }

    public function test_legacy_preview_is_read_only_and_backfill_is_once_per_current_subscription(): void
    {
        $this->order(['paid_at' => AiCreditService::ordersSinceAt() - 200]); $latest = $this->order(['type' => Order::TYPE_RENEWAL, 'paid_at' => AiCreditService::ordersSinceAt() - 100]);
        $this->allowLegacy($latest);
        $preview = $this->service->legacyPreview();
        self::assertSame(1, $preview['eligible_count']); self::assertSame(0, AiCreditGrant::count());
        self::assertSame($latest->id, $preview['records'][0]['order_id']);
        self::assertSame(1, $this->service->backfill(200)['created']);
        self::assertSame(0, $this->service->backfill(200)['created']);
        self::assertNull($this->service->prepareLegacy($this->user)); self::assertSame(1, AiCreditGrant::count());
    }

    public function test_preparing_legacy_before_renewal_preserves_both_entitlements(): void
    {
        $old = $this->order(['paid_at' => AiCreditService::ordersSinceAt() - 100]); $this->allowLegacy($old);
        $legacy = $this->service->prepareLegacy($this->user);
        self::assertSame($old->id, $legacy->order_id);
        $renewal = $this->grant(['type' => Order::TYPE_RENEWAL]);
        self::assertNotSame($legacy->id, $renewal->id);
        self::assertSame(2, AiCreditGrant::count());
        self::assertNull($this->service->prepareLegacy($this->user));
    }

    public function test_legacy_does_not_fall_back_when_latest_order_is_too_cheap(): void
    {
        $this->order(['paid_at' => AiCreditService::ordersSinceAt() - 200]); $latest = $this->order(['total_amount' => 1500, 'paid_at' => AiCreditService::ordersSinceAt() - 100]);
        $this->allowLegacy($latest); self::assertSame(0, $this->service->legacyPreview()['eligible_count']);
        self::assertNull($this->service->prepareLegacy($this->user));
    }

    public function test_expired_subscription_has_no_legacy_gift(): void
    {
        $old = $this->order(['paid_at' => AiCreditService::ordersSinceAt() - 100]); $this->allowLegacy($old);
        $this->user->update(['expired_at' => time() - 1]);
        self::assertSame(0, $this->service->legacyPreview()['eligible_count']);
        self::assertNull($this->service->prepareLegacy($this->user));
    }

    public function test_recent_order_cutoff_uses_beijing_payment_time_and_includes_october_twentieth(): void
    {
        $since = AiCreditService::ordersSinceAt();
        self::assertSame('2026-10-19T16:00:00+00:00', gmdate('c', $since));
        $before = $this->order(['paid_at' => $since - 1]);
        $boundary = $this->order(['paid_at' => $since]);
        $after = $this->order(['paid_at' => $since + 1]);
        $this->allowLegacy($after);
        $this->user->update(['expired_at' => time() - 1]);
        $preview = $this->service->legacyPreview();
        self::assertSame([$boundary->id, $after->id], array_column($preview['records'], 'order_id'));
        self::assertSame(['order', 'order'], array_column($preview['records'], 'source'));
        self::assertSame(2, $this->service->prepareHistorical($this->user));
        self::assertSame(0, $this->service->prepareHistorical($this->user));
        self::assertFalse(AiCreditGrant::where('order_id', $before->id)->exists());
    }

    public function test_recent_orders_and_older_current_subscription_form_a_deduplicated_union(): void
    {
        $since = AiCreditService::ordersSinceAt();
        $older = $this->order(['paid_at' => $since - 86400]);
        $current = $this->order(['paid_at' => $since - 3600, 'type' => Order::TYPE_RENEWAL]);
        $other = User::create(['email' => 'recent@example.test', 'plan_id' => null, 'expired_at' => time() - 1]);
        $recent = $this->order(['user_id' => $other->id, 'paid_at' => $since + 3600]);
        $this->allowLegacy($recent);
        $preview = $this->service->legacyPreview();
        self::assertSame([$current->id, $recent->id], array_column($preview['records'], 'order_id'));
        self::assertSame(['legacy', 'order'], array_column($preview['records'], 'source'));
        self::assertSame(2, $this->service->backfill(200)['created']);
        self::assertSame(0, $this->service->backfill(200)['created']);
        self::assertSame(1, AiCreditGrant::where('legacy_user_id', $this->user->id)->count());
        self::assertFalse(AiCreditGrant::where('order_id', $older->id)->exists());
        self::assertSame(2, AiCreditGrant::count());
    }

    public function test_historical_downgrades_and_changes_without_source_evidence_are_excluded_before_pagination(): void
    {
        $since = AiCreditService::ordersSinceAt();
        $higher = Plan::create(['name' => '500GB', 'transfer_enable' => 500]);
        $same = Plan::create(['name' => 'Other 200GB', 'transfer_enable' => 200]);
        $this->order(['paid_at' => $since + 100, 'plan_id' => $higher->id]);
        $downgrade = $this->order(['paid_at' => $since + 200, 'type' => Order::TYPE_UPGRADE]);
        $lateral = $this->order(['paid_at' => $since + 300, 'plan_id' => $same->id, 'type' => Order::TYPE_UPGRADE]);
        $this->order(['paid_at' => $since + 400, 'type' => Order::TYPE_RENEWAL]);
        $upgrade = $this->order(['paid_at' => $since + 500, 'plan_id' => $higher->id, 'type' => Order::TYPE_UPGRADE]);
        $other = User::create(['email' => 'missing-source@example.test']);
        $unknown = $this->order(['user_id' => $other->id, 'paid_at' => $since + 600, 'type' => Order::TYPE_UPGRADE]);
        $this->allowLegacy($unknown);
        $preview = $this->service->legacyPreview();
        $ids = array_column($preview['records'], 'order_id');
        self::assertNotContains($downgrade->id, $ids); self::assertNotContains($lateral->id, $ids); self::assertNotContains($unknown->id, $ids);
        self::assertContains($upgrade->id, $ids);
        self::assertSame(3, $preview['eligible_count']);
        self::assertSame(1, $this->service->backfill(1)['created']);
        self::assertSame(1, $this->service->backfill(1)['created']);
        self::assertSame(1, $this->service->backfill(1)['created']);
        self::assertSame(0, $this->service->backfill(1)['created']);
        self::assertSame(2500, AiCreditGrant::where('order_id', $upgrade->id)->value('amount_usd_cents'));
    }

    public function test_current_subscription_inside_recent_window_is_not_granted_twice(): void
    {
        $since = AiCreditService::ordersSinceAt();
        $first = $this->order(['paid_at' => $since + 100]);
        $latest = $this->order(['paid_at' => $since + 200, 'type' => Order::TYPE_RENEWAL]);
        $this->allowLegacy($latest);
        self::assertSame(2, $this->service->prepareHistorical($this->user));
        self::assertNull($this->service->prepareLegacy($this->user));
        self::assertSame(0, $this->service->backfill(200)['created']);
        self::assertSame(2, AiCreditGrant::count());
        self::assertSame(0, AiCreditGrant::whereNotNull('legacy_user_id')->count());
    }

    public function test_backfill_batch_limit_applies_to_orders_even_for_the_same_user(): void
    {
        $since = AiCreditService::ordersSinceAt();
        $first = $this->order(['paid_at' => $since + 10]);
        $second = $this->order(['paid_at' => $since + 20, 'type' => Order::TYPE_RENEWAL]);
        $third = $this->order(['paid_at' => $since + 30, 'type' => Order::TYPE_RENEWAL]);
        $this->allowLegacy($third);
        $batch = $this->service->backfill(1);
        self::assertSame(['created' => 1, 'skipped' => 0, 'has_more' => true], $batch);
        self::assertSame([$first->id], AiCreditGrant::pluck('order_id')->all());
        self::assertSame(1, $this->service->backfill(1)['created']);
        self::assertSame(1, $this->service->backfill(1)['created']);
        self::assertSame(0, $this->service->backfill(1)['created']);
        self::assertSame(3, AiCreditGrant::count());
    }

    public function test_recent_window_still_excludes_free_manual_reset_and_threshold_orders(): void
    {
        $since = AiCreditService::ordersSinceAt();
        foreach ([['total_amount' => 1500], ['total_amount' => 0], ['callback_no' => 'manual_operation'], ['period' => Plan::PERIOD_RESET_TRAFFIC], ['status' => Order::STATUS_CANCELLED]] as $extra) {
            $this->order(array_replace(['paid_at' => $since + 100], $extra));
        }
        $good = $this->order(['paid_at' => $since + 200, 'total_amount' => 1501]);
        $this->allowLegacy($good);
        self::assertSame([$good->id], array_column($this->service->legacyPreview()['records'], 'order_id'));
        self::assertSame(1, $this->service->prepareHistorical($this->user));
    }

    public function test_user_preparation_is_bounded_and_only_prepares_own_recent_orders(): void
    {
        $since = AiCreditService::ordersSinceAt();
        for ($i = 0; $i < 3; $i++) $last = $this->order(['paid_at' => $since + 100 + $i]);
        $other = User::create(['email' => 'private-recent@example.test']);
        $last = $this->order(['user_id' => $other->id, 'paid_at' => $since + 200]);
        $this->allowLegacy($last);
        self::assertSame(2, $this->service->prepareHistorical($this->user, 2));
        self::assertSame(1, $this->service->prepareHistorical($this->user, 2));
        self::assertSame(0, AiCreditGrant::where('user_id', $other->id)->count());
        self::assertSame(1, $this->service->prepareHistorical($other));
    }

    public function test_claim_encrypts_code_and_repeated_claim_returns_the_same_code(): void
    {
        $grant = $this->grant();
        $first = $this->service->claim($grant->id, $this->user);
        $second = $this->service->claim($grant->id, $this->user);
        self::assertSame('issued', $first['status']); self::assertSame($first['code'], $second['code']);
        self::assertSame(1, $this->client->posts);
        self::assertStringNotContainsString($first['code'], $this->database->getConnection()->table('v2_ai_credit_grants')->where('id', $grant->id)->value('code'));
        self::assertArrayNotHasKey('code', $this->service->overview($this->user)['grants'][0]);
        self::assertArrayNotHasKey('bridge_token', $this->service->settings());
        self::assertNotSame(str_repeat('t', 32), $this->database->getConnection()->table('v2_ai_credit_settings')->value('bridge_token'));
    }

    public function test_other_user_cannot_read_or_claim_a_code(): void
    {
        $grant = $this->grant(); $other = User::create(['email' => 'other@example.test']);
        $this->expectException(ApiException::class); $this->expectExceptionCode(404);
        $this->service->claim($grant->id, $other);
    }

    public function test_response_lost_after_issue_is_recovered_without_second_creation(): void
    {
        $grant = $this->grant(); $this->client->loseResponse = true;
        self::assertSame('reconciling', $this->service->claim($grant->id, $this->user)['status']);
        self::assertSame(1, $this->client->created);
        $this->client->loseResponse = false;
        self::assertSame('issued', $this->service->detail($grant->id, $this->user)['status']);
        self::assertSame(1, $this->client->posts); self::assertSame(1, $this->client->created);
    }

    public function test_retry_after_timeout_uses_permanent_reference_and_old_amount(): void
    {
        $grant = $this->grant(); $this->client->failBeforeIssue = true;
        $this->service->claim($grant->id, $this->user); $ref = $grant->external_ref;
        $this->client->failBeforeIssue = false;
        AiCreditGrant::whereKey($grant->id)->update(['next_attempt_at' => time() - 1]);
        $this->service->reconcile($grant->id);
        self::assertSame('issued', $grant->fresh()->status); self::assertSame($ref, $grant->fresh()->external_ref);
        self::assertSame(1, $this->client->created); self::assertSame('10.00', $this->client->records[$ref]['value']);
    }

    public static function disabledRecoveryCases(): array
    {
        return [
            'banned with upstream result' => [false, true],
            'deleted with upstream result' => [true, true],
            'banned before upstream issue' => [false, false],
            'deleted before upstream issue' => [true, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('disabledRecoveryCases')]
    public function test_interrupted_request_for_disabled_account_only_queries_original_result(bool $deleted, bool $remoteExists): void
    {
        $grant = $this->grant();
        $this->client->loseResponse = $remoteExists;
        $this->client->failBeforeIssue = !$remoteExists;
        self::assertSame('reconciling', $this->service->claim($grant->id, $this->user)['status']);
        $this->client->loseResponse = $this->client->failBeforeIssue = false;
        if ($deleted) {
            $this->user->delete();
            Order::whereKey($grant->order_id)->delete();
        } else $this->user->update(['banned' => true]);
        $grant->refresh();
        $ref = $grant->external_ref;
        $grant->update(['next_attempt_at' => time() - 1]);

        $result = $this->service->reconcile($grant->id);
        self::assertSame($remoteExists ? 'issued' : 'reconciling', $result['status']);
        self::assertSame($ref, $grant->fresh()->external_ref);
        self::assertSame(1, $this->client->gets);
        self::assertSame(1, $this->client->posts);
        self::assertSame($remoteExists ? 1 : 0, $this->client->created);
        if ($remoteExists) {
            self::assertNull($grant->fresh()->last_error);
            self::assertSame('revoked', $this->service->revoke($grant->id, 'Fixture account closed')['status']);
            self::assertSame('expired', $this->client->records[$ref]['status']);
        } else {
            self::assertGreaterThan(time(), $grant->fresh()->next_attempt_at);
            self::assertNull($grant->fresh()->lease_token);
            $grant->refresh()->update(['next_attempt_at' => time() - 1]);
            $this->service->reconcile($grant->id);
            self::assertSame(2, $this->client->gets);
            self::assertSame(1, $this->client->posts);
        }
    }

    public function test_disabled_account_still_cannot_start_an_unrequested_grant(): void
    {
        $grant = $this->grant();
        $this->user->update(['banned' => true]);
        try { $this->service->process($grant->id, true); self::fail('Disabled account must not issue a new code'); }
        catch (ApiException $e) { self::assertSame(403, $e->getCode()); }
        self::assertSame('eligible', $grant->fresh()->status);
        self::assertSame(0, $this->client->gets);
        self::assertSame(0, $this->client->posts);
    }

    public function test_live_lease_prevents_concurrent_claim_requests(): void
    {
        $grant = $this->grant(); $grant->update(['status' => 'generating', 'lease_token' => 'other-worker', 'lease_until' => time() + 60]);
        self::assertSame('generating', $this->service->claim($grant->id, $this->user)['status']);
        self::assertSame(0, $this->client->posts);
    }

    public function test_paused_activity_keeps_existing_codes_and_stops_new_issuance(): void
    {
        $issued = $this->grant(); $code = $this->service->claim($issued->id, $this->user)['code'];
        $pending = $this->grant(); $this->client->failBeforeIssue = true;
        $this->service->claim($pending->id, $this->user); $posts = $this->client->posts;
        $this->client->failBeforeIssue = false; $this->enable(['enabled' => false]);
        AiCreditGrant::whereKey($pending->id)->update(['next_attempt_at' => time() - 1]);
        $this->service->reconcile($pending->id); self::assertSame($posts, $this->client->posts);
        self::assertSame($code, $this->service->detail($issued->id, $this->user)['code']);
    }

    public function test_upstream_redemption_and_expiry_are_reflected_without_new_codes(): void
    {
        $grant = $this->grant(); $this->service->claim($grant->id, $this->user);
        $this->client->records[$grant->external_ref]['status'] = 'used';
        self::assertSame('redeemed', $this->service->detail($grant->id, $this->user)['status']);
        $other = $this->grant(); $this->service->claim($other->id, $this->user);
        $this->client->records[$other->external_ref]['status'] = 'expired';
        self::assertSame('revoked', $this->service->detail($other->id, $this->user)['status']);
        self::assertSame(2, $this->client->posts);
    }

    public function test_revocation_blocks_claim_and_inflight_grant_is_not_locally_revoked(): void
    {
        $grant = $this->grant(); self::assertSame('revoked', $this->service->revoke($grant->id, 'Fixture cancellation')['status']);
        self::assertSame('revoked', $this->service->claim($grant->id, $this->user)['status']); self::assertSame(0, $this->client->posts);
        $other = $this->grant(); $other->update(['status' => 'generating']);
        $this->expectException(ApiException::class); $this->service->revoke($other->id, 'Fixture cancellation');
    }

    public function test_admin_revoke_expires_unused_upstream_code_and_keeps_used_balances(): void
    {
        $grant = $this->grant(); $this->service->claim($grant->id, $this->user);
        self::assertSame('revoked', $this->service->revoke($grant->id, 'Fixture revocation')['status']);
        self::assertSame('expired', $this->client->records[$grant->external_ref]['status']);
        self::assertArrayNotHasKey('code', $this->service->detail($grant->id, $this->user));
        $other = $this->grant(); $this->service->claim($other->id, $this->user);
        $this->client->records[$other->external_ref]['status'] = 'used';
        try { $this->service->revoke($other->id, 'Fixture revocation'); self::fail('Used codes must not be expired.'); }
        catch (ApiException $e) { self::assertSame(409, $e->getCode()); }
        self::assertSame('redeemed', $other->fresh()->status);
        self::assertSame('used', $this->client->records[$other->external_ref]['status']);
    }

    public function test_bad_provider_records_back_off_instead_of_starving_the_next_batch(): void
    {
        $stuck = [];
        for ($i = 0; $i < 20; $i++) {
            $grant = $this->grant(); $stuck[] = $grant->id;
            $grant->update(['status' => 'reconciling', 'bridge_url' => 'https://old.example.test', 'next_attempt_at' => time() - 1]);
        }
        $next = $this->grant();
        $next->update(['status' => 'reconciling', 'bridge_url' => 'https://bridge.example.test', 'next_attempt_at' => time() - 1]);
        $command = $this->createPartialMock(\App\Console\Commands\AiCreditReconcile::class, ['option', 'info', 'warn']);
        $command->method('option')->willReturnCallback(fn ($name) => $name === 'limit' ? 20 : false);
        self::assertSame(0, $command->handle($this->service));
        self::assertSame('reconciling', $next->fresh()->status);
        self::assertSame(20, AiCreditGrant::whereIn('id', $stuck)->where('next_attempt_at', '>', time())->count());
        self::assertSame(0, $command->handle($this->service));
        self::assertSame('issued', $next->fresh()->status);
        self::assertSame(1, $this->client->created);
    }

    public function test_provider_change_never_reissues_existing_reference_to_another_server(): void
    {
        $grant = $this->grant(); $this->client->failBeforeIssue = true; $this->service->claim($grant->id, $this->user);
        $this->enable(['bridge_url' => 'https://different.example.test']);
        $this->expectException(ApiException::class); $this->service->claim($grant->id, $this->user);
    }

    public function test_ineligible_post_launch_order_never_gets_a_legacy_gift_in_the_same_second(): void
    {
        $order = $this->order(['type' => Order::TYPE_UPGRADE]);
        $this->allowLegacy($order);
        self::assertNull($this->service->onOrderCompleted($order, $this->plan, ['id' => 99, 'traffic_gb' => 500]));
        self::assertNull($this->service->prepareLegacy($this->user));
        self::assertNotNull($order->fresh()->ai_credit_evaluated_at);
    }

    public function test_user_pagination_keeps_all_older_qualifications_accessible_and_owned(): void
    {
        for ($i = 0; $i < 24; $i++) $this->grant();
        $first = $this->service->userRecords($this->user, 1, 20, 'available');
        $second = $this->service->userRecords($this->user, 2, 20, 'available');
        self::assertSame(24, $first['total']); self::assertCount(20, $first['records']); self::assertCount(4, $second['records']);
        self::assertEmpty(array_intersect(array_column($first['records'], 'id'), array_column($second['records'], 'id')));
        self::assertSame(24, $this->service->overview($this->user)['eligible_count']);
        self::assertSame(0, $this->service->userRecords($this->user, 1, 20, 'history')['total']);
        $this->service->claim($first['records'][0]['id'], $this->user);
        self::assertSame(1, $this->service->userRecords($this->user, 1, 20, 'history', 'issued')['total']);
        $other = User::create(['email' => 'private@example.test']);
        self::assertSame(0, $this->service->userRecords($other)['total']);
    }

    public function test_real_http_client_uses_bridge_contract_and_stable_reference(): void
    {
        $factory = new \Illuminate\Http\Client\Factory();
        Container::getInstance()->instance(\Illuminate\Http\Client\Factory::class, $factory);
        $factory->preventStrayRequests();
        $factory->fake(fn ($request) => $factory->response([
            'code' => 0, 'data' => ['external_ref' => 'instance:qualification', 'id' => 1, 'code' => 'synthetic-code',
                'type' => 'balance', 'value' => 10, 'status' => 'unused', 'used_at' => null, 'expires_at' => null],
        ]));
        $client = new AiCreditClient();
        $result = $client->request('POST', 'https://bridge.example.test', str_repeat('t', 32), 'instance:qualification', '10.00');
        self::assertSame('synthetic-code', $result['code']);
        $client->request('GET', 'https://bridge.example.test', str_repeat('t', 32), 'instance:qualification');
        $factory->assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://bridge.example.test/api/integrations/xboard/rewards'
            && $request->hasHeader('Authorization', 'Bearer ' . str_repeat('t', 32))
            && $request->hasHeader('Idempotency-Key', 'instance:qualification')
            && $request['external_ref'] === 'instance:qualification' && $request['amount_usd'] === '10.00');
        $factory->assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'instance%3Aqualification'));
    }

    public function test_provider_amount_mismatch_is_not_shown_as_issued(): void
    {
        $grant = $this->grant();
        $this->client->records[$grant->external_ref] = ['external_ref' => $grant->external_ref, 'id' => 9,
            'code' => 'wrong-value-fixture', 'type' => 'balance', 'value' => '99.00', 'status' => 'unused'];
        $result = $this->service->claim($grant->id, $this->user);
        self::assertSame('reconciling', $result['status']); self::assertArrayNotHasKey('code', $result);
    }

    public function test_eligibility_rolls_back_with_enclosing_order_transaction(): void
    {
        $order = $this->order();
        try { $this->database->getConnection()->transaction(function () use ($order) { $this->service->onOrderCompleted($order, $this->plan); throw new RuntimeException('rollback'); }); }
        catch (RuntimeException $e) {}
        self::assertSame(0, AiCreditGrant::count());
    }
}

class FakeAiCreditClient extends AiCreditClient
{
    public array $records = [];
    public int $posts = 0;
    public int $gets = 0;
    public int $created = 0;
    public bool $loseResponse = false;
    public bool $failBeforeIssue = false;

    public function request(string $method, string $baseUrl, string $token, string $externalRef, ?string $amount = null): ?array
    {
        if ($method === 'GET') { $this->gets++; return $this->records[$externalRef] ?? null; }
        if ($method === 'EXPIRE') {
            if (($this->records[$externalRef]['status'] ?? null) === 'used') throw new ApiException('Fixture used', 409);
            $this->records[$externalRef]['status'] = 'expired';
            return $this->records[$externalRef];
        }
        $this->posts++;
        if ($this->failBeforeIssue) throw new RuntimeException('Fixture network outage');
        if (!isset($this->records[$externalRef])) {
            $this->created++;
            $this->records[$externalRef] = ['external_ref' => $externalRef, 'id' => $this->created, 'code' => 'fixture-redeem-' . $this->created, 'type' => 'balance', 'value' => $amount, 'status' => 'unused'];
        }
        if ($this->loseResponse) throw new RuntimeException('Fixture lost response');
        return $this->records[$externalRef];
    }
}
