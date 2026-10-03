<?php

namespace App\Support;

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
        $known = (array) config("finopal.products.{$type}");
        if ($known !== []) {
            return $known + ['type' => $type];
        }

        return (array) config('finopal.products._default', [
            'label' => 'سود درگاه پرداخت',
            'requires_merchant' => false,
            'requires_owner' => true,
            'sale_points' => 0,
        ]) + ['type' => 'gateway_profit'];
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

        return match ($type) {
            'gateway', 'gateway_payment', 'payment_gateway', '',
            'monthly_bonus', 'monthly_bonus_residual', 'organizational', 'custom' => 'gateway_profit',
            'ticket', 'tickets', 'finopal_ticketing' => 'ticketing',
            default => $type,
        };
    }

    /** @return list<array{type:string,label:string}> */
    public static function options(): array
    {
        $out = [];
        foreach ((array) config('finopal.products', []) as $type => $cfg) {
            if ($type === '_default' || ! is_array($cfg)) {
                continue;
            }
            $out[] = [
                'type' => (string) $type,
                'label' => (string) ($cfg['label'] ?? $type),
            ];
        }

        return $out;
    }
}
