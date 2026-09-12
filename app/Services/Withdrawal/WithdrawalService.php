<?php

namespace App\Services\Withdrawal;

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

    public function request(User $user, Wallet $wallet, string $amount, ?string $idempotencyKey = null): WithdrawalRequest
    {
        if ($wallet->user_id !== $user->id) {
            throw new RuntimeException('کیف پول متعلق به کاربر نیست.');
        }

        $key = $idempotencyKey ?: 'wd-req-'.Str::uuid();

        return DB::transaction(function () use ($user, $wallet, $amount, $key) {
            $existing = WithdrawalRequest::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $amount = Money::normalize($amount);
            if (Money::cmp($amount, '0') <= 0) {
                throw new RuntimeException('مبلغ برداشت نامعتبر است.');
            }

            $status = WithdrawalRequest::SENIOR_MANAGER_PENDING;
            if ($user->isSuperuser()) {
                $status = WithdrawalRequest::SUPERUSER_PENDING;
            } elseif ($user->hasRole('senior_manager')) {
                $status = WithdrawalRequest::SUPERUSER_PENDING;
            }

            $withdrawal = WithdrawalRequest::query()->create([
                'wallet_id' => $locked->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => $status,
                'idempotency_key' => $key,
                'requested_at' => now(),
            ]);

            $this->wallets->hold($locked, $amount, 'wd-hold-'.$withdrawal->id, WithdrawalRequest::class, $withdrawal->id);

            if ($status === WithdrawalRequest::SUPERUSER_PENDING) {
                WithdrawalApproval::query()->create([
                    'withdrawal_request_id' => $withdrawal->id,
                    'approver_user_id' => $user->id,
                    'stage' => 'senior_manager',
                    'decision' => 'approved',
                    'note' => $user->hasRole('senior_manager')
                        ? 'تایید خودکار مرحله مدیر ارشد برای درخواست خودش'
                        : 'رد شدن از مرحله مدیر ارشد توسط سوپریوزر',
                    'decided_at' => now(),
                ]);
            }

            $this->audit->record($user, 'withdrawal.requested', $withdrawal, null, $withdrawal->toArray());

            return $withdrawal;
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
                    throw new RuntimeException('فقط سوپریوزر می‌تواند در این مرحله تایید کند.');
                }
                $stage = 'superuser';
                $next = $decision === 'approved'
                    ? WithdrawalRequest::PROCESSING
                    : WithdrawalRequest::REJECTED;
            } elseif ($withdrawal->status === WithdrawalRequest::REJECTED && $decision === 'approved') {
                if (! $approver->hasRole('senior_manager') && ! $approver->isSuperuser()) {
                    throw new RuntimeException('فقط مدیر ارشد یا سوپریوزر می‌تواند وضعیت ردشده را تغییر دهد.');
                }
                $withdrawal->loadMissing('wallet');
                $this->wallets->hold(
                    $withdrawal->wallet,
                    (string) $withdrawal->amount,
                    'wd-rehold-'.$withdrawal->id.'-'.Str::uuid(),
                    WithdrawalRequest::class,
                    $withdrawal->id
                );
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
                $this->wallets->releaseHold(
                    $withdrawal->wallet,
                    (string) $withdrawal->amount,
                    'wd-release-'.$withdrawal->id,
                    WithdrawalRequest::class,
                    $withdrawal->id
                );
                $withdrawal->failure_reason = $note ?: 'رد شد';
            }

            if ($next === WithdrawalRequest::PROCESSING) {
                $this->complete($withdrawal);
            }

            $withdrawal->save();
            $this->audit->record($approver, 'withdrawal.'.$decision, $withdrawal);

            return $withdrawal->fresh(['approvals', 'wallet']);
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

            $this->wallets->releaseHold(
                $withdrawal->wallet,
                (string) $withdrawal->amount,
                'wd-cancel-'.$withdrawal->id,
                WithdrawalRequest::class,
                $withdrawal->id
            );
            $withdrawal->status = WithdrawalRequest::CANCELLED;
            $withdrawal->failure_reason = 'cancelled_by_user';
            $withdrawal->save();

            return $withdrawal;
        });
    }

    private function complete(WithdrawalRequest $withdrawal): void
    {
        $this->wallets->captureHold(
            $withdrawal->wallet,
            (string) $withdrawal->amount,
            'wd-debit-'.$withdrawal->id,
            WithdrawalRequest::class,
            $withdrawal->id
        );
        $withdrawal->status = WithdrawalRequest::COMPLETED;
        $withdrawal->completed_at = now();
    }
}
