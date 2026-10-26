<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'province')) {
                    $table->string('province', 120)->nullable()->after('email');
                }
                if (! Schema::hasColumn('users', 'city')) {
                    $table->string('city', 120)->nullable()->after('province');
                }
            });
        }

        if (! Schema::hasTable('user_goal_bank_access')) {
            return;
        }

        $rows = DB::table('user_goal_bank_access')
            ->orderByDesc('enabled')
            ->orderByDesc('max_concurrent')
            ->orderBy('id')
            ->get();

        $keepIdByUser = [];
        $merge = [];
        $deleteIds = [];

        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            if (! isset($keepIdByUser[$uid])) {
                $keepIdByUser[$uid] = (int) $row->id;
                $merge[$uid] = [
                    'enabled' => (bool) $row->enabled,
                    'max_concurrent' => (int) $row->max_concurrent,
                    'granted_by' => $row->granted_by,
                    'role_id' => $row->role_id,
                ];
                continue;
            }
            $merge[$uid]['enabled'] = $merge[$uid]['enabled'] || (bool) $row->enabled;
            $merge[$uid]['max_concurrent'] = max($merge[$uid]['max_concurrent'], (int) $row->max_concurrent);
            if (! $merge[$uid]['granted_by'] && $row->granted_by) {
                $merge[$uid]['granted_by'] = $row->granted_by;
            }
            $deleteIds[] = (int) $row->id;
        }

        foreach ($merge as $uid => $data) {
            DB::table('user_goal_bank_access')->where('id', $keepIdByUser[$uid])->update([
                'enabled' => $data['enabled'],
                'max_concurrent' => $data['max_concurrent'],
                'granted_by' => $data['granted_by'],
            ]);
        }

        if ($deleteIds !== []) {
            DB::table('user_goal_bank_access')->whereIn('id', $deleteIds)->delete();
        }

        try {
            Schema::table('user_goal_bank_access', function (Blueprint $table) {
                $table->dropUnique('ugba_user_role_unique');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('user_goal_bank_access', function (Blueprint $table) {
                $table->unique('user_id', 'ugba_user_unique');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        try {
            Schema::table('user_goal_bank_access', function (Blueprint $table) {
                $table->dropUnique('ugba_user_unique');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('user_goal_bank_access', function (Blueprint $table) {
                $table->unique(['user_id', 'role_id'], 'ugba_user_role_unique');
            });
        } catch (\Throwable) {
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'city')) {
                $table->dropColumn('city');
            }
            if (Schema::hasColumn('users', 'province')) {
                $table->dropColumn('province');
            }
        });
    }
};
