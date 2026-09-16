<?php

namespace Database\Seeders;

use App\Services\Integration\Finopal\FinopalWebhookDemoService;
use Illuminate\Database\Seeder;

class FinopalWebhookDemoSeeder extends Seeder
{
    public function run(bool $withTransaction = false, bool $reset = true): array
    {
        return app(FinopalWebhookDemoService::class)->prepare($withTransaction, $reset);
    }
}
