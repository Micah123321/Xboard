<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $p = 'v2_node_update_';
        Schema::create($p.'settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('max_concurrency')->default(5);
            $t->unsignedBigInteger('revision')->default(1);
            $t->timestamps();
        });
        DB::table($p.'settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create($p.'releases', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->string('version', 64)->unique();
            $t->string('os', 64);
            $t->unsignedBigInteger('min_agent_protocol');
            $t->json('artifacts');
            $t->dateTime('published_at');
            $t->dateTime('revoked_at')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
        Schema::create($p.'installations', function (Blueprint $t) use ($p) {
            $t->char('id', 36)->primary();
            $t->json('scope');
            $t->char('token_hash', 64)->unique();
            $t->char('credential_hash', 64);
            $t->dateTime('revoked_at')->nullable();
            $t->boolean('policy_enabled')->default(false);
            $t->char('target_release_id', 36)->nullable();
            $t->foreign('target_release_id')->references('id')->on($p.'releases')->restrictOnDelete();
            $t->unsignedBigInteger('policy_revision')->default(1);
            foreach (['capability','runtime','versions','installed_sha256'] as $field) $t->json($field);
            $t->dateTime('last_seen_at')->nullable();
            $t->char('active_task_id', 36)->nullable()->unique();
            $t->json('reported_active_attempt')->nullable();
            $t->timestamps();
        });
        Schema::create($p.'enrollments', function (Blueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('secret_hash', 64)->unique();
            $t->json('scope');
            $t->dateTime('expires_at');
            $t->dateTime('consumed_at')->nullable();
            $t->char('installation_id', 36)->nullable();
            $t->char('token_hash', 64)->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
        Schema::create($p.'batches', function (Blueprint $t) use ($p) {
            $t->char('id', 36)->primary();
            $t->char('release_id', 36);
            $t->foreign('release_id')->references('id')->on($p.'releases')->restrictOnDelete();
            $t->string('state',64);
            $t->unsignedInteger('max_concurrency')->default(5);
            $t->unsignedInteger('fail_pause_after')->default(1);
            $t->unsignedBigInteger('failure_baseline')->default(0);
            $t->boolean('cancel_requested')->default(false);
            $t->unsignedBigInteger('created_by');
            $t->dateTime('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create($p.'tasks', function (Blueprint $t) use ($p) {
            $t->char('id', 36)->primary();
            $t->char('batch_id', 36);
            $t->foreign('batch_id')->references('id')->on($p.'batches')->restrictOnDelete();
            $t->char('installation_id', 36);
            $t->foreign('installation_id')->references('id')->on($p.'installations')->restrictOnDelete();
            $t->json('release_snapshot');
            $t->unsignedBigInteger('policy_revision');
            $t->string('state',64)->index();
            $t->string('last_phase',64)->nullable();
            $t->boolean('cancel_requested')->default(false);
            $t->string('code',64)->nullable();
            $t->string('message',1024)->nullable();
            $t->dateTime('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['batch_id','installation_id'], 'nu_task_batch_install_unique');
        });
        Schema::create($p.'attempts', function (Blueprint $t) use ($p) {
            $t->char('id', 36)->primary();
            $t->char('task_id', 36)->unique();
            $t->foreign('task_id')->references('id')->on($p.'tasks')->restrictOnDelete();
            $t->char('installation_id', 36);
            $t->foreign('installation_id')->references('id')->on($p.'installations')->restrictOnDelete();
            $t->char('claim_id', 36);
            $t->char('claim_request_hash',64);
            $t->char('lease_token_hash',64);
            $t->text('lease_token_ciphertext');
            $t->text('claim_response_ciphertext');
            $t->dateTime('lease_expires_at')->nullable()->index();
            $t->unsignedBigInteger('last_seq')->default(0);
            $t->dateTime('installing_acked_at')->nullable();
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['installation_id','claim_id'], 'nu_attempt_install_claim_unique');
        });
        Schema::create($p.'events', function (Blueprint $t) use ($p) {
            $t->bigIncrements('id');
            $t->char('attempt_id', 36)->nullable();
            $t->foreign('attempt_id')->references('id')->on($p.'attempts')->restrictOnDelete();
            $t->char('task_id', 36);
            $t->foreign('task_id')->references('id')->on($p.'tasks')->restrictOnDelete();
            $t->unsignedBigInteger('seq')->nullable();
            $t->char('payload_hash',64);
            $t->json('payload');
            $t->json('response')->nullable();
            $t->string('source',64);
            $t->dateTime('received_at');
            $t->timestamps();
            $t->unique(['attempt_id','seq'], 'nu_event_attempt_seq_unique');
        });
        Schema::create($p.'requests', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('admin_id');
            $t->char('key', 36);
            $t->string('route',255);
            $t->char('request_hash',64);
            $t->json('response');
            $t->json('request_payload');
            $t->timestamps();
            $t->unique(['admin_id','key'], 'nu_request_admin_key_unique');
        });
    }

    public function down(): void
    {
        foreach (['requests','events','attempts','tasks','batches','enrollments','installations','releases','settings'] as $table) {
            Schema::dropIfExists('v2_node_update_'.$table);
        }
    }
};
