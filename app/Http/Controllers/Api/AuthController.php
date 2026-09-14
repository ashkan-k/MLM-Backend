<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Wallet\WalletService;
use App\Services\Authorization\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'mobile' => ['required', 'string'],
            'password' => ['required', 'string'],
            'role_slug' => ['nullable', 'string'],
        ]);

        $user = User::query()->where('mobile', $data['mobile'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['mobile' => ['اطلاعات ورود صحیح نیست.']]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['mobile' => ['حساب شما مسدود است.']]);
        }

        $role = null;
        if (! empty($data['role_slug'])) {
            $role = $user->activeRoles()->where('slug', $data['role_slug'])->first();
            if (! $role) {
                throw ValidationException::withMessages(['role_slug' => ['این نقش برای شما فعال نیست.']]);
            }
        } else {
            $role = $user->activeRoles()->orderBy('hierarchy_level')->first();
        }

        [, $plain] = $user->createApiToken($role?->id);

        return response()->json([
            'token' => $plain,
            'user' => $this->payload($user->fresh('roles'), $role),
        ]);
    }

    public function register(Request $request, OrganizationTreeService $tree, WalletService $wallets)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'unique:users,mobile'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'referral_code' => ['nullable', 'string'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'mobile' => $data['mobile'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'is_active' => true,
        ]);

        $repRole = Role::query()->where('slug', 'representative')->firstOrFail();
        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $repRole->id,
            'effective_from' => now()->toDateString(),
            'is_primary' => true,
            'is_active' => true,
        ]);
        $wallets->walletFor($user, $repRole);

        $code = ReferralCode::query()->create([
            'user_id' => $user->id,
            'code' => 'R'.$user->id.strtoupper(substr(md5($user->mobile), 0, 6)),
            'source' => 'finopal',
            'is_active' => true,
        ]);

        $parent = null;
        if (! empty($data['referral_code'])) {
            $ref = ReferralCode::query()->where('code', $data['referral_code'])->where('is_active', true)->first();
            if ($ref) {
                RepresentativeReferral::query()->create([
                    'referred_user_id' => $user->id,
                    'referrer_user_id' => $ref->user_id,
                    'source' => 'finopal',
                    'referral_code_id' => $ref->id,
                ]);
                $parent = $tree->activeNodesFor($ref->user)->first();
                $referrerRole = Role::query()->where('slug', 'representative_referrer')->first();
                if ($referrerRole && ! $ref->user->hasRole('representative_referrer')) {
                    UserRole::query()->create([
                        'user_id' => $ref->user_id,
                        'role_id' => $referrerRole->id,
                        'effective_from' => now()->toDateString(),
                        'is_active' => true,
                    ]);
                    $wallets->walletFor($ref->user, $referrerRole);
                }
            }
        }

        $tree->attach($user, $repRole, $parent, now()->toDateString());
        [, $plain] = $user->createApiToken($repRole->id);

        return response()->json([
            'token' => $plain,
            'user' => $this->payload($user->fresh('roles'), $repRole),
            'referral_code' => $code->code,
        ], 201);
    }

    public function me(Request $request)
    {
        $role = $request->attributes->get('active_role');

        return response()->json($this->payload($request->user()->load('roles'), $role));
    }

    public function logout(Request $request)
    {
        $request->attributes->get('api_token')?->delete();

        return response()->json(['ok' => true]);
    }

    public function switchRole(Request $request)
    {
        $data = $request->validate(['role_slug' => ['required', 'string']]);
        $user = $request->user();
        $role = $user->activeRoles()->where('slug', $data['role_slug'])->first();
        if (! $role) {
            abort(403, 'نقش انتخاب‌شده برای شما فعال نیست.');
        }

        $token = $request->attributes->get('api_token');
        $token->active_role_id = $role->id;
        $token->save();

        return response()->json($this->payload($user->fresh('roles'), $role));
    }

    private function payload(User $user, $role): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'mobile' => $user->mobile,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'is_superuser' => $user->isSuperuser(),
            'roles' => $user->roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'hierarchy_level' => $r->hierarchy_level,
                'is_organizational' => (bool) $r->is_organizational,
                'is_active' => (bool) $r->pivot->is_active,
            ])->values(),
            'active_role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ] : null,
            'permissions' => app(PermissionService::class)->slugsFor($user, $role),
        ];
    }
}
