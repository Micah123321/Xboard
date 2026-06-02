<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_mail_log', 'config')) {
            Schema::table('v2_mail_log', function (Blueprint $table) {
                $table->json('config')->nullable()->after('error');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_mail_log', 'config')) {
            Schema::table('v2_mail_log', function (Blueprint $table) {
                $table->dropColumn('config');
            });
        }
    }
};
