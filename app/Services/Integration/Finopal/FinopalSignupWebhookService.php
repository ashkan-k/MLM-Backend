<?php

namespace App\Services\Integration\Finopal;

use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\ReferralCode;
use App\Models\RepresentativeReferral;
use App\Models\Role;
use App\Models\User;
use App\Support\JalaliDate;
use App\Models\UserRole;
use App\Services\Gateway\GatewaySaleService;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Sms\SmsService;
use App\Services\Wallet\WalletService;
use App\Support\ReferralCodeGenerator;
use App\Support\UniqueConstraint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inbound Finopal signup, in two steps. The person may register on finopal.ir and only
 * finish the gateway days later, so user creation and gateway creation are separate webhooks.
 * A new user is placed under the referrer. A later gateway is attached to that same user
 * and the manager chain already stored on them.
 */
class FinopalSignupWebhookService
{
    public function __construct(
        private readonly OrganizationTreeService $tree,
        private readonly WalletService $wallets,
        private readonly SmsService $sms,
        private readonly GatewaySaleService $sales,
    ) {}

    /**
     * Step 1. The person finished signup on Finopal. Create the representative when missing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function registerUser(array $data): array
    {
        $userInput = $data['user'];
        $nationalId = $this->digits((string) ($userInput['national_id'] ?? ''));
        $mobile = $this->mobile((string) ($userInput['mobile'] ?? ''));
        $lockKey = 'finopal-signup:'.($nationalId !== '' ? $nationalId : $mobile);

        return $this->withIdentityLock($lockKey, function () use ($data, $userInput, $nationalId, $mobile) {
            return DB::transaction(function () use ($data, $userInput, $nationalId, $mobile) {
                // Avoid SELECT ... FOR UPDATE here: under concurrency it gap-locks the
                // users index and serializes unrelated inserts (slow + timeouts).
                $existing = $this->findUser($userInput);
                if ($existing) {
                    throw ValidationException::withMessages([
                        'user' => 'این کاربر قبلاً ثبت شده است.',
                    ]);
                }

                $referrer = $this->referrer(self::normalizeReferralCode((string) ($data['referral_code'] ?? '')));

                try {
                    [$user, $welcome] = $this->createRepresentative(
                        $userInput,
                        $referrer,
                        $nationalId,
                        $mobile,
                    );
                } catch (QueryException $e) {
                    // Only identity uniques mean "already registered".
                    // Other uniques (referral code, email, …) must not wipe the create.
                    if (UniqueConstraint::violatedOn($e, [
                        'users_national_id_unique',
                        'users_mobile_unique',
                        'users_farasof_user_id_unique',
                        'users.national_id',
                        'users.mobile',
                        'users.farasof_user_id',
                    ])) {
                        throw ValidationException::withMessages([
                            'user' => 'این کاربر قبلاً ثبت شده است.',
                        ]);
                    }
                    throw $e;
                }

                if ($welcome) {
                    $sms = $this->sms;
                    $plain = $welcome;
                    DB::afterCommit(function () use ($sms, $user, $plain) {
                        $sms->sendWelcome($user, $plain, 'representative');
                    });
                }

                return [
                    'user_id' => $user->id,
                    'user_created' => true,
                    'user_updated' => false,
                    'user_skipped' => false,
                    'welcome_sms_queued' => $welcome !== null,
                ];
            });
        });
    }

    /**
     * Step 2. The same person later finished the gateway. Attach the sale to the user
     * already in the tree. Do not create or move the user here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function registerGateway(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $userInput = $data['user'];
            $user = $this->findUser($userInput);
            if (! $user) {
                throw ValidationException::withMessages([
                    'user' => 'این کاربر در سازمان فروش ثبت نشده است. ابتدا وب‌هوک ثبت کاربر را ارسال کنید.',
                ]);
            }

            $gatewayInput = $data['gateway'];
            $externalId = trim((string) ($gatewayInput['external_id'] ?? ''));
            $merchant = trim((string) ($gatewayInput['merchant_code'] ?? ''));
            if ($externalId === '' && $merchant !== '') {
                $externalId = 'fp-merchant-'.$merchant;
            }
            if ($externalId === '') {
                throw ValidationException::withMessages([
                    'gateway.external_id' => 'شناسه درگاه یا کد مرچنت الزامی است.',
                ]);
            }

            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
            if ($idempotencyKey !== '') {
                $existingSale = GatewaySale::query()->with('gateway')->where('idempotency_key', $idempotencyKey)->first();
                if ($existingSale) {
                    $existingExternal = (string) ($existingSale->gateway?->external_id ?? '');
                    $existingMerchant = (string) ($existingSale->gateway?->merchant_code ?? '');
                    $sameGateway = ($externalId !== '' && $existingExternal === $externalId)
                        || ($merchant !== '' && $existingMerchant !== '' && $existingMerchant === $merchant);
                    if ($sameGateway) {
                        return $this->gatewayPayload($user, false, $existingSale->id, $existingSale->gateway_id);
                    }

                    throw ValidationException::withMessages([
                        'idempotency_key' => 'این کلید یکتا قبلاً برای درگاه دیگری ثبت شده است. برای درگاه جدید یک idempotency_key تازه بفرستید.',
                    ]);
                }
            }

            $gateway = Gateway::query()
                ->where(function ($q) use ($externalId, $merchant) {
                    $q->where('external_id', $externalId);
                    if ($merchant !== '') {
                        $q->orWhere('merchant_code', $merchant);
                    }
                })
                ->first();

            if ($gateway) {
                $externalId = (string) $gateway->external_id;
            }

            if ($gateway && $gateway->sales()->exists()) {
                $sale = $gateway->sales()->latest('id')->first();

                return $this->gatewayPayload($user, false, $sale?->id, $gateway->id);
            }

            $nationalId = $this->digits((string) ($user->national_id ?: ($userInput['national_id'] ?? '')));
            $sale = $this->sales->record([
                'external_id' => $externalId,
                'name' => $gatewayInput['name'] ?? $user->name,
                'merchant_code' => $merchant !== '' ? $merchant : null,
                'amount' => $gatewayInput['amount'] ?? 0,
                'status' => 'successful',
                'source' => 'finopal',
                'idempotency_key' => $data['idempotency_key'] ?? ('finopal-gateway-'.$externalId),
                'sync_finopal' => false,
                'sold_at' => $gatewayInput['sold_at'] ?? now(),
                'ownership_type' => 'solo',
                'representatives' => [[
                    'user_id' => $user->id,
                    'share_percent' => '100.000',
                ]],
                'customer' => [
                    'name' => $userInput['name'] ?? $user->name,
                    'mobile' => $user->mobile,
                    'person_type' => $gatewayInput['person_type'] ?? 'individual',
                    'national_id' => $nationalId,
                    'email' => $userInput['email'] ?? $user->email,
                    'birth_date' => $this->dateOrNull($userInput['birth_date'] ?? null) ?? optional($user->birth_date)->toDateString(),
                    'sheba' => $userInput['sheba'] ?? $user->sheba,
                    'website' => $gatewayInput['website'] ?? null,
                    'shop_name' => $gatewayInput['name'] ?? null,
                    'metadata' => [
                        'source' => 'finopal_gateway_webhook',
                        'callback_url' => $gatewayInput['callback_url'] ?? null,
                    ],
                ],
            ]);

            return $this->gatewayPayload($user, true, $sale->id, $sale->gateway_id);
        });
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    private function findUser(array $userInput): ?User
    {
        return $this->findUserQuery($userInput)->first();
    }

    /**
     * @param  array<string, mixed>  $userInput
     */
    private function findUserQuery(array $userInput): \Illuminate\Database\Eloquent\Builder
    {
        $nationalId = $this->digits((string) ($userInput['national_id'] ?? ''));
        $mobile = $this->mobile((string) ($userInput['mobile'] ?? ''));
        $finopalUserId = $this->emptyToNull($userInput['finopal_user_id'] ?? null);

        if ($nationalId === '' && $mobile === '' && $finopalUserId === null) {
            return User::query()->whereRaw('0 = 1');
        }

        return User::query()->where(function ($q) use ($nationalId, $mobile, $finopalUserId) {
            if ($nationalId !== '') {
                $q->orWhere('national_id', $nationalId);
            }
            if ($mobile !== '') {
                $q->orWhere('mobile', $mobile);
            }
            if ($finopalUserId !== null) {
                $q->orWhere('farasof_user_id', $finopalUserId);
            }
        });
    }

    /**
     * Serialize concurrent webhook creates for the same identity.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withIdentityLock(string $key, callable $callback): mixed
    {
        try {
            $lock = Cache::lock($key, 30);
        } catch (\Throwable) {
            return $callback();
        }

        try {
            return $lock->block(8, $callback);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw ValidationException::withMessages([
                'user' => 'درخواست هم‌زمان برای این کاربر در حال پردازش است. کمی بعد دوباره تلاش کنید.',
            ]);
        } catch (\BadMethodCallException) {
            return $callback();
        }
    }

    public static function normalizeReferralCode(string $value): string
    {
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $value) ?? $value;
        $value = trim($value);
        if (preg_match('/(?:\?|&)finonet_ref=([^&#\s]+)/', $value, $match)) {
            return trim(rawurldecode($match[1]));
        }

        return $value;
    }

    public static function signupUrl(string $code, ?string $base = null): string
    {
        $base = rtrim((string) ($base ?: config('finopal.signup_url', 'https://finopal.ir/login')), '?&');
        $join = str_contains($base, '?') ? '&' : '?';

        return $base.$join.'finonet_ref='.rawurlencode($code);
    }

    /**
     * Products that have their own public signup site.
     *
     * @return list<array{product_type:string,label:string,url:string}>
     */
    public static function signupLinks(string $code): array
    {
        $links = [];
        foreach (\App\Support\ProductCatalog::signupDefinitions() as $row) {
            $links[] = [
                'product_type' => (string) $row['type'],
                'label' => (string) $row['label'],
                'url' => self::signupUrl($code, (string) $row['signup_url']),
            ];
        }

        return $links;
    }

    private function referrer(string $code): User
    {
        $ref = ReferralCode::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
        if (! $ref || ! $ref->user) {
            throw ValidationException::withMessages([
                'referral_code' => 'کد معرف نامعتبر یا غیرفعال است.',
            ]);
        }

        return $ref->user;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: User, 1: string}
     */
    private function createRepresentative(array $input, User $referrer, string $nationalId, string $mobile): array
    {
        $plain = (string) config('finopal.sync_default_password', 'Password123!');
        $user = User::query()->create([
            'name' => $input['name'],
            'mobile' => $mobile,
            'national_id' => $nationalId,
            'email' => $this->freeEmail($input['email'] ?? null),
            'farasof_user_id' => $this->emptyToNull($input['finopal_user_id'] ?? null),
            'birth_date' => $this->dateOrNull($input['birth_date'] ?? null),
            'sheba' => $this->emptyToNull($input['sheba'] ?? null),
            'password' => $plain,
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
        $this->wallets->walletFor($user, $repRole);

        $this->createReferralCodeWithRetry($user->id, 'finopal');

        $codeId = ReferralCode::query()->where('user_id', $referrer->id)->where('is_active', true)->value('id');
        RepresentativeReferral::query()->create([
            'referred_user_id' => $user->id,
            'referrer_user_id' => $referrer->id,
            'source' => 'finopal',
            'referral_code_id' => $codeId,
        ]);

        $referrerRole = Role::query()->where('slug', 'representative_referrer')->first();
        if ($referrerRole && ! $referrer->hasRole('representative_referrer')) {
            try {
                UserRole::query()->firstOrCreate(
                    ['user_id' => $referrer->id, 'role_id' => $referrerRole->id],
                    ['effective_from' => now()->toDateString(), 'is_active' => true]
                );
                $this->wallets->walletFor($referrer, $referrerRole);
            } catch (QueryException $e) {
                if (! UniqueConstraint::violated($e)) {
                    throw $e;
                }
            }
        }

        $parent = $this->tree->registrationParentFor($referrer);
        $this->tree->attach($user, $repRole, $parent, now()->toDateString());

        return [$user, $plain];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayPayload(User $user, bool $gatewayCreated, ?int $saleId, ?int $gatewayId): array
    {
        return [
            'user_id' => $user->id,
            'gateway_id' => $gatewayId,
            'gateway_sale_id' => $saleId,
            'gateway_created' => $gatewayCreated,
            'gateway_skipped' => ! $gatewayCreated,
        ];
    }

    private function createReferralCodeWithRetry(int $userId, string $source): void
    {
        $attempts = 0;
        while (true) {
            try {
                ReferralCode::query()->create([
                    'user_id' => $userId,
                    'code' => ReferralCodeGenerator::unique(),
                    'source' => $source,
                    'is_active' => true,
                ]);

                return;
            } catch (QueryException $e) {
                if (! UniqueConstraint::violated($e) || ++$attempts >= 12) {
                    throw $e;
                }
            }
        }
    }

    private function digits(string $value): string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];

        return preg_replace('/\D+/', '', strtr($value, $map)) ?? '';
    }

    private function mobile(string $value): string
    {
        $digits = $this->digits($value);
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    private function dateOrNull(mixed $value): ?string
    {
        return JalaliDate::store($value);
    }

    private function emptyToNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function freeEmail(mixed $value): ?string
    {
        $email = $this->emptyToNull($value);
        if ($email === null) {
            return null;
        }
        if (User::query()->where('email', $email)->exists()) {
            return null;
        }

        return $email;
    }
}
