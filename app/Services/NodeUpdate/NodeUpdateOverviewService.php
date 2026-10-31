<?php

namespace App\Services\NodeUpdate;

use Illuminate\Support\Facades\DB;

class NodeUpdateOverviewService
{
    public function __construct(
        private NodeUpdateService $service,
        private NodeDiscoveryService $discoveries = new NodeDiscoveryService(),
    ) {}

    public function listing(array $input): array
    {
        Protocol::fields($input, [], ['page', 'page_size', 'node_id', 'machine_id', 'capability_status']);
        $page = $this->queryInt($input['page'] ?? 1, 1000000);
        $pageSize = $this->queryInt($input['page_size'] ?? 20, 100);
        foreach (['node_id', 'machine_id'] as $field) {
            if (array_key_exists($field, $input)) $input[$field] = $this->queryInt($input[$field]);
        }
        if (array_key_exists('capability_status', $input)
            && !in_array($input['capability_status'], ['supported', 'unsupported'], true)) Protocol::fail();

        // Read only identity/scope metadata globally; build the existing managed DTO on this page only.
        $installations = $this->service->q('installations')->get(['id', 'scope', 'capability', 'last_seen_at']);
        $legacyIds = []; $machineIds = [];
        foreach ($installations as $installation) {
            $scope = $installation->scope;
            if ($scope['kind'] === 'machine') $machineIds[$scope['machine_id']] = true;
            else foreach ($scope['node_ids'] as $nodeId) $legacyIds[$nodeId] = true;
        }
        $nodesById = []; $nodesByMachine = [];
        if ($legacyIds || $machineIds) {
            $nodes = DB::table('v2_server')->where(function ($query) use ($legacyIds, $machineIds) {
                $query->whereIn('id', array_keys($legacyIds))->orWhereIn('machine_id', array_keys($machineIds));
            })->get(['id', 'name', 'machine_id']);
            foreach ($nodes as $node) {
                $item = ['id' => (int) $node->id, 'name' => $node->name,
                    'machine_id' => $node->machine_id === null ? null : (int) $node->machine_id];
                $nodesById[$item['id']] = $item;
                if ($item['machine_id'] !== null) $nodesByMachine[$item['machine_id']][$item['id']] = $item;
            }
        }

        $rows = []; $registeredNodes = []; $records = [];
        foreach ($installations as $installation) {
            $key = strtolower($installation->id);
            $records[$key] = $installation;
            $row = $this->emptyRow($key);
            $scope = $installation->scope;
            if ($scope['kind'] === 'machine') $row['nodes'] = $nodesByMachine[$scope['machine_id']] ?? [];
            else foreach ($scope['node_ids'] as $nodeId) {
                if (isset($nodesById[$nodeId])) $row['nodes'][$nodeId] = $nodesById[$nodeId];
            }
            $registeredNodes[$key] = array_fill_keys(array_keys($row['nodes']), true);
            $row['last_seen_at'] = Protocol::time($installation->last_seen_at);
            $rows[$key] = $row;
        }

        // Join the complete discovery inventory before pagination, using one database read.
        foreach ($this->discoveries->inventory() as $discovery) {
            $key = strtolower($discovery['installation_id']);
            $rows[$key] ??= $this->emptyRow($key);
            $rows[$key]['discoveries'][] = $discovery;
            $nodeId = (int) $discovery['node_id'];
            $rows[$key]['nodes'][$nodeId] = ['id' => $nodeId, 'name' => $discovery['node_name'],
                'machine_id' => $discovery['machine_id'] === null ? null : (int) $discovery['machine_id']];
            if (isset($records[$key]) && !isset($registeredNodes[$key][$nodeId])) {
                $rows[$key]['scope_mismatch_node_ids'][] = $nodeId;
            }
            $seen = $discovery['last_seen_at'];
            if ($rows[$key]['last_seen_at'] === null || strcmp($seen, $rows[$key]['last_seen_at']) > 0) {
                $rows[$key]['last_seen_at'] = $seen;
            }
        }

        foreach ($rows as &$row) {
            ksort($row['nodes'], SORT_NUMERIC);
            $row['nodes'] = array_values($row['nodes']);
            sort($row['scope_mismatch_node_ids'], SORT_NUMERIC);
        }
        unset($row);
        $rows = array_values(array_filter($rows, function ($row) use ($input, $records) {
            $installation = $records[$row['installation_id']] ?? null;
            if (isset($input['capability_status'])
                && (!$installation || ($installation->capability['status'] ?? null) !== $input['capability_status'])) return false;
            if (isset($input['node_id']) && !in_array($input['node_id'], array_column($row['nodes'], 'id'), true)) return false;
            if (isset($input['machine_id'])) {
                $scope = $installation?->scope;
                if (!(($scope['kind'] ?? null) === 'machine' && $scope['machine_id'] === $input['machine_id'])
                    && !in_array($input['machine_id'], array_column($row['nodes'], 'machine_id'), true)) return false;
            }
            return true;
        }));
        usort($rows, static function ($a, $b) {
            return ($a['nodes'][0]['id'] ?? PHP_INT_MAX) <=> ($b['nodes'][0]['id'] ?? PHP_INT_MAX)
                ?: strcmp($a['installation_id'], $b['installation_id']);
        });
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $pageSize, $pageSize);
        $pageIds = [];
        foreach ($items as $row) {
            if (isset($records[$row['installation_id']])) $pageIds[] = $records[$row['installation_id']]->id;
        }
        $details = $pageIds ? $this->service->q('installations')->whereIn('id', $pageIds)->get()
            ->keyBy(fn ($record) => strtolower($record->id)) : collect();
        foreach ($items as &$row) {
            $record = $details->get($row['installation_id']);
            if ($record) $row['installation'] = $this->service->dto('installations', $record);
        }
        unset($row);
        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    private function emptyRow(string $id): array
    {
        return ['installation_id' => $id, 'installation' => null, 'discoveries' => [], 'nodes' => [],
            'scope_mismatch_node_ids' => [], 'last_seen_at' => null];
    }

    private function queryInt(mixed $value, int $max = 9007199254740991): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,16}$/D', $value)) $value = (int) $value;
        Protocol::integer($value, 1, $max);
        return $value;
    }
}
