<?php

namespace App\Services;

class AccessTrafficActivityInference
{
    public function infer(array $row): array
    {
        $upload = max(0, (float) $row['raw_u']);
        $download = max(0, (float) $row['raw_d']);
        $total = $upload + $download;
        if ($total < 1024 * 1024) return [];

        $upShare = $upload / $total;
        $downShare = $download / $total;
        $hint = '双向数据传输';
        $reason = '上传与接收均有明显占比，可能包含同步、交互或音视频等多种操作。';
        if ($upShare >= 0.9) {
            $hint = '疑似上传 / 同步';
            $reason = '上行占比 ' . round($upShare * 100, 1) . '%，发送数据占主导；可能涉及文件上传、同步或音视频上传。';
        } elseif ($downShare >= 0.9) {
            $hint = '疑似下载 / 内容接收';
            $reason = '下行占比 ' . round($downShare * 100, 1) . '%，接收数据占主导；文件下载、网页资源与流媒体接收都可能产生这种流量。';
        }
        if (($row['domain'] ?? '') === '' && ($row['ip'] ?? '') === '') $reason .= '真实目标未采集。';
        return ['activity_hint' => $hint, 'activity_confidence' => 'low',
            'activity_reason' => $reason . '仅为流量形态线索，不表示已识别具体内容或用户操作。'];
    }
}
