<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // MySQL commits each DDL separately; resume after a partial migration.
        if (!Schema::hasIndex('v2_access_report', 'access_pending_capture_node')) {
            Schema::table('v2_access_report', function (Blueprint $table): void {
                $table->index(['processed_at', 'observed_at', 'node_id'], 'access_pending_capture_node');
            });
        }
        if (!Schema::hasIndex('v2_traffic_report', 'traffic_received_node_report')) {
            Schema::table('v2_traffic_report', function (Blueprint $table): void {
                $table->index(['received_at', 'node_id', 'report_id'], 'traffic_received_node_report');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('v2_access_report', 'access_pending_capture_node')) {
            Schema::table('v2_access_report', fn (Blueprint $table) => $table->dropIndex('access_pending_capture_node'));
        }
        if (Schema::hasIndex('v2_traffic_report', 'traffic_received_node_report')) {
            Schema::table('v2_traffic_report', fn (Blueprint $table) => $table->dropIndex('traffic_received_node_report'));
        }
    }
};
