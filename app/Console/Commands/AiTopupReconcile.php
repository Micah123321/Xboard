<?php

namespace App\Console\Commands;

use App\Services\AiTopup\AiTopupService;
use Illuminate\Console\Command;

class AiTopupReconcile extends Command
{
    protected $signature = 'ai-topup:reconcile {--limit=20 : Maximum records to reconcile} {--dry-run : Count recoverable records without issuing codes}';
    protected $description = 'Recover paid AI top-up orders using their permanent provider references';

    public function handle(AiTopupService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) { $this->error('limit must be between 1 and 100'); return self::FAILURE; }
        if (!$service->available()) { $this->info('AI top-up tables are not installed.'); return self::SUCCESS; }
        $result = $service->reconcile($limit, (bool) $this->option('dry-run'));
        $this->info('Pending: ' . $result['pending'] . '; processed: ' . $result['processed']
            . '; issued: ' . $result['issued'] . '; reconciling: ' . $result['reconciling']);
        return self::SUCCESS;
    }
}
