<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AccessTrafficReclassifier
{
    public function chunk(int $afterId, int $throughId, int $size = 500): array
    {
        $rows = DB::table('v2_access_traffic')->where('id', '>', $afterId)->where('id', '<=', $throughId)
            ->orderBy('id')->limit(max(1, min(2000, $size)))->get(['id', 'report_id', 'domain', 'ip', 'port', 'domain_source', 'service', 'category', 'confidence', 'reason']);
        $classifier = new AccessTrafficClassifier();
        $groups = [];
        $partial = [];
        foreach ($rows as $row) {
            $changes = [];
            if (AccessTrafficService::isUnspecifiedIP($row->ip)) {
                $changes['ip'] = '';
                if ($row->domain === '') {
                    $changes += ['port' => 0, 'domain_source' => 'unknown'];
                    $row->domain_source = 'unknown';
                    $partial[] = $row->report_id;
                }
            }
            foreach ($classifier->classify($row->domain, $row->domain_source) as $field => $value) {
                if ($row->$field !== $value) $changes[$field] = $value;
            }
            if (!$changes) continue;
            $key = json_encode($changes, JSON_THROW_ON_ERROR);
            $groups[$key]['values'] = $changes;
            $groups[$key]['ids'][] = $row->id;
        }
        $updated = DB::transaction(function () use ($groups, $partial): int {
            $count = 0;
            foreach ($groups as $group) $count += DB::table('v2_access_traffic')->whereIn('id', $group['ids'])->update($group['values']);
            if ($partial) DB::table('v2_access_report')->whereIn('id', array_unique($partial))->update(['coverage' => 'partial']);
            return $count;
        }, 3);
        return ['last_id' => $rows->isEmpty() ? $throughId : (int) $rows->last()->id, 'updated' => $updated, 'scanned' => $rows->count()];
    }
}
