<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server', 'parent_auto_hidden')) {
                $table->boolean('parent_auto_hidden')
                    ->default(false)
                    ->after('gfw_auto_action_at')
                    ->comment('Hidden automatically because parent node is unavailable');
            }

            if (!Schema::hasColumn('v2_server', 'parent_auto_action_at')) {
                $table->unsignedBigInteger('parent_auto_action_at')
                    ->nullable()
                    ->after('parent_auto_hidden')
                    ->comment('Last parent visibility sync action timestamp');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            foreach (['parent_auto_hidden', 'parent_auto_action_at'] as $column) {
                if (Schema::hasColumn('v2_server', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
