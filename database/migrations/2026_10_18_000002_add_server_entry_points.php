<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['entry_points_enabled', 'entry_points'] as $column) {
            if (Schema::hasColumn('v2_server', $column)) continue;
            Schema::table('v2_server', function (Blueprint $table) use ($column): void {
                if ($column === 'entry_points_enabled') $table->boolean($column)->default(false);
                else $table->json($column)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['entry_points', 'entry_points_enabled'] as $column) {
            if (!Schema::hasColumn('v2_server', $column)) continue;
            Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
