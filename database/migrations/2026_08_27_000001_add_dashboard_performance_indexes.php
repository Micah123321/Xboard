<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const STAT_SERVER_TABLE = 'v2_stat_server';
    private const GFW_CHECK_TABLE = 'server_gfw_checks';
    private const STAT_SERVER_RECORD_SERVER_INDEX = 'idx_stat_server_record_server';
    private const GFW_STATUS_SERVER_ID_INDEX = 'idx_gfw_status_server_id';

    public function up(): void
    {
        if (Schema::hasTable(self::STAT_SERVER_TABLE)) {
            $this->addIndexIfNotExists(
                self::STAT_SERVER_TABLE,
                ['record_at', 'server_id'],
                self::STAT_SERVER_RECORD_SERVER_INDEX
            );
        }

        if (Schema::hasTable(self::GFW_CHECK_TABLE)) {
            $this->addIndexIfNotExists(
                self::GFW_CHECK_TABLE,
                ['status', 'server_id', 'id'],
                self::GFW_STATUS_SERVER_ID_INDEX
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::GFW_CHECK_TABLE)) {
            $this->dropIndexIfExists(self::GFW_CHECK_TABLE, self::GFW_STATUS_SERVER_ID_INDEX);
        }

        if (Schema::hasTable(self::STAT_SERVER_TABLE)) {
            $this->dropIndexIfExists(self::STAT_SERVER_TABLE, self::STAT_SERVER_RECORD_SERVER_INDEX);
        }
    }

    private function addIndexIfNotExists(string $table, array $columns, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        return match ($driver) {
            'mysql' => DB::table('information_schema.statistics')
                ->where('table_schema', $connection->getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists(),
            'pgsql' => DB::table('pg_indexes')
                ->whereRaw('schemaname = current_schema()')
                ->where('tablename', $table)
                ->where('indexname', $indexName)
                ->exists(),
            'sqlite' => collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($index): bool => isset($index->name) && $index->name === $indexName),
            default => false,
        };
    }
};
