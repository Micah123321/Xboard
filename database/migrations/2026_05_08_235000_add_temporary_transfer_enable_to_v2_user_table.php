<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'temporary_transfer_enable')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->bigInteger('temporary_transfer_enable')
                    ->default(0)
                    ->after('transfer_enable')
                    ->comment('管理员分配的一次性临时流量，单位字节');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_user', 'temporary_transfer_enable')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('temporary_transfer_enable');
            });
        }
    }
};
