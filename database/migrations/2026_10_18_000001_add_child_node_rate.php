<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table): void {
            $table->boolean('child_rate_enabled')->default(false);
        });
        Schema::create('v2_server_child_identity', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('parent_id');
            $table->unsignedInteger('child_id');
            $table->unsignedInteger('user_id');
            $table->unique(['parent_id', 'child_id', 'user_id'], 'child_identity_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_child_identity');
        Schema::table('v2_server', function (Blueprint $table): void {
            $table->dropColumn('child_rate_enabled');
        });
    }
};
