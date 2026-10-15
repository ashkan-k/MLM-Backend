<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_visits', function (Blueprint $table) {
            $table->dateTime('notified_at')->nullable()->after('notify_sms');
            $table->dateTime('sms_sent_at')->nullable()->after('notified_at');
        });

        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dateTime('notified_at')->nullable()->after('notify_sms');
            $table->dateTime('sms_sent_at')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_visits', function (Blueprint $table) {
            $table->dropColumn(['notified_at', 'sms_sent_at']);
        });
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropColumn(['notified_at', 'sms_sent_at']);
        });
    }
};
