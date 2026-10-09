<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\Bepusdt\Plugin;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../../plugins-core/Bepusdt/Plugin.php';

class BepusdtPaymentTest extends TestCase
{
    private Container $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private Plugin $plugin;
    private Manager $database;
    private array $config = [
        'api_url' => 'https://gateway.example.com/',
        'api_token' => 'epusdt_password_xasddawqe',
        'fiat' => 'CNY',
        'currencies' => 'USDT,USDC',
        'return_url' => 'https://shop.example.com/dashboard/finance/orders',
        'id' => 1,
        'enable' => true,
    ];

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $container = new Container();
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('app', $container);
        $container->instance('log', new NullLogger());
        $container->instance(Factory::class, new Factory());
        $responses = $this->createMock(ResponseFactory::class);
        $responses->method('json')->willReturnCallback(fn ($data, $status) => new JsonResponse($data, $status));
        $container->instance(ResponseFactory::class, $responses);
        $this->database = new Manager($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('v2_payment', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->string('payment');
            $table->string('name')->nullable();
            $table->text('config');
            $table->boolean('enable');
            $table->timestamps();
        });
        $schema->create('v2_order', function (Blueprint $table) {
            $table->id();
            $table->string('trade_no')->unique();
            $table->integer('payment_id');
            $table->integer('total_amount');
            $table->integer('handling_amount')->nullable();
            $table->integer('status')->default(0);
            $table->integer('paid_at')->nullable();
            $table->string('callback_no')->nullable();
            $table->string('payment_channel')->nullable();
            $table->string('payment_method')->nullable();
            $table->integer('payment_amount')->nullable();
            $table->timestamps();
        });
        Payment::create(['uuid' => 'payment-uuid', 'payment' => 'BEpusdt', 'config' => $this->config, 'enable' => true]);
        Order::create(['trade_no' => 'order-1', 'payment_id' => 1, 'total_amount' => 1000, 'handling_amount' => 25, 'status' => 0]);
        $this->plugin = new Plugin('bepusdt');
        $this->plugin->setConfig($this->config);
        $this->plugin->boot();
        $manager = $this->createMock(PluginManager::class);
        $manager->method('getEnabledPaymentPlugins')->willReturn([$this->plugin]);
        $container->instance(PluginManager::class, $manager);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
    }

    public function test_official_signature_example_and_unescaped_values(): void
    {
        self::assertSame('1cd4b52df5587cfb1968b0c0c6e156cd', $this->plugin->sign([
            'order_id' => '20220201030210321', 'amount' => 42,
            'notify_url' => 'http://example.com/notify', 'redirect_url' => 'http://example.com/redirect',
        ]));
        self::assertSame(md5('A=0&a=false&url=https://example.com/a?x=1&y=a+bepusdt_password_xasddawqe'),
            $this->plugin->sign(['url' => 'https://example.com/a?x=1&y=a+b', 'a' => false, 'A' => 0,
                'empty' => '', 'null' => null, 'signature' => 'ignored']));
    }

    public function test_create_order_uses_fee_inclusive_fiat_json_and_tls_verification(): void
    {
        Http::fake(function ($request, $options) {
            self::assertSame('POST', $request->method());
            self::assertSame('https://gateway.example.com/api/v1/order/create-order', $request->url());
            self::assertTrue($request->hasHeader('Content-Type', 'application/json'));
            self::assertEquals(10.25, $request['amount']);
            self::assertSame('CNY', $request['fiat']);
            self::assertSame('USDT,USDC', $request['currencies']);
            self::assertSame($this->config['return_url'], $request['redirect_url']);
            self::assertSame('https://shop.example.com/notify', $request['notify_url']);
            self::assertSame($this->plugin->sign($request->data()), $request['signature']);
            self::assertTrue($options['verify']);
            self::assertFalse($options['allow_redirects']);
            self::assertSame(30, $options['timeout']);
            return Http::response(['status_code' => 200, 'data' => ['payment_url' => 'https://gateway.example.com/pay/cashier/1']]);
        });
        self::assertSame(['type' => 1, 'data' => 'https://gateway.example.com/pay/cashier/1'], $this->plugin->pay($this->payOrder()));
        Http::assertSentCount(1);
    }

    public static function badResponses(): array
    {
        return [
            'HTTP failure' => [503, ['status_code' => 200]],
            'redirect' => [302, ''],
            'invalid JSON' => [200, '<html>error</html>'],
            'API error' => [200, ['status_code' => 400, 'message' => 'secret details']],
            'missing URL' => [200, ['status_code' => 200, 'data' => []]],
            'unsafe URL' => [200, ['status_code' => 200, 'data' => ['payment_url' => 'javascript:alert(1)']]],
        ];
    }

    #[DataProvider('badResponses')]
    public function test_create_order_rejects_gateway_errors(int $status, mixed $body): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->expectException(ApiException::class);
        $this->plugin->pay($this->payOrder());
    }

    public function test_connection_failure_is_sanitized_and_not_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('sensitive transport detail');
        });
        try {
            $this->plugin->pay($this->payOrder());
            self::fail('Expected connection failure');
        } catch (ApiException $e) {
            self::assertSame('BEpusdt connection failed', $e->getMessage());
            self::assertSame(1, $calls);
        }
    }

    public function test_zero_amount_and_missing_return_url_fail_before_http(): void
    {
        foreach ([['total_amount' => 0], ['total_amount' => -1], ['total_amount' => 1.5]] as $override) {
            try {
                $this->plugin->pay(array_replace($this->payOrder(), $override));
                self::fail('Expected invalid amount');
            } catch (ApiException $e) {
                self::assertSame('BEpusdt requires a positive order amount', $e->getMessage());
            }
        }
        $this->plugin->setConfig(array_replace($this->config, ['return_url' => '']));
        $this->expectException(ApiException::class);
        $this->plugin->pay($this->payOrder());
    }

    public static function invalidCallbacks(): array
    {
        return [
            'amount mismatch' => [['amount' => '10.24']],
            'sub-cent amount' => [['amount' => '10.251']],
            'negative amount' => [['amount' => '-10.25']],
            'malformed amount' => [['amount' => '1e1']],
            'zero amount' => [['amount' => 0]],
            'wrong fiat' => [['fiat' => 'USD']],
            'unknown order' => [['order_id' => 'unknown']],
            'missing trade ID' => [['trade_id' => '']],
            'unknown status' => [['status' => 9]],
            'fractional status' => [['status' => 2.5]],
        ];
    }

    #[DataProvider('invalidCallbacks')]
    public function test_signed_but_invalid_callbacks_do_not_pay(array $override): void
    {
        $this->expectDispatches(0);
        $response = $this->notify($this->callbackPayload($override));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(Order::STATUS_PENDING, Order::first()->status);
    }

    public function test_bad_signature_nested_payload_and_wrong_channels_are_rejected(): void
    {
        $params = $this->callbackPayload();
        $params['signature'] = str_repeat('0', 32);
        self::assertFalse($this->plugin->notify($params));
        $params['nested'] = ['unexpected'];
        self::assertFalse($this->plugin->notify($params));
        Order::query()->update(['payment_id' => 2]);
        self::assertFalse($this->plugin->notify($this->callbackPayload()));
        Order::query()->update(['payment_id' => 1]);
        Payment::query()->update(['payment' => 'TokenPay']);
        self::assertFalse($this->plugin->notify($this->callbackPayload()));
    }

    public function test_waiting_and_timeout_acknowledge_without_fulfillment(): void
    {
        $this->expectDispatches(0);
        foreach ([1, 3, '1', '3'] as $status) {
            self::assertSame('success', $this->notify($this->callbackPayload(['status' => $status])));
            self::assertSame(Order::STATUS_PENDING, Order::first()->status);
            self::assertNull(Order::first()->paid_at);
        }
    }

    public function test_success_and_duplicate_notifications_fulfill_once(): void
    {
        $this->expectDispatches(1);
        $callback = $this->callbackPayload(['amount' => '10.2500', 'actual_amount' => '1.42']);
        self::assertSame('success', $this->notify($callback));
        self::assertSame('success', $this->notify($callback));
        $order = Order::first();
        self::assertSame(Order::STATUS_COMPLETED, $order->status);
        self::assertSame('gateway-trade-1', $order->callback_no);
        self::assertSame(1025, $order->payment_amount);
        self::assertSame('BEpusdt', $order->payment_channel);
        self::assertSame(0, $this->database->getConnection()->transactionLevel());
    }

    public function test_failure_rolls_back_and_gateway_retry_fulfills(): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $attempt = 0;
        $dispatcher->expects(self::exactly(2))->method('dispatchSync')->willReturnCallback(function () use (&$attempt) {
            self::assertGreaterThan(0, $this->database->getConnection()->transactionLevel());
            self::assertSame(Order::STATUS_PROCESSING, Order::first()->status);
            if (++$attempt === 1) {
                throw new \RuntimeException('Simulated fulfillment failure');
            }
            Order::query()->update(['status' => Order::STATUS_COMPLETED]);
        });
        app()->instance(Dispatcher::class, $dispatcher);
        self::assertSame(500, $this->notify($this->callbackPayload())->getStatusCode());
        self::assertSame(Order::STATUS_PENDING, Order::first()->status);
        self::assertNull(Order::first()->callback_no);
        self::assertNull(Order::first()->paid_at);
        self::assertSame('success', $this->notify($this->callbackPayload()));
        self::assertSame(Order::STATUS_COMPLETED, Order::first()->status);
    }

    public function test_channel_change_after_verification_is_rechecked_inside_transaction(): void
    {
        $this->expectDispatches(0);
        HookManager::register('payment.notify.verified', function () {
            Order::query()->update(['payment_id' => 2]);
        });
        self::assertSame(400, $this->notify($this->callbackPayload())->getStatusCode());
        self::assertSame(Order::STATUS_PENDING, Order::first()->status);
    }

    public function test_cancelled_order_is_not_fulfilled_or_acknowledged_as_paid(): void
    {
        $this->expectDispatches(0);
        Order::query()->update(['status' => Order::STATUS_CANCELLED]);
        self::assertSame(400, $this->notify($this->callbackPayload())->getStatusCode());
    }

    public function test_amount_change_after_verification_is_rechecked(): void
    {
        $this->expectDispatches(0);
        HookManager::register('payment.notify.verified', function () {
            Order::query()->update(['handling_amount' => 50]);
        });
        self::assertSame(400, $this->notify($this->callbackPayload())->getStatusCode());
        self::assertSame(Order::STATUS_PENDING, Order::first()->status);
    }

    public function test_disabled_payment_does_not_fulfill(): void
    {
        $this->expectDispatches(0);
        Payment::query()->update(['enable' => false]);
        self::assertSame(500, $this->notify($this->callbackPayload())->getStatusCode());
        self::assertSame(Order::STATUS_PENDING, Order::first()->status);
    }

    public function test_existing_plugins_without_paid_flag_still_fulfill(): void
    {
        $legacy = $this->getMockBuilder(Plugin::class)->setConstructorArgs(['legacy'])
            ->onlyMethods(['notify'])->getMock();
        $legacy->method('notify')->willReturn(['trade_no' => 'order-1', 'callback_no' => 'legacy-1']);
        HookManager::registerFilter('available_payment_methods', function ($methods) {
            $methods['Legacy'] = ['plugin_code' => 'legacy'];
            return $methods;
        });
        $manager = $this->createMock(PluginManager::class);
        $manager->method('getEnabledPaymentPlugins')->willReturn([$legacy]);
        app()->instance(PluginManager::class, $manager);
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchSync')->willReturnCallback(function () {
            Order::query()->update(['status' => Order::STATUS_COMPLETED]);
        });
        app()->instance(Dispatcher::class, $dispatcher);
        self::assertSame('success', (new PaymentController())->notify('Legacy', 'payment-uuid', Request::create('/notify', 'POST')));
        self::assertSame('legacy-1', Order::first()->callback_no);
    }

    private function expectDispatches(int $count): void
    {
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects(self::exactly($count))->method('dispatchSync')->willReturnCallback(function ($job) {
            self::assertInstanceOf(OrderHandleJob::class, $job);
            self::assertGreaterThan(0, $this->database->getConnection()->transactionLevel());
            Order::query()->update(['status' => Order::STATUS_COMPLETED]);
        });
        app()->instance(Dispatcher::class, $dispatcher);
    }

    private function notify(array $params): mixed
    {
        $request = Request::create('/api/v1/guest/payment/notify/BEpusdt/payment-uuid', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($params));
        return (new PaymentController())->notify('BEpusdt', 'payment-uuid', $request);
    }

    private function callbackPayload(array $override = []): array
    {
        $params = array_replace(['order_id' => 'order-1', 'trade_id' => 'gateway-trade-1', 'status' => 2,
            'amount' => '10.25', 'actual_amount' => '1.42', 'token' => 'wallet-address'], $override);
        $params['signature'] = $this->plugin->sign($params);
        return $params;
    }

    private function payOrder(): array
    {
        return ['trade_no' => 'order-1', 'total_amount' => 1025, 'notify_url' => 'https://shop.example.com/notify',
            'return_url' => 'https://shop.example.com/#/order/order-1', 'user_id' => 1];
    }
}
