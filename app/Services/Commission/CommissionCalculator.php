<?php

namespace App\Services\Commission;

use App\Support\Money;

class CommissionCalculator
{
    public function amount(string $baseAmount, string $percent): string
    {
        return Money::percentOf($baseAmount, $percent, 3);
    }

    public function sharedPercent(string $rolePercent, string $sharePercent): string
    {
        return Money::percentOf($rolePercent, $sharePercent, 3);
    }

    public function qualifiedPercent(string $basePercent, ?string $qualifiedPercent, bool $qualified): string
    {
        if ($qualified && $qualifiedPercent !== null) {
            return Money::normalize($qualifiedPercent);
        }

        return Money::normalize($basePercent);
    }
}
