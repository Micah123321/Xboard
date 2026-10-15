<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table): void {
            if (!Schema::hasColumn('v2_server', 'plan_restriction_enabled')) {
                $table->boolean('plan_restriction_enabled')->default(false);
            }
            if (!Schema::hasColumn('v2_server', 'allowed_plan_ids')) {
                $table->json('allowed_plan_ids')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table): void {
            if (Schema::hasColumn('v2_server', 'allowed_plan_ids')) {
                $table->dropColumn('allowed_plan_ids');
            }
            if (Schema::hasColumn('v2_server', 'plan_restriction_enabled')) {
                $table->dropColumn('plan_restriction_enabled');
            }
        });
    }
};
