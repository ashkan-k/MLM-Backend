<?php

namespace App\Services\Referral;

use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Wallet\WalletService;

class SelfReferralService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * Senior managers have no external referrer; they are defined as their own referrer
     * so the configurable representative_referrer plan (default 2%) still applies.
     */
    public function ensureForSenior(User $senior): void
    {
        if (! $senior->hasRole('senior_manager')) {
            return;
        }

        $code = ReferralCode::query()->firstOrCreate(
            ['user_id' => $senior->id],
            [
                'code' => 'R'.$senior->id.strtoupper(substr(md5($senior->mobile), 0, 6)),
                'source' => 'finopal',
                'is_active' => true,
            ]
        );

        RepresentativeReferral::query()->firstOrCreate(
            ['referred_user_id' => $senior->id],
            [
                'referrer_user_id' => $senior->id,
                'source' => 'finopal',
                'referral_code_id' => $code->id,
            ]
        );

        $referrerRole = Role::query()->where('slug', 'representative_referrer')->first();
        if ($referrerRole && ! $senior->hasRole('representative_referrer')) {
            UserRole::query()->firstOrCreate(
                ['user_id' => $senior->id, 'role_id' => $referrerRole->id],
                [
                    'effective_from' => now()->toDateString(),
                    'is_active' => true,
                ]
            );
            $this->wallets->walletFor($senior, $referrerRole);
        }
    }
}
