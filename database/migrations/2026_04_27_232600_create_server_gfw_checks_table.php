<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('server_gfw_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->json('summary')->nullable();
            $table->json('operator_summary')->nullable();
            $table->json('raw_result')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('checked_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('v2_server')->cascadeOnDelete();
            $table->index(['server_id', 'created_at']);
            $table->index(['server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_gfw_checks');
    }
};
