<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_bonus_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_sale_id')->constrained('gateway_sales')->cascadeOnDelete();
            $table->string('qualified_month', 7); // YYYY-MM
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'gateway_sale_id'], 'gbe_user_role_sale_unique');
            $table->index(['user_id', 'role_id', 'qualified_month'], 'gbe_user_role_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_bonus_eligibilities');
    }
};
