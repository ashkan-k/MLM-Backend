<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->string('merchant_code')->nullable()->unique()->after('external_id');
        });

        Schema::create('finopal_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_sale_id')->nullable()->constrained('gateway_sales')->nullOnDelete();
            $table->string('merchant_code');
            $table->string('event')->default('transaction.verified');
            $table->string('authority')->nullable()->index();
            $table->string('ref_id')->nullable();
            $table->string('order_id')->nullable();
            $table->decimal('amount', 18, 3);
            $table->decimal('profit', 18, 3);
            $table->string('currency', 8)->default('IRT');
            $table->string('status')->default('verified');
            $table->unsignedInteger('code')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['merchant_code', 'paid_at']);
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('finopal_transaction_id')->nullable()->after('gateway_sale_id')->constrained('finopal_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finopal_transaction_id');
        });
        Schema::dropIfExists('finopal_transactions');
        Schema::table('gateways', function (Blueprint $table) {
            $table->dropUnique(['merchant_code']);
            $table->dropColumn('merchant_code');
        });
    }
};
