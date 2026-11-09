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

    /**
     * True when the unique violation is on one of the given column/index needles.
     * Prevents treating unrelated uniques (e.g. referral_codes.code) as "user exists".
     *
     * @param  list<string>  $needles
     */
    public static function violatedOn(QueryException $e, array $needles): bool
    {
        if (! self::violated($e)) {
            return false;
        }

        $message = strtolower($e->getMessage());
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($message, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
