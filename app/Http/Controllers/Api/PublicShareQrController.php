<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * تصویر QR عمومی برای اشتراک در پیام‌رسان‌ها (پیش‌نمایش تلگرام/بله و …).
 */
class PublicShareQrController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'u' => ['required', 'url', 'max:2000'],
        ]);

        $target = $data['u'];
        $scheme = parse_url($target, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return response('invalid', 422);
        }

        $cacheKey = 'share-qr:'.hash('sha256', $target);
        $png = Cache::remember($cacheKey, now()->addDay(), function () use ($target) {
            $res = Http::timeout(12)
                ->connectTimeout(6)
                ->get('https://api.qrserver.com/v1/create-qr-code/', [
                    'size' => '512x512',
                    'margin' => 12,
                    'ecc' => 'M',
                    'data' => $target,
                ]);

            if (! $res->successful() || $res->body() === '') {
                return null;
            }

            $qr = $res->body();

            return $this->composeCard($qr, $target) ?? $qr;
        });

        if ($png === null) {
            return response('qr unavailable', 502);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function composeCard(string $qrPng, string $link): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $qr = @imagecreatefromstring($qrPng);
        if (! $qr) {
            return null;
        }

        $qrW = imagesx($qr);
        $qrH = imagesy($qr);
        $pad = 28;
        $header = 40;
        $footer = 70;
        $width = $qrW + $pad * 2;
        $height = $qrH + $pad * 2 + $header + $footer;

        $canvas = imagecreatetruecolor($width, $height);
        if (! $canvas) {
            imagedestroy($qr);

            return null;
        }

        $bg = imagecolorallocate($canvas, 242, 245, 241);
        $ink = imagecolorallocate($canvas, 21, 33, 27);
        $prim = imagecolorallocate($canvas, 14, 132, 103);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $bg);

        $title = 'فینونت';
        imagestring($canvas, 5, (int) (($width - imagefontwidth(5) * strlen($title)) / 2), 14, $title, $prim);

        imagecopy($canvas, $qr, $pad, $pad + $header, 0, 0, $qrW, $qrH);

        $y = $pad + $header + $qrH + 18;
        $chunk = 42;
        $lines = str_split($link, $chunk);
        foreach (array_slice($lines, 0, 2) as $line) {
            $x = (int) (($width - imagefontwidth(3) * strlen($line)) / 2);
            imagestring($canvas, 3, max(8, $x), $y, $line, $ink);
            $y += 16;
        }

        ob_start();
        imagepng($canvas);
        $out = ob_get_clean();
        imagedestroy($qr);
        imagedestroy($canvas);

        return $out === false ? null : $out;
    }
}
