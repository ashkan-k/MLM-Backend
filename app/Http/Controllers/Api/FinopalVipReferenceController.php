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

        $key = 'finopal.vip.cities.v2.'.$data['state_id'].'.'.md5((string) ($data['search'] ?? ''));
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
        $this->ensureEnabled();
        $data = $request->validate([
            'postal_code' => ['required', 'digits:10'],
        ]);

        try {
            $body = $this->client->inquirePostal($data['postal_code']);
        } catch (VipPartnerException $e) {
            abort(422, $e->getMessage());
        }

        $row = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $province = (string) ($row['province'] ?? $row['Province'] ?? $row['state'] ?? '');
        $city = (string) ($row['city'] ?? $row['LocalityName'] ?? $row['town'] ?? '');
        $address = trim((string) ($row['address'] ?? ''));
        if ($address === '') {
            $parts = array_filter([
                $row['town'] ?? null,
                isset($row['district']) ? 'منطقه '.$row['district'] : null,
                $row['street'] ?? null,
                $row['street2'] ?? null,
                isset($row['number']) ? 'پلاک '.$row['number'] : null,
                isset($row['floor']) ? 'طبقه '.$row['floor'] : null,
                $row['buildingName'] ?? null,
            ], fn ($part) => filled($part));
            $address = implode('، ', array_map('strval', $parts));
        }

        $stateId = $this->intOf($row['state_id'] ?? $row['province_id'] ?? null);
        $cityId = $this->intOf($row['city_id'] ?? null);
        if (! $stateId || ! $cityId) {
            [$stateId, $cityId, $province, $city] = $this->matchGeo($province, $city, $stateId, $cityId);
        }

        if ($address === '' || ! $stateId || ! $cityId) {
            abort(422, 'استعلام کد پستی آدرس کامل برنگرداند. استان و شهر را دستی انتخاب کنید.');
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
    private function matchGeo(string $province, string $city, ?int $stateId, ?int $cityId): array
    {
        $cached = Cache::get('finopal.vip.reference');
        $states = is_array($cached['states'] ?? null)
            ? $cached['states']
            : $this->options($this->client->get('reference/states'));

        if (! $stateId) {
            foreach ($states as $state) {
                if ($this->same($state['title'], $province)) {
                    $stateId = $state['id'];
                    $province = $state['title'];
                    break;
                }
            }
        }
        if ($stateId && ! $cityId) {
            $cities = $this->options($this->client->get('reference/cities', ['state_id' => $stateId, 'search' => '']));
            foreach ($cities as $row) {
                if ($this->same($row['title'], $city)) {
                    $cityId = $row['id'];
                    $city = $row['title'];
                    break;
                }
            }
            if (! $cityId && isset($cities[0])) {
                $cityId = $cities[0]['id'];
                $city = $cities[0]['title'];
            }
        }

        return [$stateId, $cityId, $province, $city];
    }

    private function same(string $left, string $right): bool
    {
        $norm = fn (string $value) => mb_strtolower(str_replace(['ي', 'ك', 'ة', '‌', ' '], ['ی', 'ک', 'ه', '', ''], trim($value)));

        return $norm($left) !== '' && ($norm($left) === $norm($right) || str_contains($norm($left), $norm($right)) || str_contains($norm($right), $norm($left)));
    }

    private function intOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
