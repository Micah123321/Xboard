<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'v2_stat_user';
    private const OLD_UNIQUE_INDEX = 'server_rate_user_id_record_at';
    private const OLD_COMPOSITE_INDEX = 'v2_stat_user_user_id_server_rate_record_at_index';
    private const NEW_UNIQUE_INDEX = 'stat_user_user_rate_server_record_at_unique';
    private const NEW_COMPOSITE_INDEX = 'stat_user_user_server_record_at_index';
    private const NEW_SERVER_ID_INDEX = 'stat_user_server_id_index';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            if (!Schema::hasColumn(self::TABLE, 'server_id')) {
                $table->unsignedInteger('server_id')->default(0)->comment('Node ID');
            }

            if (!Schema::hasColumn(self::TABLE, 'server_type')) {
                $table->string('server_type', 32)->default('')->comment('Node type');
            }
        });

        $this->dropUniqueIfExists(self::TABLE, self::OLD_UNIQUE_INDEX);
        $this->dropIndexIfExists(self::TABLE, self::OLD_COMPOSITE_INDEX);

        $this->addUniqueIfNotExists(
            self::TABLE,
            ['user_id', 'server_rate', 'server_id', 'server_type', 'record_at'],
            self::NEW_UNIQUE_INDEX
        );
        $this->addIndexIfNotExists(
            self::TABLE,
            ['user_id', 'server_id', 'server_type', 'record_at'],
            self::NEW_COMPOSITE_INDEX
        );
        $this->addIndexIfNotExists(self::TABLE, ['server_id'], self::NEW_SERVER_ID_INDEX);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropUniqueIfExists(self::TABLE, self::NEW_UNIQUE_INDEX);
        $this->dropIndexIfExists(self::TABLE, self::NEW_COMPOSITE_INDEX);
        $this->dropIndexIfExists(self::TABLE, self::NEW_SERVER_ID_INDEX);

        $this->addUniqueIfNotExists(self::TABLE, ['server_rate', 'user_id', 'record_at'], self::OLD_UNIQUE_INDEX);
        $this->addIndexIfNotExists(self::TABLE, ['user_id', 'server_rate', 'record_at'], self::OLD_COMPOSITE_INDEX);

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (Schema::hasColumn(self::TABLE, 'server_type')) {
                $table->dropColumn('server_type');
            }

            if (Schema::hasColumn(self::TABLE, 'server_id')) {
                $table->dropColumn('server_id');
            }
        });
    }

    private function addUniqueIfNotExists(string $table, array $columns, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->unique($columns, $indexName);
        });
    }

    private function addIndexIfNotExists(string $table, array $columns, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropUniqueIfExists(string $table, string $indexName): void
    {
        if (!$this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropUnique($indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
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
                ->contains(fn($index) => isset($index->name) && $index->name === $indexName),
            default => false,
        };
    }
};
