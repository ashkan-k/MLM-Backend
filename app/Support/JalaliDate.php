<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class JalaliDate
{
    public const TIMEZONE = 'Asia/Tehran';

    /**
     * First Jalali business month for monthly bonus / points (Aban 1405).
     * Earlier history stays on Gregorian keys (e.g. 2026-10) for accounting.
     */
    public const BONUS_JALALI_FROM = '1405-08';

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

    /** Jalali calendar parts for an instant, using Iran wall clock. @return array{0:int,1:int,2:int} */
    public static function parts(?CarbonInterface $at = null): array
    {
        $tehran = ($at ? $at->copy() : now())->timezone(self::TIMEZONE);

        return self::gregorianToJalali((int) $tehran->year, (int) $tehran->month, (int) $tehran->day);
    }

    /** Business month key like 1405-07 (Mehr 1405). */
    public static function monthKey(?CarbonInterface $at = null): string
    {
        [$jy, $jm] = self::parts($at);

        return sprintf('%04d-%02d', $jy, $jm);
    }

    public static function previousMonthKey(?CarbonInterface $at = null): string
    {
        [$jy, $jm] = self::parts($at);
        $jm--;
        if ($jm < 1) {
            $jm = 12;
            $jy--;
        }

        return sprintf('%04d-%02d', $jy, $jm);
    }

    public static function isJalaliMonthKey(string $key): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $key, $m)) {
            return false;
        }

        return (int) $m[1] < 1600 && (int) $m[2] >= 1 && (int) $m[2] <= 12;
    }

    public static function isGregorianMonthKey(string $key): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $key, $m)) {
            return false;
        }

        return (int) $m[1] >= 1600 && (int) $m[2] >= 1 && (int) $m[2] <= 12;
    }

    /**
     * Inclusive UTC bounds for a Jalali (or legacy Gregorian) YYYY-MM key.
     *
     * @return array{0:Carbon,1:Carbon,2:string} [startUtc, endUtc, jalaliMonthKey]
     */
    public static function monthBounds(?CarbonInterface $at = null, ?string $monthKey = null): array
    {
        if ($monthKey !== null && self::isJalaliMonthKey($monthKey)) {
            [$jy, $jm] = array_map('intval', explode('-', $monthKey));
        } elseif ($monthKey !== null && self::isGregorianMonthKey($monthKey)) {
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $monthKey.'-01 00:00:00', 'UTC')->startOfMonth();
            $end = $start->copy()->endOfMonth();

            return [$start, $end, $start->format('Y-m')];
        } else {
            [$jy, $jm] = self::parts($at);
        }

        $key = sprintf('%04d-%02d', $jy, $jm);
        $startLocal = self::jalaliDateTime($jy, $jm, 1, 0, 0, 0);
        $lastDay = self::daysInMonth($jy, $jm);
        $endLocal = self::jalaliDateTime($jy, $jm, $lastDay, 23, 59, 59)->endOfSecond();

        return [
            $startLocal->copy()->timezone('UTC'),
            $endLocal->copy()->timezone('UTC'),
            $key,
        ];
    }

    /** Point-in-time used as "end of this Jalali month" (or now if still inside the month). */
    public static function atMonthEnd(?CarbonInterface $at = null, ?string $monthKey = null): Carbon
    {
        [$start, $end, $key] = self::monthBounds($at, $monthKey);
        $now = $at ? $at->copy() : now();
        if (self::monthKey($now) === $key) {
            return $now->copy();
        }

        return $end->copy();
    }

    /**
     * Profits before this instant in Aban 1405 stay inside the frozen Gregorian 2026-10 bonus.
     * New Aban bonus only counts from this cutover forward.
     */
    public static function bonusJalaliProfitCutover(): Carbon
    {
        return Carbon::parse('2026-10-24 00:00:00', self::TIMEZONE)->timezone('UTC');
    }

    public static function usesJalaliBusinessMonth(?CarbonInterface $at = null, ?string $monthKey = null): bool
    {
        if ($monthKey !== null) {
            if (self::isGregorianMonthKey($monthKey)) {
                return false;
            }
            if (self::isJalaliMonthKey($monthKey)) {
                return strcmp($monthKey, self::BONUS_JALALI_FROM) >= 0;
            }

            return false;
        }

        return strcmp(self::monthKey($at), self::BONUS_JALALI_FROM) >= 0;
    }

    /** Active bonus/points month key: Jalali from Aban 1405, else Gregorian YYYY-MM. */
    public static function businessMonthKey(?CarbonInterface $at = null): string
    {
        if (self::usesJalaliBusinessMonth($at)) {
            return self::monthKey($at);
        }

        return ($at ? $at->copy() : now())->startOfMonth()->format('Y-m');
    }

    public static function businessPreviousMonthKey(?CarbonInterface $at = null): string
    {
        if (! self::usesJalaliBusinessMonth($at)) {
            return ($at ? $at->copy() : now())->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        }

        $prev = self::previousMonthKey($at);
        if (strcmp($prev, self::BONUS_JALALI_FROM) < 0) {
            return '2026-10';
        }

        return $prev;
    }

    /**
     * Inclusive UTC bounds for the active business month.
     * When $applyProfitCutover is true, the first Jalali month (Aban) starts at the cutover instant
     * so profits already booked in Gregorian 2026-10 are not double-counted.
     *
     * @return array{0:Carbon,1:Carbon,2:string}
     */
    public static function businessMonthBounds(?CarbonInterface $at = null, ?string $monthKey = null, bool $applyProfitCutover = false): array
    {
        if ($monthKey !== null && self::isGregorianMonthKey($monthKey)) {
            return self::monthBounds(null, $monthKey);
        }

        if ($monthKey !== null && self::isJalaliMonthKey($monthKey)) {
            [$start, $end, $key] = self::monthBounds(null, $monthKey);
        } elseif (self::usesJalaliBusinessMonth($at)) {
            [$start, $end, $key] = self::monthBounds($at);
        } else {
            $at = $at ? $at->copy() : now();
            $start = $at->copy()->startOfMonth();
            $end = $at->copy()->endOfMonth();
            $key = $start->format('Y-m');

            return [$start, $end, $key];
        }

        if ($applyProfitCutover && $key === self::BONUS_JALALI_FROM) {
            $cut = self::bonusJalaliProfitCutover();
            if ($cut->gt($start)) {
                $start = $cut->copy();
            }
        }

        return [$start, $end, $key];
    }

    public static function atBusinessMonthEnd(?CarbonInterface $at = null, ?string $monthKey = null): Carbon
    {
        [$start, $end, $key] = self::businessMonthBounds($at, $monthKey, false);
        $now = $at ? $at->copy() : now();
        if (self::businessMonthKey($now) === $key) {
            return $now->copy();
        }

        return $end->copy();
    }

    public static function daysInMonth(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }

        return self::isLeap($jy) ? 30 : 29;
    }

    public static function isLeap(int $jy): bool
    {
        $cycle = (($jy - 474) % 128 + 128) % 128;
        $breaks = [1, 5, 9, 13, 17, 22, 26, 30, 34, 38, 42, 46, 50, 55, 59, 63, 67, 71, 75, 79, 84, 88, 92, 96, 100, 104, 108, 112, 116, 121, 125];

        return in_array($cycle, $breaks, true);
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

    /** @return array{0:int,1:int,2:int} */
    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668
            + (365 * $jy)
            + ((int) ($jy / 33) * 8)
            + (int) ((($jy % 33) + 3) / 4)
            + $jd
            + (($jm < 7) ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186));
        $gy = 400 * (int) ($days / 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * (int) (--$days / 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * (int) ($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $salA = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 1; $gm <= 12 && $gd > $salA[$gm]; $gm++) {
            $gd -= $salA[$gm];
        }

        return [$gy, $gm, $gd];
    }

    private static function jalaliDateTime(int $jy, int $jm, int $jd, int $h, int $i, int $s): Carbon
    {
        [$gy, $gm, $gd] = self::jalaliToGregorian($jy, $jm, $jd);

        return Carbon::create($gy, $gm, $gd, $h, $i, $s, self::TIMEZONE);
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
