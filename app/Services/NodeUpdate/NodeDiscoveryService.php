<?php

namespace App\Services\NodeUpdate;

use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class NodeDiscoveryService
{
    public function report(Server $node, mixed $inventory): void
    {
        // Discovery must never interfere with traffic acknowledgement, including during rollout.
        try {
            if (!is_array($inventory) || !is_string($inventory['installation_id'] ?? null)
                || !Str::isUuid($inventory['installation_id'])) {
                return;
            }
            $values = ['installation_id' => strtolower($inventory['installation_id'])];
            foreach (['version' => 128, 'os' => 32, 'arch' => 32] as $field => $max) {
                $value = $inventory[$field] ?? null;
                if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')
                    || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    return;
                }
                $values[$field] = $value;
            }
            if (array_key_exists('auto_enroll_supported', $inventory) && !is_bool($inventory['auto_enroll_supported'])) return;
            $values['auto_enroll_supported'] = $inventory['auto_enroll_supported'] ?? false;
            $values['last_seen_at'] = Carbon::now('UTC')->format('Y-m-d H:i:s');
            DB::table('v2_node_update_discoveries')->upsert(
                [['node_id' => $node->id] + $values], ['node_id'], array_keys($values)
            );
            $status = $this->enrollmentStatus($inventory['enrollment_status'] ?? null);
            if ($status !== null) {
                $this->cacheEnrollmentStatus($this->statusKey($node->id, $values), $status);
            }
        } catch (\Throwable $e) {
            // Optional metadata is best effort; do not log credentials or its payload.
        }
    }

    private function statusKey(int $nodeId, array $inventory): string
    {
        return 'node-update:enrollment:'.$nodeId.':'.hash('sha256', $inventory['installation_id'].'/'.$inventory['version']);
    }

    private function cacheEnrollmentStatus(string $key, array $status): void
    {
        // Async reports may arrive out of order; diagnostics never wait for ownership.
        $lock = Cache::lock($key.':lock', 5);
        if (!$lock->get()) return;
        try {
            $previous = $this->enrollmentStatus(Cache::get($key));
            if ($previous !== null) {
                $order = strcmp($previous['updated_at'], $status['updated_at']);
                // Timestamps have second precision, so ties preserve terminal facts.
                if ($order > 0 || ($order === 0
                    && in_array($previous['state'], ['registered','disabled'], true)
                    && !in_array($status['state'], ['registered','disabled'], true))) return;
            }
            Cache::put($key, $status, 300);
        } finally {
            $lock->release();
        }
    }

    private function enrollmentStatus(mixed $value): ?array
    {
        if (!is_array($value) || count($value) !== 4
            || !in_array($value['state'] ?? null, ['starting','retrying','registered','disabled','unsupported'], true)
            || !in_array($value['stage'] ?? null, ['bootstrap','layout','lock','config','authority','enroll','units','poll'], true)
            || !array_key_exists('code', $value)
            || !in_array($value['code'], [null,'','systemd_failed','shared_binary_layout','unsupported_platform','local_disabled',
                'update_busy','config_invalid','authority_unavailable','credential_missing','scope_conflict','scope_mismatch',
                'invalid_credentials','validation_failed','rate_limited','api_unavailable','transport_failed','invalid_api_response','unknown_error'], true)
            || !is_string($value['updated_at'] ?? null)) return null;
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value['updated_at'], new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value['updated_at']) return null;
        return ['state'=>$value['state'],'stage'=>$value['stage'],'code'=>$value['code'] === '' ? null : $value['code'],'updated_at'=>$value['updated_at']];
    }

    public function listing(array $input): array
    {
        $positive = static function ($value): int {
            if ((!is_int($value) && !is_string($value))
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                Protocol::fail();
            }
            return (int) $value;
        };
        $page = $positive($input['page'] ?? 1);
        $pageSize = min(100, $positive($input['page_size'] ?? 20));
        if ($page > intdiv(PHP_INT_MAX, $pageSize)) Protocol::fail();
        $query = DB::table('v2_node_update_discoveries as d')
            ->join('v2_server as s', 's.id', '=', 'd.node_id');
        foreach (['node_id' => 's.id', 'machine_id' => 's.machine_id'] as $key => $column) {
            if (array_key_exists($key, $input)) $query->where($column, $positive($input[$key]));
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('d.node_id')->offset(($page - 1) * $pageSize)->limit($pageSize)
            ->get(['d.node_id', 's.name as node_name', 's.machine_id', 'd.installation_id',
                'd.version', 'd.os', 'd.arch', 'd.last_seen_at', 'd.auto_enroll_supported'])
            ->map(function ($row): array {
                $item = (array) $row;
                $item['auto_enroll_supported'] = (bool) $item['auto_enroll_supported'];
                $item['enrollment_status'] = null;
                try {
                    $item['enrollment_status'] = $this->enrollmentStatus(Cache::get($this->statusKey($item['node_id'], $item)));
                } catch (\Throwable $e) {
                    // A diagnostics cache outage must not hide discovered versions.
                }
                $item['last_seen_at'] = Carbon::parse($item['last_seen_at'], 'UTC')->format('Y-m-d\TH:i:s\Z');
                return $item;
            })->all();
        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }
}
