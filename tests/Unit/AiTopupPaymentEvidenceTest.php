<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Jobs\AiTopupFulfillJob;
use App\Models\AiTopupOrder;
use App\Models\User;
use App\Services\AiCredit\AiCreditClient;
use App\Services\AiTopup\AiTopupService;
use App\Services\PaymentService;
use Illuminate\Bus\Dispatcher;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusContract;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

require_once __DIR__ . '/../../plugins-core/Coinbase/Plugin.php';
require_once __DIR__ . '/../../plugins-core/Btcpay/Plugin.php';
require_once __DIR__ . '/../../plugins-core/CoinPayments/Plugin.php';

class AiTopupPaymentEvidenceTest extends TestCase
{
    private Container $previous;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private array $previousColumns;
    private Manager $database;
    private AiTopupService $service;
    private EvidenceTopupClient $client;
    private User $user;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $columns = new ReflectionProperty(Model::class, 'guardableColumns');
        $this->previousColumns = $columns->getValue(); $columns->setValue(null, []);
        $app = new Container(); Container::setInstance($app);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        Model::unsetEventDispatcher();
        $app->instance('app', $app);
        $app->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $app->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $this->database = new Manager($app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->database->getConnection()->setTransactionManager(new DatabaseTransactionsManager());
        $app->instance('db', $this->database->getDatabaseManager());
        $schema = $this->database->getConnection()->getSchemaBuilder(); $app->instance('db.schema', $schema);
        $schema->create('v2_user', function ($table) {
            $table->id(); $table->string('email'); $table->integer('created_at'); $table->integer('updated_at');
        });
        (require __DIR__ . '/../../database/migrations/2026_10_25_000002_create_ai_topup_tables.php')->up();
        $app->instance(BusContract::class, new Dispatcher($app)); Bus::fake();
        Http::preventStrayRequests();
        $this->user = User::create(['email' => 'evidence@example.test']);
        $this->client = new EvidenceTopupClient(); $this->service = new AiTopupService($this->client);
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        $this->previousResolver ? Model::setConnectionResolver($this->previousResolver) : Model::unsetConnectionResolver();
        $this->previousDispatcher ? Model::setEventDispatcher($this->previousDispatcher) : Model::unsetEventDispatcher();
        (new ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, $this->previousColumns);
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacade); Container::setInstance($this->previous);
    }

    public static function gateways(): array
    {
        return [['Coinbase'], ['BTCPay'], ['CoinPayments']];
    }

    public static function successfulOrders(): array
    {
        $cases = [];
        foreach (self::gateways() as [$method]) {
            foreach (['pending', 'cancelled', 'expired'] as $state) $cases[$method . '-' . $state] = [$method, $state];
        }
        return $cases;
    }

    private function order(string $method, string $state = 'pending'): AiTopupOrder
    {
        return AiTopupOrder::create([
            'user_id' => $this->user->id, 'client_request_id' => (string) Str::uuid(),
            'trade_no' => 'AIT' . Str::random(24), 'external_ref' => 'fixture:' . Str::uuid(),
            'status' => $state === 'cancelled' ? 'cancelled' : 'pending', 'issue_status' => 'pending',
            'amount_usd' => 200, 'unit_price_cny' => '0.150', 'total_amount' => 3000, 'handling_amount' => 10,
            'gift_traffic_bytes' => 4831838208, 'policy_snapshot' => [], 'payment_id' => 7, 'payment_method' => $method,
            'bridge_url' => 'https://bridge.example.test', 'bridge_token' => 'fixture-bridge-token',
            'expires_at' => time() + ($state === 'pending' ? 1800 : -1800),
            'cancelled_at' => $state === 'cancelled' ? time() - 120 : null,
        ]);
    }

    private function bind(string $raw, string $header, string $signature, bool $form = false): void
    {
        $request = Request::create('/fixture/payment/notify', 'POST', [], [], [], [
            'CONTENT_TYPE' => $form ? 'application/x-www-form-urlencoded' : 'application/json',
            'HTTP_' . strtoupper(str_replace('-', '_', $header)) => $signature,
        ], $raw);
        Container::getInstance()->instance('request', $request);
    }

    private function plugin(string $method)
    {
        $class = match ($method) {
            'Coinbase' => \Plugin\Coinbase\Plugin::class,
            'BTCPay' => \Plugin\Btcpay\Plugin::class,
            default => \Plugin\CoinPayments\Plugin::class,
        };
        $plugin = new $class(strtolower($method));
        $plugin->setConfig([
            'coinbase_webhook_key' => 'fixture-secret', 'btcpay_webhook_key' => 'fixture-secret',
            'btcpay_url' => 'https://btcpay.example.test/', 'btcpay_storeId' => 'fixture-store',
            'btcpay_api_key' => 'fixture-api-key', 'coinpayments_merchant_id' => 'fixture-merchant',
            'coinpayments_ipn_secret' => 'fixture-secret',
        ]);
        return $plugin;
    }

    private function notify(string $method, array $params = []): array|string|bool
    {
        $service = (new ReflectionClass(PaymentService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PaymentService::class, 'payment'))->setValue($service, $this->plugin($method));
        (new ReflectionProperty(PaymentService::class, 'config'))->setValue($service, ['enable' => true, 'id' => 7]);
        return $service->notify($params);
    }

    private function receipt(string $method, string $trade, mixed $amount = '30.1000', string $currency = 'CNY',
        ?string $event = null, ?string $state = null, bool $tampered = false): array
    {
        $params = [];
        if ($method === 'Coinbase') {
            $body = ['id' => 'delivery-fixture', 'scheduled_for' => '2026-10-25T12:00:00Z', 'event' => [
                'id' => 'coinbase-event-fixture', 'api_version' => '2018-03-22', 'created_at' => '2026-10-25T12:00:00Z',
                'type' => $event ?? 'charge:confirmed', 'data' => [
                    'id' => 'charge-fixture', 'code' => 'ABCDEF12', 'pricing_type' => 'fixed_price',
                    'metadata' => ['outTradeNo' => $trade], 'pricing' => ['local' => ['amount' => $amount, 'currency' => $currency]],
                    'timeline' => [['status' => 'NEW', 'time' => '2026-10-25T11:55:00Z'],
                        ['status' => 'COMPLETED', 'time' => '2026-10-25T11:59:00Z'],
                        ['status' => $state ?? 'COMPLETED', 'time' => '2026-10-25T12:00:00Z']],
                ],
            ]];
            $raw = " \n" . json_encode($body, JSON_THROW_ON_ERROR) . "\n ";
            $header = 'x-cc-webhook-signature'; $signature = hash_hmac('sha256', $raw, 'fixture-secret');
        } elseif ($method === 'BTCPay') {
            $body = ['deliveryId' => 'delivery-fixture', 'webhookId' => 'webhook-fixture', 'originalDeliveryId' => 'delivery-fixture',
                'isRedelivery' => false, 'type' => $event ?? 'InvoiceSettled', 'timestamp' => 1792929600,
                'storeId' => 'fixture-store', 'invoiceId' => 'invoice-fixture',
                'url' => 'https://ignored.example.test/untrusted', 'metadata' => ['orderId' => 'AIT-untrusted']];
            Http::swap(new HttpFactory()); Http::preventStrayRequests();
            Http::fake(function ($request, $options) use ($trade, $amount, $currency, $state) {
                self::assertSame(0, DB::transactionLevel());
                self::assertSame('GET', $request->method());
                self::assertSame('https://btcpay.example.test/api/v1/stores/fixture-store/invoices/invoice-fixture', $request->url());
                self::assertTrue($request->hasHeader('Authorization', 'token fixture-api-key'));
                self::assertSame(5, $options['connect_timeout']); self::assertSame(15, $options['timeout']);
                self::assertFalse($options['allow_redirects']);
                return Http::response(['id' => 'invoice-fixture', 'storeId' => 'fixture-store',
                    'status' => $state ?? 'Settled', 'additionalStatus' => 'PaidLate', 'amount' => $amount, 'currency' => $currency,
                    'metadata' => ['orderId' => $trade]], 200);
            });
            $raw = " \n" . json_encode($body, JSON_THROW_ON_ERROR) . "\n ";
            $header = 'btcpay-sig'; $signature = 'sha256=' . hash_hmac('sha256', $raw, 'fixture-secret');
        } else {
            $params = ['ipn_version' => '1.0', 'ipn_type' => 'button', 'ipn_mode' => 'hmac', 'merchant' => 'fixture-merchant',
                'txn_id' => 'coinpayments-txn-fixture', 'item_name' => "Fixture's topup", 'item_number' => $trade,
                'currency1' => $currency, 'currency2' => 'USDT.TRC20', 'amount1' => $amount, 'amount2' => '4.12',
                'status' => $state ?? '100', 'status_text' => 'Complete'];
            if (!str_starts_with($trade, 'AIT')) ksort($params);
            $raw = http_build_query($params);
            $header = 'hmac'; $signature = hash_hmac('sha512', $raw, 'fixture-secret');
        }
        $this->bind($raw, $header, $tampered ? str_repeat('0', strlen($signature)) : $signature, $method === 'CoinPayments');
        return $params;
    }

    private function unchanged(AiTopupOrder $order): void
    {
        self::assertSame('pending', $order->fresh()->status);
        self::assertNull($this->service->fulfill($order->id)->code);
        self::assertSame([], $this->client->calls); Bus::assertNothingDispatched();
    }

    public function test_coinbase_signed_nonfinal_events_ack_without_issuing_a_code(): void
    {
        $order = $this->order('Coinbase');
        foreach ([['charge:created', 'NEW'], ['charge:pending', 'PENDING'], ['charge:failed', 'FAILED'],
            ['charge:confirmed', 'NEW'], ['charge:confirmed', 'SIGNED'], ['charge:confirmed', 'PENDING'],
            ['charge:created', 'COMPLETED']] as [$event, $state]) {
            $params = $this->receipt('Coinbase', $order->trade_no, event: $event, state: $state);
            $result = $this->notify('Coinbase', $params);
            self::assertFalse($result['paid']); self::assertSame('success', $result['custom_result']);
            self::assertArrayNotHasKey('payment_amount', $result);
            self::assertFalse($this->service->paid($result, 'Coinbase')); $this->unchanged($order);
        }
    }

    #[DataProvider('successfulOrders')]
    public function test_settled_cny_evidence_fulfills_pending_cancelled_and_expired_orders(string $method, string $state): void
    {
        $order = $this->order($method, $state);
        $result = $this->notify($method, $this->receipt($method, $order->trade_no));
        self::assertTrue($result['paid']); self::assertSame($order->trade_no, $result['trade_no']);
        self::assertSame('30.1000', $result['payment_amount']); self::assertSame(7, $result['payment_id']);
        self::assertSame(match ($method) {
            'Coinbase' => 'charge-fixture', 'BTCPay' => 'invoice-fixture', default => 'coinpayments-txn-fixture',
        }, $result['callback_no']);
        self::assertTrue($this->service->paid($result, $method));
        $paid = $order->fresh(); self::assertSame('paid', $paid->status); self::assertNotNull($paid->paid_at);
        self::assertSame($result['callback_no'], $paid->callback_no);
        self::assertNotNull($this->service->fulfill($order->id)->code);
        self::assertTrue($this->service->paid($result, $method)); $this->service->fulfill($order->id);
        self::assertSame(['GET', 'POST'], $this->client->calls); Bus::assertDispatchedTimes(AiTopupFulfillJob::class, 1);
    }

    #[DataProvider('gateways')]
    public function test_other_currency_and_malformed_amount_evidence_are_rejected(string $method): void
    {
        $order = $this->order($method);
        foreach ([['30.10', 'USD'], ['30.101', 'CNY'], ['3.01e1', 'CNY'], ['-30.10', 'CNY'],
            [' 30.10', 'CNY'], ['30.10 ', 'CNY'], ['0', 'CNY'], [null, 'CNY'], [[], 'CNY']] as [$amount, $currency]) {
            $params = $this->receipt($method, $order->trade_no, $amount, $currency);
            try { $this->notify($method, $params); self::fail('Invalid currency/amount evidence was accepted.'); }
            catch (ApiException $e) { self::assertSame(400, $e->getCode()); }
            $this->unchanged($order);
        }
    }

    #[DataProvider('gateways')]
    public function test_exact_amount_evidence_is_compared_with_original_quote_including_fees(string $method): void
    {
        $order = $this->order($method);
        foreach (['30.00', '30.09', '30.11'] as $amount) {
            $result = $this->notify($method, $this->receipt($method, $order->trade_no, $amount));
            self::assertSame($amount, $result['payment_amount']);
            self::assertFalse($this->service->paid($result, $method)); $this->unchanged($order);
        }
    }

    #[DataProvider('gateways')]
    public function test_tampered_hmac_is_rejected_before_http_or_payment_processing(string $method): void
    {
        $order = $this->order($method);
        $params = $this->receipt($method, $order->trade_no, tampered: true);
        try { $this->notify($method, $params); self::fail('Tampered HMAC was accepted.'); }
        catch (ApiException $e) { self::assertSame('HMAC signature does not match', $e->getMessage()); }
        Http::assertNothingSent(); $this->unchanged($order);
    }

    public function test_btcpay_requires_both_settled_event_and_api_state(): void
    {
        $order = $this->order('BTCPay');
        foreach ([['InvoiceSettled', 'New'], ['InvoiceSettled', 'Processing'], ['InvoiceSettled', 'Expired'],
            ['InvoiceSettled', 'Invalid'], ['InvoiceProcessing', 'Settled'], ['InvoicePaymentSettled', 'Settled']] as [$event, $state]) {
            $result = $this->notify('BTCPay', $this->receipt('BTCPay', $order->trade_no, event: $event, state: $state));
            self::assertFalse($result['paid']); self::assertSame('success', $result['custom_result']);
            self::assertFalse($this->service->paid($result, 'BTCPay')); $this->unchanged($order);
        }
    }

    public function test_btcpay_rejects_path_injection_and_database_transaction_before_network(): void
    {
        Http::fake();
        foreach (['../invoices/other', 'id?foo=bar', 'https://other.example.test', 'id%2fother'] as $id) {
            $raw = json_encode(['invoiceId' => $id, 'type' => 'InvoiceSettled']);
            $this->bind($raw, 'Btcpay-Sig', 'sha256=' . hash_hmac('sha256', $raw, 'fixture-secret'));
            try { $this->notify('BTCPay'); self::fail('Untrusted invoice path was accepted.'); }
            catch (ApiException $e) { self::assertSame(400, $e->getCode()); }
        }
        $raw = json_encode(['invoiceId' => 'invoice-fixture', 'type' => 'InvoiceSettled']);
        $this->bind($raw, 'Btcpay-Sig', 'sha256=' . hash_hmac('sha256', $raw, 'fixture-secret'));
        DB::beginTransaction();
        try {
            try { $this->notify('BTCPay'); self::fail('Invoice lookup ran inside a transaction.'); }
            catch (ApiException $e) { self::assertSame(409, $e->getCode()); }
        } finally { DB::rollBack(); }
        Http::assertNothingSent();
    }

    public function test_coinpayments_pending_and_failed_statuses_ack_as_arrays_without_issuing(): void
    {
        $order = $this->order('CoinPayments');
        foreach (['0', '1', '99', '-1'] as $status) {
            $params = $this->receipt('CoinPayments', $order->trade_no, state: $status);
            $result = $this->notify('CoinPayments', $params);
            self::assertFalse($result['paid']); self::assertSame('IPN OK', $result['custom_result']);
            self::assertFalse($this->service->paid($result, 'CoinPayments')); $this->unchanged($order);
        }
        $result = $this->notify('CoinPayments', $this->receipt('CoinPayments', $order->trade_no, state: '2'));
        self::assertTrue($result['paid']); self::assertSame('30.1000', $result['payment_amount']);
    }

    public function test_coinpayments_uses_signed_body_fields_instead_of_other_input_parameters(): void
    {
        $order = $this->order('CoinPayments');
        $params = $this->receipt('CoinPayments', $order->trade_no, '0.01');
        $params['amount1'] = '30.10'; $params['currency1'] = 'USD'; $params['item_number'] = 'subscription-fixture';
        $result = $this->notify('CoinPayments', $params);
        self::assertSame('0.01', $result['payment_amount']); self::assertSame($order->trade_no, $result['trade_no']);
        self::assertFalse($this->service->paid($result, 'CoinPayments')); $this->unchanged($order);
    }

    public function test_coinbase_uses_charge_id_as_stable_payment_receipt(): void
    {
        $order = $this->order('Coinbase');
        $receipt = $this->notify('Coinbase', $this->receipt('Coinbase', $order->trade_no));
        self::assertSame('charge-fixture', $receipt['callback_no']);
    }

    public function test_coinpayments_checkout_quotes_cny_only_for_ai_topups(): void
    {
        $plugin = $this->plugin('CoinPayments');
        $plugin->setConfig(['coinpayments_currency' => 'USD', 'coinpayments_merchant_id' => 'fixture-merchant']);
        foreach (['AIT-fixture' => 'CNY', 'subscription-fixture' => 'USD'] as $trade => $currency) {
            $result = $plugin->pay(['trade_no' => $trade, 'total_amount' => 3010,
                'return_url' => 'https://panel.example.test/order', 'notify_url' => 'https://panel.example.test/notify']);
            parse_str(parse_url($result['data'], PHP_URL_QUERY), $query);
            self::assertSame($currency, $query['currency']); self::assertSame('30.10', $query['amountf']);
            self::assertSame(str_starts_with($trade, 'AIT') ? 'https://panel.example.test/order' : 'https://panel.example.test', $query['success_url']);
        }
    }

    public function test_btcpay_lookup_errors_and_mismatched_invoices_never_issue_a_code(): void
    {
        $order = $this->order('BTCPay');
        foreach ([404, 500, 200] as $status) {
            $this->receipt('BTCPay', $order->trade_no);
            Http::swap(new HttpFactory()); Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['id' => 'another-invoice', 'status' => 'Settled',
                'amount' => '30.10', 'currency' => 'CNY', 'metadata' => ['orderId' => $order->trade_no]], $status)]);
            try { $this->notify('BTCPay'); self::fail('Invalid invoice lookup was accepted.'); }
            catch (\Illuminate\Http\Client\RequestException | ApiException $e) {
                self::assertInstanceOf($status === 200 ? ApiException::class : \Illuminate\Http\Client\RequestException::class, $e);
            }
            $this->unchanged($order);
        }
    }

    #[DataProvider('gateways')]
    public function test_non_ai_subscription_result_contract_is_preserved(string $method): void
    {
        $params = $this->receipt($method, 'subscription-fixture', '30.1000', 'USD',
            event: $method === 'Coinbase' ? 'charge:created' : null, state: $method === 'CoinPayments' ? '100' : 'New');
        $result = $this->notify($method, $params);
        self::assertSame('subscription-fixture', $result['trade_no']); self::assertSame(7, $result['payment_id']);
        self::assertArrayNotHasKey('paid', $result); self::assertArrayNotHasKey('payment_amount', $result);
        if ($method === 'CoinPayments') {
            self::assertSame('IPN OK', $result['custom_result']);
            $params = $this->receipt($method, 'subscription-fixture', state: '1');
            self::assertSame('IPN OK: pending', $this->notify($method, $params));
            $params = $this->receipt($method, 'subscription-fixture', state: '-1');
            $this->expectException(ApiException::class); $this->notify($method, $params);
        }
    }
}

class EvidenceTopupClient extends AiCreditClient
{
    public array $calls = [];

    public function request(string $method, string $baseUrl, string $token, string $externalRef, ?string $amount = null): ?array
    {
        TestCase::assertSame(0, DB::transactionLevel()); $this->calls[] = $method;
        if ($method === 'GET') return null;
        return ['id' => 'fixture-code-id', 'external_ref' => $externalRef, 'type' => 'balance',
            'value' => $amount, 'code' => 'fixture-issued-code', 'status' => 'unused'];
    }
}
