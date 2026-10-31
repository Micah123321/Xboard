<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\Nya\NyaEntrySyncRunner;
use App\Services\Nya\NyaEntrySyncSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NyaEntryAdminTest extends TestCase
{
    private const API = '/api/v2/nya-test-admin/server/entry-sync';

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.nya-offline-does-not-exist');
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));
        $app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\BootProviders::class, static function ($app): void {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.database', ':memory:');
            $app['config']->set('cache.default', 'array');
            $app['config']->set('cache.stores.redis', ['driver' => 'array']);
            $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('n', 32)));
            $app['config']->set('app.debug', false);
            $app['config']->set('logging.default', 'null');
            $app['config']->set('v2board.secure_path', 'nya-test-admin');
            $app['config']->set('nya_entry_sync', ['enabled' => false, 'base_url' => 'https://nya.example.test',
                'username' => 'fixture-user', 'password' => 'fixture-password', 'proxy' => '',
                'timeout' => 5, 'server_ids' => [], 'ports' => []]);
        });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::purge('sqlite');
        Cache::flush();
        Http::preventStrayRequests();
        Schema::create('v2_plugins', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_enabled')->default(false);
        });
        Schema::create('v2_admin_audit_log', function (Blueprint $table): void {
            $table->id();
            $table->integer('admin_id');
            $table->string('action');
            $table->string('method');
            $table->string('uri');
            $table->text('request_data');
            $table->string('ip');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('NYA fixture');
            $table->string('type')->default('trojan');
            $table->string('host');
            $table->string('port');
            $table->integer('server_port')->default(30521);
            $table->integer('parent_id')->nullable();
            $table->boolean('entry_points_enabled')->default(true);
            $table->text('entry_points')->nullable();
            $table->text('protocol_settings')->nullable();
            $table->timestamps();
        });
    }

    public function test_real_admin_routes_reject_guests_and_non_admin_users(): void
    {
        foreach ([false, true] as $signedIn) {
            if ($signedIn) {
                Sanctum::actingAs(new User(['id' => 2, 'is_admin' => false]));
            }
            $this->getJson(self::API . '/settings')->assertForbidden();
            $this->getJson(self::API . '/status')->assertForbidden();
            $this->postJson(self::API . '/settings', $this->form())->assertForbidden();
            $this->postJson(self::API . '/run', ['dry_run' => true])->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_settings_are_encrypted_and_password_and_protocol_secrets_never_echo(): void
    {
        $node = $this->node();
        $this->admin();
        $response = $this->postJson(self::API . '/settings', $this->form(['server_ids' => [$node->id]]))
            ->assertOk()->assertJsonPath('data.settings.password_set', true)
            ->assertJsonPath('data.nodes.0.entry_points_count', 1);
        self::assertArrayNotHasKey('password', $response->json('data.settings'));
        self::assertSame(['id', 'name', 'parent_id', 'host', 'port', 'type', 'entry_points_count'],
            array_keys($response->json('data.nodes.0')));
        self::assertStringNotContainsString('fixture-password', $response->getContent());
        self::assertStringNotContainsString('protocol-secret', $response->getContent());
        $raw = DB::table('v2_settings')->where('name', 'nya_entry_sync')->value('value');
        self::assertStringNotContainsString('fixture-password', $raw);
        $stored = json_decode(Crypt::decryptString($raw), true);
        self::assertArrayNotHasKey('password', $stored);
        self::assertArrayNotHasKey('username', $stored);
        self::assertSame('fixture-password', $stored['accounts'][0]['password']);
        self::assertArrayNotHasKey('password', $response->json('data.settings.accounts.0'));
        self::assertSame(1, DB::table('v2_settings')->count());
        $this->getJson(self::API . '/settings')->assertJsonPath('data.settings.password_set', true);
        Http::assertNothingSent();
    }

    public function test_legacy_database_settings_migrate_without_rewriting_until_saved(): void
    {
        $this->admin();
        $legacy = $this->form(['username' => 'legacy-user', 'password' => 'legacy-secret']);
        $ciphertext = Crypt::encryptString(json_encode($legacy, JSON_THROW_ON_ERROR));
        DB::table('v2_settings')->insert(['name' => NyaEntrySyncSettings::NAME, 'value' => $ciphertext]);
        $this->getJson(self::API . '/settings')->assertOk()
            ->assertJsonPath('data.settings.accounts', [[
                'id' => 'legacy', 'name' => '账号 1', 'username' => 'legacy-user',
                'password_set' => true, 'enabled' => true,
            ]]);
        $runtime = app(NyaEntrySyncSettings::class)->runtime();
        self::assertSame('legacy-secret', $runtime['accounts'][0]['password']);
        self::assertSame('legacy-user', $runtime['username']);
        self::assertSame('legacy-secret', $runtime['password']);
        self::assertSame($ciphertext, DB::table('v2_settings')->value('value'));
        $this->postJson(self::API . '/settings', array_replace($legacy, ['password' => '']))->assertOk();
        $stored = $this->storedSettings();
        self::assertSame($runtime['accounts'], $stored['accounts']);
        self::assertArrayNotHasKey('password', $stored);
        self::assertArrayNotHasKey('username', $stored);
        Http::assertNothingSent();
    }

    public function test_invalid_legacy_port_defaults_fail_before_requests_or_node_writes(): void
    {
        $node = $this->node();
        $this->admin();
        config()->set('nya_entry_sync.server_ids', [$node->id]);
        $this->fakePanel('https://nya.example.test');
        foreach ([['15767x'], ['15767', '15767x'], ['0'], ['65536'], ['-1'], ['1.5']] as $ports) {
            config()->set('nya_entry_sync.ports', $ports);
            $response = $this->postJson(self::API . '/run', ['dry_run' => false])->assertOk()
                ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result', null);
            self::assertStringContainsString('NYA_CONFIG_ERROR', $response->json('data.error'));
            Http::assertNothingSent();
            self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
            self::assertSame(0, DB::table('v2_settings')->count());
        }
    }

    public function test_valid_legacy_port_defaults_keep_scope_and_empty_defaults_allow_all_ports(): void
    {
        $node = $this->node();
        $this->admin();
        config()->set('nya_entry_sync.server_ids', [' ' . $node->id . ' ', '', null]);
        config()->set('nya_entry_sync.ports', [' 15767 ', '15767', '', null]);
        $settings = app(NyaEntrySyncSettings::class);
        self::assertSame([$node->id], $settings->runtime()['server_ids']);
        self::assertSame([15767], $settings->runtime()['ports']);
        $this->fakePanel('https://nya.example.test');
        $this->postJson(self::API . '/run', ['dry_run' => false])->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.matched', 1);
        self::assertSame($node->host, $node->fresh()->host);
        self::assertSame('198.51.100.10', $node->fresh()->entry_points[0]['host']);
        config()->set('nya_entry_sync.ports', ['', null, ' ']);
        self::assertSame([], $settings->runtime()['ports']);
        $this->postJson(self::API . '/run', ['dry_run' => false])->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.matched', 2);
        self::assertSame('198.51.100.10', $node->fresh()->host);
        self::assertSame(0, DB::table('v2_settings')->count());
        Http::assertSentCount(6);
    }

    public function test_saved_port_scope_takes_precedence_over_invalid_legacy_defaults(): void
    {
        $node = $this->node();
        $this->admin();
        $this->postJson(self::API . '/settings', $this->form(['server_ids' => [$node->id], 'ports' => [15767]]))->assertOk();
        config()->set('nya_entry_sync.ports', ['15767x']);
        $this->fakePanel();
        $this->postJson(self::API . '/run', ['dry_run' => false])->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.matched', 1);
        self::assertSame($node->host, $node->fresh()->host);
        self::assertSame('198.51.100.10', $node->fresh()->entry_points[0]['host']);
        Http::assertSentCount(3);
    }

    public function test_legacy_configuration_without_credentials_exposes_one_empty_editable_card(): void
    {
        $this->admin();
        config()->set('nya_entry_sync.username', '');
        config()->set('nya_entry_sync.password', '');
        $this->getJson(self::API . '/settings')->assertOk()
            ->assertJsonPath('data.settings.accounts', [[
                'id' => 'legacy', 'name' => '账号 1', 'username' => '',
                'password_set' => false, 'enabled' => true,
            ]]);
        self::assertSame(0, DB::table('v2_settings')->count());
    }

    public function test_multiple_account_passwords_follow_ids_across_reorder_and_username_changes(): void
    {
        $this->admin();
        $this->postJson(self::API . '/settings', $this->accountForm([
            $this->account('first', ['password' => 'first-secret']),
            $this->account('second', ['password' => 'second-secret']),
        ]))->assertOk();
        $response = $this->postJson(self::API . '/settings', $this->accountForm([
            $this->account('second', ['username' => 'renamed-user', 'password' => '']),
            $this->account('first'),
        ]))->assertOk()->assertJsonPath('data.settings.accounts.0.password_set', true)
            ->assertJsonPath('data.settings.accounts.1.password_set', true);
        $runtime = app(NyaEntrySyncSettings::class)->runtime();
        self::assertSame(['second-secret', 'first-secret'], array_column($runtime['accounts'], 'password'));
        self::assertSame('renamed-user', $runtime['accounts'][0]['username']);
        self::assertSame('second-secret', $runtime['password']);
        foreach ($response->json('data.settings.accounts') as $account) {
            self::assertSame(['id', 'name', 'username', 'password_set', 'enabled'], array_keys($account));
        }
        self::assertArrayNotHasKey('password', $response->json('data.settings'));
        foreach (['first-secret', 'second-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $response->getContent());
            self::assertStringNotContainsString($secret, DB::table('v2_settings')->value('value'));
        }
        $this->getJson(self::API . '/settings')->assertOk()
            ->assertJsonPath('data.settings.accounts.0.username', 'renamed-user');
        $this->postJson(self::API . '/settings', $this->accountForm([
            $this->account('second', ['password' => 'replacement-secret']),
            $this->account('first', ['password' => null]),
        ]))->assertOk();
        self::assertSame(['replacement-secret', 'first-secret'], array_column($this->storedSettings()['accounts'], 'password'));
        self::assertSame(1, DB::table('v2_settings')->count());
        Http::assertNothingSent();
    }

    public function test_removed_credentials_are_deleted_and_disabling_preserves_only_that_account(): void
    {
        $this->admin();
        $this->postJson(self::API . '/settings', $this->accountForm([
            $this->account('first', ['password' => 'deleted-secret']),
            $this->account('second', ['password' => 'retained-secret']),
        ]))->assertOk();
        $this->postJson(self::API . '/settings', $this->accountForm([
            $this->account('second', ['enabled' => false]),
        ]))->assertOk()->assertJsonCount(1, 'data.settings.accounts')
            ->assertJsonPath('data.settings.accounts.0.id', 'second')
            ->assertJsonPath('data.settings.accounts.0.enabled', false)
            ->assertJsonPath('data.settings.accounts.0.password_set', true);
        self::assertSame(['second'], array_column($this->storedSettings()['accounts'], 'id'));
        self::assertStringNotContainsString('deleted-secret', json_encode($this->storedSettings()));
        $this->postJson(self::API . '/settings', $this->accountForm([
            $this->account('first', ['password' => '']),
            $this->account('second', ['enabled' => false, 'clear_password' => true, 'password' => 'ignored-secret']),
        ]))->assertOk()->assertJsonPath('data.settings.accounts.0.password_set', false)
            ->assertJsonPath('data.settings.accounts.1.password_set', false);
        self::assertSame(['', ''], array_column($this->storedSettings()['accounts'], 'password'));
        $this->postJson(self::API . '/settings', $this->accountForm([]))->assertOk()
            ->assertJsonPath('data.settings.accounts', []);
        self::assertSame([], app(NyaEntrySyncSettings::class)->runtime()['accounts']);
        $this->getJson(self::API . '/settings')->assertJsonPath('data.settings.accounts', []);
    }

    public function test_cached_single_account_forms_preserve_other_accounts_and_omitted_username(): void
    {
        $this->admin();
        $this->postJson(self::API . '/settings', $this->accountForm([
            $this->account('first', ['password' => 'first-secret']),
            $this->account('second', ['password' => 'second-secret', 'enabled' => false]),
        ]))->assertOk();
        $other = $this->storedSettings()['accounts'][1];
        $this->postJson(self::API . '/settings', $this->form(['username' => 'cached-user', 'password' => '']))
            ->assertOk()->assertJsonCount(2, 'data.settings.accounts');
        $stored = $this->storedSettings();
        self::assertSame('cached-user', $stored['accounts'][0]['username']);
        self::assertSame('first-secret', $stored['accounts'][0]['password']);
        self::assertSame($other, $stored['accounts'][1]);
        $form = $this->form(['timeout' => 9]);
        unset($form['username'], $form['password']);
        $this->postJson(self::API . '/settings', $form)->assertOk();
        self::assertSame($stored['accounts'], $this->storedSettings()['accounts']);
        $this->postJson(self::API . '/settings', $this->form(['password' => 'new-first-secret']))->assertOk();
        self::assertSame('new-first-secret', $this->storedSettings()['accounts'][0]['password']);
        self::assertSame($other, $this->storedSettings()['accounts'][1]);
    }

    public function test_account_ids_structure_and_field_limits_are_validated_without_writing(): void
    {
        $this->admin();
        $valid = $this->account('valid');
        $invalid = [
            [[$this->account('')], 'accounts.0.id'],
            [[$this->account('bad.id')], 'accounts.0.id'],
            [[$this->account('space id')], 'accounts.0.id'],
            [[$this->account(str_repeat('a', 65))], 'accounts.0.id'],
            [[array_replace($valid, ['id' => 1])], 'accounts.0.id'],
            [[$valid, $valid], 'accounts.0.id'],
            [[$this->account('valid', ['name' => str_repeat('n', 101)])], 'accounts.0.name'],
            [[$this->account('valid', ['username' => str_repeat('u', 256)])], 'accounts.0.username'],
            [[$this->account('valid', ['password' => str_repeat('p', 4097)])], 'accounts.0.password'],
            [[$this->account('valid', ['enabled' => 'yes'])], 'accounts.0.enabled'],
            [[$this->account('valid', ['clear_password' => 'yes'])], 'accounts.0.clear_password'],
            [[$this->account('valid', ['credentials' => ['password' => 'nested-secret']])], 'accounts.0'],
            [['named' => $valid], 'accounts'],
            [null, 'accounts'],
            [array_map(fn (int $id): array => $this->account('id-' . $id), range(1, 11)), 'accounts'],
        ];
        foreach ($invalid as [$accounts, $field]) {
            $this->postJson(self::API . '/settings', $this->accountForm($accounts))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        self::assertSame(0, DB::table('v2_settings')->count());
        $boundary = $this->account(str_repeat('A', 62) . '_-', [
            'name' => str_repeat('n', 100), 'username' => str_repeat('u', 255), 'password' => str_repeat('p', 4096),
        ]);
        $this->postJson(self::API . '/settings', $this->accountForm([
            $boundary, ...array_map(fn (int $id): array => $this->account('id-' . $id), range(2, 10)),
        ]))->assertOk()->assertJsonCount(10, 'data.settings.accounts');
        self::assertSame($boundary['password'], $this->storedSettings()['accounts'][0]['password']);
        Http::assertNothingSent();
    }

    public function test_enabled_and_manual_readiness_require_all_enabled_accounts_and_existing_nodes(): void
    {
        $this->admin();
        $node = $this->node();
        $settings = app(NyaEntrySyncSettings::class);
        $complete = $this->account('complete', ['password' => 'complete-secret']);
        foreach ([[], [$this->account('disabled', ['enabled' => false])],
            [$complete, $this->account('incomplete')],
            [$complete, $this->account('missing-user', ['username' => '', 'password' => 'secret'])],
        ] as $accounts) {
            $draft = $this->accountForm($accounts, ['server_ids' => [$node->id]]);
            $this->postJson(self::API . '/settings', $draft)->assertOk();
            $this->postJson(self::API . '/settings', array_replace($draft, ['enabled' => true]))
                ->assertUnprocessable()->assertJsonValidationErrors('enabled');
            $this->postJson(self::API . '/run', ['dry_run' => true])->assertOk()
                ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result', null);
        }
        $ready = $this->accountForm([$complete, $this->account('draft', ['enabled' => false, 'username' => ''])],
            ['server_ids' => [$node->id], 'enabled' => true]);
        $this->postJson(self::API . '/settings', $ready)->assertOk();
        $settings->assertReady($settings->runtime());
        $this->postJson(self::API . '/settings', array_replace($ready, ['server_ids' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('enabled');
        Server::withoutEvents(static fn () => $node->delete());
        $this->postJson(self::API . '/settings', $ready)->assertUnprocessable()->assertJsonValidationErrors('server_ids.0');
        $this->postJson(self::API . '/run', ['dry_run' => true])->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result', null);
        Http::assertNothingSent();
    }

    public function test_empty_or_omitted_password_preserves_secret_and_explicit_clear_removes_it(): void
    {
        $this->admin();
        $this->postJson(self::API . '/settings', $this->form(['password' => 'saved-secret']))->assertOk();
        foreach (['', null] as $password) {
            $form = $this->form(['password' => $password]);
            if ($password === null) {
                unset($form['password']);
            }
            $this->postJson(self::API . '/settings', $form)->assertOk();
            self::assertSame('saved-secret', app(NyaEntrySyncSettings::class)->runtime()['password']);
        }
        $this->postJson(self::API . '/settings', $this->form(['password' => '', 'clear_password' => true]))
            ->assertOk()->assertJsonPath('data.settings.password_set', false);
        self::assertSame('', app(NyaEntrySyncSettings::class)->runtime()['password']);
    }

    public function test_disabled_drafts_are_allowed_but_enable_and_execution_require_complete_settings(): void
    {
        $this->admin();
        $draft = $this->form(['base_url' => '', 'username' => '', 'password' => '', 'clear_password' => true]);
        $this->postJson(self::API . '/settings', $draft)->assertOk();
        $this->postJson(self::API . '/settings', array_replace($draft, ['enabled' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $this->postJson(self::API . '/run', ['dry_run' => false])->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result', null);
        Http::assertNothingSent();
    }

    public function test_urls_node_scope_ports_and_timeout_are_validated(): void
    {
        $this->admin();
        foreach ([
            ['base_url', 'https://user:secret@panel.example.test'],
            ['base_url', 'https://panel.example.test?token=secret'],
            ['base_url', 'https://panel.example.test#fragment'],
            ['base_url', 'ftp://panel.example.test'],
            ['proxy', 'http://user:secret@proxy.example.test:8080'],
            ['proxy', 'socks5://127.0.0.1:1080'],
            ['server_ids', [99999]], ['ports', [0]], ['ports', [65536]], ['timeout', 0], ['timeout', 61],
        ] as [$field, $value]) {
            $this->postJson(self::API . '/settings', $this->form([$field => $value]))->assertUnprocessable();
        }
        self::assertSame(0, DB::table('v2_settings')->count());
        Http::assertNothingSent();
    }

    public function test_preview_persists_run_state_but_never_changes_node_or_saved_settings(): void
    {
        $node = $this->node();
        $this->admin();
        $this->postJson(self::API . '/settings', $this->form(['server_ids' => [$node->id]]))->assertOk();
        $settingsBefore = DB::table('v2_settings')->first();
        $this->fakePanel();
        DB::enableQueryLog();
        $run = $this->postJson(self::API . '/run', ['dry_run' => true])->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.source', 'manual')->assertJsonPath('data.result.updated', 1)->json('data');
        self::assertCount(2, $run['result']['changes']);
        self::assertNotNull($run['finished_at']);
        self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        self::assertEquals($settingsBefore, DB::table('v2_settings')->first());
        self::assertSame([], array_filter(DB::getQueryLog(), static fn (array $query): bool =>
            preg_match('/^(update|insert|delete).*v2_server/i', $query['query']) === 1));
        Cache::put('SCHEDULE_LAST_CHECK_AT', 123456);
        $this->getJson(self::API . '/status')->assertOk()->assertJsonPath('data.last_run.id', $run['id'])
            ->assertJsonPath('data.scheduler.last_checked_at', 123456);
        Http::assertSentCount(3);
    }

    public function test_execution_reads_new_saved_settings_and_ignores_unsaved_request_fields(): void
    {
        $node = $this->node();
        $other = $this->node();
        $this->admin();
        $this->postJson(self::API . '/settings', $this->form(['server_ids' => [$node->id]]))->assertOk();
        $this->postJson(self::API . '/settings', $this->form(['server_ids' => [$node->id],
            'base_url' => 'https://new.example.test/panel', 'username' => 'new-user', 'password' => 'new-password']))->assertOk();
        $this->fakePanel('https://new.example.test/panel', 'new-user', 'new-password');
        $this->postJson(self::API . '/run', ['dry_run' => false, 'server_ids' => [$other->id],
            'base_url' => 'https://ignored.example.test'])->assertOk()->assertJsonPath('data.status', 'succeeded');
        self::assertSame('198.51.100.10', $node->fresh()->host);
        self::assertSame('198.51.100.10', $node->fresh()->entry_points[0]['host']);
        self::assertTrue($node->fresh()->entry_points_enabled);
        self::assertSame('old.example.test', $other->fresh()->host);
        self::assertSame('fixture-user', config('nya_entry_sync.username'));
        Http::assertSentCount(3);
    }

    public function test_shared_lock_blocks_web_and_cli_without_replacing_current_state(): void
    {
        $this->admin();
        $active = ['id' => 'existing-run', 'status' => 'running'];
        Cache::put(NyaEntrySyncRunner::LAST_RUN, $active, 604800);
        $lock = Cache::lock(NyaEntrySyncRunner::LOCK, 600);
        self::assertTrue($lock->get());
        try {
            $this->postJson(self::API . '/run', ['dry_run' => false])->assertOk()
                ->assertJsonPath('data.status', 'failed');
            $this->artisan('sync:nya-entry-ips')->expectsOutputToContain('already running')->assertSuccessful();
            self::assertSame($active, app(NyaEntrySyncRunner::class)->status()['last_run']);
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_abandoned_run_recovers_only_after_its_lock_is_free_and_can_run_again(): void
    {
        $node = $this->node();
        $this->admin();
        $this->postJson(self::API . '/settings', $this->form(['server_ids' => [$node->id]]))->assertOk();
        $abandoned = ['id' => 'abandoned-run', 'status' => 'running', 'dry_run' => false,
            'source' => 'manual', 'started_at' => time() - 601, 'finished_at' => null,
            'result' => null, 'error' => null];
        Cache::put(NyaEntrySyncRunner::LAST_RUN, $abandoned, 604800);
        $lock = Cache::lock(NyaEntrySyncRunner::LOCK, 600);
        self::assertTrue($lock->get());
        try {
            $this->getJson(self::API . '/status')->assertOk()->assertJsonPath('data.last_run.status', 'running');
        } finally {
            $lock->release();
        }
        $expired = $this->getJson(self::API . '/status')->assertOk()
            ->assertJsonPath('data.last_run.status', 'failed')->json('data.last_run');
        self::assertStringContainsString('NYA_RUN_EXPIRED', $expired['error']);
        self::assertSame($expired, Cache::get(NyaEntrySyncRunner::LAST_RUN));
        $this->fakePanel();
        $this->postJson(self::API . '/run', ['dry_run' => true])->assertOk()
            ->assertJsonPath('data.status', 'succeeded');
    }

    public function test_failure_is_sanitized_and_lock_is_released(): void
    {
        $node = $this->node();
        $this->admin();
        $this->postJson(self::API . '/settings', $this->form(['server_ids' => [$node->id]]))->assertOk();
        Http::fake(static function (): void {
            throw new ConnectionException('fixture-password token-secret previous-secret');
        });
        $response = $this->postJson(self::API . '/run', ['dry_run' => false])->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result', null);
        self::assertStringContainsString('NYA_TRANSPORT_ERROR', $response->json('data.error'));
        foreach (['fixture-password', 'token-secret', 'previous-secret', 'previous', 'exception'] as $secret) {
            self::assertStringNotContainsString($secret, $response->getContent());
        }
        self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        $lock = Cache::lock(NyaEntrySyncRunner::LOCK, 600);
        self::assertTrue($lock->get());
        $lock->release();
        self::assertSame('failed', app(NyaEntrySyncRunner::class)->status()['last_run']['status']);
    }

    public function test_schedule_reads_saved_enabled_and_disabled_schedule_keeps_last_run(): void
    {
        $node = $this->node();
        $this->admin();
        $form = $this->form(['server_ids' => [$node->id], 'enabled' => true]);
        $this->postJson(self::API . '/settings', $form)->assertOk();
        config()->set('nya_entry_sync.enabled', false);
        $this->fakePanel();
        $this->artisan('sync:nya-entry-ips', ['--scheduled' => true])->assertSuccessful();
        $last = app(NyaEntrySyncRunner::class)->status()['last_run'];
        self::assertSame('schedule', $last['source']);
        self::assertSame('succeeded', $last['status']);
        $this->postJson(self::API . '/settings', array_replace($form, ['enabled' => false]))->assertOk();
        config()->set('nya_entry_sync.enabled', true);
        $this->artisan('sync:nya-entry-ips', ['--scheduled' => true])->expectsOutputToContain('disabled')->assertSuccessful();
        self::assertSame($last, app(NyaEntrySyncRunner::class)->status()['last_run']);
        Http::assertSentCount(3);
    }

    public function test_invalid_urls_and_top_level_password_are_redacted_from_audit(): void
    {
        $this->admin();
        $this->postJson(self::API . '/settings', $this->form([
            'base_url' => 'https://user:url-secret@panel.example.test',
            'proxy' => 'http://user:proxy-secret@proxy.example.test:8080',
            'password' => 'password-secret',
        ]))->assertUnprocessable();
        $records = DB::table('v2_admin_audit_log')->pluck('request_data')->all();
        self::assertNotEmpty($records);
        foreach ($records as $record) {
            self::assertStringNotContainsString('url-secret', $record);
            self::assertStringNotContainsString('proxy-secret', $record);
            self::assertStringNotContainsString('password-secret', $record);
        }
    }

    public function test_corrupt_encrypted_settings_fail_closed_and_can_be_replaced(): void
    {
        $node = $this->node();
        $this->admin();
        DB::table('v2_settings')->insert(['name' => 'nya_entry_sync', 'value' => 'bad-secret-ciphertext']);
        $response = $this->postJson(self::API . '/run', ['dry_run' => false])->assertOk()
            ->assertJsonPath('data.status', 'failed');
        self::assertStringContainsString('NYA_SETTINGS_ERROR', $response->json('data.error'));
        self::assertStringNotContainsString('bad-secret-ciphertext', $response->getContent());
        $this->postJson(self::API . '/settings', $this->form(['server_ids' => [$node->id]]))->assertOk();
        self::assertSame('fixture-password', app(NyaEntrySyncSettings::class)->runtime()['password']);
        Http::assertNothingSent();
    }

    public function test_legacy_url_credentials_are_hidden_in_initial_settings_response(): void
    {
        $this->admin();
        config()->set('nya_entry_sync.base_url', 'https://user:base-secret@panel.example.test');
        config()->set('nya_entry_sync.proxy', 'http://user:proxy-secret@proxy.example.test:8080');
        $response = $this->getJson(self::API . '/settings')->assertOk()
            ->assertJsonPath('data.settings.base_url', '')->assertJsonPath('data.settings.proxy', '');
        self::assertStringNotContainsString('base-secret', $response->getContent());
        self::assertStringNotContainsString('proxy-secret', $response->getContent());
    }

    public function test_cli_overrides_saved_node_and_port_scope_and_reports_cli_source(): void
    {
        $node = $this->node();
        $other = $this->node();
        $this->admin();
        $this->postJson(self::API . '/settings', $this->form(['server_ids' => [$other->id]]))->assertOk();
        $this->fakePanel();
        $this->artisan('sync:nya-entry-ips', ['--server' => [$node->id], '--port' => [15767], '--dry-run' => true])
            ->expectsOutputToContain('would_update=1 entries_changed=1')->assertSuccessful();
        $last = app(NyaEntrySyncRunner::class)->status()['last_run'];
        self::assertSame('cli', $last['source']);
        self::assertSame('entry_points.0.host', $last['result']['changes'][0]['field']);
        self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        self::assertSame($other->getRawOriginal(), $other->fresh()->getRawOriginal());
    }

    private function account(string $id, array $overrides = []): array
    {
        return array_replace(['id' => $id, 'name' => 'Account ' . $id,
            'username' => 'user-' . $id, 'enabled' => true], $overrides);
    }

    private function accountForm(?array $accounts, array $overrides = []): array
    {
        $form = $this->form(['accounts' => $accounts]);
        unset($form['username'], $form['password']);
        return array_replace($form, $overrides);
    }

    private function storedSettings(): array
    {
        return json_decode(Crypt::decryptString(DB::table('v2_settings')
            ->where('name', NyaEntrySyncSettings::NAME)->value('value')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function admin(): void
    {
        Sanctum::actingAs((new User())->forceFill(['id' => 1, 'is_admin' => true]));
    }

    private function form(array $overrides = []): array
    {
        return array_replace(['enabled' => false, 'base_url' => 'https://saved.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'proxy' => '',
            'timeout' => 5, 'server_ids' => [], 'ports' => []], $overrides);
    }

    private function node(array $overrides = []): Server
    {
        return Server::withoutEvents(static fn (): Server => Server::create(array_replace([
            'host' => 'old.example.test', 'port' => '12192', 'entry_points_enabled' => true,
            'entry_points' => [['host' => 'old-extra.example.test', 'port' => 15767]],
            'protocol_settings' => ['password' => 'protocol-secret'],
        ], $overrides)))->fresh();
    }

    private function fakePanel(string $base = 'https://saved.example.test', string $username = 'fixture-user', string $password = 'fixture-password'): void
    {
        Http::fake(static function (Request $request) use ($base, $username, $password) {
            self::assertStringStartsWith($base . '/api/v1/', $request->url());
            self::assertSame('running', Cache::get(NyaEntrySyncRunner::LAST_RUN)['status']);
            $data = match (basename(parse_url($request->url(), PHP_URL_PATH))) {
                'login' => 'fixture-token',
                'devicegroup' => [['id' => 1, 'type' => 'DeviceGroupType_Inbound', 'connect_host' => '198.51.100.10']],
                'forward' => [['id' => 1, 'device_group_in' => 1, 'listen_port' => 12192],
                    ['id' => 2, 'device_group_in' => 1, 'listen_port' => 15767]],
            };
            if ($request->method() === 'POST') {
                self::assertSame(['username' => $username, 'password' => $password], $request->data());
            }
            return Http::response(['code' => 0, 'data' => $data]);
        });
    }
}
