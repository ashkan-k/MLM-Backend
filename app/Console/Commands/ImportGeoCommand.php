<?php

namespace App\Console\Commands;

use App\Services\Geo\GeoCatalogService;
use Illuminate\Console\Command;

class ImportGeoCommand extends Command
{
    protected $signature = 'geo:import
        {--from-shop-maker : استان و شهر را از دیتابیس MySQL فروشگاه‌ساز (shop_maker) بخوان و JSON را هم به‌روز کن}
        {--path= : مسیر فایل JSON؛ پیش‌فرض database/data/iran-geo.json}';

    protected $description = 'ایمپورت استان و شهر ایران برای فرم درگاه. روی سرور همین دستور را اجرا کنید.';

    public function handle(GeoCatalogService $geo): int
    {
        try {
            $result = $this->option('from-shop-maker')
                ? $geo->importFromShopMaker()
                : $geo->importFromJson($this->option('path') ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("استان‌ها: {$result['states']} | شهرها: {$result['cities']}");
        if ($this->option('from-shop-maker')) {
            $this->info('فایل JSON هم در database/data/iran-geo.json ذخیره شد.');
        }

        return self::SUCCESS;
    }
}
