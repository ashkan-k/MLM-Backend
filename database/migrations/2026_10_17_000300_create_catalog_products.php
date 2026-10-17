<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64)->unique();
            $table->string('label');
            $table->boolean('requires_merchant')->default(false);
            $table->boolean('requires_owner')->default(true);
            $table->unsignedInteger('sale_points')->default(0);
            $table->string('default_code', 120)->nullable();
            $table->string('signup_url', 255)->nullable();
            $table->string('aliases', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $aliases = [
            'gateway_profit' => 'gateway,gateway_payment,payment_gateway',
            'ticketing' => 'ticket,tickets,finopal_ticketing',
            'ghalebazar' => 'ghale_bazar,template_bazaar',
            'monthly_bonus_residual' => 'bonus_residual',
        ];
        $system = ['gateway_profit', 'monthly_bonus_residual'];
        $now = now();
        $order = 0;
        foreach ((array) config('finopal.products', []) as $type => $cfg) {
            if ($type === '_default' || ! is_array($cfg)) {
                continue;
            }
            DB::table('catalog_products')->insert([
                'type' => $type,
                'label' => (string) ($cfg['label'] ?? $type),
                'requires_merchant' => (bool) ($cfg['requires_merchant'] ?? false),
                'requires_owner' => (bool) ($cfg['requires_owner'] ?? true),
                'sale_points' => (int) ($cfg['sale_points'] ?? 0),
                'default_code' => $cfg['default_code'] ?? null,
                'signup_url' => $cfg['signup_url'] ?? null,
                'aliases' => $aliases[$type] ?? null,
                'is_system' => in_array($type, $system, true),
                'is_active' => true,
                'sort_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
