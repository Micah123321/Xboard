<?php

namespace App\Console\Commands;

use App\Services\AccessTrafficDomainDatabase;
use Illuminate\Console\Command;

class SyncAccessTrafficDomains extends Command
{
    protected $signature = 'access-traffic:sync-domains {--status : Display the active local database without downloading}';
    protected $description = 'Update the local GeoSite service domain index atomically';

    public function handle(AccessTrafficDomainDatabase $database): int
    {
        try {
            $result = $this->option('status') ? $database->status() : $database->update();
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('GeoSite update failed; previous database retained: ' . $error->getMessage());
            return self::FAILURE;
        }
    }
}
