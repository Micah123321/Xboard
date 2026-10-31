<?php

namespace App\Console\Commands;

use App\Services\Nya\NyaEntrySyncRunner;
use Illuminate\Console\Command;
use Throwable;

class SyncNyaEntryIps extends Command
{
    protected $signature = 'sync:nya-entry-ips
        {--dry-run : Preview changes without saving}
        {--server=* : Override the configured server IDs}
        {--port=* : Only manage these connection ports}
        {--scheduled : Skip when automatic sync is disabled}';

    protected $description = 'Sync selected node primary and extra entry IPs from NYA forwarding rules';

    public function handle(NyaEntrySyncRunner $runner): int
    {
        try {
            $run = $runner->run((bool) $this->option('dry-run'), $this->option('scheduled') ? 'schedule' : 'cli',
                $this->option('server') ?: null, $this->option('port') ?: null);
        } catch (Throwable) {
            $this->error('同步任务状态服务异常，请检查缓存服务。');
            return self::FAILURE;
        }
        if ($run === null) {
            $this->info('NYA automatic sync is disabled; skipped.');
            return self::SUCCESS;
        }
        if ($run['status'] === 'failed') {
            if (str_contains($run['error'], 'NYA_BUSY')) {
                $this->warn('An NYA entry sync is already running.');
                return self::SUCCESS;
            }
            $this->error($run['error']);
            return self::FAILURE;
        }
        $result = $run['result'];
        if ($result['changes']) {
            $this->table(['Server', 'Field', 'Port', 'Rule', 'Old IP / host', 'New IP', 'Account'],
                array_map(fn (array $change): array => [$change['server_id'], $change['field'], $change['port'],
                    $change['rule_id'], $change['from'], $change['to'], $change['account_name'] ?? '-'], $result['changes']));
        }
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }
        $this->info(sprintf('%s: servers=%d %s=%d entries_changed=%d unchanged=%d skipped=%d',
            $run['dry_run'] ? 'NYA entry preview' : 'NYA entries synced', $result['servers'],
            $run['dry_run'] ? 'would_update' : 'updated', $result['updated'], count($result['changes']),
            $result['unchanged'], $result['skipped']));
        return self::SUCCESS;
    }
}
