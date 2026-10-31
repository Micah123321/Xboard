<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\AiCredit\AiCreditClient;
use App\Services\AiCredit\AiCreditService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;

$bridge = getenv('AI_CREDIT_SMOKE_BRIDGE_URL');
$fixture = getenv('AI_CREDIT_SMOKE_FIXTURE_URL');
if (!$bridge || !$fixture || !str_starts_with($bridge, 'http://127.0.0.1:') || !str_starts_with($fixture, 'http://127.0.0.1:')) {
    throw new RuntimeException('Run through the local bridge smoke-test launcher.');
}
$container = new Container(); Container::setInstance($container); Facade::setFacadeApplication($container);
$container->instance('app', $container);
$container->instance('config', new Illuminate\Config\Repository(['app' => ['timezone' => 'UTC']]));
$container->instance('encrypter', new Illuminate\Encryption\Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
$translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
$container->instance('translator', $translator);
$db = new Manager($container); $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); $db->bootEloquent();
$container->instance('db', $db->getDatabaseManager());
$schema = $db->getConnection()->getSchemaBuilder(); $container->instance('db.schema', $schema);
$validator = new Illuminate\Validation\Factory($translator, $container);
$validator->setPresenceVerifier(new Illuminate\Validation\DatabasePresenceVerifier($db->getDatabaseManager()));
$container->instance('validator', $validator);
$http = new Illuminate\Http\Client\Factory(); $container->instance(Illuminate\Http\Client\Factory::class, $http);
$schema->create('v2_user', function (Blueprint $t) {
    $t->increments('id'); $t->string('email'); $t->integer('plan_id')->nullable(); $t->integer('expired_at')->nullable();
    $t->boolean('banned')->default(false); $t->integer('created_at'); $t->integer('updated_at');
});
$schema->create('v2_plan', function (Blueprint $t) {
    $t->increments('id'); $t->string('name'); $t->integer('transfer_enable'); $t->integer('created_at'); $t->integer('updated_at');
});
$schema->create('v2_order', function (Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->integer('plan_id'); $t->string('trade_no'); $t->string('period');
    $t->integer('type'); $t->integer('status'); $t->integer('total_amount'); $t->integer('balance_amount')->default(0);
    $t->integer('payment_id')->nullable(); $t->string('callback_no')->nullable(); $t->integer('paid_at')->nullable();
    $t->integer('created_at'); $t->integer('updated_at');
});
(require dirname(__DIR__, 2) . '/database/migrations/2026_10_25_000001_create_ai_credit_tables.php')->up();
$service = new AiCreditService(new AiCreditClient());
$service->saveSettings(['enabled' => true, 'bridge_url' => $bridge, 'bridge_token' => 'synthetic-xboard-rewards-token-32-characters']);
$plan = Plan::create(['name' => 'Smoke 200GB', 'transfer_enable' => 200]);
$user = User::create(['email' => 'smoke@example.test', 'plan_id' => $plan->id, 'expired_at' => time() + 86400]);
$create = function (int $type = 1) use ($user, $plan) {
    return Order::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'trade_no' => bin2hex(random_bytes(8)),
        'period' => Plan::PERIOD_MONTHLY, 'type' => $type, 'status' => Order::STATUS_COMPLETED, 'total_amount' => 1990,
        'payment_id' => 1, 'callback_no' => 'synthetic-payment', 'paid_at' => time()]);
};
$check = function (bool $condition, string $message) { if (!$condition) throw new RuntimeException($message); };
$first = $service->onOrderCompleted($create(), $plan);
$issued = $service->claim($first->id, $user);
$again = $service->claim($first->id, $user);
$check($issued['status'] === 'issued' && $issued['amount_usd'] === '10.00' && $issued['code'] === $again['code'], 'Issue/replay contract failed');
$check($service->revoke($first->id, 'Synthetic smoke revocation')['status'] === 'revoked', 'Expiration contract failed');
$check(!isset($service->detail($first->id, $user)['code']), 'Revoked code remained visible');
$second = $service->onOrderCompleted($create(2), $plan);
$service->claim($second->id, $user);
$http->post($fixture . '/fixture/redeem', ['external_ref' => $second->external_ref]);
$check($service->detail($second->id, $user)['status'] === 'redeemed', 'Redemption status contract failed');
$http->post($fixture . '/fixture/lose-next-response');
$third = $service->onOrderCompleted($create(2), $plan);
$check($service->claim($third->id, $user)['status'] === 'reconciling', 'Uncertain issue was not retained');
$check($service->detail($third->id, $user)['status'] === 'issued', 'Lost response was not recovered');
$stats = $http->get($fixture . '/fixture/stats')->json();
$check($stats['created'] === 3 && $stats['expired'] === 1 && $stats['used'] === 1, 'Unexpected upstream code counts');
echo json_encode(['passed' => true, 'transport' => 'PHP HTTP client -> real Fastify bridge -> isolated HTTP upstream', 'created' => $stats['created'], 'checks' => ['issue', 'replay', 'expire', 'redemption', 'lost-response-recovery']], JSON_UNESCAPED_SLASHES) . PHP_EOL;
