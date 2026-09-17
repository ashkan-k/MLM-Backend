<?php

namespace App\Services\Withdrawal;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalApproval;
use App\Models\WithdrawalRequest;
use App\Services\Audit\AuditService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class WithdrawalService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  'active_role'|'all_roles'  $scope
     */
    public function request(
        User $user,
        string $amount,
        string $scope = 'active_role',
        ?Wallet $wallet = null,
        ?Role $activeRole = null,
        ?string $idempotencyKey = null,
    ): WithdrawalRequest {
        $amount = Money::normalize($amount);
        if (Money::cmp($amount, '0') <= 0) {
            throw new RuntimeException('مبلغ برداشت نامعتبر است.');
        }

        $key = $idempotencyKey ?: 'wd-req-'.Str::uuid();

        return DB::transaction(function () use ($user, $amount, $scope, $wallet, $activeRole, $key) {
            $existing = WithdrawalRequest::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $allocations = $scope === 'all_roles'
                ? $this->allocateAcrossWallets($user, $amount, $activeRole)
                : $this->allocateSingleWallet($user, $amount, $wallet, $activeRole);

            $primaryWalletId = (int) $allocations[0]['wallet_id'];
            $lockedPrimary = Wallet::query()->whereKey($primaryWalletId)->lockForUpdate()->firstOrFail();

            $status = WithdrawalRequest::SENIOR_MANAGER_PENDING;
            if ($user->isSuperuser()) {
                $status = WithdrawalRequest::SUPERUSER_PENDING;
            } elseif ($user->hasRole('senior_manager')) {
                $status = WithdrawalRequest::SUPERUSER_PENDING;
            }

            $withdrawal = WithdrawalRequest::query()->create([
                'wallet_id' => $lockedPrimary->id,
                'wallet_allocations' => count($allocations) > 1 ? $allocations : null,
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => $status,
                'idempotency_key' => $key,
                'requested_at' => now(),
            ]);

            $this->holdAllocations($allocations, $withdrawal);

            if ($status === WithdrawalRequest::SUPERUSER_PENDING) {
                WithdrawalApproval::query()->create([
                    'withdrawal_request_id' => $withdrawal->id,
                    'approver_user_id' => $user->id,
                    'stage' => 'senior_manager',
                    'decision' => 'approved',
                    'note' => $user->hasRole('senior_manager')
                        ? 'تایید خودکار مرحله مدیر ارشد برای درخواست خودش'
                        : 'رد شدن از مرحله مدیر ارشد توسط مدیر سامانه',
                    'decided_at' => now(),
                ]);
            }

            $this->audit->record($user, 'withdrawal.requested', $withdrawal, null, $withdrawal->toArray());

            return $withdrawal->fresh(['wallet.role']);
        });
    }

    public function decide(User $approver, WithdrawalRequest $withdrawal, string $decision, string $note = ''): WithdrawalRequest
    {
        return DB::transaction(function () use ($approver, $withdrawal, $decision, $note) {
            $withdrawal = WithdrawalRequest::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($withdrawal->status === WithdrawalRequest::SENIOR_MANAGER_PENDING) {
                if (! $approver->hasRole('senior_manager') && ! $approver->isSuperuser()) {
                    throw new RuntimeException('فقط مدیر ارشد می‌تواند در این مرحله تایید کند.');
                }
                $stage = 'senior_manager';
                $next = $decision === 'approved'
                    ? WithdrawalRequest::SUPERUSER_PENDING
                    : WithdrawalRequest::REJECTED;
            } elseif ($withdrawal->status === WithdrawalRequest::SUPERUSER_PENDING) {
                if (! $approver->isSuperuser()) {
                    throw new RuntimeException('فقط مدیر سامانه می‌تواند در این مرحله تایید کند.');
                }
                $stage = 'superuser';
                $next = $decision === 'approved'
                    ? WithdrawalRequest::PROCESSING
                    : WithdrawalRequest::REJECTED;
            } elseif ($withdrawal->status === WithdrawalRequest::REJECTED && $decision === 'approved') {
                if (! $approver->hasRole('senior_manager') && ! $approver->isSuperuser()) {
                    throw new RuntimeException('فقط مدیر ارشد یا مدیر سامانه می‌تواند وضعیت ردشده را تغییر دهد.');
                }
                $this->holdAllocations($this->allocationsOf($withdrawal), $withdrawal, 'wd-rehold-'.$withdrawal->id.'-'.Str::uuid());
                $stage = $approver->isSuperuser() ? 'superuser' : 'senior_manager';
                $next = $approver->isSuperuser()
                    ? WithdrawalRequest::PROCESSING
                    : WithdrawalRequest::SUPERUSER_PENDING;
                $withdrawal->failure_reason = null;
            } else {
                throw new RuntimeException('این درخواست در وضعیت قابل تصمیم‌گیری نیست.');
            }

            WithdrawalApproval::query()->create([
                'withdrawal_request_id' => $withdrawal->id,
                'approver_user_id' => $approver->id,
                'stage' => $stage,
                'decision' => $decision,
                'note' => $note,
                'decided_at' => now(),
            ]);

            $withdrawal->status = $next;
            if ($next === WithdrawalRequest::REJECTED) {
                $this->releaseAllocations($withdrawal);
                $withdrawal->failure_reason = $note ?: 'رد شد';
            }

            if ($next === WithdrawalRequest::PROCESSING) {
                $this->complete($withdrawal);
            }

            $withdrawal->save();
            $this->audit->record($approver, 'withdrawal.'.$decision, $withdrawal);

            return $withdrawal->fresh(['approvals', 'wallet.role']);
        });
    }

    public function cancel(User $user, WithdrawalRequest $withdrawal): WithdrawalRequest
    {
        return DB::transaction(function () use ($user, $withdrawal) {
            $withdrawal = WithdrawalRequest::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($withdrawal->user_id !== $user->id) {
                throw new RuntimeException('فقط درخواست‌کننده می‌تواند لغو کند.');
            }
            if (! in_array($withdrawal->status, [
                WithdrawalRequest::REQUESTED,
                WithdrawalRequest::SENIOR_MANAGER_PENDING,
                WithdrawalRequest::SUPERUSER_PENDING,
            ], true)) {
                throw new RuntimeException('امکان لغو در این وضعیت وجود ندارد.');
            }

            $this->releaseAllocations($withdrawal);
            $withdrawal->status = WithdrawalRequest::CANCELLED;
            $withdrawal->failure_reason = 'cancelled_by_user';
            $withdrawal->save();

            return $withdrawal;
        });
    }

    private function complete(WithdrawalRequest $withdrawal): void
    {
        foreach ($this->allocationsOf($withdrawal) as $row) {
            $wallet = Wallet::query()->whereKey($row['wallet_id'])->lockForUpdate()->firstOrFail();
            $this->wallets->captureHold(
                $wallet,
                (string) $row['amount'],
                'wd-debit-'.$withdrawal->id.'-'.$row['wallet_id'],
                WithdrawalRequest::class,
                $withdrawal->id
            );
        }
        $withdrawal->status = WithdrawalRequest::COMPLETED;
        $withdrawal->completed_at = now();
    }

    /** @return list<array{wallet_id: int, amount: string, role_id?: int|null}> */
    private function allocateSingleWallet(User $user, string $amount, ?Wallet $wallet, ?Role $activeRole): array
    {
        if (! $wallet) {
            if (! $activeRole) {
                throw new RuntimeException('نقش فعال برای برداشت مشخص نیست.');
            }
            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->where('role_id', $activeRole->id)
                ->first();
            if (! $wallet) {
                throw new RuntimeException('کیف پول نقش فعال یافت نشد.');
            }
        }

        if ($wallet->user_id !== $user->id) {
            throw new RuntimeException('کیف پول متعلق به کاربر نیست.');
        }

        if ($activeRole && ! $user->isSuperuser() && (int) $wallet->role_id !== (int) $activeRole->id) {
            throw new RuntimeException('برای برداشت از نقش فعال، کیف پول همان نقش را انتخاب کنید.');
        }

        $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
        $available = Money::sub((string) $locked->balance, (string) $locked->held_balance);
        if (Money::cmp($available, $amount) < 0) {
            throw new RuntimeException('موجودی قابل برداشت نقش فعال کافی نیست.');
        }

        return [[
            'wallet_id' => (int) $locked->id,
            'amount' => $amount,
            'role_id' => $locked->role_id,
        ]];
    }

    /** @return list<array{wallet_id: int, amount: string, role_id?: int|null}> */
    private function allocateAcrossWallets(User $user, string $amount, ?Role $activeRole): array
    {
        $wallets = Wallet::query()
            ->where('user_id', $user->id)
            ->with('role')
            ->lockForUpdate()
            ->get()
            ->sortBy(function (Wallet $w) use ($activeRole) {
                // Prefer active-role wallet first, then by available desc
                $prefer = ($activeRole && (int) $w->role_id === (int) $activeRole->id) ? 0 : 1;
                $available = (float) Money::sub((string) $w->balance, (string) $w->held_balance);

                return sprintf('%d-%020.3f', $prefer, -$available);
            })
            ->values();

        $remaining = $amount;
        $allocations = [];
        foreach ($wallets as $wallet) {
            $available = Money::sub((string) $wallet->balance, (string) $wallet->held_balance);
            if (Money::cmp($available, '0') <= 0) {
                continue;
            }
            $take = Money::cmp($available, $remaining) >= 0 ? $remaining : $available;
            if (Money::cmp($take, '0') <= 0) {
                continue;
            }
            $allocations[] = [
                'wallet_id' => (int) $wallet->id,
                'amount' => $take,
                'role_id' => $wallet->role_id,
            ];
            $remaining = Money::sub($remaining, $take);
            if (Money::cmp($remaining, '0') <= 0) {
                break;
            }
        }

        if (Money::cmp($remaining, '0') > 0 || $allocations === []) {
            throw new RuntimeException('موجودی قابل برداشت تجمیعی همه نقش‌ها کافی نیست.');
        }

        return $allocations;
    }

    /** @return list<array{wallet_id: int, amount: string, role_id?: int|null}> */
    private function allocationsOf(WithdrawalRequest $withdrawal): array
    {
        if (is_array($withdrawal->wallet_allocations) && $withdrawal->wallet_allocations !== []) {
            return array_map(fn ($row) => [
                'wallet_id' => (int) $row['wallet_id'],
                'amount' => Money::normalize((string) $row['amount']),
                'role_id' => isset($row['role_id']) ? (int) $row['role_id'] : null,
            ], $withdrawal->wallet_allocations);
        }

        return [[
            'wallet_id' => (int) $withdrawal->wallet_id,
            'amount' => Money::normalize((string) $withdrawal->amount),
            'role_id' => $withdrawal->wallet?->role_id,
        ]];
    }

    /** @param  list<array{wallet_id: int, amount: string}>  $allocations */
    private function holdAllocations(array $allocations, WithdrawalRequest $withdrawal, ?string $prefix = null): void
    {
        $prefix ??= 'wd-hold-'.$withdrawal->id;
        foreach ($allocations as $row) {
            $wallet = Wallet::query()->whereKey($row['wallet_id'])->lockForUpdate()->firstOrFail();
            $this->wallets->hold(
                $wallet,
                (string) $row['amount'],
                $prefix.'-'.$row['wallet_id'],
                WithdrawalRequest::class,
                $withdrawal->id
            );
        }
    }

    private function releaseAllocations(WithdrawalRequest $withdrawal): void
    {
        foreach ($this->allocationsOf($withdrawal) as $row) {
            $wallet = Wallet::query()->whereKey($row['wallet_id'])->lockForUpdate()->firstOrFail();
            $this->wallets->releaseHold(
                $wallet,
                (string) $row['amount'],
                'wd-release-'.$withdrawal->id.'-'.$row['wallet_id'],
                WithdrawalRequest::class,
                $withdrawal->id
            );
        }
    }
}
