<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('server_bandwidth_health', function (Blueprint $table) {
            $table->string('probe_method', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_bandwidth_health', function (Blueprint $table) {
            $table->dropColumn('probe_method');
        });
    }
};
