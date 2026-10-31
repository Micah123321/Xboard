<?php

// Isolated Laravel fixture for the existing admin dev page; never loads project credentials.
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$directory = $root . '/output/nya-entry-admin-e2e';
if (!is_dir($directory)) mkdir($directory, 0700, true);
foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:' . base64_encode(str_repeat('n', 32)),
    'APP_URL' => 'http://127.0.0.1:18918', 'CACHE_DRIVER' => 'file',
    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'single',
    'APP_CONFIG_CACHE' => $directory . '/config.php', 'APP_ROUTES_CACHE' => $directory . '/routes.php',
] as $key => $value) {
    putenv($key . '=' . $value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
}
$app = require $root . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.nya-entry-isolated-does-not-exist');
$app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class,
    function ($app) use ($root, $directory): void {
        $config = $app->make('config');
        $database = $directory . '/fixture.sqlite';
        if (!file_exists($database)) touch($database);
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => $database,
            'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000]);
        $config->set('cache.default', 'file');
        $config->set('cache.stores.redis', ['driver' => 'file', 'path' => $directory . '/cache']);
        $config->set('cache.stores.file.path', $directory . '/cache');
        $config->set('logging.channels.single.path', $directory . '/laravel.log');
        $schema = $app->make('db')->connection()->getSchemaBuilder();
        if (!$schema->hasTable('v2_settings')) {
            $schema->create('v2_settings', function ($table): void {
                $table->id(); $table->string('name')->unique(); $table->text('value')->nullable();
                $table->string('group')->nullable(); $table->string('type')->nullable(); $table->timestamps();
            });
            $schema->create('v2_plugins', function ($table): void {
                $table->id(); $table->string('code')->unique(); $table->string('type')->nullable();
                $table->boolean('is_enabled')->default(false); $table->text('config')->nullable(); $table->timestamps();
            });
            $schema->create('v2_user', function ($table): void {
                $table->id(); $table->string('email')->unique(); $table->string('password');
                $table->string('uuid'); $table->string('token'); $table->boolean('is_admin')->default(false);
                $table->boolean('is_staff')->default(false); $table->boolean('banned')->default(false);
                $table->string('language')->nullable(); $table->integer('created_at'); $table->integer('updated_at');
            });
            $schema->create('personal_access_tokens', function ($table): void {
                $table->id(); $table->morphs('tokenable'); $table->string('name');
                $table->string('token', 64)->unique(); $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();
            });
            $schema->create('v2_admin_audit_log', function ($table): void {
                $table->id(); $table->integer('admin_id'); $table->string('action');
                $table->string('method'); $table->string('uri'); $table->text('request_data');
                $table->string('ip'); $table->integer('created_at'); $table->integer('updated_at');
            });
            $schema->create('v2_server', function ($table): void {
                $table->id(); $table->string('name'); $table->string('type')->default('trojan');
                $table->string('host'); $table->string('port'); $table->integer('server_port')->default(30521);
                $table->integer('parent_id')->nullable(); $table->boolean('entry_points_enabled')->default(false);
                $table->text('entry_points')->nullable(); $table->text('protocol_settings')->nullable();
                $table->boolean('child_rate_enabled')->default(false); $table->boolean('tcp_check_enabled')->default(false);
                $table->boolean('show')->default(true); $table->boolean('enabled')->default(true);
                $table->decimal('rate', 8, 2)->default(12.5); $table->integer('sort')->default(0); $table->timestamps();
            });
        }
        foreach (['secure_path' => 'nya-entry-fixture', 'app_name' => 'Xboard Admin'] as $name => $value) {
            $app->make('db')->table('v2_settings')->insertOrIgnore([
                'name' => $name, 'value' => $value, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Http::fake(function ($request) use ($directory) {
            if (!str_starts_with($request->url(), 'https://nya.example.test/')) {
                return Illuminate\Support\Facades\Http::response(['code' => 1, 'data' => null], 502);
            }
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/v1/auth/login') {
                $backup = $request['username'] === 'saobi';
                return Illuminate\Support\Facades\Http::response([
                    'code' => $request['password'] === ($backup ? 'fixture-backup-password' : 'fixture-primary-password') ? 0 : 1,
                    'data' => $backup ? 'fixture-backup-token' : 'fixture-primary-token']);
            }
            $backup = $request->hasHeader('Authorization', 'fixture-backup-token');
            $ip = $backup ? '203.0.113.22' : '198.51.100.22';
            $data = $path === '/api/v1/user/devicegroup'
                ? [['id' => 'inbound', 'type' => 'DeviceGroupType_Inbound', 'connect_host' => $ip]]
                : array_map(fn (int $port): array => ['id' => 'rule-' . $port, 'device_group_in' => 'inbound', 'listen_port' => $port], $backup ? [11823] : [12192, 15767, 28877]);
            return Illuminate\Support\Facades\Http::response(['code' => 0, 'data' => $data]);
        });
        Illuminate\Support\Facades\Redis::swap(new class {
            public function connection($name) { return $this; }
            public function zrevrangebyscore(...$arguments) { return []; }
        });
    });
if (PHP_SAPI === 'cli') {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (($argv[1] ?? '') === 'init') {
        foreach (['admin' => true, 'user' => false] as $role => $isAdmin) {
            $user = App\Models\User::withoutEvents(fn () => App\Models\User::updateOrCreate(['email' => $role . '@nya.example.test'], [
                'password' => password_hash('fixture-password', PASSWORD_DEFAULT), 'uuid' => 'fixture-' . $role,
                'token' => 'fixture-' . $role, 'is_admin' => $isAdmin,
            ]));
            Illuminate\Support\Facades\DB::table('personal_access_tokens')->updateOrInsert(['name' => 'nya-' . $role], [
                'tokenable_type' => App\Models\User::class, 'tokenable_id' => $user->id,
                'token' => hash('sha256', 'nya-' . $role . '-browser-fixture-token'), 'abilities' => '["*"]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([434 => ['12.5x Singapore dedicated', 12192], 430 => ['12.5x Japan dedicated', 15767]] as $id => [$name, $port]) {
            Illuminate\Support\Facades\DB::table('v2_server')->updateOrInsert(['id' => $id], [
                'name' => $name, 'host' => '123.253.227.76', 'port' => (string) $port, 'type' => 'trojan',
                'entry_points_enabled' => true, 'entry_points' => json_encode([['host' => '123.253.227.76', 'port' => 11823]]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        App\Models\Setting::where('name', 'nya_entry_sync')->delete();
        Illuminate\Support\Facades\Cache::flush();
        Illuminate\Support\Facades\Cache::put(App\Utils\CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time(), 86400);
        echo json_encode(['initialized' => true, 'nodes' => 2]) . PHP_EOL;
    } elseif (($argv[1] ?? '') === 'inspect') {
        $settings = Illuminate\Support\Facades\DB::table('v2_settings')->where('name', 'nya_entry_sync')->value('value');
        $audit = Illuminate\Support\Facades\DB::table('v2_admin_audit_log')->pluck('request_data')->implode('');
        echo json_encode(['nodes' => Illuminate\Support\Facades\DB::table('v2_server')->select('id', 'host', 'port', 'entry_points')->orderBy('id')->get(),
            'settings_encrypted' => is_string($settings) && !str_contains($settings, 'fixture-password'),
            'audit_redacted' => !str_contains($audit, 'fixture-password')]) . PHP_EOL;
    }
    return;
}
$request = Illuminate\Http\Request::capture();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
