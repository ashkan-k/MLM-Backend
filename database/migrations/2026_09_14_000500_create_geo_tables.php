<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_states', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedInteger('code')->nullable();
            $table->string('title', 80);
            $table->string('slug', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('geo_cities', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('state_id');
            $table->unsignedInteger('code')->nullable();
            $table->string('slug', 40)->nullable();
            $table->string('title', 80);
            $table->string('sub_title', 80)->nullable();
            $table->timestamps();
            $table->index('state_id');
            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_cities');
        Schema::dropIfExists('geo_states');
    }
};
