<?php


namespace App\Http\Requests\Admin;

use App\Models\Server;
use App\Models\Plan;
use App\Services\ServerEntryPointService;
use Illuminate\Foundation\Http\FormRequest;

class ServerSave extends FormRequest
{
    private const UTLS_RULES = [
        'utls.enabled' => 'nullable|boolean',
        'utls.fingerprint' => 'nullable|string',
    ];

    private const MULTIPLEX_RULES = [
        'multiplex.enabled' => 'nullable|boolean',
        'multiplex.protocol' => 'nullable|string',
        'multiplex.max_connections' => 'nullable|integer',
        'multiplex.min_streams' => 'nullable|integer',
        'multiplex.max_streams' => 'nullable|integer',
        'multiplex.padding' => 'nullable|boolean',
        'multiplex.brutal.enabled' => 'nullable|boolean',
        'multiplex.brutal.up_mbps' => 'nullable|integer',
        'multiplex.brutal.down_mbps' => 'nullable|integer',
    ];

    private const ECH_RULES = [
        'enabled' => 'nullable|boolean',
        'config' => 'nullable|string',
        'query_server_name' => 'nullable|string',
        'key' => 'nullable|string',
    ];

    private const REALITY_RULES = [
        'reality_settings.allow_insecure' => 'nullable|boolean',
        'reality_settings.server_name' => 'nullable|string',
        'reality_settings.server_port' => 'nullable|integer',
        'reality_settings.public_key' => 'nullable|string',
        'reality_settings.private_key' => 'nullable|string',
        'reality_settings.short_id' => 'nullable|string',
    ];

    private const PROTOCOL_RULES = [
        'snell' => [
            'version' => 'sometimes|required|integer|in:4',
            'obfs' => 'sometimes|required|string|in:off,http',
            'obfs_host' => ['nullable', 'string', 'max:255', 'regex:/\A[^\s,]*\z/'],
            'udp' => 'sometimes|required|boolean|declined',
        ],
        'shadowsocks' => [
            'cipher' => 'required|string',
            'obfs' => 'nullable|string',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
            'plugin' => 'nullable|string',
            'plugin_opts' => 'nullable|string',
        ],
        'vmess' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'rules' => 'nullable|array',
        ],
        'trojan' => [
            'tls' => 'nullable|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable|string',
            'allow_insecure' => 'nullable|boolean',
        ],
        'hysteria' => [
            'version' => 'required|integer',
            'alpn' => 'nullable|string',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'string|nullable',
            'obfs.password' => 'string|nullable',
            'bandwidth.up' => 'nullable|integer',
            'bandwidth.down' => 'nullable|integer',
            'hop_interval' => 'integer|nullable',
        ],
        'vless' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'encryption' => 'nullable|array',
            'encryption.enabled' => 'nullable|boolean',
            'encryption.encryption' => 'nullable|string',
            'encryption.decryption' => 'nullable|string',
        ],
        'socks' => [
            'tls' => 'nullable|integer',
        ],
        'naive' => [
            'tls' => 'required|integer',
        ],
        'http' => [
            'tls' => 'required|integer',
        ],
        'tuic' => [
            'version' => 'nullable|integer',
            'congestion_control' => 'nullable|string',
            'alpn' => 'nullable|array',
            'udp_relay_mode' => 'nullable|string',
        ],
        'mieru' => [
            'transport' => 'required|string|in:TCP,UDP',
            'traffic_pattern' => 'string',
        ],
        'anytls' => [
            'tls' => 'nullable|array',
            'alpn' => 'nullable|string',
            'padding_scheme' => 'nullable|array',
        ],
    ];

    private function getBaseRules(): array
    {
        return [
            'type' => 'required|in:' . implode(',', Server::VALID_TYPES),
            'spectific_key' => 'nullable|string',
            'code' => 'nullable|string',
            'show' => '',
            'auto_online' => 'nullable|boolean',
            'auto_online_cooldown_enabled' => 'nullable|boolean',
            'gfw_check_enabled' => 'nullable|boolean',
            'tcp_check_enabled' => 'nullable|boolean',
            'bandwidth_check_enabled' => 'sometimes|boolean',
            'name' => 'required|string',
            'group_ids' => 'nullable|array',
            'subscription_label_id' => 'sometimes|nullable|integer|min:1|exists:v2_subscription_labels,id',
            'plan_restriction_enabled' => 'sometimes|boolean',
            'allowed_plan_ids' => 'sometimes|array',
            'allowed_plan_ids.*' => 'required|integer|min:1|distinct',
            'route_ids' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'child_rate_enabled' => 'sometimes|boolean',
            'machine_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'entry_points_enabled' => 'sometimes|boolean',
            'entry_points' => 'sometimes|nullable|array|list|max:' . ServerEntryPointService::MAX_EXTRA_ENTRIES,
            'entry_points.*' => 'required|array:host,port',
            'entry_points.*.host' => ['required', 'string', 'max:255', function ($attribute, $value, $fail): void {
                if (!is_string($value) || !ServerEntryPointService::validHost($value)) {
                    $fail('入口地址请填写域名或 IP，不含协议、路径和端口');
                }
            }],
            'entry_points.*.port' => 'required|integer|between:1,65535',
            'tags' => 'nullable|array',
            'excludes' => 'nullable|array',
            'ips' => 'nullable|array',
            'rate' => 'required|numeric',
            'rate_time_enable' => 'nullable|boolean',
            'rate_time_ranges' => 'nullable|array',
            'custom_outbounds' => 'nullable|array',
            'custom_routes' => 'nullable|array',
            'cert_config' => 'nullable|array',
            'rate_time_ranges.*.start' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.end' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.rate' => 'required_with:rate_time_ranges|numeric|min:0',
            'protocol_settings' => 'array',
            'transfer_enable' => 'nullable|integer|min:0',
            'traffic_limit_enabled' => 'nullable|boolean',
            'traffic_limit_reset_day' => 'nullable|integer|min:1|max:31',
            'traffic_limit_reset_time' => 'nullable|string|date_format:H:i',
            'traffic_limit_timezone' => 'nullable|string|max:64',
        ];
    }

    private function getProtocolRules(string $type): array
    {
        $rules = self::PROTOCOL_RULES[$type] ?? [];

        return match ($type) {
            'vmess' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(),
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'trojan' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(includeRoot: true),
                self::REALITY_RULES,
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'hysteria' => array_merge(
                $rules,
                $this->buildTlsObjectRules(),
            ),
            'tuic' => array_merge(
                $rules,
                $this->buildTlsObjectRules(),
            ),
            'mieru' => array_merge(
                $rules,
                self::MULTIPLEX_RULES,
            ),
            'vless' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(),
                self::REALITY_RULES,
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'socks', 'naive', 'http' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(includeRoot: $type !== 'socks'),
            ),
            'anytls' => array_merge(
                $rules,
                $this->buildTlsObjectRules(includeRoot: true),
            ),
            default => $rules,
        };
    }

    private function buildTlsSettingsRules(bool $includeRoot = false): array
    {
        return array_merge(
            $includeRoot ? ['tls_settings' => 'nullable|array'] : [],
            [
                'tls_settings.server_name' => 'nullable|string',
                'tls_settings.allow_insecure' => 'nullable|boolean',
                'tls_settings.ech' => 'nullable|array',
            ],
            $this->prefixRules('tls_settings.ech.', self::ECH_RULES),
        );
    }

    private function buildTlsObjectRules(bool $includeRoot = false): array
    {
        return array_merge(
            $includeRoot ? ['tls' => 'nullable|array'] : [],
            [
                'tls.server_name' => 'nullable|string',
                'tls.allow_insecure' => 'nullable|boolean',
                'tls.ech' => 'nullable|array',
            ],
            $this->prefixRules('tls.ech.', self::ECH_RULES),
        );
    }

    private function prefixRules(string $prefix, array $rules): array
    {
        $result = [];
        foreach ($rules as $field => $rule) {
            $result[$prefix . $field] = $rule;
        }
        return $result;
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $rules = $this->getBaseRules();
        $protocolRules = $this->getProtocolRules($type);

        foreach ($protocolRules as $field => $rule) {
            $rules['protocol_settings.' . $field] = $rule;
        }

        if ($type === Server::TYPE_SNELL) {
            $rules['protocol_settings'] = 'array:version,obfs,obfs_host,udp';
            $rules['machine_id'] = 'nullable|integer|prohibited';
            foreach (['route_ids', 'custom_outbounds', 'custom_routes', 'cert_config', 'psk', 'password', 'shared_psk', 'server_key'] as $field) {
                $rules[$field] = 'prohibited';
            }
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $server = $this->input('id') ? Server::find($this->input('id')) : null;
            $enabled = $this->has('entry_points_enabled')
                ? $this->boolean('entry_points_enabled') : (bool) $server?->entry_points_enabled;
            $entries = $this->input('entry_points', $server?->entry_points ?? []) ?? [];
            if ($enabled && !$entries) {
                $validator->errors()->add('entry_points', '开启随机入口后，请至少添加一个额外入口');
            }
            if ($enabled && (!is_string($this->input('host')) || !ServerEntryPointService::validHost($this->input('host')))) {
                $validator->errors()->add('host', '随机入口的主地址请填写域名或 IP');
            }
            if ($enabled && !ServerEntryPointService::validPort($this->input('port'))) {
                $validator->errors()->add('port', '随机入口的主连接端口需为 1-65535 的单个整数');
            }
            $seen = [];
            if (is_string($this->input('host')) && ServerEntryPointService::validPort($this->input('port'))) {
                $seen[json_encode([ServerEntryPointService::normalizeHost($this->input('host')), (int) $this->input('port')])] = true;
            }
            foreach ($entries as $index => $entry) {
                $key = json_encode([ServerEntryPointService::normalizeHost($entry['host']), (int) $entry['port']]);
                if (isset($seen[$key])) {
                    $validator->errors()->add("entry_points.{$index}.host", '入口地址与连接端口组合重复');
                }
                $seen[$key] = true;
            }
        });
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $server = $this->input('id') ? Server::find($this->input('id')) : new Server();
            if (!$server) {
                return;
            }
            $server->fill($this->only(['parent_id', 'child_rate_enabled', 'type', 'protocol_settings']));
            try {
                \App\Services\ChildNodeRateService::validate($server,
                    $server->isDirty(['child_rate_enabled', 'parent_id', 'type', 'protocol_settings']));
            } catch (\Illuminate\Validation\ValidationException $error) {
                foreach ($error->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty() || !$this->hasAny(['plan_restriction_enabled', 'allowed_plan_ids', 'subscription_label_id'])) {
                return;
            }

            $server = $this->input('id') ? Server::find($this->input('id')) : null;
            $labelId = array_key_exists('subscription_label_id', $this->all())
                ? $this->input('subscription_label_id') : $server?->subscription_label_id;
            if ($labelId !== null) {
                return;
            }
            $enabled = $this->has('plan_restriction_enabled')
                ? $this->boolean('plan_restriction_enabled')
                : (bool) $server?->plan_restriction_enabled;
            $planIds = $this->has('allowed_plan_ids')
                ? $this->input('allowed_plan_ids')
                : ($server?->allowed_plan_ids ?? []);

            if ($enabled && (!is_array($planIds) || count($planIds) === 0)) {
                $validator->errors()->add('allowed_plan_ids', '启用套餐限制时，请至少选择一个允许使用的套餐');
            } elseif ($enabled && Plan::whereIn('id', $planIds)->count() !== count(array_unique($planIds))) {
                $validator->errors()->add('allowed_plan_ids', '所选套餐已不存在，请重新选择允许使用的套餐');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'entry_points_enabled' => '随机入口',
            'entry_points' => '额外入口',
            'entry_points.*.host' => '入口地址',
            'entry_points.*.port' => '入口连接端口',
            'subscription_label_id' => '订阅标签',
            'plan_restriction_enabled' => '套餐限制',
            'allowed_plan_ids' => '允许使用的套餐',
            'allowed_plan_ids.*' => '允许使用的套餐',
            'protocol_settings.cipher' => '加密方式',
            'protocol_settings.obfs' => '混淆类型',
            'protocol_settings.network' => '传输协议',
            'protocol_settings.port_range' => '端口范围',
            'protocol_settings.traffic_pattern' => 'Traffic Pattern',
            'protocol_settings.transport' => '传输方式',
            'protocol_settings.version' => '协议版本',
            'protocol_settings.password' => '密码',
            'protocol_settings.handshake.server' => '握手服务器',
            'protocol_settings.handshake.server_port' => '握手端口',
            'protocol_settings.multiplex.enabled' => '多路复用',
            'protocol_settings.multiplex.protocol' => '复用协议',
            'protocol_settings.multiplex.max_connections' => '最大连接数',
            'protocol_settings.multiplex.min_streams' => '最小流数',
            'protocol_settings.multiplex.max_streams' => '最大流数',
            'protocol_settings.multiplex.padding' => '复用填充',
            'protocol_settings.multiplex.brutal.enabled' => 'Brutal加速',
            'protocol_settings.multiplex.brutal.up_mbps' => 'Brutal上行速率',
            'protocol_settings.multiplex.brutal.down_mbps' => 'Brutal下行速率',
            'protocol_settings.utls.enabled' => 'uTLS',
            'protocol_settings.utls.fingerprint' => 'uTLS指纹',
            'protocol_settings.tls_settings.ech.enabled' => 'ECH',
            'protocol_settings.tls_settings.ech.config' => 'ECH配置',
            'protocol_settings.tls_settings.ech.query_server_name' => 'ECH查询域名',
            'protocol_settings.tls_settings.ech.key' => 'ECH密钥',
            'protocol_settings.tls.ech.enabled' => 'ECH',
            'protocol_settings.tls.ech.config' => 'ECH配置',
            'protocol_settings.tls.ech.query_server_name' => 'ECH查询域名',
            'protocol_settings.tls.ech.key' => 'ECH密钥',
        ];
    }

    public function messages()
    {
        return [
            'name.required' => '节点名称不能为空',
            'group_ids.required' => '权限组不能为空',
            'group_ids.array' => '权限组格式不正确',
            'route_ids.array' => '路由组格式不正确',
            'parent_id.integer' => '父ID格式不正确',
            'host.required' => '节点地址不能为空',
            'port.required' => '连接端口不能为空',
            'server_port.required' => '后端服务端口不能为空',
            'tls.required' => 'TLS不能为空',
            'tags.array' => '标签格式不正确',
            'rate.required' => '倍率不能为空',
            'rate.numeric' => '倍率格式不正确',
            'network.required' => '传输协议不能为空',
            'network.in' => '传输协议格式不正确',
            'networkSettings.array' => '传输协议配置有误',
            'ruleSettings.array' => '规则配置有误',
            'tlsSettings.array' => 'tls配置有误',
            'dnsSettings.array' => 'dns配置有误',
            'protocol_settings.*.required' => ':attribute 不能为空',
            'protocol_settings.*.required_if' => ':attribute 不能为空',
            'protocol_settings.*.string' => ':attribute 必须是字符串',
            'protocol_settings.*.integer' => ':attribute 必须是整数',
            'protocol_settings.*.in' => ':attribute 的值不合法',
            'transfer_enable.integer' => '流量上限必须是整数',
            'transfer_enable.min' => '流量上限不能小于0',
            'traffic_limit_reset_day.integer' => '重置日期必须是整数',
            'traffic_limit_reset_day.min' => '重置日期不能小于1',
            'traffic_limit_reset_day.max' => '重置日期不能大于31',
            'traffic_limit_reset_time.date_format' => '重置时间格式必须为HH:mm',
            'traffic_limit_timezone.max' => '重置时区长度不能超过64个字符',
        ];
    }
}
