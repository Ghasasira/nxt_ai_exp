<?php

namespace App\Providers;

use App\Support\ApiError;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('rag-api', function (Request $request) {
            $key = $request->user()?->id
                ? 'user:'.$request->user()->id
                : 'ip:'.$request->ip();

            return Limit::perMinute(60)
                ->by($key)
                ->response(function (Request $req, array $headers) {
                    return ApiError::response(
                        $req,
                        'rate_limited',
                        'Too many requests. Please retry after the cooldown.',
                        429,
                        $headers
                    );
                });
        });
    }
}
