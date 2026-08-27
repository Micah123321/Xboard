<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'v2_ticket';
    private const INDEX = 'idx_ticket_status_reply_updated';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || $this->indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['status', 'reply_status', 'updated_at'], self::INDEX);
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
