<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthMiddleware
{
    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        $request->attributes->set('request_id', (string) Str::uuid());

        $bearer = $request->bearerToken();
        if ($bearer !== null && $bearer !== '') {
            $hash = hash('sha256', $bearer);
            $apiKey = ApiKey::query()->with('user')->where('key_hash', $hash)->first();

            if (! $apiKey || ! $apiKey->isActive() || ! $apiKey->user) {
                return ApiError::response($request, 'unauthorized', 'Invalid, expired, or revoked API key.', 401);
            }

            if ($requiredScope !== null && ! $apiKey->hasScope($requiredScope)) {
                return ApiError::response($request, 'forbidden', "API key lacks required scope '{$requiredScope}'.", 403);
            }

            $apiKey->forceFill(['last_used_at' => now()])->save();
            Auth::setUser($apiKey->user);
            $request->attributes->set('api_key_id', $apiKey->id);

            return $next($request);
        }

        $user = Auth::user();
        if (! $user) {
            return ApiError::response($request, 'unauthorized', 'Authentication required.', 401);
        }

        if ($requiredScope === 'admin' && ! $user->isAdmin()) {
            return ApiError::response($request, 'forbidden', 'Admin role required.', 403);
        }

        if ($requiredScope === 'documents' && ! $user->isManagerOrAbove()) {
            return ApiError::response($request, 'forbidden', 'Knowledge Manager or Admin role required.', 403);
        }

        return $next($request);
    }
}
