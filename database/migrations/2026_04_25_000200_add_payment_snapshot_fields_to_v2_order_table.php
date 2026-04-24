<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            $table->string('payment_channel')->nullable()->after('payment_id');
            $table->string('payment_method')->nullable()->after('payment_channel');
            $table->integer('payment_amount')->nullable()->after('callback_no');
            $table->string('payment_ip', 64)->nullable()->after('payment_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropColumn([
                'payment_channel',
                'payment_method',
                'payment_amount',
                'payment_ip',
            ]);
        });
    }
};
