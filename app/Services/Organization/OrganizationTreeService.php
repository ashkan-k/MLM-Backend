<?php

namespace App\Services\Organization;

use App\Models\OrganizationNode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Collection;

class OrganizationTreeService
{
    public function __construct(private readonly WalletService $wallets) {}

    public function activeNodesFor(User $user, ?string $roleSlug = null): Collection
    {
        $query = OrganizationNode::query()
            ->where('user_id', $user->id)
            ->where('is_active', true);

        if ($roleSlug) {
            $query->whereHas('role', fn ($q) => $q->where('slug', $roleSlug));
        }

        return $query->get();
    }

    public function descendants(User $user, ?string $roleSlug = null): Collection
    {
        $nodes = $this->activeNodesFor($user, $roleSlug);
        $ids = collect();

        foreach ($nodes as $node) {
            $path = $node->path ?: '/'.$node->id.'/';
            $childIds = OrganizationNode::query()
                ->where('is_active', true)
                ->where('path', 'like', $path.'%')
                ->where('id', '!=', $node->id)
                ->pluck('user_id');
            $ids = $ids->merge($childIds);
        }

        return User::query()->whereIn('id', $ids->unique()->all())->get();
    }

    public function ancestors(User $user, ?string $roleSlug = null): Collection
    {
        $nodes = $this->activeNodesFor($user, $roleSlug);
        $ids = collect();

        foreach ($nodes as $node) {
            $current = $node;
            while ($current->parent_node_id) {
                $current = OrganizationNode::query()->find($current->parent_node_id);
                if (! $current) {
                    break;
                }
                $ids->push($current->user_id);
            }
        }

        return User::query()->whereIn('id', $ids->unique()->all())->get();
    }

    public function isDescendant(User $actor, User $target): bool
    {
        return $this->descendants($actor)->contains(fn (User $user) => $user->id === $target->id);
    }

    public function isAncestor(User $actor, User $target): bool
    {
        return $this->ancestors($actor)->contains(fn (User $user) => $user->id === $target->id);
    }

    public function relationship(User $actor, User $target): string
    {
        if ($actor->id === $target->id) {
            return 'self';
        }
        if ($this->isDescendant($actor, $target)) {
            return 'descendant';
        }
        if ($this->isAncestor($actor, $target)) {
            return 'ancestor';
        }

        return 'unrelated';
    }

    public function canCommunicate(User $actor, User $target): bool
    {
        $relation = $this->relationship($actor, $target);

        return in_array($relation, ['self', 'descendant', 'ancestor'], true);
    }

    public function attach(User $user, Role $role, ?OrganizationNode $parent, string $from): OrganizationNode
    {
        $node = OrganizationNode::query()->create([
            'user_id' => $user->id,
            'parent_node_id' => $parent?->id,
            'role_id' => $role->id,
            'effective_from' => $from,
            'is_active' => true,
        ]);

        $parentPath = $parent?->path ?: ($parent ? '/'.$parent->id.'/' : '/');
        $node->path = $parentPath.$node->id.'/';
        $node->save();

        return $node;
    }

    public function reparent(OrganizationNode $node, ?OrganizationNode $newParent): OrganizationNode
    {
        if ($newParent && ($newParent->id === $node->id || str_starts_with((string) $newParent->path, (string) $node->path))) {
            throw new \InvalidArgumentException('نمی‌توان گره را زیرمجموعهٔ خودش قرار داد.');
        }

        $oldPath = $node->path ?: '/'.$node->id.'/';
        $node->parent_node_id = $newParent?->id;
        $parentPath = $newParent?->path ?: '/';
        $node->path = $parentPath.$node->id.'/';
        $node->save();

        $descendants = OrganizationNode::query()
            ->where('path', 'like', $oldPath.'%')
            ->where('id', '!=', $node->id)
            ->orderBy('id')
            ->get();

        foreach ($descendants as $child) {
            $suffix = substr((string) $child->path, strlen($oldPath));
            $child->path = $node->path.$suffix;
            $child->save();
        }

        return $node->fresh(['user', 'role']);
    }

    /**
     * Empty-org default: senior holds senior + development + sales manager roles/nodes
     * (and representative so they can share a referral link), until real managers are promoted.
     */
    public function ensureSeniorManagerChain(User $senior): array
    {
        $seniorRole = Role::query()->where('slug', 'senior_manager')->firstOrFail();
        $devRole = Role::query()->where('slug', 'development_manager')->firstOrFail();
        $salesRole = Role::query()->where('slug', 'sales_manager')->firstOrFail();
        $repRole = Role::query()->where('slug', 'representative')->first();

        foreach ([$seniorRole, $devRole, $salesRole, $repRole] as $role) {
            if (! $role) {
                continue;
            }
            $userRole = UserRole::query()->firstOrNew([
                'user_id' => $senior->id,
                'role_id' => $role->id,
            ]);
            if (! $userRole->exists) {
                $userRole->effective_from = now()->toDateString();
                $userRole->is_primary = $role->slug === 'senior_manager';
            }
            $userRole->is_active = true;
            $userRole->effective_to = null;
            $userRole->save();
            $this->wallets->walletFor($senior, $role);
        }

        $senior = $senior->fresh('roles');

        $seniorNode = $this->activeNodesFor($senior, 'senior_manager')->first()
            ?? $this->attach($senior, $seniorRole, null, now()->toDateString());

        $devNode = $this->activeNodesFor($senior, 'development_manager')->first();
        if (! $devNode) {
            $devNode = $this->attach($senior, $devRole, $seniorNode, now()->toDateString());
        }

        $salesNode = $this->activeNodesFor($senior, 'sales_manager')->first();
        if (! $salesNode) {
            $salesNode = $this->attach($senior, $salesRole, $devNode, now()->toDateString());
        }

        return [
            'senior' => $seniorNode,
            'development' => $devNode,
            'sales' => $salesNode,
        ];
    }

    /** Parent node for a newly registered representative under their referrer. */
    public function registrationParentFor(User $referrer): ?OrganizationNode
    {
        $sm = $this->activeNodesFor($referrer, 'sales_manager')->first();
        if ($sm) {
            return $sm;
        }

        $repNode = $this->activeNodesFor($referrer, 'representative')->first();
        if ($repNode) {
            $current = $repNode;
            while ($current->parent_node_id) {
                $parent = OrganizationNode::query()->with('role')->find($current->parent_node_id);
                if (! $parent) {
                    break;
                }
                if ($parent->role?->slug === 'sales_manager') {
                    return $parent;
                }
                $current = $parent;
            }

            return $repNode->parent;
        }

        return OrganizationNode::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', 'sales_manager'))
            ->orderBy('id')
            ->first();
    }

    public function tree(?int $rootId = null): array
    {
        $nodes = OrganizationNode::query()
            ->with(['user:id,name,mobile,is_active', 'role:id,name,slug'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $byParent = $nodes->groupBy(fn ($n) => $n->parent_node_id ?: 0);

        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent[$parentId] ?? collect())->map(function ($node) use ($build) {
                $children = $build($node->id);
                $descendantCount = collect($children)->sum(fn ($child) => 1 + ($child['descendant_count'] ?? 0));

                return [
                    'id' => $node->id,
                    'user' => $node->user,
                    'role' => $node->role,
                    'descendant_count' => $descendantCount,
                    'children' => $children,
                ];
            })->values()->all();
        };

        if ($rootId) {
            $root = $nodes->firstWhere('id', $rootId);
            if (! $root) {
                return [];
            }
            $children = $build($root->id);

            return [[
                'id' => $root->id,
                'user' => $root->user,
                'role' => $root->role,
                'descendant_count' => collect($children)->sum(fn ($child) => 1 + ($child['descendant_count'] ?? 0)),
                'children' => $children,
            ]];
        }

        return $build(0);
    }
}
