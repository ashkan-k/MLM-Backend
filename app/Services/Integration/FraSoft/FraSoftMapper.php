<?php

namespace App\Services\Integration\FraSoft;

class FraSoftMapper
{
    public function user(array $row): array
    {
        return [
            'external_id' => (string) ($row['id'] ?? $row['external_id']),
            'name' => $row['name'] ?? 'FraSoft User',
            'mobile' => $row['mobile'] ?? null,
            'email' => $row['email'] ?? null,
            'roles' => $row['roles'] ?? [],
        ];
    }

    public function shares(array $row): array
    {
        return collect($row['shares'] ?? $row['representatives'] ?? [])->map(fn ($item) => [
            'external_user_id' => (string) ($item['user_id'] ?? $item['id']),
            'share_percent' => $item['share_percent'] ?? $item['percent'],
        ])->all();
    }
}
