<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('national_id', 20)->nullable()->unique()->after('mobile');
            $table->string('farasof_user_id', 64)->nullable()->unique()->after('national_id');
            $table->date('birth_date')->nullable()->after('farasof_user_id');
            $table->string('sheba', 34)->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['national_id', 'farasof_user_id', 'birth_date', 'sheba']);
        });
    }
};
