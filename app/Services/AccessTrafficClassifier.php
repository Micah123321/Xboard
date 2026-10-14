<?php

namespace App\Services;

class AccessTrafficClassifier
{
    public const LABELS = ['video' => '视频服务', 'web' => '网页服务', 'download' => '下载',
        'update' => '软件更新', 'sync' => '同步服务', 'cdn' => '通用 CDN', 'unknown' => '未知'];

    public function classify(string $domain, string $source): array
    {
        $domain = strtolower(rtrim($domain, '.'));
        if ($domain === '' || !in_array($source, ['request', 'sniff'], true)) {
            return ['service' => '', 'category' => 'unknown', 'confidence' => 'unknown',
                'reason' => '缺少可核实的目标域名，具体用途未知。'];
        }
        $rules = config('access_traffic.rules', []);
        foreach ($rules as [$suffix, $service, $category]) {
            if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                return compact('service', 'category') + ['confidence' => $category === 'video' || $category === 'update' ? 'high' : 'medium',
                    'reason' => '匹配域名后缀 ' . $suffix . '，据此推断服务类型；具体 HTTPS 内容及用户意图未知。'];
            }
        }
        return ['service' => '', 'category' => 'unknown', 'confidence' => 'unknown',
            'reason' => '已记录目标域名，尚无匹配的服务规则；具体 HTTPS 内容及用户意图未知。'];
    }
}
