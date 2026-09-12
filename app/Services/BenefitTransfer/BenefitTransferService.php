<?php

namespace App\Services\BenefitTransfer;

use App\Models\BenefitTransfer;
use App\Models\GatewayRepresentative;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

class BenefitTransferService
{
    public function __construct(private readonly AuditService $audit) {}

    public function transferAll(User $actor, User $from, User $to, string $reason, ?string $effectiveFrom = null): BenefitTransfer
    {
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

            $this->audit->record($actor, 'benefit_transfer.all', $transfer, ['from' => $from->id], ['to' => $to->id]);

            return $transfer->load('items');
        });
    }

    public function transferShare(User $actor, User $from, User $to, int $gatewayRepresentativeId, string $reason, ?string $effectiveFrom = null): BenefitTransfer
    {
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

            $this->audit->record($actor, 'benefit_transfer.share', $transfer);

            return $transfer->load('items');
        });
    }
}
