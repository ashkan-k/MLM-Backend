<?php

namespace App\Support;

use App\Models\ReferralCode;

class ReferralCodeGenerator
{
    /** Max digits for a referral code (numeric string only). */
    public const MAX_DIGITS = 5;

    /**
     * Random unique referral code: digits only, length 1..MAX_DIGITS.
     * Prefer full-width codes (10000–99999) when the pool allows.
     */
    public static function unique(int $maxDigits = self::MAX_DIGITS): string
    {
        $maxDigits = max(1, min(self::MAX_DIGITS, $maxDigits));
        $max = (10 ** $maxDigits) - 1;
        $min = $maxDigits >= 5 ? 10 ** ($maxDigits - 1) : 1;
        $attempts = 0;

        do {
            $code = (string) random_int($min, $max);
            $attempts++;
            if ($attempts > 2000) {
                // Fall back to full 1..max range if high range is exhausted
                $code = (string) random_int(1, $max);
            }
            if ($attempts > 5000) {
                throw new \RuntimeException('Could not generate a unique numeric referral code.');
            }
        } while (ReferralCode::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * True when the stored code should be replaced (non-numeric, too long, or legacy predictable keys).
     */
    public static function looksPredictable(string $code): bool
    {
        $code = trim($code);

        if ($code === '' || ! preg_match('/^\d{1,'.self::MAX_DIGITS.'}$/', $code)) {
            return true;
        }

        return false;
    }
}
