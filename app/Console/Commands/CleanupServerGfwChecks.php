<?php

namespace App\Console\Commands;

use App\Models\ServerGfwCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CleanupServerGfwChecks extends Command
{
    protected $signature = 'cleanup:server-gfw-checks
        {--days=3 : Keep complete check details for this many recent days}
        {--active-hours=6 : Mark pending/checking tasks older than this many hours as failed}
        {--chunk=1000 : Delete rows in batches of this size}
        {--dry-run : Report how many rows would be changed without writing}';

    protected $description = 'Compact historical server GFW check rows while preserving status transitions';

    private const ACTIVE_STATUSES = [
        ServerGfwCheck::STATUS_PENDING,
        ServerGfwCheck::STATUS_CHECKING,
    ];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $activeHours = max(1, (int) $this->option('active-hours'));
        $chunkSize = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $keepFullSince = now()->subDays($days)->timestamp;
        $staleActiveBefore = now()->subHours($activeHours);

        $staleActiveQuery = ServerGfwCheck::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('updated_at', '<', $staleActiveBefore);
        $staleActiveCount = (clone $staleActiveQuery)->count();

        if (!$dryRun && $staleActiveCount > 0) {
            $staleActiveQuery->update([
                'status' => ServerGfwCheck::STATUS_FAILED,
                'error_message' => '墙检测任务超时：节点端未领取或未上报结果',
                'checked_at' => time(),
            ]);
        }

        $serverIds = ServerGfwCheck::query()
            ->distinct()
            ->orderBy('server_id')
            ->pluck('server_id');
        $latestFinalIds = ServerGfwCheck::query()
            ->whereIn('status', ServerGfwCheck::FINAL_STATUSES)
            ->selectRaw('MAX(id) as id')
            ->groupBy('server_id')
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();

        $scanned = 0;
        $kept = 0;
        $deleted = 0;

        foreach ($serverIds as $serverId) {
            $previousStatus = null;
            $deleteIds = [];

            ServerGfwCheck::query()
                ->where('server_id', (int) $serverId)
                ->whereIn('status', ServerGfwCheck::FINAL_STATUSES)
                ->select(['id', 'server_id', 'status', 'triggered_by', 'checked_at', 'created_at', 'updated_at'])
                ->chunkById($chunkSize, function ($checks) use (&$previousStatus, &$deleteIds, &$scanned, &$kept, &$deleted, $keepFullSince, $latestFinalIds, $chunkSize, $dryRun): void {
                    foreach ($checks as $check) {
                        $scanned++;
                        $isRecent = $this->resolveCheckTimestamp($check) >= $keepFullSince;
                        $isManual = $check->triggered_by !== null;
                        $isTransition = $previousStatus === null || $check->status !== $previousStatus;
                        $isLatestFinal = isset($latestFinalIds[(int) $check->id]);

                        if ($isRecent || $isManual || $isTransition || $isLatestFinal) {
                            $kept++;
                        } else {
                            $deleteIds[] = (int) $check->id;
                        }

                        $previousStatus = $check->status;

                        if (count($deleteIds) >= $chunkSize) {
                            $deleted += $this->deleteRows($deleteIds, $dryRun);
                            $deleteIds = [];
                        }
                    }
                });

            if (!empty($deleteIds)) {
                $deleted += $this->deleteRows($deleteIds, $dryRun);
            }
        }

        $this->info(sprintf(
            'Server GFW checks cleanup %s: scanned=%d kept=%d deleted=%d stale_active=%d keep_days=%d',
            $dryRun ? 'dry-run' : 'done',
            $scanned,
            $kept,
            $deleted,
            $staleActiveCount,
            $days
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<int, int> $ids
     */
    private function deleteRows(array $ids, bool $dryRun): int
    {
        if (empty($ids)) {
            return 0;
        }

        if (!$dryRun) {
            ServerGfwCheck::query()->whereIn('id', $ids)->delete();
        }

        return count($ids);
    }

    private function resolveCheckTimestamp(ServerGfwCheck $check): int
    {
        $checkedAt = (int) ($check->checked_at ?: 0);
        if ($checkedAt > 0) {
            return $checkedAt;
        }

        $createdAt = $check->created_at;
        if ($createdAt instanceof Carbon) {
            return $createdAt->timestamp;
        }

        $updatedAt = $check->updated_at;
        if ($updatedAt instanceof Carbon) {
            return $updatedAt->timestamp;
        }

        return now()->timestamp;
    }
}
