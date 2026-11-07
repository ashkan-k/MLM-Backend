<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('catalog_products')
            && Schema::hasColumn('catalog_products', 'description')
            && Schema::getConnection()->getDriverName() === 'mysql'
        ) {
            DB::statement('ALTER TABLE catalog_products MODIFY description TEXT NULL');
        }

        $products = [
            'gateway_profit' => [
                'label' => 'فینوپال',
                'sort_order' => 10,
                'description' => "ارائه‌دهنده خدمات درگاه پرداخت اینترنتی به کسب‌وکارها و فروشگاه‌های آنلاین، با امکان مدیریت و پردازش هوشمند تراکنش‌ها.\nمدل درآمدزایی نماینده: دریافت سهم درآمد از تراکنش‌های درگاه‌های پرداخت ثبت‌شده و فعال‌شده توسط مشتریان معرفی‌شده؛ ایجاد درآمد مستمر با ادامه فعالیت پذیرندگان.",
            ],
            'subscription' => [
                'label' => 'فاپ',
                'sort_order' => 20,
                'description' => "مجموعه‌ای یکپارچه از خدمات پرداخت، استعلام و سرویس‌های کاربردی روزمره در قالب یک اپلیکیشن برای کاربران حقیقی و کسب‌وکارها.\nمدل درآمدزایی نماینده: دریافت سهم درآمد از تراکنش‌های پرداختی و خدمات استعلامی کاربران معرفی‌شده به فاپ.",
            ],
            'finopol' => [
                'label' => 'فینوپُل',
                'sort_order' => 30,
                'description' => "ارائه مجموعه‌ای از APIها و وب‌سرویس‌های مالی، هویتی و اطلاعاتی برای کسب‌وکارها، سامانه‌ها و توسعه‌دهندگان نرم‌افزار.\nمدل درآمدزایی نماینده: دریافت سهم درآمد از مصرف سرویس‌ها و تراکنش‌های کسب‌وکارهای معرفی‌شده؛ با ظرفیت ایجاد درآمد مستمر از مشتریان سازمانی.",
            ],
            'finolo' => [
                'label' => 'فینولو',
                'sort_order' => 40,
                'description' => "ابزاری کاربردی برای برنامه‌ریزی، مدیریت پروژه‌ها، سازمان‌دهی وظایف و افزایش بهره‌وری تیم‌ها و کسب‌وکارها.\nمدل درآمدزایی نماینده: دریافت پورسانت از فروش و تمدید اشتراک مشتریان معرفی‌شده.",
            ],
            'ghalebazar' => [
                'label' => 'قالب‌بازار',
                'sort_order' => 50,
                'description' => "مجموعه‌ای گسترده با بیش از ۶ هزار قالب و طرح مدرن وب‌سایت، مبتنی بر فناوری SPA، برای راه‌اندازی سریع و حرفه‌ای کسب‌وکارهای آنلاین.\nمدل درآمدزایی نماینده: دریافت پورسانت از فروش قالب‌های آماده و محصولات دیجیتال به مشتریان معرفی‌شده.",
            ],
            'finoticket' => [
                'label' => 'فینوتیکت',
                'sort_order' => 60,
                'description' => "راهکاری حرفه‌ای برای مدیریت تیکت‌ها، ارتباط با مشتریان، پیگیری درخواست‌ها و سازمان‌دهی فرآیندهای پشتیبانی کسب‌وکارها.\nمدل درآمدزایی نماینده: دریافت پورسانت از فروش و تمدید اشتراک کسب‌وکارهای معرفی‌شده.",
            ],
            'ticketing' => [
                'label' => 'فینوتیکت',
                'sort_order' => 60,
                'description' => "راهکاری حرفه‌ای برای مدیریت تیکت‌ها، ارتباط با مشتریان، پیگیری درخواست‌ها و سازمان‌دهی فرآیندهای پشتیبانی کسب‌وکارها.\nمدل درآمدزایی نماینده: دریافت پورسانت از فروش و تمدید اشتراک کسب‌وکارهای معرفی‌شده.",
            ],
            'finocart' => [
                'sort_order' => 70,
            ],
        ];

        foreach ($products as $type => $data) {
            if (! DB::table('catalog_products')->where('type', $type)->exists()) {
                continue;
            }
            DB::table('catalog_products')->where('type', $type)->update(array_filter([
                'label' => $data['label'] ?? null,
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'] ?? null,
                'updated_at' => now(),
            ], fn ($v) => $v !== null));
        }

        Cache::forget('catalog_products.v1');
    }

    public function down(): void
    {
        // Keep seeded copy; no destructive rollback.
    }
};
