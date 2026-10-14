<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_traffic_report', function (Blueprint $table): void {
            $table->longText('billing_snapshot')->nullable();
            $table->unsignedBigInteger('billed_at')->nullable();
            $table->index('received_at', 'traffic_report_received_at');
        });
        Schema::create('v2_access_report', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('node_id');
            $table->string('report_id', 66);
            $table->string('billing_report_id', 66);
            $table->string('node_name', 255)->default('');
            $table->string('payload_hash', 64);
            $table->string('totals_hash', 64);
            $table->longText('payload')->nullable();
            $table->string('kernel', 32);
            $table->string('coverage', 16);
            $table->unsignedBigInteger('dropped_entries')->default(0);
            $table->unsignedInteger('bucket_seconds');
            $table->unsignedBigInteger('observed_at')->index();
            $table->unsignedBigInteger('received_at')->index();
            $table->unsignedBigInteger('processed_at')->nullable()->index();
            $table->unsignedBigInteger('settled_at')->nullable()->index();
            $table->string('settlement_error', 64)->nullable();
            $table->unique(['node_id', 'report_id']);
            $table->unique(['node_id', 'billing_report_id']);
        });
        Schema::create('v2_access_traffic', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('node_id');
            $table->string('domain', 253)->default('');
            $table->string('ip', 45)->default('');
            $table->unsignedSmallInteger('port')->default(0);
            $table->string('network', 16);
            $table->string('domain_source', 16);
            $table->unsignedBigInteger('bucket_at');
            $table->unsignedBigInteger('first_seen');
            $table->unsignedBigInteger('last_seen');
            $table->unsignedBigInteger('raw_u');
            $table->unsignedBigInteger('raw_d');
            $table->unsignedBigInteger('billed_u')->nullable();
            $table->unsignedBigInteger('billed_d')->nullable();
            $table->double('rate')->nullable();
            $table->unsignedBigInteger('connections')->default(0);
            $table->string('service', 64);
            $table->string('category', 16);
            $table->string('confidence', 16);
            $table->string('reason', 255);
            $table->foreign('report_id')->references('id')->on('v2_access_report')->cascadeOnDelete();
            $table->index(['bucket_at', 'node_id', 'user_id'], 'access_time_node_user');
            $table->index(['user_id', 'bucket_at']);
            $table->index(['node_id', 'bucket_at']);
            $table->index(['category', 'bucket_at']);
            $table->index(['last_seen', 'first_seen'], 'access_capture_interval');
            $table->index(['user_id', 'last_seen']);
            $table->index(['node_id', 'last_seen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_access_traffic');
        Schema::dropIfExists('v2_access_report');
        Schema::table('v2_traffic_report', function (Blueprint $table): void {
            $table->dropIndex('traffic_report_received_at');
            $table->dropColumn(['billing_snapshot', 'billed_at']);
        });
    }
};
