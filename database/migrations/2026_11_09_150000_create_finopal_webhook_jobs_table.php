<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finopal_webhook_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('endpoint', 40)->index();
            $table->string('queue', 64)->default('finopal-webhook')->index();
            $table->string('status', 24)->default('processing')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('success')->nullable()->index();
            $table->string('national_id', 20)->nullable()->index();
            $table->string('mobile', 20)->nullable()->index();
            $table->string('referral_code', 120)->nullable();
            $table->string('idempotency_key', 190)->nullable()->index();
            $table->string('message', 500)->nullable();
            $table->json('request_summary')->nullable();
            $table->json('response_summary')->nullable();
            $table->json('errors')->nullable();
            $table->text('exception')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finopal_webhook_jobs');
    }
};
