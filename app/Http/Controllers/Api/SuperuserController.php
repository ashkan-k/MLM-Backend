<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\CommissionRuleVersion;
use App\Models\Course;
use App\Models\FraSoftSyncLog;
use App\Models\GatewaySale;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Wallet;
use App\Services\Audit\AuditService;
use App\Services\Integration\FraSoft\FraSoftSyncService;
use App\Services\Integration\FraSoft\FraSoftWebhookHandler;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;

class SuperuserController extends Controller
{
    public function stats()
    {
        return response()->json([
            'users' => User::query()->count(),
            'active_users' => User::query()->where('is_active', true)->count(),
            'sales' => GatewaySale::query()->count(),
            'commission_total' => Commission::query()->sum('commission_amount'),
            'wallets' => Wallet::query()->count(),
            'wallet_balance' => Wallet::query()->sum('balance'),
        ]);
    }

    public function users()
    {
        return response()->json(User::query()->with('roles')->latest()->paginate(30));
    }

    public function storeUser(Request $request, WalletService $wallets, OrganizationTreeService $tree, AuditService $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'mobile' => ['required', 'unique:users,mobile'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'role_slugs' => ['required', 'array'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'mobile' => $data['mobile'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'is_active' => true,
        ]);

        foreach ($data['role_slugs'] as $slug) {
            $role = Role::query()->where('slug', $slug)->firstOrFail();
            UserRole::query()->create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'effective_from' => now()->toDateString(),
                'is_active' => true,
            ]);
            if ($role->is_organizational) {
                $wallets->walletFor($user, $role);
                $tree->attach($user, $role, $tree->activeNodesFor($request->user())->first(), now()->toDateString());
            }
        }

        $audit->record($request->user(), 'user.created', $user);

        return response()->json($user->load('roles'), 201);
    }

    public function assignRole(Request $request, User $user, WalletService $wallets, AuditService $audit)
    {
        $data = $request->validate(['role_slug' => ['required', 'string']]);
        $role = Role::query()->where('slug', $data['role_slug'])->firstOrFail();
        UserRole::query()->firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id],
            ['effective_from' => now()->toDateString(), 'is_active' => true]
        );
        if ($role->is_organizational) {
            $wallets->walletFor($user, $role);
        }
        $audit->record($request->user(), 'role.assigned', $user, null, ['role' => $role->slug]);

        return response()->json($user->fresh('roles'));
    }

    public function roles()
    {
        return response()->json(Role::query()->with('permissions')->get());
    }

    public function permissions()
    {
        return response()->json(Permission::query()->get());
    }

    public function assignPermission(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'role_id' => ['nullable', 'exists:roles,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'permission_id' => ['required', 'exists:permissions,id'],
            'allowed' => ['required', 'boolean'],
        ]);

        if (! empty($data['role_id'])) {
            $role = Role::query()->findOrFail($data['role_id']);
            $role->permissions()->syncWithoutDetaching([
                $data['permission_id'] => ['allowed' => $data['allowed']],
            ]);
            $audit->record($request->user(), 'permission.role_assigned', $role, null, $data);
        }

        if (! empty($data['user_id'])) {
            $user = User::query()->findOrFail($data['user_id']);
            \DB::table('user_permissions')->updateOrInsert(
                ['user_id' => $user->id, 'permission_id' => $data['permission_id']],
                ['allowed' => $data['allowed']]
            );
            $audit->record($request->user(), 'permission.user_override', $user, null, $data);
        }

        return response()->json(['ok' => true]);
    }

    public function settings()
    {
        return response()->json(SystemSetting::query()->get());
    }

    public function updateSetting(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'value' => ['required'],
            'value_type' => ['nullable', 'string'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $setting = SystemSetting::query()->updateOrCreate(
            ['key' => $data['key']],
            [
                'value' => $data['value'],
                'value_type' => $data['value_type'] ?? 'json',
                'is_public' => $data['is_public'] ?? false,
            ]
        );
        $audit->record($request->user(), 'setting.updated', $setting, null, $data);

        return response()->json($setting);
    }

    public function commissionRules()
    {
        return response()->json(CommissionRule::query()->with('versions')->get());
    }

    public function updateRule(Request $request, CommissionRule $rule, AuditService $audit)
    {
        $data = $request->validate([
            'percent' => ['required', 'numeric'],
            'qualified_percent' => ['nullable', 'numeric'],
            'conditions' => ['nullable', 'array'],
        ]);

        $version = $rule->versions()->create([
            'version' => ((int) $rule->versions()->max('version')) + 1,
            'percent' => $data['percent'],
            'qualified_percent' => $data['qualified_percent'] ?? null,
            'conditions' => $data['conditions'] ?? null,
            'effective_from' => now(),
        ]);
        $audit->record($request->user(), 'commission_rule.versioned', $rule, null, $version->toArray());

        return response()->json($rule->fresh('versions'));
    }

    public function courses(Request $request)
    {
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'title' => ['required', 'string'],
                'description' => ['nullable', 'string'],
                'is_required_for_promotion' => ['boolean'],
                'role_ids' => ['array'],
                'levels' => ['array'],
                'levels.*.title' => ['required', 'string'],
                'levels.*.sort_order' => ['required', 'integer'],
                'levels.*.passing_score' => ['required', 'numeric'],
            ]);
            $course = Course::query()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'is_active' => true,
                'is_required_for_promotion' => $data['is_required_for_promotion'] ?? false,
            ]);
            $course->roles()->sync($data['role_ids'] ?? []);
            foreach ($data['levels'] ?? [] as $level) {
                $course->levels()->create($level + ['is_active' => true]);
            }

            return response()->json($course->load(['levels', 'roles']), 201);
        }

        return response()->json(Course::query()->with(['levels', 'roles'])->get());
    }

    public function audits()
    {
        return response()->json(AuditLog::query()->with('actor:id,name,mobile')->latest()->paginate(30));
    }

    public function frasoftLogs()
    {
        return response()->json(FraSoftSyncLog::query()->latest()->paginate(30));
    }

    public function frasoftSync(Request $request, FraSoftSyncService $sync)
    {
        $data = $request->validate([
            'event' => ['required', 'string'],
            'payload' => ['required', 'array'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        return response()->json($sync->inbound($data['event'], $data['payload'], $data['idempotency_key'] ?? uniqid('fs-', true)));
    }

    public function frasoftWebhook(Request $request, FraSoftWebhookHandler $handler)
    {
        return response()->json($handler->handle($request->all()));
    }
}
