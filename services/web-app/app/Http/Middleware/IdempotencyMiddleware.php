<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $key || ! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $scopedKey = hash('sha256', ($request->user()?->id ?? 'anon').'|'.$request->method().'|'.$request->path().'|'.$key);
        $existing = IdempotencyKey::query()->find($scopedKey);
        if ($existing) {
            return response($existing->response_body, $existing->status_code, [
                'Content-Type' => 'application/json',
                'Idempotent-Replayed' => 'true',
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            IdempotencyKey::query()->create([
                'key' => $scopedKey,
                'method' => $request->method(),
                'path' => $request->path(),
                'status_code' => $response->getStatusCode(),
                'response_body' => (string) $response->getContent(),
            ]);
        }

        return $response;
    }
}
