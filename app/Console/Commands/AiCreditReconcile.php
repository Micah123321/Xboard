<?php

namespace App\Console\Commands;

use App\Models\AiCreditGrant;
use App\Services\AiCredit\AiCreditService;
use Illuminate\Console\Command;

class AiCreditReconcile extends Command
{
    protected $signature = 'ai-credit:reconcile {--limit=20 : Maximum records to reconcile} {--dry-run : Show pending count without issuing codes}';
    protected $description = 'Recover interrupted AI credit requests using their permanent business references';

    public function handle(AiCreditService $service): int
    {
        if (!$service->available()) {
            $this->info('AI credit tables are not installed.');
            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) { $this->error('limit must be between 1 and 100'); return self::FAILURE; }
        $query = AiCreditGrant::whereIn('status', ['generating', 'reconciling'])
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', time()))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', time()));
        if ($this->option('dry-run')) { $this->info('Pending: ' . $query->count()); return self::SUCCESS; }
        foreach ($query->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            try { $service->reconcile((int) $id); }
            catch (\Throwable $e) {
                AiCreditGrant::whereKey($id)->whereIn('status', ['generating', 'reconciling'])->update([
                    'next_attempt_at' => time() + 300, 'updated_at' => time(),
                    'last_error' => '原请求配置待核对，请检查发码服务地址或凭据',
                ]);
                $this->warn('Record ' . $id . ' remains pending; check provider configuration.');
            }
        }
        // Poll issued records in oldest-checked order; no code values are printed.
        foreach (AiCreditGrant::where('status', 'issued')->orderBy('updated_at')->limit($limit)->pluck('id') as $id) {
            $service->reconcile((int) $id);
            AiCreditGrant::whereKey($id)->update(['updated_at' => time()]);
        }
        $this->info('AI credit reconciliation completed.');
        return self::SUCCESS;
    }
}
