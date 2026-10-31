<?php

namespace App\Services\Nya;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class NyaEntrySyncSettings
{
    public const NAME = 'nya_entry_sync';

    public function runtime(): array
    {
        // The legacy environment configuration is only an initial default.
        $value = Schema::hasTable('v2_settings')
            ? Setting::where('name', self::NAME)->value('value') : null;
        if ($value !== null) {
            try {
                $settings = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($settings)) {
                    throw new RuntimeException();
                }
                return $this->normalize($settings);
            } catch (Throwable) {
                throw new RuntimeException('NYA_SETTINGS_ERROR');
            }
        }
        $defaults = config('nya_entry_sync', []);
        $integers = static function (array $values, int $max = PHP_INT_MAX): array {
            $result = [];
            foreach ($values as $value) {
                if (is_string($value)) {
                    $value = trim($value);
                }
                if ($value === '' || $value === null) {
                    continue;
                }
                if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)
                    || (float) $value < 1 || (float) $value > $max) {
                    throw new RuntimeException('NYA_CONFIG_ERROR');
                }
                $result[] = (int) $value;
            }
            return array_values(array_unique($result));
        };
        $defaults['server_ids'] = $integers($defaults['server_ids'] ?? []);
        $defaults['ports'] = $integers($defaults['ports'] ?? [], 65535);
        return $this->normalize($defaults);
    }

    private function normalize(array $settings): array
    {
        $accounts = $settings['accounts'] ?? [[
            'id' => 'legacy', 'name' => '账号 1', 'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''), 'enabled' => true,
        ]];
        if (!is_array($accounts) || !array_is_list($accounts)) {
            throw new RuntimeException('NYA_SETTINGS_ERROR');
        }
        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'base_url' => (string) ($settings['base_url'] ?? ''),
            'proxy' => (string) ($settings['proxy'] ?? ''),
            'timeout' => (int) ($settings['timeout'] ?? 20),
            'server_ids' => $settings['server_ids'] ?? [],
            'ports' => $settings['ports'] ?? [],
            'accounts' => $accounts,
            // Runtime aliases keep older clients and CLI integrations working.
            'username' => $accounts[0]['username'] ?? '',
            'password' => $accounts[0]['password'] ?? '',
        ];
    }

    public function publicSettings(): array
    {
        $settings = $this->runtime();
        $settings['password_set'] = ($settings['password'] ?? '') !== '';
        unset($settings['password']);
        $settings['accounts'] = array_map(static fn (array $account): array => [
            'id' => $account['id'], 'name' => $account['name'], 'username' => $account['username'],
            'password_set' => ($account['password'] ?? '') !== '', 'enabled' => (bool) $account['enabled'],
        ], $settings['accounts']);
        foreach (['base_url', 'proxy'] as $field) {
            if (!self::validUrl($settings[$field] ?? '')) {
                $settings[$field] = '';
            }
        }
        return $settings;
    }

    public function save(array $input): void
    {
        $urlRule = static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && $value !== '' && !self::validUrl($value)) {
                $fail('地址须为不含账号、查询参数或片段的 HTTP(S) URL。');
            }
        };
        $values = Validator::make($input, [
            'enabled' => ['required', 'boolean'],
            'base_url' => ['present', 'nullable', 'string', 'max:2048', $urlRule],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'clear_password' => ['sometimes', 'boolean'],
            'accounts' => ['sometimes', 'array', 'max:10', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && !array_is_list($value)) {
                    $fail('账号须为列表。');
                }
            }],
            'accounts.*' => ['required', 'array:id,name,username,enabled,password,clear_password'],
            'accounts.*.id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D', 'distinct:strict'],
            'accounts.*.name' => ['present', 'nullable', 'string', 'max:100'],
            'accounts.*.username' => ['present', 'nullable', 'string', 'max:255'],
            'accounts.*.enabled' => ['required', 'boolean'],
            'accounts.*.password' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'accounts.*.clear_password' => ['sometimes', 'boolean'],
            'proxy' => ['present', 'nullable', 'string', 'max:2048', $urlRule],
            'timeout' => ['required', 'integer', 'between:1,60'],
            'server_ids' => ['present', 'array', 'max:10000'],
            'server_ids.*' => ['required', 'integer', 'min:1', 'distinct', 'exists:v2_server,id'],
            'ports' => ['present', 'array', 'max:65535'],
            'ports.*' => ['required', 'integer', 'between:1,65535', 'distinct'],
        ], [
            'server_ids.*.exists' => '所选节点已不存在。',
        ])->validate();
        try {
            $previous = $this->runtime()['accounts'];
        } catch (RuntimeException $exception) {
            // Recover unreadable settings only when every secret is explicitly replaced or cleared.
            $replacements = $values['accounts'] ?? [$values];
            foreach ($replacements as $account) {
                if (!($account['clear_password'] ?? false) && ($account['password'] ?? '') === '') {
                    throw $exception;
                }
            }
            $previous = [];
        }
        if (array_key_exists('accounts', $values)) {
            $previousById = [];
            foreach ($previous as $account) {
                $previousById['id:' . $account['id']] = $account;
            }
            $accounts = [];
            foreach ($values['accounts'] as $account) {
                $accounts[] = [
                    'id' => $account['id'], 'name' => trim($account['name'] ?? ''),
                    'username' => trim($account['username'] ?? ''), 'enabled' => (bool) $account['enabled'],
                    'password' => $this->password($account, $previousById['id:' . $account['id']]['password'] ?? ''),
                ];
            }
        } else {
            // Cached single-account forms may update only the first account.
            $accounts = $previous ?: [[
                'id' => 'legacy', 'name' => '账号 1', 'username' => '', 'password' => '', 'enabled' => true,
            ]];
            if (array_key_exists('username', $values)) {
                $accounts[0]['username'] = trim($values['username'] ?? '');
            }
            $accounts[0]['password'] = $this->password($values, $accounts[0]['password']);
        }
        $settings = [
            'enabled' => (bool) $values['enabled'],
            'base_url' => rtrim(trim($values['base_url'] ?? ''), '/'),
            'proxy' => trim($values['proxy'] ?? ''),
            'timeout' => (int) $values['timeout'],
            'server_ids' => array_values(array_map('intval', $values['server_ids'])),
            'ports' => array_values(array_map('intval', $values['ports'])),
            'accounts' => $accounts,
        ];
        if ($settings['enabled']) {
            try {
                $this->assertReady($settings);
            } catch (RuntimeException) {
                throw ValidationException::withMessages(['enabled' => '开启自动同步前请填写有效地址、至少启用一个账号、补全所有启用账号的账号密码并选择有效节点。']);
            }
        }
        Setting::updateOrCreate(['name' => self::NAME], [
            'value' => Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)),
        ]);
    }

    private function password(array $input, string $previous): string
    {
        if ($input['clear_password'] ?? false) {
            return '';
        }
        return ($input['password'] ?? '') !== '' ? $input['password'] : $previous;
    }

    public function assertReady(array $settings): void
    {
        $settings = $this->normalize($settings);
        $accounts = array_filter($settings['accounts'], static fn (array $account): bool => (bool) ($account['enabled'] ?? false));
        if (!self::validUrl($settings['base_url']) || $accounts === []
            || empty($settings['server_ids'])
            || Server::whereIn('id', $settings['server_ids'])->count() !== count($settings['server_ids'])) {
            throw new RuntimeException('NYA_CONFIG_ERROR');
        }
        foreach ($accounts as $account) {
            if (!is_string($account['username'] ?? null) || trim($account['username']) === ''
                || !is_string($account['password'] ?? null) || $account['password'] === '') {
                throw new RuntimeException('NYA_CONFIG_ERROR');
            }
        }
    }

    public static function validUrl(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/[\x00-\x20\x7f]/', $value) || str_contains($value, '\\')) {
            return false;
        }
        $url = parse_url($value);
        return $url !== false && filter_var($value, FILTER_VALIDATE_URL)
            && in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($url['host']) && !array_key_exists('user', $url) && !array_key_exists('pass', $url)
            && !array_key_exists('query', $url) && !array_key_exists('fragment', $url);
    }
}
