<?php

namespace App\Services\Nya;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

class NyaClient
{
    public function fetchEntries(?array $settings = null): array
    {
        $settings ??= config('nya_entry_sync', []);
        if (array_key_exists('accounts', $settings)) {
            return $this->fetchAccounts($settings);
        }
        $baseUrl = $settings['base_url'] ?? null;
        $username = $settings['username'] ?? null;
        $password = $settings['password'] ?? null;
        $proxy = $settings['proxy'] ?? '';
        $timeout = $settings['timeout'] ?? 20;

        if (!is_string($baseUrl) || preg_match('/[\x00-\x20\x7f]/', $baseUrl) || str_contains($baseUrl, '\\')) {
            throw new RuntimeException('NYA_CONFIG_ERROR');
        }
        $url = parse_url($baseUrl);
        if ($url === false || !filter_var($baseUrl, FILTER_VALIDATE_URL)
            || !in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || empty($url['host']) || array_key_exists('user', $url) || array_key_exists('pass', $url)
            || array_key_exists('query', $url) || array_key_exists('fragment', $url)
            || !is_string($username) || trim($username) === ''
            || !is_string($password) || $password === ''
            || ($proxy !== null && !is_string($proxy))
            || (!is_int($timeout) && !is_float($timeout) && !is_string($timeout))
            || !is_numeric($timeout) || !is_finite((float) $timeout) || $timeout < 1 || $timeout > 60) {
            throw new RuntimeException('NYA_CONFIG_ERROR');
        }

        if ($proxy !== null && $proxy !== '' && !NyaEntrySyncSettings::validUrl($proxy)) {
            throw new RuntimeException('NYA_CONFIG_ERROR');
        }

        $request = Http::acceptJson()->asJson()->withUserAgent('nya-entry-sync/1.0')
            ->withoutRedirecting()->connectTimeout(5)->timeout((float) $timeout);
        $request->withOptions(['proxy' => $proxy ?? '']);
        $baseUrl = rtrim($baseUrl, '/');
        $token = $this->request($request, 'POST', $baseUrl . '/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        if (!is_string($token) || trim($token) === '' || preg_match('/[\r\n]/', $token)) {
            throw new RuntimeException('NYA_AUTH_ERROR');
        }

        $request->withHeaders(['Authorization' => $token]);
        $groups = $this->objectList($this->request($request, 'GET', $baseUrl . '/api/v1/user/devicegroup'));
        $rules = $this->objectList($this->request($request, 'GET', $baseUrl . '/api/v1/user/forward'));
        if ($rules === []) {
            throw new RuntimeException('NYA_RULE_ERROR');
        }

        $groupMap = [];
        foreach ($groups as $group) {
            $id = $this->identifier($group->id ?? null, 'NYA_GROUP_ERROR');
            if (isset($groupMap['id:' . $id])) {
                throw new RuntimeException('NYA_GROUP_ERROR');
            }
            $groupMap['id:' . $id] = $group;
        }

        $entries = [];
        $seenRules = [];
        $cache = [];
        foreach ($rules as $rule) {
            $id = $this->identifier($rule->id ?? null, 'NYA_RULE_ERROR');
            if (isset($seenRules['id:' . $id])) {
                throw new RuntimeException('NYA_RULE_ERROR');
            }
            $seenRules['id:' . $id] = true;
            $groupId = $this->identifier($rule->device_group_in ?? null, 'NYA_GROUP_ERROR');
            $group = $groupMap['id:' . $groupId] ?? null;
            if ($group === null || ($group->type ?? null) !== 'DeviceGroupType_Inbound') {
                throw new RuntimeException('NYA_GROUP_ERROR');
            }
            $port = $rule->listen_port ?? null;
            if (!is_int($port) || $port < 1 || $port > 65535) {
                throw new RuntimeException('NYA_RULE_ERROR');
            }
            if (!isset($group->connect_host) || !is_string($group->connect_host)) {
                throw new RuntimeException('NYA_ADDRESS_ERROR');
            }

            $hosts = [];
            foreach (preg_split('/\r\n|\r|\n/', $group->connect_host) as $host) {
                $host = trim($host);
                if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
                    $host = substr($host, 1, -1);
                }
                if ($host !== '') {
                    $hosts[strtolower($host)] = true;
                }
            }
            if ($hosts === []) {
                throw new RuntimeException('NYA_ADDRESS_ERROR');
            }

            $ips = [];
            foreach (array_keys($hosts) as $host) {
                if (!isset($cache[$host])) {
                    try {
                        $addresses = $this->resolveHost((string) $host);
                    } catch (Throwable) {
                        throw new RuntimeException('NYA_DNS_ERROR');
                    }
                    if ($addresses === []) {
                        throw new RuntimeException('NYA_DNS_ERROR');
                    }
                    $cache[$host] = [];
                    foreach ($addresses as $address) {
                        if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_IP)) {
                            throw new RuntimeException('NYA_DNS_ERROR');
                        }
                        $cache[$host][] = inet_ntop(inet_pton($address));
                    }
                }
                foreach ($cache[$host] as $address) {
                    $ips[$address] = true;
                }
            }
            $ips = array_keys($ips);
            usort($ips, static function (string $left, string $right): int {
                $left = inet_pton($left);
                $right = inet_pton($right);

                return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
            });
            $entries[] = ['rule_id' => $id, 'port' => $port, 'ips' => $ips];
        }

        return $entries;
    }

    private function fetchAccounts(array $settings): array
    {
        $accounts = $settings['accounts'];
        if (!is_array($accounts) || !array_is_list($accounts) || count($accounts) > 10) {
            throw new RuntimeException('NYA_CONFIG_ERROR');
        }
        $entries = [];
        $active = 0;
        $seen = [];
        $deadline = microtime(true) + 180;
        foreach ($accounts as $index => $account) {
            if (!is_array($account)) {
                throw new RuntimeException('NYA_CONFIG_ERROR');
            }
            if (($account['enabled'] ?? true) !== true) {
                continue;
            }
            $id = $account['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) || isset($seen[$id])) {
                throw new RuntimeException('NYA_CONFIG_ERROR');
            }
            $seen[$id] = true;
            $name = is_string($account['name'] ?? null) ? $account['name'] : 'Account ' . ($index + 1);
            $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $name), 0, 100);
            $remaining = $deadline - microtime(true);
            if ($remaining < 3) {
                throw new NyaAccountSyncException($name, 'NYA_TIMEOUT_ERROR');
            }
            $single = $settings;
            unset($single['accounts']);
            $single['username'] = $account['username'] ?? '';
            $single['password'] = $account['password'] ?? '';
            // Three requests per account share a bounded total budget across sequential logins.
            $single['timeout'] = min((float) ($settings['timeout'] ?? 20), floor($remaining / 3));
            try {
                $rules = $this->fetchEntries($single);
            } catch (Throwable $exception) {
                $code = get_class($exception) === RuntimeException::class
                    && preg_match('/^NYA_[A-Z_]+$/D', $exception->getMessage())
                    ? $exception->getMessage() : 'NYA_TRANSPORT_ERROR';
                throw new NyaAccountSyncException($name, $code);
            }
            foreach ($rules as $rule) {
                $entries[] = $rule + ['account_id' => $id, 'account_name' => $name];
            }
            $active++;
        }
        if ($active === 0) {
            throw new RuntimeException('NYA_CONFIG_ERROR');
        }
        return $entries;
    }

    protected function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new RuntimeException('NYA_DNS_ERROR');
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            throw new RuntimeException('NYA_DNS_ERROR');
        }
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private function request(PendingRequest $request, string $method, string $url, array $payload = []): mixed
    {
        try {
            $response = $method === 'POST' ? $request->post($url, $payload) : $request->get($url);
        } catch (Throwable) {
            // Request exceptions can contain credentials, headers, or response bodies.
            throw new RuntimeException('NYA_TRANSPORT_ERROR');
        }
        if (!$response->successful()) {
            throw new RuntimeException('NYA_HTTP_ERROR');
        }
        try {
            // Keep JSON objects distinct from arrays so pagination envelopes fail closed.
            $body = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('NYA_RESPONSE_ERROR');
        }
        if (!$body instanceof stdClass || !property_exists($body, 'code') || !property_exists($body, 'data')) {
            throw new RuntimeException('NYA_RESPONSE_ERROR');
        }
        if ($body->code !== 0) {
            throw new RuntimeException('NYA_API_ERROR');
        }

        return $body->data;
    }

    private function objectList(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw new RuntimeException('NYA_RESPONSE_ERROR');
        }
        foreach ($data as $item) {
            if (!$item instanceof stdClass) {
                throw new RuntimeException('NYA_RESPONSE_ERROR');
            }
        }

        return $data;
    }

    private function identifier(mixed $id, string $category): string
    {
        if ((!is_int($id) && !is_string($id)) || trim((string) $id) === '') {
            throw new RuntimeException($category);
        }

        return (string) $id;
    }
}
