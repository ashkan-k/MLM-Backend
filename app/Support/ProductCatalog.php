<?php

namespace App\Support;

use App\Models\CatalogProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ProductCatalog
{
    public static function oriented(): bool
    {
        return (bool) config('finopal.product_oriented', false);
    }

    /** @return array<string, mixed> */
    public static function definition(string $productType): array
    {
        $type = self::normalize($productType);
        foreach (self::rows() as $row) {
            if ($row['type'] === $type) {
                return $row;
            }
        }

        return self::fallback($type);
    }

    public static function known(string $productType): bool
    {
        $type = self::normalize($productType);
        foreach (self::rows() as $row) {
            if ($row['type'] === $type) {
                return true;
            }
        }

        return false;
    }

    public static function label(?string $productType): string
    {
        $type = self::normalize($productType ?: 'gateway_profit');

        return (string) (self::definition($type)['label'] ?? 'سود درگاه پرداخت');
    }

    public static function normalize(?string $raw): string
    {
        $type = strtolower(trim((string) ($raw ?: 'gateway_profit')));
        $type = str_replace([' ', '-'], '_', $type);

        $builtin = match ($type) {
            'gateway', 'gateway_payment', 'payment_gateway', '' => 'gateway_profit',
            'monthly_bonus', 'organizational', 'custom' => 'gateway_profit',
            'monthly_bonus_residual', 'bonus_residual' => 'monthly_bonus_residual',
            'ticket', 'tickets', 'finopal_ticketing' => 'ticketing',
            'ghale_bazar', 'template_bazaar' => 'ghalebazar',
            default => $type,
        };
        if ($builtin !== $type) {
            return $builtin;
        }

        foreach (self::rows() as $row) {
            if ($row['type'] === $type || in_array($type, $row['aliases'], true)) {
                return $row['type'];
            }
        }

        return $type;
    }

    /** @return list<array{type:string,label:string}> */
    public static function options(): array
    {
        $out = [];
        foreach (self::rows() as $row) {
            if (! $row['is_active']) {
                continue;
            }
            $out[] = [
                'type' => $row['type'],
                'label' => $row['label'],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function signupDefinitions(): array
    {
        return array_values(array_filter(
            self::rows(),
            fn (array $row) => $row['is_active'] && trim((string) ($row['signup_url'] ?? '')) !== ''
        ));
    }

    public static function forget(): void
    {
        Cache::forget('catalog_products.v1');
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        $load = function () {
            if (! Schema::hasTable('catalog_products')) {
                return self::fromConfig();
            }
            $stored = CatalogProduct::query()->orderBy('sort_order')->orderBy('id')->get();
            if ($stored->isEmpty()) {
                return self::fromConfig();
            }

            return $stored->map(fn (CatalogProduct $product) => self::shape($product->type, [
                'label' => $product->label,
                'requires_merchant' => $product->requires_merchant,
                'requires_owner' => $product->requires_owner,
                'sale_points' => $product->sale_points,
                'default_code' => $product->default_code,
                'signup_url' => $product->signup_url,
                'aliases' => $product->aliases,
                'is_system' => $product->is_system,
                'is_active' => $product->is_active,
            ]))->all();
        };

        if (app()->runningUnitTests()) {
            return $load();
        }

        return Cache::remember('catalog_products.v1', 300, $load);
    }

    /** @return list<array<string, mixed>> */
    private static function fromConfig(): array
    {
        $out = [];
        foreach ((array) config('finopal.products', []) as $type => $cfg) {
            if ($type === '_default' || ! is_array($cfg)) {
                continue;
            }
            $out[] = self::shape((string) $type, $cfg);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $cfg */
    private static function shape(string $type, array $cfg): array
    {
        return [
            'type' => $type,
            'label' => (string) ($cfg['label'] ?? $type),
            'requires_merchant' => (bool) ($cfg['requires_merchant'] ?? false),
            'requires_owner' => (bool) ($cfg['requires_owner'] ?? true),
            'sale_points' => (int) ($cfg['sale_points'] ?? 0),
            'default_code' => $cfg['default_code'] ?? null,
            'signup_url' => $cfg['signup_url'] ?? null,
            'aliases' => self::aliasList($cfg['aliases'] ?? null),
            'is_system' => (bool) ($cfg['is_system'] ?? false),
            'is_active' => (bool) ($cfg['is_active'] ?? true),
        ];
    }

    /** @return list<string> */
    private static function aliasList(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/\s*,\s*/', (string) $raw) ?: [];
        }
        $out = [];
        foreach ($parts as $part) {
            $alias = strtolower(trim((string) $part));
            $alias = str_replace([' ', '-'], '_', $alias);
            if ($alias !== '' && ! in_array($alias, $out, true)) {
                $out[] = $alias;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function fallback(string $type): array
    {
        return self::shape($type, (array) config('finopal.products._default', [
            'label' => $type,
            'requires_merchant' => false,
            'requires_owner' => true,
            'sale_points' => 0,
        ]));
    }
}
