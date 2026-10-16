<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubscriptionPolicyService
{
    public const SETTING_KEY = 'subscribe_protocol_policies';
    public const RESERVED_PARAMETERS = ['token', 'flag', 'types', 'filter', 'access_token', 'api_token', 'lang', 'locale'];

    private const LABELS = [
        'hysteria' => 'Hysteria', 'vless' => 'VLESS', 'trojan' => 'Trojan',
        'vmess' => 'VMess', 'tuic' => 'TUIC', 'shadowsocks' => 'Shadowsocks',
        'anytls' => 'AnyTLS', 'socks' => 'SOCKS', 'naive' => 'Naive',
        'http' => 'HTTP', 'mieru' => 'Mieru', 'snell' => 'Snell',
    ];

    public function settings(): array
    {
        $stored = admin_setting(self::SETTING_KEY, []);
        $overrides = [];
        foreach (is_array($stored) ? $stored : [] as $rule) {
            if (is_array($rule) && isset($rule['protocol']) && is_string($rule['protocol'])) {
                $overrides[$rule['protocol']] = $rule;
            }
        }
        $rules = [];
        foreach (Server::VALID_TYPES as $protocol) {
            $rule = $overrides[$protocol] ?? [];
            $rules[] = [
                'protocol' => $protocol,
                'label' => self::LABELS[$protocol] ?? $protocol,
                'default_enabled' => (bool) ($rule['default_enabled'] ?? ($protocol !== Server::TYPE_SNELL)),
                'allow_override' => (bool) ($rule['allow_override'] ?? true),
                'parameter' => $rule['parameter'] ?? $protocol,
                'requirement' => $protocol === Server::TYPE_SNELL ? 'Mihomo 1.19.26+；Surge 沿用现有兼容规则' : '沿用客户端协议兼容规则',
            ];
        }
        return ['rules' => $rules, 'reserved_parameters' => self::RESERVED_PARAMETERS];
    }

    public function save(array $input): array
    {
        $data = Validator::make($input, [
            'rules' => ['required', 'array', 'list', 'size:' . count(Server::VALID_TYPES)],
            'rules.*' => 'required|array:protocol,default_enabled,allow_override,parameter',
            'rules.*.protocol' => ['required', 'string', 'distinct', Rule::in(Server::VALID_TYPES)],
            'rules.*.default_enabled' => 'required|boolean',
            'rules.*.allow_override' => 'required|boolean',
            'rules.*.parameter' => ['required', 'string', 'max:32', 'regex:/\A[a-z][a-z0-9_]*\z/', 'distinct', Rule::notIn(self::RESERVED_PARAMETERS)],
        ], [
            'rules.size' => '请提交全部协议的下发规则',
            'rules.*.parameter.regex' => '参数名须以小写英文字母开头，仅包含小写字母、数字和下划线',
            'rules.*.parameter.distinct' => '不同协议的参数名须保持唯一',
            'rules.*.parameter.not_in' => '参数名与现有订阅参数冲突，请更换名称',
        ])->validate();
        $rules = array_map(fn (array $rule) => [
            'protocol' => $rule['protocol'],
            'default_enabled' => (bool) $rule['default_enabled'],
            'allow_override' => (bool) $rule['allow_override'],
            'parameter' => $rule['parameter'],
        ], $data['rules']);
        admin_setting([self::SETTING_KEY => $rules]);
        return $this->settings();
    }

    public function queryForRefresh(Request $request): array
    {
        $query = $request->query();
        $result = [];
        foreach (['flag', 'types', 'filter'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $result[$key] = $query[$key];
            }
        }
        foreach ($this->settings()['rules'] as $rule) {
            $value = $query[$rule['parameter']] ?? null;
            if ($rule['allow_override'] && in_array($value, ['0', '1', 0, 1], true)) {
                $result[$rule['parameter']] = (string) $value;
            }
        }
        return $result;
    }

    public function filter(array $servers, Request $request): array
    {
        $query = $request->query();
        $allowed = [];
        foreach ($this->settings()['rules'] as $rule) {
            $enabled = $rule['default_enabled'];
            $value = $query[$rule['parameter']] ?? null;
            // Only explicit scalar 1/0 values override defaults; arrays and unknown values do not opt in.
            if ($rule['allow_override'] && in_array($value, ['0', '1', 0, 1], true)) {
                $enabled = (string) $value === '1';
            }
            if ($enabled) {
                $allowed[] = $rule['protocol'];
            }
        }
        return array_values(array_filter($servers, fn (array $server) => in_array($server['type'] ?? null, $allowed, true)));
    }
}
