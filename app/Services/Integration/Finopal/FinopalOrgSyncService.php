<?php

namespace App\Services\Integration\Finopal;

use App\Models\OrganizationNode;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Sms\SmsService;
use App\Services\Wallet\WalletService;
use App\Support\ReferralCodeGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sync Finopal MLM_Structure into local users + organization tree.
 *
 * Mapping (Finopal key → local role):
 * - Senior → senior_manager (مدیر ارشد)
 * - DM     → development_manager (مدیر توسعه)
 * - SM     → sales_manager (مدیر فروش)
 * - REPS   → representative (نماینده؛ object=انفرادی، array=اشتراکی)
 */
class FinopalOrgSyncService
{
    public function __construct(
        private readonly OrganizationTreeService $tree,
        private readonly WalletService $wallets,
        private readonly SmsService $sms,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   created: list<array<string, mixed>>,
     *   skipped: list<array<string, mixed>>,
     *   errors: list<array<string, mixed>>,
     *   structure: array<string, mixed>,
     *   sms_queued: int
     * }
     */
    public function sync(array $payload): array
    {
        $structure = $payload['MLM_Structure'] ?? $payload['mlm_structure'] ?? null;
        if (! is_array($structure)) {
            throw new RuntimeException('فیلد MLM_Structure الزامی است.');
        }

        return DB::transaction(function () use ($structure) {
            $created = [];
            $skipped = [];
            $errors = [];
            $welcomeQueue = [];

            $seniorRole = Role::query()->where('slug', 'senior_manager')->firstOrFail();
            $dmRole = Role::query()->where('slug', 'development_manager')->firstOrFail();
            $smRole = Role::query()->where('slug', 'sales_manager')->firstOrFail();
            $repRole = Role::query()->where('slug', 'representative')->firstOrFail();

            $seniorNode = $this->upsertSlot(
                $structure['Senior'] ?? $structure['senior'] ?? null,
                'Senior',
                $seniorRole,
                null,
                $created,
                $skipped,
                $errors,
                $welcomeQueue,
            );

            $dmParent = $seniorNode;
            $dmNode = $this->upsertSlot(
                $structure['DM'] ?? $structure['dm'] ?? null,
                'DM',
                $dmRole,
                $dmParent,
                $created,
                $skipped,
                $errors,
                $welcomeQueue,
            );

            $smParent = $dmNode ?? $seniorNode;
            $smNode = $this->upsertSlot(
                $structure['SM'] ?? $structure['sm'] ?? null,
                'SM',
                $smRole,
                $smParent,
                $created,
                $skipped,
                $errors,
                $welcomeQueue,
            );

            $repParent = $smNode ?? $dmNode ?? $seniorNode;
            $repsRaw = $structure['REPS'] ?? $structure['reps'] ?? null;
            $reps = $this->normalizeReps($repsRaw);

            if ($reps === [] && $repsRaw !== null) {
                $errors[] = [
                    'slot' => 'REPS',
                    'message' => 'ساختار REPS نامعتبر است (باید object یا array از نمایندگان باشد).',
                ];
            }

            foreach ($reps as $index => $rep) {
                $slot = count($reps) > 1 ? 'REPS['.$index.']' : 'REPS';
                $this->upsertSlot(
                    $rep,
                    $slot,
                    $repRole,
                    $repParent,
                    $created,
                    $skipped,
                    $errors,
                    $welcomeQueue,
                    true,
                );
            }

            if ($welcomeQueue !== []) {
                $sms = $this->sms;
                DB::afterCommit(function () use ($welcomeQueue, $sms) {
                    foreach ($welcomeQueue as $row) {
                        $sms->sendWelcome($row['user'], $row['password'], $row['role']);
                    }
                });
            }

            return [
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
                'sms_queued' => count($welcomeQueue),
                'structure' => [
                    'senior_node_id' => $seniorNode?->id,
                    'dm_node_id' => $dmNode?->id,
                    'sm_node_id' => $smNode?->id,
                    'reps_parent_node_id' => $repParent?->id,
                    'reps_count' => count($reps),
                    'shared' => count($reps) > 1,
                ],
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $created
     * @param  list<array<string, mixed>>  $skipped
     * @param  list<array<string, mixed>>  $errors
     * @param  list<array{user: User, password: string, role: string}>  $welcomeQueue
     */
    private function upsertSlot(
        mixed $raw,
        string $slot,
        Role $role,
        ?OrganizationNode $parent,
        array &$created,
        array &$skipped,
        array &$errors,
        array &$welcomeQueue,
        bool $isRep = false,
    ): ?OrganizationNode {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_array($raw) || array_is_list($raw)) {
            $errors[] = ['slot' => $slot, 'message' => 'داده این نقش باید یک object باشد.'];

            return null;
        }

        try {
            $person = $this->normalizePerson($raw, $isRep);
        } catch (RuntimeException $e) {
            $errors[] = ['slot' => $slot, 'message' => $e->getMessage()];

            return null;
        }

        $existing = User::query()->where('national_id', $person['national_id'])->first();
        if ($existing) {
            $skipped[] = [
                'slot' => $slot,
                'user_id' => $existing->id,
                'national_id' => $person['national_id'],
                'mobile' => $existing->mobile,
                'role' => $role->slug,
                'reason' => 'national_id_exists',
            ];

            // Existing users are not modified; only expose their current org node as parent for children.
            return $this->activeNodeFor($existing, $role->slug);
        }

        if (User::query()->where('mobile', $person['mobile'])->exists()) {
            $errors[] = [
                'slot' => $slot,
                'national_id' => $person['national_id'],
                'mobile' => $person['mobile'],
                'message' => 'شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.',
            ];

            return null;
        }

        if ($person['farasof_user_id'] && User::query()->where('farasof_user_id', $person['farasof_user_id'])->exists()) {
            $errors[] = [
                'slot' => $slot,
                'farasof_user_id' => $person['farasof_user_id'],
                'message' => 'Farasof_User_ID قبلاً برای کاربر دیگری ثبت شده است.',
            ];

            return null;
        }

        $plainPassword = (string) config('finopal.sync_default_password', 'Password123!');

        try {
            $user = User::query()->create([
                'name' => $person['name'],
                'mobile' => $person['mobile'],
                'national_id' => $person['national_id'],
                'farasof_user_id' => $person['farasof_user_id'],
                'birth_date' => $person['birth_date'],
                'sheba' => $person['sheba'],
                'password' => $plainPassword,
                'is_active' => true,
            ]);

            $node = $this->ensureRoleAndNode($user, $role, $parent, true);
        } catch (RuntimeException $e) {
            $errors[] = [
                'slot' => $slot,
                'national_id' => $person['national_id'],
                'message' => $e->getMessage(),
            ];

            return null;
        }

        if ($isRep) {
            ReferralCode::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'code' => ReferralCodeGenerator::unique(),
                    'source' => 'finopal_org_sync',
                    'is_active' => true,
                ]
            );
        }

        $welcomeQueue[] = [
            'user' => $user,
            'password' => $plainPassword,
            'role' => $role->slug,
        ];

        $created[] = [
            'slot' => $slot,
            'user_id' => $user->id,
            'national_id' => $person['national_id'],
            'mobile' => $person['mobile'],
            'farasof_user_id' => $person['farasof_user_id'],
            'role' => $role->slug,
            'node_id' => $node->id,
            'mlm_commission' => $person['mlm_commission'],
            'welcome_sms' => true,
        ];

        return $node;
    }

    private function ensureRoleAndNode(
        User $user,
        Role $role,
        ?OrganizationNode $parent,
        bool $isPrimary,
    ): OrganizationNode {
        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'effective_from' => now()->toDateString(),
            'is_primary' => $isPrimary,
            'is_active' => true,
        ]);

        $this->wallets->walletFor($user, $role);

        if ($role->slug === 'senior_manager') {
            $this->wallets->residualBonusWallet($user, $role);
        }

        if ($role->slug !== 'senior_manager' && ! $parent) {
            throw new RuntimeException("والد سازمانی برای نقش {$role->slug} مشخص نیست (ابتدا Senior/DM/SM بالادست را بفرستید).");
        }

        return $this->tree->attach($user, $role, $parent, now()->toDateString());
    }

    private function activeNodeFor(User $user, string $roleSlug): ?OrganizationNode
    {
        return $this->tree->activeNodesFor($user, $roleSlug)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeReps(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw) && ! array_is_list($raw) && $this->looksLikePerson($raw)) {
            return [$raw];
        }
        if (is_array($raw) && array_is_list($raw)) {
            return array_values(array_filter($raw, fn ($row) => is_array($row)));
        }

        return [];
    }

    /** @param  array<string, mixed>  $row */
    private function looksLikePerson(array $row): bool
    {
        return isset($row['NationalCode'])
            || isset($row['national_code'])
            || isset($row['PhoneNumber'])
            || isset($row['phone_number']);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   name: string,
     *   mobile: string,
     *   national_id: string,
     *   farasof_user_id: ?string,
     *   birth_date: ?string,
     *   sheba: ?string,
     *   mlm_commission: ?string
     * }
     */
    private function normalizePerson(array $raw, bool $isRep): array
    {
        $national = $this->digits((string) ($raw['NationalCode'] ?? $raw['national_code'] ?? ''));
        $mobile = $this->normalizeMobile((string) ($raw['PhoneNumber'] ?? $raw['phone_number'] ?? ''));
        $farasof = trim((string) ($raw['Farasof_User_ID'] ?? $raw['farasof_user_id'] ?? ''));
        $sheba = $this->normalizeSheba((string) ($raw['ShebaNumber'] ?? $raw['sheba_number'] ?? $raw['sheba'] ?? ''));
        $birth = $this->normalizeDate((string) ($raw['BirthDate'] ?? $raw['birth_date'] ?? ''));
        $commission = isset($raw['MLM_Commission']) || isset($raw['mlm_commission'])
            ? trim((string) ($raw['MLM_Commission'] ?? $raw['mlm_commission']))
            : null;

        if ($national === '' || strlen($national) < 8) {
            throw new RuntimeException('NationalCode معتبر الزامی است.');
        }
        if ($mobile === '') {
            throw new RuntimeException('PhoneNumber معتبر الزامی است.');
        }
        if ($isRep && ($commission === null || $commission === '')) {
            // Allow missing commission; treat as 100 for solo.
            $commission = '100';
        }

        $name = trim((string) ($raw['FullName'] ?? $raw['Name'] ?? $raw['name'] ?? ''));
        if ($name === '') {
            $name = 'کاربر فاینوپال '.$national;
        }

        return [
            'name' => $name,
            'mobile' => $mobile,
            'national_id' => $national,
            'farasof_user_id' => $farasof !== '' ? $farasof : null,
            'birth_date' => $birth,
            'sheba' => $sheba !== '' ? $sheba : null,
            'mlm_commission' => $commission,
        ];
    }

    private function normalizeMobile(string $value): string
    {
        $digits = $this->digits($value);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : '';
    }

    private function normalizeSheba(string $value): string
    {
        $clean = strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
        if ($clean === '') {
            return '';
        }
        if (! str_starts_with($clean, 'IR') && preg_match('/^\d{24}$/', $clean)) {
            $clean = 'IR'.$clean;
        }

        return $clean;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
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
}
