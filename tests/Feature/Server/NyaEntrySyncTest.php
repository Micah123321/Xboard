<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Services\Nya\NyaClient;
use App\Services\Nya\NyaEntrySyncService;
use App\Services\ServerEntryPointService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NyaEntrySyncTest extends TestCase
{
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
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        config()->set('cache.default', 'array');
        config()->set('nya_entry_sync.server_ids', []);
        config()->set('nya_entry_sync.ports', []);
        Cache::flush();
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('NYA test');
            $table->string('type')->default('trojan');
            $table->string('host');
            $table->string('port');
            $table->integer('server_port')->default(30521);
            $table->integer('parent_id')->nullable();
            $table->boolean('entry_points_enabled')->default(false);
            $table->text('entry_points')->nullable();
            $table->text('protocol_settings')->nullable();
            $table->boolean('child_rate_enabled')->default(false);
            $table->boolean('tcp_check_enabled')->default(false);
            $table->boolean('show')->default(true);
            $table->decimal('rate', 8, 2)->default(12.5);
            $table->timestamps();
        });
    }

    public function test_multiple_accounts_match_primary_and_random_entry_independently_with_distinct_ips(): void
    {
        $node = $this->node(['host' => '198.51.100.10', 'entry_points_enabled' => true,
            'entry_points' => [['host' => '123.253.227.76', 'port' => 11823]]]);
        $settings = $this->multipleAccountSettings();
        $this->fakeMultipleAccounts();
        $service = app(NyaEntrySyncService::class);
        $preview = $service->sync(true, [$node->id], null, $settings);
        self::assertSame(2, $preview['accounts_checked']);
        self::assertSame([['id' => 'primary', 'name' => 'Main account', 'rules' => 1],
            ['id' => 'backup', 'name' => 'Backup account', 'rules' => 1]], $preview['account_summaries']);
        self::assertSame(2, $preview['matched']);
        self::assertSame(0, $preview['skipped']);
        self::assertCount(1, $preview['changes']);
        self::assertSame('Backup account', $preview['changes'][0]['account_name']);
        self::assertSame('203.0.113.20', $preview['changes'][0]['to']);
        self::assertSame('123.253.227.76', $node->fresh()->entry_points[0]['host']);
        $result = $service->sync(false, [$node->id], null, $settings);
        self::assertSame(1, $result['updated']);
        self::assertSame('198.51.100.10', $node->fresh()->host);
        self::assertSame([['host' => '203.0.113.20', 'port' => 11823]], $node->fresh()->entry_points);
        Http::assertSentCount(12);
    }

    public function test_failure_of_second_account_discards_first_account_rules_before_any_writes(): void
    {
        $node = $this->node(['entry_points' => [['host' => 'old.example', 'port' => 11823]]]);
        $this->fakeMultipleAccounts(true);
        try {
            app(NyaEntrySyncService::class)->sync(false, [$node->id], null, $this->multipleAccountSettings());
            self::fail('A failed account must not leave a partial sync.');
        } catch (\App\Services\Nya\NyaAccountSyncException $exception) {
            self::assertSame('Backup account', $exception->accountName);
            self::assertSame('NYA_API_ERROR', $exception->reasonCode);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('backup-password', $exception->getMessage());
        }
        self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        Http::assertSentCount(4);
    }

    public function test_same_port_in_different_accounts_is_a_conflict_and_never_uses_the_first_one(): void
    {
        $node = $this->node(['entry_points' => [['host' => 'old.example', 'port' => 11823]]]);
        $this->fakeMultipleAccounts(false, true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Multiple NYA rules use connection port 12192');
        try {
            app(NyaEntrySyncService::class)->sync(false, [$node->id], null, $this->multipleAccountSettings());
        } finally {
            self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        }
    }

    private function multipleAccountSettings(): array
    {
        return ['base_url' => 'https://nya.example.test', 'timeout' => 5, 'proxy' => '', 'accounts' => [
            ['id' => 'primary', 'name' => 'Main account', 'username' => 'main-user', 'password' => 'main-password', 'enabled' => true],
            ['id' => 'backup', 'name' => 'Backup account', 'username' => 'backup-user', 'password' => 'backup-password', 'enabled' => true],
        ]];
    }

    private function fakeMultipleAccounts(bool $failBackup = false, bool $portConflict = false): void
    {
        Http::preventStrayRequests();
        $expected = 0;
        Http::fake(function ($request) use ($failBackup, $portConflict, &$expected) {
            $step = $expected++ % 6;
            $backup = $step >= 3;
            $path = basename(parse_url($request->url(), PHP_URL_PATH));
            self::assertSame(['login', 'devicegroup', 'forward'][$step % 3], $path);
            if ($path === 'login') {
                self::assertFalse($request->hasHeader('Authorization'));
                self::assertSame($backup ? 'backup-user' : 'main-user', $request['username']);
                return Http::response(['code' => ($backup && $failBackup) ? 1 : 0,
                    'data' => ($backup && $failBackup) ? 'backup-password' : ($backup ? 'backup-token' : 'main-token')]);
            }
            self::assertTrue($request->hasHeader('Authorization', $backup ? 'backup-token' : 'main-token'));
            $data = $path === 'devicegroup'
                ? [['id' => 1, 'type' => 'DeviceGroupType_Inbound', 'connect_host' => $backup ? '203.0.113.20' : '198.51.100.10']]
                : [['id' => 1, 'device_group_in' => 1, 'listen_port' => $backup && !$portConflict ? 11823 : 12192]];
            return Http::response(['code' => 0, 'data' => $data]);
        });
    }

    public function test_sync_updates_primary_and_each_extra_by_its_own_port_with_scope_and_idempotency(): void
    {
        $parent = $this->node(['host' => 'parent.example', 'port' => '30521']);
        $node = $this->node(['parent_id' => $parent->id, 'entry_points_enabled' => true,
            'entry_points' => [['host' => '123.253.227.76', 'port' => 15767],
                ['host' => 'other-provider.example', 'port' => 443]]]);
        $untouched = $this->node();
        $service = $this->service([$this->rule(12192, '198.51.100.11'), $this->rule(15767, '198.51.100.12')], 2);
        $result = $service->sync(false, [$node->id]);
        $fresh = $node->fresh();
        self::assertSame(1, $result['updated']);
        self::assertCount(2, $result['changes']);
        self::assertSame(1, $result['skipped']);
        self::assertSame('198.51.100.11', $fresh->host);
        self::assertSame([['host' => '198.51.100.12', 'port' => 15767],
            ['host' => 'other-provider.example', 'port' => 443]], $fresh->entry_points);
        foreach (['port', 'server_port', 'parent_id', 'rate', 'show', 'entry_points_enabled'] as $field) {
            self::assertSame($node->{$field}, $fresh->{$field});
        }
        self::assertSame('parent.example', $parent->fresh()->host);
        self::assertSame('123.253.227.76', $untouched->fresh()->host);
        DB::enableQueryLog();
        $again = $service->sync(false, [$node->id]);
        self::assertSame(0, $again['updated']);
        self::assertSame(2, $again['unchanged']);
        self::assertSame([], array_filter(DB::getQueryLog(), fn (array $query): bool =>
            preg_match('/^(update|insert|delete)\b/i', $query['query']) === 1));
    }

    public function test_dry_run_command_reports_changes_and_performs_no_writes(): void
    {
        $node = $this->node(['entry_points' => [['host' => '123.253.227.76', 'port' => 15767]]]);
        $this->bindClient([$this->rule(12192), $this->rule(15767)]);
        $this->artisan('sync:nya-entry-ips', ['--dry-run' => true, '--server' => [$node->id]])
            ->expectsOutputToContain('would_update=1 entries_changed=2')->assertSuccessful();
        self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
    }

    public function test_http_catalog_flows_through_command_into_both_subscription_candidates(): void
    {
        $node = $this->node(['entry_points_enabled' => true,
            'entry_points' => [['host' => '123.253.227.76', 'port' => 15767]]]);
        config()->set('nya_entry_sync', ['base_url' => 'https://nya.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'proxy' => '',
            'server_ids' => [$node->id], 'ports' => [], 'timeout' => 5]);
        Http::preventStrayRequests();
        Http::fake([
            'https://nya.example.test/api/v1/auth/login' => Http::response(['code' => 0, 'data' => 'fixture-token']),
            'https://nya.example.test/api/v1/user/devicegroup' => Http::response(['code' => 0, 'data' => [
                ['id' => 'inbound', 'type' => 'DeviceGroupType_Inbound', 'connect_host' => '198.51.100.10'],
            ]]),
            'https://nya.example.test/api/v1/user/forward' => Http::response(['code' => 0, 'data' => [
                ['id' => 'sg', 'device_group_in' => 'inbound', 'listen_port' => 12192],
                ['id' => 'jp', 'device_group_in' => 'inbound', 'listen_port' => 15767],
            ]]),
        ]);
        $this->artisan('sync:nya-entry-ips')->expectsOutputToContain('updated=1 entries_changed=2')->assertSuccessful();
        $fresh = $node->fresh();
        self::assertSame([['host' => '198.51.100.10', 'port' => 12192],
            ['host' => '198.51.100.10', 'port' => 15767]], (new ServerEntryPointService())->candidates($fresh));
        self::assertSame('198.51.100.10', (new ServerEntryPointService())->selectForSubscription($fresh)['host']);
        Http::assertSentCount(3);
    }

    public function test_api_failure_keeps_both_addresses_and_command_does_not_echo_server_message(): void
    {
        $node = $this->node(['entry_points' => [['host' => '123.253.227.76', 'port' => 15767]]]);
        config()->set('nya_entry_sync', ['base_url' => 'https://nya.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'proxy' => '',
            'server_ids' => [$node->id], 'ports' => [], 'timeout' => 5]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 1, 'data' => 'fixture-password'])]);
        $this->artisan('sync:nya-entry-ips')->expectsOutputToContain('NYA_API_ERROR')
            ->doesntExpectOutputToContain('fixture-password')->assertFailed();
        self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        self::assertTrue(Cache::lock('nya-entry-ip-sync', 600)->get());
        Http::assertSentCount(1);
    }

    public function test_disabled_random_pool_is_still_updated_but_keeps_its_switch_off(): void
    {
        $node = $this->node(['entry_points_enabled' => false,
            'entry_points' => [['host' => '123.253.227.76', 'port' => 15767]]]);
        $this->service([$this->rule(12192), $this->rule(15767)])->sync(false, [$node->id]);
        self::assertFalse($node->fresh()->entry_points_enabled);
        self::assertSame('198.51.100.10', $node->fresh()->entry_points[0]['host']);
    }

    public function test_current_advertised_ip_is_kept_and_optional_port_filter_excludes_other_entries(): void
    {
        $node = $this->node(['host' => '198.51.100.12',
            'entry_points' => [['host' => '123.253.227.76', 'port' => 15767]]]);
        $rule = $this->rule(12192);
        $rule['ips'][] = '198.51.100.12';
        $result = $this->service([$rule, $this->rule(15767)])->sync(false, [$node->id], [12192]);
        self::assertSame(0, $result['updated']);
        self::assertSame(1, $result['unchanged']);
        self::assertSame('123.253.227.76', $node->fresh()->entry_points[0]['host']);
    }

    public function test_duplicate_rule_port_aborts_every_node_before_saving(): void
    {
        $first = $this->node();
        $second = $this->node(['port' => '15767']);
        $duplicate = $this->rule(15767, '198.51.100.12');
        $duplicate['rule_id'] = 'different-rule';
        try {
            $this->service([$this->rule(12192), $this->rule(15767), $duplicate])
                ->sync(false, [$first->id, $second->id]);
            self::fail('Ambiguous rules must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Multiple NYA rules', $exception->getMessage());
        }
        self::assertSame('123.253.227.76', $first->fresh()->host);
        self::assertSame('123.253.227.76', $second->fresh()->host);
    }

    public function test_save_failure_rolls_back_already_saved_nodes(): void
    {
        $first = $this->node();
        $second = $this->node(['port' => '15767']);
        DB::unprepared("CREATE TRIGGER fail_second BEFORE UPDATE ON v2_server WHEN NEW.id = {$second->id} BEGIN SELECT RAISE(ABORT, 'fixture'); END");
        try {
            $this->service([$this->rule(12192), $this->rule(15767)])->sync(false, [$first->id, $second->id]);
            self::fail('The fixture update must fail.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('fixture', $exception->getMessage());
        }
        self::assertSame('123.253.227.76', $first->fresh()->host);
        self::assertSame('123.253.227.76', $second->fresh()->host);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('samePortPools')]
    public function test_same_port_pool_preserves_valid_ips_before_allocating_unused_candidates(array $current, array $ips, array $expected): void
    {
        $attributes = ['host' => $current[0], 'entry_points_enabled' => true,
            'entry_points' => [['host' => $current[1], 'port' => 12192],
                ['host' => 'old-backup.example', 'port' => 15767]]];
        $first = $this->node($attributes);
        $second = $this->node($attributes);
        $main = array_replace($this->rule(12192), ['ips' => $ips,
            'account_id' => 'main', 'account_name' => 'Main account']);
        $backup = array_replace($this->rule(15767, $ips[0]), [
            'account_id' => 'backup', 'account_name' => 'Backup account']);
        $service = $this->service([$main, $backup], 3);
        $preview = $service->sync(true, [$first->id, $second->id]);
        self::assertSame(2, $preview['updated']);
        self::assertSame(2, $preview['accounts_checked']);
        foreach ([$first, $second] as $node) {
            self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        }
        $result = $service->sync(false, [$first->id, $second->id]);
        self::assertSame($preview, $result);
        foreach ([$first, $second] as $node) {
            $fresh = $node->fresh();
            self::assertSame($expected[0], $fresh->host);
            self::assertSame([['host' => $expected[1], 'port' => 12192],
                ['host' => $ips[0], 'port' => 15767]], $fresh->entry_points);
            self::assertTrue($fresh->entry_points_enabled);
        }
        DB::enableQueryLog();
        $again = $service->sync(false, [$first->id, $second->id]);
        self::assertSame(0, $again['updated']);
        self::assertSame(6, $again['unchanged']);
        self::assertSame([], $again['changes']);
        self::assertSame([], array_filter(DB::getQueryLog(), static fn (array $query): bool =>
            preg_match('/^(update|insert|delete)\b/i', $query['query']) === 1));
    }

    public static function samePortPools(): array
    {
        return [
            'retained primary' => [['198.51.100.10', '198.51.100.11'],
                ['198.51.100.10', '198.51.100.12'], ['198.51.100.10', '198.51.100.12']],
            'retained extra must be reserved first' => [['198.51.100.11', '198.51.100.10'],
                ['198.51.100.10', '198.51.100.12'], ['198.51.100.12', '198.51.100.10']],
            'all candidates rotated' => [['198.51.100.10', '198.51.100.11'],
                ['198.51.100.12', '198.51.100.13'], ['198.51.100.12', '198.51.100.13']],
            'normalized IPv6 extra is retained' => [['198.51.100.11', '[2001:0db8:0:0:0:0:0:1]'],
                ['2001:db8::1', '198.51.100.12'], ['198.51.100.12', '[2001:0db8:0:0:0:0:0:1]']],
        ];
    }

    public function test_insufficient_same_port_candidates_abort_the_entire_batch_before_writes(): void
    {
        $first = $this->node(['port' => '15767']);
        $second = $this->node(['host' => '198.51.100.10', 'entry_points' => [
            ['host' => '198.51.100.11', 'port' => 12192],
            ['host' => '198.51.100.12', 'port' => 12192],
        ]]);
        $rule = array_replace($this->rule(12192), ['ips' => ['198.51.100.13', '198.51.100.14']]);
        DB::enableQueryLog();
        try {
            $this->service([$this->rule(15767), $rule])->sync(false, [$first->id, $second->id]);
            self::fail('Insufficient candidates must abort every node.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('no changes saved', $exception->getMessage());
        }
        foreach ([$first, $second] as $node) {
            self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        }
        self::assertSame([], array_filter(DB::getQueryLog(), static fn (array $query): bool =>
            preg_match('/^(update|insert|delete)\b/i', $query['query']) === 1));
    }

    public function test_collapsing_distinct_entries_is_rejected(): void
    {
        $node = $this->node(['entry_points' => [['host' => 'old-other.example', 'port' => 12192]]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate entries');
        try {
            $this->service([$this->rule(12192)])->sync(false, [$node->id]);
        } finally {
            self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        }
    }

    public function test_incomplete_extra_entry_aborts_the_primary_update(): void
    {
        $node = $this->node(['entry_points' => [['host' => 'old.example']]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid extra entry');
        try {
            $this->service([$this->rule(12192)])->sync(false, [$node->id]);
        } finally {
            self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        }
    }

    public function test_empty_scope_never_fetches_or_changes_any_node(): void
    {
        $node = $this->node();
        $client = Mockery::mock(NyaClient::class);
        $client->shouldNotReceive('fetchEntries');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NYA_ENTRY_SYNC_SERVER_IDS');
        try {
            (new NyaEntrySyncService($client))->sync();
        } finally {
            self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
        }
    }

    public function test_missing_server_or_unmatched_ports_fail_without_writes(): void
    {
        $node = $this->node(['port' => '20000-20010']);
        $service = $this->service([$this->rule(12192)], 2);
        foreach ([[$node->id, 99999], [$node->id]] as $ids) {
            try {
                $service->sync(false, $ids);
                self::fail('Invalid scope or no matching port must fail.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('no changes saved', strtolower($exception->getMessage()));
            }
        }
        self::assertSame($node->getRawOriginal(), $node->fresh()->getRawOriginal());
    }

    public function test_ip_change_invalidates_old_health_until_new_candidates_are_checked(): void
    {
        $node = $this->node(['parent_id' => 100, 'tcp_check_enabled' => true, 'entry_points_enabled' => true,
            'entry_points' => [['host' => '123.253.227.76', 'port' => 15767]]]);
        $pool = new ServerEntryPointService();
        $pool->recordHealth($node, [['host' => $node->host, 'port' => 12192, 'online' => true]]);
        self::assertNotNull($pool->selectForSubscription($node));
        $this->service([$this->rule(12192), $this->rule(15767)])->sync(false, [$node->id]);
        $fresh = $node->fresh();
        self::assertNull($pool->selectForSubscription($fresh));
        $pool->recordHealth($fresh, [['host' => $fresh->host, 'port' => 12192, 'online' => true]]);
        self::assertSame(['host' => '198.51.100.10', 'port' => 12192], $pool->selectForSubscription($fresh));
    }

    public function test_overlapping_command_does_not_fetch_or_write(): void
    {
        $client = Mockery::mock(NyaClient::class);
        $client->shouldNotReceive('fetchEntries');
        $this->app->instance(NyaClient::class, $client);
        $lock = Cache::lock('nya-entry-ip-sync', 600);
        self::assertTrue($lock->get());
        try {
            $this->artisan('sync:nya-entry-ips')->expectsOutputToContain('already running')->assertSuccessful();
        } finally {
            $lock->release();
        }
    }

    public function test_console_routes_always_register_one_five_minute_scheduled_task(): void
    {
        Schema::create('v2_plugins', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_enabled')->default(false);
        });
        foreach ([false, true] as $enabled) {
            config()->set('nya_entry_sync.enabled', $enabled);
            $this->app->forgetInstance(Schedule::class);
            $events = array_values(array_filter($this->app->make(Schedule::class)->events(), fn ($event): bool =>
                str_contains($event->command ?? '', 'sync:nya-entry-ips')));
            self::assertCount(1, $events);
            self::assertSame('*/5 * * * *', $events[0]->expression);
            self::assertStringContainsString('--scheduled', $events[0]->command);
            self::assertTrue($events[0]->onOneServer);
            self::assertTrue($events[0]->withoutOverlapping);
        }
    }

    private function node(array $attributes = []): Server
    {
        return Server::withoutEvents(fn (): Server => Server::create($attributes + [
            'host' => '123.253.227.76', 'port' => '12192', 'type' => 'trojan', 'server_port' => 30521,
            'entry_points_enabled' => false, 'entry_points' => null, 'rate' => 12.5,
        ]))->fresh();
    }

    private function rule(int $port, string $ip = '198.51.100.10'): array
    {
        return ['rule_id' => (string) $port, 'port' => $port, 'ips' => [$ip]];
    }

    private function service(array $rules, int $calls = 1): NyaEntrySyncService
    {
        return new NyaEntrySyncService($this->bindClient($rules, $calls));
    }

    private function bindClient(array $rules, int $calls = 1): NyaClient
    {
        $client = Mockery::mock(NyaClient::class);
        $client->shouldReceive('fetchEntries')->times($calls)->andReturn($rules);
        $this->app->instance(NyaClient::class, $client);
        return $client;
    }
}
