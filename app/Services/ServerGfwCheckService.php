<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerGfwCheck;
use Illuminate\Support\Collection;

class ServerGfwCheckService
{
    private const TASK_STATUS = [
        ServerGfwCheck::STATUS_PENDING,
        ServerGfwCheck::STATUS_CHECKING,
    ];

    public function startChecks(array $ids, ?int $adminUserId = null, bool $respectAutoSwitch = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $servers = Server::whereIn('id', $ids)->get()->keyBy('id');
        $started = [];
        $skipped = [];

        foreach ($ids as $id) {
            $server = $servers->get($id);
            if (!$server) {
                $skipped[] = ['id' => $id, 'status' => ServerGfwCheck::STATUS_SKIPPED, 'reason' => '节点不存在'];
                continue;
            }

            if ($server->parent_id) {
                $skipped[] = [
                    'id' => $id,
                    'status' => ServerGfwCheck::STATUS_SKIPPED,
                    'reason' => '子节点随父节点检测',
                    'source_node_id' => (int) $server->parent_id,
                ];
                continue;
            }

            if ($respectAutoSwitch && !$this->isGfwCheckEnabled($server)) {
                $skipped[] = [
                    'id' => $id,
                    'status' => ServerGfwCheck::STATUS_SKIPPED,
                    'reason' => '节点已关闭自动墙检测',
                ];
                continue;
            }

            $check = $this->createCheck($server, $adminUserId);
            $started[] = [
                'id' => $server->id,
                'check_id' => $check->id,
                'status' => $check->status,
            ];
        }

        return [
            'started' => $started,
            'skipped' => $skipped,
            'total' => count($ids),
        ];
    }

    public function startAutomaticChecks(?int $limit = null): array
    {
        $query = Server::query()
            ->whereNull('parent_id')
            ->where('gfw_check_enabled', true)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $servers = $query->get();
        $activeServerIds = ServerGfwCheck::whereIn('server_id', $servers->pluck('id'))
            ->whereIn('status', self::TASK_STATUS)
            ->pluck('server_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $activeLookup = array_flip($activeServerIds);
        $started = [];
        $skipped = [];

        foreach ($servers as $server) {
            if (isset($activeLookup[(int) $server->id])) {
                $skipped[] = [
                    'id' => (int) $server->id,
                    'status' => ServerGfwCheck::STATUS_SKIPPED,
                    'reason' => '已有检测任务等待上报',
                ];
                continue;
            }

            $check = $this->createCheck($server, null);
            $started[] = [
                'id' => (int) $server->id,
                'check_id' => (int) $check->id,
                'status' => $check->status,
            ];
        }

        return [
            'started' => $started,
            'skipped' => $skipped,
            'total' => $servers->count(),
            'active' => count($activeServerIds),
        ];
    }

    public function decorateServers(Collection $servers): Collection
    {
        $sourceIds = $servers
            ->map(fn (Server $server) => (int) ($server->parent_id ?: $server->id))
            ->unique()
            ->values();

        $latestChecks = $this->latestChecksByServerIds($sourceIds);

        return $servers->map(function (Server $server) use ($latestChecks) {
            $sourceNodeId = (int) ($server->parent_id ?: $server->id);
            $check = $latestChecks->get($sourceNodeId);
            $server->setAttribute('gfw_check', $this->formatCheck($check, (bool) $server->parent_id, $sourceNodeId));
            return $server;
        });
    }

    public function getBlockedSourceIdsForServers(Collection $servers): array
    {
        return collect($this->getLatestStatusesForServers($servers))
            ->filter(fn (string $status) => $status === ServerGfwCheck::STATUS_BLOCKED)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function getLatestStatusesForServers(Collection $servers): array
    {
        $sourceIds = $servers
            ->map(fn (Server $server) => (int) ($server->parent_id ?: $server->id))
            ->filter()
            ->unique()
            ->values();

        if ($sourceIds->isEmpty()) {
            return [];
        }

        $enabledSourceIds = Server::whereIn('id', $sourceIds)
            ->where('gfw_check_enabled', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($enabledSourceIds->isEmpty()) {
            return [];
        }

        return $this->latestChecksByServerIds($enabledSourceIds)
            ->map(fn (ServerGfwCheck $check) => $check->status)
            ->all();
    }

    public function getPendingTaskForNode(Server $node): ?array
    {
        if ($node->parent_id) {
            return null;
        }

        $check = ServerGfwCheck::where('server_id', $node->id)
            ->whereIn('status', self::TASK_STATUS)
            ->orderByDesc('id')
            ->first();

        if (!$check) {
            return null;
        }

        if ($check->status === ServerGfwCheck::STATUS_PENDING) {
            $check->update(['status' => ServerGfwCheck::STATUS_CHECKING]);
        }

        return $this->formatTask($check->refresh());
    }

    public function reportResult(Server $node, array $payload): bool
    {
        $checkId = (int) ($payload['check_id'] ?? 0);
        if ($node->parent_id || $checkId <= 0) {
            return false;
        }

        $check = ServerGfwCheck::where('id', $checkId)
            ->where('server_id', $node->id)
            ->first();

        if (!$check) {
            return false;
        }

        $rawResult = $this->arrayOrNull($payload['raw_result'] ?? null);
        $operatorSummary = $this->arrayOrNull($payload['operator_summary'] ?? null)
            ?: $this->arrayOrNull(data_get($rawResult, 'operators'));
        $summary = $this->arrayOrNull($payload['summary'] ?? null) ?: [];
        $errorMessage = trim((string) ($payload['error_message'] ?? ''));
        $status = $this->determineStatus($operatorSummary, (string) ($payload['status'] ?? ''), $errorMessage);

        $summary = array_merge($summary, $this->buildSummary($operatorSummary, $status));

        $check->update([
            'status' => $status,
            'summary' => $summary,
            'operator_summary' => $operatorSummary,
            'raw_result' => $rawResult,
            'error_message' => $errorMessage !== '' ? $errorMessage : null,
            'checked_at' => time(),
        ]);

        $this->syncVisibilityFromStatus($node, $status);

        return true;
    }

    private function createCheck(Server $server, ?int $adminUserId): ServerGfwCheck
    {
        $check = ServerGfwCheck::create([
            'server_id' => $server->id,
            'status' => ServerGfwCheck::STATUS_PENDING,
            'triggered_by' => $adminUserId,
        ]);

        NodeSyncService::push($server->id, 'gfw.check', $this->formatTask($check));

        return $check;
    }

    private function formatTask(ServerGfwCheck $check): array
    {
        return [
            'check_id' => (int) $check->id,
            'targets' => $this->defaultTargets(),
            'ping_count' => 2,
            'timeout_seconds' => 2,
            'parallel' => 12,
        ];
    }

    private function formatCheck(?ServerGfwCheck $check, bool $inherited, int $sourceNodeId): array
    {
        if (!$check) {
            return [
                'status' => 'unchecked',
                'inherited' => $inherited,
                'source_node_id' => $sourceNodeId,
            ];
        }

        return [
            'id' => (int) $check->id,
            'status' => $check->status,
            'inherited' => $inherited,
            'source_node_id' => $sourceNodeId,
            'summary' => $check->summary,
            'operator_summary' => $check->operator_summary,
            'error_message' => $check->error_message,
            'checked_at' => $check->checked_at,
            'updated_at' => optional($check->updated_at)->timestamp,
        ];
    }

    private function latestChecksByServerIds($sourceIds): Collection
    {
        $ids = collect($sourceIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return ServerGfwCheck::whereIn('server_id', $ids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('server_id')
            ->map(fn (Collection $items) => $items->first());
    }

    private function syncVisibilityFromStatus(Server $sourceNode, string $status): array
    {
        if (!in_array($status, [ServerGfwCheck::STATUS_BLOCKED, ServerGfwCheck::STATUS_NORMAL], true)) {
            return ['shown' => 0, 'hidden' => 0, 'unchanged' => 0];
        }

        if (!$this->isGfwCheckEnabled($sourceNode)) {
            return ['shown' => 0, 'hidden' => 0, 'unchanged' => 1];
        }

        $nodes = Server::query()
            ->where('id', $sourceNode->id)
            ->orWhere('parent_id', $sourceNode->id)
            ->get();

        $result = ['shown' => 0, 'hidden' => 0, 'unchanged' => 0];
        $now = time();

        foreach ($nodes as $node) {
            if (!$this->isGfwCheckEnabled($node)) {
                $result['unchanged']++;
                continue;
            }

            if ($status === ServerGfwCheck::STATUS_BLOCKED) {
                if (!(bool) $node->show) {
                    $result['unchanged']++;
                    continue;
                }

                $node->update([
                    'show' => false,
                    'gfw_auto_hidden' => true,
                    'gfw_auto_action_at' => $now,
                ]);
                $result['hidden']++;
                continue;
            }

            if (!(bool) $node->gfw_auto_hidden) {
                $result['unchanged']++;
                continue;
            }

            $node->update([
                'show' => true,
                'gfw_auto_hidden' => false,
                'gfw_auto_action_at' => $now,
            ]);
            $result['shown']++;
        }

        return $result;
    }

    private function isGfwCheckEnabled(Server $server): bool
    {
        return (bool) ($server->gfw_check_enabled ?? true);
    }

    private function determineStatus(?array $operators, string $reportedStatus, string $errorMessage): string
    {
        if ($errorMessage !== '') {
            return ServerGfwCheck::STATUS_FAILED;
        }

        $allowed = [
            ServerGfwCheck::STATUS_NORMAL,
            ServerGfwCheck::STATUS_BLOCKED,
            ServerGfwCheck::STATUS_PARTIAL,
            ServerGfwCheck::STATUS_FAILED,
        ];

        if (!$operators) {
            return in_array($reportedStatus, $allowed, true) ? $reportedStatus : ServerGfwCheck::STATUS_FAILED;
        }

        $total = 0;
        $success = 0;
        $reachableOperators = 0;
        foreach ($operators as $operator) {
            $operatorTotal = (int) ($operator['total'] ?? 0);
            $operatorSuccess = (int) ($operator['success'] ?? 0);
            $total += $operatorTotal;
            $success += $operatorSuccess;
            if ($operatorSuccess > 0) {
                $reachableOperators++;
            }
        }

        if ($total <= 0) {
            return ServerGfwCheck::STATUS_FAILED;
        }

        $timeoutRatio = ($total - $success) / $total;
        if ($success === 0 || $timeoutRatio >= 0.95) {
            return ServerGfwCheck::STATUS_BLOCKED;
        }
        if ($reachableOperators >= 3 && $timeoutRatio <= 0.8) {
            return ServerGfwCheck::STATUS_NORMAL;
        }
        return ServerGfwCheck::STATUS_PARTIAL;
    }

    private function buildSummary(?array $operators, string $status): array
    {
        if (!$operators) {
            return ['status' => $status];
        }

        $total = array_sum(array_map(fn ($item) => (int) ($item['total'] ?? 0), $operators));
        $success = array_sum(array_map(fn ($item) => (int) ($item['success'] ?? 0), $operators));

        return [
            'status' => $status,
            'total' => $total,
            'success' => $success,
            'timeout' => max(0, $total - $success),
            'timeout_ratio' => $total > 0 ? round(($total - $success) / $total, 4) : null,
        ];
    }

    private function arrayOrNull($value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function defaultTargets(): array
    {
        return [
            'ct' => [
                ['name' => '北京电信', 'host' => 'v4-bj-ct.oojj.de'],
                ['name' => '上海电信', 'host' => '61.170.82.99'],
                ['name' => '江苏电信', 'host' => 'v4-js-ct.oojj.de'],
                ['name' => '广东电信', 'host' => 'gd-ct-v4.ip.zstaticcdn.com'],
                ['name' => '四川电信', 'host' => 'sc-ct-v4.ip.zstaticcdn.com'],
                ['name' => '重庆电信', 'host' => 'cq-ct-v4.ip.zstaticcdn.com'],
            ],
            'cu' => [
                ['name' => '北京联通', 'host' => 'v4-bj-cu.oojj.de'],
                ['name' => '上海联通', 'host' => 'sh-cu-v4.ip.zstaticcdn.com'],
                ['name' => '江苏联通', 'host' => 'js-cu-v4.ip.zstaticcdn.com'],
                ['name' => '广东联通', 'host' => 'gd-cu-v4.ip.zstaticcdn.com'],
                ['name' => '云南联通', 'host' => '14.205.93.189'],
                ['name' => '重庆联通', 'host' => 'cq-cu-v4.ip.zstaticcdn.com'],
            ],
            'cm' => [
                ['name' => '北京移动', 'host' => 'bj-cm-v4.ip.zstaticcdn.com'],
                ['name' => '上海移动', 'host' => 'sh-cm-v4.ip.zstaticcdn.com'],
                ['name' => '山东移动', 'host' => '218.201.96.130'],
                ['name' => '广东移动', 'host' => '211.136.192.6'],
                ['name' => '四川移动', 'host' => '183.221.253.100'],
                ['name' => '重庆移动', 'host' => 'cq-cm-v4.ip.zstaticcdn.com'],
            ],
        ];
    }
}
