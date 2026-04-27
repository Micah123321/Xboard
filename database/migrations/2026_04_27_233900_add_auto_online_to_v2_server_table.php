<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server', 'auto_online')) {
                $table->boolean('auto_online')
                    ->default(false)
                    ->after('show')
                    ->comment('Automatically sync show status from node online state');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server', 'auto_online')) {
                $table->dropColumn('auto_online');
            }
        });
    }
};
