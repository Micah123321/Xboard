<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server', 'tcp_check_enabled')) {
                $table->boolean('tcp_check_enabled')
                    ->default(false)
                    ->after('gfw_check_enabled')
                    ->comment('Enable TCP port reachability check for child nodes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server', 'tcp_check_enabled')) {
                $table->dropColumn('tcp_check_enabled');
            }
        });
    }
};
