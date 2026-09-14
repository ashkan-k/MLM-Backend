<?php

namespace App\Services\Geo;

use App\Models\GeoCity;
use App\Models\GeoState;
use App\Support\PersianText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class GeoCatalogService
{
    public function jsonPath(): string
    {
        return database_path('data/iran-geo.json');
    }

    public function importFromJson(?string $path = null, bool $persistNormalized = false): array
    {
        $file = $path ?: $this->jsonPath();
        if (! File::exists($file)) {
            throw new \RuntimeException('فایل داده جغرافیایی پیدا نشد: '.$file);
        }

        $payload = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
        $payload = $this->normalizePayload($payload);
        if ($persistNormalized) {
            $this->writeJson($payload);
        }

        return $this->importPayload($payload);
    }

    public function importFromShopMaker(): array
    {
        $states = DB::connection('shop_maker')->table('states')
            ->orderBy('id')
            ->get(['id', 'code', 'title', 'slug'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => (int) $row->code,
                'title' => (string) $row->title,
                'slug' => (string) $row->slug,
            ])
            ->all();

        $cities = DB::connection('shop_maker')->table('cities')
            ->orderBy('id')
            ->get(['id', 'state_id', 'code', 'slug', 'city_title', 'title_sub_city'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'state_id' => (int) $row->state_id,
                'code' => (int) $row->code,
                'slug' => (string) $row->slug,
                'title' => (string) $row->city_title,
                'sub_title' => $row->title_sub_city,
            ])
            ->all();

        $payload = $this->normalizePayload(['states' => $states, 'cities' => $cities]);
        $this->writeJson($payload);

        return $this->importPayload($payload);
    }

    public function importPayload(array $payload): array
    {
        $payload = $this->normalizePayload($payload);
        $states = $payload['states'] ?? [];
        $cities = $payload['cities'] ?? [];

        DB::transaction(function () use ($states, $cities) {
            foreach ($states as $row) {
                GeoState::query()->updateOrCreate(
                    ['id' => $row['id']],
                    [
                        'code' => $row['code'] ?? null,
                        'title' => $row['title'],
                        'slug' => $row['slug'] ?? null,
                    ]
                );
            }
            foreach ($cities as $row) {
                GeoCity::query()->updateOrCreate(
                    ['id' => $row['id']],
                    [
                        'state_id' => $row['state_id'],
                        'code' => $row['code'] ?? null,
                        'slug' => $row['slug'] ?? null,
                        'title' => $row['title'],
                        'sub_title' => $row['sub_title'] ?? null,
                    ]
                );
            }
        });

        return ['states' => count($states), 'cities' => count($cities)];
    }

    private function normalizePayload(array $payload): array
    {
        $payload['states'] = array_map(function ($row) {
            $row['title'] = PersianText::normalize($row['title'] ?? '');
            $row['slug'] = $row['slug'] ?? null;

            return $row;
        }, $payload['states'] ?? []);

        $payload['cities'] = array_map(function ($row) {
            $row['title'] = PersianText::normalize($row['title'] ?? '');
            $row['sub_title'] = ($row['sub_title'] ?? null) !== null && $row['sub_title'] !== ''
                ? PersianText::normalize((string) $row['sub_title'])
                : null;

            return $row;
        }, $payload['cities'] ?? []);

        return $payload;
    }

    private function writeJson(array $payload): void
    {
        File::ensureDirectoryExists(dirname($this->jsonPath()));
        File::put(
            $this->jsonPath(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }
}
