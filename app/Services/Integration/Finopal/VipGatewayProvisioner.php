<?php

namespace App\Services\Integration\Finopal;

use App\Models\GatewaySale;
use App\Models\User;
use App\Support\JalaliDate;
use Illuminate\Support\Facades\Storage;

/**
 * Pushes a local gateway sale to FinoPal VIP Partner API v1.2
 * so the official panel receives the same shop at create/edit time.
 *
 * Flow: reference → register user → (company) → address → contract → gateway request.
 */
class VipGatewayProvisioner
{
    public function __construct(private readonly VipPartnerClient $client) {}

    public function provision(GatewaySale $sale): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        $sale->loadMissing([
            'gateway',
            'customer',
            'representatives.user',
            'managers.user',
            'managers.role',
        ]);

        if (! $sale->customer || ! $sale->gateway) {
            return;
        }

        try {
            $this->push($sale);
        } catch (VipPartnerException $e) {
            abort(422, $e->getMessage());
        }
    }

    private function push(GatewaySale $sale): void
    {
        $finopal = is_array($sale->gateway->metadata['finopal'] ?? null)
            ? $sale->gateway->metadata['finopal']
            : [];

        if (empty($finopal['user_id']) && ! $this->hasKycFiles($sale)) {
            return;
        }

        $vip = $this->vip($sale);
        $geo = ! empty($vip['state_id']) && ! empty($vip['city_id'])
            ? ['state_id' => (int) $vip['state_id'], 'city_id' => (int) $vip['city_id']]
            : $this->resolveGeo(
                (string) ($sale->customer->province ?? ''),
                (string) ($sale->customer->city ?? ''),
            );

        if (empty($finopal['user_id'])) {
            $registered = $this->registerUser($sale, $geo);
            $finopal['user_id'] = $registered['user_id'] ?? null;
            $finopal['user_tracking_code'] = $registered['tracking_code'] ?? null;
            $finopal['address_id'] = $registered['address_id'] ?? ($finopal['address_id'] ?? null);
        }

        if (empty($finopal['user_id'])) {
            abort(422, 'فینوپال شناسه کاربر را برنگرداند.');
        }

        if ($sale->customer->person_type === 'legal' && empty($finopal['company_id'])) {
            $company = $this->createCompany($sale, (int) $finopal['user_id'], $geo);
            $finopal['company_id'] = $company['id'] ?? $company['company_id'] ?? null;
            $finopal['company_tracking_code'] = $company['tracking_code'] ?? null;
        }

        if (empty($finopal['address_id'])) {
            $address = $this->client->postJson('users/addresses', array_merge([
                'user_id' => (int) $finopal['user_id'],
            ], $this->addressBody($sale, $geo, $this->vip($sale))));
            $data = $this->data($address);
            $finopal['address_id'] = $data['address_id'] ?? $data['id'] ?? null;
        }

        if (empty($finopal['address_id'])) {
            abort(422, 'فینوپال شناسه آدرس را برنگرداند.');
        }

        if (empty($finopal['contract_id'])) {
            $number = 'PARTNER-'.$sale->id.'-'.now()->timestamp;
            $signed = $this->client->postJson('contracts/sign', [
                'user_id' => (int) $finopal['user_id'],
                'contract_number' => $number,
                'signed_at' => now()->toDateString(),
            ]);
            $data = $this->data($signed);
            $finopal['contract_id'] = $data['contract_id'] ?? $data['id'] ?? null;
            $finopal['contract_number'] = $number;
        }

        if (empty($finopal['contract_id'])) {
            abort(422, 'فینوپال شناسه قرارداد را برنگرداند.');
        }

        $vip = $this->vip($sale);
        $categoryId = (int) ($vip['category_id'] ?? $finopal['category_id'] ?? $this->categoryId((string) ($sale->customer->shop_category ?? '')));
        $finopal['category_id'] = $categoryId;
        $payload = $this->gatewayPayload($sale, $finopal, $categoryId);

        if (! empty($finopal['tracking_code'])) {
            $updated = $this->client->postJson('gateways/requests/update', array_merge($payload, [
                'tracking_code' => $finopal['tracking_code'],
            ]));
            $data = $this->data($updated);
        } else {
            $created = $this->client->postJson('gateways/requests', $payload);
            $data = $this->data($created);
            $finopal['tracking_code'] = $data['tracking_code'] ?? null;
            $finopal['gateway_id'] = $data['gateway_id'] ?? $data['id'] ?? null;
        }

        if (empty($finopal['tracking_code'])) {
            abort(422, 'فینوپال کد پیگیری درگاه را برنگرداند.');
        }

        $finopal['synced_at'] = now()->toIso8601String();
        $metadata = $sale->gateway->metadata ?? [];
        $metadata['finopal'] = $finopal;
        $sale->gateway->update(['metadata' => $metadata]);
    }

    private function registerUser(GatewaySale $sale, array $geo): array
    {
        $customer = $sale->customer;
        $vip = $this->vip($sale);
        [$first, $last] = ! empty($vip['first_name'])
            ? [(string) $vip['first_name'], (string) ($vip['last_name'] ?? $vip['first_name'])]
            : $this->splitName((string) $customer->name);
        $father = (string) ($customer->father_name ?: '—');
        $files = $this->kycFiles($customer->documents ?? []);

        $response = $this->client->postForm('users/register', [
            'first_name' => $first,
            'last_name' => $last,
            'first_name_en' => (string) ($vip['first_name_en'] ?? $this->latin($first)),
            'last_name_en' => (string) ($vip['last_name_en'] ?? $this->latin($last)),
            'national_id' => (string) $customer->national_id,
            'mobile' => (string) $customer->mobile,
            'father_name_fa' => $father,
            'father_name_en' => (string) ($vip['father_name_en'] ?? $this->latin($father)),
            'birth_date' => JalaliDate::format($customer->birth_date) ?: '1370/01/01',
            'gender' => $customer->gender === 'female' ? '1' : '0',
            'account_type' => 'real',
            'address' => json_encode($this->addressBody($sale, $geo, $vip), JSON_UNESCAPED_UNICODE),
        ], $files);

        return $this->data($response);
    }

    private function createCompany(GatewaySale $sale, int $userId, array $geo): array
    {
        $customer = $sale->customer;
        [$first, $last] = $this->splitName((string) $customer->name);
        $father = (string) ($customer->father_name ?: '—');
        $vip = $this->vip($sale);
        $docs = $customer->documents ?? [];
        $gazette = $this->file($docs['gazette'] ?? null, 'روزنامه رسمی');
        $letter = $this->file($docs['official_letter'] ?? $docs['license'] ?? $docs['gazette'] ?? null, 'معرفی‌نامه رسمی');
        $statute = $this->file($docs['company_statute'] ?? $docs['license'] ?? $docs['gazette'] ?? null, 'اساسنامه');

        $response = $this->client->postForm('companies', [
            'user_id' => (string) $userId,
            'company_name' => (string) $customer->company_name,
            'company_name_en' => (string) ($vip['company_name_en'] ?? $this->latin((string) $customer->company_name)),
            'company_national_id' => (string) $customer->legal_national_id,
            'company_register_number' => (string) $customer->registration_no,
            'register_date' => JalaliDate::format($vip['register_date'] ?? null) ?: JalaliDate::format(now()),
            'address' => $this->addressLine($sale),
            'postal_code' => $this->postal($sale),
            'company_economic_code' => (string) ($customer->economic_code ?: $customer->legal_national_id),
            'company_phone' => (string) $customer->mobile,
            'company_mobile' => (string) $customer->mobile,
            'state_id' => (string) $geo['state_id'],
            'city_id' => (string) $geo['city_id'],
            'active' => 'true',
            'enable_legal' => 'true',
            'company_info_from_inquiry' => 'false',
            'companyOfficers' => json_encode([[
                'residency_type' => 'iran',
                'national_code' => (string) $customer->national_id,
                'birth_date' => JalaliDate::format($customer->birth_date) ?: '1370/01/01',
                'first_name_fa' => $first,
                'last_name_fa' => $last,
                'father_name_fa' => $father,
                'first_name_en' => $this->latin($first),
                'last_name_en' => $this->latin($last),
                'father_name_en' => $this->latin($father),
                'gender' => $customer->gender === 'female' ? 'female' : 'male',
                'id_number' => (string) ($customer->birth_certificate_no ?: $customer->national_id),
                'inquired' => true,
            ]], JSON_UNESCAPED_UNICODE),
        ], [
            'official_letter' => $letter,
            'company_statute' => $statute,
            'official_gazette' => $gazette,
        ]);

        return $this->data($response);
    }

    private function gatewayPayload(GatewaySale $sale, array $finopal, int $categoryId): array
    {
        $customer = $sale->customer;
        $vip = $this->vip($sale);
        $name = (string) ($customer->shop_name ?: $sale->gateway->name);
        $domain = $this->website((string) ($customer->website ?? ''), (string) $customer->national_id);
        $sheba = $this->sheba((string) $customer->sheba);
        $backup = $this->sheba((string) ($vip['backup_sheba'] ?? $sheba));

        return [
            'user_id' => (int) $finopal['user_id'],
            'contract_id' => (int) $finopal['contract_id'],
            'entity_type' => $customer->person_type === 'legal' ? 'legal' : 'real',
            'webservice_name' => $name,
            'webservice_name_en' => (string) ($vip['shop_name_en'] ?? $this->latin($name)),
            'webservice_domain' => $domain,
            'webservice_domain_callback' => (string) ($vip['callback_url'] ?? rtrim($domain, '/').'/callback'),
            'webservice_email' => (string) ($customer->email ?: ($customer->mobile.'@merchant.finopal.ir')),
            'webservice_ip' => (string) ($vip['server_ip'] ?? '0.0.0.0'),
            'gateways_category_id' => $categoryId,
            'address_id' => (int) $finopal['address_id'],
            'iban' => $sheba,
            'backup_iban' => $backup,
            'tax' => (string) ($vip['tax'] ?? $customer->economic_code ?: $customer->national_id),
            'MLM_Structure' => $this->mlmStructure($sale),
        ];
    }

    private function mlmStructure(GatewaySale $sale): array
    {
        $reps = $sale->representatives->map(function ($rep) {
            return array_merge($this->personSlot($rep->user), [
                'MLM_Commission' => (string) $rep->share_percent,
            ]);
        })->values()->all();

        if ($reps === []) {
            $reps = [array_merge($this->personSlot(null), ['MLM_Commission' => '100'])];
        }

        $byRole = [];
        foreach ($sale->managers as $manager) {
            $slug = $manager->role?->slug;
            if ($slug) {
                $byRole[$slug] = $this->personSlot($manager->user);
            }
        }

        return [
            'REPS' => $reps,
            'SM' => $byRole['sales_manager'] ?? $this->personSlot(null),
            'DM' => $byRole['development_manager'] ?? $this->personSlot(null),
            'Senior' => $byRole['senior_manager'] ?? $this->personSlot(null),
        ];
    }

    private function personSlot(?User $user): array
    {
        return [
            'Farasof_User_ID' => (string) ($user?->farasof_user_id ?? ''),
            'NationalCode' => (string) ($user?->national_id ?? ''),
            'PhoneNumber' => (string) ($user?->mobile ?? ''),
            'BirthDate' => $user?->birth_date ? JalaliDate::format($user->birth_date) : '',
            'ShebaNumber' => $user?->sheba ? $this->sheba($user->sheba) : '',
        ];
    }

    /** @return array{state_id: int, city_id: int} */
    private function resolveGeo(string $province, string $city): array
    {
        $states = $this->rows($this->client->get('reference/states'));
        $state = $this->matchRow($states, $province) ?? ($states[0] ?? null);
        $stateId = $this->idOf($state);
        if (! $stateId) {
            abort(422, 'فینوپال فهرست استان را برنگرداند.');
        }

        $cities = $this->rows($this->client->get('reference/cities', ['state_id' => $stateId]));
        $cityRow = $this->matchRow($cities, $city) ?? ($cities[0] ?? null);
        $cityId = $this->idOf($cityRow);
        if (! $cityId) {
            abort(422, 'فینوپال شهر استان انتخاب‌شده را برنگرداند.');
        }

        return ['state_id' => $stateId, 'city_id' => $cityId];
    }

    private function categoryId(string $wanted): int
    {
        $rows = $this->rows($this->client->get('reference/gateway-categories'));
        $match = $this->matchRow($rows, $wanted) ?? ($rows[0] ?? null);
        $id = $this->idOf($match);
        if (! $id) {
            abort(422, 'فینوپال دسته درگاه را برنگرداند.');
        }

        return $id;
    }

    private function rows(array $json): array
    {
        $data = $json['data'] ?? $json;
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (! is_array($data)) {
            return [];
        }
        if (array_is_list($data)) {
            return $data;
        }
        foreach (['states', 'cities', 'items', 'categories', 'gateway_categories'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }

    private function data(array $json): array
    {
        $data = $json['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    private function matchRow(array $rows, string $wanted): ?array
    {
        $needle = $this->norm($wanted);
        if ($needle === '') {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->norm($this->labelOf($row)) === $needle || str_contains($this->norm($this->labelOf($row)), $needle)) {
                return $row;
            }
        }

        return null;
    }

    private function labelOf(array $row): string
    {
        foreach (['title', 'name', 'name_fa', 'label'] as $key) {
            if (! empty($row[$key]) && is_string($row[$key])) {
                return $row[$key];
            }
        }

        return '';
    }

    private function idOf(?array $row): ?int
    {
        if (! $row) {
            return null;
        }
        foreach (['id', 'state_id', 'city_id', 'gateways_category_id'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (int) $row[$key];
            }
        }

        return null;
    }

    /** @return array<string, array{path: string, name: string}> */
    private function vip(GatewaySale $sale): array
    {
        $meta = $sale->customer->metadata['vip'] ?? [];

        return is_array($meta) ? $meta : [];
    }

    private function hasKycFiles(GatewaySale $sale): bool
    {
        $docs = $sale->customer->documents ?? [];

        return ! empty($docs['national_id_front']) && ! empty($docs['national_id_back']) && ! empty($docs['selfie']);
    }

    private function kycFiles(array $documents): array
    {
        return [
            'national_card_front' => $this->file($documents['national_id_front'] ?? null, 'روی کارت ملی'),
            'national_card_back' => $this->file($documents['national_id_back'] ?? null, 'پشت کارت ملی'),
            'selfi' => $this->file($documents['selfie'] ?? null, 'سلفی احراز هویت'),
        ];
    }

    /** @return array{path: string, name: string} */
    private function file(?string $relative, string $label): array
    {
        if (! $relative || ! Storage::disk('public')->exists($relative)) {
            abort(422, "فایل {$label} برای ارسال به فینوپال پیدا نشد.");
        }

        return [
            'path' => Storage::disk('public')->path($relative),
            'name' => basename($relative),
        ];
    }

    private function addressLine(GatewaySale $sale): string
    {
        $customer = $sale->customer;
        $line = trim((string) ($customer->address ?: ''));
        if ($line !== '') {
            return $line;
        }

        return trim((string) $customer->province.' '.(string) $customer->city) ?: 'ایران';
    }

    private function addressBody(GatewaySale $sale, array $geo, array $vip): array
    {
        $address = [
            'title' => (string) ($vip['address_title'] ?? 'محل کسب'),
            'postal_code' => $this->postal($sale),
            'state_id' => $geo['state_id'],
            'city_id' => $geo['city_id'],
            'address' => $this->addressLine($sale),
        ];
        $phone = $this->landline($vip['phone'] ?? null);
        if ($phone === null) {
            abort(422, 'تلفن ثابت الزامی است و باید مانند 021-12345678 باشد.');
        }
        $address['phone'] = $phone;

        return $address;
    }

    private function landline(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if (preg_match('/^0\d{2}-\d{8}$/', $raw)) {
            return $raw;
        }
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (preg_match('/^0\d{10}$/', $digits)) {
            return substr($digits, 0, 3).'-'.substr($digits, 3);
        }

        return null;
    }

    private function postal(GatewaySale $sale): string
    {
        $code = preg_replace('/\D/', '', (string) ($sale->customer->postal_code ?? '')) ?? '';

        return $code !== '' ? $code : '0000000000';
    }

    private function website(string $website, string $nationalId): string
    {
        $website = trim($website);
        if ($website === '') {
            return 'https://merchant.finopal.ir/'.$nationalId;
        }
        if (str_starts_with(strtolower($website), 'http://')) {
            $website = 'https://'.substr($website, 7);
        } elseif (! str_starts_with(strtolower($website), 'https://')) {
            $website = 'https://'.$website;
        }

        return $website;
    }

    private function sheba(string $value): string
    {
        $value = strtoupper(preg_replace('/[\s\-]/', '', $value) ?? '');
        if ($value !== '' && ! str_starts_with($value, 'IR')) {
            $value = 'IR'.$value;
        }

        return $value;
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $name = trim($name);
        $parts = preg_split('/\s+/u', $name, 2) ?: [];
        $first = $parts[0] ?? ($name !== '' ? $name : 'کاربر');
        $last = $parts[1] ?? $first;

        return [$first, $last];
    }

    private function latin(string $value): string
    {
        $map = [
            'ا' => 'a', 'آ' => 'a', 'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ث' => 's', 'ج' => 'j', 'چ' => 'ch',
            'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's',
            'ش' => 'sh', 'ص' => 's', 'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f',
            'ق' => 'gh', 'ک' => 'k', 'ك' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'و' => 'v',
            'ه' => 'h', 'ی' => 'y', 'ي' => 'y', 'ئ' => 'y',
        ];
        $out = '';
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (isset($map[$char])) {
                $out .= $map[$char];
            } elseif (preg_match('/[A-Za-z0-9]/', $char)) {
                $out .= $char;
            } elseif ($char === ' ') {
                $out .= ' ';
            }
        }
        $out = trim(preg_replace('/\s+/', ' ', $out) ?? '');

        return $out !== '' ? ucwords($out) : 'User';
    }

    private function norm(string $value): string
    {
        $value = str_replace(['ي', 'ك', 'ة', '‌'], ['ی', 'ک', 'ه', ''], $value);

        return mb_strtolower(trim($value));
    }
}
