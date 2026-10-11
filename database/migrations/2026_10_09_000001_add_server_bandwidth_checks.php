<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->boolean('bandwidth_check_enabled')->default(false);
        });
        Schema::create('server_bandwidth_health', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 64)->unique();
            $table->string('status', 16)->default('unknown');
            $table->boolean('suspended')->default(false);
            $table->double('mbps')->nullable();
            $table->unsignedInteger('low_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('checked_at')->nullable();
            $table->unsignedInteger('next_check_at')->default(0);
            $table->text('error')->nullable();
            $table->string('policy_revision', 40)->nullable();
            $table->string('last_probe_id', 64)->nullable();
            $table->string('pending_probe_id', 64)->nullable();
            $table->unsignedInteger('probe_expires_at')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_bandwidth_health');
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn('bandwidth_check_enabled');
        });
    }
};
