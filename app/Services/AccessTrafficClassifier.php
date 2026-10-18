<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\IpUtils;

class AccessTrafficClassifier
{
    public const LABELS = ['crypto' => '加密货币', 'finance' => '金融服务', 'tools' => '开发与网络工具',
        'push' => '消息推送', 'telemetry' => '统计与遥测', 'social' => '社交平台', 'games' => '游戏服务',
        'shopping' => '购物电商', 'productivity' => '办公与效率', 'education' => '教育学习', 'ai' => 'AI 服务', 'messaging' => '即时通讯', 'video' => '视频服务', 'web' => '网页服务', 'download' => '下载',
        'update' => '软件更新', 'sync' => '同步服务', 'storage' => '云存储', 'cdn' => '通用 CDN', 'unknown' => '未知'];

    public function classify(string $domain, string $source, string $ip = ''): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '' || !in_array($source, ['request', 'sniff'], true)) {
            if ($domain === '' && $source === 'ip' && filter_var($ip, FILTER_VALIDATE_IP) && !AccessTrafficService::isUnspecifiedIP($ip)) {
                $packed = inet_pton($ip);
                if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                    $ip = inet_ntop(substr($packed, 12));
                }
                foreach (config('access_traffic.ip_rules', []) as $rule) {
                    foreach ($rule['ranges'] as $range) {
                        if (!IpUtils::checkIp($ip, $range)) continue;
                        return ['service' => $rule['service'], 'category' => $rule['category'], 'confidence' => $rule['confidence'],
                            'reason' => '目标 IP 匹配服务专用网段 ' . $range . '；来源：' . $rule['source']
                                . '。据此推断服务归属，具体请求内容及用户操作未被采集。'];
                    }
                }
            }
            return ['service' => '', 'category' => 'unknown', 'confidence' => 'unknown',
                'reason' => $ip === '' || AccessTrafficService::isUnspecifiedIP($ip)
                    ? '未采集到有效目标域名或 IP，服务归属未知。' : '仅有目标 IP，未匹配服务专用网段；具体用途未知。'];
        }
        if (preg_match('/(?:^|\.)eos(?:-[a-z0-9]+)+\.cmecloud\.cn$/D', $domain)) {
            return ['service' => '移动云 EOS 对象存储', 'category' => 'storage', 'confidence' => 'medium',
                'reason' => '匹配移动云 EOS 对象存储端点；可信度表示服务归属，具体文件及视频、下载用途未知。'];
        }
        $rules = config('access_traffic.rules', []);
        foreach ($rules as $rule) {
            [$suffix, $service, $category] = $rule;
            $exact = ($rule[5] ?? 'suffix') === 'full';
            if ($domain === $suffix || (!$exact && str_ends_with($domain, '.' . $suffix))) {
                return compact('service', 'category') + ['confidence' => $rule[3] ?? (in_array($category, ['video', 'update'], true) ? 'high' : 'medium'),
                    'reason' => ($exact ? '匹配完整域名 ' : '匹配域名后缀 ') . $suffix . (isset($rule[4]) ? '；来源：' . $rule[4] : '')
                        . '，可信度表示服务归属；具体交易、转账或消息未被采集，具体 HTTPS 内容及用户意图未知。'];
            }
        }
        $known = (new AccessTrafficDomainDatabase())->lookup($domain);
        if ($known !== null) return $known;
        return ['service' => '', 'category' => 'unknown', 'confidence' => 'unknown',
            'reason' => '已记录目标域名，本地服务规则与 GeoSite 数据库均未匹配；具体用途未知。'];
    }
}
