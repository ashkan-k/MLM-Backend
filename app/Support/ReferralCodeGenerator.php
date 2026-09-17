<?php

namespace App\Support;

use App\Models\ReferralCode;
use Illuminate\Support\Str;

class ReferralCodeGenerator
{
    /**
     * Random unique referral code (not derived from mobile).
     * Format: 10 chars, uppercase A-Z + 2-9 (no ambiguous 0/O/1/I).
     */
    public static function unique(int $length = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (ReferralCode::query()->where('code', $code)->exists());

        return $code;
    }

    /** Regenerate codes that look like mobile-based or predictable demo keys. */
    public static function looksPredictable(string $code): bool
    {
        $code = strtoupper(trim($code));

        return (bool) preg_match('/^\d{10,11}REF$/', $code)
            || (bool) preg_match('/^R\d+[A-F0-9]{6}$/', $code)
            || Str::endsWith($code, 'REF') && strlen($code) <= 16;
    }
}
