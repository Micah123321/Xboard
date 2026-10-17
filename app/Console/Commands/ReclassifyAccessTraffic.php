<?php

namespace App\Console\Commands;

use App\Services\AccessTrafficReclassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReclassifyAccessTraffic extends Command
{
    protected $signature = 'access-traffic:reclassify {--after-id=0 : Resume after this detail ID} {--chunk=500 : Rows per transaction}';
    protected $description = 'Refresh historic service classifications without changing raw or billed bytes';

    public function handle(AccessTrafficReclassifier $service): int
    {
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2000]]);
        if ($after === false || $chunk === false) { $this->error('Invalid after-id or chunk.'); return self::INVALID; }
        $through = (int) DB::table('v2_access_traffic')->max('id');
        $updated = 0;
        while ($after < $through) {
            $result = $service->chunk($after, $through, $chunk);
            $after = $result['last_id']; $updated += $result['updated'];
            $this->line('last_id=' . $after . ' updated=' . $updated);
        }
        return self::SUCCESS;
    }
}
