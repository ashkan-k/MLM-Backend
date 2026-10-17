<?php

namespace App\Support;

use Carbon\CarbonInterface;

class JalaliDate
{
    /**
     * Birth dates are stored on the Jalali calendar.
     * A Jalali value is kept; a Gregorian value (year 1600 or later) is converted first.
     */
    public static function store(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            $value = $value->format('Y-m-d');
        }
        $value = self::westernDigits(trim((string) $value));
        if ($value === '') {
            return null;
        }
        $value = preg_replace('/[T ].*$/', '', $value) ?? $value;
        if (! preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $value, $match)) {
            return null;
        }
        $year = (int) $match[1];
        $month = (int) $match[2];
        $day = (int) $match[3];
        if ($year >= 1600) {
            if (! checkdate($month, $day, $year)) {
                return null;
            }
            [$year, $month, $day] = self::gregorianToJalali($year, $month, $day);
        } elseif ($year < 1200 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function format(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            if ((int) $value->year > 0 && (int) $value->year < 1600) {
                return sprintf('%04d/%02d/%02d', (int) $value->year, (int) $value->month, (int) $value->day);
            }
            $value = $value->toDateString();
        }
        $value = self::westernDigits(trim((string) $value));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $match)) {
            if ((int) $match[1] < 1600) {
                return $value;
            }
            $value = sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $match) && (int) $match[1] < 1600) {
            return sprintf('%04d/%02d/%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
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

    private static function westernDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
