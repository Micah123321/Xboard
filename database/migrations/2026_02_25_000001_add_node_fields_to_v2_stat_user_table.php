<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        Schema::table(self::TABLE, function (Blueprint $table) {
            try {
                $table->dropUnique(self::OLD_UNIQUE_INDEX);
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex(self::OLD_COMPOSITE_INDEX);
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex(['user_id', 'server_rate', 'record_at']);
            } catch (\Throwable) {
            }
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            try {
                $table->unique(
                    ['user_id', 'server_rate', 'server_id', 'server_type', 'record_at'],
                    self::NEW_UNIQUE_INDEX
                );
            } catch (\Throwable) {
            }

            try {
                $table->index(
                    ['user_id', 'server_id', 'server_type', 'record_at'],
                    self::NEW_COMPOSITE_INDEX
                );
            } catch (\Throwable) {
            }

            try {
                $table->index('server_id', self::NEW_SERVER_ID_INDEX);
            } catch (\Throwable) {
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            try {
                $table->dropUnique(self::NEW_UNIQUE_INDEX);
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex(self::NEW_COMPOSITE_INDEX);
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex(self::NEW_SERVER_ID_INDEX);
            } catch (\Throwable) {
            }
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            try {
                $table->unique(['server_rate', 'user_id', 'record_at'], self::OLD_UNIQUE_INDEX);
            } catch (\Throwable) {
            }

            try {
                $table->index(['user_id', 'server_rate', 'record_at']);
            } catch (\Throwable) {
            }
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (Schema::hasColumn(self::TABLE, 'server_type')) {
                $table->dropColumn('server_type');
            }

            if (Schema::hasColumn(self::TABLE, 'server_id')) {
                $table->dropColumn('server_id');
            }
        });
    }
};
