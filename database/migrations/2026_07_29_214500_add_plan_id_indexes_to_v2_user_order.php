<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->index('plan_id', 'v2_user_plan_id_index');
        });

        Schema::table('v2_order', function (Blueprint $table) {
            $table->index('plan_id', 'v2_order_plan_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex('v2_user_plan_id_index');
        });

        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropIndex('v2_order_plan_id_index');
        });
    }
};
