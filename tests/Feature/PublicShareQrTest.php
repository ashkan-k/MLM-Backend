<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicShareQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_share_qr_returns_png(): void
    {
        $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake([
            'api.qrserver.com/*' => Http::response($tinyPng, 200, ['Content-Type' => 'image/png']),
        ]);

        $res = $this->get('/api/public/share-qr?u='.urlencode('https://finonet.ir/register?ref=ABC123'));
        $res->assertOk();
        $this->assertStringContainsString('image/png', (string) $res->headers->get('Content-Type'));
        $this->assertNotSame('', $res->getContent());
    }

    public function test_rejects_non_url(): void
    {
        $this->getJson('/api/public/share-qr?u=not-a-url')->assertStatus(422);
    }
}
