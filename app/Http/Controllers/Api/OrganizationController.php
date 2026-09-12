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
                'roles' => $u->roles()->pluck('name'),
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
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'mobile' => $u->mobile,
                    'roles' => $u->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]),
                ])
        );
    }

    public function directory(Request $request, OrganizationTreeService $tree)
    {
        $user = $request->user();
        $query = User::query()->where('is_active', true)->with('roles');

        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            $ids = $tree->descendants($user)->pluck('id')->push($user->id);
            $query->whereIn('id', $ids);
        }

        return response()->json(
            $query->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'mobile' => $u->mobile,
                'roles' => $u->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]),
            ])
        );
    }
}
