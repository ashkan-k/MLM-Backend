<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_visits', function (Blueprint $table) {
            $table->string('activity_type', 20)->default('visit')->after('visited_on');
            $table->string('status', 20)->default('open')->after('result');
        });

        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->string('priority', 20)->default('normal')->after('title');
        });

        DB::table('notifications')->where('title', 'یادآور پرزنت')->update(['title' => 'یادآور فعالیت']);
        DB::table('notifications')->where('title', 'یادآور کار')->update(['title' => 'یادآور وظیفه']);

        foreach (DB::table('notifications')->where('type', 'crm_reminder')->get() as $row) {
            $body = (string) $row->body;
            if (! str_starts_with($body, 'پرزنت ')) {
                continue;
            }
            DB::table('notifications')->where('id', $row->id)->update([
                'body' => 'فعالیت '.mb_substr($body, mb_strlen('پرزنت ')),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_visits', function (Blueprint $table) {
            $table->dropColumn(['activity_type', 'status']);
        });
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
