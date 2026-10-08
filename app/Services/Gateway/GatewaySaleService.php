<?php

namespace App\Services\Gateway;

use App\Models\Customer;
use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\Role;
use App\Models\SharedLink;
use App\Models\SystemSetting;
use App\Services\Commission\CommissionDistributor;
use App\Services\Commission\SalePartyResolver;
use App\Services\Integration\Finopal\VipGatewayProvisioner;
use App\Services\Referral\SharedLinkService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GatewaySaleService
{
    public function __construct(
        private readonly SharedLinkService $sharedLinks,
        private readonly CommissionDistributor $distributor,
        private readonly GatewayReviewService $reviews,
        private readonly SalePartyResolver $parties,
        private readonly VipGatewayProvisioner $finopal,
    ) {}

    public function record(array $payload): GatewaySale
    {
        return DB::transaction(function () use ($payload) {
            $key = $payload['idempotency_key'] ?? null;
            if ($key && $existing = GatewaySale::query()->where('idempotency_key', $key)->first()) {
                return $existing;
            }

            $customer = null;
            if (! empty($payload['customer'])) {
                $row = $payload['customer'];
                $customer = Customer::query()->create([
                    'name' => $row['name'],
                    'mobile' => $row['mobile'] ?? null,
                    'person_type' => $row['person_type'] ?? 'individual',
                    'national_id' => $row['national_id'] ?? null,
                    'father_name' => $row['father_name'] ?? null,
                    'birth_date' => $row['birth_date'] ?? null,
                    'birth_certificate_no' => $row['birth_certificate_no'] ?? null,
                    'birth_place' => $row['birth_place'] ?? null,
                    'gender' => $row['gender'] ?? null,
                    'email' => $row['email'] ?? null,
                    'province' => $row['province'] ?? null,
                    'city' => $row['city'] ?? null,
                    'address' => $row['address'] ?? null,
                    'postal_code' => $row['postal_code'] ?? null,
                    'sheba' => $row['sheba'] ?? null,
                    'bank_name' => $row['bank_name'] ?? null,
                    'account_number' => $row['account_number'] ?? null,
                    'account_holder' => $row['account_holder'] ?? null,
                    'shop_name' => $row['shop_name'] ?? null,
                    'shop_category' => $row['shop_category'] ?? null,
                    'website' => $row['website'] ?? null,
                    'company_name' => $row['company_name'] ?? null,
                    'registration_no' => $row['registration_no'] ?? null,
                    'economic_code' => $row['economic_code'] ?? null,
                    'legal_national_id' => $row['legal_national_id'] ?? null,
                    'documents' => $row['documents'] ?? null,
                    'metadata' => $row['metadata'] ?? null,
                ]);
            }

            $gateway = Gateway::query()->firstOrCreate(
                ['external_id' => $payload['external_id']],
                [
                    'name' => $payload['name'] ?? $payload['external_id'],
                    'source' => $payload['source'] ?? 'finopal',
                    'sale_amount' => $payload['amount'] ?? 0,
                    'merchant_code' => $payload['merchant_code'] ?? null,
                    'is_active' => true,
                    'metadata' => [
                        'ownership_type' => $payload['ownership_type'] ?? 'solo',
                    ],
                ]
            );

            $status = $payload['status'] ?? (
                ($payload['source'] ?? 'finopal') === 'frasoft' ? 'successful' : 'pending_inspection'
            );

            $sale = GatewaySale::query()->create([
                'gateway_id' => $gateway->id,
                'customer_id' => $customer?->id,
                'shared_link_id' => $payload['shared_link_id'] ?? null,
                'amount' => $payload['amount'] ?? 0,
                'full_sales_points' => config('finopal.full_sale_points'),
                'status' => $status,
                'sold_at' => $payload['sold_at'] ?? now(),
                'idempotency_key' => $key ?: 'sale-'.Str::uuid(),
            ]);

            $reps = $this->resolveRepresentatives($payload);
            $this->distributor->assertShares($reps);

            foreach ($reps as $rep) {
                $sale->representatives()->create([
                    'user_id' => $rep['user_id'],
                    'share_percent' => $rep['share_percent'],
                    'sales_points' => Money::percentOf((string) $sale->full_sales_points, (string) $rep['share_percent']),
                ]);
            }

            foreach ($this->parties->resolveReferrers($reps) as $ref) {
                $sale->referrers()->create($ref);
            }

            foreach ($this->parties->resolveManagers($reps[0]['user_id']) as $manager) {
                $sale->managers()->create($manager);
            }

            if (! empty($payload['shared_link_id'])) {
                $this->sharedLinks->consume(SharedLink::query()->findOrFail($payload['shared_link_id']));
            }

            $sale = $sale->fresh(['gateway', 'representatives.user', 'referrers.user', 'managers.user', 'managers.role']);
            $this->reviews->addReview($sale, null, 'submitted', 'submitted');

            if ($status === 'successful') {
                if (! empty($payload['merchant_code'])) {
                    $sale->gateway?->update(['merchant_code' => $payload['merchant_code']]);
                }
            } else {
                $this->reviews->notifySubmitted($sale);
            }

            $sale = $sale->fresh(['gateway', 'customer', 'representatives.user', 'referrers.user', 'managers.user', 'managers.role', 'reviews.actor', 'commissions']);
            if (! empty($payload['sync_finopal'])) {
                $this->finopal->provision($sale);
                $sale = $sale->fresh(['gateway', 'customer', 'representatives.user', 'referrers.user', 'managers.user', 'managers.role', 'reviews.actor', 'commissions']);
            }

            return $sale;
        });
    }

    public function updateParties(GatewaySale $sale, array $payload): GatewaySale
    {
        return DB::transaction(function () use ($sale, $payload) {
            if (array_key_exists('representatives', $payload) && is_array($payload['representatives'])) {
                $reps = $payload['representatives'];
                $this->distributor->assertShares($reps);
                $sale->representatives()->delete();
                foreach ($reps as $rep) {
                    $sale->representatives()->create([
                        'user_id' => $rep['user_id'],
                        'share_percent' => $rep['share_percent'],
                        'sales_points' => Money::percentOf((string) $sale->full_sales_points, (string) $rep['share_percent']),
                    ]);
                }
                $sale->referrers()->delete();
                foreach ($this->parties->resolveReferrers($reps) as $ref) {
                    $sale->referrers()->create($ref);
                }
            }

            if (array_key_exists('managers', $payload) && is_array($payload['managers'])) {
                $sale->managers()->delete();
                foreach ($payload['managers'] as $manager) {
                    $role = Role::query()->where('slug', $manager['role_slug'] ?? '')->first()
                        ?? Role::query()->find($manager['role_id'] ?? 0);
                    if (! $role || ! in_array($role->slug, ['sales_manager', 'development_manager', 'senior_manager'], true)) {
                        continue;
                    }
                    $sale->managers()->create([
                        'user_id' => $manager['user_id'],
                        'role_id' => $role->id,
                        'commission_percent' => $manager['commission_percent'] ?? 0,
                    ]);
                }
            } elseif (array_key_exists('representatives', $payload)) {
                $firstRep = $sale->representatives()->first();
                if ($firstRep) {
                    $sale->managers()->delete();
                    foreach ($this->parties->resolveManagers($firstRep->user_id) as $manager) {
                        $sale->managers()->create($manager);
                    }
                }
            }

            $sale = $sale->fresh([
                'gateway',
                'customer',
                'representatives.user',
                'referrers.user',
                'managers.user',
                'managers.role',
                'reviews.actor',
                'commissions.role',
            ]);
            $this->finopal->provision($sale);

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
        });
    }

    private function resolveRepresentatives(array $payload): array
    {
        if (! empty($payload['shared_link_id'])) {
            $link = SharedLink::query()->with('members')->findOrFail($payload['shared_link_id']);
            if ($link->type !== 'gateway_sale') {
                abort(422, 'این لینک برای فروش اشتراکی درگاه نیست.');
            }
            if (! SystemSetting::sharedLinkTypeEnabled('gateway_sale')) {
                abort(422, 'فروش اشتراکی درگاه فعلاً غیرفعال است.');
            }
            if ($link->status !== 'active') {
                abort(422, 'لینک اشتراکی فعال نیست.');
            }

            return $link->members->map(fn ($m) => [
                'user_id' => $m->user_id,
                'share_percent' => (string) $m->share_percent,
            ])->all();
        }

        if (! empty($payload['representatives'])) {
            return $payload['representatives'];
        }

        return [[
            'user_id' => $payload['representative_user_id'],
            'share_percent' => '100.000',
        ]];
    }

}
