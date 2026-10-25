<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_marketing_activities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 64)->nullable()->unique();
            $table->string('source', 16)->default('custom');
            $table->string('title', 80);
            $table->string('badge', 30)->default('');
            $table->string('summary', 240);
            $table->json('benefits');
            $table->string('image_url', 2048);
            $table->string('target_url', 2048);
            $table->string('button_text', 30);
            $table->string('theme', 16)->default('navy');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('published')->default(false);
            $table->unsignedBigInteger('starts_at')->nullable();
            $table->unsignedBigInteger('ends_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->index(['published', 'sort', 'id'], 'marketing_activities_feed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_marketing_activities');
    }
};
