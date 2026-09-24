<?php

namespace App\Console\Commands;

use App\Services\Qa\DatabaseIntegrityChecker;
use Illuminate\Console\Command;

class QaIntegrityCheckCommand extends Command
{
    protected $signature = 'finopal:qa-integrity';

    protected $description = 'Run independent database integrity checks (QA)';

    public function handle(DatabaseIntegrityChecker $checker): int
    {
        $result = $checker->run();
        $rows = array_map(fn ($c) => [
            $c['ok'] ? 'PASS' : 'FAIL',
            $c['name'],
            $c['detail'],
        ], $result['checks']);
        $this->table(['Status', 'Check', 'Detail'], $rows);

        if (! $result['ok']) {
            $this->error('Integrity FAILED');

            return self::FAILURE;
        }

        $this->info('Integrity OK');

        return self::SUCCESS;
    }
}
