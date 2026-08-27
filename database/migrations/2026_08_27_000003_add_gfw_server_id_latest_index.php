<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'server_gfw_checks';
    private const INDEX = 'idx_gfw_server_id_id';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || $this->indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['server_id', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !$this->indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
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
