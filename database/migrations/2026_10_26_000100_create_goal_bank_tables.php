<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_goal_bank_access')) {
            Schema::create('user_goal_bank_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('max_concurrent')->default(10);
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'role_id'], 'ugba_user_role_unique');
            });
        }

        if (! Schema::hasTable('goal_bank_items')) {
            Schema::create('goal_bank_items', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('platform', 120);
                $table->string('platform_url', 500)->nullable();
                $table->unsignedInteger('audience_count')->nullable();
                $table->text('description')->nullable();
                $table->string('province', 120)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('address', 500)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('mobile', 40)->nullable();
                $table->string('status', 32)->default('available')->index();
                $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('claimed_by_role_id')->nullable()->constrained('roles')->nullOnDelete();
                $table->timestamp('claimed_at')->nullable();
                $table->text('work_report')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamp('disposed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['claimed_by_user_id', 'claimed_by_role_id', 'status'], 'gbi_claimant_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_bank_items');
        Schema::dropIfExists('user_goal_bank_access');
    }
};
