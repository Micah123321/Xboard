<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_ai_topup_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('instance_id', 36)->unique();
            $table->longText('settings');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_ai_topup_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('client_request_id', 36);
            $table->string('trade_no', 64)->unique();
            $table->string('external_ref', 100)->nullable()->unique();
            $table->string('status', 16)->default('pending');
            $table->string('issue_status', 24)->default('pending');
            $table->unsignedInteger('amount_usd');
            $table->string('unit_price_cny', 16);
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('handling_amount')->default(0);
            $table->unsignedBigInteger('gift_traffic_bytes');
            $table->longText('policy_snapshot');
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->string('payment_method', 64)->nullable();
            $table->string('callback_no', 255)->nullable();
            $table->text('checkout_payload')->nullable();
            $table->string('checkout_token', 36)->nullable();
            $table->unsignedInteger('checkout_started_at')->nullable();
            $table->json('payment_receipts')->nullable();
            $table->string('bridge_url', 2048)->nullable();
            $table->text('bridge_token')->nullable();
            $table->string('upstream_id', 64)->nullable();
            $table->text('code')->nullable();
            $table->string('lease_token', 36)->nullable();
            $table->unsignedInteger('lease_until')->nullable();
            $table->unsignedInteger('next_attempt_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            foreach (['paid_at', 'cancelled_at', 'issued_at', 'redeemed_at', 'traffic_activated_at'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
            $table->unsignedInteger('expires_at');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->unique(['user_id', 'client_request_id'], 'ai_topup_user_request_unique');
            $table->index(['user_id', 'id']);
            $table->index(['status', 'issue_status', 'next_attempt_at'], 'ai_topup_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ai_topup_orders');
        Schema::dropIfExists('v2_ai_topup_settings');
    }
};
