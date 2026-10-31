<?php

// Isolated real Laravel HTTP application. No project .env or production database is loaded.
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$directory = $root . '/output/ai-topup-e2e';
if (!is_dir($directory)) mkdir($directory, 0700, true);
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:' . base64_encode(str_repeat('x', 32)),
    'APP_URL' => 'http://127.0.0.1:18682', 'CACHE_DRIVER' => 'file', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'single',
    'APP_CONFIG_CACHE' => $directory . '/config.php', 'APP_ROUTES_CACHE' => $directory . '/routes.php',
] as $key => $value) { putenv($key . '=' . $value); $_ENV[$key] = $value; $_SERVER[$key] = $value; }
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.ai-topup-isolated-does-not-exist');
$app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class, function ($app) use ($root, $directory) {
    $config = $app->make('config');
    $config->set('database.default', 'sqlite');
    $database = $directory . '/fixture.sqlite';
    if (!file_exists($database)) touch($database);
    $config->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => $database,
        'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 30000]);
    $config->set('cache.default', 'file');
    $config->set('cache.stores.redis', ['driver' => 'file', 'path' => $directory . '/cache']);
    $config->set('cache.stores.file.path', $directory . '/cache');
    $config->set('queue.default', 'sync');
    $config->set('logging.channels.single.path', $directory . '/laravel.log');
    $config->set('app.url', 'http://127.0.0.1:18682');
    $schema = $app->make('db')->connection()->getSchemaBuilder();
    if (!$schema->hasTable('v2_user')) {
        (require $root . '/database/migrations/2023_03_19_000000_create_v2_tables.php')->up();
        require_once $root . '/database/migrations/2023_08_14_221234_create_v2_settings_table.php';
        (new CreateV2SettingsTable())->up();
        (require $root . '/database/migrations/2025_01_18_140511_create_plugins_table.php')->up();
        (require $root . '/database/migrations/2025_07_26_000001_add_type_to_plugins_table.php')->up();
        $schema->table('v2_user', function ($table) {
            $table->bigInteger('temporary_transfer_enable')->default(0);
            $table->integer('next_reset_at')->nullable();
            $table->integer('last_reset_at')->nullable();
            $table->integer('reset_count')->default(0);
        });
        $schema->create('personal_access_tokens', function ($table) {
            $table->bigIncrements('id'); $table->morphs('tokenable'); $table->string('name');
            $table->string('token', 64)->unique(); $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();
        });
        (require $root . '/database/migrations/2026_10_25_000001_create_ai_credit_tables.php')->up();
    }
    if (!$schema->hasTable('v2_server')) {
        $schema->create('v2_server', function ($table) {
            $table->id(); $table->json('group_ids')->nullable();
        });
    }
    if (!$schema->hasTable('v2_ai_topup_orders') && file_exists($root . '/app/Services/AiTopup/AiTopupService.php')) {
        (require $root . '/database/migrations/2026_10_25_000002_create_ai_topup_tables.php')->up();
    }
    if ($schema->hasTable('v2_ai_topup_orders') && !$schema->hasColumn('v2_ai_topup_orders', 'checkout_payload')) {
        $schema->table('v2_ai_topup_orders', function ($table) {
            $table->text('checkout_payload')->nullable(); $table->string('checkout_token', 36)->nullable();
            $table->unsignedInteger('checkout_started_at')->nullable(); $table->json('payment_receipts')->nullable();
        });
    }
});
if (PHP_SAPI === 'cli') {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (($argv[1] ?? '') === 'init') {
        admin_setting(['secure_path' => 'fixture-admin', 'app_name' => 'AI top-up isolated verification']);
        $plan = App\Models\Plan::firstOrCreate(['name' => 'Fixture 200GB'], [
            'group_id' => 1, 'transfer_enable' => 200, 'reset_traffic_method' => 2,
        ]);
        $sessions = [];
        foreach (['user', 'admin'] as $role) {
            $user = App\Models\User::firstOrCreate(['email' => $role . '@ai-topup.example.test'], [
                'password' => password_hash('synthetic-test-password', PASSWORD_DEFAULT),
                'uuid' => (string) Illuminate\Support\Str::uuid(), 'token' => Illuminate\Support\Str::random(32),
                'is_admin' => $role === 'admin', 'plan_id' => $plan->id, 'group_id' => 1,
                'expired_at' => time() + 86400 * 30, 'transfer_enable' => 200 * 1073741824,
            ]);
            $token = $user->createToken('ai-topup-fixture', ['*'], now()->addHours(4));
            $sessions[$role . '_auth'] = 'Bearer ' . explode('|', $token->plainTextToken, 2)[1];
            $sessions[$role . '_id'] = $user->id;
        }
        $sessions['secure_path'] = 'fixture-admin';
        file_put_contents($directory . '/sessions.json', json_encode($sessions));
        App\Models\Plugin::firstOrCreate(['code' => 'epay'], [
            'name' => 'Fixture Epay', 'version' => '1.0.0', 'type' => 'payment', 'is_enabled' => true,
        ]);
        App\Models\Payment::updateOrCreate(['uuid' => 'fixture-epay'], [
            'name' => 'Existing test gateway', 'payment' => 'EPay', 'enable' => true,
            'handling_fee_fixed' => 0, 'handling_fee_percent' => 0, 'sort' => 1,
            'config' => ['url' => 'http://127.0.0.1:18683', 'pid' => 'fixture', 'key' => 'fixture-signing-key', 'type' => 'alipay'],
        ]);
        app(App\Services\AiCredit\AiCreditService::class)->saveSettings([
            'enabled' => false, 'bridge_url' => 'http://127.0.0.1:18683',
            'bridge_token' => 'synthetic-xboard-rewards-token-32-characters',
        ]);
        app(App\Services\AiTopup\AiTopupService::class)->saveSettings(['enabled' => true]);
        echo json_encode(['initialized' => true, 'database' => $database ?? 'isolated', 'fixture_users' => 2]) . PHP_EOL;
    } elseif (($argv[1] ?? '') === 'reconcile') {
        echo json_encode(app(App\Services\AiTopup\AiTopupService::class)->reconcile()) . PHP_EOL;
    }
    return;
}
$request = Illuminate\Http\Request::capture();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request); $response->send(); $kernel->terminate($request, $response);
