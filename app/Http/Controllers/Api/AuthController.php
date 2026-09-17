<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferralCode;
use App\Models\ReferralShareMember;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\SharedLink;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Referral\SharedLinkService;
use App\Services\Wallet\WalletService;
use App\Services\Authorization\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function register(Request $request, OrganizationTreeService $tree, WalletService $wallets, SharedLinkService $sharedLinks)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'unique:users,mobile'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'referral_code' => ['nullable', 'string', 'required_without:shared_link_token'],
            'shared_link_token' => ['nullable', 'string', 'required_without:referral_code'],
        ], [
            'referral_code.required_without' => 'ثبت‌نام فقط از طریق لینک معرف یا لینک اشتراکی امکان‌پذیر است.',
            'shared_link_token.required_without' => 'ثبت‌نام فقط از طریق لینک معرف یا لینک اشتراکی امکان‌پذیر است.',
        ]);

        return DB::transaction(function () use ($data, $tree, $wallets, $sharedLinks) {
            $shareLink = null;
            $primaryReferrer = null;
            $referralCodeId = null;

            if (! empty($data['shared_link_token'])) {
                if (! SystemSetting::sharedLinkTypeEnabled('referral')) {
                    throw ValidationException::withMessages([
                        'shared_link_token' => ['ثبت‌نام با لینک اشتراکی فعلاً غیرفعال است.'],
                    ]);
                }

                $shareLink = SharedLink::query()
                    ->with('members.user')
                    ->where('token', $data['shared_link_token'])
                    ->where('type', 'referral')
                    ->first();

                if (! $shareLink) {
                    throw ValidationException::withMessages([
                        'shared_link_token' => ['لینک اشتراکی نامعتبر است یا برای ثبت‌نام نیست.'],
                    ]);
                }
                if ($shareLink->status !== 'active' || $shareLink->used_at) {
                    $message = $shareLink->status === 'pending'
                        ? 'تا وقتی همه اعضای شریک لینک را تایید نکنند، این لینک فعال نمی‌شود.'
                        : 'این لینک اشتراکی هنوز فعال نیست یا قبلاً استفاده شده است.';
                    throw ValidationException::withMessages([
                        'shared_link_token' => [$message],
                    ]);
                }
                if ($shareLink->expires_at && $shareLink->expires_at->isPast()) {
                    throw ValidationException::withMessages([
                        'shared_link_token' => ['مهلت این لینک اشتراکی به پایان رسیده است.'],
                    ]);
                }
                if ($shareLink->members->isEmpty()) {
                    throw ValidationException::withMessages([
                        'shared_link_token' => ['لینک اشتراکی عضوی ندارد.'],
                    ]);
                }

                $primaryReferrer = User::query()->findOrFail($shareLink->creator_user_id);
            } else {
                $ref = ReferralCode::query()
                    ->where('code', $data['referral_code'])
                    ->where('is_active', true)
                    ->first();
                if (! $ref) {
                    throw ValidationException::withMessages([
                        'referral_code' => ['کد معرف نامعتبر یا غیرفعال است. از لینک معرفی نماینده استفاده کنید.'],
                    ]);
                }
                $primaryReferrer = $ref->user;
                $referralCodeId = $ref->id;
            }

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

            $referral = RepresentativeReferral::query()->create([
                'referred_user_id' => $user->id,
                'referrer_user_id' => $primaryReferrer->id,
                'source' => $shareLink ? 'shared_link' : 'finopal',
                'referral_code_id' => $referralCodeId,
            ]);

            $referrerRole = Role::query()->where('slug', 'representative_referrer')->first();

            if ($shareLink) {
                foreach ($shareLink->members as $member) {
                    ReferralShareMember::query()->create([
                        'referral_id' => $referral->id,
                        'user_id' => $member->user_id,
                        'share_percent' => $member->share_percent,
                        'approved_at' => $member->approved_at ?? now(),
                    ]);
                    if ($referrerRole && $member->user && ! $member->user->hasRole('representative_referrer')) {
                        UserRole::query()->firstOrCreate(
                            ['user_id' => $member->user_id, 'role_id' => $referrerRole->id],
                            [
                                'effective_from' => now()->toDateString(),
                                'is_active' => true,
                            ]
                        );
                        $wallets->walletFor($member->user, $referrerRole);
                    }
                }
                $sharedLinks->consume($shareLink);
            } else {
                if ($referrerRole && ! $primaryReferrer->hasRole('representative_referrer')) {
                    UserRole::query()->create([
                        'user_id' => $primaryReferrer->id,
                        'role_id' => $referrerRole->id,
                        'effective_from' => now()->toDateString(),
                        'is_active' => true,
                    ]);
                    $wallets->walletFor($primaryReferrer, $referrerRole);
                }
            }

            $parent = $tree->registrationParentFor($primaryReferrer);
            $tree->attach($user, $repRole, $parent, now()->toDateString());
            [, $plain] = $user->createApiToken($repRole->id);

            return response()->json([
                'token' => $plain,
                'user' => $this->payload($user->fresh('roles'), $repRole),
                'referral_code' => $code->code,
            ], 201);
        });
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
            'features' => [
                'shared_links' => SystemSetting::sharedLinkFeatures(),
            ],
        ];
    }
}
