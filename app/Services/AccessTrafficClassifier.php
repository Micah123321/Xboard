<?php

namespace App\Services;

class AccessTrafficClassifier
{
    public const LABELS = ['video' => '视频服务', 'web' => '网页服务', 'download' => '下载',
        'update' => '软件更新', 'sync' => '同步服务', 'storage' => '云存储', 'cdn' => '通用 CDN', 'unknown' => '未知'];

    public function classify(string $domain, string $source): array
    {
        $domain = strtolower(rtrim($domain, '.'));
        if ($domain === '' || !in_array($source, ['request', 'sniff'], true)) {
            return ['service' => '', 'category' => 'unknown', 'confidence' => 'unknown',
                'reason' => '缺少可核实的目标域名，具体用途未知。'];
        }
        if (preg_match('/(?:^|\.)eos(?:-[a-z0-9]+)+\.cmecloud\.cn$/D', $domain)) {
            return ['service' => '移动云 EOS 对象存储', 'category' => 'storage', 'confidence' => 'medium',
                'reason' => '匹配移动云 EOS 对象存储端点；可信度表示服务归属，具体文件及视频、下载用途未知。'];
        }
        $rules = config('access_traffic.rules', []);
        foreach ($rules as [$suffix, $service, $category]) {
            if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                return compact('service', 'category') + ['confidence' => $category === 'video' || $category === 'update' ? 'high' : 'medium',
                    'reason' => '匹配域名后缀 ' . $suffix . '，可信度表示服务类型识别；具体 HTTPS 内容及用户意图未知。'];
            }
        }
        $known = (new AccessTrafficDomainDatabase())->lookup($domain);
        if ($known !== null) return $known;
        return ['service' => '', 'category' => 'unknown', 'confidence' => 'unknown',
            'reason' => '已记录目标域名，本地服务规则与 GeoSite 数据库均未匹配；具体用途未知。'];
    }
}
