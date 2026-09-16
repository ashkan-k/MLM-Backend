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
        $role = $request->attributes->get('active_role');
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

        $sales = $query->latest('sold_at')->paginate(
            min(100, max(1, (int) $request->input('per_page', 20)))
        );
        $saleIds = $sales->getCollection()->pluck('id');

        $totals = Commission::query()
            ->where('user_id', $user->id)
            ->when($role && ! $user->isSuperuser(), fn ($q) => $q->where('role_id', $role->id))
            ->whereIn('gateway_sale_id', $saleIds)
            ->selectRaw('gateway_sale_id, SUM(commission_amount) as total')
            ->groupBy('gateway_sale_id')
            ->pluck('total', 'gateway_sale_id');

        $sales->getCollection()->transform(function (GatewaySale $sale) use ($totals) {
            $sale->setAttribute(
                'my_commission_total',
                number_format((float) ($totals[$sale->id] ?? 0), 3, '.', '')
            );

            return $sale;
        });

        return response()->json($sales);
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
            'amount' => ['nullable', 'numeric', 'min:0'],
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

    public function updateParties(Request $request, GatewaySale $sale, GatewaySaleService $sales)
    {
        $user = $request->user();
        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            abort(403, 'فقط مدیر ارشد یا مدیر سامانه می‌تواند طرف‌های درگاه را تغییر دهد.');
        }

        $data = $request->validate([
            'representatives' => ['nullable', 'array', 'min:1'],
            'representatives.*.user_id' => ['required_with:representatives', 'exists:users,id'],
            'representatives.*.share_percent' => ['required_with:representatives', 'numeric', 'min:0', 'max:100'],
            'managers' => ['nullable', 'array'],
            'managers.*.user_id' => ['required_with:managers', 'exists:users,id'],
            'managers.*.role_slug' => ['required_with:managers', 'in:sales_manager,development_manager,senior_manager'],
            'managers.*.commission_percent' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($sales->updateParties($sale, $data));
    }

    public function commissions(Request $request)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');

        $data = $request->validate([
            'gateway_id' => ['nullable', 'integer', 'exists:gateways,id'],
            'gateway_sale_id' => ['nullable', 'integer', 'exists:gateway_sales,id'],
        ]);

        $query = Commission::query()->with(['role', 'sale.gateway']);
        if (! $user->isSuperuser()) {
            $query->where('user_id', $user->id);
            if ($role) {
                $query->where('role_id', $role->id);
            }
        }

        if (! empty($data['gateway_sale_id'])) {
            $query->where('gateway_sale_id', $data['gateway_sale_id']);
        } elseif (! empty($data['gateway_id'])) {
            $query->whereHas('sale', fn ($q) => $q->where('gateway_id', $data['gateway_id']));
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
