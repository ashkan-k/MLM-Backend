<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('visited_on');
            $table->string('place');
            $table->string('person_name');
            $table->string('national_id', 20)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->text('result')->nullable();
            $table->dateTime('remind_at')->nullable();
            $table->boolean('notify_in_app')->default(false);
            $table->boolean('notify_sms')->default(false);
            $table->dateTime('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'visited_on']);
            $table->index(['remind_at', 'reminded_at']);
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->boolean('notify_in_app')->default(false);
            $table->boolean('notify_sms')->default(false);
            $table->dateTime('reminded_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'completed_at']);
            $table->index(['due_at', 'reminded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_visits');
    }
};
