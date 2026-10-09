<?php

namespace App\Services\NodeUpdate;

use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            $values['last_seen_at'] = Carbon::now('UTC')->format('Y-m-d H:i:s');
            DB::table('v2_node_update_discoveries')->upsert(
                [['node_id' => $node->id] + $values], ['node_id'], array_keys($values)
            );
        } catch (\Throwable $e) {
            // Optional metadata is best effort; do not log credentials or its payload.
        }
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
                'd.version', 'd.os', 'd.arch', 'd.last_seen_at'])
            ->map(static function ($row): array {
                $item = (array) $row;
                $item['last_seen_at'] = Carbon::parse($item['last_seen_at'], 'UTC')->format('Y-m-d\TH:i:s\Z');
                return $item;
            })->all();
        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }
}
