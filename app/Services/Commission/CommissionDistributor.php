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

        // Referrer shares may be weighted; total must stay within 0..100.
        // When only some shared reps have a referrer, the total is intentionally < 100%
        // (unreferred portion pays no separate referrer). Requiring exactly 100%
        // would abort the entire transaction commission run.
        if ($sale->referrers->isNotEmpty()) {
            $this->assertReferrerSharesWithinBounds($sale->referrers);
        }

        return [
            'representatives' => $sale->representatives,
            'referrers' => $sale->referrers,
            'managers' => $sale->managers,
        ];
    }

    public function assertReferrerSharesWithinBounds(iterable $shares): void
    {
        $total = '0.000';
        foreach ($shares as $share) {
            $percent = is_array($share) ? ($share['share_percent'] ?? $share['percent'] ?? 0) : $share->share_percent;
            $percent = (string) $percent;
            if (Money::cmp($percent, '0.000') < 0 || Money::cmp($percent, '100.000') > 0) {
                throw new InvalidArgumentException('سهم معرف باید بین ۰ تا ۱۰۰ درصد باشد.');
            }
            $total = Money::add($total, $percent);
        }

        if (Money::cmp($total, '0.000') <= 0 || Money::cmp($total, '100.000') > 0) {
            throw new InvalidArgumentException('مجموع سهم معرف‌ها باید بیشتر از صفر و حداکثر ۱۰۰ درصد باشد.');
        }
    }
}
