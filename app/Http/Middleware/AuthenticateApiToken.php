<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json(['message' => 'احراز هویت نشده‌اید.'], 401);
        }

        $token = PersonalAccessToken::query()
            ->with(['user.roles', 'activeRole'])
            ->where('token', hash('sha256', $bearer))
            ->first();

        if (! $token || ! $token->user || ! $token->user->is_active) {
            return response()->json(['message' => 'توکن نامعتبر است.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);
        $request->attributes->set('active_role', $token->activeRole);

        return $next($request);
    }
}
