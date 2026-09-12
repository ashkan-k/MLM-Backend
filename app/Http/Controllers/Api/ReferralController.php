<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\SharedLink;
use App\Services\Referral\SharedLinkService;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function codes(Request $request)
    {
        return response()->json(
            ReferralCode::query()->where('user_id', $request->user()->id)->get()
        );
    }

    public function referrals(Request $request)
    {
        return response()->json(
            RepresentativeReferral::query()
                ->with(['referred:id,name,mobile', 'shareMembers.user'])
                ->where('referrer_user_id', $request->user()->id)
                ->latest()
                ->get()
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

        return response()->json($service->create($request->user(), $data['type'], $data['members']), 201);
    }

    public function approveSharedLink(Request $request, SharedLink $sharedLink, SharedLinkService $service)
    {
        return response()->json($service->approve($request->user(), $sharedLink));
    }
}
