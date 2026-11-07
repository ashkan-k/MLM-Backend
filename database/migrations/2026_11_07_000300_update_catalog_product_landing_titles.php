<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_products')) {
            return;
        }

        $labels = [
            'gateway_profit' => 'فینوپال | درگاه پرداخت اینترنتی',
            'subscription' => 'فاپ | اپلیکیشن جامع خدمات مالی و پرداخت',
            'finopol' => 'فینوپُل | زیرساخت یکپارچه وب‌سرویس‌های استعلامی',
            'finolo' => 'فینولو | سامانه مدیریت پروژه و فعالیت تیمی',
            'ghalebazar' => 'قالب‌بازار | بازار تخصصی قالب‌های آماده وب‌سایت',
            'finoticket' => 'فینوتیکت | سامانه پشتیبانی و مدیریت درخواست‌ها',
            'ticketing' => 'فینوتیکت | سامانه پشتیبانی و مدیریت درخواست‌ها',
        ];

        foreach ($labels as $type => $label) {
            DB::table('catalog_products')->where('type', $type)->update([
                'label' => $label,
                'updated_at' => now(),
            ]);
        }

        Cache::forget('catalog_products.v1');
    }

    public function down(): void
    {
        // Keep titles.
    }
};
