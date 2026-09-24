<?php

namespace App\Console\Commands;

use Database\Seeders\LoadOrgSeeder;
use Illuminate\Console\Command;

class SeedLoadOrgCommand extends Command
{
    use SkipsBrokenConsoleConfirm;

    protected $signature = 'finopal:seed-load-org
        {--users=10000 : Target user count}
        {--force : Skip confirmation}';

    protected $description = 'Seed large MLM org trees (A–F) for load/QA — NEVER on production';

    public function handle(): int
    {
        $db = (string) config('database.connections.'.config('database.default').'.database');
        $host = (string) config('database.connections.'.config('database.default').'.host', '');
        $unsafe = ! preg_match('/test|load|local|finopal_mlm|sqlite|:memory:/i', $db.$host)
            && ! in_array($host, ['127.0.0.1', 'localhost', ''], true);

        if ($unsafe && ! $this->option('force')) {
            $this->error("Refusing: database '{$db}'@'{$host}' does not look like an isolated test DB. Use --force only if you are sure.");

            return self::FAILURE;
        }

        if (! $this->confirmedOrForced(
            "Seed ~{$this->option('users')} users into DB [{$db}]? This can take minutes.",
            true
        )) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        config(['qa.load_user_count' => (int) $this->option('users')]);
        (new LoadOrgSeeder)->setCommand($this)->run();
        $this->info('Done. Run: php artisan finopal:qa-integrity');

        return self::SUCCESS;
    }
}
