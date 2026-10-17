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
            $table->foreignId('role_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['user_id', 'role_id']);
        });
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['user_id', 'role_id']);
        });

        $this->backfill('crm_visits');
        $this->backfill('crm_tasks');
    }

    public function down(): void
    {
        Schema::table('crm_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }

    private function backfill(string $table): void
    {
        $seen = [];
        $roles = DB::table('user_roles')
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['user_id', 'role_id']);

        foreach ($roles as $row) {
            if (isset($seen[$row->user_id])) {
                continue;
            }
            $seen[$row->user_id] = true;
            DB::table($table)->where('user_id', $row->user_id)->whereNull('role_id')->update([
                'role_id' => $row->role_id,
            ]);
        }
    }
};
