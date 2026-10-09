<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_update_discoveries', function (Blueprint $table) {
            $table->unsignedInteger('node_id')->primary();
            $table->uuid('installation_id');
            $table->string('version', 128);
            $table->string('os', 32);
            $table->string('arch', 32);
            $table->dateTime('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_update_discoveries');
    }
};
