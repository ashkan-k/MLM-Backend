<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_visit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['crm_visit_id', 'id']);
        });

        DB::table('notifications')->where('title', 'یادآور فعالیت')->update(['title' => 'یادآور ثبت و پیگیری']);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_visit_logs');
        DB::table('notifications')->where('title', 'یادآور ثبت و پیگیری')->update(['title' => 'یادآور فعالیت']);
    }
};
