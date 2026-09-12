<?php

namespace App\Services\Organization;

use App\Models\OrganizationNode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

class OrganizationTreeService
{
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

    public function tree(?int $rootId = null): array
    {
        $nodes = OrganizationNode::query()
            ->with(['user:id,name,mobile', 'role:id,name,slug'])
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
