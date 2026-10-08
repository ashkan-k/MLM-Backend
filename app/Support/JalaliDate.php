<?php

namespace App\Support;

use Carbon\CarbonInterface;

class JalaliDate
{
    public static function format(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            $value = $value->toDateString();
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $value)) {
            return $value;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return $value;
        }

        [$jy, $jm, $jd] = self::gregorianToJalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));

        return $jy.'/'.$jm.'/'.$jd;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + (int) (($gy2 + 3) / 4) - (int) (($gy2 + 99) / 100) + (int) (($gy2 + 399) / 400) + $gd + $gDaysInMonth[$gm - 1];
        $jy = -1595 + (33 * (int) ($days / 12053));
        $days %= 12053;
        $jy += 4 * (int) ($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + (int) ($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int) (($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }
}
