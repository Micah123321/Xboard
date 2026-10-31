<?php

namespace Tests\Unit\AiTopup;

use App\Exceptions\ApiException;
use App\Jobs\AiTopupFulfillJob;
use App\Models\AiCreditSetting;
use App\Models\AiTopupOrder;
use App\Models\AiTopupSetting;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\AiCredit\AiCreditClient;
use App\Services\AiTopup\AiTopupPolicy;
use App\Services\AiTopup\AiTopupService;
use App\Services\TrafficResetService;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusContract;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AiTopupServiceTest extends TestCase
{
    private Container $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private array $previousColumns;
    private Manager $database;
    private AiTopupService $service;
    private TopupClient $client;
    private User $user;
    private Payment $payment;
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
        $container->instance('validator', new Factory($translator, $container));
        $container->instance(BusContract::class, new BusDispatcher($container));
        Bus::fake();
        $schema->create('v2_user', function (Blueprint $table) {
            $table->increments('id'); $table->string('email'); $table->integer('plan_id')->nullable();
            $table->boolean('banned')->default(false); $table->integer('expired_at')->nullable();
            $table->bigInteger('transfer_enable')->default(0); $table->bigInteger('temporary_transfer_enable')->default(0);
            $table->bigInteger('u')->default(0); $table->bigInteger('d')->default(0); $table->integer('balance')->default(0);
            $table->integer('last_reset_at')->nullable(); $table->integer('next_reset_at')->nullable(); $table->integer('reset_count')->default(0);
            $table->integer('created_at'); $table->integer('updated_at');
        });
        $schema->create('v2_plan', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->integer('reset_traffic_method');
            $table->integer('created_at'); $table->integer('updated_at');
        });
        $schema->create('v2_payment', function (Blueprint $table) {
            $table->increments('id'); $table->string('payment'); $table->string('name')->default('Fixture');
            $table->boolean('enable')->default(true); $table->integer('handling_fee_fixed')->nullable();
            $table->decimal('handling_fee_percent', 8, 4)->nullable(); $table->text('config')->nullable();
            $table->integer('created_at'); $table->integer('updated_at');
        });
        $schema->create('v2_ai_credit_settings', function (Blueprint $table) {
            $table->integer('id')->primary(); $table->string('instance_id'); $table->text('settings'); $table->text('bridge_token')->nullable();
            $table->integer('created_at'); $table->integer('updated_at');
        });
        (require __DIR__ . '/../../../database/migrations/2026_10_25_000002_create_ai_topup_tables.php')->up();
        AiCreditSetting::create(['id' => 1, 'instance_id' => (string) Str::uuid(), 'bridge_token' => str_repeat('t', 32),
            'settings' => ['enabled' => false, 'bridge_url' => 'https://bridge.example.test']]);
        $this->client = new TopupClient(); $this->service = new AiTopupService($this->client);
        $this->plan = Plan::create(['name' => 'Fixture', 'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER]);
        $this->user = User::create(['email' => 'fixture@example.test', 'plan_id' => $this->plan->id, 'expired_at' => time() + 86400,
            'transfer_enable' => 200 * AiTopupPolicy::GIB, 'balance' => 1234]);
        $this->payment = Payment::create(['payment' => 'EPay', 'handling_fee_fixed' => 25, 'handling_fee_percent' => '2.5']);
        $this->service->saveSettings(['enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        $this->previousResolver ? Model::setConnectionResolver($this->previousResolver) : Model::unsetConnectionResolver();
        $this->previousDispatcher ? Model::setEventDispatcher($this->previousDispatcher) : Model::unsetEventDispatcher();
        (new \ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, $this->previousColumns);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacade); Container::setInstance($this->previousContainer);
    }

    private function order(int $usd = 200): AiTopupOrder
    {
        $order = $this->service->create($this->user, $usd, (string) Str::uuid());
        return $this->service->prepareCheckout($this->user, $order->trade_no, $this->payment);
    }

    private function verify(AiTopupOrder $order, array $extra = []): array
    {
        return array_replace(['trade_no' => $order->trade_no, 'callback_no' => 'signed-fixture-' . $order->id,
            'payment_id' => $order->payment_id, 'expected_amount' => $order->total_amount + $order->handling_amount], $extra);
    }

    private function paidOrder(int $usd = 200): AiTopupOrder
    {
        $order = $this->order($usd);
        self::assertTrue($this->service->paid($this->verify($order), 'EPay'));
        return $order->fresh();
    }

    private function error(int $code, callable $callback): void
    {
        try { $callback(); self::fail('Expected API error ' . $code); }
        catch (ApiException $e) { self::assertSame($code, $e->getCode()); }
    }

    public function test_fixed_point_quotes_use_whole_order_tiers_and_confirmed_bonus(): void
    {
        $quote = $this->service->quote(200);
        self::assertSame('200', $quote['amount_usd']); self::assertSame(3000, $quote['total_amount']);
        self::assertSame(150, $quote['bonus_rate_bps']); self::assertSame(4831838208, $quote['gift_traffic_bytes']);
        foreach ([34 => [510, '0.150'], 999 => [14985, '0.150'], 1000 => [14500, '0.145'],
            3000 => [40500, '0.135'], 5000 => [65000, '0.130'], 6000 => [76200, '0.127'], 8000 => [98400, '0.123'],
            9000 => [107100, '0.119'], 10000 => [115000, '0.115'], 15000 => [157500, '0.105'], 19047 => [199994, '0.105']] as $usd => [$cents, $price]) {
            $quote = $this->service->quote($usd);
            self::assertSame($cents, $quote['total_amount']); self::assertSame($price, $quote['unit_price_cny']);
        }
        $this->error(422, fn () => $this->service->quote(0));
        $this->error(422, fn () => $this->service->quote(1000001));
        $this->service->saveSettings(['tiers' => [['min_usd' => 0, 'unit_price_cny' => '0.005']]]);
        self::assertSame(1, AiTopupPolicy::quote(1, $this->service->settings(), false)['total_amount']);
        self::assertSame(3221225, AiTopupPolicy::giftBytes(2, 150));
    }

    public function test_cny_principal_limits_follow_each_whole_order_tier_and_exclude_gateway_fees(): void
    {
        $settings = $this->service->settings();
        self::assertSame(500, $settings['min_cny_cents']); self::assertSame(200000, $settings['max_cny_cents']);
        self::assertSame(34, $settings['effective_min_usd']); self::assertSame(19047, $settings['effective_max_usd']);
        foreach ([1, 33, 19048, 1000000] as $usd) {
            $this->error(422, fn () => $this->service->quote($usd));
            $this->error(422, fn () => $this->service->create($this->user, $usd, (string) Str::uuid()));
        }
        self::assertSame(510, $this->service->quote(34)['total_amount']);
        self::assertSame(199994, $this->service->quote(19047)['total_amount']);
        $this->payment->update(['handling_fee_fixed' => 1000]);
        $order = $this->service->create($this->user, 19047, (string) Str::uuid());
        self::assertGreaterThan(200000, $this->service->prepareCheckout($this->user, $order->trade_no, $this->payment)->total_amount
            + $order->fresh()->handling_amount);
        $this->service->saveSettings(['tiers' => [['min_usd' => 0, 'unit_price_cny' => '0.125']]]);
        self::assertSame(500, $this->service->quote(40)['total_amount']);
        self::assertSame(200000, $this->service->quote(16000)['total_amount']);
        $this->error(422, fn () => $this->service->quote(16001));
        $this->service->saveSettings(['tiers' => [['min_usd' => 0, 'unit_price_cny' => '0.150'], ['min_usd' => 1000, 'unit_price_cny' => '0.001']]]);
        self::assertSame(34, $this->service->settings()['effective_min_usd']);
        $this->error(422, fn () => $this->service->quote(1000));
        self::assertSame(500, $this->service->quote(5000)['total_amount']);
    }

    public function test_low_prices_and_legacy_ranges_preserve_configuration_control_and_existing_checkout(): void
    {
        foreach (['0.001', '0.004'] as $price) {
            $this->service->saveSettings(['tiers' => [['min_usd' => 0, 'unit_price_cny' => $price]]]);
            self::assertSame(500, $this->service->quote($price === '0.001' ? 5000 : 1250)['total_amount']);
        }
        $this->service->saveSettings(['tiers' => AiTopupPolicy::defaults()['tiers']]);
        $legacy = $this->order(); $legacy->update(['amount_usd' => 10, 'total_amount' => 150]);
        $model = \App\Models\AiTopupSetting::find(1); $settings = $model->settings;
        $model->update(['settings' => array_replace($settings, ['min_usd' => 1, 'max_usd' => 10, 'enabled' => true])]);
        self::assertNull($this->service->settings()['effective_min_usd']);
        self::assertTrue($this->service->settings()['enabled']);
        self::assertSame(150, $this->service->prepareCheckout($this->user, $legacy->trade_no, $this->payment)->total_amount);
        self::assertFalse($this->service->saveSettings(['enabled' => false])['enabled']);
        self::assertTrue($this->service->paid($this->verify($legacy->fresh()), 'EPay'));
    }

    public function test_policy_rejects_overflow_and_invalid_tier_ranges(): void
    {
        $this->error(422, fn () => AiTopupPolicy::multiply(PHP_INT_MAX, 2));
        $this->error(422, fn () => AiTopupPolicy::add(PHP_INT_MAX, 1));
        $this->error(422, fn () => $this->service->saveSettings(['tiers' => [['min_usd' => 0, 'unit_price_cny' => '1000']], 'bonus_rate_bps' => 10000]));
        $this->error(422, fn () => $this->service->saveSettings(['min_usd' => 500, 'max_usd' => 200]));
        $this->error(422, fn () => $this->service->saveSettings(['tiers' => [['min_usd' => 5, 'unit_price_cny' => '0.150']]]));
        $this->error(422, fn () => $this->service->saveSettings(['tiers' => [
            ['min_usd' => 0, 'unit_price_cny' => '0.150'], ['min_usd' => 0, 'unit_price_cny' => '0.140']]]));
        self::assertSame(150, $this->service->settings()['bonus_rate_bps']);
    }

    public function test_create_key_is_unique_per_user_and_conflicts_on_a_different_amount(): void
    {
        $key = (string) Str::uuid(); $first = $this->service->create($this->user, 200, $key);
        self::assertStringStartsWith('AIT', $first->trade_no);
        self::assertSame($first->id, $this->service->create($this->user, 200, strtoupper($key))->id);
        $this->error(409, fn () => $this->service->create($this->user, 201, $key));
        $other = User::create(['email' => 'other@example.test']);
        self::assertNotSame($first->id, $this->service->create($other, 200, $key)->id);
        $this->error(422, fn () => $this->service->create($this->user, 200, 'invalid'));
        $this->service->saveSettings(['enabled' => false]);
        self::assertSame($first->id, $this->service->create($this->user, 200, $key)->id);
    }

    public function test_quote_policy_and_gift_are_snapshotted_and_gateway_fees_are_excluded(): void
    {
        $order = $this->order();
        self::assertSame(100, $order->handling_amount); self::assertSame(3000, $order->total_amount);
        self::assertSame(4831838208, $order->gift_traffic_bytes);
        self::assertSame(2, $order->policy_snapshot['policy_version']);
        $this->service->saveSettings(['bonus_rate_bps' => 0, 'tiers' => [['min_usd' => 0, 'unit_price_cny' => '1']]]);
        self::assertTrue($this->service->paid($this->verify($order), 'EPay'));
        self::assertSame(4831838208, $order->fresh()->gift_traffic_bytes);
        self::assertSame(3000, $this->service->overview($this->user)['paid_amount_cents']);
    }

    public function test_selected_payment_is_immutable_even_when_another_request_uses_the_same_plugin(): void
    {
        $order = $this->order(); $other = Payment::create(['payment' => 'EPay']);
        $this->payment->update(['handling_fee_fixed' => 900]);
        self::assertSame(100, $this->service->prepareCheckout($this->user, $order->trade_no, $this->payment)->handling_amount);
        $this->error(409, fn () => $this->service->prepareCheckout($this->user, $order->trade_no, $other));
        self::assertSame($this->payment->id, $order->fresh()->payment_id);
    }

    public function test_checkout_requires_current_enabled_channel_and_ready_provider(): void
    {
        $order = $this->service->create($this->user, 200, (string) Str::uuid());
        $this->payment->update(['enable' => false]);
        $this->error(422, fn () => $this->service->prepareCheckout($this->user, $order->trade_no, $this->payment));
        $this->payment->update(['enable' => true]); AiCreditSetting::find(1)->update(['bridge_token' => null]);
        $this->error(503, fn () => $this->service->prepareCheckout($this->user, $order->trade_no, $this->payment));
        $this->error(503, fn () => $this->service->create($this->user, 200, (string) Str::uuid()));
    }

    public function test_higher_tier_cannot_increase_the_unit_price(): void
    {
        $this->error(422, fn () => $this->service->saveSettings(['tiers' => [
            ['min_usd' => 0, 'unit_price_cny' => '0.150'],
            ['min_usd' => 1000, 'unit_price_cny' => '0.151'],
        ]]));
        self::assertSame('0.150', $this->service->settings()['tiers'][0]['unit_price_cny']);
    }

    public function test_unsupported_or_non_cny_gateways_are_not_bound_to_new_ai_orders(): void
    {
        foreach ([['payment' => 'MGate'], ['payment' => 'BEpusdt', 'config' => ['fiat' => 'USD']],
            ['payment' => 'CoinPayments', 'config' => ['coinpayments_currency' => 'USD']]] as $attributes) {
            $channel = Payment::create($attributes + ['enable' => true]);
            $order = $this->service->create($this->user, 200, (string) Str::uuid());
            $this->error(422, fn () => $this->service->prepareCheckout($this->user, $order->trade_no, $channel));
            self::assertNull($order->fresh()->payment_id);
        }
    }

    public function test_payment_rechecks_channel_and_every_provided_amount_before_marking_paid(): void
    {
        $order = $this->order();
        foreach ([['payment_id' => 999], ['callback_no' => ''], ['expected_amount' => 3099], ['expected_amount' => '3100'],
            ['payment_amount' => '30.00'], ['payment_amount' => null], ['payment_amount' => '31.001'], ['paid' => false]] as $extra) {
            self::assertFalse($this->service->paid($this->verify($order, $extra), 'EPay'));
        }
        self::assertFalse($this->service->paid($this->verify($order), 'OtherPay'));
        self::assertSame('pending', $order->fresh()->status);
        self::assertSame(0, $this->service->overview($this->user)['pending_traffic_bytes']); Bus::assertNothingDispatched();
        self::assertTrue($this->service->paid($this->verify($order, ['payment_amount' => '31.0000']), 'EPay'));
        self::assertSame(4831838208, $this->service->overview($this->user)['pending_traffic_bytes']);
        self::assertFalse($this->service->paid($this->verify($order, ['payment_amount' => '30.00']), 'EPay'));
    }

    public function test_checkout_replays_encrypted_gateway_result_and_never_recreates_an_unknown_invoice(): void
    {
        $order = $this->order(); $calls = 0;
        $gateway = function () use (&$calls) { $calls++; return ['type' => 1, 'data' => 'https://pay.example.test/fixture']; };
        $first = $this->service->checkoutPayment($this->user, $order->trade_no, $this->payment, $gateway);
        self::assertSame($first, $this->service->checkoutPayment($this->user, $order->trade_no, $this->payment, $gateway));
        self::assertSame(1, $calls);
        self::assertStringNotContainsString('pay.example.test', $order->fresh()->getRawOriginal('checkout_payload'));
        $unknown = $this->order();
        $this->error(503, fn () => $this->service->checkoutPayment($this->user, $unknown->trade_no, $this->payment, function () { throw new RuntimeException('lost response after invoice'); }));
        $this->error(409, fn () => $this->service->checkoutPayment($this->user, $unknown->trade_no, $this->payment, $gateway));
        self::assertSame(1, $calls);
    }

    public function test_different_paid_receipts_are_retained_without_duplicate_entitlements(): void
    {
        $order = $this->paidOrder();
        self::assertTrue($this->service->paid($this->verify($order, ['callback_no' => 'second-receipt']), 'EPay'));
        self::assertCount(2, $order->fresh()->payment_receipts);
        Bus::assertDispatchedTimes(AiTopupFulfillJob::class, 1);
        self::assertSame(4831838208, $this->service->overview($this->user)['pending_traffic_bytes']);
        self::assertStringContainsString('不同付款凭据', $this->service->serialize($order->fresh())['last_error']);
    }

    public function test_duplicate_payment_only_queues_once_and_never_auto_activates_gift(): void
    {
        $before = $this->user->getAttributes(); $order = $this->paidOrder();
        self::assertTrue($this->service->paid($this->verify($order), 'EPay'));
        Bus::assertDispatchedTimes(AiTopupFulfillJob::class, 1);
        self::assertSame(0, $this->client->posts); self::assertNull($order->traffic_activated_at);
        foreach (['balance', 'transfer_enable', 'temporary_transfer_enable', 'plan_id', 'expired_at'] as $field) {
            self::assertEquals($before[$field] ?? 0, $this->user->fresh()->getAttribute($field) ?? 0);
        }
    }

    public function test_late_cancelled_and_expired_payments_require_exact_signed_amount_evidence(): void
    {
        foreach (['cancelled', 'expired'] as $case) {
            $order = $this->order();
            if ($case === 'cancelled') $this->service->cancel($this->user, $order->trade_no);
            else $order->update(['expires_at' => time() - 1]);
            $verify = $this->verify($order); unset($verify['expected_amount']);
            self::assertFalse($this->service->paid($verify, 'EPay'));
            self::assertFalse($this->service->paid($verify + ['payment_amount' => '30.00'], 'EPay'));
            self::assertTrue($this->service->paid($verify + ['payment_amount' => '31.00'], 'EPay'));
            self::assertSame('paid', $order->fresh()->status);
            self::assertSame($order->trade_no, $order->fresh()->trade_no);
        }
        self::assertSame(2, AiTopupOrder::where('status', 'paid')->count());
    }

    public function test_all_payment_callbacks_require_exact_amount_and_bound_channel(): void
    {
        $order = $this->order(); $verify = $this->verify($order); unset($verify['expected_amount']);
        $withoutChannel = $verify; unset($withoutChannel['payment_id']);
        self::assertFalse($this->service->paid($withoutChannel, 'EPay'));
        self::assertFalse($this->service->paid($verify, 'EPay'));
        self::assertSame('pending', $order->fresh()->status);
    }

    public function test_expired_checkout_preserves_cancellation_evidence(): void
    {
        $order = $this->order(); $order->update(['expires_at' => time() - 1]);
        $this->error(409, fn () => $this->service->prepareCheckout($this->user, $order->trade_no, $this->payment));
        self::assertSame('cancelled', $order->fresh()->status); self::assertNotNull($order->fresh()->cancelled_at);
        self::assertSame(3000, $order->fresh()->total_amount);
    }

    public function test_payment_and_dispatch_roll_back_with_an_enclosing_transaction(): void
    {
        $order = $this->order();
        try {
            DB::transaction(function () use ($order) {
                self::assertTrue($this->service->paid($this->verify($order), 'EPay'));
                Bus::assertNothingDispatched();
                throw new RuntimeException('fixture rollback');
            });
        } catch (RuntimeException $e) {}
        self::assertSame('pending', $order->fresh()->status); self::assertNull($order->fresh()->bridge_token);
        Bus::assertNothingDispatched();
        self::assertSame(0, $this->service->overview($this->user)['pending_traffic_bytes']);
        DB::transaction(function () use ($order) {
            self::assertTrue($this->service->paid($this->verify($order), 'EPay'));
            Bus::assertNothingDispatched();
        });
        Bus::assertDispatchedTimes(AiTopupFulfillJob::class, 1);
    }

    public function test_queue_dispatch_failure_keeps_verified_payment_and_reconciler_recovers(): void
    {
        $order = $this->order();
        $dispatcher = $this->createMock(BusContract::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('Fixture queue outage'));
        Bus::swap($dispatcher);
        self::assertTrue($this->service->paid($this->verify($order), 'EPay'));
        self::assertSame('paid', $order->fresh()->status);
        self::assertSame('pending', $order->fresh()->issue_status);
        self::assertSame(4831838208, $this->service->overview($this->user)['pending_traffic_bytes']);
        self::assertSame(1, $this->service->reconcile()['processed']);
        self::assertSame('issued', $order->fresh()->issue_status);
    }

    public function test_issuance_encrypts_code_and_token_and_hides_them_from_all_admin_and_list_shapes(): void
    {
        $order = $this->paidOrder(); $issued = $this->service->fulfill($order->id);
        self::assertSame('issued', $issued->issue_status); self::assertSame(1, $this->client->posts);
        $row = DB::table('v2_ai_topup_orders')->where('id', $order->id)->first();
        self::assertNotSame($issued->code, $row->code); self::assertNotSame(str_repeat('t', 32), $row->bridge_token);
        self::assertSame('fixture-code-1', $this->service->detail($this->user, $order->trade_no)['code']);
        foreach ([$issued->toArray(), $this->service->serialize($issued, true, true), $this->service->records($this->user, [])['records'][0],
            $this->service->records(null, [])['records'][0], $this->service->settings()] as $shape) {
            foreach (['code', 'bridge_token', 'bridge_url', 'external_ref', 'lease_token'] as $secret) self::assertArrayNotHasKey($secret, $shape);
        }
        self::assertSame('200', $this->service->overview($this->user)['issued_amount_usd']);
    }

    public function test_timeout_after_remote_commit_is_recovered_with_get_and_no_second_post(): void
    {
        $order = $this->paidOrder(); $this->client->loseResponse = true;
        self::assertSame('reconciling', $this->service->fulfill($order->id)->issue_status);
        self::assertSame(1, $this->client->created); self::assertSame(['GET', 'POST'], array_column($this->client->calls, 'method'));
        $this->client->loseResponse = false;
        self::assertSame('issued', $this->service->fulfill($order->id, true)->issue_status);
        self::assertSame(1, $this->client->posts); self::assertSame(1, $this->client->created);
        self::assertSame(['GET', 'POST', 'GET'], array_column($this->client->calls, 'method'));
    }

    public function test_timeout_before_issue_retries_same_reference_after_get_using_snapshot(): void
    {
        $order = $this->paidOrder(); $ref = $order->external_ref; $this->client->failPost = true;
        $this->service->fulfill($order->id); $this->client->failPost = false;
        $provider = AiCreditSetting::find(1); $provider->update(['bridge_token' => str_repeat('n', 32),
            'settings' => ['bridge_url' => 'https://new.example.test', 'enabled' => false]]);
        $this->service->saveSettings(['enabled' => false, 'tiers' => [['min_usd' => 0, 'unit_price_cny' => '1']]]);
        self::assertSame('issued', $this->service->fulfill($order->id, true)->issue_status);
        self::assertSame('200', $this->client->records[$ref]['value']);
        foreach ($this->client->calls as $call) {
            self::assertSame('https://bridge.example.test', $call['url']); self::assertSame(str_repeat('t', 32), $call['token']);
            self::assertSame($ref, $call['ref']);
        }
        self::assertSame(['GET', 'POST', 'GET', 'POST'], array_column($this->client->calls, 'method'));
    }

    public function test_missing_provider_does_not_lose_paid_orders_and_restoration_can_fulfill_them(): void
    {
        $order = $this->order(); AiCreditSetting::find(1)->update(['bridge_token' => null]);
        self::assertTrue($this->service->paid($this->verify($order), 'EPay'));
        self::assertSame('reconciling', $this->service->fulfill($order->id)->issue_status);
        self::assertSame([], $this->client->calls);
        AiCreditSetting::find(1)->update(['bridge_token' => str_repeat('t', 32)]);
        $this->service->saveSettings(['enabled' => false]);
        self::assertSame('issued', $this->service->fulfill($order->id, true)->issue_status);
        self::assertSame(4831838208, $this->service->overview($this->user)['pending_traffic_bytes']);
    }

    public function test_snapshot_recovers_after_current_provider_is_removed_and_sales_are_closed(): void
    {
        $order = $this->paidOrder(); AiCreditSetting::find(1)->delete(); $this->service->saveSettings(['enabled' => false]);
        self::assertFalse($this->service->settings()['provider_ready']);
        self::assertSame('issued', $this->service->fulfill($order->id)->issue_status);
    }

    public function test_live_lease_and_backoff_prevent_duplicate_requests_even_with_force(): void
    {
        $order = $this->paidOrder(); $order->update(['issue_status' => 'generating', 'lease_token' => 'other', 'lease_until' => time() + 60]);
        self::assertSame('generating', $this->service->fulfill($order->id, true)->issue_status); self::assertSame([], $this->client->calls);
        $order->update(['lease_until' => time() - 1, 'next_attempt_at' => time() + 100]);
        $this->service->fulfill($order->id); self::assertSame([], $this->client->calls);
        self::assertSame('issued', $this->service->fulfill($order->id, true)->issue_status);
    }

    public function test_stale_worker_response_is_fenced_by_the_lease_token(): void
    {
        $order = $this->paidOrder();
        $this->client->onRequest = function (string $method) use ($order) {
            if ($method === 'POST') AiTopupOrder::whereKey($order->id)->update(['lease_token' => 'new-worker', 'lease_until' => time() + 60]);
        };
        $result = $this->service->fulfill($order->id);
        self::assertSame('generating', $result->issue_status); self::assertNull($result->code);
        $this->client->onRequest = null; $result->update(['lease_until' => time() - 1]);
        self::assertSame('issued', $this->service->fulfill($order->id, true)->issue_status); self::assertSame(1, $this->client->posts);
    }

    public function test_network_errors_are_redacted_and_amount_mismatch_never_exposes_a_code(): void
    {
        $order = $this->paidOrder(); $this->client->failPost = true;
        $result = $this->service->fulfill($order->id);
        self::assertStringNotContainsString('LEAK-TOKEN', $result->last_error);
        self::assertStringNotContainsString('fixture-code', json_encode($this->service->serialize($result)));
        $this->client->failPost = false;
        $this->client->records[$order->external_ref] = $this->client->record($order->external_ref, '201');
        self::assertSame('reconciling', $this->service->fulfill($order->id, true)->issue_status);
        self::assertNull($order->fresh()->code); self::assertSame(1, $this->client->posts);
    }

    public function test_an_issued_reference_is_never_recreated_when_provider_get_returns_missing(): void
    {
        $order = $this->paidOrder(); $issued = $this->service->fulfill($order->id); unset($this->client->records[$order->external_ref]);
        $result = $this->service->fulfill($order->id, true);
        self::assertSame('issued', $result->issue_status); self::assertSame($issued->code, $result->code); self::assertSame(1, $this->client->posts);
    }

    public function test_issued_status_sync_is_hourly_and_does_not_inflate_pending_recovery(): void
    {
        $order = $this->paidOrder(); $this->service->fulfill($order->id);
        self::assertGreaterThanOrEqual(time() + 3599, $order->fresh()->next_attempt_at);
        $order->update(['next_attempt_at' => time() - 1]);
        self::assertSame(0, $this->service->reconcile(20, true)['pending']);
        self::assertSame(1, $this->service->reconcile()['processed']);
    }

    public function test_remote_redemption_and_expiry_are_polled_without_new_codes(): void
    {
        foreach (['used' => 'redeemed', 'expired' => 'revoked'] as $remote => $status) {
            $order = $this->paidOrder(); $this->service->fulfill($order->id);
            $this->client->records[$order->external_ref]['status'] = $remote;
            $result = $this->service->fulfill($order->id, true); self::assertSame($status, $result->issue_status);
            if ($status === 'redeemed') self::assertNotNull($result->redeemed_at);
            else self::assertArrayNotHasKey('code', $this->service->detail($this->user, $order->trade_no));
            $this->service->fulfill($order->id, true);
        }
        self::assertSame(2, $this->client->posts);
    }

    public function test_fulfillment_rejects_network_execution_inside_an_enclosing_transaction(): void
    {
        $order = $this->paidOrder();
        DB::transaction(fn () => $this->error(409, fn () => $this->service->fulfill($order->id)));
        self::assertSame([], $this->client->calls);
    }

    public function test_activation_is_once_atomic_and_preserves_subscription_and_balance(): void
    {
        $order = $this->paidOrder(); $before = $this->user->fresh();
        $result = $this->service->activateTraffic($this->user, $order->trade_no);
        self::assertSame($result, $this->service->activateTraffic($this->user, $order->trade_no));
        $after = $this->user->fresh();
        self::assertSame((int) $before->transfer_enable + 4831838208, (int) $after->transfer_enable);
        self::assertSame(4831838208, (int) $after->temporary_transfer_enable);
        foreach (['plan_id', 'expired_at', 'balance', 'next_reset_at'] as $field) self::assertEquals($before->$field, $after->$field);
        self::assertSame(0, $this->service->overview($this->user)['pending_traffic_bytes']);
        self::assertSame(4831838208, $this->service->overview($this->user)['active_temporary_traffic_bytes']);
    }

    public function test_gift_waits_without_deadline_until_active_subscription_and_clears_at_existing_reset(): void
    {
        $order = $this->paidOrder(); $this->user->update(['expired_at' => time() - 1]);
        $this->error(409, fn () => $this->service->activateTraffic($this->user, $order->trade_no));
        $order->update(['paid_at' => time() - 365 * 86400]);
        $this->user->update(['expired_at' => time() + 86400]);
        $this->service->activateTraffic($this->user, $order->trade_no);
        self::assertTrue((new TopupTrafficReset())->performReset($this->user));
        self::assertSame(0, (int) $this->user->fresh()->temporary_transfer_enable);
        self::assertSame(200 * AiTopupPolicy::GIB, (int) $this->user->fresh()->transfer_enable);
        $this->service->activateTraffic($this->user, $order->trade_no);
        self::assertSame(0, (int) $this->user->fresh()->temporary_transfer_enable);
        self::assertNotNull($order->fresh()->traffic_activated_at);
    }

    public function test_activation_rolls_back_and_overflow_never_mutates_user_or_order(): void
    {
        $order = $this->paidOrder();
        try { DB::transaction(function () use ($order) { $this->service->activateTraffic($this->user, $order->trade_no); throw new RuntimeException('rollback'); }); }
        catch (RuntimeException $e) {}
        self::assertNull($order->fresh()->traffic_activated_at); self::assertSame(0, (int) $this->user->fresh()->temporary_transfer_enable);
        $this->user->update(['transfer_enable' => PHP_INT_MAX]);
        $this->error(422, fn () => $this->service->activateTraffic($this->user, $order->trade_no));
        self::assertNull($order->fresh()->traffic_activated_at); self::assertSame(PHP_INT_MAX, (int) $this->user->fresh()->transfer_enable);
    }

    public function test_every_owner_action_hides_other_users_orders_and_lists_are_paginated(): void
    {
        $order = $this->paidOrder(); $other = User::create(['email' => 'other@example.test']);
        foreach ([fn () => $this->service->ownedOrder($other, $order->trade_no), fn () => $this->service->detail($other, $order->trade_no),
            fn () => $this->service->cancel($other, $order->trade_no), fn () => $this->service->activateTraffic($other, $order->trade_no),
            fn () => $this->service->prepareCheckout($other, $order->trade_no, $this->payment)] as $action) $this->error(404, $action);
        for ($i = 0; $i < 22; $i++) $this->order();
        $first = $this->service->records($this->user, []); $second = $this->service->records($this->user, ['current' => 2]);
        self::assertSame(23, $first['total']); self::assertCount(20, $first['records']); self::assertCount(3, $second['records']);
        self::assertSame(0, $this->service->records($other, ['user_id' => $this->user->id])['total']);
        self::assertSame(1, $this->service->records(null, ['trade_no' => $order->trade_no, 'status' => 'paid'])['total']);
    }

    public function test_recovery_command_is_bounded_dry_run_is_read_only_and_bad_rows_back_off(): void
    {
        $first = $this->paidOrder(); $second = $this->paidOrder(); $this->client->failPost = true;
        self::assertSame(2, $this->service->reconcile(1, true)['pending']); self::assertSame([], $this->client->calls);
        self::assertSame(1, $this->service->reconcile(1)['processed']);
        self::assertGreaterThan(time(), $first->fresh()->next_attempt_at);
        $this->client->failPost = false;
        $command = $this->createPartialMock(\App\Console\Commands\AiTopupReconcile::class, ['option', 'info', 'error']);
        $command->method('option')->willReturnCallback(fn ($name) => $name === 'limit' ? 1 : false);
        self::assertSame(0, $command->handle($this->service)); self::assertSame('issued', $second->fresh()->issue_status);
        self::assertSame('reconciling', $first->fresh()->issue_status);
    }

    public function test_job_fulfills_only_paid_orders_and_leaves_recovery_durable(): void
    {
        $order = $this->order(); $job = new AiTopupFulfillJob($order->id);
        self::assertTrue($job->afterCommit); self::assertSame('order_handle', $job->queue);
        $job->handle($this->service); self::assertSame([], $this->client->calls);
        self::assertTrue($this->service->paid($this->verify($order), 'EPay'));
        $job->handle($this->service); self::assertSame('issued', $order->fresh()->issue_status);
    }

    public function test_mutating_flows_lock_user_before_order_and_never_call_network_in_transaction(): void
    {
        $order = $this->order(); $queries = [];
        $this->database->getConnection()->listen(function ($event) use (&$queries) {
            if (str_starts_with(strtolower($event->sql), 'select') && !str_contains($event->sql, 'sqlite_master')) $queries[] = $event->sql;
        });
        $this->service->cancel($this->user, $order->trade_no);
        self::assertStringContainsString('v2_user', $queries[0]); self::assertStringContainsString('v2_ai_topup_orders', $queries[1]);
        $queries = []; $this->service->paid($this->verify($order), 'EPay');
        self::assertStringContainsString('v2_user', $queries[1]); self::assertStringContainsString('v2_ai_topup_orders', $queries[2]);
        $this->service->fulfill($order->id); self::assertSame(1, $this->client->posts);
    }
}

class TopupClient extends AiCreditClient
{
    public array $records = [];
    public array $calls = [];
    public int $posts = 0;
    public int $created = 0;
    public bool $loseResponse = false;
    public bool $failPost = false;
    public $onRequest = null;

    public function record(string $ref, string $amount): array
    {
        return ['external_ref' => $ref, 'id' => max(1, $this->created), 'code' => 'fixture-code-' . max(1, $this->created),
            'type' => 'balance', 'value' => $amount, 'status' => 'unused'];
    }

    public function request(string $method, string $baseUrl, string $token, string $externalRef, ?string $amount = null): ?array
    {
        if (DB::transactionLevel() !== 0) throw new RuntimeException('Network request inside DB transaction');
        $this->calls[] = ['method' => $method, 'url' => $baseUrl, 'token' => $token, 'ref' => $externalRef, 'amount' => $amount];
        if ($this->onRequest) ($this->onRequest)($method);
        if ($method === 'GET') return $this->records[$externalRef] ?? null;
        $this->posts++;
        if ($this->failPost) throw new RuntimeException('LEAK-TOKEN fixture-code malicious upstream body');
        if (!isset($this->records[$externalRef])) { $this->created++; $this->records[$externalRef] = $this->record($externalRef, $amount); }
        if ($this->loseResponse) throw new RuntimeException('LEAK-TOKEN fixture-code lost response');
        return $this->records[$externalRef];
    }
}

class TopupTrafficReset extends TrafficResetService
{
    protected function recordResetLog(User $user, array $data): void {}
    protected function clearUserCache(User $user): void {}
    protected function dispatchAfterResetHook(User $user): void {}
}
