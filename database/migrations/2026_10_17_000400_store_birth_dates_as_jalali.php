<?php

use App\Support\JalaliDate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'customers'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'birth_date')) {
                continue;
            }
            DB::table($table)->whereNotNull('birth_date')->orderBy('id')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $stored = JalaliDate::store($row->birth_date);
                    $current = substr((string) $row->birth_date, 0, 10);
                    if ($stored !== null && $stored !== $current) {
                        DB::table($table)->where('id', $row->id)->update(['birth_date' => $stored]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
    }
};
