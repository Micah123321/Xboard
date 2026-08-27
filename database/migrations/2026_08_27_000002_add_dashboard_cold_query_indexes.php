<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const INDEXES = [
        ['v2_stat_server', ['record_type', 'record_at', 'server_id'], 'idx_stat_server_type_record_server'],
        ['v2_stat_user', ['record_type', 'record_at', 'user_id'], 'idx_stat_user_type_record_user'],
        ['v2_order', ['created_at', 'status'], 'idx_order_created_status'],
        ['v2_order', ['commission_status', 'status', 'commission_balance'], 'idx_order_commission_dashboard'],
        ['v2_commission_log', ['created_at', 'get_amount'], 'idx_commission_created_amount'],
        ['server_gfw_checks', ['server_id', 'status', 'id'], 'idx_gfw_server_status_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $columns, $indexName]) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $this->addIndexIfNotExists($table, $columns, $indexName);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEXES) as [$table, , $indexName]) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $this->dropIndexIfExists($table, $indexName);
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
