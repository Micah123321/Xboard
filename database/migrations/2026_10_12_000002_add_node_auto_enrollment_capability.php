<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_node_update_discoveries', function (Blueprint $table) {
            $table->boolean('auto_enroll_supported')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('v2_node_update_discoveries', fn (Blueprint $table) => $table->dropColumn('auto_enroll_supported'));
    }
};
