<?php

namespace App\Services\Commission;

use App\Models\GatewaySale;
use App\Support\Money;
use InvalidArgumentException;

class CommissionDistributor
{
    public function assertShares(iterable $shares): void
    {
        $total = '0.000';
        foreach ($shares as $share) {
            $percent = is_array($share) ? ($share['share_percent'] ?? $share['percent'] ?? 0) : $share->share_percent;
            $total = Money::add($total, (string) $percent);
        }

        if (Money::cmp($total, '100.000') !== 0) {
            throw new InvalidArgumentException('مجموع سهم‌ها باید دقیقاً ۱۰۰ درصد باشد.');
        }
    }

    public function split(GatewaySale $sale): array
    {
        $this->assertShares($sale->representatives);

        if ($sale->referrers->isNotEmpty()) {
            $this->assertShares($sale->referrers);
        }

        return [
            'representatives' => $sale->representatives,
            'referrers' => $sale->referrers,
            'managers' => $sale->managers,
        ];
    }
}
