<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('course_levels', 'content_type')) {
            Schema::table('course_levels', function (Blueprint $table) {
                $table->string('content_type')->default('text');
                $table->text('content_body')->nullable();
                $table->string('content_url')->nullable();
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
            });
        }

        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            PermissionCatalog::sync();
        }
    }

    public function down(): void
    {
        Schema::table('course_levels', function (Blueprint $table) {
            $table->dropColumn([
                'content_type',
                'content_body',
                'content_url',
                'attachment_path',
                'attachment_name',
            ]);
        });
    }
};
