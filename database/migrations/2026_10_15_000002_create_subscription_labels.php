<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_subscription_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->json('plan_ids');
            $table->timestamps();
        });
        Schema::table('v2_server', function (Blueprint $table): void {
            // Restrict deletion so removing a label never silently opens its nodes to all plans.
            $table->foreignId('subscription_label_id')->nullable()->constrained('v2_subscription_labels')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table): void {
            $table->dropForeign(['subscription_label_id']);
            $table->dropColumn('subscription_label_id');
        });
        Schema::dropIfExists('v2_subscription_labels');
    }
};
