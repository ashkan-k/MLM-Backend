<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_point_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->integer('delta_points');
            $table->string('month_key', 7); // YYYY-MM — برای شمارش ماهانه حد نصاب
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'role_id', 'month_key'], 'upa_user_role_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_point_adjustments');
    }
};
