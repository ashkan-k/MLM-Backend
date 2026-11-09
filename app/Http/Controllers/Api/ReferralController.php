<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\SharedLink;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Referral\SharedLinkService;
use App\Services\Sms\SmsService;
use App\Support\ReferralCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ReferralController extends Controller
{
    public function codes(Request $request)
    {
        $user = $request->user();
        $row = ReferralCode::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'code' => ReferralCodeGenerator::unique(),
                'source' => 'finopal',
                'is_active' => true,
            ]
        );

        if (ReferralCodeGenerator::looksPredictable($row->code)) {
            $row->code = ReferralCodeGenerator::unique();
            $row->save();
        }

        return response()->json(
            ReferralCode::query()->where('user_id', $user->id)->where('is_active', true)->get()
                ->map(function (ReferralCode $code) {
                    if (ReferralCodeGenerator::looksPredictable($code->code)) {
                        $code->code = ReferralCodeGenerator::unique();
                        $code->save();
                    }

                    return [
                        'id' => $code->id,
                        'code' => $code->code,
                        'finopal_signup_url' => \App\Services\Integration\Finopal\FinopalSignupWebhookService::signupUrl($code->code),
                        'signup_links' => \App\Services\Integration\Finopal\FinopalSignupWebhookService::signupLinks($code->code),
                    ];
                })
        );
    }

    public function referrals(Request $request)
    {
        return response()->json(
            RepresentativeReferral::query()
                ->with(['referred:id,name,mobile', 'shareMembers.user'])
                ->where(function ($q) use ($request) {
                    $q->where('referrer_user_id', $request->user()->id)
                        ->orWhereHas('shareMembers', fn ($m) => $m->where('user_id', $request->user()->id));
                })
                ->latest()
                ->get()
        );
    }

    public function showByToken(string $token)
    {
        $link = SharedLink::query()
            ->with('members.user:id,name,mobile')
            ->where('token', $token)
            ->first();

        if (! $link) {
            return response()->json(['message' => 'لینک اشتراکی یافت نشد.'], 404);
        }

        $featureOn = SystemSetting::sharedLinkTypeEnabled($link->type);
        $usable = $featureOn
            && $link->status === 'active'
            && ! $link->used_at
            && (! $link->expires_at || ! $link->expires_at->isPast());

        return response()->json([
            'token' => $link->token,
            'type' => $link->type,
            'status' => $link->status,
            'used' => (bool) $link->used_at,
            'expired' => $link->expires_at ? $link->expires_at->isPast() : false,
            'pending_approvals' => $link->status === 'pending',
            'feature_enabled' => $featureOn,
            'usable' => $usable,
        ]);
    }

    public function partners()
    {
        return response()->json(
            User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('slug', 'representative'))
                ->whereDoesntHave('roles', fn ($q) => $q->where('slug', 'superuser'))
                ->with('roles')
                ->orderBy('name')
                ->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'mobile' => $u->mobile,
                    'roles' => $u->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug]),
                ])
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

        if (! SystemSetting::sharedLinkTypeEnabled($data['type'])) {
            throw ValidationException::withMessages([
                'type' => ['این نوع لینک اشتراکی فعلاً توسط مدیر سامانه غیرفعال شده است.'],
            ]);
        }

        return response()->json($service->create($request->user(), $data['type'], $data['members']), 201);
    }

    public function approveSharedLink(Request $request, SharedLink $sharedLink, SharedLinkService $service)
    {
        return response()->json($service->approve($request->user(), $sharedLink));
    }

    /**
     * ارسال لینک معرف/فروش با پیامک به شماره دلخواه.
     * متن از قالب config ساخته می‌شود تا بعداً بتوان template سامانه‌ای گذاشت.
     */
    public function shareSms(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
            'link' => ['required', 'url', 'max:2000'],
            'kind' => ['nullable', 'string', 'in:register,sale,shared,code'],
        ]);

        $mobile = $this->normalizeIranMobile($data['mobile']);
        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => ['شماره موبایل معتبر نیست. مثال: 09xxxxxxxxx'],
            ]);
        }

        $user = $request->user();
        if (! $this->userOwnsShareLink($user, $data['link'])) {
            throw ValidationException::withMessages([
                'link' => ['این لینک متعلق به حساب شما نیست.'],
            ]);
        }

        $max = max(1, (int) config('sms.share_link_max_per_hour', 15));
        $rateKey = 'share-sms:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, $max)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'success' => false,
                'message' => 'تعداد ارسال پیامک بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.',
                'reason' => 'rate_limited',
                'retry_after' => $seconds,
            ], 429);
        }

        $message = strtr((string) config('sms.share_link_template'), [
            '{link}' => $data['link'],
            '{name}' => (string) ($user->name ?? ''),
        ]);

        // آماده برای سوییچ به pattern/template سامانه‌ای در آینده
        $templateId = trim((string) config('sms.share_link_template_id', ''));

        $result = $sms->sendText($mobile, $message, [
            'type' => 'share_link',
            'user_id' => $user->id,
            'kind' => $data['kind'] ?? null,
            'template_id' => $templateId !== '' ? $templateId : null,
            'link' => $data['link'],
        ]);

        $ok = (bool) ($result['success'] ?? false);
        $providerKey = (string) ($result['provider_key'] ?? '');
        $loggedOnly = $ok && ($providerKey === 'log' || str_contains((string) ($result['reason'] ?? ''), 'بدون ارسال واقعی'));
        $delivered = $ok && ! $loggedOnly;

        if ($delivered) {
            RateLimiter::hit($rateKey, 3600);
        }

        return response()->json([
            'success' => $delivered,
            'logged_only' => $loggedOnly,
            'message' => $loggedOnly
                ? 'سامانه پیامک واقعی روی سرور تنظیم نشده است؛ فعلاً فقط در لاگ ثبت شد و به موبایل ارسال نمی‌شود.'
                : ($delivered
                    ? 'پیامک با لینک شما ارسال شد.'
                    : ($result['reason'] ?? 'ارسال پیامک ناموفق بود.')),
            'reason' => $result['reason'] ?? null,
            'provider' => $result['provider'] ?? null,
            'provider_key' => $providerKey ?: null,
            'mobile' => $mobile,
        ], $delivered ? 200 : 422);
    }

    private function normalizeIranMobile(string $phone): ?string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];
        $digits = preg_replace('/\D+/', '', strtr(trim($phone), $map)) ?? '';
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
    }

    private function userOwnsShareLink(User $user, string $link): bool
    {
        $codes = ReferralCode::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('code');

        foreach ($codes as $code) {
            if (is_string($code) && $code !== '' && str_contains($link, $code)) {
                return true;
            }
        }

        if (preg_match('/(?:[?&]share=|shared-link\/)([A-Za-z0-9_-]+)/', $link, $m)) {
            return SharedLink::query()
                ->where('token', $m[1])
                ->where(function ($q) use ($user) {
                    $q->where('creator_user_id', $user->id)
                        ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id));
                })
                ->exists();
        }

        return false;
    }
}
