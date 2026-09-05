<?php

namespace App\Providers;

use App\Integrations\Shopify\Contracts\ShopifyAdminGateway;
use App\Integrations\Shopify\ShopifyAdminClient;
use App\Models\User;
use App\UserRole;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ShopifyAdminGateway::class, ShopifyAdminClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define(
            'manage-administration',
            fn (User $user): bool => $user->role === UserRole::Admin,
        );

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by(Str::transliterate($email.'|'.$request->ip()));
        });
    }
}
