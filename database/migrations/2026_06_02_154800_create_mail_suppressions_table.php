<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_mail_suppressions')) {
            Schema::create('v2_mail_suppressions', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('email', 191)->unique();
                $table->string('reason', 64);
                $table->string('source', 32)->default('manual');
                $table->string('diagnostic_code', 32)->nullable();
                $table->text('raw_excerpt')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_mail_suppressions');
    }
};
