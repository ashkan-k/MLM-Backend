<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSuperuser
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isSuperuser()) {
            return response()->json(['message' => 'فقط سوپریوزر به این بخش دسترسی دارد.'], 403);
        }

        return $next($request);
    }
}
