<?php

namespace App\Services\Integration\FraSoft;

use Illuminate\Support\Facades\Http;

class FraSoftClient
{
    public function pull(string $resource, array $query = []): array
    {
        $base = rtrim((string) config('services.frasoft.base_url', env('FRASOFT_BASE_URL', 'https://frasoft.example/api')), '/');

        $response = Http::timeout(15)
            ->withToken((string) config('services.frasoft.token', env('FRASOFT_TOKEN', 'demo')))
            ->get($base.'/'.$resource, $query);

        return $response->json() ?? [];
    }

    public function push(string $resource, array $payload): array
    {
        $base = rtrim((string) config('services.frasoft.base_url', env('FRASOFT_BASE_URL', 'https://frasoft.example/api')), '/');

        $response = Http::timeout(15)
            ->withToken((string) config('services.frasoft.token', env('FRASOFT_TOKEN', 'demo')))
            ->post($base.'/'.$resource, $payload);

        return $response->json() ?? [];
    }
}
