<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (class_exists(PermissionCatalog::class)) {
            PermissionCatalog::sync();
        }
    }

    public function down(): void
    {
        //
    }
};
