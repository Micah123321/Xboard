<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server', 'auto_online_cooldown_enabled')) {
                $table->boolean('auto_online_cooldown_enabled')
                    ->default(false)
                    ->after('auto_online')
                    ->comment('Suspend auto online after frequent reconnect flapping');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server', 'auto_online_cooldown_enabled')) {
                $table->dropColumn('auto_online_cooldown_enabled');
            }
        });
    }
};
