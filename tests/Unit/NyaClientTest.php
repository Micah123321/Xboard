<?php

namespace Tests\Unit;

use App\Services\Nya\NyaClient;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class NyaClientTest extends TestCase
{
    private Container $previousContainer;
    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('config', new Repository(['nya_entry_sync' => [
            'base_url' => 'https://panel.example.test/root/',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
        ]]));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Http::swap(new Factory());
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_fetches_entries_with_exact_authentication_and_http_options(): void
    {
        config(['nya_entry_sync.proxy' => 'http://127.0.0.1:8080', 'nya_entry_sync.timeout' => '7']);
        $optionsSeen = [];
        $groups = $this->fixtureGroups(" Entry.Example.test\r\n198.51.100.10\nentry.example.test\r[2001:db8::1]\n");
        $rules = [$this->rule(), $this->rule('12', '7', 65535)];
        $rules[0]['status'] = 'unknown-future-status';
        $rules[1]['status'] = false;
        $this->fakePanel($groups, $rules, static function (Request $request, array $options) use (&$optionsSeen): void {
            $optionsSeen[] = $options;
            self::assertTrue($request->hasHeader('Accept', 'application/json'));
            self::assertTrue($request->hasHeader('Content-Type', 'application/json'));
            self::assertTrue($request->hasHeader('User-Agent', 'nya-entry-sync/1.0'));
            if ($request->method() === 'POST') {
                self::assertSame('/root/api/v1/auth/login', parse_url($request->url(), PHP_URL_PATH));
                self::assertSame(['username' => 'fixture-user', 'password' => 'fixture-password'], $request->data());
                self::assertFalse($request->hasHeader('Authorization'));
            } else {
                self::assertSame('opaque.fixture-token', $request->header('Authorization')[0]);
            }
        });
        $client = $this->client([
            'entry.example.test' => ['2001:0db8:0:0:0:0:0:1', '198.51.100.10', '198.51.100.2', '198.51.100.10'],
            '198.51.100.10' => ['198.51.100.10'],
            '2001:db8::1' => ['2001:db8::1'],
        ]);
        $ips = ['198.51.100.2', '198.51.100.10', '2001:db8::1'];
        self::assertSame([
            ['rule_id' => '11', 'port' => 12192, 'ips' => $ips],
            ['rule_id' => '12', 'port' => 65535, 'ips' => $ips],
        ], $client->fetchEntries());
        self::assertSame(['entry.example.test', '198.51.100.10', '2001:db8::1'], $client->lookups);
        self::assertCount(3, $optionsSeen);
        foreach ($optionsSeen as $options) {
            self::assertFalse($options['allow_redirects']);
            self::assertSame(5, $options['connect_timeout']);
            self::assertSame(7.0, $options['timeout']);
            self::assertSame('http://127.0.0.1:8080', $options['proxy']);
        }
        Http::assertSentCount(3);
    }

    public function test_literal_ips_work_without_dns_and_timeout_defaults_to_twenty_seconds(): void
    {
        $optionsSeen = [];
        $this->fakePanel($this->fixtureGroups("[2001:db8::1]\n198.51.100.10"), [$this->rule('11', 7, 1)],
            static function (Request $request, array $options) use (&$optionsSeen): void {
                $optionsSeen[] = $options;
            });
        self::assertSame([
            ['rule_id' => '11', 'port' => 1, 'ips' => ['198.51.100.10', '2001:db8::1']],
        ], (new NyaClient())->fetchEntries());
        foreach ($optionsSeen as $options) {
            self::assertSame(20.0, $options['timeout']);
            self::assertSame('', $options['proxy']);
        }
    }

    public function test_explicit_settings_are_used_without_mutating_global_configuration(): void
    {
        $this->fakePanel($this->fixtureGroups(), [$this->rule()], static function (Request $request): void {
            self::assertStringStartsWith('https://saved.example.test/', $request->url());
            if ($request->method() === 'POST') {
                self::assertSame(['username' => 'saved-user', 'password' => 'saved-password'], $request->data());
            }
        });
        (new NyaClient())->fetchEntries(['base_url' => 'https://saved.example.test', 'username' => 'saved-user',
            'password' => 'saved-password', 'proxy' => '', 'timeout' => 3]);
        self::assertSame('fixture-user', config('nya_entry_sync.username'));
        Http::assertSentCount(3);
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_fails_before_any_http_request(string $key, mixed $value): void
    {
        config(['nya_entry_sync.' . $key => $value]);
        Http::fake();
        $this->assertFailure(new NyaClient(), 'NYA_CONFIG_ERROR');
        Http::assertNothingSent();
    }

    public static function invalidConfigurations(): array
    {
        return [
            'missing URL' => ['base_url', null],
            'relative URL' => ['base_url', '/api'],
            'FTP URL' => ['base_url', 'ftp://panel.example.test'],
            'userinfo' => ['base_url', 'https://fixture-user:fixture-password@panel.example.test'],
            'empty userinfo' => ['base_url', 'https://@panel.example.test'],
            'query' => ['base_url', 'https://panel.example.test?token=fixture-token'],
            'empty query' => ['base_url', 'https://panel.example.test?'],
            'fragment' => ['base_url', 'https://panel.example.test#fixture-token'],
            'empty fragment' => ['base_url', 'https://panel.example.test#'],
            'URL backslash' => ['base_url', 'https://panel.example.test\\other'],
            'URL control' => ['base_url', "https://panel.example.test\n"],
            'empty username' => ['username', ' '],
            'password type' => ['password', []],
            'empty password' => ['password', ''],
            'proxy type' => ['proxy', []],
            'proxy credentials' => ['proxy', 'http://user:secret@127.0.0.1:8080'],
            'proxy FTP' => ['proxy', 'ftp://127.0.0.1:8080'],
            'proxy query' => ['proxy', 'http://127.0.0.1:8080?password=secret'],
            'timeout too short' => ['timeout', 0],
            'timeout too long' => ['timeout', 61],
            'timeout boolean' => ['timeout', true],
            'timeout malformed' => ['timeout', 'secret'],
            'timeout infinite' => ['timeout', INF],
        ];
    }

    #[DataProvider('badLoginResponses')]
    public function test_rejects_bad_login_and_never_sends_authenticated_requests(mixed $body, string $category): void
    {
        Http::fake(['*' => Http::response($body)]);
        $this->assertFailure(new NyaClient(), $category);
        Http::assertSentCount(1);
    }

    public static function badLoginResponses(): array
    {
        return [
            'string zero' => [['code' => '0', 'data' => 'fixture-token'], 'NYA_API_ERROR'],
            'float zero' => ['{"code":0.0,"data":"fixture-token"}', 'NYA_API_ERROR'],
            'false code' => [['code' => false, 'data' => 'fixture-token'], 'NYA_API_ERROR'],
            'nonzero code' => [['code' => 4, 'data' => 'fixture-password'], 'NYA_API_ERROR'],
            'empty token' => [['code' => 0, 'data' => ''], 'NYA_AUTH_ERROR'],
            'blank token' => [['code' => 0, 'data' => ' '], 'NYA_AUTH_ERROR'],
            'null token' => [['code' => 0, 'data' => null], 'NYA_AUTH_ERROR'],
            'array token' => [['code' => 0, 'data' => ['token' => 'fixture-token']], 'NYA_AUTH_ERROR'],
            'header injection' => [['code' => 0, 'data' => "fixture-token\r\nInjected: yes"], 'NYA_AUTH_ERROR'],
            'missing data' => [['code' => 0], 'NYA_RESPONSE_ERROR'],
            'missing code' => [['data' => 'fixture-token'], 'NYA_RESPONSE_ERROR'],
            'JSON list' => ['[0,"fixture-token"]', 'NYA_RESPONSE_ERROR'],
            'invalid JSON' => ['fixture-password <html>', 'NYA_RESPONSE_ERROR'],
        ];
    }

    #[DataProvider('malformedLists')]
    public function test_list_endpoints_reject_wrappers_and_non_objects(string $endpoint, mixed $data): void
    {
        $this->fakePanel($this->fixtureGroups(), [$this->rule()], null, [$endpoint => ['code' => 0, 'data' => $data]]);
        $this->assertFailure(new NyaClient(), 'NYA_RESPONSE_ERROR');
    }

    public static function malformedLists(): iterable
    {
        foreach (['devicegroup', 'forward'] as $endpoint) {
            foreach ([null, (object) [], ['data' => [], 'total' => 0], ['items' => []], [1], [[]], ['broken']] as $index => $data) {
                yield $endpoint . '-' . $index => [$endpoint, $data];
            }
        }
    }

    #[DataProvider('invalidEntries')]
    public function test_bad_entries_abort_the_entire_fetch(array $groups, array $rules, string $category): void
    {
        $this->fakePanel($groups, $rules);
        $this->assertFailure(new NyaClient(), $category);
    }

    public static function invalidEntries(): iterable
    {
        $group = ['id' => 7, 'type' => 'DeviceGroupType_Inbound', 'connect_host' => '198.51.100.10'];
        $rule = ['id' => 11, 'device_group_in' => '7', 'listen_port' => 12192];
        yield 'empty rules' => [[$group], [], 'NYA_RULE_ERROR'];
        yield 'missing group' => [[], [$rule], 'NYA_GROUP_ERROR'];
        yield 'unknown group' => [[$group], [array_replace($rule, ['device_group_in' => '8'])], 'NYA_GROUP_ERROR'];
        yield 'wrong group type' => [[array_replace($group, ['type' => 'DeviceGroupType_Outbound'])], [$rule], 'NYA_GROUP_ERROR'];
        yield 'empty addresses' => [[array_replace($group, ['connect_host' => " \r\n "])], [$rule], 'NYA_ADDRESS_ERROR'];
        yield 'address object' => [[array_replace($group, ['connect_host' => ['198.51.100.10']])], [$rule], 'NYA_ADDRESS_ERROR'];
        yield 'missing addresses' => [[['id' => 7, 'type' => 'DeviceGroupType_Inbound']], [$rule], 'NYA_ADDRESS_ERROR'];
        yield 'duplicate numeric-string rule IDs' => [[$group], [$rule, array_replace($rule, ['id' => '11'])], 'NYA_RULE_ERROR'];
        yield 'duplicate group IDs' => [[$group, array_replace($group, ['id' => '7'])], [$rule], 'NYA_GROUP_ERROR'];
        foreach ([0, 65536, '12192', 1.5, true, null] as $index => $port) {
            yield 'bad port-' . $index => [[$group], [array_replace($rule, ['listen_port' => $port])], 'NYA_RULE_ERROR'];
        }
        foreach ([null, false, 1.5, '', []] as $index => $id) {
            yield 'bad rule ID-' . $index => [[$group], [array_replace($rule, ['id' => $id])], 'NYA_RULE_ERROR'];
            yield 'bad group reference-' . $index => [[$group], [array_replace($rule, ['device_group_in' => $id])], 'NYA_GROUP_ERROR'];
        }
        yield 'later invalid rule discards earlier result' => [[$group], [$rule, array_replace($rule, ['id' => 12, 'listen_port' => 0])], 'NYA_RULE_ERROR'];
    }

    #[DataProvider('dnsFailures')]
    public function test_any_dns_failure_aborts_even_after_a_valid_host(mixed $resolution): void
    {
        $this->fakePanel($this->fixtureGroups("198.51.100.10\nunresolved.example.test"), [$this->rule()]);
        $client = $this->client([
            '198.51.100.10' => ['198.51.100.10'],
            'unresolved.example.test' => $resolution,
        ]);
        $this->assertFailure($client, 'NYA_DNS_ERROR');
        self::assertSame(['198.51.100.10', 'unresolved.example.test'], $client->lookups);
    }

    public static function dnsFailures(): array
    {
        return [
            'empty DNS answer' => [[]],
            'non-IP DNS answer' => [['fixture-password']],
            'mixed bad DNS answer' => [['2001:db8::1', false]],
            'resolver exception' => [new RuntimeException('fixture-user fixture-password fixture-token')],
        ];
    }

    #[DataProvider('httpFailures')]
    public function test_http_errors_and_redirects_do_not_expose_response_bodies(string $endpoint, int $status): void
    {
        $this->fakePanel($this->fixtureGroups(), [$this->rule()], null, [
            $endpoint => Http::response('fixture-user fixture-password fixture-token', $status, [
                'Location' => 'https://other.example.test/fixture-token',
            ]),
        ]);
        $this->assertFailure(new NyaClient(), 'NYA_HTTP_ERROR');
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'other.example.test'));
    }

    public static function httpFailures(): array
    {
        return [['login', 401], ['devicegroup', 302], ['forward', 403], ['forward', 500]];
    }

    public function test_connection_exception_is_sanitized_without_a_previous_exception(): void
    {
        Http::fake(static function (): void {
            throw new ConnectionException('fixture-user fixture-password fixture-token');
        });
        $this->assertFailure(new NyaClient(), 'NYA_TRANSPORT_ERROR');
    }

    private function fixtureGroups(string $hosts = '198.51.100.10'): array
    {
        return [['id' => 7, 'type' => 'DeviceGroupType_Inbound', 'connect_host' => $hosts]];
    }

    private function rule(mixed $id = 11, mixed $group = '7', mixed $port = 12192): array
    {
        return ['id' => $id, 'device_group_in' => $group, 'listen_port' => $port];
    }

    private function fakePanel(array $groups, array $rules, ?callable $inspect = null, array $overrides = []): void
    {
        Http::fake(function (Request $request, array $options) use ($groups, $rules, $inspect, $overrides) {
            if ($inspect !== null) {
                $inspect($request, $options);
            }
            $endpoint = basename(parse_url($request->url(), PHP_URL_PATH));
            $responses = [
                'login' => ['code' => 0, 'data' => 'opaque.fixture-token'],
                'devicegroup' => ['code' => 0, 'data' => $groups],
                'forward' => ['code' => 0, 'data' => $rules],
            ];
            if (!array_key_exists($endpoint, $responses)) {
                throw new RuntimeException('Unexpected fixture endpoint.');
            }
            $body = $overrides[$endpoint] ?? $responses[$endpoint];

            return is_array($body) ? Http::response($body) : $body;
        });
    }

    private function client(array $records): NyaClient
    {
        return new class($records) extends NyaClient {
            public array $lookups = [];

            public function __construct(private array $records)
            {
            }

            protected function resolveHost(string $host): array
            {
                $this->lookups[] = $host;
                $result = $this->records[$host] ?? [];
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    private function assertFailure(NyaClient $client, string $category): void
    {
        try {
            $client->fetchEntries();
            self::fail('Expected the entire fetch to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($category, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}
