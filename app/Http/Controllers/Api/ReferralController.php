<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\SharedLink;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Referral\SharedLinkService;
use App\Support\ReferralCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReferralController extends Controller
{
    public function codes(Request $request)
    {
        $user = $request->user();
        ReferralCode::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'code' => ReferralCodeGenerator::unique(),
                'source' => 'finopal',
                'is_active' => true,
            ]
        );

        return response()->json(
            ReferralCode::query()->where('user_id', $user->id)->where('is_active', true)->get()
        );
    }

    public function referrals(Request $request)
    {
        return response()->json(
            RepresentativeReferral::query()
                ->with(['referred:id,name,mobile', 'shareMembers.user'])
                ->where(function ($q) use ($request) {
                    $q->where('referrer_user_id', $request->user()->id)
                        ->orWhereHas('shareMembers', fn ($m) => $m->where('user_id', $request->user()->id));
                })
                ->latest()
                ->get()
        );
    }

    public function showByToken(string $token)
    {
        $link = SharedLink::query()
            ->with('members.user:id,name,mobile')
            ->where('token', $token)
            ->first();

        if (! $link) {
            return response()->json(['message' => 'لینک اشتراکی یافت نشد.'], 404);
        }

        $featureOn = SystemSetting::sharedLinkTypeEnabled($link->type);
        $usable = $featureOn
            && $link->status === 'active'
            && ! $link->used_at
            && (! $link->expires_at || ! $link->expires_at->isPast());

        return response()->json([
            'token' => $link->token,
            'type' => $link->type,
            'status' => $link->status,
            'used' => (bool) $link->used_at,
            'expired' => $link->expires_at ? $link->expires_at->isPast() : false,
            'pending_approvals' => $link->status === 'pending',
            'feature_enabled' => $featureOn,
            'usable' => $usable,
        ]);
    }

    public function partners()
    {
        return response()->json(
            User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('slug', 'representative'))
                ->whereDoesntHave('roles', fn ($q) => $q->where('slug', 'superuser'))
                ->with('roles')
                ->orderBy('name')
                ->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'mobile' => $u->mobile,
                    'roles' => $u->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]),
                ])
        );
    }

    public function sharedLinks(Request $request)
    {
        return response()->json(
            SharedLink::query()
                ->with('members.user')
                ->where(function ($q) use ($request) {
                    $q->where('creator_user_id', $request->user()->id)
                        ->orWhereHas('members', fn ($m) => $m->where('user_id', $request->user()->id));
                })
                ->latest()
                ->get()
        );
    }

    public function createSharedLink(Request $request, SharedLinkService $service)
    {
        $data = $request->validate([
            'type' => ['required', 'in:gateway_sale,referral'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.user_id' => ['required', 'exists:users,id'],
            'members.*.share_percent' => ['required', 'numeric'],
        ]);

        if (! SystemSetting::sharedLinkTypeEnabled($data['type'])) {
            throw ValidationException::withMessages([
                'type' => ['این نوع لینک اشتراکی فعلاً توسط مدیر سامانه غیرفعال شده است.'],
            ]);
        }

        return response()->json($service->create($request->user(), $data['type'], $data['members']), 201);
    }

    public function approveSharedLink(Request $request, SharedLink $sharedLink, SharedLinkService $service)
    {
        return response()->json($service->approve($request->user(), $sharedLink));
    }
}
