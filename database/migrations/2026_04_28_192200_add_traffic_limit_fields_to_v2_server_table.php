<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server', 'traffic_limit_enabled')) {
                $table->boolean('traffic_limit_enabled')
                    ->default(false)
                    ->after('transfer_enable')
                    ->comment('Enable node traffic limit enforcement');
            }
            if (!Schema::hasColumn('v2_server', 'traffic_limit_reset_day')) {
                $table->unsignedTinyInteger('traffic_limit_reset_day')
                    ->nullable()
                    ->after('traffic_limit_enabled')
                    ->comment('Monthly reset day, 1-31');
            }
            if (!Schema::hasColumn('v2_server', 'traffic_limit_reset_time')) {
                $table->string('traffic_limit_reset_time', 5)
                    ->nullable()
                    ->after('traffic_limit_reset_day')
                    ->comment('Monthly reset time in HH:mm');
            }
            if (!Schema::hasColumn('v2_server', 'traffic_limit_timezone')) {
                $table->string('traffic_limit_timezone', 64)
                    ->nullable()
                    ->after('traffic_limit_reset_time')
                    ->comment('Timezone used for node traffic reset');
            }
            if (!Schema::hasColumn('v2_server', 'traffic_limit_status')) {
                $table->string('traffic_limit_status', 32)
                    ->nullable()
                    ->after('traffic_limit_timezone')
                    ->comment('Runtime status reported by node traffic limiter');
            }
            if (!Schema::hasColumn('v2_server', 'traffic_limit_last_reset_at')) {
                $table->unsignedBigInteger('traffic_limit_last_reset_at')
                    ->nullable()
                    ->after('traffic_limit_status')
                    ->comment('Last node traffic reset timestamp');
            }
            if (!Schema::hasColumn('v2_server', 'traffic_limit_next_reset_at')) {
                $table->unsignedBigInteger('traffic_limit_next_reset_at')
                    ->nullable()
                    ->after('traffic_limit_last_reset_at')
                    ->comment('Next node traffic reset timestamp');
            }
            if (!Schema::hasColumn('v2_server', 'traffic_limit_suspended_at')) {
                $table->unsignedBigInteger('traffic_limit_suspended_at')
                    ->nullable()
                    ->after('traffic_limit_next_reset_at')
                    ->comment('Timestamp when node was suspended by traffic limit');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $columns = [
                'traffic_limit_enabled',
                'traffic_limit_reset_day',
                'traffic_limit_reset_time',
                'traffic_limit_timezone',
                'traffic_limit_status',
                'traffic_limit_last_reset_at',
                'traffic_limit_next_reset_at',
                'traffic_limit_suspended_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('v2_server', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
