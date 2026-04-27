<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server', 'gfw_check_enabled')) {
                $table->boolean('gfw_check_enabled')
                    ->default(true)
                    ->after('auto_online')
                    ->comment('Automatically run GFW checks and visibility actions');
            }

            if (!Schema::hasColumn('v2_server', 'gfw_auto_hidden')) {
                $table->boolean('gfw_auto_hidden')
                    ->default(false)
                    ->after('gfw_check_enabled')
                    ->comment('Hidden by automated GFW check action');
            }

            if (!Schema::hasColumn('v2_server', 'gfw_auto_action_at')) {
                $table->unsignedInteger('gfw_auto_action_at')
                    ->nullable()
                    ->after('gfw_auto_hidden')
                    ->comment('Last automated GFW visibility action time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server', 'gfw_auto_action_at')) {
                $table->dropColumn('gfw_auto_action_at');
            }

            if (Schema::hasColumn('v2_server', 'gfw_auto_hidden')) {
                $table->dropColumn('gfw_auto_hidden');
            }

            if (Schema::hasColumn('v2_server', 'gfw_check_enabled')) {
                $table->dropColumn('gfw_check_enabled');
            }
        });
    }
};
