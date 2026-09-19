<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mobile')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('gateways', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('name');
            $table->string('source')->default('finopal');
            $table->decimal('sale_amount', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('gateway_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shared_link_id')->nullable()->constrained('shared_links')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->unsignedInteger('full_sales_points')->default(100);
            $table->string('status')->default('successful');
            $table->timestamp('sold_at');
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['status', 'sold_at']);
        });

        Schema::create('gateway_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('share_percent', 8, 3);
            $table->decimal('sales_points', 10, 3);
            $table->timestamps();
            $table->unique(['gateway_sale_id', 'user_id']);
        });

        Schema::create('gateway_referrers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('share_percent', 8, 3);
            $table->decimal('commission_percent', 8, 3);
            $table->timestamps();
            $table->unique(['gateway_sale_id', 'user_id']);
        });

        Schema::create('gateway_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->decimal('commission_percent', 8, 3);
            $table->timestamps();
            $table->unique(['gateway_sale_id', 'user_id', 'role_id']);
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->decimal('default_percent', 8, 3);
            $table->string('qualification_type')->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('commission_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('percent', 8, 3);
            $table->decimal('qualified_percent', 8, 3)->nullable();
            $table->json('conditions')->nullable();
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['commission_rule_id', 'version']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rule_version_id')->nullable()->constrained('commission_rule_versions')->nullOnDelete();
            $table->decimal('base_amount', 18, 2);
            $table->decimal('commission_percent', 8, 3);
            $table->decimal('commission_amount', 18, 3);
            $table->string('status')->default('posted');
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'role_id', 'created_at']);
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 8)->default('IRT');
            $table->decimal('balance', 18, 3)->default(0);
            $table->decimal('held_balance', 18, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'role_id', 'currency']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 18, 3);
            $table->decimal('balance_before', 18, 3);
            $table->decimal('balance_after', 18, 3);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 3);
            $table->string('status');
            $table->string('idempotency_key')->unique();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('withdrawal_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('stage');
            $table->string('decision');
            $table->text('note')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('benefit_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('gateway_sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transfer_type');
            $table->string('status')->default('active');
            $table->dateTime('effective_from');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('benefit_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_representative_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('share_percent', 8, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_transfer_items');
        Schema::dropIfExists('benefit_transfers');
        Schema::dropIfExists('withdrawal_approvals');
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_rule_versions');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('gateway_managers');
        Schema::dropIfExists('gateway_referrers');
        Schema::dropIfExists('gateway_representatives');
        Schema::dropIfExists('gateway_sales');
        Schema::dropIfExists('gateways');
        Schema::dropIfExists('customers');
    }
};
