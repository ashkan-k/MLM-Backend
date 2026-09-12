<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('target_role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_criteria_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_request_id')->constrained()->cascadeOnDelete();
            $table->string('criterion_code');
            $table->decimal('required_value', 18, 3)->nullable();
            $table->decimal('actual_value', 18, 3)->nullable();
            $table->boolean('passed')->default(false);
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('manager_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_required_for_promotion')->default(false);
            $table->timestamps();
        });

        Schema::create('course_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(1);
            $table->decimal('passing_score', 8, 2)->default(70);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('course_role_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unique(['course_id', 'role_id']);
        });

        Schema::create('user_course_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_level_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('not_started');
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('progress_percent', 8, 2)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_level_id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('direct');
            $table->string('title')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->string('message_type')->default('text');
            $table->json('attachment')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('conversation_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('authorization_type')->default('tree');
            $table->boolean('allowed')->default(true);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('value_type')->default('json');
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        Schema::create('role_dashboard_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->json('preferences')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('frasoft_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('internal_id');
            $table->string('external_id');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['entity_type', 'external_id']);
            $table->index(['entity_type', 'internal_id']);
        });

        Schema::create('frasoft_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction');
            $table->string('event_type');
            $table->string('idempotency_key')->unique();
            $table->string('status');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frasoft_sync_logs');
        Schema::dropIfExists('frasoft_mappings');
        Schema::dropIfExists('role_dashboard_preferences');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('conversation_authorizations');
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('user_course_progress');
        Schema::dropIfExists('course_role_targets');
        Schema::dropIfExists('course_levels');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('manager_feedback');
        Schema::dropIfExists('promotion_criteria_results');
        Schema::dropIfExists('promotion_requests');
    }
};
