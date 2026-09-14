<?php

use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_sales', function (Blueprint $table) {
            $table->string('shaparak_reference')->nullable()->after('idempotency_key');
            $table->timestamp('inspected_at')->nullable()->after('shaparak_reference');
            $table->foreignId('inspected_by')->nullable()->after('inspected_at')->constrained('users')->nullOnDelete();
            $table->timestamp('shaparak_at')->nullable()->after('inspected_by');
            $table->foreignId('shaparak_by')->nullable()->after('shaparak_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('shaparak_by');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_note')->nullable()->after('rejected_by');
        });

        Schema::create('gateway_sale_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage');
            $table->string('decision');
            $table->text('note')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['gateway_sale_id', 'stage']);
        });

        if (Schema::hasTable('permissions') && Schema::hasTable('roles') && Role::query()->exists()) {
            PermissionCatalog::sync();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_sale_reviews');
        Schema::table('gateway_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inspected_by');
            $table->dropConstrainedForeignId('shaparak_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn([
                'shaparak_reference',
                'inspected_at',
                'shaparak_at',
                'rejected_at',
                'rejection_note',
            ]);
        });
    }
};
