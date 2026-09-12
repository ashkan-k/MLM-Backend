<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('api');
            $table->string('token', 64)->unique();
            $table->foreignId('active_role_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('hierarchy_level');
            $table->boolean('is_organizational')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'role_id', 'effective_from']);
            $table->index(['user_id', 'role_id', 'is_active']);
        });

        Schema::create('organization_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_node_id')->nullable()->constrained('organization_nodes')->nullOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('path')->nullable()->index();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'role_id', 'is_active']);
            $table->index(['parent_node_id', 'is_active']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('panel');
            $table->string('module');
            $table->string('action');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['panel', 'module', 'action']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed')->default(true);
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed');
            $table->unique(['user_id', 'permission_id']);
        });

        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('source')->default('finopal');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('representative_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source')->default('finopal');
            $table->foreignId('referral_code_id')->nullable()->constrained('referral_codes')->nullOnDelete();
            $table->timestamps();
            $table->unique('referred_user_id');
        });

        Schema::create('referral_share_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained('representative_referrals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('share_percent', 8, 3);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['referral_id', 'user_id']);
        });

        Schema::create('shared_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shared_link_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('share_percent', 8, 3);
            $table->boolean('approved')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['shared_link_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_link_members');
        Schema::dropIfExists('shared_links');
        Schema::dropIfExists('referral_share_members');
        Schema::dropIfExists('representative_referrals');
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('organization_nodes');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('personal_access_tokens');
    }
};
