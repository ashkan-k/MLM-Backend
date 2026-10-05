<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Gateway;
use App\Models\GatewaySale;
use App\Models\ProductSale;
use App\Models\User;
use App\Services\Authorization\PermissionService;
use App\Services\Gateway\GatewayReviewService;
use App\Services\Gateway\GatewaySaleService;
use App\Support\ProductCatalog;
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
        $filters = $this->saleFilters($request);
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

        $this->applySaleFilters($query, $filters, gatewayMode: true);

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
            $sale->setAttribute('product_type', 'gateway_profit');
            $sale->setAttribute('product_code', 'GATEWAY');
            $sale->setAttribute('product_label', ProductCatalog::label('gateway_profit'));
            $sale->setAttribute('sale_kind', 'gateway');

            return $sale;
        });

        return response()->json($sales);
    }

    public function productSales(Request $request)
    {
        if (! ProductCatalog::oriented()) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'from' => null,
                'to' => null,
                'total' => 0,
                'per_page' => (int) $request->input('per_page', 20),
            ]);
        }

        $user = $request->user();
        $role = $request->attributes->get('active_role');
        $filters = $this->saleFilters($request);
        $query = ProductSale::query()->with([
            'representatives.user',
            'referrers.user',
            'managers.user',
            'managers.role',
            'commissions.role',
            'transactions',
        ]);

        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('representatives', fn ($s) => $s->where('user_id', $user->id))
                    ->orWhereHas('referrers', fn ($s) => $s->where('user_id', $user->id))
                    ->orWhereHas('managers', fn ($s) => $s->where('user_id', $user->id));
            });
        }

        $this->applySaleFilters($query, $filters, gatewayMode: false);

        $sales = $query->latest('sold_at')->paginate(
            min(100, max(1, (int) $request->input('per_page', 20)))
        );
        $saleIds = $sales->getCollection()->pluck('id');

        $totals = Commission::query()
            ->where('user_id', $user->id)
            ->when($role && ! $user->isSuperuser(), fn ($q) => $q->where('role_id', $role->id))
            ->whereIn('product_sale_id', $saleIds)
            ->selectRaw('product_sale_id, SUM(commission_amount) as total')
            ->groupBy('product_sale_id')
            ->pluck('total', 'product_sale_id');

        $sales->getCollection()->transform(function (ProductSale $sale) use ($totals) {
            $sale->setAttribute(
                'my_commission_total',
                number_format((float) ($totals[$sale->id] ?? 0), 3, '.', '')
            );
            $sale->setAttribute('product_label', ProductCatalog::label($sale->product_type));
            $sale->setAttribute('sale_kind', 'product');

            return $sale;
        });

        return response()->json($sales);
    }

    public function store(Request $request, GatewaySaleService $sales, PermissionService $permissions)
    {
        $user = $request->user();
        if (! $user->isSuperuser() && ! $permissions->can($user, 'representative.gateway.create') && ! $permissions->can($user, 'superuser.gateway.create')) {
            abort(403, 'برای ثبت درگاه باید نقش نماینده فعال باشد یا دسترسی ثبت درگاه داشته باشید.');
        }

        $personType = $request->input('customer.person_type', 'individual');
        $isLegal = $personType === 'legal';

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
            'customer.sheba' => ['required', 'string', 'regex:/^(IR)?[0-9]{24}$/i'],
            'customer.person_type' => ['nullable', 'in:individual,legal'],
            'customer.email' => ['nullable', 'email'],
            'customer.father_name' => ['nullable', 'string'],
            'customer.birth_date' => ['nullable', 'date'],
            'customer.birth_certificate_no' => ['nullable', 'string'],
            'customer.birth_place' => ['nullable', 'string'],
            'customer.gender' => ['nullable', 'string'],
            'customer.province' => [$isLegal ? 'required' : 'nullable', 'string'],
            'customer.city' => [$isLegal ? 'required' : 'nullable', 'string'],
            'customer.address' => [$isLegal ? 'required' : 'nullable', 'string'],
            'customer.postal_code' => [$isLegal ? 'required' : 'nullable', 'string'],
            'customer.bank_name' => ['nullable', 'string'],
            'customer.account_number' => ['nullable', 'string'],
            'customer.account_holder' => ['nullable', 'string'],
            'customer.shop_name' => [$isLegal ? 'required' : 'nullable', 'string'],
            'customer.shop_category' => [$isLegal ? 'required' : 'nullable', 'string'],
            'customer.website' => ['nullable', 'string'],
            'customer.company_name' => [$isLegal ? 'required' : 'nullable', 'string'],
            'customer.registration_no' => [$isLegal ? 'required' : 'nullable', 'string'],
            'customer.economic_code' => [$isLegal ? 'required' : 'nullable', 'string'],
            'customer.legal_national_id' => [$isLegal ? 'required' : 'nullable', 'string'],
            'documents.national_id_front' => ['required', 'file', 'max:5120'],
            'documents.national_id_back' => ['required', 'file', 'max:5120'],
            'documents.birth_certificate' => ['required', 'file', 'max:5120'],
            'documents.selfie' => ['required', 'file', 'max:5120'],
            'documents.gazette' => [$isLegal ? 'required' : 'nullable', 'file', 'max:5120'],
            'documents.license' => [$isLegal ? 'required' : 'nullable', 'file', 'max:5120'],
            'idempotency_key' => ['nullable', 'string'],
            'sold_at' => ['nullable', 'date'],
        ], [
            'customer.sheba.regex' => 'شبا باید ۲۴ رقم باشد (با یا بدون پیشوند IR).',
            'customer.national_id.size' => 'کد ملی باید دقیقاً ۱۰ رقم باشد.',
            'customer.email.email' => 'فرمت ایمیل معتبر نیست.',
            'customer.province.required' => 'برای شخص حقوقی، استان الزامی است.',
            'customer.city.required' => 'برای شخص حقوقی، شهر الزامی است.',
            'customer.address.required' => 'برای شخص حقوقی، نشانی کامل الزامی است.',
            'customer.postal_code.required' => 'برای شخص حقوقی، کد پستی الزامی است.',
            'customer.shop_name.required' => 'برای شخص حقوقی، نام فروشگاه الزامی است.',
            'customer.shop_category.required' => 'برای شخص حقوقی، صنف/دسته الزامی است.',
            'customer.company_name.required' => 'نام شرکت الزامی است.',
            'customer.registration_no.required' => 'شماره ثبت شرکت الزامی است.',
            'customer.economic_code.required' => 'شناسه اقتصادی الزامی است.',
            'customer.legal_national_id.required' => 'شناسه ملی شرکت الزامی است.',
            'documents.national_id_front.required' => 'تصویر روی کارت ملی الزامی است.',
            'documents.national_id_back.required' => 'تصویر پشت کارت ملی الزامی است.',
            'documents.birth_certificate.required' => 'تصویر شناسنامه الزامی است.',
            'documents.selfie.required' => 'سلفی احراز هویت الزامی است.',
            'documents.gazette.required' => 'روزنامه رسمی / آگهی تأسیس برای شخص حقوقی الزامی است.',
            'documents.license.required' => 'مجوز یا پروانه کسب برای شخص حقوقی الزامی است.',
        ]);

        if (! empty($data['shared_link_id'])) {
            $isMember = \App\Models\SharedLink::query()
                ->where('id', $data['shared_link_id'])
                ->where(function ($q) use ($user) {
                    $q->where('creator_user_id', $user->id)
                        ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id));
                })
                ->exists();
            if (! $user->isSuperuser() && ! $isMember) {
                abort(403, 'فقط اعضای لینک اشتراکی می‌توانند با آن درگاه ثبت کنند.');
            }
        }

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

        $sale->load([
            'gateway.transactions' => fn ($q) => $q->latest('id')->limit(8),
            'customer',
            'representatives.user',
            'referrers.user',
            'managers.user',
            'managers.role',
            'reviews.actor:id,name,mobile',
            'commissions.role',
        ]);
        $sale->setAttribute('product_type', 'gateway_profit');
        $sale->setAttribute('product_code', 'GATEWAY');
        $sale->setAttribute('product_label', ProductCatalog::label('gateway_profit'));
        $sale->setAttribute('sale_kind', 'gateway');

        return response()->json($sale);
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

        $query = Commission::query()->with([
            'role',
            'sale.gateway',
            'productSale',
            'transaction',
        ]);
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

        $page = $query->latest()->paginate(20);
        $oriented = ProductCatalog::oriented();
        $page->getCollection()->transform(function (Commission $row) use ($oriented) {
            $metaProduct = is_array($row->metadata) ? ($row->metadata['product_type'] ?? null) : null;
            $productType = $oriented
                ? ($row->transaction?->product_type
                    ?? $row->productSale?->product_type
                    ?? $metaProduct
                    ?? ($row->gateway_sale_id ? 'gateway_profit' : null)
                    ?? 'gateway_profit')
                : 'gateway_profit';

            $normalized = ProductCatalog::normalize((string) $productType);
            $row->setAttribute('product_type', $normalized);
            $row->setAttribute('product_code', $oriented
                ? ($row->transaction?->product_code ?? $row->productSale?->product_code)
                : ($row->transaction?->product_code ?? 'GATEWAY'));
            $row->setAttribute('product_label', ProductCatalog::label($normalized));
            $row->setAttribute(
                'source_title',
                $row->sale?->gateway?->name
                    ?? $row->productSale?->title
                    ?? $row->productSale?->product_code
                    ?? null
            );

            return $row;
        });

        return response()->json($page);
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

    /**
     * @return array{search:?string,status:?string,from:?string,to:?string}
     */
    private function saleFilters(Request $request): array
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            'search' => filled($data['search'] ?? null) ? trim((string) $data['search']) : null,
            'status' => filled($data['status'] ?? null) ? (string) $data['status'] : null,
            'from' => filled($data['from'] ?? null) ? (string) $data['from'] : null,
            'to' => filled($data['to'] ?? null) ? (string) $data['to'] : null,
        ];
    }

    private function applySaleFilters($query, array $filters, bool $gatewayMode): void
    {
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }
        if ($filters['from']) {
            $query->whereDate('sold_at', '>=', $filters['from']);
        }
        if ($filters['to']) {
            $query->whereDate('sold_at', '<=', $filters['to']);
        }
        if (! $filters['search']) {
            return;
        }

        $term = '%'.$filters['search'].'%';
        $query->where(function ($q) use ($term, $gatewayMode) {
            if ($gatewayMode) {
                $q->whereHas('gateway', fn ($g) => $g->where('name', 'like', $term)
                    ->orWhere('external_id', 'like', $term)
                    ->orWhere('merchant_code', 'like', $term))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term)
                        ->orWhere('mobile', 'like', $term)
                        ->orWhere('national_id', 'like', $term));
            } else {
                $q->where('title', 'like', $term)
                    ->orWhere('product_code', 'like', $term)
                    ->orWhere('product_type', 'like', $term);
            }
            $q->orWhereHas('representatives.user', fn ($u) => $u->where('name', 'like', $term)->orWhere('mobile', 'like', $term));
        });
    }
}
