<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinopalTransaction;
use App\Models\User;
use App\Services\Organization\OrganizationTreeService;
use App\Support\ProductCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TransactionController extends Controller
{
    public function index(Request $request, OrganizationTreeService $tree)
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'product_type' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'merchant_code' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $query = FinopalTransaction::query()->with([
            'gateway:id,name,merchant_code,external_id',
            'sale:id,gateway_id,customer_id,status,sold_at',
            'sale.customer:id,name,mobile',
            'productSale:id,title,product_type,product_code,status,sold_at',
        ]);

        $this->scopeToNetwork($query, $user, $tree);

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['product_type'])) {
            $query->where('product_type', ProductCatalog::normalize($data['product_type']));
        }
        if (! empty($data['merchant_code'])) {
            $query->where('merchant_code', 'like', '%'.$data['merchant_code'].'%');
        }
        if (! empty($data['from'])) {
            $query->where(function ($q) use ($data) {
                $from = Carbon::parse($data['from'])->startOfDay();
                $q->where('paid_at', '>=', $from)
                    ->orWhere(function ($inner) use ($from) {
                        $inner->whereNull('paid_at')->where('created_at', '>=', $from);
                    });
            });
        }
        if (! empty($data['to'])) {
            $query->where(function ($q) use ($data) {
                $to = Carbon::parse($data['to'])->endOfDay();
                $q->where('paid_at', '<=', $to)
                    ->orWhere(function ($inner) use ($to) {
                        $inner->whereNull('paid_at')->where('created_at', '<=', $to);
                    });
            });
        }
        if (! empty($data['search'])) {
            $term = '%'.trim($data['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('authority', 'like', $term)
                    ->orWhere('ref_id', 'like', $term)
                    ->orWhere('order_id', 'like', $term)
                    ->orWhere('merchant_code', 'like', $term)
                    ->orWhere('idempotency_key', 'like', $term)
                    ->orWhereHas('gateway', fn ($g) => $g->where('name', 'like', $term)->orWhere('external_id', 'like', $term))
                    ->orWhereHas('sale.customer', fn ($c) => $c->where('name', 'like', $term)->orWhere('mobile', 'like', $term))
                    ->orWhereHas('productSale', fn ($p) => $p->where('title', 'like', $term)->orWhere('product_code', 'like', $term));
            });
        }

        $page = $query->latest('id')->paginate(
            min(100, max(1, (int) ($data['per_page'] ?? 20)))
        );

        $page->getCollection()->transform(function (FinopalTransaction $tx) {
            $type = ProductCatalog::normalize((string) ($tx->product_type ?: 'gateway_profit'));
            $tx->setAttribute('product_type', $type);
            $tx->setAttribute('product_label', ProductCatalog::label($type));
            $tx->setAttribute(
                'source_title',
                $tx->gateway?->name
                    ?? $tx->productSale?->title
                    ?? $tx->productSale?->product_code
                    ?? $tx->merchant_code
            );

            return $tx;
        });

        return response()->json($page);
    }

    private function scopeToNetwork($query, User $user, OrganizationTreeService $tree): void
    {
        if ($user->isSuperuser()) {
            return;
        }

        $ids = $tree->descendants($user)->pluck('id')->push($user->id)->unique()->values()->all();

        $query->where(function ($q) use ($ids) {
            $q->whereHas('sale', function ($sale) use ($ids) {
                $sale->where(function ($inner) use ($ids) {
                    $inner->whereHas('representatives', fn ($r) => $r->whereIn('user_id', $ids))
                        ->orWhereHas('referrers', fn ($r) => $r->whereIn('user_id', $ids))
                        ->orWhereHas('managers', fn ($m) => $m->whereIn('user_id', $ids));
                });
            })->orWhereHas('productSale', function ($sale) use ($ids) {
                $sale->where(function ($inner) use ($ids) {
                    $inner->whereHas('representatives', fn ($r) => $r->whereIn('user_id', $ids))
                        ->orWhereHas('referrers', fn ($r) => $r->whereIn('user_id', $ids))
                        ->orWhereHas('managers', fn ($m) => $m->whereIn('user_id', $ids));
                });
            })->orWhereHas('commissions', fn ($c) => $c->whereIn('user_id', $ids));
        });
    }
}
