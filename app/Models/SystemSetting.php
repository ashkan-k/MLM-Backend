<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'value_type', 'is_public'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_public' => 'boolean',
        ];
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row?->value ?? $default;
    }

    public static function sharedLinkFeatures(): array
    {
        $defaults = [
            'referral_enabled' => true,
            'gateway_sale_enabled' => true,
        ];

        $value = static::getValue('shared_link_features', $defaults);

        return [
            'referral_enabled' => (bool) ($value['referral_enabled'] ?? true),
            'gateway_sale_enabled' => (bool) ($value['gateway_sale_enabled'] ?? true),
        ];
    }

    public static function sharedLinkTypeEnabled(string $type): bool
    {
        $features = static::sharedLinkFeatures();

        return match ($type) {
            'referral' => $features['referral_enabled'],
            'gateway_sale' => $features['gateway_sale_enabled'],
            default => false,
        };
    }
}
