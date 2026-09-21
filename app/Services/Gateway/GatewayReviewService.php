<?php

namespace App\Services\Gateway;

use App\Models\GatewaySale;
use App\Models\GatewaySaleReview;
use App\Models\Notification;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Commission\QualificationService;
use App\Services\Organization\OrganizationTreeService;
use Illuminate\Support\Facades\DB;

class GatewayReviewService
{
    public function __construct(
        private readonly OrganizationTreeService $tree,
        private readonly AuditService $audit,
        private readonly QualificationService $qualification,
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

    public function inspect(User $actor, GatewaySale $sale, string $decision, string $note = '', ?string $merchantCode = null): GatewaySale
    {
        if (! $this->canInspect($actor, $sale)) {
            abort(403, 'فقط مدیر ارشد زیرمجموعه می‌تواند مدارک این درگاه را بازرسی کند.');
        }
        $sale->loadMissing('gateway');
        if (! in_array($sale->status, ['pending_inspection', 'pending_shaparak'], true)) {
            abort(422, 'این درگاه در صف تایید مدیر ارشد نیست.');
        }
        if ($decision === 'rejected' && trim($note) === '') {
            abort(422, 'علت رد بازرسی الزامی است.');
        }
        $merchantCode = trim((string) $merchantCode);
        if ($decision === 'approved') {
            if ($merchantCode === '') {
                abort(422, 'کد مرچنت فاینوپال الزامی است.');
            }
            $taken = \App\Models\Gateway::query()
                ->where('merchant_code', $merchantCode)
                ->where('id', '!=', $sale->gateway_id)
                ->exists();
            if ($taken) {
                abort(422, 'این کد مرچنت قبلاً برای درگاه دیگری ثبت شده است.');
            }
        }

        return DB::transaction(function () use ($actor, $sale, $decision, $note, $merchantCode) {
            $old = $sale->status;
            $sale->inspected_at = now();
            $sale->inspected_by = $actor->id;

            if ($decision === 'rejected') {
                $sale->status = 'rejected';
                $sale->rejected_at = now();
                $sale->rejected_by = $actor->id;
                $sale->rejection_note = $note;
                $sale->save();
                $this->addReview($sale, $actor, 'rejected', 'rejected', $note);
                $this->audit->record($actor, 'gateway.rejected', $sale, ['status' => $old], [
                    'status' => 'rejected',
                    'note' => $note,
                ]);
                $this->notifyRepresentatives($sale, 'gateway.rejected', 'درگاه رد شد', "مدارک درگاه «{$sale->gateway?->name}» رد شد. علت: {$note}");
            } else {
                $sale->status = 'successful';
                $sale->save();
                $sale->gateway?->update([
                    'merchant_code' => $merchantCode,
                    'is_active' => true,
                ]);
                $this->addReview($sale, $actor, 'inspected', 'approved', $note, $merchantCode);
                $this->audit->record($actor, 'gateway.approved', $sale, ['status' => $old], [
                    'status' => 'successful',
                    'merchant_code' => $merchantCode,
                    'note' => $note,
                ]);
                $this->notifyStakeholders(
                    $sale,
                    'gateway.approved',
                    'درگاه تایید شد',
                    "درگاه «{$sale->gateway?->name}» با کد مرچنت فاینوپال تایید شد. پورسانت پس از هر تراکنش موفق این درگاه محاسبه می‌شود."
                );
                $this->syncBonusEligibilityAfterApproval($sale->fresh(['representatives', 'managers.role']));
            }

            return $this->fresh($sale);
        });
    }

    private function syncBonusEligibilityAfterApproval(GatewaySale $sale): void
    {
        $at = $sale->sold_at ?? now();
        foreach ($sale->representatives as $row) {
            if ($row->user) {
                $role = \App\Models\Role::query()->where('slug', 'representative')->first();
                if ($role) {
                    $this->qualification->syncPermanentEligibilities('representative', $row->user, $role->id, $at);
                }
            }
        }
        foreach ($sale->managers as $row) {
            $slug = $row->role?->slug;
            if ($slug && $row->user && in_array($slug, ['sales_manager', 'development_manager'], true)) {
                $this->qualification->syncPermanentEligibilities($slug, $row->user, (int) $row->role_id, $at);
            }
        }
    }

    public function notifySubmitted(GatewaySale $sale): void
    {
        $name = $sale->gateway?->name ?? 'درگاه';
        $body = "درگاه «{$name}» ثبت شد و منتظر تایید مدیر ارشد است. تا ثبت کد مرچنت و تراکنش موفق، پورسانتی واریز نمی‌شود.";

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
            'gateway.transactions' => fn ($q) => $q->latest('id')->limit(8),
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
