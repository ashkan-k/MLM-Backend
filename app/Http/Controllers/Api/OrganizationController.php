<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrganizationNode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Audit\AuditService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\User\UserBlockService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
                'is_active' => (bool) $u->is_active,
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

    public function block(Request $request, User $user, UserBlockService $blocks)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($blocks->block($request->user(), $user, $data['reason'] ?? ''));
    }

    public function unblock(Request $request, User $user, UserBlockService $blocks)
    {
        return response()->json($blocks->unblock($request->user(), $user));
    }

    /**
     * Change sales manager for a representative (or subtree of reps under an old SM),
     * or change development manager for a sales manager node.
     * mode=appoint: attach a user into a vacant SM/DM slot under a parent manager.
     */
    public function reassignManager(Request $request, OrganizationTreeService $tree, AuditService $audit, WalletService $wallets)
    {
        $actor = $request->user();
        if (! $actor->isSuperuser() && ! $actor->hasRole('senior_manager')) {
            abort(403, 'فقط مدیر ارشد یا مدیر سامانه مجاز است.');
        }

        $data = $request->validate([
            'mode' => ['nullable', 'in:reassign,appoint'],
            'target_user_id' => ['nullable', 'exists:users,id'],
            'appoint_user_id' => ['nullable', 'exists:users,id'],
            'manager_user_id' => ['required', 'exists:users,id'],
            'manager_role' => ['required', 'in:sales_manager,development_manager'],
        ]);

        $mode = $data['mode'] ?? (! empty($data['appoint_user_id']) && empty($data['target_user_id']) ? 'appoint' : 'reassign');

        if ($mode === 'appoint') {
            return $this->appointVacant($data, $tree, $audit, $wallets, $actor);
        }

        if (empty($data['target_user_id'])) {
            throw ValidationException::withMessages([
                'target_user_id' => ['برای جابجایی، کاربر هدف الزامی است. برای سمت خالی حالت «الحاق» را انتخاب کنید.'],
            ]);
        }

        $target = User::query()->findOrFail($data['target_user_id']);
        $manager = User::query()->findOrFail($data['manager_user_id']);
        $roleSlug = $data['manager_role'];

        if (! $manager->hasRole($roleSlug)) {
            throw ValidationException::withMessages([
                'manager_user_id' => ['کاربر انتخاب‌شده نقش '.$roleSlug.' ندارد.'],
            ]);
        }

        $managerNode = $tree->activeNodesFor($manager, $roleSlug)->first();
        if (! $managerNode) {
            throw ValidationException::withMessages([
                'manager_user_id' => ['گره سازمانی برای این مدیر یافت نشد.'],
            ]);
        }

        $childSlug = $roleSlug === 'sales_manager' ? 'representative' : 'sales_manager';
        $nodes = OrganizationNode::query()
            ->where('user_id', $target->id)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', $childSlug))
            ->get();

        if ($nodes->isEmpty()) {
            throw ValidationException::withMessages([
                'target_user_id' => ['گره سازمانی هدف برای جابجایی یافت نشد.'],
            ]);
        }

        DB::transaction(function () use ($nodes, $managerNode, $tree, $audit, $actor, $data) {
            foreach ($nodes as $node) {
                $tree->reparent($node, $managerNode);
            }
            $audit->record($actor, 'organization.reassign_manager', $managerNode, null, $data);
        });

        return response()->json([
            'ok' => true,
            'mode' => 'reassign',
            'moved' => $nodes->count(),
            'manager_node_id' => $managerNode->id,
        ]);
    }

    private function appointVacant(array $data, OrganizationTreeService $tree, AuditService $audit, WalletService $wallets, User $actor)
    {
        if (empty($data['appoint_user_id'])) {
            throw ValidationException::withMessages([
                'appoint_user_id' => ['کاربر مورد الحاق به سمت خالی الزامی است.'],
            ]);
        }

        $appoint = User::query()->findOrFail($data['appoint_user_id']);
        $parentUser = User::query()->findOrFail($data['manager_user_id']);
        $roleSlug = $data['manager_role'];
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        $parentNode = null;
        if ($roleSlug === 'sales_manager') {
            $parentNode = $tree->activeNodesFor($parentUser, 'development_manager')->first()
                ?? $tree->activeNodesFor($parentUser, 'senior_manager')->first();
        } else {
            $parentNode = $tree->activeNodesFor($parentUser, 'senior_manager')->first();
        }

        if (! $parentNode) {
            throw ValidationException::withMessages([
                'manager_user_id' => ['مافوق سازمانی مناسب برای این سمت یافت نشد.'],
            ]);
        }

        $node = DB::transaction(function () use ($appoint, $role, $roleSlug, $parentNode, $tree, $wallets, $audit, $actor, $data) {
            $userRole = UserRole::query()->firstOrNew([
                'user_id' => $appoint->id,
                'role_id' => $role->id,
            ]);
            if (! $userRole->exists) {
                $userRole->effective_from = now()->toDateString();
                $userRole->is_primary = false;
            }
            $userRole->is_active = true;
            $userRole->effective_to = null;
            $userRole->save();
            $wallets->walletFor($appoint, $role);

            $existing = $tree->activeNodesFor($appoint->fresh('roles'), $roleSlug)->first();
            if ($existing) {
                $node = $tree->reparent($existing, $parentNode);
            } else {
                $node = $tree->attach($appoint, $role, $parentNode, now()->toDateString());
            }

            $audit->record($actor, 'organization.appoint_vacant', $node, null, $data);

            return $node;
        });

        return response()->json([
            'ok' => true,
            'mode' => 'appoint',
            'node_id' => $node->id,
            'parent_node_id' => $parentNode->id,
        ]);
    }
}
