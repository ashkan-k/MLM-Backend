<?php

namespace App\Services\Commission;

use App\Models\CommissionRule;
use App\Models\CommissionRuleVersion;
use Carbon\CarbonInterface;

class CommissionRuleResolver
{
    public function resolve(string $code, CarbonInterface $at): ?CommissionRuleVersion
    {
        $rule = CommissionRule::query()->where('code', $code)->where('is_active', true)->first();
        if (! $rule) {
            return null;
        }

        return CommissionRuleVersion::query()
            ->where('commission_rule_id', $rule->id)
            ->where('effective_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
            })
            ->orderByDesc('version')
            ->first();
    }
}
