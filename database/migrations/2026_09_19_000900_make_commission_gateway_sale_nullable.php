<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fresh installs already get nullable gateway_sale_id from the create migration.
        // This alters existing MySQL databases that still have NOT NULL.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE commissions MODIFY gateway_sale_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE commissions MODIFY gateway_sale_id BIGINT UNSIGNED NOT NULL');
    }
};
