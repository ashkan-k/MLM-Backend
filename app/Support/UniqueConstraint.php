<?php

namespace App\Support;

use Illuminate\Database\QueryException;

final class UniqueConstraint
{
    public static function violated(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = strtolower($e->getMessage());

        // MySQL / MariaDB
        if ($sqlState === '23000' || $driverCode === 1062) {
            return true;
        }

        // SQLite / Postgres wording
        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
