<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogProduct;
use App\Models\FinopalTransaction;
use App\Models\ProductSale;
use App\Support\ProductCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogProductController extends Controller
{
    public function options()
    {
        return response()->json(ProductCatalog::options());
    }

    public function index()
    {
        return response()->json(
            CatalogProduct::query()->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $this->validateProduct($request);
        $product = CatalogProduct::query()->create($data + [
            'sort_order' => ((int) CatalogProduct::query()->max('sort_order')) + 1,
            'is_system' => false,
        ]);

        return response()->json($product, 201);
    }

    public function update(Request $request, CatalogProduct $product)
    {
        $data = $this->validateProduct($request, $product);
        if ($product->is_system) {
            unset($data['type']);
        }
        $product->fill($data)->save();

        return response()->json($product->fresh());
    }

    public function destroy(CatalogProduct $product)
    {
        if ($product->is_system) {
            return response()->json(['message' => 'این محصول سیستمی است و حذف نمی‌شود.'], 422);
        }
        $used = FinopalTransaction::query()->where('product_type', $product->type)->exists()
            || ProductSale::query()->where('product_type', $product->type)->exists();
        if ($used) {
            return response()->json(['message' => 'برای این محصول تراکنش ثبت شده و حذف نمی‌شود. می‌توانید آن را غیرفعال کنید.'], 422);
        }
        $product->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function validateProduct(Request $request, ?CatalogProduct $current = null): array
    {
        $data = $request->validate([
            'type' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('catalog_products', 'type')->ignore($current?->id),
            ],
            'label' => ['required', 'string', 'max:120'],
            'requires_merchant' => ['boolean'],
            'sale_points' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'default_code' => ['nullable', 'string', 'max:120'],
            'signup_url' => ['nullable', 'string', 'max:255'],
            'aliases' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ], [
            'type.required' => 'شناسه محصول الزامی است.',
            'type.regex' => 'شناسه فقط با حروف کوچک انگلیسی، عدد و _ نوشته می‌شود.',
            'type.unique' => 'این شناسه قبلاً ثبت شده است.',
            'label.required' => 'نام محصول الزامی است.',
        ]);

        $merchant = $request->boolean('requires_merchant');
        $signup = trim((string) ($data['signup_url'] ?? ''));
        if ($signup !== '' && ! filter_var($signup, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages(['signup_url' => 'لینک ثبت‌نام معتبر نیست.']);
        }
        $aliases = $this->aliases($data['aliases'] ?? '', (string) $data['type'], $current?->id);

        return [
            'type' => $data['type'],
            'label' => trim($data['label']),
            'requires_merchant' => $merchant,
            'requires_owner' => ! $merchant,
            'sale_points' => (int) ($data['sale_points'] ?? 0),
            'default_code' => $this->blank($data['default_code'] ?? null),
            'signup_url' => $signup !== '' ? $signup : null,
            'aliases' => $aliases !== '' ? $aliases : null,
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function aliases(string $raw, string $type, ?int $ignoreId): string
    {
        $parts = preg_split('/\s*,\s*/', strtolower($raw)) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $alias = str_replace([' ', '-'], '_', trim($part));
            if ($alias === '' || $alias === $type || in_array($alias, $clean, true)) {
                continue;
            }
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $alias)) {
                throw ValidationException::withMessages(['aliases' => 'نام معادل «'.$alias.'» معتبر نیست.']);
            }
            $taken = CatalogProduct::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->get(['type', 'aliases'])
                ->contains(function (CatalogProduct $product) use ($alias) {
                    $own = preg_split('/\s*,\s*/', (string) $product->aliases) ?: [];

                    return $product->type === $alias || in_array($alias, $own, true);
                });
            if ($taken) {
                throw ValidationException::withMessages(['aliases' => 'نام معادل «'.$alias.'» برای محصول دیگری است.']);
            }
            $clean[] = $alias;
        }

        return implode(',', $clean);
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
