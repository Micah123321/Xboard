<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_notice', 'popup')) {
            Schema::table('v2_notice', function (Blueprint $table) {
                $table->boolean('popup')->default(false)->after('show');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_notice', 'popup')) {
            Schema::table('v2_notice', function (Blueprint $table) {
                $table->dropColumn('popup');
            });
        }
    }
};
