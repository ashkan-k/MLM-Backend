<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveRole
{
    public function handle(Request $request, Closure $next)
    {
        $role = $request->attributes->get('active_role');
        $user = $request->user();

        if ($user?->isSuperuser()) {
            return $next($request);
        }

        if (! $role || ! $user?->hasRole($role->slug)) {
            return response()->json(['message' => 'نقش فعال معتبر نیست.'], 403);
        }

        return $next($request);
    }
}
