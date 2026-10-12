<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_node_update_installations', function (Blueprint $table) {
            $table->boolean('follow_latest')->default(false);
        });
        Schema::table('v2_node_update_batches', function (Blueprint $table) {
            $table->boolean('manual')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('v2_node_update_batches', fn (Blueprint $table) => $table->dropColumn('manual'));
        Schema::table('v2_node_update_installations', fn (Blueprint $table) => $table->dropColumn('follow_latest'));
    }
};
