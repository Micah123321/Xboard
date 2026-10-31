<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Models\AiTopupOrder;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

require_once __DIR__ . '/../../plugins-core/Epay/Plugin.php';
require_once __DIR__ . '/../../plugins-core/Bepusdt/Plugin.php';

class AiTopupGatewayIntegrationTest extends TestCase
{
    private Container $previous;
    private $previousFacade;
    private $resolver;
    private array $columns;
    private Manager $database;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance(); $this->previousFacade = Facade::getFacadeApplication();
        $this->resolver = Model::getConnectionResolver();
        $property = new ReflectionProperty(Model::class, 'guardableColumns');
        $this->columns = $property->getValue(); $property->setValue(null, []);
        $app = new Container(); Container::setInstance($app); Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $this->database = new Manager($app); $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent(); $app->instance('db', $this->database->getDatabaseManager());
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('v2_payment', function ($t) {
            $t->id(); $t->string('payment'); $t->boolean('enable'); $t->text('config')->nullable(); $t->timestamps();
        });
        $schema->create('v2_ai_topup_orders', function ($t) {
            $t->id(); $t->string('trade_no'); $t->integer('payment_id'); $t->integer('total_amount');
            $t->integer('handling_amount')->default(0); $t->timestamps();
        });
        Payment::create(['id' => 1, 'payment' => 'BEpusdt', 'enable' => false]);
        AiTopupOrder::create(['trade_no' => 'AIT-fixture', 'payment_id' => 1, 'total_amount' => 3000, 'handling_amount' => 10]);
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        $this->resolver ? Model::setConnectionResolver($this->resolver) : Model::unsetConnectionResolver();
        (new ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, $this->columns);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacade); Container::setInstance($this->previous);
    }

    private function epay(bool $enable = false, int $id = 1): PaymentService
    {
        $plugin = new \Plugin\Epay\Plugin('epay'); $plugin->setConfig(['key' => 'fixture-signing-key']);
        $service = (new ReflectionClass(PaymentService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PaymentService::class, 'config'))->setValue($service, ['enable' => $enable, 'id' => $id]);
        (new ReflectionProperty(PaymentService::class, 'payment'))->setValue($service, $plugin);
        return $service;
    }

    private function signed(string $trade = 'AIT-fixture'): array
    {
        $data = ['out_trade_no' => $trade, 'trade_no' => 'fixture-receipt', 'money' => '30.10', 'trade_status' => 'TRADE_SUCCESS'];
        ksort($data); $data['sign'] = md5(stripslashes(urldecode(http_build_query($data))) . 'fixture-signing-key');
        return $data;
    }

    public function test_disabled_channel_keeps_verifying_its_preexisting_ai_purchase(): void
    {
        $receipt = $this->epay()->notify($this->signed());
        self::assertSame('AIT-fixture', $receipt['trade_no']);
        self::assertSame(1, $receipt['payment_id']); self::assertSame('30.10', $receipt['payment_amount']);
    }

    public function test_disabled_channel_does_not_accept_a_purchase_bound_to_another_gateway(): void
    {
        $this->expectException(ApiException::class); $this->epay(false, 2)->notify($this->signed());
    }

    public function test_disabled_channel_does_not_reopen_subscription_callbacks_or_unknown_ai_orders(): void
    {
        foreach (['subscription-fixture', 'AIT-unknown'] as $trade) {
            try { $this->epay()->notify($this->signed($trade)); self::fail('Disabled unrelated callback was accepted.'); }
            catch (ApiException $e) { self::assertSame('gate is not enable', $e->getMessage()); }
        }
    }

    public function test_disabled_channel_still_rejects_tampered_receipts(): void
    {
        $params = $this->signed(); $params['money'] = '0.01';
        $this->expectException(ApiException::class); $this->epay()->notify($params);
    }

    public function test_bepusdt_validates_topup_amount_and_channel_against_independent_orders(): void
    {
        $plugin = new \Plugin\Bepusdt\Plugin('bepusdt');
        $plugin->setConfig(['api_token' => 'fixture-signing-key', 'id' => 1, 'fiat' => 'CNY']);
        $params = ['order_id' => 'AIT-fixture', 'trade_id' => 'receipt', 'status' => 2, 'amount' => '30.10', 'fiat' => 'CNY'];
        $params['signature'] = $plugin->sign($params);
        $receipt = $plugin->notify($params);
        self::assertSame(3010, $receipt['expected_amount']); self::assertTrue($receipt['paid']);
        $params['amount'] = '30.09'; $params['signature'] = $plugin->sign($params);
        self::assertFalse($plugin->notify($params));
    }
}
