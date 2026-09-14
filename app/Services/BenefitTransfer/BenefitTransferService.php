<?php

namespace App\Services\BenefitTransfer;

use App\Models\BenefitTransfer;
use App\Models\GatewayRepresentative;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Audit\AuditService;
use App\Services\User\UserBlockService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class BenefitTransferService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly WalletService $wallets,
        private readonly UserBlockService $blocks,
    ) {}

    public function transferAll(User $actor, User $from, User $to, string $reason, ?string $effectiveFrom = null): BenefitTransfer
    {
        $this->assertParties($from, $to);

        return DB::transaction(function () use ($actor, $from, $to, $reason, $effectiveFrom) {
            $transfer = BenefitTransfer::query()->create([
                'from_user_id' => $from->id,
                'to_user_id' => $to->id,
                'gateway_sale_id' => null,
                'transfer_type' => 'all_future_benefits',
                'status' => 'active',
                'effective_from' => $effectiveFrom ?: now(),
                'reason' => $reason,
            ]);

            $rows = GatewayRepresentative::query()->where('user_id', $from->id)->get();
            foreach ($rows as $row) {
                $transfer->items()->create([
                    'gateway_representative_id' => $row->id,
                    'share_percent' => $row->share_percent,
                ]);
                $row->user_id = $to->id;
                $row->save();
            }

            $this->moveWallets($from, $to, $transfer);
            $this->retireSource($actor, $from, $to, $transfer, $reason);

            $this->audit->record($actor, 'benefit_transfer.all', $transfer, ['from' => $from->id], ['to' => $to->id]);

            return $transfer->load(['items', 'fromUser', 'toUser']);
        });
    }

    public function transferShare(User $actor, User $from, User $to, int $gatewayRepresentativeId, string $reason, ?string $effectiveFrom = null): BenefitTransfer
    {
        $this->assertParties($from, $to);

        return DB::transaction(function () use ($actor, $from, $to, $gatewayRepresentativeId, $reason, $effectiveFrom) {
            $row = GatewayRepresentative::query()->findOrFail($gatewayRepresentativeId);
            if ($row->user_id !== $from->id) {
                abort(422, 'این سهم متعلق به مبدا نیست.');
            }

            $transfer = BenefitTransfer::query()->create([
                'from_user_id' => $from->id,
                'to_user_id' => $to->id,
                'gateway_sale_id' => $row->gateway_sale_id,
                'transfer_type' => 'gateway_share',
                'status' => 'active',
                'effective_from' => $effectiveFrom ?: now(),
                'reason' => $reason,
            ]);
            $transfer->items()->create([
                'gateway_representative_id' => $row->id,
                'share_percent' => $row->share_percent,
            ]);
            $row->user_id = $to->id;
            $row->save();

            if (! GatewayRepresentative::query()->where('user_id', $from->id)->exists()) {
                $this->moveWallets($from, $to, $transfer);
                $this->retireSource($actor, $from, $to, $transfer, $reason);
            }

            $this->audit->record($actor, 'benefit_transfer.share', $transfer);

            return $transfer->load(['items', 'fromUser', 'toUser']);
        });
    }

    private function assertParties(User $from, User $to): void
    {
        if ($from->id === $to->id) {
            abort(422, 'مبدأ و مقصد نمی‌توانند یک حساب باشند.');
        }
        if (! $from->is_active) {
            abort(422, 'حساب مبدأ از قبل مسدود است.');
        }
        if (! $to->is_active) {
            abort(422, 'حساب مقصد مسدود است و نمی‌تواند مزایا را دریافت کند.');
        }
        if ($from->isSuperuser() || $to->isSuperuser()) {
            abort(422, 'انتقال مزایا برای مدیر سامانه مجاز نیست.');
        }
    }

    private function moveWallets(User $from, User $to, BenefitTransfer $transfer): void
    {
        foreach ($from->wallets()->with('role')->get() as $wallet) {
            /** @var Wallet $wallet */
            $available = $wallet->availableBalance();
            if (Money::cmp($available, '0') <= 0 || ! $wallet->role) {
                continue;
            }

            $dest = $this->wallets->walletFor($to, $wallet->role);
            $key = 'bt-wallet-'.$transfer->id.'-'.$wallet->id;
            $this->wallets->debit(
                $wallet,
                $available,
                'benefit_transfer_out',
                $key.'-out',
                BenefitTransfer::class,
                $transfer->id,
                ['to_user_id' => $to->id]
            );
            $this->wallets->credit(
                $dest,
                $available,
                'benefit_transfer_in',
                $key.'-in',
                BenefitTransfer::class,
                $transfer->id,
                ['from_user_id' => $from->id]
            );
        }
    }

    private function retireSource(User $actor, User $from, User $to, BenefitTransfer $transfer, string $reason): void
    {
        $this->blocks->applyBlock($from);
        $this->audit->record($actor, 'user.blocked', $from, ['is_active' => true], [
            'is_active' => false,
            'reason' => $reason,
            'via' => 'benefit_transfer',
            'to_user_id' => $to->id,
            'transfer_id' => $transfer->id,
        ]);
    }
}
