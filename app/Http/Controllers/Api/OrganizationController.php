<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationTreeService;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function tree(Request $request, OrganizationTreeService $tree)
    {
        $user = $request->user();
        if ($user->isSuperuser() || $user->hasRole('senior_manager')) {
            return response()->json($tree->tree());
        }

        $node = $tree->activeNodesFor($user)->first();

        return response()->json($node ? $tree->tree($node->id) : []);
    }

    public function team(Request $request, OrganizationTreeService $tree)
    {
        return response()->json(
            $tree->descendants($request->user())->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'mobile' => $u->mobile,
                'roles' => $u->roles()->pluck('slug'),
            ])
        );
    }

    public function representatives(Request $request, OrganizationTreeService $tree)
    {
        $ids = $tree->descendants($request->user())->pluck('id')->push($request->user()->id);

        return response()->json(
            User::query()
                ->whereIn('id', $ids)
                ->whereHas('roles', fn ($q) => $q->where('slug', 'representative'))
                ->with('roles')
                ->get()
        );
    }
}
