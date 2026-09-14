<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCourseManager
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ($user->isSuperuser() || $user->hasRole('senior_manager'))) {
            return $next($request);
        }

        return response()->json(['message' => 'فقط مدیر سامانه و مدیر ارشد می‌توانند دوره‌ها را مدیریت کنند.'], 403);
    }
}
