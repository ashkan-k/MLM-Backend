<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sales', function (Blueprint $table) {
            $table->id();
            $table->string('product_type', 64)->index();
            $table->string('product_code', 120)->nullable()->index();
            $table->string('title')->nullable();
            $table->string('external_ref', 190)->nullable()->index();
            $table->decimal('amount', 18, 3)->default(0);
            $table->unsignedInteger('full_sales_points')->default(0);
            $table->string('status', 40)->default('successful')->index();
            $table->timestamp('sold_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('product_sale_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_sale_id')->constrained('product_sales')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('share_percent', 8, 3);
            $table->decimal('sales_points', 12, 3)->default(0);
            $table->timestamps();
            $table->unique(['product_sale_id', 'user_id']);
        });

        Schema::create('product_sale_referrers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_sale_id')->constrained('product_sales')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('share_percent', 8, 3);
            $table->decimal('commission_percent', 8, 3)->default(0);
            $table->timestamps();
            $table->unique(['product_sale_id', 'user_id']);
        });

        Schema::create('product_sale_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_sale_id')->constrained('product_sales')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->decimal('commission_percent', 8, 3)->default(0);
            $table->timestamps();
            $table->unique(['product_sale_id', 'user_id', 'role_id']);
        });

        Schema::table('finopal_transactions', function (Blueprint $table) {
            $table->string('product_type', 64)->default('gateway_profit')->after('id')->index();
            $table->string('product_code', 120)->nullable()->after('product_type')->index();
            $table->foreignId('product_sale_id')->nullable()->after('gateway_sale_id')->constrained('product_sales')->nullOnDelete();
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('product_sale_id')->nullable()->after('gateway_sale_id')->constrained('product_sales')->nullOnDelete();
        });

        // Production DBs that already ran the older NOT NULL create migration.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            try {
                DB::statement('ALTER TABLE finopal_transactions MODIFY gateway_id BIGINT UNSIGNED NULL');
                DB::statement('ALTER TABLE finopal_transactions MODIFY merchant_code VARCHAR(255) NULL');
            } catch (\Throwable) {
                // already nullable / different dialect
            }
        }
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_sale_id');
        });

        Schema::table('finopal_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_sale_id');
            $table->dropColumn(['product_type', 'product_code']);
        });

        Schema::dropIfExists('product_sale_managers');
        Schema::dropIfExists('product_sale_referrers');
        Schema::dropIfExists('product_sale_representatives');
        Schema::dropIfExists('product_sales');
    }
};
