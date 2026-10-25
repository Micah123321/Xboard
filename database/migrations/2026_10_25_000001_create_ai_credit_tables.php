<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            $table->unsignedInteger('ai_credit_evaluated_at')->nullable();
        });
        Schema::create('v2_ai_credit_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('instance_id', 36)->unique();
            $table->longText('settings');
            $table->text('bridge_token')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_ai_credit_grants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('legacy_user_id')->nullable()->unique();
            $table->string('external_ref', 100)->unique();
            $table->string('trade_no', 64);
            $table->unsignedBigInteger('plan_id');
            $table->string('plan_name');
            $table->unsignedBigInteger('traffic_gb');
            $table->unsignedBigInteger('paid_amount_cents');
            $table->unsignedBigInteger('amount_usd_cents');
            $table->longText('policy_snapshot');
            $table->string('source', 16);
            $table->string('status', 24)->default('eligible');
            $table->string('bridge_url', 2048)->nullable();
            $table->string('upstream_id', 64)->nullable();
            $table->text('code')->nullable();
            $table->string('lease_token', 36)->nullable();
            $table->unsignedInteger('lease_until')->nullable();
            $table->unsignedInteger('next_attempt_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->unsignedInteger('issued_at')->nullable();
            $table->unsignedInteger('redeemed_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ai_credit_grants');
        Schema::dropIfExists('v2_ai_credit_settings');
        Schema::table('v2_order', fn (Blueprint $table) => $table->dropColumn('ai_credit_evaluated_at'));
    }
};
