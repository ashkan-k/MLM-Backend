<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integration\Finopal\VipPartnerClient;
use App\Services\Integration\Finopal\VipPartnerException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FinopalVipReferenceController extends Controller
{
    public function __construct(private readonly VipPartnerClient $client) {}

    public function reference()
    {
        $this->ensureEnabled();

        $payload = Cache::remember('finopal.vip.reference', 3600, function () {
            return [
                'states' => $this->options($this->client->get('reference/states')),
                'categories' => $this->options($this->client->get('reference/gateway-categories'), ['title_fa', 'title', 'name', 'title_en', 'slug']),
                'enums' => $this->enums($this->client->get('reference/enums')),
            ];
        });

        return response()->json($payload);
    }

    public function cities(Request $request)
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'state_id' => ['required', 'integer'],
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        $key = 'finopal.vip.cities.v3.'.$data['state_id'].'.'.md5((string) ($data['search'] ?? ''));
        $cities = Cache::remember($key, 3600, function () use ($data) {
            return $this->options($this->client->get('reference/cities', [
                'state_id' => $data['state_id'],
                'search' => $data['search'] ?? '',
            ]));
        });

        return response()->json($cities);
    }

    public function postalInquiry(Request $request)
    {
        $data = $request->validate([
            'postal_code' => ['required', 'digits:10'],
        ]);

        try {
            $body = $this->client->inquirePostal($data['postal_code']);
        } catch (VipPartnerException $e) {
            abort(422, $e->getMessage());
        }

        $row = is_array($body['data'] ?? null) ? $body['data'] : [];
        $province = trim((string) ($row['province'] ?? ''));
        $city = trim((string) ($row['city'] ?? ''));
        $town = trim((string) ($row['town'] ?? ''));
        $address = trim((string) ($row['address'] ?? ''));
        if ($address === '') {
            $parts = array_filter([
                $row['town'] ?? null,
                isset($row['district']) && $row['district'] !== '' ? 'منطقه '.$row['district'] : null,
                $row['street'] ?? null,
                $row['street2'] ?? null,
                isset($row['number']) && $row['number'] !== '' ? 'پلاک '.$row['number'] : null,
                isset($row['floor']) && $row['floor'] !== '' ? 'طبقه '.$row['floor'] : null,
                $row['sideFloor'] ?? null,
                $row['buildingName'] ?? null,
            ], fn ($part) => filled($part));
            $address = implode('، ', array_map('strval', $parts));
        }

        [$stateId, $cityId, $province, $city] = $this->matchGeo($province, $city, $town);

        if ($address === '') {
            abort(422, 'استعلام این کد پستی نشانی برنگرداند.');
        }

        return response()->json([
            'province' => $province,
            'city' => $city,
            'address' => $address,
            'state_id' => $stateId,
            'city_id' => $cityId,
            'postal_code' => $data['postal_code'],
        ]);
    }

    private function ensureEnabled(): void
    {
        if (! $this->client->enabled()) {
            abort(503, 'اتصال VIP فینوپال پیکربندی نشده است.');
        }
    }

    private function options(array $json, array $labelKeys = ['city_title', 'title_fa', 'title', 'name', 'name_fa', 'label', 'title_en', 'slug']): array
    {
        $rows = $json['data'] ?? $json;
        if (isset($rows['data']) && is_array($rows['data'])) {
            $rows = $rows['data'];
        }
        if (! is_array($rows) || ! array_is_list($rows)) {
            foreach (['states', 'cities', 'items', 'categories', 'gateway_categories'] as $key) {
                if (isset($rows[$key]) && is_array($rows[$key])) {
                    $rows = $rows[$key];
                    break;
                }
            }
        }
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? $row['state_id'] ?? $row['city_id'] ?? null;
            if (! is_numeric($id)) {
                continue;
            }
            $label = '';
            foreach ($labelKeys as $key) {
                if (! empty($row[$key]) && is_string($row[$key])) {
                    $label = $row[$key];
                    break;
                }
            }
            $label = $this->plain($label);
            $out[] = ['id' => (int) $id, 'title' => $label !== '' ? $label : '#'.$id];
        }

        return $out;
    }

    private function enums(array $json): array
    {
        $data = $json['data'] ?? $json;
        if (! is_array($data)) {
            return $this->fallbackEnums();
        }
        $mapped = [];
        foreach (['gender', 'entityType', 'entity_type', 'accountType', 'account_type'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                continue;
            }
            $mapped[$key] = $this->enumList($data[$key]);
        }
        if (! isset($mapped['gender'])) {
            $mapped['gender'] = $this->fallbackEnums()['gender'];
        }
        if (! isset($mapped['entityType']) && isset($mapped['entity_type'])) {
            $mapped['entityType'] = $mapped['entity_type'];
        }
        if (! isset($mapped['entityType'])) {
            $mapped['entityType'] = $this->fallbackEnums()['entityType'];
        }

        return $mapped;
    }

    private function enumList(array $raw): array
    {
        if (array_is_list($raw)) {
            return array_values(array_map(function ($item) {
                if (is_array($item)) {
                    return [
                        'value' => (string) ($item['value'] ?? $item['id'] ?? $item['key'] ?? ''),
                        'label' => (string) ($item['label'] ?? $item['title'] ?? $item['name'] ?? $item['value'] ?? ''),
                    ];
                }

                return ['value' => (string) $item, 'label' => (string) $item];
            }, $raw));
        }

        $out = [];
        foreach ($raw as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => is_string($label) ? $label : (string) $value];
        }

        return $out;
    }

    private function fallbackEnums(): array
    {
        return [
            'gender' => [
                ['value' => '0', 'label' => 'مرد'],
                ['value' => '1', 'label' => 'زن'],
            ],
            'entityType' => [
                ['value' => 'real', 'label' => 'حقیقی'],
                ['value' => 'legal', 'label' => 'حقوقی'],
            ],
        ];
    }

    /** @return array{0: ?int, 1: ?int, 2: string, 3: string} */
    private function matchGeo(string $province, string $city, string $town = ''): array
    {
        $stateId = null;
        $cityId = null;
        if ($province === '') {
            return [null, null, $province, $city];
        }

        $cached = Cache::get('finopal.vip.reference');
        $states = is_array($cached['states'] ?? null)
            ? $cached['states']
            : ($this->client->enabled() ? $this->options($this->client->get('reference/states')) : []);

        $state = $this->bestMatch($states, $province);
        if ($state) {
            $stateId = (int) $state['id'];
            $province = (string) $state['title'];
        }

        if ($stateId && $this->client->enabled()) {
            $cities = $this->options($this->client->get('reference/cities', [
                'state_id' => $stateId,
                'search' => '',
            ]));
            foreach (array_filter([$city, $town]) as $needle) {
                $match = $this->bestMatch($cities, $needle);
                if ($match) {
                    $cityId = (int) $match['id'];
                    $city = (string) $match['title'];
                    break;
                }
            }
        }

        return [$stateId, $cityId, $province, $city];
    }

    private function bestMatch(array $rows, string $needle): ?array
    {
        $fuzzy = null;
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? '');
            if ($this->norm($title) === $this->norm($needle)) {
                return $row;
            }
            if ($fuzzy === null && $this->same($title, $needle)) {
                $fuzzy = $row;
            }
        }

        return $fuzzy;
    }

    private function plain(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        return trim($value);
    }

    private function norm(string $value): string
    {
        return mb_strtolower(str_replace(['ي', 'ك', 'ة', '‌', ' '], ['ی', 'ک', 'ه', '', ''], $this->plain($value)));
    }

    private function same(string $left, string $right): bool
    {
        $left = $this->norm($left);
        $right = $this->norm($right);

        return $left !== '' && ($left === $right || str_contains($left, $right) || str_contains($right, $left));
    }
}
