<?php

namespace App\Services\Gateway;

use App\Models\GatewaySale;
use App\Models\GatewaySaleReview;
use App\Models\Notification;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Commission\CommissionEngine;
use App\Services\Organization\OrganizationTreeService;
use Illuminate\Support\Facades\DB;

class GatewayReviewService
{
    public function __construct(
        private readonly CommissionEngine $engine,
        private readonly OrganizationTreeService $tree,
        private readonly AuditService $audit,
    ) {}

    public function canInspect(User $actor, GatewaySale $sale): bool
    {
        if ($actor->isSuperuser()) {
            return true;
        }
        if (! $actor->hasRole('senior_manager')) {
            return false;
        }

        $sale->loadMissing('representatives.user');
        foreach ($sale->representatives as $rep) {
            if (! $rep->user) {
                continue;
            }
            if ($rep->user_id === $actor->id || $this->tree->isDescendant($actor, $rep->user)) {
                return true;
            }
        }

        return false;
    }

    public function addReview(
        GatewaySale $sale,
        ?User $actor,
        string $stage,
        string $decision,
        string $note = '',
        ?string $reference = null,
    ): GatewaySaleReview {
        return $sale->reviews()->create([
            'actor_id' => $actor?->id,
            'stage' => $stage,
            'decision' => $decision,
            'note' => $note !== '' ? $note : null,
            'reference' => $reference,
        ]);
    }

    public function inspect(User $actor, GatewaySale $sale, string $decision, string $note = ''): GatewaySale
    {
        if (! $this->canInspect($actor, $sale)) {
            abort(403, 'فقط مدیر ارشد زیرمجموعه می‌تواند مدارک این درگاه را بازرسی کند.');
        }
        $sale->loadMissing('gateway');
        if ($sale->status !== 'pending_inspection') {
            abort(422, 'این درگاه در صف بازرسی مدارک نیست.');
        }
        if ($decision === 'rejected' && trim($note) === '') {
            abort(422, 'علت رد بازرسی الزامی است.');
        }

        return DB::transaction(function () use ($actor, $sale, $decision, $note) {
            if ($decision === 'rejected') {
                $sale->status = 'rejected';
                $sale->rejected_at = now();
                $sale->rejected_by = $actor->id;
                $sale->rejection_note = $note;
                $sale->inspected_at = now();
                $sale->inspected_by = $actor->id;
                $sale->save();
                $this->addReview($sale, $actor, 'rejected', 'rejected', $note);
                $this->audit->record($actor, 'gateway.rejected', $sale, ['status' => 'pending_inspection'], [
                    'status' => 'rejected',
                    'stage' => 'inspection',
                    'note' => $note,
                ]);
                $this->notifyRepresentatives($sale, 'gateway.rejected', 'درگاه رد شد', "مدارک درگاه «{$sale->gateway?->name}» در بازرسی رد شد. علت: {$note}");
            } else {
                $sale->status = 'pending_shaparak';
                $sale->inspected_at = now();
                $sale->inspected_by = $actor->id;
                $sale->save();
                $this->addReview($sale, $actor, 'inspected', 'approved', $note);
                $this->audit->record($actor, 'gateway.inspected', $sale, ['status' => 'pending_inspection'], [
                    'status' => 'pending_shaparak',
                    'note' => $note,
                ]);
                $this->notifySeniors(
                    $sale,
                    'gateway.inspected',
                    'درگاه آماده تایید شاپرک',
                    "مدارک درگاه «{$sale->gateway?->name}» بازرسی شد. تایید فاینوپال / شاپرک باقی مانده است."
                );
            }

            return $this->fresh($sale);
        });
    }

    public function confirmShaparak(User $actor, GatewaySale $sale, string $decision, string $note = '', ?string $reference = null): GatewaySale
    {
        if (! $this->canInspect($actor, $sale)) {
            abort(403, 'فقط مدیر ارشد زیرمجموعه می‌تواند تایید شاپرک را ثبت کند.');
        }
        $sale->loadMissing('gateway');
        if ($sale->status !== 'pending_shaparak') {
            abort(422, 'این درگاه در صف تایید شاپرک نیست.');
        }
        if ($decision === 'rejected' && trim($note) === '') {
            abort(422, 'علت رد شاپرک الزامی است.');
        }

        return DB::transaction(function () use ($actor, $sale, $decision, $note, $reference) {
            if ($decision === 'rejected') {
                $sale->status = 'rejected';
                $sale->rejected_at = now();
                $sale->rejected_by = $actor->id;
                $sale->rejection_note = $note;
                $sale->shaparak_at = now();
                $sale->shaparak_by = $actor->id;
                $sale->shaparak_reference = $reference;
                $sale->save();
                $this->addReview($sale, $actor, 'rejected', 'rejected', $note, $reference);
                $this->audit->record($actor, 'gateway.rejected', $sale, ['status' => 'pending_shaparak'], [
                    'status' => 'rejected',
                    'stage' => 'shaparak',
                    'note' => $note,
                    'reference' => $reference,
                ]);
                $this->notifyRepresentatives($sale, 'gateway.rejected', 'درگاه رد شد', "درگاه «{$sale->gateway?->name}» در تایید شاپرک / فاینوپال رد شد. علت: {$note}");
            } else {
                $sale->status = 'successful';
                $sale->shaparak_at = now();
                $sale->shaparak_by = $actor->id;
                $sale->shaparak_reference = $reference;
                $sale->save();
                $this->addReview($sale, $actor, 'shaparak_confirmed', 'approved', $note, $reference);
                $this->engine->process($sale->fresh(['representatives', 'referrers', 'managers.role']));
                $this->addReview($sale, $actor, 'commission_posted', 'approved', 'پورسانت نقش‌ها پس از تایید شاپرک ثبت شد.', $reference);
                $this->audit->record($actor, 'gateway.shaparak_confirmed', $sale, ['status' => 'pending_shaparak'], [
                    'status' => 'successful',
                    'reference' => $reference,
                    'note' => $note,
                ]);
                $this->notifyStakeholders(
                    $sale,
                    'gateway.shaparak_confirmed',
                    'درگاه تایید شد',
                    "درگاه «{$sale->gateway?->name}» در فاینوپال / شاپرک تایید شد و پورسانت به کیف پول نقش‌ها نشست."
                );
            }

            return $this->fresh($sale);
        });
    }

    public function notifySubmitted(GatewaySale $sale): void
    {
        $name = $sale->gateway?->name ?? 'درگاه';
        $body = "درگاه «{$name}» ثبت شد و منتظر بازرسی مدارک است. تا تایید شاپرک پورسانتی واریز نمی‌شود.";

        $this->notifySeniors($sale, 'gateway.submitted', 'درگاه جدید برای بازرسی', $body);
    }

    private function notifySeniors(GatewaySale $sale, string $type, string $title, string $body): void
    {
        User::query()
            ->whereHas('roles', fn ($q) => $q->where('slug', 'senior_manager'))
            ->get()
            ->filter(fn (User $senior) => $this->canInspect($senior, $sale))
            ->each(function (User $senior) use ($sale, $type, $title, $body) {
                Notification::query()->create([
                    'user_id' => $senior->id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'data' => ['gateway_sale_id' => $sale->id, 'path' => 'gateways'],
                ]);
            });
    }

    private function notifyRepresentatives(GatewaySale $sale, string $type, string $title, string $body): void
    {
        $sale->loadMissing('representatives');
        foreach ($sale->representatives as $rep) {
            Notification::query()->create([
                'user_id' => $rep->user_id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => ['gateway_sale_id' => $sale->id, 'path' => 'gateways'],
            ]);
        }
    }

    private function notifyStakeholders(GatewaySale $sale, string $type, string $title, string $body): void
    {
        $sale->loadMissing(['representatives', 'referrers', 'managers']);
        $ids = collect()
            ->merge($sale->representatives->pluck('user_id'))
            ->merge($sale->referrers->pluck('user_id'))
            ->merge($sale->managers->pluck('user_id'))
            ->unique()
            ->filter();

        foreach ($ids as $userId) {
            Notification::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => ['gateway_sale_id' => $sale->id, 'path' => 'gateways'],
            ]);
        }
    }

    private function fresh(GatewaySale $sale): GatewaySale
    {
        return $sale->fresh([
            'gateway',
            'customer',
            'representatives.user',
            'referrers.user',
            'managers.user',
            'managers.role',
            'reviews.actor',
            'commissions.role',
        ]);
    }
}
