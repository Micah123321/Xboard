<?php

namespace Tests\Unit\Orders;

use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\TrafficResetService;
use App\Support\Setting;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class OrderServiceInviteRewardTest extends TestCase
{
    private const PLAN_BYTES = 108447924224; // 101 GiB.
    private const BONUS_BYTES = 10844792422; // Fractional bytes are rounded down.

    private Container $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private Manager $database;
    private Dispatcher $events;
    private Repository $config;
    private Plan $plan;
    private User $inviter;
    private User $buyer;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $container = new Container();
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('app', $container);
        $container->instance(BusDispatcherContract::class, new BusDispatcher($container));
        $this->config = new Repository([
            'app' => ['timezone' => 'UTC'],
            'cache' => [
                'default' => 'array',
                'stores' => ['array' => ['driver' => 'array'], 'redis' => ['driver' => 'array']],
            ],
        ]);
        $container->instance('config', $this->config);
        $container->instance('log', new NullLogger());
        $container->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $cache = new CacheManager($container);
        $container->instance('cache', $cache);
        // Keep the real Setting helper without loading persisted settings or Redis.
        $cache->store('redis')->forever(Setting::CACHE_KEY, []);
        $container->singleton(Setting::class);
        $this->events = new Dispatcher($container);
        $container->instance('events', $this->events);
        Model::setEventDispatcher($this->events);
        $this->database = new Manager($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->database->getConnection()->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
        $container->instance('db', $this->database->getDatabaseManager());
        $this->createSchema();

        $this->plan = Plan::create([
            'name' => 'Invite reward fixture',
            'transfer_enable' => 101,
            'group_id' => 7,
            'speed_limit' => 40,
            'device_limit' => 2,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
        ]);
        $this->inviter = $this->createUser();
        $this->buyer = $this->createUser(['invite_user_id' => $this->inviter->id]);
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousDispatcher) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
    }

    public static function paidPurchases(): array
    {
        return [
            'monthly external payment' => [Plan::PERIOD_MONTHLY, 1000, 0],
            'onetime external payment' => [Plan::PERIOD_ONETIME, 1000, 0],
            'monthly entirely balance' => [Plan::PERIOD_MONTHLY, 0, 1000],
            'onetime entirely balance' => [Plan::PERIOD_ONETIME, 0, 1000],
            'monthly mixed payment' => [Plan::PERIOD_MONTHLY, 400, 600],
        ];
    }

    #[DataProvider('paidPurchases')]
    public function test_first_paid_purchase_adds_ten_percent_of_standard_plan_traffic(
        string $period, int $total, int $balance
    ): void {
        $inviterBefore = $this->inviter->fresh()->getRawOriginal();
        $order = $this->createOrder(['period' => $period, 'total_amount' => $total, 'balance_amount' => $balance]);
        (new OrderService($order))->open();

        $this->assertOpenedWithBonus($order);
        $buyer = $this->buyer->fresh();
        self::assertSame($this->plan->id, $buyer->plan_id);
        self::assertSame(7, $buyer->group_id);
        self::assertSame(40, $buyer->speed_limit);
        self::assertSame(2, $buyer->device_limit);
        self::assertSame(0, $buyer->u);
        self::assertSame(0, $buyer->d);
        if ($period === Plan::PERIOD_ONETIME) {
            self::assertNull($buyer->expired_at);
        } else {
            self::assertGreaterThan(time(), $buyer->expired_at);
        }
        self::assertSame(1, TrafficResetLog::count());
        self::assertSame($inviterBefore, $this->inviter->fresh()->getRawOriginal());
    }

    public static function ineligiblePurchases(): array
    {
        return [
            'no inviter' => ['none', Plan::PERIOD_MONTHLY, 1000],
            'self invitation' => ['self', Plan::PERIOD_MONTHLY, 1000],
            'deleted inviter' => ['deleted', Plan::PERIOD_MONTHLY, 1000],
            'free monthly' => ['valid', Plan::PERIOD_MONTHLY, 0],
            'free onetime' => ['valid', Plan::PERIOD_ONETIME, 0],
            'paid reset package' => ['valid', Plan::PERIOD_RESET_TRAFFIC, 1000],
        ];
    }

    #[DataProvider('ineligiblePurchases')]
    public function test_ineligible_purchase_gets_no_bonus(string $invitation, string $period, int $total): void
    {
        $inviterId = match ($invitation) {
            'none' => null,
            'self' => $this->buyer->id,
            default => $this->inviter->id,
        };
        if ($invitation === 'deleted') {
            $this->inviter->delete();
        }
        $this->buyer->update([
            'invite_user_id' => $inviterId,
            'plan_id' => $this->plan->id,
            'transfer_enable' => self::PLAN_BYTES,
        ]);
        $order = $this->createOrder([
            'invite_user_id' => $inviterId,
            'period' => $period,
            'total_amount' => $total,
            'type' => $period === Plan::PERIOD_RESET_TRAFFIC ? Order::TYPE_RESET_TRAFFIC : Order::TYPE_NEW_PURCHASE,
        ]);
        (new OrderService($order))->open();

        $this->assertOpenedWithoutBonus($order);
        self::assertSame(1, TrafficResetLog::count());
    }

    public static function blockingHistory(): array
    {
        return [
            'completed monthly external payment' => [Order::STATUS_COMPLETED, Plan::PERIOD_MONTHLY, 1000, 0],
            'discounted monthly external payment' => [Order::STATUS_DISCOUNTED, Plan::PERIOD_MONTHLY, 1000, 0],
            'completed onetime balance payment' => [Order::STATUS_COMPLETED, Plan::PERIOD_ONETIME, 0, 1000],
            'discounted onetime balance payment' => [Order::STATUS_DISCOUNTED, Plan::PERIOD_ONETIME, 0, 1000],
        ];
    }

    #[DataProvider('blockingHistory')]
    public function test_expired_repurchase_does_not_reward_users_with_paid_history(
        int $status, string $period, int $total, int $balance
    ): void {
        $this->createOrder([
            'status' => $status, 'period' => $period,
            'total_amount' => $total, 'balance_amount' => $balance,
            'invite_user_id' => null,
        ]);
        $this->buyer->update(['plan_id' => $this->plan->id, 'expired_at' => time() - 86400]);
        $order = $this->createOrder();
        (new OrderService($order))->open();

        $this->assertOpenedWithoutBonus($order);
        self::assertGreaterThan(time(), $this->buyer->fresh()->expired_at);
    }

    public static function nonBlockingHistory(): array
    {
        return [
            'cancelled unpaid' => [Order::STATUS_CANCELLED, Plan::PERIOD_MONTHLY, 1000],
            'pending unpaid' => [Order::STATUS_PENDING, Plan::PERIOD_MONTHLY, 1000],
            'completed free' => [Order::STATUS_COMPLETED, Plan::PERIOD_MONTHLY, 0],
            'discounted free' => [Order::STATUS_DISCOUNTED, Plan::PERIOD_ONETIME, 0],
            'completed paid reset' => [Order::STATUS_COMPLETED, Plan::PERIOD_RESET_TRAFFIC, 1000],
            'discounted paid reset' => [Order::STATUS_DISCOUNTED, Plan::PERIOD_RESET_TRAFFIC, 1000],
        ];
    }

    #[DataProvider('nonBlockingHistory')]
    public function test_unpaid_free_or_reset_history_does_not_block_first_paid_purchase(
        int $status, string $period, int $total
    ): void {
        $this->createOrder(['status' => $status, 'period' => $period, 'total_amount' => $total]);
        $order = $this->createOrder();
        (new OrderService($order))->open();
        $this->assertOpenedWithBonus($order);
    }

    public function test_paid_history_belongs_to_the_buyer_only(): void
    {
        $this->createOrder(['user_id' => $this->inviter->id, 'status' => Order::STATUS_COMPLETED]);
        $order = $this->createOrder();
        (new OrderService($order))->open();
        $this->assertOpenedWithBonus($order);
    }

    public static function nonProcessingStatuses(): array
    {
        return [
            'pending' => [Order::STATUS_PENDING],
            'cancelled' => [Order::STATUS_CANCELLED],
            'completed' => [Order::STATUS_COMPLETED],
            'discounted' => [Order::STATUS_DISCOUNTED],
        ];
    }

    #[DataProvider('nonProcessingStatuses')]
    public function test_open_rechecks_database_status_instead_of_trusting_stale_model(int $status): void
    {
        $order = $this->createOrder();
        Order::whereKey($order->id)->update(['status' => $status]);
        $before = $this->buyer->fresh()->getRawOriginal();
        $hooks = 0;
        HookManager::register('order.open.before', function () use (&$hooks) { $hooks++; });
        HookManager::register('order.open.after', function () use (&$hooks) { $hooks++; });
        (new OrderService($order))->open();

        self::assertSame($status, $order->fresh()->status);
        self::assertSame($before, $this->buyer->fresh()->getRawOriginal());
        self::assertSame(0, TrafficResetLog::count());
        self::assertSame(0, $hooks);
        $this->assertNoOpenTransaction();
    }

    public function test_two_stale_models_of_the_same_order_fulfill_and_reward_only_once(): void
    {
        $order = $this->createOrder(['refund_amount' => 300]);
        $first = new OrderService(Order::findOrFail($order->id));
        $second = new OrderService(Order::findOrFail($order->id));
        $opened = 0;
        HookManager::register('order.open.after', function () use (&$opened) { $opened++; });
        $first->open();
        $this->assertOpenedWithBonus($order);
        self::assertSame(300, $this->buyer->fresh()->balance);
        $this->buyer->fresh()->update(['u' => 55, 'd' => 66]);
        $userAfterFirstOpen = $this->buyer->fresh()->getRawOriginal();
        $orderAfterFirstOpen = $order->fresh()->getRawOriginal();
        $resetCount = TrafficResetLog::count();
        $second->open();

        self::assertSame($userAfterFirstOpen, $this->buyer->fresh()->getRawOriginal());
        self::assertSame($orderAfterFirstOpen, $order->fresh()->getRawOriginal());
        self::assertSame($resetCount, TrafficResetLog::count());
        self::assertSame(1, $opened);
        $this->assertNoOpenTransaction();
    }

    public function test_stale_payment_callback_cannot_reopen_or_restore_a_cleared_bonus(): void
    {
        $order = $this->createOrder(['status' => Order::STATUS_PENDING]);
        $firstOrder = Order::findOrFail($order->id)->setRelation('payment', null);
        $secondOrder = Order::findOrFail($order->id)->setRelation('payment', null);
        $first = new OrderService($firstOrder);
        $second = new OrderService($secondOrder);
        $opened = 0;
        HookManager::register('order.open.after', function () use (&$opened) { $opened++; });

        self::assertTrue($first->paid('first-payment', [
            'payment_channel' => 'Fixture checkout',
            'payment_method' => 'Fixture method',
            'payment_amount' => '10.00',
            'payment_ip' => '192.0.2.1',
        ]));
        $this->assertOpenedWithBonus($order);
        self::assertSame('first-payment', $order->fresh()->callback_no);
        self::assertSame(1000, $order->fresh()->payment_amount);
        self::assertTrue((new TrafficResetService())->performReset($this->buyer->fresh(), TrafficResetLog::SOURCE_AUTO));
        $this->buyer->fresh()->update(['u' => 55, 'd' => 66]);
        $userAfterReset = $this->buyer->fresh()->getRawOriginal();
        $orderAfterPayment = $order->fresh()->getRawOriginal();
        $resetCount = TrafficResetLog::count();

        self::assertTrue($second->paid('duplicate-payment', ['payment_amount' => '20.00']));
        self::assertSame($userAfterReset, $this->buyer->fresh()->getRawOriginal());
        self::assertSame($orderAfterPayment, $order->fresh()->getRawOriginal());
        self::assertSame($resetCount, TrafficResetLog::count());
        self::assertSame(1, $opened);
        self::assertSame(Order::STATUS_COMPLETED, $secondOrder->status);
        $this->assertNoOpenTransaction();
    }

    public function test_stale_payment_callback_does_not_fulfill_a_cancelled_order(): void
    {
        $order = $this->createOrder(['status' => Order::STATUS_PENDING])->setRelation('payment', null);
        Order::whereKey($order->id)->update(['status' => Order::STATUS_CANCELLED]);
        $userBefore = $this->buyer->fresh()->getRawOriginal();

        self::assertTrue((new OrderService($order))->paid('late-payment'));
        self::assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        self::assertSame($userBefore, $this->buyer->fresh()->getRawOriginal());
        self::assertSame(0, TrafficResetLog::count());
        $this->assertNoOpenTransaction();
    }

    public static function purchasePeriods(): array
    {
        return ['monthly' => [Plan::PERIOD_MONTHLY], 'onetime' => [Plan::PERIOD_ONETIME]];
    }

    #[DataProvider('purchasePeriods')]
    public function test_two_preread_orders_reward_only_first_and_renewal_clears_bonus(string $period): void
    {
        $firstOrder = $this->createOrder(['period' => $period]);
        $secondOrder = $this->createOrder(['period' => $period, 'type' => Order::TYPE_RENEWAL]);
        $first = new OrderService(Order::findOrFail($firstOrder->id));
        $second = new OrderService(Order::findOrFail($secondOrder->id));
        $bonusesAtCompletion = [];
        HookManager::register('order.open.after', function () use (&$bonusesAtCompletion) {
            $bonusesAtCompletion[] = $this->buyer->fresh()->temporary_transfer_enable;
        });
        $first->open();
        $this->assertOpenedWithBonus($firstOrder);
        $second->open();

        $this->assertOpenedWithoutBonus($secondOrder);
        self::assertSame([self::BONUS_BYTES, 0], $bonusesAtCompletion);
        self::assertSame(2, Order::where('status', Order::STATUS_COMPLETED)->count());
    }

    public function test_configured_new_order_reset_runs_before_bonus_and_after_hook_runs_after_commit(): void
    {
        $this->config->set('v2board.new_order_event_id', 1);
        $temporaryAtReset = [];
        HookManager::register('traffic.reset.after', function (User $user) use (&$temporaryAtReset) {
            $temporaryAtReset[] = $user->temporary_transfer_enable;
            self::assertGreaterThan(0, $this->database->getConnection()->transactionLevel());
        });
        $completed = 0;
        HookManager::register('order.open.after', function (Order $order) use (&$completed) {
            $this->assertNoOpenTransaction();
            $this->assertOpenedWithBonus($order);
            $completed++;
        });
        $order = $this->createOrder();
        (new OrderService($order))->open();

        $this->assertOpenedWithBonus($order);
        self::assertSame([0, 0], $temporaryAtReset);
        self::assertSame(2, TrafficResetLog::count());
        self::assertSame(2, $this->buyer->fresh()->reset_count);
        self::assertSame(1, $completed);
    }

    public function test_later_reset_removes_reward_and_restores_standard_plan_traffic(): void
    {
        $order = $this->createOrder();
        (new OrderService($order))->open();
        $this->assertOpenedWithBonus($order);
        $this->buyer->fresh()->update(['u' => 400, 'd' => 600]);
        self::assertTrue((new TrafficResetService())->performReset($this->buyer->fresh(), TrafficResetLog::SOURCE_AUTO));

        self::assertSame(self::PLAN_BYTES, $this->buyer->fresh()->transfer_enable);
        self::assertSame(0, $this->buyer->fresh()->temporary_transfer_enable);
        self::assertSame(0, $this->buyer->fresh()->u);
        self::assertSame(0, $this->buyer->fresh()->d);
        self::assertSame(2, TrafficResetLog::count());
        $this->assertNoOpenTransaction();
    }

    public static function failedResets(): array
    {
        return [
            'new monthly' => [Plan::PERIOD_MONTHLY, Order::TYPE_NEW_PURCHASE, null],
            'onetime' => [Plan::PERIOD_ONETIME, Order::TYPE_NEW_PURCHASE, null],
            'reset package' => [Plan::PERIOD_RESET_TRAFFIC, Order::TYPE_RESET_TRAFFIC, null],
            'renewal reset event' => [Plan::PERIOD_MONTHLY, Order::TYPE_RENEWAL, 'renew_order_event_id'],
            'monthly reset save failure' => [Plan::PERIOD_MONTHLY, Order::TYPE_NEW_PURCHASE, null, true],
            'onetime reset save failure' => [Plan::PERIOD_ONETIME, Order::TYPE_NEW_PURCHASE, null, true],
            'reset package save failure' => [Plan::PERIOD_RESET_TRAFFIC, Order::TYPE_RESET_TRAFFIC, null, true],
            'renewal reset save failure' => [Plan::PERIOD_MONTHLY, Order::TYPE_RENEWAL, 'renew_order_event_id', true],
        ];
    }

    #[DataProvider('failedResets')]
    public function test_failed_reset_rolls_back_order_and_bonus_then_allows_retry(string $period, int $type, ?string $event, bool $failUserSave = false): void
    {
        $this->buyer->update(['u'=>13,'d'=>29,'plan_id'=>$this->plan->id,'transfer_enable'=>self::PLAN_BYTES,'expired_at'=>time()+86400]);
        if ($event !== null) $this->config->set('v2board.'.$event, 1);
        $order = $this->createOrder(['period'=>$period,'type'=>$type,'refund_amount'=>300]);
        $userBefore = $this->buyer->fresh()->getRawOriginal();
        $orderBefore = $order->fresh()->getRawOriginal();
        $failReset = true;
        $opened = 0;
        HookManager::register('traffic.reset.after', function () use (&$failReset, $failUserSave) {
            if ($failReset && !$failUserSave) throw new RuntimeException('fixture reset failure');
        });
        $this->events->listen('eloquent.saving: '.User::class, function (User $user) use (&$failReset, $failUserSave) {
            if ($failReset && $failUserSave && $user->isDirty('reset_count')) return false;
        });
        HookManager::register('order.open.after', function () use (&$opened) { $opened++; });
        $service = new OrderService($order);
        $resetFailed = false;
        try {
            $service->open();
        } catch (RuntimeException $exception) {
            $resetFailed = true;
            self::assertSame('Order traffic reset failed', $exception->getMessage());
        }
        self::assertTrue($resetFailed, 'a failed reset must prevent completion and its bonus');
        self::assertSame($userBefore, $this->buyer->fresh()->getRawOriginal());
        self::assertSame($orderBefore, $order->fresh()->getRawOriginal());
        self::assertSame(0, TrafficResetLog::count());
        self::assertSame(0, $opened);
        $this->assertNoOpenTransaction();
        $failReset = false;
        $service->open();
        if ($period === Plan::PERIOD_RESET_TRAFFIC) $this->assertOpenedWithoutBonus($order);
        else $this->assertOpenedWithBonus($order);
        self::assertSame(1, $opened);
    }

    public function test_order_success_hook_waits_for_outer_commit_and_is_discarded_on_rollback(): void
    {
        $order = $this->createOrder();
        $opened = 0;
        HookManager::register('order.open.after', function (Order $order) use (&$opened) {
            $this->assertNoOpenTransaction();
            $this->assertOpenedWithBonus($order);
            $opened++;
        });
        $connection = $this->database->getConnection();
        $connection->beginTransaction();
        (new OrderService($order))->open();
        self::assertSame(0, $opened);
        $connection->rollBack();
        self::assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        self::assertSame(0, $this->buyer->fresh()->temporary_transfer_enable);
        self::assertSame(0, $opened);
        $connection->beginTransaction();
        (new OrderService($order->fresh()))->open();
        self::assertSame(0, $opened);
        $connection->commit();
        self::assertSame(1, $opened);
    }

    public static function failedSaves(): array
    {
        return ['user save returns false' => [User::class], 'order save returns false' => [Order::class]];
    }

    #[DataProvider('failedSaves')]
    public function test_save_failure_rolls_back_fulfillment_and_same_service_can_retry(string $modelClass): void
    {
        $order = $this->createOrder(['refund_amount' => 300]);
        $userBefore = $this->buyer->fresh()->getRawOriginal();
        $orderBefore = $order->fresh()->getRawOriginal();
        $failNextSave = true;
        $failures = 0;
        $this->events->listen('eloquent.saving: ' . $modelClass, function (Model $model) use (
            $modelClass, $order, &$failNextSave, &$failures
        ) {
            $expectedId = $modelClass === User::class ? $this->buyer->id : $order->id;
            if (!$failNextSave || $model->id !== $expectedId
                || ($modelClass === User::class && $model->temporary_transfer_enable !== self::BONUS_BYTES)) {
                return null;
            }
            $failNextSave = false;
            $failures++;
            self::assertGreaterThan(0, $this->database->getConnection()->transactionLevel());
            self::assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
            self::assertSame(1, TrafficResetLog::count());
            if ($modelClass === Order::class) {
                self::assertSame(self::BONUS_BYTES, $this->buyer->fresh()->temporary_transfer_enable);
            }
            return false;
        });
        $opened = 0;
        HookManager::register('order.open.after', function () use (&$opened) { $opened++; });
        $service = new OrderService($order);
        try {
            $service->open();
            self::fail('Expected fulfillment save to fail.');
        } catch (RuntimeException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertSame(1, $failures);
        self::assertSame($userBefore, $this->buyer->fresh()->getRawOriginal());
        self::assertSame($orderBefore, $order->fresh()->getRawOriginal());
        self::assertSame(0, TrafficResetLog::count());
        self::assertSame(0, $opened);
        $this->assertNoOpenTransaction();
        $service->open();
        $this->assertOpenedWithBonus($order);
        self::assertSame(300, $this->buyer->fresh()->balance);
        self::assertSame(1, TrafficResetLog::count());
        self::assertSame(1, $opened);
    }

    private function assertOpenedWithBonus(Order $order): void
    {
        self::assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        self::assertSame(self::BONUS_BYTES, $this->buyer->fresh()->temporary_transfer_enable);
        self::assertSame(self::PLAN_BYTES + self::BONUS_BYTES, $this->buyer->fresh()->transfer_enable);
        $this->assertNoOpenTransaction();
    }

    private function assertOpenedWithoutBonus(Order $order): void
    {
        self::assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        self::assertSame(0, $this->buyer->fresh()->temporary_transfer_enable);
        self::assertSame(self::PLAN_BYTES, $this->buyer->fresh()->transfer_enable);
        $this->assertNoOpenTransaction();
    }

    private function assertNoOpenTransaction(): void
    {
        self::assertSame(0, $this->database->getConnection()->transactionLevel());
    }

    private function createUser(array $attributes = []): User
    {
        $index = User::count() + 1;
        return User::create(array_replace([
            'email' => 'user-' . $index . '@example.test',
            'token' => 'fixture-user-' . $index,
            'transfer_enable' => 1024,
            'temporary_transfer_enable' => 0,
            'u' => 13,
            'd' => 29,
            'balance' => 0,
            'reset_count' => 0,
        ], $attributes));
    }

    private function createOrder(array $attributes = []): Order
    {
        return Order::create(array_replace([
            'user_id' => $this->buyer->id,
            'plan_id' => $this->plan->id,
            'invite_user_id' => $this->inviter->id,
            'trade_no' => 'fixture-order-' . (Order::count() + 1),
            'period' => Plan::PERIOD_MONTHLY,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PROCESSING,
            'total_amount' => 1000,
            'balance_amount' => 0,
            'refund_amount' => 0,
        ], $attributes));
    }

    private function createSchema(): void
    {
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('v2_user', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('token');
            $table->integer('invite_user_id')->nullable();
            $table->integer('plan_id')->nullable();
            $table->integer('group_id')->nullable();
            $table->bigInteger('transfer_enable')->default(0);
            $table->bigInteger('temporary_transfer_enable')->default(0);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->bigInteger('balance')->default(0);
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('expired_at')->nullable();
            $table->integer('next_reset_at')->nullable();
            $table->integer('last_reset_at')->nullable();
            $table->integer('reset_count')->default(0);
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $schema->create('v2_plan', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('group_id');
            $table->integer('transfer_enable');
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('reset_traffic_method')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $schema->create('v2_order', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('plan_id');
            $table->integer('invite_user_id')->nullable();
            $table->string('trade_no')->unique();
            $table->string('period');
            $table->integer('type');
            $table->integer('status');
            $table->integer('total_amount');
            $table->integer('balance_amount')->default(0);
            $table->integer('refund_amount')->default(0);
            $table->text('surplus_order_ids')->nullable();
            $table->integer('paid_at')->nullable();
            $table->string('callback_no')->nullable();
            $table->string('payment_channel')->nullable();
            $table->string('payment_method')->nullable();
            $table->integer('payment_amount')->nullable();
            $table->string('payment_ip')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $schema->create('v2_traffic_reset_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('reset_type');
            $table->timestamp('reset_time');
            $table->string('trigger_source');
            foreach (['old_upload', 'old_download', 'old_total', 'new_upload', 'new_download', 'new_total'] as $column) {
                $table->bigInteger($column);
            }
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
    }
}
