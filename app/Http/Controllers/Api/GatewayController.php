<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\User;
use App\Services\Authorization\PermissionService;
use App\Services\Gateway\GatewayReviewService;
use App\Services\Gateway\GatewaySaleService;
use Illuminate\Http\Request;

class GatewayController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Gateway::query()->with(['sales.representatives']);

        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            $query->whereHas('sales.representatives', fn ($q) => $q->where('user_id', $user->id));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function sales(Request $request)
    {
        $user = $request->user();
        $query = GatewaySale::query()->with([
            'gateway.transactions' => fn ($q) => $q->latest('id')->limit(8),
            'customer',
            'representatives.user',
            'referrers.user',
            'managers.user',
            'managers.role',
            'reviews.actor:id,name,mobile',
            'commissions.role',
        ]);

        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('representatives', fn ($s) => $s->where('user_id', $user->id))
                    ->orWhereHas('referrers', fn ($s) => $s->where('user_id', $user->id))
                    ->orWhereHas('managers', fn ($s) => $s->where('user_id', $user->id));
            });
        }

        return response()->json($query->latest('sold_at')->paginate(20));
    }

    public function store(Request $request, GatewaySaleService $sales, PermissionService $permissions)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');
        if (! $user->isSuperuser()) {
            $permissions->authorize($user, 'representative.gateway.create', $role);
        }

        $data = $request->validate([
            'external_id' => ['required', 'string'],
            'name' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'source' => ['nullable', 'string'],
            'ownership_type' => ['nullable', 'in:solo,shared,referral'],
            'representative_user_id' => ['nullable', 'exists:users,id'],
            'shared_link_id' => ['nullable', 'exists:shared_links,id'],
            'representatives' => ['nullable', 'array'],
            'customer.name' => ['required', 'string'],
            'customer.mobile' => ['required', 'string'],
            'customer.national_id' => ['required', 'string', 'size:10'],
            'customer.sheba' => ['required', 'string'],
            'customer.person_type' => ['nullable', 'in:individual,legal'],
            'customer.email' => ['nullable', 'email'],
            'customer.father_name' => ['nullable', 'string'],
            'customer.birth_date' => ['nullable', 'date'],
            'customer.birth_certificate_no' => ['nullable', 'string'],
            'customer.birth_place' => ['nullable', 'string'],
            'customer.gender' => ['nullable', 'string'],
            'customer.province' => ['nullable', 'string'],
            'customer.city' => ['nullable', 'string'],
            'customer.address' => ['nullable', 'string'],
            'customer.postal_code' => ['nullable', 'string'],
            'customer.bank_name' => ['nullable', 'string'],
            'customer.account_number' => ['nullable', 'string'],
            'customer.account_holder' => ['nullable', 'string'],
            'customer.shop_name' => ['nullable', 'string'],
            'customer.shop_category' => ['nullable', 'string'],
            'customer.website' => ['nullable', 'string'],
            'customer.company_name' => ['nullable', 'string'],
            'customer.registration_no' => ['nullable', 'string'],
            'customer.economic_code' => ['nullable', 'string'],
            'customer.legal_national_id' => ['nullable', 'string'],
            'documents.national_id_front' => ['nullable', 'file', 'max:5120'],
            'documents.national_id_back' => ['nullable', 'file', 'max:5120'],
            'documents.birth_certificate' => ['nullable', 'file', 'max:5120'],
            'documents.selfie' => ['nullable', 'file', 'max:5120'],
            'documents.gazette' => ['nullable', 'file', 'max:5120'],
            'documents.license' => ['nullable', 'file', 'max:5120'],
            'idempotency_key' => ['nullable', 'string'],
            'sold_at' => ['nullable', 'date'],
        ]);

        $docs = [];
        foreach (['national_id_front', 'national_id_back', 'birth_certificate', 'selfie', 'gazette', 'license'] as $key) {
            if ($request->hasFile("documents.$key")) {
                $docs[$key] = $request->file("documents.$key")->store('gateway-kyc', 'public');
            }
        }
        $data['customer']['documents'] = $docs;

        if (! $user->isSuperuser() && empty($data['shared_link_id'])) {
            $data['representative_user_id'] = $user->id;
        } elseif (empty($data['representative_user_id']) && empty($data['shared_link_id'])) {
            $data['representative_user_id'] = $user->id;
        }

        return response()->json($sales->record($data)->load(['gateway', 'customer', 'representatives.user', 'reviews.actor', 'commissions']), 201);
    }

    public function show(Request $request, GatewaySale $sale)
    {
        $this->assertCanView($request->user(), $sale);

        return response()->json($sale->load([
            'gateway.transactions' => fn ($q) => $q->latest('id')->limit(8),
            'customer',
            'representatives.user',
            'referrers.user',
            'managers.user',
            'managers.role',
            'reviews.actor:id,name,mobile',
            'commissions.role',
        ]));
    }

    public function inspect(Request $request, GatewaySale $sale, GatewayReviewService $reviews, PermissionService $permissions)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');
        if (! $user->isSuperuser()) {
            $permissions->authorize($user, 'senior_manager.gateway.inspect', $role);
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string'],
            'merchant_code' => ['required_if:decision,approved', 'nullable', 'string', 'max:64'],
        ]);

        return response()->json($reviews->inspect(
            $user,
            $sale,
            $data['decision'],
            $data['note'] ?? '',
            $data['merchant_code'] ?? null,
        ));
    }

    public function commissions(Request $request)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');

        $query = Commission::query()->with(['role', 'sale.gateway']);
        if (! $user->isSuperuser()) {
            $query->where('user_id', $user->id);
            if ($role) {
                $query->where('role_id', $role->id);
            }
        }

        return response()->json($query->latest()->paginate(20));
    }

    private function assertCanView(User $user, GatewaySale $sale): void
    {
        if ($user->isSuperuser() || $user->hasRole('senior_manager')) {
            return;
        }

        $sale->loadMissing(['representatives', 'referrers', 'managers']);
        $ids = collect()
            ->merge($sale->representatives->pluck('user_id'))
            ->merge($sale->referrers->pluck('user_id'))
            ->merge($sale->managers->pluck('user_id'));

        if (! $ids->contains($user->id)) {
            abort(403, 'دسترسی به این درگاه مجاز نیست.');
        }
    }
}
