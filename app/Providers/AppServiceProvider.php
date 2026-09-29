<?php

namespace App\Providers;

use App\Models\Order;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('api-writes', function (Request $request): Limit {
            return Limit::perMinute(30)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        Route::bind('order', function (string $value): Order {
            $user = request()->user();

            if ($user === null) {
                abort(401);
            }

            return Order::query()
                ->whereKey($value)
                ->where('user_id', $user->id)
                ->firstOrFail();
        });
    }
}
