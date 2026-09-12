<?php

namespace App\Services\Referral;

use App\Models\Notification;
use App\Models\SharedLink;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SharedLinkService
{
    public function create(User $creator, string $type, array $members): SharedLink
    {
        $total = '0.000';
        foreach ($members as $member) {
            $total = Money::add($total, (string) $member['share_percent']);
        }
        if (Money::cmp($total, '100.000') !== 0) {
            throw new InvalidArgumentException('مجموع سهم لینک اشتراکی باید ۱۰۰ درصد باشد.');
        }

        $link = SharedLink::query()->create([
            'creator_user_id' => $creator->id,
            'token' => Str::random(40),
            'type' => $type,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        foreach ($members as $member) {
            $approved = (int) $member['user_id'] === $creator->id;
            $link->members()->create([
                'user_id' => $member['user_id'],
                'share_percent' => $member['share_percent'],
                'approved' => $approved,
                'approved_at' => $approved ? now() : null,
            ]);

            if (! $approved) {
                Notification::query()->create([
                    'user_id' => $member['user_id'],
                    'type' => 'shared_link.approval',
                    'title' => 'تایید لینک اشتراکی',
                    'body' => "{$creator->name} شما را به یک لینک اشتراکی دعوت کرده است.",
                    'data' => ['shared_link_id' => $link->id, 'token' => $link->token],
                ]);
            }
        }

        $this->activateIfReady($link);

        return $link->fresh('members.user');
    }

    public function approve(User $user, SharedLink $link): SharedLink
    {
        $member = $link->members()->where('user_id', $user->id)->firstOrFail();
        $member->approved = true;
        $member->approved_at = now();
        $member->save();
        $this->activateIfReady($link);

        return $link->fresh('members.user');
    }

    public function consume(SharedLink $link): SharedLink
    {
        if ($link->status !== 'active' || $link->used_at) {
            throw new InvalidArgumentException('لینک اشتراکی یک‌بارمصرف است و قابل استفاده نیست.');
        }

        $link->status = 'used';
        $link->used_at = now();
        $link->save();

        return $link;
    }

    private function activateIfReady(SharedLink $link): void
    {
        if ($link->members()->where('approved', false)->doesntExist()) {
            $link->status = 'active';
            $link->save();
        }
    }
}
