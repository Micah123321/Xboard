<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_traffic_report', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('node_id');
            // Namespaced SHA-256 keys preserve case and separate HTTP and queue IDs.
            $table->string('report_id', 66);
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('received_at');
            $table->unique(['node_id', 'report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_traffic_report');
    }
};
