<?php

namespace Database\Seeders;

use App\Services\Geo\GeoCatalogService;
use Illuminate\Database\Seeder;

class GeoSeeder extends Seeder
{
    public function run(): void
    {
        app(GeoCatalogService::class)->importFromJson();
    }
}
