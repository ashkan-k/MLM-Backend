<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSharedCommissionSeeder;
use Illuminate\Console\Command;

class SeedDemoSharedCommissions extends Command
{
    protected $signature = 'demo:shared-commissions';

    protected $description = 'Seed demo gateways/transactions for shared referral (09163333333) and share_a/share_b shared sale';

    public function handle(): int
    {
        $seeder = new DemoSharedCommissionSeeder;
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }
}
