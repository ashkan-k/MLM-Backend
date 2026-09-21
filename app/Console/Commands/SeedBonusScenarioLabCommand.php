<?php

namespace App\Console\Commands;

use Database\Seeders\BonusScenarioLabSeeder;
use Illuminate\Console\Command;

class SeedBonusScenarioLabCommand extends Command
{
    protected $signature = 'finopal:seed-bonus-lab
        {--force : بدون پرسش}';

    protected $description = 'لاب تست بصری پاداش ماهانه: ۳ درگاه + حد نصاب امتیاز + تراکنش روی درگاه واجد شرایط';

    public function handle(): int
    {
        if (! $this->option('force') && $this->input->isInteractive()) {
            $this->comment('روی ویندوز ترجیحاً با --force اجرا کنید.');
        }

        $seeder = new BonusScenarioLabSeeder;
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }
}
