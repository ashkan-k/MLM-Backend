<?php

namespace App\Services\Organization;

use App\Models\OrganizationNode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Collection;

class OrganizationTreeService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * Align with frontend OrgTree normalize(): Persian/Arabic digits and ی/ک variants.
     */
    private function normalizeSearch(string $value): string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ك' => 'ک',
        ];

        return mb_strtolower(strtr(trim($value), $map));
    }

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
        $ids = $this->descendantUserIds($user, $roleSlug);

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids->all())->get();
    }

    /** Lightweight count — does not hydrate User models. */
    public function descendantCount(User $user, ?string $roleSlug = null): int
    {
        return $this->descendantUserIds($user, $roleSlug)->count();
    }

    /** @return Collection<int, int> */
    public function descendantUserIds(User $user, ?string $roleSlug = null): Collection
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

        return $ids->unique()->values();
    }

    /**
     * Paginate downline users without loading the full tree into memory.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateDescendants(User $user, int $perPage = 20, ?string $search = null, ?string $roleSlug = null)
    {
        $perPage = max(1, min(50, $perPage));
        $nodes = $this->activeNodesFor($user);
        if ($nodes->isEmpty()) {
            return User::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        $nodeQuery = OrganizationNode::query()
            ->where('organization_nodes.is_active', true)
            ->where(function ($q) use ($nodes) {
                foreach ($nodes as $node) {
                    $path = $node->path ?: '/'.$node->id.'/';
                    $q->orWhere(function ($inner) use ($path, $node) {
                        $inner->where('organization_nodes.path', 'like', $path.'%')
                            ->where('organization_nodes.id', '!=', $node->id);
                    });
                }
            });

        if ($roleSlug) {
            $nodeQuery->whereHas('role', fn ($q) => $q->where('slug', $roleSlug));
        }

        $userIds = $nodeQuery->select('organization_nodes.user_id')->distinct();

        return User::query()
            ->with('roles:id,name,slug')
            ->whereIn('id', $userIds)
            ->when($search, function ($q) use ($search) {
                $term = '%'.trim($search).'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('mobile', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate($perPage);
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
        return $this->descendantUserIds($actor)->contains($target->id);
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

    /**
     * After someone becomes sales manager (promotion or vacant appoint):
     * place their own representative node and all referred representatives under the new SM node.
     */
    public function rehomeUnderNewSalesManager(User $salesManager, OrganizationNode $salesNode): void
    {
        $ownRep = OrganizationNode::query()
            ->where('user_id', $salesManager->id)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->first();
        if ($ownRep && (int) $ownRep->parent_node_id !== (int) $salesNode->id) {
            $this->reparent($ownRep, $salesNode);
        }

        $referredIds = RepresentativeReferral::query()
            ->where(function ($q) use ($salesManager) {
                $q->where('referrer_user_id', $salesManager->id)
                    ->orWhereHas('shareMembers', fn ($m) => $m->where('user_id', $salesManager->id));
            })
            ->where('referred_user_id', '!=', $salesManager->id)
            ->pluck('referred_user_id');

        OrganizationNode::query()
            ->whereIn('user_id', $referredIds)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', 'representative'))
            ->get()
            ->each(function (OrganizationNode $node) use ($salesNode) {
                if ((int) $node->parent_node_id !== (int) $salesNode->id) {
                    $this->reparent($node, $salesNode);
                }
            });
    }

    /**
     * After promotion to a higher org role (e.g. SM→DM): nest this user's lower-role nodes
     * under the new higher node so the downline stays with them (not bubbled to senior).
     */
    public function nestLowerRoleNodesUnder(User $user, OrganizationNode $higherNode): void
    {
        $higherNode->loadMissing('role');
        $higherLevel = (int) ($higherNode->role?->hierarchy_level ?? 0);

        OrganizationNode::query()
            ->with('role')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('id', '!=', $higherNode->id)
            ->get()
            ->filter(fn (OrganizationNode $node) => (int) ($node->role?->hierarchy_level ?? 99) > $higherLevel)
            ->each(function (OrganizationNode $node) use ($higherNode) {
                if ((int) $node->parent_node_id !== (int) $higherNode->id) {
                    $this->reparent($node, $higherNode);
                }
            });
    }

    /**
     * Demote: deactivate managerial roles ranked above $keepRoleSlug and move their
     * downline under $teamParent (or $keepNode) so the chart shows the appointed role.
     *
     * @return list<string> deactivated role slugs
     */
    public function deactivateHigherManagerRoles(
        User $user,
        string $keepRoleSlug,
        OrganizationNode $keepNode,
        ?OrganizationNode $teamParent = null,
    ): array {
        $keepRole = Role::query()->where('slug', $keepRoleSlug)->firstOrFail();
        $keepLevel = (int) $keepRole->hierarchy_level;
        $destForTeam = $teamParent ?? $keepNode;

        $higher = Role::query()
            ->whereIn('slug', ['sales_manager', 'development_manager', 'senior_manager'])
            ->where('hierarchy_level', '<', $keepLevel)
            ->orderBy('hierarchy_level')
            ->get();

        $deactivated = [];
        foreach ($higher as $role) {
            $hadRole = UserRole::query()
                ->where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->where('is_active', true)
                ->exists();
            if (! $hadRole) {
                continue;
            }

            $nodes = $this->activeNodesFor($user, $role->slug);
            foreach ($nodes as $node) {
                OrganizationNode::query()
                    ->where('parent_node_id', $node->id)
                    ->where('is_active', true)
                    ->get()
                    ->each(function (OrganizationNode $child) use ($keepNode, $destForTeam) {
                        if ((int) $child->id === (int) $keepNode->id) {
                            return;
                        }
                        if ((int) $child->parent_node_id !== (int) $destForTeam->id) {
                            $this->reparent($child, $destForTeam);
                        }
                    });

                $node->is_active = false;
                $node->effective_to = now()->toDateString();
                $node->save();
            }

            UserRole::query()
                ->where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'effective_to' => now()->toDateString(),
                ]);

            $deactivated[] = $role->slug;
        }

        return $deactivated;
    }

    /** Parent node for a newly registered representative under their referrer. */
    public function registrationParentFor(User $referrer): ?OrganizationNode
    {
        // Senior always recruits into their own default SM slot (empty-org chain).
        if ($referrer->hasRole('senior_manager')) {
            return $this->ensureSeniorManagerChain($referrer)['sales'];
        }

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

    /**
     * Org chart for UI: each user appears once with their highest role.
     * Children of a hidden lower-role node attach under that user's visible node.
     *
     * @param  ?int  $rootId  Scope chart under this org node (visible role of that user).
     * @param  int  $maxDepth  How many child levels to embed (0 = node only / children empty). Default 1.
     * @param  ?int  $parentId  If set, return only direct visible children of this parent (lazy expand).
     * @param  ?string  $search  When set, return pruned paths from roots to matching name/mobile (server-side search).
     */
    public function tree(?int $rootId = null, int $maxDepth = 1, ?int $parentId = null, ?string $search = null): array
    {
        $maxDepth = max(0, min($maxDepth, 32));
        $search = $search !== null ? trim($search) : null;
        if ($search === '') {
            $search = null;
        }

        $nodes = OrganizationNode::query()
            ->with(['user:id,name,mobile,is_active', 'role:id,name,slug,hierarchy_level'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'user_id', 'parent_node_id', 'role_id', 'path', 'is_active']);

        $bestLevelByUser = [];
        $visibleIdByUser = [];
        foreach ($nodes as $node) {
            $uid = (int) $node->user_id;
            $level = (int) ($node->role?->hierarchy_level ?? 99);
            if (! isset($bestLevelByUser[$uid]) || $level < $bestLevelByUser[$uid]) {
                $bestLevelByUser[$uid] = $level;
                $visibleIdByUser[$uid] = (int) $node->id;
            }
        }

        $isVisible = function (OrganizationNode $node) use ($bestLevelByUser): bool {
            $uid = (int) $node->user_id;
            $level = (int) ($node->role?->hierarchy_level ?? 99);

            return ($bestLevelByUser[$uid] ?? $level) === $level;
        };

        $nodesById = $nodes->keyBy('id');

        $effectiveParentId = function (OrganizationNode $node) use ($nodesById, $isVisible, $visibleIdByUser): int {
            $pid = (int) ($node->parent_node_id ?: 0);
            if ($pid === 0) {
                return 0;
            }
            $parent = $nodesById->get($pid);
            if (! $parent) {
                return 0;
            }
            if (! $isVisible($parent)) {
                return (int) ($visibleIdByUser[(int) $parent->user_id] ?? $pid);
            }

            return $pid;
        };

        /** @var array<int, list<OrganizationNode>> $byParent */
        $byParent = [];
        /** @var array<int, int> $effParent */
        $effParent = [];
        foreach ($nodes as $node) {
            if (! $isVisible($node)) {
                continue;
            }
            $id = (int) $node->id;
            $pid = $effectiveParentId($node);
            $byParent[$pid][] = $node;
            $effParent[$id] = $pid;
        }

        if ($search !== null && $parentId === null) {
            $needle = $this->normalizeSearch($search);
            $matchIds = [];
            foreach ($byParent as $list) {
                foreach ($list as $node) {
                    $hay = $this->normalizeSearch(
                        ($node->user?->name ?? '').' '.
                        ($node->user?->mobile ?? '').' '.
                        ($node->role?->name ?? '').' '.
                        $node->id
                    );
                    if ($hay !== '' && str_contains($hay, $needle)) {
                        $matchIds[(int) $node->id] = true;
                    }
                }
            }

            // Scope matches under rootId when provided
            if ($rootId && $matchIds !== []) {
                $root = $nodes->firstWhere('id', $rootId);
                if ($root) {
                    $visibleRootId = $visibleIdByUser[(int) $root->user_id] ?? (int) $root->id;
                    $rootPathPrefix = (string) ($nodesById->get($visibleRootId)?->path ?? '');
                    foreach (array_keys($matchIds) as $mid) {
                        $mNode = $nodesById->get($mid);
                        if ($mNode && $rootPathPrefix !== '' && ! str_starts_with((string) $mNode->path, $rootPathPrefix)
                            && (int) $mid !== (int) $visibleRootId) {
                            // also allow if ancestor chain reaches visible root via effParent
                            $ok = false;
                            $cur = $mid;
                            $g = 0;
                            while ($cur && $g++ < 200) {
                                if ($cur === (int) $visibleRootId) {
                                    $ok = true;
                                    break;
                                }
                                $cur = $effParent[$cur] ?? 0;
                            }
                            if (! $ok) {
                                unset($matchIds[$mid]);
                            }
                        }
                    }
                }
            }

            $keep = [];
            foreach (array_keys($matchIds) as $mid) {
                $cur = $mid;
                $g = 0;
                while ($cur && $g++ < 200) {
                    $keep[$cur] = true;
                    $cur = $effParent[$cur] ?? 0;
                }
            }

            $pruned = [];
            foreach ($byParent as $pid => $list) {
                foreach ($list as $node) {
                    if (isset($keep[(int) $node->id])) {
                        $pruned[$pid][] = $node;
                    }
                }
            }
            $byParent = $pruned;
            // Show full path to matches (ancestors + match); siblings off-path already pruned
            $maxDepth = max($maxDepth, 24);
        }

        $descendantMemo = [];
        $descendantCount = function (int $nodeId) use (&$descendantCount, &$descendantMemo, $byParent): int {
            if (isset($descendantMemo[$nodeId])) {
                return $descendantMemo[$nodeId];
            }
            $total = 0;
            foreach ($byParent[$nodeId] ?? [] as $child) {
                $total += 1 + $descendantCount((int) $child->id);
            }

            return $descendantMemo[$nodeId] = $total;
        };

        $serialize = function (int $parentId, int $depthLeft) use (&$serialize, $byParent, $descendantCount): array {
            $out = [];
            foreach ($byParent[$parentId] ?? [] as $node) {
                $id = (int) $node->id;
                $rawKids = $byParent[$id] ?? [];
                $hasChildren = $rawKids !== [];
                $children = ($depthLeft > 0 && $hasChildren)
                    ? $serialize($id, $depthLeft - 1)
                    : [];

                $out[] = [
                    'id' => $id,
                    'user' => $node->user,
                    'role' => $node->role,
                    'descendant_count' => $descendantCount($id),
                    'has_children' => $hasChildren,
                    'children' => $children,
                ];
            }

            return $out;
        };

        if ($parentId !== null) {
            if (! $nodesById->has($parentId)) {
                return [];
            }

            return $serialize($parentId, max(0, $maxDepth));
        }

        if ($rootId) {
            $root = $nodes->firstWhere('id', $rootId);
            if (! $root) {
                return [];
            }
            $rootUid = (int) $root->user_id;
            $visibleRootId = $visibleIdByUser[$rootUid] ?? (int) $root->id;
            $visibleRoot = $nodesById->get($visibleRootId) ?? $root;
            $vid = (int) $visibleRoot->id;

            $rawKids = $byParent[$vid] ?? [];
            $hasChildren = $rawKids !== [];
            $children = ($maxDepth > 0 && $hasChildren)
                ? $serialize($vid, $maxDepth - 1)
                : [];

            // If searching and root itself not kept and no children, return empty
            if ($search !== null && ! $hasChildren) {
                $selfHay = $this->normalizeSearch(
                    ($visibleRoot->user?->name ?? '').' '.
                    ($visibleRoot->user?->mobile ?? '').' '.
                    ($visibleRoot->role?->name ?? '').' '.$vid
                );
                if (! str_contains($selfHay, $this->normalizeSearch($search))) {
                    return [];
                }
            }

            return [[
                'id' => $vid,
                'user' => $visibleRoot->user,
                'role' => $visibleRoot->role,
                'descendant_count' => $descendantCount($vid),
                'has_children' => $hasChildren,
                'children' => $children,
            ]];
        }

        return $serialize(0, $maxDepth);
    }
}
