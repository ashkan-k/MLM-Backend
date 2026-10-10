<?php

namespace Tests\Feature;

use App\Models\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VipGatewayProvisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
        config([
            'finopal.vip.enabled' => true,
            'finopal.vip.base_url' => 'https://finopal.ir',
            'finopal.vip.key' => 'VIPK_TEST',
            'finopal.vip.secret' => 'VIPS_TEST',
        ]);
    }

    public function test_gateway_registration_is_pushed_to_finopal(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $this->assertSame('VIPK_TEST', $request->header('X-Partner-Key')[0] ?? null);
            $this->assertSame('VIPS_TEST', $request->header('X-Partner-Secret')[0] ?? null);

            $body = ['success' => true, 'data' => []];
            if (str_contains($url, '/reference/states')) {
                $body['data'] = [['id' => 8, 'title' => 'تهران']];
            } elseif (str_contains($url, '/reference/cities')) {
                $body['data'] = [['id' => 301, 'title' => 'تهران']];
            } elseif (str_contains($url, '/reference/gateway-categories')) {
                $body['data'] = [['id' => 4, 'title' => 'فروشگاه']];
            } elseif (str_contains($url, '/users/register')) {
                $this->assertStringNotContainsString('"phone"', $request->body());
                $body['data'] = ['user_id' => 55, 'tracking_code' => 'USR-1', 'address_id' => 77];
            } elseif (str_contains($url, '/contracts/sign')) {
                $body['data'] = ['contract_id' => 90];
            } elseif (str_contains($url, '/gateways/requests') && ! str_contains($url, '/update')) {
                $sent = $request->data();
                $this->assertSame(55, $sent['user_id']);
                $this->assertSame(90, $sent['contract_id']);
                $this->assertSame('real', $sent['entity_type']);
                $this->assertSame('IR120170000000123456789001', $sent['iban']);
                $this->assertSame('100.000', (string) $sent['MLM_Structure']['REPS'][0]['MLM_Commission']);
                $body['data'] = ['tracking_code' => 'GW-TRK-1', 'gateway_id' => 501];
            }

            return Http::response($body);
        });

        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->json('token');

        $created = $this->withToken($token)->post('/api/gateway-sales', [
            'external_id' => 'GW-VIP-1',
            'name' => 'فروشگاه اتصال',
            'customer' => [
                'first_name' => 'علی',
                'last_name' => 'رضایی',
                'first_name_en' => 'Ali',
                'last_name_en' => 'Rezaei',
                'mobile' => '09121230009',
                'national_id' => '0012345678',
                'sheba' => 'IR120170000000123456789001',
                'backup_sheba' => 'IR120170000000123456789002',
                'email' => 'shop@example.com',
                'father_name' => 'محمد',
                'father_name_en' => 'Mohammad',
                'birth_date' => '1991-04-04',
                'gender' => '0',
                'province' => 'تهران',
                'city' => 'تهران',
                'state_id' => 8,
                'city_id' => 301,
                'address' => 'خیابان نمونه پلاک ۱۲',
                'postal_code' => '1234567890',
                'shop_name' => 'فروشگاه اتصال',
                'shop_name_en' => 'ConnectShop',
                'category_id' => 4,
                'website' => 'https://shop.example.com',
                'callback_url' => 'https://shop.example.com/callback',
                'server_ip' => '1.2.3.4',
                'tax' => '1234567890',
            ],
            'documents' => [
                'national_id_front' => UploadedFile::fake()->image('front.jpg'),
                'national_id_back' => UploadedFile::fake()->image('back.jpg'),
                'birth_certificate' => UploadedFile::fake()->image('id.jpg'),
                'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            ],
        ])->assertCreated();

        $this->assertSame('GW-TRK-1', $created->json('gateway.metadata.finopal.tracking_code'));
        $this->assertSame(501, $created->json('gateway.metadata.finopal.gateway_id'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/gateways/requests') && $request->method() === 'POST');
        $this->assertNotNull(Gateway::query()->where('external_id', 'GW-VIP-1')->first());
    }

    public function test_finopal_validation_details_are_shown_instead_of_a_generic_title(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/users/register')) {
                return Http::response([
                    'success' => false,
                    'message' => 'خطا در اعتبارسنجی',
                    'errors' => [
                        'mobile' => ['شماره موبایل قبلاً ثبت شده است'],
                        'national_id' => ['کد ملی نامعتبر است'],
                    ],
                ], 422);
            }

            $body = ['success' => true, 'data' => []];
            if (str_contains($url, '/reference/states')) {
                $body['data'] = [['id' => 8, 'title' => 'تهران']];
            } elseif (str_contains($url, '/reference/cities')) {
                $body['data'] = [['id' => 301, 'title' => 'تهران']];
            } elseif (str_contains($url, '/reference/gateway-categories')) {
                $body['data'] = [['id' => 4, 'title' => 'فروشگاه']];
            }

            return Http::response($body);
        });

        $token = $this->postJson('/api/auth/login', [
            'mobile' => '09125555555',
            'password' => 'Password123!',
            'role_slug' => 'representative',
        ])->json('token');

        $response = $this->withToken($token)->withHeader('Accept', 'application/json')->post('/api/gateway-sales', [
            'external_id' => 'GW-VIP-ERR',
            'name' => 'فروشگاه اتصال',
            'customer' => [
                'first_name' => 'علی',
                'last_name' => 'رضایی',
                'first_name_en' => 'Ali',
                'last_name_en' => 'Rezaei',
                'mobile' => '09121230009',
                'national_id' => '0012345678',
                'sheba' => 'IR120170000000123456789001',
                'backup_sheba' => 'IR120170000000123456789002',
                'email' => 'shop@example.com',
                'father_name' => 'محمد',
                'father_name_en' => 'Mohammad',
                'birth_date' => '1991-04-04',
                'gender' => '0',
                'province' => 'تهران',
                'city' => 'تهران',
                'state_id' => 8,
                'city_id' => 301,
                'address' => 'خیابان نمونه پلاک ۱۲',
                'postal_code' => '1234567890',
                'shop_name' => 'فروشگاه اتصال',
                'shop_name_en' => 'ConnectShop',
                'category_id' => 4,
                'website' => 'https://shop.example.com',
                'callback_url' => 'https://shop.example.com/callback',
                'server_ip' => '1.2.3.4',
                'tax' => '1234567890',
            ],
            'documents' => [
                'national_id_front' => UploadedFile::fake()->image('front.jpg'),
                'national_id_back' => UploadedFile::fake()->image('back.jpg'),
                'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            ],
        ]);

        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('شماره موبایل قبلاً ثبت شده است', $message);
        $this->assertStringContainsString('کد ملی نامعتبر است', $message);
        $this->assertStringNotContainsString('users/register', $message);
        $this->assertNull(Gateway::query()->where('external_id', 'GW-VIP-ERR')->first());
    }
}
