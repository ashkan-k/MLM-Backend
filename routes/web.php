<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| Public storage fallback for hosts where symlink public_html/storage is broken/unreadable.
| Apache routes /storage/* here via .htaccess → index.php.
*/
Route::get('/storage/{path}', function (string $path) {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($path === '' || str_contains($path, '..')) {
        abort(404);
    }

    $disk = Storage::disk('public');
    if (! $disk->exists($path)) {
        abort(404);
    }

    return $disk->response($path, null, [
        'Cache-Control' => 'public, max-age=604800',
    ]);
})->where('path', '.*');
